<?php
/**
 * Injects Google ClaimReview Schema into the <head> of fact checks.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_head', 'fc_inject_claim_review_schema' );

function fc_inject_claim_review_schema() {
    if ( ! is_singular( 'post' ) ) return;
    
    $post_id = get_the_ID();
    if ( ! function_exists( 'get_field' ) ) return;
    
    $is_fc = (bool) get_field( 'fc_is_fact_check', $post_id );
    if ( ! $is_fc ) return;

    $rating = get_field( 'fc_rating', $post_id );
    if ( empty( $rating ) ) return;

    $claim        = get_field( 'fc_claim', $post_id );
    $rating_label = fc_get_rating_label( $rating );
    $url          = get_permalink( $post_id );
    $org_name     = get_bloginfo( 'name' );

    // Schema requires a numerical rating. We map your text ratings to a 1-5 scale.
    $rating_value = 1; // Default to worst (False)
    if ( in_array( $rating, ['news', 'insight'] ) ) {
        $rating_value = 5; // True / Accurate
    } elseif ( in_array( $rating, ['partly-false', 'misleading', 'missing-context'] ) ) {
        $rating_value = 2; // Mostly False / Misleading
    }

    $schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'ClaimReview',
        'datePublished' => get_the_date( 'Y-m-d', $post_id ),
        'url'           => $url,
        'claimReviewed' => $claim ? $claim : get_the_title( $post_id ),
        'itemReviewed'  => [
            '@type'  => 'CreativeWork',
            'author' => [
                '@type' => 'Organization',
                'name'  => $org_name,
                'sameAs'=> home_url(),
            ],
        ],
        'author' => [
            '@type' => 'Organization',
            'name'  => 'Fact Crescendo',
            'url'   => 'https://factcrescendo.com',
        ],
        'reviewRating' => [
            '@type'         => 'Rating',
            'ratingValue'   => $rating_value,
            'bestRating'    => 5,
            'worstRating'   => 1,
            'alternateName' => $rating_label,
        ],
    ];

    echo "\n\n";
    echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}