/*
 * Service page: the reservation card (size, district, calendar, time, extras, live price),
 * then your details and the security check, then the held slot with a countdown and the pay link.
 */
( function () {
	const root = document.querySelector( '[data-qhs-reserve]' );
	const { api, el, icon, money, moneyShort, estimate, find, catalog, config, calendar, renderTurnstile, formatSlot, formatTime, formatDay, dialog, slotDate } = window.qhs;

	galleryDialogs();
	if ( ! root ) {
		return;
	}

	const card = root.querySelector( '.qhs-reserve__card' );
	const signedIn = root.dataset.signedIn === '1';
	const service = find( 'services', root.dataset.service );
	const ready = config.ready || { open: true, reasons: [] };
	const state = {
		step: 'pick',
		size: root.dataset.size || 'small',
		area: root.dataset.area || '',
		date: root.dataset.date || '',
		slot: '',
		extras: [],
		days: null,
		loading: false,
		error: '',
		booking: null,
		token: '',
		quoteKey: '',
		payUrl: '',
		form: { full_name: '', email: '', note: '', consent: false },
	};
	let check = null;
	let timer = null;

	const quoteKey = () => [ state.size, state.area, [ ...state.extras ].sort().join( '+' ) ].join( '|' );

	function setError( message ) {
		state.error = message;
		paint();
		const alert = card.querySelector( '[data-testid="reserve-error"]' );
		if ( alert ) {
			alert.focus();
		}
	}

	async function loadDays( focusSelector ) {
		state.days = null;
		state.loading = true;
		paint( focusSelector );
		try {
			const result = await api( `availability?area=${ encodeURIComponent( state.area ) }` );
			state.days = result.days || {};
			if ( state.date && ! ( state.days[ state.date ] || [] ).length ) {
				state.date = '';
			}
			if ( state.slot && ! ( state.days[ state.slot.slice( 0, 10 ) ] || [] ).includes( state.slot ) ) {
				state.slot = '';
			}
		} catch ( failure ) {
			state.error = failure.message;
		}
		state.loading = false;
		paint( focusSelector );
	}

	function segmented( label, group, current, onPick, describe ) {
		return el(
			'fieldset',
			{ class: 'qhs-field' },
			el( 'legend', { class: 'qhs-field__label' }, label ),
			el(
				'div',
				{ class: `qhs-seg qhs-seg--${ group }` },
				catalog[ group ].map( ( item ) =>
					el(
						'button',
						{
							type: 'button',
							class: 'qhs-seg__opt' + ( item.key === current ? ' is-selected' : '' ),
							'aria-pressed': item.key === current ? 'true' : 'false',
							'data-testid': `reserve-${ group === 'sizes' ? 'size' : 'area' }-${ item.key }`,
							onClick: () => onPick( item.key ),
						},
						el( 'strong', {}, item.label.replace( / \(.*\)$/, '' ) ),
						el( 'span', {}, describe( item ) )
					)
				)
			)
		);
	}

	function breakdown( lines, total, deposit, server ) {
		return el(
			'div',
			{ class: 'qhs-breakdown', 'data-testid': server ? 'quote-summary' : 'estimate' },
			el(
				'table',
				{},
				el(
					'tbody',
					{},
					lines.map( ( line ) => el( 'tr', {}, el( 'td', {}, line.label ), el( 'td', { class: 'r' }, money( line.cents ) ) ) ),
					el( 'tr', { class: 't' }, el( 'td', {}, 'Total (fictional)' ), el( 'td', { class: 'r', 'data-testid': server ? 'quote-total' : 'estimate-total' }, total ) ),
					el( 'tr', { class: 'd' }, el( 'td', {}, 'Deposit due today' ), el( 'td', { class: 'r', 'data-testid': server ? 'quote-deposit' : 'estimate-deposit' }, deposit ) )
				)
			)
		);
	}

	function header( priceCents, suffix ) {
		return el(
			'div',
			{ class: 'qhs-reserve__top' },
			el( 'p', { class: 'qhs-reserve__price' }, el( 'strong', {}, money( priceCents ) ), ' ', el( 'span', {}, suffix ) ),
			el( 'p', { class: 'qhs-reserve__hours' }, icon( 'clock', 16 ), service.hours )
		);
	}

	function errorBox() {
		return state.error ? el( 'p', { class: 'qhs-alert', role: 'alert', tabindex: '-1', 'data-testid': 'reserve-error' }, icon( 'alert', 18 ), el( 'span', {}, state.error ) ) : null;
	}

	function pausedBox() {
		if ( ready.open ) {
			return null;
		}
		return el(
			'div',
			{ class: 'qhs-paused', role: 'status', 'data-testid': 'booking-paused' },
			el( 'strong', {}, 'Online booking is paused on this demo' ),
			el( 'ul', {}, ready.reasons.map( ( reason ) => el( 'li', {}, reason ) ) ),
			el( 'span', {}, 'You can still explore prices, dates and times.' )
		);
	}

	function stepPick() {
		const est = estimate( service.key, state.size, state.area, state.extras );
		updateMobile( state.area ? est.total : est.lines[ 0 ].cents, state.area ? 'total, fictional' : 'from, fictional' );
		const slots = state.days && state.date ? state.days[ state.date ] || [] : [];
		const bounds = window.qhs.windowBounds();

		const nodes = [
			header( state.area ? est.total : est.lines[ 0 ].cents, state.area ? 'total, fictional' : 'from, fictional' ),
			pausedBox(),
			errorBox(),
			segmented( 'Home size', 'sizes', state.size, ( key ) => {
				state.size = key;
				paint( `[data-testid="reserve-size-${ key }"]` );
			}, ( item ) => ( item.multiplier === 100 ? 'Base price' : `x${ ( item.multiplier / 100 ).toFixed( 1 ) }` ) ),
			segmented( 'District', 'areas', state.area, ( key ) => {
				state.area = key;
				state.error = '';
				loadDays( `[data-testid="reserve-area-${ key }"]` );
			}, ( item ) => ( item.cents > 0 ? `+${ moneyShort( item.cents ) } travel` : 'No travel fee' ) ),
		];

		if ( ! state.area ) {
			nodes.push( el( 'p', { class: 'qhs-hint', 'data-testid': 'pick-area-hint' }, icon( 'pin', 16 ), 'Choose a district to see open days.' ) );
		} else if ( state.loading || ! state.days ) {
			nodes.push( el( 'div', { class: 'qhs-skeleton', 'aria-busy': 'true' }, 'Loading open days' ) );
		} else {
			const openDays = Object.keys( state.days ).filter( ( iso ) => state.days[ iso ].length );
			nodes.push(
				el(
					'div',
					{ class: 'qhs-field' },
					el( 'p', { class: 'qhs-field__label', id: 'qhs-date-label' }, 'Date' ),
					openDays.length
						? calendar( {
							min: bounds.min,
							max: bounds.max,
							isOpen: ( iso ) => ( state.days[ iso ] || [] ).length > 0,
							selected: state.date,
							label: 'Visit date',
							onSelect: ( iso ) => {
								state.date = iso;
								if ( state.slot.slice( 0, 10 ) !== iso ) {
									state.slot = '';
								}
								paint( `[data-date="${ iso }"]` );
							},
						} )
						: el( 'p', { class: 'qhs-hint' }, 'No open days in the next two weeks for this district.' )
				)
			);
			if ( state.date ) {
				nodes.push(
					el(
						'div',
						{ class: 'qhs-field qhs-times' },
						el( 'p', { class: 'qhs-field__label' }, `Start time, ${ formatDay( state.date, true ) }` ),
						el(
							'div',
							{ class: 'qhs-chips-time', role: 'group', 'aria-label': 'Start times in UTC' },
							slots.map( ( slot ) =>
								el(
									'button',
									{
										type: 'button',
										class: 'qhs-time' + ( slot === state.slot ? ' is-selected' : '' ),
										'aria-pressed': slot === state.slot ? 'true' : 'false',
										'data-testid': 'slot',
										'data-slot': slot,
										onClick: () => {
											state.slot = slot;
											paint( `[data-slot="${ slot }"]` );
										},
									},
									formatTime( slot )
								)
							)
						),
						el( 'p', { class: 'qhs-fine' }, 'Times are in UTC.' )
					)
				);
			}
		}

		nodes.push(
			el(
				'fieldset',
				{ class: 'qhs-field' },
				el( 'legend', { class: 'qhs-field__label' }, 'Extras' ),
				el(
					'div',
					{ class: 'qhs-checks' },
					catalog.extras.map( ( item ) =>
						el(
							'label',
							{ class: 'qhs-check' },
							el( 'input', {
								type: 'checkbox',
								value: item.key,
								checked: state.extras.includes( item.key ),
								'data-testid': `extra-${ item.key }`,
								onChange: ( event ) => {
									state.extras = event.target.checked ? [ ...state.extras, item.key ] : state.extras.filter( ( key ) => key !== item.key );
									paint( `[data-testid="extra-${ item.key }"]` );
								},
							} ),
							el( 'span', {}, item.label ),
							el( 'span', { class: 'qhs-mono' }, `+${ money( item.cents ) }` )
						)
					)
				)
			)
		);

		if ( state.area ) {
			nodes.push( breakdown( est.lines, money( est.total ), money( est.deposit ), false ) );
		}

		const canGo = ready.open && state.area && state.slot;
		nodes.push(
			el(
				'button',
				{
					type: 'button',
					class: 'qhs-btn qhs-btn--primary qhs-btn--block',
					'data-testid': 'reserve-continue',
					disabled: ! canGo,
					onClick: () => {
						state.step = 'details';
						state.error = '';
						paint( 'h3' );
					},
				},
				state.slot ? `Reserve ${ formatTime( state.slot ) }` : 'Reserve'
			),
			el( 'p', { class: 'qhs-fine qhs-center' }, 'Nothing is charged here. The deposit is paid at checkout, in test mode.' )
		);
		return nodes;
	}

	function stepDetails() {
		const est = estimate( service.key, state.size, state.area, state.extras );
		const turnstileHost = el( 'div', { class: 'qhs-turnstile' } );
		check = renderTurnstile( turnstileHost );

		const input = ( label, name, type, autocomplete ) =>
			el(
				'p',
				{ class: 'qhs-field' },
				el( 'label', { class: 'qhs-field__label', for: `qhs-${ name }` }, label ),
				el( 'input', {
					class: 'qhs-input',
					id: `qhs-${ name }`,
					name,
					type,
					required: true,
					autocomplete,
					value: state.form[ name ],
					'data-testid': `field-${ name }`,
					onInput: ( event ) => {
						state.form[ name ] = event.target.value;
					},
				} )
			);

		const form = el(
			'form',
			{ class: 'qhs-details', novalidate: true },
			el( 'h3', { tabindex: '-1' }, 'Confirm and hold' ),
			el(
				'div',
				{ class: 'qhs-trip' },
				el( 'img', { src: service.image, alt: '', width: '96', height: '60' } ),
				el(
					'div',
					{},
					el( 'strong', {}, service.label ),
					el( 'span', {}, formatSlot( state.slot ) ),
					el( 'span', {}, `${ find( 'areas', state.area ).label }, ${ find( 'sizes', state.size ).label }` )
				),
				el( 'button', { type: 'button', class: 'qhs-link', 'data-testid': 'details-back', onClick: () => back() }, 'Edit' )
			),
			errorBox(),
			signedIn
				? el( 'p', { class: 'qhs-hint' }, icon( 'user', 16 ), 'You are signed in, so this booking goes to your account.' )
				: [
					input( 'Full name', 'full_name', 'text', 'name' ),
					input( 'Email', 'email', 'email', 'email' ),
					el( 'p', { class: 'qhs-fine' }, 'Have an account? ', el( 'a', { href: root.dataset.login }, 'Sign in' ), ' first to keep this booking with it.' ),
				],
			el(
				'p',
				{ class: 'qhs-field' },
				el( 'label', { class: 'qhs-field__label', for: 'qhs-note' }, 'Anything the team should know? (optional)' ),
				el( 'textarea', {
					class: 'qhs-input',
					id: 'qhs-note',
					name: 'note',
					maxlength: '500',
					rows: '3',
					'data-testid': 'field-note',
					onInput: ( event ) => {
						state.form.note = event.target.value;
					},
				}, state.form.note )
			),
			el(
				'label',
				{ class: 'qhs-check qhs-check--top' },
				el( 'input', {
					type: 'checkbox',
					name: 'marketing_consent',
					checked: state.form.consent,
					'data-testid': 'field-consent',
					onChange: ( event ) => {
						state.form.consent = event.target.checked;
					},
				} ),
				el( 'span', {}, 'Send me occasional news from Quillmoor. Optional. We ask you to confirm by email.' )
			),
			breakdown( est.lines, money( est.total ), money( est.deposit ), false ),
			turnstileHost,
			el( 'button', { type: 'submit', class: 'qhs-btn qhs-btn--primary qhs-btn--block', 'data-testid': 'hold-submit' }, `Hold this slot for ${ config.holdMinutes } minutes` ),
			el( 'p', { class: 'qhs-fine qhs-center' }, 'The slot is yours while you pay. Nothing is charged yet.' )
		);

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const button = form.querySelector( '[data-testid="hold-submit"]' );
			button.disabled = true;
			button.classList.add( 'is-loading' );
			try {
				if ( ! state.booking || state.quoteKey !== quoteKey() ) {
					const result = await api( 'quote', {
						method: 'POST',
						body: {
							service: service.key,
							size: state.size,
							area: state.area,
							extras: state.extras,
							full_name: state.form.full_name,
							email: state.form.email,
							note: state.form.note,
							marketing_consent: state.form.consent,
							turnstile_token: await check.fresh(),
						},
					} );
					state.booking = result.booking;
					state.token = result.token;
					state.quoteKey = quoteKey();
				}
				const held = await api( 'hold', {
					method: 'POST',
					token: state.token,
					body: { booking_id: state.booking.id, slot_start: state.slot, token: state.token, turnstile_token: await check.fresh() },
				} );
				state.booking = held.booking;
				state.payUrl = held.pay_url;
				state.step = 'held';
				state.error = '';
				paint( 'h3' );
			} catch ( failure ) {
				if ( failure.code === 'slot_taken' ) {
					state.step = 'pick';
					state.slot = '';
					await loadDays();
				}
				setError( failure.message );
			}
		} );
		return [ form ];
	}

	function stepHeld() {
		const booking = state.booking;
		const expires = slotDate( booking.hold_expires_at ).getTime();
		const clock = el( 'strong', { 'data-testid': 'hold-countdown', role: 'timer', 'aria-live': 'off' }, '' );
		const tick = () => {
			const left = Math.max( 0, Math.floor( ( expires - Date.now() ) / 1000 ) );
			clock.textContent = `${ Math.floor( left / 60 ) }:${ String( left % 60 ).padStart( 2, '0' ) }`;
			if ( left > 0 ) {
				timer = setTimeout( tick, 1000 );
			} else {
				state.error = 'The hold expired, so the slot was released. Pick a time again.';
				state.step = 'pick';
				state.booking = null;
				state.slot = '';
				loadDays();
			}
		};
		clearTimeout( timer );
		tick();

		return [
			el(
				'div',
				{ class: 'qhs-held' },
				el( 'span', { class: 'qhs-held__icon' }, icon( 'check', 22 ) ),
				el( 'h3', { tabindex: '-1' }, 'Your slot is on hold' ),
				el( 'p', { 'data-testid': 'held-slot' }, formatSlot( booking.slot_start ) ),
				el( 'p', { class: 'qhs-held__clock' }, 'Held for ', clock, '. Pay the deposit to confirm it.' )
			),
			breakdown( booking.line_items, booking.total, booking.deposit, true ),
			el( 'a', { class: 'qhs-btn qhs-btn--primary qhs-btn--block', href: state.payUrl, 'data-testid': 'pay-link' }, `Pay ${ booking.deposit } test deposit` ),
			el(
				'p',
				{ class: 'qhs-held__links' },
				el( 'a', { href: `${ config.rest }bookings/${ booking.id }/pdf/quote?token=${ encodeURIComponent( state.token ) }`, 'data-testid': 'quote-pdf' }, icon( 'file', 16 ), 'Download the quote (PDF)' )
			),
			el( 'p', { class: 'qhs-fine qhs-center' }, 'Test mode only. Never enter a real card number.' ),
		];
	}

	function back() {
		state.step = 'pick';
		state.error = '';
		paint( '[data-testid="reserve-continue"]' );
	}

	function paint( focusSelector ) {
		const steps = { pick: stepPick, details: stepDetails, held: stepHeld };
		card.replaceChildren( ...steps[ state.step ]().filter( Boolean ) );
		card.dataset.step = state.step;
		if ( focusSelector ) {
			const target = card.querySelector( focusSelector );
			if ( target ) {
				target.focus( { preventScroll: true } );
			}
		}
	}

	/* Phone layout: a bar with the price and a jump to the card, hidden once the card is on screen. */
	const bar = document.querySelector( '[data-qhs-mobilebar]' );
	const barPrice = document.querySelector( '[data-qhs-mobile-price]' );
	const barNote = document.querySelector( '[data-qhs-mobile-note]' );
	function updateMobile( cents, note ) {
		if ( barPrice ) {
			barPrice.textContent = money( cents );
			barNote.textContent = note;
		}
	}
	if ( bar && 'IntersectionObserver' in window ) {
		new IntersectionObserver( ( entries ) => {
			bar.classList.toggle( 'is-hidden', entries[ 0 ].isIntersecting );
		} ).observe( root );
	}

	if ( ! catalog.sizes.some( ( item ) => item.key === state.size ) ) {
		state.size = 'small';
	}
	if ( state.area ) {
		loadDays();
	} else {
		paint();
	}

	/* Gallery: each image opens a larger view with previous and next. */
	function galleryDialogs() {
		const gallery = document.querySelector( '[data-qhs-gallery]' );
		if ( ! gallery ) {
			return;
		}
		const images = Array.from( gallery.querySelectorAll( 'img' ) ).map( ( img ) => img.getAttribute( 'src' ) );
		const captions = [ 'The service, illustrated', 'A closer look', 'Districts the teams cover' ];
		gallery.querySelectorAll( 'button[data-index]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				let index = Number( button.dataset.index );
				const figure = el( 'figure', { class: 'qhs-lightbox' } );
				const show = () => {
					figure.replaceChildren(
						el( 'img', { src: images[ index ], alt: captions[ index ], class: index === 1 ? 'is-zoom' : '' } ),
						el( 'figcaption', {}, `${ index + 1 } of ${ images.length }: ${ captions[ index ] }` )
					);
				};
				show();
				dialog( {
					title: 'Gallery',
					testid: 'gallery-dialog',
					body: figure,
					actions: [
						{ label: 'Previous', onClick: () => { index = ( index + images.length - 1 ) % images.length; show(); } },
						{ label: 'Next', primary: true, onClick: () => { index = ( index + 1 ) % images.length; show(); } },
					],
				} );
			} );
		} );
	}
}() );
