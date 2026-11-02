( function () {
	const root = document.getElementById( 'qhs-wizard' );
	if ( ! root ) {
		return;
	}
	const { api, el, formatSlot, renderTurnstile } = window.qhs;
	const signedIn = root.dataset.signedIn === '1';
	const money = ( cents ) => '$' + ( cents / 100 ).toFixed( 2 );

	const state = {
		step: 0,
		catalog: null,
		service: '',
		size: '',
		area: '',
		extras: [],
		booking: null,
		token: '',
		slot: '',
		payUrl: '',
		error: '',
	};

	const LABELS = [ 'Service', 'Size', 'Area', 'Extras', 'Your details', 'Choose a slot', 'Pay deposit' ];

	function focusHeading() {
		const heading = root.querySelector( 'h2' );
		if ( heading ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
		}
	}

	function render() {
		const nodes = [
			el(
				'ol',
				{ class: 'qhs-steps', 'aria-label': 'Progress' },
				LABELS.map( ( label, index ) =>
					el( 'li', { class: index === state.step ? 'is-current' : '', 'aria-current': index === state.step ? 'step' : null }, label )
				)
			),
			state.error ? el( 'p', { class: 'qhs-error', role: 'alert', 'data-testid': 'wizard-error' }, state.error ) : null,
			[ stepChoice( 'services', 'What do you need?', 'service' ), stepChoice( 'sizes', 'How big is the job?', 'size' ), stepChoice( 'areas', 'Where are you?', 'area' ), stepExtras, stepContact, stepSlots, stepPay ][ state.step ](),
		];
		root.replaceChildren( ...nodes.filter( Boolean ) );
		focusHeading();
	}

	function stepChoice( group, title, field ) {
		return () =>
			el(
				'section',
				{},
				el( 'h2', {}, title ),
				el(
					'div',
					{ class: 'qhs-options' },
					state.catalog[ group ].map( ( item ) =>
						el(
							'button',
							{
								type: 'button',
								class: 'qhs-option',
								'data-testid': `${ field }-${ item.key }`,
								onClick: () => {
									state[ field ] = item.key;
									state.step += 1;
									state.error = '';
									render();
								},
							},
							el( 'span', { class: 'qhs-option__label' }, item.label ),
							group === 'services' ? el( 'span', { class: 'qhs-option__price' }, `from ${ money( item.cents ) } (fictional)` ) : null,
							group === 'areas' && item.cents > 0 ? el( 'span', { class: 'qhs-option__price' }, `+${ money( item.cents ) } travel (fictional)` ) : null
						)
					)
				),
				backButton()
			);
	}

	function backButton() {
		if ( state.step === 0 || state.step > 4 ) {
			return null;
		}
		return el(
			'button',
			{
				type: 'button',
				class: 'qhs-link',
				onClick: () => {
					state.step -= 1;
					render();
				},
			},
			'Back'
		);
	}

	function stepExtras() {
		return el(
			'section',
			{},
			el( 'h2', {}, 'Any extras?' ),
			el(
				'div',
				{ class: 'qhs-checks' },
				state.catalog.extras.map( ( item ) =>
					el(
						'label',
						{},
						el( 'input', {
							type: 'checkbox',
							value: item.key,
							checked: state.extras.includes( item.key ),
							'data-testid': `extra-${ item.key }`,
							onChange: ( event ) => {
								state.extras = event.target.checked
									? [ ...state.extras, item.key ]
									: state.extras.filter( ( key ) => key !== item.key );
							},
						} ),
						` ${ item.label } (+${ money( item.cents ) }, fictional)`
					)
				)
			),
			el(
				'button',
				{
					type: 'button',
					class: 'qhs-primary',
					'data-testid': 'extras-next',
					onClick: () => {
						state.step += 1;
						render();
					},
				},
				'Continue'
			),
			backButton()
		);
	}

	function stepContact() {
		const turnstileHost = el( 'div', { class: 'qhs-turnstile' } );
		let check = { token: '' };
		renderTurnstile( turnstileHost ).then( ( widget ) => {
			check = widget;
		} );

		const field = ( label, name, type, required ) =>
			el(
				'p',
				{},
				el( 'label', { for: `qhs-${ name }` }, label ),
				el( 'input', { id: `qhs-${ name }`, name, type, required, autocomplete: type === 'email' ? 'email' : 'name', 'data-testid': `field-${ name }` } )
			);

		const form = el(
			'form',
			{},
			el( 'h2', {}, 'Your details' ),
			signedIn ? el( 'p', {}, 'You are signed in, so this quote goes to your account.' ) : [ field( 'Full name', 'full_name', 'text', true ), field( 'Email', 'email', 'email', true ) ],
			el(
				'p',
				{},
				el( 'label', { for: 'qhs-note' }, 'Anything we should know? (optional)' ),
				el( 'textarea', { id: 'qhs-note', name: 'note', maxlength: '500', rows: '3', 'data-testid': 'field-note' } )
			),
			el(
				'p',
				{ class: 'qhs-checks' },
				el(
					'label',
					{},
					el( 'input', { type: 'checkbox', name: 'marketing_consent', 'data-testid': 'field-consent' } ),
					' Send me occasional news from Quillmoor. Optional. We ask you to confirm by email.'
				)
			),
			turnstileHost,
			el( 'button', { type: 'submit', class: 'qhs-primary', 'data-testid': 'quote-submit' }, 'See my price' ),
			backButton()
		);

		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const data = new FormData( form );
			state.error = '';
			try {
				const result = await api( 'quote', {
					method: 'POST',
					body: {
						service: state.service,
						size: state.size,
						area: state.area,
						extras: state.extras,
						full_name: data.get( 'full_name' ) || '',
						email: data.get( 'email' ) || '',
						note: data.get( 'note' ) || '',
						marketing_consent: data.get( 'marketing_consent' ) === 'on',
						turnstile_token: check.token,
					},
				} );
				state.booking = result.booking;
				state.token = result.token;
				state.step = 5;
				await loadSlots();
				render();
			} catch ( error ) {
				state.error = error.message;
				render();
			}
		} );

		return form;
	}

	async function loadSlots() {
		const result = await api( `availability?area=${ encodeURIComponent( state.area ) }` );
		state.days = result.days;
	}

	function summary() {
		return el(
			'div',
			{ class: 'qhs-summary', 'data-testid': 'quote-summary' },
			el(
				'table',
				{},
				el(
					'tbody',
					{},
					state.booking.line_items.map( ( line ) => el( 'tr', {}, el( 'td', {}, line.label ), el( 'td', { class: 'r' }, money( line.cents ) ) ) ),
					el( 'tr', { class: 't' }, el( 'td', {}, 'Total (fictional)' ), el( 'td', { class: 'r', 'data-testid': 'quote-total' }, state.booking.total ) ),
					el( 'tr', {}, el( 'td', {}, 'Deposit to hold the booking' ), el( 'td', { class: 'r', 'data-testid': 'quote-deposit' }, state.booking.deposit ) )
				)
			),
			el( 'p', {}, el( 'a', { href: `${ window.qhs.config.rest }bookings/${ state.booking.id }/pdf/quote?token=${ state.token }`, 'data-testid': 'quote-pdf' }, 'Download this quote (PDF)' ) )
		);
	}

	function stepSlots() {
		const turnstileHost = el( 'div', { class: 'qhs-turnstile' } );
		let check = { token: '' };
		renderTurnstile( turnstileHost ).then( ( widget ) => {
			check = widget;
		} );

		const hold = async () => {
			if ( ! state.slot ) {
				state.error = 'Please choose a slot first.';
				render();
				return;
			}
			try {
				const result = await api( 'hold', {
					method: 'POST',
					token: state.token,
					body: {
						booking_id: state.booking.id,
						slot_start: state.slot,
						token: state.token,
						turnstile_token: check.token,
					},
				} );
				state.booking = result.booking;
				state.payUrl = result.pay_url;
				state.error = '';
				state.step = 6;
				render();
			} catch ( error ) {
				state.error = error.message;
				if ( error.code === 'slot_taken' ) {
					state.slot = '';
					await loadSlots();
				}
				render();
			}
		};

		const dates = Object.keys( state.days || {} );
		return el(
			'section',
			{},
			el( 'h2', {}, 'Your price' ),
			summary(),
			el( 'h3', {}, 'Choose a slot' ),
			dates.length === 0 ? el( 'p', {}, 'No slots are open in the next two weeks.' ) : null,
			el(
				'div',
				{ class: 'qhs-days' },
				dates.map( ( date ) =>
					el(
						'div',
						{ class: 'qhs-day' },
						state.days[ date ].map( ( slot ) =>
							el(
								'button',
								{
									type: 'button',
									class: 'qhs-slot' + ( slot === state.slot ? ' is-selected' : '' ),
									'aria-pressed': slot === state.slot ? 'true' : 'false',
									'data-testid': 'slot',
									'data-slot': slot,
									onClick: ( event ) => {
										state.slot = slot;
										root.querySelectorAll( '.qhs-slot' ).forEach( ( button ) => {
											button.classList.remove( 'is-selected' );
											button.setAttribute( 'aria-pressed', 'false' );
										} );
										event.currentTarget.classList.add( 'is-selected' );
										event.currentTarget.setAttribute( 'aria-pressed', 'true' );
									},
								},
								formatSlot( slot )
							)
						)
					)
				)
			),
			turnstileHost,
			el( 'button', { type: 'button', class: 'qhs-primary', 'data-testid': 'hold-submit', onClick: hold }, 'Hold this slot for 10 minutes' )
		);
	}

	function stepPay() {
		const expires = new Date( state.booking.hold_expires_at.replace( ' ', 'T' ) + 'Z' ).getTime();
		const clock = el( 'strong', { 'data-testid': 'hold-countdown', role: 'timer' }, '' );
		const tick = () => {
			const left = Math.max( 0, Math.floor( ( expires - Date.now() ) / 1000 ) );
			clock.textContent = `${ Math.floor( left / 60 ) }:${ String( left % 60 ).padStart( 2, '0' ) }`;
			if ( left > 0 ) {
				setTimeout( tick, 1000 );
			}
		};
		tick();

		return el(
			'section',
			{},
			el( 'h2', {}, 'Your slot is on hold' ),
			el( 'p', {}, formatSlot( state.booking.slot_start ) ),
			el( 'p', {}, 'Held for ', clock, '. Pay the deposit to confirm it.' ),
			summary(),
			el( 'a', { class: 'qhs-primary', href: state.payUrl, 'data-testid': 'pay-link' }, `Pay ${ state.booking.deposit } test deposit` ),
			el( 'p', { class: 'qhs-fine' }, 'Test mode only. Never enter a real card number.' )
		);
	}

	api( 'catalog' )
		.then( ( catalog ) => {
			state.catalog = catalog;
			render();
		} )
		.catch( ( error ) => {
			root.textContent = error.message;
		} );
}() );
