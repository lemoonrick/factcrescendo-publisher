<?php
/**
 * Links become embeds by themselves.
 *
 * Paste the article from the Google Doc as it is and press Save. A link on a
 * line of its own — or code that was pasted as text — becomes the right block:
 *
 *   Archive.org          archive.org/details/…          → Custom HTML player
 *   Awesome Screenshot   awesomescreenshot.com/video/…  → Custom HTML player
 *   Instagram            post, reel or TV link          → Custom HTML player
 *   Facebook             post, video, reel, fb.watch    → Custom HTML player
 *   Vimeo                also the owner's /manage/ link → Embed block / player
 *   YouTube, X           → WordPress's own Embed block
 *   Our own pictures     …factcrescendo.com/wp-content/uploads/…png → Image block
 *   Other pasted code    e.g. the WhatsApp channel box  → Custom HTML block
 *
 * Author notes like "Embed" or "side by side", alone just above an embed or
 * picture, are deleted (the list is in Settings).
 *
 * All of this is saved into the article as ordinary WordPress blocks, so the
 * articles keep working if the plugin is ever removed. The player code is
 * always written by the plugin, never copied from the Doc, and only for the
 * sites above.
 *
 * Links inside a sentence are left alone. Posts published before this
 * arrived are left exactly as they were, even when edited later.
 *
 * Until the post is saved (e.g. in Preview), the same rules are applied
 * while the page is shown.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** True when links on this post should become embeds. */
function fc_embeds_apply( $post_id = 0 ) {
    if ( get_option( 'fc_auto_embeds', '1' ) !== '1' ) return false;

    $post = $post_id ? get_post( $post_id ) : null;
    if ( ! $post ) return true; // The editor's preview: no post to protect.
    if ( $post->post_status !== 'publish' ) return true;

    // Published before this feature: keep the article exactly as it was.
    $since = (int) get_option( 'fc_embeds_since', 0 );
    if ( ! $since || $post->post_date_gmt === '0000-00-00 00:00:00' ) return true;
    return strtotime( $post->post_date_gmt . ' UTC' ) >= $since;
}

// Remember when this arrived, for the rule above.
add_action( 'init', function () {
    // Autoloaded: read on every page, so it must not cost a database query each time.
    if ( ! get_option( 'fc_embeds_since' ) ) add_option( 'fc_embeds_since', time(), '', 'yes' );
} );


// ── Building the players ─────────────────────────────────────────────────────

/** Sets (array [w, h]) or reads (no argument) the shape the next player should have. */
function fc_embed_size_hint( $set = false ) {
    static $hint = null;
    if ( $set !== false ) $hint = ( is_array( $set ) && $set[0] > 0 && $set[1] > 0 ) ? [ (int) $set[0], (int) $set[1] ] : null;
    return $hint;
}

/** The width and height written in pasted embed code, or null. */
function fc_embed_code_size( $inner ) {
    $text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', $inner ) ), ENT_QUOTES, 'UTF-8' );
    $text = str_replace( [ "\u{201C}", "\u{201D}", "\u{2033}" ], '"', $text );
    if ( preg_match( '#\swidth\s*=\s*["\']?(\d{2,4})#i', $text, $w ) && preg_match( '#\sheight\s*=\s*["\']?(\d{2,4})#i', $text, $h ) ) return [ (int) $w[1], (int) $h[1] ];
    return null;
}

/**
 * One player. $w × $h is its shape; $wide players fill the column, the
 * others (social posts) keep their own width and sit in the middle.
 */
function fc_embed_frame( $src, $type, $w, $h, $wide = false ) {
    // The real shape of the video wins over our guess: a wide screen recording
    // in a 16:9 box would leave empty bands above and below it.
    $hint = fc_embed_size_hint();
    if ( $hint ) { $w = $hint[0]; $h = $hint[1]; }
    $box = $wide ? 'max-width:100%' : 'max-width:' . (int) $w . 'px';
    return '<div class="fc-embed fc-embed--' . esc_attr( $type ) . '" style="' . $box . ';margin:1.5em auto;">'
        . '<div style="position:relative;width:100%;aspect-ratio:' . (int) $w . '/' . (int) $h . ';">'
        . '<iframe src="' . esc_url( $src ) . '" title="' . esc_attr( ucfirst( $type ) ) . '" width="' . (int) $w . '" height="' . (int) $h . '"'
        . ' style="position:absolute;inset:0;width:100%;height:100%;border:0;" loading="lazy"'
        . ' allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen></iframe>'
        . '</div></div>';
}

/** The real address behind a Google redirect link (google.com/url?q=…). */
function fc_embed_unwrap_url( $url ) {
    $url   = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
    $parts = wp_parse_url( $url );
    if ( preg_match( '#(^|\.)google\.[a-z.]+$#i', $parts['host'] ?? '' ) && ( $parts['path'] ?? '' ) === '/url' ) {
        parse_str( $parts['query'] ?? '', $q );
        if ( ! empty( $q['q'] ) ) $url = (string) $q['q'];
        elseif ( ! empty( $q['url'] ) ) $url = (string) $q['url'];
    }
    return $url;
}

/**
 * The player for a link, or '' when the link isn't one we embed.
 * YouTube and X are left to WordPress (see fc_embed_paragraph).
 */
function fc_embed_html( $url ) {
    $url = fc_embed_unwrap_url( $url );
    if ( ! preg_match( '#^https?://#i', $url ) ) return '';

    // Archive.org: /details/ID (or /embed/ID) → /embed/ID
    if ( preg_match( '#^https?://(?:www\.)?archive\.org/(?:details|embed)/([^\s?\#]+)#i', $url, $m ) ) {
        return fc_embed_frame( 'https://archive.org/embed/' . rtrim( $m[1], '/' ), 'archive', 640, 480, true );
    }

    // Awesome Screenshot: /video/ID?key=KEY, or an embed address
    if ( preg_match( '#^https?://(?:www\.)?awesomescreenshot\.com/(?:video/(\d+)|embed)#i', $url, $m ) ) {
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
        $id  = ! empty( $m[1] ) ? $m[1] : ( $q['id'] ?? '' );
        $key = $q['key'] ?? ( $q['shareKey'] ?? '' );
        if ( ctype_digit( (string) $id ) && preg_match( '/^[A-Za-z0-9]+$/', (string) $key ) ) {
            return fc_embed_frame( 'https://www.awesomescreenshot.com/embed?id=' . $id . '&shareKey=' . $key, 'video', 640, 360, true );
        }
        return '';
    }

    // Google Maps: only the embed address Google itself gives out ("Share > Embed a map")
    if ( preg_match( '#^https://www\.google\.com/maps/embed\?pb=[A-Za-z0-9!%._~*()-]+$#', $url ) ) {
        return fc_embed_frame( $url, 'map', 600, 450, true );
    }

    // TikTok: its player address, without the autoplay settings an author may have copied along
    if ( preg_match( '#^https://www\.tiktok\.com/(?:player/v1|embed(?:/v2)?)/(\d{8,25})#', $url, $m ) ) {
        return fc_embed_frame( 'https://www.tiktok.com/player/v1/' . $m[1] . '?music_info=1&description=1', 'tiktok', 325, 578 );
    }

    // Instagram: /p/, /reel/, /reels/, /tv/ — tracking bits like ?igsh= dropped
    if ( preg_match( '#^https?://(?:www\.)?instagram\.com/(?:[A-Za-z0-9_.]+/)?(p|reels?|tv)/([A-Za-z0-9_-]+)#i', $url, $m ) ) {
        $kind = strtolower( $m[1] ) === 'reels' ? 'reel' : strtolower( $m[1] );
        return fc_embed_frame( 'https://www.instagram.com/' . $kind . '/' . $m[2] . '/embed/captioned/', 'instagram', 400, 720 );
    }

    // Facebook: posts, photos, videos, reels, watch, share links and fb.watch
    if ( preg_match( '#^https?://(?:(?:www|m|web|mbasic)\.)?(?:facebook\.com|fb\.watch)/#i', $url ) ) {
        return fc_embed_facebook( $url );
    }

    // Vimeo's owner link (/manage/videos/ID): Vimeo's own player for the public address
    if ( preg_match( '#^https?://(?:www\.)?vimeo\.com/manage/videos/\d#i', $url ) ) {
        return fc_embed_vimeo( fc_embed_vimeo_url( $url ) );
    }

    // A picture from one of our own sites, e.g. …/wp-content/uploads/2026/10/image-92.png
    if ( fc_embed_is_own_image( $url ) ) {
        return fc_embed_image( $url );
    }

    return '';
}

