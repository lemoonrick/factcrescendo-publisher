<?php
/**
 * REST API
 *
 * GET /wp-json/fc/v1/post/<id>  — single post, all fact-check + audio data
 * GET /wp-json/fc/v1/posts       — paginated list of fact-check posts
 *
 * Both are read-only. The fields also appear in the standard
 * /wp-json/wp/v2/posts/<id> response under meta{}; that registration, and
 * who may write to them, lives in fields.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Single post endpoint ─────────────────────────────────────────────────────

add_action( 'rest_api_init', 'fc_register_custom_endpoint' );

function fc_register_custom_endpoint() {
    register_rest_route( 'fc/v1', '/post/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'fc_rest_get_post',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'validate_callback' => fn( $p ) => is_numeric( $p ),
            ],
        ],
    ] );
}

function fc_rest_get_post( $request ) {
    $post_id = (int) $request['id'];
    $post    = get_post( $post_id );

    // Published, public posts only. Password-protected ones stay private.
    if ( ! $post || $post->post_type !== 'post' || $post->post_status !== 'publish' || post_password_required( $post ) ) {
        return new WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
    }

    $rating = fc_meta( 'fc_rating', $post_id );

    return rest_ensure_response( [
        'id'            => $post_id,
        'title'         => get_the_title( $post_id ),
        'url'           => get_permalink( $post_id ),
        'is_fact_check' => fc_is_fact_check( $post_id ),
        'rating'        => $rating,
        'rating_label'  => fc_get_rating_label( $rating ),
        'claim'         => fc_meta( 'fc_claim', $post_id ),
        'fact'          => fc_plain_fact( fc_meta( 'fc_fact', $post_id ) ),
        'stamp_url'     => fc_get_stamp_url( $rating ),
        'audio_url'     => get_post_meta( $post_id, 'fc_audio_url', true ) ?: null,
        'audio_status'  => get_post_meta( $post_id, 'fc_audio_status', true ) ?: 'none',
    ] );
}


// ── Bulk / collection endpoint ───────────────────────────────────────────────

add_action( 'rest_api_init', 'fc_register_bulk_endpoint' );

function fc_register_bulk_endpoint() {
    register_rest_route( 'fc/v1', '/posts', [
        'methods'             => 'GET',
        'callback'            => 'fc_rest_get_posts',
        'permission_callback' => '__return_true',
        'args'                => [
            'page' => [
                'validate_callback' => fn( $p ) => is_numeric( $p ) && $p > 0,
            ],
        ],
    ] );
}

function fc_rest_get_posts( $request ) {
    $page = $request->get_param( 'page' ) ? min( 500, max( 1, (int) $request->get_param( 'page' ) ) ) : 1;

    // Cached for two minutes so repeated calls don't hit the database.
    $cache_key = 'fc_api_posts_' . $page;
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) return fc_rest_cacheable( $cached );

    $query = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'has_password'   => false,
        'posts_per_page' => 10,
        'paged'          => $page,
        'meta_query'     => [
            [
                'key'     => 'fc_is_fact_check',
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ] );

    $data = [];
    foreach ( $query->posts as $post ) {
        $rating = fc_meta( 'fc_rating', $post->ID );
        $data[] = [
            'id'           => $post->ID,
            'title'        => get_the_title( $post->ID ),
            'url'          => get_permalink( $post->ID ),
            'rating'       => $rating,
            'rating_label' => fc_get_rating_label( $rating ),
            'claim'        => fc_meta( 'fc_claim', $post->ID ),
            'fact'         => fc_plain_fact( fc_meta( 'fc_fact', $post->ID ) ),
            'stamp_url'    => fc_get_stamp_url( $rating ),
            'audio_url'    => get_post_meta( $post->ID, 'fc_audio_url', true ) ?: null,
        ];
    }

    $result = [
        'page'        => $page,
        'total_pages' => (int) $query->max_num_pages,
        'total_posts' => (int) $query->found_posts,
        'posts'       => $data,
    ];
    set_transient( $cache_key, $result, 2 * MINUTE_IN_SECONDS );
    return fc_rest_cacheable( $result );
}

/** A response that browsers and CDNs may keep for two minutes. */
function fc_rest_cacheable( $data ) {
    $response = rest_ensure_response( $data );
    $response->header( 'Cache-Control', 'public, max-age=120' );
    return $response;
}
