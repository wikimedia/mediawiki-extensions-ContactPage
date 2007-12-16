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
	'url' => 'http://mediawiki.org/wiki/Extension:ContactPage',
	'description' => 'contact form for visitors',
);

$wgAutoloadClasses['SpecialContact'] = dirname( __FILE__ ) . '/SpecialContact.php';
$wgSpecialPages['Contact'] = 'SpecialContact';

$wgHooks['LoadAllMessages'][] = 'loadContactPageI18n';

$wgContactUser = NULL;
$wgContactSender = 'apache@' . $wgServerName;
$wgContactSenderName = 'Contact Form on ' . $wgSitename;

/**
* load the ContactPage internationalization file
*/
function loadContactPageI18n() {
	global $wgLang, $wgMessageCache;

	static $initialized = false;

	if ( $initialized ) return true;

	$messages= array();
	
	$f= dirname( __FILE__ ) . '/ContactPage.i18n.php';
	include( $f );
	
	$f= dirname( __FILE__ ) . '/ContactPage.i18n.' . $wgLang->getCode() . '.php';
	if ( file_exists( $f ) ) include( $f );
	
	$initialized = true;
	$wgMessageCache->addMessages( $messages );

	return true;
}

