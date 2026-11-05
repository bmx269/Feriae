/**
 * Automated WCAG 2.2 AA checks (axe-core) on everything Feriae renders.
 * Scans are limited to the plugin's own markup so theme or wp-admin issues
 * outside it don't mask or cause failures.
 */
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

async function scan( page, include ) {
	const builder = new AxeBuilder( { page } ).withTags( TAGS );
	for ( const selector of include ) {
		builder.include( selector );
	}
	const { violations } = await builder.analyze();
	const summary = violations.map(
		( v ) =>
			`${ v.id } (${ v.impact }): ${ v.help } — ${ v.nodes
				.map( ( n ) => n.target.join( ' ' ) )
				.slice( 0, 3 )
				.join( ', ' ) }`
	);
	expect( summary, summary.join( '\n' ) ).toEqual( [] );
}

const PAGES = [
	[ 'month view', '/events/?feriae_view=month', [ '.feriae-calendar' ] ],
	[ 'list view', '/events/?feriae_view=list', [ '.feriae-calendar' ] ],
	[ 'past events', '/events/?feriae_view=list&feriae_past=1', [ '.feriae-calendar' ] ],
	[ 'event details', '/events/jazz-night/', [ '.feriae-details' ] ],
	[ 'RSVP form', '/events/next-month-gala/', [ '.feriae-details' ] ],
	[
		'ticket checkout and waitlist',
		'/events/spring-concert/',
		[ '.feriae-details' ],
	],
	[ 'RSVP waitlist', '/events/author-talk-full/', [ '.feriae-details' ] ],
	[ 'existing post as event', '/blog-post-book-launch/', [ '.feriae-details' ] ],
];

test.describe( 'Accessibility (WCAG 2.2 AA)', () => {
	for ( const [ name, url, include ] of PAGES ) {
		test( `front end: ${ name }`, async ( { page } ) => {
			await page.goto( url );
			await scan( page, include );
		} );
	}

	test.describe( 'on a phone', () => {
		test.use( { viewport: { width: 390, height: 844 } } );
		test( 'front end: month view as agenda', async ( { page } ) => {
			await page.goto( '/events/?feriae_view=month' );
			await scan( page, [ '.feriae-calendar' ] );
		} );
	} );

	test( 'admin: settings', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=feriae_event&page=feriae-settings'
		);
		await page.waitForSelector( '#feriae-admin button' );
		await scan( page, [ '#feriae-admin' ] );
	} );

	test( 'admin: setup wizard', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=feriae-setup' );
		await page.waitForSelector( '#feriae-admin button' );
		await scan( page, [ '#feriae-admin' ] );
	} );

	test( 'admin: orders', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=feriae_event&page=feriae-orders'
		);
		await page.waitForSelector( '.dataviews-view-table' );
		await scan( page, [ '#feriae-attendance' ] );
	} );

	test( 'admin: tickets settings', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=feriae_event&page=feriae-settings'
		);
		await page.getByRole( 'tab', { name: 'Tickets' } ).click();
		await page.waitForSelector( 'text=Card (Stripe)' );
		await scan( page, [ '#feriae-admin' ] );
	} );

	test( 'admin: waitlist', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=feriae_event&page=feriae-waitlist'
		);
		await page.waitForSelector(
			'.dataviews-view-table, .dataviews-no-results'
		);
		await scan( page, [ '#feriae-attendance' ] );
	} );

	test( 'admin: attendees', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=feriae_event&page=feriae-attendees'
		);
		await page.waitForSelector( '.dataviews-view-table' );
		await scan( page, [ '#feriae-attendance' ] );
	} );
} );
