<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class AdminUI {
	private LicenseManager $license;

	public function __construct( LicenseManager $license ) {
		$this->license = $license;
	}

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_sbs_save_module_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_sbs_activate_license', [ $this, 'ajax_activate_license' ] );
		add_action( 'wp_ajax_sbs_switch_free_module', [ $this, 'ajax_switch_free_module' ] );
		add_action( 'wp_ajax_sbs_export_settings', [ $this, 'ajax_export_settings' ] );
		add_action( 'wp_ajax_sbs_import_settings', [ $this, 'ajax_import_settings' ] );
		add_action( 'wp_ajax_sbs_get_audit_logs', [ $this, 'ajax_get_audit_logs' ] );
	}

	public function register_menu_page(): void {
		add_menu_page(
			'SBS Toolkit',
			'SBS Toolkit',
			'manage_options',
			'sbs-toolkit',
			[ $this, 'render_app_container' ],
			'dashicons-shield-alt',
			80
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_sbs-toolkit' ) {
			return;
		}

		wp_enqueue_style(
			'sbs-admin-css',
			SBS_PLUGIN_URL . 'assets/css/admin.css',
			[],
			SBS_VERSION
		);

		wp_enqueue_script(
			'sbs-admin-js',
			SBS_PLUGIN_URL . 'assets/js/admin.js',
			[],
			SBS_VERSION,
			true
		);

		$active_free_module = get_option( 'sbs_active_free_module', 'performance' );
		$is_pro_or_trial    = $this->license->is_pro_or_trial();

		wp_localize_script( 'sbs-admin-js', 'sbsData', [
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'sbs_admin_nonce' ),
			'licenseStatus'    => get_option( 'sbs_license_status', 'free' ),
			'isProOrTrial'     => $is_pro_or_trial,
			'activeFreeModule' => $active_free_module,
			'canSwitchModule'  => $this->license->can_switch_free_module(),
			'switchDaysLeft'   => max( 0, 30 - (int) floor( ( time() - (int) get_option( 'sbs_module_switch_date', 0 ) ) / DAY_IN_SECONDS ) ),
			'settings'         => json_decode( (string) get_option( 'sbs_settings', '{}' ), true ),
			'i18n'             => [
				'saved'        => __( 'Settings saved successfully.', 'sbs' ),
				'error'        => __( 'An error occurred.', 'sbs' ),
				'lockedNotice' => __( 'This module is in Soft-Lock mode (Read-Only). Upgrade to Pro or select it as your active Free module to edit.', 'sbs' ),
			]
		] );
	}

	public function render_app_container(): void {
		echo '<div id="sbs-app-root" class="sbs-app"></div>';
	}

	public function ajax_save_settings(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$module_id = sanitize_key( $_POST['module_id'] ?? '' );
		$data      = $_POST['settings'] ?? [];

		$is_pro_or_trial = $this->license->is_pro_or_trial();
		$active_free     = get_option( 'sbs_active_free_module', 'performance' );

		if ( ! $is_pro_or_trial && $active_free !== $module_id ) {
			wp_send_json_error( [ 'message' => __( 'Cannot save: Module is Soft-Locked.', 'sbs' ) ], 403 );
		}

		if ( ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid payload.', 'sbs' ) ], 400 );
		}

		$sanitized_data = $this->sanitize_array_recursive( wp_unslash( $data ) );

		foreach ( $sanitized_data as $key => $val ) {
			SettingsManager::set( $module_id, sanitize_key( $key ), $val );
		}

		wp_send_json_success( [ 'message' => __( 'Settings updated.', 'sbs' ) ] );
	}

	private function sanitize_array_recursive( array $array ): array {
		$sanitized = [];
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$sanitized[ sanitize_key( $key ) ] = $this->sanitize_array_recursive( $value );
			} else {
				$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $sanitized;
	}

	public function ajax_activate_license(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		if ( $this->license->verify_key( $key ) ) {
			update_option( 'sbs_license_key', $key );
			update_option( 'sbs_license_status', 'pro' );
			wp_send_json_success( [ 'message' => __( 'Pro License activated!', 'sbs' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Invalid or expired license key.', 'sbs' ) ] );
		}
	}

	public function ajax_switch_free_module(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$module_id = sanitize_key( $_POST['module_id'] ?? '' );
		if ( $this->license->set_active_free_module( $module_id ) ) {
			wp_send_json_success( [ 'message' => __( 'Active Free module switched.', 'sbs' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'You can only switch your active Free module once every 30 days.', 'sbs' ) ] );
		}
	}

	public function ajax_export_settings(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$settings = get_option( 'sbs_settings', '{}' );
		wp_send_json_success( [ 'json' => $settings ] );
	}

	public function ajax_import_settings(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$json_string = wp_unslash( $_POST['json_data'] ?? '' );
		$data        = json_decode( $json_string, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON format.', 'sbs' ) ] );
		}

		$sanitized_data = $this->sanitize_array_recursive( $data );

		update_option( 'sbs_settings', wp_json_encode( $sanitized_data ) );
		wp_send_json_success( [ 'message' => __( 'Settings imported successfully. Please reload the page.', 'sbs' ) ] );
	}

	public function ajax_get_audit_logs(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sbs_logs';
		$logs  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );

		wp_send_json_success( [ 'logs' => $logs ] );
	}
}