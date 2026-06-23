<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ContactPage\Hooks\Handlers;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditGetGlobalInstanceFromContextHook;

class ConfirmEditHandler implements ConfirmEditGetGlobalInstanceFromContextHook {
	/** @inheritDoc */
	public function onConfirmEditGetGlobalInstanceFromContext( IContextSource $context, string &$action ): bool {
		if ( $context->getTitle()->isSpecial( 'Contact' ) ) {
			$action = 'contactpage';
			return false;
		}
		return true;
	}
}
