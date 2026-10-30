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
fc_embed_register_handlers();

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
    delete_option( 'fc_auto_embeds' );
    delete_option( 'fc_embeds_since' );
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
check( 'new card is the default', strpos( $html, 'class="fc-fc' ) !== false && strpos( $html, 'fc-fc-boxes' ) !== false );
check( 'card shows the claim', strpos( $html, esc_html( $base['fc_claim'] ) ) !== false );
check( 'card shows the fact, with its highlight', strpos( $html, 'Fact &amp; <mark>line two</mark>' ) !== false );
$card = fc_build_card( $pid, 'misleading', 'Misleading', '<img src=x onerror=alert(1)>', '**<script>x</script>**' );
check( 'card escapes claim and fact, even inside a highlight', strpos( $card, '<img src=x' ) === false && strpos( $card, '<mark>&lt;script&gt;x&lt;/script&gt;</mark>' ) !== false );
check( 'card shows the rating as the result', strpos( $html, '<p class="fc-fc-result-text">Misleading</p>' ) !== false );
update_option( 'fc_site_key', 'factcrescendo.com' );
$card = fc_build_card( $pid, 'misleading', 'Misleading', $base['fc_claim'], $base['fc_fact'] );
check( 'India site: its number and Follow us on the card', strpos( $card, '>9049053770<' ) !== false && strpos( $card, 'fc-fc-socs' ) !== false );
update_option( 'fc_site_key', 'srilanka.factcrescendo.com' );
$card = fc_build_card( $pid, 'misleading', 'Misleading', $base['fc_claim'], $base['fc_fact'] );
check( 'Sri Lanka site: its own number', strpos( $card, '+94 77 151 4696' ) !== false && strpos( $card, '9049053770' ) === false );
reset_options();
check( 'site with no number: no WhatsApp part', strpos( fc_build_card( $pid, 'misleading', 'Misleading', 'c', 'f' ), '<div class="fc-fc-contact"' ) === false );
check( 'no photo: no stamp', strpos( $html, '<img class="fc-fc-stamp' ) === false );
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

$photo = make_image();
set_post_thumbnail( $pid, $photo );
$html = render( $pid );
check( 'featured image shown in the card', strpos( $html, '<figure class="fc-fc-photo" style="aspect-ratio:1.6"' ) !== false );
check( 'the rating\'s stamp sits on the photo', strpos( $html, 'class="fc-fc-stamp" src="' ) !== false && strpos( $html, 'assets/stamps/Misleading.png' ) !== false );
$f_card = fc_build_card( $pid, 'false', 'False', 'c', 'f' );
check( 'stamp follows the rating', strpos( $f_card, 'assets/stamps/False.png' ) !== false );

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
section( 'Images (WebP)' );

reset_options();
delete_option( 'fc_webp_uploads' );
delete_option( 'fc_webp_quality' );

/** A temporary picture file: 'jpeg', 'png' (transparent corner) or 'gif'. Noisy, so quality matters to its size. */
function tmp_picture( $kind, $w = 900, $h = 600 ) {
    $im = imagecreatetruecolor( $w, $h );
    if ( $kind === 'png' ) { imagealphablending( $im, false ); imagesavealpha( $im, true ); imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) ); imagealphablending( $im, true ); }
    mt_srand( 7 );
    for ( $i = 0; $i < 3000; $i++ ) imagefilledrectangle( $im, $x = mt_rand( 40, $w - 40 ), $y = mt_rand( 40, $h - 40 ), $x + mt_rand( 2, 30 ), $y + mt_rand( 2, 30 ), imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
    $path = trailingslashit( get_temp_dir() ) . 'fc-test-' . wp_generate_password( 8, false ) . ( $kind === 'jpeg' ? '.jpg' : '.' . $kind );
    $kind === 'jpeg' ? imagejpeg( $im, $path, 92 ) : ( $kind === 'png' ? imagepng( $im, $path ) : imagegif( $im, $path ) );
    return $path;
}
function sideload_file( $path ) {
    $file = [ 'name' => basename( $path ), 'tmp_name' => $path, 'error' => 0, 'size' => filesize( $path ) ];
    return wp_handle_sideload( $file, [ 'test_form' => false ] );
}

check( 'this server can make WebP (needed for the checks below)', fc_webp_can() && fc_webp_gd() );

$jpg   = tmp_picture( 'jpeg' );
$orig  = filesize( $jpg );
$up    = sideload_file( $jpg );
check( 'a JPEG is saved as WebP', ( $up['type'] ?? '' ) === 'image/webp' && substr( $up['file'] ?? '', -5 ) === '.webp' && is_readable( $up['file'] ) && substr( $up['url'], -5 ) === '.webp' );
check( 'the original JPEG is gone, only the WebP is kept', ! file_exists( preg_replace( '/\.webp$/', '.jpg', $up['file'] ) ) );
$wi = getimagesize( $up['file'] );
check( 'the WebP has the same size and is smaller than the JPEG', $wi[0] === 900 && $wi[1] === 600 && $wi['mime'] === 'image/webp' && filesize( $up['file'] ) < $orig, filesize( $up['file'] ) . ' vs ' . $orig );

$png = sideload_file( tmp_picture( 'png' ) );
$px  = imagecreatefromwebp( $png['file'] );
check( 'a transparent PNG keeps its transparency as WebP', ( $png['type'] ?? '' ) === 'image/webp' && ( ( imagecolorat( $px, 5, 5 ) >> 24 ) & 127 ) > 100 );

$gif = sideload_file( tmp_picture( 'gif' ) );
check( 'a GIF is left alone (it may be animated)', ( $gif['type'] ?? '' ) === 'image/gif' && substr( $gif['file'], -4 ) === '.gif' );

$again = sideload_file( $png['file'] );
check( 'a file that is already WebP is left alone', ( $again['type'] ?? '' ) === 'image/webp' );

// A real media-library item: its sizes are WebP too.
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$sl  = [ 'name' => 'pasted-from-docs.jpg', 'tmp_name' => tmp_picture( 'jpeg', 1600, 1000 ), 'error' => 0 ];
$att = media_handle_sideload( $sl, 0 );
$am  = is_wp_error( $att ) ? [] : wp_get_attachment_metadata( $att );
check( 'in the media library it is a WebP item', ! is_wp_error( $att ) && get_post_mime_type( $att ) === 'image/webp' && substr( get_attached_file( $att ), -5 ) === '.webp', is_wp_error( $att ) ? $att->get_error_message() : '' );
check( 'its smaller sizes are WebP too', ! empty( $am['sizes']['thumbnail']['file'] ) && substr( $am['sizes']['thumbnail']['file'], -5 ) === '.webp' && ( $am['sizes']['thumbnail']['mime-type'] ?? '' ) === 'image/webp', wp_json_encode( $am['sizes']['thumbnail'] ?? null ) );
check( 'the article gets the WebP address', substr( wp_get_attachment_url( $att ), -5 ) === '.webp' );

