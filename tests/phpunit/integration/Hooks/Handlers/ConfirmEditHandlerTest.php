<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ContactPage\Tests\Integration\Hooks\Handlers;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ContactPage\Hooks\Handlers\ConfirmEditHandler;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ContactPage\Hooks\Handlers\ConfirmEditHandler
 */
class ConfirmEditHandlerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
	}

	/** @dataProvider provideOnConfirmEditGetGlobalInstanceFromContext */
	public function testOnConfirmEditGetGlobalInstanceFromContext(
		string $specialPageTitle,
		string $expectedAction,
		bool $expectedReturnValue
	): void {
		$handler = new ConfirmEditHandler();
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setTitle( SpecialPage::getTitleFor( $specialPageTitle ) );

		$action = '';
		$this->assertSame(
			$expectedReturnValue,
			$handler->onConfirmEditGetGlobalInstanceFromContext( $context, $action )
		);

		$this->assertSame( $expectedAction, $action );
	}

	public static function provideOnConfirmEditGetGlobalInstanceFromContext(): array {
		return [
			'Special:Contact as title sets action to contactpage' => [
				'specialPageTitle' => 'Contact',
				'expectedAction' => 'contactpage',
				'expectedReturnValue' => false,
			],
			'Special:SpecialPages does not modify the action' => [
				'specialPageTitle' => 'Specialpages',
				'expectedAction' => '',
				'expectedReturnValue' => true,
			],
		];
	}
}