/** Facebook's own player for a post or video. */
function fc_embed_facebook( $url ) {
    // The plugin address itself (pasted embed code): use the post it shows.
    if ( preg_match( '#facebook\.com/plugins/(?:video|post)\.php#i', $url ) ) {
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
        $url = isset( $q['href'] ) ? (string) $q['href'] : '';
        if ( ! preg_match( '#^https?://(?:(?:www|m|web|mbasic)\.)?(?:facebook\.com|fb\.watch)/#i', $url ) ) return '';
    }

    // Short links say nothing about what they point to: ask Facebook once.
    if ( preg_match( '#^https?://(?:fb\.watch/|(?:[a-z]+\.)?facebook\.com/share/)#i', $url ) ) {
        $url = fc_embed_facebook_resolve( $url );
    }

    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    $is_video = (bool) preg_match( '#/(videos|reel|watch)\b|^/share/[vr]/#i', $path ) || stripos( $url, 'fb.watch' ) !== false;
    $is_post  = (bool) preg_match( '#/(posts|photos?|permalink|story|share|groups)\b|/permalink\.php|/story\.php|/photo\.php#i', $path );
    if ( ! $is_video && ! $is_post ) return ''; // A page or profile: leave the link.

    // One clean address on www.facebook.com, without tracking bits.
    $parts = wp_parse_url( $url );
    $clean = 'https://www.facebook.com' . ( $parts['path'] ?? '/' );
    parse_str( $parts['query'] ?? '', $q );
    $keep = array_intersect_key( $q, array_flip( [ 'v', 'story_fbid', 'id', 'fbid', 'set' ] ) );
    if ( $keep ) $clean .= '?' . http_build_query( $keep );
    if ( stripos( $url, 'fb.watch' ) !== false ) $clean = $url; // could not be resolved: Facebook reads it as is

    $href = rawurlencode( $clean );
    if ( preg_match( '#/reel/|^/share/r/#i', $path ) ) {
        return fc_embed_frame( 'https://www.facebook.com/plugins/video.php?href=' . $href . '&show_text=true&width=320', 'facebook', 320, 640 );
    }
    if ( $is_video ) {
        return fc_embed_frame( 'https://www.facebook.com/plugins/video.php?href=' . $href . '&show_text=false&width=500', 'facebook', 500, 281, true );
    }
    return fc_embed_frame( 'https://www.facebook.com/plugins/post.php?href=' . $href . '&show_text=true&width=500', 'facebook', 500, 640 );
}

/** Where a Facebook short link leads. Asked once a month at most; the link itself if Facebook won't say. */
function fc_embed_facebook_resolve( $url ) {
    $key    = 'fc_fb_' . md5( $url );
    $cached = get_transient( $key );
    if ( is_string( $cached ) && $cached !== '' ) return $cached;

    if ( ! fc_embed_may_look_up() ) return $url;

    $found    = $url;
    $response = wp_safe_remote_head( $url, [ 'redirection' => 0, 'timeout' => 3, 'user-agent' => 'Mozilla/5.0 (compatible; FactCrescendo)' ] );
    $to       = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'location' );
    if ( preg_match( '#^https://(?:(?:www|m|web)\.)?facebook\.com/(?!login|checkpoint)#i', $to ) ) $found = $to;

    set_transient( $key, $found, MONTH_IN_SECONDS );
    return $found;
}

/** "vimeo.com/manage/videos/123/abc" → "https://vimeo.com/123/abc", the address Vimeo embeds. */
function fc_embed_vimeo_url( $url ) {
    return preg_match( '#vimeo\.com/manage/videos/(\d+)(?:/([a-f0-9]+))?#i', $url, $m )
        ? 'https://vimeo.com/' . $m[1] . ( ! empty( $m[2] ) ? '/' . $m[2] : '' )
        : $url;
}

/**
 * Vimeo's player, as Vimeo itself describes it (it knows the private key of
 * unlisted videos). Remembered for a day, so pages don't ask Vimeo each time.
 */
