<?php
/**
 * Speclial:Contact, a contact form for visitors.
 * Based on SpecialEmailUser.php
 * 
 * @addtogroup SpecialPage
 * @author Daniel Kinzler, brightbyte.de
 * @copyright © 2007 Daniel Kinzler
 * @licence GNU General Public Licence 2.0 or later
 */

if( !defined( 'MEDIAWIKI' ) ) {
	echo( "not a valid entry point.\n" );
	die( 1 );
}

global $IP; #needed when called from the autoloader
require_once("$IP/includes/UserMailer.php");

/**
 *
 */
class SpecialContact extends SpecialPage {
	
	/**
	 * Constructor
	 */
	function __construct() {
		global $wgOut;
		SpecialPage::SpecialPage( 'Contact', '', true );

		#inject messages
		loadContactPageI18n();
	}
	
	/**
	 * Main execution function
	 * @param $par Parameters passed to the page
	 */
	function execute( $par ) {
		global $wgUser, $wgOut, $wgRequest, $wgEnableEmail, $wgContactUser, $wgContactSender;
		$fname = "SpecialContact::execute";
	
		if( !$wgEnableEmail || !$wgContactUser || !$wgContactSender) {
			$wgOut->showErrorPage( "nosuchspecialpage", "nospecialpagetext" );
			return;
		}
	
		$action = $wgRequest->getVal( 'action' );
	
		$nu = User::newFromName( $wgContactUser );
		if( is_null( $nu ) || !$nu->canReceiveEmail() ) {
			wfDebug( "Target is invalid user or can't receive.\n" );
			$wgOut->showErrorPage( "noemailtitle", "noemailtext" );
			return;
		}
	
		$f = new EmailContactForm( $nu );
	
		if ( "success" == $action ) {
			wfDebug( "$fname: success.\n" );
			$f->showSuccess( );
		} else if ( "submit" == $action && $wgRequest->wasPosted() ) {#
			$token = $wgRequest->getVal( 'wpEditToken' );

			if( $wgUser->isAnon() ) {
				# Anonymous users may not have a session
				# open. Check for suffix anyway.
				$tokenOk = ( EDIT_TOKEN_SUFFIX == $token );
			} else {
				$tokenOk = $wgUser->matchEditToken( $token );
			}

			if ( $tokenOk ) {
				wfDebug( "$fname: submit\n" );
				$f->doSubmit();
			} else {
				wfDebug( "$fname: bad token (".($wgUser->isAnon()?'anon':'user')."): $token\n" );
				$wgOut->addWikiText( wfMsg( 'sessionfailure' ) );
				$f->showForm();
			}
		} else {
			wfDebug( "$fname: form\n" );
			$f->showForm();
		}
	}
}

/**
 * @todo document
 * @addtogroup SpecialPage
 */
class EmailContactForm {

	var $target;
	var $text, $subject;
	var $cc_me;     // Whether user requested to be sent a separate copy of their email.

	/**
	 * @param User $target
	 */
	function EmailContactForm( $target ) {
		global $wgRequest, $wgUser;
		$this->target = $target;
		$this->text = $wgRequest->getText( 'wpText' );
		$this->subject = $wgRequest->getText( 'wpSubject' );
		$this->cc_me = $wgRequest->getBool( 'wpCCMe' );

		$this->fromname = $wgRequest->getText( 'wpFromName' );
		$this->fromaddress = $wgRequest->getText( 'wpFromAddress' );

		if ($wgUser->isLoggedIn()) {
			if (!$this->fromname) $this->fromname = $wgUser->getName();
			if (!$this->fromaddress) $this->fromaddress = $wgUser->getEmail();
		}
	}

