<?php
/**
 * Speclial:Contact, a contact form for visitors.
 * Based on SpecialEmailUser.php
 *
 * @file
 * @ingroup SpecialPage
 * @author Daniel Kinzler, brightbyte.de
 * @copyright © 2007 Daniel Kinzler
 * @license GNU General Public Licence 2.0 or later
 */

/**
 * Provides the contact form
 * @ingroup SpecialPage
 */
class SpecialContact extends SpecialPage {
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
	 * Main execution function
	 *
	 * @param $par Mixed: Parameters passed to the page
	 * @throws UserBlockedError
	 * @throws ErrorPageError
	 */
	public function execute( $par ) {
		global $wgEnableEmail, $wgContactUser;

		if( !$wgEnableEmail ) {
			// From Special:EmailUser
			throw new ErrorPageError( 'usermaildisabled', 'usermaildisabledtext' );
		}
		if( !$wgContactUser ) {
			$this->getOutput()->showErrorPage( 'nosuchspecialpage', 'nospecialpagetext' );
			return;
		}

		$request = $this->getRequest();
		$user = $this->getUser();

		$action = $request->getVal( 'action' );

		$nu = User::newFromName( $wgContactUser );
		if( is_null( $nu ) || !$nu->canReceiveEmail() ) {
			$this->getOutput()->showErrorPage( 'noemailtitle', 'noemailtext' );
			return;
		}

		// Blocked users cannot use the contact form if they're disabled from sending email.
		if ( $user->isBlockedFromEmailuser() ) {
			throw new UserBlockedError( $this->getUser()->mBlock );
		}

		$f = new EmailContactForm( $nu, $par );

		if ( 'success' == $action ) {
			$f->showSuccess();
		} elseif ( 'submit' == $action && $request->wasPosted() && $f->hasAllInfo() ) {
			$token = $request->getVal( 'wpEditToken' );

			if( $user->isAnon() ) {
				# Anonymous users may not have a session
				# open. Check for suffix anyway.
				$tokenOk = ( EDIT_TOKEN_SUFFIX == $token );
			} else {
				$tokenOk = $user->matchEditToken( $token );
			}

			if ( !$tokenOk ) {
				$this->getOutput()->addWikiMsg( 'sessionfailure' );
				$f->showForm();
			} elseif ( !$f->passCaptcha() ) {
				$this->getOutput()->addWikiMsg( 'contactpage-captcha-failed' );
				$f->showForm();
			} else {
				$f->doSubmit();
			}
		} else {
			$f->showForm();
		}
	}
}

/**
 * @todo document
 * @ingroup SpecialPage
 */
class EmailContactForm {
	var $target;
	var $text, $subject;
	var $cc_me; // Whether user requested to be sent a separate copy of their email.

