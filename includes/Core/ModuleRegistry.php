<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class ModuleRegistry {
	private array $modules = [];
	private LicenseManager $license;

	public function __construct( LicenseManager $license ) {
		$this->license = $license;
	}

	public function register( ModuleInterface $module ): void {
		$this->modules[ $module->get_id() ] = $module;
	}

	public function boot_all(): void {
		$active_free_module = get_option( 'sbs_active_free_module', 'performance' );
		$is_pro_or_trial    = $this->license->is_pro_or_trial();

		foreach ( $this->modules as $id => $module ) {
			$module->boot();

			if ( is_admin() ) {
				if ( $is_pro_or_trial || $active_free_module === $id ) {
					$module->register_admin_ui();
				} else {
					$module->apply_soft_lock_ui();
				}
			}
		}
	}
}