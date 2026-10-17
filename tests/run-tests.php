<?php
/**
 * Automated checks, run inside a throwaway WordPress by tests/run.sh.
 *
 * Each check builds what it needs, looks at the real output WordPress
 * produces, and prints PASS or FAIL. Exits non-zero if anything fails.
 */

if ( PHP_SAPI !== 'cli' ) exit; // command line only, never from a browser

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$fc_plugin = 'factcrescendo-publisher/factcrescendo-publisher.php';
$activated = activate_plugin( $fc_plugin );
if ( is_wp_error( $activated ) ) {
    echo 'FAIL could not activate: ' . $activated->get_error_message() . "\n";
    exit( 1 );
}
// activate_plugin() includes the file in this request; hooks registered on
// init have missed it, so run them now.
fc_register_fields();

$GLOBALS['fc_failures'] = 0;
$GLOBALS['fc_passes']   = 0;

function check( $label, $ok, $detail = '' ) {
    if ( $ok ) {
        $GLOBALS['fc_passes']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['fc_failures']++;
        echo "  FAIL  $label" . ( $detail !== '' ? "\n        $detail" : '' ) . "\n";
    }
}

function section( $title ) { echo "\n$title\n"; }

/** A published post, optionally with fact-check fields set directly. */
function make_post( $meta = [], $content = '<p>First paragraph.</p><p>Second paragraph.</p>' ) {
    $pid = wp_insert_post( [
        'post_title'   => 'Test fact check',
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_author'  => 1,
    ] );
    foreach ( $meta as $k => $v ) update_post_meta( $pid, $k, wp_slash( $v ) );
    return $pid;
}

/** The article body exactly as a visitor's page would get it. */
function render( $post_id ) {
    global $wp_query, $wp_the_query, $post;
    $wp_query     = new WP_Query( [ 'p' => $post_id ] );
    $wp_the_query = $wp_query;
    $wp_query->the_post();
    $html = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
    wp_reset_postdata();
    return $html;
}

/** Everything printed in <head> for that post (where the schema goes). */
function render_head( $post_id ) {
    global $wp_query, $wp_the_query;
    $wp_query     = new WP_Query( [ 'p' => $post_id ] );
    $wp_the_query = $wp_query;
    $wp_query->the_post();
    ob_start();
    fc_inject_claim_review_schema();
    $out = ob_get_clean();
    wp_reset_postdata();
    return $out;
}

/** Saves a post the way the editor's Fact Check box submits it. */
function save_from_box( $post_id, $fields ) {
    $_POST = array_merge( [ 'fc_fields_nonce' => wp_create_nonce( 'fc_save_fields' ) ], wp_slash( $fields ) );
    wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Test fact check' ] );
    $_POST = [];
}

/** A real photo in the media library, w × h pixels. */
function make_image( $w = 1600, $h = 1000, $name = 'photo' ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $uploads = wp_upload_dir();
    $path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $name . '.jpg' );
    $im      = imagecreatetruecolor( $w, $h );
    imagefill( $im, 0, 0, imagecolorallocate( $im, 40, 120, 200 ) );
    imagejpeg( $im, $path, 90 );
    $id = wp_insert_attachment( [ 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ], $path );
    wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
    return $id;
}

function reset_options() {
    delete_option( 'fc_site_key' );
    delete_option( 'fc_show_whatsapp_banner' );
    delete_option( 'fc_whatsapp_banner_html' );
    delete_option( 'fc_enable_audio' );
    delete_option( 'fc_whatsapp_banner_style' );
    delete_option( 'fc_card_share' );
}

wp_set_current_user( 1 );

$base = [
    'fc_is_fact_check' => '1',
    'fc_rating'        => 'misleading',
    'fc_claim'         => 'A viral photo shows X being arrested.',
    'fc_fact'          => 'The photo shows someone else, arrested in 2023.',
];


// ─────────────────────────────────────────────────────────────────────────────
section( 'Plugin loads' );

check( 'activates without ACF installed', ! class_exists( 'ACF' ) && is_plugin_active( $fc_plugin ) );
check( 'no ACF function is needed', ! function_exists( 'get_field' ) );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Fact-check box in the editor' );

