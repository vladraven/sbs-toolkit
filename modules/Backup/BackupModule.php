<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Backup;

use Vlad\SBS\Core\Logger;
use Vlad\SBS\Core\ModuleInterface;
use Vlad\SBS\Core\SettingsManager;

final class BackupModule implements ModuleInterface {

	public function get_id(): string {
		return 'backup';
	}

	public function get_name(): string {
		return __( 'Smart Backup', 'sbs' );
	}

	public function boot(): void {
		if ( SettingsManager::get( 'backup', 'safe_updates_enabled', false ) ) {
			( new SafeUpdate() )->boot();
		}

		add_action( 'admin_init', [ $this, 'handle_file_download' ] );
		add_action( 'sbs_scheduled_backup', [ $this, 'run_scheduled_backup' ] );

		// Keep cron in sync with saved settings (Pro/Trial only).
		$this->sync_schedule();
	}

	public function register_admin_ui(): void {
		add_action( 'wp_ajax_sbs_run_manual_backup', [ $this, 'ajax_run_backup' ] );
		add_action( 'wp_ajax_sbs_get_backups', [ $this, 'ajax_get_backups' ] );
		add_action( 'wp_ajax_sbs_delete_backup', [ $this, 'ajax_delete_backup' ] );
		add_action( 'wp_ajax_sbs_rename_backup', [ $this, 'ajax_rename_backup' ] );
		add_action( 'wp_ajax_sbs_restore_backup', [ $this, 'ajax_restore_backup' ] );
	}

	public function apply_soft_lock_ui(): void {
		$locked = static function (): void {
			wp_send_json_error( [ 'message' => __( 'Action locked in Free mode.', 'sbs' ) ], 403 );
		};
		add_action( 'wp_ajax_sbs_run_manual_backup', $locked );
		add_action( 'wp_ajax_sbs_delete_backup', $locked );
		add_action( 'wp_ajax_sbs_rename_backup', $locked );
		add_action( 'wp_ajax_sbs_restore_backup', $locked );
		add_action( 'wp_ajax_sbs_get_backups', [ $this, 'ajax_get_backups' ] );
	}

