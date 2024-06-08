'use strict';

const assert = require( 'assert' ),
	VersionPage = require( '../pageobjects/version.page' );

describe( 'ContactPage on Version page', () => {
	it( 'ContactPage is listed in the version page under the special page category', async () => {
		await VersionPage.open();

		assert( await VersionPage.contactPageExtension.isExisting() );
	} );
} );