$pid = make_post();
save_from_box( $pid, [
    'fc_is_fact_check'     => '1',
    'fc_rating'            => 'partly-false',
    'fc_claim'             => 'Claim with a back\\slash and "quotes"',
    'fc_fact'              => "Line one\nLine two",
    'fc_claim_source_url'  => 'https://example.com/viral',
    'fc_claim_author'      => '@someone',
    'fc_claim_author_type' => 'Organization',
] );
check( 'switch saved as 1', get_post_meta( $pid, 'fc_is_fact_check', true ) === '1' );
check( 'rating saved', get_post_meta( $pid, 'fc_rating', true ) === 'partly-false' );
check( 'backslash and quotes kept', get_post_meta( $pid, 'fc_claim', true ) === 'Claim with a back\\slash and "quotes"', var_export( get_post_meta( $pid, 'fc_claim', true ), true ) );
check( 'line breaks in fact kept', get_post_meta( $pid, 'fc_fact', true ) === "Line one\nLine two" );
check( 'source link saved', get_post_meta( $pid, 'fc_claim_source_url', true ) === 'https://example.com/viral' );
check( 'source type saved', get_post_meta( $pid, 'fc_claim_author_type', true ) === 'Organization' );

save_from_box( $pid, [ 'fc_rating' => 'totally-made-up', 'fc_claim_source_url' => 'javascript:alert(1)' ] );
check( 'unticked switch saves as 0', get_post_meta( $pid, 'fc_is_fact_check', true ) === '0' );
check( 'unknown rating is not stored', get_post_meta( $pid, 'fc_rating', true ) === '' );
check( 'javascript: link is not stored', get_post_meta( $pid, 'fc_claim_source_url', true ) === '' );
check( 'emptied claim is removed', ! metadata_exists( 'post', $pid, 'fc_claim' ) );

$pid = make_post( $base );
wp_update_post( [ 'ID' => $pid, 'post_title' => 'Edited elsewhere' ] );
check( 'save without the box (REST, quick edit) leaves fields alone', get_post_meta( $pid, 'fc_claim', true ) === $base['fc_claim'] );

$_POST = [ 'fc_fields_nonce' => 'forged', 'fc_claim' => 'hacked', 'fc_is_fact_check' => '1' ];
wp_update_post( [ 'ID' => $pid, 'post_title' => 'Forged' ] );
$_POST = [];
check( 'bad security token is ignored', get_post_meta( $pid, 'fc_claim', true ) === $base['fc_claim'] );

wp_set_current_user( 0 );
$_POST = [ 'fc_fields_nonce' => wp_create_nonce( 'fc_save_fields' ), 'fc_claim' => 'anon' ];
wp_update_post( [ 'ID' => $pid ] );
$_POST = [];
wp_set_current_user( 1 );
check( 'logged-out save is ignored', get_post_meta( $pid, 'fc_claim', true ) === $base['fc_claim'] );

ob_start();
fc_render_fields_box( get_post( $pid ) );
$box = ob_get_clean();
check( 'box shows saved claim', strpos( $box, esc_html( $base['fc_claim'] ) ) !== false );
check( 'box shows all eight ratings', substr_count( $box, '<option value="' ) >= 9 + 2 );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Posts written with the old ACF version' );

$pid = make_post( [
    'fc_is_fact_check' => '1',
    '_fc_is_fact_check' => 'field_fc_is_fact_check',
    'fc_rating'        => 'false',
    '_fc_rating'       => 'field_fc_rating',
    'fc_claim'         => 'Old ACF claim',
    'fc_fact'          => 'Old ACF fact',
] );
$html = render( $pid );
check( 'still get their fact card', strpos( $html, 'Old ACF claim' ) !== false && strpos( $html, 'fc-fc-wrap' ) !== false );
check( 'still get their author box', strpos( $html, 'fc-article-card' ) !== false );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Articles' );

$plain = make_post( [], '<p>Ordinary news.</p>' );
check( 'normal post is left untouched', trim( render( $plain ) ) === '<p>Ordinary news.</p>' );

$no_rating = make_post( [ 'fc_is_fact_check' => '1', 'fc_claim' => 'x' ] );
check( 'fact check without a rating adds nothing', strpos( render( $no_rating ), 'fc-fc' ) === false );

$pid   = make_post( array_merge( $base, [ 'fc_claim' => '<script>alert("x")</script> claim' ] ) );
$html = render( $pid );
check( 'claim text cannot inject a script', strpos( $html, '<script>alert' ) === false );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Fact card' );

