# Performance Engineering — Technical Deep Dive

This is the detailed version of the [README](../README.md) summary: what was actually broken, what was built to fix it, and — for the hardest bug — the real diagnostic path, not just the fix.

All numbers below are from real PageSpeed Insights runs (Lighthouse, Moto G Power emulation, Slow 4G throttling), not lab estimates or the hosting dashboard's own internal score.

## Baseline

Pre-fix, mobile: **Performance 84**, **CLS 0.281** ("poor" — the passing threshold is 0.1). The PSI report's "Layout shift culprits" panel named one element — the hero content block — shifting *twice* in a single page load (0.142 + 0.139), which turned out to be the key clue for everything below.

## 1. Replacing auto-generated critical CSS with a hand-authored, staleness-checked version

The hosting platform's own "critical CSS" feature works by crawling the live page and snapshotting whatever CSS renders above the fold. That's convenient until the crawl itself is wrong — which happened once already on this site: a broken snapshot missing the entire hero section shipped live and spiked CLS to 0.714 sitewide.

The fix was to stop relying on an automatic crawl and hand-author the critical CSS as a PHP string, inlined via `wp_head` at priority 1 (the earliest point available), built and maintained alongside the real stylesheet rather than derived from it after the fact.

The risk with hand-authored critical CSS is drift: someone changes a selector in the real stylesheet and forgets the critical-CSS copy, and now the two disagree in a way nothing catches until a user's Lighthouse score quietly gets worse. The fix carries its own staleness guard — a function that:

1. Normalizes both the real stylesheet and the critical-CSS string (strip comments, collapse whitespace) so formatting differences don't produce false positives.
2. Extracts each tracked selector's declaration block from both and diffs them.
3. Separately checks that each tracked selector's class still appears somewhere in the actual theme templates — a selector that no longer matches any markup is also a kind of staleness (dead weight, not a visual bug, but worth flagging).
4. Surfaces a wp-admin notice if anything disagrees, visible only to someone logged into the dashboard — not a user-facing error, just a "come check this" flag for whoever's in the code next.

See [`snippets/critical-css-staleness-check.php`](../snippets/critical-css-staleness-check.php) for the shape of this (reconstructed for clarity — see the note at the top of that file).

## 2. Font loading: self-hosted + metric-matched fallbacks

Two separate problems, two separate fixes:

- **Self-hosting the variable font** removes a third-party DNS lookup + connection + download round trip that a `<link>` to an external font host would otherwise add to the critical path.
- **Metric-matched fallback faces** solve a different problem: even a fast-loading web font still has to *arrive* before it can render, and the browser shows a fallback system font in the meantime. If that fallback font has different character widths/line-height than the real font, the moment the real font swaps in, every line of text reflows — a classic, very common CLS cause that has nothing to do with how fast the font loads.

The fix declares `@font-face` fallback entries with `ascent-override` / `descent-override` / `line-gap-override` tuned to match the real font's metrics (the same idea as the Capsize/Fontaine approach), so the fallback occupies the *exact* box the real font will. The swap becomes visually invisible instead of a layout jump.

See [`snippets/font-fallback-metrics.css`](../snippets/font-fallback-metrics.css).

## 3. Conditional asset loading

WooCommerce and Jetpack both register a meaningful amount of CSS/JS globally by default — cart fragments, checkout validation, block styles — none of which a visitor reading a blog post or the homepage needs. Those assets are now dequeued outside the pages that actually use them (cart, checkout, account, shop), checked with WooCommerce's own conditional tags rather than a URL-pattern guess. Same logic for the site's own AI chat widget and on-scroll animation library: deferred until first user interaction, not loaded eagerly on every page view.

## 4. The CLS regression: two systems fighting over the same element

This is the one worth walking through in full, because the fix wasn't obvious and the first explanation considered was wrong.

