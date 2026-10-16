<?php
/**
 * The fact card at the top of a fact-check article.
 *
 * Each post picks how its card is shown (the site setting is the default):
 *   card   – the built-in card: logo, photo and rating on the left,
 *            CLAIM and FACT CHECK boxes on the right. One column on phones.
 *   simple – the older photo-and-strip card (content-injection.php).
 *   image  – the editor's own card picture, e.g. made in Canva.
 *   off    – no card.
 *
 * The card never puts a stamp on the photo. Stamps belong to the featured
 * image (featured-image.php), and the card always uses the plain photo.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** The site's default card: 'card', 'simple' or 'off'. */
function fc_card_design() {
    $design = (string) get_option( 'fc_card_design', 'card' );
    if ( $design === 'canva' ) return 'card'; // old name for the same setting
    return in_array( $design, [ 'card', 'simple', 'off' ], true ) ? $design : 'card';
}

/** What this post shows: 'card', 'simple', 'image' or 'off'. */
function fc_card_mode( $post_id ) {
    $mode = (string) fc_meta( 'fc_card_mode', $post_id );

    if ( $mode === 'off' ) return 'off';
    if ( $mode === 'image' ) {
        // No picture chosen yet: fall back to the site default, never a blank.
        return fc_attachment_image_id( fc_meta( 'fc_card_image', $post_id ) ) ? 'image' : fc_card_design();
    }
    if ( $mode === 'card' ) return fc_card_design() === 'simple' ? 'simple' : 'card';

    return fc_card_design();
}

/** The ID if it is an image in the media library, otherwise 0. */
function fc_attachment_image_id( $id ) {
    $id = absint( $id );
    return ( $id && wp_attachment_is_image( $id ) ) ? $id : 0;
}

/**
 * The plain photo for the card, never the stamped featured image:
 * the "Card photo" set on the post, else the original photo behind a
 * featured image this plugin stamped, else the featured image.
 */
function fc_card_photo_id( $post_id ) {
    $own = fc_attachment_image_id( fc_meta( 'fc_card_photo', $post_id ) );
    if ( $own ) return $own;

    $thumb = (int) get_post_thumbnail_id( $post_id );
    if ( $thumb && $thumb === (int) get_post_meta( $post_id, '_fc_featured_made', true ) ) {
        $source = fc_attachment_image_id( get_post_meta( $post_id, '_fc_featured_source', true ) );
        if ( $source ) return $source;
    }
    return $thumb;
}

/** The logo from Settings, else the Fact Crescendo logo that ships with the plugin. */
function fc_card_logo_url() {
    $url = (string) get_option( 'fc_logo_url', '' );
    if ( $url === '' ) $url = FC_PUBLISHER_URL . 'assets/logo.png';

    /** Filter: use another logo. Return '' to show the site's name instead. */
    return (string) apply_filters( 'fc_card_logo_url', $url );
}

/** The fact without its **highlight** marks, for plain-text uses. */
function fc_plain_fact( $text ) {
    return preg_replace( '/\*\*(.+?)\*\*/s', '$1', (string) $text );
}

/** The card's headings, in the site's language. */
function fc_card_labels( $post_id ) {
    $words = [
        'en' => [ 'claim' => 'Claim', 'fact' => 'Fact check', 'rating' => 'Rating' ],
        'hi' => [ 'claim' => 'दावा', 'fact' => 'फैक्ट चेक', 'rating' => 'रेटिंग' ],
        'mr' => [ 'claim' => 'दावा', 'fact' => 'फॅक्ट चेक', 'rating' => 'रेटिंग' ],
    ];
    $lang = fc_site_lang();

    /** Filter: change the headings. Keys: claim, fact, rating. */
    return apply_filters( 'fc_card_labels', $words[ $lang ] ?? $words['en'], $post_id );
}

/** Colour family for a rating: red, orange, amber or green. */
function fc_card_tone( $rating ) {
    $map = [
        'false'           => 'red',
        'altered'         => 'red',
        'partly-false'    => 'orange',
        'misleading'      => 'orange',
        'missing-context' => 'amber',
        'satire'          => 'amber',
        'insight'         => 'green',
        'news'            => 'green',
    ];
    return $map[ strtolower( (string) $rating ) ] ?? 'amber';
}

