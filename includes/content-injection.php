<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Properly enqueue the screenshot script ONLY on single posts
add_action( 'wp_enqueue_scripts', 'fc_enqueue_fact_card_scripts' );
function fc_enqueue_fact_card_scripts() {
    if ( is_singular( 'post' ) ) {
        wp_enqueue_script( 'html2canvas', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', [], '1.4.1', true );
    }
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

    // Some themes and plugins run the_content more than once on a page
    // (related-post boxes, preview widgets). Inject only the first time,
    // otherwise the reader gets two fact cards and two audio players — and
    // the duplicate IDs break the Share and Download buttons.
    static $injected = [];
    if ( isset( $injected[ $post_id ] ) ) return $content;
    $injected[ $post_id ] = true;

    $title        = get_the_title( $post_id );
    $author_id    = get_post_field( 'post_author', $post_id );
    $author       = get_the_author_meta( 'display_name', $author_id );
    $author_url   = get_author_posts_url( $author_id );
    $stamp_url    = fc_get_stamp_url( $rating );
    $rating_label = fc_get_rating_label( $rating );
    $claim        = get_field( 'fc_claim', $post_id );
    $fact         = get_field( 'fc_fact', $post_id );
    $featured_img = get_the_post_thumbnail_url( $post_id, 'large' );
    $logo_url     = get_option( 'fc_logo_url', '' );

    $fact_card    = fc_build_fact_card( $stamp_url, $rating_label, $claim, $fact, $featured_img, $logo_url );
    $author_box   = fc_build_author_box( $title, $author, $author_url, $stamp_url, $rating_label );
    $wa_banner    = fc_get_whatsapp_banner();

    // Audio player is optional per site. When disabled, no player renders
    // and the article content is returned without the highlight wrapper.
    $audio_enabled = get_option( 'fc_enable_audio', '1' ) === '1';

    if ( $audio_enabled ) {
        $audio_player    = fc_build_audio_player( $post_id, $title, $claim, $fact );
        $wrapped_content = '<div id="fc-article-body-' . esc_attr( $post_id ) . '" class="fc-article-body">' . $content . '</div>';
        return $fact_card . $audio_player . $wrapped_content . $author_box . $wa_banner;
    }

    return $fact_card . $content . $author_box . $wa_banner;
}

// ── FACT CARD HTML ──────────────────────────────────────────────────────────────
function fc_build_fact_card( $stamp_url, $rating_label, $claim, $fact, $featured_img, $logo_url ) {

    // Convert cross-origin images (stamp, featured, logo all live on
    // factcrescendo.com) to inline base64 so html2canvas can read their
    // pixels. Without this the canvas is "tainted" and those images drop
    // out of the downloaded PNG — which is exactly why the stamp was
    // missing from downloads. Results are cached so we don't re-fetch on
    // every page load.
    $stamp_data    = $stamp_url    ? fc_image_to_base64( $stamp_url )    : '';
    $featured_data = $featured_img ? fc_image_to_base64( $featured_img ) : '';
    $logo_data     = $logo_url     ? fc_image_to_base64( $logo_url )     : '';

    // Fall back to the live URL if a fetch failed, so the card still
    // displays on-page even if the download can't include that image.
    $stamp_src    = $stamp_data    ?: $stamp_url;
    $featured_src = $featured_data ?: $featured_img;
    $logo_src     = $logo_data     ?: $logo_url;

    ob_start();
    ?>
    <style>
    .fc-card-wrapper { width: 100%; margin: 0 0 12px; display: flex; justify-content: flex-start; }
    .fc-export-card {
        width: 100%; max-width: 900px; background-color: #f3f4f6;
        display: flex; flex-wrap: wrap; padding: 30px; gap: 30px;
        box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif;
        border: 1px solid #e5e7eb; border-radius: 8px;
    }
    .fc-left-col { flex: 1 1 300px; display: flex; flex-direction: column; gap: 20px; }
    .fc-card-logo { height: 45px; object-fit: contain; margin: 0 auto; display: block; }
    .fc-image-container {
        width: 100%; aspect-ratio: 4/3; position: relative;
        border-radius: 4px; overflow: hidden;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1); background: #ddd;
    }
    .fc-featured-img { width: 100%; height: 100%; object-fit: cover; position: absolute; top: 0; left: 0; }
    .fc-stamp-overlay {
        position: absolute; bottom: 2px; left: 2px; width: 85px;
        filter: drop-shadow(0 4px 8px rgba(0,0,0,0.4));
    }
    .fc-card-footer {
        display: flex; justify-content: space-between; align-items: flex-end;
        border-bottom: 2px solid #e31b23; padding-bottom: 16px; flex-wrap: wrap; gap: 10px;
    }
    .fc-contact-info { display: flex; flex-direction: column; align-items: center; gap: 6px; }
    .fc-contact-badge {
        background: #e31b23; color: #fff; font-size: 11px; font-weight: bold;
        padding: 4px 12px; border-radius: 12px; display: inline-flex; align-items: center; gap: 6px;
    }
    .fc-contact-badge svg { fill: #25D366; background: #fff; border-radius: 50%; padding: 2px; }
    .fc-contact-number { font-size: 20px; font-weight: 900; color: #000; margin: 0; line-height: 1; }
    .fc-social-section { display: flex; flex-direction: column; align-items: center; gap: 8px; }
    .fc-social-badge {
        background: #e31b23; color: #fff; font-size: 11px; font-weight: bold;
        padding: 4px 16px; border-radius: 12px; text-align: center;
    }
    .fc-social-icons { display: flex; gap: 6px; line-height: 1; }
    .fc-social-icons svg { width: 22px; height: 22px; }
    .fc-result-row { display: flex; align-items: center; gap: 15px; margin-top: auto; }
    .fc-result-btn {
        background: #e31b23; color: #fff; font-weight: 900; font-size: 16px;
        padding: 8px 24px; border-radius: 30px; text-transform: uppercase;
    }
    .fc-result-value { font-size: 22px; font-weight: 900; color: #000; text-transform: uppercase; }
    .fc-right-col { flex: 1.2 1 350px; display: flex; flex-direction: column; gap: 40px; justify-content: center; }
    .fc-info-box {
        background-color: #e31b23; border-radius: 12px; padding: 30px 24px 20px;
        position: relative; box-shadow: 0 10px 20px -5px rgba(227,27,35,0.3);
    }
    .fc-box-badge {
        position: absolute; top: -18px; left: 24px; background-color: #e31b23; color: #fff;
        font-size: 15px; font-weight: 900; padding: 6px 24px; border-radius: 20px;
        border: 4px solid #f3f4f6; letter-spacing: 1px; text-transform: uppercase;
    }
    .fc-badge-green { background-color: #0a8a3c; }
    .fc-box-text { color: #fff; font-size: 18px; font-weight: 600; line-height: 1.5; margin: 0; margin-top: 12px; }

    /* Action row below the card — share + download */
    .fc-card-actions {
        display: flex; gap: 10px; flex-wrap: wrap;
        margin: 0 0 32px; max-width: 900px;
    }
    .fc-act-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        font-size: 14px; font-weight: 600; font-family: system-ui, -apple-system, sans-serif;
        border-radius: 10px; padding: 11px 20px; cursor: pointer; border: 1px solid transparent;
        transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .fc-act-btn svg { width: 17px; height: 17px; flex-shrink: 0; display: block; }
    .fc-act-share {
        background: #e31b23; color: #fff !important; border-color: #e31b23;
        box-shadow: 0 4px 12px rgba(227,27,35,0.22);
    }
    .fc-act-share svg { fill: #fff; }
    .fc-act-share:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(227,27,35,0.35); background: #cc181f; }
    .fc-act-download {
        background: #ffffff; color: #1e293b !important; border-color: #d1d5db;
    }
    .fc-act-download svg { stroke: #1e293b; }
    .fc-act-download:hover { transform: translateY(-2px); background: #f8fafc; border-color: #94a3b8; }
    .fc-act-btn.fc-busy { opacity: 0.6; pointer-events: none; }

    /* ── Dark mode ── */
    @media (prefers-color-scheme: dark) {
        /* The card interior intentionally stays light — it is a branded
           graphic meant to look like a printed card, and it gets exported
           as an image. We adapt only the surrounding controls so nothing
           becomes invisible against a dark page. */
        .fc-export-card { box-shadow: 0 4px 20px rgba(0,0,0,0.4); border-color: #333; }
        .fc-act-download {
            background: #1e293b; color: #e2e8f0 !important; border-color: #334155;
        }
        .fc-act-download svg { stroke: #e2e8f0; }
        .fc-act-download:hover { background: #273548; border-color: #475569; }
    }
    </style>

    <div>
        <div class="fc-card-wrapper">
            <div id="fc-fact-card" class="fc-export-card">

                <div class="fc-left-col">
                    <?php if ( $logo_src ) : ?>
                    <img src="<?php echo esc_attr( $logo_src ); ?>" class="fc-card-logo" alt="Logo">
                    <?php endif; ?>

                    <div class="fc-image-container">
                        <?php if ( $featured_src ) : ?>
                        <img src="<?php echo esc_attr( $featured_src ); ?>" class="fc-featured-img" alt="">
                        <?php endif; ?>
                        <?php if ( $stamp_src ) : ?>
                        <img src="<?php echo esc_attr( $stamp_src ); ?>" class="fc-stamp-overlay" alt="<?php echo esc_attr( $rating_label ); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="fc-card-footer">
                        <div class="fc-contact-info">
                            <span class="fc-contact-badge">
                                <svg width="14" height="14" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                Whatsapp us
                            </span>
                            <p class="fc-contact-number">9049053770</p>
                        </div>
                        <div class="fc-social-section">
                            <div class="fc-social-badge">Follow us</div>
                            <div class="fc-social-icons">
                                <svg fill="#1877F2" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.469h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.469h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                <svg fill="#E4405F" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                                <svg fill="#1DA1F2" viewBox="0 0 24 24"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>
                            </div>
                        </div>
                    </div>

                    <div class="fc-result-row">
                        <div class="fc-result-btn">RESULT</div>
                        <div class="fc-result-value"><?php echo esc_html( $rating_label ); ?></div>
                    </div>
                </div>

                <div class="fc-right-col">
                    <?php if ( $claim ) : ?>
                    <div class="fc-info-box">
                        <span class="fc-box-badge">CLAIM</span>
                        <p class="fc-box-text"><?php echo esc_html( $claim ); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ( $fact ) : ?>
                    <div class="fc-info-box">
                        <span class="fc-box-badge fc-badge-green">FACT CHECK</span>
                        <p class="fc-box-text"><?php echo esc_html( $fact ); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <div class="fc-card-actions">
            <button class="fc-act-btn fc-act-share" type="button" onclick="fcShareCard(this)">
                <svg viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>
                Share
            </button>
            <button class="fc-act-btn fc-act-download" type="button" onclick="fcDownloadCard(this)">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v13m0 0l-4-4m4 4l4-4M4 19h16"/></svg>
                Download
            </button>
        </div>
    </div>

    <script>
    function fcRenderFactCard() {
        if (typeof html2canvas === 'undefined') return Promise.reject(new Error('lib-not-loaded'));
        var card = document.getElementById('fc-fact-card');
        return html2canvas(card, { useCORS: true, scale: 2, backgroundColor: '#f3f4f6' });
    }
    function fcDownloadCard(btn) {
        var orig = btn.innerHTML;
        btn.classList.add('fc-busy');
        fcRenderFactCard().then(function(canvas) {
            var a = document.createElement('a');
            a.download = 'fact-check.png';
            a.href = canvas.toDataURL('image/png');
            a.click();
            btn.classList.remove('fc-busy');
        }).catch(function(err) {
            console.error('FC download error:', err);
            btn.innerHTML = 'Try again';
            setTimeout(function(){ btn.innerHTML = orig; btn.classList.remove('fc-busy'); }, 2500);
        });
    }
    function fcShareCard(btn) {
        var orig = btn.innerHTML;
        btn.classList.add('fc-busy');
        fcRenderFactCard().then(function(canvas) {
            canvas.toBlob(function(blob) {
                var file = new File([blob], 'fact-check.png', { type: 'image/png' });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({ files: [file], title: document.title, url: window.location.href })
                        .catch(function(){})
                        .finally(function(){ btn.classList.remove('fc-busy'); });
                } else {
                    // Desktop fallback: download the image instead of sharing
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url; a.download = 'fact-check.png'; a.click();
                    URL.revokeObjectURL(url);
                    btn.classList.remove('fc-busy');
                }
            });
        }).catch(function(err) {
            console.error('FC share error:', err);
            btn.innerHTML = 'Try again';
            setTimeout(function(){ btn.innerHTML = orig; btn.classList.remove('fc-busy'); }, 2500);
        });
    }
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Fetches a remote image and returns it as a base64 data URI, cached for
 * a week. This lets html2canvas read otherwise cross-origin images (the
 * stamp and featured image) when exporting the card. Returns '' on any
 * failure so callers can fall back to the live URL for on-page display.
 */
function fc_image_to_base64( $url ) {
    if ( empty( $url ) ) return '';

    // Defensive: only ever fetch over http(s). The callers all pass
    // trusted URLs (filtered stamp map, WP thumbnail, admin logo setting),
    // but this guard ensures no other scheme (file://, ftp://, etc.) could
    // be fetched even if a future code path passes something unexpected.
    $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
    if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
        return '';
    }

    $cache_key = 'fc_img64_' . md5( $url );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached; // may be a data URI, or '' from a prior failed fetch
    }

    $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        set_transient( $cache_key, '', HOUR_IN_SECONDS );
        return '';
    }

    $body = wp_remote_retrieve_body( $response );
    $type = wp_remote_retrieve_header( $response, 'content-type' );

    if ( empty( $body ) || strpos( (string) $type, 'image/' ) !== 0 ) {
        set_transient( $cache_key, '', HOUR_IN_SECONDS );
        return '';
    }

    $data_uri = 'data:' . $type . ';base64,' . base64_encode( $body );
    set_transient( $cache_key, $data_uri, WEEK_IN_SECONDS );
    return $data_uri;
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

            // TTS reads ONLY the article body paragraphs. The fact card,
            // claim, and fact boxes live outside this container, so they are
            // never spoken — reading starts at the actual article content.
            var paras = bodyEl ? Array.prototype.slice.call(bodyEl.querySelectorAll('p')).filter(function(p) {
                return p.textContent.replace(/\s+/g, '').length > 8;
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
    .fc-article-card::after { content:attr(data-rating); position:absolute; right:-10px; bottom:-25px; font-size:110px; font-weight:900; color:#e31b23; opacity:0.04; text-transform:uppercase; pointer-events:none; user-select:none; z-index:-1; white-space:nowrap; }
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
function fc_get_stamp_url( $rating ) {
    $default_map = [
        'false'           => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/False.png',
        'partly-false'    => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/Partly-False.png',
        'misleading'      => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/Misleading.png',
        'missing-context' => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/Missing-Context.png',
        'satire'          => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/Satire.png',
        'altered'         => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/Altered.png',
        'insight'         => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/Insight.png',
        'news'            => 'https://www.factcrescendo.com/wp-content/uploads/2026/03/News.png',
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
    return $labels[ strtolower( (string) $rating ) ] ?? ucfirst( (string) $rating );
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

