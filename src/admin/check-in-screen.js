/**
 * Events → Check-in: scan or type a pass's code at the door.
 *
 * - The code field keeps focus, so handheld scanners (which type what they
 *   read, then Enter) check people in one after another.
 * - "Scan with camera" uses the browser's BarcodeDetector where it exists
 *   (Chrome, Edge, Android). Elsewhere, a phone's own camera app opens the
 *   QR link, which lands here with the code looked up.
 * - A code from the page link is only looked up; checking in is a tap.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { Page } from '@wordpress/admin-ui';
import { Button, Card, InputControl, Notice, Stack, Text } from '@wordpress/ui';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const PATH = '/feriae/v1/check-in';
const canScan = typeof window !== 'undefined' && 'BarcodeDetector' in window;

const intentFor = ( result ) =>
	( {
		checked_in: 'success',
		already: 'warning',
		undone: 'info',
		found: 'info',
	} )[ result ] || 'error';

/**
 * The person, what they booked, warnings, and the date's tally.
 *
 * @param {Object}   props
 * @param {Object}   props.data    Check-in response (or an error's data).
 * @param {boolean}  props.busy    A request is running.
 * @param {Function} props.onCheck Check in (from a looked-up code).
 * @param {Function} props.onUndo  Undo a check-in.
 */
