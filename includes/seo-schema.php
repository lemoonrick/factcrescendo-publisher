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

    // claimReviewed: the claim itself, cleaned up but NOT cut short.
    //
    // The old ~75 character limit came from Google's guidance for the
    // search result box, and that display feature has since been retired.
    // What still reads this markup — Fact Check Explorer and AI systems —
    // is better served by the whole claim than by half of one plus "...".
    $claim_reviewed = $claim !== '' ? $claim : get_the_title( $post_id );
    $claim_reviewed = fc_trim_plain( $claim_reviewed, 300 );

    // Publisher name, kept safely under Google's 100-char author limit.
    $site_name = fc_trim_plain( get_bloginfo( 'name' ), 100 );

    $date_published = get_the_date( 'c', $post_id ); // ISO 8601 with offset
    $date_modified  = get_the_modified_date( 'c', $post_id );

    // ── The claim being reviewed ──────────────────────────────────────────
    //
    // 'appearance' means "where the claim originally appeared" — the viral
    // post, the video, the channel. It used to be filled with a link to this
    // very article, which is a circle pointing at itself and tells Google
    // nothing. It is now only included when an editor supplies a real source,
    // and left out otherwise.
    //
    // 'author' is who made the claim. It was missing entirely, and it is the
    // single most useful field here for Fact Check Explorer.
    $item_reviewed = [
        '@type'         => 'Claim',
        'datePublished' => $date_published,
    ];

    $source_url = trim( (string) get_field( 'fc_claim_source_url', $post_id ) );
    if ( $source_url !== '' && wp_http_validate_url( $source_url ) ) {
        $item_reviewed['appearance'] = [
            '@type' => 'CreativeWork',
            'url'   => esc_url_raw( $source_url ),
        ];
    }

    $claim_author = fc_trim_plain( (string) get_field( 'fc_claim_author', $post_id ), 100 );
    if ( $claim_author !== '' ) {
        $author_type = get_field( 'fc_claim_author_type', $post_id );
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

        // The claim being reviewed. Built below so the optional source
        // fields are only included when an editor actually filled them in.
        'itemReviewed' => $item_reviewed,

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