function fc_embed_vimeo( $url ) {
    $key  = 'fc_vimeo_' . md5( $url );
    $html = get_transient( $key );
    if ( ! is_string( $html ) ) {
        if ( ! fc_embed_may_look_up() ) return '';
        add_filter( 'oembed_remote_get_args', 'fc_embed_quick_lookup' );
        $html = (string) wp_oembed_get( $url );
        remove_filter( 'oembed_remote_get_args', 'fc_embed_quick_lookup' );
        set_transient( $key, $html, $html !== '' ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
    }
    if ( ! preg_match( '#<iframe[^>]+src="(https://player\.vimeo\.com/video/[^"]+)"#i', $html, $m ) ) return '';
    $w = preg_match( '#\swidth="(\d+)"#', $html, $mw ) ? (int) $mw[1] : 640;
    $h = preg_match( '#\sheight="(\d+)"#', $html, $mh ) ? (int) $mh[1] : 360;
    $prev = fc_embed_size_hint();
    fc_embed_size_hint( $prev ?: [ $w, $h ] );   // the author's own size, if given, still wins
    $out = fc_embed_frame( html_entity_decode( $m[1] ), 'video', $w, $h, true );
    fc_embed_size_hint( $prev );
    return $out;
}

function fc_embed_quick_lookup( $args ) {
    $args['timeout'] = 3;
    return $args;
}

/** True for a picture in the uploads of this site or another Fact Crescendo site. */
function fc_embed_is_own_image( $url ) {
    $host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $home  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    $ours  = $host === $home || preg_match( '/(^|\.)factcrescendo\.com$/', $host );
    return $ours && preg_match( '#/wp-content/uploads/[^?\#]+\.(?:jpe?g|png|gif|webp)$#i', (string) wp_parse_url( $url, PHP_URL_PATH ) );
}

/** A picture by its address: the media library copy when this site has it, so phones get a smaller file. */
function fc_embed_image( $url ) {
    $id  = attachment_url_to_postid( $url );
    $img = $id ? wp_get_attachment_image( $id, 'large', false, [ 'loading' => 'lazy' ] ) : '';
    if ( $img === '' ) {
        $img = '<img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async">';
    }
    return '<figure class="wp-block-image aligncenter size-large fc-embed-img">' . $img . '</figure>';
}


// ── The editor's Embed block uses the same rules ─────────────────────────────

add_action( 'init', 'fc_embed_register_handlers' );

function fc_embed_register_handlers() {
    $patterns = [
        'archive'   => '#https?://(?:www\.)?archive\.org/details/\S+#i',
        'awesome'   => '#https?://(?:www\.)?awesomescreenshot\.com/(?:video/\d+|embed\?)\S*#i',
        'instagram' => '#https?://(?:www\.)?instagram\.com/(?:[A-Za-z0-9_.]+/)?(?:p|reels?|tv)/[A-Za-z0-9_-]+\S*#i',
        'facebook'  => '#https?://(?:(?:www|m|web|mbasic)\.)?(?:facebook\.com|fb\.watch)/\S+#i',
        'vimeo'     => '#https?://(?:www\.)?vimeo\.com/manage/videos/\d+\S*#i',
        'image'     => '#https?://\S+/wp-content/uploads/\S+\.(?:jpe?g|png|gif|webp)#i',
    ];
    foreach ( $patterns as $name => $regex ) {
        // Priority 9: before WordPress's own Vimeo rule, which can't read /manage/ links.
        wp_embed_register_handler( 'fc_' . $name, $regex, 'fc_embed_handler', 9 );
    }
}

function fc_embed_handler( $matches, $attr, $url, $rawattr ) {
    if ( ! fc_embeds_apply( (int) get_the_ID() ) ) return false; // as WordPress would do without us
    $html = fc_embed_html( $url );
    return $html !== '' ? $html : false;
}


// One standard line of space above and below every player. Articles saved
// before this was added carry the old inline style, so it is corrected here.
add_action( 'wp_head', function () {
    if ( is_singular() ) echo '<style>.fc-embed[style*="margin:0 auto"]{margin-top:1.5em !important;margin-bottom:1.5em !important}</style>' . "\n";
} );


// ── Turning the pasted article into embeds ───────────────────────────────────

// Before WordPress's own link embedding (8) and the blocks (9).
add_filter( 'the_content', 'fc_embed_content', 7 );

function fc_embed_content( $content ) {
    if ( is_admin() || strpos( (string) $content, '<p' ) === false ) return $content;
    if ( ! fc_embeds_apply( (int) get_the_ID() ) ) return $content;

    $content = preg_replace_callback( '#<p(?:\s[^>]*)?>(.*?)</p>#is', 'fc_embed_paragraph', $content );

    // An author's note "Embed" just above a player has done its job.
    return preg_replace(
        '#<p(?:\s[^>]*)?>\s*(?:<(?:strong|b|em|span)[^>]*>\s*)*embed\s*:?\s*(?:</(?:strong|b|em|span)>\s*)*</p>(\s*(?:<!--\s*/?wp:[^>]*-->\s*)*)(?=<figure class="wp-block-(?:embed|image)[^"]*fc-embed)#i',
        '$1',
        $content
    );
}

/** One paragraph: a player if it holds only a link or only embed code, otherwise unchanged. */
function fc_embed_paragraph( $m ) {
    $inner = $m[1];
    $url   = fc_embed_lone_link( $inner );
    $size  = null;

    if ( $url === '' ) {
        $src = fc_embed_code_src( $inner );
        if ( $src === '' ) return $m[0];
        $url = fc_embed_src_to_url( $src );
        if ( $url === '' ) return $m[0];
        $size = fc_embed_code_size( $inner );
    }

    fc_embed_size_hint( $size ?: null );
    $html = fc_embed_html( $url );
    fc_embed_size_hint( null );

    // YouTube, X and Vimeo: WordPress's own embedding, as when the link is pasted by hand.
    if ( $html === '' && preg_match( '#^https?://(?:(?:www|m)\.)?(?:youtube\.com/(?:watch|shorts/|live/)|youtu\.be/|(?:twitter|x)\.com/\w{1,15}/status/|vimeo\.com/\d)#i', $url ) ) {
        $url  = preg_replace( '#^https?://(?:www\.)?x\.com/#i', 'https://twitter.com/', $url );
        if ( stripos( $url, 'twitter.com/' ) !== false ) $url = strtok( $url, '?' );
        $made = $GLOBALS['wp_embed']->shortcode( [], $url );
        if ( preg_match( '#<iframe[^>]+src="(https://[^"]+)"#i', (string) $made, $f ) ) {
            // A fixed-size player (YouTube) gets a frame that fits phones.
            $html = fc_embed_frame( html_entity_decode( $f[1] ), 'video', 640, 360, true );
        } elseif ( preg_match( '#<blockquote\b#i', (string) $made ) ) {
            $html = $made; // X: a quoted post that sizes itself
        }
    }

    if ( $html === '' ) return $m[0];
    if ( strpos( $html, '<figure' ) === 0 ) return $html; // a picture is already a figure
    return '<figure class="wp-block-embed fc-embed-auto"><div class="wp-block-embed__wrapper">' . $html . '</div></figure>';
}

/**
 * The address when a paragraph is ONLY one link (or only one address), else ''.
 *
 * Strict on purpose: a paragraph is replaced by a player only when nothing
 * else in it would be lost. Two links on a line ("Facebook Post | Archive
 * link"), a link with words around it, or a second address all leave the
 * paragraph exactly as it was written.
 */
function fc_embed_lone_link( $inner ) {
    // Formatting the Doc brings along (bold, highlight, spans) doesn't count.
    $bare = trim( preg_replace( '#</?(?:span|strong|b|em|i|u|mark|br)\b[^>]*>|&nbsp;#i', ' ', $inner ) );

    $anchor = '#<a\s[^>]*?href=(["\'])(.*?)\1[^>]*>((?:(?!</a>).)*)</a>#is';
    if ( preg_match_all( $anchor, $bare, $a, PREG_SET_ORDER ) ) {
        if ( count( $a ) !== 1 || trim( preg_replace( $anchor, '', $bare ) ) !== '' ) return '';
        $text = trim( html_entity_decode( wp_strip_all_tags( $a[0][3] ), ENT_QUOTES, 'UTF-8' ) );
        // Only when the link shows its own address. "Facebook Post" is a word in a sentence.
        if ( ! preg_match( '#^(?:https?://|www\.)\S+$#i', $text ) ) return '';
        return fc_embed_unwrap_url( $a[0][2] );
    }
    if ( preg_match( '#^https?://[^\s<>"]+$#i', $bare ) ) return fc_embed_unwrap_url( $bare );
    return '';
}

/** The src of embed code pasted as text (<iframe src="…">), when that is all the paragraph holds, else ''. */
function fc_embed_code_src( $inner ) {
    $text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', $inner ) ), ENT_QUOTES, 'UTF-8' );
    $text = str_replace( [ "\u{201C}", "\u{201D}", "\u{2033}", "\u{2018}", "\u{2019}", "\u{00A0}" ], [ '"', '"', '"', "'", "'", ' ' ], $text );
    $text = trim( $text );
    if ( ! preg_match( '#^<iframe\b.*</iframe>$#is', $text ) || substr_count( strtolower( $text ), '<iframe' ) !== 1 ) return '';
    if ( ! preg_match( '#\ssrc\s*=\s*(["\'])(.*?)\1#is', $text, $s ) ) return '';
    return preg_replace( '/\s+/', '', $s[2] ); // A long address may have been broken over lines.
}

/**
 * Puts back the "?" that went missing in a pasted embed address ("…/video.php" then
 * "height=476&href=…"). Seen when a Doc's code reaches the editor with a line break, a
 * space or a stray control character where the "?" should be. Only for the three
 * addresses whose parameters are known, and only when the "?" really is missing.
 */
function fc_embed_repair_src( $src ) {
    $gap = '[\s\p{Cc}\x{2028}\x{2029}\x{FFFD}\x{FF1F}]*';
    return preg_replace( [
        '#(facebook\.com/plugins/(?:video|post)\.php)' . $gap . '(?=(?:height|href|width|show_text|t|appId)=)#iu',
        '#(awesomescreenshot\.com/embed)' . $gap . '(?=(?:id|shareKey)=)#iu',
        '#(google\.com/maps/embed)' . $gap . '(?=pb=)#iu',
    ], '$1?', (string) $src );
}

/** The ordinary address for an embed code's src, for the sites we trust; '' for anything else. */
function fc_embed_src_to_url( $src ) {
    $src = fc_embed_repair_src( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );
    if ( strpos( $src, '//' ) === 0 ) $src = 'https:' . $src;

    if ( preg_match( '#^https?://(?:www\.)?(?:facebook\.com/plugins/|awesomescreenshot\.com/embed|archive\.org/embed/)#i', $src ) ) return $src;
    if ( preg_match( '#^https://www\.google\.com/maps/embed\?pb=[A-Za-z0-9!%._~*()-]+$#', $src ) ) return $src;
    if ( preg_match( '#^https://www\.tiktok\.com/(?:player/v1|embed(?:/v2)?)/(\d{8,25})#', $src ) ) return $src;
    if ( preg_match( '#^https?://(?:www\.)?instagram\.com/((?:p|reels?|tv)/[A-Za-z0-9_-]+)#i', $src, $m ) ) return 'https://www.instagram.com/' . $m[1] . '/';
    if ( preg_match( '#^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,})#i', $src, $m ) ) return 'https://www.youtube.com/watch?v=' . $m[1];
    if ( preg_match( '#^https?://player\.vimeo\.com/video/(\d+)#i', $src, $m ) ) {
        parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $q );
        return 'https://vimeo.com/' . $m[1] . ( ! empty( $q['h'] ) && ctype_xdigit( $q['h'] ) ? '/' . $q['h'] : '' );
    }
    return '';
}


