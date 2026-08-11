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
		// PHP Fallback для серверов Nginx, где не работает .htaccess
		add_action( 'init', [ BanEngine::class, 'enforce_php_bans' ], 1 );

		$honeypot = new HoneypotEngine();
		$honeypot->boot();
	}

	public function register_admin_ui(): void {
		add_action( 'wp_ajax_sbs_get_banned_ips', [ BanEngine::class, 'ajax_get_banned_ips' ] );
		add_action( 'wp_ajax_sbs_unban_ip', [ BanEngine::class, 'ajax_unban_ip' ] );
	}

	public function apply_soft_lock_ui(): void {
		add_action( 'wp_ajax_sbs_unban_ip', function (): void {
			wp_send_json_error( [ 'message' => __( 'Action locked in Free mode.', 'sbs' ) ], 403 );
		} );
		// Просмотр забаненных оставляем доступным
		add_action( 'wp_ajax_sbs_get_banned_ips', [ BanEngine::class, 'ajax_get_banned_ips' ] );
	}

	public function uninstall(): void {
		delete_option( 'sbs_banned_ips' );
	}
}