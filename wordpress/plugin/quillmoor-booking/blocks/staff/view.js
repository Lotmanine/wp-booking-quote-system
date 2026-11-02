/* Staff agenda: day filter, KPI row and job cards with a one-tap next step. */
( function () {
	const root = document.getElementById( 'qhs-staff' );
	if ( ! root ) {
		return;
	}
	const { api, el, icon, formatTime, formatDay, isoDay } = window.qhs;

	if ( root.dataset.signedIn !== '1' ) {
		root.replaceChildren(
			el(
				'div',
				{ class: 'qhs-empty-state' },
				el( 'h2', {}, 'Sign in to see your schedule' ),
				el( 'p', {}, 'Use the demo-staff account from the demo page.' ),
				el( 'a', { class: 'qhs-btn qhs-btn--primary', href: root.dataset.login }, 'Sign in' )
			)
		);
		return;
	}

	const PROGRESS = [
		[ 'scheduled', 'Scheduled' ],
		[ 'en_route', 'On the way' ],
		[ 'on_site', 'On site' ],
		[ 'done', 'Done' ],
	];
	const NEXT = {
		scheduled: [ 'en_route', 'On my way' ],
		en_route: [ 'on_site', 'Arrived on site' ],
		on_site: [ 'completed', 'Mark complete' ],
	};
	let day = 'all';
	let jobs = [];
	let error = '';

	async function act( path, body ) {
		try {
			await api( path, { method: 'POST', body } );
			error = '';
		} catch ( failure ) {
			error = failure.message;
		}
		load();
	}

	function triagePanel( job ) {
		if ( ! job.triage ) {
			return null;
		}
		return el(
			'div',
			{ class: 'qhs-triage', 'data-testid': 'triage' },
			el( 'p', { class: 'qhs-triage__head' }, icon( 'alert', 16 ), el( 'strong', {}, `Suggested priority: ${ job.triage.suggested_priority }` ), el( 'span', { class: 'qhs-tag' }, job.triage.source === 'ai' ? 'AI suggestion' : 'Automatic default' ) ),
			el( 'p', {}, job.triage.reason ),
			el( 'p', { class: 'qhs-fine' }, 'Nothing changes until you accept it.' ),
			el(
				'div',
				{ class: 'qhs-actions' },
				el( 'button', { type: 'button', class: 'qhs-btn qhs-btn--secondary qhs-btn--sm', onClick: () => act( `staff/jobs/${ job.id }/triage`, { decision: 'accepted' } ) }, 'Accept' ),
				el( 'button', { type: 'button', class: 'qhs-btn qhs-btn--ghost qhs-btn--sm', onClick: () => act( `staff/jobs/${ job.id }/triage`, { decision: 'dismissed' } ) }, 'Dismiss' )
			)
		);
	}

	function stepper( job ) {
		const current = job.state === 'completed' ? 3 : Math.max( 0, PROGRESS.findIndex( ( [ key ] ) => key === job.job_status ) );
		return el(
			'ol',
			{ class: 'qhs-stepper', 'aria-label': 'Job progress' },
			PROGRESS.map( ( [ key, label ], index ) =>
				el( 'li', { class: index < current ? 'is-done' : index === current ? 'is-current' : '', 'aria-current': index === current ? 'step' : null, 'data-step': key }, el( 'span', {}, label ) )
			)
		);
	}

	function card( job ) {
		const active = job.state === 'assigned';
		const next = active ? NEXT[ job.job_status ] || NEXT.scheduled : null;
		return el(
			'article',
			{ class: 'qhs-job' + ( active ? '' : ' is-closed' ), 'data-testid': 'job-card', 'data-booking': job.id },
			el(
				'div',
				{ class: 'qhs-job__time' },
				el( 'strong', {}, job.slot_start ? formatTime( job.slot_start ) : '' ),
				el( 'span', {}, 'UTC' )
			),
			el(
				'div',
				{ class: 'qhs-job__body' },
				el(
					'div',
					{ class: 'qhs-job__top' },
					el( 'span', { class: 'qhs-mono' }, job.ref ),
					el( 'span', { class: `qhs-pill qhs-pill--${ job.state }`, 'data-testid': 'job-state' }, job.state_label ),
					el( 'span', { class: `qhs-pill qhs-pill--prio-${ job.priority }` }, `Priority: ${ job.priority }` )
				),
				el( 'h3', { class: 'qhs-job__title' }, job.service ),
				el(
					'p',
					{ class: 'qhs-job__meta' },
					el( 'span', {}, icon( 'user', 16 ), job.customer_name ),
					el( 'span', {}, icon( 'pin', 16 ), job.area ),
					el( 'span', { class: 'qhs-job__status' }, 'Progress: ', el( 'span', { 'data-testid': 'job-status' }, job.job_status.replace( '_', ' ' ) ) )
				),
				job.customer_note ? el( 'blockquote', { class: 'qhs-job__note' }, job.customer_note ) : null,
				triagePanel( job ),
				stepper( job ),
				next
					? el(
						'div',
						{ class: 'qhs-actions' },
						el( 'button', { type: 'button', class: 'qhs-btn qhs-btn--primary qhs-btn--sm', 'data-testid': `job-${ next[ 0 ] }`, onClick: () => act( `staff/jobs/${ job.id }/status`, { status: next[ 0 ] } ) }, next[ 1 ] )
					)
					: null
			)
		);
	}

	function kpis() {
		const today = isoDay( new Date() );
		const week = isoDay( new Date( Date.now() + 7 * 86400000 ) );
		const day0 = ( job ) => ( job.slot_start || '' ).slice( 0, 10 );
		const items = [
			[ 'Today', jobs.filter( ( job ) => job.state === 'assigned' && day0( job ) === today ).length ],
			[ 'Next 7 days', jobs.filter( ( job ) => job.state === 'assigned' && day0( job ) >= today && day0( job ) <= week ).length ],
			[ 'Completed', jobs.filter( ( job ) => job.state === 'completed' ).length ],
		];
		return el( 'div', { class: 'qhs-kpis' }, items.map( ( [ label, value ] ) => el( 'div', { class: 'qhs-kpi' }, el( 'strong', {}, String( value ) ), el( 'span', {}, label ) ) ) );
	}

	function paint() {
		const days = [ ...new Set( jobs.map( ( job ) => ( job.slot_start || '' ).slice( 0, 10 ) ).filter( Boolean ) ) ].sort();
		if ( day !== 'all' && ! days.includes( day ) ) {
			day = 'all';
		}
		const strip = el(
			'div',
			{ class: 'qhs-daystrip', role: 'group', 'aria-label': 'Show day' },
			[ 'all', ...days ].map( ( iso ) =>
				el(
					'button',
					{
						type: 'button',
						class: 'qhs-daychip' + ( iso === day ? ' is-active' : '' ),
						'aria-pressed': iso === day ? 'true' : 'false',
						onClick: () => {
							day = iso;
							paint();
						},
					},
					iso === 'all' ? 'All days' : formatDay( iso ),
					el( 'span', { class: 'qhs-tab__count' }, String( iso === 'all' ? jobs.length : jobs.filter( ( job ) => ( job.slot_start || '' ).startsWith( iso ) ).length ) )
				)
			)
		);

		const shown = jobs
			.filter( ( job ) => day === 'all' || ( job.slot_start || '' ).startsWith( day ) )
			.sort( ( a, b ) => ( a.slot_start || '' ).localeCompare( b.slot_start || '' ) );
		const groups = {};
		shown.forEach( ( job ) => {
			const key = ( job.slot_start || '' ).slice( 0, 10 );
			( groups[ key ] = groups[ key ] || [] ).push( job );
		} );

		root.replaceChildren(
			error ? el( 'p', { class: 'qhs-alert', role: 'alert' }, icon( 'alert', 18 ), el( 'span', {}, error ) ) : '',
			kpis(),
			jobs.length ? strip : '',
			jobs.length
				? el(
					'div',
					{ class: 'qhs-agenda' },
					Object.keys( groups ).map( ( iso ) =>
						el(
							'section',
							{ class: 'qhs-agenda__day' },
							el( 'h2', { class: 'qhs-agenda__date' }, iso ? formatDay( iso, true ) : 'No date', el( 'span', {}, `${ groups[ iso ].length } ${ groups[ iso ].length === 1 ? 'job' : 'jobs' }` ) ),
							groups[ iso ].map( card )
						)
					)
				)
				: el( 'div', { class: 'qhs-empty-state' }, el( 'p', { 'data-testid': 'no-jobs' }, 'No jobs assigned yet.' ) )
		);
	}

	async function load() {
		try {
			( { jobs } = await api( 'staff/schedule' ) );
			paint();
		} catch ( failure ) {
			root.replaceChildren( el( 'p', { class: 'qhs-alert', role: 'alert' }, icon( 'alert', 18 ), el( 'span', {}, failure.message ) ) );
		}
	}

	load();
}() );
