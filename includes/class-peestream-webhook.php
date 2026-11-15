<?php
/**
 * PeeStream REST API Webhook Receiver (Hardened)
 * File: includes/class-peestream-webhook.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Webhook {

	private $options;

	// Permitted webhook events from PeeStream backend
	private $allowed_events = array(
		'video_ready',
		'new_episode_ready',
		'movie_ready',
	);

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'peestream/v1', '/webhook', array(
			'methods'             => WP_REST_Server::CREATABLE, // POST only
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$this->options = get_option( 'peestream_settings', array() );
		// Strict POST check
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Method not allowed. Webhook endpoint requires POST.', 'peestream-wp-dooplay' ),
			), 405 );
		}

		// HMAC-SHA256 verification over raw body with legacy secret parameter fallback
		$raw_body = $request->get_body();
		$signature = $request->get_header( 'x-peestream-signature' );
		$secret = ! empty( $this->options['webhook_secret'] ) ? trim( (string) $this->options['webhook_secret'] ) : '';
		$req_secret = method_exists( $request, 'get_param' ) ? $request->get_param( 'secret' ) : null;
		$is_valid_hmac = ! empty( $signature ) && PeeStream_Webhook_Handler::verify_signature( $raw_body, $signature, $secret );
		$is_valid_secret = ! empty( $req_secret ) && hash_equals( (string)$secret, (string)$req_secret );
		if ( ! empty( $secret ) && ! $is_valid_hmac && ! $is_valid_secret ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error' => __( 'Invalid webhook signature.', 'peestream-wp-dooplay' ),
			), 401 );
		}

		global $wpdb;

		// 1. Rate Limiting Check (Max 60 requests per minute per IP)
		$client_ip = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$client_ip = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$client_ip = trim( $ips[0] );
		} else {
			$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		}
		$client_ip = substr( preg_replace( '/[^0-9a-fA-F:.]/', '', $client_ip ), 0, 45 );

		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$rate_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_webhooks} WHERE ip = %s AND received_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
			$client_ip
		) );

		if ( $rate_count > 60 ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Rate limit exceeded (max 60 requests per minute).', 'peestream-wp-dooplay' ),
			), 429 );
		}

		// 2. Parse JSON payload
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		// API v2 contract handling.
		$event = isset( $params['event'] ) ? trim( (string) $params['event'] ) : 'video_ready';

		if ( 'site.test' === $event ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		if ( ! in_array( $event, array( 'media.ready', 'video_ready', 'new_episode_ready', 'movie_ready' ), true ) ) {
			if ( false !== strpos( $event, '.' ) ) {
				return new WP_REST_Response( array( 'success' => true ), 200 );
			}
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Disallowed event type.', 'peestream-wp-dooplay' ),
			), 400 );
		}

		// Site ownership check applies only to supported media events, never to connection tests.
		// Prefer the nested admin setting; retain the standalone option as fallback.
		$configured_site_id = ! empty( $this->options['site_id'] )
			? trim( (string) $this->options['site_id'] )
			: trim( (string) get_option( 'peestream_site_id', '' ) );

		if ( 'media.ready' === $event ) {
			if ( '' !== $configured_site_id && ( ! isset( $params['site_id'] ) || ! hash_equals( $configured_site_id, trim( (string) $params['site_id'] ) ) ) ) {
				return new WP_REST_Response( array(
					'success' => false,
					'error'   => __( 'Invalid site_id.', 'peestream-wp-dooplay' ),
				), 403 );
			}
			return $this->process_media_ready( $params, $client_ip, $event );
		}

		if ( '' !== $configured_site_id && isset( $params['site_id'] ) && ! hash_equals( $configured_site_id, trim( (string) $params['site_id'] ) ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Invalid site_id.', 'peestream-wp-dooplay' ),
			), 403 );
		}

		// Extract & Validate file_code
		$file_code = '';
		if ( ! empty( $params['file_code'] ) ) {
			$file_code = trim( (string) $params['file_code'] );
		} elseif ( ! empty( $params['filecode'] ) ) {
			$file_code = trim( (string) $params['filecode'] );
		}

		if ( empty( $file_code ) || ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $file_code ) || strlen( $file_code ) > 64 ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Missing or invalid file_code parameter.', 'peestream-wp-dooplay' ),
			), 400 );
		}

		// 3. Atomic Replay Protection & Event Pre-reservation
		// Pre-reserve event atomically on UNIQUE KEY uq_fc_event (file_code, event) with status 'processing'
		$now_date = current_time( 'mysql', true );
		$reserved = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table_webhooks} (file_code, event, post_id, ip, received_at, status)
			 VALUES (%s, %s, 0, %s, %s, 'processing')",
			$file_code,
			$event,
			$client_ip,
			$now_date
		) );

		$has_lock = false;
		if ( $reserved ) {
			$has_lock = true;
		} else {
			// Row already exists or DB error - inspect current state
			$existing_record = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, post_id, status, received_at FROM {$table_webhooks} WHERE file_code = %s AND event = %s LIMIT 1",
				$file_code,
				$event
			) );

			if ( $existing_record ) {
				$status = $existing_record->status;
				if ( 'processed' === $status ) {
					return new WP_REST_Response( array(
						'success'   => true,
						'action'    => 'replay_ignored',
						'post_id'   => (int) $existing_record->post_id,
						'file_code' => $file_code,
						'message'   => __( 'Duplicate webhook event received. Video has already been processed.', 'peestream-wp-dooplay' ),
					), 200 );
				} elseif ( 'processing' === $status ) {
					$time_diff = time() - strtotime( $existing_record->received_at );
					if ( $time_diff < 60 ) {
						return new WP_REST_Response( array(
							'success'   => true,
							'action'    => 'concurrent_ignored',
							'file_code' => $file_code,
							'message'   => __( 'Webhook event is currently being processed concurrently. Replay ignored.', 'peestream-wp-dooplay' ),
						), 200 );
					}
					// Timed out processing lock (>60s) - atomic CAS takeover
					$taken = $wpdb->query( $wpdb->prepare(
						"UPDATE {$table_webhooks} SET ip = %s, received_at = %s, status = 'processing' WHERE id = %d AND status = 'processing' AND received_at = %s",
						$client_ip,
						$now_date,
						(int) $existing_record->id,
						$existing_record->received_at
					) );
					if ( $taken ) {
						$has_lock = true;
					} else {
						return new WP_REST_Response( array(
							'success'   => true,
							'action'    => 'concurrent_ignored',
							'file_code' => $file_code,
							'message'   => __( 'Webhook event takeover failed: concurrent worker won race. Replay ignored.', 'peestream-wp-dooplay' ),
						), 200 );
					}
				} elseif ( in_array( $status, array( 'failed', 'unmatched', 'ambiguous' ), true ) ) {
					// Controlled retry for failed or previously unresolved events with atomic CAS
					$taken = $wpdb->query( $wpdb->prepare(
						"UPDATE {$table_webhooks} SET ip = %s, received_at = %s, status = 'processing' WHERE id = %d AND status = %s AND received_at = %s",
						$client_ip,
						$now_date,
						(int) $existing_record->id,
						$status,
						$existing_record->received_at
					) );
					if ( $taken ) {
						$has_lock = true;
					} else {
						return new WP_REST_Response( array(
							'success'   => true,
							'action'    => 'concurrent_ignored',
							'file_code' => $file_code,
							'message'   => __( 'Webhook event retry takeover failed: concurrent worker won race. Replay ignored.', 'peestream-wp-dooplay' ),
						), 200 );
					}
				}
			}
		}

		if ( ! $has_lock ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Could not acquire lock or reservation for webhook event. Processing aborted.', 'peestream-wp-dooplay' ),
			), 500 );
		}

		// 4. Extract identifiers
		$imdb_id = isset( $params['imdb_id'] ) ? trim( (string) $params['imdb_id'] ) : ( isset( $params['imdb'] ) ? trim( (string) $params['imdb'] ) : '' );
		if ( ! empty( $imdb_id ) && ! preg_match( '/^tt\d{5,12}$/i', $imdb_id ) ) {
			$imdb_id = '';
		}

		$tmdb_id = isset( $params['tmdb_id'] ) ? absint( $params['tmdb_id'] ) : 0;
		$season  = isset( $params['season'] ) ? absint( $params['season'] ) : 0;
		$episode = isset( $params['episode'] ) ? absint( $params['episode'] ) : 0;

		$title = isset( $params['title'] ) ? sanitize_text_field( trim( wp_strip_all_tags( $params['title'] ) ) ) : 'Video';
		$series_title = isset( $params['series_title'] ) ? sanitize_text_field( trim( wp_strip_all_tags( $params['series_title'] ) ) ) : '';

		$poster_url = '';
		if ( ! empty( $params['poster'] ) && filter_var( $params['poster'], FILTER_VALIDATE_URL ) ) {
			$poster_url = esc_url_raw( trim( $params['poster'] ) );
		} elseif ( ! empty( $params['poster_url'] ) && filter_var( $params['poster_url'], FILTER_VALIDATE_URL ) ) {
			$poster_url = esc_url_raw( trim( $params['poster_url'] ) );
		}

		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$server_name   = ! empty( $this->options['default_server_name'] ) ? $this->options['default_server_name'] : 'PeeStream HD';
		$language      = ! empty( $this->options['default_language'] ) ? $this->options['default_language'] : 'English';
		$quality       = ! empty( $this->options['default_quality'] ) ? $this->options['default_quality'] : '1080p';
		$auto_create   = ! empty( $this->options['auto_create_posts'] ) && '1' === (string) $this->options['auto_create_posts'];

		$embed_url = $player_domain . '/e/' . $file_code;

		$server_entry = array(
			'name'    => $server_name,
			'select'  => 'iframe',
			'idioma'  => $language,
			'quality' => $quality,
			'url'     => $embed_url,
		);

		// CASE 1: TV Episode
		if ( $season > 0 && $episode > 0 ) {
			return $this->process_episode( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $series_title, $season, $episode, $poster_url, $auto_create, $client_ip, $event );
		}

		// CASE 2: Movie or Generic Video
		return $this->process_movie( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $poster_url, $auto_create, $client_ip, $event );
	}

	protected function process_media_ready( $params, $client_ip, $event ) {
		global $wpdb;

		$event_id = isset( $params['event_id'] ) ? trim( (string) $params['event_id'] ) : '';
		$occurred_at = isset( $params['occurred_at'] ) ? trim( (string) $params['occurred_at'] ) : '';
		$occurred_ts = strtotime( $occurred_at );

		if ( false !== $occurred_ts && ( time() - $occurred_ts ) > ( 72 * HOUR_IN_SECONDS ) ) {
			return new WP_REST_Response( array(
				'success' => true,
				'action'  => 'expired_ignored',
			), 200 );
		}

		if ( ! empty( $event_id ) && PeeStream_Webhook_Handler::is_duplicate( $event_id ) ) {
			return new WP_REST_Response( array(
				'success' => true,
				'action'  => 'duplicate_ignored',
			), 200 );
		}

		$file_code = '';
		if ( ! empty( $params['file_code'] ) ) {
			$file_code = trim( (string) $params['file_code'] );
		} elseif ( ! empty( $params['filecode'] ) ) {
			$file_code = trim( (string) $params['filecode'] );
		}

		if ( empty( $file_code ) || ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $file_code ) || strlen( $file_code ) > 64 ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Missing or invalid file_code parameter.', 'peestream-wp-dooplay' ),
			), 400 );
		}

		$now_date = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}peestream_webhooks (file_code, event, post_id, ip, received_at, status)
			 VALUES (%s, %s, 0, %s, %s, 'processing')",
			$file_code,
			$event,
			$client_ip,
			$now_date
		) );

		$imdb_id = isset( $params['imdb_id'] ) ? trim( (string) $params['imdb_id'] ) : '';
		$tmdb_id = isset( $params['tmdb_id'] ) ? absint( $params['tmdb_id'] ) : 0;
		$season  = isset( $params['season'] ) ? absint( $params['season'] ) : 0;
		$episode = isset( $params['episode'] ) ? absint( $params['episode'] ) : 0;

		$title = isset( $params['title'] ) ? sanitize_text_field( trim( wp_strip_all_tags( $params['title'] ) ) ) : 'Video';
		$series_title = isset( $params['series_title'] ) ? sanitize_text_field( trim( wp_strip_all_tags( $params['series_title'] ) ) ) : '';

		$poster_url = '';
		if ( ! empty( $params['poster'] ) && filter_var( $params['poster'], FILTER_VALIDATE_URL ) ) {
			$poster_url = esc_url_raw( trim( $params['poster'] ) );
		} elseif ( ! empty( $params['poster_url'] ) && filter_var( $params['poster_url'], FILTER_VALIDATE_URL ) ) {
			$poster_url = esc_url_raw( trim( $params['poster_url'] ) );
		}

		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$server_name   = ! empty( $this->options['default_server_name'] ) ? $this->options['default_server_name'] : 'PeeStream HD';
		$language      = ! empty( $this->options['default_language'] ) ? $this->options['default_language'] : 'English';
		$quality       = ! empty( $this->options['default_quality'] ) ? $this->options['default_quality'] : '1080p';
		$auto_create   = ! empty( $this->options['auto_create_posts'] ) && '1' === (string) $this->options['auto_create_posts'];

		$embed_url = ! empty( $params['player_url'] ) && filter_var( $params['player_url'], FILTER_VALIDATE_URL )
			? esc_url_raw( trim( $params['player_url'] ) )
			: $player_domain . '/e/' . $file_code;

		$server_entry = array(
			'name'    => $server_name,
			'select'  => 'iframe',
			'idioma'  => $language,
			'quality' => $quality,
			'url'     => $embed_url,
		);

		$kind = isset( $params['kind'] ) ? trim( (string) $params['kind'] ) : '';
		$is_episode = 'episode' === $kind || ( $season > 0 && $episode > 0 );

		if ( $is_episode ) {
			$response = $this->process_episode( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $series_title, $season, $episode, $poster_url, $auto_create, $client_ip, $event );
		} else {
			$response = $this->process_movie( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $poster_url, $auto_create, $client_ip, $event );
		}

		$response_data = ( is_object( $response ) && method_exists( $response, 'get_data' ) ) ? $response->get_data() : array();
		if ( ! empty( $event_id ) && 200 === $response->get_status() && ! empty( $response_data['success'] ) ) {
			PeeStream_Webhook_Handler::mark_event_processed( $event_id );
		}

		return $response;
	}

	private function process_episode( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $series_title, $season, $episode, $poster_url, $auto_create, $client_ip, $event ) {
		global $wpdb;
		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$now_date = current_time( 'mysql', true );

		// 1. Strict Parent TV Show Matching (Must match verified IMDb ID or TMDb ID with DISTINCT IDs)
		$parent_show_id = 0;
		$matching_shows = array();

		if ( ! empty( $imdb_id ) ) {
			$matching_shows = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'ids' OR pm.meta_key = 'imdb_id'))
				 WHERE p.post_type = 'tvshows' AND pm.meta_value = %s",
				$imdb_id
			) );
		}

		if ( empty( $matching_shows ) && $tmdb_id > 0 ) {
			$matching_shows = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'id' OR pm.meta_key = 'tmdb_id'))
				 WHERE p.post_type = 'tvshows' AND pm.meta_value = %d",
				$tmdb_id
			) );
		}

		$matching_shows = array_values( array_unique( array_map( 'intval', (array) $matching_shows ) ) );

		// Ambiguity check for parent TV shows: multiple distinct shows sharing the same ID must NOT be updated
		if ( count( $matching_shows ) > 1 ) {
			$wpdb->update(
				$table_webhooks,
				array( 'status' => 'ambiguous', 'received_at' => $now_date ),
				array( 'file_code' => $file_code, 'event' => $event )
			);
			return new WP_REST_Response( array(
				'success' => false,
				'action'  => 'ambiguous_match',
				'error'   => sprintf( 'Ambiguous match: %d TV shows share the same IMDb/TMDb identifier. Manual review required.', count( $matching_shows ) ),
			), 409 );
		}

		$parent_show_id = ! empty( $matching_shows ) ? (int) $matching_shows[0] : 0;

		// 2. Strict Episode Matching Within That Verified TV Show ONLY
		$matched_ep_id = 0;
		if ( $parent_show_id > 0 ) {
			$show_name = get_the_title( $parent_show_id );

			// In DooPlay, episodes link to parent show via postmeta 'serie' (show title or show ID)
			$query = "
				SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_s ON (p.ID = pm_s.post_id AND pm_s.meta_key = 'temporada' AND pm_s.meta_value = %d)
				INNER JOIN {$wpdb->postmeta} pm_e ON (p.ID = pm_e.post_id AND pm_e.meta_key = 'episodio' AND pm_e.meta_value = %d)
				INNER JOIN {$wpdb->postmeta} pm_p ON (p.ID = pm_p.post_id AND pm_p.meta_key = 'serie' AND (pm_p.meta_value = %s OR pm_p.meta_value = %s))
				WHERE p.post_type = 'episodes' AND p.post_status IN ('publish', 'draft')
			";

			$matching_eps = $wpdb->get_col( $wpdb->prepare( $query, $season, $episode, $show_name, (string) $parent_show_id ) );
			$matching_eps = array_values( array_unique( array_map( 'intval', (array) $matching_eps ) ) );

			if ( count( $matching_eps ) > 1 ) {
				$wpdb->update(
					$table_webhooks,
					array( 'status' => 'ambiguous', 'received_at' => $now_date ),
					array( 'file_code' => $file_code, 'event' => $event )
				);
				return new WP_REST_Response( array(
					'success' => false,
					'action'  => 'ambiguous_match',
					'error'   => sprintf( 'Ambiguous match: %d episodes share S%02dE%02d under TV show %s.', count( $matching_eps ), $season, $episode, esc_html( $show_name ) ),
				), 409 );
			}

			$matched_ep_id = ! empty( $matching_eps ) ? (int) $matching_eps[0] : 0;
		}

		// A. Update Existing Verified Episode
		if ( $matched_ep_id > 0 ) {
			$attached = $this->attach_server_to_post( $matched_ep_id, $server_entry, $file_code, $embed_url );
			if ( ! $attached ) {
				$wpdb->update(
					$table_webhooks,
					array( 'status' => 'failed', 'received_at' => $now_date ),
					array( 'file_code' => $file_code, 'event' => $event )
				);
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to persist repeatable_fields to episode post.' ), 500 );
			}

			$wpdb->update(
				$table_webhooks,
				array(
					'post_id'     => $matched_ep_id,
					'ip'          => $client_ip,
					'received_at' => $now_date,
					'status'      => 'processed',
				),
				array(
					'file_code' => $file_code,
					'event'     => $event,
				)
			);

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'episode_updated',
				'post_id'   => $matched_ep_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'Verified episode #%d updated with PeeStream player.', $matched_ep_id ),
			), 200 );
		}

		// B. Auto-Create Episode Post (ONLY if parent TV show is verified AND auto-create is explicitly enabled)
		if ( $auto_create && $parent_show_id > 0 ) {
			$show_name = get_the_title( $parent_show_id );
			$ep_post_title = sprintf( '%s: %dx%d', $show_name, $season, $episode );

			// Idempotent recovery check: Did a previous interrupted attempt already create this draft post?
			$existing_draft_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$table_webhooks} WHERE file_code = %s AND event = %s AND post_id > 0",
				$file_code,
				$event
			) );

			if ( ! $existing_draft_id ) {
				$existing_draft_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_peestream_webhook_filecode')
					 WHERE pm.meta_value = %s AND p.post_type = 'episodes' AND p.post_status = 'draft' LIMIT 1",
					$file_code
				) );
			}

			// Strict validation: Re-use draft ONLY if it strictly belongs to this show, season, and episode!
			// Never recover draft purely by title to prevent cross-show/cross-episode clobbering
			if ( $existing_draft_id > 0 ) {
				$draft_post = get_post( $existing_draft_id );
				if ( ! $draft_post || $draft_post->post_type !== 'episodes' ) {
					$existing_draft_id = 0;
				} else {
					$draft_season = get_post_meta( $existing_draft_id, 'temporada', true );
					$draft_episode = get_post_meta( $existing_draft_id, 'episodio', true );
					$draft_ids = get_post_meta( $existing_draft_id, 'ids', true );
					if ( ( $draft_season !== '' && (int) $draft_season !== (int) $season ) ||
					     ( $draft_episode !== '' && (int) $draft_episode !== (int) $episode ) ||
					     ( ! empty( $imdb_id ) && ! empty( $draft_ids ) && $draft_ids !== $imdb_id ) ) {
						$existing_draft_id = 0; // Mismatched draft rejected
					}
				}
			}

			$new_post_id = 0;
			$is_new_transaction = false;

			if ( $existing_draft_id > 0 ) {
				// Re-use verified existing draft post from interrupted attempt (zero duplicate post creation!)
				$new_post_id = $existing_draft_id;
			} else {
				$author_id = $this->get_default_author_id();

				$tx_started = $wpdb->query( 'START TRANSACTION' );
				if ( false === $tx_started ) {
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => 'Database transaction start failed.' ), 500 );
				}
				$is_new_transaction = true;

				$post_status = ! empty( $this->options['default_post_status'] ) && in_array( $this->options['default_post_status'], array( 'publish', 'draft' ), true )
					? $this->options['default_post_status']
					: 'draft';

				$new_post_id = wp_insert_post( array(
					'post_title'   => $ep_post_title,
					'post_content' => sprintf( 'Watch %s Season %d Episode %d online in full HD.', esc_html( $show_name ), $season, $episode ),
					'post_status'  => $post_status,
					'post_type'    => 'episodes',
					'post_author'  => $author_id,
					'meta_input'   => array(
						'_peestream_webhook_filecode' => $file_code,
						'_peestream_webhook_event'    => $event,
						'temporada'                   => $season,
						'episodio'                    => $episode,
						'serie'                       => $show_name,
					),
				), true );

				if ( empty( $new_post_id ) || is_wp_error( $new_post_id ) ) {
					$wpdb->query( 'ROLLBACK' );
					$err_msg = is_wp_error( $new_post_id ) ? $new_post_id->get_error_message() : 'Episode creation failed (returned ID 0).';
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => $err_msg ), 500 );
				}

				// Immediately link post ID to webhook audit record inside atomic transaction and verify link
				$wpdb->update(
					$table_webhooks,
					array( 'post_id' => $new_post_id ),
					array( 'file_code' => $file_code, 'event' => $event )
				);

				$verify_link = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$table_webhooks} WHERE file_code = %s AND event = %s",
					$file_code,
					$event
				) );

				if ( $verify_link !== (int) $new_post_id ) {
					$wpdb->query( 'ROLLBACK' );
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to verify webhook audit linking to episode draft.' ), 500 );
				}
			}

			// Assign DooPlay metadata
			update_post_meta( $new_post_id, 'temporada', $season );
			update_post_meta( $new_post_id, 'episodio', $episode );
			update_post_meta( $new_post_id, 'serie', $show_name );
			if ( ! empty( $imdb_id ) ) {
				update_post_meta( $new_post_id, 'ids', $imdb_id );
			}
			if ( ! empty( $poster_url ) ) {
				update_post_meta( $new_post_id, 'dt_backdrop', $poster_url );
			}

			$attached = $this->attach_server_to_post( $new_post_id, $server_entry, $file_code, $embed_url );
			if ( ! $attached ) {
				if ( $is_new_transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				$wpdb->update(
					$table_webhooks,
					array( 'status' => 'failed', 'received_at' => $now_date ),
					array( 'file_code' => $file_code, 'event' => $event )
				);
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to persist repeatable_fields to new episode draft.' ), 500 );
			}

			if ( $is_new_transaction ) {
				$committed = $wpdb->query( 'COMMIT' );
				if ( false === $committed ) {
					$wpdb->query( 'ROLLBACK' );
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to commit episode transaction.' ), 500 );
				}
			}

			$wpdb->update(
				$table_webhooks,
				array(
					'post_id'     => $new_post_id,
					'ip'          => $client_ip,
					'received_at' => $now_date,
					'status'      => 'processed',
				),
				array(
					'file_code' => $file_code,
					'event'     => $event,
				)
			);

			$audit_processed = $wpdb->get_var( $wpdb->prepare(
				"SELECT status FROM {$table_webhooks} WHERE file_code = %s AND event = %s",
				$file_code,
				$event
			) );

			if ( 'processed' !== $audit_processed ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to verify webhook audit record promotion to processed.' ), 500 );
			}

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'episode_created',
				'post_id'   => $new_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'New episode #%d created as draft with PeeStream player attached.', $new_post_id ),
			), 201 );
		}

		// C. Ambiguous / Unmatched: Audit and leave untouched
		$wpdb->update(
			$table_webhooks,
			array(
				'post_id'     => 0,
				'ip'          => $client_ip,
				'received_at' => $now_date,
				'status'      => 'unmatched',
			),
			array(
				'file_code' => $file_code,
				'event'     => $event,
			)
		);

		return new WP_REST_Response( array(
			'success'   => true,
			'action'    => 'unmatched',
			'file_code' => $file_code,
			'message'   => 'Ambiguous episode or parent TV show not found by verified IMDb/TMDb ID. Logged for manual confirmation.',
		), 200 );
	}

	private function process_movie( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $poster_url, $auto_create, $client_ip, $event ) {
		global $wpdb;
		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$now_date = current_time( 'mysql', true );

		$matched_post_id = 0;
		$matching_movies = array();

		// 1. Strict IMDb Match with DISTINCT post IDs
		if ( ! empty( $imdb_id ) ) {
			$matching_movies = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'ids' OR pm.meta_key = 'imdb_id'))
				 WHERE p.post_type IN ('movies', 'post') AND pm.meta_value = %s",
				$imdb_id
			) );
		}

		// 2. Strict TMDb Match with DISTINCT post IDs
		if ( empty( $matching_movies ) && $tmdb_id > 0 ) {
			$matching_movies = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'id' OR pm.meta_key = 'tmdb_id'))
				 WHERE p.post_type IN ('movies', 'post') AND pm.meta_value = %d",
				$tmdb_id
			) );
		}

		$matching_movies = array_values( array_unique( array_map( 'intval', (array) $matching_movies ) ) );

		// Ambiguity check: if multiple distinct movies share the same IMDb or TMDb identifier, reject with 409
		if ( count( $matching_movies ) > 1 ) {
			$wpdb->update(
				$table_webhooks,
				array( 'status' => 'ambiguous', 'received_at' => $now_date ),
				array( 'file_code' => $file_code, 'event' => $event )
			);
			return new WP_REST_Response( array(
				'success' => false,
				'action'  => 'ambiguous_match',
				'error'   => sprintf( 'Ambiguous match: %d movies share identifier (%s). Manual review required.', count( $matching_movies ), esc_html( $imdb_id ?: (string) $tmdb_id ) ),
			), 409 );
		}

		$matched_post_id = ! empty( $matching_movies ) ? (int) $matching_movies[0] : 0;

		// A. Update Existing Movie
		if ( $matched_post_id > 0 ) {
			$attached = $this->attach_server_to_post( $matched_post_id, $server_entry, $file_code, $embed_url );
			if ( ! $attached ) {
				$wpdb->update(
					$table_webhooks,
					array( 'status' => 'failed', 'received_at' => $now_date ),
					array( 'file_code' => $file_code, 'event' => $event )
				);
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to persist repeatable_fields to movie post.' ), 500 );
			}

			$wpdb->update(
				$table_webhooks,
				array(
					'post_id'     => $matched_post_id,
					'ip'          => $client_ip,
					'received_at' => $now_date,
					'status'      => 'processed',
				),
				array(
					'file_code' => $file_code,
					'event'     => $event,
				)
			);

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'movie_updated',
				'post_id'   => $matched_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'Verified movie #%d updated with PeeStream player.', $matched_post_id ),
			), 200 );
		}

		// B. Auto-Create Movie (Draft only, if enabled)
		if ( $auto_create ) {
			$target_cpt = ! empty( $this->options['default_post_type'] ) ? $this->options['default_post_type'] : 'movies';

			// Idempotent recovery check: Did a previous interrupted attempt already create this draft post?
			$existing_draft_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$table_webhooks} WHERE file_code = %s AND event = %s AND post_id > 0",
				$file_code,
				$event
			) );

			if ( ! $existing_draft_id ) {
				$existing_draft_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_peestream_webhook_filecode')
					 WHERE pm.meta_value = %s AND p.post_type = %s AND p.post_status = 'draft' LIMIT 1",
					$file_code,
					$target_cpt
				) );
			}

			// Strict validation: Re-use draft ONLY if it strictly belongs to this movie/CPT!
			// Never recover draft purely by title to prevent cross-movie clobbering (two movies with identical titles)
			if ( $existing_draft_id > 0 ) {
				$draft_post = get_post( $existing_draft_id );
				if ( ! $draft_post || $draft_post->post_type !== $target_cpt ) {
					$existing_draft_id = 0;
				} else {
					if ( ! empty( $imdb_id ) ) {
						$draft_imdb = get_post_meta( $existing_draft_id, 'ids', true );
						if ( empty( $draft_imdb ) ) {
							$draft_imdb = get_post_meta( $existing_draft_id, 'imdb_id', true );
						}
						if ( ! empty( $draft_imdb ) && $draft_imdb !== $imdb_id ) {
							$existing_draft_id = 0; // Mismatched draft rejected
						}
					}
				}
			}

			$new_post_id = 0;
			$is_new_transaction = false;

			if ( $existing_draft_id > 0 ) {
				// Re-use verified existing draft post from interrupted attempt (zero duplicate post creation!)
				$new_post_id = $existing_draft_id;
			} else {
				$author_id  = $this->get_default_author_id();

				$tx_started = $wpdb->query( 'START TRANSACTION' );
				if ( false === $tx_started ) {
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => 'Database transaction start failed.' ), 500 );
				}
				$is_new_transaction = true;

				$meta_input = array(
					'_peestream_webhook_filecode' => $file_code,
					'_peestream_webhook_event'    => $event,
				);
				if ( ! empty( $imdb_id ) ) {
					$meta_input['ids'] = $imdb_id;
				}

				$post_status = ! empty( $this->options['default_post_status'] ) && in_array( $this->options['default_post_status'], array( 'publish', 'draft' ), true )
					? $this->options['default_post_status']
					: 'draft';

				$new_post_id = wp_insert_post( array(
					'post_title'   => $title,
					'post_content' => sprintf( 'Watch %s online in full HD.', esc_html( $title ) ),
					'post_status'  => $post_status,
					'post_type'    => $target_cpt,
					'post_author'  => $author_id,
					'meta_input'   => $meta_input,
				), true );

				if ( empty( $new_post_id ) || is_wp_error( $new_post_id ) ) {
					$wpdb->query( 'ROLLBACK' );
					$err_msg = is_wp_error( $new_post_id ) ? $new_post_id->get_error_message() : 'Movie creation failed (returned ID 0).';
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => $err_msg ), 500 );
				}

				// Immediately link post ID to webhook audit record inside atomic transaction and verify link
				$wpdb->update(
					$table_webhooks,
					array( 'post_id' => $new_post_id ),
					array( 'file_code' => $file_code, 'event' => $event )
				);

				$verify_link = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$table_webhooks} WHERE file_code = %s AND event = %s",
					$file_code,
					$event
				) );

				if ( $verify_link !== (int) $new_post_id ) {
					$wpdb->query( 'ROLLBACK' );
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to verify webhook audit linking to movie draft.' ), 500 );
				}
			}

			if ( ! empty( $imdb_id ) ) {
				update_post_meta( $new_post_id, 'ids', $imdb_id );
			}
			if ( ! empty( $poster_url ) ) {
				update_post_meta( $new_post_id, 'dt_poster', $poster_url );
			}

			$attached = $this->attach_server_to_post( $new_post_id, $server_entry, $file_code, $embed_url );
			if ( ! $attached ) {
				if ( $is_new_transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				$wpdb->update(
					$table_webhooks,
					array( 'status' => 'failed', 'received_at' => $now_date ),
					array( 'file_code' => $file_code, 'event' => $event )
				);
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to persist repeatable_fields to new movie draft.' ), 500 );
			}

			if ( $is_new_transaction ) {
				$committed = $wpdb->query( 'COMMIT' );
				if ( false === $committed ) {
					$wpdb->query( 'ROLLBACK' );
					$wpdb->update(
						$table_webhooks,
						array( 'status' => 'failed', 'received_at' => $now_date ),
						array( 'file_code' => $file_code, 'event' => $event )
					);
					return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to commit movie transaction.' ), 500 );
				}
			}

			$wpdb->update(
				$table_webhooks,
				array(
					'post_id'     => $new_post_id,
					'ip'          => $client_ip,
					'received_at' => $now_date,
					'status'      => 'processed',
				),
				array(
					'file_code' => $file_code,
					'event'     => $event,
				)
			);

			$audit_processed = $wpdb->get_var( $wpdb->prepare(
				"SELECT status FROM {$table_webhooks} WHERE file_code = %s AND event = %s",
				$file_code,
				$event
			) );

			if ( 'processed' !== $audit_processed ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => 'Failed to verify webhook audit record promotion to processed.' ), 500 );
			}

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'movie_created',
				'post_id'   => $new_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'New movie #%d created as draft with PeeStream player attached.', $new_post_id ),
			), 201 );
		}

		// C. Unmatched Movie: Audit and leave untouched
		$wpdb->update(
			$table_webhooks,
			array(
				'post_id'     => 0,
				'ip'          => $client_ip,
				'received_at' => $now_date,
				'status'      => 'unmatched',
			),
			array(
				'file_code' => $file_code,
				'event'     => $event,
			)
		);

		return new WP_REST_Response( array(
			'success'   => true,
			'action'    => 'unmatched',
			'file_code' => $file_code,
			'message'   => 'Ambiguous or unverified movie. No matching post found by IMDb or TMDb ID. Original articles untouched.',
		), 200 );
	}

	private function attach_server_to_post( $post_id, $server_entry, $file_code, $embed_url ) {
		$repeatable = get_post_meta( $post_id, 'repeatable_fields', true );
		if ( is_string( $repeatable ) ) {
			$repeatable = maybe_unserialize( $repeatable );
		}
		if ( ! is_array( $repeatable ) ) {
			$repeatable = array();
		}

		// Prevent duplicate server entries
		$already_exists = false;
		foreach ( $repeatable as $srv ) {
			if ( isset( $srv['url'] ) && stripos( $srv['url'], $file_code ) !== false ) {
				$already_exists = true;
				break;
			}
		}

		if ( ! $already_exists ) {
			array_unshift( $repeatable, $server_entry );
			$repeatable = array_values( $repeatable );
			update_post_meta( $post_id, 'repeatable_fields', $repeatable );

			// Strict verification: verify that repeatable_fields was successfully saved and contains the player
			$fresh_meta = get_post_meta( $post_id, 'repeatable_fields', true );
			if ( is_string( $fresh_meta ) ) {
				$fresh_meta = maybe_unserialize( $fresh_meta );
			}
			$found = false;
			if ( is_array( $fresh_meta ) ) {
				foreach ( $fresh_meta as $srv ) {
					if ( isset( $srv['url'] ) && stripos( $srv['url'], $file_code ) !== false ) {
						$found = true;
						break;
					}
				}
			}
			if ( ! $found ) {
				return false;
			}
		}

		update_post_meta( $post_id, 'peestream_code', $file_code );
		update_post_meta( $post_id, 'peestream_embed_url', $embed_url );
		return true;
	}

	private function get_default_author_id() {
		$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( ! empty( $users ) ) {
			return $users[0]->ID;
		}
		return 1;
	}
}
