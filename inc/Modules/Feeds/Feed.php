<?php
/**
 * A calendar feed to import.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Feeds;

use OpenEvents\Content\PostTypes;
use WP_Error;
use WP_Post;

/**
 * Stored as a private `oe_feed` post (title = name) with its settings in meta.
 *
 * The iCal address is encrypted at rest and never sent back to the browser in
 * full: a calendar's secret address (Google's "Secret address in iCal format")
 * lets anyone who has it read the whole calendar.
 */
final class Feed {

	public const POST_TYPE = 'oe_feed';

	/** Sync frequency => seconds between syncs (0 = only when asked). */
	public const FREQUENCIES = array(
		'hourly' => HOUR_IN_SECONDS,
		'daily'  => DAY_IN_SECONDS,
		'weekly' => WEEK_IN_SECONDS,
		'manual' => 0,
	);

	/** Status of the events a feed creates. */
	public const STATUSES = array( 'publish', 'draft' );

	private const URL_KEY    = '_oe_feed_url';
	private const OPTIONS    = '_oe_feed_options';
	private const LAST       = '_oe_feed_last';
	private const NEXT       = '_oe_feed_next';
	private const CACHE      = '_oe_feed_cache';
	private const LOCK       = 'openevents_feed_lock_';
	private const LOCK_TTL   = 15 * MINUTE_IN_SECONDS;
	private const NAME_LIMIT = 200;

	private function __construct( public readonly WP_Post $post ) {}