reset_options();
$pid  = make_post( array_merge( $base, [ 'fc_fact' => "Fact line one\nFact & **line two** <b>x</b>" ] ) );
$html = render( $pid );
check( 'new card is the default', strpos( $html, 'class="fc-fc fc-fc--orange' ) !== false );
check( 'card shows the claim', strpos( $html, esc_html( $base['fc_claim'] ) ) !== false );
check( 'card shows the fact, with its highlight', strpos( $html, 'Fact &amp; <mark>line two</mark>' ) !== false );
$card = fc_build_card( $pid, 'misleading', 'Misleading', '<img src=x onerror=alert(1)>', '**<script>x</script>**' );
check( 'card escapes claim and fact, even inside a highlight', strpos( $card, '<img src=x' ) === false && strpos( $card, '<mark>&lt;script&gt;x&lt;/script&gt;</mark>' ) !== false );
check( 'card shows the rating', strpos( $html, '<span>Misleading</span>' ) !== false );
update_option( 'fc_site_key', 'factcrescendo.com' );
$card = fc_build_card( $pid, 'misleading', 'Misleading', $base['fc_claim'], $base['fc_fact'] );
check( 'no stamp, phone number or socials on the card', stripos( $card, 'stamp' ) === false && stripos( $card, 'whatsapp' ) === false && strpos( $card, '9049053770' ) === false );
reset_options();
check( 'no photo set: no empty photo frame', strpos( $html, '<figure class="fc-fc-photo"' ) === false );
check( 'Fact Crescendo logo shown by default', strpos( $html, 'assets/logo.png' ) !== false );
add_filter( 'fc_card_logo_url', '__return_empty_string' );
check( 'no logo: site name shown instead', strpos( render( $pid ), 'class="fc-fc-site"' ) !== false );
remove_filter( 'fc_card_logo_url', '__return_empty_string' );
check( 'card sits before the article text', strpos( $html, 'fc-fc-wrap' ) < strpos( $html, 'First paragraph' ) );
check( 'Download and Share buttons under the card', strpos( $html, 'data-act="download"' ) !== false && strpos( $html, 'data-act="share"' ) !== false && wp_script_is( 'fc-card-share', 'enqueued' ) );
check( 'buttons stay hidden until their script runs', strpos( $html, '<div class="fc-share" hidden' ) !== false );
update_option( 'fc_card_share', '0' );
check( 'buttons can be switched off', strpos( render( $pid ), 'fc-share-btn' ) === false );
delete_option( 'fc_card_share' );
check( 'false rating is red with an X', strpos( render( make_post( array_merge( $base, [ 'fc_rating' => 'false' ] ) ) ), 'fc-fc--red' ) !== false );

$photo = make_image();
set_post_thumbnail( $pid, $photo );
$html = render( $pid );
check( 'featured image shown in the card', strpos( $html, '<figure class="fc-fc-photo" style="aspect-ratio:1.6"' ) !== false );

$own_photo = make_image( 1200, 800, 'card-photo' );
update_post_meta( $pid, 'fc_card_photo', $own_photo );
check( 'a chosen card photo is used instead', strpos( render( $pid ), 'card-photo' ) !== false );
delete_post_meta( $pid, 'fc_card_photo' );

update_option( 'fc_site_key', 'factcrescendo.com' );
$html = render( $pid );
check( 'Hindi site: Hindi headings', strpos( $html, 'फैक्ट चेक' ) !== false && strpos( $html, 'fc-fc--indic' ) !== false );
reset_options();

// Each post can choose
$canva = make_image( 1280, 768, 'canva-card' );
update_post_meta( $pid, 'fc_card_mode', 'image' );
check( 'own picture chosen but not set: built-in card instead', strpos( render( $pid ), 'fc-fc-wrap' ) !== false );
update_post_meta( $pid, 'fc_card_image', $canva );
$html = render( $pid );
check( 'own picture (Canva) replaces the card', strpos( $html, 'fc-own-card' ) !== false && strpos( $html, 'canva-card' ) !== false && strpos( $html, 'class="fc-fc-wrap"' ) === false );
check( 'own picture can be downloaded as it is', strpos( $html, 'data-fc-own' ) !== false && strpos( $html, 'data-act="download"' ) !== false );
check( 'own picture has readable alt text', strpos( $html, 'alt="Fact check. Rating: Misleading. Claim: ' ) !== false );
update_post_meta( $pid, 'fc_card_mode', 'off' );
$html = render( $pid );
check( 'post can switch the card off; author box stays', strpos( $html, 'fc-fc' ) === false && strpos( $html, 'fc-own-card' ) === false && strpos( $html, 'fc-article-card' ) !== false );
delete_post_meta( $pid, 'fc_card_mode' );

