<?php
/**
 * Google reCAPTCHA v2 checkbox and v3 score drivers.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge\Driver;

defined( 'ABSPATH' ) || exit;


/** Google reCAPTCHA v2 checkbox or v3 score provider. */
class Recaptcha extends Remote {
	/**
	 * Store the selected reCAPTCHA variant and keys.
	 *
	 * @param array  $options Saved provider credentials.
	 * @param string $version Selected variant.
	 */
	public function __construct( private readonly array $options, private readonly string $version ) {}

	/** Check for variant-specific credentials. */
	public function configured(): bool {
		return ! empty( $this->options[ $this->version . '_site' ] ) && ! empty( $this->options[ $this->version . '_secret' ] );
	}

	/** Return the official reCAPTCHA script URL. */
	public function scripts(): array {
		return 'recaptcha_v3' === $this->version
			? [ 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $this->options['recaptcha_v3_site'] ) ]
			: [ 'https://www.google.com/recaptcha/api.js?render=explicit' ];
	}

	/**
	 * Render a checkbox or invisible placeholder.
	 *
	 * @param string $context Form context.
	 * @param bool   $inline  Explicit field placement.
	 * @return string Escaped widget markup.
	 */
	public function render( string $context, bool $inline = false ): string {
		return sprintf(
			'<div class="form-challenge" data-challenge="%s" data-context="%s" data-sitekey="%s">%s</div>',
			esc_attr( $this->version ),
			esc_attr( $context ),
			esc_attr( $this->options[ $this->version . '_site' ] ),
			'recaptcha_v2' === $this->version ? '<div class="form-challenge-widget"></div>' : ''
		);
	}

	/**
	 * Verify token, hostname, and v3 action and score.
	 *
	 * @param string $token   Submitted token.
	 * @param string $context Form context.
	 * @return bool Provider result.
	 */
	public function verify( string $token, string $context ): bool {
		$result = $this->post(
			'https://www.google.com/recaptcha/api/siteverify',
			[
				'secret'   => $this->options[ $this->version . '_secret' ],
				'response' => $token,
			]
		);
		if ( true !== ( $result['success'] ?? false ) || ! $this->hostname_matches( $result ) ) {
			return false;
		}
		if ( 'recaptcha_v3' !== $this->version ) {
			return true;
		}
		return ( $result['action'] ?? '' ) === $context && is_numeric( $result['score'] ?? null ) && (float) $result['score'] >= (float) ( $this->options['threshold'] ?? 0.5 );
	}
}
