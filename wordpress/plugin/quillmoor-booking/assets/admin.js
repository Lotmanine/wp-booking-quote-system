/* Host screens: confirmation prompts, the listing map picker and the price preview. */
( function () {
	const cfg = window.qhsAdmin || {};

	/* Forms with data-confirm ask first. */
	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( '[data-confirm]' );
		if ( form && ! window.confirm( form.dataset.confirm ) ) {
			event.preventDefault();
		}
	} );

	/* Map picker: writes the latitude and longitude fields of the listing. */
	const box = document.querySelector( '[data-qhs-mappicker]' );
	if ( box && window.L ) {
		const lat = document.querySelector( '[data-name="qm_lat"] input' );
		const lng = document.querySelector( '[data-name="qm_lng"] input' );
		const start = lat && lng && lat.value && lng.value ? [ Number( lat.value ), Number( lng.value ) ] : null;
		const map = window.L.map( box ).setView( start || [ 38.6, -122.8 ], start ? 13 : 8 );
		window.L.tileLayer( cfg.tiles, { maxZoom: 18, attribution: cfg.attr } ).addTo( map );
		const icon = window.L.divIcon( { className: 'qhs-adminpin', html: '<span></span>', iconSize: [ 22, 22 ], iconAnchor: [ 11, 11 ] } );
		let pin = start ? window.L.marker( start, { draggable: true, icon } ).addTo( map ) : null;
		const write = ( point ) => {
			if ( lat && lng ) {
				lat.value = point.lat.toFixed( 6 );
				lng.value = point.lng.toFixed( 6 );
				lat.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				lng.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		};
		const place = ( point ) => {
			if ( pin ) {
				pin.setLatLng( point );
			} else {
				pin = window.L.marker( point, { draggable: true, icon } ).addTo( map );
				pin.on( 'dragend', () => write( pin.getLatLng() ) );
			}
			write( point );
		};
		pin?.on( 'dragend', () => write( pin.getLatLng() ) );
		map.on( 'click', ( event ) => place( event.latlng ) );
		// The box can start hidden behind a field tab; fix the size once it shows.
		new ResizeObserver( () => map.invalidateSize() ).observe( box );
	}

	/* Price preview through the public price route, so it matches the booking card exactly. */
	const preview = document.querySelector( '[data-qhs-preview]' );
	if ( preview ) {
		const out = preview.querySelector( '.qhs-preview__out' );
		preview.querySelector( '[data-qhs-preview-run]' ).addEventListener( 'click', async () => {
			const value = ( name ) => preview.querySelector( `[name="${ name }"]` )?.value || '';
			const body = {
				check_in: value( 'check_in' ),
				check_out: value( 'check_out' ),
				slot_start: value( 'slot_start' ),
				guests: Number( value( 'guests' ) || 1 ),
				extras: [ ...preview.querySelectorAll( '[name="extras"]:checked' ) ].map( ( box ) => box.value ),
			};
			out.textContent = 'Working...';
			const response = await fetch( `${ cfg.rest }listings/${ preview.dataset.listing }/price`, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( body ),
			} );
			const data = await response.json().catch( () => ( {} ) );
			out.replaceChildren();
			if ( ! response.ok ) {
				out.textContent = data.message || 'No price for that request.';
				return;
			}
			const list = document.createElement( 'ul' );
			data.lines.forEach( ( line ) => {
				const li = document.createElement( 'li' );
				li.textContent = `${ line.label }: ${ line.amount }`;
				list.append( li );
			} );
			const total = document.createElement( 'p' );
			total.textContent = `Total ${ data.total }, deposit ${ data.deposit }`;
			total.dataset.testid = 'preview-total';
			out.append( list, total );
		} );
	}
}() );
