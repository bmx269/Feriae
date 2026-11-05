import { __ } from '@wordpress/i18n';
import { Slot, TextControl, Flex } from '@wordpress/components';

function Field( { meta, setMeta, metaKey, label, type = 'text', help } ) {
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			type={ type }
			label={ label }
			help={ help }
			value={ meta[ metaKey ] ?? '' }
			onChange={ ( value ) => setMeta( { [ metaKey ]: value } ) }
		/>
	);
}

export function VenuePanel( { meta, setMeta } ) {
	const props = { meta, setMeta };
	return (
		<Flex direction="column" gap={ 4 }>
			<Field
				{ ...props }
				metaKey="_feriae_address"
				label={ __( 'Address', 'feriae' ) }
			/>
			<Field
				{ ...props }
				metaKey="_feriae_city"
				label={ __( 'City', 'feriae' ) }
			/>
			<Flex align="flex-start">
				<Field
					{ ...props }
					metaKey="_feriae_region"
					label={ __( 'State / Province', 'feriae' ) }
				/>
				<Field
					{ ...props }
					metaKey="_feriae_postal_code"
					label={ __( 'Postal code', 'feriae' ) }
				/>
			</Flex>
			<Field
				{ ...props }
				metaKey="_feriae_country"
				label={ __( 'Country', 'feriae' ) }
			/>
			<Field
				{ ...props }
				metaKey="_feriae_phone"
				type="tel"
				label={ __( 'Phone', 'feriae' ) }
			/>
			<Field
				{ ...props }
				metaKey="_feriae_website"
				type="url"
				label={ __( 'Website', 'feriae' ) }
			/>
			<Flex align="flex-start">
				<Field
					{ ...props }
					metaKey="_feriae_lat"
					label={ __( 'Latitude', 'feriae' ) }
				/>
				<Field
					{ ...props }
					metaKey="_feriae_lng"
					label={ __( 'Longitude', 'feriae' ) }
				/>
			</Flex>
			<Slot name="FeriaeVenuePanel" fillProps={ { meta, setMeta } } />
		</Flex>
	);
}

export function OrganizerPanel( { meta, setMeta } ) {
	const props = { meta, setMeta };
	return (
		<Flex direction="column" gap={ 4 }>
			<Field
				{ ...props }
				metaKey="_feriae_email"
				type="email"
				label={ __( 'Email', 'feriae' ) }
			/>
			<Field
				{ ...props }
				metaKey="_feriae_phone"
				type="tel"
				label={ __( 'Phone', 'feriae' ) }
			/>
			<Field
				{ ...props }
				metaKey="_feriae_website"
				type="url"
				label={ __( 'Website', 'feriae' ) }
			/>
			<Slot
				name="FeriaeOrganizerPanel"
				fillProps={ { meta, setMeta } }
			/>
		</Flex>
	);
}
