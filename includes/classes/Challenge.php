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
use Eighteen73\Challenge\Driver\VerificationOutage;

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

		$provider = Settings::options()['driver'];
		$key      = hash( 'sha256', $provider . ':' . $context . ':' . $token );
		if ( array_key_exists( $key, $this->verified ) ) {
			return $this->verified[ $key ];
		}
		$result = $this->driver()->verify( $token, $context );
		if ( $result instanceof VerificationOutage ) {
			/**
			 * Allow submissions during transient server-side verification outages.
			 *
			 * @param bool   $allow    Whether to allow the submission. Defaults to true.
			 * @param string $context Protected submission context.
			 * @param string $provider Selected provider identifier.
			 * @param string $reason   Sanitised outage category, never a remote error message.
			 */
			$allow = (bool) apply_filters( 'challenge_allow_outage_fallback', true, $context, $provider, $result->value );
			if ( $allow ) {
				$event = [
					'time'     => time(),
					'provider' => $provider,
					'context'  => $context,
					'reason'   => $result->value,
				];
				update_option( 'form_challenge_last_fallback', $event, false );
				error_log( 'Challenge outage fallback: ' . wp_json_encode( $event ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational event without credentials or submission data.
			}
			$result = $allow;
		}
		$this->verified[ $key ] = $result;
		return $result;
	}

	/** Enqueue scripts for the selected provider and frontend widgets. */
	public function enqueue(): void {
		if ( ! $this->active() ) {
			return;
		}
		$this->driver()->enqueue();
	}
}
