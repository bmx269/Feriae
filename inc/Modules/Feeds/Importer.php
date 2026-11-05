<?php
/**
 * Turns feed items into events.
 *
 * @package Feriae
 */

namespace Feriae\Modules\Feeds;

use DateTimeImmutable;
use Feriae\Content\Event;
use Feriae\Content\EventStatus;
use Feriae\Content\Meta;
use Feriae\Content\PostTypes;
use Feriae\Modules\Recurrence\Recurrence;
use Feriae\Modules\Recurrence\Rule;
use Feriae\Occurrences\Sync;

/**
 * Creates and updates events from feed items, matched by UID, so syncing
 * again updates rather than duplicates.
 *
 * - Repeating events keep their RRULE, RDATE, and EXDATE. A date the calendar
 *   cancelled becomes a skipped date; a date it moved or changed becomes its
 *   own event, and is skipped in the series.
 * - Events edited (or trashed) here are left alone from then on.
 * - Upcoming events that leave the calendar are unpublished (moved to draft),
 *   and published again if they come back.
 * - Past events are not imported, private ones never.
 */
final class Importer {

	/** Meta linking an event to its feed. */
	public const FEED     = '_feriae_feed';
	public const UID      = '_feriae_feed_uid';
	public const HASH     = '_feriae_feed_hash';
	public const SYNCED   = '_feriae_feed_synced';
	public const REMOVED  = '_feriae_feed_removed';
	public const META_KEYS = array( self::FEED, self::UID, self::HASH, self::SYNCED, self::REMOVED );

	private const COUNTS = array( 'created', 'updated', 'unchanged', 'removed', 'kept', 'private', 'past', 'unsupported', 'failed', 'skipped' );

	/** @var array<string, int> */
	private array $counts;

	/** @var array<string, int> Venue name => id, found or created this run. */
	private array $venues = array();

	/** @var array<string, object> Key => existing event row. */
	private array $existing = array();

	/** @var array<string, true> Keys present in this import. */
	private array $seen = array();

	private int $writes = 0;

	/**
	 * @param int    $feed        Feed id, or 0 for one-off file imports.
	 * @param string $post_type   Post type of new events.
	 * @param string $post_status Status of new events: publish or draft.
	 * @param int    $category    Event category for every event, or 0.
	 * @param int    $author      Author of new events and venues.
	 */
	public function __construct(
		private int $feed,
		private string $post_type,
		private string $post_status,
		private int $category,
		private int $author,
	) {
		$this->counts = array_fill_keys( self::COUNTS, 0 );
	}

	public static function for_feed( Feed $feed ): Importer {
		return new self( $feed->id(), $feed->post_type(), $feed->post_status(), $feed->category(), $feed->author() );
	}

	/**
	 * @param Item[] $items          Parsed items.
	 * @param bool   $remove_missing Unpublish upcoming events no longer in the items (feeds, not one-off files).
	 * @param int    $budget         Seconds to spend before stopping (0 = no limit); a later run continues.
	 * @return array<string, mixed> Counts, plus `partial` when the budget ran out.
	 */
	public function import( array $items, bool $remove_missing, int $budget = 0 ): array {
		$deadline       = $budget ? microtime( true ) + $budget : 0;
		$this->existing = $this->existing();

		$masters   = array();
		$overrides = array();
		foreach ( $items as $item ) {
			if ( $item->recurrence_id ) {
				$overrides[ $item->uid ][] = $item;
			} else {
				$masters[ $item->uid ] = $item;
			}
		}

		$queue = array();
		foreach ( $masters as $uid => $item ) {
			$skip = array();
			foreach ( $item->is_private() ? array() : ( $overrides[ $uid ] ?? array() ) as $override ) {
				$skip[] = $override->recurrence_id->format( 'Y-m-d' );
				if ( ! $override->is_cancelled() ) {
					$queue[] = array( self::override_key( $override ), $override, array() );
				}
			}
			unset( $overrides[ $uid ] );
			$queue[] = array( $uid, $item, $skip );
		}
		// Changed dates whose repeating event isn't in the calendar on its own.
		foreach ( $overrides as $list ) {
			foreach ( $list as $override ) {
				if ( ! $override->is_cancelled() ) {
					$queue[] = array( self::override_key( $override ), $override, array() );
				}
			}
		}

		$partial = false;
		foreach ( $queue as list( $key, $item, $skip ) ) {
			if ( $deadline && microtime( true ) > $deadline ) {
				$partial = true;
				break;
			}
			$this->seen[ $key ] = true;
			$this->save( $key, $item, $skip );
		}

		// Only a complete import knows what's gone.
		if ( $remove_missing && ! $partial ) {
			$this->remove_missing();
		}
		Sync::instance()->flush();

		return $this->counts + array( 'partial' => $partial );
	}

