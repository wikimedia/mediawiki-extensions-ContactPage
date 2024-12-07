<?php

namespace MediaWiki\Extension\ContactPage\Tests\Integration;

use MediaWiki\Extension\ContactPage\SpecialContact;
use MediaWiki\User\User;
use SpecialPageTestBase;
use TestUser;

/**
 * @group Database
 * @group SpecialPage
 * @covers \MediaWiki\Extension\ContactPage\SpecialContact
 */
class SpecialContactTest extends SpecialPageTestBase {

	private static User $contactUser;

	public function addDBDataOnce() {
		self::$contactUser = ( new TestUser( 'ContactUser' ) )->getUser();
	}

	protected function newSpecialPage() {
		return new SpecialContact(
			$this->getServiceContainer()->getUserOptionsLookup(),
			$this->getServiceContainer()->getUserFactory()
		);
	}

	/**
	 * @dataProvider provideFormConfigurations
	 */
	public function testFormConfigurations( $formName, $config, $expected, $loggedIn = false ) {
		$this->setFormConfig( $formName, $config );

		$result = $this->executeSpecialPage( $formName, null, 'qqx', $loggedIn ? self::$contactUser : null );

		$this->assertStringContainsString( $expected, $result[ 0 ] );
	}

	public static function provideFormConfigurations() {
		return [
			'default-form-unconfigured' => [ '', [], 'contactpage-config-error' ],
			'default-form-configured' => [
				'',
				[ 'SenderName' => 'ContactTest', 'RecipientUser' => 'ContactUser' ],
				// Form shows up
				'contactpage-fromname'
			],
			'custom-form' => [
				'custom-form',
				[ 'RecipientEmail' => 'contact@wiki.test' ],
				// Form shows up
				'contactpage-fromname-custom'
			],
			'invalid-config' => [
				'invalid-form',
				[
					'RecipientEmail' => 'contact@wiki.test',
					'RequireDetails' => true,
					'MustBeLoggedIn' => true,
					'EmailReadonly' => true
				],
				// Error page
				'contactpage-pagetext-invalid-form',
				true
			],
		];
	}

	private function setFormConfig( $formName, $config ) {
		$this->setTemporaryHook(
			'EmailConfirmed',
			static fn ( $user ) => $user->getName() !== 'ContactUser'
		);

		// To add custom form configuration, we must redefine the extension
		// defaults here. This should not be necessary after T381662 is fixed.
		$config = array_merge( self::getDefaults(), [ $formName => $config ] );
		$this->overrideConfigValue( 'ContactConfig', $config );
	}

	private static function getDefaults() {
		// This duplicates what's in extension.json because of T381662
		return [
			'default' => [
				'RecipientUser' => null,
				'RecipientEmail' => null,
				'RecipientName' => null,
				'SenderEmail' => null,
				'SenderName' => null,
				'RequireDetails' => false,
				'IncludeIP' => false,
				'MustBeLoggedIn' => false,
				'MustHaveEmail' => false,
				'NameReadonly' => false,
				'EmailReadonly' => false,
				'SubjectReadonly' => false,
				'UseCustomBlockMessage' => false,
				'Redirect' => null,
				'RLModules' => [],
				'RLStyleModules' => [],
				'AdditionalFields' => [
					'Text' => [
						'label-message' => 'emailmessage',
						'type' => 'textarea',
						'required' => true
					]
				]
			]
		];
	}
}
