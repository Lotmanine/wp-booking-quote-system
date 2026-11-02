/* Header: shadow on scroll and an accessible mobile drawer. */
( function () {
	const header = document.querySelector( '[data-qm-header]' );
	if ( ! header ) {
		return;
	}
	const drawer = header.querySelector( '[data-qm-drawer]' );
	const opener = header.querySelector( '[data-qm-open]' );
	const panel = drawer.querySelector( '[role="dialog"]' );
	let lastFocus = null;

	const onScroll = () => header.classList.toggle( 'is-scrolled', window.scrollY > 4 );
	window.addEventListener( 'scroll', onScroll, { passive: true } );
	onScroll();

	const focusables = () =>
		Array.from( panel.querySelectorAll( 'a[href], button:not([disabled])' ) ).filter( ( node ) => node.offsetParent !== null );

	function open() {
		lastFocus = document.activeElement;
		drawer.hidden = false;
		document.documentElement.classList.add( 'qm-lock' );
		opener.setAttribute( 'aria-expanded', 'true' );
		requestAnimationFrame( () => {
			drawer.classList.add( 'is-open' );
			const first = panel.querySelector( '.qm-drawer__link' ) || focusables()[ 0 ];
			if ( first ) {
				first.focus();
			}
		} );
	}

	function close() {
		drawer.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'qm-lock' );
		opener.setAttribute( 'aria-expanded', 'false' );
		drawer.hidden = true;
		if ( lastFocus ) {
			lastFocus.focus();
		}
	}

	opener.addEventListener( 'click', open );
	drawer.querySelectorAll( '[data-qm-close]' ).forEach( ( node ) => node.addEventListener( 'click', close ) );
	drawer.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' ) {
			close();
			return;
		}
		if ( event.key !== 'Tab' ) {
			return;
		}
		const items = focusables();
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );
	window.matchMedia( '(min-width: 960px)' ).addEventListener( 'change', ( query ) => {
		if ( query.matches && ! drawer.hidden ) {
			close();
		}
	} );
}() );
