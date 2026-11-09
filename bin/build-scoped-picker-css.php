<?php
/**
 * Regenerate public/css/intl-tel-input-scoped.css from the vendored
 * intl-tel-input stylesheet. Run after every upgrade of
 * public/lib/intl-tel-input:
 *
 *     php bin/build-scoped-picker-css.php
 *
 * Why: upstream's selectors are bare — `.iti__flag`, `.iti__country-list` —
 * and so are those of every other release. A site that already runs its own
 * copy of the picker (a theme snippet loading 18.x from a CDN, WPForms Pro's
 * Smart phone field) had *its* picker restyled by ours: our sprite behind its
 * flag offsets, so every flag was wrong, on every page our stylesheet loaded.
 *
 * The generated sheet is upstream's, with every top-level selector limited to
 * our own picker — the element carrying .send4-iti, or anything inside it (and
 * the library's measuring box, see send4_scoped_css_selector()). The
 * vendored file stays byte for byte as published; this is derived from it, and
 * tests/Unit/PhoneScopedCssTest.php fails when the two drift apart.
 *
 * @package Send4
 */

if ( 'cli' !== PHP_SAPI && ! defined( 'SEND4_SCOPED_CSS_AS_LIBRARY' ) ) {
	exit( 1 );
}

/**
 * Split a stylesheet (or a block's contents) into its top-level statements.
 *
 * @param string $css CSS without comments.
 * @return array<int,array{prelude:string,body:?string}> body is null for a statement with no block.
 */
function send4_scoped_css_statements( $css ) {
	$statements = array();
	$length     = strlen( $css );
	$depth      = 0;
	$start      = 0;
	$open       = 0;
	$quote      = '';

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $css[ $i ];

		if ( '' !== $quote ) {
			if ( '\\' === $char ) {
				++$i;
			} elseif ( $char === $quote ) {
				$quote = '';
			}

			continue;
		}

		if ( '"' === $char || "'" === $char ) {
			$quote = $char;
		} elseif ( '{' === $char ) {
			if ( 0 === $depth ) {
				$open = $i;
			}

			++$depth;
		} elseif ( '}' === $char ) {
			--$depth;

			if ( 0 === $depth ) {
				$statements[] = array(
					'prelude' => trim( substr( $css, $start, $open - $start ) ),
					'body'    => substr( $css, $open + 1, $i - $open - 1 ),
				);
				$start        = $i + 1;
			}
		} elseif ( ';' === $char && 0 === $depth ) {
			$statements[] = array(
				'prelude' => trim( substr( $css, $start, $i - $start ) ),
				'body'    => null,
			);
			$start        = $i + 1;
		}
	}

	return $statements;
}

/**
 * Limit one selector to our picker.
 *
 * The condition goes on the selector's first compound, because that is the
 * outermost element it names: `.iti__country-list .iti__flag` becomes
 * `.iti__country-list:is(…) .iti__flag`. On the container itself as well as
 * inside it, since upstream's `.iti` and `.iti--fullscreen-popup` are classes
 * of the very element that carries .send4-iti.
 *
 * @param string $selector One selector, no commas.
 * @return string
 */
