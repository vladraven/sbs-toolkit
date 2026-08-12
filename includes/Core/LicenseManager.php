<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class LicenseManager {

	public const SECRET_OPTION = 'sbs_license_secret';

	private const TRIAL_DAYS = 90;
	private const SWITCH_COOLDOWN_DAYS = 30;

	/**
	 * Create a site-unique secret once (activation / first run).
	 * Optional override: define( 'SBS_LICENSE_SECRET', '...' ) in wp-config.php.
	 */
	public static function ensure_secret(): string {
		if ( defined( 'SBS_LICENSE_SECRET' ) && is_string( SBS_LICENSE_SECRET ) && SBS_LICENSE_SECRET !== '' ) {
			return SBS_LICENSE_SECRET;
		}

		$existing = get_option( self::SECRET_OPTION, '' );
		if ( is_string( $existing ) && strlen( $existing ) >= 32 ) {
			self::persist_secret_file( $existing );
			return $existing;
		}

		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			$secret = wp_generate_password( 64, true, true );
		}

		update_option( self::SECRET_OPTION, $secret, false );
		self::persist_secret_file( $secret );

		return $secret;
	}

	private static function persist_secret_file( string $secret ): void {
		if ( ! defined( 'SBS_STORAGE_DIR' ) ) {
			return;
		}
		if ( ! is_dir( SBS_STORAGE_DIR ) ) {
			wp_mkdir_p( SBS_STORAGE_DIR );
		}

		$file = SBS_STORAGE_DIR . '.license_secret';
		if ( ! file_exists( $file ) ) {
			@file_put_contents( $file, $secret, LOCK_EX );
		}

		$ht = SBS_STORAGE_DIR . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			@file_put_contents( $ht, "Deny from all\n" );
		}
		$idx = SBS_STORAGE_DIR . 'index.php';
		if ( ! file_exists( $idx ) ) {
			@file_put_contents( $idx, "<?php\n// Silence.\n" );
		}
	}

	private function get_secret_key(): string {
		if ( defined( 'SBS_LICENSE_SECRET' ) && is_string( SBS_LICENSE_SECRET ) && SBS_LICENSE_SECRET !== '' ) {
			return SBS_LICENSE_SECRET;
		}

		if ( defined( 'SBS_STORAGE_DIR' ) ) {
			$file = SBS_STORAGE_DIR . '.license_secret';
			if ( is_readable( $file ) ) {
				$from_file = trim( (string) file_get_contents( $file ) );
				if ( strlen( $from_file ) >= 32 ) {
					return $from_file;
				}
			}
		}

		$from_option = get_option( self::SECRET_OPTION, '' );
		if ( is_string( $from_option ) && strlen( $from_option ) >= 32 ) {
			return $from_option;
		}

		return self::ensure_secret();
	}

	public function init_defaults(): void {
		self::ensure_secret();

		if ( ! get_option( 'sbs_license_status' ) ) {
			add_option( 'sbs_license_status', 'trial', '', false );
			add_option( 'sbs_trial_end_date', time() + ( self::TRIAL_DAYS * DAY_IN_SECONDS ), '', false );
			add_option( 'sbs_active_free_module', 'performance', '', false );
			add_option( 'sbs_module_switch_date', 0, '', false );
		}
	}

	public function is_pro_or_trial(): bool {
		$status = (string) get_option( 'sbs_license_status', 'free' );

		if ( $status === 'pro' ) {
			return $this->verify_key( (string) get_option( 'sbs_license_key', '' ) );
		}

		if ( $status === 'trial' ) {
			$trial_end = (int) get_option( 'sbs_trial_end_date', 0 );
			if ( $trial_end > 0 && time() <= $trial_end ) {
				return true;
			}
			update_option( 'sbs_license_status', 'free', false );
		}

		return false;
	}

	public function get_status(): string {
		if ( ! $this->is_pro_or_trial() ) {
			return 'free';
		}
		$status = (string) get_option( 'sbs_license_status', 'free' );
		return $status === 'pro' ? 'pro' : 'trial';
	}

	public function verify_key( string $key ): bool {
		$secret = $this->get_secret_key();
		if ( $secret === '' ) {
			return false;
		}

		$clean_key = strtoupper( str_replace( [ 'SBS1-', '-' ], '', trim( $key ) ) );
		if ( strlen( $clean_key ) !== 16 ) {
			return false;
		}

		$raw_bytes = $this->base32_decode( $clean_key );
		if ( strlen( $raw_bytes ) !== 10 ) {
			return false;
		}

		$payload   = substr( $raw_bytes, 0, 7 );
		$signature = substr( $raw_bytes, 7, 3 );

		$expected = substr( hash_hmac( 'sha256', $payload, $secret, true ), 0, 3 );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		$unpacked = unpack( 'Nexpire/Ctype', substr( $payload, 0, 5 ) );
		if ( ! is_array( $unpacked ) || (int) $unpacked['expire'] < time() ) {
			return false;
		}

		$current_domain = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
		$current_domain = explode( ':', explode( '/', $current_domain )[0] )[0];
		$current_hash   = substr( hash( 'sha256', $current_domain, true ), 0, 2 );
		$key_hash       = substr( $payload, 5, 2 );

		return hash_equals( $current_hash, $key_hash );
	}

	public function activate_key( string $key ): bool {
		$key = sanitize_text_field( $key );
		if ( ! $this->verify_key( $key ) ) {
			return false;
		}
		update_option( 'sbs_license_key', $key, false );
		update_option( 'sbs_license_status', 'pro', false );
		return true;
	}

	public function can_switch_free_module(): bool {
		$last_switch = (int) get_option( 'sbs_module_switch_date', 0 );
		if ( $last_switch === 0 ) {
			return true;
		}
		return ( time() - $last_switch ) >= ( self::SWITCH_COOLDOWN_DAYS * DAY_IN_SECONDS );
	}

	public function set_active_free_module( string $module_id ): bool {
		$module_id = sanitize_key( $module_id );
		$allowed   = [ 'backup', 'performance', 'security', 'deception', 'analytics', 'devops' ];
		if ( ! in_array( $module_id, $allowed, true ) ) {
			return false;
		}
		if ( ! $this->can_switch_free_module() ) {
			return false;
		}
		update_option( 'sbs_active_free_module', $module_id, false );
		update_option( 'sbs_module_switch_date', time(), false );
		return true;
	}

	private function base32_decode( string $input ): string {
		$map    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$input  = strtoupper( $input );
		$buffer = 0;
		$bits   = 0;
		$output = '';

		$len = strlen( $input );
		for ( $i = 0; $i < $len; $i++ ) {
			$val = strpos( $map, $input[ $i ] );
			if ( $val === false ) {
				continue;
			}
			$buffer = ( $buffer << 5 ) | $val;
			$bits  += 5;
			if ( $bits >= 8 ) {
				$bits   -= 8;
				$output .= chr( ( $buffer >> $bits ) & 0xFF );
			}
		}

		return $output;
	}
}