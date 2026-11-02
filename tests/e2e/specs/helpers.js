import { expect } from '@playwright/test';

export const PASSWORD = process.env.QHS_DEMO_PASSWORD || 'quillmoor-demo-2026';
export const BANNER = 'Demo: fictional company, no real services or payments';

export async function login( page, username ) {
	await page.goto( '/wp-login.php' );
	await expect( page.getByText( BANNER ) ).toBeVisible();
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'domcontentloaded' );
}

export async function waitForTurnstile( page ) {
	await page.waitForFunction( () => {
		const input = document.querySelector( '.qhs-turnstile input[name="cf-turnstile-response"]' );
		return input && input.value.length > 0;
	}, null, { timeout: 30000 } );
}

export async function runWizard( page, { note = '', slotIndex = 0, email = '' } = {} ) {
	await page.goto( '/quote/' );
	await expect( page.getByText( BANNER ) ).toBeVisible();
	await page.getByTestId( 'service-cleaning' ).click();
	await page.getByTestId( 'size-small' ).click();
	await page.getByTestId( 'area-north' ).click();
	await page.getByTestId( 'extra-supplies' ).check();
	await page.getByTestId( 'extras-next' ).click();
	if ( email ) {
		await page.getByTestId( 'field-full_name' ).fill( 'Playwright Visitor' );
		await page.getByTestId( 'field-email' ).fill( email );
	}
	if ( note ) {
		await page.getByTestId( 'field-note' ).fill( note );
	}
	await waitForTurnstile( page );
	await page.getByTestId( 'quote-submit' ).click();

	await expect( page.getByTestId( 'quote-total' ) ).toHaveText( '$135.00' );
	await expect( page.getByTestId( 'quote-deposit' ) ).toHaveText( '$27.00' );

	await page.getByTestId( 'slot' ).nth( slotIndex ).click();
	await waitForTurnstile( page );
	await page.getByTestId( 'hold-submit' ).click();
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
