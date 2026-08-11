<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Performance;

use Vlad\SBS\Core\SettingsManager;

final class AssetManager {
	public function inject_custom_assets(): void {
		$custom_css = SettingsManager::get( 'performance', 'custom_css', '' );
		if ( ! empty( $custom_css ) ) {
			wp_register_style( 'sbs-custom-css', false );
			wp_enqueue_style( 'sbs-custom-css' );
			wp_add_inline_style( 'sbs-custom-css', wp_strip_all_tags( $custom_css ) );
		}

		$custom_js = SettingsManager::get( 'performance', 'custom_js', '' );
		if ( ! empty( $custom_js ) ) {
			wp_register_script( 'sbs-custom-js', '', [], false, true );
			wp_enqueue_script( 'sbs-custom-js' );
			wp_add_inline_script( 'sbs-custom-js', wp_strip_all_tags( $custom_js ) );
		}
	}

	public function apply_dequeue_rules(): void {
		// Задел для будущего расширения UI (правила отключения плагинов на конкретных страницах)
		$rules = SettingsManager::get( 'performance', 'dequeue_rules', [] );
		
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return;
		}

		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

		foreach ( $rules as $rule ) {
			if ( empty( $rule['url_pattern'] ) || empty( $rule['handle'] ) || empty( $rule['type'] ) ) {
				continue;
			}

			// Простейший wildcard match (например, *contact*)
			$pattern = str_replace( '\*', '.*', preg_quote( $rule['url_pattern'], '/' ) );
			if ( preg_match( '/^' . $pattern . '$/i', $current_url ) ) {
				if ( $rule['type'] === 'script' ) {
					wp_dequeue_script( $rule['handle'] );
				} elseif ( $rule['type'] === 'style' ) {
					wp_dequeue_style( $rule['handle'] );
				}
			}
		}
	}
}