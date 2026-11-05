<?php
/**
 * iCal module.
 *
 * @package Feriae
 */

namespace Feriae\Modules\ICal;

use Feriae\Content\Event;
use Feriae\Content\PostTypes;
use Feriae\Modules\AbstractModule;
use Feriae\Occurrences\Occurrence;
use Feriae\Occurrences\Repository;

/**
 * .ics downloads for single events (?ical=1) and an upcoming-events feed
 * (/events/?ical=1), plus "Add to calendar" links.
 */
final class Module extends AbstractModule {

	private const FEED_LIMIT = 100;

	public function id(): string {
		return 'ical';
	}

	public function label(): string {
		return __( 'iCal export', 'feriae' );
	}

	public function description(): string {
		return __( 'Adds .ics downloads for each event, a subscribable feed of upcoming events, and Google Calendar links.', 'feriae' );
	}

	public function boot(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
		add_filter( 'feriae/event_details/links', array( $this, 'event_links' ), 10, 2 );
		add_filter( 'feriae/calendar/links', array( $this, 'calendar_links' ) );
	}

	/**
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'ical';
		return $vars;
	}

	public function maybe_serve(): void {
		if ( ! get_query_var( 'ical' ) ) {
			return;
		}

		$repository = feriae()->occurrences();

		if ( is_singular( feriae()->settings()->event_post_types() ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
			$date     = sanitize_text_field( wp_unslash( $_GET['feriae_occurrence'] ?? '' ) );
			$one      = $date ? $repository->on_date( get_queried_object_id(), $date ) : null;
			$result   = $one ? array( 'items' => array( $one ) ) : $repository->query(
				array(
					'event_ids'      => array( get_queried_object_id() ),
					'include_hidden' => true,
					'per_page'       => -1,
				)
			);
			$filename = get_post_field( 'post_name', get_queried_object_id() );
		} elseif ( is_post_type_archive( PostTypes::EVENT ) || is_tax( PostTypes::CATEGORY ) || $this->is_calendar_page() ) {
			$result   = $repository->query(
				array(
					'ends_after' => Repository::now(),
					'categories' => is_tax( PostTypes::CATEGORY ) ? array( get_queried_object_id() ) : array(),
					'per_page'   => self::FEED_LIMIT,
				)
			);
			$filename = sanitize_title( get_bloginfo( 'name' ) ) . '-events';
		} else {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ( $filename ?: 'events' ) . '.ics"' );
		echo Writer::calendar( $result['items'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar body, escaped per RFC 5545 in Writer.
		exit;
	}

	/**
	 * @param array<int, array{label: string, url: string}> $links Links.
	 * @return array<int, array{label: string, url: string}>
	 */
	public function event_links( array $links, Occurrence $occurrence ): array {
		$event = $occurrence->event();
		if ( ! $event ) {
			return $links;
		}
		$links[] = array(
			'label' => __( 'Google Calendar', 'feriae' ),
			'url'   => self::google_url( $occurrence, $event ),
		);
		$links[] = array(
			'label' => __( 'Download .ics', 'feriae' ),
			'url'   => add_query_arg( 'ical', '1', feriae_occurrence_url( $occurrence ) ),
		);
		return $links;
	}

	/**
	 * @param array<int, array{label: string, url: string}> $links Links.
	 * @return array<int, array{label: string, url: string}>
	 */
	public function calendar_links( array $links ): array {
		$links[] = array(
			'label' => __( 'Subscribe (iCal)', 'feriae' ),
			'url'   => add_query_arg( 'ical', '1', feriae_calendar_url() ),
		);
		return $links;
	}

	private function is_calendar_page(): bool {
		$page = (int) feriae()->settings()->get( 'calendar_page' );
		return $page && is_page( $page );
	}

	public static function google_url( Occurrence $occurrence, Event $event ): string {
		if ( $occurrence->all_day ) {
			$dates = $occurrence->start->format( 'Ymd' ) . '/' . $occurrence->end->modify( '+1 day' )->format( 'Ymd' );
		} else {
			$dates = $occurrence->start_utc()->format( 'Ymd\THis\Z' ) . '/' . $occurrence->end_utc()->format( 'Ymd\THis\Z' );
		}
		$venue = $event->venue();
		return add_query_arg(
			rawurlencode_deep(
				array(
					'action'   => 'TEMPLATE',
					'text'     => wp_strip_all_tags( get_the_title( $event->post ) ),
					'dates'    => $dates,
					'details'  => wp_trim_words( wp_strip_all_tags( get_the_excerpt( $event->post ) ), 50 ),
					'location' => $venue ? get_the_title( $venue ) . ', ' . Event::venue_address( $venue ) : '',
				)
			),
			'https://calendar.google.com/calendar/render'
		);
	}
}
