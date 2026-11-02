/* Shared helpers for the search bar, service pages, portal and staff agenda. */
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

	/* Build DOM nodes from data, so customer text is always inserted as text. */
	function el( tag, attrs = {}, ...children ) {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ key, value ] ) => {
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

	const ICONS = {
		cleaning: 'M7 21h10M9 21V9h6v12M10 9V5h4v4M14 5h3l1 2',
		repairs: 'M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2.6-.4-.4-2.6z',
		garden: 'M12 21v-8M12 13c-4 0-6-3-6-7 4 0 6 3 6 7zM12 13c4 0 6-3 6-7-4 0-6 3-6 7z',
		all: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
		pin: 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
		calendar: 'M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM4 10h16M9 3v4M15 3v4',
		home: 'M4 11l8-7 8 7v9H4zM10 20v-5h4v5',
		clock: 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17zM12 7.5V12l3 2',
		check: 'M5 12.5l4.5 4.5L19 7.5',
		user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
		left: 'M15 6l-6 6 6 6',
		right: 'M9 6l6 6-6 6',
		close: 'M6 6l12 12M18 6L6 18',
		file: 'M7 3h7l5 5v13H7zM14 3v5h5',
		alert: 'M12 4l9 16H3zM12 10v4M12 17v.5',
	};

	function icon( name, size = 20 ) {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'class', 'qhs-icon' );
		svg.setAttribute( 'width', size );
		svg.setAttribute( 'height', size );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.8' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		const path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', ICONS[ name ] || '' );
		svg.append( path );
		return svg;
	}

	const money = ( cents ) => '$' + ( cents / 100 ).toFixed( 2 );
	const moneyShort = ( cents ) => '$' + Math.round( cents / 100 );

	/* Dates are handled in UTC, because every slot is stored and shown in UTC. */
	const toDate = ( iso ) => new Date( iso + 'T00:00:00Z' );
	const isoDay = ( date ) => date.toISOString().slice( 0, 10 );
	const slotDate = ( slot ) => new Date( slot.replace( ' ', 'T' ) + 'Z' );

	function formatSlot( slot ) {
		return slotDate( slot ).toLocaleString( 'en-US', {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
			timeZone: 'UTC',
		} ) + ' UTC';
	}

	function formatTime( slot ) {
		return slotDate( slot ).toLocaleTimeString( 'en-US', { hour: 'numeric', minute: '2-digit', timeZone: 'UTC' } );
	}

	function formatDay( iso, long = false ) {
		return toDate( iso ).toLocaleDateString( 'en-US', {
			weekday: long ? 'long' : 'short',
			month: long ? 'long' : 'short',
			day: 'numeric',
			timeZone: 'UTC',
		} );
	}

	/* Bookable window, mirrored from the server so the calendar greys out what the server refuses. */
	function windowBounds() {
		const now = Date.now();
		return {
			min: isoDay( new Date( now + ( config.leadHours || 12 ) * 3600000 ) ),
			max: isoDay( new Date( now + ( config.daysAhead || 14 ) * DAY ) ),
		};
	}

	const catalog = config.catalog || {};
	const find = ( group, key ) => ( catalog[ group ] || [] ).find( ( item ) => item.key === key );

	/* Client estimate. The server computes the real price when the quote is created. */
	function estimate( service, size, area, extras ) {
		const row = find( 'services', service );
		if ( ! row ) {
			return null;
		}
		const sizeRow = find( 'sizes', size ) || find( 'sizes', 'small' );
		const areaRow = find( 'areas', area );
		const base = Math.round( ( row.cents * ( sizeRow ? sizeRow.multiplier : 100 ) ) / 100 );
		const lines = [ { label: `${ row.label }, ${ sizeRow ? sizeRow.label : '' }`, cents: base } ];
		if ( areaRow && areaRow.cents > 0 ) {
			lines.push( { label: `${ areaRow.label } travel`, cents: areaRow.cents } );
		}
		[ ...new Set( extras ) ].forEach( ( key ) => {
			const extra = find( 'extras', key );
			if ( extra ) {
				lines.push( { label: extra.label, cents: extra.cents } );
			}
		} );
		const total = lines.reduce( ( sum, line ) => sum + line.cents, 0 );
		const deposit = Math.max( 2000, Math.round( ( total * 0.2 ) / 100 ) * 100 );
		return { lines, total, deposit };
	}

	/*
	 * Month calendar with a roving tab stop. Arrow keys move by day and week, PageUp and PageDown
	 * by month, Home and End to the edges of the week. Enter or Space picks the focused day.
	 */
	function calendar( { min, max, isOpen = () => true, selected = '', onSelect, label = 'Choose a date' } ) {
		const root = el( 'div', { class: 'qhs-cal', 'data-testid': 'calendar' } );
		let focusDay = selected || min;
		let view = toDate( focusDay );
		view = new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth(), 1 ) );

		const usable = ( iso ) => iso >= min && iso <= max && isOpen( iso );

		function paint( moveFocus ) {
			const year = view.getUTCFullYear();
			const month = view.getUTCMonth();
			const first = new Date( Date.UTC( year, month, 1 ) );
			const lead = ( first.getUTCDay() + 6 ) % 7;
			const days = new Date( Date.UTC( year, month + 1, 0 ) ).getUTCDate();
			const title = first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' } );
			const prevMonth = isoDay( new Date( Date.UTC( year, month, 0 ) ) );
			const nextMonth = isoDay( new Date( Date.UTC( year, month + 1, 1 ) ) );

			const rows = [];
			let cells = [];
			for ( let i = 0; i < lead; i++ ) {
				cells.push( el( 'td', { role: 'gridcell' } ) );
			}
			for ( let day = 1; day <= days; day++ ) {
				const iso = isoDay( new Date( Date.UTC( year, month, day ) ) );
				const open = usable( iso );
				cells.push(
					el(
						'td',
						{ role: 'gridcell' },
						el(
							'button',
							{
								type: 'button',
								class: 'qhs-cal__day' + ( open ? ' is-open' : '' ) + ( iso === selected ? ' is-selected' : '' ),
								'data-testid': 'cal-day',
								'data-date': iso,
								tabindex: iso === focusDay ? '0' : '-1',
								'aria-pressed': iso === selected ? 'true' : 'false',
								'aria-disabled': open ? null : 'true',
								'aria-label': formatDay( iso, true ) + ( open ? '' : ', unavailable' ),
								onClick: () => {
									if ( usable( iso ) ) {
										selected = iso;
										focusDay = iso;
										paint( true );
										onSelect( iso );
									}
								},
							},
							String( day )
						)
					)
				);
				if ( cells.length === 7 ) {
					rows.push( el( 'tr', {}, cells ) );
					cells = [];
				}
			}
			if ( cells.length ) {
				while ( cells.length < 7 ) {
					cells.push( el( 'td', { role: 'gridcell' } ) );
				}
				rows.push( el( 'tr', {}, cells ) );
			}

			root.replaceChildren(
				el(
					'div',
					{ class: 'qhs-cal__head' },
					el( 'button', { type: 'button', class: 'qhs-cal__nav', 'aria-label': 'Previous month', disabled: prevMonth < min, onClick: () => shift( -1 ) }, icon( 'left', 18 ) ),
					el( 'p', { class: 'qhs-cal__title', 'aria-live': 'polite' }, title ),
					el( 'button', { type: 'button', class: 'qhs-cal__nav', 'aria-label': 'Next month', disabled: nextMonth > max, onClick: () => shift( 1 ) }, icon( 'right', 18 ) )
				),
				el(
					'table',
					{ class: 'qhs-cal__grid', role: 'grid', 'aria-label': `${ label }, ${ title }` },
					el( 'thead', {}, el( 'tr', {}, [ 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' ].map( ( d ) => el( 'th', { scope: 'col', abbr: d } , d ) ) ) ),
					el( 'tbody', {}, rows )
				)
			);
			if ( moveFocus ) {
				const target = root.querySelector( `[data-date="${ focusDay }"]` );
				if ( target ) {
					target.focus();
				}
			}
		}

		function shift( months ) {
			view = new Date( Date.UTC( view.getUTCFullYear(), view.getUTCMonth() + months, 1 ) );
			const firstOpen = [ ...Array( 31 ).keys() ]
				.map( ( i ) => isoDay( new Date( view.getTime() + i * DAY ) ) )
				.find( ( iso ) => iso.slice( 0, 7 ) === isoDay( view ).slice( 0, 7 ) && usable( iso ) );
			focusDay = firstOpen || isoDay( view );
			paint( false );
		}

		root.addEventListener( 'keydown', ( event ) => {
			const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
			let next = null;
			const current = toDate( focusDay );
			if ( steps[ event.key ] !== undefined ) {
				next = new Date( current.getTime() + steps[ event.key ] * DAY );
			} else if ( event.key === 'Home' ) {
				next = new Date( current.getTime() - ( ( current.getUTCDay() + 6 ) % 7 ) * DAY );
			} else if ( event.key === 'End' ) {
				next = new Date( current.getTime() + ( 6 - ( ( current.getUTCDay() + 6 ) % 7 ) ) * DAY );
			} else if ( event.key === 'PageUp' || event.key === 'PageDown' ) {
				next = new Date( Date.UTC( current.getUTCFullYear(), current.getUTCMonth() + ( event.key === 'PageUp' ? -1 : 1 ), current.getUTCDate() ) );
			}
			if ( ! next || ! event.target.classList.contains( 'qhs-cal__day' ) ) {
				return;
			}
			event.preventDefault();
			const iso = isoDay( next );
			if ( iso < min || iso > max ) {
				return;
			}
			focusDay = iso;
			view = new Date( Date.UTC( next.getUTCFullYear(), next.getUTCMonth(), 1 ) );
			paint( true );
		} );

		paint( false );
		root.qhsRefresh = ( nextIsOpen, nextSelected ) => {
			isOpen = nextIsOpen;
			selected = nextSelected;
			paint( false );
		};
		return root;
	}

	/*
	 * Turnstile widget. Tokens are single use, so fresh() resets the widget once a token was spent
	 * and waits for the next one.
	 */
	function renderTurnstile( host ) {
		const state = { token: '', spent: false, widget: null, failed: false };
		const ready = new Promise( ( resolve ) => {
			let tries = 0;
			const start = () => {
				// The host must be in the page before Turnstile renders into it.
				if ( window.turnstile && config.turnstileKey && host.isConnected ) {
					state.widget = window.turnstile.render( host, {
						sitekey: config.turnstileKey,
						callback: ( token ) => {
							state.token = token;
							state.spent = false;
						},
						'expired-callback': () => {
							state.token = '';
						},
					} );
					resolve();
				} else if ( ++tries < 300 && config.turnstileKey ) {
					setTimeout( start, 100 );
				} else {
					state.failed = true;
					resolve();
				}
			};
			start();
		} );

		state.fresh = async () => {
			await ready;
			if ( state.failed ) {
				return '';
			}
			if ( state.spent ) {
				state.token = '';
				window.turnstile.reset( state.widget );
			}
			const started = Date.now();
			while ( ! state.token && Date.now() - started < 60000 ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 150 ) );
			}
			state.spent = true;
			return state.token;
		};
		return state;
	}

	/* Modal dialog on the native element, which traps focus and closes on Escape. */
	function dialog( { title, body, actions = [], testid = 'dialog' } ) {
		const node = el(
			'dialog',
			{ class: 'qhs-dialog', 'aria-labelledby': 'qhs-dialog-title', 'data-testid': testid },
			el(
				'div',
				{ class: 'qhs-dialog__head' },
				el( 'h2', { id: 'qhs-dialog-title', class: 'qhs-dialog__title' }, title ),
				el( 'button', { type: 'button', class: 'qhs-icon-btn', 'aria-label': 'Close', onClick: () => node.close() }, icon( 'close', 18 ) )
			),
			el( 'div', { class: 'qhs-dialog__body' }, body ),
			actions.length
				? el(
					'div',
					{ class: 'qhs-dialog__actions' },
					actions.map( ( action ) =>
						el(
							'button',
							{
								type: 'button',
								class: 'qhs-btn ' + ( action.primary ? 'qhs-btn--primary' : action.danger ? 'qhs-btn--danger' : 'qhs-btn--secondary' ),
								'data-testid': action.testid || null,
								onClick: () => action.onClick( node ),
							},
							action.label
						)
					)
				)
				: null
		);
		node.addEventListener( 'close', () => node.remove() );
		document.body.append( node );
		node.showModal();
		return node;
	}

	function params() {
		return new URLSearchParams( window.location.search );
	}

	window.qhs = {
		api,
		el,
		icon,
		money,
		moneyShort,
		formatSlot,
		formatTime,
		formatDay,
		isoDay,
		slotDate,
		windowBounds,
		estimate,
		find,
		calendar,
		renderTurnstile,
		dialog,
		params,
		catalog,
		config,
		nonce,
	};
}() );
