<?php
/**
 * REST API
 *
 * 1. Registers meta fields so they appear in the standard WP REST API
 *    response under /wp-json/wp/v2/posts/<id> → meta{}
 *    (ACF Free fallback — ACF Pro exposes these natively)
 *
 * 2. Adds a clean combined endpoint:
 *    GET /wp-json/fc/v1/post/<id>
 *    Returns all fact-check fields + stamp URL in one call.
 *    Useful for future dashboards or Google Docs publisher.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Expose fields in standard WP REST API ───────────────────────────────────

add_action( 'init', 'fc_register_rest_meta' );

function fc_register_rest_meta() {
    $fields = [
        'fc_is_fact_check' => 'boolean',
        'fc_rating'        => 'string',
        'fc_claim'         => 'string',
        'fc_fact'          => 'string',
    ];

    foreach ( $fields as $key => $type ) {
        register_post_meta( 'post', $key, [
            'type'          => $type,
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ] );
    }
}


// ── Custom combined endpoint ─────────────────────────────────────────────────

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

    if ( ! $post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) {
        return new WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
    }

    $rating = get_field( 'fc_rating', $post_id );

    return rest_ensure_response( [
        'id'            => $post_id,
        'title'         => get_the_title( $post_id ),
        'url'           => get_permalink( $post_id ),
        'is_fact_check' => (bool) get_field( 'fc_is_fact_check', $post_id ),
        'rating'        => $rating,
        'rating_label'  => fc_get_rating_label( $rating ),
        'claim'         => get_field( 'fc_claim', $post_id ),
        'fact'          => get_field( 'fc_fact', $post_id ),
        'stamp_url'     => fc_get_stamp_url( $rating ),
    ] );
}

// ── Custom Bulk Endpoint (Collection) ────────────────────────────────────────

add_action( 'rest_api_init', 'fc_register_bulk_endpoint' );

function fc_register_bulk_endpoint() {
    register_rest_route( 'fc/v1', '/posts', [
        'methods'             => 'GET',
        'callback'            => 'fc_rest_get_posts',
        'permission_callback' => '__return_true',
    ] );
}

function fc_rest_get_posts( $request ) {
    // Optional: Allow the app to pass a page number like ?page=2
    $page = $request->get_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1;

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 10, // How many to load at once
        'paged'          => $page,
        // Only fetch posts where the "Mark as Fact Check" toggle is ON
        'meta_query'     => [
            [
                'key'     => 'fc_is_fact_check',
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ];

    $query = new WP_Query( $args );
    $data  = [];

    foreach ( $query->posts as $post ) {
        $rating = get_field( 'fc_rating', $post->ID );
        
        $data[] = [
            'id'            => $post->ID,
            'title'         => get_the_title( $post->ID ),
            'url'           => get_permalink( $post->ID ),
            'rating'        => $rating,
            'rating_label'  => fc_get_rating_label( $rating ),
            'claim'         => get_field( 'fc_claim', $post->ID ),
            'fact'          => get_field( 'fc_fact', $post->ID ),
            'stamp_url'     => fc_get_stamp_url( $rating ),
        ];
    }

    return rest_ensure_response( $data );
}