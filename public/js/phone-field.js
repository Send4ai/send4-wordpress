/**
 * Send4: a country picker on the phone field a form already has (#0688).
 *
 * Builder-agnostic. The server says, in a JSON block, which inputs to attach
 * to (a scope selector per form copy, an input selector inside it); this file
 * never needs to know which form plugin drew them.
 *
 * ⚠️ The client/server contract. When this runs it guarantees the number is
 * E.164. The server (Send4_Phone_Validator) is the source of truth and
 * enforces a floor; everything accepted here is accepted there, so the two can
 * never disagree in the direction that lets a bad number through. When this
 * does NOT run — JavaScript off, the library blocked, an optimiser eating the
 * listener — the form still submits and the server makes the call.
 *
 * Refusals happen here only for a form whose owner opted in to refusing
 * (target.validate). With just the picker on, a number is tidied, never
 * blocked.
 *
 * @package Send4
 */
( function () {
	'use strict';

	var CONFIG_ID = 'send4-phone-config';
	var MARKER    = 'data-send4-phone';

	var config    = null;
	var lib       = null;
	var states    = [];
	var attached  = new WeakSet();
	var utils     = 'idle';
	var listening = false;
	var scheduled = false;
	var counter   = 0;
	var codes     = null;
	var guarded   = false;

	/**
	 * The configuration the server printed, or null.
	 *
	 * @return {Object|null}
	 */
	function readConfig() {
		var block = document.getElementById( CONFIG_ID );

		if ( ! block ) {
			return null;
		}

		try {
			return JSON.parse( block.textContent );
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Our copy of intl-tel-input, if it loaded intact and is the major this
	 * file was written against. Never window.intlTelInput, which WPForms Pro's
	 * older copy may own.
	 *
	 * @return {Function|null}
	 */
	function library() {
		var candidate = window.send4Iti;

		if ( typeof candidate !== 'function' || typeof candidate.version !== 'string' ) {
			return null;
		}

		return candidate.version.split( '.' )[ 0 ] === String( config.major ) ? candidate : null;
	}

	/**
	 * @return {boolean}
	 */
	function reducedMotion() {
		return typeof window.matchMedia === 'function' && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	/**
	 * The picker's UI strings, from the plugin's own text domain.
	 *
	 * @return {Object}
	 */
	function translations() {
		var i18n = config.i18n || {};

		return {
			selectedCountryAriaLabel: i18n.selectedCountryAriaLabel,
			noCountrySelected: i18n.noCountrySelected,
			countryListAriaLabel: i18n.countryListAriaLabel,
			searchPlaceholder: i18n.searchPlaceholder,
			clearSearchAriaLabel: i18n.clearSearchAriaLabel,
			searchEmptyState: i18n.searchEmptyState,
			searchSummaryAria: function ( count ) {
				if ( count === 0 ) {
					return i18n.searchEmptyState;
				}

				return count === 1 ? i18n.searchOneResult : String( i18n.searchManyResults ).replace( '%d', count );
			}
		};
	}

	/**
	 * Does the input carry a pattern that refuses a formatted number?
	 *
	 * Elementor Pro's tel field ships pattern="[0-9()#&+*-=.]+", which has no
	 * space in it: "+370 600 12345" would fail the browser's own constraint
	 * check before a submit event ever fired. Such an input keeps its number
	 * compact instead. Checked against the pattern itself, not the builder.
	 *
	 * @param {HTMLInputElement} input The phone input.
	 * @return {boolean}
	 */
	function patternRefusesSpaces( input ) {
		var pattern = input.getAttribute( 'pattern' );

		if ( ! pattern ) {
			return false;
		}

		// Compiled the way this browser compiles pattern attributes: with the v
		// flag where it exists, the u flag before that. A pattern that does not
		// compile is ignored by the browser, so it refuses nothing.
		var flags = 'unicodeSets' in RegExp.prototype ? 'v' : 'u';

		try {
			return ! new RegExp( '^(?:' + pattern + ')$', flags ).test( '+370 600 12345' );
		} catch ( error ) {
			return false;
		}
	}

	/**
	 * Options for one input.
	 *
	 * @param {Object}           target Target from the config.
	 * @param {HTMLInputElement} input  The phone input.
	 * @return {Object}
	 */
	function options( target, input ) {
		var compact = patternRefusesSpaces( input );

		return {
			initialCountry: target.country || '',
			// ⚠️ Deliberately not the v29 default. With a separate dial code the
			// input holds only the national part, so if our submit handling
			// never runs — a script calling form.submit(), an optimiser
			// swallowing the listener — the server receives "600 12345" and
			// refuses a perfectly entered lead. With it off, the country code
			// lives in the input itself.
			separateDialCode: false,
			strictMode: true,
			formatAsYouType: ! compact,
			numberDisplayFormat: compact ? 'E164' : 'INTERNATIONAL',
			countrySearch: true,
			// Leave a placeholder the site owner wrote alone.
			placeholderNumberPolicy: 'POLITE',
			countryNameLocale: config.locale || 'en',
			strictRejectAnimation: ! reducedMotion(),
			containerClass: 'send4-iti',
			uiTranslations: translations()
		};
	}

	/**
	 * Attach to every input the config names that is not attached yet.
	 * Idempotent, so it can run on every DOM change.
	 *
	 * @return void
	 */
	function scan() {
		states = states.filter( function ( state ) {
			if ( state.input.isConnected ) {
				return true;
			}

			release( state );

			return false;
		} );

		config.targets.forEach( function ( target ) {
			var scopes;

			// A target added through send4_phone_targets may carry a selector
			// the browser rejects; that must not stop the others.
			try {
				scopes = document.querySelectorAll( target.scope );
			} catch ( error ) {
				return;
			}

			Array.prototype.forEach.call( scopes, function ( scope ) {
				var inputs;

				try {
					inputs = scope.querySelectorAll( target.input );
				} catch ( error ) {
					return;
				}

				Array.prototype.forEach.call( inputs, function ( input ) {
					attach( input, target );
				} );
			} );
		} );
	}

	/**
	 * Initialise the picker on one input.
	 *
	 * @param {HTMLInputElement} input  The phone input.
	 * @param {Object}           target Its target.
	 * @return void
	 */
	function attach( input, target ) {
		if ( ! ( input instanceof HTMLInputElement ) || input.type === 'hidden' || attached.has( input ) ) {
			return;
		}

		attached.add( input );

		// Somebody else's picker is already here — WPForms Pro's Smart phone
		// field brings its own. Two on one input would fight over the value.
		if ( input.hasAttribute( 'data-intl-tel-input-id' ) || input.closest( '.iti' ) ) {
			return;
		}

		var iti;

		try {
			iti = lib( input, options( target, input ) );
		} catch ( error ) {
			return;
		}

		input.setAttribute( MARKER, '' );
		guardGlobal();

		if ( ! input.hasAttribute( 'autocomplete' ) ) {
			input.setAttribute( 'autocomplete', 'tel' );
		}

		// Filling a live region that already exists is announced reliably;
		// inserting a role="alert" node is not.
		var status = document.createElement( 'p' );

		status.id        = 'send4-phone-status-' + ( ++counter );
		status.className = 'send4-phone-error';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );

		( input.closest( '.iti' ) || input ).insertAdjacentElement( 'afterend', status );

		var state = {
			input: input,
			iti: iti,
			target: target,
			status: status,
			compact: patternRefusesSpaces( input ),
			showing: false,
			failedSubmit: false,
			markedInvalid: false,
			previousInvalid: null,
			released: false
		};

		states.push( state );
		describe( state );
		seed( state );

		// The library's own event, fired on the input when the country changes.
		// Every listener checks state.released: an input put back on the page
		// after release() gets a new state, and this one's picker is gone.
		input.addEventListener( 'countrychange', function () {
			if ( ! state.released ) {
				seed( state );
			}
		} );

		input.addEventListener( 'focus', function () {
			if ( ! state.released ) {
				seed( state );
			}
		} );

		input.addEventListener( 'blur', function () {
			if ( ! state.released ) {
				onBlur( state );
			}
		} );

		input.addEventListener( 'input', function () {
			if ( ! state.released ) {
				onInput( state );
			}
		} );

		// Installed only once a picker has initialised, so a broken library
		// can never block a form.
		if ( ! listening ) {
			listening = true;
			window.addEventListener( 'submit', onSubmit, true );
			window.addEventListener( 'reset', function ( event ) {
				if ( event.target instanceof HTMLFormElement ) {
					reseedSoon( event.target );
				}
			}, true );
		}
	}

	/**
	 * Keep another copy of intl-tel-input off an input that already has ours.
	 *
	 * A site may carry its own snippet — `intlTelInput( document.querySelector(
	 * 'input[name=…]' ), … )` on DOMContentLoaded, from a theme or an earlier
	 * developer — aimed at the very field a form's Send4 picker is on. Run after
	 * ours, it wraps the input a second time: two flags, two country codes, one
	 * on top of the other. attach() already declines an input that has somebody
	 * else's picker; this is the other order.
	 *
	 * window.intlTelInput is theirs (the inline scripts around our library keep
	 * ours off it), so it is replaced with a stand-in that forwards every
	 * call and every property to the real one, except a call for an input that
	 * is ours. That call gets a handle on our picker instead, so the rest of
	 * their snippet — reading the number, checking it — goes on working.
	 * A setter, because their library may not have loaded yet.
	 *
	 * @return void
	 */
	function guardGlobal() {
		if ( guarded || typeof window.Proxy !== 'function' ) {
			return;
		}

		guarded = true;

		var current = guard( window.intlTelInput );

		try {
			Object.defineProperty( window, 'intlTelInput', {
				configurable: true,
				enumerable: true,
				get: function () {
					return current;
				},
				set: function ( value ) {
					current = guard( value );
				}
			} );
		} catch ( error ) {
			// Declared with `var` by somebody else's script, so it cannot be
			// redefined. It can still be assigned.
			try {
				window.intlTelInput = current;
			} catch ( ignored ) {
				// Read-only as well. Theirs to keep.
			}
		}
	}

	/**
	 * @param {*} real Whatever is being put on window.intlTelInput.
	 * @return {*} A stand-in for a foreign picker function, anything else as is.
	 */
	function guard( real ) {
		if ( real === lib || ( typeof real === 'function' && real.send4Guard ) ) {
			return real;
		}

		// No other copy on this page, yet the snippet may still run: a theme
		// that loads its library on some pages and its inline script on all of
		// them. For our inputs it gets our picker; for any other it fails the
		// way it did before we were here.
		if ( typeof real !== 'function' ) {
			real = function () {
				throw new TypeError( 'intlTelInput is not loaded on this page.' );
			};
		}

		return new Proxy( real, {
			get: function ( target, property ) {
				return property === 'send4Guard' ? true : target[ property ];
			},
			apply: function ( target, thisArg, args ) {
				var state = stateFor( args[ 0 ] );

				return state ? handle( state ) : target.apply( thisArg, args );
			}
		} );
	}

	/**
	 * @param {*} input Possibly one of our inputs.
	 * @return {Object|null}
	 */
	function stateFor( input ) {
		for ( var i = 0; i < states.length; i++ ) {
			if ( states[ i ].input === input ) {
				return states[ i ];
			}
		}

		return null;
	}

	/**
	 * What a foreign snippet gets back for one of our inputs: our picker, under
	 * the method names older releases used as well. Anything else it might call
	 * does nothing, rather than throwing inside somebody's submit handler.
	 *
	 * @param {Object} state Input state.
	 * @return {Object}
	 */
	function handle( state ) {
		var aliases = {
			getSelectedCountryData: 'getSelectedCountry',
			setCountry: 'setSelectedCountry',
			isPossibleNumber: 'isValidNumber'
		};

		return new Proxy( {}, {
			get: function ( target, property ) {
				var name = aliases[ property ] || property;

				// Theirs to call, not theirs to take down.
				if ( name === 'destroy' || typeof state.iti[ name ] !== 'function' ) {
					return function () {};
				}

				return function () {
					return state.iti[ name ].apply( state.iti, arguments );
				};
			}
		} );
	}

	/**
	 * Every calling code the picker knows, as a lookup.
	 *
	 * @return {Object}
	 */
	function callingCodes() {
		if ( ! codes ) {
			codes = {};

			( lib.getAllCountries ? lib.getAllCountries() : [] ).forEach( function ( country ) {
				codes[ country.dialCode ] = true;
			} );
		}

		return codes;
	}

	/**
	 * Is this a country code with no number after it — the prefill, untouched?
	 *
	 * Exactly a known code, not merely something short: "+37" on its way to
	 * "+370" is the visitor typing, and rewriting it would eat a keystroke.
	 *
	 * @param {string} raw Trimmed input value.
	 * @return {boolean}
	 */
	function isBareCode( raw ) {
		return /^\+[\d\s]*$/.test( raw ) && callingCodes()[ raw.replace( /\D/g, '' ) ] === true;
	}

	/**
	 * Put the selected country's code into an input that holds no number, so
	 * the visitor can see it.
	 *
	 * In the input rather than beside the flag (separateDialCode) for the
	 * reason given in options(): what is in the input is what gets posted.
	 * A bare code counts as empty everywhere — evaluate() here, and
	 * Send4_Normalizer::clean_phone() for a submit this script never saw.
	 *
	 * @param {Object} state Input state.
	 * @return void
	 */
	function seed( state ) {
		var raw = asciiDigits( state.input.value ).trim();

		if ( raw !== '' && ! isBareCode( raw ) ) {
			return;
		}

		var country = state.iti.getSelectedCountry();

		if ( ! country || ! country.dialCode ) {
			return;
		}

		var value = '+' + country.dialCode + ( state.compact ? '' : ' ' );

		// Untouched when already right, so the caret stays where it is.
		if ( state.input.value !== value ) {
			state.input.value = value;
		}
	}

	/**
	 * What the input holds, judged.
	 *
	 * valid is true or false once libphonenumber (utils.js) is loaded, and null
	 * before that: nothing is refused on a check that cannot be performed.
	 *
	 * @param {Object} state Input state.
	 * @return {{empty: boolean, valid: (boolean|null), e164: string}}
	 */
	function evaluate( state ) {
		var raw = asciiDigits( state.input.value ).trim();

		if ( raw === '' || isBareCode( raw ) ) {
			return { empty: true, valid: true, e164: '', bare: raw !== '' };
		}

		if ( utils === 'ready' ) {
			var valid = state.iti.isValidNumber() === true;
			// In the visitor's own numerals: intl-tel-input hands back
			// "+٩٦٦٥٠١٢٣٤٥٦٧" for a number typed in Arabic-Indic digits.
			var e164  = valid ? asciiDigits( state.iti.getNumber( lib.NUMBER_FORMAT.E164 ) ) : '';

			// Belt and braces on the contract: never pass anything the
			// server's floor would refuse.
			if ( valid && ! /^\+\d{7,15}$/.test( e164 ) ) {
				valid = false;
				e164  = '';
			}

			return { empty: false, valid: valid, e164: e164 };
		}

		return { empty: false, valid: null, e164: byHand( raw ) };
	}

	/**
	 * E.164 without libphonenumber: only for a number that already carries its
	 * country code, which is a matter of dropping the spaces.
	 *
	 * ⚠️ A national number is deliberately left as typed. Completing it by hand
	 * from the selected country needs the country's trunk prefixes, and the
	 * picker's core data holds one per country where real numbering has more:
	 * Lithuanian "8 600 12345" became +370860012345, a different person's
	 * number, which the server's floor would have accepted. Left alone, the
	 * server refuses it with a message the visitor can act on — asking beats
	 * guessing wrong.
	 *
	 * @param {string} raw Trimmed input value.
	 * @return {string} The number to send, or '' to leave the input alone.
	 */
	function byHand( raw ) {
		raw = asciiDigits( raw );

		var digits = raw.replace( /\D/g, '' );

		return digits !== '' && /^[^0-9]*\+/.test( raw ) ? '+' + digits : '';
	}

	/**
	 * The same string with Arabic-Indic, Persian and fullwidth digits, and the
	 * fullwidth plus, as their ASCII equivalents.
	 *
	 * A Saudi or Iranian visitor's keyboard types ٥٠١ or ۵۰۱, and a Japanese
	 * IME may type ５０１ and ＋. JavaScript's \d is ASCII only, so without this
	 * a perfectly valid "+٩٦٦ ٥٠ ١٢٣ ٤٥٦٧" failed the E.164 check and could not
	 * be submitted, and what was posted would not have been E.164 either.
	 *
	 * @param {string} value Raw text.
	 * @return {string}
	 */
	function asciiDigits( value ) {
		return String( value ).replace( /[\u0660-\u0669\u06F0-\u06F9\uFF10-\uFF19\uFF0B]/g, function ( character ) {
			var code = character.charCodeAt( 0 );

			if ( code === 0xFF0B ) {
				return '+';
			}

			if ( code >= 0xFF10 ) {
				return String( code - 0xFF10 );
			}

			return String( code - ( code >= 0x06F0 ? 0x06F0 : 0x0660 ) );
		} );
	}

	/**
	 * Take the picker off an input that has left the page.
	 *
	 * intl-tel-input keeps every instance in intlTelInput.instances, with its
	 * listeners and abort controllers, until destroy() is called; dropping only
	 * our state leaked one picker per Gravity Forms AJAX render, Contact Form 7
	 * re-render and Elementor popup reopen. Should the same input come back,
	 * it is no longer marked attached, so the next scan gives it a new one.
	 *
	 * @param {Object} state Input state.
	 * @return void
	 */
	function release( state ) {
		// Our aria-invalid and message first, while the wrapper is still there.
		clear( state );
		state.released = true;

		try {
			state.iti.destroy();
		} catch ( error ) {
			// Already torn down by whoever removed it. Nothing left to free.
		}

		if ( state.status.parentNode ) {
			state.status.parentNode.removeChild( state.status );
		}

		var ids = ( state.input.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( function ( id ) {
			return id !== '' && id !== state.status.id;
		} );

		if ( ids.length ) {
			state.input.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		} else {
			state.input.removeAttribute( 'aria-describedby' );
		}

		state.input.removeAttribute( MARKER );
		attached.delete( state.input );
	}

	/**
	 * Nothing while typing; on leaving the field, put the country code into it
	 * and say whether it will be accepted.
	 *
	 * @param {Object} state Input state.
	 * @return void
	 */
	function onBlur( state ) {
		var result = evaluate( state );

		if ( result.empty ) {
			clear( state );

			return;
		}

		// A national number becomes an international one in the field itself,
		// so it still carries its country code if our submit handling never
		// gets to run.
		if ( result.e164 && result.valid !== false && asciiDigits( state.input.value ).trim().charAt( 0 ) !== '+' ) {
			state.iti.setNumber( result.e164 );
		}

		if ( state.target.validate && result.valid === false ) {
			show( state );
		} else {
			clear( state );
		}
	}

	/**
	 * Once a message is showing, or a submit has failed, keep it current as the
	 * visitor types.
	 *
	 * @param {Object} state Input state.
	 * @return void
	 */
	function onInput( state ) {
		if ( ! state.showing && ! state.failedSubmit ) {
			return;
		}

		var result = evaluate( state );

		if ( result.empty || result.valid !== false ) {
			clear( state );
		} else if ( state.target.validate && state.failedSubmit ) {
			show( state );
		}
	}

	/**
	 * Capture phase on window, ahead of every form plugin's own submit
	 * listener: stopping it here stops all four of them. Not
	 * setCustomValidity(), which Contact Form 7's novalidate would ignore.
	 *
	 * @param {Event} event The submit event.
	 * @return void
	 */
	function onSubmit( event ) {
		var form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		var invalid = null;
		var writes  = [];

		states.forEach( function ( state ) {
			if ( state.input.form !== form ) {
				return;
			}

			var result = evaluate( state );

			if ( result.empty ) {
				clear( state );

				// Post nothing, not "+370": the form plugin's own required
				// rule should see the field as the visitor left it.
				if ( result.bare ) {
					writes.push( [ state, '' ] );
				}

				return;
			}

			if ( state.target.validate && result.valid === false ) {
				// A step the visitor cannot see (Gravity Forms pages, WPForms
				// steps) submits to advance. Blocking on it would deadlock.
				if ( ! isRendered( state.input ) ) {
					return;
				}

				state.failedSubmit = true;
				show( state );
				invalid = invalid || state;

				return;
			}

			clear( state );

			if ( result.e164 ) {
				writes.push( [ state, result.e164 ] );
			}
		} );

		if ( invalid ) {
			event.preventDefault();
			event.stopImmediatePropagation();

			// Gravity Forms marks a form as submitting on the button's click,
			// before this event, and ignores every later click while the mark
			// stands. Lift it, or the visitor could never try again.
			var gravity = /^gform_(\d+)$/.exec( form.id );

			if ( gravity ) {
				window[ 'gf_submitting_' + gravity[ 1 ] ] = false;
			}

			// Focus the input, not the message: aria-describedby reads it out.
			invalid.input.focus();

			return;
		}

		// Only once nothing is refused, so a blocked submit leaves every field
		// as the visitor typed it.
		writes.forEach( function ( write ) {
			write[ 0 ].input.value = write[ 1 ];
		} );

		reseedSoon( form );
	}

	/**
	 * Show the country code again once the form has read its fields: after a
	 * submit that blanked it, and after a reset (Contact Form 7 resets the form
	 * when a message is sent). A timer, because both have to finish first.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return void
	 */
	function reseedSoon( form ) {
		window.setTimeout( function () {
			states.forEach( function ( state ) {
				if ( state.input.form === form && state.input.isConnected ) {
					seed( state );
				}
			} );
		}, 0 );
	}

	/**
	 * @param {HTMLElement} element Element.
	 * @return {boolean}
	 */
	function isRendered( element ) {
		return element.offsetParent !== null || element.getClientRects().length > 0;
	}

	/**
	 * Link the message to the input through aria-describedby.
	 *
	 * Appended, never overwritten: Contact Form 7 and WPForms both keep their
	 * own ids there. And re-checked every time a message shows, because Contact
	 * Form 7's own client-side validation replaces the attribute wholesale.
	 *
	 * @param {Object} state Input state.
	 * @return void
	 */
	function describe( state ) {
		var ids = ( state.input.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );

		if ( ids.indexOf( state.status.id ) === -1 ) {
			ids.push( state.status.id );
			state.input.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		}
	}

	/**
	 * @param {Object} state Input state.
	 * @return void
	 */
	function show( state ) {
		describe( state );
		state.status.textContent = state.target.message;
		state.showing            = true;

		if ( state.input.getAttribute( 'aria-invalid' ) !== 'true' ) {
			state.previousInvalid = state.input.getAttribute( 'aria-invalid' );
			state.input.setAttribute( 'aria-invalid', 'true' );
			state.markedInvalid = true;
		}

		var wrapper = state.input.closest( '.iti' );

		if ( wrapper ) {
			wrapper.classList.add( 'send4-iti--invalid' );
		}
	}

	/**
	 * @param {Object} state Input state.
	 * @return void
	 */
	function clear( state ) {
		if ( ! state.showing ) {
			return;
		}

		state.status.textContent = '';
		state.showing            = false;

		// Only undo what we did, back to what the form plugin had set.
		if ( state.markedInvalid ) {
			if ( state.previousInvalid === null ) {
				state.input.removeAttribute( 'aria-invalid' );
			} else {
				state.input.setAttribute( 'aria-invalid', state.previousInvalid );
			}

			state.markedInvalid = false;
		}

		var wrapper = state.input.closest( '.iti' );

		if ( wrapper ) {
			wrapper.classList.remove( 'send4-iti--invalid' );
		}
	}

	/**
	 * Load libphonenumber (269 KB) only when it is about to be useful. The
	 * picker, formatting and strict mode all work without it; only validation
	 * and E.164 conversion need it.
	 *
	 * @return void
	 */
	function loadUtils() {
		if ( utils !== 'idle' || typeof lib.attachUtils !== 'function' ) {
			return;
		}

		utils = 'loading';

		var url = new URL( config.utilsUrl, document.baseURI ).href;

		try {
			lib.attachUtils( function () {
				return import( url );
			} ).then( function () {
				utils = lib.utils ? 'ready' : 'failed';
			}, function () {
				utils = 'failed';
			} );
		} catch ( error ) {
			utils = 'failed';
		}
	}

	/**
	 * @return void
	 */
	function init() {
		config = readConfig();

		if ( ! config || ! Array.isArray( config.targets ) || config.targets.length === 0 ) {
			return;
		}

		lib = library();

		if ( ! lib ) {
			return;
		}

		scan();

		// One observer covers Elementor popups, Contact Form 7 re-renders,
		// WPForms steps and Gravity Forms' gform_post_render with no
		// builder-specific code. Coalesced to one scan per frame.
		new MutationObserver( function () {
			if ( scheduled ) {
				return;
			}

			scheduled = true;

			window.requestAnimationFrame( function () {
				scheduled = false;
				scan();
			} );
		} ).observe( document.body, { childList: true, subtree: true } );

		document.addEventListener( 'focusin', function ( event ) {
			if ( event.target instanceof Element && event.target.hasAttribute( MARKER ) ) {
				loadUtils();
			}
		} );

		window.setTimeout( function () {
			// With a timeout: without one, a busy or background tab may
			// never report idle, and validation would wait for a focus.
			if ( typeof window.requestIdleCallback === 'function' ) {
				window.requestIdleCallback( loadUtils, { timeout: 3000 } );
			} else {
				loadUtils();
			}
		}, 2000 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
