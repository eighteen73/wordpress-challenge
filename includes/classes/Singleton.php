<?php
/**
 * Singleton for hook registration classes.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge;

defined( 'ABSPATH' ) || exit;

trait Singleton {
	/**
	 * The single instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/** Hide direct construction. */
	private function __construct() {}

	/** Return the hook registration instance. */
	final public static function instance(): self {
		return self::$instance ??= new self();
	}
}
