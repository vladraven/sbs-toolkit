<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\DevOps;

use Vlad\SBS\Core\JobQueue;
use Vlad\SBS\Modules\Backup\ArchiveBuilder;
use Vlad\SBS\Modules\Performance\CacheEngine;

final class RemoteAPI {
	public static function register_routes(): void {
		register_rest_route( 'sbs/v1', '/remote/(?P<action>[a-zA-Z0-9_-]+)', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'handle_request' ],
			'permission_callback' => [ self::class, 'verify_token' ],
		] );
	}

	public static function verify_token( \WP_REST_Request $request ): bool {
		$auth_header = $request->get_header( 'authorization' );
		if ( ! $auth_header || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
			return false;
		}

		$token        = substr( $auth_header, 7 );
		$stored_token = get_option( 'sbs_remote_access_token' );

		return ! empty( $stored_token ) && hash_equals( $stored_token, $token );
	}

	public static function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$action = $request->get_param( 'action' );

		return match ( $action ) {
			'status'      => self::get_system_status(),
			'purge_cache' => self::purge_all_caches(),
			'run_backup'  => self::trigger_remote_backup(),
			default       => new \WP_REST_Response( [ 'error' => 'Unknown action' ], 400 ),
		};
	}

	private static function get_system_status(): \WP_REST_Response {
		global $wpdb;

		$start_time = microtime( true );
		$wpdb->query( "SELECT 1" );
		$db_ttfb = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		return new \WP_REST_Response( [
			'site_name'   => get_bloginfo( 'name' ),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'db_ttfb_ms'  => $db_ttfb,
			'disk_free'   => function_exists( 'disk_free_space' ) ? round( disk_free_space( ABSPATH ) / 1024 / 1024, 2 ) . ' MB' : 'N/A',
			'server_time' => current_time( 'mysql' ),
		], 200 );
	}

	private static function purge_all_caches(): \WP_REST_Response {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}

		// Фикс бага: теперь файловый кэш плагина тоже удаляется
		if ( class_exists( CacheEngine::class ) ) {
			CacheEngine::purge_all();
		}

		return new \WP_REST_Response( [ 'status' => 'success', 'message' => 'All caches purged successfully' ], 200 );
	}

	private static function trigger_remote_backup(): \WP_REST_Response {
		$queue  = new JobQueue();
		$job_id = $queue->push( ArchiveBuilder::class, [ 'type' => 'full', 'source' => 'remote_api' ] );

		return new \WP_REST_Response( [ 'status' => 'queued', 'job_id' => $job_id ], 200 );
	}

	public static function ajax_generate_token(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$new_token = bin2hex( random_bytes( 32 ) );
		update_option( 'sbs_remote_access_token', $new_token );

		wp_send_json_success( [ 'token' => $new_token ] );
	}
}