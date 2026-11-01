<?php
/**
 * Calendar import module.
 *
 * @package OpenEvents
 */

namespace OpenEvents\Modules\Feeds;

use OpenEvents\Modules\AbstractModule;

/**
 * Imports events from iCal feeds (Google Calendar, Outlook, Apple Calendar,
 * Meetup, other event sites) on a schedule, and from .ics files once.
 */
final class Module extends AbstractModule {

	public const CRON_HOOK = 'openevents_feeds_sync';

	/** Seconds a scheduled run spends importing before handing over to the next run. */
	private const RUN_BUDGET = 25;

	public function id(): string {
		return 'feeds';
	}

	public function label(): string {
		return __( 'Calendar import', 'openevents' );
	}

	public function description(): string {
		return __( 'Imports events from Google Calendar, Outlook, Apple Calendar, or any iCal feed and keeps them in sync, and imports .ics files.', 'openevents' );
	}

	public function requires(): array {
		return array( 'recurrence' ); // Repeating events in feeds.
	}

	public function boot(): void {
		add_action( 'init', array( Feed::class, 'register_type' ) );
		add_action( 'init', array( self::class, 'schedule_cron' ) );
		add_action( self::CRON_HOOK, array( self::class, 'run' ) );
		add_action( 'rest_api_init', array( new Rest(), 'routes' ) );
		add_action( 'wp_abilities_api_init', array( Rest::class, 'abilities' ) );
		add_action( 'openevents/uninstall', array( self::class, 'uninstall' ) );
		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Hourly: syncs the feeds that are due. A run that can't finish in its
	 * budget asks for another run in a minute.
	 */
	public static function run(): void {
		$stop = microtime( true ) + self::RUN_BUDGET;
		foreach ( Feed::all() as $feed ) {
			if ( ! $feed->is_due() ) {
				continue;
			}
			$left = (int) ceil( $stop - microtime( true ) );
			if ( $left < 1 ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
				return;
			}
			if ( ! empty( $feed->sync( $left )['partial'] ) ) {
				return; // The feed scheduled the next run.
			}
		}
	}

	public static function uninstall(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		Feed::uninstall();
		foreach ( Importer::META_KEYS as $key ) {
			delete_post_meta_by_key( $key );
		}
	}
}