/** Icon inside the rating badge, so the rating never relies on colour alone. */
function fc_card_glyph( $rating ) {
    $x     = '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>';
    $mark  = '<path d="M12 5v8.2"/><circle class="fc-fill" cx="12" cy="18.3" r="1.9"/>';
    $dots  = '<circle class="fc-fill" cx="5.5" cy="12" r="2.1"/><circle class="fc-fill" cx="12" cy="12" r="2.1"/><circle class="fc-fill" cx="18.5" cy="12" r="2.1"/>';
    $check = '<path d="M5 12.5l4.5 4.5L19 7.5"/>';
    $info  = '<circle class="fc-fill" cx="12" cy="5.8" r="1.9"/><path d="M12 10.5V19"/>';

    $map = [
        'false'        => $x,    'altered'         => $x,
        'partly-false' => $mark, 'misleading'      => $mark,
        'satire'       => $dots, 'missing-context' => $dots,
        'insight'      => $info, 'news'            => $check,
    ];
    return $map[ strtolower( (string) $rating ) ] ?? $dots;
}

/** Text size step, 1 (short) to 4 (long), so long text still fits. */
function fc_card_size( $text ) {
    $len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
    if ( $len <= 70 )  return 1;
    if ( $len <= 140 ) return 2;
    if ( $len <= 240 ) return 3;
    return 4;
}

/** True when the text uses a non-Latin script (it needs more line height). */
function fc_card_is_indic( $text ) {
    return (bool) preg_match( '/[^\x{0000}-\x{024F}\x{2000}-\x{206F}]/u', $text );
}

/** The fact card for a post, in whichever way the post asks for. */
function fc_render_fact_card( $post_id, $rating, $rating_label, $claim, $fact ) {
    switch ( fc_card_mode( $post_id ) ) {
        case 'off':
            return '';
        case 'image':
            return fc_build_card_image( $post_id, $rating_label, $claim );
        case 'simple':
            return fc_build_fact_card( $post_id, $rating, $rating_label, $claim, fc_plain_fact( $fact ), get_option( 'fc_logo_url', '' ) );
        default:
            return fc_build_card( $post_id, $rating, $rating_label, $claim, $fact );
    }
}

/** The editor's own card picture (e.g. from Canva), shown as it is. */
function fc_build_card_image( $post_id, $rating_label, $claim ) {
    $id = fc_attachment_image_id( fc_meta( 'fc_card_image', $post_id ) );
    if ( ! $id ) return '';

    // Screen readers get the text that is in the picture.
    $alt = 'Fact check.';
    if ( $rating_label !== '' ) $alt .= ' Rating: ' . $rating_label . '.';
    if ( $claim !== '' )        $alt .= ' Claim: ' . $claim;

    $img = wp_get_attachment_image( $id, 'large', false, [
        'class'         => 'fc-own-card-img',
        'alt'           => wp_strip_all_tags( $alt ),
        'loading'       => 'eager',
        'fetchpriority' => 'high',
    ] );

    return '<style>'
        . '.fc-own-card{margin:0 0 28px !important;padding:0 !important;line-height:0}'
        . '.fc-own-card .fc-own-card-img{display:block;width:100% !important;height:auto !important;max-width:100% !important;margin:0 !important;border-radius:12px}'
        . '</style><figure class="fc-own-card">' . $img . '</figure>';
}

