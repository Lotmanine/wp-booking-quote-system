import { expect } from '@playwright/test';

export const PASSWORD = process.env.QHS_DEMO_PASSWORD || 'quillmoor-demo-2026';
export const BANNER = 'Demo: fictional places, no real bookings or payments';

export async function login( page, username ) {
	await page.goto( '/wp-login.php', { waitUntil: 'load' } );
	await expect( page.getByText( BANNER ) ).toBeVisible();
	// The login screen focuses and selects the username field shortly after load. Fill after that.
	await expect( page.locator( '#user_login' ) ).toBeFocused();
	await expect( async () => {
		await page.fill( '#user_login', username );
		await page.fill( '#user_pass', PASSWORD );
		await expect( page.locator( '#user_login' ) ).toHaveValue( username, { timeout: 500 } );
		await expect( page.locator( '#user_pass' ) ).toHaveValue( PASSWORD, { timeout: 500 } );
	} ).toPass( { timeout: 10000 } );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'domcontentloaded' );
}

/* Each browser project books its own dates, so desktop and phone runs never collide. */
export function offsetFor( testInfo, base ) {
	return base + ( testInfo.project.name === 'phone' ? 40 : 0 );
}

const iso = ( date ) => date.toISOString().slice( 0, 10 );
const addDays = ( day, n ) => iso( new Date( new Date( day + 'T00:00:00Z' ).getTime() + n * 86400000 ) );

/* First free run of nights at or after an offset, read from the same calendar the card uses. */
export async function freeStay( page, listingId, offset, nights ) {
	return page.evaluate(
		async ( { id, start, n } ) => {
			const add = ( d, k ) => new Date( new Date( d + 'T00:00:00Z' ).getTime() + k * 86400000 ).toISOString().slice( 0, 10 );
			const today = new Date().toISOString().slice( 0, 10 );
			const response = await fetch( `${ window.qhs.config.rest }listings/${ id }/calendar?from=${ today }&to=${ add( today, 260 ) }` );
			const data = await response.json();
			for ( let i = start; i < start + 150; i++ ) {
				const from = add( new Date().toISOString().slice( 0, 10 ), i );
				let ok = true;
				for ( let k = 0; k < n; k++ ) {
					if ( data.unavailable[ add( from, k ) ] ) {
						ok = false;
					}
				}
				if ( ok ) {
					return { from, to: add( from, n ) };
				}
			}
			return null;
		},
		{ id: listingId, start: offset, n: nights }
	);
}

/* First day and time with room for the party at or after an offset. */
export async function freeTable( page, listingId, offset, party ) {
	return page.evaluate(
		async ( { id, start, size } ) => {
			const today = new Date().toISOString().slice( 0, 10 );
			for ( let i = start; i < start + 30; i++ ) {
				const day = new Date( new Date( today + 'T00:00:00Z' ).getTime() + i * 86400000 ).toISOString().slice( 0, 10 );
				const response = await fetch( `${ window.qhs.config.rest }listings/${ id }/slots?date=${ day }&party=${ size }` );
				const data = await response.json();
				const open = ( data.slots || [] ).filter( ( s ) => s.fits );
				if ( open.length ) {
					return { day, slot: open[ 0 ].slot_start };
				}
			}
			return null;
		},
		{ id: listingId, start: offset, size: party }
	);
}

export async function listingId( page ) {
	const data = await page.getByTestId( 'booking-card' ).getAttribute( 'data-qm-book' );
	return JSON.parse( data ).id;
}

/* Pick a day in the booking card calendar, moving months forward when needed. */
export async function pickDay( page, day ) {
	const card = page.getByTestId( 'booking-card' );
	const cell = card.locator( `[data-testid="cal-day"][data-date="${ day }"]` );
	for ( let i = 0; i < 12 && ! ( await cell.count() ); i++ ) {
		await card.getByRole( 'button', { name: 'Next month' } ).click();
	}
	await cell.click();
}

