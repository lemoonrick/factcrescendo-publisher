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
    if ( ! function_exists( 'get_field' ) ) return $content;

    $is_fc = (bool) get_field( 'fc_is_fact_check', $post_id );
    if ( ! $is_fc ) return $content;

    $rating = get_field( 'fc_rating', $post_id );
    if ( empty( $rating ) ) return $content;

    $title        = get_the_title( $post_id );
    $author_id    = get_post_field( 'post_author', $post_id );
    $author       = get_the_author_meta( 'display_name', $author_id );
    $author_url   = get_author_posts_url( $author_id );
    $stamp_url    = fc_get_stamp_url( $rating );
    $rating_label = fc_get_rating_label( $rating );
    $claim        = get_field( 'fc_claim', $post_id );
    $fact         = get_field( 'fc_fact', $post_id );
    $logo_url     = get_option( 'fc_logo_url', '' );

    $fact_card    = fc_build_fact_card( $post_id, $rating, $rating_label, $claim, $fact, $logo_url );
    $author_box   = fc_build_author_box( $title, $author, $author_url, $stamp_url, $rating_label );
    $wa_banner    = fc_get_whatsapp_banner();

    list( $before, $after ) = fc_split_on_card_marker( $content );

    // Audio player is optional per site. When disabled, no player renders
    // and the article content is returned without the highlight wrapper.
    $audio_enabled = get_option( 'fc_enable_audio', '1' ) === '1';

    if ( $audio_enabled ) {
        $audio_player    = fc_build_audio_player( $post_id, $title, $claim, $fact );
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
    $thumb_id = get_post_thumbnail_id( $post_id );
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
    .fc-hero-logo { position:absolute; top:16px; left:16px; height:30px; width:auto; max-width:36%; object-fit:contain; filter:drop-shadow(0 1px 4px rgba(0,0,0,0.35)); }

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
        .fc-hero-media { border-radius:10px; margin-bottom:11px; }
        .fc-hero-strip { border-radius:10px; }
        .fc-hero-row { padding:12px 14px; gap:12px; }
        .fc-hero-text { font-size:13.5px; line-height:1.55; }
        .fc-hero-label { flex-basis:40px; font-size:10px; letter-spacing:0.7px; }
        .fc-hero-verdict { font-size:10px; padding:3px 8px; margin-right:8px; }
        .fc-hero-logo { height:22px; top:11px; left:11px; }
    }
    </style>

    <div class="fc-hero">

        <?php if ( $has_img ) : ?>
        <figure class="fc-hero-media">
            <?php
            // Already escaped by WordPress. Nothing is layered over the
            // picture except the logo, so any stamp burned into the artwork
            // stays fully visible.
            echo $img_html;
            ?>

            <?php if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" class="fc-hero-logo" alt="">
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
        <div class="fc-a-pill" id="<?php echo esc_attr( $player_id ); ?>"
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

    <script>
    (function() {
        try {
            var ICON_PLAY    = '<svg viewBox="0 0 24 24"><path fill="#0f172a" d="M8 5v14l11-7z"/></svg>';
            var ICON_PAUSE   = '<svg viewBox="0 0 24 24"><rect fill="#0f172a" x="6" y="5" width="4" height="14" rx="1"/><rect fill="#0f172a" x="14" y="5" width="4" height="14" rx="1"/></svg>';

            var root = document.getElementById(<?php echo wp_json_encode( $player_id ); ?>);
            if (!root) return;

            var audioUrl   = root.getAttribute('data-audio-url') || '';
            var speechLang = root.getAttribute('data-lang') || 'en-US';
            var langShort  = root.getAttribute('data-lang-short') || 'en';
            var bodyEl     = document.getElementById(<?php echo wp_json_encode( $body_id ); ?>);

            // TTS reads ONLY the article body paragraphs. The fact card and
            // the player are placed outside this container, and anything the
            // editor wrote above the [fact_card] marker — a byline line —
            // stays outside it too, so none of that is ever read aloud.
            //
            // The card/player checks below are belt and braces: they are not
            // inside the container today, but if a future layout change ever
            // puts them there, the narrator still won't read the claim, the
            // fact, or the words "Listen to this article".
            var paras = bodyEl ? Array.prototype.slice.call(bodyEl.querySelectorAll('p')).filter(function(p) {
                if (p.textContent.replace(/\s+/g, '').length <= 8) return false;
                if (p.closest && (p.closest('.fc-hero') || p.closest('.fc-a-wrap'))) return false;
                return true;
            }) : [];

            var playBtn  = root.querySelector('.fc-a-play');
            var speedBtn = root.querySelector('.fc-a-speed');
            var fill     = root.querySelector('.fc-a-fill');
            var timeText = root.querySelector('.fc-a-time-text');
            var track    = root.querySelector('.fc-a-track');

            var mini      = document.getElementById(<?php echo wp_json_encode( $player_id . '-mini' ); ?>);
            var miniBtn   = mini ? mini.querySelector('.fc-a-mini-play') : null;

            playBtn.innerHTML = ICON_PLAY;
            if (miniBtn) miniBtn.innerHTML = ICON_PLAY;

            var speeds = [1, 1.25, 1.5, 1.75, 0.75];
            var speedIndex = 0;
            var isPlaying = false;
            var muted = false;
            var mediaEl = null;
            var chosenVoice = null;
            var currentParaIndex = -1;
            var currentChunkIndex = -1;

            function fmt(sec) {
                if (!isFinite(sec) || sec < 0) sec = 0;
                var m = Math.floor(sec / 60), s = Math.floor(sec % 60);
                return m + ':' + (s < 10 ? '0' : '') + s;
            }
            function setPlayingUI(playing) {
                isPlaying = playing;
                playBtn.innerHTML = playing ? ICON_PAUSE : ICON_PLAY;
                if (miniBtn) miniBtn.innerHTML = playing ? ICON_PAUSE : ICON_PLAY;
            }
            function showMini() { if (mini) mini.classList.add('fc-a-visible'); }
            function hideMini() { if (mini) mini.classList.remove('fc-a-visible'); }
            function clearHighlight() { paras.forEach(function(p) { p.classList.remove('fc-reading'); }); }
            function highlightPara(i) {
                clearHighlight();
                if (paras[i]) {
                    paras[i].classList.add('fc-reading');
                    paras[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                currentParaIndex = i;
                // Progress reflects chunks read (finer + smoother than
                // paragraph count), shown as a simple percentage bar.
                if (chunkList.length) {
                    var pct = Math.min(100, ((currentChunkIndex + 1) / chunkList.length) * 100);
                    fill.style.width = pct + '%';
                    timeText.textContent = Math.round(pct) + '%';
                }
            }

            // Pick a browser voice that matches the site language, so Hindi
            // and Marathi articles are read by a Hindi/Marathi voice when the
            // visitor's device has one installed.
            //
            // Critical: the browser only speaks a language if the device has
            // a voice for it. English is everywhere; most Indian-language
            // voices are not installed by default. When there is no matching
            // voice AND no premium MP3, we hide the whole player rather than
            // show a control that plays nothing (or mangles the text with an
            // English voice). English is allowed to fall back to any English
            // voice, which every device has.
            function findVoice() {
                if (!('speechSynthesis' in window)) return null;
                var voices = window.speechSynthesis.getVoices() || [];
                if (!voices.length) return null;
                return (
                    voices.find(function(v) { return v.lang && v.lang.toLowerCase() === speechLang.toLowerCase(); }) ||
                    voices.find(function(v) { return v.lang && v.lang.toLowerCase().indexOf(langShort) === 0; }) ||
                    null
                );
            }

            function hidePlayerWrap() {
                var wrap = root.closest('.fc-a-wrap');
                if (wrap) wrap.style.display = 'none';
            }

            // Decides whether the free browser player is usable. Runs once
            // voices are known. Premium MP3 mode always keeps the player.
            var voiceResolved = false;
            function resolveVoiceAndMaybeHide() {
                if (audioUrl) { voiceResolved = true; return; } // MP3: always keep
                if (voiceResolved) return;
                var voices = window.speechSynthesis ? (window.speechSynthesis.getVoices() || []) : [];
                if (!voices.length) return; // not loaded yet — wait for voiceschanged
                voiceResolved = true;
                chosenVoice = findVoice();
                if (!chosenVoice) {
                    // No voice for this language on this device — hide entirely.
                    hidePlayerWrap();
                }
            }

            if ('speechSynthesis' in window) {
                resolveVoiceAndMaybeHide();
                window.speechSynthesis.addEventListener('voiceschanged', resolveVoiceAndMaybeHide);
                // Safari sometimes never fires voiceschanged; re-check shortly after load.
                setTimeout(resolveVoiceAndMaybeHide, 800);
            } else if (!audioUrl) {
                // Browser has no speech engine at all and there's no MP3 — hide.
                hidePlayerWrap();
            }

            // ── Premium MP3 ──
            function playPremium() {
                if (!mediaEl) {
                    mediaEl = new Audio(audioUrl);
                    mediaEl.addEventListener('timeupdate', function() {
                        var pct = mediaEl.duration ? (mediaEl.currentTime / mediaEl.duration) * 100 : 0;
                        fill.style.width = pct + '%';
                        timeText.textContent = fmt(mediaEl.currentTime);
                    });
                    mediaEl.addEventListener('ended', function() { setPlayingUI(false); hideMini(); });
                }
                mediaEl.playbackRate = speeds[speedIndex];
                mediaEl.muted = muted;
                var p = mediaEl.play();
                if (p && p.catch) p.catch(function() {});
                setPlayingUI(true);
            }
            function pausePremium() { if (mediaEl) mediaEl.pause(); setPlayingUI(false); }

            // ── Browser voice, article body only ──
            //
            // Chrome cuts off any single utterance longer than ~200-250
            // characters. Article paragraphs — especially in Indian
            // languages — routinely exceed that, which is why long
            // paragraphs were going silent mid-way. We split each paragraph
            // into chunks under the limit (on sentence boundaries where
            // possible) and queue them in order. Each chunk remembers which
            // paragraph it came from so highlighting still works per-para.
            var MAX_CHUNK = 180;

            function splitIntoChunks(text) {
                text = text.trim();
                if (!text) return [];
                // Split on sentence enders shared across our languages,
                // including the Devanagari danda (। / ॥) used by Hindi/Marathi.
                //
                // Written WITHOUT a regex lookbehind on purpose. Safari below
                // 16.4 (iOS 15 and older, still common on our readers' phones)
                // treats a lookbehind as a syntax error at parse time, which
                // kills this entire script before the try/catch can catch it —
                // taking the whole player down with it. Marking the break with
                // a null character and splitting on that does the same job and
                // parses everywhere.
                var sentences = text.replace(/([.!?।॥])\s+/g, '$1\u0000').split('\u0000');
                var chunks = [];
                sentences.forEach(function(sentence) {
                    if (sentence.length <= MAX_CHUNK) {
                        if (sentence.trim()) chunks.push(sentence.trim());
                        return;
                    }
                    // Still too long (no sentence breaks) — split on spaces.
                    var words = sentence.split(/\s+/);
                    var buffer = '';
                    words.forEach(function(word) {
                        if ((buffer + ' ' + word).trim().length > MAX_CHUNK) {
                            if (buffer.trim()) chunks.push(buffer.trim());
                            buffer = word;
                        } else {
                            buffer = buffer ? buffer + ' ' + word : word;
                        }
                    });
                    if (buffer.trim()) chunks.push(buffer.trim());
                });
                return chunks;
            }

            // Pre-build the full chunk list once, each tagged with its
            // paragraph index so we can highlight the right paragraph.
            var chunkList = [];
            paras.forEach(function(p, paraIdx) {
                splitIntoChunks(p.textContent).forEach(function(chunk) {
                    chunkList.push({ text: chunk, paraIdx: paraIdx });
                });
            });

            // Maps a paragraph index to the first chunk index in that para,
            // so "resume from current paragraph" lands on a clean boundary.
            function firstChunkOfPara(paraIdx) {
                for (var i = 0; i < chunkList.length; i++) {
                    if (chunkList[i].paraIdx >= paraIdx) return i;
                }
                return 0;
            }

            function buildQueue(fromChunk) {
                if (!('speechSynthesis' in window)) return;
                window.speechSynthesis.cancel();
                var list = [];
                for (var i = Math.max(fromChunk, 0); i < chunkList.length; i++) {
                    (function(idx) {
                        var item = chunkList[idx];
                        var u = new SpeechSynthesisUtterance(item.text);
                        u.rate = speeds[speedIndex];
                        u.lang = speechLang;
                        if (chosenVoice) u.voice = chosenVoice;
                        u.onstart = function() {
                            currentChunkIndex = idx;
                            highlightPara(item.paraIdx);
                        };
                        list.push(u);
                    })(i);
                }
                if (list.length) {
                    list[list.length - 1].onend = function() {
                        setPlayingUI(false); clearHighlight(); hideMini();
                        currentChunkIndex = -1;
                    };
                }
                list.forEach(function(u) { window.speechSynthesis.speak(u); });
            }
            function playBasic(fromChunk) { buildQueue(fromChunk === undefined ? 0 : fromChunk); setPlayingUI(true); }
            function pauseBasic() { window.speechSynthesis.pause(); setPlayingUI(false); }
            function resumeBasic() { window.speechSynthesis.resume(); setPlayingUI(true); }

            function toggle() {
                if (audioUrl) {
                    isPlaying ? pausePremium() : playPremium();
                    return;
                }
                if (!('speechSynthesis' in window) || !chunkList.length) return;
                if (isPlaying) {
                    pauseBasic();
                } else if (window.speechSynthesis.paused) {
                    resumeBasic();
                } else {
                    playBasic(Math.max(currentChunkIndex, 0));
                }
            }

            playBtn.addEventListener('click', toggle);
            if (miniBtn) miniBtn.addEventListener('click', toggle);

            speedBtn.addEventListener('click', function() {
                speedIndex = (speedIndex + 1) % speeds.length;
                speedBtn.textContent = speeds[speedIndex] + 'x';
                if (audioUrl && mediaEl) {
                    mediaEl.playbackRate = speeds[speedIndex];
                } else if (!audioUrl && isPlaying) {
                    playBasic(Math.max(currentChunkIndex, 0)); // browser speech restarts to apply rate
                }
            });

            // Seek. Premium MP3 seeks to an exact time. Browser voice can't
            // seek to a time, but it CAN restart narration from a chosen
            // chunk, so a click on the bar jumps to the nearest chunk. (True
            // drag-scrubbing isn't possible with the browser speech engine —
            // this click-to-jump is the closest reliable behaviour.)
            if (track) {
                track.addEventListener('click', function(e) {
                    var rect = this.getBoundingClientRect();
                    var pct = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
                    if (audioUrl && mediaEl && mediaEl.duration) {
                        mediaEl.currentTime = pct * mediaEl.duration;
                    } else if (!audioUrl && chunkList.length) {
                        var targetChunk = Math.floor(pct * chunkList.length);
                        targetChunk = Math.min(chunkList.length - 1, Math.max(0, targetChunk));
                        currentChunkIndex = targetChunk;
                        fill.style.width = (pct * 100) + '%';
                        if (isPlaying || window.speechSynthesis.paused) {
                            playBasic(targetChunk); // jump and keep reading
                        }
                    }
                });
            }

            // Floating mini player when the main bar scrolls out of view mid-play
            if (mini) {
                var observer = new IntersectionObserver(function(entries) {
                    var visible = entries[0].isIntersecting;
                    if (!visible && isPlaying) showMini(); else hideMini();
                }, { threshold: 0 });
                observer.observe(root);
            }

            // Stop speech if the visitor navigates away, so it doesn't keep talking
            window.addEventListener('beforeunload', function() {
                if ('speechSynthesis' in window) window.speechSynthesis.cancel();
            });

        } catch (err) {
            if (window.console) console.error('FC audio player failed to initialise:', err);
        }
    })();
    </script>
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
function fc_get_whatsapp_banner() {
    $html = get_option( 'fc_whatsapp_banner_html', '' );
    if ( empty( trim( $html ) ) ) return '';

    // The banner is admin-pasted HTML with its own colours. Some themes'
    // dark mode was inverting its text to white on its white card, making
    // it unreadable. `color-scheme: light` forces this subtree to render
    // in light mode so the banner always looks the way it was designed,
    // regardless of the visitor's theme or OS setting.
    $style = 'margin:16px 0; color-scheme:light;';
    return '<div class="fc-whatsapp-banner" style="' . esc_attr( $style ) . '">' . $html . '</div>';
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
    $labels = [
        'false'           => 'False',
        'partly-false'    => 'Partly False',
        'misleading'      => 'Misleading',
        'missing-context' => 'Missing Context',
        'satire'          => 'Satire',
        'altered'         => 'Altered',
        'insight'         => 'Insight',
        'news'            => 'News',
    ];
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
        /* Automatically add spacing below all images, figures, and embeds */
        .entry-content figure, 
        .entry-content iframe, 
        .entry-content img,
        .entry-content .wp-block-embed,
        .post-content figure, 
        .post-content iframe, 
        .post-content img,
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

