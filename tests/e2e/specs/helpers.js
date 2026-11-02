import { expect } from '@playwright/test';

export const PASSWORD = process.env.QHS_DEMO_PASSWORD || 'quillmoor-demo-2026';
export const BANNER = 'Demo: fictional company, no real services or payments';

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

export async function waitForTurnstile( page ) {
	await page.waitForFunction( () => {
		const input = document.querySelector( '.qhs-turnstile input[name="cf-turnstile-response"]' );
		return input && input.value.length > 0;
	}, null, { timeout: 60000 } );
}

/* Search from the home page: each field opens a panel and moves on to the next one. */
export async function searchFromHome( page, { category = 'cleaning', area = 'north', size = 'small' } = {} ) {
	await page.goto( '/' );
	await expect( page.getByText( BANNER ) ).toBeVisible();
	await page.getByTestId( 'search-open-category' ).click();
	await page.getByTestId( `search-category-${ category }` ).click();
	await page.getByTestId( `search-area-${ area }` ).click();
	await page.locator( '#qhs-pop-date' ).getByRole( 'button', { name: 'Any date' } ).click();
	await page.getByTestId( `search-size-${ size }` ).click();
	await page.getByTestId( 'search-submit' ).click();
	await page.waitForURL( /\/services\/\?/ );
}

/* Pick the nth open slot for the district shown on the card, moving months when needed. */
export async function pickSlot( page, slotIndex ) {
	await expect( page.locator( '[data-testid="cal-day"].is-open' ).first() ).toBeVisible();
	const area = await page.locator( '[data-qhs-reserve] [aria-pressed="true"][data-testid^="reserve-area-"]' ).getAttribute( 'data-testid' );
	const slot = await page.evaluate( async ( key ) => {
		const response = await fetch( `${ window.qhs.config.rest }availability?area=${ key }` );
		const { days } = await response.json();
		return Object.values( days ).flat();
	}, area.replace( 'reserve-area-', '' ) ).then( ( slots ) => slots[ slotIndex ] );
	expect( slot, 'enough open slots' ).toBeTruthy();
	const day = page.locator( `[data-qhs-reserve] [data-testid="cal-day"][data-date="${ slot.slice( 0, 10 ) }"]` );
	if ( ! ( await day.count() ) ) {
		await page.locator( '[data-qhs-reserve]' ).getByRole( 'button', { name: 'Next month' } ).click();
	}
	await day.click();
	await page.locator( `[data-testid="slot"][data-slot="${ slot }"]` ).click();
	return slot;
}

/* Service page to a held slot: size, district, day, time, extras, details, hold. */
export async function runBooking( page, { note = '', slotIndex = 0, email = '', fromSearch = false } = {} ) {
	if ( fromSearch ) {
		await searchFromHome( page );
		await page.getByTestId( 'service-card-cleaning' ).getByRole( 'link' ).click();
		await page.waitForURL( /\/book\/\?service=cleaning/ );
	} else {
		await page.goto( '/book/?service=cleaning' );
		await page.getByTestId( 'reserve-area-north' ).click();
	}
	await expect( page.getByText( BANNER ) ).toBeVisible();
	await expect( page.getByTestId( 'reserve-size-small' ) ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( page.getByTestId( 'reserve-area-north' ) ).toHaveAttribute( 'aria-pressed', 'true' );

	await pickSlot( page, slotIndex );
	await page.getByTestId( 'extra-supplies' ).check();
	await expect( page.getByTestId( 'estimate-total' ) ).toHaveText( '$135.00' );
	await expect( page.getByTestId( 'estimate-deposit' ) ).toHaveText( '$27.00' );
	await page.getByTestId( 'reserve-continue' ).click();

	if ( email ) {
		await page.getByTestId( 'field-full_name' ).fill( 'Playwright Visitor' );
		await page.getByTestId( 'field-email' ).fill( email );
	}
	if ( note ) {
		await page.getByTestId( 'field-note' ).fill( note );
	}
	await waitForTurnstile( page );
	await page.getByTestId( 'hold-submit' ).click();

	await expect( page.getByTestId( 'quote-total' ) ).toHaveText( '$135.00' );
	await expect( page.getByTestId( 'quote-deposit' ) ).toHaveText( '$27.00' );
	await expect( page.getByTestId( 'hold-countdown' ) ).toHaveText( /^(9|10):\d\d$/ );
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
	await page.waitForURL( /\/portal\/.*paid=1/, { timeout: 60000 } );
	await expect( page.getByTestId( 'portal-notice' ) ).toContainText( 'test deposit was received' );
}

export function bookingIdFrom( url ) {
	return new URL( url ).searchParams.get( 'booking' );
}
