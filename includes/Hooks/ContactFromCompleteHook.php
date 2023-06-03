<?php

namespace MediaWiki\Extension\ContactPage\Hooks;

use MailAddress;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "ContactFromComplete" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface ContactFromCompleteHook {
	/**
	 * @param MailAddress $contactRecipientAddress
	 * @param MailAddress|null $replyTo
	 * @param string $subject
	 * @param string $text
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onContactFromComplete(
		$contactRecipientAddress,
		$replyTo,
		$subject,
		$text
	);
}
