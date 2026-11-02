import fs from 'node:fs';
import { expect, test } from '@playwright/test';
import { BANNER, expectNoSideScroll, holdStay, holdTable, login, offsetFor, payDeposit } from './helpers.js';

const SHOTS = process.env.SCREENSHOTS ? '../../docs/screenshots' : null;

async function shot( page, testInfo, name, fullPage = true ) {
	if ( SHOTS ) {
		fs.mkdirSync( SHOTS, { recursive: true } );
		await page.screenshot( { path: `${ SHOTS }/${ testInfo.project.name }-${ name }.png`, fullPage } );
	}
}

async function newPage( browser, testInfo ) {
	const context = await browser.newContext( { ...testInfo.project.use, baseURL: testInfo.project.use.baseURL } );
	const page = await context.newPage();
	page.on( 'dialog', ( dialog ) => dialog.accept() );
	return { context, page };
}

test( 'public pages: search, explore, listings, credits, no sideways scroll', async ( { page }, testInfo ) => {
	await test.step( 'home shows stays and places to eat with photos', async () => {
		await page.goto( '/' );
		await expect( page.getByText( BANNER ) ).toBeVisible();
		await expect( page.getByTestId( 'shelf-stay' ).getByTestId( 'listing-card' ) ).toHaveCount( 8 );
		await expect( page.getByTestId( 'shelf-dining' ).getByTestId( 'listing-card' ) ).toHaveCount( 5 );
		await expect( page.getByTestId( 'listing-card' ).first().locator( 'img' ).first() ).toHaveJSProperty( 'complete', true );
		await expect( page.getByText( /\b(stars?|reviews?|guest favou?rite)\b/i ) ).toHaveCount( 0 );
		await expectNoSideScroll( page );
		await shot( page, testInfo, 'home', false );
	} );

	await test.step( 'search for restaurants on a date filters the grid', async () => {
		await page.getByTestId( 'search-type-dining' ).check( { force: true } );
		await page.getByTestId( 'search-where' ).selectOption( 'old-harbour' );
		await page.getByTestId( 'search-submit' ).click();
		await page.waitForURL( /\/explore\/\?/ );
		await expect( page.getByTestId( 'results-title' ) ).toContainText( 'places to eat in Old Harbour' );
		await expect( page.getByTestId( 'listing-card' ) ).toHaveCount( 3 );
		await page.goto( '/explore/?type=stay&category=pools' );
		await expect( page.getByTestId( 'category-pools' ) ).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.getByTestId( 'listing-card' ) ).toHaveCount( 3 );
		await page.goto( '/explore/?type=stay' );
		await expect( page.getByTestId( 'listing-card' ) ).toHaveCount( 9 );
		if ( testInfo.project.name === 'desktop' ) {
			await expect( page.locator( '.qm-pin' ).first() ).toBeVisible();
			await expect( page.getByText( 'OpenStreetMap' ).first() ).toBeVisible();
		} else {
			await page.getByTestId( 'map-toggle' ).click();
			await expect( page.locator( '.qm-pin' ).first() ).toBeVisible();
			await page.getByTestId( 'map-toggle' ).click();
		}
		await expectNoSideScroll( page );
		await shot( page, testInfo, 'explore', testInfo.project.name === 'desktop' );
	} );

	await test.step( 'a stay page shows the gallery, details, map and host', async () => {
		await page.goto( '/listing/fern-hollow-cabin/' );
		await expect( page.getByRole( 'heading', { level: 1, name: 'Fern Hollow Cabin' } ) ).toBeVisible();
		await expect( page.getByTestId( 'listing-facts' ) ).toHaveText( '4 guests, 2 bedrooms, 3 beds, 1 bath' );
		await expect( page.getByTestId( 'amenities' ) ).toContainText( 'Indoor fireplace' );
		await expect( page.getByTestId( 'host-panel' ) ).toContainText( '2024 (demo data)' );
		await page.getByTestId( 'listing-map' ).scrollIntoViewIfNeeded();
		await expect( page.getByTestId( 'listing-map' ).locator( '.leaflet-tile-loaded' ).first() ).toBeVisible();
		await expect( page.getByTestId( 'booking-card' ) ).toContainText( '$185' );
		await expectNoSideScroll( page );
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await shot( page, testInfo, 'listing-stay', false );
	} );

	await test.step( 'a restaurant page shows opening hours and times', async () => {
		await page.goto( '/listing/the-brick-kitchen/' );
		await expect( page.getByTestId( 'opening-hours' ) ).toContainText( 'Dinner' );
		await expect( page.getByTestId( 'listing-facts' ) ).toContainText( 'Tables for 1 to 8' );
		await expectNoSideScroll( page );
		await shot( page, testInfo, 'listing-dining', false );
	} );

	await test.step( 'photo credits list every photo with a licence', async () => {
		await page.goto( '/photo-credits/' );
		await expect( page.getByTestId( 'credit' ) ).toHaveCount( 56 );
		await expect( page.getByTestId( 'credit' ).first() ).toContainText( 'Unsplash License' );
		await expectNoSideScroll( page );
	} );

	await test.step( 'demo logins list the three accounts', async () => {
		await page.goto( '/demo/' );
		for ( const login of [ 'demo-customer', 'demo-host', 'demo-admin' ] ) {
			await expect( page.getByTestId( `login-${ login }` ) ).toBeVisible();
		}
		await expectNoSideScroll( page );
	} );
} );

