'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class VersionPage extends Page {
	get contactPageExtension() {
		return $( '#mw-version-ext-specialpage-ContactPage' );
	}

	open() {
		super.openTitle( 'Special:Version' );
	}
}

module.exports = new VersionPage();
