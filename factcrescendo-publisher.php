<?php
/**
 * Plugin Name:       FactCrescendo Publisher
 * Plugin URI:        https://factcrescendo.com
 * Description:       Automates fact-check publishing. Registers ACF fields, exposes them via REST API, injects Author Box/WA Banner, and adds ClaimReview Schema.
 * Version:           3.1.0
 * Author:            FactCrescendo
 * License:           GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FC_PUBLISHER_VERSION', '3.1.0' );
define( 'FC_PUBLISHER_DIR', plugin_dir_path( __FILE__ ) );

// Load all modules
require_once FC_PUBLISHER_DIR . 'includes/rest-api.php';
require_once FC_PUBLISHER_DIR . 'includes/content-injection.php';
require_once FC_PUBLISHER_DIR . 'includes/settings-page.php';
require_once FC_PUBLISHER_DIR . 'includes/seo-schema.php'; // NEW: SEO Schema
require_once FC_PUBLISHER_DIR . 'includes/acf-fields.php';

// Flush rewrite rules on ACF activation
add_action( 'activate_advanced-custom-fields/acf.php', 'fc_flush_on_acf_activate' );
add_action( 'activate_advanced-custom-fields-pro/acf.php', 'fc_flush_on_acf_activate' );
function fc_flush_on_acf_activate() {
    flush_rewrite_rules();
}

// Admin notice when ACF is missing
add_action( 'admin_notices', 'fc_acf_missing_notice' );
function fc_acf_missing_notice() {
    if ( class_exists( 'ACF' ) ) return;

    $install_url = admin_url( 'plugin-install.php?s=advanced+custom+fields&tab=search&type=term' );
    echo '<div class="notice notice-error"><p>
        <strong>FactCrescendo Publisher requires Advanced Custom Fields (ACF).</strong> 
        <a href="' . esc_url( $install_url ) . '">Install ACF Free</a> for the fact-check fields to appear.
    </p></div>';
}