<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * [fact_card] — lets an editor say where the card goes.
 *
 * By default the card is added to the very start of the article body, which
 * puts it above a byline when one is typed as the first line. Typing
 * [fact_card] on the line below the byline moves the card there instead.
 *
 * Why a placeholder comment rather than the card itself: shortcodes are
 * expanded at priority 11 on the_content, but the card needs data this
 * filter gathers at priority 20. So the shortcode leaves a marker, and
 * fc_inject_blocks() splits the content on it.
 *
 * Registering it also means a stray [fact_card] on a normal post prints
 * nothing at all, rather than showing as raw text.
 */
const FC_CARD_MARKER = '<!--fc-card-here-->';

add_shortcode( 'fact_card', 'fc_card_marker_shortcode' );
function fc_card_marker_shortcode() {
    return FC_CARD_MARKER;
}

// 2. Inject the Blocks
add_filter( 'the_content', 'fc_inject_blocks', 20 );
function fc_inject_blocks( $content ) {

    if ( ! is_singular( 'post' ) || is_admin() || is_feed() ) return $content;

    $post_id = get_the_ID();
    if ( ! fc_is_fact_check( $post_id ) ) return $content;

    // Password-protected and not unlocked yet: show only WordPress's form.
    if ( post_password_required( $post_id ) ) return $content;

    $rating = fc_meta( 'fc_rating', $post_id );
    if ( empty( $rating ) ) return $content;

    $title        = get_the_title( $post_id );
    $author_id    = get_post_field( 'post_author', $post_id );
    $author       = get_the_author_meta( 'display_name', $author_id );
    $author_url   = get_author_posts_url( $author_id );
    $stamp_url    = fc_get_stamp_url( $rating );
    $rating_label = fc_get_rating_label( $rating );
    $claim        = fc_meta( 'fc_claim', $post_id );
    $fact         = fc_meta( 'fc_fact', $post_id );

    $fact_card    = fc_render_fact_card( $post_id, $rating, $rating_label, $claim, $fact );
    $author_box   = fc_build_author_box( $title, $author, $author_url, $stamp_url, $rating_label );
    $wa_banner    = fc_get_whatsapp_banner( $content );

    list( $before, $after ) = fc_split_on_card_marker( $content );

    // Audio player is optional per site. When disabled, no player renders
    // and the article content is returned without the highlight wrapper.
    //
    // The free voice is English only: browsers rarely ship voices for Indian
    // languages, so elsewhere it would mostly not work. A post that already
    // has a premium recording keeps playing it, whatever the language.
    $audio_enabled = get_option( 'fc_enable_audio', '1' ) === '1'
        && ( fc_site_lang() === 'en' || get_post_meta( $post_id, 'fc_audio_url', true ) );

    if ( $audio_enabled ) {
        $audio_player    = fc_build_audio_player( $post_id, $title, $claim, fc_plain_fact( $fact ) );
        $wrapped_content = '<div id="fc-article-body-' . esc_attr( $post_id ) . '" class="fc-article-body">' . $after . '</div>';

        // Anything above the marker — a byline line, typically — stays outside
        // the narration wrapper, so the player doesn't read it aloud before
        // starting the article.
        return $before . $fact_card . $audio_player . $wrapped_content . $author_box . $wa_banner;
    }

    return $before . $fact_card . $after . $author_box . $wa_banner;
}

/**
 * Splits article content at the [fact_card] marker.
 *
 * Returns [ text before the marker, everything after it ]. With no marker,
 * everything lands in the second half and the card sits at the top exactly
 * as it always has.
 *
 * Only the first marker counts; any others are stripped so they can't leave
 * stray comments in the page or split the article twice.
 */
function fc_split_on_card_marker( $content ) {

    if ( strpos( $content, FC_CARD_MARKER ) === false ) {
        return [ '', $content ];
    }

    // wpautop runs before us and may have wrapped the marker in its own
    // paragraph tags. Swallow those too, so no empty <p></p> is left behind.
    $pattern = '/(?:<p>\s*)?' . preg_quote( FC_CARD_MARKER, '/' ) . '(?:\s*<\/p>)?/';

    $parts = preg_split( $pattern, $content, 2 );

    if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
        return [ '', $content ];
    }

    // Drop any further markers from the remainder.
    $after = preg_replace( $pattern, '', $parts[1] );

    return [ $parts[0], $after ];
}

/**
 * Three tones, matching how the site already colours its category tags:
 * red for a flat falsehood, amber for the shades in between, green for
 * the ratings that confirm something.
 */
