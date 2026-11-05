const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { rest, day } = require( './helpers' );

const scan = ( page ) =>
	new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.include( '.feriae-feeds' )
		.analyze();

const compact = ( offset ) => day( offset ).replaceAll( '-', '' );

// Unique per run, so a retry imports afresh rather than finding the last try's events.
const run = Date.now().toString( 36 );

// What Google Calendar exports: a talk, a weekly club, and a private appointment.
const ics = [
	'BEGIN:VCALENDAR',
	'VERSION:2.0',
	'X-WR-CALNAME:Imported team calendar',
	'X-WR-TIMEZONE:America/Vancouver',
	'BEGIN:VEVENT',
	`UID:e2e-talk-${ run }@example.com`,
	`DTSTART;TZID=America/Vancouver:${ compact( 12 ) }T190000`,
	`DTEND;TZID=America/Vancouver:${ compact( 12 ) }T200000`,
	`SUMMARY:Imported astronomy talk ${ run }`,
	'DESCRIPTION:Telescopes provided\\, bring a coat.',
	'END:VEVENT',
	'BEGIN:VEVENT',
	`UID:e2e-club-${ run }@example.com`,
	`DTSTART;TZID=America/Vancouver:${ compact( 6 ) }T170000`,
	'DURATION:PT1H',
	'RRULE:FREQ=WEEKLY;COUNT=4',
	`SUMMARY:Imported chess club ${ run }`,
	'END:VEVENT',
	'BEGIN:VEVENT',
	`UID:e2e-private-${ run }@example.com`,
	`DTSTART:${ compact( 3 ) }T170000Z`,
	'CLASS:PRIVATE',
	'SUMMARY:Imported dentist',
	'END:VEVENT',
	'END:VCALENDAR',
].join( '\r\n' );

test.describe( 'Calendar import', () => {
	test( 'imports an .ics file, and again without duplicates', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=feriae-feeds' );
		await expect(
			page.getByRole( 'heading', { name: 'Calendar import', level: 1 } )
		).toBeVisible();
		const status = page.getByRole( 'status' );

		// The server fetches feeds, so private-network addresses are refused.
		await page.getByLabel( 'iCal address' ).fill( 'http://10.0.0.1/a.ics' );
		await page.getByRole( 'button', { name: 'Add and import' } ).click();
		await expect( status ).toContainText( 'private network' );

		const file = {
			name: 'team.ics',
			mimeType: 'text/calendar',
			buffer: Buffer.from( ics ),
		};
		await page.getByLabel( 'iCal file (.ics)' ).setInputFiles( file );
		await page.getByRole( 'button', { name: 'Import file' } ).click();
		await expect( status ).toContainText(
			'2 events added and 1 private event not imported.'
		);

		await page.getByLabel( 'iCal file (.ics)' ).setInputFiles( file );
		await page.getByRole( 'button', { name: 'Import file' } ).click();
		await expect( status ).toContainText( '2 unchanged' );

		const { violations } = await scan( page );
		expect( violations.map( ( v ) => v.id ) ).toEqual( [] );

		// The repeating club is one event with four dates.
		const { body } = await rest(
			page,
			`/wp/v2/events?search=${ run }&_fields=id,title,meta`
		);
		expect( body.map( ( e ) => e.title.rendered ).sort() ).toEqual( [
			`Imported astronomy talk ${ run }`,
			`Imported chess club ${ run }`,
		] );
		const club = body.find(
			( e ) => e.title.rendered === `Imported chess club ${ run }`
		);
		expect( club.meta._feriae_recurrence.rrule ).toBe( 'FREQ=WEEKLY;COUNT=4' );
		const dates = await rest(
			page,
			`/feriae/v1/occurrences?event=${ club.id }&per_page=10`
		);
		expect( dates.body ).toHaveLength( 4 );
	} );
} );
