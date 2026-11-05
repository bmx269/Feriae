<?php
/**
 * Waitlist emails.
 *
 * @package Feriae
 */

namespace Feriae\Attendance;

use Feriae\Frontend\Formatter;

/**
 * Joined (with place in line), manage link, promoted (RSVP), and offer
 * (tickets, with a deadline). Plain text; filter feriae/waitlist/email.
 */
final class WaitlistMailer {

	public static function link( WaitlistEntry $entry, string $token ): string {
		$type   = ( new TicketTypes() )->get( $entry->ticket_type_id );
		$anchor = 'rsvp' === $type?->kind ? 'rsvp' : 'tickets';
		return add_query_arg( 'feriae_waitlist', rawurlencode( $token ), self::page( $entry, $type ) ) . '#' . $anchor;
	}

	/**
	 * The page for the entry's date (each date of a repeating event has its
	 * own waitlist).
	 */
	private static function page( WaitlistEntry $entry, ?TicketType $type ): string {
		return $type ? TicketTypes::url( $type ) : (string) get_permalink( $entry->event_id );
	}

	public function joined( WaitlistEntry $entry, int $position, string $token ): void {
		$this->send(
			$entry,
			'joined',
			/* translators: %s: event title */
			__( 'You’re on the waitlist: %s', 'feriae' ),
			array(
				/* translators: 1: place in line, 2: event title */
				sprintf( __( 'You’re #%1$d on the waitlist for %2$s.', 'feriae' ), $position, $this->title( $entry ) ),
				__( 'We’ll email you as soon as a spot opens up.', 'feriae' ),
				'',
				__( 'Leave the waitlist:', 'feriae' ),
				self::link( $entry, $token ),
			)
		);
	}

	public function manage( WaitlistEntry $entry, string $token ): void {
		$this->send(
			$entry,
			'manage',
			/* translators: %s: event title */
			__( 'Your waitlist place: %s', 'feriae' ),
			array(
				/* translators: %s: event title */
				sprintf( __( 'Someone (hopefully you) tried to join the waitlist for %s with this email address, which is already on it.', 'feriae' ), $this->title( $entry ) ),
				__( 'Manage or leave your place here:', 'feriae' ),
				self::link( $entry, $token ),
				'',
				__( 'If this wasn’t you, you can ignore this email.', 'feriae' ),
			)
		);
	}

	public function promoted( WaitlistEntry $entry, string $token ): void {
		$this->send(
			$entry,
			'promoted',
			/* translators: %s: event title */
			__( 'A spot opened up: you’re going to %s', 'feriae' ),
			array_merge(
				array(
					/* translators: %s: event title */
					sprintf( _n( 'Good news! A spot opened up and you’re now confirmed for %s.', 'Good news! Spots opened up and your party is now confirmed for %s.', $entry->quantity, 'feriae' ), $this->title( $entry ) ),
					'',
				),
				$this->event_lines( $entry ),
				Pass::email_lines( ( new Attendees() )->group( $entry->uuid ) ), // Promotion uses the entry's uuid for the response.
				array(
					'',
					__( 'Change or cancel your RSVP:', 'feriae' ),
					add_query_arg( 'feriae_rsvp_token', rawurlencode( $token ), self::page( $entry, ( new TicketTypes() )->get( $entry->ticket_type_id ) ) ) . '#rsvp',
				)
			)
		);
	}

	public function offered( WaitlistEntry $entry, string $token ): void {
		$deadline = $entry->offer_expires_utc ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry->offer_expires_utc . ' UTC' ) ) : '';
		$type     = ( new TicketTypes() )->get( $entry->ticket_type_id );
		$this->send(
			$entry,
			'offered',
			/* translators: %s: event title */
			__( 'Tickets are being held for you: %s', 'feriae' ),
			array_merge(
				array(
					/* translators: 1: quantity, 2: ticket type, 3: event title */
					sprintf( __( 'Good news! %1$d × %2$s for %3$s opened up, and we’re holding them for you.', 'feriae' ), $entry->quantity, $type ? $type->name : '', $this->title( $entry ) ),
					/* translators: %s: date and time */
					sprintf( __( 'Claim them before %s, or they’ll go to the next person in line:', 'feriae' ), $deadline ),
					self::link( $entry, $token ),
					'',
				),
				$this->event_lines( $entry )
			)
		);
	}

	private function title( WaitlistEntry $entry ): string {
		return wp_strip_all_tags( get_the_title( $entry->event_id ) );
	}

	/**
	 * @return string[]
	 */
	private function event_lines( WaitlistEntry $entry ): array {
		$lines      = array();
		$type       = ( new TicketTypes() )->get( $entry->ticket_type_id );
		$occurrence = ( $type ? TicketTypes::date_of( $type ) : null ) ?? feriae()->occurrences()->for_event( $entry->event_id );
		if ( $occurrence ) {
			/* translators: %s: date and time */
			$lines[] = sprintf( __( 'When: %s', 'feriae' ), Formatter::range( $occurrence ) );
		}
		$lines[] = self::page( $entry, $type );
		return $lines;
	}

	/**
	 * @param string[] $body Lines after the greeting.
	 */
	private function send( WaitlistEntry $entry, string $type, string $subject_format, array $body ): void {
		$email = array(
			'to'      => $entry->email,
			'subject' => sprintf( $subject_format, $this->title( $entry ) ),
			/* translators: %s: name */
			'message' => implode( "\n", array_merge( array( sprintf( __( 'Hi %s,', 'feriae' ), $entry->name ), '' ), $body ) ),
			'headers' => array(),
		);

		/**
		 * Filters a waitlist email. Return an empty array to skip it.
		 *
		 * @param array         $email to, subject, message, headers.
		 * @param string        $type  joined, manage, promoted, or offered.
		 * @param WaitlistEntry $entry Entry.
		 */
		$email = apply_filters( 'feriae/waitlist/email', $email, $type, $entry );
		if ( $email ) {
			wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
		}
	}
}
