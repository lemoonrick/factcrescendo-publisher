<?php
/**
 * Automatic updates from GitHub Releases.
 *
 * Publish a release on GitHub, and every site running this plugin shows the
 * normal "Update available" prompt in Plugins -> Installed Plugins. One
 * click updates it. No ZIP uploads, no FTP, no per-site work.
 *
 * How it works:
 *  - WordPress asks all plugins "is there a newer version?" a few times a
 *    day. We answer by looking at the latest GitHub release.
 *  - The answer is cached for 12 hours so we don't hammer GitHub's API
 *    (which allows 60 unauthenticated requests per hour, per server).
 *  - A failed check is cached for only 1 hour, so a brief GitHub outage
 *    doesn't block updates for half a day.
 *  - GitHub's auto-generated ZIP unpacks into a folder named after the
 *    repo and commit. Left alone, WordPress would install that as a brand
 *    new plugin and deactivate the real one. We rename it back first.
 *
 * The repository is public, so no token or password is needed anywhere.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FC_UPDATE_CACHE_KEY', 'fc_latest_release' );

/**
 * Which GitHub repository to check. Filterable so a fork or a private
 * mirror can point somewhere else without editing this file.
 */
function fc_update_repo() {
    return apply_filters( 'fc_update_repo', 'lemoonrick/factcrescendo-publisher' );
}

function fc_plugin_basename() {
    return plugin_basename( FC_PUBLISHER_FILE );
}

function fc_plugin_slug() {
    return dirname( fc_plugin_basename() );
}


// -- Ask GitHub what the latest release is -----------------------------------

/**
 * Returns [ version, zip, notes, published, url ] for the newest release,
 * or null if there isn't one / GitHub couldn't be reached.
 */
function fc_get_latest_release( $force = false ) {

    if ( ! $force ) {
        $cached = get_transient( FC_UPDATE_CACHE_KEY );
        // An empty string means "we checked recently and got nothing usable".
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . fc_update_repo() . '/releases/latest',
        [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                // GitHub rejects API requests that don't identify themselves.
                'User-Agent' => 'FactCrescendo-Publisher/' . FC_PUBLISHER_VERSION,
            ],
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        set_transient( FC_UPDATE_CACHE_KEY, '', HOUR_IN_SECONDS );
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body['tag_name'] ) ) {
        set_transient( FC_UPDATE_CACHE_KEY, '', HOUR_IN_SECONDS );
        return null;
    }

    $release = [
        // Tags are usually written "v4.1.0"; WordPress compares "4.1.0".
        'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
        'zip'       => fc_pick_release_zip( $body ),
        'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
        'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
        'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
    ];

    set_transient( FC_UPDATE_CACHE_KEY, $release, 12 * HOUR_IN_SECONDS );

    return $release;
}

/**
 * Prefers a ZIP file attached to the release (cleanly packaged, correct
 * folder name). Falls back to GitHub's automatic source ZIP, which works
 * fine once fc_fix_update_folder_name() renames the folder.
 */
function fc_pick_release_zip( $body ) {
    if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
        foreach ( $body['assets'] as $asset ) {
            if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) continue;
            if ( strtolower( substr( $asset['name'], -4 ) ) === '.zip' ) {
                return (string) $asset['browser_download_url'];
            }
        }
    }
    return ! empty( $body['zipball_url'] ) ? (string) $body['zipball_url'] : '';
}


// -- Tell WordPress an update is available -----------------------------------

add_filter( 'pre_set_site_transient_update_plugins', 'fc_check_for_update' );

function fc_check_for_update( $transient ) {

    if ( ! is_object( $transient ) ) return $transient;

    $release = fc_get_latest_release();
    if ( ! $release || empty( $release['zip'] ) || empty( $release['version'] ) ) {
        return $transient;
    }

    $basename = fc_plugin_basename();

    $info = (object) [
        'id'          => fc_update_repo(),
        'slug'        => fc_plugin_slug(),
        'plugin'      => $basename,
        'new_version' => $release['version'],
        'url'         => $release['url'],
        'package'     => $release['zip'],
        'icons'       => [],
        'banners'     => [],
    ];

    if ( version_compare( FC_PUBLISHER_VERSION, $release['version'], '<' ) ) {
        $transient->response[ $basename ] = $info;
    } else {
        // Telling WordPress "no update needed" keeps the plugin off the
        // "unknown origin" list and stops repeat lookups.
        $transient->no_update[ $basename ] = $info;
    }

    return $transient;
}