// ── Saved into the article itself, so it lasts without the plugin ───────────

/** Author notes deleted when they sit alone just above an embed or picture. One per line in Settings. */
function fc_embed_default_notes() {
    return "embed\nembed here\nembed below\nembed link\nembed video\nembed post\nembed tweet\nembed code\nembed image\nembed photo\n"
        . "insert\ninsert here\ninsert embed\ninsert image\ninsert photo\ninsert video\ninsert link\n"
        . "add embed\nadd image\nadd photo\nadd video\nside by side\nimages side by side\nphotos side by side\n"
        . "image\nimages\nphoto\nphotos\nscreenshot\nscreenshots\nvideo\nlink\nlink here\n"
        . "archive\narchive link\narchive links\narchive url\narchive here\narchive embed\narchive video\narchive version\n"
        . "archived\narchived link\narchived video\narchived version\narchive.org\narchive.org link\narchive.org video\n"
        . "link to archive\nlink of archive\narchive of the video\narchive of the post\n"
        . "production note*";
}

/** The list that shipped before the Archive words were added. A site still on it gets the new default. */
function fc_embed_previous_default_notes() {
    return "embed\nembed here\nembed below\nembed link\nembed video\nembed post\nembed tweet\nembed code\nembed image\nembed photo\n"
        . "insert\ninsert here\ninsert embed\ninsert image\ninsert photo\ninsert video\ninsert link\n"
        . "add embed\nadd image\nadd photo\nadd video\nside by side\nimages side by side\nphotos side by side\n"
        . "image\nimages\nphoto\nphotos\nscreenshot\nscreenshots\nvideo\nlink\nlink here";
}

/** The note list as saved in Settings; a list nobody has changed follows the plugin's default. */
function fc_embed_notes_text() {
    $saved = get_option( 'fc_embed_notes', false );
    // Browsers send the Settings box back with Windows line endings: compare without them.
    $norm  = function ( $t ) { return trim( preg_replace( '/[ \t]*\R[ \t]*/u', "\n", (string) $t ) ); };
    $old   = [ $norm( fc_embed_previous_default_notes() ), $norm( preg_replace( '/\nproduction note\*$/', '', fc_embed_default_notes() ) ), $norm( fc_embed_default_notes() ) ];
    if ( $saved === false || $norm( $saved ) === '' || in_array( $norm( $saved ), $old, true ) ) return fc_embed_default_notes();
    return (string) $saved;
}

/**
 * Lines in the list that end in * mean "any line that starts like this", e.g.
 * "production note*" catches "[PRODUCTION NOTE: These three posts could be
 * merged." Those are deleted wherever they stand alone, not only above an
 * embed: no article starts a paragraph that way.
 */
function fc_embed_note_prefixes() {
    $out = [];
    foreach ( preg_split( '/\R/u', fc_embed_notes_text() ) as $line ) {
        if ( substr( trim( $line ), -1 ) !== '*' ) continue;
        $key = fc_embed_note_key( rtrim( trim( $line ), '*' ) );
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $key ) >= 6 : strlen( $key ) >= 6 ) $out[] = $key;   // too short a start would catch real text
    }
    /** Filter: the "starts with" notes, as plain lowercase text. */
    return apply_filters( 'fc_embed_note_prefixes', $out );
}

function fc_embed_is_prefix_note( $html ) {
    $key = fc_embed_note_key( $html );
    foreach ( fc_embed_note_prefixes() as $prefix ) {
        // The whole word must match: "production note:" yes, "production notes are kept" no.
        if ( strpos( $key, $prefix ) === 0 && ! preg_match( '/^[\p{L}\p{N}]/u', substr( $key, strlen( $prefix ) ) ) ) return true;
    }
    return false;
}

function fc_embed_notes() {
    $list  = fc_embed_notes_text();
    $words = [];
    foreach ( preg_split( '/\R/u', $list ) as $line ) {
        if ( substr( trim( $line ), -1 ) === '*' ) continue; // a "starts with" note, see below
        $line = fc_embed_note_key( $line );
        if ( $line !== '' ) $words[ $line ] = true;
    }
    /** Filter: the author notes to delete, as [ 'embed' => true, … ]. */
    return apply_filters( 'fc_embed_notes', $words );
}

