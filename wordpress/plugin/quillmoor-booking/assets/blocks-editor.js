( function () {
	const { registerBlockType } = window.wp.blocks;
	const { createElement } = window.wp.element;

	[
		[ 'quillmoor/wizard', 'Quote wizard' ],
		[ 'quillmoor/portal', 'Customer portal' ],
		[ 'quillmoor/staff', 'Staff portal' ],
	].forEach( ( [ name, title ] ) => {
		registerBlockType( name, {
			title,
			edit: () => createElement( 'p', { style: { padding: '24px', border: '1px dashed #888' } }, `${ title } (shown on the live page)` ),
			save: () => null,
		} );
	} );
}() );