	/**
	 * @param string[] $skip Dates moved or cancelled on their own.
	 */
	private function save( string $key, Item $item, array $skip ): void {
		$row = $this->existing[ $key ] ?? null;
		if ( $item->is_private() ) {
			++$this->counts['private'];
			return;
		}
		if ( $row && ( 'trash' === $row->post_status || $this->edited( $row ) ) ) {
			++$this->counts['kept'];
			return;
		}

		$recurrence = $this->recurrence( $item, $skip );
		if ( ! $row && self::is_past( $item, $recurrence ) ) {
			++$this->counts['past'];
			return;
		}

		$url      = esc_url_raw( $item->url, array( 'http', 'https' ) );
		$location = $item->location;
		if ( preg_match( '#^https?://\S+$#i', $location ) ) {
			// Online events put their meeting link in LOCATION.
			$url      = '' !== $url ? $url : esc_url_raw( $location, array( 'http', 'https' ) );
			$location = '';
		}

		$title   = sanitize_text_field( $item->summary );
		$content = self::content( $item->description );
		$meta    = array(
			'_feriae_start'            => $item->start->format( Meta::DATETIME_FORMAT ),
			'_feriae_end'              => $item->end->format( Meta::DATETIME_FORMAT ),
			'_feriae_timezone'         => $item->timezone(),
			'_feriae_all_day'          => $item->all_day ? '1' : '',
			'_feriae_url'              => $url,
			'_feriae_status'           => $item->is_cancelled() ? EventStatus::CANCELLED : EventStatus::SCHEDULED,
			Recurrence::META_KEY   => $recurrence ?? Recurrence::empty_meta(),
		);
		$hash    = md5( (string) wp_json_encode( array( $title, $content, $meta, $location, $item->lat, $item->lng, $this->category, $this->post_type ) ) );

		if ( $row && $hash === $row->hash && ! $row->removed ) {
			++$this->counts['unchanged'];
			return;
		}

		$post = array(
			'post_title'   => '' !== $title ? $title : __( '(No title)', 'feriae' ),
			'post_content' => $content,
		);
		if ( $row ) {
			$post['ID'] = $row->ID;
			if ( $row->removed ) {
				$post['post_status'] = $this->post_status; // Back in the calendar.
			}
			$id = wp_update_post( wp_slash( $post ), true );
		} else {
			$id = wp_insert_post(
				wp_slash(
					$post + array(
						'post_type'   => $this->post_type,
						'post_status' => $this->post_status,
						'post_author' => $this->author,
					)
				),
				true
			);
		}
		if ( is_wp_error( $id ) || ! $id ) {
			++$this->counts['failed'];
			return;
		}

		$meta['_feriae_venue_id'] = $this->venue( $location, $item->lat, $item->lng );
		foreach ( $meta as $meta_key => $value ) {
			update_post_meta( $id, $meta_key, $value );
		}
		if ( $this->category && is_object_in_taxonomy( $this->post_type, PostTypes::CATEGORY ) ) {
			wp_set_object_terms( $id, array( $this->category ), PostTypes::CATEGORY, true );
		}
		if ( ! $row ) {
			update_post_meta( $id, self::FEED, $this->feed );
			update_post_meta( $id, self::UID, $key );
		}
		update_post_meta( $id, self::HASH, $hash );
		delete_post_meta( $id, self::REMOVED );
		$this->stamp( $id );

		++$this->counts[ $row ? 'updated' : 'created' ];
		if ( 0 === ++$this->writes % 100 ) {
			Sync::instance()->flush(); // Keep the pending list short on big imports.
		}
	}