// Site setting
update_option( 'fc_card_design', 'simple' );
$html = render( $pid );
check( 'simple design can be chosen instead', strpos( $html, 'fc-hero' ) !== false && strpos( $html, 'class="fc-fc-wrap"' ) === false );
check( 'simple design shows no ** marks', strpos( $html, '**' ) === false );
check( 'simple design uses the white logo', strpos( $html, 'assets/logo-white.png' ) !== false );
update_option( 'fc_card_design', 'off' );
check( 'site can switch the card off', strpos( render( $pid ), 'class="fc-fc-wrap"' ) === false );
update_post_meta( $pid, 'fc_card_mode', 'card' );
check( 'a post can still ask for the card', strpos( render( $pid ), 'fc-fc-wrap' ) !== false );
update_option( 'fc_card_design', 'simple' );
check( 'site uses the simple card: a post can still pick the Fact Crescendo card', strpos( render( $pid ), 'class="fc-fc-wrap"' ) !== false );
check( 'card choices never list the same card twice', count( array_unique( fc_card_mode_options() ) ) === 4 && strpos( fc_card_mode_options()[''], 'Simple card' ) === 0 );
delete_post_meta( $pid, 'fc_card_mode' );
update_option( 'fc_card_design', 'canva' );
check( 'old setting name still means the card', fc_card_design() === 'card' );
update_option( 'fc_card_design', 'nonsense' );
check( 'unknown design falls back to the default', fc_card_design() === 'card' );
delete_option( 'fc_card_design' );

update_option( 'fc_logo_url', 'https://example.com/logo.png' );
check( 'logo from settings is used', strpos( render( $pid ), 'https://example.com/logo.png' ) !== false );
delete_option( 'fc_logo_url' );

add_filter( 'fc_card_labels', $hi = function( $w ) { $w['claim'] = 'दावा'; return $w; } );
check( 'card words can be translated', strpos( render( $pid ), 'दावा' ) !== false );
remove_filter( 'fc_card_labels', $hi );

save_from_box( $pid, array_merge( $base, [ 'fc_card_mode' => 'sneaky', 'fc_card_image' => '999999', 'fc_featured_badge' => 'evil' ] ) );
check( 'unknown card choices are not stored', ! metadata_exists( 'post', $pid, 'fc_card_mode' ) && ! metadata_exists( 'post', $pid, 'fc_card_image' ) && ! metadata_exists( 'post', $pid, 'fc_featured_badge' ) );
save_from_box( $pid, array_merge( $base, [ 'fc_card_mode' => 'image', 'fc_card_image' => (string) $canva ] ) );
check( 'card choice saves from the box', get_post_meta( $pid, 'fc_card_mode', true ) === 'image' && (int) get_post_meta( $pid, 'fc_card_image', true ) === $canva );
delete_post_meta( $pid, 'fc_card_mode' );
reset_options();


// ─────────────────────────────────────────────────────────────────────────────
section( 'Featured image stamp' );

reset_options();
check( 'this server can draw images', fc_featured_can_draw() );

/** Saves the post the way the block editor does, then returns its featured image. */
function save_rest( $post_id, $body = [] ) {
    $req = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
    $req->set_body_params( $body );
    rest_do_request( $req );
    return (int) get_post_thumbnail_id( $post_id );
}

$pid   = make_post( $base );
$plain_photo = make_image( 1600, 1000, 'plain' );
$thumb = save_rest( $pid, [ 'featured_media' => $plain_photo ] );
$meta  = wp_get_attachment_metadata( $thumb );
check( 'saving stamps a copy of the photo', $thumb && $thumb !== $plain_photo );
check( 'stamped copy is 1280 × 768', ( $meta['width'] ?? 0 ) === 1280 && ( $meta['height'] ?? 0 ) === 768, wp_json_encode( $meta ) );
check( 'misleading gets the fact-checked stamp', strpos( get_attached_file( $thumb ), 'fact-checked' ) !== false );
check( 'the plain photo is kept', get_post( $plain_photo ) && (int) get_post_meta( $pid, '_fc_featured_source', true ) === $plain_photo );
check( 'fact card uses the plain photo, not the stamped one', fc_card_photo_id( $pid ) === $plain_photo && strpos( render( $pid ), 'plain' ) !== false );

