/* Shared helpers for the explore grid, the listing page and trips. Exposed as window.qhs. */
( function () {
	const config = window.qhsConfig || {};
	const nonceTag = document.querySelector( 'meta[name="qhs-nonce"]' );
	const nonce = nonceTag ? nonceTag.getAttribute( 'content' ) : '';
	const DAY = 86400000;

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

	/* Build DOM nodes from data, so any text from the server is inserted as text. */
	function el( tag, attrs = {}, ...children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else if ( value === true ) {
				node.setAttribute( key, '' );
			} else if ( value !== false && value !== null && value !== undefined ) {
				node.setAttribute( key, value );
			}
		} );
		children.flat( Infinity ).forEach( ( child ) => {
			if ( child !== null && child !== undefined && child !== false ) {
				node.append( child.nodeType ? child : document.createTextNode( String( child ) ) );
			}
		} );
		return node;
	}

	const escape = ( text ) => String( text ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

	/* Dates are handled in UTC, because every night and seating is stored and shown in UTC. */
	const toDate = ( iso ) => new Date( iso + 'T00:00:00Z' );
	const iso = ( date ) => date.toISOString().slice( 0, 10 );
	const addDays = ( day, n ) => iso( new Date( toDate( day ).getTime() + n * DAY ) );
	const nightsBetween = ( a, b ) => Math.round( ( toDate( b ) - toDate( a ) ) / DAY );
	const formatDay = ( day, opts = {} ) => toDate( day ).toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', timeZone: 'UTC', ...opts } );
	const formatTime = ( slot ) => slot.slice( 11, 16 );

	/*
	 * Month calendar. In range mode the first pick is the arrival and the second the departure;
	 * a departure may land on a taken night because the guest leaves that morning.
	 * Arrow keys move by day and week, PageUp and PageDown by month, Enter or Space picks.
	 */
	function calendar( { min, max, range = false, blocked = () => '', start = '', end = '', months = 2, onChange } ) {
		const root = el( 'div', { class: 'qm-cal', 'data-testid': 'calendar' } );
		let from = start;
		let to = end;
		let focus = start || min;
		let view = toDate( focus );
		view = new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth(), 1 ) );

		const status = ( day ) => {
			if ( day < min || day > max ) {
				return 'out';
			}
			return blocked( day ) || '';
		};
		const canPick = ( day ) => {
			if ( ! range ) {
				return status( day ) === '';
			}
			if ( from && ! to && day > from ) {
				// Departure: every night from arrival to the day before must be free.
				for ( let d = from; d < day; d = addDays( d, 1 ) ) {
					if ( status( d ) !== '' ) {
						return false;
					}
				}
				return day <= max;
			}
			return status( day ) === '';
		};

		function pick( day ) {
			if ( ! canPick( day ) ) {
				return;
			}
			if ( ! range ) {
				from = day;
			} else if ( ! from || to || day <= from ) {
				from = day;
				to = '';
			} else {
				to = day;
			}
			focus = day;
			paint( true );
			onChange?.( { from, to } );
		}

		function month( first ) {
			const year = first.getUTCFullYear();
			const m = first.getUTCMonth();
			const lead = ( first.getUTCDay() + 6 ) % 7;
			const days = new Date( Date.UTC( year, m + 1, 0 ) ).getUTCDate();
			const title = first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' } );
			const cells = [];
			for ( let i = 0; i < lead; i++ ) {
				cells.push( el( 'span', { class: 'qm-cal__pad', 'aria-hidden': 'true' } ) );
			}
			for ( let d = 1; d <= days; d++ ) {
				const day = iso( new Date( Date.UTC( year, m, d ) ) );
				const st = status( day );
				const open = canPick( day );
				const inRange = range && from && to && day > from && day < to;
				const cls = [ 'qm-cal__day' ];
				if ( ! open ) {
					cls.push( 'is-off' );
				}
				if ( st && st !== 'out' ) {
					cls.push( 'is-' + st );
				}
				if ( day === from || day === to ) {
					cls.push( 'is-picked' );
				}
				if ( inRange ) {
					cls.push( 'is-between' );
				}
				cells.push(
					el(
						'button',
						{
							type: 'button',
							class: cls.join( ' ' ),
							'data-testid': 'cal-day',
							'data-date': day,
							tabindex: day === focus ? '0' : '-1',
							'aria-pressed': day === from || day === to ? 'true' : 'false',
							'aria-disabled': open ? null : 'true',
							'aria-label': toDate( day ).toLocaleDateString( 'en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' } ) + ( open ? '' : ', not available' ),
							onClick: () => pick( day ),
						},
						String( d )
					)
				);
			}
			return el(
				'div',
				{ class: 'qm-cal__month' },
				el( 'p', { class: 'qm-cal__title' }, title ),
				el( 'div', { class: 'qm-cal__head', 'aria-hidden': 'true' }, [ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( ( n ) => el( 'span', {}, n ) ) ),
				el( 'div', { class: 'qm-cal__grid' }, cells )
			);
		}

		function paint( keepFocus ) {
			const shown = [];
			for ( let i = 0; i < months; i++ ) {
				shown.push( month( new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth() + i, 1 ) ) ) );
			}
			const prevAllowed = iso( new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth(), 0 ) ) ) >= min;
			const nextFirst = iso( new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth() + months, 1 ) ) );
			root.replaceChildren(
				el(
					'div',
					{ class: 'qm-cal__nav' },
					el( 'button', { type: 'button', class: 'qm-cal__arrow', 'aria-label': 'Previous month', disabled: ! prevAllowed, onClick: () => move( -1 ) }, '‹' ),
					el( 'button', { type: 'button', class: 'qm-cal__arrow', 'aria-label': 'Next month', disabled: nextFirst > max, onClick: () => move( 1 ) }, '›' )
				),
				el( 'div', { class: 'qm-cal__months' }, shown )
			);
			if ( keepFocus ) {
				root.querySelector( `[data-date="${ focus }"]` )?.focus();
			}
		}

		function move( n ) {
			view = new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth() + n, 1 ) );
			paint( false );
		}

		root.addEventListener( 'keydown', ( event ) => {
			const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, PageUp: -30, PageDown: 30 };
			if ( ! ( event.key in steps ) || ! event.target.dataset?.date ) {
				return;
			}
			event.preventDefault();
			focus = addDays( event.target.dataset.date, steps[ event.key ] );
			const first = new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth(), 1 ) );
			const last = new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth() + months, 0 ) );
			if ( toDate( focus ) < first || toDate( focus ) > last ) {
				const f = toDate( focus );
				view = new Date( Date.UTC( f.getUTCFullYear(), f.getUTCMonth(), 1 ) );
			}
			paint( true );
		} );

		paint( false );
		return {
			node: root,
			clear() {
				from = '';
				to = '';
				paint( false );
			},
			refresh() {
				paint( false );
			},
		};
	}

	/* Photo arrows and dots on a card's scroll-snap strip. */
	function cardPhotos( card ) {
		const strip = card.querySelector( '[data-qm-photos]' );
		const prev = card.querySelector( '[data-dir="-1"]' );
		const next = card.querySelector( '[data-dir="1"]' );
		const dots = [ ...card.querySelectorAll( '.qm-card__dots i' ) ];
		const link = card.querySelector( 'a' );
		strip?.addEventListener( 'click', () => {
			if ( link ) {
				window.location.href = link.href;
			}
		} );
		if ( ! strip || ! next ) {
			return;
		}
		const index = () => Math.round( strip.scrollLeft / strip.clientWidth );
		const sync = () => {
			const i = index();
			prev.hidden = i <= 0;
			next.hidden = i >= dots.length - 1;
			dots.forEach( ( dot, n ) => dot.classList.toggle( 'is-on', n === i ) );
		};
		[ prev, next ].forEach( ( button ) =>
			button.addEventListener( 'click', () => {
				strip.scrollTo( { left: ( index() + Number( button.dataset.dir ) ) * strip.clientWidth, behavior: 'smooth' } );
			} )
		);
		strip.addEventListener( 'scroll', () => window.requestAnimationFrame( sync ), { passive: true } );
		sync();
	}

	/* Leaflet map with OpenStreetMap tiles and their attribution. */
	function baseMap( node, options = {} ) {
		const map = window.L.map( node, { zoomControl: true, attributionControl: true, ...options } );
		window.L.tileLayer( config.tiles, { maxZoom: 18, attribution: config.attribution } ).addTo( map );
		return map;
	}

	/* Countdown text for a hold, in minutes and seconds. */
	function countdown( node, expiresAt, onDone ) {
		const end = new Date( expiresAt.replace( ' ', 'T' ) + 'Z' ).getTime();
		const tick = () => {
			const left = Math.max( 0, end - Date.now() );
			const m = Math.floor( left / 60000 );
			const s = Math.floor( ( left % 60000 ) / 1000 );
			node.textContent = `${ m }:${ String( s ).padStart( 2, '0' ) }`;
			if ( left <= 0 ) {
				window.clearInterval( timer );
				onDone?.();
			}
		};
		const timer = window.setInterval( tick, 1000 );
		tick();
		return () => window.clearInterval( timer );
	}

	/* Render the Turnstile widget when keys exist; resolve with a getter for the token. */
	function turnstile( host ) {
		if ( ! config.turnstileKey ) {
			return () => '';
		}
		let token = '';
		const box = el( 'div', { class: 'qm-turnstile' } );
		host.append( box );
		const render = () => {
			window.turnstile.render( box, { sitekey: config.turnstileKey, callback: ( t ) => ( token = t ), 'expired-callback': () => ( token = '' ) } );
		};
		if ( window.turnstile ) {
			render();
		} else {
			const wait = window.setInterval( () => {
				if ( window.turnstile ) {
					window.clearInterval( wait );
					render();
				}
			}, 200 );
		}
		return () => token;
	}

	window.qhs = { config, nonce, api, el, escape, toDate, iso, addDays, nightsBetween, formatDay, formatTime, calendar, cardPhotos, baseMap, countdown, turnstile };
}() );
