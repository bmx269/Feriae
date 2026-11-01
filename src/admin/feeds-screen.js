/**
 * Events → Calendar import: iCal feeds kept in sync, and one-off .ics files.
 *
 * Feed addresses are write-only here: the list shows a masked address, and
 * editing a feed keeps its address unless a new one is entered.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { Page } from '@wordpress/admin-ui';
import {
	Button,
	Card,
	InputControl,
	Notice,
	SelectControl,
	Stack,
	Text,
} from '@wordpress/ui';
import {
	useCallback,
	useEffect,
	useId,
	useMemo,
	useState,
} from '@wordpress/element';

const PATH = '/openevents/v1/feeds';

const FREQUENCIES = [
	{ value: 'hourly', label: __( 'Every hour', 'openevents' ) },
	{ value: 'daily', label: __( 'Every day', 'openevents' ) },
	{ value: 'weekly', label: __( 'Every week', 'openevents' ) },
	{ value: 'manual', label: __( 'Only when I sync', 'openevents' ) },
];

const STATUSES = [
	{ value: 'publish', label: __( 'Publish them', 'openevents' ) },
	{ value: 'draft', label: __( 'Save as drafts', 'openevents' ) },
];

const when = ( utc ) =>
	utc ? new Date( `${ utc.replace( ' ', 'T' ) }Z` ).toLocaleString() : '';

const noticeFor = ( report ) => ( {
	intent: report.ok ? 'success' : 'error',
	text: report.message,
} );

/**
 * Category, status, post type, and (for feeds) how often to sync.
 *
 * @param {Object}   props
 * @param {Object}   props.value         Current options.
 * @param {Function} props.onChange      Receives the changed options.
 * @param {Object}   props.config        Categories and post types.
 * @param {boolean}  props.withFrequency Show the sync frequency.
 */
function Options( { value, onChange, config, withFrequency } ) {
	const set = ( key ) => ( v ) => onChange( { ...value, [ key ]: v } );
	return (
		<Stack direction="row" gap="md" wrap="wrap">
			{ withFrequency && (
				<SelectControl
					label={ __( 'Sync', 'openevents' ) }
					items={ FREQUENCIES }
					value={ value.frequency }
					onValueChange={ set( 'frequency' ) }
				/>
			) }
			<SelectControl
				label={ __( 'Category', 'openevents' ) }
				items={ [
					{ value: '0', label: __( 'None', 'openevents' ) },
					...config.categories.map( ( c ) => ( {
						value: String( c.value ),
						label: c.label,
					} ) ),
				] }
				value={ String( value.category ) }
				onValueChange={ set( 'category' ) }
			/>
			<SelectControl
				label={ __( 'New events', 'openevents' ) }
				items={ STATUSES }
				value={ value.post_status }
				onValueChange={ set( 'post_status' ) }
			/>
			{ config.types.length > 1 && (
				<SelectControl
					label={ __( 'Create as', 'openevents' ) }
					items={ config.types }
					value={ value.post_type }
					onValueChange={ set( 'post_type' ) }
				/>
			) }
		</Stack>
	);
}

const payload = ( options ) => ( {
	...options,
	category: Number( options.category ) || 0,
} );

/**
 * Address, name, and options; used to add a feed and to edit one.
 *
 * @param {Object}   props
 * @param {Object}   props.initial  Starting values.
 * @param {Object}   props.config   Categories and post types.
 * @param {boolean}  props.editing  Editing an existing feed.
 * @param {string}   props.submit   Button label.
 * @param {Function} props.onSubmit Receives the values; returns a promise.
 */
function FeedForm( { initial, config, editing, submit, onSubmit } ) {
	const [ values, setValues ] = useState( initial );
	const [ busy, setBusy ] = useState( false );
	return (
		<form
			onSubmit={ async ( event ) => {
				event.preventDefault();
				setBusy( true );
				const done = await onSubmit( payload( values ) );
				setBusy( false );
				if ( done && ! editing ) {
					setValues( initial );
				}
			} }
		>
			<Stack direction="column" gap="md">
				<InputControl
					label={ __( 'iCal address', 'openevents' ) }
					description={
						editing
							? __(
									'Leave blank to keep the current address.',
									'openevents'
							  )
							: __(
									'Google Calendar: Settings → your calendar → Integrate calendar → “Public address in iCal format”, or the secret address for a private calendar. Outlook: Publish a calendar → ICS link. Apple Calendar: Share → Public Calendar.',
									'openevents'
							  )
					}
					type="url"
					autoComplete="off"
					spellCheck={ false }
					required={ ! editing }
					value={ values.url }
					onValueChange={ ( url ) => setValues( { ...values, url } ) }
				/>
				<InputControl
					label={ __( 'Name', 'openevents' ) }
					description={ __(
						'Shown on this screen only.',
						'openevents'
					) }
					value={ values.name }
					onValueChange={ ( name ) =>
						setValues( { ...values, name } )
					}
				/>
				<Options
					value={ values }
					onChange={ setValues }
					config={ config }
					withFrequency
				/>
				<div>
					<Button
						type="submit"
						disabled={ busy || ( ! editing && ! values.url ) }
					>
						{ busy ? __( 'Importing…', 'openevents' ) : submit }
					</Button>
				</div>
			</Stack>
		</form>
	);
}

