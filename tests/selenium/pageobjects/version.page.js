import Page from 'wdio-mediawiki/Page';

class VersionPage extends Page {
	get contactPageExtension() {
		return $( '#mw-version-ext-specialpage-ContactPage' );
	}

	async open() {
		return super.openTitle( 'Special:Version' );
	}
}

export default new VersionPage();
