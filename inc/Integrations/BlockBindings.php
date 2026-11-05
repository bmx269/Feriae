<?php
/**
 * Block Bindings source.
 *
 * @package Feriae
 */

namespace Feriae\Integrations;

use Feriae\Content\Event;
use Feriae\Frontend\Formatter;

/**
 * "feriae/event" binding source: lets core blocks (Paragraph,
 * Heading, Button, Image) show event fields, so themes can design event
 * templates from core blocks instead of fixed plugin markup.
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{
 *        "source":"feriae/event","args":{"key":"date"}}}}} -->
 */
final class BlockBindings {

	public const SOURCE = 'feriae/event';

	/**
	 * Bindable keys and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array(
			'date'          => __( 'Event date and time', 'feriae' ),
			'start_date'    => __( 'Start date', 'feriae' ),
			'start_time'    => __( 'Start time', 'feriae' ),
			'end_date'      => __( 'End date', 'feriae' ),
			'end_time'      => __( 'End time', 'feriae' ),
			'venue'         => __( 'Venue name', 'feriae' ),
			'venue_address' => __( 'Venue address', 'feriae' ),
			'venue_url'     => __( 'Venue link', 'feriae' ),
			'cost'          => __( 'Cost', 'feriae' ),
			'website'       => __( 'Event website', 'feriae' ),
		);
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_source' ) );
	}

	public function register_source(): void {
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Event', 'feriae' ),
				'get_value_callback' => array( $this, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $args  Binding args; "key" selects the field.
	 * @param \WP_Block            $block Block instance.
	 */
	public function get_value( array $args, \WP_Block $block ): ?string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$event   = $post_id ? feriae_get_event( $post_id ) : null;
		if ( ! $event ) {
			return null;
		}
		$occurrence = feriae()->occurrences()->requested( $post_id );
		$venue      = $event->venue();

		$value = match ( (string) ( $args['key'] ?? '' ) ) {
			'date'          => $occurrence ? Formatter::range( $occurrence ) : '',
			'start_date'    => $occurrence ? Formatter::date( $occurrence->start ) : '',
			'start_time'    => $occurrence && ! $occurrence->all_day ? Formatter::time( $occurrence->start ) : '',
			'end_date'      => $occurrence ? Formatter::date( $occurrence->end ) : '',
			'end_time'      => $occurrence && ! $occurrence->all_day ? Formatter::time( $occurrence->end ) : '',
			'venue'         => $venue ? get_the_title( $venue ) : '',
			'venue_address' => $venue ? Event::venue_address( $venue ) : '',
			'venue_url'     => $venue ? (string) get_permalink( $venue ) : '',
			'cost'          => $event->cost(),
			'website'       => $event->url(),
			default         => null,
		};

		/**
		 * Filters a bound event value. Add-ons can supply values for their own keys.
		 *
		 * @param string|null          $value Value, or null for unknown keys.
		 * @param array<string, mixed> $args  Binding args.
		 * @param Event                $event Event.
		 */
		$value = apply_filters( 'feriae/block_bindings/value', $value, $args, $event );

		if ( null === $value ) {
			return null;
		}
		// URLs land in attributes, which the HTML API escapes; text lands in rich-text content.
		return in_array( $args['key'] ?? '', array( 'venue_url', 'website' ), true ) ? esc_url_raw( $value ) : esc_html( $value );
	}
}
