<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Deception;

use Vlad\SBS\Core\SettingsManager;

final class HoneypotEngine {
	public function boot(): void {
		add_action( 'parse_request', [ $this, 'intercept_honeypot_requests' ], 1 );
	}

	public function intercept_honeypot_requests( \WP $wp ): void {
		$raw_paths = SettingsManager::get( 'deception', 'honeypot_paths', '/shell.php,/backup.sql,/admin/config.php,/wp-config.bak' );
		$traps = array_map( 'trim', explode( ',', $raw_paths ) );

		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$parsed_url  = wp_parse_url( $request_uri );
		$path        = $parsed_url['path'] ?? '';

		foreach ( $traps as $trap ) {
			if ( ! empty( $trap ) && str_ends_with( $path, $trap ) ) {
				$this->trigger_trap();
			}
		}
	}

	private function trigger_trap(): void {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		if ( ! empty( $ip ) ) {
			BanEngine::ban_ip( $ip );
		}

		header( 'HTTP/1.1 200 OK' );
		header( 'Content-Type: text/plain' );
		// Имитация успешного ответа для обмана автоматических сканеров
		echo "<?php echo 'success'; ?>\n--\nAccess granted.";
		exit;
	}
}