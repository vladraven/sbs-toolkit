<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Analytics;

final class TrackerAPI {
	public static function register_routes(): void {
		register_rest_route( 'sbs/v1', '/track', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'handle_ping' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function handle_ping( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( empty( $params['session_id'] ) || empty( $params['url'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		global $wpdb;
		
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$is_bot = 0;
		if ( preg_match( '/(bot|crawl|spider|slurp|baidu|bingbot|yandex|curl|wget)/i', $user_agent ) ) {
			$is_bot = 1;
		}

		$session_id   = sanitize_text_field( $params['session_id'] );
		$url          = esc_url_raw( $params['url'] );
		$time_on_page = absint( $params['time_on_page'] ?? 0 );
		$event_type   = sanitize_text_field( $params['event_type'] ?? 'pageview' );
		$event_data   = esc_url_raw( $params['event_data'] ?? '' );

		$ip       = $_SERVER['REMOTE_ADDR'] ?? '';
		$os       = self::get_os( $user_agent );
		$browser  = self::get_browser( $user_agent );
		$country  = self::get_country( $ip );

		if ( $event_type === 'outbound_click' && ! empty( $event_data ) ) {
			$outbound_table = $wpdb->prefix . 'sbs_analytics_outbound';
			$wpdb->insert(
				$outbound_table,
				[
					'session_id' => $session_id,
					'url_from'   => $url,
					'url_to'     => $event_data,
					'os'         => sanitize_text_field( $os ),
					'browser'    => sanitize_text_field( $browser ),
					'country'    => sanitize_text_field( $country ),
					'is_bot'     => $is_bot,
					'created_at' => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);
			return new \WP_REST_Response( [ 'status' => 'logged_outbound' ], 200 );
		}

		$table = $wpdb->prefix . 'sbs_analytics';

		$existing_id = $wpdb->get_var( $wpdb->prepare( 
			"SELECT id FROM {$table} WHERE session_id = %s AND url = %s ORDER BY id DESC LIMIT 1", 
			$session_id, $url 
		) );

		if ( $existing_id && $time_on_page > 0 ) {
			$wpdb->update( $table, [ 'time_on_page' => $time_on_page ], [ 'id' => $existing_id ] );
			return new \WP_REST_Response( [ 'status' => 'updated' ], 200 );
		}

		$referrer = esc_url_raw( $params['referrer'] ?? '' );
		$ref_domain = '';
		if ( ! empty( $referrer ) ) {
			$parsed_ref = wp_parse_url( $referrer );
			if ( ! empty( $parsed_ref['host'] ) ) {
				$ref_domain = preg_replace( '/^www\./i', '', $parsed_ref['host'] );
			}
		}

		$wpdb->insert(
			$table,
			[
				'session_id'      => $session_id,
				'ip'              => sanitize_text_field( $ip ),
				'url'             => $url,
				'referrer'        => $referrer,
				'referrer_domain' => sanitize_text_field( $ref_domain ),
				'os'              => sanitize_text_field( $os ),
				'browser'         => sanitize_text_field( $browser ),
				'country'         => sanitize_text_field( $country ),
				'time_on_page'    => $time_on_page,
				'device_data'     => sanitize_text_field( $params['screen'] ?? '' ),
				'is_bot'          => $is_bot,
				'created_at'      => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
		);

		return new \WP_REST_Response( [ 'status' => 'logged' ], 200 );
	}

	private static function get_os( string $ua ): string {
		if ( preg_match( '/windows nt/i', $ua ) ) return 'Windows';
		if ( preg_match( '/mac os x/i', $ua ) ) return 'Mac OS';
		if ( preg_match( '/linux/i', $ua ) ) return 'Linux';
		if ( preg_match( '/android/i', $ua ) ) return 'Android';
		if ( preg_match( '/iphone|ipad|ipod/i', $ua ) ) return 'iOS';
		return 'Unknown';
	}

	private static function get_browser( string $ua ): string {
		if ( preg_match( '/edg/i', $ua ) ) return 'Edge';
		if ( preg_match( '/opr|opera/i', $ua ) ) return 'Opera';
		if ( preg_match( '/chrome/i', $ua ) ) return 'Chrome';
		if ( preg_match( '/safari/i', $ua ) && ! preg_match( '/chrome/i', $ua ) ) return 'Safari';
		if ( preg_match( '/firefox/i', $ua ) ) return 'Firefox';
		return 'Unknown';
	}

	private static function get_country( string $ip ): string {
		if ( empty( $ip ) || $ip === '127.0.0.1' || $ip === '::1' ) {
			return 'Localhost';
		}
		
		$transient_key = 'sbs_geo_' . md5( $ip );
		$country       = get_transient( $transient_key );
		
		if ( $country === false ) {
			$response = wp_remote_get( "http://ip-api.com/json/{$ip}?fields=country", [ 'timeout' => 2 ] );
			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$body    = json_decode( wp_remote_retrieve_body( $response ), true );
				$country = $body['country'] ?? 'Unknown';
			} else {
				$country = 'Unknown';
			}
			set_transient( $transient_key, $country, WEEK_IN_SECONDS );
		}
		
		return $country;
	}
}