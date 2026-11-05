<?php
/**
 * Downloads iCalendar feeds.
 *
 * @package Feriae
 */

namespace Feriae\Modules\Feeds;

use WP_Error;

/**
 * Fetches a feed with wp_safe_remote_get() (no private-network addresses,
 * redirects included), a size cap, and conditional requests, so an unchanged
 * calendar costs one 304.
 */
final class Fetcher {

	public const MAX_BYTES = 10 * MB_IN_BYTES;

	/**
	 * @return array{body: ?string, etag: string, modified: string}|WP_Error Body is null when unchanged (304).
	 */
	public static function get( string $url, string $etag = '', string $modified = '' ): array|WP_Error {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Feriae/' . \Feriae\VERSION . '; ' . home_url( '/' ),
				'headers'             => array_filter(
					array(
						'Accept'            => 'text/calendar, text/plain;q=0.9, */*;q=0.5',
						'If-None-Match'     => $etag,
						'If-Modified-Since' => $modified,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'feriae_feed_unreachable', sprintf( __( 'The calendar couldn’t be reached: %s', 'feriae' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			return array(
				'body'     => null,
				'etag'     => $etag,
				'modified' => $modified,
			);
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'feriae_feed_http', self::http_error( $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) >= self::MAX_BYTES ) {
			/* translators: %s: size such as "10 MB" */
			return new WP_Error( 'feriae_feed_size', sprintf( __( 'The calendar is larger than %s. Use a calendar with fewer past events, or split it.', 'feriae' ), size_format( self::MAX_BYTES ) ) );
		}
		if ( ! Parser::is_calendar( $body ) ) {
			return new WP_Error( 'feriae_feed_format', __( 'That address didn’t return a calendar in iCal format. In Google Calendar, copy “Public address in iCal format” or “Secret address in iCal format”, not the calendar’s web page or embed link.', 'feriae' ) );
		}

		return array(
			'body'     => $body,
			'etag'     => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
		);
	}

	private static function http_error( int $code ): string {
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'The calendar refused access (HTTP %d). For a private Google calendar, use its secret address in iCal format.', 'feriae' ), $code );
		}
		if ( in_array( $code, array( 404, 410 ), true ) ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'There is no calendar at that address any more (HTTP %d). If its secret address was reset, copy the new one.', 'feriae' ), $code );
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( 'The calendar’s server answered with an error (HTTP %d). The next sync will try again.', 'feriae' ), $code );
	}
}