/** "  Embed: " → "embed", "(Side-by-side)" → "side by side", so small differences still match. */
function fc_embed_note_key( $text ) {
    $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
    $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
    $text = preg_replace( '/[\s\x{00A0}\-–—_]+/u', ' ', $text );
    $text = trim( $text, " \t:.;,!*()[]{}\"'“”‘’|>" );
    return preg_replace( '/^please\s+/u', '', $text );   // "Please embed" is the note "embed"
}

/** The blocks that stand in for a link, ready to save, or '' when the link isn't one we embed. */
function fc_embed_block( $url ) {
    // YouTube, X, Vimeo: WordPress's own Embed block, exactly as when the link is pasted by hand.
    if ( preg_match( '#^https?://(?:(?:www|m)\.)?(?:youtube\.com/(?:watch|shorts/|live/)|youtu\.be/)#i', $url ) ) {
        return fc_embed_core_block( $url, 'video', 'youtube', 'wp-embed-aspect-16-9 wp-has-aspect-ratio' );
    }
    if ( preg_match( '#^https?://(?:(?:www|mobile)\.)?(?:twitter|x)\.com/(\w{1,15})/status/(\d+)#i', $url, $m ) ) {
        return fc_embed_core_block( 'https://twitter.com/' . $m[1] . '/status/' . $m[2], 'rich', 'twitter', '' );
    }
    if ( preg_match( '#^https?://(?:www\.)?vimeo\.com/\d+#i', $url ) ) {
        return fc_embed_core_block( strtok( $url, '?' ), 'video', 'vimeo', 'wp-embed-aspect-16-9 wp-has-aspect-ratio' );
    }

    $html = fc_embed_html( $url );
    if ( $html === '' ) return '';

    if ( fc_embed_is_own_image( fc_embed_unwrap_url( $url ) ) ) {
        return fc_embed_image_block( fc_embed_unwrap_url( $url ) );
    }
    // Everything else: a Custom HTML block holding the player.
    return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
}

function fc_embed_core_block( $url, $type, $provider, $class ) {
    $attrs = [ 'url' => $url, 'type' => $type, 'providerNameSlug' => $provider, 'responsive' => true ];
    if ( $class !== '' ) $attrs['className'] = $class;
    $classes = trim( 'wp-block-embed is-type-' . $type . ' is-provider-' . $provider . ' wp-block-embed-' . $provider . ' ' . $class );
    return '<!-- wp:embed ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . " -->\n"
        . '<figure class="' . esc_attr( $classes ) . '"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $url ) . "\n</div></figure>\n"
        . '<!-- /wp:embed -->';
}

function fc_embed_image_block( $url ) {
    $id = attachment_url_to_postid( $url );
    if ( $id ) {
        $src = wp_get_attachment_image_url( $id, 'large' ) ?: $url;
        return '<!-- wp:image {"id":' . (int) $id . ',"sizeSlug":"large","linkDestination":"none","align":"center"} -->' . "\n"
            . '<figure class="wp-block-image aligncenter size-large"><img src="' . esc_url( $src ) . '" alt="" class="wp-image-' . (int) $id . '"/></figure>' . "\n"
            . '<!-- /wp:image -->';
    }
    return '<!-- wp:image {"align":"center"} -->' . "\n"
        . '<figure class="wp-block-image aligncenter"><img src="' . esc_url( $url ) . '" alt=""/></figure>' . "\n"
        . '<!-- /wp:image -->';
}

/**
 * Code pasted as text that isn't a player — e.g. the Sri Lanka "Subscribe to
 * our WhatsApp Channel" box — becomes a Custom HTML block. Cleaned the same
 * way WordPress cleans articles: no scripts, no event handlers.
 */
function fc_embed_code_block( $inner ) {
    $text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', $inner ) ), ENT_QUOTES, 'UTF-8' );
    $text = trim( str_replace( [ "\u{201C}", "\u{201D}", "\u{2033}", "\u{2018}", "\u{2019}", "\u{00A0}" ], [ '"', '"', '"', "'", "'", ' ' ], $text ) );
    if ( ! preg_match( '#^<([a-z][a-z0-9]*)\b[^>]*>.*</\1>$#is', $text ) ) return '';

    // A quoted X post or Instagram post: the proper embed instead of the code.
    if ( stripos( $text, 'twitter-tweet' ) !== false && preg_match( '#https?://(?:twitter|x)\.com/\w{1,15}/status/\d+#i', $text, $t ) ) {
        return fc_embed_block( $t[0] );
    }
    if ( stripos( $text, 'instagram-media' ) !== false && preg_match( '#https?://(?:www\.)?instagram\.com/(?:p|reels?|tv)/[A-Za-z0-9_-]+#i', $text, $t ) ) {
        return fc_embed_block( $t[0] );
    }

    $clean = trim( wp_kses_post( preg_replace( '#<(script|style)\b.*?</\1>#is', '', $text ) ) );
    if ( $clean === '' || strpos( $clean, '<' ) === false ) return '';
    return "<!-- wp:html -->\n" . $clean . "\n<!-- /wp:html -->";
}

/**
 * Rewrites a pasted article into ordinary WordPress blocks: links and pasted
 * code become Embed, Custom HTML and Image blocks; author notes above them
 * are deleted. Anything else is left exactly as it is.
 */
