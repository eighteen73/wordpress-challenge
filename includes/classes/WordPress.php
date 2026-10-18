<?php
/**
 * Core account forms.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

defined( 'ABSPATH' ) || exit;

/** Protect native registration and lost-password requests. */
class WordPress {
	use Singleton;

	/** Register core account form hooks. */
	public function setup(): void {
		add_action( 'register_form', [ $this, 'registration_widget' ] );
		add_filter( 'registration_errors', [ $this, 'registration_errors' ] );
		add_action( 'lostpassword_form', [ $this, 'reset_widget' ] );
		// Also runs for WooCommerce reset requests; use a distinct context for each form.
		add_action( 'lostpassword_post', [ $this, 'reset_errors' ], 10, 2 );
	}

	/** Render the registration challenge. */
	public function registration_widget(): void {
		Challenge::instance()->render( 'wp_register' );
	}

	/**
	 * Reject unverified registrations.
	 *
	 * @param \WP_Error $errors Existing validation errors.
	 * @return \WP_Error Updated errors.
	 */
	public function registration_errors( \WP_Error $errors ): \WP_Error {
		if ( ! Challenge::instance()->verify( 'wp_register' ) ) {
			$errors->add( 'challenge_failed', __( 'Verification failed. Please try again.', 'challenge' ) );
		}
		return $errors;
	}

	/** Render the lost-password request challenge. */
	public function reset_widget(): void {
		Challenge::instance()->render( 'wp_reset_request' );
	}

	/**
	 * Reject unverified WordPress or WooCommerce reset requests.
	 *
	 * @param \WP_Error      $errors    Mutable validation errors.
	 * @param \WP_User|false $user_data Account receiving the reset email, if found.
	 */
	public function reset_errors( \WP_Error $errors, $user_data = false ): void {
		// Core user-management screens already authorise and nonce-check these requests.
		if ( is_admin() && $user_data instanceof \WP_User && current_user_can( 'edit_user', $user_data->ID ) ) {
			return;
		}
		$context = isset( $_POST['wc_reset_password'] ) ? 'wc_reset_request' : 'wp_reset_request';
		if ( ! Challenge::instance()->verify( $context ) ) {
			$errors->add( 'challenge_failed', __( 'Verification failed. Please try again.', 'challenge' ) );
		}
	}
}
