/**
 * Send4 → Forms & Triggers → one form: name the default-country options.
 *
 * The server renders the select with bare country codes so it works with no
 * JavaScript at all. This swaps each code for the country's name in the
 * admin's own language (Intl.DisplayNames ships in every supported browser,
 * so no translated country list has to ship with the plugin) and sorts by it.
 *
 * @package Send4
 */
( function () {
	'use strict';

	var select = document.getElementById( 'send4-phone-default-country' );

	if ( ! select || typeof Intl === 'undefined' || typeof Intl.DisplayNames !== 'function' ) {
		return;
	}

	var names;

	try {
		names = new Intl.DisplayNames( [ select.getAttribute( 'data-send4-locale' ) || 'en', 'en' ], { type: 'region' } );
	} catch ( error ) {
		// An unrecognised locale tag throws; the codes are still usable.
		return;
	}

	var placeholder = select.options[ 0 ];
	var options     = Array.prototype.slice.call( select.options, 1 );

	options.forEach( function ( option ) {
		var code  = option.value.toUpperCase();
		var label = names.of( code );

		// `ac` and `xk` are not ISO 3166-1, and some engines echo the code back.
		option.textContent = label && label !== code ? label + ' (' + code + ')' : code;
	} );

	options.sort( function ( a, b ) {
		return a.textContent.localeCompare( b.textContent );
	} );

	select.textContent = '';
	select.appendChild( placeholder );

	options.forEach( function ( option ) {
		select.appendChild( option );
	} );
}() );