update_option( 'fc_webp_quality', '70' );  $small = sideload_file( tmp_picture( 'jpeg' ) );
update_option( 'fc_webp_quality', '90' );  $sharp = sideload_file( tmp_picture( 'jpeg' ) );
check( 'the quality setting changes the file size', filesize( $small['file'] ) < filesize( $sharp['file'] ), filesize( $small['file'] ) . ' vs ' . filesize( $sharp['file'] ) );
delete_option( 'fc_webp_quality' );
fc_register_settings();
check( 'an odd quality value falls back to the default', apply_filters( 'sanitize_option_fc_webp_quality', '55' ) === '82' && apply_filters( 'sanitize_option_fc_webp_uploads', 'x' ) === '1' );

// Servers that only have PHP's own GD tools (no Imagick) work too.
add_filter( 'wp_image_editors', $only_gd = function () { return [ 'WP_Image_Editor_GD' ]; } );
update_option( 'fc_webp_quality', '70' );  $gd_small = sideload_file( tmp_picture( 'jpeg' ) );
update_option( 'fc_webp_quality', '90' );  $gd_sharp = sideload_file( tmp_picture( 'jpeg' ) );
check( 'with GD only: converts to WebP and the quality setting applies', ( $gd_small['type'] ?? '' ) === 'image/webp' && filesize( $gd_small['file'] ) < filesize( $gd_sharp['file'] ) );
delete_option( 'fc_webp_quality' );
remove_filter( 'wp_image_editors', $only_gd );

update_option( 'fc_webp_uploads', '0' );
$off = sideload_file( tmp_picture( 'jpeg' ) );
check( 'switched off in Settings: photos stay as they are', ( $off['type'] ?? '' ) === 'image/jpeg' && substr( $off['file'], -4 ) === '.jpg' );
delete_option( 'fc_webp_uploads' );

// Never breaks an upload.
$junk = trailingslashit( get_temp_dir() ) . 'junk.jpg'; file_put_contents( $junk, 'not a picture' );
check( 'a file that is not really a picture is left for WordPress to handle', fc_webp_convert( $junk, 'image/jpeg' ) === '' && file_exists( $junk ) );
$keep = [ 'error' => 'Sorry, something went wrong.' ];
check( 'a failed upload is passed on untouched', fc_webp_on_upload( $keep ) === $keep );

$apng = tmp_picture( 'png' );
$data = file_get_contents( $apng );
$chunk = pack( 'N', 8 ) . 'acTL' . pack( 'N2', 2, 0 ) . pack( 'N', crc32( 'acTL' . pack( 'N2', 2, 0 ) ) );
file_put_contents( $apng, substr( $data, 0, 33 ) . $chunk . substr( $data, 33 ) );
check( 'an animated PNG is left alone', fc_webp_convert( $apng, 'image/png' ) === '' );

check( 'a picture too big for the memory this request has is left alone', fc_webp_fits_memory( 800, 600 ) && ! fc_webp_fits_memory( 20000, 20000 ) );
add_filter( 'fc_webp_bytes_per_pixel', $huge = function () { return 100000; } );
check( 'a tight server keeps the original instead of risking the upload', fc_webp_convert( $jpg, 'image/jpeg' ) === '' );
remove_filter( 'fc_webp_bytes_per_pixel', $huge );
add_filter( 'fc_webp_bytes_per_pixel', $boom = function () { throw new Exception( 'boom' ); } );
fc_webp_on_upload( [ 'file' => $jpg, 'url' => 'http://x/y.jpg', 'type' => 'image/jpeg' ] );
remove_filter( 'fc_webp_bytes_per_pixel', $boom );
check( 'an error while converting never leaves the quality override switched on', empty( $GLOBALS['fc_webp_converting'] ) );
$none = [ 'file' => $jpg, 'url' => 'http://x/y.jpg', 'type' => 'image/jpeg' ];
add_filter( 'wp_image_editors', '__return_empty_array' );
check( 'a server without WebP support keeps the original', fc_webp_on_upload( $none ) === $none );
remove_filter( 'wp_image_editors', '__return_empty_array' );

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
$im  = imagecreatefromstring( file_get_contents( get_attached_file( $thumb ) ) );
$rgb = imagecolorat( $im, 104 + 150, 768 - 128 - 125 );
check( 'stamp is drawn in the corner', ( ( $rgb >> 16 ) & 255 ) > 150, dechex( $rgb ) );
check( 'the stamped featured image is a WebP file like every other photo', get_post_mime_type( $thumb ) === 'image/webp' && substr( get_attached_file( $thumb ), -5 ) === '.webp' );

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
section( 'One copy only, and a card that keeps its photo' );

