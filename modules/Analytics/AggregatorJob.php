<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Analytics;

final class AggregatorJob {
	public function handle( array $payload = [] ): void {
		global $wpdb;
		$raw_table   = $wpdb->prefix . 'sbs_analytics';
		$stats_table = $wpdb->prefix . 'sbs_analytics_stats';
		
		$outbound_raw   = $wpdb->prefix . 'sbs_analytics_outbound';
		$outbound_stats = $wpdb->prefix . 'sbs_analytics_outbound_stats';

		$wpdb->query( "DELETE FROM {$raw_table} WHERE is_bot = 1" );
		$wpdb->query( "DELETE FROM {$outbound_raw} WHERE is_bot = 1" );

		$cutoff_time = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
		
		// 1. Агрегация хитов страниц
		$query_pageviews = $wpdb->prepare(
			"SELECT DATE(created_at) as stat_date, url, referrer_domain, os, browser, country, 
			 COUNT(*) as pageviews, COUNT(DISTINCT session_id) as unique_sessions, AVG(time_on_page) as avg_time
			 FROM {$raw_table}
			 WHERE created_at <= %s
			 GROUP BY stat_date, url, referrer_domain, os, browser, country",
			$cutoff_time
		);

		$results = $wpdb->get_results( $query_pageviews );

		if ( $results ) {
			foreach ( $results as $row ) {
				$sql = $wpdb->prepare(
					"INSERT INTO {$stats_table} (stat_date, url, referrer_domain, os, browser, country, pageviews, unique_sessions, avg_time_sec)
					 VALUES (%s, %s, %s, %s, %s, %s, %d, %d, %f)
					 ON DUPLICATE KEY UPDATE
					 pageviews = pageviews + VALUES(pageviews),
					 unique_sessions = unique_sessions + VALUES(unique_sessions),
					 avg_time_sec = (avg_time_sec + VALUES(avg_time_sec)) / 2",
					$row->stat_date,
					$row->url,
					$row->referrer_domain,
					$row->os,
					$row->browser,
					$row->country,
					$row->pageviews,
					$row->unique_sessions,
					round( (float) $row->avg_time, 1 )
				);
				$wpdb->query( $sql );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$raw_table} WHERE created_at <= %s", $cutoff_time ) );
		}

		// 2. Агрегация исходящих кликов
		$query_outbound = $wpdb->prepare(
			"SELECT DATE(created_at) as stat_date, url_from, url_to, os, browser, country, COUNT(*) as clicks
			 FROM {$outbound_raw}
			 WHERE created_at <= %s
			 GROUP BY stat_date, url_from, url_to, os, browser, country",
			$cutoff_time
		);

		$outbound_results = $wpdb->get_results( $query_outbound );

		if ( $outbound_results ) {
			foreach ( $outbound_results as $row ) {
				$sql = $wpdb->prepare(
					"INSERT INTO {$outbound_stats} (stat_date, url_from, url_to, os, browser, country, clicks)
					 VALUES (%s, %s, %s, %s, %s, %s, %d)
					 ON DUPLICATE KEY UPDATE clicks = clicks + VALUES(clicks)",
					$row->stat_date,
					$row->url_from,
					$row->url_to,
					$row->os,
					$row->browser,
					$row->country,
					$row->clicks
				);
				$wpdb->query( $sql );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$outbound_raw} WHERE created_at <= %s", $cutoff_time ) );
		}
	}
}