	/**
	 * @param User $target
	 * @param $par
	 */
	function __construct( $target, $par ) {
		global $wgRequest, $wgUser;

		$this->wasPosted = $wgRequest->wasPosted();
		$this->formType = $wgRequest->getText( 'formtype', $par );

		# Check for type in [[Special:Contact/type]]: change pagetext and prefill form fields
		if ( $this->formType != '' ) {
			$message = wfMessage( 'contactpage-pagetext-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$this->formularText = $message->parseAsBlock();
			} else {
				$this->formularText = wfMessage( 'contactpage-pagetext' )->parseAsBlock();
			}

			$message = wfMessage( 'contactpage-subject-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$text = $message->inContentLanguage()->plain();
				$this->subject = $wgRequest->getText( 'wpSubject', $text );
			} else {
				$this->subject = $wgRequest->getText( 'wpSubject' );
			}

			$message = wfMessage( 'contactpage-text-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$text = $message->inContentLanguage()->plain();
				$this->text = $wgRequest->getText( 'wpText', $text );
			} else {
				$this->text = $wgRequest->getText( 'wpText' );
			}
		} else {
			$this->formularText = wfMessage( 'contactpage-pagetext' )->parseAsBlock();
			$this->subject = $wgRequest->getText( 'wpSubject' );
			$this->text = $wgRequest->getText( 'wpText' );
		}

		$this->formularText = trim( $this->formularText );
		$this->subject = trim( $this->subject );
		$this->text = trim( $this->text );

		if ( $this->subject === '' ) {
			$this->subject = wfMessage( 'contactpage-defsubject' )->inContentLanguage()->text();
		}

		$this->target = $target;
		$this->cc_me = $wgRequest->getBool( 'wpCCMe' );
		$this->includeIP = $wgRequest->getBool( 'wpIncludeIP' );

		$this->fromname = trim( $wgRequest->getText( 'wpFromName' ) );
		$this->fromaddress = trim( $wgRequest->getText( 'wpFromAddress' ) );

		if( $wgUser->isLoggedIn() ) {
			if( !$this->fromname ) {
				$this->fromname = $wgUser->getName();
			}
			if( !$this->fromaddress ) {
				$this->fromaddress = $wgUser->getEmail();
			}
		}

		// prepare captcha if applicable
		if ( $this->useCaptcha() ) {
			$captcha = ConfirmEditHooks::getInstance();
			$captcha->trigger = 'contactpage';
			$captcha->action = 'contact';
		}
	}

	/**
	 * @return bool
	 */
	function hasAllInfo() {
		global $wgContactRequireAll;
		if ( $this->text === ''
			|| ( $wgContactRequireAll && ( $this->fromname === '' || $this->fromaddress === '' ) ) ) {
				return false;
		}

		return true;
	}

	function showForm() {
		global $wgOut, $wgUser, $wgContactRequireAll, $wgContactIncludeIP;

		# @todo Show captcha

		$pageTitle = wfMessage( 'contactpage-title' );
		if ( $this->formType != '' ) {
			$message = wfMessage( 'contactpage-title-' . $this->formType );
			if ( !$message->isDisabled() ) {
				$pageTitle = $message;
			}
		}
		$wgOut->setPageTitle( $pageTitle );
		$wgOut->addHTML( $this->formularText );

		$msgSuffix = $wgContactRequireAll ? '-required' : '';

		$titleObj = SpecialPage::getTitleFor( 'Contact' );
		$action = $titleObj->getLocalURL( 'action=submit' );
		$token = $wgUser->isAnon() ? EDIT_TOKEN_SUFFIX : $wgUser->getEditToken(); //this kind of sucks, really...

		$form =
			Xml::openElement( 'form', array( 'method' => 'post', 'action' => $action, 'id' => 'emailuser' ) ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, wfMessage( 'contactpage-legend' )->text() ) .
			Xml::openElement( 'table', array( 'id' => 'mailheader' ) ) .
			'<tr>
				<td class="mw-label">' .
					Xml::label( wfMessage( 'emailsubject' )->text(), 'wpSubject' ) .
				'</td>
				<td class="mw-input" id="mw-contactpage-subject">' .
					Xml::input( 'wpSubject', 60, $this->subject, array( 'type' => 'text', 'maxlength' => 200 ) ) .
				'</td>
			</tr>
			<tr>
				<td class="mw-label">' .
					Xml::label( wfMessage( "contactpage-fromname$msgSuffix" )->text(), 'wpFromName' ) .
				'</td>
				<td class="mw-input" id="mw-contactpage-from">' .
					Xml::input( 'wpFromName', 60, $this->fromname, array( 'type' => 'text', 'maxlength' => 200 ) ) .
				'</td>
			</tr>
			<tr>
				<td class="mw-label">' .
					Xml::label( wfMessage( "contactpage-fromaddress$msgSuffix" )->text(), 'wpFromAddress' ) .
				'</td>
				<td class="mw-input" id="mw-contactpage-address">' .
					Xml::input( 'wpFromAddress', 60, $this->fromaddress, array( 'type' => 'text', 'maxlength' => 200 ) ) .
				'</td>
			</tr>';

			// Allow other extensions to add more fields into Special:Contact
			wfRunHooks( 'ContactFormBeforeMessage', array( $this, &$form ) );

			// @todo FIXME: Unescaped text is inserted into HTML here.
			$form .= '<tr>
				<td></td>
				<td class="mw-input" id="mw-contactpage-formfootnote">
					<small>' . wfMessage( "contactpage-formfootnotes$msgSuffix" )->text() . '</small>
				</td>
			</tr>
			<tr>
				<td class="mw-label">' .
					Xml::label( wfMessage( 'emailmessage' )->text(), 'wpText' ) .
				'</td>
				<td class="mw-input">' .
					Xml::textarea( 'wpText', $this->text, 80, 20, array( 'id' => 'wpText' ) ) .
				'</td>
			</tr>';
			if ( $wgContactIncludeIP && $wgUser->isLoggedIn() ) {
				$form .= '<tr>
					<td></td>
					<td class="mw-input">' .
						Xml::checkLabel( wfMessage( 'contactpage-includeip' )->text(), 'wpIncludeIP', 'wpIncludeIP', false ) .
					'</td>
				</tr>';
			}

			$ccme = $this->wasPosted ? $this->cc_me : $wgUser->getBoolOption( 'ccmeonemails' );
			$form .= '<tr>
				<td></td>
				<td class="mw-input">' .
					Xml::checkLabel( wfMessage( 'emailccme' )->text(), 'wpCCMe', 'wpCCMe', $ccme ) .
					'<br />' . $this->getCaptcha() .
				'</td>
			</tr>
			<tr>
				<td></td>
				<td class="mw-submit">' .
					Xml::submitButton( wfMessage( 'emailsend' )->text(), array( 'name' => 'wpSend', 'accesskey' => 's' ) ) .
				'</td>
			</tr>' .
			Html::hidden( 'wpEditToken', $token ) .
			Html::hidden( 'formtype', $this->formType ) .
			Xml::closeElement( 'table' ) .
			Xml::closeElement( 'fieldset' ) .
			Xml::closeElement( 'form' );
		$wgOut->addHTML( $form );
	}

