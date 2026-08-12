<?php
/**
 * Plugin Name: SBS Toolkit
 * Plugin URI: https://vlad.dev/sbs-toolkit
 * Description: All-in-one modular toolkit: Setup, Backup, Security, Analytics & Remote Control.
 * Version: 1.0.4
 * Author: Vlad
 * Text Domain: sbs
 * Domain Path: /languages
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SBS_VERSION', '1.0.4' );
define( 'SBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SBS_STORAGE_DIR', WP_CONTENT_DIR . '/sbs-storage/' );
define( 'SBS_DB_VERSION', '1.0' );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'Vlad\\SBS\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative_class = substr( $class, strlen( $prefix ) );

	if ( str_starts_with( $relative_class, 'Core\\' ) || str_starts_with( $relative_class, 'Utils\\' ) ) {
		$file = SBS_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';
	} elseif ( str_starts_with( $relative_class, 'Modules\\' ) ) {
		$module_path = substr( $relative_class, strlen( 'Modules\\' ) );
		$file        = SBS_PLUGIN_DIR . 'modules/' . str_replace( '\\', '/', $module_path ) . '.php';
	} else {
		$file = SBS_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';
	}

	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

use Vlad\SBS\Core\AdminUI;
use Vlad\SBS\Core\Database;
use Vlad\SBS\Core\LicenseManager;
use Vlad\SBS\Core\ModuleRegistry;
use Vlad\SBS\Core\SettingsManager;

add_filter( 'cron_schedules', static function ( array $schedules ): array {
	if ( ! isset( $schedules['every_minute'] ) ) {
		$schedules['every_minute'] = [
			'interval' => 60,
			'display'  => __( 'Every Minute (SBS Toolkit)', 'sbs' ),
		];
	}
	return $schedules;
} );

add_action( 'plugins_loaded', static function (): void {
	load_plugin_textdomain( 'sbs', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Ensure site secret exists even if activation hook was skipped.
	LicenseManager::ensure_secret();

	$license_manager = new LicenseManager();
	$registry        = new ModuleRegistry( $license_manager );

	new \Vlad\SBS\Core\JobQueue();

	if ( is_admin() ) {
		$admin_ui = new AdminUI( $license_manager );
		$admin_ui->boot();

		add_action( 'admin_notices', static function (): void {
			$page_cache_enabled = SettingsManager::get( 'performance', 'page_cache_enabled', false );
			if ( $page_cache_enabled && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) ) {
				echo '<div class="notice notice-error"><p><strong>SBS Toolkit:</strong> Page Cache is enabled, but <code>define("WP_CACHE", true);</code> is missing in <code>wp-config.php</code>. Cache drop-in is inactive.</p></div>';
			}
		} );
	}

	$modules = [
		\Vlad\SBS\Modules\Backup\BackupModule::class,
		\Vlad\SBS\Modules\Performance\PerformanceModule::class,
		\Vlad\SBS\Modules\Security\SecurityModule::class,
		\Vlad\SBS\Modules\Deception\DeceptionModule::class,
		\Vlad\SBS\Modules\Analytics\AnalyticsModule::class,
		\Vlad\SBS\Modules\DevOps\DevOpsModule::class,
	];

	foreach ( $modules as $module_class ) {
		if ( class_exists( $module_class ) ) {
			$registry->register( new $module_class() );
		}
	}

	$registry->boot_all();
} );

register_activation_hook( __FILE__, static function (): void {
	Database::create_tables();

	if ( ! file_exists( SBS_STORAGE_DIR ) ) {
		wp_mkdir_p( SBS_STORAGE_DIR );
	}
	file_put_contents( SBS_STORAGE_DIR . '.htaccess', "Deny from all\n" );
	file_put_contents( SBS_STORAGE_DIR . 'index.php', "<?php\n// Silence is golden.\n" );

	// Generate per-site license HMAC secret (not shipped in git).
	LicenseManager::ensure_secret();

	$license = new LicenseManager();
	$license->init_defaults();
} );