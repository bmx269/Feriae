import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	DropdownMenu,
	Flex,
	MenuGroup,
	MenuItem,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { getSettings } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { DateField, display } from './event-panel';

const EMPTY = { rrule: '', rdates: [], exdates: [] };

const STATUS_LABELS = {
	cancelled: __( 'Cancelled', 'feriae' ),
	postponed: __( 'Postponed', 'feriae' ),
};
const DAYS = [ 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ];
const DAY_NAMES = () => [
	__( 'Monday', 'feriae' ),
	__( 'Tuesday', 'feriae' ),
	__( 'Wednesday', 'feriae' ),
	__( 'Thursday', 'feriae' ),
	__( 'Friday', 'feriae' ),
	__( 'Saturday', 'feriae' ),
	__( 'Sunday', 'feriae' ),
];
/* translators: one-letter weekday abbreviations, Monday to Sunday */
const DAY_LETTERS = () => __( 'M,T,W,T,F,S,S', 'feriae' ).split( ',' );
const ORDINALS = () => [
	'',
	__( 'first', 'feriae' ),
	__( 'second', 'feriae' ),
	__( 'third', 'feriae' ),
	__( 'fourth', 'feriae' ),
	__( 'fifth', 'feriae' ),
];
/** Parts the simple controls can edit; any other rule is edited as text. */
const SIMPLE_PARTS = [
	'FREQ',
	'INTERVAL',
	'BYDAY',
	'BYMONTHDAY',
	'COUNT',
	'UNTIL',
];

/**
 * @param {string} rrule Rule string.
 * @return {Object} Parts keyed by name.
 */
function parse( rrule ) {
	const parts = {};
	( rrule || '' )
		.replace( /^RRULE:/i, '' )
		.split( ';' )
		.filter( Boolean )
		.forEach( ( pair ) => {
			const [ name, value = '' ] = pair.split( '=' );
			parts[ name.toUpperCase() ] = value.toUpperCase();
		} );
	return parts;
}

/**
 * @param {Object} parts Parts keyed by name.
 * @return {string} Rule string, in the order the server writes it.
 */
