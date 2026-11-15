<?php
/**
 * PeeStream AutoPublish Webhook Handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PeeStream_Webhook_Handler {
	public static function verify_signature( $payload, $signature, $secret ) {
		if ( empty( $secret ) || empty( $signature ) || ! is_string( $payload ) ) {
			return false;
		}

		$signature = trim( (string) $signature );
		if ( 0 === stripos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $signature ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $payload, $secret );
		return hash_equals( $expected, $signature );
	}
}
