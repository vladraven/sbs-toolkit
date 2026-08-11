<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class Database {
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "
		CREATE TABLE {$wpdb->prefix}sbs_logs (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			module varchar(50) NOT NULL,
			level varchar(20) NOT NULL,
			message text NOT NULL,
			context longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;

		CREATE TABLE {$wpdb->prefix}sbs_queue (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			task varchar(100) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts int(11) NOT NULL DEFAULT 0,
			locked_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_locked (status, locked_at)
		) $charset_collate;

		CREATE TABLE {$wpdb->prefix}sbs_analytics (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			url varchar(255) NOT NULL,
			referrer varchar(255) NOT NULL,
			referrer_domain varchar(100) NOT NULL DEFAULT '',
			os varchar(50) NOT NULL DEFAULT '',
			browser varchar(50) NOT NULL DEFAULT '',
			country varchar(50) NOT NULL DEFAULT '',
			time_on_page int(11) NOT NULL DEFAULT 0,
			device_data longtext NOT NULL,
			is_bot tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) $charset_collate;

		CREATE TABLE {$wpdb->prefix}sbs_analytics_stats (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			url varchar(255) NOT NULL,
			referrer_domain varchar(100) NOT NULL DEFAULT '',
			os varchar(50) NOT NULL DEFAULT '',
			browser varchar(50) NOT NULL DEFAULT '',
			country varchar(50) NOT NULL DEFAULT '',
			pageviews int(11) NOT NULL DEFAULT 0,
			unique_sessions int(11) NOT NULL DEFAULT 0,
			avg_time_sec float NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY agg_dimensions (stat_date, url(100), referrer_domain(50), os(20), browser(20), country(20))
		) $charset_collate;

		CREATE TABLE {$wpdb->prefix}sbs_analytics_outbound (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			url_from varchar(255) NOT NULL,
			url_to varchar(255) NOT NULL,
			os varchar(50) NOT NULL DEFAULT '',
			browser varchar(50) NOT NULL DEFAULT '',
			country varchar(50) NOT NULL DEFAULT '',
			is_bot tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) $charset_collate;

		CREATE TABLE {$wpdb->prefix}sbs_analytics_outbound_stats (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			url_from varchar(255) NOT NULL,
			url_to varchar(255) NOT NULL,
			os varchar(50) NOT NULL DEFAULT '',
			browser varchar(50) NOT NULL DEFAULT '',
			country varchar(50) NOT NULL DEFAULT '',
			clicks int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY agg_out_dimensions (stat_date, url_from(100), url_to(100), os(20), browser(20), country(20))
		) $charset_collate;
		";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'sbs_db_version', SBS_DB_VERSION );
	}
}