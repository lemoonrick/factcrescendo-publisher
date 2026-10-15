<?php
/**
 * Automated checks, run inside a throwaway WordPress by tests/run.sh.
 *
 * Each check builds what it needs, looks at the real output WordPress
 * produces, and prints PASS or FAIL. Exits non-zero if anything fails.
 */

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

function reset_options() {
    delete_option( 'fc_site_key' );
    delete_option( 'fc_show_whatsapp_banner' );
    delete_option( 'fc_whatsapp_banner_html' );
    delete_option( 'fc_enable_audio' );
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
check( 'still get their fact card', strpos( $html, 'Old ACF claim' ) !== false && strpos( $html, 'fc-hero' ) !== false );
check( 'still get their author box', strpos( $html, 'fc-article-card' ) !== false );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Articles' );

$plain = make_post( [], '<p>Ordinary news.</p>' );
check( 'normal post is left untouched', trim( render( $plain ) ) === '<p>Ordinary news.</p>' );

$no_rating = make_post( [ 'fc_is_fact_check' => '1', 'fc_claim' => 'x' ] );
check( 'fact check without a rating adds nothing', strpos( render( $no_rating ), 'fc-hero' ) === false );

$pid   = make_post( array_merge( $base, [ 'fc_claim' => '<script>alert("x")</script> claim' ] ) );
$html = render( $pid );
check( 'claim text cannot inject a script', strpos( $html, '<script>alert' ) === false );


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
    check( "entry $key is complete", ! empty( $p['whatsapp_url'] ) && strpos( $p['banner_image'], 'https://' ) === 0 && ! empty( $p['label'] ) );
}


// ─────────────────────────────────────────────────────────────────────────────
section( 'WhatsApp banner' );

reset_options();
$pid = make_post( $base );
check( 'site not in the list: no banner', strpos( render( $pid ), 'fc-whatsapp-banner' ) === false );

update_option( 'fc_site_key', 'malayalam.factcrescendo.com' );
$html = render( $pid );
check( 'listed site: banner added', strpos( $html, 'Malayalam-2.png' ) !== false );
check( 'banner links to WhatsApp in a new tab', strpos( $html, 'api.whatsapp.com/send?phone=919049053770' ) !== false && strpos( $html, 'noopener' ) !== false );
check( 'banner image has alt text', strpos( $html, 'alt="Send us a message on WhatsApp"' ) !== false );

$pasted = make_post( $base, '<p>Body</p><figure><a href="https://api.whatsapp.com/send?phone=919049053770"><img src="x.png"></a></figure>' );
check( 'article with a pasted banner gets no second one', strpos( render( $pasted ), 'fc-whatsapp-banner' ) === false );

update_option( 'fc_show_whatsapp_banner', '0' );
check( 'switched off in settings: no banner', strpos( render( $pid ), 'fc-whatsapp-banner' ) === false );

update_option( 'fc_show_whatsapp_banner', '1' );
update_option( 'fc_whatsapp_banner_html', '<p class="custom-banner">Custom</p>' );
$html = render( $pid );
check( 'banner pasted in settings still wins', strpos( $html, 'custom-banner' ) !== false, substr( $html, -300 ) );

$saved = fc_sanitize_banner_html( '<p onclick="alert(1)">Hi</p><script>alert(1)</script>' );
check( 'pasted banner is cleaned of scripts', strpos( $saved, 'script' ) === false && strpos( $saved, 'onclick' ) === false );


// ─────────────────────────────────────────────────────────────────────────────
section( 'Audio player' );

reset_options();
update_option( 'fc_site_key', 'english.factcrescendo.com' );
check( 'English site: player shown', strpos( render( $pid ), 'fc-a-wrap' ) !== false );

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
check( 'single post endpoint works', $res->get_status() === 200 && ( $data['claim'] ?? '' ) === $base['fc_claim'] && $data['is_fact_check'] === true, wp_json_encode( $data ) );

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
echo "\n{$GLOBALS['fc_passes']} passed, {$GLOBALS['fc_failures']} failed\n";
exit( $GLOBALS['fc_failures'] ? 1 : 0 );
