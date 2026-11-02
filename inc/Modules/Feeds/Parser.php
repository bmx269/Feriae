<?php
/**
 * RFC 5545 reader.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Feeds;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the VEVENTs of an iCalendar document (Google Calendar, Outlook,
 * Apple Calendar, Meetup, another OpenEvents or TEC site).
 *
 * Times keep the zone they were written in (TZID, including Outlook's Windows
 * names and Mozilla-style prefixed ids); UTC and floating times use the
 * calendar's X-WR-TIMEZONE, else the site's. Alarms, attendees, and organizers
 * are ignored: a feed can name people, and none of that is published.
 */
final class Parser {

	/** Events read from one document at most. */
	public const MAX_EVENTS = 5000;

	/** Outlook writes Windows zone names; these are the common ones (intl covers the rest). */
	private const WINDOWS_ZONES = array(
		'Hawaiian Standard Time'         => 'Pacific/Honolulu',
		'Alaskan Standard Time'          => 'America/Anchorage',
		'Pacific Standard Time'          => 'America/Los_Angeles',
		'US Mountain Standard Time'      => 'America/Phoenix',
		'Mountain Standard Time'         => 'America/Denver',
		'Central Standard Time'          => 'America/Chicago',
		'Eastern Standard Time'          => 'America/New_York',
		'Atlantic Standard Time'         => 'America/Halifax',
		'Newfoundland Standard Time'     => 'America/St_Johns',
		'E. South America Standard Time' => 'America/Sao_Paulo',
		'GMT Standard Time'              => 'Europe/London',
		'W. Europe Standard Time'        => 'Europe/Berlin',
		'Romance Standard Time'          => 'Europe/Paris',
		'Central Europe Standard Time'   => 'Europe/Budapest',
		'South Africa Standard Time'     => 'Africa/Johannesburg',
		'India Standard Time'            => 'Asia/Kolkata',
		'China Standard Time'            => 'Asia/Shanghai',
		'Singapore Standard Time'        => 'Asia/Singapore',
		'Tokyo Standard Time'            => 'Asia/Tokyo',
		'AUS Eastern Standard Time'      => 'Australia/Sydney',
		'New Zealand Standard Time'      => 'Pacific/Auckland',
		'UTC'                            => 'UTC',
	);

	/** Calendar name (X-WR-CALNAME), once parsed. */
	public string $name = '';

	/** VEVENTs left out: no usable start, or past MAX_EVENTS. */
	public int $skipped = 0;

	/** @var array<string, string> VTIMEZONE TZID => X-LIC-LOCATION. */
	private array $aliases = array();

	/** @var array<string, true>|null Zone ids PHP knows, including old aliases. */
	private static ?array $known = null;

	/** @var array<string, true>|null Current zone ids (what `_oe_timezone` accepts). */
	private static ?array $canonical = null;

	private DateTimeZone $default;

	public function __construct( ?DateTimeZone $default = null ) {
		$this->default = $default ?? wp_timezone();
	}

	public static function is_calendar( string $text ): bool {
		return 1 === preg_match( '/^\s*BEGIN:VCALENDAR\s*$/mi', substr( $text, 0, 4096 ) );
	}

	/**
	 * @return Item[]
	 */
	public function parse( string $ics ): array {
		$ics = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $ics );
		$ics = str_replace( array( "\r\n", "\r" ), "\n", $ics );
		$ics = (string) preg_replace( '/\n[ \t]/', '', $ics ); // Unfold (§3.1).

		$stack  = array();
		$events = array();
		$props  = null;
		$tzid   = '';
		foreach ( explode( "\n", $ics ) as $raw ) {
			$line = self::line( $raw );
			if ( null === $line ) {
				continue;
			}
			list( $name, $params, $value ) = $line;

			if ( 'BEGIN' === $name ) {
				$stack[] = strtoupper( trim( $value ) );
				if ( array( 'VCALENDAR', 'VEVENT' ) === $stack ) {
					$props = array();
				}
				continue;
			}
			if ( 'END' === $name ) {
				if ( array( 'VCALENDAR', 'VEVENT' ) === $stack && null !== $props ) {
					if ( count( $events ) < self::MAX_EVENTS ) {
						$events[] = $props;
					} else {
						++$this->skipped;
					}
					$props = null;
				}
				array_pop( $stack );
				continue;
			}

			switch ( end( $stack ) ) {
				case 'VEVENT':
					if ( null !== $props && 2 === count( $stack ) ) {
						$props[ $name ][] = array( $params, $value );
					}
					break;
				case 'VTIMEZONE':
					if ( 'TZID' === $name ) {
						$tzid = trim( $value );
					} elseif ( 'X-LIC-LOCATION' === $name && '' !== $tzid ) {
						$this->aliases[ $tzid ] = trim( $value );
					}
					break;
				case 'VCALENDAR':
					if ( 'X-WR-CALNAME' === $name ) {
						$this->name = self::text( $value, 200 );
					} elseif ( 'X-WR-TIMEZONE' === $name ) {
						$zone          = $this->zone( $value );
						$this->default = $zone && self::is_canonical( $zone ) ? $zone : $this->default;
					}
					break;
			}
		}

