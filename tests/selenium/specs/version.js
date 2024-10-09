'use strict';

const VersionPage = require( '../pageobjects/version.page' );

describe( 'ContactPage on Version page', () => {
	it( 'ContactPage is listed in the version page under the special page category', async () => {
		await VersionPage.open();

		await expect( VersionPage.contactPageExtension ).toExist();
	} );
} );
