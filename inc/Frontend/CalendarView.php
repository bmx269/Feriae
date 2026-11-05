<?php
/**
 * Calendar block views.
 *
 * @package Feriae
 */

namespace Feriae\Frontend;

use DateTimeImmutable;
use Feriae\Content\EventStatus;
use Feriae\Occurrences\Occurrence;
use Feriae\Occurrences\Repository;

/**
 * Server-renders the calendar block's month and list views.
 *
 * Navigation is plain links with query args, so it works with JavaScript off,
 * behind page caches, and without the REST API. The Interactivity API upgrades
 * those links to in-place updates. Navigation URLs are noindexed and limited
 * to a fixed range so crawlers cannot walk an endless chain of months.
 */
final class CalendarView {

	public const PARAMS = array( 'feriae_view', 'feriae_month', 'feriae_date', 'feriae_page', 'feriae_past', 'feriae_cat', 'feriae_s' );

	/** How far from today month navigation may go, in years. */
	private const RANGE_YEARS = 5;

	private static int $instance = 0;

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function __construct( private array $attributes ) {}

	/**
	 * @return array<string, callable> View id => renderer( CalendarView, array $state ): string.
	 */
	public static function views(): array {
		$views = array(
			'month' => array( self::class, 'render_month' ),
			'week'  => array( self::class, 'render_week' ),
			'day'   => array( self::class, 'render_day' ),
			'list'  => array( self::class, 'render_list' ),
		);

		/**
		 * Filters calendar views. Add-ons can register a renderer (callable that
		 * receives the CalendarView and state array and returns HTML).
		 *
		 * @param array<string, callable> $views View id => renderer.
		 */
		return apply_filters( 'feriae/calendar/views', $views );
	}

	public static function view_label( string $id ): string {
		$labels = array(
			'month' => __( 'Month', 'feriae' ),
			'week'  => __( 'Week', 'feriae' ),
			'day'   => __( 'Day', 'feriae' ),
			'list'  => __( 'List', 'feriae' ),
		);
		return $labels[ $id ] ?? ucfirst( $id );
	}

	public function render(): string {
		++self::$instance;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation params.
		$params = array_intersect_key( wp_unslash( $_GET ), array_flip( self::params() ) );
		$key    = 'cal:' . md5(
			wp_json_encode(
				array(
					$this->attributes,
					$params,
					self::$instance,
					strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), '?' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					get_locale(),
					Repository::now(), // "Upcoming" and the today marker move with time.
				)
			)
		) . ':' . Repository::last_changed();

		/**
		 * Filters whether rendered calendar HTML is cached in the object cache.
		 * Disable if you add per-user output (for example "you're going" badges).
		 *
		 * @param bool  $cache      Whether to cache.
		 * @param array $attributes Block attributes.
		 */
		$use_cache = apply_filters( 'feriae/calendar/cache', true, $this->attributes );
		if ( $use_cache ) {
			$html = wp_cache_get( $key, Repository::CACHE_GROUP );
			if ( is_string( $html ) ) {
				return $html;
			}
		}

