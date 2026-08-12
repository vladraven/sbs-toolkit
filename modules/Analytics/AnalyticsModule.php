<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Analytics;

use Vlad\SBS\Core\JobQueue;
use Vlad\SBS\Core\ModuleInterface;
use Vlad\SBS\Core\SettingsManager;

final class AnalyticsModule implements ModuleInterface {

	public function get_id(): string {
		return 'analytics';
	}

	public function get_name(): string {
		return __( 'Smart Analytics', 'sbs' );
	}

	public function boot(): void {
		// Default OFF — no tracking until explicitly enabled.
		if ( ! SettingsManager::get( 'analytics', 'enabled', false ) ) {
			return;
		}

		add_action( 'rest_api_init', [ TrackerAPI::class, 'register_routes' ] );

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'inject_tracker_script' ] );
		}

		add_action( 'sbs_analytics_aggregate_cron', [ $this, 'queue_aggregation_job' ] );
		if ( ! wp_next_scheduled( 'sbs_analytics_aggregate_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'sbs_analytics_aggregate_cron' );
		}
	}

	public function queue_aggregation_job(): void {
		$queue = new JobQueue();
		$queue->push( AggregatorJob::class, [] );
	}

	public function register_admin_ui(): void {
		add_action( 'wp_ajax_sbs_get_analytics_dashboard', [ $this, 'ajax_get_dashboard' ] );
	}

	public function apply_soft_lock_ui(): void {
		// Read-only dashboard still available in soft-lock.
		add_action( 'wp_ajax_sbs_get_analytics_dashboard', [ $this, 'ajax_get_dashboard' ] );
	}

	public function inject_tracker_script(): void {
		$endpoint = esc_url_raw( rest_url( 'sbs/v1/track' ) );

		$js = <<<JS
(function() {
	var startTime = Date.now();
	var sessionId = sessionStorage.getItem('sbs_sid') || (Math.random().toString(36).substring(2) + Date.now().toString(36));
	sessionStorage.setItem('sbs_sid', sessionId);

	function sendPing(timeSpent, eventType, eventData) {
		timeSpent = timeSpent || 0;
		eventType = eventType || 'pageview';
		eventData = eventData || '';
		var data = {
			session_id: sessionId,
			url: window.location.href,
			referrer: document.referrer || '',
			time_on_page: timeSpent,
			screen: window.innerWidth + 'x' + window.innerHeight,
			event_type: eventType,
			event_data: eventData
		};
		try {
			fetch('{$endpoint}', {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(data),
				keepalive: true,
				credentials: 'same-origin'
			}).catch(function() {});
		} catch (e) {}
	}

	sendPing(0, 'pageview', '');

	document.addEventListener('visibilitychange', function() {
		if (document.visibilityState === 'hidden') {
			sendPing(Math.round((Date.now() - startTime) / 1000), 'pageview', '');
		}
	});

	document.addEventListener('click', function(e) {
		var link = e.target && e.target.closest ? e.target.closest('a') : null;
		if (!link || !link.href) return;
		try {
			var linkHost = new URL(link.href, window.location.href).hostname;
			if (linkHost && linkHost !== window.location.hostname && link.href.indexOf('javascript:') !== 0) {
				sendPing(Math.round((Date.now() - startTime) / 1000), 'outbound_click', link.href);
			}
		} catch (err) {}
	});
})();
JS;

		wp_register_script( 'sbs-tracker', false, [], SBS_VERSION, true );
		wp_enqueue_script( 'sbs-tracker' );
		wp_add_inline_script( 'sbs-tracker', $js );
	}

	public function ajax_get_dashboard(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		global $wpdb;
		$stats_table    = $wpdb->prefix . 'sbs_analytics_stats';
		$outbound_table = $wpdb->prefix . 'sbs_analytics_outbound_stats';
		$raw_table      = $wpdb->prefix . 'sbs_analytics';

		$range   = isset( $_POST['range'] ) && (string) $_POST['range'] === '30' ? 30 : 7;
		$os      = sanitize_text_field( wp_unslash( $_POST['os'] ?? '' ) );
		$browser = sanitize_text_field( wp_unslash( $_POST['browser'] ?? '' ) );
		$country = sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) );

		$where = [ 'stat_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)' ];
		$args  = [ $range ];

		if ( $os !== '' ) {
			$where[] = 'os = %s';
			$args[]  = $os;
		}
		if ( $browser !== '' ) {
			$where[] = 'browser = %s';
			$args[]  = $browser;
		}
		if ( $country !== '' ) {
			$where[] = 'country = %s';
			$args[]  = $country;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(pageviews) as pv, SUM(unique_sessions) as us, AVG(avg_time_sec) as atime FROM {$stats_table} WHERE {$where_sql}",
				...$args
			)
		);

		$today_pv = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$raw_table} WHERE is_bot = 0" );
		$today_us = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM {$raw_table} WHERE is_bot = 0" );

		$total_pv = (int) ( $summary->pv ?? 0 ) + $today_pv;
		$total_us = (int) ( $summary->us ?? 0 ) + $today_us;
		$avg_time = round( (float) ( $summary->atime ?? 0 ), 1 );

		$referrers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_domain as label, SUM(pageviews) as pv FROM {$stats_table} WHERE {$where_sql} AND referrer_domain != '' GROUP BY referrer_domain ORDER BY pv DESC LIMIT 10",
				...$args
			)
		);
		$countries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country as label, SUM(pageviews) as pv FROM {$stats_table} WHERE {$where_sql} GROUP BY country ORDER BY pv DESC LIMIT 10",
				...$args
			)
		);
		$oses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT os as label, SUM(pageviews) as pv FROM {$stats_table} WHERE {$where_sql} GROUP BY os ORDER BY pv DESC LIMIT 10",
				...$args
			)
		);
		$browsers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT browser as label, SUM(pageviews) as pv FROM {$stats_table} WHERE {$where_sql} GROUP BY browser ORDER BY pv DESC LIMIT 10",
				...$args
			)
		);
		$outbound = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_to as label, SUM(clicks) as pv FROM {$outbound_table} WHERE {$where_sql} GROUP BY url_to ORDER BY pv DESC LIMIT 10",
				...$args
			)
		);

		$filters = [
			'os'      => $wpdb->get_col( "SELECT DISTINCT os FROM {$stats_table} WHERE os != '' ORDER BY os ASC" ),
			'browser' => $wpdb->get_col( "SELECT DISTINCT browser FROM {$stats_table} WHERE browser != '' ORDER BY browser ASC" ),
			'country' => $wpdb->get_col( "SELECT DISTINCT country FROM {$stats_table} WHERE country != '' ORDER BY country ASC" ),
		];
		// phpcs:enable

		wp_send_json_success( [
			'summary'   => [
				'pageviews'       => $total_pv,
				'unique_sessions' => $total_us,
				'avg_time_sec'    => $avg_time,
			],
			'referrers' => $referrers,
			'outbound'  => $outbound,
			'countries' => $countries,
			'oses'      => $oses,
			'browsers'  => $browsers,
			'filters'   => $filters,
		] );
	}

	public function uninstall(): void {
		wp_clear_scheduled_hook( 'sbs_analytics_aggregate_cron' );
	}
}