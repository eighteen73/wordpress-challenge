<?php
/**
 * Provider selection, rendering and per-request verification.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

use Eighteen73\Challenge\Driver\Cap;
use Eighteen73\Challenge\Driver\Driver;
use Eighteen73\Challenge\Driver\Recaptcha;
use Eighteen73\Challenge\Driver\Turnstile;

defined( 'ABSPATH' ) || exit;

/** Route each protected submission through the selected driver. */
class Challenge {
	use Singleton;

	/**
	 * Per-request results for repeated validation hooks.
	 *
	 * @var array<string, bool>
	 */
	private array $verified = [];

	/** Return the selected provider. */
	public function driver(): Driver {
		$options = Settings::options();
		return match ( $options['driver'] ) {
			'turnstile'    => new Turnstile( $options ),
			'recaptcha_v2' => new Recaptcha( $options, 'recaptcha_v2' ),
			'recaptcha_v3' => new Recaptcha( $options, 'recaptcha_v3' ),
			default        => new Cap( $options ),
		};
	}

	/** Check whether verification is enabled. */
	public function active(): bool {
		return $this->driver()->configured();
	}

	/**
	 * Output escaped provider markup.
	 *
	 * @param string $context Trusted form context.
	 * @param bool   $inline  Explicit widget placement.
	 */
	public function render( string $context, bool $inline = false ): void {
		if ( $this->active() ) {
			// Provider markup contains only escaped administrator-controlled attributes.
			echo $this->driver()->render( $context, $inline ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Verify a token exactly once per context in this request.
	 *
	 * @param string      $context Trusted form context.
	 * @param string|null $token   Token from a REST request or the current form.
	 * @return bool Whether the submission may proceed.
	 */
	public function verify( string $context, ?string $token = null ): bool {
		if ( ! $this->active() ) {
			return true;
		}
		if ( null === $token ) {
			$token = isset( $_POST['form_challenge_token'] ) && is_string( $_POST['form_challenge_token'] ) ? sanitize_text_field( wp_unslash( $_POST['form_challenge_token'] ) ) : '';
		}
		if ( '' === $token || strlen( $token ) > 4096 ) {
			return false;
		}

		return $this->verified[ hash( 'sha256', $context . ':' . $token ) ] ??= $this->driver()->verify( $token, $context );
	}

	/** Enqueue scripts for the selected provider and frontend widgets. */
	public function enqueue(): void {
		if ( ! $this->active() ) {
			return;
		}
		$this->driver()->enqueue();
	}
}