	function showForm() {
		global $wgOut, $wgUser, $wgContactSender;

		#TODO: show captcha

		$wgOut->setPagetitle( wfMsg( "contactpage-title" ) );
		$wgOut->addWikiText( wfMsg( "contactpage-pagetext" ) );

		if ( $this->subject === "" ) {
			$this->subject = wfMsgForContent( "contactpage-defsubject" );
		}

		#$emf = wfMsg( "emailfrom" );
		#$sender = $wgContactSender;
		$emt = wfMsg( "emailto" );
		$rcpt = $this->target->getName();
		$emr = wfMsg( "emailsubject" );
		$emm = wfMsg( "emailmessage" );
		$ems = wfMsg( "emailsend" );
		$emc = wfMsg( "emailccme" );
		$emfn = wfMsg( "contactpage-fromname" );
		$emfa = wfMsg( "contactpage-fromaddress" );
		$encSubject = htmlspecialchars( $this->subject );
		$encFromName = htmlspecialchars( $this->fromname );
		$encFromAddress = htmlspecialchars( $this->fromaddress );

		$titleObj = SpecialPage::getTitleFor( "Contact" );
		$action = $titleObj->escapeLocalURL( "action=submit" );
		$token = $wgUser->isAnon() ? EDIT_TOKEN_SUFFIX : $wgUser->editToken(); //this kind of sucks, really...
		$token = htmlspecialchars( $token );

		$wgOut->addHTML( "
<form id=\"emailuser\" method=\"post\" action=\"{$action}\">
<table border='0' id='mailheader'>
<tr>
<td align='right'>{$emr}:</td>
<td align='left'>
<input type='text' size='60' maxlength='200' name=\"wpSubject\" value=\"{$encSubject}\" />
</td>
</tr><tr>
<td align='right'>{$emfn}:</td>
<td align='left'>
<input type='text' size='60' maxlength='200' name=\"wpFromName\" value=\"{$encFromName}\" />
</td>
<tr>
<td align='right'>{$emfa}:</td>
<td align='left'>
<input type='text' size='60' maxlength='200' name=\"wpFromAddress\" value=\"{$encFromAddress}\" />
</td>
</tr>
<tr>
<td></td>
<td align='left'>
<small>".wfMsg( "contactpage-formfootnotes" )."</small>
</td>
</tr>
</table>
<span id='wpTextLabel'><label for=\"wpText\">{$emm}:</label><br /></span>
<textarea name=\"wpText\" rows='20' cols='80' wrap='virtual' style=\"width: 100%;\">" . htmlspecialchars( $this->text ) .
"</textarea>
" . wfCheckLabel( $emc, 'wpCCMe', 'wpCCMe', $wgUser->getBoolOption( 'ccmeonemails' ) ) . "<br />
<input type='submit' name=\"wpSend\" value=\"{$ems}\" />
<input type='hidden' name='wpEditToken' value=\"$token\" />
</form>\n" );

	}

	function doSubmit( ) {
		global $wgOut, $wgContactSender, $wgContactSenderName;

		#TODO: check captcha

		$fname = 'EmailContactForm::doSubmit';

		wfDebug( "$fname: start\n" );

		$to = new MailAddress( $this->target );
		$from = new MailAddress( $wgContactSender, $wgContactSenderName );
		$replyto = $this->fromaddress ? new MailAddress( $this->fromaddress, $this->fromname ) : NULL; 
		$subject = trim( $this->subject );

		if ( $subject === "" ) {
			$subject = wfMsgForContent( "contactpage-defsubject" );
		}

		if ( $this->fromname !== "" ) {
			$subject = wfMsgForContent( "contactpage-subject-and-sender", $subject, $this->fromname );
		}

		if( wfRunHooks( 'ContactForm', array( &$to, &$replyto, &$subject, &$this->text ) ) ) {

			wfDebug( "$fname: sending mail from ".$from->toString()." to ".$to->toString()." replyto ".($replyto==null?'-/-':$replyto->toString())."\n" );

			#HACK: in MW 1.9, replyto must be a string, in MW 1.0, it must be an object!
			$ver = preg_replace( '![^\d._+]!', '', $GLOBALS['wgVersion'] );
			$replyaddr = $replyto == null 
					? NULL : version_compare( $ver, '1.10', '<' ) 
						? $replyto->toString() : $replyto;

			$mailResult = userMailer( $to, $from, $subject, $this->text, $replyaddr ); 

			if( WikiError::isError( $mailResult ) ) {
				$wgOut->addHTML( wfMsg( "usermailererror" ) . $mailResult);
			} else {
				
				// if the user requested a copy of this mail, do this now,
				// unless they are emailing themselves, in which case one copy of the message is sufficient.
				if ($this->cc_me && $replyto) {
					$cc_subject = wfMsg('emailccsubject', $this->target->getName(), $subject);
					if( wfRunHooks( 'ContactForm', array( &$from, &$replyto, &$cc_subject, &$this->text ) ) ) {
						wfDebug( "$fname: sending cc mail from ".$from->toString()." to ".$replyto->toString()."\n" );
						$ccResult = userMailer( $replyto, $from, $cc_subject, $this->text );
						if( WikiError::isError( $ccResult ) ) {
							// At this stage, the user's CC mail has failed, but their 
							// original mail has succeeded. It's unlikely, but still, what to do?
							// We can either show them an error, or we can say everything was fine,
							// or we can say we sort of failed AND sort of succeeded. Of these options, 
							// simply saying there was an error is probably best.
							$wgOut->addHTML( wfMsg( "usermailererror" ) . $ccResult);
							return;
						}
					}
				}
				
				wfDebug( "$fname: success\n" );

				$titleObj = SpecialPage::getTitleFor( "Contact" );
				$wgOut->redirect( $titleObj->getFullURL( "action=success" ) );
				wfRunHooks( 'ContactFromComplete', array( $to, $replyto, $subject, $this->text ) );
			}
		}

		wfDebug( "$fname: end\n" );
	}

	function showSuccess( ) {
		global $wgOut;

		$wgOut->setPagetitle( wfMsg( "emailsent" ) );
		$wgOut->addHTML( wfMsg( "emailsenttext" ) );

		$wgOut->returnToMain( false );
	}
}

