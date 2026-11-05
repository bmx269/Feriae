/**
 * Editor sidebar panels for events, RSVP, venues, and organizers.
 *
 * Add-ons can add controls to these panels with SlotFill:
 *
 *   import { Fill } from '@wordpress/components';
 *   registerPlugin( 'my-addon', { render: () => (
 *     <Fill name="FeriaeEventPanel">
 *       { ( { meta, setMeta, postType } ) => <MyControl /> }
 *     </Fill>
 *   ) } );
 *
 * Slots: FeriaeEventPanel, FeriaeVenuePanel, FeriaeOrganizerPanel.
 * Register your meta with the feriae/meta_fields PHP filter so it saves
 * over REST.
 */
import { registerPlugin } from '@wordpress/plugins';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import EventPanel from './event-panel';
import { VenuePanel, OrganizerPanel } from './place-panels';
import RsvpPanel from './rsvp-panel';
import TicketsPanel from './tickets-panel';
import RepeatPanel from './repeat-panel';
import './editor.scss';

const config = window.feriae || {};

function FeriaeSidebar() {
	const { postType, postId } = useSelect(
		( select ) => ( {
			postType: select( editorStore ).getCurrentPostType(),
			postId: select( editorStore ).getCurrentPostId(),
		} ),
		[]
	);
	const [ meta, setMetaRaw ] = useEntityProp( 'postType', postType, 'meta' );
	const setMeta = ( changes ) => setMetaRaw( { ...meta, ...changes } );

	if ( ! meta ) {
		return null;
	}

	if ( ( config.eventPostTypes || [] ).includes( postType ) ) {
		const isDedicated = postType === config.dedicatedType;
		const isEvent = isDedicated || !! meta._feriae_start;
		const rsvpEnabled = ( config.modules || [] ).includes( 'rsvp' );
		const ticketsEnabled = ( config.modules || [] ).includes( 'tickets' );
		const repeatEnabled = ( config.modules || [] ).includes( 'recurrence' );
		return (
			<>
				<PluginDocumentSettingPanel
					name="feriae-event"
					title={ __( 'Event', 'feriae' ) }
					className="feriae-editor-panel"
				>
					<EventPanel
						meta={ meta }
						setMeta={ setMeta }
						postType={ postType }
						isDedicated={ isDedicated }
						config={ config }
					/>
				</PluginDocumentSettingPanel>
				{ repeatEnabled && isEvent && (
					<PluginDocumentSettingPanel
						name="feriae-repeat"
						title={ __( 'Repeat', 'feriae' ) }
						className="feriae-editor-panel"
					>
						<RepeatPanel
							meta={ meta }
							setMeta={ setMeta }
							postId={ postId }
							config={ config }
						/>
					</PluginDocumentSettingPanel>
				) }
				{ rsvpEnabled && isEvent && (
					<PluginDocumentSettingPanel
						name="feriae-rsvp"
						title={ __( 'RSVP', 'feriae' ) }
						className="feriae-editor-panel"
					>
						<RsvpPanel
							meta={ meta }
							setMeta={ setMeta }
							postId={ postId }
							config={ config }
						/>
					</PluginDocumentSettingPanel>
				) }
				{ ticketsEnabled && isEvent && (
					<PluginDocumentSettingPanel
						name="feriae-tickets"
						title={ __( 'Tickets', 'feriae' ) }
						className="feriae-editor-panel"
					>
						<TicketsPanel
							meta={ meta }
							setMeta={ setMeta }
							postId={ postId }
							config={ config }
						/>
					</PluginDocumentSettingPanel>
				) }
			</>
		);
	}

	if (
		postType === 'feriae_series' &&
		( config.modules || [] ).includes( 'tickets' )
	) {
		return (
			<PluginDocumentSettingPanel
				name="feriae-series-passes"
				title={ __( 'Series passes', 'feriae' ) }
				className="feriae-editor-panel"
			>
				<p className="feriae-editor-panel__hint">
					{ __(
						'Passes are sold on the series page and cover every event in the series.',
						'feriae'
					) }
				</p>
				<TicketsPanel
					meta={ meta }
					setMeta={ setMeta }
					postId={ postId }
					config={ config }
				/>
			</PluginDocumentSettingPanel>
		);
	}

	if ( postType === 'feriae_venue' ) {
		return (
			<PluginDocumentSettingPanel
				name="feriae-venue"
				title={ __( 'Venue details', 'feriae' ) }
				className="feriae-editor-panel"
			>
				<VenuePanel meta={ meta } setMeta={ setMeta } />
			</PluginDocumentSettingPanel>
		);
	}

	if ( postType === 'feriae_organizer' ) {
		return (
			<PluginDocumentSettingPanel
				name="feriae-organizer"
				title={ __( 'Organizer details', 'feriae' ) }
				className="feriae-editor-panel"
			>
				<OrganizerPanel meta={ meta } setMeta={ setMeta } />
			</PluginDocumentSettingPanel>
		);
	}

	return null;
}

registerPlugin( 'feriae', { render: FeriaeSidebar } );
