<?php
/**
 * Calendar import: iCal parsing, feed syncs, and one-off file imports.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use OpenEvents\Content\PostTypes;
use OpenEvents\Modules\Feeds\Feed;
use OpenEvents\Modules\Feeds\Importer;
use OpenEvents\Modules\Feeds\Parser;
use OpenEvents\Modules\Recurrence\Recurrence;
use OpenEvents\Tests\TestCase;

final class FeedsTest extends TestCase {

	private const URL = 'https://calendar.google.com/calendar/ical/team%40example.com/private-0123456789abcdef/basic.ics';

	/** What the mocked calendar server returns. */
	private string $body = '';

	private int $code = 200;

	/** @var array<int, array<string, mixed>> Requests made. */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'pre_http_request', array( $this, 'respond' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'respond' ) );
		parent::tear_down();
	}

	/**
	 * The calendar server: answers 304 when asked for the version it has.
	 *
	 * @param false|array<string, mixed> $pre  Short-circuit value.
	 * @param array<string, mixed>       $args Request arguments.
	 * @return array<string, mixed>
	 */
	public function respond( $pre, array $args, string $url ): array {
		$this->requests[] = $args + array( 'url' => $url );
		$etag             = '"' . md5( $this->body ) . '"';
		$unchanged        = ( $args['headers']['If-None-Match'] ?? '' ) === $etag;
		return array(
			'headers'  => array( 'etag' => $etag ),
			'body'     => $unchanged ? '' : $this->body,
			'response' => array(
				'code'    => $unchanged ? 304 : $this->code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function day( int $offset, string $format = 'Ymd' ): string {
		return ( new DateTimeImmutable( "today +{$offset} days", new DateTimeZone( 'America/Toronto' ) ) )->format( $format );
	}

	/**
	 * A calendar like Google's export: folded lines, escaped text, TZIDs.
	 *
	 * @param string[] $events VEVENT bodies.
	 */
	private static function calendar( array $events ): string {
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Google Inc//Google Calendar 70.9054//EN', 'X-WR-CALNAME:Team events', 'X-WR-TIMEZONE:America/Toronto' );
		foreach ( $events as $event ) {
			$lines[] = "BEGIN:VEVENT\r\n" . trim( $event ) . "\r\nEND:VEVENT";
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * @return string[] The usual calendar: a talk, a weekly meetup with a cancelled
	 *                  and a moved date, an all-day fair, a private event, a past one.
	 */
	private static function events( string $talk = 'Planning talk' ): array {
		$talk_day = self::day( 10 );
		$weekly   = self::day( 7 );
		$second   = self::day( 14 );
		$third    = self::day( 21 );
		return array(
			"UID:talk-1@google.com\r\nDTSTART;TZID=America/Toronto:{$talk_day}T190000\r\nDTEND;TZID=America/Toronto:{$talk_day}T203000\r\nSUMMARY:{$talk}\r\nDESCRIPTION:Bring questions\\, and friends.\\n\\nMore at https://example.org/talk<script>alert(1)</script>\r\nLOCATION:Central Library\\, 350 W Georgia St\\, Vancouver\r\nGEO:49.2797;-123.1157\r\nURL:https://example.org/talk\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder that must not be the description\r\nTRIGGER:-PT10M\r\nEND:VALARM",
			"UID:weekly@google.com\r\nDTSTART;TZID=America/Toronto:{$weekly}T180000\r\nDURATION:PT2H\r\nRRULE:FREQ=WEEKLY;COUNT=6\r\nSUMMARY:Weekly meetup\r\nLOCATION:https://meet.example.com/abc",
			"UID:weekly@google.com\r\nRECURRENCE-ID;TZID=America/Toronto:{$second}T180000\r\nDTSTART;TZID=America/Toronto:{$second}T180000\r\nDTEND;TZID=America/Toronto:{$second}T200000\r\nSTATUS:CANCELLED\r\nSUMMARY:Weekly meetup",
			"UID:weekly@google.com\r\nRECURRENCE-ID;TZID=America/Toronto:{$third}T180000\r\nDTSTART;TZID=America/Toronto:{$third}T190000\r\nDTEND;TZID=America/Toronto:{$third}T210000\r\nSUMMARY:Weekly meetup (late start)",
			"UID:fair@google.com\r\nDTSTART;VALUE=DATE:" . self::day( 30 ) . "\r\nDTEND;VALUE=DATE:" . self::day( 32 ) . "\r\nSUMMARY:Spring fair",
			"UID:secret@google.com\r\nDTSTART:" . self::day( 5 ) . "T150000Z\r\nDTEND:" . self::day( 5 ) . "T160000Z\r\nCLASS:PRIVATE\r\nSUMMARY:Doctor",
			"UID:old@google.com\r\nDTSTART:" . self::day( -30 ) . "T150000Z\r\nDTEND:" . self::day( -30 ) . "T160000Z\r\nSUMMARY:Last month",
		);
	}

	/**
	 * @return array<string, \WP_Post> Imported events by title.
	 */
	private function imported( Feed $feed ): array {
		$posts = array();
		foreach ( Importer::event_ids( $feed->id() ) as $id ) {
			$post                       = get_post( $id );
			$posts[ $post->post_title ] = $post;
		}
		ksort( $posts );
		return $posts;
	}

	private function feed( array $options = array() ): Feed {
		$feed = Feed::create( $options + array( 'url' => self::URL ) );
		$this->assertInstanceOf( Feed::class, $feed );
		return $feed;
	}

	public function test_parser_reads_calendars_from_different_apps(): void {
		$day    = self::day( 3 );
		$ics    = self::calendar(
			array(
				"UID:a\r\nDTSTART:{$day}T170000Z\r\nDTEND:{$day}T180000Z\r\nSUMMARY:A long title that is folded across\r\n  two lines\r\nCATEGORIES:Talks,Kids\\, families\r\nATTENDEE;CN=\"Doe: Jane\";ROLE=REQ-PARTICIPANT:mailto:jane@example.com",
				"UID:b\r\nDTSTART;TZID=Pacific Standard Time:{$day}T090000\r\nDTEND;TZID=Pacific Standard Time:{$day}T100000\r\nSUMMARY:From Outlook",
				"UID:c\r\nDTSTART;TZID=/mozilla.org/20050126_1/Europe/Paris:{$day}T090000\r\nSUMMARY:From Thunderbird",
				"UID:d\r\nDTSTART;VALUE=DATE:{$day}\r\nSUMMARY:One day",
				"UID:e\r\nSUMMARY:No start",
			)
		);
		$parser = new Parser();
		$items  = $parser->parse( $ics );

		$this->assertSame( 'Team events', $parser->name );
		$this->assertSame( 1, $parser->skipped, 'The event without a start.' );
		$this->assertCount( 4, $items );

		list( $utc, $outlook, $mozilla, $day_only ) = $items;
		$this->assertSame( 'A long title that is folded across two lines', $utc->summary );
		$this->assertSame( array( 'Talks', 'Kids, families' ), $utc->categories );
		$this->assertSame( 'America/Toronto', $utc->timezone(), 'UTC times use the calendar’s zone.' );
		$this->assertSame( ( new DateTimeImmutable( "{$day}T170000Z" ) )->setTimezone( new DateTimeZone( 'America/Toronto' ) )->format( 'H:i' ), $utc->start->format( 'H:i' ) );

		$this->assertSame( 'America/Los_Angeles', $outlook->timezone() );
		$this->assertSame( '09:00', $outlook->start->format( 'H:i' ) );
		$this->assertSame( 'Europe/Paris', $mozilla->timezone() );
		$this->assertEquals( $mozilla->start, $mozilla->end, 'No end: same as the start.' );

		$this->assertTrue( $day_only->all_day );
		$this->assertSame( $day_only->start->format( 'Y-m-d' ), $day_only->end->format( 'Y-m-d' ), 'A one-day event ends that day.' );
	}

	public function test_sync_imports_a_calendar(): void {
		$category   = self::factory()->term->create( array( 'taxonomy' => PostTypes::CATEGORY, 'name' => 'Team' ) );
		$this->body = self::calendar( self::events() );
		$feed       = $this->feed( array( 'category' => $category ) );
		$report     = $feed->sync();

		$this->assertTrue( $report['ok'], $report['message'] );
		$this->assertSame( 4, $report['created'], $report['message'] );
		$this->assertSame( 1, $report['private'] );
		$this->assertSame( 1, $report['past'] );
		$this->assertStringContainsString( '4 events added', $report['message'] );
		$this->assertStringContainsString( 'team%40example.com', $this->requests[0]['url'] );
		$this->assertTrue( $this->requests[0]['reject_unsafe_urls'], 'Fetched with wp_safe_remote_get().' );

		$events = $this->imported( $feed );
		$this->assertSame( array( 'Planning talk', 'Spring fair', 'Weekly meetup', 'Weekly meetup (late start)' ), array_keys( $events ) );

		$talk = $events['Planning talk'];
		$this->assertSame( 'publish', $talk->post_status );
		$this->assertSame( self::day( 10, 'Y-m-d' ) . ' 19:00:00', get_post_meta( $talk->ID, '_oe_start', true ) );
		$this->assertSame( 'America/Toronto', get_post_meta( $talk->ID, '_oe_timezone', true ) );
		$this->assertSame( 'https://example.org/talk', get_post_meta( $talk->ID, '_oe_url', true ) );
		$this->assertStringContainsString( 'Bring questions, and friends.', $talk->post_content );
		$this->assertStringContainsString( '<a href="https://example.org/talk', $talk->post_content );
		$this->assertStringNotContainsString( '<script', $talk->post_content );
		$this->assertStringNotContainsString( 'alert', $talk->post_content );
		$this->assertStringNotContainsString( 'Reminder', $talk->post_content, 'Alarm text is ignored.' );
		$this->assertSame( array( $category ), wp_get_post_terms( $talk->ID, PostTypes::CATEGORY, array( 'fields' => 'ids' ) ) );

		$venue = get_post( (int) get_post_meta( $talk->ID, '_oe_venue_id', true ) );
		$this->assertSame( 'Central Library', $venue->post_title );
		$this->assertSame( '350 W Georgia St, Vancouver', get_post_meta( $venue->ID, '_oe_address', true ) );
		$this->assertSame( '49.2797', get_post_meta( $venue->ID, '_oe_lat', true ) );

		$weekly = $events['Weekly meetup'];
		$this->assertSame( 'https://meet.example.com/abc', get_post_meta( $weekly->ID, '_oe_url', true ), 'An online link in LOCATION.' );
		$this->assertSame( '', (string) get_post_meta( $weekly->ID, '_oe_venue_id', true ) ?: '' );
		$this->assertSame(
			array(
				'rrule'   => 'FREQ=WEEKLY;COUNT=6',
				'rdates'  => array(),
				'exdates' => array( self::day( 14, 'Y-m-d' ), self::day( 21, 'Y-m-d' ) ),
			),
			get_post_meta( $weekly->ID, Recurrence::META_KEY, true ),
			'Cancelled and moved dates are skipped in the series.'
		);
		$this->assertCount( 4, $this->rows( $weekly->ID ), 'Six weeks minus two.' );
		$this->assertSame( self::day( 21, 'Y-m-d' ) . ' 19:00:00', get_post_meta( $events['Weekly meetup (late start)']->ID, '_oe_start', true ) );

		$fair = $events['Spring fair'];
		$this->assertSame( '1', get_post_meta( $fair->ID, '_oe_all_day', true ) );
		$this->assertSame( self::day( 31, 'Y-m-d' ) . ' 00:00:00', get_post_meta( $fair->ID, '_oe_end', true ), 'DTEND is exclusive for dates.' );
	}

	public function test_syncing_again_updates_instead_of_duplicating(): void {
		$this->body = self::calendar( self::events() );
		$feed       = $this->feed();
		$feed->sync();

		$again = $feed->sync();
		$this->assertTrue( $again['not_modified'], 'The server answered 304.' );
		$this->assertSame( 'No changes since the last sync.', $again['message'] );

		$this->body = self::calendar( self::events( 'Planning talk (room change)' ) );
		$changed    = $feed->sync();
		$this->assertSame( 0, $changed['created'] );
		$this->assertSame( 1, $changed['updated'] );
		$this->assertSame( 3, $changed['unchanged'] );
		$this->assertArrayHasKey( 'Planning talk (room change)', $this->imported( $feed ) );
		$this->assertCount( 4, Importer::event_ids( $feed->id() ) );
		$this->assertCount( 1, get_posts( array( 'post_type' => PostTypes::VENUE, 'title' => 'Central Library' ) ), 'The venue is reused.' );
	}

	public function test_local_edits_are_kept_and_removed_events_unpublished(): void {
		$this->body = self::calendar( self::events() );
		$feed       = $this->feed();
		$feed->sync();
		$events = $this->imported( $feed );

		// Someone edits the talk here; the calendar then changes it and drops the fair.
		$this->edit_here( $events['Planning talk']->ID, 'Planning talk (our notes)' );
		$this->body = self::calendar( array_values( array_filter( self::events( 'Planning talk v2' ), static fn( $e ) => ! str_contains( $e, 'fair@' ) ) ) );
		$report     = $feed->sync();

		$this->assertSame( 1, $report['kept'] );
		$this->assertSame( 1, $report['removed'] );
		$this->assertSame( 'Planning talk (our notes)', get_post( $events['Planning talk']->ID )->post_title );
		$this->assertSame( 'draft', get_post_status( $events['Spring fair']->ID ) );

		// The fair comes back.
		$this->body = self::calendar( self::events( 'Planning talk v3' ) );
		$back       = $feed->sync();
		$this->assertSame( 1, $back['updated'] );
		$this->assertSame( 'publish', get_post_status( $events['Spring fair']->ID ) );
	}

	/**
	 * An edit made here, a second later (modified times have second precision).
	 */
	private function edit_here( int $id, string $title ): void {
		global $wpdb;
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $title,
			)
		);
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 5 ) ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
	}

	public function test_addresses_are_checked_encrypted_and_masked(): void {
		foreach ( array( 'ftp://example.com/a.ics', 'https://user:pass@example.com/a.ics', 'not a url', 'http://127.0.0.1/a.ics', 'http://10.0.0.8/a.ics', 'http://[::1]/a.ics', 'http://localhost/a.ics', 'http://169.254.169.254/latest' ) as $bad ) {
			$this->assertWPError( Feed::validate_url( $bad ), $bad );
		}
		$this->assertSame( 'https://p01-calendars.icloud.com/published/2/abc', Feed::validate_url( 'webcal://p01-calendars.icloud.com/published/2/abc' ) );

		$feed = $this->feed();
		$this->assertSame( self::URL, $feed->url() );
		$raw = (string) get_post_meta( $feed->id(), '_oe_feed_url', true );
		$this->assertStringNotContainsString( 'private-0123456789abcdef', base64_decode( $raw ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( 'calendar.google.com/…/basic.ics', Feed::mask( $feed->url() ) );

		$list = rest_do_request( new \WP_REST_Request( 'GET', '/openevents/v1/feeds' ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertStringNotContainsString( '0123456789abcdef', (string) wp_json_encode( $list->get_data() ), 'The secret address is never sent back.' );
		$this->assertSame( 'calendar.google.com/…/basic.ics', $list->get_data()[0]['address'] );

		// An address that can't be decrypted any more (the site's keys changed).
		update_post_meta( $feed->id(), '_oe_feed_url', base64_encode( str_repeat( 'x', 64 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$report = Feed::get( $feed->id() )->sync();
		$this->assertFalse( $report['ok'] );
		$this->assertStringContainsString( 'enter its address again', $report['message'] );
	}

	public function test_only_administrators(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', '/openevents/v1/feeds' ) )->get_status() );
		$request = new \WP_REST_Request( 'POST', '/openevents/v1/feeds/import' );
		$request->set_param( 'ics', self::calendar( self::events() ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( array(), Importer::event_ids( 0 ) );
	}

	public function test_fetch_problems_are_reported(): void {
		$feed       = $this->feed();
		$this->code = 404;
		$report     = $feed->sync();
		$this->assertFalse( $report['ok'] );
		$this->assertStringContainsString( 'HTTP 404', $report['message'] );
		$this->assertSame( $report, Feed::get( $feed->id() )->last(), 'Shown on the screen.' );

		$this->code = 200;
		$this->body = '<!doctype html><title>Google Calendar</title>';
		$this->assertStringContainsString( 'not the calendar’s web page', $feed->sync()['message'] );
	}

	public function test_a_running_sync_is_not_started_twice(): void {
		global $wpdb;
		$this->body = self::calendar( self::events() );
		$feed       = $this->feed();
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'openevents_feed_lock_' . $feed->id(), 'option_value' => (string) ( time() + 600 ), 'autoload' => 'off' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertStringContainsString( 'already being synced', $feed->sync()['message'] );
		$this->assertSame( array(), $this->requests );

		// A lock left by a sync that died is taken over.
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 1 ) ), array( 'option_name' => 'openevents_feed_lock_' . $feed->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertTrue( $feed->sync()['ok'] );
	}

	public function test_import_a_file_through_rest(): void {
		$import = function (): array {
			$request = new \WP_REST_Request( 'POST', '/openevents/v1/feeds/import' );
			$request->set_param( 'ics', self::calendar( self::events() ) );
			$request->set_param( 'post_status', 'draft' );
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			return $response->get_data();
		};
		$this->assertSame( 4, $import()['created'] );
		$again = $import();
		$this->assertSame( 0, $again['created'] );
		$this->assertSame( 4, $again['unchanged'], 'Importing the same file again changes nothing.' );
		$this->assertSame( array( 'draft' ), array_values( array_unique( array_map( 'get_post_status', Importer::event_ids( 0 ) ) ) ) );

		$bad = new \WP_REST_Request( 'POST', '/openevents/v1/feeds/import' );
		$bad->set_param( 'ics', 'name,date' );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );
	}

	public function test_rest_adds_syncs_and_deletes_a_feed(): void {
		$this->body = self::calendar( self::events() );
		$create     = new \WP_REST_Request( 'POST', '/openevents/v1/feeds' );
		$create->set_param( 'url', self::URL );
		$create->set_param( 'frequency', 'hourly' );
		$response = rest_do_request( $create );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'calendar.google.com', $data['name'], 'Named after the host when no name is given.' );
		$this->assertSame( 4, $data['events'] );
		$this->assertSame( 4, $data['last']['created'] );
		$this->assertNotSame( '', $data['next'] );

		$sync = rest_do_request( new \WP_REST_Request( 'POST', "/openevents/v1/feeds/{$data['id']}/sync" ) );
		$this->assertTrue( $sync->get_data()['result']['not_modified'] );

		$edit = new \WP_REST_Request( 'POST', "/openevents/v1/feeds/{$data['id']}" );
		$edit->set_param( 'name', 'Team' );
		$edit->set_param( 'url', '' );
		$this->assertSame( 'Team', rest_do_request( $edit )->get_data()['name'] );
		$this->assertSame( self::URL, Feed::get( $data['id'] )->url(), 'An empty address keeps the old one.' );

		$ids    = Importer::event_ids( $data['id'] );
		$delete = new \WP_REST_Request( 'DELETE', "/openevents/v1/feeds/{$data['id']}" );
		$delete->set_param( 'events', 'trash' );
		$this->assertSame( 4, rest_do_request( $delete )->get_data()['trashed'] );
		$this->assertNull( Feed::get( $data['id'] ) );
		$this->assertSame( 'trash', get_post_status( $ids[0] ) );
	}

	public function test_cron_syncs_due_feeds(): void {
		$day        = self::day( 2 );
		$this->body = self::calendar( array( "UID:odd\r\nDTSTART:{$day}T150000Z\r\nRRULE:FREQ=YEARLY;BYWEEKNO=20\r\nSUMMARY:Odd rule" ) );
		$daily      = $this->feed( array( 'frequency' => 'daily' ) );
		$manual     = $this->feed( array( 'frequency' => 'manual' ) );

		\OpenEvents\Modules\Feeds\Module::run();
		$this->assertCount( 2, $this->requests, 'New feeds are due straight away.' );
		$report = Feed::get( $daily->id() )->last();
		$this->assertSame( 1, $report['unsupported'] );
		$this->assertStringContainsString( '1 repeat rule not supported (first date imported)', $report['message'] );
		$this->assertCount( 1, $this->rows( Importer::event_ids( $daily->id() )[0] ) );

		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, Feed::get( $daily->id() )->next(), 5 );
		$this->assertSame( 0, Feed::get( $manual->id() )->next(), 'Manual feeds sync only when asked.' );
		\OpenEvents\Modules\Feeds\Module::run();
		$this->assertCount( 2, $this->requests, 'Nothing is due.' );
	}
}
