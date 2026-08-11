<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Backup;

use Vlad\SBS\Core\ModuleInterface;
use Vlad\SBS\Core\SettingsManager;
use Vlad\SBS\Core\JobQueue;

final class BackupModule implements ModuleInterface {
	public function get_id(): string {
		return 'backup';
	}

	public function get_name(): string {
		return __( 'Smart Backup', 'sbs' );
	}

	public function boot(): void {
		if ( SettingsManager::get( 'backup', 'safe_updates_enabled', false ) ) {
			$safe_update = new SafeUpdate();
			$safe_update->boot();
		}
		
		// Обработчик скачивания работает без AJAX, через обычный GET (для потоковой передачи файла)
		add_action( 'admin_init', [ $this, 'handle_file_download' ] );
	}

	public function register_admin_ui(): void {
		add_action( 'wp_ajax_sbs_run_manual_backup', [ $this, 'ajax_run_backup' ] );
		add_action( 'wp_ajax_sbs_get_backups', [ $this, 'ajax_get_backups' ] );
		add_action( 'wp_ajax_sbs_delete_backup', [ $this, 'ajax_delete_backup' ] );
		add_action( 'wp_ajax_sbs_rename_backup', [ $this, 'ajax_rename_backup' ] );
	}

	public function apply_soft_lock_ui(): void {
		$locked_callback = function (): void {
			wp_send_json_error( [ 'message' => __( 'Action locked in Free mode.', 'sbs' ) ], 403 );
		};
		add_action( 'wp_ajax_sbs_run_manual_backup', $locked_callback );
		add_action( 'wp_ajax_sbs_delete_backup', $locked_callback );
		add_action( 'wp_ajax_sbs_rename_backup', $locked_callback );
		
		// Чтение списка оставляем доступным
		add_action( 'wp_ajax_sbs_get_backups', [ $this, 'ajax_get_backups' ] );
	}

	public function ajax_run_backup(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$queue  = new JobQueue();
		$job_id = $queue->push( ArchiveBuilder::class, [
			'type'           => 'full',
			'exclude_thumbs' => true,
			'upload_ftp'     => SettingsManager::get( 'backup', 'ftp_enabled', false ),
			'upload_s3'      => SettingsManager::get( 'backup', 's3_enabled', false )
		] );

		wp_send_json_success( [
			'message' => __( 'Backup job queued successfully. Check the list in a few moments.', 'sbs' ),
			'job_id'  => $job_id
		] );
	}

	public function ajax_get_backups(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$files = [];
		if ( is_dir( SBS_STORAGE_DIR ) ) {
			$iterator = new \DirectoryIterator( SBS_STORAGE_DIR );
			foreach ( $iterator as $fileinfo ) {
				if ( $fileinfo->isFile() && preg_match( '/\.(zip|tar\.gz)$/i', $fileinfo->getFilename() ) ) {
					$files[] = [
						'name' => $fileinfo->getFilename(),
						'size' => round( $fileinfo->getSize() / 1024 / 1024, 2 ) . ' MB',
						'date' => wp_date( 'Y-m-d H:i:s', $fileinfo->getMTime() )
					];
				}
			}
		}

		usort( $files, function ( $a, $b ) {
			return strtotime( $b['date'] ) <=> strtotime( $a['date'] );
		} );

		wp_send_json_success( [ 'backups' => $files ] );
	}

	public function ajax_delete_backup(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
		$path     = SBS_STORAGE_DIR . $filename;

		if ( file_exists( $path ) && @unlink( $path ) ) {
			wp_send_json_success( [ 'message' => __( 'Backup deleted.', 'sbs' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'File not found or cannot be deleted.', 'sbs' ) ] );
		}
	}

	public function ajax_rename_backup(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$old_name = sanitize_file_name( wp_unslash( $_POST['old_name'] ?? '' ) );
		$new_name = sanitize_file_name( wp_unslash( $_POST['new_name'] ?? '' ) );

		if ( ! preg_match( '/\.(zip|tar\.gz)$/i', $new_name ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid file extension.', 'sbs' ) ] );
		}

		$old_path = SBS_STORAGE_DIR . $old_name;
		$new_path = SBS_STORAGE_DIR . $new_name;

		if ( file_exists( $old_path ) && ! file_exists( $new_path ) ) {
			if ( @rename( $old_path, $new_path ) ) {
				wp_send_json_success( [ 'message' => __( 'Backup renamed.', 'sbs' ) ] );
			}
		}
		wp_send_json_error( [ 'message' => __( 'Rename failed.', 'sbs' ) ] );
	}

	public function handle_file_download(): void {
		if ( isset( $_GET['sbs_download_backup'], $_GET['nonce'] ) ) {
			if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['nonce'], 'sbs_admin_nonce' ) ) {
				wp_die( 'Unauthorized access.' );
			}

			$filename = sanitize_file_name( wp_unslash( $_GET['sbs_download_backup'] ) );
			$path     = SBS_STORAGE_DIR . $filename;

			if ( file_exists( $path ) && is_readable( $path ) ) {
				header( 'Content-Description: File Transfer' );
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
				header( 'Expires: 0' );
				header( 'Cache-Control: must-revalidate' );
				header( 'Pragma: public' );
				header( 'Content-Length: ' . filesize( $path ) );
				readfile( $path );
				exit;
			}
			wp_die( 'File not found.' );
		}
	}

	public function uninstall(): void {}
}