/** How many stamped copies this plugin holds for a post. */
function copies_for( $post_id ) {
    return count( get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_fc_generated_for', 'meta_value' => $post_id, 'suppress_filters' => true ] ) );
}

reset_options();
$one   = make_post( $base );
$thumb = save_rest( $one, [ 'featured_media' => $plain_photo ] );
check( 'one stamped copy after the first save', copies_for( $one ) === 1 && $thumb !== $plain_photo );

// A "hide featured image" snippet makes WordPress say there is no featured image.
add_filter( 'get_post_metadata', $hide = function ( $v, $id, $key ) { return $key === '_thumbnail_id' ? '' : $v; }, 10, 3 );
$html = render( $one );
remove_filter( 'get_post_metadata', $hide );
check( 'card keeps its photo even when a snippet hides the featured image', strpos( $html, '<figure class="fc-fc-photo"' ) !== false );
check( 'and it is the plain photo, not the stamped one', fc_card_photo_id( $one ) === $plain_photo );

delete_post_meta( $one, '_thumbnail_id' );
$html = render( $one );
check( 'no photo at all: the page source says why', strpos( $html, '<!-- Fact Crescendo: no card photo found' ) !== false );
set_post_thumbnail( $one, $thumb );

// A save running at the same moment makes a stray copy; the next save removes it.
$files = fc_featured_stamp_files();
$stray = fc_featured_make( $one, $plain_photo, $files['fact-checked'], 'fact-checked' );
check( 'a stray copy can appear (two saves at once)', copies_for( $one ) === 2 );
save_rest( $one, [ 'title' => 'Saved again' ] );
check( 'the next save removes the stray', copies_for( $one ) === 1 && ! get_post( $stray ) && get_post( $thumb ) );

// The lock: a second run while one is busy does nothing; an old lock is ignored.
add_option( 'fc_lock_' . $one, time(), '', 'no' );
check( 'a second save is held off while one is running', fc_featured_lock( $one ) === false );
update_option( 'fc_lock_' . $one, time() - 600 );
check( 'an old lock does not block forever', fc_featured_lock( $one ) === true );
delete_option( 'fc_lock_' . $one );
check( 'the lock is released after every save', ( save_rest( $one, [ 'title' => 'Again' ] ) || true ) && get_option( 'fc_lock_' . $one ) === false );

// Media Library: only the editor's own pictures are listed.
$q = fc_hide_generated( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ] );
$listed = get_posts( $q + [ 'suppress_filters' => true ] );
check( 'the picture chooser lists the plain photo, not the stamped copy', in_array( $plain_photo, $listed, true ) && ! in_array( (int) get_post_thumbnail_id( $one ), $listed, true ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media/' . (int) get_post_thumbnail_id( $one ) ) );
check( 'the editor can still show the stamped featured image', $res->get_status() === 200 );
add_filter( 'fc_hide_generated_copies', '__return_false' );
check( 'the hiding can be switched off', ! isset( fc_hide_generated( [] )['meta_query'] ) );
remove_filter( 'fc_hide_generated_copies', '__return_false' );

$copy = (int) get_post_thumbnail_id( $one );
wp_delete_post( $one, true );
check( 'deleting the post leaves no stamped copy behind', ! get_post( $copy ) && get_post( $plain_photo ) );


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
$channel = make_post( $base, '<p>Body <a href="https://chat.whatsapp.com/abc" onclick="window.open(\'https://wa.me/94721414696 \')">Join our channel</a> or <a href="https://api.whatsapp.com/send?phone=15551234567">another number</a></p>' );
check( 'another WhatsApp link in the article (channel, other number) does not hide the banner', strpos( render( $channel ), 'fc-whatsapp-banner' ) !== false );
$own_text = make_post( $base, '<p>Send it to our <a href="https://api.whatsapp.com/send?phone=919049053770&#038;text=Hi">WhatsApp number (9049053770)</a>.</p><p><em>Or <a href="https://wa.me/919049053770">here</a></em></p>' );
check( 'text links to our own number do not hide the banner', strpos( render( $own_text ), 'fc-whatsapp-banner' ) !== false );
$own_nested = make_post( $base, '<p><a href="https://api.whatsapp.com/send?phone=919049053770"><span>Message us</span></a></p>' );
check( 'a text-only button to our number does not hide the banner', strpos( render( $own_nested ), 'fc-whatsapp-banner' ) !== false );
$own_img = make_post( $base, '<figure class="wp-block-image"><a href="https://api.whatsapp.com/send?phone=919049053770&#038;text=x" target="_blank"><img src="b.png"></a></figure><p>Text</p>' );
check( 'a pasted banner picture for our number still gets no second banner', strpos( render( $own_img ), 'fc-whatsapp-banner' ) === false );

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
check( 'Insight and News articles are not marked as fact checks for Google', ! fc_claimreview_for_rating( 'insight' ) && ! fc_claimreview_for_rating( 'News' ) );
check( '…every real verdict still is', fc_claimreview_for_rating( 'false' ) && fc_claimreview_for_rating( 'misleading' ) && fc_claimreview_for_rating( 'missing-context' ) && fc_claimreview_for_rating( 'satire' ) && fc_claimreview_for_rating( 'altered' ) && fc_claimreview_for_rating( 'partly-false' ) );

// ─────────────────────────────────────────────────────────────────────────────
section( 'Updates from GitHub' );

$gh_calls = [];
$gh_tags  = [ 'v4.9.1', 'v4.8.0', 'v4.10.0', 'v4.9.0', 'v4.10.0-rc1', 'beta' ];
$gh_mock  = function ( $pre, $args, $url ) use ( &$gh_calls, &$gh_tags ) {
    if ( strpos( $url, 'api.github.com' ) === false ) return $pre;
    $gh_calls[] = $url;
    if ( strpos( $url, '/releases/tags/' ) !== false ) return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( [ 'body' => 'What changed', 'published_at' => '2026-10-29T10:00:00Z' ] ) ];
    $body = array_map( function ( $t ) { return [ 'name' => $t, 'zipball_url' => 'https://api.github.com/repos/lemoonrick/factcrescendo-publisher/zipball/' . $t ]; }, $gh_tags );
    return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( $body ) ];
};
add_filter( 'pre_http_request', $gh_mock, 10, 3 );
delete_transient( FC_UPDATER_CACHE_KEY );
$latest = fc_updater_latest_version( true );
check( 'the highest version tag is offered, whatever order GitHub lists them in', ( $latest['version'] ?? '' ) === '4.10.0' && ( $latest['tag'] ?? '' ) === 'v4.10.0' );
check( 'one request to GitHub per check (tags only)', count( $gh_calls ) === 1 && strpos( $gh_calls[0], '/tags' ) !== false );
check( 'the answer is kept for 3 hours, not 12', FC_UPDATER_CACHE_TTL === 3 * HOUR_IN_SECONDS );
$gh_tags[] = 'v4.11.0';
check( 'a normal check uses the saved answer', fc_updater_latest_version()['version'] === '4.10.0' );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'update-core' );
$_GET['force-check'] = '1';
$forced = fc_updater_latest_version();
fc_updater_latest_version();
unset( $_GET['force-check'] );
check( 'WordPress\'s own "Check again" button asks GitHub straight away (once per page)', $forced['version'] === '4.11.0' && count( $gh_calls ) === 2 );
$GLOBALS['current_screen'] = null;
$details = fc_updater_plugin_details( false, 'plugin_information', (object) [ 'slug' => fc_updater_slug() ] );
check( 'release notes are only fetched for "View details"', strpos( $details->sections['changelog'] ?? '', 'What changed' ) !== false && count( $gh_calls ) === 3 );
remove_filter( 'pre_http_request', $gh_mock, 10 );
delete_transient( FC_UPDATER_CACHE_KEY );



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
section( 'Embeds' );

reset_options();
function para( $html ) { return "<!-- wp:paragraph -->\n<p>$html</p>\n<!-- /wp:paragraph -->\n"; }
$doc = para( 'Intro with an <a href="https://www.facebook.com/odishalive/posts/123">Odisha live news</a> link.' )
     . para( 'Embed' )
     . para( '<a href="https://archive.org/details/fb-post-081026">https://archive.org/details/fb-post-081026</a>' )
     . para( '<a href="https://www.facebook.com/x/posts/1">Facebook Post</a>|<a href="https://archive.org/details/y">Archive link</a>' )
     . para( '<strong>(Side-by-side):</strong>' )
     . para( 'https://www.instagram.com/reel/DeRV_RoyoIZ/?igsh=abc123' )
     . para( '&lt;iframe frameborder=&#8221;0&#8243; width=&#8221;640&#8243; src=&#8221;https://www.awesomescreenshot.com/embed?id=57468985&amp;shareKey=43083a894f6137751491<br>bdf604402ad9&#8221;&gt;&lt;/iframe&gt;' )
     . para( '&lt;iframe src="https://www.facebook.com/plugins/video.php?height=476&amp;href=https%3A%2F%2Fwww.facebook.com%2Freel%2F823825144054334%2F&amp;show_text=true" width="267"&gt;&lt;/iframe&gt;' )
     . para( 'Embed:' )
     . para( '<mark><a href="https://srilanka.factcrescendo.com/wp-content/uploads/2026/10/image-92.png">https://srilanka.factcrescendo.com/wp-content/uploads/2026/10/image-92.png</a></mark>' )
     . para( 'https://www.awesomescreenshot.com/video/57468985?key=43083a894f6137751491bdf604402ad9' )
     . para( '&lt;iframe src="https://evil.example.com/x"&gt;&lt;/iframe&gt;' )
     . para( 'https://www.google.com/url?q=https://archive.org/details/zzz&amp;sa=D' )
     . para( 'https://example.com/wp-content/uploads/a.png' )
     . para( '<a href="javascript:alert(1)">https://archive.org/details/q</a>' )
     . para( 'https://www.facebook.com/SomePage' )
     . para( 'We embed the evidence below.' )
     . para( '<a href="https://www.youtube.com/watch?v=muERuPLVZTU">https://www.youtube.com/watch?v=muERuPLVZTU</a>' )
     . para( 'https://x.com/PIBFactCheck/status/2111340344063496466?s=20' )
     . para( '&lt;p class="has-black-color has-background" style="background-color:#b6babf91"&gt;&lt;strong&gt;&lt;a href="https://www.whatsapp.com/channel/0029Va5pj8o4IBh5WxNhlE0I"&gt; Subscribe to our WhatsApp Channel &lt;/a&gt;&lt;/strong&gt;&lt;/p&gt;' )
     . para( '&lt;p onclick="alert(1)"&gt;Hi&lt;script&gt;alert(2)&lt;/script&gt;&lt;/p&gt;' )
     . "<!-- wp:embed {\"url\":\"https://www.instagram.com/p/ABC123/\",\"type\":\"rich\",\"providerNameSlug\":\"instagram\"} -->\n<figure class=\"wp-block-embed is-type-rich is-provider-instagram\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.instagram.com/p/ABC123/\n</div></figure>\n<!-- /wp:embed -->\n"
     . para( 'Embed' );
