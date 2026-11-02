import fs from 'node:fs';
import { expect, test } from '@playwright/test';
import { sql } from '../global-setup.js';
import { BANNER, bookingIdFrom, login, payDeposit, runBooking } from './helpers.js';

const SHOTS = process.env.SCREENSHOTS ? '../../docs/screenshots' : null;

async function shot( page, testInfo, name ) {
	if ( SHOTS ) {
		fs.mkdirSync( SHOTS, { recursive: true } );
		await page.screenshot( { path: `${ SHOTS }/${ testInfo.project.name }-${ name }.png`, fullPage: true } );
	}
}

test( 'quote to deposit, confirmation, assignment and completion', async ( { browser, page }, testInfo ) => {
	const note = 'Smoke alarm keeps beeping and there is a small water leak under the sink.';

	await test.step( 'customer searches, opens a service, holds a slot and pays the test deposit', async () => {
		await login( page, 'demo-customer' );
		await expect( page ).toHaveURL( /\/portal\// );
		await runBooking( page, { note, slotIndex: 4, fromSearch: true } );
		await shot( page, testInfo, 'hold' );
		await payDeposit( page );
		await shot( page, testInfo, 'paid' );
	} );

	const bookingId = bookingIdFrom( page.url() );
	expect( bookingId ).toBeTruthy();

	await test.step( 'the booking is confirmed and the dispatch service assigns staff', async () => {
		await expect( async () => {
			await page.reload();
			await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Staff assigned', { timeout: 3000 } );
		} ).toPass( { timeout: 180000, intervals: [ 5000 ] } );
		await expect( page.getByTestId( 'booking-staff' ) ).toHaveText( 'Rowan Vale' );
		await shot( page, testInfo, 'assigned' );
		await page.goto( '/portal/' );
		await expect( page.getByTestId( 'tab-upcoming' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.locator( `[data-testid="booking-card"][data-booking="${ bookingId }"]` ) ).toBeVisible();
		await shot( page, testInfo, 'portal' );
		await page.goto( `/portal/?booking=${ bookingId }` );
	} );

	await test.step( 'the confirmation PDF downloads', async () => {
		const href = await page.getByTestId( 'pdf-confirmation' ).getAttribute( 'href' );
		const response = await page.request.get( href );
		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'content-type' ] ).toContain( 'application/pdf' );
		expect( ( await response.body() ).subarray( 0, 4 ).toString() ).toBe( '%PDF' );
	} );

	await test.step( 'staff work the job through to completion', async () => {
		const staff = await browser.newContext( { ...testInfo.project.use, baseURL: testInfo.project.use.baseURL } );
		const staffPage = await staff.newPage();
		await login( staffPage, 'demo-staff' );
		await expect( staffPage ).toHaveURL( /\/staff\// );
		await expect( staffPage.getByText( BANNER ) ).toBeVisible();

		const job = staffPage.locator( `[data-testid="job-card"][data-booking="${ bookingId }"]` );
		await expect( job ).toBeVisible();
		await expect( job ).toContainText( note );
		await shot( staffPage, testInfo, 'staff-schedule' );
		await job.getByTestId( 'job-en_route' ).click();
		await expect( job.getByTestId( 'job-status' ) ).toHaveText( 'en route' );
		await job.getByTestId( 'job-on_site' ).click();
		await expect( job.getByTestId( 'job-status' ) ).toHaveText( 'on site' );
		await job.getByTestId( 'job-completed' ).click();
		await expect( job.getByTestId( 'job-state' ) ).toHaveText( 'Completed' );
		await staff.close();
	} );

	await test.step( 'the customer sees the job as completed', async () => {
		await page.reload();
		await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Completed' );
	} );

	await test.step( 'the restricted admin sees it on the calendar and reports', async () => {
		const admin = await browser.newContext( { ...testInfo.project.use, baseURL: testInfo.project.use.baseURL } );
		const adminPage = await admin.newPage();
		await login( adminPage, 'demo-admin' );
		await expect( adminPage ).toHaveURL( /page=qhs-calendar/ );
		await expect( adminPage.getByText( BANNER ) ).toBeVisible();
		await expect( adminPage.locator( '.wrap' ) ).toContainText( `QHS-${ bookingId }` );
		await shot( adminPage, testInfo, 'admin-calendar' );
		await adminPage.goto( `/wp-admin/admin.php?page=qhs-bookings&s=QHS-${ bookingId }` );
		await expect( adminPage.locator( '.qhs-admin__table' ) ).toContainText( `QHS-${ bookingId }` );
		await expect( adminPage.locator( '.qhs-admin__table tbody tr' ) ).toHaveCount( 1 );
		await adminPage.goto( '/wp-admin/admin.php?page=qhs-bookings' );
		await shot( adminPage, testInfo, 'admin-bookings' );
		await adminPage.goto( '/wp-admin/admin.php?page=qhs-reports' );
		await expect( adminPage.locator( '.wrap' ) ).toContainText( 'Completed' );
		await shot( adminPage, testInfo, 'admin-reports' );
		await admin.close();
	} );

	await test.step( 'history shows every state in order', async () => {
		const states = sql( `SELECT to_state FROM qwp_qhs_booking_history WHERE booking_id = ${ bookingId } ORDER BY id` ).split( /\s+/ );
		expect( states ).toEqual( [ 'hold', 'deposit_paid', 'confirmed', 'assigned', 'completed' ] );
	} );
} );

test( 'customer can reschedule and then cancel from the portal', async ( { page } ) => {
	await login( page, 'demo-customer' );
	await runBooking( page, { slotIndex: 10 } );
	await payDeposit( page );
	const bookingId = bookingIdFrom( page.url() );
	const before = await page.getByTestId( 'booking-slot' ).textContent();

	await page.getByTestId( 'reschedule-open' ).click();
	const dialog = page.getByTestId( 'reschedule-dialog' );
	await expect( dialog ).toBeVisible();
	await dialog.locator( '[data-testid="cal-day"].is-open' ).nth( 2 ).click();
	await dialog.getByTestId( 'reschedule-slot' ).first().click();
	await expect( page.getByTestId( 'portal-notice' ) ).toContainText( 'updated' );
	await expect( page.getByTestId( 'booking-slot' ) ).not.toHaveText( before );

	await page.getByTestId( 'cancel' ).click();
	await page.getByTestId( 'cancel-confirm' ).click();
	await expect( page.getByTestId( 'booking-state' ) ).toHaveText( 'Cancelled' );
	expect( sql( `SELECT COUNT(*) FROM qwp_qhs_slot_claims WHERE booking_id = ${ bookingId }` ) ).toBe( '0' );
} );

test( 'a guest gets a quote without an account', async ( { page }, testInfo ) => {
	await runBooking( page, { slotIndex: 14, email: `guest-${ testInfo.project.name }@example.com` } );
	await expect( page.getByTestId( 'pay-link' ) ).toContainText( 'test deposit' );
} );

test( 'the banner shows on every page and nothing scrolls sideways', async ( { page } ) => {
	for ( const path of [ '/', '/services/', '/book/?service=lawn', '/portal/', '/staff/', '/demo/', '/wp-login.php' ] ) {
		await page.goto( path );
		await expect( page.getByText( BANNER ), path ).toBeVisible();
		const overflow = await page.evaluate( () => {
			window.scrollTo( 200, 0 );
			return window.scrollX;
		} );
		expect( overflow, `${ path } scrolls sideways` ).toBe( 0 );
	}
} );

test( 'demo accounts stay inside their area', async ( { page } ) => {
	await login( page, 'demo-customer' );
	await page.goto( '/wp-admin/profile.php' );
	await expect( page ).not.toHaveURL( /profile\.php/ );

	await page.context().clearCookies();
	await login( page, 'demo-admin' );
	// Each screen is either refused by WordPress core or redirected by the demo guard.
	for ( const screen of [ 'plugins.php', 'options-general.php', 'users.php', 'themes.php', 'profile.php' ] ) {
		await page.goto( `/wp-admin/${ screen }` );
		const refused = ( await page.locator( 'body' ).textContent() ).includes( 'not allowed' );
		expect( refused || page.url().includes( 'page=qhs-calendar' ), screen ).toBe( true );
	}
} );

test( 'a customer cannot open another customer booking', async ( { browser, page }, testInfo ) => {
	await runBooking( page, { slotIndex: 16, email: `owner-${ testInfo.project.name }@example.com` } );
	const bookingId = sql( "SELECT MAX(id) FROM qwp_qhs_bookings" );

	const other = await browser.newContext( { ...testInfo.project.use, baseURL: testInfo.project.use.baseURL } );
	const otherPage = await other.newPage();
	await login( otherPage, 'demo-customer' );
	await otherPage.goto( `/portal/?booking=${ bookingId }` );
	await expect( otherPage.locator( '#qhs-portal' ) ).toContainText( 'Booking not found' );
	await other.close();
} );

test( 'search, chips, filters and sorting narrow the service list', async ( { page }, testInfo ) => {
	await page.goto( '/services/' );
	await expect( page.getByText( BANNER ) ).toBeVisible();
	const cards = page.locator( '.qhs-svc:visible' );
	await expect( cards ).toHaveCount( 6 );
	await shot( page, testInfo, 'services' );

	await page.getByTestId( 'chip-garden' ).click();
	await expect( cards ).toHaveCount( 2 );
	await expect( page ).toHaveURL( /category=garden/ );
	await page.getByTestId( 'sort' ).selectOption( 'price-asc' );
	await expect( cards.first() ).toHaveAttribute( 'data-key', 'lawn' );

	await page.getByTestId( 'chip-all' ).click();
	await page.getByTestId( 'filters-toggle' ).click();
	await page.getByTestId( 'filter-length-short' ).check();
	await expect( cards ).toHaveCount( 3 );
	await expect( page.locator( '.qhs-svc[data-key="deep-clean"]' ) ).toBeHidden();
	await page.getByTestId( 'sort' ).selectOption( 'price-desc' );
	await expect( cards.first() ).toHaveAttribute( 'data-key', 'handyman' );
	await page.locator( '[data-qhs-filters] [data-qhs-clear]' ).click();
	await expect( cards ).toHaveCount( 6 );
} );

test( 'the date panel and the reservation calendar work from the keyboard', async ( { page }, testInfo ) => {
	await page.goto( '/' );
	await shot( page, testInfo, 'home' );
	await page.getByTestId( 'search-open-date' ).focus();
	await page.keyboard.press( 'Enter' );
	const panel = page.locator( '#qhs-pop-date' );
	await expect( panel ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( panel ).toHaveCount( 0 );
	await expect( page.getByTestId( 'search-open-date' ) ).toBeFocused();

	await page.goto( '/book/?service=handyman&area=central' );
	const first = page.locator( '[data-qhs-reserve] [data-testid="cal-day"].is-open' ).first();
	await first.click();
	const firstDate = await first.getAttribute( 'data-date' );
	await page.locator( `[data-qhs-reserve] [data-date="${ firstDate }"]` ).focus();
	await page.keyboard.press( 'ArrowRight' );
	const next = await page.evaluate( () => document.activeElement.getAttribute( 'data-date' ) );
	expect( next ).not.toBe( firstDate );
	await page.keyboard.press( 'Enter' );
	const moved = page.locator( `[data-qhs-reserve] [data-date="${ next }"]` );
	if ( await moved.evaluate( ( node ) => node.classList.contains( 'is-open' ) ) ) {
		await expect( moved ).toHaveAttribute( 'aria-pressed', 'true' );
	}
	await expect( page.getByTestId( 'slot' ).first() ).toBeVisible();
	await shot( page, testInfo, 'service' );
} );

test( 'the drawer menu opens with focus inside and closes on Escape at phone width', async ( { page }, testInfo ) => {
	test.skip( testInfo.project.name !== 'phone', 'The drawer only shows on narrow screens.' );
	await page.goto( '/' );
	const opener = page.getByRole( 'button', { name: 'Open menu' } );
	await opener.click();
	const drawer = page.getByRole( 'dialog', { name: 'Menu' } );
	await expect( drawer ).toBeVisible();
	await expect( drawer.getByRole( 'link', { name: 'Services' } ) ).toBeFocused();
	await shot( page, testInfo, 'menu' );
	await page.keyboard.press( 'Escape' );
	await expect( drawer ).toBeHidden();
	await expect( opener ).toBeFocused();
} );
