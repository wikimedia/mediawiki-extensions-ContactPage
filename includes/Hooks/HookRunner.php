<?php

namespace MediaWiki\Extension\ContactPage\Hooks;

use MediaWiki\HookContainer\HookContainer;

/**
 * This is a hook runner class, see docs/Hooks.md in core.
 * @internal
 */
class HookRunner implements
	ContactFormHook,
	ContactFromCompleteHook,
	\MediaWiki\Hook\EmailUserFormHook
{
	private HookContainer $hookContainer;

	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/**
	 * @inheritDoc
	 */
	public function onContactForm(
		&$contactRecipientAddress, &$replyTo, &$subject, &$text, $formType, $formData
	) {
		return $this->hookContainer->run(
			'ContactForm',
			[ &$contactRecipientAddress, &$replyTo, &$subject, &$text, $formType, $formData ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onContactFromComplete( $contactRecipientAddress, $replyTo, $subject, $text ) {
		return $this->hookContainer->run(
			'ContactFromComplete',
			[ $contactRecipientAddress, $replyTo, $subject, $text ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onEmailUserForm( &$form ) {
		return $this->hookContainer->run(
			'EmailUserForm',
			[ &$form ]
		);
	}
}
