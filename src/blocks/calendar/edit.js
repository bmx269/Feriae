import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import CategoryPicker from '../shared/category-picker';

const VIEWS = [
	{ value: 'month', label: __( 'Month', 'feriae' ) },
	{ value: 'week', label: __( 'Week', 'feriae' ) },
	{ value: 'day', label: __( 'Day', 'feriae' ) },
	{ value: 'list', label: __( 'List', 'feriae' ) },
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		views,
		defaultView,
		categories,
		showLegend,
		perPage,
		headingLevel,
	} = attributes;

	const toggleView = ( view, checked ) => {
		const next = checked
			? [ ...views, view ]
			: views.filter( ( v ) => v !== view );
		if ( next.length ) {
			setAttributes( { views: next } );
		}
	};

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Views', 'feriae' ) }>
					{ VIEWS.map( ( view ) => (
						<CheckboxControl
							key={ view.value }
							__nextHasNoMarginBottom
							label={ view.label }
							checked={ views.includes( view.value ) }
							onChange={ ( checked ) =>
								toggleView( view.value, checked )
							}
						/>
					) ) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Default view', 'feriae' ) }
						value={ defaultView }
						options={ [
							{
								value: '',
								label: __( 'Site default', 'feriae' ),
							},
							...VIEWS.filter( ( v ) =>
								views.includes( v.value )
							),
						] }
						onChange={ ( value ) =>
							setAttributes( { defaultView: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Filter', 'feriae' ) }>
					<CategoryPicker
						value={ categories }
						onChange={ ( value ) =>
							setAttributes( { categories: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Display', 'feriae' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Selecting an event', 'feriae' ) }
						value={ attributes.clickAction }
						options={ [
							{
								value: '',
								label: __( 'Site default', 'feriae' ),
							},
							{
								value: 'page',
								label: __( 'Go to the event page', 'feriae' ),
							},
							{
								value: 'popup',
								label: __( 'Show a summary popup', 'feriae' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { clickAction: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show filter and search', 'feriae' ) }
						checked={ attributes.showFilters }
						onChange={ ( value ) =>
							setAttributes( { showFilters: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show category colour legend', 'feriae' ) }
						checked={ showLegend }
						onChange={ ( value ) =>
							setAttributes( { showLegend: value } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Events per page (list view)', 'feriae' ) }
						help={ __( '0 uses the site setting.', 'feriae' ) }
						min={ 0 }
						max={ 50 }
						value={ perPage }
						onChange={ ( value ) =>
							setAttributes( { perPage: value ?? 0 } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Heading level', 'feriae' ) }
						value={ String( headingLevel ) }
						options={ [ 1, 2, 3, 4, 5, 6 ].map( ( level ) => ( {
							value: String( level ),
							label: `H${ level }`,
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( { headingLevel: Number( value ) } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div inert="">
				<ServerSideRender
					block="feriae/calendar"
					attributes={ attributes }
				/>
			</div>
		</div>
	);
}
