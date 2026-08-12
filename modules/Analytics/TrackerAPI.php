<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Analytics;

use Vlad\SBS\Core\SettingsManager;

final class TrackerAPI {

	private const RATE_LIMIT_PER_MINUTE = 60;

	public static function register_routes(): void {
		if ( ! SettingsManager::get( 'analytics', 'enabled', false ) ) {
			return;
		}

		register_rest_route( 'sbs/v1', '/track', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'handle_ping' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function handle_ping( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! SettingsManager::get( 'analytics', 'enabled', false ) ) {
			return new \WP_REST_Response( [ 'error' => 'disabled' ], 403 );
		}

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		if ( ! self::allow_request( $ip ) ) {
			return new \WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || empty( $params['session_id'] ) || empty( $params['url'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		// Skip logged-in admins / users if configured (default: skip admins).
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return new \WP_REST_Response( [ 'status' => 'ignored_admin' ], 200 );
		}

		global $wpdb;

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$is_bot     = preg_match( '/(bot|crawl|spider|slurp|baidu|bingbot|yandex|curl|wget|facebookexternalhit)/i', $user_agent ) ? 1 : 0;

		$session_id   = sanitize_text_field( (string) $params['session_id'] );
		$url          = esc_url_raw( (string) $params['url'] );
		$time_on_page = absint( $params['time_on_page'] ?? 0 );
		$event_type   = sanitize_key( (string) ( $params['event_type'] ?? 'pageview' ) );
		$event_data   = esc_url_raw( (string) ( $params['event_data'] ?? '' ) );

		if ( strlen( $session_id ) > 64 ) {
			$session_id = substr( $session_id, 0, 64 );
		}

		$os      = self::get_os( $user_agent );
		$browser = self::get_browser( $user_agent );
		$country = '';

		if ( $event_type === 'outbound_click' && $event_data !== '' ) {
			$outbound_table = $wpdb->prefix . 'sbs_analytics_outbound';
			$wpdb->insert(
				$outbound_table,
				[
					'session_id' => $session_id,
					'url_from'   => $url,
					'url_to'     => $event_data,
					'os'         => $os,
					'browser'    => $browser,
					'country'    => $country,
					'is_bot'     => $is_bot,
					'created_at' => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);
			return new \WP_REST_Response( [ 'status' => 'logged_outbound' ], 200 );
		}

		$table = $wpdb->prefix . 'sbs_analytics';

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE session_id = %s AND url = %s ORDER BY id DESC LIMIT 1",
				$session_id,
				$url
			)
		);

		if ( $existing_id && $time_on_page > 0 ) {
			$wpdb->update(
				$table,
				[ 'time_on_page' => $time_on_page ],
				[ 'id' => (int) $existing_id ],
				[ '%d' ],
				[ '%d' ]
			);
			return new \WP_REST_Response( [ 'status' => 'updated' ], 200 );
		}

		$referrer = esc_url_raw( (string) ( $params['referrer'] ?? '' ) );
		$ref_domain = '';
		if ( $referrer !== '' ) {
			$parts = wp_parse_url( $referrer );
			$ref_domain = isset( $parts['host'] ) ? sanitize_text_field( $parts['host'] ) : '';
		}

		$wpdb->insert(
			$table,
			[
				'session_id'   => $session_id,
				'url'          => $url,
				'referrer'     => $referrer,
				'ref_domain'   => $ref_domain,
				'time_on_page' => $time_on_page,
				'os'           => $os,
				'browser'      => $browser,
				'country'      => $country,
				'is_bot'       => $is_bot,
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ]
		);

		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	private static function allow_request( string $ip ): bool {
		$key   = 'sbs_trk_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private static function get_os( string $ua ): string {
		if ( stripos( $ua, 'Windows' ) !== false ) {
			return 'Windows';
		}
		if ( stripos( $ua, 'Mac' ) !== false ) {
			return 'Mac';
		}
		if ( stripos( $ua, 'Android' ) !== false ) {
			return 'Android';
		}
		if ( stripos( $ua, 'iPhone' ) !== false || stripos( $ua, 'iPad' ) !== false ) {
			return 'iOS';
		}
		if ( stripos( $ua, 'Linux' ) !== false ) {
			return 'Linux';
		}
		return 'Other';
	}

	private static function get_browser( string $ua ): string {
		if ( stripos( $ua, 'Edg' ) !== false ) {
			return 'Edge';
		}
		if ( stripos( $ua, 'Chrome' ) !== false ) {
			return 'Chrome';
		}
		if ( stripos( $ua, 'Firefox' ) !== false ) {
			return 'Firefox';
		}
		if ( stripos( $ua, 'Safari' ) !== false ) {
			return 'Safari';
		}
		return 'Other';
	}
}