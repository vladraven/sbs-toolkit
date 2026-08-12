<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Deception;

use Vlad\SBS\Core\SettingsManager;

final class HoneypotEngine {

	public function boot(): void {
		// Default OFF — must be explicitly enabled in settings.
		if ( ! SettingsManager::get( 'deception', 'honeypot_enabled', false ) ) {
			return;
		}
		add_action( 'parse_request', [ $this, 'intercept_honeypot_requests' ], 1 );
	}

	public function intercept_honeypot_requests( \WP $wp ): void {
		// Logged-in admins never trip honeypots.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw_paths = (string) SettingsManager::get(
			'deception',
			'honeypot_paths',
			'/shell.php,/backup.sql,/admin/config.php,/wp-config.bak'
		);
		$traps = array_filter( array_map( 'trim', explode( ',', $raw_paths ) ) );
		if ( empty( $traps ) ) {
			return;
		}

		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$parsed      = wp_parse_url( $request_uri );
		$path        = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';

		foreach ( $traps as $trap ) {
			if ( $trap !== '' && str_ends_with( $path, $trap ) ) {
				$this->trigger_trap();
				return;
			}
		}
	}

	private function trigger_trap(): void {
		$ip = BanEngine::client_ip();
		if ( $ip !== '' ) {
			// Never ban whitelist / private optional.
			if ( ! BanEngine::is_whitelisted( $ip ) ) {
				BanEngine::ban_ip( $ip );
			}
		}

		// Fake success for scanners — no useful payload.
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo "OK\n";
		exit;
	}
}