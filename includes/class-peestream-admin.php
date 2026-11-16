<?php
/**
 * PeeStream Admin Settings & Controller
 * File: includes/class-peestream-admin.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Admin {

	private $options;

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );
		
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_peestream_test_api', array( $this, 'ajax_test_api' ) );
	}

	public function register_admin_menu() {
		$icon_url = PEESTREAM_PLUGIN_URL . 'assets/images/icon.png';
		add_menu_page(
			__( 'PeeStream Settings', 'peestream-wp-dooplay' ),
			'PeeStream',
			'manage_options',
			'peestream-settings',
			array( $this, 'render_admin_page' ),
			$icon_url,
			81
		);
	}

	public function register_settings() {
		register_setting(
			'peestream_settings_group',
			'peestream_settings',
			array( $this, 'sanitize_settings' )
		);
	}

	public function sanitize_settings( $input ) {
		$output = array();
		$output['api_key'] = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : '';
		$output['site_id'] = isset( $input['site_id'] ) ? sanitize_text_field( trim( $input['site_id'] ) ) : '';
		
		$player_domain = isset( $input['player_domain'] ) ? esc_url_raw( trim( $input['player_domain'] ) ) : 'https://peeplayer.com';
		if ( 0 !== strpos( $player_domain, 'https://' ) ) {
			$player_domain = 'https://' . ltrim( preg_replace( '#^https?://#i', '', $player_domain ), '/' );
		}
		$output['player_domain'] = rtrim( $player_domain, '/' );

		$api_domain = isset( $input['api_domain'] ) ? esc_url_raw( trim( $input['api_domain'] ) ) : 'https://peestream.com';
		if ( 0 !== strpos( $api_domain, 'https://' ) ) {
			$api_domain = 'https://' . ltrim( preg_replace( '#^https?://#i', '', $api_domain ), '/' );
		}
		$output['api_domain'] = rtrim( $api_domain, '/' );

		$output['default_folder_id'] = isset( $input['default_folder_id'] ) ? absint( $input['default_folder_id'] ) : 0;
		$output['default_server_name'] = isset( $input['default_server_name'] ) ? sanitize_text_field( trim( $input['default_server_name'] ) ) : 'PeeStream HD';
		$output['default_language'] = isset( $input['default_language'] ) ? sanitize_text_field( trim( $input['default_language'] ) ) : 'English';
		$output['default_quality'] = isset( $input['default_quality'] ) ? sanitize_text_field( trim( $input['default_quality'] ) ) : '1080p';
		
		$secret = isset( $input['webhook_secret'] ) ? sanitize_text_field( trim( $input['webhook_secret'] ) ) : '';
		if ( strlen( $secret ) < 16 ) {
			$secret = wp_generate_password( 24, false );
		}
		$output['webhook_secret'] = $secret;
		$output['auto_create_posts'] = ! empty( $input['auto_create_posts'] ) ? '1' : '0';
		$output['default_post_status'] = ( isset( $input['default_post_status'] ) && 'publish' === $input['default_post_status'] ) ? 'publish' : 'draft';
		$output['default_post_type'] = isset( $input['default_post_type'] ) ? sanitize_key( $input['default_post_type'] ) : 'movies';

		return $output;
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'peestream' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'peestream-admin-css',
			PEESTREAM_PLUGIN_URL . 'assets/css/peestream-admin.css',
			array(),
			PEESTREAM_VERSION
		);

		wp_enqueue_script(
			'peestream-admin-js',
			PEESTREAM_PLUGIN_URL . 'assets/js/peestream-admin.js',
			array( 'jquery' ),
			PEESTREAM_VERSION,
			true
		);

		wp_localize_script(
			'peestream-admin-js',
			'peestreamAdminData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'peestream_admin_nonce' ),
			)
		);
	}

	public function ajax_test_api() {
		check_ajax_referer( 'peestream_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( trim( $_POST['api_key'] ) ) : '';
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter an API key.', 'peestream-wp-dooplay' ) ) );
		}

		$api_domain = ! empty( $this->options['api_domain'] ) ? rtrim( $this->options['api_domain'], '/' ) : 'https://peestream.com';
		if ( 0 !== strpos( $api_domain, 'https://' ) ) {
			$api_domain = 'https://peestream.com';
		}

		$url = $api_domain . '/api/account/info?key=' . rawurlencode( $api_key );

		$response = wp_safe_remote_get( $url, array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'PeeStream-WP-DooPlay/' . PEESTREAM_VERSION,
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid response from PeeStream API server.', 'peestream-wp-dooplay' ) ) );
		}

		$res = isset( $data['result'] ) ? $data['result'] : $data;
		$status = isset( $data['status'] ) ? (int) $data['status'] : (int) $code;
		$result_code = isset( $res['code'] ) ? sanitize_text_field( $res['code'] ) : '';
		$message = isset( $res['message'] ) ? sanitize_text_field( $res['message'] ) : ( isset( $data['msg'] ) ? sanitize_text_field( $data['msg'] ) : '' );

		if ( 200 !== $status ) {
			if ( 401 === $status && 'invalid_api_key' === $result_code ) {
				$message = __( 'Invalid API key. Please check your PeeStream API key.', 'peestream-wp-dooplay' );
			} elseif ( 403 === $status && 'account_inactive' === $result_code ) {
				$message = __( 'Account inactive or pending approval. Active PeeStream account required.', 'peestream-wp-dooplay' );
			} elseif ( 402 === $status && 'quota_exceeded' === $result_code ) {
				$message = __( 'PeeStream quota exceeded. Please top-up or upgrade plan.', 'peestream-wp-dooplay' );
			}

			if ( empty( $message ) ) {
				$message = __( 'API authentication failed.', 'peestream-wp-dooplay' );
			}

			wp_send_json_error( array( 'message' => $message ) );
		}

		// Strict validation: Success requires actual email or login data.
		// No fallbacks for email, balance, storage_used, or plan_tier.
		$email = isset( $res['email'] ) ? sanitize_email( $res['email'] ) : '';
		$login = isset( $res['login'] ) ? sanitize_text_field( $res['login'] ) : '';
		
		if ( empty( $email ) && empty( $login ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid response from PeeStream API server. Missing account identifiers.', 'peestream-wp-dooplay' ) ) );
		}

		wp_send_json_success( array(
			'email'        => $email,
			'login'        => $login,
			'balance'      => isset( $res['balance'] ) ? number_format( (float) $res['balance'], 2 ) . ' USD' : '',
			'storage_used' => isset( $res['storage_used'] ) ? sanitize_text_field( $res['storage_used'] ) : '',
			'plan_tier'    => isset( $res['tier'] ) ? sanitize_text_field( $res['tier'] ) : '',
			'message'      => __( 'API connection successful! Account active.', 'peestream-wp-dooplay' ),
		) );
	}

	public function render_admin_page() {
		$options = wp_parse_args( get_option( 'peestream_settings', array() ), array(
			'api_key'             => '',
			'site_id'             => '',
			'player_domain'       => 'https://peeplayer.com',
			'api_domain'          => 'https://peestream.com',
			'default_folder_id'   => '0',
			'default_server_name' => 'PeeStream Player',
			'default_language'    => 'English',
			'default_quality'     => '1080p',
			'webhook_secret'      => wp_generate_password( 24, false ),
			'auto_create_posts'   => '0', // Default OFF per P0 requirement
			'default_post_status' => 'draft',
			'default_post_type'   => 'movies',
		) );

		$webhook_endpoint = get_rest_url( null, 'peestream/v1/webhook' );
		?>
		<div class="wrap peestream-wrap">
			<div class="peestream-header">
				<div class="peestream-header-brand">
					<img src="<?php echo esc_url( PEESTREAM_PLUGIN_URL . 'assets/images/peestream-logo.png' ); ?>" alt="PeeStream Logo" class="peestream-header-logo">
					<div>
						<h1><?php esc_html_e( 'PeeStream Video Player & DooPlay Integration', 'peestream-wp-dooplay' ); ?></h1>
						<p class="peestream-tagline"><?php esc_html_e( 'High-performance video hosting, DooPlay native repeatable fields support, auto-migrator & webhook sync.', 'peestream-wp-dooplay' ); ?></p>
					</div>
				</div>
				<div class="peestream-header-badge">v<?php echo esc_html( PEESTREAM_VERSION ); ?></div>
			</div>

			<!-- Live API Status Card -->
			<div class="peestream-card peestream-status-card">
				<div class="peestream-status-inner">
					<div class="peestream-status-info">
						<span class="peestream-status-dot <?php echo ! empty( $options['api_key'] ) ? 'active' : ''; ?>"></span>
						<div>
							<h3 id="pst-status-title"><?php echo ! empty( $options['api_key'] ) ? esc_html__( 'Connected to PeeStream Cloud', 'peestream-wp-dooplay' ) : esc_html__( 'API Key Not Configured', 'peestream-wp-dooplay' ); ?></h3>
							<p id="pst-status-desc"><?php echo ! empty( $options['api_key'] ) ? esc_html__( 'Ready to stream and monetize video views.', 'peestream-wp-dooplay' ) : esc_html__( 'Please enter your API key below to unlock all features.', 'peestream-wp-dooplay' ); ?></p>
						</div>
					</div>
					<div class="peestream-status-actions">
						<button type="button" class="button button-secondary" id="pst-btn-check-api">
							<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Verify API Connection', 'peestream-wp-dooplay' ); ?>
						</button>
						<a href="https://peestream.com/?op=my_account" target="_blank" rel="noopener noreferrer" class="button button-primary">
							<?php esc_html_e( 'Get API Key', 'peestream-wp-dooplay' ); ?> &rarr;
						</a>
					</div>
				</div>

				<!-- Plan & Promo Tier Banner -->
				<div class="peestream-promo-banner" style="margin-top: 15px; padding: 12px 16px; background: #f0f6fc; border-left: 4px solid #0073aa; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
					<div>
						<div style="font-weight: 600; color: #1d2327; font-size: 13px;">
							<span class="dashicons dashicons-awards" style="color: #0073aa; margin-right: 4px;"></span>
							<?php esc_html_e( 'Current Tier: Early Adopter Promo (All Features Unlocked)', 'peestream-wp-dooplay' ); ?>
						</div>
						<div style="font-size: 12px; color: #50575e; margin-top: 3px;">
							<?php esc_html_e( 'All high-volume automation (AutoPublish V2 webhook sync and competitor video migrator) is 100% free during the launch promo. Basic player embedding remains free forever; dedicated mass-sync queues will later be available via PeeStream VIP webmaster addons (from $1/mo).', 'peestream-wp-dooplay' ); ?>
						</div>
					</div>
					<div>
						<span class="badge" style="background: #0073aa; color: #fff; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
							<?php esc_html_e( 'Free Launch Promo', 'peestream-wp-dooplay' ); ?>
						</span>
					</div>
				</div>

				<div id="pst-api-feedback" style="display:none;" class="peestream-notice"></div>
			</div>

			<form method="post" action="options.php" class="peestream-settings-form">
				<?php settings_fields( 'peestream_settings_group' ); ?>

				<div class="peestream-grid">
					<!-- Left Column: Core API & Player Settings -->
					<div class="peestream-col">
						<div class="peestream-card">
							<h2><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'API & CDN Settings', 'peestream-wp-dooplay' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Configure communication with PeeStream API and delivery endpoints.', 'peestream-wp-dooplay' ); ?></p>
							
							<table class="form-table">
								<tr>
									<th scope="row"><label for="pst_api_key"><?php esc_html_e( 'API Key', 'peestream-wp-dooplay' ); ?> <span class="required">*</span></label></th>
									<td>
										<input type="password" id="pst_api_key" name="peestream_settings[api_key]" value="<?php echo esc_attr( $options['api_key'] ); ?>" class="regular-text" autocomplete="off" placeholder="e.g. 52834b679a...">
										<button type="button" class="button button-small" onclick="pstToggleSecret('pst_api_key')"><?php esc_html_e( 'Show/Hide', 'peestream-wp-dooplay' ); ?></button>
										<p class="description"><?php esc_html_e( 'Found in your PeeStream dashboard under My Account -> API Key.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_site_id"><?php esc_html_e( 'PeeStream Site ID', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<input type="text" id="pst_site_id" name="peestream_settings[site_id]" value="<?php echo esc_attr( $options['site_id'] ); ?>" class="regular-text" placeholder="e.g. site_8f3k2m1q">
										<p class="description"><?php esc_html_e( 'PeeStream Site UID for this portal (used for multi-site routing and verification).', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_player_domain"><?php esc_html_e( 'Player Embed Domain', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<input type="url" id="pst_player_domain" name="peestream_settings[player_domain]" value="<?php echo esc_attr( $options['player_domain'] ); ?>" class="regular-text" placeholder="https://peeplayer.com">
										<p class="description"><?php esc_html_e( 'Default player domain used for iframe embeds (usually https://peeplayer.com).', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_api_domain"><?php esc_html_e( 'API Base Domain', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<input type="url" id="pst_api_domain" name="peestream_settings[api_domain]" value="<?php echo esc_attr( $options['api_domain'] ); ?>" class="regular-text" placeholder="https://peestream.com">
										<p class="description"><?php esc_html_e( 'Endpoint for remote uploads and file listings.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_folder_id"><?php esc_html_e( 'Default Folder ID', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<input type="number" id="pst_folder_id" name="peestream_settings[default_folder_id]" value="<?php echo esc_attr( $options['default_folder_id'] ); ?>" class="small-text" min="0">
										<p class="description"><?php esc_html_e( 'Folder ID where remote uploaded videos will be saved (0 = Root folder).', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
					</div>

					<!-- Right Column: DooPlay Specific Integration -->
					<div class="peestream-col">
						<div class="peestream-card">
							<h2><span class="dashicons dashicons-format-video"></span> <?php esc_html_e( 'DooPlay Player Preferences', 'peestream-wp-dooplay' ); ?></h2>
							<p class="description"><?php esc_html_e( 'How PeeStream servers appear inside DooPlay repeatable player tabs.', 'peestream-wp-dooplay' ); ?></p>

							<table class="form-table">
								<tr>
									<th scope="row"><label for="pst_server_name"><?php esc_html_e( 'Default Server Name', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<input type="text" id="pst_server_name" name="peestream_settings[default_server_name]" value="<?php echo esc_attr( $options['default_server_name'] ); ?>" class="regular-text" placeholder="PeeStream HD">
										<p class="description"><?php esc_html_e( 'Tab title displayed to viewers inside DooPlay player selector.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_language"><?php esc_html_e( 'Default Audio Language', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<select id="pst_language" name="peestream_settings[default_language]">
											<?php
											$languages = array( 'English', 'Spanish', 'Subbed', 'Latino', 'Portuguese', 'French', 'German', 'Italian', 'Russian', 'Turkish', 'Arabic', 'Hindi', 'Japanese' );
											foreach ( $languages as $lang ) {
												printf( '<option value="%s" %s>%s</option>', esc_attr( $lang ), selected( $options['default_language'], $lang, false ), esc_html( $lang ) );
											}
											?>
										</select>
										<p class="description"><?php esc_html_e( 'Language flag/label assigned to the player tab.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_quality"><?php esc_html_e( 'Default Quality Label', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<select id="pst_quality" name="peestream_settings[default_quality]">
											<?php
											$qualities = array( '1080p', '720p', '4K UltraHD', 'HD', 'CAM' );
											foreach ( $qualities as $q ) {
												printf( '<option value="%s" %s>%s</option>', esc_attr( $q ), selected( $options['default_quality'], $q, false ), esc_html( $q ) );
											}
											?>
										</select>
										<p class="description"><?php esc_html_e( 'Quality badge shown in player tabs.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<!-- Webhook Auto-Publish Card -->
						<div class="peestream-card">
							<h2><span class="dashicons dashicons-rest-api"></span> <?php esc_html_e( 'Webhook Auto-Publish', 'peestream-wp-dooplay' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Automatically receive TV episodes and movies from PeeStream encoding queues.', 'peestream-wp-dooplay' ); ?></p>

							<table class="form-table">
								<tr>
									<th scope="row"><?php esc_html_e( 'Auto-Create Episodes', 'peestream-wp-dooplay' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="peestream_settings[auto_create_posts]" value="1" <?php checked( '1', $options['auto_create_posts'] ); ?>>
											<?php esc_html_e( 'Create new episode posts if they do not exist yet in DooPlay.', 'peestream-wp-dooplay' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_post_status"><?php esc_html_e( 'Default Post Status', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<select id="pst_post_status" name="peestream_settings[default_post_status]">
											<option value="draft" <?php selected( $options['default_post_status'], 'draft' ); ?>><?php esc_html_e( 'Draft (Recommended for review)', 'peestream-wp-dooplay' ); ?></option>
											<option value="publish" <?php selected( $options['default_post_status'], 'publish' ); ?>><?php esc_html_e( 'Publish immediately', 'peestream-wp-dooplay' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'Status applied to newly created movies and episodes received via webhook.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="pst_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'peestream-wp-dooplay' ); ?></label></th>
									<td>
										<input type="text" id="pst_webhook_secret" name="peestream_settings[webhook_secret]" value="<?php echo esc_attr( $options['webhook_secret'] ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Secret authentication token to protect your webhook endpoint.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Webhook URL', 'peestream-wp-dooplay' ); ?></th>
									<td>
										<input type="text" readonly value="<?php echo esc_attr( $webhook_endpoint ); ?>" class="large-text code" id="pst_webhook_url" onclick="this.select()">
										<p class="description"><?php esc_html_e( 'Paste this webhook URL into your PeeStream Account Webhook settings.', 'peestream-wp-dooplay' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
					</div>
				</div>

				<div class="peestream-submit-bar">
					<?php submit_button( __( 'Save All Changes', 'peestream-wp-dooplay' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}
}
