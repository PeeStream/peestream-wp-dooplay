<?php
/**
 * PeeStream API Error Mapping Test
 * File: tests/test_ajax_error_mapping.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'PEESTREAM_PLUGIN_URL' ) ) {
	define( 'PEESTREAM_PLUGIN_URL', 'https://example.com/wp-content/plugins/peestream-wp-dooplay/' );
}

if ( ! defined( 'PEESTREAM_VERSION' ) ) {
	define( 'PEESTREAM_VERSION', '1.0.0' );
}

// Global Mock Storage.
$GLOBALS['mock_wp_responses'] = array();
$GLOBALS['mock_wp_options']   = array(
	'peestream_settings' => array(
		'api_key'     => 'test_api_key_1234567890abcdef',
		'api_domain'  => 'https://peestream.com',
	),
);
$GLOBALS['mock_wp_json_output'] = null;

// Mock WP Functions.
if ( ! class_exists( 'MockJsonSendException' ) ) {
	class MockJsonSendException extends Exception {
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = array() ) {
		return $GLOBALS['mock_wp_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( $email, FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status = 200 ) {
		$GLOBALS['mock_wp_json_output'] = array(
			'success' => true,
			'data'    => $data,
			'status'  => $status,
		);
		throw new MockJsonSendException( 'json_sent' );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status = 400 ) {
		$GLOBALS['mock_wp_json_output'] = array(
			'success' => false,
			'data'    => $data,
			'status'  => $status,
		);
		throw new MockJsonSendException( 'json_sent' );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action, $field ) {
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 500;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = array() ) {
		return $GLOBALS['mock_wp_responses']['current'];
	}
}

// Mock WP_Error class.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

require_once __DIR__ . '/../includes/class-peestream-admin.php';

// Test Execution.
class TestPeeStreamApiErrorMapping {
	private $passed = 0;
	private $failed = 0;

	private function assert( $desc, $cond ) {
		if ( $cond ) {
			echo " [PASS] " . $desc . "\n";
			$this->passed++;
		} else {
			echo " [FAIL] " . $desc . "\n";
			$this->failed++;
		}
	}

	public function run() {
		$this->test_api_200_ok();
		$this->test_api_401_invalid_key();
		$this->test_api_403_account_inactive();
		$this->test_api_402_quota_exceeded();
		$this->test_api_invalid_json();
		$this->test_api_general_fallback_error();

		echo "\n";
		echo "======================================================\n";
		echo "TOTAL: " . $this->passed . " OK, " . $this->failed . " FAIL\n";
		echo "======================================================\n";
		return $this->failed === 0;
	}

	private function run_api_test( $mock_response ) {
		$GLOBALS['mock_wp_responses']['current'] = $mock_response;
		$GLOBALS['mock_wp_json_output']          = null;

		$admin = new PeeStream_Admin();
		$_POST['api_key'] = 'test_api_key_1234567890abcdef';
		try {
			$admin->ajax_test_api();
		} catch ( MockJsonSendException $e ) {
			// Expected behavior: json was sent and execution should stop.
		}

		return $GLOBALS['mock_wp_json_output'];
	}

	public function test_api_200_ok() {
		$res = $this->run_api_test( array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'status' => 200,
				'result' => array(
					'email'   => 'test@peestream.com',
					'login'   => 'testuser',
					'balance' => 100,
					'tier'    => 'Pro',
				),
			) ),
		) );

		$this->assert( 'API 200 OK: Success response returned', ! empty( $res['success'] ) );
		$this->assert( 'API 200 OK: Email is correct', $res['data']['email'] === 'test@peestream.com' );
		$this->assert( 'API 200 OK: Plan tier is correct', $res['data']['plan_tier'] === 'Pro' );
	}

	public function test_api_401_invalid_key() {
		$res = $this->run_api_test( array(
			'response' => array( 'code' => 401 ),
			'body'     => json_encode( array(
				'status' => 401,
				'result' => array(
					'code'    => 'invalid_api_key',
					'message' => 'API key not found',
				),
			) ),
		) );

		$this->assert( 'API 401: Error response returned', empty( $res['success'] ) );
		$this->assert( 'API 401: Correct error message', $res['data']['message'] === 'Invalid API key. Please check your PeeStream API key.' );
	}

	public function test_api_403_account_inactive() {
		$res = $this->run_api_test( array(
			'response' => array( 'code' => 403 ),
			'body'     => json_encode( array(
				'status' => 403,
				'result' => array(
					'code'    => 'account_inactive',
					'message' => 'Account inactive',
				),
			) ),
		) );

		$this->assert( 'API 403: Error response returned', empty( $res['success'] ) );
		$this->assert( 'API 403: Correct error message', $res['data']['message'] === 'Account inactive or pending approval. Active PeeStream account required.' );
	}

	public function test_api_402_quota_exceeded() {
		$res = $this->run_api_test( array(
			'response' => array( 'code' => 402 ),
			'body'     => json_encode( array(
				'status' => 402,
				'result' => array(
					'code'    => 'quota_exceeded',
					'message' => 'Quota exceeded',
				),
			) ),
		) );

		$this->assert( 'API 402: Error response returned', empty( $res['success'] ) );
		$this->assert( 'API 402: Correct error message', $res['data']['message'] === 'PeeStream quota exceeded. Please top-up or upgrade plan.' );
	}

	public function test_api_invalid_json() {
		$res = $this->run_api_test( array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html>Unexpected HTML or malformed response</html>',
		) );

		$this->assert( 'API Invalid JSON: Error response returned', empty( $res['success'] ) );
		$this->assert( 'API Invalid JSON: Correct error message', $res['data']['message'] === 'Invalid response from PeeStream API server.' );
	}

	public function test_api_general_fallback_error() {
		$res = $this->run_api_test( array(
			'response' => array( 'code' => 500 ),
			'body'     => json_encode( array(
				'status' => 500,
				'result' => array(
					'code'    => 'internal_error',
					'message' => 'Something went wrong.',
				),
			) ),
		) );

		$this->assert( 'API 500: Error response returned', empty( $res['success'] ) );
		$this->assert( 'API 500: Correct fallback error message', $res['data']['message'] === 'Something went wrong.' );
	}
}

$test = new TestPeeStreamApiErrorMapping();
exit( $test->run() ? 0 : 1 );
