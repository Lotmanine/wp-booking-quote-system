/* Shared helpers for the wizard, portal and staff views. */
( function () {
	const config = window.qhsConfig || {};
	const nonceTag = document.querySelector( 'meta[name="qhs-nonce"]' );
	const nonce = nonceTag ? nonceTag.getAttribute( 'content' ) : '';

	async function api( path, options = {} ) {
		const headers = { Accept: 'application/json' };
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}
		if ( options.token ) {
			headers[ 'X-QHS-Token' ] = options.token;
		}
		const init = { method: options.method || 'GET', headers, credentials: 'same-origin' };
		if ( options.body ) {
			headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( options.body );
		}
		const response = await fetch( config.rest + path, init );
		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			const error = new Error( data.message || 'Something went wrong. Please try again.' );
			error.code = data.code;
			throw error;
		}
		return data;
	}

	/* Build DOM nodes from data, so customer text is always inserted as text. */
	function el( tag, attrs = {}, ...children ) {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ key, value ] ) => {
			if ( key === 'onClick' ) {
				node.addEventListener( 'click', value );
			} else if ( key === 'onChange' ) {
				node.addEventListener( 'change', value );
			} else if ( value === true ) {
				node.setAttribute( key, '' );
			} else if ( value !== false && value !== null && value !== undefined ) {
				node.setAttribute( key, value );
			}
		} );
		children.flat().forEach( ( child ) => {
			if ( child !== null && child !== undefined ) {
				node.append( child.nodeType ? child : document.createTextNode( String( child ) ) );
			}
		} );
		return node;
	}

	function formatSlot( slot ) {
		const date = new Date( slot.replace( ' ', 'T' ) + 'Z' );
		return date.toLocaleString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
			timeZone: 'UTC',
		} ) + ' UTC';
	}

	function renderTurnstile( host ) {
		return new Promise( ( resolve ) => {
			let tries = 0;
			const start = () => {
				if ( window.turnstile ) {
					const state = { token: '' };
					state.widget = window.turnstile.render( host, {
						sitekey: config.turnstileKey,
						callback: ( token ) => {
							state.token = token;
						},
						'expired-callback': () => {
							state.token = '';
						},
					} );
					resolve( state );
				} else if ( ++tries < 100 ) {
					setTimeout( start, 100 );
				} else {
					resolve( { token: '', failed: true } );
				}
			};
			start();
		} );
	}

	window.qhs = { api, el, formatSlot, renderTurnstile, config, nonce };
}() );