	/**
	 * @return bool
	 */
	function useCaptcha() {
		global $wgCaptchaClass, $wgCaptchaTriggers, $wgUser;
		if ( !$wgCaptchaClass ) {
			return false; // no captcha installed
		}
		if ( isset( $wgCaptchaTriggers['contactpage'] ) && !$wgCaptchaTriggers['contactpage'] ) {
			return false; // don't trigger on contact form
		}

		if( $wgUser->isAllowed( 'skipcaptcha' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return string
	 */
	function getCaptcha() {
		if ( !$this->useCaptcha() ) {
			return '';
		}

		wfSetupSession(); #NOTE: make sure we have a session. May be required for captchas to work.

		/** @var SimpleCaptcha $wgCaptcha */
		global $wgCaptcha;

		return '<div class="captcha">' .
			$wgCaptcha->getForm() .
			wfMessage( 'contactpage-captcha' )->parse() .
		"</div>\n";
	}

	/**
	 * @return bool
	 */
	function passCaptcha() {
		/** @var SimpleCaptcha $wgCaptcha */
		global $wgCaptcha;
		if ( !$this->useCaptcha() ) {
			return true;
		}

		return $wgCaptcha->passCaptcha();
	}

	function doSubmit() {
		global $wgOut, $wgUser, $wgRequest;
		global $wgUserEmailUseReplyTo, $wgPasswordSender;
		global $wgContactSender, $wgContactSenderName, $wgContactIncludeIP;

		$csender = $wgContactSender ? $wgContactSender : $wgPasswordSender;
		$cname = $wgContactSenderName;
		$senderIP = $wgRequest->getIP();

		$targetAddress = new MailAddress( $this->target );
		$replyto = null;
		$contactSender = new MailAddress( $csender, $cname );
		$subject = '';

		if ( !$this->fromaddress ) {
			$submitterAddress = $contactSender;
		} else {
			$submitterAddress = new MailAddress( $this->fromaddress, $this->fromname );
			if ( $wgUserEmailUseReplyTo ) {
				$replyto = $submitterAddress;
			}
		}

		$includeIP = $wgContactIncludeIP && ( $this->includeIP || $wgUser->isAnon() );
		if ( $this->fromname !== '' ) {
			if ( $includeIP ) {
				$subject = wfMessage(
					'contactpage-subject-and-sender-withip',
					$this->subject,
					$this->fromname,
					$senderIP
				)->inContentLanguage()->text();
			} else {
				$subject = wfMessage(
					'contactpage-subject-and-sender',
					$this->subject,
					$this->fromname
				)->inContentLanguage()->text();
			}
		} elseif ( $this->fromaddress !== '' ) {
			if ( $includeIP ) {
				$subject = wfMessage(
					'contactpage-subject-and-sender-withip',
					$this->subject,
					$this->fromaddress,
					$senderIP
				)->inContentLanguage()->text();
			} else {
				$subject = wfMessage(
					'contactpage-subject-and-sender',
					$this->subject,
					$this->fromaddress
				)->inContentLanguage()->text();
			}
		} elseif ( $includeIP ) {
			$subject = wfMessage(
				'contactpage-subject-and-sender',
				$this->subject,
				$senderIP
			)->inContentLanguage()->text();
		}

		if( !wfRunHooks( 'ContactForm', array( &$targetAddress, &$replyto, &$subject, &$this->text, $this->formType ) ) ) {
			return;
		}

		wfDebug( __METHOD__ . ': sending mail from ' . $submitterAddress->toString() .
			' to ' . $targetAddress->toString().
			' replyto ' . ( $replyto == null ? '-/-' : $replyto->toString() ) . "\n" );

		$mailResult = UserMailer::send( $targetAddress, $submitterAddress, $subject, $this->text, $replyto );

		if( !$mailResult->isOK() ) {
			$wgOut->addWikiText( wfMessage( 'usermailererror' )->text() . $mailResult->getMessage() );
			wfDebug( __METHOD__ . ': got error from UserMailer: ' . $mailResult->getMessage() . "\n" );
			return;
		}

		// if the user requested a copy of this mail, do this now,
		// unless they are emailing themselves, in which case one copy of the message is sufficient.
		if( $this->cc_me && $this->fromaddress ) {
			$cc_subject = wfMessage( 'emailccsubject', $this->target->getName(), $subject )->text();
			if( wfRunHooks( 'ContactForm', array( &$submitterAddress, &$contactSender, &$cc_subject, &$this->text, $this->formType ) ) ) {
				wfDebug( __METHOD__ . ': sending cc mail from ' . $contactSender->toString() .
					' to ' . $submitterAddress->toString() . "\n" );
				$ccResult = UserMailer::send( $submitterAddress, $contactSender, $cc_subject, $this->text );
				if( !$ccResult->isOK() ) {
					// At this stage, the user's CC mail has failed, but their
					// original mail has succeeded. It's unlikely, but still, what to do?
					// We can either show them an error, or we can say everything was fine,
					// or we can say we sort of failed AND sort of succeeded. Of these options,
					// simply saying there was an error is probably best.
					$wgOut->addWikiText( wfMessage( 'usermailererror' )->text() . $ccResult->getMessage() );
					return;
				}
			}
		}

		$titleObj = SpecialPage::getTitleFor( 'Contact' );
		$wgOut->redirect( $titleObj->getFullURL( 'action=success' ) );
		wfRunHooks( 'ContactFromComplete', array( $targetAddress, $replyto, $subject, $this->text ) );
	}

	function showSuccess() {
		global $wgOut;

		$wgOut->setPageTitle( wfMessage( 'emailsent' ) );
		$wgOut->addWikiMsg( 'emailsenttext' );

		$wgOut->returnToMain( false );
	}
}