$pid   = make_post( $base, $doc );
$saved = get_post_field( 'post_content', $pid );
$html  = render( $pid );
check( 'Archive.org link is saved as a Custom HTML player', strpos( $saved, "<!-- wp:html -->\n<div class=\"fc-embed fc-embed--archive\"" ) !== false && strpos( $saved, 'src="https://archive.org/embed/fb-post-081026"' ) !== false );
check( 'author notes above embeds are deleted from the article ("Embed", "Embed:", "(Side-by-side):")', substr_count( $saved, '>Embed' ) === 1 && stripos( $saved, 'side-by-side' ) === false );
check( 'a note word that is not above an embed stays', strpos( $saved, "<p>Embed</p>\n<!-- /wp:paragraph -->\n" ) !== false && substr( rtrim( $saved ), -strlen( '<!-- /wp:paragraph -->' ) ) === '<!-- /wp:paragraph -->' );
check( 'article sentences with the same word stay', strpos( $saved, 'We embed the evidence below.' ) !== false );
check( 'links inside a sentence are left alone', strpos( $saved, '<a href="https://www.facebook.com/odishalive/posts/123">Odisha live news</a>' ) !== false );
$two = fc_embed_convert( para( '<a href="https://www.facebook.com/x/posts/1">https://www.facebook.com/x/posts/1</a>|<a href="https://archive.org/details/y">https://archive.org/details/y</a>' ) . para( '<a href="https://x.com/PIB/status/1">ट्विटर लिंक</a> |' ) . para( '<a href="https://x.com/PIB/status/1">ट्विटर लिंक</a>' ) . para( '&lt;iframe src="https://archive.org/embed/a"&gt;&lt;/iframe&gt; &lt;iframe src="https://archive.org/embed/b"&gt;&lt;/iframe&gt;' ) . para( 'https://archive.org/details/y<br>https://www.facebook.com/x/posts/1' ) );
check( 'two links on one line (even shown as addresses) are never merged into one player: no link is lost', substr_count( $two, 'facebook.com/x/posts/1' ) === 3 && substr_count( $two, 'archive.org/details/y' ) === 3 && strpos( $two, 'fc-embed' ) === false );
check( 'source-link labels ("ट्विटर लिंक |") are kept', substr_count( $two, 'ट्विटर लिंक' ) === 2 );
check( 'two pasted embed codes in one paragraph are kept as text, not reduced to one player', substr_count( $two, 'archive.org/embed/' ) === 2 );
check( 'a link line with words ("Facebook Post | Archive link") is left alone', strpos( $saved, '>Facebook Post</a>|<a' ) !== false );
check( 'Instagram reel becomes the post, without tracking bits', strpos( $saved, 'src="https://www.instagram.com/reel/DeRV_RoyoIZ/embed/captioned/"' ) !== false && strpos( $saved, 'igsh' ) === false );
check( 'Awesome Screenshot code pasted as text (curly quotes, broken line) becomes the player', strpos( $saved, 'awesomescreenshot.com/embed?id=57468985&#038;shareKey=43083a894f6137751491bdf604402ad9' ) !== false );
check( 'Awesome Screenshot share link becomes the player', substr_count( $saved, 'awesomescreenshot.com/embed?id=57468985' ) === 2 );
check( 'Facebook reel code pasted as text becomes a tall player', strpos( $saved, 'plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Freel%2F823825144054334%2F' ) !== false && strpos( $saved, 'max-width:320px' ) !== false );
check( 'pasted code is never copied: only our own player markup', strpos( $saved, 'height=476' ) === false && strpos( $saved, 'frameborder' ) === false );
check( 'our own picture link is saved as an Image block', strpos( $saved, "<!-- wp:image {\"align\":\"center\"} -->\n<figure class=\"wp-block-image aligncenter\"><img src=\"https://srilanka.factcrescendo.com/wp-content/uploads/2026/10/image-92.png\" alt=\"\"/></figure>" ) !== false );
check( 'YouTube link is saved as WordPress\'s own Embed block', strpos( $saved, '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=muERuPLVZTU","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->' ) !== false );
check( 'X link is saved as an Embed block, without tracking bits', strpos( $saved, '"url":"https://twitter.com/PIBFactCheck/status/2111340344063496466","type":"rich","providerNameSlug":"twitter"' ) !== false );
check( 'pasted HTML (WhatsApp channel box) is saved as a Custom HTML block', strpos( $saved, "<!-- wp:html -->\n<p class=\"has-black-color has-background\" style=\"background-color:#b6babf91\"><strong><a href=\"https://www.whatsapp.com/channel/0029Va5pj8o4IBh5WxNhlE0I\">" ) !== false );
check( 'pasted HTML is cleaned of scripts and event handlers', strpos( $saved, "<!-- wp:html -->\n<p>Hi</p>\n<!-- /wp:html -->" ) !== false && strpos( $saved, 'alert(2)' ) === false && strpos( $saved, 'onclick' ) === false );
check( 'an Instagram Embed block becomes a working player', strpos( $saved, 'instagram.com/p/ABC123/embed/captioned/' ) !== false && strpos( $saved, 'providerNameSlug":"instagram' ) === false );
check( 'embed code from other sites stays as text', strpos( $saved, 'evil.example.com' ) !== false && strpos( $saved, '<iframe src="https://evil' ) === false );
check( 'Google redirect links are opened up', strpos( $saved, 'src="https://archive.org/embed/zzz"' ) !== false );
check( 'pictures from other sites are not shown', strpos( $saved, '<img src="https://example.com' ) === false );
check( 'a javascript: link is never embedded', strpos( $saved, 'archive.org/embed/q' ) === false );
check( 'a Facebook page (not a post) stays a link', strpos( $saved, 'facebook.com%2FSomePage' ) === false );
check( 'page shows the players', strpos( $html, 'src="https://archive.org/embed/fb-post-081026"' ) !== false );
check( 'saving again changes nothing', ( wp_update_post( [ 'ID' => $pid ] ) && get_post_field( 'post_content', $pid ) === $saved ) );

