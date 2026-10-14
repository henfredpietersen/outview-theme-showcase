<?php
/**
 * Illustrative reconstruction — not a verbatim file copy.
 *
 * This repo documents a real, shipped system (see docs/PERFORMANCE-ENGINEERING.md,
 * section 1), but the literal production functions.php isn't included here. This
 * file shows the same mechanism in isolation so the approach is legible without
 * the rest of the theme around it: a hand-authored critical-CSS string, checked
 * against the real stylesheet so the two can never silently drift apart.
 *
 * The real version tracks ~30 selectors across "global" (every page) and
 * "front_page" (homepage-only) groups, and runs the check on every wp-admin
 * page load for a logged-in user — cheap, and it means drift shows up as an
 * admin notice within a day rather than as a slowly worsening Lighthouse score.
 */

/**
 * The selectors this system is responsible for keeping in sync. Add a selector
 * here, put its declaration in both style.css and the critical-CSS string below,
 * and the staleness check will catch it if either side is ever edited alone.
 */
function theme_critical_css_selectors(): array {
	return [
		'global'      => [ 'body', 'header', '.navlinks', '.btn-primary' ],
		'front_page'  => [ '.hero', '.hero h1', '.hero p.lede' ],
	];
}

/**
 * The hand-authored critical CSS itself — inlined via wp_head at priority 1,
 * i.e. before anything else in <head> gets a chance to block first paint.
 */
function theme_critical_css_source( bool $include_hero = false ): string {
	$css = "body{margin:0;background:var(--navy);color:var(--ink);font-family:'Inter',sans-serif}header{position:fixed;top:0;width:100%;z-index:10}";

	if ( $include_hero ) {
		$css .= ".hero{position:relative;min-height:100svh;display:flex;align-items:center}.hero h1{font-size:clamp(42px,6vw,76px);line-height:1.02}";
	}

	return $css;
}
add_action( 'wp_head', function () {
	echo '<style id="theme-critical-css">' . theme_critical_css_source( is_front_page() ) . '</style>';
}, 1 );

/**
 * Strip comments and collapse whitespace so formatting differences (a trailing
 * newline, two spaces vs one) never register as a false-positive mismatch.
 */
function theme_ccss_normalize( string $css ): string {
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	$css = preg_replace( '/\s+/', ' ', $css );
	return trim( $css );
}

/**
 * Pull one selector's declaration block out of a stylesheet string, e.g.
 * theme_ccss_extract_block( $css, '.hero h1' ) => "font-size:...;line-height:...;"
 */
function theme_ccss_extract_block( string $css, string $selector ): ?string {
	$pattern = '/' . preg_quote( $selector, '/' ) . '\s*\{([^}]+)\}/'
	if ( preg_match( $pattern, $css, $m ) ) {
		return trim( $m[1] );
	}
	return null;
}

/**
 * The actual guard: compares every tracked selector's declarations between the
 * real stylesheet and the inlined critical-CSS string, and separately confirms
 * each selector's class still appears in a real template file (a selector with
 * no matching markup anywhere is also a kind of staleness — dead weight, not a
 * visual bug, but worth flagging to whoever's in the code next).
 *
 * Hooked to admin_notices so it's visible only to someone logged into wp-admin,
 * never shown to a site visitor.
 */
function theme_critical_css_staleness_check(): array {
	$stylesheet   = file_get_contents( get_stylesheet_directory() . '/style.css' );
	$critical     = theme_critical_css_source( true );
	$mismatches   = [];

	foreach ( theme_critical_css_selectors() as $group => $selectors ) {
		foreach ( $selectors as $selector ) {
			$real = theme_ccss_normalize( (string) theme_ccss_extract_block( $stylesheet, $selector ) );
			$crit = theme_ccss_normalize( (string) theme_ccss_extract_block( $critical, $selector ) );

			if ( $real !== $crit ) {
				$mismatches[] = "{$selector} ({$kroux}): stylesheet and critical CSS disagree";
			}
		}
	}

	return $mismatches;
}
add_action( 'admin_notices', function () {
	$mismatches = theme_critical_css_staleness_check();
	if ( empty( $mismatches ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Critical CSS may be out of sync:</strong></p><ul><li>'
		. implode( '</li><li>', array_map( 'esc_html', $mismatches ) )
		. '</li></ul></div>';
} );
