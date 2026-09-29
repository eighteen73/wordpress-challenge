<?php
/**
 * Native WordPress settings for the selected provider.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

use Eighteen73\Challenge\Driver\Cap;

defined( 'ABSPATH' ) || exit;

/** Native provider settings and setup notice. */
class Settings {
	use Singleton;

	/** Settings API option name. */
	public const OPTION = 'form_challenge';
	/** Provider labels. */
	public const DRIVERS = [
		'cap'          => 'Cap',
		'turnstile'    => 'Cloudflare Turnstile',
		'recaptcha_v2' => 'Google reCAPTCHA v2',
		'recaptcha_v3' => 'Google reCAPTCHA v3',
	];

	/** Register the settings page and setup notice. */
	public function setup(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * Enqueue provider switching only on this settings page.
	 *
	 * @param string $hook Admin page identifier.
	 */
	public function enqueue( string $hook ): void {
		if ( 'settings_page_form-challenge' === $hook ) {
			wp_enqueue_script( 'form-challenge-settings', CHALLENGE_URL . 'assets/settings.js', [], '0.1.0', true );
		}
	}

	/** Return saved provider options with defaults. */
	public static function options(): array {
		$value = get_option( self::OPTION, [] );
		return array_merge(
			[
				'driver'      => 'cap',
				'cap_display' => 'floating',
				'gf_all'      => 1,
				'threshold'   => '0.5',
			],
			is_array( $value ) ? $value : []
		);
	}

	/** Add the settings page under Settings. */
	public function menu(): void {
		add_options_page( __( 'Challenge', 'challenge' ), __( 'Challenge', 'challenge' ), 'manage_options', 'form-challenge', [ $this, 'page' ] );
	}

	/** Register native controls for the site-wide provider. */
	public function register(): void {
		register_setting(
			'form_challenge',
			self::OPTION,
			[
				'sanitize_callback' => [ $this, 'sanitize' ],
				'type'              => 'object',
			]
		);
		add_settings_section( 'form_challenge_credentials', __( 'CAPTCHA provider', 'challenge' ), '__return_null', 'form-challenge' );
		add_settings_field( 'driver', __( 'Provider', 'challenge' ), [ $this, 'field' ], 'form-challenge', 'form_challenge_credentials', [ 'name' => 'driver' ] );
		$selected = self::options()['driver'];
		foreach ( self::DRIVERS as $provider => $label ) {
			$fields = match ( $provider ) {
				'cap'          => [ 'server', 'site', 'secret', 'display' ],
				'recaptcha_v3' => [ 'site', 'secret', 'threshold' ],
				default        => [ 'site', 'secret' ],
			};
			foreach ( $fields as $field ) {
				$name   = 'threshold' === $field ? $field : $provider . '_' . $field;
				$suffix = match ( $field ) {
					'server'    => __( 'server URL', 'challenge' ),
					'site'      => __( 'site key', 'challenge' ),
					'secret'    => __( 'secret key', 'challenge' ),
					'display'   => __( 'display', 'challenge' ),
					default     => __( 'score threshold', 'challenge' ),
				};
				$title = 'cap_site' === $name ? __( 'Cap site key (site ID)', 'challenge' ) : sprintf( '%s %s', $label, $suffix );
				add_settings_field(
					$name,
					$title,
					[ $this, 'field' ],
					'form-challenge',
					'form_challenge_credentials',
					[
						'name'  => $name,
						'class' => 'form-challenge-provider form-challenge-provider-' . $provider . ( $provider === $selected ? '' : ' hidden' ),
					]
				);
			}
		}
		if ( $this->gravity_forms_available() ) {
			add_settings_section( 'form_challenge_gravity_forms', __( 'Gravity Forms', 'challenge' ), '__return_null', 'form-challenge' );
			add_settings_field( 'gf_all', __( 'Automatic protection', 'challenge' ), [ $this, 'field' ], 'form-challenge', 'form_challenge_gravity_forms', [ 'name' => 'gf_all' ] );
		}
	}

	/** Check whether Gravity Forms is loaded before showing its controls. */
	private function gravity_forms_available(): bool {
		return class_exists( 'GFForms' ) || class_exists( 'GF_Fields' );
	}

	/**
	 * Validate keys and preserve secrets when their input is blank.
	 *
	 * @param mixed $input Submitted option value.
	 * @return array Sanitised settings.
	 */
	public function sanitize( $input ): array {
		$old            = self::options();
		$input          = is_array( $input ) ? $input : [];
		$next           = $old;
		$next['driver'] = isset( self::DRIVERS[ $input['driver'] ?? '' ] ) ? $input['driver'] : 'cap';
		if ( $this->gravity_forms_available() ) {
			$next['gf_all'] = empty( $input['gf_all'] ) ? 0 : 1;
		}
		if ( array_key_exists( 'cap_display', $input ) ) {
			$next['cap_display'] = in_array( $input['cap_display'], [ 'floating', 'visible' ], true ) ? $input['cap_display'] : 'floating';
		}
		if ( array_key_exists( 'cap_server', $input ) ) {
			$server = sanitize_text_field( wp_unslash( $input['cap_server'] ) );
			if ( '' === $server ) {
				$next['cap_server'] = '';
			} elseif ( Cap::valid_server_url( $server ) ) {
				$next['cap_server'] = rtrim( esc_url_raw( $server, [ 'https' ] ), '/' );
			} else {
				add_settings_error( self::OPTION, 'cap_server_url', __( 'Enter a valid HTTPS Cap server URL without a site key, query or fragment.', 'challenge' ) );
			}
		}
		foreach ( [ 'cap_site', 'turnstile_site', 'recaptcha_v2_site', 'recaptcha_v3_site' ] as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$next[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}
		if ( isset( $next['cap_site'] ) && $next['cap_site'] !== ( $old['cap_site'] ?? '' ) && preg_match( '~^https?://~i', $next['cap_site'] ) ) {
			add_settings_error( self::OPTION, 'cap_site_url', __( 'Enter only the Cap site key (site ID), not the Cap server URL.', 'challenge' ) );
			$next['cap_site'] = $old['cap_site'] ?? '';
		}
		foreach ( [ 'cap_secret', 'turnstile_secret', 'recaptcha_v2_secret', 'recaptcha_v3_secret' ] as $key ) {
			$secret = sanitize_text_field( wp_unslash( $input[ $key ] ?? '' ) );
			if ( '' !== $secret ) {
				$next[ $key ] = $secret;
			}
			if ( ! empty( $input[ 'clear_' . $key ] ) ) {
				$next[ $key ] = '';
			}
		}
		if ( array_key_exists( 'threshold', $input ) ) {
			$score             = filter_var( $input['threshold'], FILTER_VALIDATE_FLOAT );
			$next['threshold'] = false !== $score && $score >= 0 && $score <= 1 ? (string) $score : '0.5';
		}
		return $next;
	}

	/**
	 * Render a native control or provider selector.
	 *
	 * @param array $args Registered settings field arguments.
	 */
	public function field( array $args ): void {
		$name    = $args['name'];
		$options = self::options();
		$id      = 'form-challenge-' . $name;
		if ( 'driver' === $name ) {
			printf( '<select id="%s" name="%s[driver]">', esc_attr( $id ), esc_attr( self::OPTION ) );
			foreach ( self::DRIVERS as $value => $label ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $options['driver'], $value, false ), esc_html( $label ) );
			}
			echo '</select>';
			return;
		}
		if ( 'gf_all' === $name ) {
			printf( '<label><input type="checkbox" name="%s[gf_all]" value="1" %s> %s</label>', esc_attr( self::OPTION ), checked( $options['gf_all'], 1, false ), esc_html__( 'Protect every Gravity Form unless it has a Challenge field for inline placement.', 'challenge' ) );
			echo '<p class="description">' . esc_html__( 'When unchecked, only forms with a Challenge field are protected.', 'challenge' ) . '</p>';
			return;
		}
		if ( 'cap_display' === $name ) {
			printf( '<select id="%s" name="%s[cap_display]">', esc_attr( $id ), esc_attr( self::OPTION ) );
			foreach (
				[
					'floating' => __( 'Floating', 'challenge' ),
					'visible'  => __( 'Visible', 'challenge' ),
				] as $value => $label
			) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $options['cap_display'], $value, false ), esc_html( $label ) );
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Floating opens on submit; visible displays the widget in the form. A Gravity Forms Challenge field always displays inline.', 'challenge' ) . '</p>';
			return;
		}
		$secret = str_ends_with( $name, '_secret' );
		printf(
			'<input class="regular-text" id="%s" type="%s" name="%s[%s]" value="%s" %s />',
			esc_attr( $id ),
			$secret ? 'password' : ( 'threshold' === $name ? 'number' : ( 'cap_server' === $name ? 'url' : 'text' ) ),
			esc_attr( self::OPTION ),
			esc_attr( $name ),
			$secret ? '' : esc_attr( $options[ $name ] ?? '' ),
			'threshold' === $name ? 'min="0" max="1" step="0.1"' : ''
		);
		if ( $secret && ! empty( $options[ $name ] ) ) {
			printf( ' <span class="description">%s</span> <label><input type="checkbox" name="%s[clear_%s]" value="1"> %s</label>', esc_html__( 'Saved. Leave blank to keep.', 'challenge' ), esc_attr( self::OPTION ), esc_attr( $name ), esc_html__( 'Remove key', 'challenge' ) );
		}
		if ( 'cap_site' === $name ) {
			echo '<p class="description">' . esc_html__( 'Enter the site key (site ID) issued by your Cap provider, not a URL.', 'challenge' ) . '</p>';
		} elseif ( 'cap_server' === $name ) {
			echo '<p class="description">' . esc_html__( 'HTTPS base URL of your Cap server or hosted endpoint, without the site key.', 'challenge' ) . '</p>';
		}
	}

	/** Render the native Settings API form. */
	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options  = self::options();
		$provider = self::DRIVERS[ $options['driver'] ] ?? self::DRIVERS['cap'];
		$active   = Challenge::instance()->active();
		$places   = [];
		if ( $this->gravity_forms_available() ) {
			$places[] = ! empty( $options['gf_all'] )
				? __( 'Gravity Forms: all public forms, with optional inline placement using the Challenge field.', 'challenge' )
				: __( 'Gravity Forms: only forms containing a Challenge field.', 'challenge' );
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$places[] = __( 'WooCommerce: guest classic and Checkout Block checkouts.', 'challenge' );
			if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
				$places[] = __( 'WooCommerce: My Account registration.', 'challenge' );
			}
			$places[] = __( 'WooCommerce: My Account password reset requests.', 'challenge' );
		}
		if ( get_option( 'users_can_register' ) ) {
			$places[] = __( 'WordPress: user registration.', 'challenge' );
		}
		$places[] = __( 'WordPress: password reset requests.', 'challenge' );
		if ( $active ) {
			/* translators: %s: selected CAPTCHA provider name. */
			$description = sprintf( __( '%s is configured to protect submissions on:', 'challenge' ), $provider );
		} else {
			/* translators: %s: selected CAPTCHA provider name. */
			$description = sprintf( __( '%s is not configured yet. Once configured, it will protect submissions on:', 'challenge' ), $provider );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Challenge', 'challenge' ) . '</h1>';
		echo '<h2>' . esc_html__( 'Where protection applies', 'challenge' ) . '</h2>';
		echo '<p>' . esc_html( $description ) . '</p>';
		echo '<ul class="ul-disc">';
		foreach ( $places as $place ) {
			echo '<li>' . esc_html( $place ) . '</li>';
		}
		echo '</ul><form action="options.php" method="post">';
		settings_fields( 'form_challenge' );
		do_settings_sections( 'form-challenge' );
		submit_button();
		echo '</form></div>';
	}

	/** Warn administrators until the selected provider is configured. */
	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || Challenge::instance()->active() ) {
			return;
		}
		printf( '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>', esc_html__( 'Challenge is not protecting submissions until the selected provider is configured.', 'challenge' ), esc_url( admin_url( 'options-general.php?page=form-challenge' ) ), esc_html__( 'Configure Challenge', 'challenge' ) );
	}
}
