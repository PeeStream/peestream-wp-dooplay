<?php
/**
 * PeeStream Webhook Handler (Hardened HMAC)
 * File: includes/class-peestream-webhook-handler.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Webhook_Handler {
	/**
	 * Verify raw body signature.
	 *
	 * @param string $payload Raw body.
	 * @param string $signature Header signature.
	 * @param string $secret Secret.
	 * @return bool
	 */
	public static function verify_signature( $payload, $signature, $secret ) {
		if ( empty( $secret ) || empty( $signature ) || ! is_string( $payload ) ) {
			return false;
		}
		$signature = trim( (string) $signature );
		// Accept `sha256=<hex>` or raw lowercase hex.
		if ( 0 === stripos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $signature ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $payload, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Dedupe by event_id with a 72-hour TTL.
	 *
	 * @param string $event_id Unique event ID.
	 * @return bool True if duplicate.
	 */
	public static function is_duplicate( $event_id ) {
		$event_id = trim( (string) $event_id );
		if ( empty( $event_id ) ) {
			return false;
		}
		$key = 'peestream_webhook_processed_' . hash( 'sha256', $event_id );
		return (bool) get_transient( $key );
	}

	/**
	 * Mark event as processed.
	 *
	 * @param string $event_id Unique event ID.
	 */
	public static function mark_event_processed( $event_id ) {
		$event_id = trim( (string) $event_id );
		if ( empty( $event_id ) ) {
			return;
		}
		$key = 'peestream_webhook_processed_' . hash( 'sha256', $event_id );
		set_transient( $key, 1, 72 * HOUR_IN_SECONDS );
	}
}
