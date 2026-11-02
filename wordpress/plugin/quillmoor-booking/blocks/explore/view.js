/* Explore: photo arrows on cards, the results map with price pins, and the phone map toggle. */
( function () {
	const qm = window.qhs;

	document.querySelectorAll( '[data-qm-card]' ).forEach( ( card ) => qm.cardPhotos( card ) );

	const root = document.querySelector( '[data-qm-explore]' );
	if ( ! root ) {
		return;
	}
	const box = root.querySelector( '[data-qm-map]' );
	let map = null;

	function draw() {
		if ( map || ! box || ! window.L ) {
			return;
		}
		const points = JSON.parse( box.dataset.points || '[]' );
		map = qm.baseMap( box, { scrollWheelZoom: false } );
		const markers = [];
		points.forEach( ( point ) => {
			const marker = window.L.marker( [ point.lat, point.lng ], {
				icon: window.L.divIcon( { className: 'qm-pin', html: '<span>' + qm.escape( point.label ) + '</span>', iconSize: null } ),
				title: point.title,
				keyboard: true,
			} ).addTo( map );
			const popup = qm.el(
				'a',
				{ class: 'qm-pop', href: point.url },
				point.photo ? qm.el( 'img', { src: point.photo, alt: '', width: 240, height: 160 } ) : null,
				qm.el( 'strong', {}, point.title ),
				qm.el( 'span', {}, point.line )
			);
			marker.bindPopup( popup, { closeButton: false, minWidth: 220, maxWidth: 240 } );
			marker.on( 'popupopen', () => {
				root.querySelectorAll( '[data-qm-card]' ).forEach( ( c ) => c.classList.toggle( 'is-hot', c.dataset.listing === String( point.id ) ) );
			} );
			markers.push( { marker, id: String( point.id ) } );
		} );
		if ( points.length ) {
			map.fitBounds( window.L.latLngBounds( points.map( ( p ) => [ p.lat, p.lng ] ) ).pad( 0.25 ), { maxZoom: 12 } );
		} else {
			map.setView( [ 38.6, -122.8 ], 8 );
		}
		root.querySelectorAll( '[data-qm-card]' ).forEach( ( card ) => {
			const hit = markers.find( ( m ) => m.id === card.dataset.listing );
			if ( ! hit ) {
				return;
			}
			card.addEventListener( 'mouseenter', () => hit.marker.getElement()?.classList.add( 'is-hot' ) );
			card.addEventListener( 'mouseleave', () => hit.marker.getElement()?.classList.remove( 'is-hot' ) );
		} );
	}

	const wide = window.matchMedia( '(min-width: 1128px)' );
	if ( wide.matches ) {
		draw();
	}
	wide.addEventListener( 'change', () => {
		if ( wide.matches ) {
			draw();
			map?.invalidateSize();
		}
	} );

	const toggle = root.querySelector( '[data-qm-maptoggle]' );
	toggle?.addEventListener( 'click', () => {
		const on = ! root.classList.contains( 'is-map' );
		root.classList.toggle( 'is-map', on );
		toggle.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		const label = toggle.querySelector( 'span' );
		label.textContent = on ? label.dataset.labelHide : label.dataset.labelShow;
		if ( on ) {
			draw();
			window.setTimeout( () => map?.invalidateSize(), 50 );
		}
		window.scrollTo( { top: 0 } );
	} );
}() );
