/* Search pill: switch between stay and table fields, keep checkout after check-in. */
( function () {
	document.querySelectorAll( '[data-qm-search]' ).forEach( ( form ) => {
		const sync = () => {
			const type = form.querySelector( 'input[name="type"]:checked' )?.value || 'stay';
			form.dataset.type = type;
			form.querySelectorAll( '[data-for]' ).forEach( ( field ) => {
				const on = field.dataset.for === type;
				field.hidden = ! on;
				field.querySelectorAll( 'input, select' ).forEach( ( input ) => {
					input.disabled = ! on;
				} );
			} );
		};
		form.querySelectorAll( 'input[name="type"]' ).forEach( ( radio ) => radio.addEventListener( 'change', sync ) );

		const checkIn = form.querySelector( 'input[name="check_in"]' );
		const checkOut = form.querySelector( 'input[name="check_out"]' );
		if ( checkIn && checkOut ) {
			checkIn.addEventListener( 'change', () => {
				if ( ! checkIn.value ) {
					return;
				}
				const next = new Date( checkIn.value + 'T00:00:00Z' );
				next.setUTCDate( next.getUTCDate() + 1 );
				const min = next.toISOString().slice( 0, 10 );
				checkOut.min = min;
				if ( ! checkOut.value || checkOut.value < min ) {
					checkOut.value = min;
				}
			} );
		}

		// Empty fields stay out of the address bar.
		form.addEventListener( 'submit', () => {
			form.querySelectorAll( 'input, select' ).forEach( ( input ) => {
				if ( input.type !== 'radio' && ! input.value ) {
					input.disabled = true;
				}
			} );
		} );
		sync();
	} );
}() );