**Symptom:** a real PSI run on the live, already-"fixed" site still showed CLS 0.281 — "poor" — with the hero content block shifting twice. The number 0.142 in the shift report was suspicious: it was the *exact* value a previous fix (switching `100vh` to `100svh` in the hero, to stop the layout recalculating every time the mobile browser's URL bar animates) had already resolved once. Re-checking confirmed that fix was still correctly in place, byte-identical in both the stylesheet and the inlined critical CSS — so something *else*, in parallel, was now causing an equivalent second shift.

**Root cause:** the hosting platform's own critical-CSS feature — the one this theme's hand-authored system was built specifically to replace — had been silently re-enabled (most likely reset by a plugin update) and had regenerated a fresh snapshot for the homepage three minutes before the PSI capture that caught it. Two independent critical-CSS mechanisms were now running in parallel, both targeting the same hero section, each completing its own async stylesheet swap and triggering its own reflow — which explained both the *duplicate* shift and its near-identical magnitude to the already-fixed bug.

**Fix:** disabled the hosting platform's critical-CSS toggle, confirmed off via a hard reload (checking the actual underlying state, not just the visual toggle position).

**Verification:** a fresh real-device PSI run the same day: CLS 0.002 ("good"), Performance 96 mobile / 97 desktop, Accessibility/Best Practices/SEO all 100. Not claimed as fixed because the toggle was off and nothing looked broken — claimed fixed because a second, independent real-world measurement agreed.

## 5. Selective render-blocking defers, matched by content not filename

Two more render-blocking resources turned up in the same investigation, each handled differently based on what it actually was:

- One combined CSS bundle (from the hosting platform's CSS-combine feature) was, on inspection, genuinely above-the-fold CSS (hero/header/nav/fonts) mixed with unrelated plugin styles, with no clean way to split the two. Deliberately left alone rather than deferred blind — deferring real above-the-fold CSS is exactly how the original CLS bug happened in the first place.
- A second bundle, inspected the same way, turned out to be WooCommerce's block-editor CSS — unused on any page outside the shop — and was safe to defer. The defer rule matches it by **content fingerprint**, not filename, because the bundler's own output filenames rotate every time its cache regenerates; a hardcoded filename would have silently stopped matching on the next cache clear.
- Separately, a third-party chat widget's own CSS turned out to be the single longest link in the page's network dependency chain — not because it was large, but because the widget loads it via client-side JavaScript, which is invisible to the browser's early preloader and to any `<link>`-rewriting defer logic (there's no tag to rewrite yet when the page first parses). Fixed with an explicit, server-rendered `<link rel="preload">` for that exact URL, with the version number read dynamically from the plugin's own metadata so it can't quietly go stale on a future plugin update.

## 6. Adding a decorative feature without spending the budget just earned

A later request added a full animated SVG "circuit board" background treatment site-wide — a real risk to everything above, since animated, `filter: drop-shadow()`-heavy SVG paths are genuinely expensive to paint. It was built with that risk explicit from the start:

- `position: fixed`, so rendering cost is bounded to one viewport, not tiled across a page's full scroll height.
- Deliberately excluded from the critical CSS path — first paint is a solid background color (matching in both the real stylesheet and the critical CSS), so there's no flash of the old theme and no drop-shadow cost before first paint.
- A lighter animation variant (no secondary jitter/flash effects) on every page except the homepage.
- A `prefers-reduced-motion` media query that disables all of it for anyone with that OS-level accessibility setting.

See [`snippets/circuit-background-performance-safeguards.css`](../snippets/circuit-background-performance-safeguards.css) — this one is the real shipped CSS, not a reconstruction.

## Verification discipline

A pattern worth naming explicitly: more than one "fix" in this project's history looked correct from the dashboard — a toggle showing off, a clean deploy, no console errors — and wasn't actually confirmed until checked against a real PageSpeed Insights run on a real device. The CLS regression above is the clearest example: the admin-visible state said "already fixed," and a real-world measurement is what caught that it wasn't. Every number in this document is from that kind of run, not a lab estimate or an internal score.
