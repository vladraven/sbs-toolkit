<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Deception;

use Vlad\SBS\Core\Logger;
use Vlad\SBS\Core\SettingsManager;

final class BanEngine {

	public static function client_ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return self::validate_ip( $ip ) ? $ip : '';
	}

	public static function validate_ip( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false
			|| filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
	}

	public static function is_whitelisted( string $ip ): bool {
		$raw = (string) SettingsManager::get( 'deception', 'ip_whitelist', '' );
		if ( $raw === '' ) {
			return false;
		}
		$list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return in_array( $ip, $list, true );
	}

	public static function ban_ip( string $ip ): void {
		if ( ! self::validate_ip( $ip ) || self::is_whitelisted( $ip ) ) {
			return;
		}

		$banned = get_option( 'sbs_banned_ips', [] );
		if ( ! is_array( $banned ) ) {
			$banned = [];
		}

		if ( ! in_array( $ip, $banned, true ) ) {
			$banned[] = $ip;
			// Cap list to avoid giant options.
			if ( count( $banned ) > 5000 ) {
				$banned = array_slice( $banned, -5000 );
			}
			update_option( 'sbs_banned_ips', array_values( $banned ), false );
			self::sync_htaccess( $banned );
			Logger::log( 'deception', 'warning', 'IP banned.', [ 'ip' => $ip ] );
		}
	}

	public static function unban_ip( string $ip ): void {
		$banned = get_option( 'sbs_banned_ips', [] );
		if ( ! is_array( $banned ) ) {
			return;
		}
		$key = array_search( $ip, $banned, true );
		if ( $key === false ) {
			return;
		}
		unset( $banned[ $key ] );
		$banned = array_values( $banned );
		update_option( 'sbs_banned_ips', $banned, false );
		self::sync_htaccess( $banned );
		Logger::log( 'deception', 'info', 'IP unbanned.', [ 'ip' => $ip ] );
	}

	private static function sync_htaccess( array $ips ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$htaccess_path = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess_path ) || ! is_writable( $htaccess_path ) ) {
			return;
		}

		$rules = [];
		$valid = [];
		foreach ( $ips as $ip ) {
			$ip = (string) $ip;
			if ( self::validate_ip( $ip ) ) {
				$valid[] = $ip;
			}
		}
		if ( ! empty( $valid ) ) {
			$rules[] = '<RequireAll>';
			foreach ( $valid as $ip ) {
				$rules[] = 'Require not ip ' . $ip;
			}
			$rules[] = '</RequireAll>';
		}

		insert_with_markers( $htaccess_path, 'SBS_TOOLKIT_BANS', $rules );
	}

	/** PHP fallback for Nginx / hosts without .htaccess bans. */
	public static function enforce_php_bans(): void {
		if ( ! SettingsManager::get( 'deception', 'ban_enabled', true ) ) {
			return;
		}

		$ip = self::client_ip();
		if ( $ip === '' || self::is_whitelisted( $ip ) ) {
			return;
		}

		// Never lock out a logged-in administrator.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$banned = get_option( 'sbs_banned_ips', [] );
		if ( is_array( $banned ) && in_array( $ip, $banned, true ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'Forbidden';
			exit;
		}
	}

	public static function ajax_get_banned_ips(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		$banned = get_option( 'sbs_banned_ips', [] );
		wp_send_json_success( [ 'banned_ips' => is_array( $banned ) ? $banned : [] ] );
	}

	public static function ajax_unban_ip(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		$ip = sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) );
		if ( ! self::validate_ip( $ip ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid IP.', 'sbs' ) ] );
		}
		self::unban_ip( $ip );
		wp_send_json_success( [ 'message' => __( 'IP unbanned.', 'sbs' ) ] );
	}
}