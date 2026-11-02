( function () {
	const { registerBlockType } = window.wp.blocks;
	const { createElement } = window.wp.element;

	[
		[ 'quillmoor/search', 'Search bar' ],
		[ 'quillmoor/services', 'Service results' ],
		[ 'quillmoor/service', 'Service page' ],
		[ 'quillmoor/portal', 'Customer portal' ],
		[ 'quillmoor/staff', 'Staff portal' ],
	].forEach( ( [ name, title ] ) => {
		registerBlockType( name, {
			title,
			attributes: { mode: { type: 'string', default: 'browse' } },
			edit: () => createElement( 'p', { style: { padding: '24px', border: '1px dashed #888' } }, `${ title } (shown on the live page)` ),
			save: () => null,
		} );
	} );
}() );
