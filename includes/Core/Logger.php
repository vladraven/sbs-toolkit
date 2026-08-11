<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class Logger {
	public static function log( string $module, string $level, string $message, array $context = [] ): void {
		global $wpdb;

		// Добавляем базовый контекст, если его нет
		if ( ! isset( $context['ip'] ) ) {
			$context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		}
		if ( ! isset( $context['user_id'] ) ) {
			$context['user_id'] = get_current_user_id();
		}

		$wpdb->insert(
			$wpdb->prefix . 'sbs_logs',
			[
				'module'     => sanitize_key( $module ),
				'level'      => sanitize_key( $level ),
				'message'    => sanitize_text_field( $message ),
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
	}
}