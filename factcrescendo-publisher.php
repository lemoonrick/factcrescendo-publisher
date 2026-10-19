<?php
/**
 * Plugin Name:       FactCrescendo Publisher
 * Plugin URI:        https://factcrescendo.com
 * Description:       Automates fact-check publishing. Built-in fact-check fields, REST API, auto-injected Fact Card / stamped Featured Image / Author Box / WhatsApp Banner, automatic embeds, ClaimReview schema, and article narration.
 * Version:           4.9.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            FactCrescendo
 * License:           GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FC_PUBLISHER_VERSION', '4.9.0' );
define( 'FC_PUBLISHER_FILE', __FILE__ );
define( 'FC_PUBLISHER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FC_PUBLISHER_URL', plugin_dir_url( __FILE__ ) );

/** Version tag for a plugin file: changes whenever the file does, so browsers fetch the new copy. */
function fc_asset_version( $relative ) {
    $time = @filemtime( FC_PUBLISHER_DIR . $relative );
    return FC_PUBLISHER_VERSION . ( $time ? '.' . $time : '' );
}

// Load all modules. fields.php and sites.php come first because the
// others read from them.
require_once FC_PUBLISHER_DIR . 'includes/fields.php';
require_once FC_PUBLISHER_DIR . 'includes/sites.php';
require_once FC_PUBLISHER_DIR . 'includes/rest-api.php';
require_once FC_PUBLISHER_DIR . 'includes/content-injection.php';
require_once FC_PUBLISHER_DIR . 'includes/fact-card.php';
require_once FC_PUBLISHER_DIR . 'includes/featured-image.php';
require_once FC_PUBLISHER_DIR . 'includes/embeds.php';
require_once FC_PUBLISHER_DIR . 'includes/settings-page.php';
require_once FC_PUBLISHER_DIR . 'includes/seo-schema.php';
require_once FC_PUBLISHER_DIR . 'includes/updater.php';

// Premium ElevenLabs narration (includes/audio-generator.php) is switched off
// for now: it is not loaded, so nothing calls the API. Posts that already
// have a recording keep playing it. To bring it back, load the file here,
// restore its settings and give it a field in fields.php.

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
    delete_option( 'fc_site_key' );
    delete_option( 'fc_card_design' );
    delete_option( 'fc_show_whatsapp_banner' );
    delete_option( 'fc_whatsapp_banner_style' );
    delete_option( 'fc_card_share' );
    delete_option( 'fc_featured_stamps' );
    delete_option( 'fc_featured_corner' );
    delete_option( 'fc_featured_since' );
    delete_option( 'fc_auto_embeds' );
    delete_option( 'fc_embeds_since' );
    delete_option( 'fc_embed_notes' );
    delete_transient( 'fc_latest_release' );
}
