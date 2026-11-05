<?php
/**
 * Post types and taxonomies.
 *
 * @package Feriae
 */

namespace Feriae\Content;

use Feriae\Installer;
use Feriae\Settings;

/**
 * Registers the optional Events post type, venues, organizers, and event
 * categories, and attaches event support to existing post types chosen in setup.
 */
final class PostTypes {

	public const EVENT     = Settings::EVENT_TYPE;
	public const VENUE     = 'feriae_venue';
	public const ORGANIZER = 'feriae_organizer';
	public const CATEGORY  = 'feriae_event_category';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'register_types' ), 5 );
		// Late, so post types registered by themes and other plugins exist.
		add_action( 'init', array( $this, 'attach_to_existing_types' ), 50 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'flush_on_change' ), 10, 2 );
	}

	/**
	 * Admin menu that venues, organizers, and settings live under: the Events post
	 * type menu when it is registered, otherwise the plugin's own top-level page.
	 */
	public static function menu_parent( Settings $settings ): string {
		return $settings->registers_event_type() ? 'edit.php?post_type=' . self::EVENT : 'feriae';
	}

	public function register_types(): void {
		$slug   = $this->settings->events_slug();
		$parent = self::menu_parent( $this->settings );

		if ( $this->settings->registers_event_type() ) {
			$this->register_event_type( $slug );
		}

		register_post_type(
			self::VENUE,
			array(
				'labels'       => array(
					'name'          => __( 'Venues', 'feriae' ),
					'singular_name' => __( 'Venue', 'feriae' ),
					'add_new_item'  => __( 'Add New Venue', 'feriae' ),
					'edit_item'     => __( 'Edit Venue', 'feriae' ),
					'all_items'     => __( 'Venues', 'feriae' ),
					'not_found'     => __( 'No venues found.', 'feriae' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'venues',
				'show_in_menu' => $parent,
				'rewrite'      => array(
					'slug'       => 'venue',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			)
		);

		register_post_type(
			self::ORGANIZER,
			array(
				'labels'       => array(
					'name'          => __( 'Organizers', 'feriae' ),
					'singular_name' => __( 'Organizer', 'feriae' ),
					'add_new_item'  => __( 'Add New Organizer', 'feriae' ),
					'edit_item'     => __( 'Edit Organizer', 'feriae' ),
					'all_items'     => __( 'Organizers', 'feriae' ),
					'not_found'     => __( 'No organizers found.', 'feriae' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'organizers',
				'show_in_menu' => $parent,
				'rewrite'      => array(
					'slug'       => 'organizer',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			)
		);

		register_taxonomy(
			self::CATEGORY,
			$this->settings->registers_event_type() ? array( self::EVENT ) : array(),
			array(
				'labels'            => array(
					'name'          => __( 'Event Categories', 'feriae' ),
					'singular_name' => __( 'Event Category', 'feriae' ),
					'menu_name'     => __( 'Event Categories', 'feriae' ),
				),
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'rest_base'         => 'event-categories',
				'show_admin_column' => true,
				'rewrite'           => array(
					'slug'         => $slug . '/category',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}

	private function register_event_type( string $slug ): void {
		register_post_type(
			self::EVENT,
			array(
				'labels'        => array(
					'name'                  => __( 'Events', 'feriae' ),
					'singular_name'         => __( 'Event', 'feriae' ),
					'add_new_item'          => __( 'Add New Event', 'feriae' ),
					'edit_item'             => __( 'Edit Event', 'feriae' ),
					'new_item'              => __( 'New Event', 'feriae' ),
					'view_item'             => __( 'View Event', 'feriae' ),
					'view_items'            => __( 'View Events', 'feriae' ),
					'search_items'          => __( 'Search Events', 'feriae' ),
					'not_found'             => __( 'No events found.', 'feriae' ),
					'not_found_in_trash'    => __( 'No events found in Trash.', 'feriae' ),
					'all_items'             => __( 'All Events', 'feriae' ),
					'archives'              => __( 'Event Archives', 'feriae' ),
					'item_published'        => __( 'Event published.', 'feriae' ),
					'item_updated'          => __( 'Event updated.', 'feriae' ),
					'item_scheduled'        => __( 'Event scheduled.', 'feriae' ),
					'menu_name'             => __( 'Events', 'feriae' ),
					'filter_items_list'     => __( 'Filter events list', 'feriae' ),
					'items_list_navigation' => __( 'Events list navigation', 'feriae' ),
					'items_list'            => __( 'Events list', 'feriae' ),
				),
				'public'        => true,
				'show_in_rest'  => true,
				'rest_base'     => 'events',
				'menu_icon'     => 'dashicons-calendar-alt',
				'menu_position' => 6,
				'has_archive'   => $slug,
				'rewrite'       => array(
					'slug'       => $this->settings->single_slug(),
					'with_front' => false,
				),
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
				'taxonomies'    => array( 'post_tag' ),
			)
		);
	}

	/**
	 * Gives existing event-enabled post types the category taxonomy and the
	 * custom-fields support that REST meta requires. Nothing else about those
	 * post types changes.
	 */
	public function attach_to_existing_types(): void {
		foreach ( $this->settings->event_post_types() as $post_type ) {
			if ( self::EVENT === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}
			register_taxonomy_for_object_type( self::CATEGORY, $post_type );
			add_post_type_support( $post_type, 'custom-fields' );
		}
	}

	/**
	 * Schedules a rewrite flush (never flushes inline) when settings that affect
	 * permalinks change.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public function flush_on_change( mixed $old_value, mixed $value ): void {
		foreach ( array( 'events_slug', 'single_slug', 'register_event_type' ) as $key ) {
			if ( ( $old_value[ $key ] ?? null ) !== ( $value[ $key ] ?? null ) ) {
				update_option( Installer::FLUSH_OPTION, 1 );
				return;
			}
		}
	}
}
