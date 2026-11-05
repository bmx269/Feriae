<?php
/**
 * Waitlist forms, endpoints, and admin routes.
 *
 * @package Feriae
 */

namespace Feriae\Tests\Integration;

use Feriae\Attendance\Guard;
use Feriae\Attendance\Order;
use Feriae\Attendance\Orders;
use Feriae\Attendance\TicketTypes;
use Feriae\Attendance\Waitlist;
use Feriae\Attendance\WaitlistService;
use Feriae\Modules\Rsvp\Form as RsvpForm;
use Feriae\Modules\Rsvp\Service as Rsvp;
use Feriae\Modules\Tickets\AbstractGateway;
use Feriae\Modules\Tickets\Checkout;
use Feriae\Modules\Tickets\Gateways;
use Feriae\Modules\Tickets\Module as Tickets;
use Feriae\Modules\Tickets\TicketForm;
use Feriae\Tests\TestCase;

final class WaitlistFormsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( AbstractGateway::OPTION, array( 'manual' => array( 'enabled' => true ) ) );
		Gateways::reset();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		unset( $_GET['feriae_waitlist'] );
		parent::tear_down();
	}

	private function upcoming(): int {
		$day = current_datetime()->modify( '+10 days' )->format( 'Y-m-d' );
		$id  = $this->event( array( '_feriae_start' => "{$day} 19:00:00" ) );
		$this->rows( $id );
		return $id;
	}

	private function last_token( string $param ): string {
		$mailer = tests_retrieve_phpmailer_instance();
		preg_match( '/' . $param . '=([a-f0-9]+)/', $mailer->get_sent( count( $mailer->mock_sent ) - 1 )->body, $m );
		return $m[1] ?? '';
	}

	public function test_full_rsvp_shows_an_accessible_waitlist_form_and_count(): void {
		$event = $this->upcoming();
		update_post_meta( $event, Rsvp::META_KEY, array( 'enabled' => true, 'capacity' => 1, 'max_per_response' => 3, 'waitlist' => true ) );
		$rsvp = new Rsvp();
		$rsvp->respond( $event, array( 'name' => 'A', 'email' => 'a@example.com', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );

		$request = new \WP_REST_Request( 'POST', "/feriae/v1/events/{$event}/rsvp" );
		$request->set_body_params( array( 'name' => 'B', 'email' => 'b@example.com', 'status' => 'waitlist', 'quantity' => 2, 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'waitlisted', $response->get_data()['code'] );

		$html = RsvpForm::render( feriae_get_event( $event ), $rsvp->ticket_type( $event ), $rsvp );
		$this->assertStringContainsString( 'This event is full.', $html );
		$this->assertStringContainsString( '1 going · Full · 2 waiting', $html );
		$this->assertStringContainsString( '<input type="hidden" name="status" value="waitlist">', $html );
		$this->assertMatchesRegularExpression( '/<label for="(feriae-rsvp-wl-\d+-name)">Name<\/label><input id="\1"/', $html );
		$this->assertStringContainsString( '>Join the waitlist</button>', $html );
	}

	public function test_private_link_shows_place_in_line_and_leaves(): void {
		$event = $this->upcoming();
		update_post_meta( $event, Rsvp::META_KEY, array( 'enabled' => true, 'capacity' => 1, 'waitlist' => true ) );
		$rsvp = new Rsvp();
		$rsvp->respond( $event, array( 'name' => 'A', 'email' => 'a@example.com', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$rsvp->respond( $event, array( 'name' => 'B', 'email' => 'b@example.com', 'status' => 'waitlist', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$token = $this->last_token( 'feriae_waitlist' );

		$_GET['feriae_waitlist'] = $token;
		$html                = RsvpForm::render( feriae_get_event( $event ), $rsvp->ticket_type( $event ), $rsvp );
		$this->assertStringContainsString( 'You’re on the waitlist for 1 person (#1 in line).', $html );
		$this->assertStringContainsString( '>Leave the waitlist</button>', $html );

		$leave = new \WP_REST_Request( 'POST', '/feriae/v1/waitlist/leave' );
		$leave->set_body_params( array( 'token' => $token ) );
		$this->assertSame( 200, rest_do_request( $leave )->get_status() );
		$this->assertSame( 404, rest_do_request( $leave )->get_status(), 'Tokens work once.' );
	}

	public function test_sold_out_tickets_offer_a_waitlist_form_and_rest_join(): void {
		$event = $this->upcoming();
		update_post_meta( $event, Tickets::META_KEY, array( array( 'key' => 'vip', 'name' => 'VIP', 'price' => '50', 'capacity' => 1, 'waitlist' => true ) ) );
		$type = ( new TicketTypes() )->find( $event, Tickets::KEY_PREFIX . 'vip' );
		( new Checkout() )->place( $event, array( 'name' => 'A', 'email' => 'a@example.com', 'tickets' => array( $type->id => 1 ), 'gateway' => 'manual', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );

		$checkout = new Checkout();
		$html     = TicketForm::render( feriae_get_event( $event ), $checkout->ticket_types( $event ), $checkout );
		$this->assertStringContainsString( '<h4 id="feriae-tickets-wl-' . $event . '-title">Join the waitlist</h4>', $html );
		$this->assertStringContainsString( 'aria-labelledby="feriae-tickets-wl-' . $event . '-title"', $html );
		$this->assertStringContainsString( 'You’ll have 24 hours to buy them.', $html );

		$join = new \WP_REST_Request( 'POST', "/feriae/v1/events/{$event}/waitlist" );
		$join->set_body_params( array( 'ticket_type_id' => $type->id, 'name' => 'W', 'email' => 'w@example.com', 'quantity' => 1, 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$this->assertSame( 'waitlisted', rest_do_request( $join )->get_data()['code'] );
	}

	public function test_claim_view_checks_out_exactly_the_held_tickets(): void {
		$event = $this->upcoming();
		update_post_meta( $event, Tickets::META_KEY, array( array( 'key' => 'ga', 'name' => 'General', 'price' => '15', 'capacity' => 2, 'waitlist' => true ) ) );
		$type  = ( new TicketTypes() )->find( $event, Tickets::KEY_PREFIX . 'ga' );
		$first = ( new Checkout() )->place( $event, array( 'name' => 'A', 'email' => 'a@example.com', 'tickets' => array( $type->id => 2 ), 'gateway' => 'manual', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		( new WaitlistService() )->join( ( new TicketTypes() )->get( $type->id ), array( 'name' => 'Wanda', 'email' => 'wanda@example.com', 'quantity' => 2 ) );
		( new Checkout() )->release( ( new Orders() )->get( $first->order->id ), Order::CANCELLED );
		$token = $this->last_token( 'feriae_waitlist' );

		$_GET['feriae_waitlist'] = $token;
		$checkout            = new Checkout();
		$html                = TicketForm::render( feriae_get_event( $event ), $checkout->ticket_types( $event ), $checkout );
		$this->assertStringContainsString( 'Your tickets are being held', $html );
		$this->assertStringContainsString( '2 × General', $html );
		$this->assertStringContainsString( 'name="waitlist_token" value="' . $token . '"', $html );
		$this->assertStringContainsString( 'value="wanda@example.com"', $html, 'Prefilled from the waitlist entry.' );
		$this->assertStringContainsString( '>Claim tickets</button>', $html );
		$this->assertStringContainsString( 'No thanks, release these tickets', $html );
	}

	public function test_organiser_routes_need_rights_and_removal_passes_the_hold_on(): void {
		$event = $this->upcoming();
		update_post_meta( $event, Tickets::META_KEY, array( array( 'key' => 'ga', 'name' => 'General', 'price' => '15', 'capacity' => 1, 'waitlist' => true ) ) );
		$type  = ( new TicketTypes() )->find( $event, Tickets::KEY_PREFIX . 'ga' );
		$first = ( new Checkout() )->place( $event, array( 'name' => 'A', 'email' => 'a@example.com', 'tickets' => array( $type->id => 1 ), 'gateway' => 'manual', 'ts' => Guard::sign_timestamp( time() - 10 ) ) );
		$svc   = new WaitlistService();
		$svc->join( ( new TicketTypes() )->get( $type->id ), array( 'name' => 'One', 'email' => 'one@example.com' ) );
		$svc->join( ( new TicketTypes() )->get( $type->id ), array( 'name' => 'Two', 'email' => 'two@example.com' ) );
		( new Checkout() )->release( ( new Orders() )->get( $first->order->id ), Order::CANCELLED );

		$list = new \WP_REST_Request( 'GET', '/feriae/v1/waitlist' );
		$list->set_param( 'event', $event );
		$this->assertGreaterThanOrEqual( 401, rest_do_request( $list )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$items = rest_do_request( $list )->get_data();
		$this->assertSame( array( 'offered', 'waiting' ), wp_list_pluck( $items, 'status' ) );
		$this->assertSame( 'General', $items[0]['type_name'] );

		$remove = new \WP_REST_Request( 'POST', '/feriae/v1/waitlist/' . $items[0]['id'] );
		$remove->set_body_params( array( 'action' => 'remove' ) );
		$this->assertSame( 'left', rest_do_request( $remove )->get_data()['status'] );
		$this->assertSame( array( 'left', 'offered' ), wp_list_pluck( rest_do_request( $list )->get_data(), 'status' ), 'The hold passed to the next person.' );
	}
}
