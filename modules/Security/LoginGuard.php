<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Security;

final class LoginGuard {
	private string $custom_slug;

	public function __construct( string $custom_slug ) {
		$this->custom_slug = trim( sanitize_title( $custom_slug ) );
	}

	public function boot(): void {
		if ( empty( $this->custom_slug ) ) {
			return;
		}

		add_action( 'init', [ $this, 'intercept_login_requests' ] );
		add_filter( 'site_url', [ $this, 'filter_site_url' ], 10, 3 );
		add_filter( 'network_site_url', [ $this, 'filter_site_url' ], 10, 3 );
		add_filter( 'wp_redirect', [ $this, 'filter_redirect' ] );
	}

	public function intercept_login_requests(): void {
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$parsed_url  = wp_parse_url( $request_uri );
		$path        = $parsed_url['path'] ?? '';

		$is_wp_login = ( strpos( $path, 'wp-login.php' ) !== false );
		$is_custom   = ( strpos( $path, '/' . $this->custom_slug ) !== false );

		// 1. Блокируем прямой доступ к wp-login.php
		if ( $is_wp_login && ! is_user_logged_in() ) {
			$action = $_GET['action'] ?? '';
			// Исключаем AJAX и logout
			if ( ! in_array( $action, [ 'logout', 'postpass', 'confirmaction' ], true ) ) {
				header( 'HTTP/1.1 403 Forbidden' );
				exit( 'Access Denied.' );
			}
		}

		// 2. Обрабатываем правильный кастомный slug
		if ( $is_custom && ! is_user_logged_in() ) {
			global $error, $interim_login, $action, $user_login;
			@require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	public function filter_site_url( string $url, string $path, string $scheme ): string {
		if ( strpos( $path, 'wp-login.php' ) !== false ) {
			$action = $_GET['action'] ?? '';
			if ( $action !== 'logout' && $action !== 'postpass' ) {
				return str_replace( 'wp-login.php', $this->custom_slug, $url );
			}
		}
		return $url;
	}

	public function filter_redirect( string $location ): string {
		if ( strpos( $location, 'wp-login.php' ) !== false ) {
			$location = str_replace( 'wp-login.php', $this->custom_slug, $location );
		}
		return $location;
	}
}