		$items = array();
		foreach ( $events as $event ) {
			$item = $this->item( $event );
			if ( $item ) {
				$items[] = $item;
			} else {
				++$this->skipped;
			}
		}
		return $items;
	}

	/**
	 * @param array<string, array<int, array{0: array<string, string>, 1: string}>> $props Property name => [params, value] list.
	 */
	private function item( array $props ): ?Item {
		$first = static fn( string $name ): ?array => $props[ $name ][0] ?? null;

		$dtstart = $first( 'DTSTART' );
		$start   = $dtstart ? $this->time( $dtstart[1], $dtstart[0] ) : null;
		if ( ! $start ) {
			return null;
		}
		list( $start, $all_day ) = $start;
		if ( ! self::is_canonical( $start->getTimezone() ) ) {
			// Stored zones must be canonical names; the instant stays right.
			$start = $start->setTimezone( $this->default );
		}
		$tz = $start->getTimezone();

		$end = null;
		if ( $first( 'DTEND' ) ) {
			$end = $this->time( $first( 'DTEND' )[1], $first( 'DTEND' )[0], $tz )[0] ?? null;
		} elseif ( $first( 'DURATION' ) ) {
			$end = self::add_duration( $start, $first( 'DURATION' )[1] );
		}
		if ( $all_day ) {
			// DTEND is exclusive for dates: an event on the 3rd ends on the 4th.
			$end = $end ? $end->setTime( 0, 0 )->modify( '-1 day' ) : $start;
		}
		$end = ( $end && $end >= $start ) ? $end : $start;

		$categories = array();
		foreach ( $props['CATEGORIES'] ?? array() as $prop ) {
			foreach ( preg_split( '/(?<!\\\\),/', $prop[1] ) as $category ) {
				$category = self::text( $category, 200 );
				if ( '' !== $category ) {
					$categories[] = $category;
				}
			}
		}

		$rdates  = array();
		$exdates = array();
		foreach ( array( 'RDATE', 'EXDATE' ) as $name ) {
			foreach ( $props[ $name ] ?? array() as $prop ) {
				foreach ( explode( ',', $prop[1] ) as $value ) {
					$date = $this->time( explode( '/', $value )[0], $prop[0], $tz )[0] ?? null; // VALUE=PERIOD: start/end.
					if ( ! $date ) {
						continue;
					}
					if ( 'RDATE' === $name ) {
						$rdates[] = ( $all_day ? $date->setTime( 0, 0 ) : $date )->format( 'Y-m-d H:i:s' );
					} else {
						$exdates[] = $date->format( 'Y-m-d' );
					}
				}
			}
		}

		$recurrence_id = $first( 'RECURRENCE-ID' ) ? ( $this->time( $first( 'RECURRENCE-ID' )[1], $first( 'RECURRENCE-ID' )[0], $tz )[0] ?? null ) : null;

		$lat = null;
		$lng = null;
		if ( $first( 'GEO' ) && preg_match( '/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[;,]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $first( 'GEO' )[1], $m ) && abs( (float) $m[1] ) <= 90 && abs( (float) $m[2] ) <= 180 ) {
			$lat = (float) $m[1];
			$lng = (float) $m[2];
		}

		$summary = self::text( $first( 'SUMMARY' )[1] ?? '', 500 );
		$uid     = self::text( $first( 'UID' )[1] ?? '', 255 );

		return new Item(
			uid: '' !== $uid ? $uid : 'oe-' . md5( $start->format( 'c' ) . $summary ),
			start: $start,
			end: $end,
			all_day: $all_day,
			summary: $summary,
			description: self::text( $first( 'DESCRIPTION' )[1] ?? '', 100000 ),
			location: self::text( $first( 'LOCATION' )[1] ?? '', 500 ),
			url: trim( $first( 'URL' )[1] ?? '' ),
			categories: array_values( array_unique( $categories ) ),
			status: strtoupper( trim( $first( 'STATUS' )[1] ?? '' ) ),
			class: strtoupper( trim( $first( 'CLASS' )[1] ?? '' ) ) ?: 'PUBLIC',
			rrule: trim( $first( 'RRULE' )[1] ?? '' ),
			rdates: array_values( array_unique( $rdates ) ),
			exdates: array_values( array_unique( $exdates ) ),
			recurrence_id: $recurrence_id,
			lat: $lat,
			lng: $lng,
		);
	}

	/**
	 * A DATE or DATE-TIME value, and whether it is a date.
	 *
	 * @param array<string, string> $params Property parameters (TZID, VALUE).
	 * @param DateTimeZone|null     $into   Convert to this zone (DTEND and friends follow DTSTART's zone).
	 * @return array{0: DateTimeImmutable, 1: bool}|null
	 */
	private function time( string $value, array $params, ?DateTimeZone $into = null ): ?array {
		$value = strtoupper( trim( $value ) );
		if ( preg_match( '/^\d{8}$/', $value ) || ( 'DATE' === strtoupper( $params['VALUE'] ?? '' ) && preg_match( '/^\d{8}/', $value ) ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Ymd', substr( $value, 0, 8 ), $into ?? $this->default );
			return $date && $date->format( 'Ymd' ) === substr( $value, 0, 8 ) ? array( $date, true ) : null;
		}
		if ( ! preg_match( '/^(\d{8}T\d{6})(Z?)$/', $value, $m ) ) {
			return null;
		}
		$zone = 'Z' === $m[2] ? new DateTimeZone( 'UTC' ) : ( $this->zone( $params['TZID'] ?? '' ) ?? $into ?? $this->default );
		$date = DateTimeImmutable::createFromFormat( '!Ymd\THis', $m[1], $zone );
		if ( ! $date || $date->format( 'Ymd\THis' ) !== $m[1] ) {
			return null;
		}
		$target = $into ?? ( 'Z' === $m[2] ? $this->default : null );
		return array( $target ? $date->setTimezone( $target ) : $date, false );
	}

	private function zone( string $tzid ): ?DateTimeZone {
		$tzid = trim( $tzid, " \t\"" );
		if ( '' === $tzid ) {
			return null;
		}
		$candidates = array( $tzid, $this->aliases[ $tzid ] ?? '', self::WINDOWS_ZONES[ $tzid ] ?? '' );
		if ( class_exists( 'IntlTimeZone' ) ) {
			$candidates[] = (string) \IntlTimeZone::getIDForWindowsID( $tzid );
		}
		if ( preg_match( '#([A-Za-z_]+/[A-Za-z_+\-]+(?:/[A-Za-z_+\-]+)?)$#', $tzid, $m ) ) {
			$candidates[] = $m[1]; // "/mozilla.org/20050126_1/America/New_York".
		}
		self::$known ??= array_fill_keys( DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true );
		foreach ( array_filter( $candidates ) as $name ) {
			if ( isset( self::$known[ $name ] ) ) {
				return new DateTimeZone( $name );
			}
		}
		return null;
	}

	private static function is_canonical( DateTimeZone $zone ): bool {
		self::$canonical ??= array_fill_keys( DateTimeZone::listIdentifiers(), true );
		return isset( self::$canonical[ $zone->getName() ] );
	}

	/**
	 * Start plus a positive DURATION such as PT1H30M or P1D.
	 */
	private static function add_duration( DateTimeImmutable $start, string $value ): ?DateTimeImmutable {
		if ( ! preg_match( '/^\+?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper( trim( $value ) ), $m ) ) {
			return null;
		}
		$days    = 7 * (int) ( $m[1] ?? 0 ) + (int) ( $m[2] ?? 0 );
		$seconds = 3600 * (int) ( $m[3] ?? 0 ) + 60 * (int) ( $m[4] ?? 0 ) + (int) ( $m[5] ?? 0 );
		return $start->add( new DateInterval( sprintf( 'P%dDT%dS', $days, $seconds ) ) );
	}

	/**
	 * Splits a content line into name, parameters, and value; colons and
	 * semicolons inside quoted parameter values don't count.
	 *
	 * @return array{0: string, 1: array<string, string>, 2: string}|null
	 */
	private static function line( string $raw ): ?array {
		$quoted = false;
		$length = strlen( $raw );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( '"' === $raw[ $i ] ) {
				$quoted = ! $quoted;
			} elseif ( ':' === $raw[ $i ] && ! $quoted ) {
				break;
			}
		}
		if ( $i >= $length ) {
			return null;
		}
		$parts = preg_split( '/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', substr( $raw, 0, $i ) );
		$name  = strtoupper( trim( (string) array_shift( $parts ) ) );
		if ( ! preg_match( '/^[A-Z0-9-]+$/', $name ) ) {
			return null;
		}
		$params = array();
		foreach ( $parts as $part ) {
			if ( str_contains( $part, '=' ) ) {
				list( $key, $value )                   = explode( '=', $part, 2 );
				$params[ strtoupper( trim( $key ) ) ] = trim( $value, '"' );
			}
		}
		return array( $name, $params, substr( $raw, $i + 1 ) );
	}

	/**
	 * Unescapes a TEXT value (§3.3.11), drops invalid UTF-8, and caps its length.
	 */
	private static function text( string $value, int $max ): string {
		$value = (string) preg_replace_callback(
			'/\\\\(.)/s',
			static fn( array $m ): string => 'n' === $m[1] || 'N' === $m[1] ? "\n" : $m[1],
			$value
		);
		return trim( mb_substr( wp_check_invalid_utf8( $value, true ), 0, $max ) );
	}
}