/**
 * One-off import of an .ics file.
 *
 * @param {Object}   props
 * @param {Object}   props.config Categories, post types, size limit.
 * @param {Function} props.onDone Receives the import report (or an error).
 */
function FileImport( { config, onDone } ) {
	const id = useId();
	const [ file, setFile ] = useState( null );
	const [ options, setOptions ] = useState( {
		category: '0',
		post_status: 'publish',
		post_type: config.defaultType,
	} );
	const [ busy, setBusy ] = useState( false );

	const run = async ( event ) => {
		event.preventDefault();
		if ( file.size > config.maxFile ) {
			onDone( {
				ok: false,
				message: __(
					'That file is larger than 5 MB. Import a shorter date range.',
					'openevents'
				),
			} );
			return;
		}
		setBusy( true );
		try {
			onDone(
				await apiFetch( {
					path: `${ PATH }/import`,
					method: 'POST',
					data: { ics: await file.text(), ...payload( options ) },
				} )
			);
		} catch ( error ) {
			onDone( { ok: false, message: error.message } );
		}
		setBusy( false );
	};

	return (
		<form onSubmit={ run }>
			<Stack direction="column" gap="md">
				<div className="oe-file-field">
					<label htmlFor={ id }>
						{ __( 'iCal file (.ics)', 'openevents' ) }
					</label>
					<input
						id={ id }
						type="file"
						accept=".ics,text/calendar"
						onChange={ ( event ) =>
							setFile( event.target.files[ 0 ] || null )
						}
					/>
				</div>
				<Options
					value={ options }
					onChange={ setOptions }
					config={ config }
				/>
				<div>
					<Button type="submit" disabled={ busy || ! file }>
						{ busy
							? __( 'Importing…', 'openevents' )
							: __( 'Import file', 'openevents' ) }
					</Button>
				</div>
			</Stack>
		</form>
	);
}

