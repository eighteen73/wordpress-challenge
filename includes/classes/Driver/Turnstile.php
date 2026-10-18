<?php
/**
 * Cloudflare Turnstile Managed driver.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge\Driver;

defined( 'ABSPATH' ) || exit;

/** Cloudflare Turnstile Managed provider. */
class Turnstile extends Remote {
	/**
	 * Store the Turnstile keys.
	 *
	 * @param array $options Saved provider credentials.
	 */
	public function __construct( private readonly array $options ) {}

	/** Check for Turnstile credentials. */
	public function configured(): bool {
		return ! empty( $this->options['turnstile_site'] ) && ! empty( $this->options['turnstile_secret'] );
	}

	/** Return the official widget URL. */
	public function scripts(): array {
		return [ 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' ];
	}

	/**
	 * Render a managed widget placeholder.
	 *
	 * @param string $context Form context.
	 * @param bool   $inline  Explicit field placement.
	 * @return string Escaped widget markup.
	 */
	public function render( string $context, bool $inline = false ): string {
		return sprintf( '<div class="form-challenge" data-challenge="turnstile" data-context="%s" data-sitekey="%s"><div class="form-challenge-widget"></div></div>', esc_attr( $context ), esc_attr( $this->options['turnstile_site'] ) );
	}

	/**
	 * Verify a Turnstile token and its hostname.
	 *
	 * @param string $token   Submitted token.
	 * @param string $context Form context.
	 * @return bool|VerificationOutage Provider result.
	 */
	public function verify( string $token, string $context ): bool|VerificationOutage {
		$result = $this->post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			[
				'secret'   => $this->options['turnstile_secret'],
				'response' => $token,
			]
		);
		return $result instanceof VerificationOutage ? $result : true === ( $result['success'] ?? false ) && $this->hostname_matches( $result );
	}
}
