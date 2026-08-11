<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\DevOps;

use Vlad\SBS\Core\SettingsManager;
use Vlad\SBS\Core\Logger;

final class UptimeMonitor {
    public function boot(): void {
        add_action('shutdown', [$this, 'log_ttfb']);
    }

    public function log_ttfb(): void {
        $threshold = (float) SettingsManager::get('devops', 'ttfb_threshold', 1.5);
        
        if ($threshold <= 0) {
            return;
        }

        if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return;
        }

        $ttfb = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];

        if ($ttfb >= $threshold) {
            $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
            Logger::log('devops', 'warning', 'Slow Response Detected (TTFB)', [
                'url'  => sanitize_text_field($uri),
                'ttfb' => round($ttfb, 4) . 's'
            ]);
        }
    }
}