		$html = $this->render_uncached();
		if ( $use_cache ) {
			wp_cache_set( $key, $html, Repository::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		return $html;
	}

	private function render_uncached(): string {
		$views   = self::views();
		$enabled = array_values( array_intersect( (array) ( $this->attributes['views'] ?? array( 'month', 'list' ) ), array_keys( $views ) ) ) ?: array( 'month' );
		$wanted  = (string) ( $this->attributes['defaultView'] ?? '' ) ?: (string) feriae()->settings()->get( 'default_view' );
		$default = in_array( $wanted, $enabled, true ) ? $wanted : $enabled[0];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$requested = sanitize_key( wp_unslash( $_GET['feriae_view'] ?? '' ) );
		$state     = array(
			'view'       => in_array( $requested, $enabled, true ) ? $requested : $default,
			'month'      => self::parse_month( (string) wp_unslash( $_GET['feriae_month'] ?? '' ) ),
			'date'       => self::parse_date( (string) wp_unslash( $_GET['feriae_date'] ?? '' ) ),
			'page'       => max( 1, absint( $_GET['feriae_page'] ?? 1 ) ),
			'past'       => ! empty( $_GET['feriae_past'] ),
			'categories' => array_map( 'absint', (array) ( $this->attributes['categories'] ?? array() ) ),
			'enabled'    => $enabled,
			'category'   => null,
			'search'     => mb_substr( sanitize_text_field( wp_unslash( $_GET['feriae_s'] ?? '' ) ), 0, 100 ),
			'weekdays'   => self::requested_weekdays( wp_unslash( $_GET['feriae_dow'] ?? array() ) ),
			'terms'      => self::requested_terms(),
		);
		$state['category'] = $this->requested_category( sanitize_title( wp_unslash( $_GET['feriae_cat'] ?? '' ) ), $state['categories'] );
		if ( $state['category'] ) {
			// Narrow to the chosen category (children included by the repository).
			$state['categories'] = array( $state['category']->term_id );
		}
		// phpcs:enable

		$region = 'feriae-calendar-' . self::$instance;

		$switcher = '';
		if ( count( $enabled ) > 1 ) {
			$switcher = '<div class="feriae-calendar__views" role="group" aria-label="' . esc_attr__( 'Calendar view', 'feriae' ) . '">';
			foreach ( $enabled as $view ) {
				$switcher .= sprintf(
					'<a class="feriae-calendar__view" href="%s" rel="nofollow" data-wp-on--click="actions.navigate"%s>%s</a>',
					esc_url( self::url( array( 'feriae_view' => $view ) ) ),
					$view === $state['view'] ? ' aria-current="page"' : '',
					esc_html( self::view_label( $view ) )
				);
			}
			$switcher .= '</div>';
		}
		// Views place this in their header row, next to their own navigation.
		$state['switcher'] = $switcher;

		$state['filters'] = ! empty( $this->attributes['showFilters'] ) ? $this->filters( $state ) : '';
		$state['popup']   = 'popup' === ( (string) ( $this->attributes['clickAction'] ?? '' ) ?: (string) feriae()->settings()->get( 'click_action' ) );

		$body = call_user_func( $views[ $state['view'] ], $this, $state );

		/**
		 * Filters links shown under the calendar (iCal subscribe, etc.).
		 *
		 * @param array<int, array{label: string, url: string}> $links Links.
		 */
		$links  = apply_filters( 'feriae/calendar/links', array() );
		$footer = '';
		if ( $links ) {
			$footer = '<ul class="feriae-calendar__links">';
			foreach ( $links as $link ) {
				$footer .= sprintf( '<li><a href="%s" rel="nofollow">%s</a></li>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
			}
			$footer .= '</ul>';
		}

		return sprintf(
			'<div class="feriae-calendar feriae-calendar--%1$s" data-wp-interactive="feriae/calendar" data-wp-router-region="%2$s" id="%2$s"%6$s>%3$s%4$s%5$s%7$s</div>',
			esc_attr( $state['view'] ),
			esc_attr( $region ),
			$body,
			! empty( $this->attributes['showLegend'] ) ? self::legend( array_map( 'absint', (array) ( $this->attributes['categories'] ?? array() ) ), ! empty( $this->attributes['showFilters'] ) ) : '',
			$footer,
			$state['popup'] ? ' data-wp-context="' . esc_attr( (string) wp_json_encode( array( 'popup' => array(), 'popupOpen' => false ) ) ) . '"' : '',
			$state['popup'] ? self::dialog( $region ) : ''
		);
	}

	public function heading_tag(): string {
		$level = (int) ( $this->attributes['headingLevel'] ?? 2 );
		return 'h' . ( $level >= 1 && $level <= 6 ? $level : 2 );
	}

	/**
	 * @param array<string, mixed> $state View state.
	 */
	public static function render_month( CalendarView $view, array $state ): string {
		$month = $state['month'];
		$sow   = (int) get_option( 'start_of_week', 1 );

		$first      = $month;
		$last       = $month->modify( 'last day of this month' );
		$grid_start = $first->modify( '-' . ( ( (int) $first->format( 'w' ) - $sow + 7 ) % 7 ) . ' days' );
		$grid_end   = $last->modify( '+' . ( ( $sow + 6 - (int) $last->format( 'w' ) + 7 ) % 7 ) . ' days' );

		$result = feriae()->occurrences()->query(
			array(
				'local_from' => $grid_start->format( 'Y-m-d 00:00:00' ),
				'local_to'   => $grid_end->format( 'Y-m-d 23:59:59' ),
				'per_page'   => -1,
				'count'      => false,
			) + self::query_filters( $state )
		);

		$days = array();
		foreach ( $result['items'] as $occurrence ) {
			$day  = max( $occurrence->start->format( 'Y-m-d' ), $grid_start->format( 'Y-m-d' ) );
			$stop = min( $occurrence->end->format( 'Y-m-d' ), $grid_end->format( 'Y-m-d' ) );
			while ( $day <= $stop ) {
				$days[ $day ][] = $occurrence;
				$day            = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
			}
		}

		$min   = self::range_start();
		$max   = self::range_end();
		$prev  = $month->modify( '-1 month' );
		$next  = $month->modify( '+1 month' );
		$title = wp_date( 'F Y', $month->getTimestamp(), wp_timezone() );
		$tag   = $view->heading_tag();

		$nav  = '<div class="feriae-calendar__header">';
		$nav .= sprintf( '<%1$s class="feriae-calendar__title" tabindex="-1">%2$s</%1$s>', $tag, esc_html( $title ) );
		$nav .= '<div class="feriae-calendar__tools">' . $state['switcher'];
		$nav .= '<nav class="feriae-calendar__nav" aria-label="' . esc_attr__( 'Months', 'feriae' ) . '">';
		$nav .= $prev >= $min ? self::nav_link( array( 'feriae_month' => $prev->format( 'Y-m' ) ), __( 'Previous month', 'feriae' ), '‹' ) : '';
		$nav .= self::nav_link( array( 'feriae_month' => null ), __( 'This month', 'feriae' ), __( 'Today', 'feriae' ), false );
		$nav .= $next <= $max ? self::nav_link( array( 'feriae_month' => $next->format( 'Y-m' ) ), __( 'Next month', 'feriae' ), '›' ) : '';
		$nav .= '</nav></div></div>' . $state['filters'] . self::summary( $state );

		$today = current_datetime()->format( 'Y-m-d' );
		$html  = '<table class="feriae-month"><caption class="feriae-visually-hidden">' . esc_html( $title ) . '</caption><thead><tr>';

		global $wp_locale;
		for ( $i = 0; $i < 7; $i++ ) {
			$weekday = $wp_locale->get_weekday( ( $sow + $i ) % 7 );
			$html   .= sprintf( '<th scope="col"><abbr title="%s">%s</abbr></th>', esc_attr( $weekday ), esc_html( $wp_locale->get_weekday_abbrev( $weekday ) ) );
		}
		$html .= '</tr></thead><tbody>';

		$max_per_day = 3;
		for ( $day = $grid_start; $day <= $grid_end; $day = $day->modify( '+1 day' ) ) {
			$key = $day->format( 'Y-m-d' );
			if ( (int) $day->format( 'w' ) === $sow ) {
				$html .= '<tr>';
			}
			$classes = array( 'feriae-month__day' );
			if ( $day->format( 'm' ) !== $month->format( 'm' ) ) {
				$classes[] = 'is-other-month';
			}
			if ( $key === $today ) {
				$classes[] = 'is-today';
			}
			$events = $days[ $key ] ?? array();
			if ( ! $events ) {
				$classes[] = 'is-empty';
			}

			$html .= sprintf(
				'<td class="%s"><span class="feriae-month__date"><time datetime="%s">%s</time></span>',
				esc_attr( implode( ' ', $classes ) ),
				esc_attr( $key ),
				esc_html( wp_date( 'j', $day->getTimestamp(), wp_timezone() ) )
			);
			if ( $events ) {
				$html .= '<ul class="feriae-month__events">';
				foreach ( array_slice( $events, 0, $max_per_day ) as $occurrence ) {
					$html .= '<li>' . self::month_item( $occurrence, $key, $state['popup'] ) . '</li>';
				}
				$html .= '</ul>';
				if ( count( $events ) > $max_per_day ) {
					$html .= sprintf(
						'<a class="feriae-month__more" href="%s" rel="nofollow" data-wp-on--click="actions.navigate">%s</a>',
						esc_url(
							self::url(
								array(
									'feriae_view' => in_array( 'day', $state['enabled'], true ) ? 'day' : 'list',
									'feriae_date' => $key,
								)
							)
						),
						/* translators: %d: number of additional events */
						esc_html( sprintf( _n( '+%d more', '+%d more', count( $events ) - $max_per_day, 'feriae' ), count( $events ) - $max_per_day ) )
					);
				}
			}
			$html .= '</td>';
			if ( (int) $day->modify( '+1 day' )->format( 'w' ) === $sow ) {
				$html .= '</tr>';
			}
		}
		$html .= '</tbody></table>';

		if ( ! $result['items'] ) {
			$html .= '<p class="feriae-calendar__empty">' . esc_html( self::is_filtered( $state ) ? __( 'No events match these filters this month.', 'feriae' ) : __( 'No events scheduled this month.', 'feriae' ) ) . '</p>';
		}

		return $nav . $html;
	}

	/**
	 * A week of days, each listing its events (all-day ones first). A row of
	 * day columns on wide screens; days stack on phones.
	 *
	 * @param array<string, mixed> $state View state.
	 */
	public static function render_week( CalendarView $view, array $state ): string {
		$sow    = (int) get_option( 'start_of_week', 1 );
		$anchor = self::clamp( $state['date'] ?? current_datetime()->setTime( 0, 0 ) );
		$start  = $anchor->modify( '-' . ( ( (int) $anchor->format( 'w' ) - $sow + 7 ) % 7 ) . ' days' );
		$end    = $start->modify( '+6 days' );
		$days   = self::by_day( $start, $end, $state );
		$tag    = $view->heading_tag();

		/* translators: 1: first day of the week, 2: last day */
		$title = sprintf( __( '%1$s – %2$s', 'feriae' ), wp_date( 'M j', $start->getTimestamp(), wp_timezone() ), wp_date( 'M j, Y', $end->getTimestamp(), wp_timezone() ) );
		$prev  = $start->modify( '-7 days' );
		$next  = $start->modify( '+7 days' );

		$html  = '<div class="feriae-calendar__header">';
		$html .= sprintf( '<%1$s class="feriae-calendar__title" tabindex="-1">%2$s</%1$s>', $tag, esc_html( $title ) );
		$html .= '<div class="feriae-calendar__tools">' . $state['switcher'];
		$html .= '<nav class="feriae-calendar__nav" aria-label="' . esc_attr__( 'Weeks', 'feriae' ) . '">';
		$html .= $prev >= self::range_start() ? self::nav_link( array( 'feriae_date' => $prev->format( 'Y-m-d' ) ), __( 'Previous week', 'feriae' ), '‹' ) : '';
		$html .= self::nav_link( array( 'feriae_date' => null ), __( 'This week', 'feriae' ), __( 'Today', 'feriae' ), false );
		$html .= $next <= self::range_end() ? self::nav_link( array( 'feriae_date' => $next->format( 'Y-m-d' ) ), __( 'Next week', 'feriae' ), '›' ) : '';
		$html .= '</nav></div></div>' . $state['filters'] . self::summary( $state );

		$today   = current_datetime()->format( 'Y-m-d' );
		$sub_tag = 'h' . min( 6, (int) substr( $tag, 1 ) + 1 );
		$any     = false;
		$html   .= '<ol class="feriae-week">';
		for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
			$key    = $day->format( 'Y-m-d' );
			$events = $days[ $key ] ?? array();
			$any    = $any || (bool) $events;
			$html  .= sprintf( '<li class="feriae-week__day%s%s">', $key === $today ? ' is-today' : '', $events ? '' : ' is-empty' );
			$html  .= sprintf(
				'<%1$s class="feriae-week__date"><a href="%2$s" rel="nofollow" data-wp-on--click="actions.navigate"%5$s><span class="feriae-week__weekday">%3$s</span> <time datetime="%4$s">%6$s</time></a></%1$s>',
				$sub_tag,
				esc_url(
					self::url(
						array(
							'feriae_view' => in_array( 'day', $state['enabled'], true ) ? 'day' : 'list',
							'feriae_date' => $key,
						)
					)
				),
				esc_html( wp_date( 'D', $day->getTimestamp(), wp_timezone() ) ),
				esc_attr( $key ),
				$key === $today ? ' aria-current="date"' : '',
				esc_html( wp_date( 'j', $day->getTimestamp(), wp_timezone() ) )
			);
			if ( $events ) {
				$html .= '<ul class="feriae-week__events">';
				foreach ( $events as $occurrence ) {
					$html .= '<li>' . self::month_item( $occurrence, $key, $state['popup'] ) . '</li>';
				}
				$html .= '</ul>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';

		if ( ! $any ) {
			$html .= '<p class="feriae-calendar__empty">' . esc_html( self::is_filtered( $state ) ? __( 'No events match these filters this week.', 'feriae' ) : __( 'No events scheduled this week.', 'feriae' ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * One day's events as cards, all-day ones first.
	 *
	 * @param array<string, mixed> $state View state.
	 */
	public static function render_day( CalendarView $view, array $state ): string {
		$day    = self::clamp( $state['date'] ?? current_datetime()->setTime( 0, 0 ) );
		$key    = $day->format( 'Y-m-d' );
		$events = self::by_day( $day, $day, $state )[ $key ] ?? array();
		$tag    = $view->heading_tag();
		$prev   = $day->modify( '-1 day' );
		$next   = $day->modify( '+1 day' );

		$html  = '<div class="feriae-calendar__header">';
		$html .= sprintf( '<%1$s class="feriae-calendar__title" tabindex="-1">%2$s</%1$s>', $tag, esc_html( wp_date( 'l, F j, Y', $day->getTimestamp(), wp_timezone() ) ) );
		$html .= '<div class="feriae-calendar__tools">' . $state['switcher'];
		$html .= '<nav class="feriae-calendar__nav" aria-label="' . esc_attr__( 'Days', 'feriae' ) . '">';
		$html .= $prev >= self::range_start() ? self::nav_link( array( 'feriae_date' => $prev->format( 'Y-m-d' ) ), __( 'Previous day', 'feriae' ), '‹' ) : '';
		$html .= self::nav_link( array( 'feriae_date' => null ), __( 'Today', 'feriae' ), __( 'Today', 'feriae' ), false );
		$html .= $next <= self::range_end() ? self::nav_link( array( 'feriae_date' => $next->format( 'Y-m-d' ) ), __( 'Next day', 'feriae' ), '›' ) : '';
		$html .= '</nav></div></div>' . $state['filters'] . self::summary( $state );

		if ( ! $events ) {
			return $html . '<p class="feriae-calendar__empty">' . esc_html( self::is_filtered( $state ) ? __( 'No events match these filters on this day.', 'feriae' ) : __( 'No events scheduled on this day.', 'feriae' ) ) . '</p>';
		}
		$sub_tag = 'h' . min( 6, (int) substr( $tag, 1 ) + 1 );
		$html   .= '<ol class="feriae-list__items feriae-day">';
		foreach ( $events as $occurrence ) {
			$html .= '<li>' . self::list_item( $occurrence, $sub_tag, array( 'popup' => $state['popup'] ) ) . '</li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Occurrences touching each local day from $from to $to, all-day and
	 * multi-day ones first, then by start time.
	 *
	 * @param array<string, mixed> $state View state.
	 * @return array<string, Occurrence[]> Keyed by "Y-m-d".
	 */
	private static function by_day( DateTimeImmutable $from, DateTimeImmutable $to, array $state ): array {
		$result = feriae()->occurrences()->query(
			array(
				'local_from'   => $from->format( 'Y-m-d 00:00:00' ),
				'local_to'     => $to->format( 'Y-m-d 23:59:59' ),
				'per_page'     => -1,
				'count'        => false,
				'prime_images' => 'day' === $state['view'],
			) + self::query_filters( $state )
		);
		$days = array();
		foreach ( $result['items'] as $occurrence ) {
			$day  = max( $occurrence->start->format( 'Y-m-d' ), $from->format( 'Y-m-d' ) );
			$stop = min( $occurrence->end->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
			while ( $day <= $stop ) {
				$days[ $day ][] = $occurrence;
				$day            = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
			}
		}
		foreach ( $days as $key => $items ) {
			usort(
				$items,
				static function ( Occurrence $a, Occurrence $b ) use ( $key ): int {
					$a_long = $a->all_day || $a->start->format( 'Y-m-d' ) !== $key;
					$b_long = $b->all_day || $b->start->format( 'Y-m-d' ) !== $key;
					return $a_long === $b_long ? $a->start <=> $b->start : ( $a_long ? -1 : 1 );
				}
			);
			$days[ $key ] = $items;
		}
		return $days;
	}

	private static function clamp( DateTimeImmutable $date ): DateTimeImmutable {
		return max( self::range_start(), min( self::range_end(), $date ) );
	}

	private static function month_item( Occurrence $occurrence, string $day, bool $popup = false ): string {
		$event = $occurrence->event();
		if ( ! $event ) {
			return '';
		}
		$starts_today = $occurrence->start->format( 'Y-m-d' ) === $day;
		$time         = ( ! $occurrence->all_day && $starts_today ) ? Formatter::time( $occurrence->start ) . ' ' : '';
		return sprintf(
			'<a class="feriae-month__event%s%s" href="%s"%s style="%s">%s%s<span class="feriae-month__event-title">%s</span></a>',
			$occurrence->all_day || $occurrence->is_multi_day() ? ' is-all-day' : '',
			$occurrence->is_called_off() ? ' is-called-off' : '',
			esc_url( feriae_occurrence_url( $occurrence ) ),
			$popup ? self::popup_attributes( $occurrence ) : '',
			esc_attr( CategoryColors::style_for( $event->id() ) ),
			EventStatus::badge( $occurrence, 'feriae-status' ),
			$time ? '<span class="feriae-month__event-time">' . esc_html( $time ) . '</span>' : '',
			esc_html( get_the_title( $event->post ) )
		);
	}

	/**
	 * @param array<string, mixed> $state View state.
	 */
	public static function render_list( CalendarView $view, array $state ): string {
		$per_page = (int) ( $view->attributes['perPage'] ?? 0 ) ?: (int) feriae()->settings()->get( 'per_page' );
		$args     = self::query_filters( $state ) + array(
			'per_page'     => $per_page,
			'page'         => $state['page'],
			'prime_images' => true,
		);

		if ( $state['past'] ) {
			$args['ends_before'] = Repository::now();
			$args['order']       = 'DESC';
			$title               = __( 'Past events', 'feriae' );
		} elseif ( $state['date'] ) {
			$args['local_from'] = $state['date']->format( 'Y-m-d 00:00:00' );
			$args['local_to']   = '9999-12-31 23:59:59';
			/* translators: %s: date */
			$title = sprintf( __( 'Events from %s', 'feriae' ), Formatter::date( $state['date'] ) );
		} else {
			$args['ends_after'] = Repository::now();
			$title              = __( 'Upcoming events', 'feriae' );
		}

		$result = feriae()->occurrences()->query( $args );
		$tag    = $view->heading_tag();

		$html  = '<div class="feriae-calendar__header">';
		$html .= sprintf( '<%1$s class="feriae-calendar__title" tabindex="-1">%2$s</%1$s>', $tag, esc_html( $title ) );
		$html .= '<div class="feriae-calendar__tools">' . $state['switcher'];
		$html .= '<nav class="feriae-calendar__nav" aria-label="' . esc_attr__( 'Event list', 'feriae' ) . '">';
		$html .= $state['past']
			? self::nav_link( array( 'feriae_past' => null, 'feriae_page' => null ), __( 'Upcoming events', 'feriae' ), __( 'Upcoming', 'feriae' ), false )
			: self::nav_link( array( 'feriae_past' => 1, 'feriae_page' => null, 'feriae_date' => null ), __( 'Past events', 'feriae' ), __( 'Past', 'feriae' ), false );
		$html .= '</nav></div></div>' . $state['filters'] . self::summary( $state );

		if ( ! $result['items'] ) {
			return $html . '<p class="feriae-calendar__empty">' . esc_html( self::is_filtered( $state ) ? __( 'No events match these filters.', 'feriae' ) : __( 'There are no events to show.', 'feriae' ) ) . '</p>';
		}

		$current_group = '';
		$sub_tag       = 'h' . min( 6, (int) substr( $tag, 1 ) + 1 );
		$html         .= '<div class="feriae-list">';
		foreach ( $result['items'] as $occurrence ) {
			$group = wp_date( 'F Y', $occurrence->start->getTimestamp(), $occurrence->start->getTimezone() );
			if ( $group !== $current_group ) {
				$html         .= ( $current_group ? '</ol>' : '' ) . sprintf( '<%1$s class="feriae-list__group">%2$s</%1$s><ol class="feriae-list__items">', $sub_tag, esc_html( $group ) );
				$current_group = $group;
			}
			$html .= '<li>' . self::list_item( $occurrence, $sub_tag, array( 'popup' => $state['popup'] ) ) . '</li>';
		}
		$html .= '</ol></div>';

		if ( $result['pages'] > 1 ) {
			$html .= '<nav class="feriae-pagination" aria-label="' . esc_attr__( 'Event pages', 'feriae' ) . '">';
			if ( $state['page'] > 1 ) {
				$html .= self::nav_link( array( 'feriae_page' => $state['page'] - 1 ), __( 'Previous page', 'feriae' ), __( 'Previous', 'feriae' ), false );
			}
			/* translators: 1: current page, 2: total pages */
			$html .= '<span class="feriae-pagination__status">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'feriae' ), $state['page'], $result['pages'] ) ) . '</span>';
			if ( $state['page'] < $result['pages'] ) {
				$html .= self::nav_link( array( 'feriae_page' => $state['page'] + 1 ), __( 'Next page', 'feriae' ), __( 'Next', 'feriae' ), false );
			}
			$html .= '</nav>';
		}

		return $html;
	}

	/**
	 * One event in list layouts. Shared with the upcoming-events block.
	 */
	public static function list_item( Occurrence $occurrence, string $title_tag = 'h3', array $options = array() ): string {
		$event = $occurrence->event();
		if ( ! $event ) {
			return '';
		}
		$options = wp_parse_args(
			$options,
			array(
				'image'   => true,
				'excerpt' => true,
				'venue'   => true,
				'popup'   => false,
			)
		);

		$image = $options['image'] ? get_the_post_thumbnail( $event->post, 'medium', array( 'class' => 'feriae-event-card__image', 'alt' => '' ) ) : '';
		$venue = $options['venue'] ? $event->venue() : null;

		$html  = sprintf( '<article class="feriae-event-card%s%s" style="%s">', $image ? ' has-image' : '', $occurrence->is_called_off() ? ' is-called-off' : '', esc_attr( CategoryColors::style_for( $event->id() ) ) );
		$html .= sprintf(
			'<div class="feriae-event-card__date" aria-hidden="true"><span class="feriae-event-card__month">%s</span><span class="feriae-event-card__day">%s</span></div>',
			esc_html( wp_date( 'M', $occurrence->start->getTimestamp(), $occurrence->start->getTimezone() ) ),
			esc_html( wp_date( 'j', $occurrence->start->getTimestamp(), $occurrence->start->getTimezone() ) )
		);
		$html .= '<div class="feriae-event-card__body">';
		$html .= sprintf( '<%1$s class="feriae-event-card__title"><a href="%2$s"%4$s>%3$s</a></%1$s>', tag_escape( $title_tag ), esc_url( feriae_occurrence_url( $occurrence ) ), esc_html( get_the_title( $event->post ) ), $options['popup'] ? self::popup_attributes( $occurrence ) : '' );
		$html .= sprintf( '<p class="feriae-event-card__when">%s<time datetime="%s">%s</time></p>', EventStatus::badge( $occurrence, 'feriae-status' ), esc_attr( Formatter::iso( $occurrence->start, $occurrence->all_day ) ), esc_html( Formatter::range( $occurrence ) ) );
		if ( $venue ) {
			$html .= '<p class="feriae-event-card__venue">' . esc_html( get_the_title( $venue ) ) . '</p>';
		}
		if ( $options['excerpt'] && has_excerpt( $event->post ) ) {
			$html .= '<p class="feriae-event-card__excerpt">' . esc_html( get_the_excerpt( $event->post ) ) . '</p>';
		}
		$html .= '</div>' . $image . '</article>';

		return $html;
	}

	/**
	 * Context and click handler that open the summary popup. The link keeps its
	 * href, so without JavaScript (or with a modifier key) it opens the page.
	 */
	private static function popup_attributes( Occurrence $occurrence ): string {
		$event = $occurrence->event();
		$venue = $event?->venue();
		$data  = array(
			'event' => array(
				'title'   => html_entity_decode( get_the_title( $event->post ), ENT_QUOTES ),
				'when'    => Formatter::range( $occurrence ),
				'venue'   => $venue ? html_entity_decode( get_the_title( $venue ), ENT_QUOTES ) : '',
				'excerpt' => wp_trim_words( wp_strip_all_tags( get_the_excerpt( $event->post ) ), 30 ),
				'status'  => self::status_text( $occurrence ),
				'url'     => feriae_occurrence_url( $occurrence ),
			),
		);
		return ' aria-haspopup="dialog" data-wp-on--click="actions.openEvent" data-wp-context="' . esc_attr( (string) wp_json_encode( $data ) ) . '"';
	}

	/**
	 * "Cancelled: reason" for a called-off date, else empty.
	 */
	private static function status_text( Occurrence $occurrence ): string {
		if ( ! $occurrence->is_called_off() ) {
			return '';
		}
		$reason = EventStatus::reason( $occurrence );
		$label  = EventStatus::label( $occurrence->event_status );
		/* translators: 1: status such as "Cancelled", 2: the organiser's note */
		return '' !== $reason ? sprintf( __( '%1$s: %2$s', 'feriae' ), $label, $reason ) : $label;
	}

	/**
	 * The summary popup: a native modal <dialog> (focus containment, Escape to
	 * close) filled from the clicked event's context.
	 */
	private static function dialog( string $region ): string {
		$title = $region . '-popup-title';
		return '<dialog class="feriae-popup" aria-labelledby="' . esc_attr( $title ) . '" data-wp-watch="callbacks.syncDialog" data-wp-on--close="actions.dialogClosed" data-wp-on--click="actions.dialogBackdrop">'
			. '<div class="feriae-popup__inner">'
			. '<button type="button" class="feriae-popup__close" data-wp-on--click="actions.closePopup"><span aria-hidden="true">×</span><span class="feriae-visually-hidden">' . esc_html__( 'Close', 'feriae' ) . '</span></button>'
			. '<h2 class="feriae-popup__title" id="' . esc_attr( $title ) . '" data-wp-text="context.popup.title"></h2>'
			. '<p class="feriae-popup__status" data-wp-text="context.popup.status" data-wp-bind--hidden="!context.popup.status"></p>'
			. '<p class="feriae-popup__when" data-wp-text="context.popup.when"></p>'
			. '<p class="feriae-popup__venue" data-wp-text="context.popup.venue" data-wp-bind--hidden="!context.popup.venue"></p>'
			. '<p class="feriae-popup__excerpt" data-wp-text="context.popup.excerpt" data-wp-bind--hidden="!context.popup.excerpt"></p>'
			. '<p class="feriae-popup__actions"><a class="feriae-popup__link" data-wp-bind--href="context.popup.url">' . esc_html__( 'View event', 'feriae' ) . '</a></p>'
			. '</div></dialog>';
	}

	/**
	 * Legend of coloured categories.
	 *
	 * @param int[] $categories Restrict to these categories.
	 */
	private static function legend( array $categories, bool $filter_links = false ): string {
		$terms = CategoryColors::legend_terms( $categories );
		if ( ! $terms ) {
			return '';
		}
		$html = '<ul class="feriae-legend" aria-label="' . esc_attr__( 'Category colours', 'feriae' ) . '">';
		foreach ( $terms as $term ) {
			$html .= sprintf(
				'<li><span class="feriae-legend__swatch" style="background:%s" aria-hidden="true"></span><a href="%s"' . ( $filter_links ? ' rel="nofollow" data-wp-on--click="actions.navigate"' : '' ) . '>%s</a></li>',
				esc_attr( CategoryColors::fill( CategoryColors::color( $term->term_id ) ) ),
				esc_url(
					$filter_links
						? self::url(
							array(
								'feriae_cat' => $term->slug,
								'feriae_page' => null,
							)
						)
						: get_term_link( $term )
				),
				esc_html( $term->name )
			);
		}
		return $html . '</ul>';
	}

	/**
	 * The category chosen in the filter, if it exists and the block allows it.
	 *
	 * @param int[] $allowed Block's category restriction (empty for all).
	 */
	private function requested_category( string $slug, array $allowed ): ?\WP_Term {
		if ( '' === $slug ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, \Feriae\Content\PostTypes::CATEGORY );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		if ( $allowed ) {
			$ancestors = get_ancestors( $term->term_id, \Feriae\Content\PostTypes::CATEGORY, 'taxonomy' );
			if ( ! array_intersect( array_merge( array( $term->term_id ), $ancestors ), $allowed ) ) {
				return null;
			}
		}
		return $term;
	}

	private static function is_filtered( array $state ): bool {
		return null !== $state['category'] || '' !== $state['search'] || $state['weekdays'] || $state['terms'];
	}

	/**
	 * Weekdays from ?feriae_dow (a list, or comma-separated), 0 (Sunday) to 6.
	 *
	 * @return int[]
	 */
	private static function requested_weekdays( mixed $value ): array {
		$days = is_array( $value ) ? $value : explode( ',', (string) $value );
		$days = array_values( array_unique( array_filter( array_map( 'intval', array_filter( $days, 'is_numeric' ) ), static fn( $d ) => $d >= 0 && $d <= 6 ) ) );
		sort( $days );
		return count( $days ) < 7 ? $days : array();
	}

	/**
	 * Attribute terms from ?{taxonomy}[]=slug, as taxonomy => term ids.
	 *
	 * @return array<string, int[]>
	 */
	private static function requested_terms(): array {
		$out = array();
		foreach ( array_keys( \Feriae\Content\Attributes::all() ) as $taxonomy ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = wp_unslash( $_GET[ $taxonomy ] ?? array() );
			$slugs = array_map( 'sanitize_title', is_array( $value ) ? $value : explode( ',', (string) $value ) );
			$ids   = array();
			foreach ( array_filter( $slugs ) as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$ids[] = $term->term_id;
				}
			}
			if ( $ids ) {
				$out[ $taxonomy ] = $ids;
			}
		}
		return $out;
	}

	/**
	 * "More filters": day of the week and each event attribute, as checkboxes
	 * (any ticked value matches). Open while any of them is in use.
	 *
	 * @param array<string, mixed> $state View state.
	 */
	private static function more_filters( string $id, array $state ): string {
		global $wp_locale;
		$sow    = (int) get_option( 'start_of_week', 1 );
		$groups = '';

		$days = '';
		for ( $i = 0; $i < 7; $i++ ) {
			$day   = ( $sow + $i ) % 7;
			$days .= sprintf(
				'<label class="feriae-calendar__choice"><input type="checkbox" name="feriae_dow[]" value="%1$d"%2$s> %3$s</label>',
				$day,
				checked( in_array( $day, $state['weekdays'], true ), true, false ),
				esc_html( $wp_locale->get_weekday( $day ) )
			);
		}
		$groups .= '<fieldset class="feriae-calendar__choices"><legend>' . esc_html__( 'Day of the week', 'feriae' ) . '</legend>' . $days . '</fieldset>';

		foreach ( \Feriae\Content\Attributes::all() as $taxonomy => $label ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					// Creation order: imported options keep their source order.
					'orderby'    => 'term_id',
				)
			);
			if ( ! is_array( $terms ) || ! $terms ) {
				continue;
			}
			$chosen = $state['terms'][ $taxonomy ] ?? array();
			$items  = '';
			foreach ( $terms as $term ) {
				$items .= sprintf(
					'<label class="feriae-calendar__choice"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
					esc_attr( $taxonomy ),
					esc_attr( $term->slug ),
					checked( in_array( $term->term_id, $chosen, true ), true, false ),
					esc_html( $term->name )
				);
			}
			$groups .= '<fieldset class="feriae-calendar__choices"><legend>' . esc_html( $label ) . '</legend>' . $items . '</fieldset>';
		}

		return sprintf(
			'<details class="feriae-calendar__more" id="%1$s-more"%2$s><summary>%3$s</summary><div class="feriae-calendar__more-groups">%4$s</div></details>',
			esc_attr( $id ),
			$state['weekdays'] || $state['terms'] ? ' open' : '',
			esc_html__( 'More filters', 'feriae' ),
			$groups
		);
	}

	/**
	 * Filter and search form. A plain GET form (works without JavaScript); the
	 * view script submits it through the router to update in place.
	 *
	 * @param array<string, mixed> $state View state.
	 */
	private function filters( array $state ): string {
		$id      = 'feriae-filters-' . self::$instance;
		$allowed = array_map( 'absint', (array) ( $this->attributes['categories'] ?? array() ) );
		$terms   = get_terms(
			array(
				'taxonomy'   => \Feriae\Content\PostTypes::CATEGORY,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		$terms   = is_array( $terms ) ? $terms : array();
		if ( $allowed ) {
			$terms = array_values(
				array_filter(
					$terms,
					static fn( \WP_Term $t ) => (bool) array_intersect( array_merge( array( $t->term_id ), get_ancestors( $t->term_id, \Feriae\Content\PostTypes::CATEGORY, 'taxonomy' ) ), $allowed )
				)
			);
		}

		$html  = sprintf(
			'<form class="feriae-calendar__filters" role="search" aria-label="%s" method="get" action="%s" data-wp-on--submit="actions.filter">',
			esc_attr__( 'Filter events', 'feriae' ),
			esc_url( remove_query_arg( self::params() ) )
		);
		// Keep the current view, month, date, and past/upcoming; filtering starts again at page 1.
		$keep = array(
			'feriae_view'  => $state['view'],
			'feriae_month' => isset( $_GET['feriae_month'] ) ? $state['month']->format( 'Y-m' ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'feriae_date'  => $state['date'] ? $state['date']->format( 'Y-m-d' ) : '',
			'feriae_past'  => $state['past'] ? '1' : '',
		);
		foreach ( $keep as $name => $value ) {
			if ( '' !== $value ) {
				$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
		}
		if ( $terms ) {
			$html .= '<p class="feriae-calendar__filter"><label for="' . esc_attr( $id ) . '-cat">' . esc_html__( 'Category', 'feriae' ) . '</label>';
			$html .= '<select id="' . esc_attr( $id ) . '-cat" name="feriae_cat"><option value="">' . esc_html__( 'All categories', 'feriae' ) . '</option>';
			foreach ( self::ordered_terms( $terms ) as list( $term, $depth ) ) {
				$html .= sprintf(
					'<option value="%s"%s>%s%s</option>',
					esc_attr( $term->slug ),
					selected( $state['category']?->term_id, $term->term_id, false ),
					str_repeat( '— ', $depth ),
					esc_html( $term->name )
				);
			}
			$html .= '</select></p>';
		}
		$html .= '<p class="feriae-calendar__filter"><label for="' . esc_attr( $id ) . '-s">' . esc_html__( 'Search events', 'feriae' ) . '</label>';
		$html .= '<input id="' . esc_attr( $id ) . '-s" type="search" name="feriae_s" maxlength="100" value="' . esc_attr( $state['search'] ) . '"></p>';
		$html .= self::more_filters( $id, $state );
		$html .= '<p class="feriae-calendar__filter-actions"><button type="submit" class="feriae-calendar__filter-submit">' . esc_html__( 'Filter', 'feriae' ) . '</button>';
		if ( self::is_filtered( $state ) ) {
			$clear = array(
				'feriae_cat'  => null,
				'feriae_s'    => null,
				'feriae_dow'  => null,
				'feriae_page' => null,
			);
			foreach ( array_keys( \Feriae\Content\Attributes::all() ) as $taxonomy ) {
				$clear[ $taxonomy ] = null;
			}
			$html .= self::nav_link(
				$clear,
				__( 'Clear filters', 'feriae' ),
				__( 'Clear filters', 'feriae' ),
				false
			);
		}
		return $html . '</p></form>';
	}

	/**
	 * Terms in parent → child order with depth, for an indented select.
	 *
	 * @param \WP_Term[] $terms Terms.
	 * @return array<int, array{0: \WP_Term, 1: int}>
	 */
	private static function ordered_terms( array $terms ): array {
		$by_parent = array();
		$ids       = wp_list_pluck( $terms, 'term_id' );
		foreach ( $terms as $term ) {
			$parent                 = in_array( $term->parent, $ids, true ) ? $term->parent : 0;
			$by_parent[ $parent ][] = $term;
		}
		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $by_parent ): void {
			foreach ( $by_parent[ $parent ] ?? array() as $term ) {
				$out[] = array( $term, $depth );
				$walk( $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	/**
	 * "Showing Music events matching “jazz”", read with the heading after updates.
	 *
	 * @param array<string, mixed> $state View state.
	 */
	private static function summary( array $state ): string {
		if ( ! self::is_filtered( $state ) ) {
			return '';
		}
		if ( $state['weekdays'] || $state['terms'] ) {
			global $wp_locale;
			$parts = array();
			if ( $state['category'] ) {
				$parts[] = $state['category']->name;
			}
			foreach ( $state['terms'] as $taxonomy => $ids ) {
				$parts[] = implode( ' / ', array_map( static fn( $id ) => get_term( $id, $taxonomy )->name, $ids ) );
			}
			if ( $state['weekdays'] ) {
				$parts[] = implode( ' / ', array_map( array( $wp_locale, 'get_weekday' ), $state['weekdays'] ) );
			}
			if ( '' !== $state['search'] ) {
				/* translators: %s: search terms */
				$parts[] = sprintf( __( 'matching “%s”', 'feriae' ), $state['search'] );
			}
			/* translators: %s: active filters, e.g. "Kids / Teens, Saturday" */
			return '<p class="feriae-calendar__summary">' . esc_html( sprintf( __( 'Showing events: %s', 'feriae' ), implode( ', ', $parts ) ) ) . '</p>';
		}
		if ( $state['category'] && '' !== $state['search'] ) {
			/* translators: 1: category name, 2: search terms */
			$text = sprintf( __( 'Showing %1$s events matching “%2$s”', 'feriae' ), $state['category']->name, $state['search'] );
		} elseif ( $state['category'] ) {
			/* translators: %s: category name */
			$text = sprintf( __( 'Showing %s events', 'feriae' ), $state['category']->name );
		} else {
			/* translators: %s: search terms */
			$text = sprintf( __( 'Showing events matching “%s”', 'feriae' ), $state['search'] );
		}
		return '<p class="feriae-calendar__summary">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Current URL with calendar params replaced. Null values remove a param.
	 *
	 * @param array<string, mixed> $params Params to set.
	 */
	public static function url( array $params ): string {
		$keep = array();
		foreach ( self::params() as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $key ] ) && 'feriae_page' !== $key ) {
				$value        = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$keep[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
			}
		}
		$params = array_merge( $keep, $params );
		$url    = remove_query_arg( self::params() );
		return add_query_arg( array_filter( $params, static fn( $v ) => null !== $v ), $url );
	}

	/**
	 * Every calendar query parameter: navigation, filters, and one per event
	 * attribute (named after its taxonomy).
	 *
	 * @return string[]
	 */
	public static function params(): array {
		return array_merge( self::PARAMS, array( 'feriae_dow' ), array_keys( \Feriae\Content\Attributes::all() ) );
	}

	/**
	 * Filters every view applies to its query.
	 *
	 * @param array<string, mixed> $state View state.
	 * @return array<string, mixed>
	 */
	private static function query_filters( array $state ): array {
		return array(
			'categories' => $state['categories'],
			'search'     => $state['search'],
			'weekdays'   => $state['weekdays'],
			'terms'      => $state['terms'],
		);
	}

	private static function nav_link( array $params, string $label, string $text, bool $icon = true ): string {
		return sprintf(
			'<a class="feriae-calendar__nav-link%s" href="%s" rel="nofollow" data-wp-on--click="actions.navigate"%s>%s</a>',
			$icon ? ' is-icon' : '',
			esc_url( self::url( $params ) ),
			$icon ? ' aria-label="' . esc_attr( $label ) . '"' : '',
			esc_html( $text )
		);
	}

	private static function parse_month( string $value ): DateTimeImmutable {
		$month = preg_match( '/^\d{4}-\d{2}$/', $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value . '-01', wp_timezone() ) : false;
		if ( ! $month ) {
			return current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 );
		}
		return max( self::range_start(), min( self::range_end(), $month ) );
	}

	private static function parse_date( string $value ): ?DateTimeImmutable {
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;
		return $date ?: null;
	}

	private static function range_start(): DateTimeImmutable {
		return current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 )->modify( '-' . self::RANGE_YEARS . ' years' );
	}

	private static function range_end(): DateTimeImmutable {
		return current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 )->modify( '+' . self::RANGE_YEARS . ' years' );
	}
}
