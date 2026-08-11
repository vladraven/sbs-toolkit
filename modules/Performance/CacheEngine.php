<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Performance;

use Vlad\SBS\Core\SettingsManager;

final class CacheEngine {
	private string $cache_dir;

	public function __construct() {
		$this->cache_dir = SBS_STORAGE_DIR . 'cache/';
		if ( ! is_dir( $this->cache_dir ) ) {
			wp_mkdir_p( $this->cache_dir );
		}
	}

	public function start_buffer(): void {
		if ( ! $this->should_cache_request() ) {
			return;
		}

		ob_start( function ( string $html ): string {
			$this->save_to_file( $html );
			return $html . "\n<!-- SBS Cached -->";
		} );
	}

	private function should_cache_request(): bool {
		if ( ! SettingsManager::get( 'performance', 'page_cache_enabled', false ) ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			return false;
		}
		if ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
			return false;
		}
		if ( is_search() || is_404() ) {
			return false;
		}
		if ( ! empty( $_GET ) ) {
			return false;
		}
		return true;
	}

	private function save_to_file( string $html ): void {
		$url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$hash = md5( $url );
		
		$file_path = $this->cache_dir . $hash . '.html';
		file_put_contents( $file_path, $html, LOCK_EX );
	}

	public static function purge_all(): void {
		$dir = SBS_STORAGE_DIR . 'cache/';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		
		$iterator = new \DirectoryIterator( $dir );
		foreach ( $iterator as $fileinfo ) {
			if ( $fileinfo->isFile() && $fileinfo->getExtension() === 'html' ) {
				@unlink( $fileinfo->getRealPath() );
			}
		}
	}

	public static function ajax_purge_cache(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		
		self::purge_all();
		wp_send_json_success( [ 'message' => __( 'Page cache purged successfully.', 'sbs' ) ] );
	}

	public static function ajax_toggle_dropin(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$enable        = filter_var( $_POST['enable'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$dropin_dest   = WP_CONTENT_DIR . '/advanced-cache.php';
		$dropin_source = SBS_PLUGIN_DIR . 'modules/Performance/DropIn/advanced-cache.php';

		if ( $enable ) {
			if ( ! file_exists( $dropin_source ) ) {
				wp_send_json_error( [ 'message' => __( 'Source drop-in file is missing in plugin directory.', 'sbs' ) ] );
			}
			if ( ! copy( $dropin_source, $dropin_dest ) ) {
				wp_send_json_error( [ 'message' => __( 'Cannot write advanced-cache.php. Check wp-content permissions.', 'sbs' ) ] );
			}

			$wp_config_status = self::set_wp_cache_constant( true ) 
				? 'WP_CACHE constant updated.' 
				: 'Please add define("WP_CACHE", true); to wp-config.php manually.';

			SettingsManager::set( 'performance', 'page_cache_enabled', true );
			wp_send_json_success( [ 'message' => __( 'Page cache activated. ' . $wp_config_status, 'sbs' ) ] );
		} else {
			self::remove_dropin();
			self::set_wp_cache_constant( false );
			SettingsManager::set( 'performance', 'page_cache_enabled', false );
			wp_send_json_success( [ 'message' => __( 'Page cache deactivated.', 'sbs' ) ] );
		}
	}

	public static function remove_dropin(): void {
		$dropin_dest = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( file_exists( $dropin_dest ) ) {
			@unlink( $dropin_dest );
		}
	}

	private static function set_wp_cache_constant( bool $enable ): bool {
		$config_path = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $config_path ) ) {
			$config_path = dirname( ABSPATH ) . '/wp-config.php';
		}

		if ( ! file_exists( $config_path ) || ! is_writable( $config_path ) ) {
			return false;
		}

		$config = file_get_contents( $config_path );
		if ( $config === false ) {
			return false;
		}

		$has_constant = preg_match( "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,/i", $config );

		if ( $enable && ! $has_constant ) {
			$insert_string = "define( 'WP_CACHE', true ); // Added by SBS Toolkit\r\n";
			$pattern       = "/(\/\*\s*That's all, stop editing!(.*?)?\*\/)/i";
			
			if ( preg_match( $pattern, $config ) ) {
				$config = preg_replace( $pattern, $insert_string . "$1", $config );
			} else {
				$config = preg_replace( "/(\\$table_prefix\s*=\s*['\"][a-zA-Z0-9_]+['\"];)/i", "$1\r\n\r\n" . $insert_string, $config );
			}
			return (bool) file_put_contents( $config_path, $config );
		}

		if ( ! $enable && $has_constant ) {
			$config = preg_replace( "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*(true|false)\s*\);.*?(\r\n|\n)/i", "", $config );
			return (bool) file_put_contents( $config_path, $config );
		}

		return true;
	}
}