// The plugin removed: the article still has its players and pictures.
remove_filter( 'the_content', 'fc_embed_content', 7 );
foreach ( [ 'archive', 'awesome', 'instagram', 'facebook', 'vimeo', 'image' ] as $h ) wp_embed_unregister_handler( 'fc_' . $h, 9 );
$html = apply_filters( 'the_content', $saved );
check( 'without the plugin, the players and pictures are still there', strpos( $html, 'archive.org/embed/fb-post-081026' ) !== false && strpos( $html, 'instagram.com/reel/DeRV_RoyoIZ/embed' ) !== false && strpos( $html, 'image-92.png' ) !== false && strpos( $html, 'Subscribe to our WhatsApp Channel' ) !== false );
add_filter( 'the_content', 'fc_embed_content', 7 );
fc_embed_register_handlers();

// An editor without the "unfiltered HTML" right saves the post later.
$author = wp_insert_user( [ 'user_login' => 'writer' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
wp_update_post( [ 'ID' => $pid, 'post_author' => $author ] );
wp_set_current_user( $author );
kses_init();
wp_update_post( wp_slash( [ 'ID' => $pid, 'post_content' => $saved . "<!-- wp:html -->\n<iframe src=\"https://evil.example.com/y\"></iframe>\n<!-- /wp:html -->" ] ) );
$after = get_post_field( 'post_content', $pid );
check( 'players survive a save by an editor without raw-HTML rights', substr_count( $after, '<iframe src=' ) === substr_count( $saved, '<iframe src=' ) && strpos( $after, 'fc-embed --' ) === false );
check( '…while other iframes are still removed for them', strpos( $after, 'evil.example.com/y' ) === false );
wp_set_current_user( 1 );
kses_remove_filters();

// Hostile input from an author without raw-HTML rights.
$evil = base64_encode( wp_json_encode( [ 'video', true, 640, 360, 'https://evil.example.com/steal' ] ) );
$ok   = base64_encode( wp_json_encode( [ 'video', true, 640, 360, 'https://player.vimeo.com/video/123' ] ) );
$bad  = base64_encode( wp_json_encode( [ 'video"onload="alert(1)', true, 640, 360, 'https://player.vimeo.com/video/123"onload="alert(1)' ] ) );
wp_update_post( [ 'ID' => $pid, 'post_author' => $author ] );
wp_set_current_user( $author );
kses_init();
$hostile = "<!-- wp:html -->\n<!-- fc-embed $evil -->\n<!-- fc-embed $ok -->\n<!-- fc-embed $bad -->\n<!-- /wp:html -->"
    . para( '<a href="https://archive.org/details/x&quot; onload=&quot;alert(1)">https://archive.org/details/x" onload="alert(1)</a>' )
    . para( 'https://archive.org/details/y"><img src=x onerror=alert(1)>' )
    . para( '&lt;iframe src="https://www.facebook.com/plugins/video.php?href=x&quot; onload=&quot;alert(1)"&gt;&lt;/iframe&gt;' );
wp_update_post( wp_slash( [ 'ID' => $pid, 'post_content' => $hostile ] ) );
$got = get_post_field( 'post_content', $pid );
check( 'a forged player marker pointing at another site is dropped', strpos( $got, 'evil.example.com' ) === false );
check( 'a forged marker for a trusted site makes only the plugin\'s fixed player markup', substr_count( $got, '<iframe src="https://player.vimeo.com/video/123" ' ) === 1 && strpos( $got, 'video/123onload' ) === false && strpos( $got, 'fc-embed--videoonload' ) === false );
$dom = new DOMDocument(); @$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $got . '</body>' );
$handlers = 0;
foreach ( $dom->getElementsByTagName( '*' ) as $node ) foreach ( $node->attributes as $attr ) if ( strpos( strtolower( $attr->name ), 'on' ) === 0 ) $handlers++;
check( 'hostile addresses never create an event handler or script in the article', $handlers === 0 && $dom->getElementsByTagName( 'script' )->length === 0 );
wp_set_current_user( 1 );
kses_remove_filters();

$plain = make_post( [], $doc );
check( 'works on any post, not only fact checks', strpos( get_post_field( 'post_content', $plain ), 'archive.org/embed/fb-post-081026' ) !== false );

// An older article: published before this arrived, then edited.
update_option( 'fc_auto_embeds', '0' );
$old = make_post( $base, $doc );
update_option( 'fc_auto_embeds', '1' );
update_option( 'fc_embeds_since', time() + 3600 );
wp_update_post( [ 'ID' => $old, 'post_title' => 'Edited later' ] );
$html = render( $old );
check( 'articles published before this feature are not changed, even when edited', get_post_field( 'post_content', $old ) === $doc && strpos( $html, 'archive.org/embed' ) === false );
delete_option( 'fc_embeds_since' );

update_option( 'fc_auto_embeds', '0' );
check( 'can be switched off', strpos( get_post_field( 'post_content', make_post( $base, $doc ) ), 'archive.org/embed' ) === false );
delete_option( 'fc_auto_embeds' );

update_option( 'fc_embed_notes', "Embed\nएम्बेड करा" );
$mr = make_post( [], para( 'एम्बेड करा' ) . para( 'https://archive.org/details/abc' ) . para( 'Side by side' ) . para( 'https://archive.org/details/def' ) );
$mr = get_post_field( 'post_content', $mr );
update_option( 'fc_embed_notes', fc_embed_previous_default_notes() );
$ar = get_post_field( 'post_content', make_post( [], para( 'Archive Link' ) . para( 'https://archive.org/details/abc' ) . para( '<strong>Archive:</strong>' ) . para( 'https://archive.org/details/def' ) . para( 'Facebook | Archive' ) . para( 'Fact Check-' ) ) );
check( '"Archive" and "Archive Link" notes above an embed are deleted (also for a list saved before they were added)', strpos( $ar, 'Archive Link' ) === false && strpos( $ar, '>Archive:' ) === false && strpos( $ar, 'Facebook | Archive' ) !== false && strpos( $ar, 'Fact Check-' ) !== false );
delete_option( 'fc_embed_notes' );
check( 'the note list can be changed in Settings, any language', strpos( $mr, 'एम्बेड' ) === false && strpos( $mr, 'Side by side' ) !== false );
delete_option( 'fc_embed_notes' );

// If anything goes wrong while converting, the article is saved as written and nothing is printed.
add_filter( 'fc_embed_notes', function () { echo 'STRAY OUTPUT'; throw new Exception( 'boom' ); } );
ob_start();
$broken = make_post( [], para( 'Embed' ) . para( 'https://archive.org/details/abc' ) );
$printed = ob_get_clean();
remove_all_filters( 'fc_embed_notes' );
check( 'a failure while converting saves the article as written, and prints nothing', strpos( get_post_field( 'post_content', $broken ), '<p>Embed</p>' ) !== false && strpos( $printed, 'STRAY' ) === false );

check( 'a player takes the shape of the pasted code', strpos( fc_embed_convert( para( '&lt;iframe src="https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Freel%2F1%2F" width="267" height="591"&gt;&lt;/iframe&gt;' ) ), 'aspect-ratio:267/591' ) !== false );
fc_embed_size_hint( [ 426, 184 ] );
check( 'a player takes the shape of its video (wide recording: no empty bands)', strpos( fc_embed_frame( 'https://player.vimeo.com/video/1', 'video', 640, 360, true ), 'aspect-ratio:426/184' ) !== false );
fc_embed_size_hint( null );
check( 'a landscape Facebook video gets a landscape frame', strpos( fc_embed_html( 'https://www.facebook.com/x/videos/123/' ), 'aspect-ratio:500/281' ) !== false );
$side = get_post_field( 'post_content', make_post( [], para( '&lt;p&gt;Side by Side&lt;/p&gt;' ) . para( 'https://archive.org/details/abc' ) . para( '&lt;p&gt;Keep this real sentence&lt;/p&gt;' ) . para( 'https://archive.org/details/def' ) ) );
check( 'a note typed as code ("<p>Side by Side</p>") above an embed is deleted; other pasted text stays', strpos( $side, 'Side by Side' ) === false && strpos( $side, 'Keep this real sentence' ) !== false && substr_count( $side, 'fc-embed--archive' ) === 2 );
$maps = '<iframe src="https://www.google.com/maps/embed?pb=!1m16!1m11!1m3!1d3!2d39.95!3d21.49!2m2!1f0!2f90!3m2!1i1024!2i768!4f75!3m3!1m2!1s0x15c1ff006a8d8b2d%3A0x6afc273b467538e8!4v1793110681783" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy"></iframe>';
$tik  = '<iframe height="578" width="325" src="https://www.tiktok.com/player/v1/7544423956279856392?embed_from=webapp_share&music_info=1&description=1&autoplay=1&muted=1" frameborder="0" allow="autoplay" title="x" allowfullscreen loading="lazy"></iframe>';
$real = get_post_field( 'post_content', make_post( [], para( esc_html( $maps ) ) . para( esc_html( $tik ) ) . para( esc_html( '<iframe src="https://www.google.com/maps/embed?pb=!1m1&quot; onload=&quot;alert(1)" width="1"></iframe>' ) ) . para( esc_html( '<iframe src="https://evil.example.com/maps/embed?pb=!1m1"></iframe>' ) ) ) );
check( 'Google Maps embed code (as authors paste it) becomes a map player', strpos( $real, 'class="fc-embed fc-embed--map"' ) !== false && strpos( $real, 'google.com/maps/embed?pb=!1m16!1m11' ) !== false && strpos( $real, 'aspect-ratio:600/450' ) !== false );
check( 'TikTok embed code becomes a tall player, without autoplay', strpos( $real, 'fc-embed--tiktok' ) !== false && strpos( $real, 'player/v1/7544423956279856392?music_info=1&#038;description=1' ) !== false && strpos( $real, 'autoplay=1' ) === false && strpos( $real, 'max-width:325px' ) !== false );
check( 'extra attributes in a pasted map code are dropped, and a foreign address stays text', substr_count( $real, 'fc-embed--map' ) === 2 && substr_count( $real, '<iframe ' ) === 3 && strpos( $real, '<iframe src="https://evil' ) === false && preg_match( '#<iframe[^>]*\\sonload#i', $real ) === 0 && strpos( $real, '&lt;iframe src=&quot;https://evil.example.com' ) !== false );
// Pictures stuck in a paragraph next to text (the Sri Lanka Tamil case).
$tamil = 'மேலும் நாம் குறித்த இரு காணொளிகளை &amp; "ஆய்வு" செய்தபோது';
$stuck = get_post_field( 'post_content', make_post( [], para( $tamil . '<br><img src="https://example.com/wp-content/uploads/tiktok.png" alt="Shop &amp; crowd" width="574" height="823" class="wp-image-77"><br><a href="https://www.tiktok.com/@x/video/1">Tiktok Link</a>' ) ) );
check( 'a picture stuck in a paragraph gets its own block, between the text before and after', strpos( $stuck, "<!-- wp:paragraph -->\n<p>" . $tamil . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:image {\"id\":77} -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/wp-content/uploads/tiktok.png\" alt=\"Shop &amp; crowd\" class=\"wp-image-77\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:paragraph -->\n<p><a href=\"https://www.tiktok.com/@x/video/1\">Tiktok Link</a></p>" ) !== false, $stuck );
check( 'the text is kept character for character (Tamil, &amp;, quotes, the link)', fc_embed_words( $stuck ) === fc_embed_words( para( $tamil . ' Tiktok Link' ) ) );
$two = get_post_field( 'post_content', make_post( [], para( 'Before <img src="https://example.com/a.png"> middle <img src="https://example.com/b.png"> after' ) ) );
check( 'two pictures with text between: text, picture, text, picture, text', substr_count( $two, '<!-- wp:image' ) === 2 && substr_count( $two, '<!-- wp:paragraph' ) === 3 && strpos( $two, '<p>middle</p>' ) !== false );
$keep = [
    'a small icon inside a sentence'   => 'I like <img src="https://example.com/i.png" width="20"> this',
    'a picture inside a link'          => 'See <a href="https://example.com"><img src="https://example.com/a.png"></a> here',
    'a picture inside bold text'       => '<strong>Bold <img src="https://example.com/a.png"></strong> text',
    'a picture written into the page'  => 'Text <img src="data:image/png;base64,iVBORw0KGgo="> more',
];
foreach ( $keep as $label => $html ) {
    $got = get_post_field( 'post_content', make_post( [], para( $html ) ) );
    check( "left exactly as it was: $label", strpos( $got, '<p>' . $html . '</p>' ) !== false && strpos( $got, 'wp:image' ) === false );
}
$aligned = "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Centered <img src=\"https://example.com/a.png\"> text</p>\n<!-- /wp:paragraph -->\n";
check( 'left exactly as it was: a paragraph with its own alignment', get_post_field( 'post_content', make_post( [], $aligned ) ) === $aligned );
$only = get_post_field( 'post_content', make_post( [], para( '<img src="https://example.com/only.png" alt="">' ) ) );
check( 'a paragraph holding only a picture becomes just the picture', strpos( $only, '<!-- wp:image -->' ) !== false && strpos( $only, 'wp:paragraph' ) === false );

// Pictures side by side: ONLY a table of pictures or a "side by side" note does it.
function img_block( $n ) { return "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/p$n.png\" alt=\"\"/></figure>\n<!-- /wp:image -->\n"; }
function side( $note, $n ) { $h = para( $note ); for ( $i = 1; $i <= $n; $i++ ) $h .= img_block( $i ); return get_post_field( 'post_content', make_post( [], $h . para( 'Text after.' ) ) ); }
function layout( $html ) { preg_match_all( '#<!-- wp:columns -->(.*?)<!-- /wp:columns -->#s', $html, $rows ); return implode( ' | ', array_map( function ( $r ) { return substr_count( $r, '<!-- wp:image' ) . '/' . substr_count( $r, '<!-- wp:column -->' ); }, $rows[1] ) ); }

$ptable = "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td><img src=\"https://example.com/a.png\" width=\"250\"></td><td><img src=\"https://example.com/b.png\"><br></td></tr><tr><td><img src=\"https://example.com/c.png\"></td><td></td></tr></tbody></table></figure>\n<!-- /wp:table -->\n";
$tt = get_post_field( 'post_content', make_post( [], $ptable ) );
check( 'a table of pictures is copied row by row (2 pictures | 1 picture and an empty place)', strpos( $tt, 'wp:table' ) === false && layout( $tt ) === '2/2 | 1/2' && substr_count( $tt, '<!-- wp:image' ) === 3, layout( $tt ) );
$dtable = "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>Claim</td><td><img src=\"https://example.com/a.png\"></td></tr></tbody></table></figure>\n<!-- /wp:table -->\n";
check( 'a table with any words in it is left exactly as it is', get_post_field( 'post_content', make_post( [], $dtable ) ) === $dtable );

check( '"side by side" + 2 pictures → 2 columns, note gone', layout( $x = side( 'Side by side', 2 ) ) === '2/2' && stripos( $x, 'side by side' ) === false && strpos( $x, 'Text after.' ) !== false, layout( $x ) );
check( '"SIDE-BY-SIDE" + 3 pictures → 3 columns', layout( side( 'SIDE-BY-SIDE', 3 ) ) === '3/3' );
check( '"side by side" + 4 pictures → 2 rows of 2', layout( side( '[Images side_by_side]', 4 ) ) === '2/2 | 2/2' );
check( '"side by side" + 5 pictures → 2 + 2 + 1 (same size, empty place)', layout( $x = side( 'sidebyside pls', 5 ) ) === '2/2 | 2/2 | 1/2', layout( $x ) );
check( 'a "side by side" note written in bold or with odd spacing still counts', layout( side( '<strong>Side  By   Side :</strong>', 2 ) ) === '2/2' );
$stack = get_post_field( 'post_content', make_post( [], para( 'Some text.' ) . img_block( 1 ) . img_block( 2 ) . img_block( 3 ) ) );
check( 'stacked pictures with no note stay stacked (no columns)', strpos( $stack, 'wp:columns' ) === false && substr_count( $stack, '<!-- wp:image' ) === 3 );
$sentence = 'The viral image and the original are shown side by side below for comparison by our team.';
$sx = side( $sentence, 2 );
check( 'a real sentence saying "side by side" stays, and changes nothing (only a note does)', strpos( $sx, $sentence ) !== false && strpos( $sx, 'wp:columns' ) === false );
$x = get_post_field( 'post_content', make_post( [], para( 'Side by side' ) . img_block( 1 ) . para( '' ) . img_block( 2 ) ) );
check( 'an empty line between the pictures does not stop it', layout( $x ) === '2/2' );
check( 'a "side by side" note above a single picture makes no columns', strpos( side( 'Side by side', 1 ), 'wp:columns' ) === false );

$sl = para( '<strong>[PRODUCTION NOTE: These three near-identical posts could be merged.</strong>' )
    . para( '<a href="https://srilanka.factcrescendo.com/wp-content/uploads/2026/10/image-135-13.webp">https://srilanka.factcrescendo.com/wp-content/uploads/2026/10/image-135-13.webp</a>' )
    . para( '<a href="https://srilanka.factcrescendo.com/wp-content/uploads/2026/10/image-135-15.webp">https://srilanka.factcrescendo.com/wp-content/uploads/2026/10/image-135-15.webp</a>' )
    . para( 'Text after.' );
$slc = get_post_field( 'post_content', make_post( [], $sl ) );
check( 'a production note without "side by side": the note goes, the pictures stay stacked', stripos( $slc, 'production note' ) === false && strpos( $slc, 'wp:columns' ) === false && substr_count( $slc, '<!-- wp:image' ) === 2 && strpos( $slc, 'Text after.' ) !== false );
$pn = get_post_field( 'post_content', make_post( [], para( 'Intro.' ) . para( '[PRODUCTION NOTE: please check the photo credits]' ) . para( "Editor's note: this article was updated on 20 October." ) . para( 'Production notes are kept by the team.' ) ) );
check( '"[PRODUCTION NOTE: ..." is deleted wherever it stands alone', stripos( $pn, 'please check the photo credits' ) === false );
check( '…but "Editor\'s note" stays', strpos( $pn, 'Editor&#8217;s note' ) !== false || strpos( $pn, "Editor's note" ) !== false );
check( '…and a sentence that merely starts with the same words ("Production notes are kept…") stays', strpos( $pn, 'Production notes are kept by the team.' ) !== false && strpos( $pn, 'Intro.' ) !== false );

// Production notes, any spelling. Only the note goes.
$notes_in = [
    '<strong>[PRODUCTION NOTE: These three near-identical posts could be merged.</strong>' => '',
    'PRODUCTION NOTE - check the date'                                          => '',
    'Production Notes: use the second photo'                                    => '',
    'production-note: crop the image'                                           => '',
    '*Production note* swap the order'                                          => '',
    '(prod. note: second screenshot)'                                           => '',
    'Note to production: merge these'                                           => '',
    'PRODUCTION NOTE'                                                           => '',
    '[Production note: delete this] The claim went viral on Monday.'            => 'The claim went viral on Monday.',
    'Production notes are kept by the team.'                                    => 'Production notes are kept by the team.',
    'The production note was missing from the file.'                            => 'The production note was missing from the file.',
];
foreach ( $notes_in as $in => $want ) {
    $got = get_post_field( 'post_content', make_post( [], para( 'Intro.' ) . para( $in ) . para( 'End.' ) ) );
    $ok  = $want === '' ? stripos( $got, 'prod' ) === false && strpos( $got, 'Intro.' ) !== false && strpos( $got, 'End.' ) !== false
                        : strpos( $got, '<p>' . $want . '</p>' ) !== false && strpos( $got, 'Intro.' ) !== false;
    check( ( $want === '' ? 'production note removed: ' : 'kept: ' ) . wp_strip_all_tags( $in ), $ok, $got );
}
// The Settings box comes back from the browser with Windows line endings: still the default list.
update_option( 'fc_embed_notes', str_replace( "\n", "\r\n", preg_replace( '/\nproduction note\*$/', '', fc_embed_default_notes() ) ) );
check( 'a notes list saved from the browser (Windows line endings) still gets the new defaults', in_array( 'production note', fc_embed_note_prefixes(), true ) );
delete_option( 'fc_embed_notes' );

// After the first save, a layout made by hand is the editor's choice.
$hand = make_post( [], para( 'Text.' ) );
$by_hand = $ptable . para( 'Before <img src="https://example.com/inline.png"> after' );
wp_update_post( wp_slash( [ 'ID' => $hand, 'post_content' => $by_hand ] ) );
$after_hand = get_post_field( 'post_content', $hand );
check( 'later saves keep a table of pictures and an inline picture the editor made by hand', strpos( $after_hand, 'wp:table' ) !== false && strpos( $after_hand, 'Before <img' ) !== false && strpos( $after_hand, 'wp:columns' ) === false );
$fresh = get_post_field( 'post_content', make_post( [], $by_hand ) );
check( '…while the first save of a pasted article still tidies them', strpos( $fresh, 'wp:table' ) === false && strpos( $fresh, 'wp:columns' ) !== false && strpos( $fresh, 'Before <img' ) === false );

check( 'Awesome Screenshot: video link with key', strpos( fc_embed_html( 'https://www.awesomescreenshot.com/video/56526199?key=dc9dbe8652de8661e10d6fb0eeee75db' ), 'embed?id=56526199&#038;shareKey=dc9dbe8652de8661e10d6fb0eeee75db' ) !== false );
check( 'Awesome Screenshot: embed address on its own line', strpos( get_post_field( 'post_content', make_post( [], para( 'https://www.awesomescreenshot.com/embed?id=56443580&amp;shareKey=7c2f0e835e96f593fac8e1692295db68' ) ) ), 'embed?id=56443580&#038;shareKey=7c2f0e835e96f593fac8e1692295db68' ) !== false );
check( 'Awesome Screenshot: the editor\'s Embed block takes the embed address too', ( $GLOBALS['wp_embed']->get_embed_handler_html( [], 'https://www.awesomescreenshot.com/embed?id=56443580&shareKey=7c2f0e835e96f593fac8e1692295db68' ) ?: '' ) !== '' );
check( 'plugin list: Settings link first', strpos( implode( '', apply_filters( 'plugin_action_links_' . plugin_basename( FC_PUBLISHER_FILE ), [ '<a>Deactivate</a>' ] ) ), '>Settings</a>' ) !== false && array_key_first( apply_filters( 'plugin_action_links_' . plugin_basename( FC_PUBLISHER_FILE ), [ 'deactivate' => '<a>Deactivate</a>' ] ) ) === 'settings' );
check( 'players have one standard line of space above and below', strpos( fc_embed_html( 'https://archive.org/details/abc' ), 'margin:1.5em auto' ) !== false );
check( 'players saved with the old tight spacing are still recognised as ours', preg_match( fc_embed_frame_pattern(), '<div class="fc-embed fc-embed--archive" style="max-width:100%;margin:0 auto;"><div style="position:relative;width:100%;aspect-ratio:640/480;"><iframe src="https://archive.org/embed/a"></iframe></div></div>' ) === 1 && preg_match( fc_embed_frame_pattern(), fc_embed_html( 'https://archive.org/details/abc' ) ) === 1 );
$frame = fc_embed_html( 'https://www.instagram.com/p/"><script>/' );
check( 'odd addresses cannot break out of the player', strpos( $frame, '<script' ) === false );
check( 'Embed block in the editor uses the same rules', ( $GLOBALS['wp_embed']->get_embed_handler_html( [], 'https://archive.org/details/abc' ) ?: '' ) !== '' );
check( 'Vimeo owner link is read as the public address', fc_embed_vimeo_url( 'https://vimeo.com/manage/videos/1237155433' ) === 'https://vimeo.com/1237155433' && fc_embed_src_to_url( 'https://player.vimeo.com/video/55?h=ab12' ) === 'https://vimeo.com/55/ab12' );

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


// ── Hide the featured image on article pages ─────────────────────────────────
section( 'Hide the featured image on article pages' );
{
    $att = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'x', 'post_status' => 'inherit' ], 'x.png' );
    $hp  = make_post();
    $op  = make_post();
    set_post_thumbnail( $hp, $att );
    set_post_thumbnail( $op, $att );
    $on_article = function ( $id ) {
        global $wp_query, $wp_the_query;
        $wp_query = new WP_Query( [ 'p' => $id ] ); $wp_the_query = $wp_query;
        $wp_query->the_post();
        $html = get_the_post_thumbnail( $id );
        wp_reset_postdata();
        return $html;
    };
    update_option( 'fc_hide_featured', '1' );
    check( 'hidden on the post\'s own article page', $on_article( $hp ) === '' );
    global $wp_query, $wp_the_query;
    $wp_query = new WP_Query( [ 'p' => $hp ] ); $wp_the_query = $wp_query; $wp_query->the_post();
    check( 'another post\'s picture (a related list) is kept', fc_hide_featured_on_article( '<img>', $op ) === '<img>' );
    wp_reset_postdata();
    $wp_query = new WP_Query( [ 'post_type' => 'post' ] ); $wp_the_query = $wp_query;   // a list page, not an article
    check( 'outside an article page nothing is hidden', fc_hide_featured_on_article( '<img>', $hp ) === '<img>' );
    update_option( 'fc_hide_featured', '0' );
    check( 'switched off: shown again', $on_article( $hp ) !== '' );
    delete_option( 'fc_hide_featured' );
    check( 'default is on', $on_article( $hp ) === '' );
}


