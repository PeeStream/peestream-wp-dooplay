<?php
/**
 * PeeStream Webhook Handler Mock Test
 * File: tests/mock_webhook_test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$GLOBALS['mock_transients'] = array();

function get_transient( $key ) {
	return $GLOBALS['mock_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $expiration ) {
	$GLOBALS['mock_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['mock_transients'][ $key ] );
	return true;
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;

		public function __construct( $data = array(), $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $method;
		private $headers;
		private $body;
		private $json;
		private $body_params;

		public function __construct( $method = 'POST', $body = '', $headers = array(), $json = array(), $body_params = array() ) {
			$this->method      = $method;
			$this->body        = $body;
			$this->headers     = $headers;
			$this->json        = $json;
			$this->body_params = $body_params;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_body() {
			return $this->body;
		}

		public function get_header( $key ) {
			return $this->headers[ $key ] ?? '';
		}

		public function get_json_params() {
			return $this->json;
		}

		public function get_body_params() {
			return $this->body_params;
		}
	}
}

if ( ! class_exists( 'Mock_WPDB' ) ) {
	class Mock_WPDB {
		public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
		public function prepare( $query, ...$args ) {
			return $query;
		}
		public function get_var( $query, ...$args ) {
			return 0;
		}
		public function query( $query, ...$args ) {
			return 1;
		}

	public function update( $table, $data, $where = array(), $format = null, $where_format = null ) {
		return 1;
	}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $route, $args ) {
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = array() ) {
		$mock_options = array(
			'peestream_settings' => array(
				'webhook_secret'       => 'test_webhook_secret_1234567890',
				'player_domain'        => 'https://peeplayer.com',
				'default_server_name'  => 'PeeStream HD',
				'default_language'     => 'English',
				'default_quality'      => '1080p',
				'auto_create_posts'    => '1',
			'site_id'               => $GLOBALS['mock_site_id'] ?? '',
			),
		);
		return $mock_options[ $name ] ?? $default;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single ) {
		return '';
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id, $output = OBJECT ) {
		return null;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		return '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		return true;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post_id ) {
		return '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return absint( (string) $value );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt ) {
		return date( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $args = array(), $wp_error = false ) {
		return 0;
	}
}

$GLOBALS['mock_transients'] = array();
$GLOBALS['mock_site_id'] = '';

require_once __DIR__ . '/../includes/class-peestream-webhook-handler.php';
require_once __DIR__ . '/../includes/class-peestream-webhook.php';

class PeeStream_Webhook_Test extends PeeStream_Webhook {
	public function public_process_media_ready( $params, $client_ip, $event ) {
		return $this->process_media_ready( $params, $client_ip, $event );
	}
}

$passed = 0;
$failed = 0;

function it( $desc, $assertion ) {
	global $passed, $failed;
	if ( $assertion ) {
		echo "[PASS] {$desc}\n";
		$passed++;
	} else {
		echo "[FAIL] {$desc}\n";
		$failed++;
	}
}

$secret = 'test_webhook_secret_1234567890';
$payload = json_encode( array(
	'event_id'    => 'evt_abc123',
	'event'       => 'video_ready',
	'file_code'   => 'fc_safe123',
	'filecode'    => 'fc_safe123',
	'imdb_id'     => 'tt0903747',
	'season'      => '1',
	'episode'     => '1',
	'title'       => 'Test Video',
	'poster_url'  => 'https://example.com/poster.jpg',
	'poster'      => 'https://example.com/poster.jpg',
	'api_key'     => 'test_api_key',
	'player_domain' => 'https://peeplayer.com',
) );
$signature = hash_hmac( 'sha256', $payload, $secret );

it( 'Accepts valid sha256 HMAC signature', PeeStream_Webhook_Handler::verify_signature( $payload, $signature, $secret ) );
it( 'Accepts valid sha256 prefixed signature', PeeStream_Webhook_Handler::verify_signature( $payload, 'sha256=' . $signature, $secret ) );
it( 'Rejects missing signature', false === PeeStream_Webhook_Handler::verify_signature( $payload, '', $secret ) );
it( 'Rejects missing secret', false === PeeStream_Webhook_Handler::verify_signature( $payload, $signature, '' ) );
it( 'Rejects tampered signature', false === PeeStream_Webhook_Handler::verify_signature( $payload, str_repeat( 'a', 64 ), $secret ) );
it( 'Rejects malformed signature', false === PeeStream_Webhook_Handler::verify_signature( $payload, 'not-a-signature', $secret ) );
it( 'Rejects wrong secret', false === PeeStream_Webhook_Handler::verify_signature( $payload, $signature, 'different_secret' ) );
it( 'Rejects non-string payload', false === PeeStream_Webhook_Handler::verify_signature( array( 'a' => 1 ), $signature, $secret ) );

it( 'First event_id is not duplicate', false === PeeStream_Webhook_Handler::is_duplicate( 'evt_abc123' ) );
PeeStream_Webhook_Handler::mark_event_processed( 'evt_abc123' );
it( 'Second event_id is duplicate after marking', PeeStream_Webhook_Handler::is_duplicate( 'evt_abc123' ) );
it( 'Different event_id is not duplicate', false === PeeStream_Webhook_Handler::is_duplicate( 'evt_def456' ) );
delete_transient( 'peestream_webhook_processed_' . hash( 'sha256', 'evt_abc123' ) );
it( 'Duplicate event_id clears when transient is deleted', false === PeeStream_Webhook_Handler::is_duplicate( 'evt_abc123' ) );

$GLOBALS['wpdb'] = new Mock_WPDB();

// site.test
$site_test_payload = array(
	'event'       => 'site.test',
	'event_id'    => 'evt_site_test',
	'occurred_at' => date( 'Y-m-d\TH:i:s\Z' ),
	'site_id'     => 'site-1',
	'version'     => '2',
);
$site_test_body = json_encode( $site_test_payload );
$req = new WP_REST_Request( 'POST', $site_test_body, array(
	'x-peestream-signature' => 'sha256=' . hash_hmac( 'sha256', $site_test_body, $secret ),
), $site_test_payload );
$resp = ( new PeeStream_Webhook_Test() )->handle_webhook( $req );
it( 'site.test returns 200 OK', 200 === $resp->get_status() );
it( 'site.test returns status ok', 'ok' === $resp->get_data()['status'] );

$GLOBALS['mock_site_id'] = 'site-configured';
$media_payload = array(
	'event' => 'media.ready', 'event_id' => 'evt_site_mismatch',
	'occurred_at' => date( 'Y-m-d\TH:i:s\Z' ), 'file_code' => 'fc_site_mismatch',
	'site_id' => 'site-wrong', 'kind' => 'movie',
);
$media_body = json_encode( $media_payload );
$media_req = new WP_REST_Request( 'POST', $media_body, array(
	'x-peestream-signature' => 'sha256=' . hash_hmac( 'sha256', $media_body, $secret ),
), $media_payload );
$media_resp = ( new PeeStream_Webhook_Test() )->handle_webhook( $media_req );
it( 'media.ready rejects mismatched site_id with 403', 403 === $media_resp->get_status() );
it( 'media.ready mismatch reports Invalid site_id', 'Invalid site_id.' === $media_resp->get_data()['error'] );

$media_payload['event_id'] = 'evt_site_match';
$media_payload['file_code'] = 'fc_site_match';
$media_payload['site_id'] = 'site-configured';
$media_body = json_encode( $media_payload );
$media_req = new WP_REST_Request( 'POST', $media_body, array(
	'x-peestream-signature' => 'sha256=' . hash_hmac( 'sha256', $media_body, $secret ),
), $media_payload );
$media_resp = ( new PeeStream_Webhook_Test() )->handle_webhook( $media_req );
it( 'media.ready matching site_id proceeds beyond authorization', 400 !== $media_resp->get_status() && 'Invalid site_id.' !== ( $media_resp->get_data()['error'] ?? '' ) );

$GLOBALS['mock_site_id'] = '';
$site_test_payload['site_id'] = 'unregistered-site';
$site_test_body = json_encode( $site_test_payload );
$site_test_req = new WP_REST_Request( 'POST', $site_test_body, array(
	'x-peestream-signature' => 'sha256=' . hash_hmac( 'sha256', $site_test_body, $secret ),
), $site_test_payload );
$site_test_resp = ( new PeeStream_Webhook_Test() )->handle_webhook( $site_test_req );
it( 'site.test passes while site_id is unconfigured', 200 === $site_test_resp->get_status() && 'ok' === $site_test_resp->get_data()['status'] );

// unknown event
$unknown_payload = array(
	'event'       => 'unknown.future',
	'event_id'    => 'evt_unknown',
	'occurred_at' => date( 'Y-m-d\TH:i:s\Z' ),
);
$unknown_body = json_encode( $unknown_payload );
$req_unknown = new WP_REST_Request( 'POST', $unknown_body, array(
	'x-peestream-signature' => 'sha256=' . hash_hmac( 'sha256', $unknown_body, $secret ),
), $unknown_payload );
$resp_unknown = ( new PeeStream_Webhook_Test() )->handle_webhook( $req_unknown );
it( 'unknown event returns 200 OK', 200 === $resp_unknown->get_status() );
it( 'unknown event returns success true', true === $resp_unknown->get_data()['success'] );

// media.ready expired
$expired_payload = array(
	'event'       => 'media.ready',
	'event_id'    => 'evt_expired',
	'occurred_at' => date( 'Y-m-d\TH:i:s\Z', strtotime( '-4 days' ) ),
	'file_code'   => 'fc_expired',
	'kind'        => 'movie',
	'title'       => 'Expired Movie',
);
$expired_body = json_encode( $expired_payload );
$req_expired = new WP_REST_Request( 'POST', $expired_body, array(
	'x-peestream-signature' => 'sha256=' . hash_hmac( 'sha256', $expired_body, $secret ),
), $expired_payload );
$resp_expired = ( new PeeStream_Webhook_Test() )->public_process_media_ready( $req_expired->get_json_params(), '127.0.0.1', 'media.ready' );
it( 'media.ready expired returns 200 OK', 200 === $resp_expired->get_status() );
it( 'media.ready expired ignored', 'expired_ignored' === $resp_expired->get_data()['action'] );

// media.ready duplicate
PeeStream_Webhook_Handler::mark_event_processed( 'evt_duplicate' );
$duplicate_payload = array(
	'event'       => 'media.ready',
	'event_id'    => 'evt_duplicate',
	'occurred_at' => date( 'Y-m-d\TH:i:s\Z' ),
	'file_code'   => 'fc_duplicate',
	'kind'        => 'movie',
	'title'       => 'Duplicate Movie',
);
$duplicate_body = json_encode( $duplicate_payload );
$req_dup = new WP_REST_Request( 'POST', $duplicate_body, array(
	'x-peestream-signature' => 'sha256=' . hash_hmac( 'sha256', $duplicate_body, $secret ),
), $duplicate_payload );
$resp_dup = ( new PeeStream_Webhook_Test() )->public_process_media_ready( $req_dup->get_json_params(), '127.0.0.1', 'media.ready' );
it( 'media.ready duplicate returns 200 OK', 200 === $resp_dup->get_status() );
it( 'media.ready duplicate ignored', 'duplicate_ignored' === $resp_dup->get_data()['action'] );

echo "Mock Webhook Test: {$passed} passed, {$failed} failed.\n";
exit( $failed > 0 ? 1 : 0 );
