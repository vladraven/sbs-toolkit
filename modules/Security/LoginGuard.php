<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Security;

/**
 * Custom login slug + emergency unlock.
 *
 * Emergency (any one):
 * 1) define( 'SBS_DISABLE_CUSTOM_LOGIN', true ); in wp-config.php
 * 2) Visit: /wp-login.php?sbs_emergency=TOKEN
 *    Token: option sbs_login_emergency_token (generated when custom login is used)
 */
final class LoginGuard {
	private string $custom_slug;

	public function __construct( string $custom_slug ) {
		$this->custom_slug = trim( sanitize_title( $custom_slug ) );
	}

	public function boot(): void {
		if ( defined( 'SBS_DISABLE_CUSTOM_LOGIN' ) && SBS_DISABLE_CUSTOM_LOGIN ) {
			return;
		}

		if ( $this->custom_slug === '' ) {
			return;
		}

		self::ensure_emergency_token();

		add_action( 'init', [ $this, 'maybe_emergency_unlock' ], 0 );
		add_action( 'init', [ $this, 'intercept_login_requests' ], 1 );
		add_filter( 'site_url', [ $this, 'filter_site_url' ], 10, 3 );
		add_filter( 'network_site_url', [ $this, 'filter_site_url' ], 10, 3 );
		add_filter( 'wp_redirect', [ $this, 'filter_redirect' ] );
	}

	public function maybe_emergency_unlock(): void {
		if ( empty( $_GET['sbs_emergency'] ) ) {
			return;
		}

		$token  = sanitize_text_field( wp_unslash( (string) $_GET['sbs_emergency'] ) );
		$stored = (string) get_option( 'sbs_login_emergency_token', '' );
		if ( $stored === '' || ! hash_equals( $stored, $token ) ) {
			return;
		}

		$raw = get_option( 'sbs_settings', '{}' );
		$all = json_decode( is_string( $raw ) ? $raw : '{}', true );
		if ( is_array( $all ) ) {
			if ( ! isset( $all['security'] ) || ! is_array( $all['security'] ) ) {
				$all['security'] = [];
			}
			$all['security']['custom_login_slug'] = '';
			update_option( 'sbs_settings', wp_json_encode( $all ), false );
		}

		update_option( 'sbs_login_emergency_token', bin2hex( random_bytes( 16 ) ), false );

		$this->custom_slug = '';
		remove_action( 'init', [ $this, 'intercept_login_requests' ], 1 );
	}

	public function intercept_login_requests(): void {
		if ( $this->custom_slug === '' ) {
			return;
		}

		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$parsed_url  = wp_parse_url( $request_uri );
		$path        = isset( $parsed_url['path'] ) ? (string) $parsed_url['path'] : '';

		$is_wp_login = str_contains( $path, 'wp-login.php' );
		$is_custom   = str_contains( $path, '/' . $this->custom_slug );

		if ( $is_wp_login && ! is_user_logged_in() ) {
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
			if ( ! in_array( $action, [ 'logout', 'postpass', 'confirmaction' ], true ) ) {
				if ( empty( $_GET['sbs_emergency'] ) ) {
					status_header( 403 );
					header( 'Content-Type: text/plain; charset=UTF-8' );
					echo 'Access Denied.';
					exit;
				}
			}
		}

		if ( $is_custom && ! is_user_logged_in() ) {
			global $error, $interim_login, $action, $user_login;
			require ABSPATH . 'wp-login.php';
			exit;
		}
	}

	public function filter_site_url( string $url, string $path, $scheme = null ): string {
		if ( $this->custom_slug === '' ) {
			return $url;
		}
		if ( str_contains( $path, 'wp-login.php' ) ) {
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
			if ( $action !== 'logout' && $action !== 'postpass' ) {
				return str_replace( 'wp-login.php', $this->custom_slug, $url );
			}
		}
		return $url;
	}

	public function filter_redirect( string $location ): string {
		if ( $this->custom_slug === '' ) {
			return $location;
		}
		if ( str_contains( $location, 'wp-login.php' ) ) {
			$location = str_replace( 'wp-login.php', $this->custom_slug, $location );
		}
		return $location;
	}

	public static function ensure_emergency_token(): string {
		$token = (string) get_option( 'sbs_login_emergency_token', '' );
		if ( $token === '' || strlen( $token ) < 16 ) {
			$token = bin2hex( random_bytes( 16 ) );
			update_option( 'sbs_login_emergency_token', $token, false );
		}
		return $token;
	}
}