<?php
/**
 * Speclial:Contact, a contact form for visitors.
 * Based on SpecialEmailUser.php
 *
 * @file
 * @ingroup SpecialPage
 * @author Daniel Kinzler, brightbyte.de
 * @copyright © 2007-2014 Daniel Kinzler, Sam Reed
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\ContactPage;

use MailAddress;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\UserBlockedError;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\Extension\ConfirmEdit\Hooks as ConfirmEditHooks;
use MediaWiki\Extension\ContactPage\Hooks\HookRunner;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\Field\HTMLCheckField;
use MediaWiki\HTMLForm\Field\HTMLHiddenField;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Session\SessionManager;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use UserMailer;

/**
 * Provides the contact form
 * @ingroup SpecialPage
 */
class SpecialContact extends UnlistedSpecialPage {
	private UserOptionsLookup $userOptionsLookup;
	private UserFactory $userFactory;
	private HookRunner $contactPageHookRunner;

	/** @var string|null */
	private $recipientName = null;

	/**
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param UserFactory $userFactory
	 */
	public function __construct( UserOptionsLookup $userOptionsLookup, UserFactory $userFactory ) {
		parent::__construct( 'Contact' );
		$this->userOptionsLookup = $userOptionsLookup;
		$this->userFactory = $userFactory;

		$this->contactPageHookRunner = new HookRunner( $this->getHookContainer() );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'contactpage' );
	}

	/**
	 * @var string
	 */
	protected $formType;

	/**
	 * @return array
	 */
	protected function getTypeConfig() {
		$contactConfig = $this->getConfig()->get( 'ContactConfig' );

		if ( $contactConfig['default']['SenderName'] === null ) {
			$sitename = $this->getConfig()->get( 'Sitename' );
			$contactConfig['default']['SenderName'] = "Contact Form on $sitename";
		}

		if ( isset( $contactConfig[$this->formType] ) ) {
			return $contactConfig[$this->formType] + $contactConfig['default'];
		}
		return $contactConfig['default'];
	}

	/**
	 * Helper function that returns a form-specific message key if it is not
	 * disabled. Otherwise returns the generic message key. Used to make it
	 * possible for forms to have form-specific messages.
	 *
	 * @param string $genericMessageKey The message key that will be used if no form-specific one can be used
	 * @return string
	 */
	protected function getFormSpecificMessageKey( string $genericMessageKey ): string {
		$formSpecificMessageKey = $genericMessageKey . '-' . $this->formType;
		if ( !str_starts_with( $genericMessageKey, 'contactpage' ) ) {
			// If the generic message does not start with "contactpage", the form
			// specific one will have "contactpage-" prefixed on the generic message
			// name.
			$formSpecificMessageKey = 'contactpage-' . $formSpecificMessageKey;
		}
		if ( $this->formType && !$this->msg( $formSpecificMessageKey )->isDisabled() ) {
			// Return the form-specific message if the form type is not the empty string
			//  and the message is defined.
			return $formSpecificMessageKey;
		}
		return $genericMessageKey;
	}

	/**
	 * Main execution function
	 *
	 * @param string|null $par Parameters passed to the page
	 * @throws UserBlockedError
	 * @throws ErrorPageError
	 */
	public function execute( $par ) {
		if ( !$this->getConfig()->get( MainConfigNames::EnableEmail ) ) {
			// From Special:EmailUser
			throw new ErrorPageError( 'usermaildisabled', 'usermaildisabledtext' );
		}

		$request = $this->getRequest();
		$this->formType = strtolower( $request->getText( 'formtype', $par ?? '' ) );

		$config = $this->getTypeConfig();

		if ( $config['Redirect'] ) {
			$this->getOutput()->redirect( $config['Redirect'] );
			return;
		}

		$user = $this->getUser();

		$error = $this->checkFormErrors( $user, $config );

		if ( $error ) {
			$this->getOutput()->showErrorPage( ...$error );
			return;
		}

		// Set page title now we're certain we will display the form
		$this->getOutput()->setPageTitleMsg(
			$this->msg( $this->getFormSpecificMessageKey( 'contactpage-title' ) )
		);

		$formItems = $this->getFormFields( $user, $config );

		$form = HTMLForm::factory( 'ooui',
			$formItems, $this->getContext(), "contactpage-{$this->formType}"
		);
		$form->setWrapperLegendMsg( 'contactpage-legend' );
		$form->setSubmitTextMsg( $this->getFormSpecificMessageKey( 'emailsend' ) );
		if ( $this->formType !== '' ) {
			$form->setId( "contactpage-{$this->formType}" );

			$msg = $this->msg( "contactpage-legend-{$this->formType}" );
			if ( !$msg->isDisabled() ) {
				$form->setWrapperLegendMsg( $msg );
			}

			$msg = $this->msg( "contactpage-emailsend-{$this->formType}" );
			if ( !$msg->isDisabled() ) {
				$form->setSubmitTextMsg( $msg );
			}
		}
		$form->setSubmitCallback( [ $this, 'processInput' ] );
		$form->prepareForm();

		// Stolen from Special:EmailUser
		if ( !$this->contactPageHookRunner->onEmailUserForm( $form ) ) {
			return;
		}

		$result = $form->show();

		if ( $result === true || ( $result instanceof Status && $result->isGood() ) ) {
			$output = $this->getOutput();
			$output->setPageTitleMsg( $this->msg( $this->getFormSpecificMessageKey( 'emailsent' ) ) );
			$output->addWikiMsg(
				$this->getFormSpecificMessageKey( 'emailsenttext' ),
				$this->recipientName
			);

			$output->returnToMain( false );
		} else {
			if ( $config['RLStyleModules'] ) {
				$this->getOutput()->addModuleStyles( $config['RLStyleModules'] );
			}
			if ( $config['RLModules'] ) {
				$this->getOutput()->addModules( $config['RLModules'] );
			}
			$formText = $this->msg(
				$this->getFormSpecificMessageKey( 'contactpage-pagetext' )
			)->parseAsBlock();
			$this->getOutput()->prependHTML( trim( $formText ) );
		}
	}

	/**
	 * Various permission and form misconfiguration checks
	 *
	 * When there's an error and the form should not be displayed, the return value
	 * must be an array of exactly 2 string elements: message key for the error page title
	 * and message key for the actual error message.
	 *
	 * The method may also throw a subclass of ErrorPageError to halt displaying the form.
	 *
	 * @return array|false false means there's no error and we should proceed to display the form.
	 * @phan-return array{0:string,1:string}|false [ error title msg key, error text msg key ]
	 * @throws UserNotLoggedIn
	 * @throws UserBlockedError
	 */
	private function checkFormErrors( User $user, array $config ) {
		// Display error if user not logged in when config requires it
		$requiresConfirmedEmail = $config['MustHaveEmail'] ?? false;
		$requiresLogin = $config['MustBeLoggedIn'] ?? false;

		if ( $requiresLogin ) {
			// Uses the following message keys:
			// * contactpage-mustbeloggedin
			// * contactpage-mustbeloggedin-for-temp-user
			$this->requireNamedUser( 'contactpage-mustbeloggedin' );
		} elseif ( $requiresConfirmedEmail ) {
			// MustHaveEmail must not be set without setting MustBeLoggedIn, as
			// anon and temporary users do not have email addresses.
			return [ 'contactpage-config-error-title', 'contactpage-config-error' ];
		}

		// Display error if sender has no confirmed email when config requires it
		if ( $requiresConfirmedEmail && !$user->isEmailConfirmed() ) {
			return [ 'contactpage-musthaveemail-error-title', 'contactpage-musthaveemail-error' ];
		}

		// Display error if no recipient specified in configuration
		if ( !$config['RecipientUser'] && !$config['RecipientEmail'] ) {
			return [ 'contactpage-config-error-title', 'contactpage-config-error' ];
		}

		// Display error if 'RecipientUser' is used, but they have email disabled
		if ( $config['RecipientUser'] ) {
			$recipient = $this->userFactory->newFromName( $config['RecipientUser'] );
			if ( $recipient === null || !$recipient->canReceiveEmail() ) {
				return [ 'noemailtitle', 'noemailtext' ];
			}
			$this->recipientName = $config['RecipientUser'];
		} else {
			$this->recipientName = $config['RecipientName'] ?? $this->getConfig()->get( 'Sitename' );
		}

		// Blocked users cannot use the contact form if they're disabled from sending email.
		$block = $user->getBlock();
		if ( $block && $block->appliesToRight( 'sendemail' ) ) {
			$useCustomBlockMessage = $config['UseCustomBlockMessage'] ?? false;
			if ( $useCustomBlockMessage ) {
				return [ $this->getFormSpecificMessageKey( 'contactpage-title' ),
					$this->getFormSpecificMessageKey( 'contactpage-blocked-message' ) ];
			}

			throw new UserBlockedError( $block );
		}

		// Show error if the following are true as they are in combination invalid configuration:
		// * The form doesn't require logging in
		// * The form requires details
		// * The email form is read-only.
		// This is because the email field will be empty for anon and temp users and must be filled
		// for the form to be valid, but cannot be modified by the client.
		$emailReadonly = $user->isNamed() && ( $config['EmailReadonly'] ?? false );
		if ( !$requiresLogin && $emailReadonly && $config['RequireDetails'] ) {
			return [ 'contactpage-config-error-title', 'contactpage-config-error' ];
		}

		return false;
	}

	private function getFormFields( User $user, array $config ): array {
		# Check for type in [[Special:Contact/type]]: change the pagetext and prefill form fields
		$formSpecificSubjectMessageKey = $this->msg( [
			'contactpage-defsubject-' . $this->formType,
			'contactpage-subject-' . $this->formType
		] );

		if ( $this->formType !== '' && !$formSpecificSubjectMessageKey->isDisabled() ) {
			$subject = trim( $formSpecificSubjectMessageKey->inContentLanguage()->plain() );
		} else {
			$subject = $this->msg( 'contactpage-defsubject' )->inContentLanguage()->text();
		}

		$fromAddress = '';
		$fromName = '';
		$nameReadonly = false;
		$emailReadonly = false;
		$subjectReadonly = $config['SubjectReadonly'] ?? false;

		if ( $user->isRegistered() ) {
			// See T335962#10283428 for an explanation for why
			// we are including temporary accounts here.
			$realName = $user->getRealName();
			if ( $realName ) {
				$fromName = $realName;
			} else {
				$fromName = $user->getName();
			}
			$fromAddress = $user->getEmail();
			$nameReadonly = $config['NameReadonly'] ?? false;
			$emailReadonly = $config['EmailReadonly'] ?? false;
		}

		$stockFields = [
			'FromName' => [
				'label-message' => $this->getFormSpecificMessageKey( 'contactpage-fromname' ),
				'type' => 'text',
				'required' => $config['RequireDetails'],
				'default' => $fromName,
				'disabled' => $nameReadonly,
			],
			'FromAddress' => [
				'label-message' => $this->getFormSpecificMessageKey( 'contactpage-fromaddress' ),
				'type' => 'email',
				'required' => $config['RequireDetails'],
				'default' => $fromAddress,
				'disabled' => $emailReadonly,
			],
			'Subject' => [
				'label-message' => $this->getFormSpecificMessageKey( 'emailsubject' ),
				'type' => 'text',
				'default' => $subject,
				'disabled' => $subjectReadonly,
			],
		];

		// Control fields cannot be repositioned or removed by FieldsMergeStrategy
		// option, to ensure better visual hierarchy and consistent form control.
		$controlFields = [
			'CCme' => [
				'label-message' => $this->getFormSpecificMessageKey( 'emailccme' ),
				'type' => 'check',
				'default' => $this->userOptionsLookup->getBoolOption( $user, 'ccmeonemails' ),
			],
			'FormType' => [
				'class' => HTMLHiddenField::class,
				'label' => 'Type',
				'default' => $this->formType,
			]
		];

		if ( $config['IncludeIP'] && $user->isRegistered() ) {
			$controlFields['IncludeIP'] = [
				'label-message' => $this->getFormSpecificMessageKey( 'contactpage-includeip' ),
				'type' => 'check',
			];
		}

		if ( $this->useCaptcha() ) {
			$controlFields['Captcha'] = [
				'label-message' => 'captcha-label',
				'type' => 'info',
				'default' => $this->getCaptcha(),
				'raw' => true,
			];
		}

		$additionalFields = $config['AdditionalFields'] ?? [];

		if ( $additionalFields && $config['FieldsMergeStrategy'] === 'replace' ) {
			// Merge fields and redefine stock fields with the same key.
			$formFields = $additionalFields + $stockFields;

			// Stock fields set to null should be removed from the form
			$items = [
				'FromName' => [ $fromName, $nameReadonly ],
				'FromAddress' => [ $fromAddress, $emailReadonly ],
				'Subject' => [ $subject, $subjectReadonly ],
			];

			foreach ( $items as $field => [ $value, $disabled ] ) {
				// Remove the field entirely if set to null
				if ( $formFields[$field] === null ) {
					unset( $formFields[$field] );
				} else {
					// if 'default' is null/unset, use computed default value
					$formFields[$field]['default'] ??= $value;
					// if 'disabled' is null/unset, set it from form config.
					$formFields[$field]['disabled'] ??= $disabled;
				}

			}
		} else {
			$formFields = $stockFields + $additionalFields;
		}

		// This field needs to be immediately after 'FromAddress' field. We have to
		// do this here to check if that field exists and if we should add this one.
		if ( isset( $formFields['FromAddress'] ) && !$config['RequireDetails'] ) {
			$fromInfo = [
				'FromInfo' => [
					'label' => '',
					'type' => 'info',
					'default' => Html::rawElement( 'small', [],
						$this->msg(
							$this->getFormSpecificMessageKey( 'contactpage-formfootnotes' )
						)->escaped()
					),
					'raw' => true,
				]
			];

			$formFields = wfArrayInsertAfter( $formFields, $fromInfo, 'FromAddress' );

		}

		// Form controls fields cannot be overridden. Use array_merge() to enforce that.
		return array_merge( $formFields, $controlFields );
	}

	/**
	 * @param array $formData
	 * @return bool|string|array|Status
	 *     - Bool true or a good Status object indicates success,
	 *     - Bool false indicates no submission was attempted,
	 *     - Anything else indicates failure. The value may be a fatal Status
	 *       object, an HTML string, or an array of arrays (message keys and
	 *       params) or strings (message keys)
	 */
	public function processInput( $formData ) {
		$request = $this->getRequest();
		$user = $this->getUser();

		if (
			$this->useCaptcha() &&
			!ConfirmEditHooks::getInstance()->passCaptchaFromRequest( $request, $user )
		) {
			return [ 'contactpage-captcha-error' ];
		}

		$senderIP = $request->getIP();
		$config = $this->getTypeConfig();

		// Setup user that is going to receive the contact page response
		if ( $config['RecipientUser'] ) {
			$contactRecipientUser = $this->userFactory->newFromName( $config['RecipientUser'] );
			'@phan-var \MediaWiki\User\User $contactRecipientUser';
			$contactRecipientAddress = MailAddress::newFromUser( $contactRecipientUser );
			$ccName = $contactRecipientUser->getName();
		} else {
			$ccName = $config['RecipientName'] ?? $this->getConfig()->get( 'Sitename' );
			$contactRecipientAddress = new MailAddress( $config['RecipientEmail'] );
		}

		// Used when the user hasn't set an email, when $wgUserEmailUseReplyTo is true,
		// or when sending CC email to the user
		$siteAddress = new MailAddress(
			$config['SenderEmail'] ?: $this->getConfig()->get( 'PasswordSender' ),
			$config['SenderName']
		);

		// Initialize the sender to the site address
		$senderAddress = $siteAddress;

		$fromAddress = $formData['FromAddress'] ?? '';
		$fromName = $formData['FromName'] ?? '';

		$fromUserAddress = null;
		$replyTo = null;

		if ( $fromAddress ) {
			// T232199 - If the email address is invalid, bail out.
			// Don't allow the from address to fall back to basically @server.host.name
			if ( !Sanitizer::validateEmail( $fromAddress ) ) {
				return [ 'invalidemailaddress' ];
			}

			// Use user submitted details
			$fromUserAddress = new MailAddress( $fromAddress, $fromName );

			if ( $this->getConfig()->get( 'UserEmailUseReplyTo' ) ) {
				// Define reply-to address
				$replyTo = $fromUserAddress;
			} else {
				// Not using ReplyTo, so set the sender to $fromUserAddress
				$senderAddress = $fromUserAddress;
			}
		}

		$includeIP = isset( $config['IncludeIP'] ) && $config['IncludeIP']
			&& ( $user->isAnon() || $formData['IncludeIP'] );
		$subject = $formData['Subject'] ?? '';

		if ( $fromName !== '' ) {
			if ( $includeIP ) {
				$subject = $this->msg(
					'contactpage-subject-and-sender-withip',
					$subject,
					$fromName,
					$senderIP
				)->inContentLanguage()->text();
			} else {
				$subject = $this->msg(
					'contactpage-subject-and-sender',
					$subject,
					$fromName
				)->inContentLanguage()->text();
			}
		} elseif ( $fromAddress !== '' ) {
			if ( $includeIP ) {
				$subject = $this->msg(
					'contactpage-subject-and-sender-withip',
					$subject,
					$fromAddress,
					$senderIP
				)->inContentLanguage()->text();
			} else {
				$subject = $this->msg(
					'contactpage-subject-and-sender',
					$subject,
					$fromAddress
				)->inContentLanguage()->text();
			}
		} elseif ( $includeIP ) {
			$subject = $this->msg(
				'contactpage-subject-and-sender',
				$subject,
				$senderIP
			)->inContentLanguage()->text();
		}

		$text = '';
		foreach ( $config['AdditionalFields'] ?? [] as $name => $field ) {
			if ( $field === null ) {
				continue;
			}

			$class = HTMLForm::getClassFromDescriptor( $name, $field );

			$value = '';
			// TODO: Support selectandother/HTMLSelectAndOtherField
			// options, options-messages and options-message
			if ( isset( $field['options-messages'] ) ) {
				// Multiple values!
				if ( is_string( $formData[$name] ) ) {
					$optionValues = array_flip( $field['options-messages'] );
					if ( isset( $optionValues[$formData[$name]] ) ) {
						$value = $this->msg( $optionValues[$formData[$name]] )->inContentLanguage()->text();
					} else {
						$value = $formData[$name];
					}
				} elseif ( count( $formData[$name] ) ) {
					$formValues = array_flip( $formData[$name] );
					$value .= "\n";
					foreach ( $field['options-messages'] as $msg => $optionValue ) {
						$msg = $this->msg( $msg )->inContentLanguage()->text();
						$optionValue = $this->getYesOrNoMsg( isset( $formValues[$optionValue] ) );
						$value .= "\t$msg: $optionValue\n";
					}
				}
			} elseif ( isset( $field['options'] ) ) {
				if ( is_string( $formData[$name] ) ) {
					$value = $formData[$name];
				} elseif ( count( $formData[$name] ) ) {
					$formValues = array_flip( $formData[$name] );
					$value .= "\n";
					foreach ( $field['options'] as $msg => $optionValue ) {
						$optionValue = $this->getYesOrNoMsg( isset( $formValues[$optionValue] ) );
						$value .= "\t$msg: $optionValue\n";
					}
				}
			} elseif ( $class === HTMLCheckField::class
				// Checking old alias for compatibility with unchanged extensions
				|| $class === \HTMLCheckField::class
			) {
				$value = $this->getYesOrNoMsg( $formData[$name] xor
					( isset( $field['invert'] ) && $field['invert'] ) );
			} elseif ( isset( $formData[$name] ) ) {
				// HTMLTextField, HTMLTextAreaField
				// HTMLFloatField, HTMLIntField

				// Just dump the value if its wordy
				$value = $formData[$name];
			} else {
				continue;
			}

			if ( isset( $field['contactpage-email-label'] ) ) {
				$name = $field['contactpage-email-label'];
			} elseif ( isset( $field['label-message'] ) ) {
				$name = $this->msg( $field['label-message'] )->inContentLanguage()->text();
			} else {
				$name = $field['label'];
			}

			$text .= "{$name}: $value\n";
		}

		if ( !$this->contactPageHookRunner->onContactForm( $contactRecipientAddress, $replyTo, $subject,
			$text, $this->formType, $formData )
		) {
			// TODO: Need to do some proper error handling here
			return false;
		}

		wfDebug( __METHOD__ . ': sending mail from ' . $senderAddress->toString() .
			' to ' . $contactRecipientAddress->toString() .
			' replyto ' . ( $replyTo === null ? '-/-' : $replyTo->toString() ) . "\n"
		);
		// @phan-suppress-next-line SecurityCheck-XSS UserMailer::send defaults to text/plain if passed a string
		$mailResult = UserMailer::send(
			$contactRecipientAddress,
			$senderAddress,
			$subject,
			$text,
			[ 'replyTo' => $replyTo ]
		);

		$language = $this->getLanguage();
		if ( !$mailResult->isOK() ) {
			wfDebug( __METHOD__ . ': got error from UserMailer: ' .
				$mailResult->getMessage( false, false, 'en' )->text() . "\n" );
			return [ $mailResult->getMessage( 'contactpage-usermailererror', false, $language ) ];
		}

		// if the user requested a copy of this mail, do this now,
		// unless they are emailing themselves, in which case one copy of the message is sufficient.
		if ( $formData['CCme'] && $fromUserAddress ) {
			$cc_subject = $this->msg( 'emailccsubject', $ccName, $subject )->text();
			if ( $this->contactPageHookRunner->onContactForm(
				$fromUserAddress, $senderAddress, $cc_subject, $text, $this->formType, $formData )
			) {
				wfDebug( __METHOD__ . ': sending cc mail from ' . $senderAddress->toString() .
					' to ' . $fromUserAddress->toString() . "\n"
				);
				// @phan-suppress-next-line SecurityCheck-XSS UserMailer::send defaults to text/plain if passed a string
				$ccResult = UserMailer::send(
					$fromUserAddress,
					$senderAddress,
					$cc_subject,
					$text,
				);
				if ( !$ccResult->isOK() ) {
					// At this stage, the user's CC mail has failed, but their
					// original mail has succeeded. It's unlikely, but still, what to do?
					// We can either show them an error, or we can say everything was fine,
					// or we can say we sort of failed AND sort of succeeded. Of these options,
					// simply saying there was an error is probably best.
					return [ $ccResult->getMessage( 'contactpage-usermailererror', false, $language ) ];
				}
			}
		}

		$this->contactPageHookRunner->onContactFromComplete( $contactRecipientAddress, $replyTo, $subject, $text );

		return true;
	}

	/**
	 * @param bool $value
	 * @return string
	 */
	private function getYesOrNoMsg( $value ) {
		return $this->msg( $value ? 'htmlform-yes' : 'htmlform-no' )->inContentLanguage()->text();
	}

	/**
	 * @return bool True if CAPTCHA should be used, false otherwise
	 */
	private function useCaptcha() {
		$extRegistry = ExtensionRegistry::getInstance();
		if ( !$extRegistry->isLoaded( 'ConfirmEdit' ) ) {
			 return false;
		}
		$config = $this->getConfig();
		$captchaTriggers = $config->get( 'CaptchaTriggers' );

		return $config->get( 'CaptchaClass' )
			&& isset( $captchaTriggers['contactpage'] )
			&& $captchaTriggers['contactpage']
			&& !$this->getUser()->isAllowed( 'skipcaptcha' );
	}

	/**
	 * @return string CAPTCHA form HTML
	 */
	private function getCaptcha() {
		// NOTE: make sure we have a session. May be required for CAPTCHAs to work.
		SessionManager::getGlobalSession()->persist();

		$captcha = ConfirmEditHooks::getInstance();
		$captcha->setTrigger( 'contactpage' );
		$captcha->setAction( 'contact' );

		$output = $this->getOutput();
		$formInformation = $captcha->getFormInformation( 1, $output );
		$formMetainfo = $formInformation;
		unset( $formMetainfo['html'] );
		$captcha->addFormInformationToOutput( $output, $formMetainfo );

		return '<div class="captcha">' .
			$formInformation['html'] .
			"</div>\n";
	}
}
