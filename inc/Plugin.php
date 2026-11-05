<?php
/**
 * Service wiring.
 *
 * @package Feriae
 */

namespace Feriae;

use Feriae\Admin\AdminColumns;
use Feriae\Admin\AdminApp;
use Feriae\Content\Meta;
use Feriae\Content\PostTypes;
use Feriae\Frontend\Blocks;
use Feriae\Frontend\Editor;
use Feriae\Frontend\Templates;
use Feriae\Integrations\Abilities;
use Feriae\Integrations\BlockBindings;
use Feriae\Modules\Registry;
use Feriae\Occurrences\Repository;
use Feriae\Occurrences\Sync;
use Feriae\Rest\OccurrencesController;

/**
 * Holds the shared service instances and registers their hooks.
 *
 * Add-ons reach services through feriae(), e.g.
 * feriae()->occurrences()->query( [ ... ] ).
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private Repository $occurrences;
	private Registry $modules;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function boot(): void {
		$plugin = self::instance();
		$plugin->register();

		/**
		 * Fires once core services are registered and enabled modules have booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'feriae/loaded', $plugin );
	}

	private function __construct() {
		$this->settings    = new Settings();
		$this->occurrences = new Repository();
		$this->modules     = new Registry( $this->settings );
	}

	private function register(): void {
		Installer::register();
		( new PostTypes( $this->settings ) )->register();
		( new Meta( $this->settings ) )->register();
		\Feriae\Content\Attributes::register();
		( new Sync() )->register();
		\Feriae\Attendance\Cleanup::register();
		\Feriae\Attendance\Privacy::register();
		\Feriae\Attendance\Pass::register();
		\Feriae\Attendance\CheckIn::register();
		$rest = new OccurrencesController( $this->occurrences );
		$rest->register();
		( new Abilities( $rest ) )->register();
		( new BlockBindings() )->register();
		( new AdminApp( $this->settings, $this->modules ) )->register();
		( new Blocks() )->register();
		( new Editor() )->register();
		( new Templates() )->register();

		if ( is_admin() ) {
			( new AdminColumns() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'feriae', Cli\Command::class );
		}

		$this->modules->boot();
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function occurrences(): Repository {
		return $this->occurrences;
	}

	public function modules(): Registry {
		return $this->modules;
	}
}