function fc_embed_convert( $content, $first = true ) {
    if ( strpos( $content, '<!-- wp:' ) === false ) return $content; // classic editor: not touched

    // Paragraph blocks holding only a link, embed code, or pasted HTML.
    $content = preg_replace_callback(
        '#<!-- wp:paragraph(?:\s+\{[^\n]*?\})?\s+-->\s*<p\b[^>]*>((?:(?!</p>).)*)</p>\s*<!-- /wp:paragraph -->#s',
        function ( $m ) {
            $url  = fc_embed_lone_link( $m[1] );
            $size = null;
            if ( $url === '' ) {
                $src = fc_embed_code_src( $m[1] );
                $url = $src !== '' ? fc_embed_src_to_url( $src ) : '';
                if ( $url === '' ) {
                    $code = fc_embed_code_block( $m[1] );
                    return $code !== '' ? $code : $m[0];
                }
                $size = fc_embed_code_size( $m[1] );
            }
            fc_embed_size_hint( $size ?: null );
            $block = fc_embed_block( $url );
            fc_embed_size_hint( null );
            return $block !== '' ? $block : $m[0];
        },
        $content
    );

    // Embed blocks WordPress can't show on its own (Instagram, Facebook, …) become Custom HTML.
    $content = preg_replace_callback(
        '#<!-- wp:embed\s+(\{[^\n]*?\})\s+-->.*?<!-- /wp:embed -->#s',
        function ( $m ) {
            $attrs = json_decode( $m[1], true );
            $url   = is_array( $attrs ) ? (string) ( $attrs['url'] ?? '' ) : '';
            if ( $url === '' || preg_match( '#youtube\.com|youtu\.be|twitter\.com|x\.com/|vimeo\.com/\d#i', $url ) ) return $m[0];
            $html = fc_embed_html( $url );
            if ( $html === '' ) return $m[0];
            return fc_embed_is_own_image( $url ) ? fc_embed_image_block( $url ) : "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
        },
        $content
    );

    // Layout, only the first time the article is saved: after that, whatever the editor arranges
    // by hand (a picture inside a paragraph, a table of pictures) is their choice and stays.
    if ( $first ) {
        // A picture stuck inside a paragraph (pasted right next to a line of text) gets its own block.
        $content = preg_replace_callback(
            '#<!-- wp:paragraph(\s+\{[^\n]*?\})?\s+-->\s*(<p\b[^>]*>)((?:(?!</p>).)*)</p>\s*<!-- /wp:paragraph -->#s',
            'fc_embed_split_pictures',
            $content
        );

        // A table that holds only pictures (writers use one to put pictures side by side) becomes rows of pictures.
        $content = preg_replace_callback( '#<!-- wp:table(?:\s+\{[^\n]*?\})?\s+-->(?:(?!<!-- /wp:table -->).)*<!-- /wp:table -->#s', 'fc_embed_picture_table', $content );
    }

    // A "side by side" note just above two or more pictures: the pictures go side by side.
    // Nothing else puts pictures side by side.
    $image   = '<!-- wp:image(?:\s+\{[^\n]*?\})?\s+-->(?:(?!<!-- /wp:image -->).)*<!-- /wp:image -->';
    $blank   = '<!-- wp:paragraph(?:\s+\{[^\n]*?\})?\s+-->\s*<p\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</p>\s*<!-- /wp:paragraph -->';
    $content = preg_replace_callback(
        '#(<!-- wp:paragraph(?:\s+\{[^\n]*?\})?\s+-->\s*<p\b[^>]*>((?:(?!</p>).)*)</p>\s*<!-- /wp:paragraph -->)\s*((?:' . $image . '\s*(?:' . $blank . '\s*)*){2,})#s',
        function ( $m ) use ( $image ) {
            // Only a note (which is then removed, so this happens once and never undoes a layout
            // made by hand). A real sentence that happens to say "side by side" changes nothing.
            if ( ! fc_embed_says_side_by_side( $m[2] ) || ! fc_embed_is_side_note( $m[2] ) ) return $m[0];
            preg_match_all( '#' . $image . '#s', $m[3], $all );
            if ( count( $all[0] ) < 2 ) return $m[0];
            return fc_embed_columns( fc_embed_side_rows( $all[0] ) ) . "\n\n";
        },
        $content
    );

    // Production notes ("[PRODUCTION NOTE: ...", any spelling) and the other "starts with" notes, wherever they are.
    $content = preg_replace_callback(
        '#(<!-- wp:paragraph(?:\s+\{[^\n]*?\})?\s+-->\s*<p\b[^>]*>)((?:(?!</p>).)*)(</p>\s*<!-- /wp:paragraph -->)\s*#s',
        function ( $m ) {
            $rest = fc_embed_strip_production_note( $m[2] );
            if ( $rest === null ) return fc_embed_is_prefix_note( $m[2] ) ? '' : $m[0];
            return $rest === '' ? '' : $m[1] . $rest . $m[3] . "\n\n";
        },
        $content
    );

    // Author notes ("Embed", "side by side" …) alone just above an embed or picture.
    $notes = fc_embed_notes();
    do {
        $before  = $content;
        // …also when the author typed it as code, e.g. "<p>Side by Side</p>", which became a Custom HTML block.
        $content = preg_replace_callback(
            '#<!-- wp:html -->\s*((?:(?!<!-- /wp:html -->).)*?)\s*<!-- /wp:html -->\s*(?=<!-- wp:(?:embed|html|image|gallery|video|columns)\b)#s',
            function ( $m ) use ( $notes ) {
                // Only plain text in simple tags: never a player, picture or link.
                if ( preg_match( '#<(?!/?(?:p|strong|b|em|i|span|br|h[1-6]|div)\b)#i', $m[1] ) ) return $m[0];
                return isset( $notes[ fc_embed_note_key( $m[1] ) ] ) ? '' : $m[0];
            },
            $content
        );
        $content = preg_replace_callback(
            '#<!-- wp:paragraph(?:\s+\{[^\n]*?\})?\s+-->\s*<p\b[^>]*>((?:(?!</p>).)*)</p>\s*<!-- /wp:paragraph -->\s*(?=<!-- wp:(?:embed|html|image|gallery|video|columns)\b)#s',
            function ( $m ) use ( $notes ) {
                return isset( $notes[ fc_embed_note_key( $m[1] ) ] ) ? '' : $m[0];
            },
            $content
        );
    } while ( $content !== $before );

    return $content;
}

/**
 * One paragraph that holds text and a picture becomes separate blocks: the
 * text before, the picture, the text after.
 *
 * The article must never be hurt by this, so it is deliberately strict and
 * leaves the paragraph exactly as it was whenever anything is unusual:
 *   - the text is cut at the picture, never rewritten: every piece is the
 *     original HTML, character for character, and the words before and after
 *     are checked to be exactly the same as before;
 *   - a small picture (an icon in a sentence, under 100 pixels) stays inline;
 *   - a picture inside a link, bold text or any other tag is left alone;
 *   - a paragraph with a style or alignment of its own is left alone.
 */