	/**
	 * Unpublishes upcoming events that left the calendar. Past ones stay, as
	 * calendars often drop old events.
	 */
	private function remove_missing(): void {
		$now = new DateTimeImmutable( 'now' );
		foreach ( $this->existing as $key => $row ) {
			if ( isset( $this->seen[ $key ] ) || ! in_array( $row->post_status, array( 'publish', 'future' ), true ) || $this->edited( $row ) ) {
				continue;
			}
			$event = Event::get( (int) $row->ID );
			if ( ! $event || ( ! Recurrence::from_meta( $event->meta( Recurrence::META_KEY ) ) && $event->end() && $event->end() < $now ) ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'          => $row->ID,
					'post_status' => 'draft',
				)
			);
			update_post_meta( $row->ID, self::REMOVED, 1 );
			$this->stamp( (int) $row->ID );
			++$this->counts['removed'];
		}
	}

	/**
	 * Records the post's modified time after our write; any later change is an edit made here.
	 */
	private function stamp( int $id ): void {
		clean_post_cache( $id );
		update_post_meta( $id, self::SYNCED, (string) get_post_field( 'post_modified_gmt', $id ) );
	}

	private function edited( object $row ): bool {
		return '' !== $row->synced && $row->synced !== $row->post_modified_gmt;
	}

	/**
	 * `_feriae_recurrence` for an item, or null for a single date. Rules outside
	 * what Feriae can repeat are dropped (the first date is still
	 * imported) and counted, rather than guessed at.
	 *
	 * @param string[] $skip Extra skipped dates.
	 * @return array{rrule: string, rdates: string[], exdates: string[]}|null
	 */
	private function recurrence( Item $item, array $skip ): ?array {
		$rrule = '';
		if ( '' !== $item->rrule ) {
			try {
				$rrule = (string) Rule::parse( $item->rrule );
			} catch ( \InvalidArgumentException ) {
				++$this->counts['unsupported'];
			}
		}
		if ( '' === $rrule && ! $item->rdates ) {
			return null;
		}
		return array(
			'rrule'   => $rrule,
			'rdates'  => $item->rdates,
			'exdates' => array_values( array_unique( array_merge( $item->exdates, $skip ) ) ),
		);
	}

	/**
	 * Whether every date of the item has ended.
	 *
	 * @param array{rrule: string, rdates: string[], exdates: string[]}|null $recurrence Recurrence meta.
	 */
	private static function is_past( Item $item, ?array $recurrence ): bool {
		$now    = new DateTimeImmutable( 'now', $item->start->getTimezone() );
		$length = $item->end->getTimestamp() - $item->start->getTimestamp() + ( $item->all_day ? DAY_IN_SECONDS : 0 );
		if ( $item->start->getTimestamp() + $length > $now->getTimestamp() ) {
			return false;
		}
		$repeat = $recurrence ? Recurrence::from_meta( $recurrence ) : null;
		if ( ! $repeat ) {
			return true;
		}
		if ( $repeat->is_open_ended() ) {
			return false;
		}
		$max    = ( $repeat->rule?->count ?? 5000 ) + count( $repeat->rdates ) + 1;
		$starts = $repeat->starts( $item->start, $now->modify( '+50 years' ), $max );
		$last   = end( $starts );
		return ! $last || $last->getTimestamp() + $length <= $now->getTimestamp();
	}

	/**
	 * A venue for the location: one with the same name, else a new one.
	 * "Central Library, 350 W Georgia St" becomes a venue named "Central
	 * Library" at "350 W Georgia St"; a bare address names itself.
	 */
	private function venue( string $location, ?float $lat, ?float $lng ): int {
		if ( '' === $location ) {
			return 0;
		}
		$parts   = array_map( 'trim', explode( ',', $location, 2 ) );
		$named   = isset( $parts[1] ) && '' !== $parts[0] && ! preg_match( '/^\d/', $parts[0] );
		$name    = mb_substr( sanitize_text_field( $named ? $parts[0] : $location ), 0, 200 );
		$address = $named ? sanitize_text_field( $parts[1] ) : '';
		if ( '' === $name ) {
			return 0;
		}
		if ( isset( $this->venues[ $name ] ) ) {
			return $this->venues[ $name ];
		}

		$found = get_posts(
			array(
				'post_type'        => PostTypes::VENUE,
				'post_status'      => 'publish',
				'title'            => $name,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		if ( $found ) {
			return $this->venues[ $name ] = (int) $found[0];
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => PostTypes::VENUE,
					'post_status' => 'publish',
					'post_title'  => $name,
					'post_author' => $this->author,
					'meta_input'  => array_filter(
						array(
							'_feriae_address' => $address,
							'_feriae_lat'     => null === $lat ? '' : (string) $lat,
							'_feriae_lng'     => null === $lng ? '' : (string) $lng,
						),
						'strlen'
					),
				)
			),
			true
		);
		return $this->venues[ $name ] = is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Post content from a DESCRIPTION. Calendars send plain text (made into
	 * paragraphs, links clickable) or HTML (Google does), which is limited to
	 * what a post may contain.
	 */
	public static function content( string $description ): string {
		if ( '' === trim( $description ) ) {
			return '';
		}
		if ( wp_strip_all_tags( $description ) !== $description ) {
			// Scripts and styles go with their contents; kses keeps the text of tags it removes.
			$html = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $description );
			return make_clickable( wpautop( wp_kses_post( $html ) ) );
		}
		$blocks = array();
		foreach ( preg_split( '/\n\s*\n/', $description ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' !== $paragraph ) {
				$blocks[] = "<!-- wp:paragraph -->\n<p>" . make_clickable( nl2br( esc_html( $paragraph ), false ) ) . "</p>\n<!-- /wp:paragraph -->";
			}
		}
		return implode( "\n\n", $blocks );
	}

	private static function override_key( Item $item ): string {
		return $item->uid . '@' . $item->recurrence_id?->format( 'Ymd\THis' );
	}

	/**
	 * Events this feed made, by key, with what decides whether to touch them.
	 *
	 * @return array<string, object{ID: int, post_status: string, post_modified_gmt: string, hash: string, synced: string, removed: bool}>
	 */
	private function existing(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one query for the whole feed.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.ID, p.post_status, p.post_modified_gmt, u.meta_value AS uid
				FROM %i p
				JOIN %i f ON f.post_id = p.ID AND f.meta_key = %s AND f.meta_value = %s
				JOIN %i u ON u.post_id = p.ID AND u.meta_key = %s',
				$wpdb->posts,
				$wpdb->postmeta,
				self::FEED,
				(string) $this->feed,
				$wpdb->postmeta,
				self::UID
			)
		);
		update_meta_cache( 'post', array_map( static fn( $row ) => (int) $row->ID, (array) $rows ) );

		$existing = array();
		foreach ( (array) $rows as $row ) {
			$row->ID                    = (int) $row->ID;
			$row->hash                  = (string) get_post_meta( $row->ID, self::HASH, true );
			$row->synced                = (string) get_post_meta( $row->ID, self::SYNCED, true );
			$row->removed               = (bool) get_post_meta( $row->ID, self::REMOVED, true );
			$existing[ (string) $row->uid ] = $row;
		}
		return $existing;
	}

	/**
	 * Ids of every event a feed created (any status).
	 *
	 * @return int[]
	 */
	public static function event_ids( int $feed ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, self::FEED, (string) $feed ) ) );
	}

	/**
	 * Events per feed, not counting the trash.
	 *
	 * @return array<int, int> Feed id => events.
	 */
	public static function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.meta_value AS feed, COUNT(*) AS events FROM %i m JOIN %i p ON p.ID = m.post_id
				WHERE m.meta_key = %s AND p.post_status NOT IN (%s, %s) GROUP BY m.meta_value',
				$wpdb->postmeta,
				$wpdb->posts,
				self::FEED,
				'trash',
				'auto-draft'
			)
		);
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->feed ] = (int) $row->events;
		}
		return $counts;
	}

	/**
	 * A complete report: counts (zero when missing), ok, time, and a sentence.
	 *
	 * @param array<string, mixed> $result Partial report.
	 * @return array<string, mixed>
	 */
	public static function report( array $result ): array {
		$report = array_merge(
			array_fill_keys( self::COUNTS, 0 ),
			array(
				'ok'           => true,
				'error'        => '',
				'partial'      => false,
				'not_modified' => false,
				'time'         => gmdate( 'Y-m-d H:i:s' ),
			),
			$result
		);
		$report['message'] = self::message( $report );
		return $report;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function failure( string $error ): array {
		return self::report(
			array(
				'ok'    => false,
				'error' => $error,
			)
		);
	}

	/**
	 * What happened, in a sentence or two.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	public static function message( array $report ): string {
		if ( ! $report['ok'] ) {
			return (string) $report['error'];
		}
		if ( $report['not_modified'] ) {
			return __( 'No changes since the last sync.', 'feriae' );
		}
		$parts = array();
		$say   = array(
			/* translators: %s: number of events */
			'created'     => _n_noop( '%s event added', '%s events added', 'feriae' ),
			/* translators: %s: number of events */
			'updated'     => _n_noop( '%s updated', '%s updated', 'feriae' ),
			/* translators: %s: number of events */
			'removed'     => _n_noop( '%s unpublished (no longer in the calendar)', '%s unpublished (no longer in the calendar)', 'feriae' ),
			/* translators: %s: number of events */
			'kept'        => _n_noop( '%s left as edited here', '%s left as edited here', 'feriae' ),
			/* translators: %s: number of events */
			'unchanged'   => _n_noop( '%s unchanged', '%s unchanged', 'feriae' ),
			/* translators: %s: number of events */
			'private'     => _n_noop( '%s private event not imported', '%s private events not imported', 'feriae' ),
			/* translators: %s: number of events */
			'past'        => _n_noop( '%s past event not imported', '%s past events not imported', 'feriae' ),
			/* translators: %s: number of events */
			'unsupported' => _n_noop( '%s repeat rule not supported (first date imported)', '%s repeat rules not supported (first dates imported)', 'feriae' ),
			/* translators: %s: number of events */
			'failed'      => _n_noop( '%s couldn’t be saved', '%s couldn’t be saved', 'feriae' ),
			/* translators: %s: number of events */
			'skipped'     => _n_noop( '%s unreadable event skipped', '%s unreadable events skipped', 'feriae' ),
		);
		foreach ( $say as $key => $noop ) {
			$count = (int) $report[ $key ];
			if ( $count ) {
				$parts[] = sprintf( translate_nooped_plural( $noop, $count, 'feriae' ), number_format_i18n( $count ) );
			}
		}
		$message = $parts ? wp_sprintf( '%l.', $parts ) : __( 'The calendar has no events to import.', 'feriae' );
		if ( $report['partial'] ) {
			$message .= ' ' . ( 'file' === ( $report['source'] ?? '' )
				? __( 'Import the file again to continue.', 'feriae' )
				: __( 'The rest will be imported in a minute.', 'feriae' ) );
		}
		return $message;
	}
}
