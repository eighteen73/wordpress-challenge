<?php
/**
 * Focused provider verification checks without a WordPress database.
 */

namespace Eighteen73\Challenge\Driver {
	const ABSPATH = '/';

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function wp_safe_remote_post( $url, $args ) {
		$GLOBALS['challenge_request'] = [ $url, $args ];
		return $GLOBALS['challenge_response'];
	}

	function is_wp_error( $value ) {
		return false;
	}

	function wp_remote_retrieve_response_code( $response ) {
		return $response['status'];
	}

	function wp_remote_retrieve_body( $response ) {
		return json_encode( $response['body'] );
	}

	function home_url() {
		return 'https://example.org';
	}

	function wp_parse_url( $url, $part = -1 ) {
		return parse_url( $url, $part );
	}

	function esc_url( $url ) {
		return $url;
	}

	function esc_attr( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES );
	}
}

namespace Eighteen73\Challenge {
	function get_option() {
		return $GLOBALS['challenge_options'];
	}

	function is_user_logged_in() {
		return false;
	}

	function is_admin() {
		return $GLOBALS['challenge_admin'] ?? false;
	}

	function current_user_can( $capability, $user_id ) {
		return 'edit_user' === $capability && $user_id === ( $GLOBALS['challenge_editable_user'] ?? null );
	}