test( 'stay: hold, pay, instant confirmation, change dates, cancel and refund', async ( { browser, page }, testInfo ) => {
	page.on( 'dialog', ( dialog ) => dialog.accept() );
	let bookingId;

	await test.step( 'the guest holds three nights with an extra and pays the test deposit', async () => {
		await login( page, 'demo-customer' );
		await expect( page ).toHaveURL( /\/trips\// );
		await holdStay( page, { slug: 'fern-hollow-cabin', offset: offsetFor( testInfo, 30 ), nights: 3, extra: 'breakfast-basket' } );
		await shot( page, testInfo, 'hold', false );
		bookingId = await payDeposit( page );
		expect( bookingId ).toBeTruthy();
		await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Confirmed' );
		await expect( page.getByTestId( 'trip-address' ) ).toContainText( 'Hollow Lane' );
		await shot( page, testInfo, 'paid' );
	} );

	await test.step( 'the booking PDF downloads', async () => {
		const href = await page.getByTestId( 'pdf-confirmation' ).getAttribute( 'href' );
		const response = await page.request.get( href );
		expect( response.status() ).toBe( 200 );
		expect( ( await response.body() ).subarray( 0, 4 ).toString() ).toBe( '%PDF' );
	} );

	await test.step( 'trips lists it as upcoming, with the deposit under payments', async () => {
		await page.goto( '/trips/' );
		await expect( page.getByTestId( 'tab-upcoming' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( `[data-testid="trip-card"][data-booking="${ bookingId }"]` ) ).toBeVisible();
		await page.getByTestId( 'tab-payments' ).click();
		await expect( page.getByTestId( 'payments' ) ).toContainText( `QM-${ bookingId }` );
		await page.getByTestId( 'tab-upcoming' ).click();
		await expectNoSideScroll( page );
		await shot( page, testInfo, 'trips' );
	} );

	await test.step( 'the guest moves the stay by a week', async () => {
		await page.goto( `/trips/?booking=${ bookingId }` );
		const before = await page.getByTestId( 'trip-when' ).textContent();
		await page.getByTestId( 'change-open' ).click();
		const shift = async ( id ) => {
			const field = page.getByTestId( id );
			const value = await field.inputValue();
			const next = new Date( new Date( value + 'T00:00:00Z' ).getTime() + 7 * 86400000 ).toISOString().slice( 0, 10 );
			await field.fill( next );
		};
		await shift( 'change-check-in' );
		await shift( 'change-check-out' );
		await page.getByTestId( 'change-submit' ).click();
		await expect( page.getByTestId( 'trip-when' ) ).not.toHaveText( before );
		await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Confirmed' );
	} );

	await test.step( 'the guest cancels inside the refund window', async () => {
		await page.getByTestId( 'cancel-open' ).click();
		await page.getByTestId( 'cancel-confirm' ).click();
		await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Cancelled' );
		await expect( page.getByTestId( 'trip-refund' ) ).toHaveText( 'Deposit refund due' );
	} );

	await test.step( 'the host refunds the deposit through the gateway', async () => {
		const { context, page: host } = await newPage( browser, testInfo );
		await login( host, 'demo-host' );
		await expect( host ).toHaveURL( /page=qhs-dashboard/ );
		await host.goto( `/wp-admin/admin.php?page=qhs-bookings&booking=${ bookingId }` );
		await expect( host.getByTestId( 'state-badge' ).first() ).toHaveText( 'Cancelled' );
		await host.getByTestId( 'action-refund' ).click();
		await expect( host.getByTestId( 'admin-notice' ) ).toHaveText( 'Deposit refunded in test mode.' );
		await expect( host.getByTestId( 'refund-status' ) ).toContainText( 'Deposit refunded' );
		await expect( host.getByTestId( 'history' ) ).toContainText( 'deposit refunded' );
		await context.close();
		await page.reload();
		await expect( page.getByTestId( 'trip-refund' ) ).toContainText( 'Deposit refunded' );
	} );
} );

test( 'table: hold a time, pay, and see it confirmed', async ( { page }, testInfo ) => {
	await login( page, 'demo-customer' );
	const free = await holdTable( page, { slug: 'corner-light-cafe', offset: offsetFor( testInfo, 5 ), party: 3 } );
	await expect( page.getByTestId( 'held' ) ).toContainText( free.slot.slice( 11, 16 ) );
	await shot( page, testInfo, 'hold-table', false );
	const bookingId = await payDeposit( page );
	await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Confirmed' );
	await expect( page.getByTestId( 'trip-when' ) ).toContainText( '3 guests' );
	expect( bookingId ).toBeTruthy();
} );

test( 'request to book: the host accepts, adds a note and manages the calendar', async ( { browser, page }, testInfo ) => {
	page.on( 'dialog', ( dialog ) => dialog.accept() );
	let bookingId;

	await test.step( 'the guest requests the boathouse and pays the deposit', async () => {
		await login( page, 'demo-customer' );
		await holdStay( page, { slug: 'boathouse-on-the-pond', offset: offsetFor( testInfo, 50 ), nights: 2 } );
		bookingId = await payDeposit( page );
		await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Waiting for the host' );
	} );

	const { context, page: host } = await newPage( browser, testInfo );

	await test.step( 'the host sees the request on the dashboard and accepts it', async () => {
		await login( host, 'demo-host' );
		await expect( host.getByText( BANNER ) ).toBeVisible();
		await expect( host.getByTestId( 'requests' ) ).toContainText( 'Boathouse on the Pond' );
		await shot( host, testInfo, 'host-dashboard' );
		await host.goto( `/wp-admin/admin.php?page=qhs-bookings&booking=${ bookingId }` );
		await host.getByTestId( 'action-confirm' ).click();
		await expect( host.getByTestId( 'admin-notice' ) ).toHaveText( 'Request accepted. The guest has been told.' );
		await expect( host.getByTestId( 'state-badge' ).first() ).toHaveText( 'Confirmed' );
		await host.getByTestId( 'note-input' ).fill( 'Leave the rowing boat keys in the box by the door.' );
		await host.getByTestId( 'note-submit' ).click();
		await expect( host.getByTestId( 'notes' ) ).toContainText( 'rowing boat keys' );
		await shot( host, testInfo, 'host-booking' );
	} );

	await test.step( 'the guest sees it confirmed, with the address', async () => {
		await page.reload();
		await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Confirmed' );
		await expect( page.getByTestId( 'trip-address' ) ).toContainText( 'Pond Road' );
	} );

	await test.step( 'the host blocks and unblocks a night on the calendar', async () => {
		await host.goto( '/wp-admin/admin.php?page=qhs-calendar' );
		await host.getByTestId( 'calendar-listing' ).selectOption( { label: 'Boathouse on the Pond (stay)' } );
		await host.waitForURL( /listing=/ );
		await host.getByTestId( 'calendar-next' ).click();
		const day = host.locator( '[data-testid="admin-day"][data-status="free"]' ).filter( { has: host.locator( 'input' ) } ).first();
		const date = await day.getAttribute( 'data-date' );
		await day.locator( 'input' ).check();
		await host.getByTestId( 'calendar-block' ).click();
		await expect( host.getByTestId( 'admin-notice' ) ).toHaveText( '1 night blocked.' );
		const blocked = host.locator( `[data-testid="admin-day"][data-date="${ date }"]` );
		await expect( blocked ).toHaveAttribute( 'data-status', 'blocked' );
		await shot( host, testInfo, 'host-calendar' );
		await blocked.locator( 'input' ).check();
		await host.getByTestId( 'calendar-unblock' ).click();
		await expect( host.locator( `[data-testid="admin-day"][data-date="${ date }"]` ) ).toHaveAttribute( 'data-status', 'free' );
	} );

	await test.step( 'the host sees only their own listings', async () => {
		await host.goto( '/wp-admin/edit.php?post_type=qm_listing' );
		await expect( host.locator( '#the-list tr' ) ).toHaveCount( 5 );
		await expect( host.locator( '#the-list' ) ).not.toContainText( 'Clifftop Pool Villa' );
		await host.goto( '/wp-admin/admin.php?page=qhs-bookings' );
		await expect( host.getByTestId( 'bookings-table' ) ).not.toContainText( 'Clifftop Pool Villa' );
		await host.goto( '/wp-admin/options-general.php' );
		await expect( host ).toHaveURL( /page=qhs-dashboard/ );
	} );

	await test.step( 'the listing editor has the map picker and the price preview', async () => {
		await host.goto( '/wp-admin/edit.php?post_type=qm_listing' );
		await host.locator( '#the-list a.row-title', { hasText: 'Boathouse on the Pond' } ).click();
		await expect( host.getByTestId( 'map-picker' ).locator( '.leaflet-tile-loaded' ).first() ).toBeVisible();
		await host.locator( '[data-qhs-preview-run]' ).click();
		await expect( host.getByTestId( 'price-preview' ) ).toContainText( 'Total $' );
		await shot( host, testInfo, 'listing-editor', false );
	} );
	await context.close();
} );

test( 'the manager sees every host, the activity log and the taxonomies', async ( { page }, testInfo ) => {
	await login( page, 'demo-admin' );
	await expect( page ).toHaveURL( /page=qhs-dashboard/ );
	await expect( page.getByTestId( 'system' ) ).toContainText( 'Local fake gateway' );
	await page.goto( '/wp-admin/admin.php?page=qhs-bookings&state=upcoming' );
	await expect( page.getByTestId( 'booking-row' ).first() ).toBeVisible();
	await page.getByTestId( 'bookings-search' ).fill( 'Fern Hollow' );
	await page.getByTestId( 'bookings-search' ).press( 'Enter' );
	await expect( page.getByTestId( 'bookings-table' ) ).toContainText( 'Fern Hollow Cabin' );
	await shot( page, testInfo, 'admin-bookings' );
	await page.goto( '/wp-admin/edit.php?post_type=qm_listing' );
	await expect( page.locator( '#the-list tr' ) ).toHaveCount( 14 );
	await page.goto( '/wp-admin/edit-tags.php?taxonomy=qm_location&post_type=qm_listing' );
	await expect( page.locator( '#the-list' ) ).toContainText( 'Old Harbour' );
	await page.goto( '/wp-admin/admin.php?page=qhs-activity' );
	await expect( page.getByRole( 'heading', { name: 'Mail log' } ) ).toBeVisible();
} );
