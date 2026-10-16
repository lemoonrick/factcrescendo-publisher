<?php
/**
 * The fact card in the FactCrescendo thumbnail design.
 *
 * Rebuilt from the Canva thumbnail, as a live part of the page rather than
 * a picture: the logo, the photo with its rating stamp, the WhatsApp number
 * and the result on one side; the red CLAIM and FACT CHECK boxes on the
 * other. On narrow screens it becomes one column, in reading order.
 *
 * It sizes itself by the width of the article column it sits in, not the
 * screen, so it lays out correctly in a narrow theme column on a wide
 * monitor too. Browsers too old to do that get the one-column layout,
 * which works at any width.
 *
 * Which design a site shows is a setting (fc_card_design). This one is the
 * default; "simple" is the earlier card in content-injection.php, kept so
 * a site can switch back, and a future design can be added alongside.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Which card this site shows: 'canva' (default), 'simple' or 'off'. */
function fc_card_design() {
    $design = (string) get_option( 'fc_card_design', 'canva' );
    return in_array( $design, [ 'canva', 'simple', 'off' ], true ) ? $design : 'canva';
}

/**
 * The logo shown on the card.
 *
 * The logo set under Settings if there is one, otherwise the logo the
 * theme already uses, so most sites need nothing set. Empty when neither
 * exists; the card then shows the site's name instead.
 */
function fc_card_logo_url() {
    $url = (string) get_option( 'fc_logo_url', '' );
    if ( $url !== '' ) return $url;

    $logo_id = (int) get_theme_mod( 'custom_logo' );
    if ( $logo_id ) {
        $src = wp_get_attachment_image_url( $logo_id, 'medium' );
        if ( $src ) return $src;
    }
    return '';
}

/**
 * Builds the card. Returns '' when there is nothing to show.
 *
 * Every value is escaped where it is printed. Claim and fact are plain text
 * with line breaks kept.
 */