// The stamp really is drawn: the bottom-left corner is no longer plain blue.
$im  = imagecreatefromjpeg( get_attached_file( $thumb ) );
$rgb = imagecolorat( $im, 104 + 150, 768 - 128 - 125 );
check( 'stamp is drawn in the corner', ( ( $rgb >> 16 ) & 255 ) > 150, dechex( $rgb ) );

check( 'saving again does not make another copy', save_rest( $pid, [ 'title' => 'Again' ] ) === $thumb );

$first = $thumb;
$thumb = save_rest( $pid, [ 'meta' => [ 'fc_rating' => 'insight' ] ] );
check( 'insight gets the insight badge', strpos( get_attached_file( $thumb ), 'insight' ) !== false );
check( 'old stamped copy is deleted', ! get_post( $first ) );

$thumb = save_rest( $pid, [ 'meta' => [ 'fc_rating' => 'news' ] ] );
check( 'news: plain photo, no stamp', $thumb === $plain_photo );

$thumb = save_rest( $pid, [ 'meta' => [ 'fc_featured_badge' => 'fact-checked' ] ] );
check( 'editor can force a stamp', $thumb !== $plain_photo && strpos( get_attached_file( $thumb ), 'fact-checked' ) !== false );

$made = $thumb;
$thumb = save_rest( $pid, [ 'meta' => [ 'fc_featured_badge' => 'none' ] ] );
check( '"No stamp" puts the plain photo back', $thumb === $plain_photo && ! get_post( $made ) );

$canva_featured = make_image( 1280, 768, 'canva-featured' );
$thumb = save_rest( $pid, [ 'featured_media' => $canva_featured ] );
check( 'own Canva featured image is left alone with "No stamp"', $thumb === $canva_featured );
check( 'the own image is never deleted', get_post( $canva_featured ) && get_post( $plain_photo ) );

$pid2 = make_post( $base );
$thumb = save_rest( $pid2, [ 'featured_media' => $plain_photo ] );
$other = $thumb;
$pid3 = make_post( $base );
$thumb = save_rest( $pid3, [ 'featured_media' => $other ] );
check( 'a stamped copy is never stamped again', (int) get_post_meta( $pid3, '_fc_featured_source', true ) === $plain_photo && get_post( $other ) );

save_rest( $pid2, [ 'featured_media' => 0 ] );
check( 'featured image removed: its stamped copy is deleted', ! get_post( $other ) && get_post( $plain_photo ) );

$old = wp_insert_post( [ 'post_title' => 'Old', 'post_status' => 'publish', 'post_date_gmt' => '2020-01-01 00:00:00', 'post_date' => '2020-01-01 00:00:00' ] );
foreach ( $base as $k => $v ) update_post_meta( $old, $k, $v );
update_option( 'fc_featured_since', time() - 60 );
check( 'older posts keep their image (likely already stamped)', save_rest( $old, [ 'featured_media' => $canva_featured ] ) === $canva_featured );

$pid4 = make_post( array_merge( $base, [ 'fc_is_fact_check' => '0' ] ) );
check( 'normal posts are not stamped', save_rest( $pid4, [ 'featured_media' => $plain_photo ] ) === $plain_photo );

update_option( 'fc_featured_stamps', '0' );
$pid5 = make_post( $base );
check( 'switched off in settings: nothing is stamped', save_rest( $pid5, [ 'featured_media' => $plain_photo ] ) === $plain_photo );
delete_option( 'fc_featured_stamps' );

$pid6 = make_post( $base );
$_POST = [ 'fc_fields_nonce' => wp_create_nonce( 'fc_save_fields' ) ] + wp_slash( $base ) + [ '_thumbnail_id' => $plain_photo ];
wp_update_post( [ 'ID' => $pid6, '_thumbnail_id' => $plain_photo ] );
$_POST = [];
$thumb = (int) get_post_thumbnail_id( $pid6 );
check( 'classic editor save stamps too', $thumb && $thumb !== $plain_photo );
wp_delete_post( $pid6, true );
check( 'deleting the post deletes its stamped copy', ! get_post( $thumb ) && get_post( $plain_photo ) );

