<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Performance;

use Vlad\SBS\Core\ModuleInterface;
use Vlad\SBS\Core\SettingsManager;

final class PerformanceModule implements ModuleInterface {
	public function get_id(): string {
		return 'performance';
	}

	public function get_name(): string {
		return __( 'Setup & Performance', 'sbs' );
	}

	public function boot(): void {
		if ( SettingsManager::get( 'performance', 'clean_head', false ) ) {
			$this->clean_wp_head();
		}

		$asset_manager = new AssetManager();
		add_action( 'wp_enqueue_scripts', [ $asset_manager, 'inject_custom_assets' ], 100 );
		add_action( 'wp_enqueue_scripts', [ $asset_manager, 'apply_dequeue_rules' ], 9999 );

		$image_converter = new ImageConverter();
		add_filter( 'image_editor_output_format', [ $image_converter, 'set_image_format' ] );
		add_filter( 'intermediate_image_sizes_advanced', [ $image_converter, 'disable_image_sizes' ] );

		// Внедрение Lazy Load
		$lazy_load = new LazyLoadEngine();
		add_filter( 'the_content', [ $lazy_load, 'apply_lazy_load' ] );
		add_filter( 'post_thumbnail_html', [ $lazy_load, 'apply_lazy_load' ] );

		if ( ! is_admin() ) {
			$cache_engine = new CacheEngine();
			add_action( 'template_redirect', [ $cache_engine, 'start_buffer' ], 0 );
			add_action( 'template_redirect', [ $this, 'start_html_processing_buffer' ], 1 );
		}
	}

	public function start_html_processing_buffer(): void {
		$minify = SettingsManager::get( 'performance', 'minify_html', false );
		$format = SettingsManager::get( 'performance', 'image_format', 'original' );

		if ( $minify || $format !== 'original' ) {
			ob_start( function ( string $html ) use ( $minify, $format ): string {
				if ( $format !== 'original' ) {
					$html = ImageConverter::rewrite_html( $html );
				}
				if ( $minify ) {
					$html = HTMLMinifier::minify( $html );
				}
				return $html;
			} );
		}
	}

	public function register_admin_ui(): void {
		add_action( 'wp_ajax_sbs_scan_orphan_media', [ MediaCleaner::class, 'ajax_scan_orphans' ] );
		add_action( 'wp_ajax_sbs_delete_orphan_media', [ MediaCleaner::class, 'ajax_delete_orphans' ] );
		add_action( 'wp_ajax_sbs_toggle_page_cache', [ CacheEngine::class, 'ajax_toggle_dropin' ] );
		add_action( 'wp_ajax_sbs_purge_page_cache', [ CacheEngine::class, 'ajax_purge_cache' ] );
	}

	public function apply_soft_lock_ui(): void {
		$locked_callback = function (): void {
			wp_send_json_error( [ 'message' => __( 'Action locked in Free mode.', 'sbs' ) ], 403 );
		};
		add_action( 'wp_ajax_sbs_delete_orphan_media', $locked_callback );
		add_action( 'wp_ajax_sbs_toggle_page_cache', $locked_callback );
		
		// Фикс бага: теперь очистка кэша блокируется корректно
		add_action( 'wp_ajax_sbs_purge_page_cache', $locked_callback );
	}

	private function clean_wp_head(): void {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'start_post_rel_link' );
		remove_action( 'wp_head', 'index_rel_link' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
	}

	public function uninstall(): void {
		CacheEngine::remove_dropin();
		CacheEngine::purge_all();
	}
}