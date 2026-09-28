<?php
/**
 * Main plugin class.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

defined( 'ABSPATH' ) || exit;

/** Register the plugin's integrations. */
class Plugin {
	use Singleton;

	/** Register WordPress and optional third-party hooks. */
	public function setup(): void {
		Settings::instance()->setup();
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login' ] );
		add_action( 'gform_enqueue_scripts', [ GravityForms::instance(), 'enqueue' ] );
		WordPress::instance()->setup();
		add_action( 'gform_loaded', [ GravityForms::instance(), 'setup' ] );
		add_action( 'plugins_loaded', [ $this, 'integrations' ] );
	}

	/** Enqueue assets on WooCommerce pages containing protected forms. */
	public function enqueue_frontend(): void {
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_account_page() ) ) {
			Challenge::instance()->enqueue();
		}
	}

	/** Enqueue assets only on native registration and reset-request screens. */
	public function enqueue_login(): void {
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only script selection.
		if ( in_array( $action, [ 'register', 'lostpassword', 'retrievepassword' ], true ) ) {
			Challenge::instance()->enqueue();
		}
	}

	/** Initialise integrations only after their plugins have loaded. */
	public function integrations(): void {
		if ( class_exists( 'GF_Fields' ) ) {
			GravityForms::instance()->setup();
		}
		if ( class_exists( 'WooCommerce' ) ) {
			WooCommerce::instance()->setup();
		}
	}
}