wp_set_current_user( 0 );
$pid7 = make_post( $base );
set_post_thumbnail( $pid7, $plain_photo );
fc_featured_sync( $pid7 );
wp_set_current_user( 1 );
check( 'logged-out saves never make images', (int) get_post_thumbnail_id( $pid7 ) === $plain_photo );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Site list' );

reset_options();
check( 'test site address is not in the list', fc_site_key() === '' );
update_option( 'fc_site_key', 'english.factcrescendo.com' );
check( 'admin can pick an entry', fc_site_key() === 'english.factcrescendo.com' );
check( 'English entry is English', fc_site_lang() === 'en' );
update_option( 'fc_site_key', 'not-a-real-site.com' );
check( 'unknown entry is ignored', fc_site_key() === '' );

add_filter( 'home_url', $fake_home = function() { return 'https://www.factcrescendo.com'; } );
delete_option( 'fc_site_key' );
check( 'www. address finds its entry', fc_site_key() === 'factcrescendo.com' && fc_site_lang() === 'hi' );
remove_filter( 'home_url', $fake_home );

foreach ( fc_site_profiles() as $key => $p ) {
    check( "entry $key is complete", ! empty( $p['whatsapp_url'] ) && ! empty( $p['whatsapp_number'] ) && isset( $p['join_language'] ) && strpos( $p['banner_image'], 'https://' ) === 0 && ! empty( $p['label'] ) );
}


// ─────────────────────────────────────────────────────────────────────────────
section( 'WhatsApp banner' );

reset_options();
$pid = make_post( $base );
check( 'site not in the list: no banner', strpos( render( $pid ), 'fc-whatsapp-banner' ) === false );

update_option( 'fc_site_key', 'malayalam.factcrescendo.com' );
$html = render( $pid );
check( 'listed site: new banner added', strpos( $html, 'class="fc-wa-card' ) !== false );
check( 'banner shows the number', strpos( $html, '+91 90490 53770' ) !== false );
check( 'banner says which language to send', strpos( $html, 'language as Malayalam' ) !== false );
check( 'banner links to WhatsApp in a new tab', strpos( $html, 'api.whatsapp.com/send?phone=919049053770' ) !== false && strpos( $html, 'noopener' ) !== false );
check( 'banner adds no headings to the article', strpos( substr( $html, strpos( $html, '<a class="fc-wa-card' ) ), '<h' ) === false );

update_option( 'fc_whatsapp_banner_style', 'image' );
$html = render( $pid );
check( 'old picture banner can still be chosen', strpos( $html, 'Malayalam-2.png' ) !== false && strpos( $html, 'alt="Send us a message on WhatsApp"' ) !== false && strpos( $html, 'fc-wa-card' ) === false );
delete_option( 'fc_whatsapp_banner_style' );

update_option( 'fc_site_key', 'srilanka.factcrescendo.com' );
$html = render( $pid );
check( 'Sri Lanka: its own number, no broadcast line', strpos( $html, '+94 77 151 4696' ) !== false && strpos( $html, 'broadcast' ) === false );
update_option( 'fc_site_key', 'malayalam.factcrescendo.com' );

add_filter( 'fc_whatsapp_banner_text', $tr = function( $t ) { $t['button'] = '<b>Translated</b>'; return $t; } );
check( 'banner words can be translated, and are escaped', strpos( render( $pid ), '&lt;b&gt;Translated&lt;/b&gt;' ) !== false );
remove_filter( 'fc_whatsapp_banner_text', $tr );

$pasted = make_post( $base, '<p>Body</p><figure><a href="https://api.whatsapp.com/send?phone=919049053770"><img src="x.png"></a></figure>' );
check( 'article with a pasted banner gets no second one', strpos( render( $pasted ), 'fc-whatsapp-banner' ) === false );

update_option( 'fc_show_whatsapp_banner', '0' );
check( 'switched off in settings: no banner', strpos( render( $pid ), 'fc-whatsapp-banner' ) === false );

