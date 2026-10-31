<?php
/**
 * Google's fact-check data (ClaimReview) in the page head, following Google's
 * published spec. Read by Fact Check Explorer, search engines and AI systems.
 * One per page: a second one makes Google ignore both.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_head', 'fc_inject_claim_review_schema', 5 );

function fc_inject_claim_review_schema() {

    // Google allows only one ClaimReview per page. Never emit twice.
    static $already_emitted = false;
    if ( $already_emitted ) return;

    if ( ! is_singular( 'post' ) ) return;

    $post_id = get_the_ID();
    if ( ! $post_id ) return;

    // Not for pages hidden from search (Yoast "noindex").
    $noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
    if ( $noindex === '1' ) return;

    if ( ! fc_is_fact_check( $post_id ) ) return;
    if ( post_password_required( $post_id ) ) return; // locked: keep the verdict private

    $rating = fc_meta( 'fc_rating', $post_id );
    if ( empty( $rating ) ) return;

    if ( ! fc_claimreview_for_rating( $rating ) ) return;

    $claim        = trim( (string) fc_meta( 'fc_claim', $post_id ) );
    $rating_label = fc_get_rating_label( $rating );
    $rating_value = fc_get_schema_rating_value( $rating );
    $permalink    = get_permalink( $post_id );

    // The whole claim, not cut short (the old 75-character advice was for a retired search box).
    $claim_reviewed = $claim !== '' ? $claim : get_the_title( $post_id );
    $claim_reviewed = fc_trim_plain( $claim_reviewed, 300 );

    // Google allows 100 characters for the publisher's name.
    $site_name = fc_trim_plain( get_bloginfo( 'name' ), 100 );

    $date_published = get_the_date( 'c', $post_id ); // ISO 8601 with offset
    $date_modified  = get_the_modified_date( 'c', $post_id );

    // The claim. 'appearance' (where it was first posted) and 'author' (who made it)
    // only when the editor filled them in.
    $item_reviewed = [
        '@type'         => 'Claim',
        'datePublished' => $date_published,
    ];

    $source_url = trim( (string) fc_meta( 'fc_claim_source_url', $post_id ) );
    if ( $source_url !== '' && wp_http_validate_url( $source_url ) ) {
        $item_reviewed['appearance'] = [
            '@type' => 'CreativeWork',
            'url'   => esc_url_raw( $source_url ),
        ];
    }

    $claim_author = fc_trim_plain( (string) fc_meta( 'fc_claim_author', $post_id ), 100 );
    if ( $claim_author !== '' ) {
        $author_type = fc_meta( 'fc_claim_author_type', $post_id );
        $item_reviewed['author'] = [
            '@type' => $author_type === 'Organization' ? 'Organization' : 'Person',
            'name'  => $claim_author,
        ];
    }

    $schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'ClaimReview',
        'url'           => $permalink,
        'datePublished' => $date_published,
        'dateModified'  => $date_modified,
        'claimReviewed' => $claim_reviewed,
        'itemReviewed'  => $item_reviewed,

        // Who published the fact check (this site), not who made the claim.
        'author' => [
            '@type' => 'Organization',
            'name'  => $site_name,
            'url'   => home_url(),
        ],

        // Google's 1–5 scale; the verdict text is what people see.
        'reviewRating' => [
            '@type'         => 'Rating',
            'ratingValue'   => $rating_value,
            'bestRating'    => 5,
            'worstRating'   => 1,
            'alternateName' => $rating_label,
        ],
    ];

    /** Filter: change the data before it is printed. */
    $schema = apply_filters( 'fc_claimreview_schema', $schema, $post_id );

    $already_emitted = true;

    // JSON_HEX_TAG and escaped slashes: no value (even one added by the filter) can close the
    // <script> tag. Unicode stays readable.
    echo "\n" . '<script type="application/ld+json">'
        . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
        . '</script>' . "\n";
}

/** False for Insight and News: they explain or report, they don't rate a claim. */
function fc_claimreview_for_rating( $rating ) {
    /** Filter: ratings that never get fact-check data. */
    $skip = (array) apply_filters( 'fc_claimreview_skip_ratings', [ 'insight', 'news' ] );
    return ! in_array( strtolower( (string) $rating ), $skip, true );
}

/** Our rating on Google's 1–5 scale (1 = False … 5 = True). The verdict text carries the real meaning. */
function fc_get_schema_rating_value( $rating ) {
    $map = [
        'false'           => 1,
        'altered'         => 1,
        'partly-false'    => 2,
        'misleading'      => 2,
        'missing-context' => 3,
        'satire'          => 3,
        'insight'         => 5,
        'news'            => 5,
    ];
    return $map[ strtolower( (string) $rating ) ] ?? 1;
}

/** Plain text on one line, at most $max characters. */
function fc_trim_plain( $text, $max ) {
    $text = wp_strip_all_tags( (string) $text );
    $text = preg_replace( '/\s+/', ' ', $text );
    $text = trim( $text );
    if ( mb_strlen( $text ) > $max ) {
        $text = mb_substr( $text, 0, $max );
    }
    return $text;
}
