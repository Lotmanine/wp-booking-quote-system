/* Listing page: gallery, area map and the booking card for stays and tables. */
( function () {
	const qm = window.qhs;
	const { el, api } = qm;

	/* Gallery: phone swipe counter and the all-photos dialog. */
	const gallery = document.querySelector( '[data-qm-gallery]' );
	const lightbox = document.querySelector( '[data-qm-lightbox]' );
	if ( gallery && lightbox ) {
		gallery.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-index]' );
			if ( ! button ) {
				return;
			}
			lightbox.showModal();
			lightbox.querySelector( `#qm-photo-${ button.dataset.index }` )?.scrollIntoView();
		} );
		lightbox.querySelector( '[data-close]' ).addEventListener( 'click', () => lightbox.close() );
		const count = gallery.querySelector( '.qm-gallery__count' );
		gallery.addEventListener(
			'scroll',
			() => {
				if ( count ) {
					const total = gallery.querySelectorAll( '.qm-gallery__item' ).length;
					count.textContent = `${ Math.round( gallery.scrollLeft / gallery.clientWidth ) + 1 } / ${ total }`;
				}
			},
			{ passive: true }
		);
	}

	/* Area map: a circle, never the exact point. */
	const mapBox = document.querySelector( '[data-qm-listing-map]' );
	if ( mapBox && window.L ) {
		const centre = [ Number( mapBox.dataset.lat ), Number( mapBox.dataset.lng ) ];
		const draw = () => {
			const map = qm.baseMap( mapBox, { scrollWheelZoom: false } ).setView( centre, 13 );
			window.L.circle( centre, { radius: 650, color: '#11695b', weight: 2, fillColor: '#11695b', fillOpacity: 0.16 } ).addTo( map );
		};
		if ( 'IntersectionObserver' in window ) {
			const seen = new IntersectionObserver( ( entries ) => {
				if ( entries[ 0 ].isIntersecting ) {
					seen.disconnect();
					draw();
				}
			} );
			seen.observe( mapBox );
		} else {
			draw();
		}
	}

	const host = document.querySelector( '[data-qm-book]' );
	if ( ! host ) {
		return;
	}
	const card = JSON.parse( host.dataset.qmBook );
	const stay = card.type === 'stay';
	const mobilePrice = document.querySelector( '[data-qm-mobile-price]' );
	const mobileNote = document.querySelector( '[data-qm-mobile-note]' );

	const state = {
		from: card.start.check_in || '',
		to: card.start.check_out || '',
		day: card.start.date || '',
		slot: '',
		guests: stay ? Math.min( card.maxGuests, Math.max( 1, card.start.guests || 2 ) ) : Math.min( card.maxParty, Math.max( card.minParty, card.start.party || 2 ) ),
		extras: new Set(),
		price: null,
		priceError: '',
		cal: null,
		slots: [],
		picking: false,
	};

	const priceHead = el( 'p', { class: 'qm-book__price' } );
	const pickBox = el( 'div', { class: 'qm-book__pick' } );
	const calBox = el( 'div', { class: 'qm-book__cal', hidden: true } );
	const slotBox = el( 'div', { class: 'qm-book__slots' } );
	const extrasBox = el( 'fieldset', { class: 'qm-book__extras' } );
	const priceBox = el( 'div', { class: 'qm-book__breakdown', 'aria-live': 'polite', 'data-testid': 'price-box' } );
	const actionBox = el( 'div', { class: 'qm-book__action' } );
	const message = el( 'p', { class: 'qm-book__msg', role: 'alert', hidden: true } );

	host.replaceChildren( priceHead, pickBox, calBox, slotBox, extrasBox, priceBox, message, actionBox );

	function say( text ) {
		message.hidden = ! text;
		message.textContent = text || '';
	}

	/* ---------- Header price ---------- */
	function paintHead() {
		if ( state.price && stay ) {
			priceHead.replaceChildren( el( 'strong', {}, state.price.total ), ' ', el( 'span', {}, `for ${ state.price.nights } ${ state.price.nights === 1 ? 'night' : 'nights' }` ) );
			if ( mobilePrice ) {
				mobilePrice.textContent = state.price.total;
				mobileNote.textContent = 'total';
			}
		} else {
			priceHead.replaceChildren( el( 'strong', {}, card.price ), ' ', el( 'span', {}, stay ? 'a night' : 'per guest, set menu' ) );
		}
	}

	/* ---------- Dates, party and times ---------- */
	function dateCell( label, value, testid ) {
		return el(
			'button',
			{ type: 'button', class: 'qm-book__cell', 'data-testid': testid, 'aria-expanded': state.picking ? 'true' : 'false', onClick: () => togglePicker() },
			el( 'span', { class: 'qm-book__k' }, label ),
			el( 'span', { class: 'qm-book__v' }, value ? qm.formatDay( value, { weekday: 'short' } ) : 'Add date' )
		);
	}

	function guestSelect() {
		const min = stay ? 1 : card.minParty;
		const max = stay ? card.maxGuests : card.maxParty;
		const select = el( 'select', { 'data-testid': 'book-guests', id: 'qm-book-guests' } );
		for ( let i = min; i <= max; i++ ) {
			select.append( el( 'option', { value: i, selected: i === state.guests }, `${ i } ${ i === 1 ? 'guest' : 'guests' }` ) );
		}
		select.addEventListener( 'change', () => {
			state.guests = Number( select.value );
			if ( ! stay ) {
				loadSlots();
			}
			refreshPrice();
		} );
		return el( 'label', { class: 'qm-book__cell qm-book__cell--wide', for: 'qm-book-guests' }, el( 'span', { class: 'qm-book__k' }, stay ? 'Guests' : 'Party size' ), select );
	}

	function paintPick() {
		pickBox.replaceChildren(
			el(
				'div',
				{ class: 'qm-book__grid' },
				stay ? [ dateCell( 'Check-in', state.from, 'book-check-in' ), dateCell( 'Checkout', state.to, 'book-check-out' ) ] : dateCell( 'Date', state.day, 'book-date' ),
				guestSelect()
			)
		);
	}

	function togglePicker( force ) {
		state.picking = force === undefined ? ! state.picking : force;
		calBox.hidden = ! state.picking;
		paintPick();
		if ( state.picking ) {
			state.cal?.refresh();
		}
	}

	async function loadCalendar() {
		const from = qm.iso( new Date() );
		const to = qm.addDays( from, stay ? 280 : 70 );
		let data;
		try {
			data = await api( `listings/${ card.id }/calendar?from=${ from }&to=${ to }` );
		} catch ( error ) {
			say( error.message );
			return;
		}
		const off = stay ? data.unavailable || {} : data.closed || {};
		state.cal = qm.calendar( {
			min: data.first,
			max: data.last,
			range: stay,
			months: 1,
			start: stay ? state.from : state.day,
			end: state.to,
			blocked: ( day ) => ( off[ day ] ? 'taken' : '' ),
			onChange: ( { from: a, to: b } ) => {
				say( '' );
				if ( stay ) {
					state.from = a;
					state.to = b;
					if ( a && b ) {
						const nights = qm.nightsBetween( a, b );
						if ( nights < card.minNights ) {
							say( `This place needs at least ${ card.minNights } nights.` );
						} else if ( nights > card.maxNights ) {
							say( `This place takes at most ${ card.maxNights } nights.` );
						}
						togglePicker( false );
					} else {
						paintPick();
					}
					refreshPrice();
				} else {
					state.day = a;
					state.slot = '';
					togglePicker( false );
					loadSlots();
					refreshPrice();
				}
			},
		} );
		calBox.replaceChildren(
			state.cal.node,
			el(
				'div',
				{ class: 'qm-book__calfoot' },
				el( 'span', { class: 'qm-muted' }, stay ? `Stays of ${ card.minNights } to ${ card.maxNights } nights` : 'Greyed days are closed' ),
				el(
					'button',
					{
						type: 'button',
						class: 'qm-link',
						onClick: () => {
							state.from = '';
							state.to = '';
							state.day = '';
							state.slot = '';
							state.cal.clear();
							refreshPrice();
							paintPick();
							slotBox.replaceChildren();
						},
					},
					'Clear dates'
				)
			)
		);
		if ( ! stay && state.day ) {
			loadSlots();
		}
		if ( ( stay && ! state.from ) || ( ! stay && ! state.day ) ) {
			togglePicker( true );
		}
	}

	async function loadSlots() {
		if ( stay ) {
			return;
		}
		if ( ! state.day ) {
			slotBox.replaceChildren();
			return;
		}
		slotBox.replaceChildren( el( 'p', { class: 'qm-muted' }, 'Finding tables...' ) );
		try {
			const data = await api( `listings/${ card.id }/slots?date=${ state.day }&party=${ state.guests }` );
			state.slots = data.slots;
		} catch ( error ) {
			slotBox.replaceChildren( el( 'p', { class: 'qm-book__msg' }, error.message ) );
			return;
		}
		const open = state.slots.filter( ( s ) => s.fits );
		if ( ! open.length ) {
			slotBox.replaceChildren( el( 'p', { class: 'qm-muted', 'data-testid': 'no-slots' }, 'No table for this party on that day. Try another day or a smaller party.' ) );
			state.slot = '';
			refreshPrice();
			return;
		}
		if ( ! open.some( ( s ) => s.slot_start === state.slot ) ) {
			state.slot = '';
		}
		const groups = {};
		open.forEach( ( s ) => ( groups[ s.period ] = groups[ s.period ] || [] ).push( s ) );
		slotBox.replaceChildren(
			el( 'p', { class: 'qm-book__k' }, 'Time (UTC)' ),
			...Object.entries( groups ).map( ( [ period, slots ] ) =>
				el(
					'div',
					{ class: 'qm-book__period' },
					el( 'span', { class: 'qm-muted' }, period ),
					el(
						'div',
						{ class: 'qm-chips' },
						slots.map( ( s ) =>
							el(
								'button',
								{
									type: 'button',
									class: 'qm-chip' + ( s.slot_start === state.slot ? ' is-on' : '' ),
									'aria-pressed': s.slot_start === state.slot ? 'true' : 'false',
									'data-testid': 'slot',
									'data-slot': s.slot_start,
									title: `${ s.seats_left } seats left`,
									onClick: () => {
										state.slot = s.slot_start;
										loadSlots();
										refreshPrice();
									},
								},
								s.time
							)
						)
					)
				)
			)
		);
	}

	/* ---------- Extras ---------- */
	function paintExtras() {
		if ( ! card.extras.length ) {
			extrasBox.hidden = true;
			return;
		}
		extrasBox.replaceChildren(
			el( 'legend', { class: 'qm-book__k' }, 'Extras' ),
			...card.extras.map( ( x ) => {
				const box = el( 'input', { type: 'checkbox', value: x.key, 'data-testid': `extra-${ x.key }` } );
				box.addEventListener( 'change', () => {
					if ( box.checked ) {
						state.extras.add( x.key );
					} else {
						state.extras.delete( x.key );
					}
					refreshPrice();
				} );
				return el( 'label', { class: 'qm-check' }, box, el( 'span', {}, x.label ), el( 'span', { class: 'qm-muted' }, x.price ) );
			} )
		);
	}

	/* ---------- Server price ---------- */
	function ready() {
		return stay ? state.from && state.to : state.day && state.slot;
	}

	function request() {
		return {
			check_in: stay ? state.from : '',
			check_out: stay ? state.to : '',
			slot_start: stay ? '' : state.slot,
			guests: state.guests,
			extras: [ ...state.extras ],
		};
	}

	let priceRun = 0;
	async function refreshPrice() {
		state.price = null;
		state.priceError = '';
		const run = ++priceRun;
		if ( ! ready() ) {
			paintPrice();
			return;
		}
		try {
			const price = await api( `listings/${ card.id }/price`, { method: 'POST', body: request() } );
			if ( run === priceRun ) {
				state.price = price;
			}
		} catch ( error ) {
			if ( run === priceRun ) {
				state.priceError = error.message;
			}
		}
		paintPrice();
	}

	function paintPrice() {
		paintHead();
		if ( state.priceError ) {
			priceBox.replaceChildren( el( 'p', { class: 'qm-book__msg' }, state.priceError ) );
		} else if ( ! state.price ) {
			priceBox.replaceChildren();
		} else {
			priceBox.replaceChildren(
				el(
					'ul',
					{ class: 'qm-lines' },
					state.price.lines.map( ( line ) => el( 'li', {}, el( 'span', {}, line.label ), el( 'span', {}, line.amount ) ) )
				),
				el( 'p', { class: 'qm-lines__total' }, el( 'span', {}, 'Total' ), el( 'strong', { 'data-testid': 'price-total' }, state.price.total ) ),
				el( 'p', { class: 'qm-lines__dep' }, el( 'span', {}, 'Deposit due now' ), el( 'span', { 'data-testid': 'price-deposit' }, state.price.deposit ) ),
				el( 'p', { class: 'qm-muted qm-lines__note' }, `The ${ state.price.balance } balance is settled with the host, never on this demo.` )
			);
		}
		paintAction();
	}

	/* ---------- Reserve ---------- */
	function paintAction() {
		if ( card.ready.blocked ) {
			actionBox.replaceChildren( el( 'p', { class: 'qm-book__msg' }, 'Booking is off: a live payment key was refused.' ) );
			return;
		}
		const button = el(
			'button',
			{ type: 'button', class: 'qm-btn qm-btn--primary qm-btn--block', 'data-testid': 'reserve', disabled: ! state.price, onClick: details },
			card.instant ? 'Reserve' : 'Request to book'
		);
		actionBox.replaceChildren(
			button,
			el( 'p', { class: 'qm-muted qm-book__under' }, state.price ? `Your ${ stay ? 'dates are' : 'table is' } held for ${ card.holdMinute } minutes while you pay.` : stay ? 'Choose your dates to see the total.' : 'Choose a day and a time to see the total.' )
		);
	}

	let tokenOf = () => '';
	function details() {
		say( '' );
		if ( ! card.signedIn && ! card.ready.guests ) {
			actionBox.replaceChildren(
				el( 'div', { class: 'qm-note', 'data-testid': 'signin-needed' }, el( 'p', {}, 'Sign in to reserve. The security check for guests is not set up yet on this demo, so only signed-in accounts can hold dates.' ), el( 'a', { class: 'qm-btn qm-btn--primary qm-btn--block', href: card.login }, 'Sign in' ), el( 'p', { class: 'qm-muted' }, 'Demo logins are on the Demo logins page.' ) )
			);
			return;
		}
		const fields = [];
		if ( ! card.signedIn ) {
			fields.push(
				el( 'label', { class: 'qm-field' }, el( 'span', {}, 'Full name' ), el( 'input', { name: 'full_name', autocomplete: 'name', required: true, maxlength: 120, 'data-testid': 'field-full_name' } ) ),
				el( 'label', { class: 'qm-field' }, el( 'span', {}, 'Email' ), el( 'input', { name: 'email', type: 'email', autocomplete: 'email', required: true, 'data-testid': 'field-email' } ) )
			);
		}
		fields.push(
			el( 'label', { class: 'qm-field' }, el( 'span', {}, stay ? 'Message for the host (optional)' : 'Allergies or a note (optional)' ), el( 'textarea', { name: 'note', rows: 3, maxlength: 500, 'data-testid': 'field-note' } ) ),
			el( 'label', { class: 'qm-check' }, el( 'input', { type: 'checkbox', name: 'marketing_consent' } ), el( 'span', {}, 'Send me occasional demo news. Optional, and only after I confirm my email.' ) )
		);
		const form = el(
			'form',
			{ class: 'qm-book__form', novalidate: true },
			fields,
			el( 'button', { type: 'submit', class: 'qm-btn qm-btn--primary qm-btn--block', 'data-testid': 'hold-submit' }, `Hold for ${ card.holdMinute } minutes` ),
			el( 'button', { type: 'button', class: 'qm-link', onClick: paintAction }, 'Back' )
		);
		actionBox.replaceChildren( form );
		tokenOf = card.signedIn ? () => '' : qm.turnstile( form );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			hold( form );
		} );
	}

	async function hold( form ) {
		const submit = form.querySelector( '[type="submit"]' );
		submit.disabled = true;
		say( '' );
		const data = new FormData( form );
		try {
			const quote = await api( 'quote', {
				method: 'POST',
				body: {
					listing_id: card.id,
					...request(),
					full_name: data.get( 'full_name' ) || '',
					email: data.get( 'email' ) || '',
					note: data.get( 'note' ) || '',
					marketing_consent: data.get( 'marketing_consent' ) === 'on',
					turnstile_token: tokenOf(),
				},
			} );
			const held = await api( 'hold', { method: 'POST', token: quote.token, body: { booking_id: quote.booking.id, turnstile_token: tokenOf() } } );
			showHeld( held, quote.token );
		} catch ( error ) {
			say( error.message );
			submit.disabled = false;
			if ( error.code === 'unavailable' ) {
				loadCalendar();
				loadSlots();
			}
		}
	}

	function showHeld( held, token ) {
		const b = held.booking;
		const timer = el( 'strong', { 'data-testid': 'hold-countdown' } );
		const trips = `${ card.trips }?booking=${ b.id }&token=${ encodeURIComponent( token ) }`;
		const pay = held.pay_url
			? el( 'a', { class: 'qm-btn qm-btn--primary qm-btn--block', href: held.pay_url, 'data-testid': 'pay-link' }, `Pay the ${ b.deposit } deposit` )
			: el(
				'div',
				{ class: 'qm-note qm-note--warn', 'data-testid': 'payments-not-set-up' },
				el( 'strong', {}, 'Payments are not set up yet on this demo' ),
				el( 'p', {}, `No Stripe test keys are configured, so the ${ b.deposit } deposit cannot be taken. Your hold shows the real availability and ends on its own; nothing is reserved.` )
			);
		host.replaceChildren(
			el(
				'div',
				{ class: 'qm-held', 'data-testid': 'held' },
				el( 'p', { class: 'qm-held__head' }, 'Held for you ', timer ),
				el( 'p', { class: 'qm-held__what' }, el( 'strong', {}, b.listing.title ), el( 'span', {}, b.when + ( stay ? '' : ' UTC' ) ) ),
				el( 'ul', { class: 'qm-lines' }, b.line_items.map( ( line ) => el( 'li', {}, el( 'span', {}, line.label ), el( 'span', {}, line.amount ) ) ) ),
				el( 'p', { class: 'qm-lines__total' }, el( 'span', {}, 'Total' ), el( 'strong', { 'data-testid': 'quote-total' }, b.total ) ),
				el( 'p', { class: 'qm-lines__dep' }, el( 'span', {}, 'Deposit due now' ), el( 'span', { 'data-testid': 'quote-deposit' }, b.deposit ) ),
				pay,
				el( 'p', { class: 'qm-muted' }, b.listing.policy ),
				el( 'a', { class: 'qm-link', href: trips, 'data-testid': 'trip-link' }, 'See it in your trips' )
			)
		);
		qm.countdown( timer, b.hold_expires_at, () => {
			timer.textContent = 'expired';
		} );
		document.querySelector( '[data-qm-mobilebar]' )?.setAttribute( 'hidden', '' );
		host.scrollIntoView( { block: 'start', behavior: 'smooth' } );
	}

	paintHead();
	paintPick();
	paintExtras();
	paintAction();
	loadCalendar();
	refreshPrice();
}() );
