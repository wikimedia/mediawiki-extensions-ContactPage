<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'ContactPage' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['ContactPage'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['ContactPageAliases'] = __DIR__ . '/ContactPage.alias.php';
	wfWarn(
		'Deprecated PHP entry point used for ContactPage extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the ContactPage extension requires MediaWiki 1.25+' );
}
