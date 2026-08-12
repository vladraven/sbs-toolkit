<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Backup;

use Vlad\SBS\Core\Logger;
use Vlad\SBS\Core\SettingsManager;

/**
 * Builds ONE complete .zip: full site files + database.sql inside.
 * Writes to *.zip.building, renames only on success (no half-dead part files in the list).
 */
final class ArchiveBuilder {

	public function handle( array $payload ): void {
		$result = $this->create_backup( $payload );

		if ( empty( $result['path'] ) || ! file_exists( $result['path'] ) ) {
			throw new \RuntimeException( 'Backup finished without a valid archive file.' );
		}

		Logger::log( 'backup', 'info', 'Full backup completed.', [
			'file' => basename( $result['path'] ),
			'size' => $result['size'] ?? 0,
		] );

		// Optional remote upload (Pro settings).
		if ( ! empty( $payload['upload_ftp'] ) || ! empty( $payload['upload_s3'] ) ) {
			$uploader = new RemoteUploader();
			$uploader->upload( $result['path'] );
		}
	}

	/**
	 * @return array{path:string,file:string,size:int}
	 */
	public function create_backup( array $payload = [] ): array {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new \RuntimeException( 'ZipArchive PHP extension is required for backups.' );
		}

		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		if ( ! is_dir( SBS_STORAGE_DIR ) ) {
			wp_mkdir_p( SBS_STORAGE_DIR );
		}

		// Drop stale incomplete archives from previous crashes.
		$this->cleanup_incomplete_files();

		$stamp     = gmdate( 'Ymd-His' );
		$final     = SBS_STORAGE_DIR . 'backup-' . $stamp . '.zip';
		$building  = $final . '.building';
		$exclude_thumbs = ! empty( $payload['exclude_thumbs'] );

		// 1) SQL dump to temp file (will be added into the zip, then deleted).
		$sql_path = SBS_STORAGE_DIR . 'db-' . $stamp . '.sql';
		$dumper   = new DatabaseDumper();
		if ( ! $dumper->dump( $sql_path ) || ! file_exists( $sql_path ) ) {
			throw new \RuntimeException( 'Database dump failed.' );
		}

		$zip = new \ZipArchive();
		$opened = $zip->open( $building, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		if ( $opened !== true ) {
			@unlink( $sql_path );
			throw new \RuntimeException( 'Cannot create zip archive (code ' . $opened . ').' );
		}

		try {
			// 2) Full site under ABSPATH.
			$this->add_directory_to_zip( $zip, ABSPATH, '', $exclude_thumbs );

			// 3) Database inside the same zip (single downloadable package).
			$zip->addFile( $sql_path, 'database.sql' );

			if ( ! $zip->close() ) {
				throw new \RuntimeException( 'ZipArchive::close() failed.' );
			}
		} catch ( \Throwable $e ) {
			@$zip->close();
			@unlink( $building );
			@unlink( $sql_path );
			throw $e;
		}

		@unlink( $sql_path );

		if ( ! file_exists( $building ) || filesize( $building ) === 0 ) {
			@unlink( $building );
			throw new \RuntimeException( 'Archive is missing or empty after close.' );
		}

		// Atomic publish: only complete zips are visible to restore/download UI.
		if ( ! @rename( $building, $final ) ) {
			@unlink( $building );
			throw new \RuntimeException( 'Failed to finalize archive filename.' );
		}

		$this->apply_retention();

		return [
			'path' => $final,
			'file' => basename( $final ),
			'size' => (int) filesize( $final ),
		];
	}

	private function add_directory_to_zip( \ZipArchive $zip, string $root, string $local_prefix, bool $exclude_thumbs ): void {
		$root = trailingslashit( wp_normalize_path( $root ) );
		$storage = trailingslashit( wp_normalize_path( SBS_STORAGE_DIR ) );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			$path = wp_normalize_path( $file->getPathname() );

			// Never pack our own backup storage (avoids recursion + part files).
			if ( str_starts_with( $path, $storage ) ) {
				continue;
			}

			$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
			if ( $relative === '' ) {
				continue;
			}

			// Skip noisy / unsafe paths.
			if ( $this->should_skip( $relative, $exclude_thumbs ) ) {
				continue;
			}

			$zip_path = $local_prefix !== '' ? $local_prefix . $relative : $relative;

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $zip_path );
				continue;
			}

			if ( ! $file->isFile() || ! $file->isReadable() ) {
				continue;
			}

			// addFile stores path reference — fine while zip still open and source exists.
			$zip->addFile( $path, $zip_path );
		}
	}

	private function should_skip( string $relative, bool $exclude_thumbs ): bool {
		$relative = str_replace( '\\', '/', $relative );

		$skip_prefixes = [
			'wp-content/sbs-storage/',
			'wp-content/cache/',
			'wp-content/upgrade/',
			'wp-content/updraft/',
			'wp-content/ai1wm-backups/',
			'wp-content/debug.log',
		];

		foreach ( $skip_prefixes as $prefix ) {
			if ( str_starts_with( $relative, $prefix ) || $relative === rtrim( $prefix, '/' ) ) {
				return true;
			}
		}

		// Skip our incomplete artifacts if any leaked outside storage.
		if ( preg_match( '/\.(building|tmp|part)$/i', $relative ) ) {
			return true;
		}

		if ( $exclude_thumbs && str_starts_with( $relative, 'wp-content/uploads/' ) ) {
			if ( preg_match( '/-\d+x\d+\.(jpe?g|png|gif|webp|avif)$/i', $relative ) ) {
				return true;
			}
		}

		return false;
	}

	private function cleanup_incomplete_files(): void {
		if ( ! is_dir( SBS_STORAGE_DIR ) ) {
			return;
		}
		foreach ( glob( SBS_STORAGE_DIR . 'backup-*.{building,tmp,part}', GLOB_BRACE ) ?: [] as $stale ) {
			@unlink( $stale );
		}
		foreach ( glob( SBS_STORAGE_DIR . 'db-*.sql' ) ?: [] as $stale_sql ) {
			@unlink( $stale_sql );
		}
	}

	private function apply_retention(): void {
		$max = (int) SettingsManager::get( 'backup', 'retention_count', 14 );
		if ( $max < 1 ) {
			$max = 14;
		}

		$files = glob( SBS_STORAGE_DIR . 'backup-*.zip' ) ?: [];
		// Only finalized zips.
		$files = array_values( array_filter( $files, static function ( string $f ): bool {
			return is_file( $f ) && ! str_ends_with( $f, '.building' );
		} ) );

		usort( $files, static function ( string $a, string $b ): int {
			return filemtime( $b ) <=> filemtime( $a );
		} );

		$i = 0;
		foreach ( $files as $file ) {
			$i++;
			if ( $i > $max ) {
				@unlink( $file );
			}
		}
	}
}