// ── Embed code that lost its "?" ─────────────────────────────────────────────
section( 'Embed code that lost its "?"' );
{
    $good = 'https://www.facebook.com/plugins/video.php?height=476&href=https%3A%2F%2Fwww.facebook.com%2Freel%2F1145138658205462%2F&show_text=false&width=267&t=0';
    $tail = '&lt;/iframe&gt;';
    $mk = function ( $mid ) use ( $tail ) {
        return para( '&lt;iframe src="https://www.facebook.com/plugins/video.php' . $mid . 'height=476&amp;href=https%3A%2F%2Fwww.facebook.com%2Freel%2F1145138658205462%2F&amp;show_text=false&amp;width=267&amp;t=0" width="267" height="476"&gt;' . $tail );
    };
    foreach ( [ 'a line break' => '<br>', 'a space' => ' ', 'nothing' => '', 'a control character' => "\x0B", 'a full-width ?' => "\u{FF1F}" ] as $what => $mid ) {
        $out = fc_embed_convert( $mk( $mid ) );
        check( "the ? is put back when $what stands in its place", strpos( $out, 'fc-embed--facebook' ) !== false && strpos( $out, 'plugins/video.php?href=' ) !== false && strpos( $out, 'aspect-ratio:267/476' ) !== false && strpos( $out, '&lt;iframe' ) === false, substr( $out, 0, 300 ) );
    }
    check( 'a code that is already right is left as it was', fc_embed_repair_src( $good ) === $good );
    check( 'an address that only looks similar is not touched', fc_embed_repair_src( 'https://evil.example.com/plugins/video.phpheight=1' ) === 'https://evil.example.com/plugins/video.phpheight=1' );
    check( 'Awesome Screenshot and Google Maps codes are mended too', fc_embed_repair_src( 'https://www.awesomescreenshot.com/embedid=1&shareKey=2' ) === 'https://www.awesomescreenshot.com/embed?id=1&shareKey=2' && fc_embed_repair_src( 'https://www.google.com/maps/embedpb=!1m1' ) === 'https://www.google.com/maps/embed?pb=!1m1' );
}


// ─────────────────────────────────────────────────────────────────────────────
echo "\n{$GLOBALS['fc_passes']} passed, {$GLOBALS['fc_failures']} failed\n";
exit( $GLOBALS['fc_failures'] ? 1 : 0 );
