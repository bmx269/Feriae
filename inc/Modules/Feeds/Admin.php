<?php
/**
 * Events → Calendar import screen.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Feeds;

use OpenEvents\Content\PostTypes;

/**
 * The calendar list and the add and import forms, rendered by the attendance
 * admin bundle (which carries DataViews) from the feeds REST routes.
 */
final class Admin {

	public const SLUG = 'openevents-feeds';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 23 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function menu(): void {
		add_submenu_page(
			PostTypes::menu_parent( openevents()->settings() ),
			__( 'Calendar import', 'openevents' ),
			__( 'Calendar import', 'openevents' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			\OpenEvents\Modules\Rsvp\Admin::enqueue_bundle();
		}
	}

	public function render(): void {
		$categories = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => false,
			)
		);
		$types      = array();
		foreach ( openevents_event_post_types() as $type ) {
			$object  = get_post_type_object( $type );
			$types[] = array(
				'value' => $type,
				'label' => $object ? $object->labels->singular_name : $type,
			);
		}
		printf(
			'<div id="openevents-attendance" class="openevents-admin" data-screen="feeds" data-categories="%s" data-post-types="%s" data-default-type="%s" data-max-file="%d"><noscript>%s</noscript></div>',
			esc_attr(
				(string) wp_json_encode(
					is_array( $categories ) ? array_map(
						static fn( \WP_Term $term ) => array(
							'value' => $term->term_id,
							'label' => $term->name,
						),
						$categories
					) : array()
				)
			),
			esc_attr( (string) wp_json_encode( $types ) ),
			esc_attr( Feed::default_post_type() ),
			(int) Rest::MAX_FILE,
			esc_html__( 'Calendar import needs JavaScript. You can also use WP-CLI: wp openevents import-ics and wp openevents sync-feeds.', 'openevents' )
		);
	}
}
