<?php
/**
 * Calendar feed endpoints and abilities.
 *
 * @package Feriae
 */

namespace Feriae\Modules\Feeds;

use Feriae\Content\PostTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET    /feriae/v1/feeds             List calendars.
 * POST   /feriae/v1/feeds             Add one (and import it now).
 * POST   /feriae/v1/feeds/{id}        Change one (an empty url keeps the address).
 * DELETE /feriae/v1/feeds/{id}        Remove one; events=trash also trashes its events.
 * POST   /feriae/v1/feeds/{id}/sync   Sync now.
 * POST   /feriae/v1/feeds/import      Import an .ics file's text once.
 *
 * Administrators only: this site fetches the address, and imported events
 * are published under the feed's author.
 */
final class Rest {

	/** Seconds a sync started from the screen may take; the rest follows by cron. */
	private const REQUEST_BUDGET = 20;

	/** Largest .ics file accepted, in bytes. */
	public const MAX_FILE = 5 * MB_IN_BYTES;

	public function routes(): void {
		$options = array(
			'name'        => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'category'    => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'post_status' => array(
				'type' => 'string',
				'enum' => Feed::STATUSES,
			),
			'frequency'   => array(
				'type' => 'string',
				'enum' => array_keys( Feed::FREQUENCIES ),
			),
			'post_type'   => array(
				'type' => 'string',
				'enum' => feriae_event_post_types(),
			),
		);
		$url     = array(
			'type'      => 'string',
			'maxLength' => 2000,
		);
		$id      = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			'feriae/v1',
			'/feeds',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => $options + array(
						'url'  => array( 'required' => true ) + $url,
						'sync' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			'feriae/v1',
			'/feeds/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => $id + $options + array( 'url' => $url ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( self::class, 'can_manage' ),
					'args'                => $id + array(
						'events' => array(
							'type'    => 'string',
							'enum'    => array( 'keep', 'trash' ),
							'default' => 'keep',
						),
					),
				),
			)
		);
		register_rest_route(
			'feriae/v1',
			'/feeds/(?P<id>\d+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			'feriae/v1',
			'/feeds/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array_diff_key( $options, array_flip( array( 'name', 'frequency' ) ) ) + array(
					'ics' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => self::MAX_FILE,
					),
				),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function list(): WP_REST_Response {
		$counts = Importer::counts();
		return new WP_REST_Response( array_map( static fn( Feed $feed ) => self::present( $feed, $counts ), Feed::all() ) );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$feed = Feed::create( $request->get_params() );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		if ( $request['sync'] ) {
			$feed->sync( self::REQUEST_BUDGET );
		}
		return new WP_REST_Response( self::present( Feed::get( $feed->id() ) ?? $feed ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$feed = self::feed( $request );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		$params = array_intersect_key( $request->get_params(), array_flip( array( 'url', 'name', 'category', 'post_status', 'frequency', 'post_type' ) ) );
		$feed   = $feed->update( $params );
		return is_wp_error( $feed ) ? $feed : new WP_REST_Response( self::present( $feed ) );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$feed = self::feed( $request );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		return new WP_REST_Response(
			array(
				'deleted' => true,
				'trashed' => $feed->delete( 'trash' === $request['events'] ),
			)
		);
	}

	public function sync( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$feed = self::feed( $request );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		$result = $feed->sync( self::REQUEST_BUDGET );
		return new WP_REST_Response( array( 'result' => $result ) + self::present( Feed::get( $feed->id() ) ?? $feed ) );
	}

	/**
	 * One-off import of an .ics file the browser read. Importing the same file
	 * again updates those events rather than adding copies.
	 */
	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ics = (string) $request['ics'];
		if ( ! Parser::is_calendar( $ics ) ) {
			return new WP_Error( 'feriae_feed_format', __( 'That file isn’t an iCal (.ics) calendar.', 'feriae' ), array( 'status' => 400 ) );
		}
		$options = Feed::options( $request->get_params() );
		$parser  = new Parser();
		$items   = $parser->parse( $ics );
		$result  = ( new Importer( 0, $options['post_type'], $options['post_status'], $options['category'], get_current_user_id() ) )->import( $items, false, self::REQUEST_BUDGET );
		return new WP_REST_Response(
			Importer::report(
				array(
					'skipped' => $parser->skipped,
					'source'  => 'file',
				) + $result
			)
		);
	}

	private static function feed( WP_REST_Request $request ): Feed|WP_Error {
		return Feed::get( (int) $request['id'] ) ?? new WP_Error( 'feriae_feed_not_found', __( 'That calendar wasn’t found.', 'feriae' ), array( 'status' => 404 ) );
	}

	/**
	 * A feed for the screen. The address is masked; it's never sent back.
	 *
	 * @param array<int, int>|null $counts Events per feed, when listing many.
	 * @return array<string, mixed>
	 */
	public static function present( Feed $feed, ?array $counts = null ): array {
		$counts ??= Importer::counts();
		$term     = $feed->category() ? get_term( $feed->category(), PostTypes::CATEGORY ) : null;
		$url      = $feed->url();
		return array(
			'id'            => $feed->id(),
			'name'          => $feed->name(),
			'address'       => '' !== $url ? Feed::mask( $url ) : '',
			'category'      => $feed->category(),
			'category_name' => $term instanceof \WP_Term ? $term->name : '',
			'post_status'   => $feed->post_status(),
			'frequency'     => $feed->frequency(),
			'post_type'     => $feed->post_type(),
			'events'        => $counts[ $feed->id() ] ?? 0,
			'last'          => $feed->last() ?: null,
			'next'          => $feed->next() ? gmdate( 'Y-m-d H:i:s', $feed->next() ) : '',
		);
	}

	public static function abilities(): void {
		$feed = array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'name'    => array( 'type' => 'string' ),
				'address' => array(
					'type'        => 'string',
					'description' => __( 'Masked iCal address.', 'feriae' ),
				),
				'events'  => array( 'type' => 'integer' ),
				'last'    => array( 'type' => array( 'object', 'null' ) ),
				'next'    => array( 'type' => 'string' ),
			),
		);
		$meta = static fn( bool $readonly ) => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => false,
				'idempotent'  => true,
			),
		);

		wp_register_ability(
			'feriae/list-calendar-feeds',
			array(
				'label'               => __( 'List calendar feeds', 'feriae' ),
				'description'         => __( 'Lists the calendars (iCal feeds) events are imported from, with each one’s last sync.', 'feriae' ),
				'category'            => 'feriae',
				'output_schema'       => array(
					'type'  => 'array',
					'items' => $feed,
				),
				'execute_callback'    => static fn() => ( new self() )->list()->get_data(),
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => $meta( true ),
			)
		);
		wp_register_ability(
			'feriae/sync-calendar-feed',
			array(
				'label'               => __( 'Sync calendar feed', 'feriae' ),
				'description'         => __( 'Imports a calendar feed now: adds new events, updates changed ones, and unpublishes upcoming events no longer in it.', 'feriae' ),
				'category'            => 'feriae',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ok'      => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( array $input ) {
					$feed = Feed::get( (int) $input['id'] );
					return $feed ? $feed->sync( self::REQUEST_BUDGET ) : new WP_Error( 'feriae_feed_not_found', __( 'That calendar wasn’t found.', 'feriae' ) );
				},
				'permission_callback' => array( self::class, 'can_manage' ),
				'meta'                => $meta( false ),
			)
		);
	}
}