function build( parts ) {
	return [ 'FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'COUNT', 'UNTIL' ]
		.filter( ( name ) => parts[ name ] )
		.filter(
			( name ) => ! ( name === 'INTERVAL' && parts[ name ] === '1' )
		)
		.map( ( name ) => `${ name }=${ parts[ name ] }` )
		.join( ';' );
}

/**
 * Start as a wall-clock Date (see event-panel: stored times are treated as UTC).
 *
 * @param {string} start Stored "Y-m-d H:i:s" start.
 * @return {Date} Date.
 */
const startDate = ( start ) => new Date( `${ start.replace( ' ', 'T' ) }Z` );

/**
 * Monthly choices for a start date: "day 7", "the first Tuesday", and, in the
 * last week of a month, "the last Tuesday".
 *
 * @param {string} start Stored start.
 * @return {Array} Options with their BYDAY/BYMONTHDAY values.
 */
function monthlyOptions( start ) {
	const date = startDate( start );
	const day = date.getUTCDate();
	const code = DAYS[ ( date.getUTCDay() + 6 ) % 7 ];
	const name = DAY_NAMES()[ ( date.getUTCDay() + 6 ) % 7 ];
	const nth = Math.ceil( day / 7 );
	const length = new Date(
		Date.UTC( date.getUTCFullYear(), date.getUTCMonth() + 1, 0 )
	).getUTCDate();
	const options = [
		{
			value: 'day',
			/* translators: %d: day of the month */
			label: sprintf( __( 'On day %d', 'feriae' ), day ),
			parts: { BYMONTHDAY: String( day ), BYDAY: '' },
		},
	];
	if ( nth <= 4 ) {
		options.push( {
			value: 'nth',
			label: sprintf(
				/* translators: 1: ordinal, e.g. "second"; 2: weekday */
				__( 'On the %1$s %2$s', 'feriae' ),
				ORDINALS()[ nth ],
				name
			),
			parts: { BYDAY: `${ nth }${ code }`, BYMONTHDAY: '' },
		} );
	}
	if ( day > length - 7 ) {
		options.push( {
			value: 'last',
			/* translators: %s: weekday */
			label: sprintf( __( 'On the last %s', 'feriae' ), name ),
			parts: { BYDAY: `-1${ code }`, BYMONTHDAY: '' },
		} );
	}
	return options;
}

/**
 * Upcoming occurrence ids by start ("Y-m-d H:i:s"), for the per-date actions.
 *
 * @param {number}  postId  Event id.
 * @param {boolean} enabled Fetch only when the saved event is published.
 * @return {Object} Map of start to occurrence id.
 */
function useOccurrenceIds( postId, enabled ) {
	const [ ids, setIds ] = useState( {} );
	useEffect( () => {
		if ( ! enabled ) {
			setIds( {} );
			return;
		}
		apiFetch( {
			path: `/feriae/v1/occurrences?event=${ postId }&per_page=100`,
		} )
			.then( ( items ) =>
				setIds(
					Object.fromEntries(
						items.map( ( item ) => [
							item.start.slice( 0, 19 ).replace( 'T', ' ' ),
							item.id,
						] )
					)
				)
			)
			.catch( () => setIds( {} ) );
	}, [ postId, enabled ] );
	return ids;
}

/**
 * Live preview: the rule in words and the next dates, from the server so it
 * matches exactly what will be saved.
 *
 * @param {Object}   props
 * @param {Object}   props.meta       Post meta.
 * @param {Object}   props.recurrence Recurrence meta.
 * @param {Function} props.onSkip     Skips a date.
 * @param {Array}    props.dateStatus Dates cancelled or postponed on their own.
 * @param {Function} props.onStatus   Sets a date's status ('' for scheduled).
 * @param {number}   props.postId     Event id.
 */
function Preview( { meta, recurrence, onSkip, dateStatus, onStatus, postId } ) {
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const { status, isDirty, isSaving } = useSelect(
		( select ) => ( {
			status: select( editorStore ).getEditedPostAttribute( 'status' ),
			isDirty: select( editorStore ).isEditedPostDirty(),
			isSaving: select( editorStore ).isSavingPost(),
		} ),
		[]
	);
	const canSplit = status === 'publish' && ! isDirty && ! isSaving;
	const ids = useOccurrenceIds( postId, canSplit );
	const settings = getSettings();

	const request = JSON.stringify( {
		start: meta._feriae_start,
		end: meta._feriae_end || '',
		timezone: meta._feriae_timezone || '',
		all_day: !! meta._feriae_all_day,
		rrule: recurrence.rrule,
		rdates: recurrence.rdates,
		exdates: recurrence.exdates,
		limit: 8,
	} );

	useEffect( () => {
		const timer = setTimeout( () => {
			apiFetch( {
				path: '/feriae/v1/recurrence/preview',
				method: 'POST',
				data: JSON.parse( request ),
			} )
				.then( ( data ) => {
					setResult( data );
					setError( '' );
				} )
				.catch( ( e ) => {
					setResult( null );
					setError( e.message );
				} );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ request ] );

	const act = async ( id, action ) => {
		setBusy( true );
		try {
			const created = await apiFetch( {
				path: `/feriae/v1/events/${ postId }/occurrences/${ id }/${ action }`,
				method: 'POST',
			} );
			window.location.href = created.edit_link;
		} catch ( e ) {
			setError( e.message );
			setBusy( false );
		}
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! result ) {
		return <Spinner />;
	}
	const format = meta._feriae_all_day
		? settings.formats.date
		: `${ settings.formats.date } ${ settings.formats.time }`;

	return (
		<div className="feriae-editor-panel__preview">
			{ result.description && <p>{ result.description }</p> }
			<ul aria-label={ __( 'Next dates', 'feriae' ) }>
				{ result.dates.map( ( date, index ) => {
					const dateState = dateStatus.find(
						( entry ) => entry.date === date.start.slice( 0, 10 )
					)?.status;
					return (
						<li key={ date.start }>
							<span>
								{ display( format, date.start ) }
								{ dateState && (
									<span className="feriae-editor-panel__tag">
										{ STATUS_LABELS[ dateState ] }
									</span>
								) }
							</span>
							<DropdownMenu
								icon="ellipsis"
								label={ sprintf(
									/* translators: %s: date */
									__( 'Actions for %s', 'feriae' ),
									display( format, date.start )
								) }
								toggleProps={ {
									size: 'small',
									disabled: busy,
								} }
							>
								{ ( { onClose } ) => (
									<>
										<MenuGroup>
											{ dateState ? (
												<MenuItem
													onClick={ () => {
														onStatus(
															date.start,
															''
														);
														onClose();
													} }
												>
													{ __(
														'Mark this date as scheduled',
														'feriae'
													) }
												</MenuItem>
											) : (
												<>
													<MenuItem
														onClick={ () => {
															onStatus(
																date.start,
																'cancelled'
															);
															onClose();
														} }
													>
														{ __(
															'Cancel this date',
															'feriae'
														) }
													</MenuItem>
													<MenuItem
														onClick={ () => {
															onStatus(
																date.start,
																'postponed'
															);
															onClose();
														} }
													>
														{ __(
															'Postpone this date',
															'feriae'
														) }
													</MenuItem>
												</>
											) }
										</MenuGroup>
										{ index > 0 && (
											<MenuGroup>
												<MenuItem
													onClick={ () => {
														onSkip( date.start );
														onClose();
													} }
												>
													{ __(
														'Skip this date',
														'feriae'
													) }
												</MenuItem>
												<MenuItem
													disabled={
														! ids[ date.start ]
													}
													onClick={ () =>
														act(
															ids[ date.start ],
															'detach'
														)
													}
												>
													{ __(
														'Edit only this date',
														'feriae'
													) }
												</MenuItem>
												<MenuItem
													disabled={
														! ids[ date.start ]
													}
													onClick={ () =>
														act(
															ids[ date.start ],
															'split'
														)
													}
												>
													{ __(
														'Edit this and following dates',
														'feriae'
													) }
												</MenuItem>
												{ ! canSplit && (
													<p className="feriae-editor-panel__hint">
														{ __(
															'Publish and save your changes to edit one date or the rest of the series separately.',
															'feriae'
														) }
													</p>
												) }
											</MenuGroup>
										) }
									</>
								) }
							</DropdownMenu>
						</li>
					);
				} ) }
			</ul>
			{ result.more && (
				<p className="feriae-editor-panel__hint">
					{ result.finite
						? __( 'More dates follow.', 'feriae' )
						: __(
								'Repeats with no end date. Dates are added two years ahead.',
								'feriae'
						  ) }
				</p>
			) }
		</div>
	);
}

export default function RepeatPanel( { meta, setMeta, postId, config } ) {
	const recurrence = { ...EMPTY, ...( meta._feriae_recurrence || {} ) };
	const update = ( changes ) =>
		setMeta( { _feriae_recurrence: { ...recurrence, ...changes } } );
	// One date cancelled or postponed on its own (see Content\EventStatus).
	const dateStatus = meta._feriae_date_status || [];
	const setDateStatus = ( date, status, reason = '' ) => {
		const day = date.slice( 0, 10 );
		const others = dateStatus.filter( ( entry ) => entry.date !== day );
		setMeta( {
			_feriae_date_status: status
				? [ ...others, { date: day, status, reason } ].sort( ( a, b ) =>
						a.date.localeCompare( b.date )
				  )
				: others,
		} );
	};
	const parts = parse( recurrence.rrule );
	const isCustom =
		recurrence.rrule &&
		( Object.keys( parts ).some(
			( name ) => ! SIMPLE_PARTS.includes( name )
		) ||
			( parts.FREQ === 'MONTHLY' &&
				! monthlyOptions(
					meta._feriae_start || '2000-01-01 00:00:00'
				).some(
					( option ) =>
						( option.parts.BYDAY || '' ) ===
							( parts.BYDAY || '' ) &&
						( option.parts.BYMONTHDAY || '' ) ===
							( parts.BYMONTHDAY || '' )
				) ) );
	const [ custom, setCustom ] = useState( isCustom );
	// The picker reports every change (day, then time), so an extra date is
	// added with a button rather than on change.
	const [ draft, setDraft ] = useState( '' );

	if ( ! meta._feriae_start ) {
		return (
			<p className="feriae-editor-panel__hint">
				{ __( 'Set a start date to repeat this event.', 'feriae' ) }
			</p>
		);
	}

	const setParts = ( changes ) =>
		update( { rrule: build( { ...parts, ...changes } ) } );
	const start = startDate( meta._feriae_start );
	const weekday = DAYS[ ( start.getUTCDay() + 6 ) % 7 ];

	const frequency = custom ? 'CUSTOM' : parts.FREQ || '';
	const setFrequency = ( value ) => {
		if ( value === 'CUSTOM' ) {
			setCustom( true );
			return;
		}
		setCustom( false );
		if ( ! value ) {
			update( { rrule: '' } );
			return;
		}
		const next = { FREQ: value, COUNT: parts.COUNT, UNTIL: parts.UNTIL };
		if ( value === 'WEEKLY' ) {
			next.BYDAY = weekday;
		}
		if ( value === 'MONTHLY' ) {
			Object.assign( next, monthlyOptions( meta._feriae_start )[ 0 ].parts );
		}
		update( { rrule: build( next ) } );
	};

	const units = {
		DAILY: ( n ) => _n( 'day', 'days', n, 'feriae' ),
		WEEKLY: ( n ) => _n( 'week', 'weeks', n, 'feriae' ),
		MONTHLY: ( n ) => _n( 'month', 'months', n, 'feriae' ),
		YEARLY: ( n ) => _n( 'year', 'years', n, 'feriae' ),
	};
	const interval = parseInt( parts.INTERVAL || '1', 10 );
	// A weekly rule without BYDAY repeats on the start's weekday.
	const days = parts.BYDAY
		? parts.BYDAY.split( ',' ).filter( Boolean )
		: [ weekday ];
	const monthly = monthlyOptions( meta._feriae_start );
	const monthlyValue =
		monthly.find(
			( option ) =>
				( option.parts.BYDAY || '' ) === ( parts.BYDAY || '' ) &&
				( option.parts.BYMONTHDAY || '' ) === ( parts.BYMONTHDAY || '' )
		)?.value || 'day';
	let ends = 'never';
	if ( parts.COUNT ) {
		ends = 'count';
	} else if ( parts.UNTIL ) {
		ends = 'until';
	}
	const untilValue = parts.UNTIL
		? `${ parts.UNTIL.slice( 0, 4 ) }-${ parts.UNTIL.slice(
				4,
				6
		  ) }-${ parts.UNTIL.slice( 6, 8 ) } 00:00:00`
		: '';

	return (
		<Flex direction="column" gap={ 4 }>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Repeats', 'feriae' ) }
				value={ frequency }
				options={ [
					{ value: '', label: __( 'Does not repeat', 'feriae' ) },
					{ value: 'DAILY', label: __( 'Daily', 'feriae' ) },
					{ value: 'WEEKLY', label: __( 'Weekly', 'feriae' ) },
					{ value: 'MONTHLY', label: __( 'Monthly', 'feriae' ) },
					{ value: 'YEARLY', label: __( 'Yearly', 'feriae' ) },
					{
						value: 'CUSTOM',
						label: __( 'Custom rule', 'feriae' ),
					},
				] }
				onChange={ setFrequency }
			/>

			{ custom && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Rule (iCalendar RRULE)', 'feriae' ) }
					help={ __(
						'For example FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1 for the last weekday of each month.',
						'feriae'
					) }
					value={ recurrence.rrule }
					onChange={ ( rrule ) => update( { rrule } ) }
				/>
			) }

			{ ! custom && parts.FREQ && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ sprintf(
							/* translators: %s: unit, e.g. "weeks" */
							__( 'Every how many %s', 'feriae' ),
							units[ parts.FREQ ]( 2 )
						) }
						value={ String( interval ) }
						onChange={ ( value ) =>
							setParts( {
								INTERVAL: String(
									Math.max( 1, parseInt( value, 10 ) || 1 )
								),
							} )
						}
					/>

					{ parts.FREQ === 'WEEKLY' && (
						<fieldset className="feriae-editor-panel__days">
							<legend>{ __( 'On', 'feriae' ) }</legend>
							{ DAYS.map( ( code, index ) => (
								<Button
									key={ code }
									size="small"
									variant={
										days.includes( code )
											? 'primary'
											: 'secondary'
									}
									aria-pressed={ days.includes( code ) }
									aria-label={ DAY_NAMES()[ index ] }
									onClick={ () => {
										const next = days.includes( code )
											? days.filter( ( d ) => d !== code )
											: [ ...days, code ];
										setParts( {
											BYDAY: DAYS.filter( ( d ) =>
												( next.length
													? next
													: [ weekday ]
												).includes( d )
											).join( ',' ),
										} );
									} }
								>
									{ DAY_LETTERS()[ index ] }
								</Button>
							) ) }
						</fieldset>
					) }

					{ parts.FREQ === 'MONTHLY' && (
						<RadioControl
							label={ __( 'On', 'feriae' ) }
							selected={ monthlyValue }
							options={ monthly.map( ( { value, label } ) => ( {
								value,
								label,
							} ) ) }
							onChange={ ( value ) =>
								setParts(
									monthly.find(
										( option ) => option.value === value
									).parts
								)
							}
						/>
					) }

					<RadioControl
						label={ __( 'Ends', 'feriae' ) }
						selected={ ends }
						options={ [
							{
								value: 'never',
								label: __( 'Never', 'feriae' ),
							},
							{
								value: 'until',
								label: __( 'On a date', 'feriae' ),
							},
							{
								value: 'count',
								label: __(
									'After a number of dates',
									'feriae'
								),
							},
						] }
						onChange={ ( value ) => {
							const last = new Date( start );
							last.setUTCMonth( last.getUTCMonth() + 3 );
							setParts( {
								COUNT: value === 'count' ? '10' : '',
								UNTIL:
									value === 'until'
										? last
												.toISOString()
												.slice( 0, 10 )
												.replaceAll( '-', '' )
										: '',
							} );
						} }
					/>
					{ ends === 'until' && (
						<DateField
							label={ __( 'Last date', 'feriae' ) }
							value={ untilValue }
							allDay
							onChange={ ( value ) =>
								setParts( {
									UNTIL: value
										.slice( 0, 10 )
										.replaceAll( '-', '' ),
								} )
							}
							is12Hour={ config.timeFormat12h }
						/>
					) }
					{ ends === 'count' && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 1 }
							label={ __( 'Number of dates', 'feriae' ) }
							value={ parts.COUNT }
							onChange={ ( value ) =>
								setParts( {
									COUNT: String(
										Math.max(
											1,
											parseInt( value, 10 ) || 1
										)
									),
								} )
							}
						/>
					) }
				</>
			) }

			{ ( recurrence.rrule || recurrence.rdates.length > 0 ) && (
				<Preview
					meta={ meta }
					recurrence={ recurrence }
					postId={ postId }
					onSkip={ ( date ) =>
						update( {
							exdates: [
								...recurrence.exdates,
								date.slice( 0, 10 ),
							].sort(),
						} )
					}
					dateStatus={ dateStatus }
					onStatus={ setDateStatus }
				/>
			) }

			{ dateStatus.length > 0 && (
				<div>
					<h3 className="feriae-editor-panel__subhead">
						{ __( 'Cancelled and postponed dates', 'feriae' ) }
					</h3>
					<ul className="feriae-editor-panel__list">
						{ dateStatus.map( ( entry ) => (
							<li key={ entry.date }>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: 1: date, 2: status such as "Cancelled" */
										__( '%1$s (%2$s): note', 'feriae' ),
										display(
											getSettings().formats.date,
											`${ entry.date } 00:00:00`
										),
										STATUS_LABELS[ entry.status ]
									) }
									help={ __(
										'Optional, shown with the date. For example, the reason.',
										'feriae'
									) }
									value={ entry.reason || '' }
									onChange={ ( reason ) =>
										setDateStatus(
											entry.date,
											entry.status,
											reason
										)
									}
								/>
								<Button
									variant="link"
									onClick={ () =>
										setDateStatus( entry.date, '' )
									}
								>
									{ __( 'Mark as scheduled', 'feriae' ) }
								</Button>
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ recurrence.exdates.length > 0 && (
				<div>
					<h3 className="feriae-editor-panel__subhead">
						{ __( 'Skipped dates', 'feriae' ) }
					</h3>
					<ul className="feriae-editor-panel__list">
						{ recurrence.exdates.map( ( date ) => (
							<li key={ date }>
								<span>
									{ display(
										getSettings().formats.date,
										`${ date } 00:00:00`
									) }
								</span>
								<Button
									variant="link"
									onClick={ () =>
										update( {
											exdates: recurrence.exdates.filter(
												( d ) => d !== date
											),
										} )
									}
								>
									{ __( 'Restore', 'feriae' ) }
								</Button>
							</li>
						) ) }
					</ul>
				</div>
			) }

			<div>
				<h3 className="feriae-editor-panel__subhead">
					{ __( 'Extra dates', 'feriae' ) }
				</h3>
				{ recurrence.rdates.length > 0 && (
					<ul className="feriae-editor-panel__list">
						{ recurrence.rdates.map( ( date ) => (
							<li key={ date }>
								<span>
									{ display(
										`${ getSettings().formats.date } ${
											getSettings().formats.time
										}`,
										date
									) }
								</span>
								<Button
									variant="link"
									onClick={ () =>
										update( {
											rdates: recurrence.rdates.filter(
												( d ) => d !== date
											),
										} )
									}
								>
									{ __( 'Remove', 'feriae' ) }
								</Button>
							</li>
						) ) }
					</ul>
				) }
				<DateField
					label={ __( 'New date', 'feriae' ) }
					value={ draft }
					emptyLabel={ __( 'Choose…', 'feriae' ) }
					onChange={ setDraft }
					onClear={ () => setDraft( '' ) }
					is12Hour={ config.timeFormat12h }
				/>
				<Button
					variant="secondary"
					size="compact"
					disabled={ ! draft }
					onClick={ () => {
						update( {
							rdates: [
								...new Set( [ ...recurrence.rdates, draft ] ),
							].sort(),
						} );
						setDraft( '' );
					} }
				>
					{ __( 'Add date', 'feriae' ) }
				</Button>
			</div>
		</Flex>
	);
}
