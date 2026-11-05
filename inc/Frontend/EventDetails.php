<?php
/**
 * Event details markup.
 *
 * @package Feriae
 */

namespace Feriae\Frontend;

use Feriae\Content\Event;
use Feriae\Occurrences\Occurrence;

/**
 * Renders the details panel (date, venue, organizers, cost, links) shared by the
 * event-details block and the classic-theme content filter.
 */
final class EventDetails {

	/** @var array<int, true> Posts whose details were already output this request. */
	private static array $rendered = array();

	public static function was_rendered( int $post_id ): bool {
		return isset( self::$rendered[ $post_id ] );
	}

	/**
	 * @param array<string, mixed> $options show_* toggles from block attributes.
	 */
	public static function render( int $post_id, array $options = array() ): string {
		$event = feriae_get_event( $post_id );
		if ( ! $event ) {
			return '';
		}
		$occurrence = feriae()->occurrences()->requested( $post_id );
		if ( ! $occurrence ) {
			return '';
		}

		$options = wp_parse_args(
			$options,
			array(
				'showVenue'      => true,
				'showOrganizers' => true,
				'showCost'       => true,
				'showCategories' => true,
				'showLinks'      => true,
			)
		);

		self::$rendered[ $post_id ] = true;

		$rows = array();

		// Called off: first, so nobody misses it.
		if ( $occurrence->is_called_off() ) {
			$reason         = \Feriae\Content\EventStatus::reason( $occurrence );
			$rows['status'] = array(
				'label' => __( 'Status', 'feriae' ),
				'html'  => \Feriae\Content\EventStatus::badge( $occurrence, 'feriae-status' ) . ( '' !== $reason ? ' ' . esc_html( $reason ) : '' ),
			);
		}

		$rows['date'] = array(
			'label' => __( 'When', 'feriae' ),
			'html'  => sprintf(
				'<time datetime="%s">%s</time>',
				esc_attr( Formatter::iso( $occurrence->start, $occurrence->all_day ) ),
				esc_html( Formatter::range( $occurrence ) )
			) . ( $occurrence->has_ended() ? ' <span class="feriae-details__badge">' . esc_html__( 'This event has passed.', 'feriae' ) . '</span>' : '' ),
		);

		$venue = $options['showVenue'] ? $event->venue() : null;
		if ( $venue ) {
			$address = Event::venue_address( $venue );
			$html    = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $venue ) ), esc_html( get_the_title( $venue ) ) );
			if ( $address ) {
				$html .= sprintf(
					'<br><span class="feriae-details__address">%s</span> <a class="feriae-details__map" href="%s" rel="noopener" target="_blank">%s<span class="feriae-visually-hidden"> %s</span></a>',
					esc_html( $address ),
					esc_url( 'https://www.openstreetmap.org/search?query=' . rawurlencode( $address ) ),
					esc_html__( 'Map', 'feriae' ),
					esc_html__( '(opens in a new tab)', 'feriae' )
				);
			}
			$rows['venue'] = array(
				'label' => __( 'Where', 'feriae' ),
				'html'  => $html,
			);
		}

		$organizers = $options['showOrganizers'] ? $event->organizers() : array();
		if ( $organizers ) {
			$rows['organizers'] = array(
				'label' => _n( 'Organizer', 'Organizers', count( $organizers ), 'feriae' ),
				'html'  => implode(
					', ',
					array_map(
						static fn( \WP_Post $o ) => sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $o ) ), esc_html( get_the_title( $o ) ) ),
						$organizers
					)
				),
			);
		}

		$cost = $options['showCost'] ? $event->cost() : '';
		if ( '' !== $cost ) {
			$rows['cost'] = array(
				'label' => __( 'Cost', 'feriae' ),
				'html'  => esc_html( $cost ),
			);
		}

		$terms = get_the_terms( $event->post, \Feriae\Content\PostTypes::CATEGORY );
		if ( $options['showCategories'] && is_array( $terms ) && $terms ) {
			$badges = '';
			foreach ( $terms as $term ) {
				$link    = get_term_link( $term );
				$style   = CategoryColors::style_for_term( $term->term_id );
				$badges .= sprintf(
					'<li><a class="feriae-badge%s" href="%s"%s>%s</a></li>',
					$style ? ' has-color' : '',
					esc_url( is_wp_error( $link ) ? '' : $link ),
					$style ? ' style="' . esc_attr( $style ) . '"' : '',
					esc_html( $term->name )
				);
			}
			$rows['categories'] = array(
				'label' => _n( 'Category', 'Categories', count( $terms ), 'feriae' ),
				'html'  => '<ul class="feriae-details__categories">' . $badges . '</ul>',
			);
		}

		$rows += \Feriae\Content\Attributes::detail_rows( $post_id );
		foreach ( (array) $event->meta( '_feriae_details' ) as $i => $detail ) {
			if ( is_array( $detail ) && '' !== (string) ( $detail['label'] ?? '' ) && '' !== (string) ( $detail['value'] ?? '' ) ) {
				$rows[ 'detail-' . $i ] = array(
					'label' => (string) $detail['label'],
					'html'  => make_clickable( esc_html( (string) $detail['value'] ) ),
				);
			}
		}

		if ( $event->url() ) {
			$rows['website'] = array(
				'label' => __( 'Website', 'feriae' ),
				'html'  => sprintf( '<a href="%1$s">%2$s</a>', esc_url( $event->url() ), esc_html( wp_parse_url( $event->url(), PHP_URL_HOST ) ?: $event->url() ) ),
			);
		}

		/**
		 * Filters the detail rows. Each row is ['label' => string, 'html' => escaped HTML].
		 *
		 * @param array      $rows       Rows keyed by id.
		 * @param Event      $event      Event.
		 * @param Occurrence $occurrence Occurrence shown.
		 */
		$rows = apply_filters( 'feriae/event_details/rows', $rows, $event, $occurrence );

		$out = '<dl class="feriae-details__list">';
		foreach ( $rows as $id => $row ) {
			$out .= sprintf(
				'<div class="feriae-details__row feriae-details__row--%s"><dt>%s</dt><dd>%s</dd></div>',
				esc_attr( (string) $id ),
				esc_html( $row['label'] ),
				wp_kses_post( $row['html'] )
			);
		}
		$out .= '</dl>';

		if ( $options['showLinks'] ) {
			/**
			 * Filters "add to calendar"-style links. Each link is ['label' => string, 'url' => string].
			 *
			 * @param array      $links      Links.
			 * @param Occurrence $occurrence Occurrence.
			 */
			$links = apply_filters( 'feriae/event_details/links', array(), $occurrence );
			if ( $links ) {
				$out .= '<ul class="feriae-details__links">';
				foreach ( $links as $link ) {
					$out .= sprintf( '<li><a href="%s" rel="nofollow">%s</a></li>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
				}
				$out .= '</ul>';
			}
		}

		/**
		 * Filters extra sections appended to the details panel (RSVP form, tickets).
		 * Each section is safe HTML.
		 *
		 * @param string[]   $sections   Sections keyed by id.
		 * @param Event      $event      Event.
		 * @param Occurrence $occurrence Occurrence.
		 */
		$sections = apply_filters( 'feriae/event_details/sections', array(), $event, $occurrence );

		return $out . implode( '', $sections );
	}
}
