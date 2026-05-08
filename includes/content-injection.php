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

    $fact_card  = fc_build_fact_card( $stamp_url, $rating_label, $claim, $fact, $featured_img, $logo_url );
    $author_box = fc_build_author_box( $title, $author, $author_url, $stamp_url, $rating_label );
    $wa_banner  = fc_get_whatsapp_banner();

    return $fact_card . $content . $author_box . $wa_banner;
}

// ── FACT CARD HTML ──────────────────────────────────────────────────────────────
function fc_build_fact_card( $stamp_url, $rating_label, $claim, $fact, $featured_img, $logo_url ) {
    ob_start();
    ?>
    <style>
    .fc-card-wrapper { width: 100%; margin: 0 0 32px; display: flex; justify-content: flex-start; }
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
        position: absolute; 
        bottom: 2px; 
        left: 2px; 
        width: 85px; /* Reduced from 110px */
        filter: drop-shadow(0 4px 8px rgba(0,0,0,0.4));
    }
    /* PERFECT ALIGNMENT FIX */
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
    
/* PREMIUM RED GRADIENT DOWNLOAD BUTTON */
    .fc-dl-btn {
        display: inline-flex; 
        align-items: center; 
        gap: 8px;
        background: linear-gradient(135deg, #e31b23 0%, #ff4b4b 100%);
        color: #ffffff; 
        font-size: 15px; 
        font-weight: 700;
        border: none; 
        padding: 12px 28px; 
        border-radius: 8px; 
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(227, 27, 35, 0.25);
        transition: all 0.3s ease; 
        font-family: system-ui, -apple-system, sans-serif;
        margin-top: 16px;
        margin-bottom: 32px; /* Guarantees space below the button */
    }
    
    .fc-dl-btn:hover { 
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(227, 27, 35, 0.4);
        background: linear-gradient(135deg, #cc181f 0%, #ff3333 100%);
    }
    
    .fc-dl-btn:active {
        transform: translateY(1px);
        box-shadow: 0 2px 6px rgba(227, 27, 35, 0.3);
    }
    
    .fc-dl-btn svg { 
        width: 18px; 
        height: 18px; 
    }
    </style>

    <div>
        <div class="fc-card-wrapper">
            <div id="fc-fact-card" class="fc-export-card">
                
                <div class="fc-left-col">
                    <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" class="fc-card-logo" alt="Logo">
                    <?php endif; ?>

                    <div class="fc-image-container">
                        <?php if ( $featured_img ) : ?>
                        <img src="<?php echo esc_url( $featured_img ); ?>" class="fc-featured-img" alt="">
                        <?php endif; ?>
                        <?php if ( $stamp_url ) : ?>
                        <img src="<?php echo esc_url( $stamp_url ); ?>" class="fc-stamp-overlay" alt="<?php echo esc_attr( $rating_label ); ?>">
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

        <button class="fc-dl-btn" onclick="fcDownloadCard()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-3 3m0 0l-3-3m3 3V4"/>
            </svg>
            Download Fact Card
        </button>
    </div>

    <script>
    function fcDownloadCard() {
        if(typeof html2canvas === 'undefined') { alert('Export library still loading. Please try again in a moment.'); return; }
        var card = document.getElementById('fc-fact-card');
        var btn  = document.querySelector('.fc-dl-btn');
        var orig = btn.innerHTML;
        btn.innerHTML = 'Generating...';
        btn.disabled  = true;
        
        html2canvas(card, { useCORS: true, scale: 2, backgroundColor: '#f3f4f6' }).then(function(canvas) {
            var a = document.createElement('a');
            a.download = 'fact-card.png';
            a.href = canvas.toDataURL('image/png');
            a.click();
            btn.innerHTML = orig;
            btn.disabled = false;
        }).catch(function(err) {
            console.error('FC fact card error:', err);
            btn.innerHTML = 'Error — check console';
            setTimeout(function() { btn.innerHTML = orig; btn.disabled = false; }, 3000);
        });
    }
    </script>
    <?php
    return ob_get_clean();
}

// Helper to prevent CORS blocking on download
function fc_get_base64_image( $url ) {
    $response = wp_remote_get( $url );
    if ( is_wp_error( $response ) ) return $url;
    
    $type   = wp_remote_retrieve_header( $response, 'content-type' );
    $data   = wp_remote_retrieve_body( $response );
    $base64 = 'data:' . $type . ';base64,' . base64_encode( $data );
    
    return $base64;
}

// ── AUTHOR BOX HTML ─────────────────────────────────────────────────────────────
function fc_build_author_box( $title, $author, $author_url, $stamp_url, $rating_label ) {
    ob_start();
    ?>
    <style>
    /* ... (Keep your exact Author Box CSS from the previous version here) ... */
    .fc-article-card { position:relative; display:flex; align-items:center; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; padding:32px; margin:40px 0; box-shadow:0 10px 40px -10px rgba(0,0,0,0.08); font-family:system-ui,-apple-system,sans-serif; gap:32px; overflow:hidden; transition:transform 0.3s ease, box-shadow 0.3s ease; z-index:1; }
    .fc-article-card:hover { transform:translateY(-2px); box-shadow:0 15px 50px -15px rgba(0,0,0,0.12); }
    .fc-article-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:6px; background:linear-gradient(180deg,#e31b23 0%,#ff4b4b 100%); border-radius:16px 0 0 16px; z-index:2; }
    .fc-article-card::after { content:attr(data-rating); position:absolute; right:-10px; bottom:-25px; font-size:110px; font-weight:900; color:#e31b23; opacity:0.04; text-transform:uppercase; pointer-events:none; user-select:none; z-index:-1; white-space:nowrap; }
    .fc-stamp-wrapper { position:relative; flex-shrink:0; z-index:2; animation:fc-stamp-in 0.5s cubic-bezier(0.175,0.885,0.32,1.275) both; }
    .fc-stamp-wrapper img { width:110px; height:110px; object-fit:contain; filter:drop-shadow(0 8px 16px rgba(227,27,35,0.25)); transition:transform 0.3s cubic-bezier(0.175,0.885,0.32,1.275), filter 0.3s ease; }
    .fc-article-card:hover .fc-stamp-wrapper img { transform:scale(1.08) rotate(-5deg); filter:drop-shadow(0 12px 24px rgba(227,27,35,0.4)) brightness(1.05); }
    .fc-content { display:flex; flex-direction:column; gap:8px; z-index:2; }
    .fc-title { font-size:20px; font-weight:800; color:#0f172a; line-height:1.4; margin:0; }
    .fc-author { font-size:15px; color:#64748b; margin:0; display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
    .fc-author a { color:#0f172a; text-decoration:none; font-weight:700; border-bottom:2px solid transparent; transition:border-color 0.2s ease; }
    .fc-author a:hover { border-bottom-color:#e31b23; }
    .fc-rating-text { font-weight:900; color:#e31b23; text-transform:uppercase; letter-spacing:0.5px; }
    .fc-label { font-weight:700; color:#555; text-transform:uppercase; font-size:13px; letter-spacing:0.5px; margin-right:5px; }
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
    return '<div class="fc-whatsapp-banner" style="margin:16px 0;">' . $html . '</div>';
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

// ── FEATURED IMAGE STAMP AUTOMATION ───────────────────────────────────────────
add_filter( 'post_thumbnail_html', 'fc_overlay_featured_image_stamp', 10, 5 );

function fc_overlay_featured_image_stamp( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
    if ( is_admin() || empty( $html ) ) return $html;

    $stamp_choice = get_field( 'fc_featured_stamp', $post_id );
    if ( empty( $stamp_choice ) || $stamp_choice === 'none' ) return $html;

    $stamp_url = '';
    $transform = ''; // Default is straight

    if ( $stamp_choice === 'fact-checked' ) {
        $stamp_url = 'https://factcrescendo.com/wp-content/uploads/2026/04/Fact-Checked.png';
        $transform = 'transform:rotate(-15deg);'; // Tilt only the fact-check stamp
    } elseif ( $stamp_choice === 'insight' ) {
        $stamp_url = 'https://www.factcrescendo.com/wp-content/uploads/2026/03/Insight.png';
        $transform = ''; // Keep the insight stamp straight
    }

    if ( ! $stamp_url ) return $html;

    $start = '<div class="fc-feat-img-wrapper" style="position:relative; display:inline-block; max-width:100%; line-height:0;">';
    
    // The $transform variable is dynamically applied right here
    $stamp = '<img src="' . esc_url( $stamp_url ) . '" alt="Stamp" style="position:absolute; bottom:15px; left:15px; width:160px; max-width:40%; z-index:10; pointer-events:none; background:transparent; border:none; box-shadow:none; ' . $transform . '">';
    
    $end   = '</div>';

    return $start . $html . $stamp . $end;
}