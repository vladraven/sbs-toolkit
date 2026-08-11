<?php

declare(strict_types=1);

namespace Vlad\SBS\Core;

interface ModuleInterface {
	public function get_id(): string;

	public function get_name(): string;

	public function boot(): void;

	public function register_admin_ui(): void;

	public function apply_soft_lock_ui(): void;

	public function uninstall(): void;
}