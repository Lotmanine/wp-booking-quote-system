( function () {
	const root = document.getElementById( 'qhs-staff' );
	if ( ! root ) {
		return;
	}
	const { api, el, formatSlot } = window.qhs;

	if ( root.dataset.signedIn !== '1' ) {
		root.replaceChildren( el( 'p', {}, el( 'a', { href: root.dataset.login }, 'Sign in' ), ' to see your schedule.' ) );
		return;
	}

	const STEPS = [
		[ 'en_route', 'On my way' ],
		[ 'on_site', 'On site' ],
		[ 'completed', 'Mark complete' ],
	];

	async function act( path, body ) {
		try {
			await api( path, { method: 'POST', body } );
			load();
		} catch ( failure ) {
			root.prepend( el( 'p', { class: 'qhs-error', role: 'alert' }, failure.message ) );
		}
	}

	function triagePanel( job ) {
		if ( ! job.triage ) {
			return null;
		}
		return el(
			'div',
			{ class: 'qhs-triage', 'data-testid': 'triage' },
			el( 'p', {}, el( 'strong', {}, `Suggested priority: ${ job.triage.suggested_priority }` ), ` (${ job.triage.source === 'ai' ? 'AI suggestion, for your review' : 'automatic default' })` ),
			el( 'p', {}, job.triage.reason ),
			el( 'button', { type: 'button', class: 'qhs-secondary', onClick: () => act( `staff/jobs/${ job.id }/triage`, { decision: 'accepted' } ) }, 'Accept' ),
			' ',
			el( 'button', { type: 'button', class: 'qhs-secondary', onClick: () => act( `staff/jobs/${ job.id }/triage`, { decision: 'dismissed' } ) }, 'Dismiss' )
		);
	}

	function card( job ) {
		const active = job.state === 'assigned';
		return el(
			'article',
			{ class: 'qhs-card', 'data-testid': 'job-card', 'data-booking': job.id },
			el( 'h2', {}, `${ job.ref }: ${ job.service }` ),
			el( 'p', {}, el( 'span', { class: 'qhs-badge', 'data-testid': 'job-state' }, job.state_label ), ' ', el( 'span', { class: 'qhs-badge qhs-badge--soft' }, `Priority: ${ job.priority }` ) ),
			el(
				'dl',
				{},
				el( 'dt', {}, 'Slot' ),
				el( 'dd', {}, job.slot_start ? formatSlot( job.slot_start ) : '' ),
				el( 'dt', {}, 'Area' ),
				el( 'dd', {}, job.area ),
				el( 'dt', {}, 'Customer' ),
				el( 'dd', {}, job.customer_name ),
				el( 'dt', {}, 'Progress' ),
				el( 'dd', { 'data-testid': 'job-status' }, job.job_status.replace( '_', ' ' ) )
			),
			job.customer_note ? el( 'p', {}, el( 'strong', {}, 'Customer note: ' ), job.customer_note ) : null,
			triagePanel( job ),
			active
				? el(
					'div',
					{ class: 'qhs-actions' },
					STEPS.map( ( [ status, label ] ) =>
						el( 'button', { type: 'button', class: 'qhs-secondary', 'data-testid': `job-${ status }`, onClick: () => act( `staff/jobs/${ job.id }/status`, { status } ) }, label )
					)
				)
				: null
		);
	}

	async function load() {
		try {
			const { jobs } = await api( 'staff/schedule' );
			root.replaceChildren( ...( jobs.length ? jobs.map( card ) : [ el( 'p', { 'data-testid': 'no-jobs' }, 'No jobs assigned yet.' ) ] ) );
		} catch ( failure ) {
			root.replaceChildren( el( 'p', { class: 'qhs-error', role: 'alert' }, failure.message ) );
		}
	}

	load();
}() );
