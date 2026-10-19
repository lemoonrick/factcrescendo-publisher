<?php
/**
 * Links become embeds by themselves.
 *
 * Paste the article from the Google Doc as it is. A link on a line of its
 * own — or embed code that was pasted as text — turns into the right player
 * on the page:
 *
 *   Archive.org          archive.org/details/…          → its player
 *   Awesome Screenshot   awesomescreenshot.com/video/…  → its player
 *   Instagram            post, reel or TV link          → the post
 *   Facebook             post, video, reel, fb.watch    → the post or video
 *   Vimeo                also the owner's /manage/ link → the player
 *   YouTube, X           as WordPress already does
 *   Our own pictures     …factcrescendo.com/wp-content/uploads/…png → the picture
 *
 * The player code is always written by the plugin, never copied from the
 * Doc, and only for the sites above. That is why WordPress no longer strips
 * it: nobody pastes code any more.
 *
 * Links inside a sentence are left alone. Posts published before this
 * arrived are left exactly as they were.
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
    if ( ! get_option( 'fc_embeds_since' ) ) update_option( 'fc_embeds_since', time(), false );
} );


// ── Building the players ─────────────────────────────────────────────────────

/**
 * One player. $w × $h is its shape; $wide players fill the column, the
 * others (social posts) keep their own width and sit in the middle.
 */
function fc_embed_frame( $src, $type, $w, $h, $wide = false ) {
    $box = $wide ? 'max-width:100%' : 'max-width:' . (int) $w . 'px';
    return '<div class="fc-embed fc-embed--' . esc_attr( $type ) . '" style="' . $box . ';margin:0 auto;">'
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
        return fc_embed_frame( 'https://www.facebook.com/plugins/video.php?href=' . $href . '&show_text=true&width=500', 'facebook', 500, 560 );
    }
    return fc_embed_frame( 'https://www.facebook.com/plugins/post.php?href=' . $href . '&show_text=true&width=500', 'facebook', 500, 640 );
}

/** Where a Facebook short link leads. Asked once a month at most; the link itself if Facebook won't say. */
function fc_embed_facebook_resolve( $url ) {
    $key    = 'fc_fb_' . md5( $url );
    $cached = get_transient( $key );
    if ( is_string( $cached ) && $cached !== '' ) return $cached;

    $found    = $url;
    $response = wp_safe_remote_head( $url, [ 'redirection' => 0, 'timeout' => 5, 'user-agent' => 'Mozilla/5.0 (compatible; FactCrescendo)' ] );
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
        $html = (string) wp_oembed_get( $url );
        set_transient( $key, $html, $html !== '' ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
    }
    if ( ! preg_match( '#<iframe[^>]+src="(https://player\.vimeo\.com/video/[^"]+)"#i', $html, $m ) ) return '';
    return fc_embed_frame( html_entity_decode( $m[1] ), 'video', 640, 360, true );
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
        'awesome'   => '#https?://(?:www\.)?awesomescreenshot\.com/video/\d+\S*#i',
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

    if ( $url === '' ) {
        $src = fc_embed_code_src( $inner );
        if ( $src === '' ) return $m[0];
        $url = fc_embed_src_to_url( $src );
        if ( $url === '' ) return $m[0];
    }

    $html = fc_embed_html( $url );

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

/** The address when a paragraph is only a link (or only an address), else ''. */
function fc_embed_lone_link( $inner ) {
    // Formatting the Doc brings along (bold, highlight, spans) doesn't count.
    $bare = trim( preg_replace( '#</?(?:span|strong|b|em|i|u|mark|br)\b[^>]*>|&nbsp;#i', ' ', $inner ) );

    if ( preg_match( '#^(?:<a\s[^>]*?href=(["\'])(.*?)\1[^>]*>(.*?)</a>)$#is', $bare, $a ) ) {
        $text = trim( html_entity_decode( wp_strip_all_tags( $a[3] ), ENT_QUOTES, 'UTF-8' ) );
        // Only when the link shows its own address. "Facebook Post" is a word in a sentence.
        return preg_match( '#^(?:https?://|www\.)\S+$#i', $text ) ? fc_embed_unwrap_url( $a[2] ) : '';
    }
    if ( preg_match( '#^https?://[^\s<>"]+$#i', $bare ) ) return fc_embed_unwrap_url( $bare );
    return '';
}

/** The src of embed code pasted as text (<iframe src="…">), else ''. */
function fc_embed_code_src( $inner ) {
    $text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', ' ', $inner ) ), ENT_QUOTES, 'UTF-8' );
    $text = str_replace( [ "\u{201C}", "\u{201D}", "\u{2033}", "\u{2018}", "\u{2019}", "\u{00A0}" ], [ '"', '"', '"', "'", "'", ' ' ], $text );
    $text = trim( $text );
    if ( ! preg_match( '#^<iframe\b.*</iframe>$#is', $text ) ) return '';
    if ( ! preg_match( '#\ssrc\s*=\s*(["\'])(.*?)\1#is', $text, $s ) ) return '';
    return preg_replace( '/\s+/', '', $s[2] ); // A long address may have been broken over lines.
}

/** The ordinary address for an embed code's src, for the sites we trust; '' for anything else. */
function fc_embed_src_to_url( $src ) {
    $src = html_entity_decode( $src, ENT_QUOTES, 'UTF-8' );
    if ( strpos( $src, '//' ) === 0 ) $src = 'https:' . $src;

    if ( preg_match( '#^https?://(?:www\.)?(?:facebook\.com/plugins/|awesomescreenshot\.com/embed|archive\.org/embed/)#i', $src ) ) return $src;
    if ( preg_match( '#^https?://(?:www\.)?instagram\.com/((?:p|reels?|tv)/[A-Za-z0-9_-]+)#i', $src, $m ) ) return 'https://www.instagram.com/' . $m[1] . '/';
    if ( preg_match( '#^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,})#i', $src, $m ) ) return 'https://www.youtube.com/watch?v=' . $m[1];
    if ( preg_match( '#^https?://player\.vimeo\.com/video/(\d+)#i', $src, $m ) ) {
        parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $q );
        return 'https://vimeo.com/' . $m[1] . ( ! empty( $q['h'] ) && ctype_xdigit( $q['h'] ) ? '/' . $q['h'] : '' );
    }
    return '';
}
