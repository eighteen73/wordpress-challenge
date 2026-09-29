<?php
/**
 * WooCommerce account and checkout entry points.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

defined( 'ABSPATH' ) || exit;

/** Protect WooCommerce account forms and guest checkout. */
class WooCommerce {
	use Singleton;

	/**
	 * Whether Store API schema registration has completed.
	 *
	 * @var bool
	 */
	private bool $checkout_data_registered = false;

	/** Register classic and Store API integrations. */
	public function setup(): void {
		add_action( 'woocommerce_register_form', [ $this, 'register_widget' ] );
		add_filter( 'woocommerce_process_registration_errors', [ $this, 'register_errors' ] );
		add_action( 'woocommerce_lostpassword_form', [ $this, 'reset_widget' ] );
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'checkout_widget' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'checkout_errors' ] );
		add_filter( 'rest_request_before_callbacks', [ $this, 'store_checkout' ], 10, 3 );
		add_action( 'wp_enqueue_scripts', [ $this, 'block_assets' ], 20 );
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_checkout_data' ] );
		add_action( 'init', [ $this, 'register_checkout_data' ] );
	}

	/** Declare the Checkout Block extension token field. */
	public function register_checkout_data(): void {
		if ( $this->checkout_data_registered || ! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\StoreApi' ) ) {
			return;
		}
		$extend                         = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class );
		$this->checkout_data_registered = true;
		$extend->register_endpoint_data(
			[
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
				'namespace'       => 'form-challenge',
				'schema_callback' => static fn(): array => [
					'token' => [
						'description' => __( 'Challenge verification token.', 'challenge' ),
						'type'        => 'string',
						'context'     => [ 'view', 'edit' ],
						'optional'    => true,
					],
				],
				'schema_type'     => ARRAY_A,
			]
		);
	}

	/** Render the My Account registration widget. */
	public function register_widget(): void {
		Challenge::instance()->render( 'wc_register' );
	}

	/**
	 * Reject unverified My Account registrations.
	 *
	 * @param \WP_Error $errors WooCommerce registration errors.
	 * @return \WP_Error Updated errors.
	 */
	public function register_errors( \WP_Error $errors ): \WP_Error {
		if ( ! Challenge::instance()->verify( 'wc_register' ) ) {
			$errors->add( 'challenge_failed', __( 'Verification failed. Please try again.', 'challenge' ) );
		}
		return $errors;
	}

	/** Render the My Account lost-password widget. */
	public function reset_widget(): void {
		Challenge::instance()->render( 'wc_reset_request' );
	}

	/** Render a widget at guest classic checkout. */
	public function checkout_widget(): void {
		if ( ! is_user_logged_in() ) {
			Challenge::instance()->render( 'wc_checkout' );
		}
	}

	/** Reject unverified classic checkout submissions. */
	public function checkout_errors(): void {
		if ( ! is_user_logged_in() && ! Challenge::instance()->verify( 'wc_checkout' ) ) {
			wc_add_notice( __( 'Verification failed. Please try again.', 'challenge' ), 'error' );
		}
	}

	/**
	 * Check guest Checkout Block submissions before the REST route callback.
	 *
	 * @param mixed            $response Early REST response.
	 * @param array            $handler  Matched REST handler.
	 * @param \WP_REST_Request $request  Checkout request.
	 * @return mixed Original response or a verification error.
	 */
	public function store_checkout( $response, $handler, \WP_REST_Request $request ) {
		if ( null !== $response || ! Challenge::instance()->active() || is_user_logged_in() || 'POST' !== $request->get_method() || ! preg_match( '~^/wc/store(?:/v[0-9]+)?/checkout$~', $request->get_route() ) ) {
			return $response;
		}
		$extensions = $request->get_param( 'extensions' );
		$token      = is_array( $extensions ) && isset( $extensions['form-challenge']['token'] ) && is_string( $extensions['form-challenge']['token'] ) ? $extensions['form-challenge']['token'] : '';
		if ( ! Challenge::instance()->verify( 'wc_checkout', $token ) ) {
			return new \WP_Error( 'challenge_failed', __( 'Verification failed. Please try again.', 'challenge' ), [ 'status' => 400 ] );
		}
		return $response;
	}

	/** Enqueue the Checkout Block integration on its checkout page. */
	public function block_assets(): void {
		if ( ! Challenge::instance()->active() || is_user_logged_in() || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		$page = get_post( wc_get_page_id( 'checkout' ) );
		if ( ! $page || ! has_block( 'woocommerce/checkout', $page ) ) {
			return;
		}
		wp_enqueue_script( 'form-challenge-checkout', CHALLENGE_URL . 'assets/checkout.js', [ 'form-challenge', 'wp-plugins', 'wp-element', 'wp-data', 'wc-blocks-checkout' ], '0.1.0', true );
		wp_add_inline_script( 'form-challenge-checkout', 'window.formChallengeCheckout = ' . wp_json_encode( [ 'markup' => Challenge::instance()->driver()->render( 'wc_checkout' ) ] ) . ';', 'before' );
	}
}