function fc_rating_tone( $rating ) {
    $r = strtolower( (string) $rating );
    if ( in_array( $r, [ 'insight', 'news' ], true ) )   return 'true';
    if ( in_array( $r, [ 'false', 'altered' ], true ) )  return 'false';
    return 'warn';
}

// ── FACT CARD ─────────────────────────────────────────────────────────────
/**
 * Two pieces: the featured image at full content width, then a findings bar
 * directly beneath it carrying the claim, the verdict and the fact.
 *
 * The bar used to be an overlay sitting on the picture, which clipped the
 * FACT CHECKED stamp burned into the artwork. Nothing covers the image now
 * except the site logo in the corner.
 *
 * Deliberately plain: no screenshot export, no inlined base64 images, no
 * social icons or phone number baked into the graphic. The bar is sized by
 * its text, so it stays compact as long as the claim and fact are written
 * tightly.
 *
 * Where this appears: at the start of the article body by default, or
 * wherever the editor typed [fact_card] — see fc_inject_blocks().
 */
function fc_build_fact_card( $post_id, $rating, $rating_label, $claim, $fact, $logo_url ) {

    // Build the image from the attachment directly rather than with
    // get_the_post_thumbnail().
    //
    // get_the_post_thumbnail() passes its output through the
    // 'post_thumbnail_html' filter on the way out, and blanking that filter
    // is exactly how "hide featured image" plugins work. Using it here meant
    // that hiding the theme's duplicate image also blanked ours, and the card
    // collapsed into its no-image fallback.
    //
    // wp_get_attachment_image() is the inner call that builds the same tag,
    // srcset included, without passing through that filter — so the theme's
    // copy can be hidden while the card keeps its picture.
    //
    // fc_card_photo_id() gives the plain photo, never a stamped featured image.
    $thumb_id = fc_card_photo_id( $post_id );
    $img_html = $thumb_id ? wp_get_attachment_image( $thumb_id, 'full', false, [
        'class'         => 'fc-hero-img',
        'loading'       => 'eager',        // it is the top of the article
        'fetchpriority' => 'high',
    ] ) : '';

    $has_img = ( $img_html !== '' );
    $claim   = trim( (string) $claim );
    $fact    = trim( (string) $fact );

    // Nothing to show at all — don't render an empty box.
    if ( ! $has_img && $claim === '' && $fact === '' ) return '';

    $tone = fc_rating_tone( $rating );

    ob_start();
    ?>
    <style>
    /* Material Design treatment, using Google's own palette values.
       Two tonal rows rather than lines and rules, colour-coded the way a
       reader expects: the claim on a red surface because that is the part
       that did not hold up, the finding on green because that is what is
       true. Colour does the separating, so no borders are needed.

       font-family:inherit keeps the theme's typeface. */
    /* Two separate Material surfaces, not one card: the picture stands on
       its own, then a gap, then the findings. Each has its own corners and
       its own elevation so they read as two objects rather than one block
       split by a line. */
    .fc-hero { background:transparent; margin:0 0 28px; font-family:inherit; }

    .fc-hero-media {
        position:relative; margin:0 0 14px; padding:0; line-height:0;
        border-radius:12px; overflow:hidden;
        box-shadow:0 1px 2px rgba(60,64,67,0.30), 0 1px 3px 1px rgba(60,64,67,0.15);
    }
    .fc-hero-img { width:100%; height:auto; display:block; }

    /* Most news themes add a bottom margin to every image in article
       content. Inside the figure's overflow:hidden that becomes a pale band
       under the photo. Two class names here so these win on specificity
       against a theme's own .entry-content img rule. */
    .fc-hero .fc-hero-media { margin:0 0 14px !important; padding:0 !important; }
    .fc-hero .fc-hero-img,
    .fc-hero .fc-hero-logo { margin:0 !important; padding:0 !important; border-radius:0; }
    .fc-hero .fc-hero-strip { margin:0 !important; }
    .fc-hero .fc-hero-logo { position:absolute; top:18px; left:18px; height:46px !important; width:auto !important; max-width:48% !important; object-fit:contain; filter:drop-shadow(0 1px 6px rgba(0,0,0,0.55)); }

    .fc-hero-strip {
        background:#ffffff; border-radius:12px; overflow:hidden;
        box-shadow:0 1px 2px rgba(60,64,67,0.30), 0 1px 3px 1px rgba(60,64,67,0.15);
    }
    .fc-hero-row { display:flex; gap:18px; align-items:baseline; margin:0; padding:15px 20px; }

    /* Material label: small, medium weight, tracked out. */
    .fc-hero-label { flex:0 0 50px; font-size:11px; font-weight:600; letter-spacing:0.9px; text-transform:uppercase; line-height:1.75; }
    /* Semibold, not heavy — enough weight to carry the card without
       shouting, and it stays legible in Devanagari and Khmer where a
       700 weight starts to fill in the counters. */
    .fc-hero-text { font-size:15px; line-height:1.6; font-weight:600; color:#202124 !important; margin:0; padding:0; }

    /* CLAIM — Google Red tonal surface (red 50), label in red 700. */
    .fc-hero-row-claim { background:#fce8e6; }
    .fc-hero-row-claim .fc-hero-label { color:#c5221f !important; }

    /* FACT — Google Green tonal surface (green 50), label in green 800. */
    .fc-hero-row-fact { background:#e6f4ea; }
    .fc-hero-row-fact .fc-hero-label { color:#137333 !important; }

    /* Verdict — a filled Material chip. Red 600 for a flat falsehood,
       Orange 800 for the shades between, Green 700 for the confirming
       ratings. All straight from the Google palette. */
    .fc-hero-verdict {
        display:inline-block; font-size:11px; font-weight:600; letter-spacing:0.7px;
        text-transform:uppercase; color:#ffffff !important; padding:4px 11px;
        border-radius:8px; margin-right:11px; white-space:nowrap;
    }
    .fc-hero-verdict.fc-v-false { background:#d93025; }
    .fc-hero-verdict.fc-v-warn  { background:#e37400; }
    .fc-hero-verdict.fc-v-true  { background:#1e8e3e; }

    @media (max-width:600px) {
        .fc-hero .fc-hero-media { border-radius:10px; margin-bottom:11px !important; }
        .fc-hero-strip { border-radius:10px; }
        .fc-hero-row { padding:12px 14px; gap:12px; }
        .fc-hero-text { font-size:13.5px; line-height:1.55; }
        .fc-hero-label { flex-basis:40px; font-size:10px; letter-spacing:0.7px; }
        .fc-hero-verdict { font-size:10px; padding:3px 8px; margin-right:8px; }
        .fc-hero .fc-hero-logo { height:32px !important; top:12px; left:12px; }
    }
    </style>

    <div class="fc-hero" data-fc-card>

        <?php if ( $has_img ) : ?>
        <figure class="fc-hero-media">
            <?php
            // Already escaped by WordPress. Nothing is layered over the
            // picture except the logo, so any stamp burned into the artwork
            // stays fully visible.
            echo $img_html;
            ?>

            <?php if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" class="fc-hero-logo" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
            <?php endif; ?>
        </figure>
        <?php endif; ?>

        <?php if ( $claim !== '' || $fact !== '' ) : ?>
        <div class="fc-hero-strip">

            <?php /* Divs, not paragraphs: keeps theme paragraph styling out
                     of the bar, and keeps the narrator from ever treating the
                     claim and fact as article text. */ ?>

            <?php if ( $claim !== '' ) : ?>
            <div class="fc-hero-row fc-hero-row-claim">
                <span class="fc-hero-label">Claim</span>
                <span class="fc-hero-text"><?php echo esc_html( $claim ); ?></span>
            </div>
            <?php endif; ?>

            <?php if ( $fact !== '' ) : ?>
            <div class="fc-hero-row fc-hero-row-fact">
                <span class="fc-hero-label">Fact</span>
                <span class="fc-hero-text"><?php if ( $rating_label ) : ?><span class="fc-hero-verdict fc-v-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $rating_label ); ?></span><?php endif; ?><?php echo esc_html( $fact ); ?></span>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

    </div>

    <?php
    return ob_get_clean();
}

// ── AUDIO PLAYER ──────────────────────────────────────────────────────────────
/**
 * Light-themed narration player. Two modes:
 *
 * - Premium (fc_audio_url set): a real <audio> element with seekable
 *   progress, real elapsed/total time, and playback speed.
 * - Free fallback (no MP3): the browser's built-in voice reads the
 *   article paragraph by paragraph, highlighting and auto-scrolling to
 *   whichever one is currently being read. Progress shows as
 *   "paragraph X of Y" since real timing isn't available from the
 *   browser's speech engine.
 *
 * Every colour is either a hard-coded hex value or set via an explicit
 * CSS rule scoped to fc-a- prefixed classes, rather than relying on
 * `currentColor` inheritance — this is deliberate so the controls stay
 * visible regardless of what the active theme's global styles do.
 */
function fc_build_audio_player( $post_id, $title, $claim, $fact ) {
    // Printed in the footer, after the player's markup exists.
    wp_enqueue_script( 'fc-audio-player', FC_PUBLISHER_URL . 'assets/audio-player.js', [], fc_asset_version( 'assets/audio-player.js' ), true );

    $player_id = 'fc-a-' . $post_id;
    $body_id   = 'fc-article-body-' . $post_id;
    $audio_url = get_post_meta( $post_id, 'fc_audio_url', true );

    // Pass the site's language to the browser speech engine. Without a
    // correct lang tag (e.g. hi-IN, mr-IN), Hindi and Marathi text is read
    // with an English voice and comes out as gibberish. get_locale()
    // returns values like "hi_IN"; we convert to the BCP-47 form the
    // Web Speech API expects ("hi-IN").
    $locale     = get_locale();                       // e.g. hi_IN, mr_IN, en_US
    $speech_lang = str_replace( '_', '-', $locale );  // e.g. hi-IN
    // Bare language code too (hi, mr, en) for matching browser voices that
    // only report the short form.
    $lang_short = strtolower( substr( $speech_lang, 0, 2 ) );

    ob_start();
    ?>
    <style>
    .fc-a-wrap { font-family: system-ui, -apple-system, sans-serif; margin: 0 0 28px; }
    .fc-a-heading { font-size: 13px; font-weight: 700; color: #64748b; letter-spacing: 0.3px; margin: 0 0 10px; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; }
    .fc-a-pill {
        display: flex; align-items: center; gap: 10px;
        background: #f3f4f6 !important; border: 1px solid #e5e7eb !important;
        border-radius: 999px; padding: 8px 12px 8px 8px;
    }
    .fc-a-play {
        flex-shrink: 0; width: 38px; height: 38px; border-radius: 50%;
        background: #ffffff !important; border: 1px solid #e2e8f0 !important; cursor: pointer;
        display: flex !important; align-items: center; justify-content: center; padding: 0;
    }
    .fc-a-play svg, .fc-a-mini-play svg { display: block !important; width: 15px; height: 15px; }
    .fc-a-time-text { font-size: 12.5px; color: #334155 !important; white-space: nowrap; font-variant-numeric: tabular-nums; min-width: 40px; }
    .fc-a-track { position: relative; flex: 1; height: 20px; display: flex; align-items: center; cursor: pointer; touch-action: none; }
    .fc-a-track-line { position: relative; width: 100%; height: 4px; background: #d9dce1 !important; border-radius: 4px; }
    .fc-a-fill { position: absolute; left: 0; top: 0; height: 100%; width: 0%; background: #e31b23 !important; border-radius: 4px; }
    /* Speed control sits directly on the bar, always visible. This was the
       control that had disappeared — it is now a permanent pill, never
       hidden inside a popover. */
    .fc-a-speed {
        flex-shrink: 0; font-size: 12.5px; font-weight: 700; color: #334155 !important;
        background: #ffffff !important; border: 1px solid #e2e8f0 !important;
        border-radius: 999px; padding: 6px 12px; cursor: pointer; min-width: 46px; text-align: center;
        font-family: system-ui, -apple-system, sans-serif;
    }
    .fc-a-speed:hover { background: #eef1f5 !important; }
    .fc-article-body p.fc-reading { background: #f1f0ec; box-shadow: inset 3px 0 0 #94a3b8; border-radius: 2px; transition: background 0.3s ease; }
    .fc-a-mini {
        position: fixed; left: 16px; right: 16px; bottom: 16px; z-index: 9999; max-width: 420px; margin: 0 auto;
        background: #ffffff !important; color: #0f172a !important; border: 1px solid #e5e7eb !important;
        border-radius: 999px; padding: 6px 14px 6px 6px; display: flex !important; align-items: center; gap: 10px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.14);
        opacity: 0; transform: translateY(16px); pointer-events: none; transition: opacity 0.25s ease, transform 0.25s ease;
    }
    .fc-a-mini.fc-a-visible { opacity: 1; transform: translateY(0); pointer-events: auto; }
    .fc-a-mini-play { width: 32px; height: 32px; border-radius: 50%; background: #f3f4f6 !important; border: none; cursor: pointer; display: flex !important; align-items: center; justify-content: center; flex-shrink: 0; padding: 0; }
    .fc-a-mini-title { font-size: 12.5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }

    @media (prefers-color-scheme: dark) {
        .fc-a-heading { color: #94a3b8; border-bottom-color: #333; }
        .fc-a-pill { background: #1e293b !important; border-color: #334155 !important; }
        .fc-a-play { background: #0f172a !important; border-color: #334155 !important; }
        .fc-a-play svg path, .fc-a-play svg rect { fill: #f1f5f9 !important; }
        .fc-a-time-text { color: #cbd5e1 !important; }
        .fc-a-track-line { background: #334155 !important; }
        .fc-a-speed { background: #0f172a !important; border-color: #334155 !important; color: #e2e8f0 !important; }
        .fc-a-speed:hover { background: #1a2536 !important; }
        .fc-article-body p.fc-reading { background: rgba(148,163,184,0.16); box-shadow: inset 3px 0 0 #64748b; }
        .fc-a-mini { background: #1e293b !important; color: #f1f5f9 !important; border-color: #334155 !important; }
        .fc-a-mini-play { background: #0f172a !important; }
        .fc-a-mini-play svg path, .fc-a-mini-play svg rect { fill: #f1f5f9 !important; }
    }
    </style>

    <div class="fc-a-wrap">
        <p class="fc-a-heading">Listen to this article</p>
        <div class="fc-a-pill" id="<?php echo esc_attr( $player_id ); ?>" data-fc-player
             data-body-id="<?php echo esc_attr( $body_id ); ?>"
             data-audio-url="<?php echo esc_url( $audio_url ); ?>"
             data-lang="<?php echo esc_attr( $speech_lang ); ?>"
             data-lang-short="<?php echo esc_attr( $lang_short ); ?>">
            <button class="fc-a-play" type="button" aria-label="Play"></button>
            <span class="fc-a-time-text">0:00</span>
            <div class="fc-a-track" role="slider" tabindex="0" aria-label="Seek"><div class="fc-a-track-line"><div class="fc-a-fill"></div></div></div>
            <button class="fc-a-speed" type="button" aria-label="Playback speed">1x</button>
        </div>
    </div>

    <div class="fc-a-mini" id="<?php echo esc_attr( $player_id ); ?>-mini">
        <button class="fc-a-mini-play" type="button" aria-label="Play"></button>
        <span class="fc-a-mini-title"><?php echo esc_html( $title ); ?></span>
    </div>

    <?php
    return ob_get_clean();
}


// ── AUTHOR BOX HTML ─────────────────────────────────────────────────────────────
function fc_build_author_box( $title, $author, $author_url, $stamp_url, $rating_label ) {
    ob_start();
    ?>
    <style>
    /* Author box: locked to a light card with dark text in ALL modes.
       Themes that flip text to white in dark mode were causing white-on-
       white here, so every colour is forced with !important and does not
       react to the OS or theme colour scheme. It stays readable always. */
    .fc-article-card { position:relative; display:flex; align-items:center; background:#ffffff !important; border:1px solid #e2e8f0 !important; border-radius:16px; padding:32px; margin:40px 0; box-shadow:0 10px 40px -10px rgba(0,0,0,0.08); font-family:system-ui,-apple-system,sans-serif; gap:32px; overflow:hidden; transition:transform 0.3s ease, box-shadow 0.3s ease; z-index:1; }
    .fc-article-card:hover { transform:translateY(-2px); box-shadow:0 15px 50px -15px rgba(0,0,0,0.12); }
    .fc-article-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:6px; background:linear-gradient(180deg,#e31b23 0%,#ff4b4b 100%); border-radius:16px 0 0 16px; z-index:2; }
    .fc-article-card::after { content:attr(data-rating); position:absolute; right:-10px; bottom:-25px; font-size:110px; font-weight:900; color:#e31b23; opacity:0.09; text-transform:uppercase; pointer-events:none; user-select:none; z-index:-1; white-space:nowrap; }
    .fc-stamp-wrapper { position:relative; flex-shrink:0; z-index:2; animation:fc-stamp-in 0.5s cubic-bezier(0.175,0.885,0.32,1.275) both; }
    .fc-stamp-wrapper img { width:110px; height:110px; object-fit:contain; filter:drop-shadow(0 8px 16px rgba(227,27,35,0.25)); transition:transform 0.3s cubic-bezier(0.175,0.885,0.32,1.275), filter 0.3s ease; }
    .fc-article-card:hover .fc-stamp-wrapper img { transform:scale(1.08) rotate(-5deg); filter:drop-shadow(0 12px 24px rgba(227,27,35,0.4)) brightness(1.05); }
    .fc-content { display:flex; flex-direction:column; gap:8px; z-index:2; }
    .fc-title { font-size:20px; font-weight:800; color:#0f172a !important; line-height:1.4; margin:0; }
    .fc-author { font-size:15px; color:#64748b !important; margin:0; display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
    .fc-author a { color:#0f172a !important; text-decoration:none; font-weight:700; border-bottom:2px solid transparent; transition:border-color 0.2s ease; }
    .fc-author a:hover { border-bottom-color:#e31b23; }
    .fc-rating-text { font-weight:900; color:#e31b23 !important; text-transform:uppercase; letter-spacing:0.5px; }
    .fc-label { font-weight:700; color:#555 !important; text-transform:uppercase; font-size:13px; letter-spacing:0.5px; margin-right:5px; }
    @keyframes fc-stamp-in { 0% { transform:scale(1.4) rotate(15deg); opacity:0; } 100% { transform:scale(1) rotate(0deg); opacity:1; } }
    @media (max-width:600px) { .fc-article-card { flex-direction:column; text-align:center; padding:24px; gap:20px; } .fc-author { justify-content:center; } .fc-article-card::after { font-size:80px; right:50%; transform:translateX(50%); bottom:10px; } }
    </style>

    <div class="fc-article-card" data-rating="<?php echo esc_attr( $rating_label ); ?>">
        <?php if ( $stamp_url ) : ?>
        <div class="fc-stamp-wrapper">
            <img src="<?php echo esc_url( $stamp_url ); ?>" alt="<?php echo esc_attr( $rating_label ); ?> stamp">
        </div>
        <?php endif; ?>
        <div class="fc-content">
            <h3 class="fc-title"><?php echo esc_html( $title ); ?></h3>
            <p class="fc-author">
                Written By
                <a href="<?php echo esc_url( $author_url ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $author ); ?></a>
                | <span class="fc-label">Result:</span> <span class="fc-rating-text"><?php echo esc_html( $rating_label ); ?></span>
            </p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── WHATSAPP BANNER ────────────────────────────────────────────────────────
/**
 * The banner at the end of the article. Settings choose which one:
 *   card   – the new banner, with this site's number (default)
 *   image  – the old picture banner
 *   custom – banner HTML pasted under Settings
 *   off    – no banner
 * An article with a WhatsApp link already in it (older posts, where the
 * banner was pasted by hand) gets no second one.
 */
function fc_whatsapp_banner_choice() {
    $choice = (string) get_option( 'fc_whatsapp_banner_style', '' );
    if ( $choice === '' ) {
        // Not chosen yet: keep the old on/off switch working.
        return get_option( 'fc_show_whatsapp_banner', '1' ) === '0' ? 'off' : 'card';
    }
    return in_array( $choice, [ 'card', 'image', 'custom', 'off' ], true ) ? $choice : 'card';
}

function fc_get_whatsapp_banner( $content = '' ) {
    $choice = fc_whatsapp_banner_choice();
    if ( $choice === 'off' ) return '';

    if ( preg_match( '#api\.whatsapp\.com/send|wa\.me/#i', (string) $content ) ) return '';

    if ( $choice === 'custom' ) {
        // Cleaned when saved, and again here in case the option was changed
        // some other way. color-scheme:light stops dark themes turning its
        // text white.
        $pasted = trim( fc_sanitize_banner_html( (string) get_option( 'fc_whatsapp_banner_html', '' ), true ) );
        if ( $pasted === '' ) return '';
        return '<div class="fc-whatsapp-banner" style="margin:28px 0; color-scheme:light;">' . $pasted . '</div>';
    }

    $site = fc_site_profile();
    if ( $site['whatsapp_url'] === '' ) return '';

    if ( $choice === 'image' ) {
        if ( $site['banner_image'] === '' ) return '';
        return '<figure class="fc-whatsapp-banner" style="margin:28px 0;">'
            . '<a href="' . esc_url( $site['whatsapp_url'] ) . '" target="_blank" rel="noopener noreferrer">'
            . '<img src="' . esc_url( $site['banner_image'] ) . '" alt="' . esc_attr( 'Send us a message on WhatsApp' ) . '"'
            . ' loading="lazy" decoding="async" style="display:block; width:100%; height:auto; margin:0;">'
            . '</a></figure>';
    }

    return fc_build_whatsapp_card( $site );
}

/** The words on the banner. */
function fc_whatsapp_banner_text( $site ) {
    $text = [
        'title'  => 'Verify videos and images you receive on WhatsApp.',
        'number' => 'Our WhatsApp number:',
        'join'   => $site['join_language'] !== ''
            ? sprintf( 'Send your name and language as %s to join our broadcast.', $site['join_language'] )
            : 'Send us anything you want checked.',
        'button' => 'Send to WhatsApp',
    ];

    /** Filter: change the banner's words, e.g. to translate them. */
    return apply_filters( 'fc_whatsapp_banner_text', $text, $site );
}

/** The new WhatsApp banner: icon, message, number and a button. */
function fc_build_whatsapp_card( $site ) {
    $t    = fc_whatsapp_banner_text( $site );
    $icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.198.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

    ob_start();
    ?>
    <style>
    /* Locked to light colours: themes can't restyle it or flip it in dark mode. */
    .fc-wa-card, .fc-wa-card * { box-sizing:border-box !important; color-scheme:light !important; }
    .fc-wa-card {
        display:flex !important; align-items:center !important; justify-content:space-between !important;
        background:#fff !important; color:#0f172a !important; -webkit-text-fill-color:#0f172a !important;
        border:1px solid #e2e8f0 !important; border-left:5px solid #25d366 !important; border-radius:12px !important;
        padding:18px 20px !important; margin:28px 0 !important; text-decoration:none !important; cursor:pointer !important;
        font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif !important;
        box-shadow:0 4px 6px -1px rgba(0,0,0,.05), 0 2px 4px -1px rgba(0,0,0,.03) !important;
        transition:transform .2s ease, border-color .2s ease !important;
    }
    .fc-wa-card:link, .fc-wa-card:visited, .fc-wa-card:hover, .fc-wa-card:active { color:#0f172a !important; text-decoration:none !important; background:#fff !important; }
    .fc-wa-card:hover { transform:translateY(-2px) !important; border-color:#cbd5e1 !important; border-left-color:#25d366 !important; }
    .fc-wa-card:focus-visible { outline:3px solid rgba(37,211,102,.35) !important; outline-offset:3px !important; }

    .fc-wa-content { display:flex !important; align-items:center !important; gap:15px !important; min-width:0; }
    .fc-wa-icon { display:flex !important; align-items:center !important; justify-content:center !important; width:50px !important; height:50px !important; flex:0 0 50px !important; background:#dcf8c6 !important; border-radius:50% !important; }
    .fc-wa-icon svg { width:28px !important; height:28px !important; }
    .fc-wa-icon path { fill:#128c7e !important; }

    .fc-wa-text { display:flex !important; flex-direction:column !important; gap:5px !important; min-width:0; }
    .fc-wa-title { display:block !important; margin:0 !important; font-size:16px !important; font-weight:700 !important; line-height:1.35 !important; color:#0f172a !important; -webkit-text-fill-color:#0f172a !important; }
    .fc-wa-desc { display:block !important; margin:0 !important; font-size:14px !important; line-height:1.55 !important; color:#475569 !important; -webkit-text-fill-color:#475569 !important; }
    .fc-wa-number { display:inline-block !important; background:#dcf8c6 !important; color:#128c7e !important; -webkit-text-fill-color:#128c7e !important; padding:2px 7px !important; margin:0 3px !important; border-radius:5px !important; font-size:13px !important; font-weight:800 !important; letter-spacing:.3px !important; white-space:nowrap !important; }

    .fc-wa-cta { display:flex !important; align-items:center !important; justify-content:center !important; gap:7px !important; margin-left:20px !important; padding:11px 18px !important; background:#25d366 !important; color:#fff !important; -webkit-text-fill-color:#fff !important; border-radius:999px !important; font-size:14px !important; font-weight:700 !important; white-space:nowrap !important; animation:fc-wa-pulse 2.5s infinite !important; }
    .fc-wa-cta svg { width:17px !important; height:17px !important; }
    .fc-wa-cta path { fill:#fff !important; }
    .fc-wa-card:hover .fc-wa-cta { background:#128c7e !important; animation:none !important; }

    @keyframes fc-wa-pulse { 0% { box-shadow:0 0 0 0 rgba(37,211,102,.45); } 70% { box-shadow:0 0 0 9px rgba(37,211,102,0); } 100% { box-shadow:0 0 0 0 rgba(37,211,102,0); } }
    @media (prefers-reduced-motion: reduce) { .fc-wa-card, .fc-wa-cta { animation:none !important; transition:none !important; } }

    @media (max-width: 650px) {
        .fc-wa-card { flex-direction:column !important; text-align:center !important; padding:22px 18px !important; gap:18px !important; border-left:1px solid #e2e8f0 !important; border-top:5px solid #25d366 !important; }
        .fc-wa-card:hover { border-left-color:#cbd5e1 !important; border-top-color:#25d366 !important; }
        .fc-wa-content { flex-direction:column !important; gap:13px !important; }
        .fc-wa-text { align-items:center !important; }
        .fc-wa-desc { font-size:13.5px !important; }
        .fc-wa-number { margin:3px 0 !important; }
        .fc-wa-cta { width:100% !important; margin-left:0 !important; padding:12px 18px !important; }
    }
    </style>

    <?php /* Spans, not headings or paragraphs: valid inside a link, and kept out of the article's outline. */ ?>
    <a class="fc-wa-card fc-whatsapp-banner" href="<?php echo esc_url( $site['whatsapp_url'] ); ?>" target="_blank" rel="noopener noreferrer">
        <span class="fc-wa-content">
            <span class="fc-wa-icon"><?php echo $icon; // Fixed markup. ?></span>
            <span class="fc-wa-text">
                <span class="fc-wa-title"><?php echo esc_html( $t['title'] ); ?></span>
                <span class="fc-wa-desc">
                    <?php echo esc_html( $t['number'] ); ?>
                    <span class="fc-wa-number"><?php echo esc_html( $site['whatsapp_number'] ); ?></span><br>
                    <?php echo esc_html( $t['join'] ); ?>
                </span>
            </span>
        </span>
        <span class="fc-wa-cta"><?php echo $icon; // Fixed markup. ?><?php echo esc_html( $t['button'] ); ?></span>
    </a>
    <?php
    return ob_get_clean();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
/**
 * Rating stamps now ship inside the plugin, in assets/stamps/.
 *
 * They used to be loaded live from factcrescendo.com, which meant every
 * edition depended on that one site staying up and on that uploads folder
 * never being reorganised. If either had changed, stamps would have broken
 * across all editions at the same moment. Serving them from each site's own
 * copy of the plugin removes that shared point of failure, and they arrive
 * from the same domain as the page.
 *
 * The fc_stamp_map filter still works exactly as before, so a site that
 * wants its own artwork can keep overriding these.
 */
function fc_get_stamp_url( $rating ) {
    $base = FC_PUBLISHER_URL . 'assets/stamps/';

    $default_map = [
        'false'           => $base . 'False.png',
        'partly-false'    => $base . 'Partly-False.png',
        'misleading'      => $base . 'Misleading.png',
        'missing-context' => $base . 'Missing-Context.png',
        'satire'          => $base . 'Satire.png',
        'altered'         => $base . 'Altered.png',
        'insight'         => $base . 'Insight.png',
        'news'            => $base . 'News.png',
    ];
    $stamp_map = apply_filters( 'fc_stamp_map', $default_map );
    return $stamp_map[ strtolower( (string) $rating ) ] ?? '';
}

function fc_get_rating_label( $rating ) {
    $labels = fc_rating_choices();
    $key = strtolower( (string) $rating );

    if ( isset( $labels[ $key ] ) ) return $labels[ $key ];

    /*
     * An unrecognised rating. The editor's dropdown only offers the eight
     * above, but fc_rating is registered as post meta, and anything with
     * edit_posts can write arbitrary text to it through the REST meta
     * endpoint — the dropdown is not a guarantee about what is stored.
     *
     * This label ends up in the ClaimReview markup, so it is stripped of
     * tags and capped here rather than passed through untouched. Every HTML
     * use of it is escaped at the point of output as well.
     */
    $label = wp_strip_all_tags( (string) $rating );
    $label = trim( preg_replace( '/\s+/', ' ', $label ) );

    if ( function_exists( 'mb_substr' ) && mb_strlen( $label ) > 60 ) {
        $label = mb_substr( $label, 0, 60 );
    }

    return ucfirst( $label );
}

// ── GLOBAL ARTICLE SPACING FIX ────────────────────────────────────────────────
add_action( 'wp_head', 'fc_global_content_spacing' );

function fc_global_content_spacing() {
    // Only apply this to single articles, not the homepage
    if ( ! is_singular( 'post' ) ) return;
    ?>
    <style>
        /* Spacing below images, figures and embeds written by the editor.
           The fact cards are left out: a margin inside their rounded photo
           frame shows as a pale band under the picture. */
        .entry-content figure:not(.fc-hero-media):not(.fc-fc-photo):not(.fc-own-card),
        .entry-content iframe,
        .entry-content img:not(.fc-hero-img):not(.fc-hero-logo):not(.fc-fc-img):not(.fc-fc-logo):not(.fc-own-card-img),
        .entry-content .wp-block-embed,
        .post-content figure:not(.fc-hero-media):not(.fc-fc-photo):not(.fc-own-card),
        .post-content iframe,
        .post-content img:not(.fc-hero-img):not(.fc-hero-logo):not(.fc-fc-img):not(.fc-fc-logo):not(.fc-own-card-img),
        .post-content .wp-block-embed {
            margin-bottom: 16px !important;
        }
        
        /* Hide any manual empty paragraphs people accidentally created before */
        .entry-content p:empty, 
        .post-content p:empty {
            display: none !important;
        }
    </style>
    <?php
}

