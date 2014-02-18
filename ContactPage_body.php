<?php
/**
 * Speclial:Contact, a contact form for visitors.
 * Based on SpecialEmailUser.php
 *
 * @file
 * @ingroup SpecialPage
 * @author Daniel Kinzler, brightbyte.de
 * @copyright © 2007-2014 Daniel Kinzler, Sam Reed
 * @license GNU General Public Licence 2.0 or later
 */

/**
 * Provides the contact form
 * @ingroup SpecialPage
 */
class SpecialContact extends UnlistedSpecialPage {
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'Contact' );
	}

	/**
	 * @see SpecialPage::getDescription
	 */
	function getDescription() {
		return $this->msg( 'contactpage' )->text();
	}

	/**
	 * @var string
	 */
	protected $formType;

	/**
	 * @return array
	 */
	protected function getTypeConfig() {
		global $wgContactConfig;
		if ( isset( $wgContactConfig[$this->formType] ) ) {
			return $wgContactConfig[$this->formType] + $wgContactConfig['default'];
		}
		return $wgContactConfig['default'];
	}

	/**
	 * Main execution function
	 *
	 * @param $par Mixed: Parameters passed to the page
	 * @throws UserBlockedError
	 * @throws ErrorPageError
	 */
	public function execute( $par ) {
		global $wgEnableEmail;

		if( !$wgEnableEmail ) {
			// From Special:EmailUser
			throw new ErrorPageError( 'usermaildisabled', 'usermaildisabledtext' );
		}

		$request = $this->getRequest();
		$this->formType = strtolower( $request->getText( 'formtype', $par ) );

		$config = $this->getTypeConfig();
		if( !$config['RecipientUser'] ) {
			$this->getOutput()->showErrorPage( 'nosuchspecialpage', 'nospecialpagetext' );
			return;
		}

		$user = $this->getUser();

		$nu = User::newFromName( $config['RecipientUser'] );
		if( is_null( $nu ) || !$nu->canReceiveEmail() ) {
			$this->getOutput()->showErrorPage( 'noemailtitle', 'noemailtext' );
			return;
		}

		// Blocked users cannot use the contact form if they're disabled from sending email.
		if ( $user->isBlockedFromEmailuser() ) {
			throw new UserBlockedError( $this->getUser()->mBlock );
		}

		$pageTitle = '';
		if ( $this->formType != '' ) {
			$message = wfMessage( 'contactpage-title-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$pageTitle = $message;
			}
		}

		if ( $pageTitle === '' ) {
			$pageTitle = wfMessage( 'contactpage-title' );
		}
		$this->getOutput()->setPageTitle( $pageTitle );

		$msgSuffix = $config['RequireDetails'] ? '-required' : '';

		$text = '';
		$subject = '';

		# Check for type in [[Special:Contact/type]]: change pagetext and prefill form fields
		if ( $this->formType != '' ) {
			$message = wfMessage( 'contactpage-pagetext-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$formText = $message->parseAsBlock();
			} else {
				$formText = wfMessage( 'contactpage-pagetext' )->parseAsBlock();
			}

			$message = wfMessage( 'contactpage-subject-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$subject = $message->inContentLanguage()->plain();
			}

			$message = wfMessage( 'contactpage-text-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$text = $message->inContentLanguage()->plain();
			}
		} else {
			$formText = wfMessage( 'contactpage-pagetext' )->parseAsBlock();
		}

		$subject = trim( $subject );
		$text = trim( $text );

		if ( $subject === '' ) {
			$subject = wfMessage( 'contactpage-defsubject' )->inContentLanguage()->text();
		}

		$user = $this->getUser();
		$fromAddress = '';
		$fromName = '';
		if( $user->isLoggedIn() ) {
			// Use real name if set
			$realName = $user->getRealName();
			if ( $realName ) {
				$fromName = $realName;
			} else {
				$fromName = $user->getName();
			}
			$fromAddress = $user->getEmail();
		}

		$formItems = array(
			'FromName' => array(
				'label-message' => "contactpage-fromname$msgSuffix",
				'type' => 'text',
				'required' => $config['RequireDetails'],
				'default' => $fromName,
			),
			'FromAddress' => array(
				'label-message' => "contactpage-fromaddress$msgSuffix",
				'type' => 'email',
				'required' => $config['RequireDetails'],
				'default' => $fromAddress,
			),
			'FromInfo' => array(
				'label' => '',
				'type' => 'info',
				'default' => Html::rawElement( 'small', array(),
					$this->msg( "contactpage-formfootnotes$msgSuffix" )->text()
				),
				'raw' => true,
			),
			'Subject' => array(
				'label-message' => 'emailsubject',
				'type' => 'text',
				'default' => $subject,
			),
			'Text' => array(
				'label-message' => 'emailmessage',
				'type' => 'textarea',
				'rows' => 20,
				'cols' => 80,
				'default' => $text,
				'required' => true,
			),
			'CCme' => array(
				'label-message' => 'emailccme',
				'type' => 'check',
				'default' => $this->getUser()->getBoolOption( 'ccmeonemails' ),
			),
			'FormType' => array(
				'class' => 'HTMLHiddenField',
				'label' => 'Type',
				'default' => $this->formType,
			)
		);

		if ( $config['IncludeIP'] && $user->isLoggedIn() ) {
			$formItems['IncludeIP'] = array(
				'label-message' => 'contactpage-includeip',
				'type' => 'check',
			);
		}

		$form = new HTMLForm( $formItems, $this->getContext(), "contactpage-{$this->formType}" );
		$form->setWrapperLegendMsg( 'contactpage-legend' );
		$form->setSubmitTextMsg( 'emailsend' );
		$form->setSubmitCallback( array( $this, 'processInput' ) );
		$form->loadData();

		// Stolen from Special:EmailUser
		if ( !wfRunHooks( 'EmailUserForm', array( &$form ) ) ) {
			return;
		}

		$result = $form->show();

		if ( $result === true || ( $result instanceof Status && $result->isGood() ) ) {
			$out = $this->getOutput();
			$out->setPageTitle( wfMessage( 'emailsent' ) );
			$out->addWikiMsg( 'emailsenttext' );

			$out->returnToMain( false );
		} else {
			$this->getOutput()->prependHTML( trim( $formText ) );
		}
	}

	/**
	 * @param array $formData
	 * @return bool|string
	 *      true: Form won't be displayed
	 *      false: Form will be redisplayed
	 *      string: Error message to display
	 */
	public function processInput( $formData ) {
		global $wgUserEmailUseReplyTo, $wgPasswordSender;
		$config = $this->getTypeConfig();

		$request = $this->getRequest();
		$user = $this->getUser();

		$csender = $config['SenderEmail'] ?: $wgPasswordSender;
		$cname = $config['SenderName'];
		$senderIP = $request->getIP();

		$contactUser = User::newFromName( $config['RecipientUser'] );
		$targetAddress = new MailAddress( $contactUser );
		$replyto = null;
		$contactSender = new MailAddress( $csender, $cname );

		$fromAddress = $formData['FromAddress'];
		$fromName = $formData['FromName'];
		if ( !$fromAddress ) {
			$submitterAddress = $contactSender;
		} else {
			$submitterAddress = new MailAddress( $fromAddress, $fromName );
			if ( $wgUserEmailUseReplyTo ) {
				$replyto = $submitterAddress;
			}
		}

		$includeIP = $config['IncludeIP'] && ( $formData['IncludeIP'] || $user->isAnon() );
		$fromName = $formData['FromName'];
		$subject = $formData['Subject'];

		if ( $fromName !== '' ) {
			if ( $includeIP ) {
				$subject = wfMessage(
					'contactpage-subject-and-sender-withip',
					$subject,
					$fromName,
					$senderIP
				)->inContentLanguage()->text();
			} else {
				$subject = wfMessage(
					'contactpage-subject-and-sender',
					$subject,
					$fromName
				)->inContentLanguage()->text();
			}
		} elseif ( $fromAddress !== '' ) {
			if ( $includeIP ) {
				$subject = wfMessage(
					'contactpage-subject-and-sender-withip',
					$subject,
					$fromAddress,
					$senderIP
				)->inContentLanguage()->text();
			} else {
				$subject = wfMessage(
					'contactpage-subject-and-sender',
					$subject,
					$fromAddress
				)->inContentLanguage()->text();
			}
		} elseif ( $includeIP ) {
			$subject = wfMessage(
				'contactpage-subject-and-sender',
				$subject,
				$senderIP
			)->inContentLanguage()->text();
		}

		wfDebug( __METHOD__ . ': sending mail from ' . $submitterAddress->toString() .
			' to ' . $targetAddress->toString().
			' replyto ' . ( $replyto == null ? '-/-' : $replyto->toString() ) . "\n" );

		$text = $formData['Text'];

		// Stolen from Special:EmailUser
		$error = '';
		if ( !wfRunHooks( 'EmailUser', array( &$targetAddress, &$submitterAddress, &$subject, &$text, &$error ) ) ) {
			return $error;
		}

		if( !wfRunHooks( 'ContactForm', array( &$targetAddress, &$replyto, &$subject, &$text, $this->formType ) ) ) {
			return false; // TODO: Need to do some proper error handling here
		}

		$mailResult = UserMailer::send( $targetAddress, $submitterAddress, $subject, $text, $replyto );

		if( !$mailResult->isOK() ) {
			wfDebug( __METHOD__ . ': got error from UserMailer: ' . $mailResult->getMessage() . "\n" );
			return wfMessage( 'usermailererror' )->text() . $mailResult->getMessage();
		}

		// if the user requested a copy of this mail, do this now,
		// unless they are emailing themselves, in which case one copy of the message is sufficient.
		if( $formData['CCme'] && $fromAddress ) {
			$cc_subject = wfMessage( 'emailccsubject', $contactUser->getName(), $subject )->text();
			if( wfRunHooks( 'ContactForm', array( &$submitterAddress, &$contactSender, &$cc_subject, &$text, $this->formType ) ) ) {
				wfDebug( __METHOD__ . ': sending cc mail from ' . $contactSender->toString() .
					' to ' . $submitterAddress->toString() . "\n" );
				$ccResult = UserMailer::send( $submitterAddress, $contactSender, $cc_subject, $text );
				if( !$ccResult->isOK() ) {
					// At this stage, the user's CC mail has failed, but their
					// original mail has succeeded. It's unlikely, but still, what to do?
					// We can either show them an error, or we can say everything was fine,
					// or we can say we sort of failed AND sort of succeeded. Of these options,
					// simply saying there was an error is probably best.
					return wfMessage( 'usermailererror' )->text() . $ccResult->getMessage();
				}
			}
		}

		wfRunHooks( 'ContactFromComplete', array( $targetAddress, $replyto, $subject, $text ) );

		return true;
	}
}