/* Choose dates for a stay, add an extra, hold, and land on the held panel. */
export async function holdStay( page, { slug, offset, nights, extra = '' } ) {
	await page.goto( `/listing/${ slug }/` );
	await expect( page.getByText( BANNER ) ).toBeVisible();
	await expect( page.getByTestId( 'calendar' ) ).toBeVisible();
	const id = await listingId( page );
	const range = await freeStay( page, id, offset, nights );
	expect( range, 'a free run of nights' ).toBeTruthy();
	await pickDay( page, range.from );
	await pickDay( page, range.to );
	if ( extra ) {
		await page.getByTestId( `extra-${ extra }` ).check();
	}
	await expect( page.getByTestId( 'price-total' ) ).toBeVisible();
	const total = await page.getByTestId( 'price-total' ).textContent();
	const deposit = await page.getByTestId( 'price-deposit' ).textContent();
	await page.getByTestId( 'reserve' ).click();
	await page.getByTestId( 'hold-submit' ).click();
	await expect( page.getByTestId( 'held' ) ).toBeVisible();
	await expect( page.getByTestId( 'quote-total' ) ).toHaveText( total );
	await expect( page.getByTestId( 'quote-deposit' ) ).toHaveText( deposit );
	await expect( page.getByTestId( 'hold-countdown' ) ).toHaveText( /^(9|10):\d\d$/ );
	return { range, total, deposit };
}

/* Choose a day, party and time for a table, hold, and land on the held panel. */
export async function holdTable( page, { slug, offset, party } ) {
	await page.goto( `/listing/${ slug }/` );
	await expect( page.getByTestId( 'calendar' ) ).toBeVisible();
	const id = await listingId( page );
	await page.getByTestId( 'book-guests' ).selectOption( String( party ) );
	const free = await freeTable( page, id, offset, party );
	expect( free, 'a free table' ).toBeTruthy();
	await pickDay( page, free.day );
	await page.locator( `[data-testid="slot"][data-slot="${ free.slot }"]` ).click();
	await expect( page.getByTestId( 'price-total' ) ).toBeVisible();
	await page.getByTestId( 'reserve' ).click();
	await page.getByTestId( 'hold-submit' ).click();
	await expect( page.getByTestId( 'held' ) ).toBeVisible();
	await expect( page.getByTestId( 'hold-countdown' ) ).toHaveText( /^(9|10):\d\d$/ );
	return free;
}

export async function payDeposit( page ) {
	await page.getByTestId( 'pay-link' ).click();
	await page.waitForURL( /\/checkout\// );
	await expect( page.getByText( BANNER ) ).toBeVisible();
	await expect( page.locator( '.wp-block-woocommerce-checkout' ) ).toBeVisible( { timeout: 30000 } );

	const fill = async ( selector, value ) => {
		const field = page.locator( selector );
		if ( await field.isVisible() && ! ( await field.inputValue() ) ) {
			await field.fill( value );
		}
	};
	await fill( '#email', 'demo-customer@example.com' );
	await fill( '#billing-first_name', 'Avery' );
	await fill( '#billing-last_name', 'Demo' );
	await fill( '#billing-address_1', '1 Example Street' );
	await fill( '#billing-city', 'Exampleville' );
	await fill( '#billing-postcode', '94016' );
	const state = page.locator( '#billing-state' );
	if ( await state.isVisible() ) {
		await state.selectOption( 'CA' ).catch( () => {} );
	}
	await expect( page.getByText( 'Fake test gateway (not Stripe, no money moves)' ).first() ).toBeVisible();
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
	await page.waitForURL( /\/trips\/.*paid=1/, { timeout: 60000 } );
	await expect( page.getByTestId( 'trips-notice' ) ).toContainText( 'test deposit was received' );
	return new URL( page.url() ).searchParams.get( 'booking' );
}

/* No sideways scroll: the page cannot be scrolled horizontally. */
export async function expectNoSideScroll( page ) {
	const x = await page.evaluate( () => {
		window.scrollTo( 400, window.scrollY );
		const moved = window.scrollX;
		window.scrollTo( 0, window.scrollY );
		return moved;
	} );
	expect( x, 'horizontal scroll' ).toBe( 0 );
}