update_option( 'fc_show_whatsapp_banner', '1' );
update_option( 'fc_whatsapp_banner_html', '<p class="custom-banner">Custom</p>' );
update_option( 'fc_site_key', 'srilanka.factcrescendo.com' );
$html = render( $pid );
check( 'old pasted banner no longer hides the chosen site (Sri Lanka bug)', strpos( $html, 'custom-banner' ) === false && strpos( $html, '+94 77 151 4696' ) !== false );
update_option( 'fc_whatsapp_banner_style', 'custom' );
check( '"My own banner" shows the pasted code', strpos( render( $pid ), 'custom-banner' ) !== false );
update_option( 'fc_whatsapp_banner_style', 'off' );
check( '"No banner" shows none', strpos( render( $pid ), 'fc-whatsapp-banner' ) === false );
delete_option( 'fc_whatsapp_banner_style' );

$own = '<style>.x{color:red}</style><a href="https://api.whatsapp.com/send?phone=1" class="fc-wa-card" target="_blank" aria-label="WhatsApp"><div><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 1"/></svg></div><h4 class="t">Title</h4><p>Line<br>two <span>+91</span></p></a>';
$kept = fc_sanitize_banner_html( $own );
check( 'your banner code is kept as written', strpos( $kept, '<style>' ) !== false && strpos( $kept, 'viewbox' ) !== false || strpos( $kept, 'viewBox' ) !== false );
check( 'your banner keeps line breaks and labels', strpos( $kept, '<br' ) !== false && strpos( $kept, 'aria-label="WhatsApp"' ) !== false );

$saved = fc_sanitize_banner_html( '<p onclick="alert(1)">Hi</p><script>alert(1)</script>' );
check( 'pasted banner is cleaned of scripts', strpos( $saved, 'script' ) === false && strpos( $saved, 'onclick' ) === false );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Audio player' );

reset_options();
update_option( 'fc_site_key', 'english.factcrescendo.com' );
$html = render( $pid );
check( 'English site: player shown', strpos( $html, 'fc-a-wrap' ) !== false );
check( 'player code is a separate file, not inside the article', strpos( $html, 'ICON_PLAY' ) === false && wp_script_is( 'fc-audio-player', 'enqueued' ) );

update_option( 'fc_site_key', 'factcrescendo.com' );
check( 'Hindi site: no player', strpos( render( $pid ), 'fc-a-wrap' ) === false );

update_post_meta( $pid, 'fc_audio_url', 'https://example.com/a.mp3' );
check( 'Hindi post with an existing recording keeps it', strpos( render( $pid ), 'fc-a-wrap' ) !== false );
delete_post_meta( $pid, 'fc_audio_url' );

update_option( 'fc_site_key', 'english.factcrescendo.com' );
update_option( 'fc_enable_audio', '0' );
check( 'switched off in settings: no player', strpos( render( $pid ), 'fc-a-wrap' ) === false );
reset_options();


// ─────────────────────────────────────────────────────────────────────────────
section( 'Google fact-check data (ClaimReview)' );

$pid   = make_post( array_merge( $base, [ 'fc_claim_author' => 'Viral post', 'fc_claim_source_url' => 'https://example.com/v' ] ) );
$head = render_head( $pid );
preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $m );
$json = isset( $m[1] ) ? json_decode( $m[1], true ) : null;
check( 'schema is valid JSON', is_array( $json ), $head );
check( 'type is ClaimReview', ( $json['@type'] ?? '' ) === 'ClaimReview' );
check( 'claim reviewed is the claim', ( $json['claimReviewed'] ?? '' ) === $base['fc_claim'] );
check( 'verdict text is Misleading', ( $json['reviewRating']['alternateName'] ?? '' ) === 'Misleading' );
check( 'claim source included', ( $json['itemReviewed']['appearance']['url'] ?? '' ) === 'https://example.com/v' );
check( 'normal post has no schema', render_head( $plain ) === '' );


// ─────────────────────────────────────────────────────────────────────────────
section( 'REST API' );

$res  = rest_do_request( new WP_REST_Request( 'GET', '/fc/v1/post/' . $pid ) );
$data = $res->get_data();
update_post_meta( $pid, 'fc_fact', 'Was **false** all along' );
$data = rest_do_request( new WP_REST_Request( 'GET', '/fc/v1/post/' . $pid ) )->get_data();
check( 'API fact has no ** marks', ( $data['fact'] ?? '' ) === 'Was false all along' );
update_post_meta( $pid, 'fc_fact', $base['fc_fact'] );
$data = rest_do_request( new WP_REST_Request( 'GET', '/fc/v1/post/' . $pid ) )->get_data();
check( 'single post endpoint works', $res->get_status() === 200 && ( $data['claim'] ?? '' ) === $base['fc_claim'] && $data['is_fact_check'] === true, wp_json_encode( $data ) );

