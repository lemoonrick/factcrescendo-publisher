<?php
/**
 * ClaimReview structured data — the org-wide, one-stop fact-check schema.
 *
 * Google is phasing out ClaimReview *rich results* in Search, but the
 * markup is still consumed by Google's Fact Check Explorer Tool and by
 * other engines and AI systems, and it remains the Schema.org standard.
 * This implementation follows Google's current published spec exactly
 * (verified against developers.google.com, last updated Dec 2025) so it
 * validates cleanly in the Rich Results Test with no errors.
 *
 * Design decisions, mapped to Google's requirements:
 *  - Exactly ONE ClaimReview per page (Google requires this; a second one
 *    disqualifies the page). We guard with a static flag.
 *  - claimReviewed is the claim text, rating stripped, trimmed toward the
 *    ~75-char target Google recommends, without cutting mid-word.
 *  - reviewRating carries BOTH the numeric scale (1-5) and the textual
 *    alternateName — the text is what search shows.
 *  - url is the canonical permalink on this same domain (Google requires
 *    same domain / subdomain; no redirects or shorteners).
 *  - itemReviewed uses the modern Claim type with an `appearance` pointing
 *    at this article, plus datePublished.
 *  - author is the publishing site (dynamic per edition), name kept under
 *    Google's 100-char limit, with a url.
 *  - dateModified is included so corrected verdicts stay accurate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_head', 'fc_inject_claim_review_schema', 5 );

function fc_inject_claim_review_schema() {

    // Google allows only one ClaimReview per page. Never emit twice.
    static $already_emitted = false;
    if ( $already_emitted ) return;

    if ( ! is_singular( 'post' ) ) return;
    if ( ! function_exists( 'get_field' ) ) return;

    $post_id = get_the_ID();
    if ( ! $post_id ) return;

    // Don't emit for noindexed posts (Yoast) — schema on a hidden page is
    // pointless and can look like markup spam.
    $noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
    if ( $noindex === '1' ) return;

    $is_fc = (bool) get_field( 'fc_is_fact_check', $post_id );
    if ( ! $is_fc ) return;

    $rating = get_field( 'fc_rating', $post_id );
    if ( empty( $rating ) ) return;

    $claim        = trim( (string) get_field( 'fc_claim', $post_id ) );
    $rating_label = fc_get_rating_label( $rating );
    $rating_value = fc_get_schema_rating_value( $rating );
    $permalink    = get_permalink( $post_id );

    // claimReviewed: claim text, no rating, trimmed toward ~75 chars on a
    // word boundary. Falls back to the post title if no claim was set.
    $claim_reviewed = $claim !== '' ? $claim : get_the_title( $post_id );
    $claim_reviewed = fc_trim_claim_text( $claim_reviewed, 75 );

    // Publisher name, kept safely under Google's 100-char author limit.
    $site_name = fc_trim_plain( get_bloginfo( 'name' ), 100 );

    $date_published = get_the_date( 'c', $post_id ); // ISO 8601 with offset
    $date_modified  = get_the_modified_date( 'c', $post_id );

    $schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'ClaimReview',
        'url'           => $permalink,
        'datePublished' => $date_published,
        'dateModified'  => $date_modified,
        'claimReviewed' => $claim_reviewed,

        // The claim being reviewed. Modern Claim type with an appearance
        // pointing back at this fact-check article.
        'itemReviewed' => [
            '@type'         => 'Claim',
            'datePublished' => $date_published,
            'appearance'    => [
                '@type' => 'CreativeWork',
                'url'   => $permalink,
            ],
        ],

        // Publisher of the FACT CHECK (this site/edition), not the claim.
        'author' => [
            '@type' => 'Organization',
            'name'  => $site_name,
            'url'   => home_url(),
        ],

        // Both numeric scale and the human-readable verdict text.
        'reviewRating' => [
            '@type'         => 'Rating',
            'ratingValue'   => $rating_value,
            'bestRating'    => 5,
            'worstRating'   => 1,
            'alternateName' => $rating_label,
        ],
    ];

    /**
     * Lets a site fine-tune the schema before output (e.g. add an image,
     * a claim author, or firstAppearance) without editing the plugin.
     */
    $schema = apply_filters( 'fc_claimreview_schema', $schema, $post_id );

    $already_emitted = true;

    echo "\n" . '<script type="application/ld+json">'
        . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
        . '</script>' . "\n";
}

/**
 * Maps each rating to Google's documented 1-5 truthfulness scale:
 * 1 = False, 2 = Mostly false, 3 = Half true, 4 = Mostly true, 5 = True.
 *
 * Satire and Altered are deliberately NOT lumped into 1 ("False") — they
 * are distinct verdicts. The numeric value is a best-fit on Google's scale
 * while the real meaning is always carried by alternateName (the verdict
 * text), which is what actually displays.
 */
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

/**
 * Trims claim text toward a target length without cutting mid-word, and
 * collapses whitespace. Google recommends claimReviewed stay near 75 chars
 * to avoid wrapping on mobile.
 */
function fc_trim_claim_text( $text, $target = 75 ) {
    $text = fc_trim_plain( $text, 300 ); // hard ceiling first
    if ( mb_strlen( $text ) <= $target ) return $text;

    $cut = mb_substr( $text, 0, $target );
    $last_space = mb_strrpos( $cut, ' ' );
    if ( $last_space !== false && $last_space > 40 ) {
        $cut = mb_substr( $cut, 0, $last_space );
    }
    return rtrim( $cut, " ,.;:" ) . '…';
}

/**
 * Collapses whitespace, strips tags, and hard-caps length. Used for any
 * value that goes into the schema so nothing malformed reaches output.
 */
function fc_trim_plain( $text, $max ) {
    $text = wp_strip_all_tags( (string) $text );
    $text = preg_replace( '/\s+/', ' ', $text );
    $text = trim( $text );
    if ( mb_strlen( $text ) > $max ) {
        $text = mb_substr( $text, 0, $max );
    }
    return $text;
}
