<?php
/**
 * Cap driver for compatible hosted or self-hosted instances.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge\Driver;

defined( 'ABSPATH' ) || exit;

/** Cap provider. */
class Cap extends Remote {
	/**
	 * Check that a Cap base URL is a secure URL without embedded credentials or parameters.
	 *
	 * @param string $url Cap server base URL.
	 * @return bool Whether the URL is suitable for widget assets and verification.
	 */
	public static function valid_server_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return false !== filter_var( $url, FILTER_VALIDATE_URL )
			&& is_array( $parts )
			&& 'https' === strtolower( $parts['scheme'] ?? '' )
			&& ! empty( $parts['host'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['query'] )
			&& ! isset( $parts['fragment'] );
	}

	/**
	 * Store the Cap keys.
	 *
	 * @param array $options Saved provider credentials.
	 */
	public function __construct( private readonly array $options ) {}

	/** Check for Cap credentials. */
	public function configured(): bool {
		return ! empty( $this->options['cap_server'] )
			&& self::valid_server_url( $this->options['cap_server'] )
			&& ! empty( $this->options['cap_site'] )
			&& ! preg_match( '~^https?://~i', $this->options['cap_site'] )
			&& ! empty( $this->options['cap_secret'] );
	}

	/** Return the site's Cap API endpoint. */
	private function endpoint(): string {
		return rtrim( $this->options['cap_server'], '/' ) . '/' . rawurlencode( $this->options['cap_site'] ) . '/';
	}

	/** Return the base path for Cap's browser assets. */
	private function assets_base(): string {
		return rtrim( $this->options['cap_server'], '/' ) . '/assets/';
	}

	/** Return the server-hosted widget and floating script URLs. */
	public function scripts(): array {
		$base = $this->assets_base();
		return [ $base . 'widget.js', $base . 'floating.js' ];
	}

	/** Initialise Cap's asset overrides before the widget and floating behaviour afterwards. */
	public function enqueue(): void {
		$base = $this->assets_base();
		if ( ! wp_script_is( 'e73-challenge-provider-0', 'enqueued' ) ) {
			wp_enqueue_script( 'e73-challenge-provider-0', $base . 'widget.js', [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Provider-managed asset.
			wp_add_inline_script(
				'e73-challenge-provider-0',
				'Object.assign(window, ' . wp_json_encode( $this->asset_urls(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');',
				'before'
			);
		}
		wp_enqueue_script( 'e73-challenge', CHALLENGE_URL . 'assets/challenge.js', [ 'e73-challenge-provider-0' ], '0.1.0', true );
		if ( 'visible' !== ( $this->options['cap_display'] ?? 'floating' ) ) {
			wp_enqueue_script( 'e73-challenge-floating', $base . 'floating.js', [ 'e73-challenge' ], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Provider-managed asset.
		}
	}

	/**
	 * Point the widget's WASM and older-browser fallback at the same server.
	 *
	 * @return array<string, string> Cap's browser asset overrides.
	 */
	public function asset_urls(): array {
		$base = $this->assets_base();
		return [
			'CAP_CUSTOM_WASM_URL'   => $base . 'cap_wasm_bg.wasm',
			'CAP_CUSTOM_HASHWX_URL' => $base . 'hashwx.wasm',
			'CAP_PAKO_URL'          => $base . 'pako_inflate.min.js',
		];
	}

	/**
	 * Render Cap's widget.
	 *
	 * @param string $context Form context.
	 * @param bool   $inline  Disable floating presentation.
	 * @return string Escaped widget markup.
	 */
	public function render( string $context, bool $inline = false ): string {
		$floating = ! $inline && 'visible' !== ( $this->options['cap_display'] ?? 'floating' );
		return sprintf(
			'<div class="e73-challenge" data-challenge="cap" data-context="%s" data-floating="%s"><cap-widget data-cap-api-endpoint="%s"></cap-widget></div>',
			esc_attr( $context ),
			$floating ? 'true' : 'false',
			esc_url( $this->endpoint() )
		);
	}

	/**
	 * Verify a Cap token.
	 *
	 * @param string $token   Submitted token.
	 * @param string $context Form context.
	 * @return bool Provider result.
	 */
	public function verify( string $token, string $context ): bool {
		$result = $this->post(
			$this->endpoint() . 'siteverify',
			[
				'secret'   => $this->options['cap_secret'],
				'response' => $token,
			],
			true
		);
		return true === ( $result['success'] ?? false );
	}
}