function fc_build_canva_card( $post_id, $rating, $rating_label, $claim, $fact ) {

    $claim = trim( (string) $claim );
    $fact  = trim( (string) $fact );

    // Built from the attachment directly so "hide featured image" plugins,
    // which blank the theme's copy, don't blank this one too. See the note
    // in fc_build_fact_card().
    $thumb_id = get_post_thumbnail_id( $post_id );
    $img_html = $thumb_id ? wp_get_attachment_image( $thumb_id, 'large', false, [
        'class'         => 'fc-cc-photo-img',
        'loading'       => 'eager',
        'fetchpriority' => 'high',
        'sizes'         => '(max-width: 700px) 100vw, 420px',
    ] ) : '';

    if ( $img_html === '' && $claim === '' && $fact === '' ) return '';

    $site      = fc_site_profile();
    $logo_url  = fc_card_logo_url();
    $stamp_url = fc_get_stamp_url( $rating );
    $fonts     = FC_PUBLISHER_URL . 'assets/fonts/';
    $latin     = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
    $deva      = 'U+0900-097F,U+1CD0-1CF9,U+200C-200D,U+20A8,U+20B9,U+20F0,U+25CC,U+A830-A839,U+A8E0-A8FF';

    /**
     * The words printed on the card, so a site can translate them.
     * Keys: claim, fact, result, whatsapp.
     */
    $words = apply_filters( 'fc_card_labels', [
        'claim'    => 'Claim',
        'fact'     => 'Fact Check',
        'result'   => 'Result',
        'whatsapp' => 'Whatsapp us',
    ], $post_id );

    ob_start();
    ?>
    <style>
    /* Fonts ship with the plugin and load from this site. Each file is only
       downloaded when the card actually uses a character in its range, so a
       Hindi page fetches the Devanagari file and an English page doesn't. */
    @font-face { font-family:'FC Poppins'; font-style:normal; font-weight:600; font-display:swap; src:url(<?php echo esc_url( $fonts . 'poppins-latin-600-normal.woff2' ); ?>) format('woff2'); unicode-range:<?php echo esc_html( $latin ); ?>; }
    @font-face { font-family:'FC Poppins'; font-style:normal; font-weight:700; font-display:swap; src:url(<?php echo esc_url( $fonts . 'poppins-latin-700-normal.woff2' ); ?>) format('woff2'); unicode-range:<?php echo esc_html( $latin ); ?>; }
    @font-face { font-family:'FC Poppins'; font-style:normal; font-weight:600; font-display:swap; src:url(<?php echo esc_url( $fonts . 'poppins-devanagari-600-normal.woff2' ); ?>) format('woff2'); unicode-range:<?php echo esc_html( $deva ); ?>; }
    @font-face { font-family:'FC Poppins'; font-style:normal; font-weight:700; font-display:swap; src:url(<?php echo esc_url( $fonts . 'poppins-devanagari-700-normal.woff2' ); ?>) format('woff2'); unicode-range:<?php echo esc_html( $deva ); ?>; }
    @font-face { font-family:'FC Bebas'; font-style:normal; font-weight:400; font-display:swap; src:url(<?php echo esc_url( $fonts . 'bebas-neue-latin-400-normal.woff2' ); ?>) format('woff2'); unicode-range:<?php echo esc_html( $latin ); ?>; }

    /* Scripts Poppins doesn't cover (Tamil, Malayalam, Sinhala, Odia,
       Gujarati, Bengali/Assamese) fall through to the visitor's system
       font for those letters, which is what the site's own text uses. */
    .fc-cc-wrap {
        --fc-red:#ed1c24; --fc-green:#1f9d47; --fc-ink:#111111;
        container-type:inline-size; margin:0 0 30px; color-scheme:light;
    }
    .fc-cc-wrap .fc-cc {
        display:flex; flex-direction:column; gap:22px;
        background:#ffffff !important; color:var(--fc-ink) !important;
        border:1px solid #ececec; border-radius:18px; padding:22px 18px;
        box-shadow:0 6px 24px -10px rgba(0,0,0,0.18);
        font-family:'FC Poppins', system-ui, -apple-system, 'Segoe UI', sans-serif;
        box-sizing:border-box; text-align:left;
    }
    .fc-cc-wrap .fc-cc *, .fc-cc-wrap .fc-cc *::before { box-sizing:border-box; }
    /* Themes style img, figure and a inside articles; none of that belongs here. */
    .fc-cc-wrap .fc-cc img { margin:0 !important; padding:0 !important; border:0 !important; box-shadow:none !important; max-width:100%; }
    .fc-cc-wrap .fc-cc figure { margin:0 !important; padding:0 !important; }
    .fc-cc-wrap .fc-cc a { text-decoration:none !important; border:0 !important; box-shadow:none !important; }

    /* The two halves only exist as columns on wide cards. On narrow ones
       their pieces join one column, put in reading order below. */
    .fc-cc-wrap .fc-cc-left, .fc-cc-wrap .fc-cc-right { display:contents; }

    .fc-cc-wrap .fc-cc-logo   { order:1; }
    .fc-cc-wrap .fc-cc-photo  { order:2; }
    .fc-cc-wrap .fc-cc-claim  { order:3; }
    .fc-cc-wrap .fc-cc-fact   { order:4; }
    .fc-cc-wrap .fc-cc-result { order:5; }
    .fc-cc-wrap .fc-cc-wa     { order:6; }

    /* Logo */
    .fc-cc-wrap .fc-cc-logo { display:flex; align-items:center; justify-content:center; min-height:34px; }
    .fc-cc-wrap .fc-cc-logo img { height:42px; width:auto; max-width:70%; object-fit:contain; display:block; }
    .fc-cc-wrap .fc-cc-sitename { font-weight:700; font-size:20px; color:var(--fc-ink) !important; }

    /* Photo, framed in red, stamp in the lower left as in the thumbnail */
    /* The frame sets the shape and the photo fills it, so a theme's own
       image size rules can't leave an empty band inside the frame. Some
       themes (Twenty Twenty-One among them) write a max-width straight onto
       small images, hence the !important on max-width too. */
    .fc-cc-wrap .fc-cc-photo { position:relative; border:2px solid var(--fc-red) !important; line-height:0; overflow:hidden; background:#f3f3f3; aspect-ratio:3 / 2; }
    .fc-cc-wrap .fc-cc-photo .fc-cc-photo-img { position:absolute; inset:0; display:block; width:100% !important; height:100% !important; max-width:none !important; max-height:none !important; object-fit:cover; }
    .fc-cc-wrap .fc-cc-stamp {
        position:absolute; left:8px; bottom:8px; width:26%; max-width:96px; min-width:58px; height:auto;
        background:rgba(255,255,255,0.92); border-radius:50%; padding:3px !important;
        box-shadow:0 2px 8px rgba(0,0,0,0.25) !important;
    }

    /* CLAIM and FACT CHECK boxes, each with its tab sitting on the top edge */
    .fc-cc-wrap .fc-cc-box {
        position:relative; background:var(--fc-red) !important; border-radius:14px;
        padding:34px 18px 18px; margin-top:14px;
        box-shadow:0 4px 12px -4px rgba(237,28,36,0.45);
    }
    .fc-cc-wrap .fc-cc-tab {
        position:absolute; top:-17px; left:16px;
        font-family:'FC Bebas', 'Oswald', 'Arial Narrow', sans-serif; font-weight:400;
        font-size:25px; line-height:1; letter-spacing:1.5px; text-transform:uppercase;
        color:#ffffff !important; background:var(--fc-red); padding:6px 22px 5px;
        border:3px solid #ffffff; border-radius:999px; box-shadow:0 3px 8px rgba(0,0,0,0.28);
        white-space:nowrap;
    }
    .fc-cc-wrap .fc-cc-fact .fc-cc-tab { background:var(--fc-green); }
    .fc-cc-wrap .fc-cc-text {
        color:#ffffff !important; font-weight:600; font-size:15.5px; line-height:1.6;
        margin:0; white-space:pre-line; overflow-wrap:anywhere;
    }

    /* RESULT, under a red rule */
    .fc-cc-wrap .fc-cc-result { display:flex; align-items:center; gap:14px; flex-wrap:wrap; border-top:2px solid var(--fc-red); padding-top:16px; }
    .fc-cc-wrap .fc-cc-result-pill {
        background:var(--fc-red); color:#ffffff !important; font-weight:700; font-size:16px;
        letter-spacing:0.5px; text-transform:uppercase; padding:7px 24px; border-radius:999px; line-height:1.2;
    }
    .fc-cc-wrap .fc-cc-result-text { font-weight:700; font-size:19px; text-transform:uppercase; color:var(--fc-ink) !important; letter-spacing:0.3px; line-height:1.2; }

    /* WhatsApp */
    .fc-cc-wrap .fc-cc-wa { display:inline-flex; flex-direction:column; align-items:flex-start; gap:6px; align-self:flex-start; }
    .fc-cc-wrap .fc-cc-wa-pill {
        display:inline-flex; align-items:center; gap:8px; background:var(--fc-red); color:#ffffff !important;
        font-weight:600; font-size:13px; padding:4px 14px 4px 4px; border-radius:999px; line-height:1.2;
    }
    .fc-cc-wrap .fc-cc-wa-icon { width:22px; height:22px; flex:0 0 22px; display:block; }
    .fc-cc-wrap .fc-cc-wa-num { font-weight:700; font-size:20px; color:var(--fc-ink) !important; letter-spacing:0.3px; line-height:1.2; }
    .fc-cc-wrap a.fc-cc-wa:hover .fc-cc-wa-pill, .fc-cc-wrap a.fc-cc-wa:focus-visible .fc-cc-wa-pill { background:#c8141b; }
    .fc-cc-wrap a.fc-cc-wa:focus-visible { outline:3px solid var(--fc-green); outline-offset:4px; border-radius:8px; }

    /* Wide card: the thumbnail's two-column layout */
    @container (min-width: 600px) {
        .fc-cc-wrap .fc-cc { flex-direction:row; align-items:stretch; gap:28px; padding:26px 28px; }
        .fc-cc-wrap .fc-cc-left  { display:flex; flex-direction:column; gap:18px; flex:0 0 44%; min-width:0; }
        .fc-cc-wrap .fc-cc-right { display:flex; flex-direction:column; gap:26px; flex:1 1 auto; min-width:0; padding-top:14px; }
        .fc-cc-wrap .fc-cc-box { flex:1 1 0; margin-top:0; padding:36px 22px 20px; }
        .fc-cc-wrap .fc-cc-text { font-size:16.5px; }
        .fc-cc-wrap .fc-cc-wa { order:5; }
        .fc-cc-wrap .fc-cc-result { order:6; margin-top:auto; }
    }
    /* Very narrow card (small phones) */
    @container (max-width: 360px) {
        .fc-cc-wrap .fc-cc { padding:18px 14px; }
        .fc-cc-wrap .fc-cc-tab { font-size:22px; padding:5px 18px 4px; }
        .fc-cc-wrap .fc-cc-text { font-size:14.5px; }
        .fc-cc-wrap .fc-cc-result-text { font-size:17px; }
    }
    </style>

    <div class="fc-cc-wrap">
    <aside class="fc-cc" aria-label="<?php echo esc_attr( 'Fact check summary' ); ?>">

        <div class="fc-cc-left">
            <div class="fc-cc-logo">
                <?php if ( $logo_url !== '' ) : ?>
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" loading="eager" decoding="async">
                <?php else : ?>
                <span class="fc-cc-sitename"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( $img_html !== '' ) : ?>
            <figure class="fc-cc-photo">
                <?php echo $img_html; // Built and escaped by WordPress. ?>
                <?php if ( $stamp_url ) : ?>
                <img class="fc-cc-stamp" src="<?php echo esc_url( $stamp_url ); ?>" alt="<?php echo esc_attr( $rating_label ); ?>" width="96" height="96" decoding="async">
                <?php endif; ?>
            </figure>
            <?php endif; ?>

            <?php if ( $site['whatsapp_url'] !== '' && $site['whatsapp_number'] !== '' ) : ?>
            <a class="fc-cc-wa" href="<?php echo esc_url( $site['whatsapp_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                <span class="fc-cc-wa-pill">
                    <svg class="fc-cc-wa-icon" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><circle cx="16" cy="16" r="16" fill="#25d366"/><path fill="#fff" d="M16 6.5a9.5 9.5 0 0 0-8.2 14.3L6.5 25.5l4.8-1.3A9.5 9.5 0 1 0 16 6.5zm0 17.3a7.8 7.8 0 0 1-4-1.1l-.3-.2-2.8.8.8-2.7-.2-.3A7.8 7.8 0 1 1 16 23.8zm4.3-5.8c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1a6.4 6.4 0 0 1-3.2-2.8c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.7-1.7c-.2-.5-.4-.4-.5-.4h-.5a.9.9 0 0 0-.7.3 2.8 2.8 0 0 0-.9 2.1 4.9 4.9 0 0 0 1 2.6 11.2 11.2 0 0 0 4.3 3.8c1.6.7 2.2.7 3 .6a2.6 2.6 0 0 0 1.7-1.2 2.1 2.1 0 0 0 .1-1.2c0-.1-.2-.2-.4-.3z"/></svg>
                    <?php echo esc_html( $words['whatsapp'] ); ?>
                </span>
                <span class="fc-cc-wa-num"><?php echo esc_html( $site['whatsapp_number'] ); ?></span>
            </a>
            <?php endif; ?>

            <?php if ( $rating_label !== '' ) : ?>
            <div class="fc-cc-result">
                <span class="fc-cc-result-pill"><?php echo esc_html( $words['result'] ); ?></span>
                <span class="fc-cc-result-text"><?php echo esc_html( $rating_label ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="fc-cc-right">
            <?php if ( $claim !== '' ) : ?>
            <div class="fc-cc-box fc-cc-claim">
                <span class="fc-cc-tab"><?php echo esc_html( $words['claim'] ); ?></span>
                <div class="fc-cc-text"><?php echo esc_html( $claim ); ?></div>
            </div>
            <?php endif; ?>

            <?php if ( $fact !== '' ) : ?>
            <div class="fc-cc-box fc-cc-fact">
                <span class="fc-cc-tab"><?php echo esc_html( $words['fact'] ); ?></span>
                <div class="fc-cc-text"><?php echo esc_html( $fact ); ?></div>
            </div>
            <?php endif; ?>
        </div>

    </aside>
    </div>
    <?php
    return ob_get_clean();
}
