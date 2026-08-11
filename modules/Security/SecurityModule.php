<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Security;

use Vlad\SBS\Core\ModuleInterface;
use Vlad\SBS\Core\SettingsManager;
use Vlad\SBS\Core\Logger;

final class SecurityModule implements ModuleInterface {
	public function get_id(): string {
		return 'security';
	}

	public function get_name(): string {
		return __( 'Security Hardening', 'sbs' );
	}

	public function boot(): void {
		if ( SettingsManager::get( 'security', 'disable_xmlrpc', true ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', [ $this, 'remove_x_pingback_header' ] );
		}

		if ( SettingsManager::get( 'security', 'disable_app_passwords', true ) ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}

		$custom_slug = SettingsManager::get( 'security', 'custom_login_slug', '' );
		if ( ! empty( $custom_slug ) ) {
			$login_guard = new LoginGuard( $custom_slug );
			$login_guard->boot();
		}

		add_action( 'sbs_cve_scan_cron', [ CveScanner::class, 'run_scan' ] );

		// Исправление аудита: используем реальные хуки вместо выдуманного pre_user_role
		if ( SettingsManager::get( 'security', 'block_external_admins', true ) ) {
			add_action( 'user_register', [ $this, 'prevent_admin_creation' ], 999 );
			add_action( 'set_user_role', [ $this, 'prevent_admin_promotion' ], 999, 3 );
		}
	}

	public function prevent_admin_creation( int $user_id ): void {
		if ( current_user_can( 'create_users' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user && in_array( 'administrator', $user->roles, true ) ) {
			$user->set_role( 'subscriber' ); // Жестко понижаем
			
			$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
			Logger::log( 'security', 'critical', 'Blocked unauthorized admin creation (user_register)', [ 'ip' => $ip, 'user_id' => $user_id ] );
		}
	}

	public function prevent_admin_promotion( int $user_id, string $role, array $old_roles ): void {
		if ( $role !== 'administrator' || current_user_can( 'promote_users' ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user ) {
			// Возвращаем предыдущую роль или сбрасываем до подписчика
			$safe_role = empty( $old_roles ) ? 'subscriber' : $old_roles[0];
			$user->set_role( $safe_role );
			
			$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
			Logger::log( 'security', 'critical', 'Blocked unauthorized admin promotion (set_user_role)', [ 'ip' => $ip, 'user_id' => $user_id ] );
		}
	}

	public function register_admin_ui(): void {
		add_action( 'wp_ajax_sbs_run_cve_scan', [ CveScanner::class, 'ajax_run_scan' ] );
		add_action( 'wp_ajax_sbs_reset_all_sessions', [ $this, 'ajax_reset_sessions' ] );
	}

	public function apply_soft_lock_ui(): void {
		$locked_callback = function (): void {
			wp_send_json_error( [ 'message' => __( 'Action locked in Free mode.', 'sbs' ) ], 403 );
		};
		add_action( 'wp_ajax_sbs_run_cve_scan', $locked_callback );
		add_action( 'wp_ajax_sbs_reset_all_sessions', $locked_callback );
	}

	public function ajax_reset_sessions(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$current_user_id = get_current_user_id();
		wp_destroy_all_sessions();
		wp_set_auth_cookie( $current_user_id );

		Logger::log( 'security', 'info', 'All user sessions have been reset by admin.' );
		wp_send_json_success( [ 'message' => __( 'All sessions destroyed. You remain logged in.', 'sbs' ) ] );
	}

	public function remove_x_pingback_header( array $headers ): array {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public function uninstall(): void {
		wp_clear_scheduled_hook( 'sbs_cve_scan_cron' );
	}
}