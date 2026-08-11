<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Security;

use Vlad\SBS\Core\Logger;

final class CveScanner {
	public static function run_scan(): void {
		$vulnerabilities = self::scan_plugins();
		
		if ( ! empty( $vulnerabilities ) ) {
			foreach ( $vulnerabilities as $vuln ) {
				Logger::log( 'security', 'critical', 'Vulnerability Found in Plugin', [
					'plugin'  => $vuln['name'],
					'version' => $vuln['current_version'],
					'details' => $vuln['vuln_title']
				] );
			}
		} else {
			Logger::log( 'security', 'info', 'CVE Scan completed. No vulnerabilities found.' );
		}
	}

	public static function ajax_run_scan(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$vulnerabilities = self::scan_plugins();
		wp_send_json_success( [ 'vulnerabilities' => $vulnerabilities ] );
	}

	private static function scan_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins         = get_plugins();
		$vulnerabilities = [];

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$slug    = dirname( $plugin_file );
			$version = $plugin_data['Version'] ?? '';

			if ( empty( $slug ) || $slug === '.' ) {
				continue;
			}

			// Используем бесплатный открытый API wpvulnerability.net
			$api_url  = 'https://www.wpvulnerability.net/plugin/' . urlencode( $slug );
			$response = wp_remote_get( $api_url, [ 'timeout' => 10 ] );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( empty( $data['data']['vulnerability'] ) || ! is_array( $data['data']['vulnerability'] ) ) {
				continue;
			}

			foreach ( $data['data']['vulnerability'] as $vuln ) {
				$fixed_in = $vuln['operator']['max_version'] ?? '';
				
				// Если версия не указана или у нас версия ниже той, в которой исправлен баг
				if ( ! empty( $fixed_in ) && version_compare( $version, $fixed_in, '<' ) ) {
					$vulnerabilities[] = [
						'name'            => $plugin_data['Name'],
						'current_version' => $version,
						'new_version'     => $fixed_in,
						'vuln_title'      => $vuln['title'] ?? 'Unknown Vulnerability'
					];
					break; // Достаточно одной найденной уязвимости для этого плагина
				}
			}
		}

		return $vulnerabilities;
	}
}