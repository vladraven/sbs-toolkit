<?php

declare(strict_types=1);

namespace Vlad\SBS\Modules\DevOps;

use Vlad\SBS\Core\ModuleInterface;

final class DevOpsModule implements ModuleInterface {
    public function get_id(): string {
        return 'devops';
    }

    public function get_name(): string {
        return __('DevOps & Remote Control', 'sbs');
    }

    public function boot(): void {
        add_action('rest_api_init', [RemoteAPI::class, 'register_routes']);

        $white_label = new WhiteLabel();
        $white_label->boot();

        $uptime = new UptimeMonitor();
        $uptime->boot();
    }

    public function register_admin_ui(): void {
        add_action('wp_ajax_sbs_generate_remote_token', [RemoteAPI::class, 'ajax_generate_token']);
    }

    public function apply_soft_lock_ui(): void {
        add_action('wp_ajax_sbs_generate_remote_token', function(): void {
            wp_send_json_error(['message' => __('Action locked in Free mode.', 'sbs')], 403);
        });
    }

    public function uninstall(): void {
        delete_option('sbs_remote_access_token');
    }
}