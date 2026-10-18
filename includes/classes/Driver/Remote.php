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
			$handle = 'form-challenge-provider-' . $index;
			wp_enqueue_script( $handle, $url, $dependencies, null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Provider-managed asset.
			$dependencies = [ $handle ];
		}
		wp_enqueue_script( 'form-challenge', CHALLENGE_URL . 'assets/challenge.js', $dependencies, '0.1.0', true );
	}

	/**
	 * Post a verification request and parse a successful JSON response.
	 *
	 * @param string $url  Verification URL.
	 * @param array  $body Posted credentials and token.
	 * @param bool   $json Send JSON rather than form data.
	 * @return array<string, mixed>|VerificationOutage Decoded response, rejection or transient outage.
	 */
	protected function post( string $url, array $body, bool $json = false ): array|VerificationOutage {
		$args = [
			'timeout'     => 5,
			'redirection' => 0,
			'body'        => $json ? wp_json_encode( $body ) : $body,
		];
		if ( $json ) {
			$args['headers'] = [ 'Content-Type' => 'application/json' ];
		}
		$response = wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			// WP also uses http_request_failed for unsafe URLs and TLS/configuration errors.
			// Only recognise explicit connectivity/timeout failures; unknown errors stay closed.
			if ( 'http_request_failed' === $response->get_error_code() && preg_match( '/cURL error (?:5|6|7|28):|connection (?:refused|timed out)|network is unreachable|could not resolve host|php_network_getaddresses/i', $response->get_error_message() ) ) {
				return VerificationOutage::Connection;
			}
			return [];
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 500 && $status <= 599 ) {
			return VerificationOutage::Server;
		}
		if ( 200 !== $status ) {
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
