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

		$upload_dir = wp_upload_dir();
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
		$base_url   = isset( $upload_dir['baseurl'] ) ? (string) $upload_dir['baseurl'] : '';

		$base_real = $base_dir !== '' ? realpath( $base_dir ) : false;
		if ( $base_real === false || ! is_dir( $base_real ) ) {
			wp_send_json_error( [ 'message' => __( 'Uploads directory not found.', 'sbs' ) ] );
		}
		$base_real = wp_normalize_path( $base_real );

		global $wpdb;
		$registered   = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" );
		$valid_lookup = [];
		if ( is_array( $registered ) ) {
			foreach ( $registered as $file ) {
				$file = ltrim( str_replace( '\\', '/', (string) $file ), '/' );
				if ( $file !== '' ) {
					$valid_lookup[ $file ] = true;
				}
			}
		}

		$orphans    = [];
		$total_size = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}

			$real_path = $file->getRealPath();
			if ( $real_path === false ) {
				continue;
			}
			$real_path = wp_normalize_path( $real_path );

			if ( $real_path !== $base_real && ! str_starts_with( $real_path, $base_real . '/' ) ) {
				continue;
			}

			$relative = ltrim( substr( $real_path, strlen( $base_real ) ), '/' );
			$relative = str_replace( '\\', '/', $relative );

			if ( str_starts_with( $relative, 'sbs-storage/' ) ) {
				continue;
			}

			if ( preg_match( '/-\d+x\d+\.(jpe?g|png|gif|webp|avif)$/i', $relative ) ) {
				continue;
			}

			if ( isset( $valid_lookup[ $relative ] ) ) {
				continue;
			}

			$size        = (int) $file->getSize();
			$total_size += $size;
			$orphans[]   = [
				'path'     => $relative,
				'abs_path' => $real_path,
				'url'      => trailingslashit( $base_url ) . $relative,
				'size'     => $size,
			];

			if ( count( $orphans ) >= 500 ) {
				break;
			}
		}

		wp_send_json_success( [
			'orphans'    => $orphans,
			'total_size' => $total_size,
			'limit_hit'  => count( $orphans ) >= 500,
		] );
	}

	public static function ajax_delete_orphans(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$files = $_POST['files'] ?? [];
		if ( ! is_array( $files ) || $files === [] ) {
			wp_send_json_error( [ 'message' => __( 'No files specified.', 'sbs' ) ] );
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
		$base_real  = $base_dir !== '' ? realpath( $base_dir ) : false;
		if ( $base_real === false ) {
			wp_send_json_error( [ 'message' => __( 'Uploads directory not found.', 'sbs' ) ] );
		}
		$base_real = wp_normalize_path( $base_real );

		$deleted = 0;
		foreach ( $files as $entry ) {
			if ( is_array( $entry ) ) {
				$candidate = (string) ( $entry['abs_path'] ?? $entry['path'] ?? '' );
			} else {
				$candidate = (string) $entry;
			}
			$candidate = wp_unslash( $candidate );
			if ( $candidate === '' || str_contains( $candidate, "\0" ) ) {
				continue;
			}

			if ( ! str_starts_with( $candidate, '/' ) && ! preg_match( '/^[A-Za-z]:\\\\/', $candidate ) ) {
				$candidate = $base_real . '/' . ltrim( str_replace( '\\', '/', $candidate ), '/' );
			}

			$real = realpath( $candidate );
			if ( $real === false ) {
				continue;
			}
			$real = wp_normalize_path( $real );

			if ( $real !== $base_real && ! str_starts_with( $real, $base_real . '/' ) ) {
				continue;
			}

			if ( is_file( $real ) && @unlink( $real ) ) {
				$deleted++;
			}
		}

		Logger::log( 'performance', 'info', "Deleted {$deleted} orphaned media files." );
		wp_send_json_success( [ 'deleted_count' => $deleted ] );
	}
}