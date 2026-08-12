<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

final class ModuleRegistry {
	/** @var array<string, ModuleInterface> */
	private array $modules = [];
	private LicenseManager $license;

	public function __construct( LicenseManager $license ) {
		$this->license = $license;
	}

	public function register( ModuleInterface $module ): void {
		$this->modules[ $module->get_id() ] = $module;
	}

	public function boot_all(): void {
		$active_free_module = (string) get_option( 'sbs_active_free_module', 'performance' );
		$is_pro_or_trial    = $this->license->is_pro_or_trial();

		foreach ( $this->modules as $id => $module ) {
			$is_active = $is_pro_or_trial || $active_free_module === $id;

			if ( $is_active ) {
				// Full runtime + full admin for Pro/Trial or the single Free active module.
				$module->boot();
				if ( is_admin() ) {
					$module->register_admin_ui();
				}
				continue;
			}

			// Soft-Lock: keep safe runtime effects (via module's own settings checks),
			// but admin mutations are locked. Dangerous modules must no-op when disabled.
			$module->boot();
			if ( is_admin() ) {
				$module->apply_soft_lock_ui();
			}
		}
	}

	/** @return array<string, ModuleInterface> */
	public function all(): array {
		return $this->modules;
	}
}