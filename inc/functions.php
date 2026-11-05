<?php
/**
 * Public PHP API for themes and add-ons.
 *
 * These functions are a stable contract: their signatures will not change in
 * minor releases. Prefer them to instantiating internal classes.
 *
 * @package Feriae
 */

use Feriae\Content\Event;
use Feriae\Occurrences\Occurrence;
use Feriae\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin instance: settings(), occurrences() repository, modules() registry.
 */
function feriae(): Plugin {
	return Plugin::instance();
}

/**
 * Post types that can hold event data.
 *
 * @return string[]
 */
function feriae_event_post_types(): array {
	return feriae()->settings()->event_post_types();
}

/**
 * Post types that can sell tickets or take RSVPs: event types, plus series
 * (for series passes) when the series module is on.
 *
 * @return string[]
 */
function feriae_ticketed_post_types(): array {
	$types = feriae_event_post_types();
	if ( feriae()->modules()->is_enabled( 'series' ) ) {
		$types[] = Feriae\Modules\Series\Module::POST_TYPE;
	}
	return $types;
}

/**
 * Event wrapper for a post, or null when the post's type is not event-enabled
 * or the post has no start date.
 */
function feriae_get_event( int|WP_Post|null $post = null ): ?Event {
	$event = Event::get( $post );
	return ( $event && $event->is_scheduled() ) ? $event : null;
}

function feriae_is_event( int|WP_Post|null $post = null ): bool {
	return null !== feriae_get_event( $post );
}

/**
 * Queries occurrences. See Feriae\Occurrences\Repository::defaults() for arguments.
 *
 * @param array<string, mixed> $args Query arguments.
 * @return array{items: Occurrence[], total: int, pages: int}
 */
function feriae_get_occurrences( array $args = array() ): array {
	return feriae()->occurrences()->query( $args );
}

/**
 * Upcoming occurrences, soonest first.
 *
 * @return Occurrence[]
 */
function feriae_get_upcoming( int $limit = 10, array $args = array() ): array {
	$args = array_merge(
		array(
			'ends_after' => Feriae\Occurrences\Repository::now(),
			'per_page'   => $limit,
			'count'      => false,
		),
		$args
	);
	return feriae_get_occurrences( $args )['items'];
}

/**
 * Human-readable date range for an occurrence, e.g. "November 3, 2026, 7:00 pm – 9:00 pm".
 */
function feriae_format_range( Occurrence $occurrence ): string {
	return Feriae\Frontend\Formatter::range( $occurrence );
}

/**
 * Link to one occurrence. Later dates of a repeating event add the date
 * (/event/{slug}/{Y-m-d}/, or ?feriae_occurrence= without pretty permalinks) so
 * the event page shows the date that was clicked; the first date uses the
 * plain permalink, which stays the canonical URL.
 */
function feriae_occurrence_url( Occurrence $occurrence ): string {
	$url = (string) get_permalink( $occurrence->event_id );
	if ( 0 === $occurrence->sequence ) {
		return $url;
	}
	$date = $occurrence->start->format( 'Y-m-d' );
	// Pretty permalinks: /event/{slug}/{date}/, as TEC links them (see the recurrence module's rewrite).
	if ( get_option( 'permalink_structure' ) && Feriae\Content\PostTypes::EVENT === get_post_type( $occurrence->event_id ) && feriae_is_module_enabled( 'recurrence' ) ) {
		return trailingslashit( $url ) . $date . '/';
	}
	return add_query_arg( 'feriae_occurrence', $date, $url );
}

function feriae_is_module_enabled( string $id ): bool {
	return feriae()->modules()->is_enabled( $id );
}

/**
 * URL of the main calendar: the Events archive when the post type is registered,
 * otherwise the calendar page chosen in setup.
 */
function feriae_calendar_url(): string {
	$settings = feriae()->settings();
	if ( $settings->registers_event_type() ) {
		$url = get_post_type_archive_link( $settings::EVENT_TYPE );
		if ( $url ) {
			return $url;
		}
	}
	$page = (int) $settings->get( 'calendar_page' );
	return $page ? (string) get_permalink( $page ) : home_url( '/' );
}
