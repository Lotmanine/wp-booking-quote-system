/* Header: shadow on scroll, and the account menu closes on Escape or an outside click. */
( function () {
	const header = document.querySelector( '[data-qm-header]' );
	if ( ! header ) {
		return;
	}
	const onScroll = () => header.classList.toggle( 'is-scrolled', window.scrollY > 4 );
	window.addEventListener( 'scroll', onScroll, { passive: true } );
	onScroll();

	const menu = header.querySelector( '[data-qm-menu]' );
	if ( ! menu ) {
		return;
	}
	document.addEventListener( 'click', ( event ) => {
		if ( menu.open && ! menu.contains( event.target ) ) {
			menu.open = false;
		}
	} );
	menu.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' && menu.open ) {
			menu.open = false;
			menu.querySelector( 'summary' ).focus();
		}
	} );
}() );
