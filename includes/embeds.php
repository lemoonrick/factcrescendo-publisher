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

/** The ordinary address for an embed code's src, for the sites we trust; '' for anything else. */
function fc_embed_src_to_url( $src ) {
    $src = html_entity_decode( $src, ENT_QUOTES, 'UTF-8' );
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
        . "link to archive\nlink of archive\narchive of the video\narchive of the post";
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
    if ( $saved === false || trim( (string) $saved ) === '' || trim( (string) $saved ) === trim( fc_embed_previous_default_notes() ) ) return fc_embed_default_notes();
    return (string) $saved;
}

function fc_embed_notes() {
    $list  = fc_embed_notes_text();
    $words = [];
    foreach ( preg_split( '/\R/u', $list ) as $line ) {
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
    return trim( $text, " \t:.;,!*()[]{}\"'“”‘’|>" );
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
function fc_embed_convert( $content ) {
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

    // Author notes ("Embed", "side by side" …) alone just above an embed or picture.
    $notes = fc_embed_notes();
    do {
        $before  = $content;
        // …also when the author typed it as code, e.g. "<p>Side by Side</p>", which became a Custom HTML block.
        $content = preg_replace_callback(
            '#<!-- wp:html -->\s*((?:(?!<!-- /wp:html -->).)*?)\s*<!-- /wp:html -->\s*(?=<!-- wp:(?:embed|html|image|gallery|video)\b)#s',
            function ( $m ) use ( $notes ) {
                // Only plain text in simple tags: never a player, picture or link.
                if ( preg_match( '#<(?!/?(?:p|strong|b|em|i|span|br|h[1-6]|div)\b)#i', $m[1] ) ) return $m[0];
                return isset( $notes[ fc_embed_note_key( $m[1] ) ] ) ? '' : $m[0];
            },
            $content
        );
        $content = preg_replace_callback(
            '#<!-- wp:paragraph(?:\s+\{[^\n]*?\})?\s+-->\s*<p\b[^>]*>((?:(?!</p>).)*)</p>\s*<!-- /wp:paragraph -->\s*(?=<!-- wp:(?:embed|html|image|gallery|video)\b)#s',
            function ( $m ) use ( $notes ) {
                return isset( $notes[ fc_embed_note_key( $m[1] ) ] ) ? '' : $m[0];
            },
            $content
        );
    } while ( $content !== $before );

    return $content;
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
    ob_start();
    try {
        $converted = fc_embed_convert( wp_unslash( (string) $data['post_content'] ) );
        if ( is_string( $converted ) && $converted !== '' ) $data['post_content'] = wp_slash( $converted );
    } catch ( \Throwable $e ) {
        // Leave the article as it is.
    }
    ob_end_clean();
    return $data;
}

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
