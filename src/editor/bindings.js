/**
 * Editor side of the "feriae/event" block bindings source:
 * previews bound values from the event being edited and lists the fields in
 * the block bindings UI. Values are read-only; edit them in the Event panel.
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { dateI18n, getSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

const FIELDS = {
	date: __( 'Event date and time', 'feriae' ),
	start_date: __( 'Start date', 'feriae' ),
	start_time: __( 'Start time', 'feriae' ),
	end_date: __( 'End date', 'feriae' ),
	end_time: __( 'End time', 'feriae' ),
	venue: __( 'Venue name', 'feriae' ),
	venue_address: __( 'Venue address', 'feriae' ),
	venue_url: __( 'Venue link', 'feriae' ),
	cost: __( 'Cost', 'feriae' ),
	website: __( 'Event website', 'feriae' ),
};

// Stored values are wall-clock times; anchor them to UTC and format in UTC so
// no timezone conversion shifts them.
const toDate = ( value ) =>
	value ? `${ value.replace( ' ', 'T' ) }Z` : null;

function valueFor( key, meta, venue ) {
	const { formats } = getSettings();
	const start = toDate( meta._feriae_start );
	const end = toDate( meta._feriae_end ) || start;
	const allDay = !! meta._feriae_all_day;
	const date = ( d ) => ( d ? dateI18n( formats.date, d, true ) : '' );
	const time = ( d ) =>
		d && ! allDay ? dateI18n( formats.time, d, true ) : '';

	switch ( key ) {
		case 'date': {
			if ( ! start ) {
				return '';
			}
			const sameDay = date( start ) === date( end );
			if ( allDay ) {
				return sameDay
					? date( start )
					: `${ date( start ) } – ${ date( end ) }`;
			}
			return sameDay
				? `${ date( start ) }, ${ time( start ) } – ${ time( end ) }`
				: `${ date( start ) }, ${ time( start ) } – ${ date(
						end
				  ) }, ${ time( end ) }`;
		}
		case 'start_date':
			return date( start );
		case 'start_time':
			return time( start );
		case 'end_date':
			return date( end );
		case 'end_time':
			return time( end );
		case 'venue':
			return venue?.title?.raw ?? venue?.title?.rendered ?? '';
		case 'venue_address':
			return venue
				? [
						venue.meta?._feriae_address,
						venue.meta?._feriae_city,
						venue.meta?._feriae_region,
						venue.meta?._feriae_country,
				  ]
						.filter( Boolean )
						.join( ', ' )
				: '';
		case 'venue_url':
			return venue?.link ?? '';
		case 'cost':
			return meta._feriae_cost ?? '';
		case 'website':
			return meta._feriae_url ?? '';
	}
	return '';
}

registerBlockBindingsSource( {
	name: 'feriae/event',
	label: __( 'Event', 'feriae' ),
	usesContext: [ 'postId', 'postType' ],
	getValues( { select, context, bindings } ) {
		const values = {};
		const record =
			context?.postId && context?.postType
				? select( coreStore ).getEditedEntityRecord(
						'postType',
						context.postType,
						context.postId
				  )
				: null;
		const meta = record?.meta || {};
		const venue = meta._feriae_venue_id
			? select( coreStore ).getEntityRecord(
					'postType',
					'feriae_venue',
					meta._feriae_venue_id
			  )
			: null;

		for ( const [ attribute, { args } ] of Object.entries( bindings ) ) {
			// Outside an event (e.g. editing a template) show the field name as a placeholder.
			values[ attribute ] = record
				? valueFor( args?.key, meta, venue ) ||
				  FIELDS[ args?.key ] ||
				  ''
				: FIELDS[ args?.key ] || '';
		}
		return values;
	},
	canUserEditValue: () => false,
	getFieldsList() {
		return Object.entries( FIELDS ).map( ( [ key, label ] ) => ( {
			label,
			type: 'string',
			args: { key },
		} ) );
	},
} );