function fc_embed_split_pictures( $m ) {
    $attrs = isset( $m[1] ) ? trim( $m[1] ) : '';
    $open  = $m[2];
    $inner = $m[3];
    if ( stripos( $inner, '<img' ) === false ) return $m[0];
    if ( $attrs !== '' || ! preg_match( '#^<p>$#i', $open ) ) return $m[0];

    // Walk the tags: every picture must sit directly in the paragraph, not inside another tag.
    $void  = [ 'br', 'img', 'wbr' ];
    $depth = 0;
    $cuts  = [];
    if ( ! preg_match_all( '#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#', $inner, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) return $m[0];
    foreach ( $tags as $t ) {
        $name = strtolower( $t[2][0] );
        if ( $name === 'img' ) {
            if ( $depth !== 0 ) return $m[0];
            $tag = $t[0][0];
            if ( fc_embed_inline_size( $tag ) !== 0 && fc_embed_inline_size( $tag ) < 100 ) return $m[0];
            // Only an ordinary web address: a picture written into the page itself (data:) or a
            // file on someone's computer could not be moved safely.
            if ( ! preg_match( '#\ssrc=("|\')(https?://|/)[^"\']+\1#i', $tag ) ) return $m[0];
            $cuts[] = [ $t[0][1], strlen( $tag ), $tag ];
            continue;
        }
        if ( in_array( $name, $void, true ) || $t[3][0] === '/' ) continue;
        $depth += ( $t[1][0] === '/' ) ? -1 : 1;
        if ( $depth < 0 ) return $m[0];
    }
    if ( ! $cuts || $depth !== 0 ) return $m[0];

    // Cut the paragraph's own HTML at each picture.
    $pieces = [];
    $pos    = 0;
    foreach ( $cuts as $c ) {
        $pieces[] = [ 'text', substr( $inner, $pos, $c[0] - $pos ) ];
        $pieces[] = [ 'img', $c[2] ];
        $pos = $c[0] + $c[1];
    }
    $pieces[] = [ 'text', substr( $inner, $pos ) ];

    $out = [];
    $kept_text = '';
    foreach ( $pieces as $piece ) {
        if ( $piece[0] === 'img' ) {
            $out[] = fc_embed_picture_block( $piece[1] );
            continue;
        }
        // Only line breaks and spaces at the cut are dropped.
        $text = preg_replace( '#^(?:\s|&nbsp;|<br\s*/?>)+|(?:\s|&nbsp;|<br\s*/?>)+$#i', '', $piece[1] );
        if ( fc_embed_words( $text ) === '' ) continue;
        $kept_text .= ' ' . $text;
        $out[] = "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
    }

    // The check: the same words, in the same order, and the same number of pictures.
    $no_pics = preg_replace( '#<img\b[^>]*>#i', ' ', $inner );
    if ( fc_embed_words( $no_pics ) !== fc_embed_words( $kept_text ) ) return $m[0];
    if ( substr_count( implode( '', $out ), '<img ' ) !== count( $cuts ) ) return $m[0];

    return implode( "\n\n", $out );
}

/**
 * A production note, however it is written: "[PRODUCTION NOTE: …",
 * "Production Notes -", "PRODUCTION-NOTE", "(prod. note) …", "Note to
 * production: …".
 *
 * Returns null when the paragraph is not one (it stays as it is), '' when the
 * whole paragraph is the note, or the paragraph without the note when the note
 * was in brackets and the article text carries on after it. Never touches a
 * sentence like "Production notes are kept by the team." — a note has a
 * bracket, a colon or dash after the label, or is written in capitals.
 */
function fc_embed_strip_production_note( $html ) {
    $lead  = '(?:\s|&nbsp;|<[^>]+>)*';                       // formatting before the label
    $label = '(?:production|prod\.?)[\s\-_]*notes?|notes?\s+(?:to|for)\s+(?:the\s+)?production';

    // In brackets: "[PRODUCTION NOTE: …]" or "(production note …)".
    if ( preg_match( '#^' . $lead . '([\[\(])' . $lead . '(?:' . $label . ')\b#iu', $html, $mm ) ) {
        $close = $mm[1] === '[' ? ']' : ')';
        $end   = strpos( $html, $close, strlen( $mm[0] ) );
        if ( $end === false ) return '';                       // never closed: the whole line is the note
        $rest = preg_replace( '#^(?:\s|&nbsp;|</[^>]+>)+#', '', substr( $html, $end + 1 ) );
        return fc_embed_words( $rest ) === '' ? '' : force_balance_tags( $rest );   // no stray closing tags left behind
    }

    $text = fc_embed_words( $html );
    if ( ! preg_match( '#^[\*\s]*(' . $label . ')\b(.?)#iu', $text, $t ) ) return null;
    $after_ok = $t[2] === '' || preg_match( '#^[:\-–—\]\)\*]#u', $t[2] );
    $shouted  = preg_match( '#^[\*\s]*(?:PRODUCTION|PROD)#', $text ) === 1;
    return ( $after_ok || $shouted ) ? '' : null;
}

/** True when the text asks for side by side, however it is written: "Side by Side", "SIDE-BY-SIDE", "side_by_side", "sidebyside" ... */
function fc_embed_says_side_by_side( $html ) {
    $low = function_exists( 'mb_strtolower' ) ? mb_strtolower( fc_embed_words( $html ) ) : strtolower( fc_embed_words( $html ) );
    return strpos( preg_replace( '/[^a-z]+/', '', $low ), 'sidebyside' ) !== false;
}

/**
 * True when that paragraph is only an instruction, so it can be deleted: a few
 * words, in brackets, or with the word "note". "The viral image and the
 * original are shown side by side below for comparison." is a sentence of the
 * article: the pictures still go side by side, the sentence stays.
 */
function fc_embed_is_side_note( $html ) {
    $low = function_exists( 'mb_strtolower' ) ? mb_strtolower( fc_embed_words( $html ) ) : strtolower( fc_embed_words( $html ) );
    if ( $low === '' ) return false;
    $words = count( preg_split( '/\s+/u', trim( $low ) ) );
    return $words <= 6 || (bool) preg_match( '/^[\[\(\*\{]/u', $low ) || (bool) preg_match( '/\bnote\b/u', $low );
}

/** Pictures under a "side by side" note: 2 → one row of 2, 3 → one row of 3, 4 or more → 2 to a row. */
function fc_embed_side_rows( $images ) {
    return fc_embed_rows( $images );
}

/**
 * Places in one row (a picture, or null for an empty place): up to 3 stay in
 * one row; 4 or more go 2 to a row, the last one padded so every picture is
 * the same size.
 */
function fc_embed_rows( $places ) {
    if ( count( $places ) <= 3 ) return [ $places ];
    $rows = [];
    foreach ( array_chunk( $places, 2 ) as $row ) {
        if ( count( $row ) === 1 ) $row[] = null;
        $rows[] = $row;
    }
    return $rows;
}

/** Rows of places as Columns blocks: one column per place, an Image block in each, empty places stay empty. */
function fc_embed_columns( $rows ) {
    $out = [];
    foreach ( $rows as $row ) {
        $cols = [];
        foreach ( $row as $img ) {
            $cols[] = $img === null
                ? "<!-- wp:column -->\n<div class=\"wp-block-column\"></div>\n<!-- /wp:column -->"
                : "<!-- wp:column -->\n<div class=\"wp-block-column\">" . trim( $img ) . "</div>\n<!-- /wp:column -->";
        }
        $out[] = "<!-- wp:columns -->\n<div class=\"wp-block-columns\">" . implode( "\n\n", $cols ) . "</div>\n<!-- /wp:columns -->";
    }
    return implode( "\n\n", $out );
}

/**
 * A Table block that holds only pictures (no words at all) is copied row by
 * row into Columns blocks: each picture keeps its place, an empty cell stays an
 * empty place. A row with a single cell is just the picture. A table with any
 * text is left alone.
 */
function fc_embed_picture_table( $m ) {
    $block = $m[0];
    if ( stripos( $block, '<img' ) === false ) return $block;
    if ( fc_embed_words( preg_replace( '#<img\b[^>]*>#i', '', preg_replace( '#<!--.*?-->#s', '', $block ) ) ) !== '' ) return $block;
    if ( ! preg_match_all( '#<tr\b[^>]*>(.*?)</tr>#is', $block, $trs ) ) return $block;

    $out   = [];
    $count = 0;
    foreach ( $trs[1] as $tr ) {
        if ( ! preg_match_all( '#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $tr, $cells ) ) continue;
        $places = [];
        foreach ( $cells[1] as $cell ) {
            if ( ! preg_match_all( '#<img\b[^>]*>#i', $cell, $imgs ) ) { $places[] = null; continue; }
            foreach ( $imgs[0] as $tag ) {
                // Only ordinary web addresses can be moved safely.
                if ( ! preg_match( '#\ssrc=("|\')(https?://|/)[^"\']+\1#i', $tag ) ) return $block;
                $places[] = fc_embed_picture_block( $tag );
                $count++;
            }
        }
        if ( ! array_filter( $places ) ) continue;
        $out[] = count( $places ) === 1 ? $places[0] : fc_embed_columns( fc_embed_rows( $places ) );
    }
    // The check: every picture in the table is still there.
    if ( ! $out || $count !== preg_match_all( '#<img\b#i', $block ) ) return $block;
    return implode( "\n\n", $out );
}

/** The words of some HTML, for comparing: no tags, no line breaks, spaces collapsed. */
function fc_embed_words( $html ) {
    $text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', (string) $html ) ), ENT_QUOTES, 'UTF-8' );
    return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
}

/** The width a picture asks for, in pixels (attribute or style), or 0 when it doesn't say. */
function fc_embed_inline_size( $tag ) {
    if ( preg_match( '#\swidth=["\']?(\d+)#i', $tag, $w ) ) return (int) $w[1];
    if ( preg_match( '#\sstyle=["\'][^"\']*\bwidth:\s*(\d+)px#i', $tag, $w ) ) return (int) $w[1];
    return 0;
}

/** An Image block for a picture taken out of a paragraph. Keeps its address, its description and its Media Library link. */
function fc_embed_picture_block( $tag ) {
    preg_match( '#\ssrc=("|\')([^"\']+)\1#i', $tag, $s );
    $src = html_entity_decode( $s[2], ENT_QUOTES, 'UTF-8' );
    $alt = preg_match( '#\salt=("|\')([^"\']*)\1#i', $tag, $a ) ? html_entity_decode( $a[2], ENT_QUOTES, 'UTF-8' ) : '';
    $id  = preg_match( '#\bwp-image-(\d+)\b#', $tag, $i ) ? (int) $i[1] : 0;

    $comment = $id ? '<!-- wp:image {"id":' . $id . '} -->' : '<!-- wp:image -->';
    $class   = $id ? ' class="wp-image-' . $id . '"' : '';
    return $comment . "\n" . '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"' . $class . '/></figure>' . "\n<!-- /wp:image -->";
}

// When a post is saved. After WordPress has cleaned the content, so the
// players the plugin writes are kept.
add_filter( 'wp_insert_post_data', 'fc_embed_on_save', 10, 2 );

function fc_embed_on_save( $data, $postarr ) {
    if ( ! in_array( $data['post_type'] ?? '', [ 'post', 'page' ], true ) ) return $data;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return $data;
    if ( ! fc_embeds_apply( (int) ( $postarr['ID'] ?? 0 ) ) ) return $data; // older articles stay as they are

    /*
     * Saving must never fail because of this feature. The editor talks to the
     * server in JSON, and a warning printed in the middle of that answer — or
     * an error — shows up as "The response is not a valid JSON response". So
     * anything printed here is thrown away, and if anything at all goes wrong
     * the article is saved exactly as it was written.
     */
    $id    = (int) ( $postarr['ID'] ?? 0 );
    $first = ! $id || ! get_post_meta( $id, '_fc_layout_done', true );

    ob_start();
    try {
        $converted = fc_embed_convert( wp_unslash( (string) $data['post_content'] ), $first );
        if ( is_string( $converted ) && $converted !== '' ) $data['post_content'] = wp_slash( $converted );
        if ( $first && trim( wp_strip_all_tags( (string) $data['post_content'] ) . ( strpos( (string) $data['post_content'], '<img' ) !== false ? 'x' : '' ) ) !== '' ) {
            $GLOBALS['fc_layout_done_pending'] = true;     // noted once the post has its ID (below)
        }
    } catch ( \Throwable $e ) {
        // Leave the article as it is.
    }
    ob_end_clean();
    return $data;
}

// After the first save with content, the layout is the editor's: see fc_embed_convert().
add_action( 'save_post', function ( $post_id, $post ) {
    if ( empty( $GLOBALS['fc_layout_done_pending'] ) || wp_is_post_revision( $post_id ) ) return;
    if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
    unset( $GLOBALS['fc_layout_done_pending'] );
    update_post_meta( $post_id, '_fc_layout_done', 1 );
}, 10, 2 );

/**
 * Whether one more lookup on another website is allowed during this request.
 * Saving waits for these, so a post with many links must not make the save
 * slow enough for the server to give up: at most 3 per save, 3 seconds each.
 * A link whose lookup was skipped is simply tried again on the next save.
 */
function fc_embed_may_look_up() {
    static $used = 0;
    return ++$used <= 3;
}

/*
 * Keeping the players through later saves.
 *
 * For editors without the "unfiltered HTML" right (common on multisite),
 * WordPress removes every <iframe> when a post is saved. Just before that
 * cleaning, the plugin's own players — and only those, with an address from
 * the sites above — are set aside as a note, and put back right after.
 */
add_filter( 'content_save_pre', 'fc_embed_protect', 9 );
add_filter( 'wp_insert_post_data', 'fc_embed_restore', 5 );

/** The exact markup fc_embed_frame() writes. */
function fc_embed_frame_pattern() {
    return '#<div class="fc-embed fc-embed--([a-z]+)" style="max-width:(100%|\d+px);margin:(?:0|1\.5em) auto;"><div style="position:relative;width:100%;aspect-ratio:(\d+)/(\d+);"><iframe src="([^"]+)"[^>]*></iframe></div></div>#';
}

function fc_embed_protect( $content ) {
    if ( ! has_filter( 'content_save_pre', 'wp_filter_post_kses' ) || strpos( (string) $content, 'fc-embed' ) === false ) return $content;
    $raw = preg_replace_callback( fc_embed_frame_pattern(), function ( $m ) {
        return '<!-- fc-embed ' . base64_encode( wp_json_encode( [ $m[1], $m[2] === '100%', (int) $m[3], (int) $m[4], html_entity_decode( $m[5] ) ] ) ) . ' -->';
    }, wp_unslash( (string) $content ) );
    return wp_slash( $raw );
}

function fc_embed_restore( $data ) {
    if ( strpos( (string) $data['post_content'], '<!-- fc-embed ' ) === false ) return $data;
    $raw = preg_replace_callback( '#<!-- fc-embed ([A-Za-z0-9+/=]+) -->#', function ( $m ) {
        $v = json_decode( (string) base64_decode( $m[1], true ), true );
        if ( ! is_array( $v ) || count( $v ) !== 5 || ! fc_embed_trusted_src( (string) $v[4] ) ) return '';
        $type = in_array( $v[0], [ 'video', 'archive', 'instagram', 'facebook', 'map', 'tiktok' ], true ) ? $v[0] : 'video';
        return fc_embed_frame( (string) $v[4], $type, (int) $v[2], (int) $v[3], (bool) $v[1] );
    }, wp_unslash( (string) $data['post_content'] ) );
    $data['post_content'] = wp_slash( $raw );
    return $data;
}

/** True only for player addresses the plugin itself writes. */
function fc_embed_trusted_src( $src ) {
    if ( ! preg_match( '#^[A-Za-z0-9._~:/?\#@!$&()*+,;=%-]+$#', $src ) ) return false; // no quotes, spaces or brackets
    return (bool) preg_match( '#^https://(?:www\.facebook\.com/plugins/(?:video|post)\.php\?|www\.instagram\.com/(?:p|reel|tv)/[A-Za-z0-9_-]+/embed/|archive\.org/embed/|www\.awesomescreenshot\.com/embed\?|player\.vimeo\.com/video/\d+|www\.youtube\.com/embed/|www\.google\.com/maps/embed\?pb=|www\.tiktok\.com/player/v1/\d+)#', $src );
}
