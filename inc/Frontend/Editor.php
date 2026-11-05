<?php
/**
 * Block editor sidebar.
 *
 * @package Feriae
 */

namespace Feriae\Frontend;

use Feriae\Content\PostTypes;

use const Feriae\PLUGIN_DIR;
use const Feriae\PLUGIN_FILE;

/**
 * Loads the sidebar panels for events, venues, and organizers.
 */
final class Editor {

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		$this->enqueue_bindings();

		$screen = get_current_screen();
		if ( ! $screen || ! $screen->post_type ) {
			return;
		}
		$settings    = feriae()->settings();
		$event_types = $settings->event_post_types();
		if ( ! in_array( $screen->post_type, array_merge( $event_types, array( PostTypes::VENUE, PostTypes::ORGANIZER, \Feriae\Modules\Series\Module::POST_TYPE ) ), true ) ) {
			return;
		}

		$asset_file = PLUGIN_DIR . '/build/editor/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'feriae-editor', plugins_url( 'build/editor/index.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'feriae-editor', 'feriae' );
		if ( is_readable( PLUGIN_DIR . '/build/editor/index.css' ) ) {
			wp_enqueue_style( 'feriae-editor', plugins_url( 'build/editor/index.css', PLUGIN_FILE ), array( 'wp-components' ), $asset['version'] );
		}

		wp_add_inline_script(
			'feriae-editor',
			'window.feriae = ' . wp_json_encode(
				array(
					'eventPostTypes'  => $event_types,
					// Posts in the dedicated type are always events; existing types opt in per post.
					'dedicatedType'   => $settings->registers_event_type() ? PostTypes::EVENT : null,
					'siteTimezone'    => wp_timezone_string(),
					'timezones'       => timezone_identifiers_list(),
					'timeFormat12h'   => (bool) preg_match( '/[aAgh]/', (string) get_option( 'time_format' ) ),
					'modules'         => feriae()->modules()->enabled_ids(),
					'attendeesUrl'    => admin_url( 'admin.php?page=feriae-attendees' ),
					'ordersUrl'       => admin_url( 'admin.php?page=feriae-orders' ),
					'currency'        => strtoupper( (string) ( $settings->get( 'currency' ) ?: 'USD' ) ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The bindings source is needed in every block editor (posts, pages, and the
	 * Site Editor), not only on event screens.
	 */
	private function enqueue_bindings(): void {
		$asset_file = PLUGIN_DIR . '/build/editor/bindings.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = require $asset_file;
			wp_enqueue_script( 'feriae-bindings', plugins_url( 'build/editor/bindings.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'feriae-bindings', 'feriae' );
		}
	}
}
