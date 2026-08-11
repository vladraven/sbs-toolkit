<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Deception;

use Vlad\SBS\Core\Logger;

final class BanEngine {
	public static function ban_ip( string $ip ): void {
		if ( empty( $ip ) ) {
			return;
		}

		$banned_ips = get_option( 'sbs_banned_ips', [] );
		if ( ! in_array( $ip, $banned_ips, true ) ) {
			$banned_ips[] = $ip;
			update_option( 'sbs_banned_ips', $banned_ips );
		}

		self::sync_htaccess( $banned_ips );
		Logger::log( 'deception', 'warning', 'IP Banned via Honeypot', [ 'ip' => $ip ] );
	}

	public static function unban_ip( string $ip ): void {
		$banned_ips = get_option( 'sbs_banned_ips', [] );
		$key        = array_search( $ip, $banned_ips, true );
		
		if ( $key !== false ) {
			unset( $banned_ips[ $key ] );
			update_option( 'sbs_banned_ips', array_values( $banned_ips ) );
			self::sync_htaccess( $banned_ips );
		}
	}

	private static function sync_htaccess( array $ips ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$htaccess_path = ABSPATH . '.htaccess';

		if ( file_exists( $htaccess_path ) && is_writable( $htaccess_path ) ) {
			$rules = [];
			if ( ! empty( $ips ) ) {
				$rules[] = '<RequireAll>';
				foreach ( $ips as $ip ) {
					$rules[] = 'Require not ip ' . sanitize_text_field( $ip );
				}
				$rules[] = '</RequireAll>';
			}
			// Нативная функция WordPress (вместо галлюцинации insert_with_rules)
			insert_with_markers( $htaccess_path, 'SBS_TOOLKIT_BANS', $rules );
		}
	}

	// Вызывается на init. Решает проблему серверов Nginx
	public static function enforce_php_bans(): void {
		$banned_ips = get_option( 'sbs_banned_ips', [] );
		$current_ip = $_SERVER['REMOTE_ADDR'] ?? '';

		if ( ! empty( $current_ip ) && in_array( $current_ip, $banned_ips, true ) ) {
			header( 'HTTP/1.1 403 Forbidden' );
			exit( 'Forbidden by SBS Toolkit Firewall.' );
		}
	}

	public static function ajax_get_banned_ips(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		wp_send_json_success( [ 'banned_ips' => get_option( 'sbs_banned_ips', [] ) ] );
	}

	public static function ajax_unban_ip(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$ip = sanitize_text_field( $_POST['ip'] ?? '' );
		self::unban_ip( $ip );

		wp_send_json_success( [ 'message' => __( 'IP unbanned.', 'sbs' ) ] );
	}
}