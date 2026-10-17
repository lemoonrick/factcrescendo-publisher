<?php
/**
 * The fact card at the top of a fact-check article.
 *
 * Each post picks how its card is shown (the site setting is the default):
 *   card   – the built-in card: logo, photo (with the rating's stamp),
 *            WhatsApp number, socials and result on the left, red CLAIM and
 *            FACT CHECK boxes on the right. One column on phones.
 *   simple – the older photo-and-strip card (content-injection.php).
 *   image  – the editor's own card picture, e.g. made in Canva.
 *   off    – no card.
 *
 * The card always uses the plain photo and lays the rating's stamp over it
 * itself, so a stamp never appears twice (the featured image's own stamp is
 * baked in by featured-image.php, and is never used here).
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
    if ( $mode === 'card' ) return 'card';

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

    $thumb = fc_featured_id( $post_id );
    if ( $thumb && $thumb === (int) get_post_meta( $post_id, '_fc_featured_made', true ) ) {
        $source = fc_attachment_image_id( get_post_meta( $post_id, '_fc_featured_source', true ) );
        if ( $source ) return $source;
    }
    return $thumb;
}

/**
 * The post's featured image ID, read straight from the database.
 *
 * Many sites hide the theme's featured image with a snippet or plugin that
 * makes WordPress say "no featured image" on article pages. The card still
 * needs the photo, so it asks the database directly.
 */
function fc_featured_id( $post_id ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id' LIMIT 1",
        $post_id
    ) );
}

/** The logo from Settings, else the Fact Crescendo logo that ships with the plugin. */
function fc_card_logo_url() {
    $url = (string) get_option( 'fc_logo_url', '' );
    if ( $url === '' ) $url = FC_PUBLISHER_URL . 'assets/logo.png';

    /** Filter: use another logo. Return '' to show the site's name instead. */
    return (string) apply_filters( 'fc_card_logo_url', $url );
}

/** The white logo, for the simple card where it sits on the photo. */
function fc_card_logo_white_url() {
    /** Filter: use another white logo. Return '' for none. */
    return (string) apply_filters( 'fc_card_logo_white_url', FC_PUBLISHER_URL . 'assets/logo-white.png' );
}

/** The fact without its **highlight** marks, for plain-text uses. */
function fc_plain_fact( $text ) {
    return preg_replace( '/\*\*(.+?)\*\*/s', '$1', (string) $text );
}

/** The card's headings, in the site's language. */
function fc_card_labels( $post_id ) {
    $words = [
        'en' => [ 'claim' => 'Claim', 'fact' => 'Fact check', 'rating' => 'Rating', 'result' => 'Result', 'whatsapp' => 'Whatsapp us', 'follow' => 'Follow us', 'download' => 'Download', 'share' => 'Share' ],
        'hi' => [ 'claim' => 'दावा', 'fact' => 'फैक्ट चेक', 'rating' => 'रेटिंग', 'result' => 'नतीजा', 'whatsapp' => 'व्हाट्सएप करें', 'follow' => 'फॉलो करें', 'download' => 'डाउनलोड', 'share' => 'शेयर' ],
        'mr' => [ 'claim' => 'दावा', 'fact' => 'फॅक्ट चेक', 'rating' => 'रेटिंग', 'result' => 'निकाल', 'whatsapp' => 'व्हॉट्सअॅप करा', 'follow' => 'फॉलो करा', 'download' => 'डाउनलोड', 'share' => 'शेअर' ],
    ];
    $lang = fc_site_lang();

    /** Filter: change the words. Keys: claim, fact, rating, result, whatsapp, follow, download, share. */
    return array_merge( $words['en'], (array) apply_filters( 'fc_card_labels', $words[ $lang ] ?? $words['en'], $post_id ) );
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
            $card = fc_build_card_image( $post_id, $rating_label, $claim );
            break;
        case 'simple':
            $card = fc_build_fact_card( $post_id, $rating, $rating_label, $claim, fc_plain_fact( $fact ), fc_card_logo_white_url() );
            break;
        default:
            $card = fc_build_card( $post_id, $rating, $rating_label, $claim, $fact );
    }
    if ( $card === '' ) return '';

    return '<div class="fc-card-block">' . $card . fc_card_share_buttons( $post_id ) . '</div>';
}

/** "fact-check-<post-slug>", without saying "fact-check" twice. */
function fc_card_file_name( $post_id ) {
    $slug = (string) get_post_field( 'post_name', $post_id );
    return strpos( $slug, 'fact-check' ) === 0 ? $slug : 'fact-check-' . $slug;
}

