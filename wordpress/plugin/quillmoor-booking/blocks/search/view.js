/* Search bar: each field opens a panel, and picking a value moves on to the next field. */
( function () {
	const { el, icon, calendar, windowBounds, formatDay, catalog, moneyShort } = window.qhs;

	document.querySelectorAll( '[data-qhs-search]' ).forEach( ( form ) => {
		const segs = Array.from( form.querySelectorAll( '.qhs-search__seg' ) );
		let openSeg = null;
		form.classList.add( 'is-enhanced' );

		const optionsFor = {
			category: () => [
				{ value: '', label: 'Any service', hint: 'Show all six services', icon: 'all' },
				...catalog.categories.map( ( item ) => ( {
					value: item.key,
					label: item.label,
					hint: catalog.services.filter( ( s ) => s.category === item.key ).map( ( s ) => s.label ).join( ', ' ),
					icon: item.key,
				} ) ),
			],
			area: () => [
				{ value: '', label: 'Any district', hint: 'See every district', icon: 'pin' },
				...catalog.areas.map( ( item ) => ( {
					value: item.key,
					label: item.label,
					hint: item.cents > 0 ? `${ moneyShort( item.cents ) } travel, fictional` : 'No travel fee',
					icon: 'pin',
				} ) ),
			],
			size: () => [
				{ value: '', label: 'Any size', hint: 'Decide on the service page', icon: 'home' },
				...catalog.sizes.map( ( item ) => ( { value: item.key, label: item.label, hint: item.multiplier === 100 ? 'Base price' : `Base price x ${ ( item.multiplier / 100 ).toFixed( 1 ) }`, icon: 'home' } ) ),
			],
		};

		function valueText( seg ) {
			const native = seg.querySelector( '.qhs-search__native' );
			const field = seg.dataset.field;
			if ( field === 'date' ) {
				return native.value ? formatDay( native.value ) : 'Any date';
			}
			return native.options[ native.selectedIndex ].text;
		}

		function close( returnFocus = true ) {
			if ( ! openSeg ) {
				return;
			}
			const seg = openSeg;
			openSeg = null;
			seg.classList.remove( 'is-active' );
			form.classList.remove( 'has-active' );
			const pop = seg.querySelector( '.qhs-pop' );
			if ( pop ) {
				pop.remove();
			}
			const button = seg.querySelector( '.qhs-search__btn' );
			button.setAttribute( 'aria-expanded', 'false' );
			if ( returnFocus ) {
				button.focus();
			}
		}

		function pick( seg, value ) {
			const native = seg.querySelector( '.qhs-search__native' );
			native.value = value;
			seg.querySelector( '.qhs-search__value' ).textContent = valueText( seg );
			seg.classList.toggle( 'has-value', value !== '' );
			close( false );
			const next = segs[ segs.indexOf( seg ) + 1 ];
			if ( next ) {
				open( next );
			} else {
				form.querySelector( '.qhs-search__go' ).focus();
			}
		}

		function panel( seg ) {
			const field = seg.dataset.field;
			const native = seg.querySelector( '.qhs-search__native' );
			const label = seg.querySelector( '.qhs-search__label' ).textContent;
			let body;
			if ( field === 'date' ) {
				const bounds = windowBounds();
				body = el(
					'div',
					{},
					calendar( {
						min: bounds.min,
						max: bounds.max,
						// Teams do not work on Sundays.
						isOpen: ( iso ) => new Date( iso + 'T00:00:00Z' ).getUTCDay() !== 0,
						selected: native.value,
						label: 'Preferred date',
						onSelect: ( iso ) => pick( seg, iso ),
					} ),
					el( 'div', { class: 'qhs-pop__foot' }, el( 'button', { type: 'button', class: 'qhs-link', onClick: () => pick( seg, '' ) }, 'Any date' ) )
				);
			} else {
				body = el(
					'ul',
					{ class: 'qhs-pop__list' },
					optionsFor[ field ]().map( ( option ) =>
						el(
							'li',
							{},
							el(
								'button',
								{
									type: 'button',
									class: 'qhs-pop__option' + ( option.value === native.value ? ' is-selected' : '' ),
									'aria-pressed': option.value === native.value ? 'true' : 'false',
									'data-testid': `search-${ field }-${ option.value || 'any' }`,
									onClick: () => pick( seg, option.value ),
								},
								el( 'span', { class: 'qhs-pop__icon' }, icon( option.icon ) ),
								el( 'span', { class: 'qhs-pop__text' }, el( 'strong', {}, option.label ), el( 'span', {}, option.hint ) )
							)
						)
					)
				);
			}
			return el( 'div', { class: 'qhs-pop', role: 'dialog', 'aria-label': label, id: `qhs-pop-${ field }` }, body );
		}

		function open( seg ) {
			if ( openSeg === seg ) {
				close();
				return;
			}
			close( false );
			openSeg = seg;
			seg.classList.add( 'is-active' );
			form.classList.add( 'has-active' );
			const pop = panel( seg );
			seg.append( pop );
			seg.querySelector( '.qhs-search__btn' ).setAttribute( 'aria-expanded', 'true' );
			const first = pop.querySelector( '.is-selected, [tabindex="0"], button:not([disabled])' );
			if ( first ) {
				first.focus();
			}
		}

		segs.forEach( ( seg ) => {
			const label = seg.querySelector( '.qhs-search__label' );
			const native = seg.querySelector( '.qhs-search__native' );
			native.tabIndex = -1;
			native.setAttribute( 'aria-hidden', 'true' );
			const button = el(
				'button',
				{
					type: 'button',
					class: 'qhs-search__btn',
					'aria-expanded': 'false',
					'aria-controls': `qhs-pop-${ seg.dataset.field }`,
					'data-testid': `search-open-${ seg.dataset.field }`,
					onClick: () => open( seg ),
				},
				el( 'span', { class: 'qhs-search__label' }, label.textContent ),
				el( 'span', { class: 'qhs-search__value' }, valueText( seg ) )
			);
			label.replaceWith( button );
			seg.classList.toggle( 'has-value', native.value !== '' );
			seg.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Escape' && openSeg === seg ) {
					event.stopPropagation();
					close();
				}
			} );
		} );

		// A picked option is already gone from the DOM when this runs, so only real outside clicks close.
		document.addEventListener( 'click', ( event ) => {
			if ( openSeg && event.target.isConnected && ! form.contains( event.target ) ) {
				close( false );
			}
		} );
		form.addEventListener( 'focusout', ( event ) => {
			if ( openSeg && event.relatedTarget && ! openSeg.contains( event.relatedTarget ) && ! form.contains( event.relatedTarget ) ) {
				close( false );
			}
		} );

		// Empty fields stay out of the URL, so links stay short. Back navigation re-enables them.
		form.addEventListener( 'submit', () => {
			form.querySelectorAll( '.qhs-search__native' ).forEach( ( native ) => {
				native.disabled = native.value === '';
			} );
		} );
		window.addEventListener( 'pageshow', () => {
			form.querySelectorAll( '.qhs-search__native' ).forEach( ( native ) => {
				native.disabled = false;
			} );
		} );
	} );
}() );
