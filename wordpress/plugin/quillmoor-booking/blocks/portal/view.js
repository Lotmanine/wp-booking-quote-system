/* Customer portal: bookings as trip cards with tabs, details, reschedule and cancel. */
( function () {
	const root = document.getElementById( 'qhs-portal' );
	if ( ! root ) {
		return;
	}
	const { api, el, icon, money, formatSlot, formatTime, formatDay, slotDate, calendar, windowBounds, dialog, nonce, config, params } = window.qhs;
	const query = params();
	const bookingId = query.get( 'booking' );
	const token = query.get( 'token' ) || '';
	const signedIn = root.dataset.signedIn === '1';
	const paid = query.get( 'paid' ) === '1';
	const PAID_NOTICE = 'Thank you. Your test deposit was received.';
	let notice = paid ? PAID_NOTICE : '';
	let error = '';
	let tab = 'upcoming';

	const FLOW = [
		[ 'quote', 'Quote' ],
		[ 'hold', 'Slot held' ],
		[ 'deposit_paid', 'Deposit paid' ],
		[ 'confirmed', 'Confirmed' ],
		[ 'assigned', 'Staff assigned' ],
		[ 'completed', 'Completed' ],
	];

	const bucket = ( booking ) => {
		if ( booking.state === 'cancelled' ) {
			return 'cancelled';
		}
		if ( booking.state === 'completed' || ( booking.slot_start && slotDate( booking.slot_start ).getTime() < Date.now() ) ) {
			return 'past';
		}
		return 'upcoming';
	};

	function pill( booking ) {
		return el( 'span', { class: `qhs-pill qhs-pill--${ booking.state }`, 'data-testid': 'booking-state' }, booking.state_label );
	}

	function pdfLink( booking, kind, label ) {
		const auth = token ? `token=${ encodeURIComponent( token ) }` : `_wpnonce=${ encodeURIComponent( nonce ) }`;
		return el( 'a', { class: 'qhs-doc', href: `${ config.rest }bookings/${ booking.id }/pdf/${ kind }?${ auth }`, 'data-testid': `pdf-${ kind }` }, icon( 'file', 16 ), label );
	}

	function timeline( booking ) {
		const reached = FLOW.findIndex( ( [ key ] ) => key === booking.state );
		const when = {};
		( booking.history || [] ).forEach( ( row ) => {
			when[ row.to_state ] = row.created_at;
		} );
		return el(
			'ol',
			{ class: 'qhs-timeline', 'aria-label': 'Booking progress' },
			FLOW.map( ( [ key, label ], index ) =>
				el(
					'li',
					{ class: index <= reached ? 'is-done' : '', 'aria-current': index === reached ? 'step' : null },
					el( 'span', { class: 'qhs-timeline__dot' }, index <= reached ? icon( 'check', 12 ) : null ),
					el( 'span', { class: 'qhs-timeline__label' }, label ),
					when[ key ] ? el( 'span', { class: 'qhs-timeline__when' }, formatSlot( when[ key ] ) ) : null
				)
			),
			booking.state === 'cancelled' ? el( 'li', { class: 'is-cancelled' }, el( 'span', { class: 'qhs-timeline__dot' } ), el( 'span', { class: 'qhs-timeline__label' }, 'Cancelled' ) ) : null
		);
	}

	function details( booking ) {
		const done = ! [ 'quote', 'hold' ].includes( booking.state );
		return el(
			'div',
			{ class: 'qhs-trip-card__details' },
			el(
				'div',
				{ class: 'qhs-trip-card__cols' },
				el( 'div', {}, el( 'h3', {}, 'Progress' ), timeline( booking ) ),
				el(
					'div',
					{},
					el( 'h3', {}, 'Price (fictional)' ),
					el(
						'table',
						{ class: 'qhs-lines' },
						el(
							'tbody',
							{},
							( booking.line_items || [] ).map( ( line ) => el( 'tr', {}, el( 'td', {}, line.label ), el( 'td', { class: 'r' }, money( line.cents ) ) ) ),
							el( 'tr', { class: 't' }, el( 'td', {}, 'Total' ), el( 'td', { class: 'r' }, booking.total ) ),
							el( 'tr', {}, el( 'td', {}, 'Deposit' ), el( 'td', { class: 'r' }, booking.deposit ) )
						)
					),
					el( 'h3', {}, 'Documents' ),
					el( 'p', { class: 'qhs-docs' }, pdfLink( booking, 'quote', 'Quote PDF' ), done ? pdfLink( booking, 'confirmation', 'Confirmation PDF' ) : null )
				)
			)
		);
	}

	function card( booking, expanded ) {
		const panel = el( 'div', { class: 'qhs-trip-card__panel', id: `qhs-trip-${ booking.id }`, hidden: ! expanded }, details( booking ) );
		const actions = el( 'div', { class: 'qhs-trip-card__actions' } );
		if ( ! expanded ) {
			actions.append(
				el(
					'button',
					{
						type: 'button',
						class: 'qhs-btn qhs-btn--ghost qhs-btn--sm',
						'aria-expanded': 'false',
						'aria-controls': `qhs-trip-${ booking.id }`,
						'data-testid': 'details-toggle',
						onClick: ( event ) => {
							panel.hidden = ! panel.hidden;
							event.currentTarget.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
							event.currentTarget.lastChild.textContent = panel.hidden ? 'Details' : 'Hide details';
						},
					},
					'Details'
				)
			);
		}
		if ( booking.can_change ) {
			actions.append( el( 'button', { type: 'button', class: 'qhs-btn qhs-btn--secondary qhs-btn--sm', 'data-testid': 'reschedule-open', onClick: () => reschedule( booking ) }, icon( 'calendar', 16 ), 'Reschedule' ) );
		}
		if ( booking.can_cancel ) {
			actions.append( el( 'button', { type: 'button', class: 'qhs-btn qhs-btn--danger-ghost qhs-btn--sm', 'data-testid': 'cancel', onClick: () => cancel( booking ) }, 'Cancel' ) );
		}

		return el(
			'article',
			{ class: 'qhs-trip-card' + ( expanded ? ' is-expanded' : '' ), 'data-testid': 'booking-card', 'data-booking': booking.id },
			el(
				'div',
				{ class: 'qhs-trip-card__main' },
				el( 'img', { class: 'qhs-trip-card__img', src: booking.image, alt: '', width: '640', height: '400', loading: 'lazy' } ),
				el(
					'div',
					{ class: 'qhs-trip-card__body' },
					el( 'div', { class: 'qhs-trip-card__top' }, el( 'span', { class: 'qhs-mono' }, booking.ref ), pill( booking ) ),
					el( 'h2', { class: 'qhs-trip-card__title' }, booking.service ),
					el( 'p', { class: 'qhs-trip-card__when' }, icon( 'calendar', 16 ), el( 'span', { 'data-testid': 'booking-slot' }, booking.slot_start ? formatSlot( booking.slot_start ) : 'No time chosen yet' ) ),
					el(
						'p',
						{ class: 'qhs-trip-card__meta' },
						el( 'span', {}, icon( 'pin', 16 ), booking.area ),
						el( 'span', {}, icon( 'user', 16 ), el( 'span', { 'data-testid': 'booking-staff' }, booking.staff_name || 'Not assigned yet' ) )
					)
				),
				el( 'div', { class: 'qhs-trip-card__price' }, el( 'strong', {}, booking.total ), el( 'span', {}, 'total, fictional' ) )
			),
			actions,
			panel
		);
	}

	async function reschedule( booking ) {
		let days = {};
		try {
			( { days } = await api( `availability?area=${ encodeURIComponent( booking.area_key ) }` ) );
		} catch ( failure ) {
			error = failure.message;
			load();
			return;
		}
		const bounds = windowBounds();
		const times = el( 'div', { class: 'qhs-times' }, el( 'p', { class: 'qhs-hint' }, 'Pick a day to see open start times.' ) );
		const body = el(
			'div',
			{ class: 'qhs-reschedule' },
			el( 'p', { class: 'qhs-fine' }, `Now: ${ booking.slot_start ? formatSlot( booking.slot_start ) : 'no time' }` ),
			calendar( {
				min: bounds.min,
				max: bounds.max,
				isOpen: ( iso ) => ( days[ iso ] || [] ).length > 0,
				label: 'New date',
				onSelect: ( iso ) => {
					times.replaceChildren(
						el( 'p', { class: 'qhs-field__label' }, formatDay( iso, true ) ),
						el(
							'div',
							{ class: 'qhs-chips-time' },
							days[ iso ].map( ( slot ) =>
								el(
									'button',
									{
										type: 'button',
										class: 'qhs-time',
										'data-testid': 'reschedule-slot',
										'data-slot': slot,
										onClick: () => {
											modal.close();
											run( () => api( `bookings/${ booking.id }/reschedule`, { method: 'POST', token, body: { slot_start: slot } } ), 'Your booking was updated.' );
										},
									},
									formatTime( slot )
								)
							)
						)
					);
				},
			} ),
			times
		);
		const modal = dialog( { title: 'Choose a new time', body, testid: 'reschedule-dialog' } );
	}

	function cancel( booking ) {
		dialog( {
			title: 'Cancel this booking?',
			testid: 'cancel-dialog',
			body: el(
				'div',
				{},
				el( 'p', {}, `${ booking.service }, ${ booking.slot_start ? formatSlot( booking.slot_start ) : 'no time chosen' }.` ),
				el( 'p', { class: 'qhs-fine' }, 'The slot is released straight away. In this demo no money is refunded, because none was taken.' )
			),
			actions: [
				{ label: 'Keep booking', onClick: ( modal ) => modal.close() },
				{
					label: 'Cancel booking',
					danger: true,
					testid: 'cancel-confirm',
					onClick: ( modal ) => {
						modal.close();
						run( () => api( `bookings/${ booking.id }/cancel`, { method: 'POST', token } ), 'Your booking was cancelled.' );
					},
				},
			],
		} );
	}

	async function run( action, message ) {
		try {
			await action();
			notice = message;
			error = '';
		} catch ( failure ) {
			error = failure.message;
			notice = '';
		}
		load();
	}

	function consentCard( enabled ) {
		return el(
			'section',
			{ class: 'qhs-panel' },
			el( 'h2', { class: 'qhs-panel__title' }, 'Email preferences' ),
			el(
				'label',
				{ class: 'qhs-switch' },
				el( 'input', {
					type: 'checkbox',
					role: 'switch',
					checked: enabled,
					'data-testid': 'consent-toggle',
					onChange: ( event ) => run( () => api( 'consent', { method: 'POST', body: { marketing_consent: event.target.checked } } ), 'Your email preference was saved.' ),
				} ),
				el( 'span', { class: 'qhs-switch__track', 'aria-hidden': 'true' } ),
				el( 'span', {}, 'Send me occasional news from Quillmoor' )
			),
			el( 'p', { class: 'qhs-fine' }, 'Booking emails are always sent. This only covers news.' )
		);
	}

	function messages() {
		return [
			notice ? el( 'p', { class: 'qhs-notice', role: 'status', 'data-testid': 'portal-notice' }, icon( 'check', 18 ), el( 'span', {}, notice ) ) : null,
			error ? el( 'p', { class: 'qhs-alert', role: 'alert' }, icon( 'alert', 18 ), el( 'span', {}, error ) ) : null,
		];
	}

	function paintSingle( booking ) {
		const nodes = [];
		const confirming = paid && notice === PAID_NOTICE;
		if ( confirming ) {
			nodes.push(
				el(
					'div',
					{ class: 'qhs-confirm-hero', role: 'status' },
					el( 'span', { class: 'qhs-held__icon' }, icon( 'check', 26 ) ),
					el(
						'div',
						{},
						el( 'h2', {}, 'You are booked' ),
						el( 'p', { 'data-testid': 'portal-notice' }, `${ PAID_NOTICE } ${ booking.service }, ${ booking.slot_start ? formatSlot( booking.slot_start ) : '' }. A team member is assigned in a minute or two.` )
					)
				)
			);
		}
		nodes.push( ...( confirming ? [ error ? messages()[ 1 ] : null ] : messages() ).filter( Boolean ), card( booking, true ) );
		if ( signedIn ) {
			nodes.push( el( 'p', {}, el( 'a', { class: 'qhs-link-arrow', href: window.location.pathname }, 'All your bookings ', el( 'span', { 'aria-hidden': 'true' }, '→' ) ) ) );
		}
		root.replaceChildren( ...nodes );
	}

	function paintList( bookings, consent ) {
		const groups = { upcoming: [], past: [], cancelled: [] };
		bookings.forEach( ( booking ) => groups[ bucket( booking ) ].push( booking ) );
		groups.upcoming.sort( ( a, b ) => ( a.slot_start || '9' ).localeCompare( b.slot_start || '9' ) );
		groups.past.sort( ( a, b ) => ( b.slot_start || '' ).localeCompare( a.slot_start || '' ) );
		const labels = { upcoming: 'Upcoming', past: 'Past', cancelled: 'Cancelled' };
		const tabs = el( 'div', { class: 'qhs-tabs', role: 'tablist', 'aria-label': 'Bookings' } );
		Object.keys( labels ).forEach( ( key ) => {
			tabs.append(
				el(
					'button',
					{
						type: 'button',
						role: 'tab',
						id: `qhs-tab-${ key }`,
						class: 'qhs-tab',
						'aria-selected': tab === key ? 'true' : 'false',
						'aria-controls': 'qhs-tabpanel',
						tabindex: tab === key ? '0' : '-1',
						'data-testid': `tab-${ key }`,
						onClick: () => {
							tab = key;
							paintList( bookings, consent );
							root.querySelector( `#qhs-tab-${ key }` ).focus();
						},
						onKeydown: ( event ) => {
							const order = Object.keys( labels );
							const step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ];
							if ( step ) {
								tab = order[ ( order.indexOf( key ) + step + order.length ) % order.length ];
								paintList( bookings, consent );
								root.querySelector( `#qhs-tab-${ tab }` ).focus();
							}
						},
					},
					labels[ key ],
					el( 'span', { class: 'qhs-tab__count' }, String( groups[ key ].length ) )
				)
			);
		} );
		const list = groups[ tab ];
		const empty = el(
			'div',
			{ class: 'qhs-empty-state' },
			el( 'p', {}, tab === 'upcoming' ? 'No upcoming bookings yet.' : `No ${ labels[ tab ].toLowerCase() } bookings.` ),
			tab === 'upcoming' ? el( 'a', { class: 'qhs-btn qhs-btn--primary', href: `${ config.home }services/` }, 'Book a service' ) : null
		);
		root.replaceChildren(
			...messages().filter( Boolean ),
			tabs,
			el( 'div', { class: 'qhs-tabpanel', id: 'qhs-tabpanel', role: 'tabpanel', 'aria-labelledby': `qhs-tab-${ tab }` }, list.length ? list.map( ( booking ) => card( booking, false ) ) : empty ),
			consentCard( consent )
		);
	}

	async function load() {
		try {
			if ( bookingId ) {
				paintSingle( await api( `bookings/${ encodeURIComponent( bookingId ) }`, { token } ) );
			} else if ( signedIn ) {
				const result = await api( 'bookings' );
				paintList( result.bookings, result.marketing_consent );
			} else {
				root.replaceChildren(
					el(
						'div',
						{ class: 'qhs-empty-state' },
						el( 'h2', {}, 'Sign in to see your bookings' ),
						el( 'p', {}, 'Or open the link in your booking email. Demo accounts are listed on the demo page.' ),
						el( 'p', { class: 'qhs-actions' }, el( 'a', { class: 'qhs-btn qhs-btn--primary', href: root.dataset.login }, 'Sign in' ), el( 'a', { class: 'qhs-btn qhs-btn--secondary', href: `${ config.home }demo/` }, 'Demo logins' ) )
					)
				);
			}
		} catch ( failure ) {
			root.replaceChildren( el( 'p', { class: 'qhs-alert', role: 'alert' }, icon( 'alert', 18 ), el( 'span', {}, failure.message ) ) );
		}
	}

	async function start() {
		const verify = query.get( 'verify' );
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