function send4_scoped_css_selector( $selector ) {
	$selector = trim( $selector );

	// Upstream keeps its custom properties on :root, where another release's
	// would overwrite them. Ours live on the picker, and on the measuring box
	// described below.
	if ( ':root' === $selector ) {
		return '.send4-iti,:where(body>.iti.iti--inline-country-selector)';
	}

	// The last two are the library's own measuring box: on first open it moves
	// the dropdown into a throwaway `div.iti.iti--inline-country-selector` on
	// <body>, without our container class, and pins the height it reads there.
	// Left out of the scope, the list is measured unstyled — all 244 rows, a
	// dropdown ten thousand pixels tall.
	//
	// Inside :where() so it adds no specificity. :is() weighs as much as its
	// heaviest argument, and `body>.iti.iti--… *` would have lifted every rule
	// here above the overrides in phone-field.css.
	$scope  = ':is(.send4-iti,.send4-iti *,:where(body>.iti.iti--inline-country-selector,body>.iti.iti--inline-country-selector *))';
	$length = strlen( $selector );
	$depth  = 0;
	$end    = $length;

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $selector[ $i ];

		if ( '(' === $char || '[' === $char ) {
			++$depth;
		} elseif ( ')' === $char || ']' === $char ) {
			--$depth;
		} elseif ( 0 === $depth && ( ' ' === $char || '>' === $char || '+' === $char || '~' === $char ) ) {
			$end = $i;
			break;
		}
	}

	$first = substr( $selector, 0, $end );
	$rest  = substr( $selector, $end );

	// A pseudo-element has to stay last in its compound.
	$pseudo = strpos( $first, '::' );

	if ( false !== $pseudo ) {
		return substr( $first, 0, $pseudo ) . $scope . substr( $first, $pseudo ) . $rest;
	}

	return $first . $scope . $rest;
}

/**
 * Scope every top-level rule, descending into conditional group rules. What
 * is nested inside a rule is relative to it, and so already scoped.
 *
 * @param string $css CSS without comments.
 * @return string
 */
function send4_scoped_css_block( $css ) {
	$out = '';

	foreach ( send4_scoped_css_statements( $css ) as $statement ) {
		$prelude = (string) preg_replace( '/\s+/', ' ', $statement['prelude'] );

		if ( null === $statement['body'] ) {
			$out .= $prelude . ";\n";
			continue;
		}

		$body = trim( (string) preg_replace( '/\s+/', ' ', $statement['body'] ) );

		if ( 1 === preg_match( '/^@(media|supports|layer|container)\b/', $prelude ) ) {
			$out .= $prelude . "{\n" . send4_scoped_css_block( $statement['body'] ) . "}\n";
			continue;
		}

		// @keyframes, @position-try, @font-face: names, not selectors.
		if ( '@' === $prelude[0] ) {
			$out .= $prelude . '{' . $body . "}\n";
			continue;
		}

		$selectors = array_map( 'send4_scoped_css_selector', explode( ',', $prelude ) );
		$out      .= implode( ',', $selectors ) . '{' . $body . "}\n";
	}

	return $out;
}

/**
 * The stylesheet, as a string.
 *
 * @param string $root Plugin root.
 * @return string
 */
function send4_build_scoped_picker_css( $root ) {
	$source = file_get_contents( $root . '/public/lib/intl-tel-input/css/intlTelInput.css' );

	if ( false === $source ) {
		throw new RuntimeException( 'Cannot read the vendored intlTelInput.css.' );
	}

	$source = (string) preg_replace( '#/\*.*?\*/#s', '', $source );

	// The generated file sits in public/css/, not beside the sprites.
	$source = str_replace( 'url(../img/', 'url(../lib/intl-tel-input/img/', $source, $rewritten );

	if ( 2 !== $rewritten ) {
		throw new RuntimeException( 'Expected two sprite URLs in the vendored stylesheet, found ' . $rewritten . '.' );
	}

	// Shipped as flags-2x.webp: WordPress.org's Plugin Check refuses a file
	// name with an "@" in it (badly_named_files), and that is an error that
	// blocks a submission. Same bytes, other name; see the library README.
	$source = str_replace( 'img/flags@2x.webp', 'img/flags-2x.webp', $source, $renamed );

	if ( 1 !== $renamed ) {
		throw new RuntimeException( 'Expected one flags@2x.webp in the vendored stylesheet, found ' . $renamed . '.' );
	}

	return "/* GENERATED by bin/build-scoped-picker-css.php from the vendored intl-tel-input. Do not edit. */\n"
		. send4_scoped_css_block( $source );
}

if ( ! defined( 'SEND4_SCOPED_CSS_AS_LIBRARY' ) ) {
	$send4_root = dirname( __DIR__ );

	file_put_contents( $send4_root . '/public/css/intl-tel-input-scoped.css', send4_build_scoped_picker_css( $send4_root ) );
	echo "Wrote public/css/intl-tel-input-scoped.css\n";
}
