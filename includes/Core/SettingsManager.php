<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class SettingsManager {
	private static ?array $settings = null;

	private static function load(): void {
		if ( self::$settings === null ) {
			$json           = get_option( 'sbs_settings', '{}' );
			$decoded        = json_decode( (string) $json, true );
			self::$settings = is_array( $decoded ) ? $decoded : [];
		}
	}

	public static function get( string $module, string $key, $default = null ) {
		self::load();
		return self::$settings[ $module ][ $key ] ?? $default;
	}

	public static function set( string $module, string $key, $value ): void {
		self::load();
		self::$settings[ $module ][ $key ] = $value;
		update_option( 'sbs_settings', wp_json_encode( self::$settings ) );
	}
}