	function __( $message ) {
		return $message;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function wp_unslash( $value ) {
		return $value;
	}

	function sanitize_text_field( $value ) {
		return trim( $value );
	}

	function esc_url_raw( $url ) {
		return $url;
	}

	function add_settings_error( $option, $code, $message ) {
		$GLOBALS['challenge_settings_errors'][] = $code;
	}

	function wp_enqueue_script( $handle, $url ) {
		$GLOBALS['challenge_enqueued'][ $handle ] = $url;
	}

	function wp_add_inline_script( $handle, $code, $position ) {
		$GLOBALS['challenge_inline'][ $handle ] = [ $code, $position ];
		$GLOBALS['challenge_inline_count'] = ( $GLOBALS['challenge_inline_count'] ?? 0 ) + 1;
	}

	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

namespace {
	function wp_enqueue_script( $handle, $url, $dependencies = [] ) {
		$GLOBALS['challenge_dependencies'][ $handle ] = $dependencies;
		\Eighteen73\Challenge\wp_enqueue_script( $handle, $url );
	}
	function wp_add_inline_script( $handle, $code, $position ) {
		\Eighteen73\Challenge\wp_add_inline_script( $handle, $code, $position );
	}
	function wp_script_is( $handle, $status ) {
		return isset( $GLOBALS['challenge_enqueued'][ $handle ] );
	}
	class GFFormDisplay {
		public static bool $last_page = true;
		public static function is_last_page( $form ) {
			return self::$last_page;
		}
	}
	define( 'ABSPATH', '/' );
	define( 'CHALLENGE_URL', 'https://example.org/wp-content/plugins/challenge/' );
	class GFForms {}
	class GF_Field {}

	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
		public function add( $code, $message ) {
			$this->code = $code;
			$this->message = $message;
		}
	}

	class WP_User {
		public function __construct( public int $ID ) {}
	}

	class WP_REST_Request {
		public function __construct( private string $method, private string $route, private array $params = [] ) {}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_param( string $name ) {
			return $this->params[ $name ] ?? null;
		}
	}

	require __DIR__ . '/../includes/classes/Driver/Driver.php';
	require __DIR__ . '/../includes/classes/Driver/Remote.php';
	require __DIR__ . '/../includes/classes/Driver/Cap.php';
	require __DIR__ . '/../includes/classes/Driver/Turnstile.php';
	require __DIR__ . '/../includes/classes/Driver/Recaptcha.php';
	require __DIR__ . '/../includes/classes/Singleton.php';
	require __DIR__ . '/../includes/classes/Settings.php';
	require __DIR__ . '/../includes/classes/Challenge.php';
	require __DIR__ . '/../includes/classes/GravityForms.php';
	require __DIR__ . '/../includes/classes/GravityField.php';
	require __DIR__ . '/../includes/classes/WooCommerce.php';
	require __DIR__ . '/../includes/classes/WordPress.php';

	function check( bool $condition, string $case ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $case );
		}
	}

	$keys = [
		'cap_server' => 'https://cap.example.test/captcha/', 'cap_site' => 'site', 'cap_secret' => 'secret',
		'turnstile_site' => 'site', 'turnstile_secret' => 'secret',
		'recaptcha_v2_site' => 'site', 'recaptcha_v2_secret' => 'secret',
		'recaptcha_v3_site' => 'site', 'recaptcha_v3_secret' => 'secret',
		'threshold' => 0.5,
	];
	$v3 = new \Eighteen73\Challenge\Driver\Recaptcha( $keys, 'recaptcha_v3' );
	$GLOBALS['challenge_response'] = [ 'status' => 200, 'body' => [ 'success' => true, 'hostname' => 'example.org', 'score' => 0.9, 'action' => 'wp_register' ] ];
	check( $v3->verify( 'token', 'wp_register' ), 'v3 accepts a matching action and score' );
	check( ! $v3->verify( 'token', 'wc_checkout' ), 'v3 rejects tokens for another action' );
	$GLOBALS['challenge_response']['body']['score'] = 0.1;
	check( ! $v3->verify( 'token', 'wp_register' ), 'v3 rejects low scores' );
	$GLOBALS['challenge_response']['body']['score'] = 0.9;
	$GLOBALS['challenge_response']['body']['hostname'] = 'attacker.example';
	check( ! $v3->verify( 'token', 'wp_register' ), 'v3 rejects another hostname' );
	$turnstile = new \Eighteen73\Challenge\Driver\Turnstile( $keys );
	check( ! $turnstile->verify( 'token', 'wc_checkout' ), 'Turnstile rejects another hostname' );
	$GLOBALS['challenge_response']['body']['hostname'] = 'example.org';
	check( $turnstile->verify( 'token', 'wc_checkout' ), 'Turnstile accepts a verified token from this site' );
	$v2 = new \Eighteen73\Challenge\Driver\Recaptcha( $keys, 'recaptcha_v2' );
	check( $v2->verify( 'token', 'gf_12' ), 'v2 accepts a verified checkbox token' );
	$GLOBALS['challenge_response']['body']['success'] = false;
	check( ! $v2->verify( 'token', 'gf_12' ), 'v2 rejects an invalid token' );
	$GLOBALS['challenge_response'] = [ 'status' => 503, 'body' => [ 'success' => true ] ];
	$cap = new \Eighteen73\Challenge\Driver\Cap( $keys );
	check( ! ( new \Eighteen73\Challenge\Driver\Cap( array_diff_key( $keys, [ 'cap_server' => true ] ) ) )->configured(), 'Cap requires a server URL, with no implicit default' );
	check( ! ( new \Eighteen73\Challenge\Driver\Cap( array_merge( $keys, [ 'cap_site' => 'https://cap.example.test/' ] ) ) )->configured(), 'Cap requires a site key, not the server URL' );
	check( ! \Eighteen73\Challenge\Driver\Cap::valid_server_url( 'http://cap.example.test' ), 'Cap server URL requires HTTPS' );
	check( ! \Eighteen73\Challenge\Driver\Cap::valid_server_url( 'https://user@cap.example.test' ), 'Cap server URL cannot contain credentials' );
	check( ! \Eighteen73\Challenge\Driver\Cap::valid_server_url( 'https://cap.example.test/?key=secret' ), 'Cap server URL cannot contain query parameters' );
	check( $cap->configured(), 'Cap accepts a configured HTTPS server with a path' );
	check( $cap->scripts() === [ 'https://cap.example.test/captcha/assets/widget.js', 'https://cap.example.test/captcha/assets/floating.js' ], 'Cap assets use the configured server' );
	check( $cap->asset_urls() === [
		'CAP_CUSTOM_WASM_URL'   => 'https://cap.example.test/captcha/assets/cap_wasm_bg.wasm',
		'CAP_CUSTOM_HASHWX_URL' => 'https://cap.example.test/captcha/assets/hashwx.wasm',
		'CAP_PAKO_URL'          => 'https://cap.example.test/captcha/assets/pako_inflate.min.js',
	], 'Cap widget fallback assets use the configured server' );
	check( str_contains( $cap->render( 'gf_12' ), 'data-cap-api-endpoint="https://cap.example.test/captcha/site/"' ), 'Cap widget uses the configured server and site key' );
	check( str_contains( $cap->render( 'gf_12' ), 'data-floating="true"' ), 'Cap defaults to floating presentation' );
	check( str_contains( $cap->render( 'gf_12', true ), 'data-floating="false"' ), 'a placed Gravity Forms field stays inline with the floating default' );
	$visible_cap = new \Eighteen73\Challenge\Driver\Cap( array_merge( $keys, [ 'cap_display' => 'visible' ] ) );
	check( str_contains( $visible_cap->render( 'wc_checkout' ), 'data-floating="false"' ), 'visible presentation renders automatic widgets inline' );
	check( ! $cap->verify( 'token', 'wp_register' ), 'Cap rejects provider outages' );
	$GLOBALS['challenge_response'] = [ 'status' => 200, 'body' => [ 'success' => true ] ];
	check( $cap->verify( 'token', 'wp_register' ), 'Cap accepts a successful verification' );
	check( 'https://cap.example.test/captcha/site/siteverify' === $GLOBALS['challenge_request'][0], 'Cap verifies against the configured server and site' );

	$GLOBALS['challenge_options'] = [ 'driver' => 'cap', 'cap_server' => 'https://cap.example.test/captcha', 'cap_site' => 'site', 'cap_secret' => 'secret', 'gf_all' => 0 ];
	check( 'form_challenge' === \Eighteen73\Challenge\Settings::OPTION && 'form_challenge' === ( new \Eighteen73\Challenge\GravityField() )->type, 'settings and saved Gravity Forms fields use the unbranded identifier' );
	$gravity = \Eighteen73\Challenge\GravityForms::instance();
	$form = [ 'id' => 12, 'fields' => [] ];
	check( $gravity->validate( [ 'is_valid' => true, 'form' => $form ] )['is_valid'], 'field-only mode leaves forms without a Challenge field unprotected' );
	$form['fields'] = [ (object) [ 'type' => 'form_challenge' ] ];
	check( ! $gravity->validate( [ 'is_valid' => true, 'form' => $form ] )['is_valid'], 'field-only mode rejects unverified opted-in forms' );
	$GLOBALS['challenge_options']['gf_all'] = 1;
	check( ! $gravity->validate( [ 'is_valid' => true, 'form' => [ 'id' => 13, 'fields' => [] ] ] )['is_valid'], 'automatic mode rejects unverified forms without a field' );
	$_POST['gform_target_page_number_13'] = 999;
	check( ! $gravity->validate( [ 'is_valid' => true, 'form' => [ 'id' => 13, 'fields' => [] ] ] )['is_valid'], 'posted non-zero target cannot bypass a resolved final submission' );
	GFFormDisplay::$last_page = false;
	check( $gravity->validate( [ 'is_valid' => true, 'form' => [ 'id' => 13, 'fields' => [] ] ] )['is_valid'], 'resolved intermediate pages do not consume a token' );
	GFFormDisplay::$last_page = true;
	unset( $_POST['gform_target_page_number_13'] );
	$settings = \Eighteen73\Challenge\Settings::instance();
	check( 'floating' === $settings::options()['cap_display'], 'sites without a saved display setting keep floating presentation' );
	$GLOBALS['challenge_enqueued'] = [];
	\Eighteen73\Challenge\Challenge::instance()->enqueue();
	check( isset( $GLOBALS['challenge_enqueued']['form-challenge-floating'] ), 'floating presentation loads the floating script' );
	check( [ 'form-challenge' ] === $GLOBALS['challenge_dependencies']['form-challenge-floating'] && [ 'form-challenge-provider-0' ] === $GLOBALS['challenge_dependencies']['form-challenge'], 'Cap declares widget, integration and floating execution order' );
	\Eighteen73\Challenge\Challenge::instance()->enqueue();
	check( 1 === $GLOBALS['challenge_inline_count'], 'multiple forms emit Cap asset overrides only once per request' );
	$inline = $GLOBALS['challenge_inline']['form-challenge-provider-0'];
	check( 'before' === $inline[1] && 1 === preg_match( '/^Object\.assign\(window, (.+)\);$/', $inline[0], $matches ), 'Cap configures secondary assets before widget.js executes' );
	check( $cap->asset_urls() === json_decode( $matches[1], true ) && ! str_contains( $inline[0], 'jsdelivr.net' ), 'Cap browser asset overrides all use the configured server' );
	$GLOBALS['challenge_options']['cap_display'] = 'visible';
	$GLOBALS['challenge_enqueued'] = [];
	$GLOBALS['challenge_inline_count'] = 0;
	\Eighteen73\Challenge\Challenge::instance()->enqueue();
	check( ! isset( $GLOBALS['challenge_enqueued']['form-challenge-floating'] ) && isset( $GLOBALS['challenge_enqueued']['form-challenge-provider-0'] ), 'visible presentation loads only the widget script' );
	check( 1 === $GLOBALS['challenge_inline_count'], 'multiple forms emit Cap asset overrides only once per request' );
	$saved = $settings->sanitize( [ 'driver' => 'turnstile', 'turnstile_site' => 'other', 'gf_all' => 0 ] );
	check( 0 === $saved['gf_all'] && 'site' === $saved['cap_site'] && 'secret' === $saved['cap_secret'] && 'https://cap.example.test/captcha' === $saved['cap_server'] && 'visible' === $saved['cap_display'], 'switching providers preserves Cap configuration and the GF opt-in setting' );
	$display = $settings->sanitize( [ 'driver' => 'cap', 'cap_display' => 'floating' ] );
	check( 'floating' === $display['cap_display'], 'admins can switch Cap back to floating mode' );
	$invalid_display = $settings->sanitize( [ 'driver' => 'cap', 'cap_display' => 'unexpected' ] );
	check( 'floating' === $invalid_display['cap_display'], 'unknown Cap display values are not accepted' );
	$updated = $settings->sanitize( [ 'driver' => 'cap', 'cap_server' => 'https://hosted.example.test/' ] );
	check( 'https://hosted.example.test' === $updated['cap_server'], 'settings accept a different HTTPS Cap server' );
	$invalid = $settings->sanitize( [ 'driver' => 'cap', 'cap_server' => 'http://hosted.example.test' ] );
	check( 'https://cap.example.test/captcha' === $invalid['cap_server'] && in_array( 'cap_server_url', $GLOBALS['challenge_settings_errors'], true ), 'settings preserve the previous server on an invalid URL' );
	$rejected = $settings->sanitize( [ 'driver' => 'cap', 'cap_site' => 'https://cap.example.test/', 'gf_all' => 1 ] );
	check( 'site' === $rejected['cap_site'] && in_array( 'cap_site_url', $GLOBALS['challenge_settings_errors'], true ), 'admin settings reject a Cap server URL as a site key' );
	$woo = \Eighteen73\Challenge\WooCommerce::instance();
	$missing = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	check( $woo->store_checkout( null, [], $missing ) instanceof WP_Error, 'guest Store API checkout rejects missing tokens before processing' );
	foreach ( [ '/wc/store/v1/CHECKOUT', '/wc/store/v1/CheckOut', '/wc/store/Checkout' ] as $route ) {
		check( $woo->store_checkout( null, [], new WP_REST_Request( 'POST', $route ) ) instanceof WP_Error, 'route casing cannot bypass guest checkout verification: ' . $route );
	}
	$other_route = new WP_REST_Request( 'POST', '/wc/store/v1/cart' );
	check( null === $woo->store_checkout( null, [], $other_route ), 'other Store API routes are unaffected' );
	$valid = new WP_REST_Request( 'POST', '/wc/store/v1/checkout', [ 'extensions' => [ 'form-challenge' => [ 'token' => 'fresh' ] ] ] );
	check( null === $woo->store_checkout( null, [], $valid ), 'verified guest checkout reaches the route callback' );
	$wordpress = \Eighteen73\Challenge\WordPress::instance();
	$user = new WP_User( 123 );
	$_POST = [ 'action' => 'send-password-reset', 'user_id' => 123 ];
	$GLOBALS['challenge_admin'] = true;
	$GLOBALS['challenge_editable_user'] = 123;
	$errors = new WP_Error();
	$wordpress->reset_errors( $errors, $user );
	check( '' === $errors->code, 'authorised user-management reset emails do not require a public CAPTCHA' );
	$GLOBALS['challenge_editable_user'] = 456;
	$errors = new WP_Error();
	$wordpress->reset_errors( $errors, $user );
	check( 'challenge_failed' === $errors->code, 'an admin request without permission for the target user cannot bypass verification' );
	$GLOBALS['challenge_admin'] = false;
	$GLOBALS['challenge_editable_user'] = 123;
	$errors = new WP_Error();
	$wordpress->reset_errors( $errors, $user );
	check( 'challenge_failed' === $errors->code, 'spoofed admin action parameters on a public reset do not bypass verification' );
	$_POST = [ 'wc_reset_password' => 'true' ];
	$errors = new WP_Error();
	$wordpress->reset_errors( $errors, $user );
	check( 'challenge_failed' === $errors->code, 'public WooCommerce reset requests still require verification' );
	$_POST = [];
	print "Provider, settings and integration verification checks passed.\n";
}
