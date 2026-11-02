/* Service results: category chips, price and visit-length filters, sorting, all in place. */
( function () {
	const { el, moneyShort, params } = window.qhs;

	document.querySelectorAll( '[data-qhs-results][data-mode="browse"]' ).forEach( ( root ) => {
		const grid = root.querySelector( '[data-qhs-grid]' );
		const cards = Array.from( grid.querySelectorAll( '.qhs-svc' ) );
		const count = root.querySelector( '[data-qhs-count]' );
		const empty = root.querySelector( '[data-qhs-empty]' );
		const sort = root.querySelector( '[data-qhs-sort]' );
		const toggle = root.querySelector( '[data-qhs-filters-toggle]' );
		const panel = root.querySelector( '[data-qhs-filters]' );
		const badge = root.querySelector( '[data-qhs-filter-count]' );
		const chips = Array.from( root.querySelectorAll( '.qhs-chip' ) );
		const areaSuffix = count.textContent.includes( ' in ' ) ? count.textContent.slice( count.textContent.indexOf( ' in ' ) ) : '';

		const prices = cards.map( ( card ) => Number( card.dataset.price ) );
		const floor = Math.floor( Math.min( ...prices ) / 500 ) * 500;
		const ceil = Math.ceil( Math.max( ...prices ) / 500 ) * 500;

		const query = params();
		const state = {
			category: query.get( 'category' ) || '',
			sort: [ 'recommended', 'price-asc', 'price-desc', 'length' ].includes( query.get( 'sort' ) ) ? query.get( 'sort' ) : 'recommended',
			max: Math.min( ceil, Math.max( floor, Number( query.get( 'max' ) ) || ceil ) ),
			length: [ 'short', 'long' ].includes( query.get( 'length' ) ) ? query.get( 'length' ) : '',
		};

		const range = el( 'input', {
			type: 'range',
			id: 'qhs-max',
			min: String( floor ),
			max: String( ceil ),
			step: '500',
			value: String( state.max ),
			'data-testid': 'filter-max',
			onInput: ( event ) => {
				state.max = Number( event.target.value );
				rangeValue.textContent = moneyShort( state.max );
				apply();
			},
		} );
		const rangeValue = el( 'output', { for: 'qhs-max', class: 'qhs-filters__value' }, moneyShort( state.max ) );
		const lengthOptions = [
			[ '', 'Any length' ],
			[ 'short', 'Up to 3 hours' ],
			[ 'long', 'Longer visits' ],
		].map( ( [ value, label ] ) =>
			el(
				'label',
				{ class: 'qhs-radio' },
				el( 'input', {
					type: 'radio',
					name: 'qhs-length',
					value,
					checked: state.length === value,
					'data-testid': `filter-length-${ value || 'any' }`,
					onChange: () => {
						state.length = value;
						apply();
					},
				} ),
				el( 'span', {}, label )
			)
		);
		panel.append(
			el(
				'div',
				{ class: 'qhs-filters__grid' },
				el( 'div', {}, el( 'label', { class: 'qhs-filters__label', for: 'qhs-max' }, 'Highest starting price (fictional)' ), el( 'div', { class: 'qhs-filters__range' }, range, rangeValue ) ),
				el( 'fieldset', {}, el( 'legend', { class: 'qhs-filters__label' }, 'Visit length' ), el( 'div', { class: 'qhs-filters__radios' }, lengthOptions ) )
			),
			el(
				'div',
				{ class: 'qhs-filters__foot' },
				el( 'button', { type: 'button', class: 'qhs-link', 'data-qhs-clear': true, onClick: clear }, 'Clear filters' )
			)
		);

		toggle.hidden = false;
		toggle.addEventListener( 'click', () => {
			const opening = panel.hidden;
			panel.hidden = ! opening;
			toggle.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
		} );
		sort.value = state.sort;
		sort.addEventListener( 'change', () => {
			state.sort = sort.value;
			apply();
		} );
		chips.forEach( ( chip ) => {
			chip.setAttribute( 'role', 'button' );
			chip.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				state.category = chip.dataset.category;
				apply();
			} );
		} );
		root.querySelectorAll( '[data-qhs-clear]' ).forEach( ( button ) => button.addEventListener( 'click', clear ) );

		function clear() {
			state.category = '';
			state.max = ceil;
			state.length = '';
			range.value = String( ceil );
			rangeValue.textContent = moneyShort( ceil );
			panel.querySelector( 'input[name="qhs-length"][value=""]' ).checked = true;
			apply();
		}

		function apply() {
			const visible = cards.filter( ( card ) => {
				const hours = Number( card.dataset.hours );
				const ok =
					( ! state.category || card.dataset.category === state.category ) &&
					Number( card.dataset.price ) <= state.max &&
					( ! state.length || ( state.length === 'short' ? hours <= 3 : hours > 3 ) );
				card.hidden = ! ok;
				return ok;
			} );

			const sorted = [ ...cards ].sort( ( a, b ) => {
				const pa = Number( a.dataset.price );
				const pb = Number( b.dataset.price );
				if ( state.sort === 'price-asc' ) {
					return pa - pb;
				}
				if ( state.sort === 'price-desc' ) {
					return pb - pa;
				}
				if ( state.sort === 'length' ) {
					return Number( a.dataset.hours ) - Number( b.dataset.hours ) || pa - pb;
				}
				return Number( a.dataset.order ) - Number( b.dataset.order );
			} );
			grid.append( ...sorted );

			chips.forEach( ( chip ) => {
				const active = chip.dataset.category === state.category;
				chip.classList.toggle( 'is-active', active );
				chip.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
				chip.removeAttribute( 'aria-current' );
			} );

			count.textContent = `${ visible.length } ${ visible.length === 1 ? 'service' : 'services' }${ areaSuffix }`;
			empty.hidden = visible.length > 0;
			const active = ( state.max < ceil ? 1 : 0 ) + ( state.length ? 1 : 0 );
			badge.hidden = active === 0;
			badge.textContent = String( active );

			const next = params();
			[ [ 'category', state.category ], [ 'sort', state.sort === 'recommended' ? '' : state.sort ], [ 'max', state.max < ceil ? String( state.max ) : '' ], [ 'length', state.length ] ].forEach( ( [ key, value ] ) => {
				if ( value ) {
					next.set( key, value );
				} else {
					next.delete( key );
				}
			} );
			const search = next.toString();
			window.history.replaceState( null, '', window.location.pathname + ( search ? `?${ search }` : '' ) );
			const select = root.querySelector( '.qhs-search select[name="category"]' );
			if ( select ) {
				select.value = state.category;
				const value = select.closest( '.qhs-search__seg' ).querySelector( '.qhs-search__value' );
				if ( value ) {
					value.textContent = select.options[ select.selectedIndex ].text;
				}
			}
		}

		apply();
	} );
}() );
