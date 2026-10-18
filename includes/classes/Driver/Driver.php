<?php
/**
 * CAPTCHA provider contract.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge\Driver;

defined( 'ABSPATH' ) || exit;

/** CAPTCHA provider boundary. */
interface Driver {
	/** Check whether the selected provider has both keys. */
	public function configured(): bool;

	/**
	 * Enqueue provider assets and initialise the shared browser integration.
	 *
	 * @return void
	 */
	public function enqueue(): void;

	/**
	 * Render a widget or an invisible placeholder.
	 *
	 * @param string $context Trusted form context.
	 * @param bool   $inline  Whether placement was chosen in the form editor.
	 * @return string Escaped markup.
	 */
	public function render( string $context, bool $inline = false ): string;

	/**
	 * Validate a token for the expected form context.
	 *
	 * @param string $token   Submitted provider token.
	 * @param string $context Trusted form context.
	 * @return bool|VerificationOutage Acceptance, rejection or a transient provider outage.
	 */
	public function verify( string $token, string $context ): bool|VerificationOutage;
}
