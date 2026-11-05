/**
 * Events → Orders: server-paged DataViews list with mark paid, refund
 * (confirmed in a dialog), and cancel.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataViews } from '@wordpress/dataviews';
import { Page } from '@wordpress/admin-ui';
import { Button, Notice, Stack, Text } from '@wordpress/ui';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

const STATUS = [
	{ value: 'completed', label: __( 'Paid', 'feriae' ) },
	{ value: 'unpaid', label: __( 'Unpaid (pay later)', 'feriae' ) },
	{ value: 'pending', label: __( 'Awaiting payment', 'feriae' ) },
	{ value: 'refunded', label: __( 'Refunded', 'feriae' ) },
	{ value: 'cancelled', label: __( 'Cancelled', 'feriae' ) },
	{ value: 'expired', label: __( 'Expired', 'feriae' ) },
	{ value: 'failed', label: __( 'Failed', 'feriae' ) },
];

export default function OrdersScreen( { root } ) {
	const events = useMemo(
		() => JSON.parse( root.dataset.events || '[]' ),
		[ root ]
	);
	const initialEvent = Number( root.dataset.event ) || 0;
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 50,
		search: '',
		fields: [
			'email',
			'event',
			'total',
			'status',
			'gateway',
			'created_utc',
		],
		titleField: 'name',
		filters: initialEvent
			? [ { field: 'event', operator: 'is', value: initialEvent } ]
			: [],
	} );
	const [ data, setData ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ refresh, setRefresh ] = useState( 0 );

	useEffect( () => {
		const filter = ( field ) =>
			view.filters?.find( ( f ) => f.field === field )?.value;
		setIsLoading( true );
		apiFetch( {
			path: addQueryArgs( '/feriae/v1/orders', {
				page: view.page,
				per_page: view.perPage,
				search: view.search || undefined,
				event: filter( 'event' ) || undefined,
				status: filter( 'status' ) || undefined,
			} ),
			parse: false,
		} )
			.then( async ( response ) => {
				setTotal( Number( response.headers.get( 'X-WP-Total' ) ) || 0 );
				setData( await response.json() );
			} )
			.catch( ( e ) => setNotice( { intent: 'error', text: e.message } ) )
			.finally( () => setIsLoading( false ) );
	}, [ view, refresh ] );

	const act = useCallback( async ( items, action, done ) => {
		try {
			for ( const item of items ) {
				await apiFetch( {
					path: `/feriae/v1/orders/${ item.id }`,
					method: 'POST',
					data: { action },
				} );
			}
			setNotice( {
				intent: 'success',
				text: sprintf(
					/* translators: %d: number of orders */
					_n(
						'%d order updated.',
						'%d orders updated.',
						items.length,
						'feriae'
					),
					items.length
				),
			} );
		} catch ( e ) {
			setNotice( { intent: 'error', text: e.message } );
		}
		setRefresh( ( n ) => n + 1 );
		done?.();
	}, [] );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Buyer', 'feriae' ),
				enableHiding: false,
				enableSorting: false,
			},
			{
				id: 'email',
				label: __( 'Email', 'feriae' ),
				enableSorting: false,
			},
			{
				id: 'event',
				label: __( 'Event', 'feriae' ),
				getValue: ( { item } ) => item.event_id,
				render: ( { item } ) => item.event_title,
				elements: events,
				enableSorting: false,
				filterBy: { operators: [ 'is' ], isPrimary: true },
			},
			{
				id: 'total',
				label: __( 'Total', 'feriae' ),
				render: ( { item } ) => item.total_text,
				enableSorting: false,
			},
			{
				id: 'status',
				label: __( 'Status', 'feriae' ),
				elements: STATUS,
				render: ( { item } ) =>
					( STATUS.find( ( s ) => s.value === item.status )?.label ||
						item.status ) +
					( item.oversold
						? ` · ${ __( 'over capacity', 'feriae' ) }`
						: '' ),
				enableSorting: false,
				filterBy: { operators: [ 'is' ], isPrimary: true },
			},
			{
				id: 'gateway',
				label: __( 'Payment', 'feriae' ),
				render: ( { item } ) => item.gateway_label,
				enableSorting: false,
			},
			{
				id: 'created_utc',
				label: __( 'Ordered', 'feriae' ),
				render: ( { item } ) =>
					new Date(
						`${ item.created_utc.replace( ' ', 'T' ) }Z`
					).toLocaleString(),
				enableSorting: false,
			},
		],
		[ events ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'mark-paid',
				label: __( 'Mark as paid', 'feriae' ),
				isPrimary: true,
				supportsBulk: true,
				isEligible: ( item ) => item.status === 'unpaid',
				callback: ( items ) => act( items, 'mark_paid' ),
			},
			{
				id: 'refund',
				label: __( 'Refund', 'feriae' ),
				isDestructive: true,
				supportsBulk: false,
				isEligible: ( item ) =>
					[ 'completed', 'unpaid' ].includes( item.status ),
				RenderModal: ( { items, closeModal } ) => (
					<Stack direction="column" gap="lg">
						<Text>
							{ sprintf(
								/* translators: 1: amount, 2: buyer name */
								__(
									'Refund %1$s to %2$s and cancel their tickets? This can’t be undone.',
									'feriae'
								),
								items[ 0 ].total_text,
								items[ 0 ].name
							) }
						</Text>
						<Stack direction="row" gap="sm" justify="flex-end">
							<Button variant="outline" onClick={ closeModal }>
								{ __( 'Keep order', 'feriae' ) }
							</Button>
							<Button
								tone="neutral"
								onClick={ () =>
									act( items, 'refund', closeModal )
								}
							>
								{ __( 'Refund', 'feriae' ) }
							</Button>
						</Stack>
					</Stack>
				),
			},
			{
				id: 'cancel',
				label: __( 'Cancel order', 'feriae' ),
				isDestructive: true,
				supportsBulk: true,
				isEligible: ( item ) =>
					[ 'pending', 'unpaid' ].includes( item.status ),
				callback: ( items ) => act( items, 'cancel' ),
			},
		],
		[ act ]
	);

	return (
		<Page
			title={ __( 'Orders', 'feriae' ) }
			subTitle={ sprintf(
				/* translators: %d: number of orders */
				_n( '%d order', '%d orders', total, 'feriae' ),
				total
			) }
		>
			{ notice && (
				<Notice.Root intent={ notice.intent }>
					<Notice.Description>{ notice.text }</Notice.Description>
				</Notice.Root>
			) }
			<DataViews
				view={ view }
				onChangeView={ setView }
				fields={ fields }
				data={ data }
				actions={ actions }
				isLoading={ isLoading }
				getItemId={ ( item ) => String( item.id ) }
				paginationInfo={ {
					totalItems: total,
					totalPages: Math.max(
						1,
						Math.ceil( total / view.perPage )
					),
				} }
				defaultLayouts={ { table: {} } }
				searchLabel={ __(
					'Search buyer, email, or order number',
					'feriae'
				) }
			/>
		</Page>
	);
}
