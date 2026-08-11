<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\DevOps;

use Vlad\SBS\Core\SettingsManager;

final class WhiteLabel {
    public function boot(): void {
        add_filter('all_plugins', [$this, 'modify_plugin_data']);
        add_action('admin_menu', [$this, 'modify_menu_title'], 999);
    }

    public function modify_plugin_data(array $plugins): array {
        $wl_name = SettingsManager::get('devops', 'wl_plugin_name', '');
        $wl_author = SettingsManager::get('devops', 'wl_author_name', '');
        
        $plugin_basename = plugin_basename(SBS_PLUGIN_DIR . 'sbs-toolkit.php');

        if (isset($plugins[$plugin_basename])) {
            if (!empty($wl_name)) {
                $plugins[$plugin_basename]['Name'] = sanitize_text_field($wl_name);
                $plugins[$plugin_basename]['Title'] = sanitize_text_field($wl_name);
            }
            if (!empty($wl_author)) {
                $plugins[$plugin_basename]['Author'] = sanitize_text_field($wl_author);
                $plugins[$plugin_basename]['AuthorName'] = sanitize_text_field($wl_author);
            }
        }

        return $plugins;
    }

    public function modify_menu_title(): void {
        global $menu;
        
        $wl_name = SettingsManager::get('devops', 'wl_plugin_name', '');
        if (empty($wl_name) || empty($menu)) {
            return;
        }

        foreach ($menu as $key => $item) {
            if (isset($item[2]) && $item[2] === 'sbs-toolkit') {
                $menu[$key][0] = sanitize_text_field($wl_name);
                break;
            }
        }
    }
}