<?php
/**
 * Plugin Name:       FactCrescendo Publisher
 * Plugin URI:        https://factcrescendo.com
 * Description:       Automates fact-check publishing. ACF fields, REST API, auto-injected Fact Card / Author Box / WhatsApp Banner, ClaimReview schema, and AI narration.
 * Version:           4.1.1
 * Author:            FactCrescendo
 * License:           GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FC_PUBLISHER_VERSION', '4.1.1' );
define( 'FC_PUBLISHER_FILE', __FILE__ );
define( 'FC_PUBLISHER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FC_PUBLISHER_URL', plugin_dir_url( __FILE__ ) );

// Load all modules. Order matters only for readability here —
// every file guards itself against missing dependencies (e.g. ACF).
require_once FC_PUBLISHER_DIR . 'includes/acf-fields.php';
require_once FC_PUBLISHER_DIR . 'includes/rest-api.php';
require_once FC_PUBLISHER_DIR . 'includes/content-injection.php';
require_once FC_PUBLISHER_DIR . 'includes/settings-page.php';
require_once FC_PUBLISHER_DIR . 'includes/seo-schema.php';
require_once FC_PUBLISHER_DIR . 'includes/audio-generator.php';
require_once FC_PUBLISHER_DIR . 'includes/updater.php';

// Flush rewrite rules the moment ACF activates, so fields register cleanly
// regardless of install order (plugin first or ACF first — both work).
add_action( 'activate_advanced-custom-fields/acf.php', 'fc_flush_on_acf_activate' );
add_action( 'activate_advanced-custom-fields-pro/acf.php', 'fc_flush_on_acf_activate' );
function fc_flush_on_acf_activate() {
    flush_rewrite_rules();
}

// Admin notice when ACF is missing — no dependency, just a clear nudge.
add_action( 'admin_notices', 'fc_acf_missing_notice' );
function fc_acf_missing_notice() {
    if ( class_exists( 'ACF' ) ) return;
    if ( ! current_user_can( 'install_plugins' ) ) return;

    $install_url = admin_url( 'plugin-install.php?s=advanced+custom+fields&tab=search&type=term' );
    echo '<div class="notice notice-warning is-dismissible"><p>
        <strong>FactCrescendo Publisher</strong> requires Advanced Custom Fields.
        <a href="' . esc_url( $install_url ) . '">Install ACF Free</a> — fields will appear automatically once it is active.
    </p></div>';
}

/**
 * Uninstall cleanup lives in a separate check so activating/deactivating
 * never touches stored data. Only fires on actual plugin deletion.
 */
register_uninstall_hook( __FILE__, 'fc_publisher_uninstall' );
function fc_publisher_uninstall() {
    delete_option( 'fc_whatsapp_banner_html' );
    delete_option( 'fc_logo_url' );
    delete_option( 'fc_enable_audio' );
    delete_option( 'fc_elevenlabs_api_key' );
    delete_option( 'fc_elevenlabs_voice_id' );
    delete_transient( 'fc_latest_release' );
}
