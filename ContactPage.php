<?php
/**
 * Setup for ContactPage extension, a special page that implements a contact form
 * for use by anonymous visitors.
 *
 * @package MediaWiki
 * @subpackage Extensions
 * @author Daniel Kinzler, brightbyte.de
 * @copyright © 2007 Daniel Kinzler
 * @licence GNU General Public Licence 2.0 or later
 */

if( !defined( 'MEDIAWIKI' ) ) {
	echo( "This file is an extension to the MediaWiki software and cannot be used standalone.\n" );
	die( 1 );
}

$wgExtensionCredits['specialpage'][] = array( 
	'name' => 'Contact', 
	'author' => 'Daniel Kinzler', 
	'url' => 'http://meta.wikimedia.org/wiki/Contact_form_extension',
	'description' => 'contact form for visitors',
);

$wgAutoloadClasses['SpecialContact'] = dirname( __FILE__ ) . '/SpecialContact.php';
$wgSpecialPages['Contact'] = 'SpecialContact';

$wgContactUser = NULL;
$wgContactSender = 'apache@' . $wgServerName;
$wgContactSenderName = 'Contact Form on ' . $wgSitename;

/**
* load the CategoryTree internationalization file
*/
function cpLoadMessages() {
	global $wgLang;
	
	$messages= array();
	
	$f= dirname( __FILE__ ) . '/ContactPage.i18n.php';
	include( $f );
	
	$f= dirname( __FILE__ ) . '/ContactPage.i18n.' . $wgLang->getCode() . '.php';
	if ( file_exists( $f ) ) include( $f );
	
	return $messages;
}

/**
* Get a ContactPage message, "contactpage-" prefix added automatically
*/
function cpMsg( $msg /*, ...*/ ) {
	static $initialized = false;
	global $wgMessageCache;
	if ( !$initialized ) {
		$wgMessageCache->addMessages( cpLoadMessages() );
		$initialized = true;
	}
	if ( $msg === false ) {
		return null;
	}
	$args = func_get_args();
	$msg = array_shift( $args );
	if ( $msg == '' ) {
		return wfMsgReal( $msg, $args );
	} else {
		return wfMsgReal( "contactpage-$msg", $args );
	}
}

?>