// -- The "View details" popup ------------------------------------------------

add_filter( 'plugins_api', 'fc_plugin_info', 20, 3 );

function fc_plugin_info( $result, $action, $args ) {

    if ( $action !== 'plugin_information' ) return $result;
    if ( empty( $args->slug ) || $args->slug !== fc_plugin_slug() ) return $result;

    $release = fc_get_latest_release();
    if ( ! $release ) return $result;

    return (object) [
        'name'          => 'FactCrescendo Publisher',
        'slug'          => fc_plugin_slug(),
        'version'       => $release['version'],
        'author'        => '<a href="https://factcrescendo.com">FactCrescendo</a>',
        'homepage'      => $release['url'],
        'download_link' => $release['zip'],
        'last_updated'  => $release['published'],
        'sections'      => [
            'description' => 'Automates fact-check publishing: structured fields, auto-inserted fact card, author box and WhatsApp banner, ClaimReview schema, audio narration, and a read-only REST API.',
            'changelog'   => fc_format_release_notes( $release['notes'] ),
        ],
    ];
}

function fc_format_release_notes( $notes ) {
    $notes = trim( (string) $notes );
    if ( $notes === '' ) return 'No release notes were provided for this version.';
    return wpautop( wp_kses_post( $notes ) );
}


// -- Fix the folder name during install --------------------------------------

/**
 * GitHub's source ZIP unpacks to something like
 * "lemoonrick-factcrescendo-publisher-a1b2c3d/". WordPress uses the folder
 * name as the plugin's identity, so without this the update would land as
 * a separate, deactivated copy. Rename it to the real folder first.
 */
add_filter( 'upgrader_source_selection', 'fc_fix_update_folder_name', 10, 4 );

function fc_fix_update_folder_name( $source, $remote_source, $upgrader, $hook_extra = null ) {

    global $wp_filesystem;

    // Only touch our own plugin's update. Everything else passes through.
    if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== fc_plugin_basename() ) {
        return $source;
    }

    if ( ! $wp_filesystem ) return $source;

    $desired = trailingslashit( $remote_source ) . fc_plugin_slug();

    if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
        return $source; // already correctly named
    }

    if ( $wp_filesystem->move( $source, $desired ) ) {
        return trailingslashit( $desired );
    }

    return new WP_Error(
        'fc_rename_failed',
        'Could not prepare the update folder. Please try again, or install the update manually.'
    );
}


// -- "Check for updates" link on the Plugins page ----------------------------

add_filter( 'plugin_action_links_' . plugin_basename( FC_PUBLISHER_FILE ), 'fc_add_check_updates_link' );

function fc_add_check_updates_link( $links ) {
    if ( ! current_user_can( 'update_plugins' ) ) return $links;

    $url = wp_nonce_url(
        admin_url( 'admin-post.php?action=fc_check_updates' ),
        'fc_check_updates'
    );

    $links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';
    return $links;
}

add_action( 'admin_post_fc_check_updates', 'fc_handle_check_updates' );

function fc_handle_check_updates() {

    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_die( 'You do not have permission to check for plugin updates.' );
    }
    check_admin_referer( 'fc_check_updates' );

    // Throw away both our cached answer and WordPress's, then ask again.
    delete_transient( FC_UPDATE_CACHE_KEY );
    delete_site_transient( 'update_plugins' );
    wp_update_plugins();

    wp_safe_redirect( add_query_arg( 'fc-checked', '1', admin_url( 'plugins.php' ) ) );
    exit;
}

add_action( 'admin_notices', 'fc_check_updates_notice' );

function fc_check_updates_notice() {
    if ( empty( $_GET['fc-checked'] ) ) return;

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'plugins' ) return;

    $release = fc_get_latest_release();

    if ( ! $release ) {
        $message = 'Could not reach GitHub to check for updates. Please try again shortly.';
        $class   = 'notice-warning';
    } elseif ( version_compare( FC_PUBLISHER_VERSION, $release['version'], '<' ) ) {
        $message = 'FactCrescendo Publisher ' . $release['version'] . ' is available. The update link is in the list below.';
        $class   = 'notice-success';
    } else {
        $message = 'FactCrescendo Publisher is up to date (version ' . FC_PUBLISHER_VERSION . ').';
        $class   = 'notice-success';
    }

    echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
