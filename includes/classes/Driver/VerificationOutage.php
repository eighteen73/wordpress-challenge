<?php
/**
 * Transient failures eligible for the submission fallback policy.
 *
 * @package Eighteen73\Challenge
 */

namespace Eighteen73\Challenge\Driver;

defined( 'ABSPATH' ) || exit;

/** Distinguish provider outages from rejected verification. */
enum VerificationOutage: string {
	case Connection = 'connection_failure';
	case Server     = 'http_5xx';
}
