<?php
/**
 * Block registration.
 *
 * @package Feriae
 */

namespace Feriae\Frontend;

use const Feriae\PLUGIN_DIR;

/**
 * Registers the plugin blocks from the build manifest, and keeps calendar
 * navigation URLs out of search indexes.
 */
final class Blocks {

	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		CategoryColors::register();
	}

	/**
	 * Groups the plugin's blocks under "Feriae" in the inserter.
	 *
	 * @param array<int, array<string, string>> $categories Block categories.
	 * @return array<int, array<string, string>>
	 */
	public function category( array $categories ): array {
		$categories[] = array(
			'slug'  => 'feriae',
			'title' => __( 'Feriae', 'feriae' ),
			'icon'  => null,
		);
		return $categories;
	}

	public function register_blocks(): void {
		$manifest = PLUGIN_DIR . '/build/blocks-manifest.php';
		if ( is_readable( $manifest ) ) {
			wp_register_block_types_from_metadata_collection( PLUGIN_DIR . '/build/blocks', $manifest );
		}
	}

	/**
	 * Calendar navigation pages (?feriae_month=, ?feriae_view=, ...) are views of the same
	 * content: noindex them, but let crawlers follow through to events.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		foreach ( CalendarView::params() as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$robots['noindex'] = true;
				$robots['follow']  = true;
				break;
			}
		}
		return $robots;
	}
}
