# FactCrescendo Publisher — instructions for Claude Code

A WordPress plugin used on all FactCrescendo sites (Hindi, English, Marathi, Tamil,
Malayalam, Gujarati, Odia, Assamese, Sri Lanka). Editors fill in a "Fact Check"
box on a post; the plugin builds the fact card, stamped featured image, author box,
WhatsApp banner, Google fact-check data and audio player. One copy of the plugin runs
on every site and finds its own settings by web address.

## How to work with the owner
- The owner is learning and is not a developer. Explain in plain, short, non-technical words.
- Give numbered step-by-step instructions whenever the owner has to do something.
- Always end with: current status, and next steps.
- Be honest about problems and risks. Never say "done" without having tested it.
- Ask before anything hard to undo: pushing, tagging, deleting files, changing GitHub.

## Project structure
- `factcrescendo-publisher.php` — starts the plugin, holds the version number (in TWO places).
- `includes/fields.php` — the Fact Check fields, how they are saved and cleaned.
- `includes/sites.php` — the list of sites: WhatsApp number, language, card number, socials.
- `includes/fact-card.php` — the fact card (the design in the screenshot) and its Download/Share buttons.
- `includes/content-injection.php` — puts card, author box, banner into the article; stamps; WhatsApp banner.
- `includes/featured-image.php` — makes the stamped featured image (keeps the plain photo too).
- `includes/embeds.php` — links on their own line (and embed code pasted as text) become players: Archive.org, Awesome Screenshot, Instagram, Facebook, Vimeo, YouTube, X, our own picture links. Never changes posts published before it arrived.
- `includes/seo-schema.php` — Google ClaimReview data.
- `includes/rest-api.php` — read-only public data endpoints.
- `includes/settings-page.php` — Settings > FC Publisher.
- `includes/updater.php` — update prompts straight from GitHub.
- `includes/audio-generator.php` — paid narration, switched OFF, not loaded, left out of release zips.
- `assets/` — scripts (`audio-player.js`, `card-share.js`, `editor-panel.js`), fonts, logos, `stamps/`, `featured/`, `vendor/`.
- `tests/` — automated checks (`sh tests/run.sh`). `RELEASING.md` — how to publish an update.

## Coding standards
- PHP 7.4 must keep working (no PHP 8-only features). WordPress 6.0+.
- Match the surrounding code: same naming, comment style and spacing. Every function starts with `fc_`.
- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) exit;`.
- No ACF, no build step, no outside libraries. Fonts, scripts and images ship inside the plugin.
- Card CSS lives inside `fact-card.php`, prefixed `.fc-fc-wrap`, and must survive theme styles
  (themes squash padding on `p` tags — use rules strong enough to win). It must work from phone width up.
- A change to how a post looks must be looked at in WordPress Playground (see Testing), not just run through tests.

## Security rules (never break these)
- Escape everything printed: `esc_html`, `esc_attr`, `esc_url`. Clean everything saved: `sanitize_*`, `wp_kses`.
- Anything that changes data needs a permission check (`current_user_can`) and a nonce.
- Never print or save raw user text as HTML. Never use `eval`, `unserialize` on outside data, or raw SQL without `$wpdb->prepare`.
- Public endpoints return published, non-password-protected posts only.
- Updates may only be downloaded from `lemoonrick/factcrescendo-publisher` on GitHub, and are never automatic.
- Never put passwords, API keys or tokens in the code or in git. Never rename or delete the GitHub repository.

## Testing
1. `sh tests/run.sh` (PHP 8.2) and `sh tests/run.sh 7.4` — all checks must pass. Needs only Node.
2. For anything visual: start WordPress Playground with the plugin mounted
   (`npx @wp-playground/cli@3.1.55 server --mount "$PWD:/wordpress/wp-content/plugins/factcrescendo-publisher" --blueprint <file>`),
   make test posts (fields `fc_is_fact_check`=1, `fc_rating`, `fc_claim`, `fc_fact`, a featured image),
   screenshot at phone, tablet and wide widths, and look at the pictures.
3. Put temporary files in the scratchpad folder, never in the project.

## Releasing (see RELEASING.md)
1. Change the version in both places in `factcrescendo-publisher.php`. Small change = patch bump (4.7.6 -> 4.7.7).
2. Run the tests. Commit. Tag `vX.Y.Z`.
3. Make the zip: `git archive --format=zip --prefix=factcrescendo-publisher/ -o ~/Downloads/factcrescendo-publisher-X.Y.Z.zip vX.Y.Z`
   and delete the older zip from Downloads.
4. Push only when the owner says so: `git push origin main vX.Y.Z`. Sites then show "update available";
   an admin clicks Update on each site. Update 2–3 sites first, check, then the rest.
5. Commit messages end with the Co-Authored-By line Claude Code provides.
