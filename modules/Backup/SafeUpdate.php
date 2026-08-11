<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Backup;

use Vlad\SBS\Core\Logger;

final class SafeUpdate {
	public function boot(): void {
		add_filter( 'upgrader_pre_install', [ $this, 'backup_before_update' ], 10, 2 );
	}

	public function backup_before_update( $response, array $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) && ! isset( $hook_extra['theme'] ) ) {
			return $response;
		}

		$type = isset( $hook_extra['plugin'] ) ? 'plugin' : 'theme';
		$slug = isset( $hook_extra['plugin'] ) ? dirname( $hook_extra['plugin'] ) : $hook_extra['theme'];

		$source_dir = WP_CONTENT_DIR . '/' . $type . 's/' . $slug;
		if ( ! is_dir( $source_dir ) ) {
			return $response;
		}

		$backup_dir  = SBS_STORAGE_DIR . 'safe_updates/';
		$backup_file = $backup_dir . $slug . '-' . time() . '.zip';

		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( $zip->open( $backup_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				$files = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $source_dir ),
					\RecursiveIteratorIterator::LEAVES_ONLY
				);

				foreach ( $files as $file ) {
					if ( ! $file->isDir() ) {
						$file_path     = $file->getRealPath();
						$relative_path = substr( $file_path, strlen( $source_dir ) + 1 );
						$zip->addFile( $file_path, $relative_path );
					}
				}
				$zip->close();
				Logger::log( 'backup', 'info', "SafeUpdate: Backed up {$type} {$slug}", [ 'file' => $backup_file ] );
			}
		}

		return $response;
	}
}