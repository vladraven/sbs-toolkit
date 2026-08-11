<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Удаление кастомных таблиц базы данных
$tables = [
	$wpdb->prefix . 'sbs_logs',
	$wpdb->prefix . 'sbs_queue',
	$wpdb->prefix . 'sbs_analytics'
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// 2. Удаление сохраненных опций плагина
$options = [
	'sbs_license_key',
	'sbs_license_status',
	'sbs_trial_end_date',
	'sbs_active_free_module',
	'sbs_module_switch_date',
	'sbs_settings',
	'sbs_db_version',
	'sbs_remote_access_token'
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// 3. Очистка запланированных задач WP-Cron
wp_clear_scheduled_hook( 'sbs_process_queue_cron' );
wp_clear_scheduled_hook( 'sbs_create_scheduled_backup' );
wp_clear_scheduled_hook( 'sbs_cve_scan_cron' );
wp_clear_scheduled_hook( 'sbs_analytics_aggregate_cron' );

// 4. Очистка правил фаервола из .htaccess (Исправление: insert_with_markers)
require_once ABSPATH . 'wp-admin/includes/file.php';
$htaccess_path = ABSPATH . '.htaccess';
if ( file_exists( $htaccess_path ) && is_writable( $htaccess_path ) ) {
	insert_with_markers( $htaccess_path, 'SBS_TOOLKIT_BANS', [] );
}

// 5. Безопасное удаление только файлов кэша (Бэкапы сохраняются)
$cache_dir = WP_CONTENT_DIR . '/sbs-storage/cache/';
if ( is_dir( $cache_dir ) ) {
	$files = glob( $cache_dir . '*' );
	if ( $files ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
	}
	@rmdir( $cache_dir );
}

// Удаление самого drop-in файла
$dropin_dest = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $dropin_dest ) ) {
	@unlink( $dropin_dest );
}