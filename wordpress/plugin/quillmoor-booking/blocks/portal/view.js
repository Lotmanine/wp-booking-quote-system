( function () {
	const root = document.getElementById( 'qhs-portal' );
	if ( ! root ) {
		return;
	}
	const { api, el, formatSlot, nonce, config } = window.qhs;
	const params = new URLSearchParams( window.location.search );
	const bookingId = params.get( 'booking' );
	const token = params.get( 'token' ) || '';
	const signedIn = root.dataset.signedIn === '1';
	let notice = params.get( 'paid' ) ? 'Thank you. Your test deposit was received.' : '';
	let error = '';

	function pdfLink( booking, kind, label ) {
		const auth = token ? `token=${ encodeURIComponent( token ) }` : `_wpnonce=${ encodeURIComponent( nonce ) }`;
		return el( 'a', { href: `${ config.rest }bookings/${ booking.id }/pdf/${ kind }?${ auth }`, 'data-testid': `pdf-${ kind }` }, label );
	}

	function card( booking ) {
		const actions = el( 'div', { class: 'qhs-actions' } );
		if ( booking.can_change ) {
			actions.append(
				el( 'button', { type: 'button', class: 'qhs-secondary', 'data-testid': 'reschedule-open', onClick: () => openReschedule( booking, panel ) }, 'Reschedule' )
			);
		}
		if ( booking.can_cancel ) {
			actions.append(
				el(
					'button',
					{
						type: 'button',
						class: 'qhs-secondary',
						'data-testid': 'cancel',
						onClick: async () => {
							// eslint-disable-next-line no-alert
							if ( window.confirm( 'Cancel this booking?' ) ) {
								await run( () => api( `bookings/${ booking.id }/cancel`, { method: 'POST', token } ) );
							}
						},
					},
					'Cancel'
				)
			);
		}
		const panel = el( 'div', {} );

		return el(
			'article',
			{ class: 'qhs-card', 'data-testid': 'booking-card', 'data-booking': booking.id },
			el( 'h2', {}, `${ booking.ref }: ${ booking.service }` ),
			el( 'p', {}, el( 'span', { class: 'qhs-badge', 'data-testid': 'booking-state' }, booking.state_label ) ),
			el(
				'dl',
				{},
				el( 'dt', {}, 'Slot' ),
				el( 'dd', { 'data-testid': 'booking-slot' }, booking.slot_start ? formatSlot( booking.slot_start ) : 'Not chosen yet' ),
				el( 'dt', {}, 'Area' ),
				el( 'dd', {}, booking.area ),
				el( 'dt', {}, 'Staff' ),
				el( 'dd', { 'data-testid': 'booking-staff' }, booking.staff_name || 'Not assigned yet' ),
				el( 'dt', {}, 'Total (fictional)' ),
				el( 'dd', {}, booking.total ),
				el( 'dt', {}, 'Deposit (fictional)' ),
				el( 'dd', {}, booking.deposit )
			),
			el(
				'p',
				{ class: 'qhs-links' },
				pdfLink( booking, 'quote', 'Quote PDF' ),
				[ 'quote', 'hold' ].includes( booking.state ) ? null : ' ',
				[ 'quote', 'hold' ].includes( booking.state ) ? null : pdfLink( booking, 'confirmation', 'Confirmation PDF' )
			),
			actions,
			panel
		);
	}

	async function openReschedule( booking, panel ) {
		const { days } = await api( `availability?area=${ encodeURIComponent( booking.area_key ) }` );
		panel.replaceChildren(
			el( 'h3', {}, 'Pick a new slot' ),
			el(
				'div',
				{ class: 'qhs-days' },
				Object.values( days )
					.flat()
					.slice( 0, 16 )
					.map( ( slot ) =>
						el(
							'button',
							{
								type: 'button',
								class: 'qhs-slot',
								'data-testid': 'reschedule-slot',
								onClick: () => run( () => api( `bookings/${ booking.id }/reschedule`, { method: 'POST', token, body: { slot_start: slot } } ) ),
							},
							formatSlot( slot )
						)
					)
			)
		);
	}

	async function run( action ) {
		try {
			await action();
			notice = 'Your booking was updated.';
			error = '';
		} catch ( failure ) {
			error = failure.message;
		}
		load();
	}

	function consentToggle( enabled ) {
		return el(
			'p',
			{ class: 'qhs-checks' },
			el(
				'label',
				{},
				el( 'input', {
					type: 'checkbox',
					checked: enabled,
					'data-testid': 'consent-toggle',
					onChange: ( event ) => run( () => api( 'consent', { method: 'POST', body: { marketing_consent: event.target.checked } } ) ),
				} ),
				' Send me occasional news from Quillmoor'
			)
		);
	}

	function paint( cards, footer ) {
		const nodes = [
			notice ? el( 'p', { class: 'qhs-notice', role: 'status', 'data-testid': 'portal-notice' }, notice ) : null,
			error ? el( 'p', { class: 'qhs-error', role: 'alert' }, error ) : null,
			...cards,
			footer || null,
		];
		root.replaceChildren( ...nodes.filter( Boolean ) );
	}

	async function load() {
		try {
			if ( bookingId ) {
				const booking = await api( `bookings/${ encodeURIComponent( bookingId ) }`, { token } );
				paint( [ card( booking ) ] );
			} else if ( signedIn ) {
				const result = await api( 'bookings' );
				paint(
					result.bookings.length ? result.bookings.map( card ) : [ el( 'p', {}, 'No bookings yet. ', el( 'a', { href: '/quote/' }, 'Get a quote' ) ) ],
					consentToggle( result.marketing_consent )
				);
			} else {
				paint( [ el( 'p', {}, el( 'a', { href: root.dataset.login }, 'Sign in' ), ' or open the link in your booking email.' ) ] );
			}
		} catch ( failure ) {
			paint( [ el( 'p', { class: 'qhs-error', role: 'alert' }, failure.message ) ] );
		}
	}

	async function start() {
		const verify = params.get( 'verify' );
		if ( verify ) {
			try {
				await api( 'verify-email', { method: 'POST', body: { token: verify } } );
				notice = 'Your email is confirmed.';
			} catch ( failure ) {
				error = failure.message;
			}
		}
		load();
	}

	start();
}() );