/** The built-in card. '' when there is nothing to show. */
function fc_build_card( $post_id, $rating, $rating_label, $claim, $fact ) {

    $claim = trim( (string) $claim );
    $fact  = trim( (string) $fact );

    // From the attachment directly, so "hide featured image" plugins
    // (which blank the theme's copy) don't blank this one.
    $photo_id = fc_card_photo_id( $post_id );
    $img_html = $photo_id ? wp_get_attachment_image( $photo_id, 'large', false, [
        'class'         => 'fc-fc-img',
        'loading'       => 'eager',
        'fetchpriority' => 'high',
        'sizes'         => '(max-width: 640px) 100vw, 360px',
    ] ) : '';

    if ( $img_html === '' && $claim === '' && $fact === '' ) return '';

    // The frame follows the photo's shape (within limits), so little is cropped.
    $ratio = 1.5;
    $meta  = $photo_id ? wp_get_attachment_metadata( $photo_id ) : [];
    if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
        $ratio = max( 1.38, min( 1.6, $meta['width'] / $meta['height'] ) );
    }

    $words    = fc_card_labels( $post_id );
    $logo_url = fc_card_logo_url();
    $indic    = fc_card_is_indic( $claim . $fact . $rating_label . implode( '', $words ) );
    $classes  = 'fc-fc fc-fc--' . fc_card_tone( $rating ) . ( $indic ? ' fc-fc--indic' : '' );
    $fonts    = FC_PUBLISHER_URL . 'assets/fonts/';

    // Escaped first; then **words** become a highlight.
    $fact_html = preg_replace( '/\*\*(.+?)\*\*/s', '<mark>$1</mark>', esc_html( $fact ) );

    ob_start();
    ?>
    <style>
    /* Fonts ship with the plugin. A file only downloads when the page uses
       a letter it covers; other scripts use the visitor's own font. */
    @font-face { font-family:'FC Archivo'; font-style:normal; font-weight:100 900; font-stretch:62% 125%; font-display:swap; src:url(<?php echo esc_url( $fonts . 'archivo-latin.woff2' ); ?>) format('woff2'); unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD; }
    @font-face { font-family:'FC Devanagari'; font-style:normal; font-weight:100 900; font-display:swap; src:url(<?php echo esc_url( $fonts . 'noto-sans-devanagari.woff2' ); ?>) format('woff2'); unicode-range:U+0900-097F,U+1CD0-1CF9,U+200C-200D,U+20A8,U+20B9,U+25CC,U+A830-A839,U+A8E0-A8FF; }

    .fc-fc-wrap { container-type:inline-size; margin:0 0 30px; color-scheme:light; }
    .fc-fc-wrap .fc-fc {
        --red:#d90a1a; --green:#007840; --paper:#f6f1f0; --surface:#fefbfa;
        --ink:#231412; --soft:#5a4c4a; --line:#dbd2d1; --lh:1.2;
        display:flex; flex-direction:column; gap:16px;
        background:var(--paper) !important; color:var(--ink) !important;
        border-radius:20px; padding:18px 16px 20px; text-align:left;
        font-family:'FC Archivo','FC Devanagari',system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    /* Rating colours: accent, icon on accent, highlight, bar tint, bar edge */
    .fc-fc-wrap .fc-fc--green  { --v:#00884b; --v-on:#f7fef9; --v-hl:#a5efbe; --v-bg:#d5e9de; --v-edge:#a8d4be; }
    .fc-fc-wrap .fc-fc--amber  { --v:#eaab05; --v-on:#261704; --v-hl:#ffdc75; --v-bg:#fbeed3; --v-edge:#f7e0a7; }
    .fc-fc-wrap .fc-fc--orange { --v:#ec6f00; --v-on:#250f07; --v-hl:#ffc698; --v-bg:#fbe5d2; --v-edge:#f8cba5; }
    .fc-fc-wrap .fc-fc--red    { --v:#b7191c; --v-on:#fff9f8; --v-hl:#ffc7be; --v-bg:#f3d7d6; --v-edge:#e6aeaf; }
    .fc-fc-wrap .fc-fc--indic  { --lh:1.45; }

    /* Keep the theme's article styles out of the card */
    .fc-fc-wrap .fc-fc, .fc-fc-wrap .fc-fc * { box-sizing:border-box; }
    .fc-fc-wrap .fc-fc p, .fc-fc-wrap .fc-fc figure { margin:0 !important; padding:0 !important; }
    .fc-fc-wrap .fc-fc img { margin:0 !important; padding:0 !important; border:0 !important; box-shadow:none !important; }

    .fc-fc-wrap .fc-fc-col { display:flex; flex-direction:column; gap:16px; min-width:0; }

    .fc-fc-wrap .fc-fc-logo { height:40px !important; width:auto !important; max-width:70% !important; object-fit:contain; display:block; align-self:flex-start; }
    .fc-fc-wrap .fc-fc-site { font-weight:800; font-size:20px; color:var(--ink) !important; }

    /* Photo: the frame sets the shape, the photo fills it */
    .fc-fc-wrap .fc-fc-photo {
        position:relative; overflow:hidden; border-radius:16px; line-height:0;
        background:#e7dfde; box-shadow:0 0 0 1.5px var(--line), 0 14px 26px -20px rgba(60,40,38,.45);
    }
    .fc-fc-wrap .fc-fc-photo .fc-fc-img {
        position:absolute; inset:0; display:block; width:100% !important; height:100% !important;
        max-width:none !important; max-height:none !important; object-fit:cover;
    }

    /* Rating bar: the colour is in the badge, the meaning in the words */
    .fc-fc-wrap .fc-fc-rating {
        display:flex; flex-direction:column; justify-content:center; gap:4px;
        background:var(--v-bg); box-shadow:0 0 0 1.5px var(--v-edge); border-radius:16px; padding:14px 20px;
    }
    .fc-fc-wrap .fc-fc-rating-label {
        font-size:12px; font-weight:600; font-stretch:80%; letter-spacing:.14em; text-transform:uppercase;
        color:var(--soft) !important; line-height:1.2;
    }
    .fc-fc-wrap .fc-fc-rating-value {
        display:flex; align-items:center; gap:12px; font-size:26px; font-weight:720;
        letter-spacing:-.01em; line-height:1.15; color:var(--ink) !important;
    }
    .fc-fc-wrap .fc-fc-badge {
        flex:none; width:.95em; height:.95em; border-radius:50%; background:var(--v); color:var(--v-on);
        display:grid; place-items:center;
    }
    .fc-fc-wrap .fc-fc-badge svg { width:52%; height:52%; fill:none; stroke:currentColor; stroke-width:3.2; stroke-linecap:round; stroke-linejoin:round; display:block; }
    .fc-fc-wrap .fc-fc-badge svg .fc-fill { fill:currentColor; stroke:none; }

    /* CLAIM and FACT CHECK boxes, each with a pill on its top edge */
    .fc-fc-wrap .fc-fc-box {
        position:relative; border-radius:16px; padding:30px 20px 20px !important; margin-top:16px !important;
        display:flex; flex-direction:column; justify-content:center;
    }
    .fc-fc-wrap .fc-fc .fc-fc-pill {
        position:absolute; top:-16px; left:18px; height:32px; padding:0 18px !important;
        display:flex; align-items:center; border-radius:999px; white-space:nowrap;
        font-size:20px; font-weight:800; font-stretch:68%; letter-spacing:.07em; text-transform:uppercase;
        line-height:1; box-shadow:0 0 0 5px var(--paper);
    }
    .fc-fc-wrap .fc-fc-claim { background:var(--red) !important; color:#fffafa !important; box-shadow:0 18px 30px -22px rgba(190,20,30,.7); }
    .fc-fc-wrap .fc-fc-claim .fc-fc-pill { background:var(--surface); color:var(--red) !important; }
    .fc-fc-wrap .fc-fc-fact { background:var(--surface) !important; color:var(--ink) !important; box-shadow:0 0 0 1.5px var(--line), 0 18px 30px -24px rgba(60,40,38,.4); }
    .fc-fc-wrap .fc-fc-fact .fc-fc-pill { background:var(--green); color:#f7fef9 !important; }

    .fc-fc-wrap .fc-fc-text { line-height:var(--lh); white-space:pre-line; overflow-wrap:anywhere; text-wrap:pretty; color:inherit !important; }
    .fc-fc-wrap .fc-fc-claim .fc-fc-text { font-weight:780; letter-spacing:-.012em; }
    .fc-fc-wrap .fc-fc-fact .fc-fc-text { font-weight:800; letter-spacing:-.015em; }
    .fc-fc-wrap .fc-fc mark {
        background:var(--v-hl) !important; color:inherit !important; padding:0 .14em; margin:0 -.14em;
        border-radius:.14em; -webkit-box-decoration-break:clone; box-decoration-break:clone;
    }

    /* Hindi, Marathi and other scripts: no tight letter spacing */
    .fc-fc-wrap .fc-fc--indic .fc-fc-text,
    .fc-fc-wrap .fc-fc--indic .fc-fc-pill,
    .fc-fc-wrap .fc-fc--indic .fc-fc-rating-label { letter-spacing:0; }
    .fc-fc-wrap .fc-fc.fc-fc--indic .fc-fc-pill { font-size:18px; padding-top:3px !important; }
    .fc-fc-wrap .fc-fc--indic .fc-fc-rating-label { font-size:14px; }

    /* Text sizes by length, 1 (short) to 4 (long) */
    .fc-fc-wrap .fc-fc-claim.fc-s1 .fc-fc-text { font-size:21px; }
    .fc-fc-wrap .fc-fc-claim.fc-s2 .fc-fc-text { font-size:18px; }
    .fc-fc-wrap .fc-fc-claim.fc-s3 .fc-fc-text { font-size:16px; }
    .fc-fc-wrap .fc-fc-claim.fc-s4 .fc-fc-text { font-size:15px; }
    .fc-fc-wrap .fc-fc-fact.fc-s1 .fc-fc-text  { font-size:23px; }
    .fc-fc-wrap .fc-fc-fact.fc-s2 .fc-fc-text  { font-size:19px; }
    .fc-fc-wrap .fc-fc-fact.fc-s3 .fc-fc-text  { font-size:17px; }
    .fc-fc-wrap .fc-fc-fact.fc-s4 .fc-fc-text  { font-size:15.5px; }

    /* Wide card: two columns, as in the design */
    @container (min-width: 600px) {
        .fc-fc-wrap .fc-fc { display:grid; grid-template-columns:45% 1fr; gap:26px; padding:26px; border-radius:24px; }
        .fc-fc-wrap .fc-fc-col-a .fc-fc-rating { flex:1 1 auto; min-height:88px; padding:12px 22px; }
        .fc-fc-wrap .fc-fc-col-b { gap:0; }
        .fc-fc-wrap .fc-fc-claim { flex:.62 1 auto; }
        .fc-fc-wrap .fc-fc-fact  { flex:1 1 auto; margin-top:30px !important; }
        .fc-fc-wrap .fc-fc-rating-value { font-size:28px; }
        .fc-fc-wrap .fc-fc-claim.fc-s1 .fc-fc-text { font-size:23px; }
        .fc-fc-wrap .fc-fc-fact.fc-s1 .fc-fc-text  { font-size:26px; }
        .fc-fc-wrap .fc-fc-fact.fc-s2 .fc-fc-text  { font-size:21px; }
    }
    /* Small phones */
    @container (max-width: 359px) {
        .fc-fc-wrap .fc-fc { padding:14px 12px 16px; }
        .fc-fc-wrap .fc-fc-box { padding:28px 16px 16px !important; }
        .fc-fc-wrap .fc-fc-rating-value { font-size:22px; }
        .fc-fc-wrap .fc-fc-claim.fc-s1 .fc-fc-text { font-size:19px; }
        .fc-fc-wrap .fc-fc-fact.fc-s1 .fc-fc-text  { font-size:20px; }
    }
    </style>

    <div class="fc-fc-wrap">
    <aside class="<?php echo esc_attr( $classes ); ?>" aria-label="<?php echo esc_attr( $words['fact'] ); ?>">

        <div class="fc-fc-col fc-fc-col-a">
            <?php if ( $logo_url !== '' ) : ?>
            <img class="fc-fc-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" decoding="async">
            <?php else : ?>
            <p class="fc-fc-site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
            <?php endif; ?>

            <?php if ( $img_html !== '' ) : ?>
            <figure class="fc-fc-photo" style="aspect-ratio:<?php echo esc_attr( round( $ratio, 3 ) ); ?>">
                <?php echo $img_html; // Built and escaped by WordPress. ?>
            </figure>
            <?php endif; ?>

            <?php if ( $rating_label !== '' ) : ?>
            <div class="fc-fc-rating">
                <p class="fc-fc-rating-label"><?php echo esc_html( $words['rating'] ); ?></p>
                <p class="fc-fc-rating-value">
                    <span class="fc-fc-badge" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><?php echo fc_card_glyph( $rating ); // Fixed markup. ?></svg></span>
                    <span><?php echo esc_html( $rating_label ); ?></span>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <div class="fc-fc-col fc-fc-col-b">
            <?php if ( $claim !== '' ) : ?>
            <div class="fc-fc-box fc-fc-claim fc-s<?php echo (int) fc_card_size( $claim ); ?>">
                <p class="fc-fc-pill"><?php echo esc_html( $words['claim'] ); ?></p>
                <p class="fc-fc-text">&ldquo;<?php echo esc_html( $claim ); ?>&rdquo;</p>
            </div>
            <?php endif; ?>

            <?php if ( $fact !== '' ) : ?>
            <div class="fc-fc-box fc-fc-fact fc-s<?php echo (int) fc_card_size( fc_plain_fact( $fact ) ); ?>">
                <p class="fc-fc-pill"><?php echo esc_html( $words['fact'] ); ?></p>
                <p class="fc-fc-text"><?php echo $fact_html; // Escaped above; only <mark> added. ?></p>
            </div>
            <?php endif; ?>
        </div>

    </aside>
    </div>
    <?php
    return ob_get_clean();
}
