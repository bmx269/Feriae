<?php
/**
 * RSVP form markup.
 *
 * @package Feriae
 */

namespace Feriae\Modules\Rsvp;

use Feriae\Attendance\Attendee;
use Feriae\Attendance\Attendees;
use Feriae\Attendance\Fields;
use Feriae\Attendance\TicketType;
use Feriae\Content\Event;

/**
 * Renders the RSVP section. A plain form that posts to admin-post.php (works
 * without JavaScript); the feriae/rsvp script module upgrades it to submit
 * over REST and show the result in place.
 */
final class Form {

	public static function render( Event $event, TicketType $type, Service $service, string $token = '' ): string {
		$event_id = $event->id();
		$closed   = $service->closed_reason( $event_id, $type );
		$existing = '' !== $token ? $service->response_for_token( $token ) : array();
		if ( $existing && $existing[0]->ticket_type_id !== $type->id ) {
			$existing = array(); // Another event's or date's response.
		}
		$counts = $type->setting( 'show_count', true ) ? ( new Attendees() )->counts( $event_id, $type->id ) : null;

		$html  = '<section class="feriae-rsvp" id="rsvp" aria-labelledby="feriae-rsvp-title-' . esc_attr( (string) $event_id ) . '"';
		$html .= ' data-wp-interactive="feriae/rsvp"';
		$html .= ' data-wp-context="' . esc_attr(
			(string) wp_json_encode(
				array(
					'endpoint' => rest_url( 'feriae/v1/events/' . $event_id . '/rsvp' ),
					'message'  => '',
					'error'    => false,
					'done'     => false,
					'busy'     => false,
					'countText'     => $counts ? self::count_text( $counts['going'], $type->remaining() ) : '',
					'fallbackError' => __( 'Something went wrong. Please check your connection and try again.', 'feriae' ),
					'leaveEndpoint' => rest_url( 'feriae/v1/waitlist/leave' ),
				)
			)
		) . '">';
		$html .= '<h3 class="feriae-rsvp__title" id="feriae-rsvp-title-' . esc_attr( (string) $event_id ) . '">' . esc_html__( 'RSVP', 'feriae' ) . '</h3>';
		$html .= \Feriae\Frontend\Formatter::booking_date( $type, 'feriae-rsvp__date' );

		$waitlist_on = \Feriae\Attendance\WaitlistService::enabled( $type );
		if ( $counts ) {
			$count_text = self::count_text( $counts['going'], $type->remaining() );
			$waiting    = $waitlist_on ? ( new \Feriae\Attendance\Waitlist() )->waiting_count( $type->id ) : 0;
			if ( $waiting ) {
				/* translators: %d: people on the waitlist */
				$count_text .= ' · ' . sprintf( _n( '%d waiting', '%d waiting', $waiting, 'feriae' ), $waiting );
			}
			$html .= '<p class="feriae-rsvp__count" data-wp-text="context.countText">' . esc_html( $count_text ) . '</p>';
		}

		// Behind a private waitlist link: their place in line, and a way out.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$wl_token = sanitize_text_field( wp_unslash( $_GET['feriae_waitlist'] ?? '' ) );
		$wl_entry = '' !== $wl_token ? ( new \Feriae\Attendance\Waitlist() )->by_token( $wl_token ) : null;
		if ( $wl_entry && $wl_entry->ticket_type_id === $type->id ) {
			$html .= \Feriae\Attendance\WaitlistController::leave_form( $wl_entry, $wl_token, 'rsvp' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$wl_result = sanitize_key( wp_unslash( $_GET['feriae_waitlist_result'] ?? '' ) );
		if ( $wl_result ) {
			$html .= '<p class="feriae-rsvp__notice" role="status" tabindex="-1">' . esc_html( \Feriae\Attendance\WaitlistController::result_text( $wl_result ) ) . '</p>';
		}

		// Outcome of a no-JS submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$outcome = sanitize_key( wp_unslash( $_GET['feriae_rsvp'] ?? '' ) );
		if ( $outcome ) {
			$ok    = in_array( $outcome, array( 'going', 'not_going', 'exists', 'cancelled', 'waitlisted' ), true );
			$html .= sprintf( '<p class="feriae-rsvp__notice%s" role="%s" tabindex="-1">%s</p>', $ok ? '' : ' is-error', $ok ? 'status' : 'alert', esc_html( self::outcome_text( $outcome ) ) );
		}
		$html .= '<p class="feriae-rsvp__notice feriae-rsvp__live" role="status" aria-live="polite" tabindex="-1" data-wp-text="context.message" data-wp-class--is-error="context.error" data-wp-bind--hidden="!context.message" hidden></p>';

		if ( $closed && ! $existing ) {
			return $html . '<p class="feriae-rsvp__closed">' . esc_html( self::closed_text( $closed ) ) . '</p></section>';
		}
		if ( $type->is_full() && ! $existing ) {
			$html .= '<p class="feriae-rsvp__closed">' . esc_html__( 'This event is full.', 'feriae' ) . '</p>';
			if ( $waitlist_on ) {
				return $html . self::waitlist_form( $event_id, $type ) . '</section>';
			}
			if ( ! $type->setting( 'allow_not_going', true ) ) {
				return $html . '</section>';
			}
		}

		$lead   = $existing[0] ?? null;
		$going  = $lead ? Attendee::GOING === $lead->status : true;
		$people = $lead && $going ? count( $existing ) : 1;
		$max    = (int) $type->setting( 'max_per_response', 1 );
		if ( null !== $type->remaining() ) {
			// Can't ask for more seats than are left (plus the ones you already hold).
			$max = max( 1, min( $max, $type->remaining() + ( $lead && $going ? $people : 0 ) ) );
		}

		if ( $lead ) {
			$html .= '<p class="feriae-rsvp__existing">' . esc_html(
				$going
					/* translators: %d: number of people */
					? sprintf( _n( 'You’re going (%d person). Change your response below.', 'You’re going (%d people). Change your response below.', $people, 'feriae' ), $people )
					: __( 'You said you can’t make it. Change your response below.', 'feriae' )
			) . '</p>';
			if ( $going ) {
				$html .= self::passes( $existing );
			}
		}

		$can_go     = ! $type->is_full() || ( $lead && $going );
		$pick_going = $lead ? $going : $can_go;
		$form_state = array(
			'quantity' => $people,
			'going'    => $pick_going,
		);
		$html      .= '<form class="feriae-rsvp__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wp-on--submit="actions.submit" data-wp-bind--hidden="context.done" data-wp-context="' . esc_attr( (string) wp_json_encode( $form_state ) ) . '">';
		$html .= '<input type="hidden" name="action" value="feriae_rsvp">';
		$html .= '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '">';
		$html .= '<input type="hidden" name="sequence" value="' . esc_attr( (string) $type->sequence ) . '">';
		$html .= '<input type="hidden" name="ts" value="' . esc_attr( Service::sign_timestamp() ) . '">';
		if ( $lead ) {
			$html .= '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
		}
		// Honeypot: hidden from people and assistive tech, filled by bots.
		$html .= '<div class="feriae-rsvp__hp" aria-hidden="true"><label>' . esc_html__( 'Website', 'feriae' ) . ' <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';

		$id     = 'feriae-rsvp-' . $event_id;
		$html  .= '<div class="feriae-rsvp__fields">';
		$html  .= self::field( $id . '-name', __( 'Name', 'feriae' ), '<input id="' . $id . '-name" type="text" name="name" required maxlength="200" autocomplete="name" value="' . esc_attr( $lead->name ?? '' ) . '"' . ( $lead ? ' readonly' : '' ) . '>' );
		$html  .= self::field( $id . '-email', __( 'Email', 'feriae' ), '<input id="' . $id . '-email" type="email" name="email" required autocomplete="email" value="' . esc_attr( $lead->email ?? '' ) . '"' . ( $lead ? ' readonly' : '' ) . '>' );
		if ( $max > 1 ) {
			$options = '';
			for ( $n = 1; $n <= $max; $n++ ) {
				$options .= sprintf( '<option value="%1$d"%2$s>%1$d</option>', $n, selected( $n, $people, false ) );
			}
			$html .= self::field( $id . '-qty', __( 'People', 'feriae' ), '<select id="' . $id . '-qty" name="quantity" data-wp-on--change="actions.setQuantity">' . $options . '</select>' );
		}
		$html .= '</div>';

		if ( Fields::asks( $type ) ) {
			// Without "not going", the respondent's own answers can be required in the browser too.
			$html .= self::people_inputs( $type, $max, $existing, $id, ! $type->setting( 'allow_not_going', true ) );
		}

		if ( $type->setting( 'allow_not_going', true ) ) {
			// When full, only "not going" (or keeping an existing seat) is possible.
			$html .= '<fieldset class="feriae-rsvp__status"><legend>' . esc_html__( 'Are you going?', 'feriae' ) . '</legend>';
			$html .= '<label><input type="radio" name="status" value="going" data-wp-on--change="actions.setStatus"' . checked( $pick_going, true, false ) . disabled( ! $can_go, true, false ) . '> ' . esc_html__( 'Going', 'feriae' ) . '</label>';
			$html .= '<label><input type="radio" name="status" value="not_going" data-wp-on--change="actions.setStatus"' . checked( ! $pick_going, true, false ) . '> ' . esc_html__( 'Not going', 'feriae' ) . '</label>';
			$html .= '</fieldset>';
		} else {
			$html .= '<input type="hidden" name="status" value="going">';
		}

		$html .= '<div class="feriae-rsvp__actions">';
		$html .= '<button type="submit" class="feriae-rsvp__submit wp-element-button" data-wp-bind--disabled="context.busy">' . esc_html( $lead ? __( 'Update RSVP', 'feriae' ) : __( 'RSVP', 'feriae' ) ) . '</button>';
		if ( $lead ) {
			$html .= '<button type="submit" name="cancel" value="1" class="feriae-rsvp__cancel" data-wp-bind--disabled="context.busy">' . esc_html__( 'Cancel my RSVP', 'feriae' ) . '</button>';
		}
		$html .= '</div></form></section>';

		return $html;
	}

	/**
	 * "Join the waitlist" for a full event: same endpoint as RSVPs, status
	 * "waitlist". It asks the same questions as an RSVP, since promotion from
	 * the waitlist confirms people without another form.
	 */
	private static function waitlist_form( int $event_id, TicketType $type ): string {
		$id         = 'feriae-rsvp-wl-' . $event_id;
		$max        = (int) $type->setting( 'max_per_response', 1 );
		$form_state = array(
			'quantity' => 1,
			'going'    => true,
		);
		$html       = '<form class="feriae-rsvp__form feriae-waitlist" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wp-on--submit="actions.submit" data-wp-bind--hidden="context.done" data-wp-context="' . esc_attr( (string) wp_json_encode( $form_state ) ) . '">';
		$html .= '<p class="feriae-waitlist__intro">' . esc_html__( 'Join the waitlist and we’ll confirm you automatically if a spot opens up.', 'feriae' ) . '</p>';
		$html .= '<input type="hidden" name="action" value="feriae_rsvp"><input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '"><input type="hidden" name="sequence" value="' . esc_attr( (string) $type->sequence ) . '">';
		$html .= '<input type="hidden" name="status" value="waitlist"><input type="hidden" name="ts" value="' . esc_attr( Service::sign_timestamp() ) . '">';
		$html .= '<div class="feriae-rsvp__hp" aria-hidden="true"><label>' . esc_html__( 'Website', 'feriae' ) . ' <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		$html .= '<div class="feriae-rsvp__fields">';
		$html .= self::field( $id . '-name', __( 'Name', 'feriae' ), '<input id="' . $id . '-name" type="text" name="name" required maxlength="200" autocomplete="name">' );
		$html .= self::field( $id . '-email', __( 'Email', 'feriae' ), '<input id="' . $id . '-email" type="email" name="email" required autocomplete="email">' );
		if ( $max > 1 ) {
			$options = '';
			for ( $n = 1; $n <= $max; $n++ ) {
				$options .= sprintf( '<option value="%1$d">%1$d</option>', $n );
			}
			$html .= self::field( $id . '-qty', __( 'People', 'feriae' ), '<select id="' . $id . '-qty" name="quantity" data-wp-on--change="actions.setQuantity">' . $options . '</select>' );
		}
		$html .= '</div>';
		if ( Fields::asks( $type ) ) {
			$html .= self::people_inputs( $type, $max, array(), $id, true );
		}
		$html .= '<div class="feriae-rsvp__actions"><button type="submit" class="feriae-rsvp__submit wp-element-button" data-wp-bind--disabled="context.busy">' . esc_html__( 'Join the waitlist', 'feriae' ) . '</button></div></form>';
		return $html;
	}

	/**
	 * Attendee questions: the respondent's answers, then a group per possible
	 * guest (shown as the party size grows, or all at once without JavaScript).
	 *
	 * @param Attendee[] $existing The response being changed, if any.
	 */
	private static function people_inputs( TicketType $type, int $max, array $existing, string $id, bool $lead_required ): string {
		$fields  = Fields::sanitize( $type->setting( 'fields', array() ) );
		$details = Fields::sanitize_details( $type->setting( 'attendee_details', 'none' ) );
		$lead    = $existing[0] ?? null;
		$html    = '';
		for ( $i = 0; $i < $max; $i++ ) {
			$person = $existing[ $i ] ?? null;
			$inner  = '';
			if ( $i > 0 ) {
				// Guests saved without their own details carry the respondent's email; leave those blank.
				$own    = $person && $lead && $person->email !== $lead->email;
				$inner .= Fields::contact_inputs(
					$details,
					'attendees[' . $i . ']',
					$id . '-p' . $i,
					array(
						'name'  => $own ? $person->name : '',
						'email' => $own ? $person->email : '',
					),
					false
				);
			}
			$inner .= Fields::inputs( $fields, 'attendees[' . $i . '][fields]', $id . '-p' . $i, $person ? $person->fields : array(), 0 === $i && $lead_required );
			if ( '' !== $inner ) {
				$html .= Fields::group( 0 === $i ? __( 'Your details', 'feriae' ) : Fields::person_label( $i, true ), $inner, array( 'n' => $i + 1 ) );
			}
		}
		return $html;
	}

	/**
	 * Links to each person's check-in pass.
	 *
	 * @param Attendee[] $people The response's rows.
	 */
	private static function passes( array $people ): string {
		$html = '<p class="feriae-rsvp__passes-title">' . esc_html__( 'Passes for the door, one per person:', 'feriae' ) . '</p><ul class="feriae-rsvp__passes">';
		foreach ( $people as $attendee ) {
			$html .= '<li><a href="' . esc_url( \Feriae\Attendance\Pass::url( $attendee ) ) . '">' . esc_html( $attendee->name ) . '</a> <code>' . esc_html( $attendee->security_code ) . '</code></li>';
		}
		return $html . '</ul>';
	}

	private static function field( string $id, string $label, string $control ): string {
		return '<p class="feriae-rsvp__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>' . $control . '</p>';
	}

	public static function closed_text( string $reason ): string {
		return match ( $reason ) {
			'started', 'ended' => __( 'RSVPs closed when the event started.', 'feriae' ),
			'cancelled'        => __( 'This event has been cancelled.', 'feriae' ),
			'postponed'        => __( 'This event has been postponed. RSVPs will open again when there’s a new date.', 'feriae' ),
			default            => __( 'RSVPs for this event are closed.', 'feriae' ),
		};
	}

	public static function count_text( int $going, ?int $remaining ): string {
		/* translators: %d: number of people going */
		$text = sprintf( _n( '%d going', '%d going', $going, 'feriae' ), $going );
		if ( null !== $remaining ) {
			$text .= ' · ' . ( $remaining > 0
				/* translators: %d: spots left */
				? sprintf( _n( '%d spot left', '%d spots left', $remaining, 'feriae' ), $remaining )
				: __( 'Full', 'feriae' ) );
		}
		return $text;
	}

	public static function outcome_text( string $code ): string {
		$messages = array(
			'going'        => __( 'You’re going! We’ve emailed you a confirmation.', 'feriae' ),
			'not_going'    => __( 'Thanks for letting us know you can’t make it.', 'feriae' ),
			'exists'       => __( 'You’ve already responded. We’ve emailed you a link to change your RSVP.', 'feriae' ),
			'cancelled'    => __( 'Your RSVP has been cancelled.', 'feriae' ),
			'waitlisted'   => __( 'You’re on the waitlist. We’ll confirm you automatically if a spot opens up.', 'feriae' ),
			'full'         => __( 'Sorry, there aren’t enough spots left.', 'feriae' ),
			'closed'       => __( 'RSVPs for this event are closed.', 'feriae' ),
			'rate_limited' => __( 'Too many responses from your connection. Please try again later.', 'feriae' ),
			'fields'       => __( 'Please fill in the details for everyone you’re RSVPing for.', 'feriae' ),
		);
		return $messages[ $code ] ?? __( 'Something went wrong. Please check the form and try again.', 'feriae' );
	}
}
