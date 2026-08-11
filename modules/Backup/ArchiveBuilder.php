<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Backup;

use Vlad\SBS\Core\Logger;

final class ArchiveBuilder {
	public function handle( array $payload ): void {
		$exclude_thumbs = $payload['exclude_thumbs'] ?? true;
		
		$db_filename = 'sbs-database-dump-' . time() . '.sql';
		$db_file     = WP_CONTENT_DIR . '/' . $db_filename;
		
		$dumper  = new DatabaseDumper();
		$dumper->dump( $db_file );

		$archive_file = SBS_STORAGE_DIR . 'backup-' . time();
		
		if ( $this->can_exec_tar() ) {
			$archive_file .= '.tar.gz';
			$this->compress_tar_exec( ABSPATH, $archive_file, $exclude_thumbs );
		} else {
			$archive_file .= '.zip';
			$this->compress_zip_php( ABSPATH, $archive_file, $exclude_thumbs );
		}

		if ( file_exists( $db_file ) ) {
			@unlink( $db_file );
		}

		// Фикс: жестко проверяем, собрал ли архиватор файл.
		// Если файла нет, Exception будет перехвачен в JobQueue, и задача упадет в failed.
		if ( ! file_exists( $archive_file ) || filesize( $archive_file ) === 0 ) {
			throw new \RuntimeException( 'Archiver finished, but archive file is missing or empty.' );
		}

		Logger::log( 'backup', 'info', 'Full backup completed successfully.', [ 'file' => basename( $archive_file ) ] );

		$uploader = new RemoteUploader();
		$uploader->upload( $archive_file );
	}

	private function can_exec_tar(): bool {
		if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
			return false;
		}
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = explode( ',', ini_get( 'disable_functions' ) );
		return ! in_array( 'exec', array_map( 'trim', $disabled ), true );
	}

	private function compress_tar_exec( string $source, string $destination, bool $exclude_thumbs ): void {
		$exclude_cmd = '';
		
		if ( $exclude_thumbs ) {
			$exclude_cmd = "--exclude='wp-content/uploads/*-[0-9]*x[0-9]*.jpg' " .
			               "--exclude='wp-content/uploads/*-[0-9]*x[0-9]*.jpeg' " .
			               "--exclude='wp-content/uploads/*-[0-9]*x[0-9]*.png' " .
			               "--exclude='wp-content/uploads/*-[0-9]*x[0-9]*.webp' " .
			               "--exclude='wp-content/uploads/*-[0-9]*x[0-9]*.avif' ";
		}

		$exclude_cmd .= "--exclude='wp-content/sbs-storage' ";

		$command = sprintf(
			'cd %s && tar %s -czf %s .',
			escapeshellarg( $source ),
			$exclude_cmd,
			escapeshellarg( $destination )
		);

		// Фикс: отлавливаем код ошибки системной команды
		exec( $command, $output, $result_code );
		if ( $result_code !== 0 ) {
			throw new \RuntimeException( "Tar command failed with exit code {$result_code}." );
		}
	}

	private function compress_zip_php( string $source, string $destination, bool $exclude_thumbs ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( 'ZipArchive extension missing. Cannot create backup.' );
		}

		$zip = new \ZipArchive();
		if ( $zip->open( $destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			throw new \RuntimeException( "Failed to create zip archive at {$destination}" );
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				continue;
			}
			
			$file_path     = $file->getRealPath();
			$relative_path = substr( $file_path, strlen( rtrim( $source, '/\\' ) ) + 1 );
			$relative_path = str_replace( '\\', '/', $relative_path );

			if ( str_starts_with( $relative_path, 'wp-content/sbs-storage' ) ) {
				continue;
			}

			if ( $exclude_thumbs && str_starts_with( $relative_path, 'wp-content/uploads' ) ) {
				if ( preg_match( '/-[0-9]+x[0-9]+\.(jpg|jpeg|png|webp|avif)$/i', $relative_path ) ) {
					continue;
				}
			}

			$zip->addFile( $file_path, $relative_path );
		}
		
		$zip->close();
	}
}