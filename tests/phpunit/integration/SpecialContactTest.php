<?php

namespace MediaWiki\Extension\ContactPage\Tests\Integration;

use MediaWiki\Extension\ContactPage\SpecialContact;
use MediaWiki\User\User;
use SpecialPageTestBase;
use TestUser;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 * @group SpecialPage
 * @covers \MediaWiki\Extension\ContactPage\SpecialContact
 */
class SpecialContactTest extends SpecialPageTestBase {

	private static User $contactUser;

	protected function setUp(): void {
		parent::setUp();
		// For now we must redefine the config variable with the same
		// values as in extension.json because of T381662
		$this->overrideConfigValue( 'ContactConfig', self::getDefaults() );
	}

	public function addDBDataOnce() {
		self::$contactUser = ( new TestUser( 'ContactUser' ) )->getUser();
	}

	protected function newSpecialPage() {
		return new SpecialContact(
			$this->getServiceContainer()->getUserOptionsLookup(),
			$this->getServiceContainer()->getUserFactory()
		);
	}

	public function testFieldsRepositioning() {
		$config = [
			'RecipientEmail' => 'contact@wiki.test',
			'FieldsMergeStrategy' => 'replace',
			'AdditionalFields' => [
				'ImportantFieldFirst' => [ 'type' => 'check' ],
				'FromName' => [ 'type' => 'text' ]
			],
		];

		$html = $this->getFormHtml( 'fields-repositioning', $config );

		// ImportantFieldFirst should have lower position because
		// it must appear before the stock FromName field.
		$first = strpos( $html, 'wpImportantFieldFirst' );
		$second = strpos( $html, 'wpFromName' );

		$this->assertGreaterThan( $first, $second, 'Fields repositioning failed.' );
	}

	public function testFieldsAttributesPrecedence() {
		$formName = 'attributes-precedence';
		$config = [
			'RecipientEmail' => 'contact@wiki.test',
			'FieldsMergeStrategy' => 'replace',
			'NameReadonly' => true,
			'AdditionalFields' => [
				'FromName' => [
					'type' => 'text',
					'disabled' => false
				]
			]
		];

		$fields = $this->getFormFields( $formName, $config );

		// The `disabled` attribute sets on `FromName` must override `NameReadonly`
		$this->assertFalse( $fields['FromName']['disabled'], 'Attributes precedence failed.' );

		unset( $config['AdditionalFields']['FromName']['disabled'] );

		$fields = $this->getFormFields( $formName, $config );

		// If `disabled` is not set on `FromName`, then it must be set based on `NameReadonly`
		$this->assertTrue( $fields['FromName']['disabled'], 'Form-config-level attribute must be used.' );
	}

	/**
	 * @dataProvider provideFormConfigurations
	 */
	public function testFormConfigurations( $formName, $config, $assert, $assertNot = [], $loggedIn = false ) {
		$html = $this->getFormHtml( $formName, $config, $loggedIn ? self::$contactUser : null );

		foreach ( $assert as $string ) {
			$this->assertStringContainsString( $string, $html );
		}

		foreach ( $assertNot as $string ) {
			$this->assertStringNotContainsString( $string, $html );
		}
	}

	public static function provideFormConfigurations() {
		// Order is [ form name, config, assert, assertNot, performer is logged in ]

		return [
			'default-form-unconfigured' => [ '', [], [ 'contactpage-config-error' ] ],
			'default-form-configured' => [
				'',
				[ 'SenderName' => 'ContactTest', 'RecipientUser' => 'ContactUser' ],
				// Form shows up
				[ 'contactpage-fromname' ]
			],
			'custom-form' => [
				'custom-form',
				[ 'RecipientEmail' => 'contact@wiki.test' ],
				// Form shows up
				[ 'contactpage-fromname-custom' ]
			],
			'invalid-form' => [
				'invalid-form',
				[
					'RecipientEmail' => 'contact@wiki.test',
					'RequireDetails' => true,
					'MustBeLoggedIn' => true,
					'EmailReadonly' => true
				],
				// Error page
				[ 'contactpage-pagetext-invalid-form' ],
				[],
				true
			],
			'simple-fields-replace' => [
				'simple-fields-replace',
				[
					'RecipientEmail' => 'contact@wiki.test',
					'FieldsMergeStrategy' => 'replace',
					'AdditionalFields' => [
						'FromName' => null,
						'FromAddress' => null,
						'Subject' => null,
						'OurOnlyField' => [ 'type' => 'check' ]
					],

				],
				// Only field exists
				[ 'wpOurOnlyField' ],
				// Stock fields removed
				[ 'wpFromName', 'wpFromAddress', 'wpSubject' ]
			],
			'control-fields-must-exist' => [
				'control-fields-must-exist',
				[
					'RecipientEmail' => 'contact@wiki.test',
					'FieldsMergeStrategy' => 'replace',
					'IncludeIP' => true,
					'AdditionalFields' => [
						'CCme' => null,
						'IncludeIP' => null,
					]
				],
				// Overriding control fields has no effect
				[ 'wpCCme', 'wpIncludeIP' ],
				[],
				true
			]
		];
	}

	private function getFormFields( $formName, $config ) {
		$this->setFormConfig( $formName, $config );

		$page = TestingAccessWrapper::newFromObject( $this->newSpecialPage() );
		// Needs to set formtype manually since we are only processing fields data
		$page->formType = $formName;

		return $page->getFormFields( self::$contactUser, $page->getTypeConfig() );
	}

	private function getFormHtml( $formName, $config, $performer = null ) {
		$this->setFormConfig( $formName, $config );

		// We use ContactUser as either perfomer or recipient in some tests.
		// Verify their email in both cases. Hook returns false for that.
		if ( $performer || ( $config['RecipientUser'] ?? null ) ) {
			$this->setTemporaryHook(
				'EmailConfirmed',
				static fn ( $user ) => $user->getName() !== 'ContactUser'
			);
		}

		return $this->executeSpecialPage( $formName, null, 'qqx', $performer )[ 0 ];
	}

	private function setFormConfig( $formName, $config = [] ) {
		if ( $config !== [] ) {
			// To add any custom form configuration, we must redefine the extension
			// defaults here. This should not be necessary after T381662 is fixed.
			$config = array_merge( self::getDefaults(), [ $formName => $config ] );
			$this->overrideConfigValue( 'ContactConfig', $config );
		}
	}

	private static function getDefaults() {
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
				],
				'FieldsMergeStrategy' => null
			]
		];
	}
}
