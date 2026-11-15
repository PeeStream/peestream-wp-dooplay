<?php
/**
 * Plugin Name: PeeStream AutoPublish
 * Description: Osnovni PeeStream AutoPublish prijemnik.
 * Version: 1.0.0
 * Author: PeeStream Team
 * Author URI: https://peestream.com
 * Text Domain: peestream-autopublish
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEESTREAM_AUTOPUBLISH_VERSION', '1.0.0' );
define( 'PEESTREAM_AUTOPUBLISH_FILE', __FILE__ );
define( 'PEESTREAM_AUTOPUBLISH_DIR', plugin_dir_path( __FILE__ ) );
define( 'PEESTREAM_AUTOPUBLISH_URL', plugin_dir_url( __FILE__ ) );

require_once PEESTREAM_AUTOPUBLISH_DIR . 'src/PeeStream_Webhook_Handler.php';

final class PeeStream_AutoPublish {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'peestream-autopublish', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
}

PeeStream_AutoPublish::get_instance();