$locked = make_post( $base );
wp_update_post( [ 'ID' => $locked, 'post_password' => 'secret' ] );
check( 'password-protected posts are not exposed', rest_do_request( new WP_REST_Request( 'GET', '/fc/v1/post/' . $locked ) )->get_status() === 404 );
wp_set_current_user( 0 );
$core = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $locked ) )->get_data();
check( 'locked posts: the standard posts API hides the fields', ! isset( $core['meta']['fc_claim'] ) && ! isset( $core['meta']['fc_rating'] ), wp_json_encode( $core['meta'] ?? [] ) );
check( 'locked posts: the page shows no card or verdict', strpos( render( $locked ), 'A viral photo' ) === false && strpos( render( $locked ), 'fc-article-card' ) === false );
check( 'locked posts: no Google fact-check data', render_head( $locked ) === '' );
wp_set_current_user( 1 );

$draft = wp_insert_post( [ 'post_title' => 'Draft', 'post_status' => 'draft' ] );
check( 'drafts are not exposed', rest_do_request( new WP_REST_Request( 'GET', '/fc/v1/post/' . $draft ) )->get_status() === 404 );

$res = rest_do_request( new WP_REST_Request( 'GET', '/fc/v1/posts' ) );
check( 'list endpoint works', $res->get_status() === 200 && $res->get_data()['total_posts'] > 0 );

wp_set_current_user( 0 );
$req = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $pid );
$req->set_body_params( [ 'meta' => [ 'fc_claim' => 'defaced' ] ] );
rest_do_request( $req );
check( 'visitors cannot change fields through the API', get_post_meta( $pid, 'fc_claim', true ) === $base['fc_claim'], get_post_meta( $pid, 'fc_claim', true ) );
wp_set_current_user( 1 );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Safety' );

$req = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $pid );
$req->set_body_params( [ 'meta' => [ 'fc_audio_url' => 'https://evil.example/a.mp3' ] ] );
rest_do_request( $req );
check( 'nobody can set the audio address through the API', get_post_meta( $pid, 'fc_audio_url', true ) === '' );

check( 'updates only from this GitHub repository', fc_updater_trusted_url( 'https://api.github.com/repos/lemoonrick/factcrescendo-publisher/zipball/v4.7.3' )
    && fc_updater_trusted_url( 'https://github.com/lemoonrick/factcrescendo-publisher/releases/download/v4.7.3/x.zip' )
    && ! fc_updater_trusted_url( 'https://evil.example/lemoonrick/factcrescendo-publisher/x.zip' )
    && ! fc_updater_trusted_url( 'https://github.com/someone-else/factcrescendo-publisher/x.zip' )
    && ! fc_updater_trusted_url( 'http://github.com/lemoonrick/factcrescendo-publisher/x.zip' ) );
check( 'updates never install on their own', apply_filters( 'auto_update_plugin', true, (object) [ 'plugin' => fc_updater_basename() ] ) === false );

$editor = wp_insert_user( [ 'user_login' => 'ed' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
wp_set_current_user( $editor );
check( 'without raw-HTML rights, style blocks are removed', strpos( fc_sanitize_banner_html( '<style>.x{}</style><p>Hi</p>' ), '<style' ) === false );
wp_set_current_user( 1 );
$saved_css = '<style>.x{}</style><p>Hi</p>';
check( 'saved banner styles are kept when shown', strpos( fc_sanitize_banner_html( $saved_css, true ), '<style' ) !== false );

delete_transient( 'fc_api_posts_1' );
$r1 = rest_do_request( new WP_REST_Request( 'GET', '/fc/v1/posts' ) );
check( 'list API is cached briefly', get_transient( 'fc_api_posts_1' ) !== false && ( $r1->get_headers()['Cache-Control'] ?? '' ) === 'public, max-age=120' );
$big = new WP_REST_Request( 'GET', '/fc/v1/posts' ); $big->set_param( 'page', 999999 );
check( 'list API page number is capped', rest_do_request( $big )->get_data()['page'] === 500 );


// ─────────────────────────────────────────────────────────────────────────────
echo "\n{$GLOBALS['fc_passes']} passed, {$GLOBALS['fc_failures']} failed\n";
exit( $GLOBALS['fc_failures'] ? 1 : 0 );