export default function FeedsScreen( { root } ) {
	const config = useMemo(
		() => ( {
			categories: JSON.parse( root.dataset.categories || '[]' ),
			types: JSON.parse( root.dataset.postTypes || '[]' ),
			defaultType: root.dataset.defaultType,
			maxFile: Number( root.dataset.maxFile ) || 0,
		} ),
		[ root ]
	);
	const blank = useMemo(
		() => ( {
			url: '',
			name: '',
			frequency: 'daily',
			category: '0',
			post_status: 'publish',
			post_type: config.defaultType,
		} ),
		[ config ]
	);
	const [ feeds, setFeeds ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ refresh, setRefresh ] = useState( 0 );
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 20,
		search: '',
		fields: [ 'address', 'events', 'category_name', 'frequency', 'last' ],
		titleField: 'name',
	} );

	useEffect( () => {
		setIsLoading( true );
		apiFetch( { path: PATH } )
			.then( setFeeds )
			.catch( ( e ) => setNotice( { intent: 'error', text: e.message } ) )
			.finally( () => setIsLoading( false ) );
	}, [ refresh ] );

	const reload = () => setRefresh( ( n ) => n + 1 );

	const add = async ( values ) => {
		try {
			const feed = await apiFetch( {
				path: PATH,
				method: 'POST',
				data: values,
			} );
			setNotice(
				feed.last
					? noticeFor( feed.last )
					: {
							intent: 'success',
							text: __( 'Calendar added.', 'openevents' ),
					  }
			);
			reload();
			return true;
		} catch ( e ) {
			setNotice( { intent: 'error', text: e.message } );
			return false;
		}
	};

	const sync = useCallback( async ( items ) => {
		for ( const item of items ) {
			try {
				const data = await apiFetch( {
					path: `${ PATH }/${ item.id }/sync`,
					method: 'POST',
				} );
				setNotice( noticeFor( data.result ) );
			} catch ( e ) {
				setNotice( { intent: 'error', text: e.message } );
			}
		}
		reload();
	}, [] );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Calendar', 'openevents' ),
				enableHiding: false,
				enableGlobalSearch: true,
			},
			{
				id: 'address',
				label: __( 'Address', 'openevents' ),
				render: ( { item } ) =>
					item.address ||
					__( 'Needs its address again', 'openevents' ),
				enableSorting: false,
			},
			{
				id: 'events',
				label: __( 'Events', 'openevents' ),
				type: 'integer',
			},
			{
				id: 'category_name',
				label: __( 'Category', 'openevents' ),
				render: ( { item } ) => item.category_name || '—',
			},
			{
				id: 'frequency',
				label: __( 'Sync', 'openevents' ),
				elements: FREQUENCIES,
				enableSorting: false,
			},
			{
				id: 'last',
				label: __( 'Last sync', 'openevents' ),
				getValue: ( { item } ) => item.last?.time || '',
				render: ( { item } ) =>
					item.last ? (
						<span
							className={
								item.last.ok ? undefined : 'oe-feed-error'
							}
						>
							{ when( item.last.time ) }: { item.last.message }
						</span>
					) : (
						__( 'Not yet', 'openevents' )
					),
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'sync',
				label: __( 'Sync now', 'openevents' ),
				isPrimary: true,
				supportsBulk: true,
				callback: sync,
			},
			{
				id: 'edit',
				label: __( 'Edit', 'openevents' ),
				modalHeader: __( 'Edit calendar', 'openevents' ),
				RenderModal: ( { items, closeModal } ) => (
					<FeedForm
						config={ config }
						editing
						initial={ {
							...items[ 0 ],
							url: '',
							category: String( items[ 0 ].category ),
						} }
						submit={ __( 'Save', 'openevents' ) }
						onSubmit={ async ( values ) => {
							try {
								await apiFetch( {
									path: `${ PATH }/${ items[ 0 ].id }`,
									method: 'POST',
									data: values,
								} );
								setNotice( {
									intent: 'success',
									text: __( 'Calendar saved.', 'openevents' ),
								} );
								closeModal();
								reload();
								return true;
							} catch ( e ) {
								setNotice( {
									intent: 'error',
									text: e.message,
								} );
								closeModal();
								return false;
							}
						} }
					/>
				),
			},
			{
				id: 'delete',
				label: __( 'Remove', 'openevents' ),
				isDestructive: true,
				modalHeader: __( 'Remove calendar', 'openevents' ),
				RenderModal: ( { items, closeModal } ) => {
					const remove = async ( events ) => {
						try {
							await apiFetch( {
								path: `${ PATH }/${ items[ 0 ].id }?events=${ events }`,
								method: 'DELETE',
							} );
							setNotice( {
								intent: 'success',
								text: __( 'Calendar removed.', 'openevents' ),
							} );
						} catch ( e ) {
							setNotice( { intent: 'error', text: e.message } );
						}
						closeModal();
						reload();
					};
					return (
						<Stack direction="column" gap="lg">
							<Text>
								{ sprintf(
									/* translators: 1: calendar name, 2: number of events */
									__(
										'Stop importing from “%1$s”? Its %2$d events can stay as ordinary events, or go to the trash.',
										'openevents'
									),
									items[ 0 ].name,
									items[ 0 ].events
								) }
							</Text>
							<Stack
								direction="row"
								gap="sm"
								justify="flex-end"
								wrap="wrap"
							>
								<Button
									variant="outline"
									onClick={ closeModal }
								>
									{ __( 'Cancel', 'openevents' ) }
								</Button>
								<Button
									variant="outline"
									onClick={ () => remove( 'keep' ) }
								>
									{ __(
										'Remove, keep events',
										'openevents'
									) }
								</Button>
								<Button
									tone="neutral"
									onClick={ () => remove( 'trash' ) }
								>
									{ __(
										'Remove and trash events',
										'openevents'
									) }
								</Button>
							</Stack>
						</Stack>
					);
				},
			},
		],
		[ config, sync ]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( feeds, view, fields ),
		[ feeds, view, fields ]
	);

	return (
		<Page
			title={ __( 'Calendar import', 'openevents' ) }
			subTitle={ __(
				'Bring in events from Google Calendar, Outlook, Apple Calendar, or any iCal feed, and keep them in sync. Events you edit here stop updating from the calendar; private events are never imported.',
				'openevents'
			) }
		>
			<Stack direction="column" gap="lg" className="oe-feeds">
				<div role="status" aria-live="polite">
					{ notice && (
						<Notice.Root intent={ notice.intent }>
							<Notice.Description>
								{ notice.text }
							</Notice.Description>
						</Notice.Root>
					) }
				</div>
				<Card.Root>
					<Card.Header>
						<Card.Title>
							{ __( 'Add a calendar', 'openevents' ) }
						</Card.Title>
					</Card.Header>
					<Card.Content>
						<FeedForm
							config={ config }
							initial={ blank }
							submit={ __( 'Add and import', 'openevents' ) }
							onSubmit={ add }
						/>
					</Card.Content>
				</Card.Root>
				<DataViews
					view={ view }
					onChangeView={ setView }
					fields={ fields }
					data={ data }
					actions={ actions }
					isLoading={ isLoading }
					getItemId={ ( item ) => String( item.id ) }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					searchLabel={ __( 'Search calendars', 'openevents' ) }
					empty={
						<p className="oe-feeds__empty">
							{ __(
								'No calendars yet. Add one above, and it syncs on its own from then on.',
								'openevents'
							) }
						</p>
					}
				/>
				<Card.Root>
					<Card.Header>
						<Card.Title>
							{ __( 'Import a file once', 'openevents' ) }
						</Card.Title>
					</Card.Header>
					<Card.Content>
						<FileImport
							config={ config }
							onDone={ ( report ) => {
								setNotice( noticeFor( report ) );
								reload();
							} }
						/>
					</Card.Content>
				</Card.Root>
			</Stack>
		</Page>
	);
}
