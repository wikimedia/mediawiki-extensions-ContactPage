<?php

namespace MediaWiki\Extension\ContactPage\Hooks;

use MailAddress;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "ContactForm" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface ContactFormHook {
	/**
	 * @param MailAddress &$contactRecipientAddress
	 * @param MailAddress|null &$replyTo
	 * @param string &$subject
	 * @param string &$text
	 * @param string $formType
	 * @param array $formData
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onContactForm(
		&$contactRecipientAddress,
		&$replyTo,
		&$subject,
		&$text,
		$formType,
		$formData
	);
}
