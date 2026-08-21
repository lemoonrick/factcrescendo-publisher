<?php
/**
 * REST API
 *
 * 1. Registers meta fields so they appear in the standard WP REST API
 *    response under /wp-json/wp/v2/posts/<id> → meta{}
 * 2. GET /wp-json/fc/v1/post/<id>      — single post, all fact-check + audio data
 * 3. GET /wp-json/fc/v1/posts          — paginated list of fact-check posts
 *
 * All endpoints are read-only. Writing to any of these meta keys via the
 * REST API requires edit_posts capability (see auth_callback below) —
 * there is no public write path anywhere in this file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Expose fields in standard WP REST API ───────────────────────────────────

add_action( 'init', 'fc_register_rest_meta' );

function fc_register_rest_meta() {
    $fields = [
        'fc_is_fact_check'  => 'boolean',
        'fc_rating'         => 'string',
        'fc_claim'          => 'string',
        'fc_fact'           => 'string',
        'fc_audio_url'      => 'string',
        'fc_audio_status'   => 'string',
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
    $page = $request->get_param( 'page' ) ? max( 1, (int) $request->get_param( 'page' ) ) : 1;

    $query = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
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
        $rating = get_field( 'fc_rating', $post->ID );
        $data[] = [
            'id'           => $post->ID,
            'title'        => get_the_title( $post->ID ),
            'url'          => get_permalink( $post->ID ),
            'rating'       => $rating,
            'rating_label' => fc_get_rating_label( $rating ),
            'claim'        => get_field( 'fc_claim', $post->ID ),
            'fact'         => get_field( 'fc_fact', $post->ID ),
            'stamp_url'    => fc_get_stamp_url( $rating ),
            'audio_url'    => get_post_meta( $post->ID, 'fc_audio_url', true ) ?: null,
        ];
    }

    return rest_ensure_response( [
        'page'        => $page,
        'total_pages' => (int) $query->max_num_pages,
        'total_posts' => (int) $query->found_posts,
        'posts'       => $data,
    ] );
}
