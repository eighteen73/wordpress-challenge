<?php
/**
 * Plugin Name:       Challenge
 * Description:       CAPTCHA protection for forms, accounts and guest checkout.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            eighteen73
 * Author URI:        https://eighteen73.co.uk
 * Text Domain:       challenge
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/eighteen73/challenge
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

defined( 'ABSPATH' ) || exit;

define( 'CHALLENGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHALLENGE_URL', plugin_dir_url( __FILE__ ) );

// Composer loads this namespace on managed sites. This fallback also supports a standalone plugin install.
spl_autoload_register(
	static function ( string $name ): void {
		$prefix = 'Eighteen73\\Challenge\\';
		if ( ! str_starts_with( $name, $prefix ) ) {
			return;
		}
		$file = CHALLENGE_PATH . 'includes/classes/' . str_replace( '\\', '/', substr( $name, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

Plugin::instance()->setup();
