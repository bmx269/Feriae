<?php
/**
 * Module base class.
 *
 * @package Feriae
 */

namespace Feriae\Modules;

/**
 * Sensible defaults for optional Module methods.
 */
abstract class AbstractModule implements Module {

	public function requires(): array {
		return array();
	}

	public function install(): void {}

	public function version(): string {
		return '1';
	}
}