/** Small Download and Share buttons under the card. Hidden until their script runs. */
function fc_card_share_buttons( $post_id ) {
    if ( get_option( 'fc_card_share', '1' ) !== '1' ) return '';

    wp_enqueue_script( 'fc-card-share', FC_PUBLISHER_URL . 'assets/card-share.js', [], fc_asset_version( 'assets/card-share.js' ), true );
    wp_add_inline_script( 'fc-card-share', 'window.fcShare = ' . wp_json_encode( [
        'lib' => FC_PUBLISHER_URL . 'assets/vendor/modern-screenshot.js?ver=4.7.0',
    ] ) . ';', 'before' );

    $words = fc_card_labels( $post_id );
    $down  = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 19h14"/></svg>';
    $share = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 15V4m0 0L7.5 8.5M12 4l4.5 4.5M6 12v7h12v-7"/></svg>';

    ob_start();
    ?>
    <style>
    .fc-card-block .fc-fc-wrap, .fc-card-block .fc-hero, .fc-card-block .fc-own-card { margin-bottom:10px !important; }
    .fc-share { display:flex; gap:8px; justify-content:flex-end; margin:0 0 28px; }
    .fc-share[hidden] { display:none !important; }
    .fc-share .fc-share-btn {
        display:inline-flex; align-items:center; gap:6px; margin:0; padding:7px 14px; min-height:36px;
        background:transparent !important; color:#5a4c4a !important; border:1px solid #dbd2d1 !important; border-radius:999px;
        font:600 13px/1 system-ui,-apple-system,'Segoe UI',sans-serif; cursor:pointer; box-shadow:none; text-transform:none; letter-spacing:0;
    }
    .fc-share .fc-share-btn:hover { background:#f6f1f0 !important; color:#231412 !important; }
    .fc-share .fc-share-btn:focus-visible { outline:2px solid #231412; outline-offset:2px; }
    .fc-share .fc-share-btn[disabled] { opacity:.7; cursor:progress; }
    .fc-share .fc-share-btn svg { width:16px; height:16px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    </style>
    <div class="fc-share" hidden data-file="<?php echo esc_attr( fc_card_file_name( $post_id ) ); ?>" data-title="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" data-url="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
        <button type="button" class="fc-share-btn" data-act="download"><?php echo $down; // Fixed markup. ?><span><?php echo esc_html( $words['download'] ); ?></span></button>
        <button type="button" class="fc-share-btn" data-act="share"><?php echo $share; // Fixed markup. ?><span><?php echo esc_html( $words['share'] ); ?></span></button>
    </div>
    <?php
    return ob_get_clean();
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
        'data-fc-own'   => '1',
        'data-full'     => wp_get_attachment_url( $id ),
        'loading'       => 'eager',
        'fetchpriority' => 'high',
    ] );

    return '<style>'
        . '.fc-own-card{margin:0 0 28px !important;padding:0 !important;line-height:0}'
        . '.fc-own-card .fc-own-card-img{display:block;width:100% !important;height:auto !important;max-width:100% !important;margin:0 !important;border-radius:12px}'
        . '</style><figure class="fc-own-card">' . $img . '</figure>';
}


/** The site's WhatsApp number as printed on the card, or '' when it has none. */
function fc_card_number() {
    $site = fc_site_profile();
    $text = $site['card_number'] !== '' ? $site['card_number'] : $site['whatsapp_number'];

    /** Filter: change the number shown on the card. Return '' to hide that part. */
    return trim( (string) apply_filters( 'fc_card_number', $text, $site ) );
}

/** Facebook, Instagram and X icons for "Follow us". Links only where the site list gives one. */
function fc_card_socials() {
    $site  = fc_site_profile();
    $links = is_array( $site['socials'] ) ? $site['socials'] : [];

    $icons = [
        'facebook'  => [ '#1877f2', '<path fill="#fff" d="M13.4 20v-7h2.3l.4-2.8h-2.7V8.4c0-.8.3-1.3 1.4-1.3h1.4V4.6c-.3 0-1.1-.1-2.1-.1-2.1 0-3.5 1.3-3.5 3.6v2.1H8.3V13h2.3v7z"/>' ],
        'instagram' => [ 'url(#fc-ig)', '<rect x="6.2" y="6.2" width="11.6" height="11.6" rx="3.4" fill="none" stroke="#fff" stroke-width="1.6"/><circle cx="12" cy="12" r="2.8" fill="none" stroke="#fff" stroke-width="1.6"/><circle cx="15.3" cy="8.7" r=".9" fill="#fff"/>' ],
        'x'         => [ '#1d9bf0', '<path fill="#fff" d="M17.7 7.7c-.4.2-.8.3-1.3.4.5-.3.8-.7 1-1.3-.4.3-.9.4-1.4.5a2.3 2.3 0 0 0-3.9 2.1c-1.9-.1-3.6-1-4.7-2.4-.6 1-.3 2.3.7 3-.4 0-.7-.1-1-.3 0 1.1.8 2 1.8 2.2-.3.1-.7.1-1 0 .3.9 1.1 1.6 2.1 1.6-.9.7-2 1-3.1.9 1 .6 2.2 1 3.5 1 4.3 0 6.7-3.6 6.5-6.9.5-.3.9-.7 1.2-1.2z"/>' ],
    ];

    $out = '';
    foreach ( $icons as $name => $icon ) {
        $svg = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="' . $icon[0] . '"/>' . $icon[1] . '</svg>';
        $url = isset( $links[ $name ] ) ? esc_url( $links[ $name ] ) : '';
        $out .= $url !== ''
            ? '<a class="fc-fc-soc" href="' . $url . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( ucfirst( $name ) ) . '">' . $svg . '</a>'
            : '<span class="fc-fc-soc">' . $svg . '</span>';
    }
    return $out;
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
        'sizes'         => '(max-width: 680px) 100vw, 400px',
    ] ) : '';

    if ( $img_html === '' && $claim === '' && $fact === '' ) return '';

    // Shown only in the page source, to help find out why a photo is missing.
    $note = $img_html === '' ? '<!-- Fact Crescendo: no card photo found (featured image ' . fc_featured_id( $post_id ) . ') -->' : '';

    // The frame follows the photo's shape (within limits), so little is cropped.
    $ratio = 1.5;
    $meta  = $photo_id ? wp_get_attachment_metadata( $photo_id ) : [];
    if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
        $ratio = max( 1.38, min( 1.6, $meta['width'] / $meta['height'] ) );
    }

    // The stamp for this rating, laid over the photo's bottom-left corner.
    $stamp_url = $img_html !== '' ? fc_get_stamp_url( $rating ) : '';
    $wide_stamp = in_array( strtolower( (string) $rating ), [ 'insight', 'news' ], true );

    $words    = fc_card_labels( $post_id );
    $logo_url = fc_card_logo_url();
    $number   = fc_card_number();
    $indic    = fc_card_is_indic( $claim . $fact . $rating_label . implode( '', $words ) );
    $classes  = 'fc-fc' . ( $indic ? ' fc-fc--indic' : '' );
    $fonts    = FC_PUBLISHER_URL . 'assets/fonts/';
    $size     = max( fc_card_size( $claim ), fc_card_size( fc_plain_fact( $fact ) ) ); // One size for both boxes.

    // Escaped first; then **words** become a highlight.
    $fact_html = preg_replace( '/\*\*(.+?)\*\*/s', '<mark>$1</mark>', esc_html( $fact ) );

    ob_start();
    echo $note; // Numbers only.
    ?>
    <style>
    /* Fonts ship with the plugin. A file only downloads when the page uses
       a letter it covers; other scripts use the visitor's own font. */
    @font-face { font-family:'FC Archivo'; font-style:normal; font-weight:100 900; font-stretch:62% 125%; font-display:swap; src:url(<?php echo esc_url( $fonts . 'archivo-latin.woff2' ); ?>) format('woff2'); unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD; }
    @font-face { font-family:'FC Devanagari'; font-style:normal; font-weight:100 900; font-display:swap; src:url(<?php echo esc_url( $fonts . 'noto-sans-devanagari.woff2' ); ?>) format('woff2'); unicode-range:U+0900-097F,U+1CD0-1CF9,U+200C-200D,U+20A8,U+20B9,U+25CC,U+A830-A839,U+A8E0-A8FF; }

    .fc-fc-wrap { container-type:inline-size; margin:0 0 30px; color-scheme:light; }
    .fc-fc-wrap .fc-fc {
        --red:#ed1c24; --ink:#111; --paper:#f2f2f2; --lh:1.38;
        display:flex; flex-direction:column; gap:20px;
        background:var(--paper) !important; color:var(--ink) !important;
        border-radius:20px; padding:16px; text-align:left;
        font-family:'FC Archivo','FC Devanagari',system-ui,-apple-system,'Segoe UI',sans-serif;
    }
    .fc-fc-wrap .fc-fc--indic { --lh:1.55; }

    /* Keep the theme's article styles out of the card */
    .fc-fc-wrap .fc-fc, .fc-fc-wrap .fc-fc * { box-sizing:border-box; }
    .fc-fc-wrap .fc-fc p, .fc-fc-wrap .fc-fc figure { margin:0 !important; padding:0 !important; }
    .fc-fc-wrap .fc-fc img { margin:0 !important; padding:0 !important; border:0 !important; box-shadow:none !important; max-width:none; }
    .fc-fc-wrap .fc-fc a { text-decoration:none !important; box-shadow:none !important; }

    .fc-fc-wrap .fc-fc-head, .fc-fc-wrap .fc-fc-foot, .fc-fc-wrap .fc-fc-boxes { display:flex; flex-direction:column; min-width:0; }
    .fc-fc-wrap .fc-fc-head { gap:14px; }
    .fc-fc-wrap .fc-fc-foot { gap:12px; }

    .fc-fc-wrap .fc-fc-logo { height:48px !important; width:auto !important; max-width:80% !important; object-fit:contain; display:block; align-self:center; }
    .fc-fc-wrap .fc-fc-site { font-weight:800; font-size:22px; text-align:center; color:var(--ink) !important; }

    /* Photo, with the rating's stamp over its bottom-left corner */
    .fc-fc-wrap .fc-fc-photo { position:relative; overflow:hidden; line-height:0; background:#e2e2e2; outline:2px solid var(--red); outline-offset:-2px; border-radius:3px; }
    .fc-fc-wrap .fc-fc-photo .fc-fc-img {
        position:absolute; inset:0; display:block; width:100% !important; height:100% !important;
        max-width:none !important; max-height:none !important; object-fit:cover;
    }
    .fc-fc-wrap .fc-fc-photo .fc-fc-stamp { position:absolute; left:2.5%; bottom:3%; width:29% !important; height:auto !important; filter:drop-shadow(0 2px 4px rgba(0,0,0,.25)); }
    .fc-fc-wrap .fc-fc-photo .fc-fc-stamp--wide { width:38% !important; }

    /* WhatsApp number and "Follow us" */
    .fc-fc-wrap .fc-fc-contact { display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .fc-fc-wrap .fc-fc-wa, .fc-fc-wrap .fc-fc-follow { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .fc-fc-wrap .fc-fc-follow { align-items:center; }
    .fc-fc-wrap .fc-fc .fc-fc-tag {
        display:inline-flex; align-items:center; gap:6px; align-self:flex-start; padding:3px 12px !important; border-radius:999px;
        background:var(--red); color:#fff !important; font-size:14px; font-weight:700; line-height:1.25; white-space:nowrap;
    }
    .fc-fc-wrap .fc-fc .fc-fc-follow .fc-fc-tag { align-self:center; }
    .fc-fc-wrap .fc-fc .fc-fc-tag svg { width:1.5em; height:1.5em; margin:-.3em 0 -.3em -.6em; flex:none; }
    .fc-fc-wrap .fc-fc-num { font-size:24px; font-weight:800; letter-spacing:.01em; line-height:1.1; color:var(--ink) !important; white-space:nowrap; }
    .fc-fc-wrap .fc-fc-socs { display:flex; gap:6px; }
    .fc-fc-wrap .fc-fc-soc { display:block; width:24px; height:24px; }
    .fc-fc-wrap .fc-fc-soc svg { display:block; width:100%; height:100%; }

    /* RESULT strip */
    .fc-fc-wrap .fc-fc-result { display:flex; align-items:center; gap:16px; padding-top:12px; border-top:3px solid var(--red); }
    .fc-fc-wrap .fc-fc .fc-fc-result-pill {
        flex:none; padding:5px 20px !important; border-radius:999px; background:var(--red); color:#fff !important;
        font-size:19px; font-weight:800; line-height:1.15; letter-spacing:.02em; text-transform:uppercase; white-space:nowrap;
    }
    .fc-fc-wrap .fc-fc-result-text { font-size:21px; font-weight:800; line-height:1.15; text-transform:uppercase; color:var(--ink) !important; overflow-wrap:anywhere; }

    /* CLAIM and FACT CHECK boxes, each with a pill on its top edge */
    .fc-fc-wrap .fc-fc-boxes { gap:34px; padding-top:18px; }
    .fc-fc-wrap .fc-fc-box {
        position:relative; border-radius:18px; padding:34px 20px 22px !important; background:var(--red) !important; color:#fff !important;
        box-shadow:0 10px 22px -10px rgba(0,0,0,.35);
    }
    .fc-fc-wrap .fc-fc .fc-fc-pill {
        position:absolute; top:-20px; left:24px; height:40px; padding:0 26px !important; display:flex; align-items:center;
        border-radius:999px; white-space:nowrap; background:var(--red); color:#fff !important; border:3px solid #fff;
        font-size:22px; font-weight:800; font-stretch:68%; letter-spacing:.06em; text-transform:uppercase; line-height:1;
        box-shadow:0 4px 10px -2px rgba(0,0,0,.3);
    }
    .fc-fc-wrap .fc-fc-fact .fc-fc-pill { background:linear-gradient(180deg,#14a85b,#007a3d); }
    .fc-fc-wrap .fc-fc-text { line-height:var(--lh); white-space:pre-line; overflow-wrap:anywhere; text-wrap:pretty; font-weight:700; letter-spacing:0; color:#fff !important; }
    .fc-fc-wrap .fc-fc mark {
        background:rgba(255,255,255,.26) !important; color:inherit !important; padding:0 .14em; margin:0 -.14em;
        border-radius:.14em; -webkit-box-decoration-break:clone; box-decoration-break:clone;
    }

    /* Hindi, Marathi and other scripts: no tight letter spacing */
    .fc-fc-wrap .fc-fc--indic .fc-fc-text, .fc-fc-wrap .fc-fc--indic .fc-fc-pill,
    .fc-fc-wrap .fc-fc--indic .fc-fc-result-pill, .fc-fc-wrap .fc-fc--indic .fc-fc-result-text { letter-spacing:0; }
    .fc-fc-wrap .fc-fc.fc-fc--indic .fc-fc-pill { font-size:20px; padding-top:3px !important; }

    /* Text sizes by length, 1 (short) to 4 (long) */
    .fc-fc-wrap .fc-s1 .fc-fc-text { font-size:22px; }
    .fc-fc-wrap .fc-s2 .fc-fc-text { font-size:19px; }
    .fc-fc-wrap .fc-s3 .fc-fc-text { font-size:17px; }
    .fc-fc-wrap .fc-s4 .fc-fc-text { font-size:15.5px; }

    /* Wide card: two columns, as in the design. Everything scales with the card's width. */
    @container (min-width: 540px) {
        .fc-fc-wrap .fc-fc { display:grid; grid-template-columns:42% 1fr; grid-template-rows:1fr auto; grid-template-areas:"head boxes" "foot boxes"; align-items:stretch; column-gap:4cqi; row-gap:2.6cqi; padding:3.4cqi; border-radius:26px; }
        .fc-fc-wrap .fc-fc-head { grid-area:head; gap:2cqi; justify-content:center; }
        .fc-fc-wrap .fc-fc-boxes { grid-area:boxes; gap:5.2cqi; padding-top:2.4cqi; }
        .fc-fc-wrap .fc-fc-foot { grid-area:foot; align-self:end; }
        .fc-fc-wrap .fc-fc-box { flex:1 1 auto; padding:5.6cqi 3.4cqi 3.4cqi !important; border-radius:20px; }
        .fc-fc-wrap .fc-fc .fc-fc-pill { top:-3cqi; left:3.4cqi; height:5.6cqi; padding:0 4cqi !important; font-size:max(16px,3.4cqi); border-width:4px; }
        .fc-fc-wrap .fc-fc--indic .fc-fc-pill { font-size:max(15px,3.1cqi); }
        .fc-fc-wrap .fc-fc-logo { height:7.4cqi !important; }
        .fc-fc-wrap .fc-fc .fc-fc-tag { font-size:max(12px,2cqi); padding:.3cqi 1.8cqi !important; }
        .fc-fc-wrap .fc-fc-num { font-size:max(18px,3.3cqi); }
        .fc-fc-wrap .fc-fc-soc { width:3.3cqi; height:3.3cqi; }
        .fc-fc-wrap .fc-fc-result { gap:2cqi; padding-top:1.4cqi; }
        .fc-fc-wrap .fc-fc-result-pill, .fc-fc-wrap .fc-fc-result-text { font-size:max(16px,3cqi); }
        .fc-fc-wrap .fc-fc .fc-fc-result-pill { padding:.5cqi 3cqi !important; }
        .fc-fc-wrap .fc-s1 .fc-fc-text { font-size:max(18px,3.1cqi); }
        .fc-fc-wrap .fc-s2 .fc-fc-text { font-size:max(16px,2.65cqi); }
        .fc-fc-wrap .fc-s3 .fc-fc-text { font-size:max(15px,2.35cqi); }
        .fc-fc-wrap .fc-s4 .fc-fc-text { font-size:max(14px,2.05cqi); }
    }
    /* Small phones */
    @container (max-width: 359px) {
        .fc-fc-wrap .fc-fc { padding:12px; gap:16px; }
        .fc-fc-wrap .fc-fc-box { padding:32px 16px 18px !important; }
        .fc-fc-wrap .fc-fc .fc-fc-pill { padding:0 20px !important; font-size:19px; }
        .fc-fc-wrap .fc-fc-num { font-size:20px; }
        .fc-fc-wrap .fc-fc-result-pill, .fc-fc-wrap .fc-fc-result-text { font-size:17px; }
        .fc-fc-wrap .fc-s1 .fc-fc-text { font-size:19px; }
    }
    </style>

    <div class="fc-fc-wrap">
    <aside data-fc-card class="<?php echo esc_attr( $classes ); ?>" aria-label="<?php echo esc_attr( $words['fact'] ); ?>">

        <div class="fc-fc-head">
            <?php if ( $logo_url !== '' ) : ?>
            <img class="fc-fc-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" decoding="async">
            <?php else : ?>
            <p class="fc-fc-site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
            <?php endif; ?>

            <?php if ( $img_html !== '' ) : ?>
            <figure class="fc-fc-photo" style="aspect-ratio:<?php echo esc_attr( round( $ratio, 3 ) ); ?>">
                <?php echo $img_html; // Built and escaped by WordPress. ?>
                <?php if ( $stamp_url !== '' ) : ?>
                <img class="fc-fc-stamp<?php echo $wide_stamp ? ' fc-fc-stamp--wide' : ''; ?>" src="<?php echo esc_url( $stamp_url ); ?>" alt="<?php echo esc_attr( $rating_label ); ?>" decoding="async">
                <?php endif; ?>
            </figure>
            <?php endif; ?>
        </div>

        <div class="fc-fc-boxes">
            <?php if ( $claim !== '' ) : ?>
            <div class="fc-fc-box fc-fc-claim fc-s<?php echo (int) $size; ?>">
                <p class="fc-fc-pill"><?php echo esc_html( $words['claim'] ); ?></p>
                <p class="fc-fc-text"><?php echo esc_html( $claim ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( $fact !== '' ) : ?>
            <div class="fc-fc-box fc-fc-fact fc-s<?php echo (int) $size; ?>">
                <p class="fc-fc-pill"><?php echo esc_html( $words['fact'] ); ?></p>
                <p class="fc-fc-text"><?php echo $fact_html; // Escaped above; only <mark> added. ?></p>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( $number !== '' || $rating_label !== '' ) : ?>
        <div class="fc-fc-foot">
            <?php if ( $number !== '' ) :
                $wa_url = fc_site_profile()['whatsapp_url'];
                $wa_tag = $wa_url !== '' ? 'a' : 'div';
                $wa_att = $wa_url !== '' ? ' href="' . esc_url( $wa_url ) . '" target="_blank" rel="noopener noreferrer"' : '';
                ?>
            <div class="fc-fc-contact">
                <<?php echo $wa_tag; ?> class="fc-fc-wa"<?php echo $wa_att; // Escaped above. ?>>
                    <span class="fc-fc-tag"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="#25d366" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.198.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg><?php echo esc_html( $words['whatsapp'] ); ?></span>
                    <span class="fc-fc-num"><?php echo esc_html( $number ); ?></span>
                </<?php echo $wa_tag; ?>>
                <div class="fc-fc-follow">
                    <span class="fc-fc-tag"><?php echo esc_html( $words['follow'] ); ?></span>
                    <span class="fc-fc-socs">
                        <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs><linearGradient id="fc-ig" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#feda75"/><stop offset=".35" stop-color="#fa7e1e"/><stop offset=".65" stop-color="#d62976"/><stop offset="1" stop-color="#4f5bd5"/></linearGradient></defs></svg>
                        <?php echo fc_card_socials(); // Fixed markup; links escaped inside. ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $rating_label !== '' ) : ?>
            <div class="fc-fc-result">
                <p class="fc-fc-result-pill"><?php echo esc_html( $words['result'] ); ?></p>
                <p class="fc-fc-result-text"><?php echo esc_html( $rating_label ); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </aside>
    </div>
    <?php
    return ob_get_clean();
}
