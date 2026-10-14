# Outview — Core Web Vitals Engineering Case Study

A write-up of the performance engineering behind [outview.co.za](https://outview.co.za), a custom WordPress + WooCommerce theme built from scratch for Outview, a Cape Town digital marketing agency. This repo documents the specific diagnosis-and-fix work that took the live site from a failing Core Web Vitals score to a near-perfect one — with real field measurements, not lab estimates, at every step.

> **Who built this:** [Henfred Pietersen](https://github.com/henfredpietersen) — owner and operator of Outview. Not a formally trained developer; the engineering here was done by directing Claude (Anthropic's AI) through root-cause diagnosis, implementation, and verification against real PageSpeed Insights data, with every fix independently confirmed before being called "done." See [How this was built](#how-this-was-built) below.

---

## Results

Real PageSpeed Insights run, mobile (Moto G Power emulation, Slow 4G, Lighthouse 13.4.1) — before and after the fixes documented in this repo:

| Metric | Before | After | |
|---|---|---|---|
| **Performance** | 84 | **96** | mobile |
| **Performance** | — | **97** | desktop |
| **CLS** (Cumulative Layout Shift) | 0.281 — *poor* | **0.002** — *good* | **140× improvement** |
| **Accessibility** | — | **100** | |
| **Best Practices** | — | **100** | |
| **SEO** | — | **100** | |
| FCP / LCP / TBT | — | 1.4s / 2.0s / 10ms | all in the "good" band |

CLS threshold for a passing score is 0.1 — the pre-fix site was landing at nearly 3× that, almost entirely from one component visibly jumping twice on every page load. Getting it to 0.002 wasn't a config toggle; it was finding two independent systems fighting over the same element (see [the CLS war story](docs/PERFORMANCE-ENGINEERING.md#the-cls-regression-two-systems-fighting-over-the-same-element) below).

---

## What's in this repo

- **[`docs/PERFORMANCE-ENGINEERING.md`](docs/PERFORMANCE-ENGINEERING.md)** — the technical deep dive: what was built, why, and the real debugging story behind the CLS fix.
- **`snippets/`** — representative, standalone excerpts of the actual techniques used (critical-CSS staleness guard, metric-matched font fallbacks, a decorative feature kept off the critical rendering path). These are reconstructed for clarity outside the full theme codebase, not a verbatim file dump — see the note at the top of each file.

---

## The optimizations, at a glance

**Rendering path**
- Hand-authored critical CSS, inlined in `<head>` at the earliest WordPress hook available (`wp_head`, priority 1) — replacing the hosting stack's own auto-generated critical CSS, which had already caused a site-wide CLS spike once before by silently shipping a broken snapshot.
- A **staleness guard**: a wp-admin notice that fires if the critical CSS string and the real stylesheet (or the real template markup) ever drift apart — so "critical CSS is stale" is caught in the admin bar, not by a Lighthouse regression three weeks later.
- Self-hosted variable fonts (no third-party font-request round trip) with **metric-matched fallback faces** — ascent/descent/line-gap overrides tuned so the fallback font occupies the exact box the real font will, so swapping in the web font doesn't shift anything below it.

**Script and asset loading**
- WooCommerce and Jetpack assets conditionally dequeued — cart/checkout/account CSS and JS only load on the pages that actually use them, not site-wide.
- The site's own AI chat widget and on-scroll animations deferred until first interaction.
- A render-blocking combined CSS bundle (produced by the hosting stack's own CSS-combine feature) selectively deferred via a preload-and-swap pattern, matched by **content fingerprint rather than filename** — because the bundler's filenames rotate on every cache regeneration, a hardcoded filename match would have silently broken on the next cache clear.
- A third-party widget's CSS, which was being injected by client-side JavaScript (invisible to the browser's early preloader), fixed with an explicit `<link rel="preload">` emitted server-side at the correct version — computed dynamically from the plugin's own version string, so it can't drift out of sync on a future plugin update.

**Layout stability**
- A mobile CLS regression traced to `100vh` recalculating every time the mobile browser's URL bar animates in and out — fixed by switching to `100svh` (stable viewport height), which doesn't.
- A second, harder regression traced to the *hosting platform's own* critical-CSS feature being silently re-enabled (most likely by a plugin update resetting the toggle) and running in parallel with this theme's hand-built system — two independent mechanisms both swapping in stylesheets for the same hero section, each triggering its own reflow. Root-caused by correlating a settings-table timestamp against the Lighthouse capture time down to the minute, not by guessing.
- A later decorative background feature (an animated SVG treatment) was deliberately built to never touch this budget: pinned off-page-flow (`position: fixed`), excluded from the critical CSS path on purpose, a lighter animation variant on every page except the homepage, and a full `prefers-reduced-motion` fallback.

**Verification discipline**
- Every fix above was confirmed against a **real PageSpeed Insights run on a real device**, not just a clean "no errors" deploy or Lighthouse's own internal cache — the CLS regression specifically was caught *because* a real-device PSI run disagreed with what the admin dashboard implied was already fixed.
- Cache-busting handled via `filemtime()` on the actual asset file rather than a hand-maintained version string, so every deploy invalidates browser caches automatically with no manual bump to forget.

Full detail, including the actual before/after numbers at each stage and the debugging narrative, is in [`docs/PERFORMANCE-ENGINEERING.md`](docs/PERFORMANCE-ENGINEERING.md).

---

## How this was built

Outview's site and its WordPress products are built end-to-end with Claude as the implementation partner — Henfred isn't a professional developer, but set the technical direction, diagnosed what "good" needed to mean (a real CWV threshold, not a vibe), and verified every fix against real PageSpeed Insights data before accepting it as done. Several of the hardest bugs here (the critical-CSS conflict in particular) weren't solved by a first guess — they took root-causing against timestamps and plugin state, a documented wrong turn or two, and re-verification with real field data before being called fixed. That loop — direct, verify, don't trust a clean deploy as proof — is the actual engineering practice this repo is demonstrating, not just the CSS.

## Stack

WordPress · WooCommerce · a custom theme (no parent theme, no page builder) · hand-authored critical CSS · self-hosted variable fonts · PageSpeed Insights / Lighthouse for verification.

## Live site

[outview.co.za](https://outview.co.za)
