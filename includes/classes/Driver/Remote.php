<?php
/**
 * Shared remote verification transport.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge\Driver;

defined( 'ABSPATH' ) || exit;

/** HTTP transport shared by provider drivers. */
abstract class Remote implements Driver {
	/** Return this provider's script URLs. */
	abstract public function scripts(): array;

	/** Enqueue standard provider scripts before the shared browser integration. */
	public function enqueue(): void {
		$dependencies = [];
		foreach ( $this->scripts() as $index => $url ) {
			$handle = 'e73-challenge-provider-' . $index;
			wp_enqueue_script( $handle, $url, $dependencies, null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Provider-managed asset.
			$dependencies = [ $handle ];
		}
		wp_enqueue_script( 'e73-challenge', CHALLENGE_URL . 'assets/challenge.js', $dependencies, '0.1.0', true );
	}

	/**
	 * Post a verification request and parse a successful JSON response.
	 *
	 * @param string $url  Verification URL.
	 * @param array  $body Posted credentials and token.
	 * @param bool   $json Send JSON rather than form data.
	 * @return array<string, mixed> Decoded response or an empty array.
	 */
	protected function post( string $url, array $body, bool $json = false ): array {
		$args = [
			'timeout'     => 5,
			'redirection' => 0,
			'body'        => $json ? wp_json_encode( $body ) : $body,
		];
		if ( $json ) {
			$args['headers'] = [ 'Content-Type' => 'application/json' ];
		}
		$response = wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Bind verification to the current site's hostname.
	 *
	 * @param array $result Provider result.
	 * @return bool Whether the host matches.
	 */
	protected function hostname_matches( array $result ): bool {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && isset( $result['hostname'] ) && is_string( $result['hostname'] ) && strtolower( $host ) === strtolower( $result['hostname'] );
	}
}
