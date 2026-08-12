<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Deception;

use Vlad\SBS\Core\ModuleInterface;
use Vlad\SBS\Core\SettingsManager;

final class DeceptionModule implements ModuleInterface {

	public function get_id(): string {
		return 'deception';
	}

	public function get_name(): string {
		return __( 'White Hat Deception', 'sbs' );
	}

	public function boot(): void {
		// Master switch — default OFF so Soft-Lock / Free never auto-bans traffic.
		if ( ! SettingsManager::get( 'deception', 'module_enabled', false ) ) {
			return;
		}

		add_action( 'init', [ BanEngine::class, 'enforce_php_bans' ], 1 );

		$honeypot = new HoneypotEngine();
		$honeypot->boot();
	}

	public function register_admin_ui(): void {
		add_action( 'wp_ajax_sbs_get_banned_ips', [ BanEngine::class, 'ajax_get_banned_ips' ] );
		add_action( 'wp_ajax_sbs_unban_ip', [ BanEngine::class, 'ajax_unban_ip' ] );
	}

	public function apply_soft_lock_ui(): void {
		// Emergency: always allow unban + list for admins (prevents self-lockout).
		add_action( 'wp_ajax_sbs_get_banned_ips', [ BanEngine::class, 'ajax_get_banned_ips' ] );
		add_action( 'wp_ajax_sbs_unban_ip', [ BanEngine::class, 'ajax_unban_ip' ] );
	}

	public function uninstall(): void {
		delete_option( 'sbs_banned_ips' );
		// Clear htaccess markers.
		if ( file_exists( ABSPATH . '.htaccess' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			insert_with_markers( ABSPATH . '.htaccess', 'SBS_TOOLKIT_BANS', [] );
		}
	}
}