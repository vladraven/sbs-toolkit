<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\Performance;

final class LazyLoadEngine {
	public function apply_lazy_load( string $content ): string {
		if ( is_feed() || is_preview() ) {
			return $content;
		}

		// Не трогаем изображения, у которых уже есть fetchpriority="high" (чтобы не ломать LCP)
		$content = preg_replace_callback( '/<img[^>]+>/i', function ( $matches ) {
			$img = $matches[0];
			if ( strpos( $img, 'fetchpriority="high"' ) !== false || strpos( $img, 'loading=' ) !== false ) {
				return $img;
			}
			return str_replace( '<img', '<img loading="lazy"', $img );
		}, $content );

		$content = preg_replace_callback( '/<iframe[^>]+>/i', function ( $matches ) {
			$iframe = $matches[0];
			if ( strpos( $iframe, 'loading=' ) !== false ) {
				return $iframe;
			}
			return str_replace( '<iframe', '<iframe loading="lazy"', $iframe );
		}, $content );

		return $content;
	}
}