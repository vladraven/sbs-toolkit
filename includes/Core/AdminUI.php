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

		wp_enqueue_style( 'sbs-admin-css', SBS_PLUGIN_URL . 'assets/css/admin.css', [], SBS_VERSION );
		wp_enqueue_script( 'sbs-admin-js', SBS_PLUGIN_URL . 'assets/js/admin.js', [], SBS_VERSION, true );

		$active_free_module = (string) get_option( 'sbs_active_free_module', 'performance' );
		$is_pro_or_trial    = $this->license->is_pro_or_trial();
		$switch_date        = (int) get_option( 'sbs_module_switch_date', 0 );
		$days_used          = $switch_date > 0 ? (int) floor( ( time() - $switch_date ) / DAY_IN_SECONDS ) : 30;
		$settings_raw       = get_option( 'sbs_settings', '{}' );
		$settings           = json_decode( is_string( $settings_raw ) ? $settings_raw : '{}', true );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		wp_localize_script( 'sbs-admin-js', 'sbsData', [
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'adminUrl'         => admin_url( 'admin.php' ),
			'nonce'            => wp_create_nonce( 'sbs_admin_nonce' ),
			'licenseStatus'    => $this->license->get_status(),
			'isProOrTrial'     => $is_pro_or_trial,
			'activeFreeModule' => $active_free_module,
			'canSwitchModule'  => $this->license->can_switch_free_module(),
			'switchDaysLeft'   => max( 0, 30 - $days_used ),
			'settings'         => $settings,
			'i18n'             => [
				'saved'        => __( 'Settings saved successfully.', 'sbs' ),
				'error'        => __( 'An error occurred.', 'sbs' ),
				'lockedNotice' => __( 'This module is in Soft-Lock mode (Read-Only). Upgrade to Pro or select it as your active Free module to edit.', 'sbs' ),
				'activated'    => __( 'Pro License activated!', 'sbs' ),
				'invalidKey'   => __( 'Invalid or expired license key.', 'sbs' ),
			],
		] );
	}

	public function render_app_container(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><div id="sbs-app-root">Loading SBS Toolkit…</div></div>';
	}

	public function ajax_save_settings(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$module_id = sanitize_key( wp_unslash( $_POST['module_id'] ?? '' ) );
		$settings  = $_POST['settings'] ?? [];
		if ( ! is_array( $settings ) || $module_id === '' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid payload.', 'sbs' ) ] );
		}

		if ( ! $this->license->is_pro_or_trial() ) {
			$active = (string) get_option( 'sbs_active_free_module', 'performance' );
			if ( $module_id !== $active ) {
				wp_send_json_error( [ 'message' => __( 'Module is soft-locked.', 'sbs' ) ], 403 );
			}
		}

		$sanitized = $this->sanitize_array_recursive( $settings );
		$all_raw   = get_option( 'sbs_settings', '{}' );
		$all       = json_decode( is_string( $all_raw ) ? $all_raw : '{}', true );
		if ( ! is_array( $all ) ) {
			$all = [];
		}
		$all[ $module_id ] = $sanitized;
		update_option( 'sbs_settings', wp_json_encode( $all ), false );

		wp_send_json_success( [ 'message' => __( 'Settings saved successfully.', 'sbs' ) ] );
	}

	private function sanitize_array_recursive( array $array ): array {
		$clean = [];
		foreach ( $array as $key => $value ) {
			$k = is_string( $key ) ? sanitize_key( $key ) : $key;
			if ( is_array( $value ) ) {
				$clean[ $k ] = $this->sanitize_array_recursive( $value );
			} else {
				$clean[ $k ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}

	public function ajax_activate_license(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		if ( $key === '' ) {
			wp_send_json_error( [ 'message' => __( 'License key is empty.', 'sbs' ) ] );
		}

		if ( $this->license->activate_key( $key ) ) {
			Logger::log( 'core', 'info', 'Pro license activated.' );
			wp_send_json_success( [
				'message' => __( 'Pro License activated!', 'sbs' ),
				'status'  => 'pro',
			] );
		}

		wp_send_json_error( [ 'message' => __( 'Invalid or expired license key.', 'sbs' ) ] );
	}

	public function ajax_switch_free_module(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$module_id = sanitize_key( wp_unslash( $_POST['module_id'] ?? '' ) );
		if ( $this->license->set_active_free_module( $module_id ) ) {
			wp_send_json_success( [ 'message' => __( 'Active Free module switched.', 'sbs' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'You can only switch your active Free module once every 30 days.', 'sbs' ) ] );
	}

	public function ajax_export_settings(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$settings = get_option( 'sbs_settings', '{}' );
		wp_send_json_success( [ 'json' => is_string( $settings ) ? $settings : '{}' ] );
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

		update_option( 'sbs_settings', wp_json_encode( $this->sanitize_array_recursive( $data ) ), false );
		wp_send_json_success( [ 'message' => __( 'Settings imported successfully. Please reload the page.', 'sbs' ) ] );
	}

	public function ajax_get_audit_logs(): void {
		check_ajax_referer( 'sbs_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sbs_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );

		wp_send_json_success( [ 'logs' => is_array( $logs ) ? $logs : [] ] );
	}
}