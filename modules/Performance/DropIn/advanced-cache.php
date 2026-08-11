<?php
/**
 * SBS Toolkit - Advanced Cache Drop-in
 * Этот файл автоматически копируется в папку wp-content/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die;
}

$sbs_cache_dir = WP_CONTENT_DIR . '/sbs-storage/cache/';
if ( ! is_dir( $sbs_cache_dir ) ) {
	return;
}

// Не кэшируем и не отдаем кэш для админки, AJAX и Cron
if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	return;
}

// Отдаем кэш только для GET запросов
if ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
	return;
}

// Не отдаем кэш авторизованным пользователям или тем, кто оставил комментарий
foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
	if ( preg_match( '/^(wp-postpass|wordpress_logged_in|comment_author_)/', $cookie_name ) ) {
		return;
	}
}

// Игнорируем запросы с параметрами (поиск, фильтры UTM метки и т.д.)
if ( ! empty( $_GET ) ) {
	return;
}

$url  = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$hash = md5( $url );
$file = $sbs_cache_dir . $hash . '.html';

if ( file_exists( $file ) ) {
	// Время жизни кэша: 24 часа. Если старше - удаляем физический файл, чтобы сгенерировать заново.
	if ( time() - filemtime( $file ) < 86400 ) {
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-SBS-Cache: HIT' );
		readfile( $file );
		exit;
	} else {
		@unlink( $file );
	}
}