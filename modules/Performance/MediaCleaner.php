<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Performance;

use Vlad\SBS\Core\Logger;

final class MediaCleaner {
	public static function ajax_scan_orphans(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		global $wpdb;
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$base_url   = trailingslashit( $upload_dir['baseurl'] );

		// 1. Формируем хэш-карту легитимных файлов (Один быстрый запрос)
		$registered_files = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );
		$valid_lookup = [];
		foreach ( $registered_files as $file ) {
			$valid_lookup[ $file ] = true;
		}

		$orphans    = [];
		$total_size = 0;

		// 2. Итерируем файлы
		if ( is_dir( $base_dir ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isDir() ) {
					continue;
				}

				$real_path     = $file->getRealPath();
				$relative_path = substr( $real_path, strlen( $base_dir ) );
				$relative_path = str_replace( '\\', '/', $relative_path );

				// Исключаем системные папки плагина
				if ( str_starts_with( $relative_path, 'sbs-storage/' ) || str_starts_with( $relative_path, 'elementor/' ) ) {
					continue;
				}

				// Главная оптимизация: Пропускаем сгенерированные миниатюры. 
				// Если оригинального файла нет, то и его миниатюры не нужны (мы удалим оригинал, а WP не найдет миниатюру).
				if ( preg_match( '/-[0-9]+x[0-9]+\.(jpg|jpeg|png|webp|gif|avif)$/i', $relative_path ) ) {
					continue;
				}

				// Если оригинального файла нет в базе - это сирота
				if ( ! isset( $valid_lookup[ $relative_path ] ) ) {
					$size         = $file->getSize();
					$total_size  += $size;
					$orphans[]    = [
						'path' => $real_path,
						'url'  => $base_url . $relative_path,
						'size' => $size,
					];
				}

				// Ограничитель, чтобы не повесить сервер при гигантских объемах (первые 500 сирот)
				if ( count( $orphans ) >= 500 ) {
					break;
				}
			}
		}

		wp_send_json_success( [
			'orphans'    => $orphans,
			'total_size' => $total_size,
			'limit_hit'  => count( $orphans ) >= 500
		] );
	}

	public static function ajax_delete_orphans(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$files = $_POST['files'] ?? [];
		if ( ! is_array( $files ) || empty( $files ) ) {
			wp_send_json_error( [ 'message' => __( 'No files specified.', 'sbs' ) ] );
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];
		$deleted    = 0;

		foreach ( $files as $path ) {
			$path = sanitize_text_field( wp_unslash( $path ) );
			// Строгая проверка, что путь действительно ведет в /uploads/ (Защита от удаления /etc/passwd)
			if ( strpos( realpath( $path ), realpath( $base_dir ) ) === 0 ) {
				if ( @unlink( $path ) ) {
					$deleted++;
				}
			}
		}

		Logger::log( 'performance', 'info', "Deleted {$deleted} orphaned media files." );
		wp_send_json_success( [ 'deleted_count' => $deleted ] );
	}
}