<?php
/**
 * The site list — what each FactCrescendo site needs to know about itself.
 *
 * Every site runs the same copy of the plugin and finds its own entry here
 * by its web address, so there is nothing to set up per site. To change a
 * site's details, edit its entry and publish an update.
 *
 * A site with no entry simply gets no WhatsApp banner. Settings > FC
 * Publisher shows which entry a site matched, and lets an admin pick one by
 * hand — useful on a staging copy, whose address won't be in the list.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keyed by web address without "www.".
 *
 *  label           Shown in Settings so an admin can see which entry matched.
 *  lang            Two-letter language code. Decides whether the free
 *                  "Listen to this article" player appears (English only).
 *  whatsapp_url    Where the WhatsApp banner links to.
 *  whatsapp_number The number as printed on the banner.
 *  card_number     The number as printed on the fact card (optional; the
 *                  banner's number is used when this is empty).
 *  socials         Optional links for the card's "Follow us" icons, e.g.
 *                  [ 'facebook' => 'https://…', 'instagram' => '…', 'x' => '…' ].
 *                  An icon with no link is shown without one.
 *  join_language   The language readers type to join the broadcast list.
 *                  Empty means the site has no broadcast list.
 *  banner_image    The old picture banner, kept for sites that prefer it.
 */
function fc_site_profiles() {

    $india_whatsapp = 'https://api.whatsapp.com/send?phone=919049053770&text=Please%20type%20your%20Name%20and%20Language:%20';
    $sl_whatsapp    = 'https://api.whatsapp.com/send?phone=94771514696&text=Please%20send%20your%20query:%20';

    $india = [ 'whatsapp_url' => $india_whatsapp, 'whatsapp_number' => '+91 90490 53770', 'card_number' => '9049053770' ];
    $sl    = [ 'whatsapp_url' => $sl_whatsapp,    'whatsapp_number' => '+94 77 151 4696', 'join_language' => '' ];

    $profiles = [
        'factcrescendo.com' => $india + [
            'label'         => 'Hindi',
            'join_language' => 'Hindi',
            'lang'          => 'hi',
            'banner_image'  => 'https://www.factcrescendo.com/wp-content/uploads/2020/03/T-Hindi-23.png',
        ],
        'english.factcrescendo.com' => $india + [
            'label'         => 'English',
            'join_language' => 'English',
            'lang'          => 'en',
            'banner_image'  => 'https://english.factcrescendo.com/wp-content/uploads/2021/03/English-2-scaled.jpg',
        ],
        'malayalam.factcrescendo.com' => $india + [
            'label'         => 'Malayalam',
            'join_language' => 'Malayalam',
            'lang'          => 'ml',
            'banner_image'  => 'https://malayalam.factcrescendo.com/wp-content/uploads/2021/03/Malayalam-2.png',
        ],
        'tamil.factcrescendo.com' => $india + [
            'label'         => 'Tamil',
            'join_language' => 'Tamil',
            'lang'          => 'ta',
            'banner_image'  => 'https://tamil.factcrescendo.com/wp-content/uploads/2021/03/Tamil-new.png',
        ],
        'gujarati.factcrescendo.com' => $india + [
            'label'         => 'Gujarati',
            'join_language' => 'Gujarati',
            'lang'          => 'gu',
            'banner_image'  => 'https://gujarati.factcrescendo.com/wp-content/uploads/2021/03/Gujarati-2.png',
        ],
        'odia.factcrescendo.com' => $india + [
            'label'         => 'Odia',
            'join_language' => 'Odia',
            'lang'          => 'or',
            'banner_image'  => 'https://odia.factcrescendo.com/wp-content/uploads/2021/03/Odia-2.png',
        ],
        'assamese.factcrescendo.com' => $india + [
            'label'         => 'Assamese',
            'join_language' => 'Assamese',
            'lang'          => 'as',
            'banner_image'  => 'https://assamese.factcrescendo.com/wp-content/uploads/2021/03/Assamese.png',
        ],
        'marathi.factcrescendo.com' => $india + [
            'label'         => 'Marathi',
            'join_language' => 'Marathi',
            'lang'          => 'mr',
            'banner_image'  => 'https://marathi.factcrescendo.com/wp-content/uploads/2021/03/Marathi-2-scaled.jpg',
        ],

        // Sri Lanka. The banner was pasted with an http:// address; browsers
        // block or upgrade those on https pages, so it is requested over
        // https directly. Language is left to the site's own setting because
        // one entry covers the Sinhala, Tamil and English editions.
        'srilanka.factcrescendo.com' => $sl + [
            'label'         => 'Sri Lanka',
            'lang'          => '',
            'banner_image'  => 'https://srilanka.factcrescendo.com/wp-content/uploads/2023/05/Whats-app-sri-lanka-new.png',
        ],
    ];

    /** Lets a site add or adjust entries without editing this file. */
    return apply_filters( 'fc_site_profiles', $profiles );
}

/** "english.factcrescendo.com" for https://www.english.factcrescendo.com/... */
function fc_normalise_host( $host ) {
    $host = strtolower( trim( (string) $host ) );
    return preg_replace( '/^www\./', '', $host );
}

/**
 * The key of the entry this site uses, or '' when there is none.
 *
 * An admin's choice in Settings wins; otherwise the site's own address.
 */
function fc_site_key() {
    $profiles = fc_site_profiles();

    $chosen = (string) get_option( 'fc_site_key', '' );
    if ( $chosen !== '' && isset( $profiles[ $chosen ] ) ) return $chosen;

    $host = fc_normalise_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
    return isset( $profiles[ $host ] ) ? $host : '';
}

/** This site's entry, with every field present (empty when not known). */
function fc_site_profile() {
    $profiles = fc_site_profiles();
    $key      = fc_site_key();
    $profile  = $key !== '' ? $profiles[ $key ] : [];

    return array_merge( [
        'label'           => '',
        'lang'            => '',
        'whatsapp_url'    => '',
        'whatsapp_number' => '',
        'card_number'     => '',
        'socials'         => [],
        'join_language'   => '',
        'banner_image'    => '',
    ], is_array( $profile ) ? $profile : [] );
}

/**
 * The site's language as a two-letter code.
 *
 * From the site list when it says, otherwise from WordPress's own language
 * setting (en_US → en).
 */
function fc_site_lang() {
    $lang = fc_site_profile()['lang'];
    if ( $lang === '' ) $lang = substr( (string) get_locale(), 0, 2 );
    return strtolower( $lang );
}