function Result( { data, busy, onCheck, onUndo } ) {
	const { attendee, warnings = [], counts } = data;
	if ( ! attendee ) {
		return null;
	}
	return (
		<Card.Root>
			<Card.Header>
				<Card.Title>{ attendee.name }</Card.Title>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="md">
					<Text>
						{ [ attendee.event, attendee.date, attendee.ticket ]
							.filter( Boolean )
							.join( ' · ' ) }
					</Text>
					<Text>
						{ sprintf(
							/* translators: %s: check-in code */
							__( 'Code: %s', 'feriae' ),
							attendee.code
						) }
						{ attendee.checked_in &&
							` · ${ sprintf(
								/* translators: %s: time */
								__( 'Checked in at %s', 'feriae' ),
								attendee.checked_in_at
							) }` }
					</Text>
					{ warnings.map( ( warning ) => (
						<Notice.Root key={ warning.code } intent="warning">
							<Notice.Description>
								{ warning.message }
							</Notice.Description>
						</Notice.Root>
					) ) }
					{ counts && (
						<Text>
							{ sprintf(
								/* translators: 1: people checked in, 2: people booked */
								__(
									'%1$d of %2$d checked in for this date',
									'feriae'
								),
								counts.checked_in,
								counts.going
							) }
						</Text>
					) }
					<Stack direction="row" gap="sm">
						{ ! attendee.checked_in &&
							attendee.status === 'going' && (
								<Button
									onClick={ () => onCheck( attendee.code ) }
									disabled={ busy }
								>
									{ __( 'Check in', 'feriae' ) }
								</Button>
							) }
						{ attendee.checked_in && (
							<Button
								variant="outline"
								onClick={ () => onUndo( attendee.code ) }
								disabled={ busy }
							>
								{ __( 'Undo check-in', 'feriae' ) }
							</Button>
						) }
					</Stack>
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}

export default function CheckInScreen( { root } ) {
	const [ code, setCode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ outcome, setOutcome ] = useState( null );
	const [ recent, setRecent ] = useState( [] );
	const [ scanning, setScanning ] = useState( false );
	const input = useRef();
	const video = useRef();

	const run = useCallback( async ( value, options = {} ) => {
		const trimmed = String( value ).trim();
		if ( ! trimmed ) {
			return;
		}
		setBusy( true );
		try {
			const data = await apiFetch(
				options.lookUp
					? { path: addQueryArgs( PATH, { code: trimmed } ) }
					: {
							path: PATH,
							method: 'POST',
							data: { code: trimmed, undo: !! options.undo },
					  }
			);
			setOutcome( data );
			if ( data.result === 'checked_in' ) {
				setRecent( ( list ) =>
					[ data.attendee, ...list ].slice( 0, 10 )
				);
			}
		} catch ( error ) {
			setOutcome( {
				result: 'error',
				message:
					error.message ||
					__( 'Something went wrong. Try again.', 'feriae' ),
				...( error.data?.data || {} ),
			} );
		}
		setBusy( false );
		setCode( '' );
		input.current?.focus();
	}, [] );

	// A scanned pass opens this page with its code: show who it is.
	useEffect( () => {
		if ( root.dataset.code ) {
			run( root.dataset.code, { lookUp: true } );
		}
	}, [ root, run ] );

	// Camera scanning, where the browser can read QR codes itself.
	useEffect( () => {
		if ( ! scanning ) {
			return undefined;
		}
		let stream;
		let timer;
		let last = '';
		let cancelled = false;
		const detector = new window.BarcodeDetector( {
			formats: [ 'qr_code' ],
		} );
		window.navigator.mediaDevices
			.getUserMedia( { video: { facingMode: 'environment' } } )
			.then( ( media ) => {
				if ( cancelled ) {
					media.getTracks().forEach( ( track ) => track.stop() );
					return;
				}
				stream = media;
				video.current.srcObject = media;
				video.current.play();
				timer = window.setInterval( async () => {
					const found = await detector
						.detect( video.current )
						.catch( () => [] );
					const value = found[ 0 ]?.rawValue;
					// The same pass stays in view for a while; check it in once.
					if ( value && value !== last ) {
						last = value;
						run( value );
					}
				}, 400 );
			} )
			.catch( () => {
				setScanning( false );
				setOutcome( {
					result: 'error',
					message: __(
						'The camera isn’t available. Allow camera access (the site must use HTTPS), or type the code.',
						'feriae'
					),
				} );
			} );
		return () => {
			cancelled = true;
			window.clearInterval( timer );
			stream?.getTracks().forEach( ( track ) => track.stop() );
		};
	}, [ scanning, run ] );

	return (
		<Page
			title={ __( 'Check-in', 'feriae' ) }
			subTitle={ __(
				'Scan a pass or type its code. Each code checks in one person.',
				'feriae'
			) }
		>
			<Stack direction="column" gap="lg" className="feriae-check-in">
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						run( code );
					} }
				>
					<Stack direction="row" gap="sm" align="end" wrap="wrap">
						<InputControl
							ref={ input }
							label={ __( 'Check-in code', 'feriae' ) }
							description={ __(
								'A handheld scanner can type here too.',
								'feriae'
							) }
							value={ code }
							onValueChange={ setCode }
							autoComplete="off"
							autoCapitalize="characters"
							spellCheck={ false }
							// eslint-disable-next-line jsx-a11y/no-autofocus -- the screen's only task; scanners type into the focused field.
							autoFocus
						/>
						<Button type="submit" disabled={ busy || ! code }>
							{ __( 'Check in', 'feriae' ) }
						</Button>
						{ canScan && (
							<Button
								variant="outline"
								onClick={ () => setScanning( ! scanning ) }
							>
								{ scanning
									? __( 'Stop camera', 'feriae' )
									: __( 'Scan with camera', 'feriae' ) }
							</Button>
						) }
					</Stack>
				</form>

				{ scanning && (
					<video
						ref={ video }
						className="feriae-check-in__video"
						muted
						playsInline
						aria-label={ __( 'Camera', 'feriae' ) }
					/>
				) }

				<div role="status" aria-live="polite">
					{ outcome && (
						<Stack direction="column" gap="md">
							<Notice.Root intent={ intentFor( outcome.result ) }>
								<Notice.Description>
									{ outcome.message ||
										( outcome.result === 'found' &&
											__(
												'Found. Check in when they arrive.',
												'feriae'
											) ) }
								</Notice.Description>
							</Notice.Root>
							<Result
								data={ outcome }
								busy={ busy }
								onCheck={ ( value ) => run( value ) }
								onUndo={ ( value ) =>
									run( value, { undo: true } )
								}
							/>
						</Stack>
					) }
				</div>

				{ recent.length > 0 && (
					<section aria-labelledby="feriae-check-in-recent">
						<h2 id="feriae-check-in-recent">
							{ __( 'Checked in on this device', 'feriae' ) }
						</h2>
						<ul>
							{ recent.map( ( person ) => (
								<li
									key={ `${ person.id }-${ person.checked_in_at }` }
								>
									{ person.checked_in_at } · { person.name } ·{ ' ' }
									{ person.ticket }
								</li>
							) ) }
						</ul>
					</section>
				) }
			</Stack>
		</Page>
	);
}