	public static function register_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( 'Calendar feeds', 'openevents' ),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
				'can_export'   => false,
				'supports'     => array( 'title' ),
			)
		);
	}

	public static function get( int $id ): ?Feed {
		$post = get_post( $id );
		return $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ? new self( $post ) : null;
	}

	/**
	 * @return Feed[] By name.
	 */
	public static function all(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return array_map( static fn( WP_Post $post ) => new self( $post ), $posts );
	}

	/**
	 * @param array<string, mixed> $data url, name, category, post_status, frequency, post_type.
	 */
	public static function create( array $data ): Feed|WP_Error {
		$url = self::validate_url( (string) ( $data['url'] ?? '' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => self::name_from( (string) ( $data['name'] ?? '' ), $url ),
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, self::URL_KEY, self::seal( $url ) );
		update_post_meta( $id, self::OPTIONS, self::options( $data ) );
		update_post_meta( $id, self::NEXT, time() ); // Due now, should the first sync not run straight away.
		return self::get( $id ) ?? new WP_Error( 'openevents_feed', __( 'The calendar couldn’t be saved.', 'openevents' ) );
	}

	/**
	 * @param array<string, mixed> $data Fields to change; an empty url keeps the address.
	 */
	public function update( array $data ): Feed|WP_Error {
		if ( '' !== trim( (string) ( $data['url'] ?? '' ) ) ) {
			$url = self::validate_url( (string) $data['url'] );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			update_post_meta( $this->id(), self::URL_KEY, self::seal( $url ) );
			delete_post_meta( $this->id(), self::CACHE ); // A new address: fetch it in full.
		}
		if ( isset( $data['name'] ) ) {
			wp_update_post(
				array(
					'ID'         => $this->id(),
					'post_title' => self::name_from( (string) $data['name'], $this->url() ),
				)
			);
		}
		update_post_meta( $this->id(), self::OPTIONS, self::options( $data + $this->options_meta() ) );
		$frequency = $this->frequency();
		update_post_meta( $this->id(), self::NEXT, self::FREQUENCIES[ $frequency ] ? time() + self::FREQUENCIES[ $frequency ] : 0 );
		return self::get( $this->id() ) ?? $this;
	}

	/**
	 * Deletes the feed. Its events stay (now ordinary events) unless $trash_events.
	 *
	 * @return int Events moved to the trash.
	 */
	public function delete( bool $trash_events ): int {
		$trashed = 0;
		foreach ( Importer::event_ids( $this->id() ) as $event_id ) {
			if ( $trash_events && wp_trash_post( $event_id ) ) {
				++$trashed;
			}
			foreach ( Importer::META_KEYS as $key ) {
				delete_post_meta( $event_id, $key );
			}
		}
		wp_delete_post( $this->id(), true );
		return $trashed;
	}

	public function id(): int {
		return $this->post->ID;
	}

	public function name(): string {
		return $this->post->post_title;
	}

	/**
	 * The iCal address, or '' when it can't be decrypted (the site's keys changed).
	 */
	public function url(): string {
		return self::open( (string) get_post_meta( $this->id(), self::URL_KEY, true ) );
	}

	public function category(): int {
		return (int) $this->options_meta()['category'];
	}

	public function post_status(): string {
		return (string) $this->options_meta()['post_status'];
	}

	public function frequency(): string {
		return (string) $this->options_meta()['frequency'];
	}

	public function post_type(): string {
		return (string) $this->options_meta()['post_type'];
	}

	public function author(): int {
		return (int) $this->post->post_author;
	}

	/**
	 * The last sync's report (see Importer), or an empty array before the first.
	 *
	 * @return array<string, mixed>
	 */
	public function last(): array {
		$last = get_post_meta( $this->id(), self::LAST, true );
		return is_array( $last ) ? $last : array();
	}

	/**
	 * When the next scheduled sync is due (Unix time), or 0 for none.
	 */
	public function next(): int {
		return (int) get_post_meta( $this->id(), self::NEXT, true );
	}

	public function is_due(): bool {
		return $this->next() > 0 && $this->next() <= time();
	}

	/**
	 * Fetches the calendar and imports it.
	 *
	 * @param int $budget Seconds to spend writing events before stopping (0 = no limit); the rest follows shortly.
	 * @return array<string, mixed> Report (see Importer::report()).
	 */
	public function sync( int $budget = 0 ): array {
		if ( ! $this->lock() ) {
			return Importer::failure( __( 'This calendar is already being synced. Try again in a few minutes.', 'openevents' ) );
		}
		try {
			$result = $this->fetch_and_import( $budget );
		} finally {
			$this->unlock();
		}

		update_post_meta( $this->id(), self::LAST, $result );
		$interval = self::FREQUENCIES[ $this->frequency() ];
		$next     = ! empty( $result['partial'] ) ? time() + MINUTE_IN_SECONDS : ( $interval ? time() + $interval : 0 );
		update_post_meta( $this->id(), self::NEXT, $next );
		if ( ! empty( $result['partial'] ) ) {
			wp_schedule_single_event( $next, Module::CRON_HOOK ); // Carry on shortly.
		}

		/**
		 * Fires after a calendar feed syncs.
		 *
		 * @param Feed                 $feed   Feed.
		 * @param array<string, mixed> $result Report.
		 */
		do_action( 'openevents/feeds/synced', $this, $result );
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetch_and_import( int $budget ): array {
		$url = $this->url();
		if ( '' === $url ) {
			return Importer::failure( __( 'The calendar’s address can’t be read, because the site’s security keys changed. Edit the calendar and enter its address again.', 'openevents' ) );
		}
		$cache   = get_post_meta( $this->id(), self::CACHE, true );
		$cache   = is_array( $cache ) ? $cache : array();
		$fetched = Fetcher::get( $url, (string) ( $cache['etag'] ?? '' ), (string) ( $cache['modified'] ?? '' ) );
		if ( is_wp_error( $fetched ) ) {
			return Importer::failure( $fetched->get_error_message() );
		}
		if ( null === $fetched['body'] ) {
			return Importer::report( array( 'not_modified' => true ) );
		}

		$parser = new Parser();
		$items  = $parser->parse( $fetched['body'] );
		$result = Importer::for_feed( $this )->import( $items, true, $budget );
		$result = Importer::report( array( 'skipped' => $parser->skipped ) + $result );

		// Only a complete import may skip an unchanged calendar next time.
		if ( empty( $result['partial'] ) ) {
			update_post_meta(
				$this->id(),
				self::CACHE,
				array(
					'etag'     => $fetched['etag'],
					'modified' => $fetched['modified'],
				)
			);
		}
		return $result;
	}

	/**
	 * A normalised iCal address, or why it can't be used. webcal:// links (what
	 * Apple Calendar and many "Subscribe" buttons use) become https://.
	 *
	 * Addresses on private networks are refused here, and again (after DNS) when
	 * fetched, since this server makes the request.
	 */
	public static function validate_url( string $url ): string|WP_Error {
		$url = trim( $url );
		$url = (string) preg_replace( '#^webcals?://#i', 'https://', $url );

		$invalid = new WP_Error( 'openevents_feed_url', __( 'Enter the calendar’s iCal address: an http or https link, often ending in .ics.', 'openevents' ), array( 'status' => 400 ) );
		$parts   = wp_parse_url( $url );
		if ( strlen( $url ) > 2000 || ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $invalid;
		}

		$host = strtolower( trim( $parts['host'], '[]' ) );
		$ip   = filter_var( $host, FILTER_VALIDATE_IP );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || ( $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) ) {
			return new WP_Error( 'openevents_feed_url', __( 'That address is on a private network. This site fetches the calendar itself, so only public addresses can be used.', 'openevents' ), array( 'status' => 400 ) );
		}

		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		return '' === $clean ? $invalid : $clean;
	}

	/**
	 * Enough of an address to recognise it, not enough to use it:
	 * "calendar.google.com/…/basic.ics".
	 */
	public static function mask( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$file = basename( (string) ( $parts['path'] ?? '' ) );
		if ( strlen( $file ) > 20 ) {
			$file = substr( $file, 0, 4 ) . '…';
		}
		return $parts['host'] . ( '' !== $file ? '/…/' . $file : '' );
	}

	/**
	 * @param array<string, mixed> $data Raw options.
	 * @return array{category: int, post_status: string, frequency: string, post_type: string}
	 */
	public static function options( array $data ): array {
		$types     = openevents_event_post_types();
		$post_type = (string) ( $data['post_type'] ?? '' );
		$category  = absint( $data['category'] ?? 0 );
		$term      = $category ? get_term( $category, PostTypes::CATEGORY ) : null;
		return array(
			'category'    => $term instanceof \WP_Term ? $term->term_id : 0,
			'post_status' => in_array( $data['post_status'] ?? '', self::STATUSES, true ) ? $data['post_status'] : 'publish',
			'frequency'   => isset( self::FREQUENCIES[ $data['frequency'] ?? '' ] ) ? $data['frequency'] : 'daily',
			'post_type'   => in_array( $post_type, $types, true ) ? $post_type : self::default_post_type(),
		);
	}

	public static function default_post_type(): string {
		$types = openevents_event_post_types();
		return in_array( PostTypes::EVENT, $types, true ) ? PostTypes::EVENT : (string) ( $types[0] ?? PostTypes::EVENT );
	}

	/**
	 * @return array{category: int, post_status: string, frequency: string, post_type: string}
	 */
	private function options_meta(): array {
		$stored = get_post_meta( $this->id(), self::OPTIONS, true );
		return self::options( is_array( $stored ) ? $stored : array() );
	}

	private static function name_from( string $name, string $url ): string {
		$name = mb_substr( sanitize_text_field( $name ), 0, self::NAME_LIMIT );
		return '' !== $name ? $name : (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Takes this feed's sync lock (an options row inserted atomically), or
	 * takes over one left by a sync that died.
	 */
	private function lock(): bool {
		global $wpdb;
		$name  = self::LOCK . $this->id();
		$until = (string) ( time() + self::LOCK_TTL );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic lock; the options API can't do insert-if-absent.
		if ( $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $name, $until, 'off' ) ) ) {
			return true;
		}
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value < %s', $wpdb->options, $until, $name, (string) time() ) );
		// phpcs:enable
	}

	private function unlock(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK . $this->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Removes every feed, its settings, and its locks (uninstall).
	 */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( self::LOCK ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Key for the address at rest, derived from the site's auth salt.
	 */
	private static function key(): string {
		return hash_hmac( 'sha256', 'openevents-feed-url', wp_salt( 'auth' ), true );
	}

	private static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ciphertext storage.
	}

	private static function open( string $sealed ): string {
		$raw = base64_decode( $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- ciphertext storage.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \SodiumException ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}
}
