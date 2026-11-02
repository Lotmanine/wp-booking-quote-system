/* Trips: upcoming, past and cancelled bookings, payments, and one booking with its actions. */
( function () {
	const qm = window.qhs;
	const { el, api } = qm;
	const root = document.querySelector( '[data-qm-trips]' );
	const body = root?.querySelector( '[data-qm-trips-body]' );
	if ( ! root ) {
		return;
	}
	const signedIn = root.dataset.signedIn === '1';
	const token = root.dataset.token;
	const cutoff = Number( root.dataset.cutoff || 24 );
	const today = qm.iso( new Date() );
	const LIVE = [ 'hold', 'deposit_paid', 'requested', 'confirmed', 'arrived' ];

	function notice( text, kind = 'ok' ) {
		return el( 'div', { class: `qm-note qm-note--${ kind }`, role: 'status', 'data-testid': 'trips-notice' }, text );
	}

	async function verify() {
		try {
			await api( 'verify-email', { method: 'POST', body: { token: root.dataset.verify } } );
			root.querySelector( '.qm-trips__head' ).after( notice( 'Thanks, your email address is confirmed.' ) );
		} catch ( error ) {
			root.querySelector( '.qm-trips__head' ).after( notice( error.message, 'warn' ) );
		}
	}

	function badge( b ) {
		return el( 'span', { class: `qhs-badge qhs-badge--${ b.state }`, 'data-testid': 'booking-state' }, b.state_label );
	}

	function tripCard( b ) {
		return el(
			'a',
			{ class: 'qm-trip', href: `?booking=${ b.id }`, 'data-testid': 'trip-card', 'data-booking': b.id },
			b.listing.photo ? el( 'img', { src: b.listing.photo, alt: '', width: 320, height: 220, loading: 'lazy' } ) : el( 'span', { class: 'qm-trip__ph' } ),
			el(
				'span',
				{ class: 'qm-trip__body' },
				badge( b ),
				el( 'strong', {}, b.listing.title ),
				el( 'span', {}, b.when + ( b.type === 'dining' ? ' UTC' : '' ) ),
				el( 'span', { class: 'qm-muted' }, `${ b.ref }, total ${ b.total }` )
			)
		);
	}

	async function list() {
		let data;
		try {
			data = await api( 'bookings' );
		} catch ( error ) {
			body.replaceChildren( notice( error.message, 'warn' ) );
			return;
		}
		const day = ( b ) => ( b.check_out || b.slot_start || '' ).slice( 0, 10 );
		const groups = {
			upcoming: data.bookings.filter( ( b ) => LIVE.includes( b.state ) && day( b ) >= today ),
			past: data.bookings.filter( ( b ) => [ 'completed', 'no_show' ].includes( b.state ) || ( LIVE.includes( b.state ) && day( b ) < today ) ),
			cancelled: data.bookings.filter( ( b ) => [ 'cancelled', 'declined' ].includes( b.state ) ),
			payments: data.bookings.filter( ( b ) => b.paid ),
		};
		const labels = { upcoming: 'Upcoming', past: 'Past', cancelled: 'Cancelled', payments: 'Payments' };
		const empty = {
			upcoming: 'No upcoming trips. Find a place to stay or a table for tonight.',
			past: 'Finished stays and meals appear here.',
			cancelled: 'Nothing cancelled.',
			payments: 'No deposits paid yet. On this demo, payments run in test mode only.',
		};
		const tabs = el( 'div', { class: 'qm-tabs', role: 'tablist' } );
		const panel = el( 'div', { class: 'qm-trips__panel', role: 'tabpanel' } );

		function show( key ) {
			tabs.querySelectorAll( 'button' ).forEach( ( b ) => b.setAttribute( 'aria-selected', b.dataset.key === key ? 'true' : 'false' ) );
			const rows = groups[ key ];
			if ( ! rows.length ) {
				panel.replaceChildren( el( 'div', { class: 'qm-empty qm-empty--small' }, el( 'p', {}, empty[ key ] ), key === 'upcoming' ? el( 'a', { class: 'qm-btn qm-btn--ghost', href: qm.config.home + 'explore/' }, 'Start exploring' ) : null ) );
				return;
			}
			if ( key === 'payments' ) {
				panel.replaceChildren(
					el(
						'table',
						{ class: 'qm-paytable', 'data-testid': 'payments' },
						el( 'thead', {}, el( 'tr', {}, el( 'th', {}, 'Booking' ), el( 'th', {}, 'Deposit' ), el( 'th', {}, 'Status' ) ) ),
						el(
							'tbody',
							{},
							rows.map( ( b ) => el( 'tr', {}, el( 'td', {}, el( 'a', { href: `?booking=${ b.id }` }, `${ b.ref }, ${ b.listing.title }` ) ), el( 'td', {}, b.deposit ), el( 'td', {}, b.refund || 'Paid in test mode' ) ) )
						)
					)
				);
				return;
			}
			panel.replaceChildren( el( 'div', { class: 'qm-trips__grid' }, rows.map( tripCard ) ) );
		}

		Object.keys( labels ).forEach( ( key ) =>
			tabs.append(
				el( 'button', { type: 'button', role: 'tab', 'data-key': key, 'data-testid': `tab-${ key }`, onClick: () => show( key ) }, labels[ key ], el( 'span', { class: 'qm-tabs__n' }, String( groups[ key ].length ) ) )
			)
		);

		const consent = el( 'input', { type: 'checkbox', checked: data.marketing_consent, 'data-testid': 'consent' } );
		consent.addEventListener( 'change', async () => {
			try {
				await api( 'consent', { method: 'POST', body: { marketing_consent: consent.checked } } );
			} catch ( error ) {
				consent.checked = ! consent.checked;
			}
		} );

		body.replaceChildren(
			tabs,
			panel,
			el( 'label', { class: 'qm-check qm-trips__consent' }, consent, el( 'span', {}, 'Occasional demo news by email. You can change this at any time.' ) )
		);
		show( 'upcoming' );
	}

	async function detail( id ) {
		let b;
		try {
			b = await api( `bookings/${ id }`, { token } );
		} catch ( error ) {
			body.replaceChildren( notice( error.message, 'warn' ) );
			return;
		}
		const query = token ? `?token=${ encodeURIComponent( token ) }` : qm.nonce ? `?_wpnonce=${ qm.nonce }` : '';
		const pdf = ( kind ) => `${ qm.config.rest }bookings/${ b.id }/pdf/${ kind }${ query }`;
		const start = new Date( b.slot_start.replace( ' ', 'T' ) + 'Z' ).getTime();
		const changeable = b.can_change && start - Date.now() > cutoff * 3600000;
		const actions = el( 'div', { class: 'qm-trip__actions' } );

		if ( changeable ) {
			actions.append( el( 'button', { type: 'button', class: 'qm-btn qm-btn--ghost', 'data-testid': 'change-open', onClick: () => changeForm( b, actions ) }, b.type === 'dining' ? 'Change time' : 'Change dates' ) );
		}
		if ( b.can_cancel ) {
			actions.append( el( 'button', { type: 'button', class: 'qm-btn qm-btn--danger', 'data-testid': 'cancel-open', onClick: () => cancelDialog( b ) }, 'Cancel booking' ) );
		}
		actions.append( el( 'a', { class: 'qm-link', href: pdf( 'quote' ), 'data-testid': 'pdf-quote' }, 'Quote PDF' ) );
		if ( ! [ 'hold', 'quote' ].includes( b.state ) ) {
			actions.append( el( 'a', { class: 'qm-link', href: pdf( 'confirmation' ), 'data-testid': 'pdf-confirmation' }, 'Booking PDF' ) );
		}

		const timer = el( 'strong', {} );
		body.replaceChildren(
			signedIn ? el( 'a', { class: 'qm-link', href: '?' }, 'All trips' ) : '',
			root.dataset.paid === '1' && b.paid ? notice( 'Your test deposit was received. Nothing real was charged.' ) : '',
			el(
				'article',
				{ class: 'qm-tripdetail', 'data-testid': 'trip-detail', 'data-booking': b.id },
				b.listing.photo ? el( 'img', { class: 'qm-tripdetail__img', src: b.listing.photo, alt: '', width: 768, height: 512 } ) : '',
				el(
					'div',
					{ class: 'qm-tripdetail__main' },
					el( 'p', {}, badge( b ), ' ', el( 'span', { class: 'qm-muted' }, b.ref ) ),
					el( 'h2', {}, el( 'a', { href: b.listing.url }, b.listing.title ) ),
					el( 'p', { class: 'qm-tripdetail__when', 'data-testid': 'trip-when' }, b.when + ( b.type === 'dining' ? ' UTC' : '' ) ),
					b.state === 'hold' && b.hold_expires_at ? el( 'p', { class: 'qm-note qm-note--warn' }, 'On hold for payment. The hold ends in ', timer, '.' ) : '',
					b.state === 'requested' ? el( 'p', { class: 'qm-note' }, `${ b.host_name || 'The host' } answers within 24 hours. Without an answer the request is declined and the deposit refunded.` ) : '',
					b.address ? el( 'p', { 'data-testid': 'trip-address' }, el( 'strong', {}, 'Address: ' ), b.address ) : '',
					b.host_name ? el( 'p', { class: 'qm-muted' }, `Host: ${ b.host_name }` ) : '',
					el( 'ul', { class: 'qm-lines' }, b.line_items.map( ( line ) => el( 'li', {}, el( 'span', {}, line.label ), el( 'span', {}, line.amount ) ) ) ),
					el( 'p', { class: 'qm-lines__total' }, el( 'span', {}, 'Total' ), el( 'strong', {}, b.total ) ),
					el( 'p', { class: 'qm-lines__dep' }, el( 'span', {}, b.paid ? 'Deposit paid (test mode)' : 'Deposit' ), el( 'span', {}, b.deposit ) ),
					b.refund ? el( 'p', { class: 'qm-note', 'data-testid': 'trip-refund' }, b.refund ) : '',
					el( 'p', { class: 'qm-muted' }, b.listing.policy ),
					actions
				),
				el(
					'section',
					{ class: 'qm-tripdetail__history' },
					el( 'h3', {}, 'What happened' ),
					el( 'ol', { class: 'qm-timeline' }, b.history.map( ( h ) => el( 'li', {}, el( 'strong', {}, h.to ), h.note ? el( 'span', {}, h.note ) : '', el( 'time', {}, `${ h.at } UTC` ) ) ) )
				)
			)
		);
		if ( b.state === 'hold' && b.hold_expires_at ) {
			qm.countdown( timer, b.hold_expires_at, () => detail( id ) );
		}
	}

	function changeForm( b, actions ) {
		const out = el( 'p', { class: 'qm-book__msg', role: 'alert' } );
		const first = qm.addDays( today, 2 );
		let fields;
		if ( b.type === 'dining' ) {
			const day = el( 'input', { type: 'date', min: first, value: b.slot_start.slice( 0, 10 ), 'data-testid': 'change-date' } );
			const time = el( 'select', { 'data-testid': 'change-time' } );
			const load = async () => {
				time.replaceChildren();
				const data = await api( `listings/${ b.listing.id }/slots?date=${ day.value }&party=${ b.guests }` ).catch( () => ( { slots: [] } ) );
				data.slots.filter( ( s ) => s.fits ).forEach( ( s ) => time.append( el( 'option', { value: s.slot_start }, s.time ) ) );
				if ( ! time.options.length ) {
					time.append( el( 'option', { value: '' }, 'No table that day' ) );
				}
			};
			day.addEventListener( 'change', load );
			load();
			fields = [ el( 'label', { class: 'qm-field' }, el( 'span', {}, 'Day' ), day ), el( 'label', { class: 'qm-field' }, el( 'span', {}, 'Time (UTC)' ), time ) ];
			fields.read = () => ( { slot_start: time.value } );
		} else {
			const inp = el( 'input', { type: 'date', min: first, value: b.check_in, 'data-testid': 'change-check-in' } );
			const outp = el( 'input', { type: 'date', min: first, value: b.check_out, 'data-testid': 'change-check-out' } );
			fields = [ el( 'label', { class: 'qm-field' }, el( 'span', {}, 'Check-in' ), inp ), el( 'label', { class: 'qm-field' }, el( 'span', {}, 'Checkout' ), outp ) ];
			fields.read = () => ( { check_in: inp.value, check_out: outp.value } );
		}
		const form = el(
			'form',
			{ class: 'qm-trip__change' },
			fields,
			el( 'button', { type: 'submit', class: 'qm-btn qm-btn--primary', 'data-testid': 'change-submit' }, 'Save the change' ),
			out
		);
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			try {
				await api( `bookings/${ b.id }/change`, { method: 'POST', token, body: fields.read() } );
				detail( b.id );
			} catch ( error ) {
				out.textContent = error.message;
			}
		} );
		actions.after( form );
	}

	function cancelDialog( b ) {
		const out = el( 'p', { class: 'qm-book__msg', role: 'alert' } );
		const dialog = el(
			'dialog',
			{ class: 'qm-dialog', 'data-testid': 'cancel-dialog' },
			el( 'h2', {}, 'Cancel this booking?' ),
			el( 'p', {}, b.listing.policy ),
			el( 'p', { class: 'qm-muted' }, b.paid ? 'Inside the refund window the deposit is refunded; after it, the deposit is kept.' : 'No deposit was paid, so nothing is refunded.' ),
			out,
			el(
				'div',
				{ class: 'qm-dialog__acts' },
				el( 'button', { type: 'button', class: 'qm-btn qm-btn--ghost', onClick: () => dialog.close() }, 'Keep it' ),
				el(
					'button',
					{
						type: 'button',
						class: 'qm-btn qm-btn--danger',
						'data-testid': 'cancel-confirm',
						onClick: async () => {
							try {
								await api( `bookings/${ b.id }/cancel`, { method: 'POST', token } );
								dialog.close();
								detail( b.id );
							} catch ( error ) {
								out.textContent = error.message;
							}
						},
					},
					'Cancel booking'
				)
			)
		);
		document.body.append( dialog );
		dialog.addEventListener( 'close', () => dialog.remove() );
		dialog.showModal();
	}

	if ( root.dataset.verify ) {
		verify();
	}
	if ( ! body ) {
		return;
	}
	const id = Number( root.dataset.booking );
	if ( id > 0 ) {
		detail( id );
	} else if ( signedIn ) {
		list();
	} else {
		body.replaceChildren();
	}
}() );