	/**
	 * Manual backup — single ZIP in one request.
	 * Free: max 1 archive, no remote, no schedule (schedule cleared in sync_schedule).
	 */
	public function ajax_run_backup(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$is_pro = $this->is_pro_or_trial();

		if ( ! $is_pro ) {
			$existing = $this->list_complete_zips();
			if ( count( $existing ) >= 1 ) {
				wp_send_json_error( [
					'message' => __( 'Free plan allows only 1 backup. Delete the existing archive or upgrade to Pro.', 'sbs' ),
				], 403 );
			}
		}

		try {
			$remote = $this->get_remote_flags( $is_pro );

			$builder = new ArchiveBuilder();
			$result  = $builder->create_backup( [
				'exclude_thumbs'  => (bool) SettingsManager::get( 'backup', 'exclude_thumbs', true ),
				'upload_ftp'      => $remote['ftp'],
				'upload_s3'       => $remote['s3'],
				'retention_count' => $is_pro
					? (int) SettingsManager::get( 'backup', 'retention_count', 14 )
					: 1,
			] );

			wp_send_json_success( [
				'message'  => __( 'Backup completed. One zip file is ready.', 'sbs' ),
				'filename' => $result['file'],
				'size'     => $result['size'],
			] );
		} catch ( \Throwable $e ) {
			Logger::log( 'backup', 'error', $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}
	}

	public function run_scheduled_backup(): void {
		if ( ! $this->is_pro_or_trial() ) {
			return;
		}

		try {
			$remote = $this->get_remote_flags( true );
			$builder = new ArchiveBuilder();
			$builder->create_backup( [
				'exclude_thumbs'  => (bool) SettingsManager::get( 'backup', 'exclude_thumbs', true ),
				'upload_ftp'      => $remote['ftp'],
				'upload_s3'       => $remote['s3'],
				'retention_count' => (int) SettingsManager::get( 'backup', 'retention_count', 14 ),
			] );
		} catch ( \Throwable $e ) {
			Logger::log( 'backup', 'error', 'Scheduled backup failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Register or clear WP-Cron from settings:
	 * backup.schedule = off | daily | weekly
	 */
	public function sync_schedule(): void {
		$hook = 'sbs_scheduled_backup';

		if ( ! $this->is_pro_or_trial() ) {
			wp_clear_scheduled_hook( $hook );
			return;
		}

		$schedule = (string) SettingsManager::get( 'backup', 'schedule', 'off' );
		$recurrence = match ( $schedule ) {
			'daily'  => 'daily',
			'weekly' => 'weekly',
			default  => '',
		};

		if ( $recurrence === '' ) {
			wp_clear_scheduled_hook( $hook );
			return;
		}

		$next = wp_next_scheduled( $hook );
		if ( $next ) {
			// Reschedule if interval changed.
			$current = wp_get_schedule( $hook );
			if ( $current === $recurrence ) {
				return;
			}
			wp_clear_scheduled_hook( $hook );
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
	}

	public function ajax_get_backups(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$files = [];
		foreach ( $this->list_complete_zips() as $path ) {
			$name = basename( $path );
			$files[] = [
				'filename' => $name,
				'size'     => (int) filesize( $path ),
				'size_h'   => size_format( (int) filesize( $path ) ),
				'date'     => gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ),
				'download' => admin_url(
					'admin.php?page=sbs-toolkit&sbs_download_backup=' . rawurlencode( $name ) . '&nonce=' . wp_create_nonce( 'sbs_admin_nonce' )
				),
			];
		}

		usort(
			$files,
			static function ( array $a, array $b ): int {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		wp_send_json_success( [ 'backups' => $files ] );
	}

	public function ajax_delete_backup(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$filename = $this->safe_backup_name( wp_unslash( $_POST['filename'] ?? '' ) );
		if ( $filename === '' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid filename.', 'sbs' ) ] );
		}

		$path = SBS_STORAGE_DIR . $filename;
		if ( is_file( $path ) && @unlink( $path ) ) {
			wp_send_json_success( [ 'message' => __( 'Backup deleted.', 'sbs' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'File not found or cannot be deleted.', 'sbs' ) ] );
	}

	public function ajax_rename_backup(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$old_name = $this->safe_backup_name( wp_unslash( $_POST['old_name'] ?? '' ) );
		$new_name = $this->safe_backup_name( wp_unslash( $_POST['new_name'] ?? '' ) );

		if ( $old_name === '' || $new_name === '' || ! str_ends_with( strtolower( $new_name ), '.zip' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid filename (must be .zip).', 'sbs' ) ] );
		}

		$old_path = SBS_STORAGE_DIR . $old_name;
		$new_path = SBS_STORAGE_DIR . $new_name;

		if ( is_file( $old_path ) && ! file_exists( $new_path ) && @rename( $old_path, $new_path ) ) {
			wp_send_json_success( [ 'message' => __( 'Backup renamed.', 'sbs' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'Rename failed.', 'sbs' ) ] );
	}

	public function ajax_restore_backup(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		if ( empty( $_POST['confirm'] ) || (string) $_POST['confirm'] !== '1' ) {
			wp_send_json_error( [ 'message' => __( 'Restore not confirmed.', 'sbs' ) ] );
		}

		$filename = $this->safe_backup_name( wp_unslash( $_POST['filename'] ?? '' ) );
		$path     = SBS_STORAGE_DIR . $filename;

		if ( $filename === '' || ! is_file( $path ) ) {
			wp_send_json_error( [ 'message' => __( 'Backup file not found.', 'sbs' ) ] );
		}

		if ( ! class_exists( \ZipArchive::class ) ) {
			wp_send_json_error( [ 'message' => 'ZipArchive required.' ] );
		}

		@set_time_limit( 0 );

		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			wp_send_json_error( [ 'message' => __( 'Cannot open backup zip.', 'sbs' ) ] );
		}

		$sql_tmp        = SBS_STORAGE_DIR . 'restore-import.sql';
		$restored_files = 0;

		try {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( empty( $stat['name'] ) ) {
					continue;
				}
				$name = str_replace( '\\', '/', (string) $stat['name'] );

				if ( str_ends_with( $name, '/' ) || str_contains( $name, '..' ) ) {
					continue;
				}

				if ( $name === 'wp-config.php' || str_ends_with( $name, '/wp-config.php' ) ) {
					continue;
				}

				if ( $name === 'database.sql' || str_ends_with( $name, '/database.sql' ) ) {
					$stream = $zip->getStream( $stat['name'] );
					if ( $stream ) {
						$out = fopen( $sql_tmp, 'wb' );
						if ( $out ) {
							while ( ! feof( $stream ) ) {
								$chunk = fread( $stream, 1048576 );
								if ( $chunk === false ) {
									break;
								}
								fwrite( $out, $chunk );
							}
							fclose( $out );
						}
						fclose( $stream );
					}
					continue;
				}

				$target = ABSPATH . $name;
				$dir    = dirname( $target );
				if ( ! is_dir( $dir ) ) {
					wp_mkdir_p( $dir );
				}

				$stream = $zip->getStream( $stat['name'] );
				if ( ! $stream ) {
					continue;
				}
				$out = fopen( $target, 'wb' );
				if ( $out ) {
					while ( ! feof( $stream ) ) {
						$chunk = fread( $stream, 1048576 );
						if ( $chunk === false ) {
							break;
						}
						fwrite( $out, $chunk );
					}
					fclose( $out );
					$restored_files++;
				}
				fclose( $stream );
			}
			$zip->close();
		} catch ( \Throwable $e ) {
			@$zip->close();
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}

		$db_ok = false;
		if ( is_file( $sql_tmp ) ) {
			$db_ok = $this->import_sql_file( $sql_tmp );
			@unlink( $sql_tmp );
		}

		Logger::log( 'backup', 'warning', 'Restore executed.', [
			'file'           => $filename,
			'restored_files' => $restored_files,
			'db_imported'    => $db_ok,
		] );

		wp_send_json_success( [
			'message'        => __( 'Restore finished. Review the site carefully.', 'sbs' ),
			'restored_files' => $restored_files,
			'db_imported'    => $db_ok,
		] );
	}

	private function import_sql_file( string $sql_path ): bool {
		global $wpdb;

		$sql = file_get_contents( $sql_path );
		if ( $sql === false || $sql === '' ) {
			return false;
		}

		$statements = preg_split( '/;\s*\n/', $sql );
		if ( ! is_array( $statements ) ) {
			return false;
		}

		foreach ( $statements as $statement ) {
			$statement = trim( $statement );
			if ( $statement === '' || str_starts_with( $statement, '--' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $statement );
		}

		return true;
	}

	public function handle_file_download(): void {
		if ( empty( $_GET['sbs_download_backup'] ) || empty( $_GET['nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'sbs_admin_nonce' ) ) {
			wp_die( 'Unauthorized access.' );
		}

		$filename = $this->safe_backup_name( wp_unslash( $_GET['sbs_download_backup'] ) );
		$path     = SBS_STORAGE_DIR . $filename;

		if ( $filename === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( 'File not found.' );
		}

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Cache-Control: private, must-revalidate' );
		header( 'Pragma: public' );
		readfile( $path );
		exit;
	}

	/** @return list<string> Absolute paths to complete backup-*.zip files. */
	private function list_complete_zips(): array {
		if ( ! is_dir( SBS_STORAGE_DIR ) ) {
			return [];
		}

		$out = [];
		foreach ( glob( SBS_STORAGE_DIR . 'backup-*.zip' ) ?: [] as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$name = basename( $path );
			if ( preg_match( '/\.(building|tmp|part)$/i', $name ) ) {
				continue;
			}
			$out[] = $path;
		}

		return $out;
	}

	/**
	 * Read nested remote settings from admin form (remote[ftp_enabled], etc.).
	 *
	 * @return array{ftp:bool,s3:bool}
	 */
	private function get_remote_flags( bool $is_pro ): array {
		if ( ! $is_pro ) {
			return [ 'ftp' => false, 's3' => false ];
		}

		$remote = SettingsManager::get( 'backup', 'remote', [] );
		if ( ! is_array( $remote ) ) {
			$remote = [];
		}

		// Support both nested remote.* and flat legacy keys.
		$ftp = ! empty( $remote['ftp_enabled'] ) || (bool) SettingsManager::get( 'backup', 'ftp_enabled', false );
		$s3  = ! empty( $remote['s3_enabled'] ) || (bool) SettingsManager::get( 'backup', 's3_enabled', false );

		return [
			'ftp' => (bool) $ftp,
			's3'  => (bool) $s3,
		];
	}

	private function is_pro_or_trial(): bool {
		$status = (string) get_option( 'sbs_license_status', 'free' );
		if ( $status === 'trial' ) {
			$end = (int) get_option( 'sbs_trial_end_date', 0 );
			return $end > 0 && time() <= $end;
		}
		if ( $status === 'pro' ) {
			// Key validity is enforced by LicenseManager on boot paths; treat stored pro as pro here.
			return (string) get_option( 'sbs_license_key', '' ) !== '';
		}
		return false;
	}

	private function safe_backup_name( string $name ): string {
		$name = basename( sanitize_file_name( $name ) );
		if ( $name === '' || str_contains( $name, '..' ) ) {
			return '';
		}
		if ( ! preg_match( '/^[\w.\-]+\.zip$/i', $name ) ) {
			return '';
		}
		return $name;
	}

	public function uninstall(): void {
		wp_clear_scheduled_hook( 'sbs_scheduled_backup' );
	}
}