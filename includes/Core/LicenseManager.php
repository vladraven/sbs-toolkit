<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class LicenseManager {
	private function get_secret_key(): string {
		return defined( 'SBS_LICENSE_SECRET' ) ? SBS_LICENSE_SECRET : 'vlad_sbs_secure_hmac_key_2026!@#';
	}

	public function init_defaults(): void {
		if ( ! get_option( 'sbs_license_status' ) ) {
			add_option( 'sbs_license_status', 'trial' );
			add_option( 'sbs_trial_end_date', time() + ( 14 * DAY_IN_SECONDS ) );
			add_option( 'sbs_active_free_module', 'performance' );
			add_option( 'sbs_module_switch_date', 0 );
		}
	}

	public function is_pro_or_trial(): bool {
		$status = get_option( 'sbs_license_status', 'free' );
		
		if ( $status === 'pro' ) {
			return $this->verify_key( (string) get_option( 'sbs_license_key' ) );
		}
		
		if ( $status === 'trial' ) {
			$trial_end = (int) get_option( 'sbs_trial_end_date', 0 );
			if ( time() <= $trial_end ) {
				return true;
			}
			update_option( 'sbs_license_status', 'free' );
		}
		
		return false;
	}

	public function verify_key( string $key ): bool {
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

		$expected_signature = substr( hash_hmac( 'sha256', $payload, $this->get_secret_key(), true ), 0, 3 );
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return false;
		}

		$unpacked = unpack( 'Nexpire/Ctype', substr( $payload, 0, 5 ) );
		if ( $unpacked['expire'] < time() ) {
			return false;
		}

		$current_domain = strtolower( $_SERVER['HTTP_HOST'] ?? 'localhost' );
		$current_domain = explode( ':', explode( '/', $current_domain )[0] )[0];
		$current_domain_hash = substr( hash( 'sha256', $current_domain, true ), 0, 2 );

		$key_domain_hash = substr( $payload, 5, 2 );
		if ( $key_domain_hash !== $current_domain_hash ) {
			return false;
		}

		return true;
	}

	public function can_switch_free_module(): bool {
		$last_switch = (int) get_option( 'sbs_module_switch_date', 0 );
		return ( time() - $last_switch ) >= ( 30 * DAY_IN_SECONDS );
	}

	public function set_active_free_module( string $module_id ): bool {
		if ( ! $this->can_switch_free_module() ) {
			return false;
		}
		update_option( 'sbs_active_free_module', $module_id );
		update_option( 'sbs_module_switch_date', time() );
		return true;
	}

	private function base32_decode( string $input ): string {
		$alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
		$bits     = '';
		foreach ( str_split( $input ) as $char ) {
			$pos = strpos( $alphabet, $char );
			if ( $pos === false ) {
				continue;
			}
			$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}
		$output = '';
		foreach ( str_split( $bits, 8 ) as $chunk ) {
			if ( strlen( $chunk ) === 8 ) {
				$output .= chr( (int) bindec( $chunk ) );
			}
		}
		return $output;
	}
}