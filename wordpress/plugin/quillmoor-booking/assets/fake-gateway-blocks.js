( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const data = getSetting( 'qhs_fake_data', {} );
	const label = data.title || 'Fake test gateway';
	const content = createElement( 'p', null, 'Local test mode. No card is taken and no money moves.' );

	registerPaymentMethod( {
		name: 'qhs_fake',
		label: createElement( 'span', null, label ),
		ariaLabel: label,
		content,
		edit: content,
		canMakePayment: () => true,
		supports: { features: data.supports || [ 'products' ] },
	} );
}() );
