<?php
/**
 * Automatic updates from GitHub.
 *
 * Push a new version to GitHub and every site shows the usual
 * "Update available" prompt under Plugins. One click and it updates.
 * No ZIP uploads, no FTP, nothing to do on each site.
 *
 * A few things worth knowing:
 *
 *  - We check both releases and tags, and use whichever is newer. A tag on
 *    its own is enough; publishing a release is optional and only adds the
 *    notes shown in the "View details" popup.
 *  - Answers are cached for 12 hours so we stay well inside GitHub's limit
 *    of 60 requests an hour. A failed check is only cached for 1 hour, so a
 *    brief outage doesn't block updates for the rest of the day.
 *  - The repository is public, so no token or password is stored anywhere.
 *
 * Every function here is prefixed fc_updater_ and used only in this file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** How long a good answer is kept before we ask GitHub again. */
define( 'FC_UPDATER_CACHE_TTL', 12 * HOUR_IN_SECONDS );

/** How long a failed answer is kept. Shorter, so outages recover quickly. */
define( 'FC_UPDATER_FAIL_TTL', HOUR_IN_SECONDS );

/**
 * Where the cached answer is stored.
 *
 * The value is left as-is from earlier versions so that sites upgrading
 * from those still find and clear their existing cache.
 */
define( 'FC_UPDATER_CACHE_KEY', 'fc_latest_release' );

/**
 * Seconds to wait on GitHub before giving up.
 *
 * This runs while an admin page is loading, and there are two requests, so
 * keep it short — a slow GitHub should not mean a slow dashboard.
 */
define( 'FC_UPDATER_TIMEOUT', 10 );


// ── Which plugin, and which repository ───────────────────────────────────────

/**
 * The GitHub repository to check.
 *
 * Filterable so a fork or mirror can point elsewhere without editing this file.
 */
function fc_updater_repo() {
    return apply_filters( 'fc_update_repo', 'lemoonrick/factcrescendo-publisher' );
}

/** e.g. "factcrescendo-publisher/factcrescendo-publisher.php" */
function fc_updater_basename() {
    return plugin_basename( FC_PUBLISHER_FILE );
}

/** The plugin's folder name, which WordPress treats as its identity. */
function fc_updater_slug() {
    return dirname( fc_updater_basename() );
}


// ── Asking GitHub what the newest version is ─────────────────────────────────

/**
 * The newest version available, as an array of
 * [ version, zip, notes, published, url ], or null if we couldn't find one.
 *
 * Checks releases and tags and returns whichever is newer. On a tie the
 * release wins, because only a release carries notes.
 *
 * @param bool $force Skip the cache and ask GitHub now.
 */
function fc_updater_latest_version( $force = false ) {

    if ( ! $force ) {
        $cached = get_transient( FC_UPDATER_CACHE_KEY );

        // An empty string means "we asked recently and got nothing usable".
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }
    }

    $best = fc_updater_from_release();
    $tag  = fc_updater_from_tag();

    if ( $tag && ( ! $best || version_compare( $tag['version'], $best['version'], '>' ) ) ) {
        $best = $tag;
    }

    if ( ! $best ) {
        set_transient( FC_UPDATER_CACHE_KEY, '', FC_UPDATER_FAIL_TTL );
        return null;
    }

    set_transient( FC_UPDATER_CACHE_KEY, $best, FC_UPDATER_CACHE_TTL );

    return $best;
}

/**
 * One request to the GitHub API. Returns the decoded response, or null.
 */
function fc_updater_github_get( $path ) {

    $response = wp_remote_get(
        'https://api.github.com/repos/' . fc_updater_repo() . $path,
        [
            'timeout' => FC_UPDATER_TIMEOUT,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                // GitHub refuses requests that don't say who they are.
                'User-Agent' => 'FactCrescendo-Publisher/' . FC_PUBLISHER_VERSION,
            ],
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    return is_array( $body ) ? $body : null;
}

/**
 * The newest published release, or null if the repository has none.
 */
function fc_updater_from_release() {

    $body = fc_updater_github_get( '/releases/latest' );

    if ( empty( $body['tag_name'] ) ) return null;

    $version = fc_updater_version_from_tag( $body['tag_name'] );
    if ( $version === '' ) return null;

    return [
        'version'   => $version,
        'zip'       => fc_updater_pick_zip( $body ),
        'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
        'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
        'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
    ];
}

/**
 * The highest version tag, whether or not it was ever published as a release.
 *
 * GitHub doesn't return tags in version order, so every tag is compared
 * rather than trusting the first one in the list.
 */
function fc_updater_from_tag() {

    // 100 is the most GitHub returns at once, and newer tags come first, so
    // the newest version is always in this page.
    $body = fc_updater_github_get( '/tags?per_page=100' );

    if ( ! $body ) return null;

    $best = null;

    foreach ( $body as $tag ) {
        if ( empty( $tag['name'] ) || empty( $tag['zipball_url'] ) ) continue;

        $version = fc_updater_version_from_tag( $tag['name'] );
        if ( $version === '' ) continue;

        if ( $best === null || version_compare( $version, $best['version'], '>' ) ) {
            $best = [
                'version'   => $version,
                'zip'       => (string) $tag['zipball_url'],
                'notes'     => '',
                'published' => '',
                'url'       => 'https://github.com/' . fc_updater_repo() . '/releases/tag/' . $tag['name'],
            ];
        }
    }

    return $best;
}

/**
 * Turns a tag name into a version number, or '' if it isn't one.
 *
 * Tags are written "v4.6.3"; WordPress compares "4.6.3". Anything that
 * isn't a plain dotted number is ignored, so tags like "beta" or
 * "v5.0-rc1" are never mistaken for a release.
 */
function fc_updater_version_from_tag( $tag_name ) {

    $version = ltrim( trim( (string) $tag_name ), 'vV' );

    return preg_match( '/^\d+(\.\d+)*$/', $version ) ? $version : '';
}

/**
 * Which ZIP to download.
 *
 * A ZIP attached to the release is preferred, since it is packaged with the
 * right folder name already. Otherwise we use GitHub's automatic source ZIP,
 * which works once fc_updater_fix_folder_name() renames the folder.
 */
function fc_updater_pick_zip( $body ) {

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


// ── Telling WordPress whether an update exists ───────────────────────────────

add_filter( 'pre_set_site_transient_update_plugins', 'fc_updater_check' );

/**
 * WordPress keeps two lists: plugins needing an update, and plugins that
 * don't. We put this plugin in the right one.
 */
function fc_updater_check( $transient ) {

    if ( ! is_object( $transient ) ) return $transient;

    $latest = fc_updater_latest_version();
    if ( ! $latest || empty( $latest['zip'] ) || empty( $latest['version'] ) ) {
        return $transient;
    }

    $basename = fc_updater_basename();

    /*
     * Which version is installed?
     *
     * Read it from $transient->checked, which WordPress has just taken from
     * the plugin files on disk — not from FC_PUBLISHER_VERSION. During an
     * update the old plugin file is still loaded in memory, so that constant
     * would still hold the version being replaced, and we would announce an
     * update to the version that was just installed.
     */
    $installed = ! empty( $transient->checked[ $basename ] )
        ? $transient->checked[ $basename ]
        : FC_PUBLISHER_VERSION;

    $info = (object) [
        'id'          => fc_updater_repo(),
        'slug'        => fc_updater_slug(),
        'plugin'      => $basename,
        'new_version' => $latest['version'],
        'url'         => $latest['url'],
        'package'     => $latest['zip'],
        'icons'       => [],
        'banners'     => [],
    ];

    /*
     * Whichever list we add to, remove from the other one.
     *
     * The "new version available" notice is drawn from the first list, and
     * plenty of things re-save this data without rebuilding it — activating
     * a plugin, clearing a cache, another plugin touching it. Without the
     * removal, an old entry would survive and the notice would stay on
     * screen even after updating.
     */
    if ( version_compare( $installed, $latest['version'], '<' ) ) {
        $transient->response[ $basename ] = $info;
        unset( $transient->no_update[ $basename ] );
    } else {
        $transient->no_update[ $basename ] = $info;
        unset( $transient->response[ $basename ] );
    }

    return $transient;
}


// ── The "View details" popup ─────────────────────────────────────────────────

add_filter( 'plugins_api', 'fc_updater_plugin_details', 20, 3 );

/**
 * Fills in the popup WordPress shows when someone clicks "View details".
 * Anything not about this plugin is passed straight through untouched.
 */
function fc_updater_plugin_details( $result, $action, $args ) {

    if ( $action !== 'plugin_information' ) return $result;
    if ( empty( $args->slug ) || $args->slug !== fc_updater_slug() ) return $result;

    $latest = fc_updater_latest_version();
    if ( ! $latest ) return $result;

    return (object) [
        'name'          => 'FactCrescendo Publisher',
        'slug'          => fc_updater_slug(),
        'version'       => $latest['version'],
        'author'        => '<a href="https://factcrescendo.com">FactCrescendo</a>',
        'homepage'      => $latest['url'],
        'download_link' => $latest['zip'],
        'last_updated'  => $latest['published'],
        'sections'      => [
            'description' => 'Automates fact-check publishing: structured fields, auto-inserted fact card, author box and WhatsApp banner, ClaimReview schema, audio narration, and a read-only REST API.',
            'changelog'   => fc_updater_format_notes( $latest['notes'] ),
        ],
    ];
}

/**
 * Release notes come from GitHub, so they are cleaned before display.
 */
function fc_updater_format_notes( $notes ) {

    $notes = trim( (string) $notes );

    if ( $notes === '' ) return 'No release notes were provided for this version.';

    return wpautop( wp_kses_post( $notes ) );
}


// ── Installing the update ────────────────────────────────────────────────────

add_filter( 'upgrader_source_selection', 'fc_updater_fix_folder_name', 10, 4 );

/**
 * Renames the unpacked folder before WordPress installs it.
 *
 * GitHub's automatic ZIP unpacks to something like
 * "lemoonrick-factcrescendo-publisher-a1b2c3d". WordPress uses the folder
 * name as the plugin's identity, so without this the update would arrive as
 * a separate, switched-off copy while the real one stayed on the old version.
 */
function fc_updater_fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = null ) {

    global $wp_filesystem;

    // Only touch our own update. Everything else passes through.
    if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== fc_updater_basename() ) {
        return $source;
    }

    if ( ! $wp_filesystem ) return $source;

    $desired = trailingslashit( $remote_source ) . fc_updater_slug();

    if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
        return $source; // already named correctly
    }

    if ( $wp_filesystem->move( $source, $desired ) ) {
        return trailingslashit( $desired );
    }

    return new WP_Error(
        'fc_rename_failed',
        'Could not prepare the update folder. Please try again, or install the update manually.'
    );
}


add_action( 'upgrader_process_complete', 'fc_updater_clear_cache', 10, 2 );

/**
 * Clears both cached answers once this plugin has been updated, so the next
 * check reads the new state instead of the old one.
 */
function fc_updater_clear_cache( $upgrader, $hook_extra ) {

    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) return;

    $plugins = ! empty( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : [];

    // A single-plugin update reports itself under 'plugin' instead.
    if ( ! empty( $hook_extra['plugin'] ) ) {
        $plugins[] = $hook_extra['plugin'];
    }

    if ( ! in_array( fc_updater_basename(), $plugins, true ) ) return;

    delete_transient( FC_UPDATER_CACHE_KEY );
    delete_site_transient( 'update_plugins' );
}


// ── "Check for updates" link on the Plugins page ─────────────────────────────

add_filter( 'plugin_action_links_' . plugin_basename( FC_PUBLISHER_FILE ), 'fc_updater_action_link' );

/**
 * Adds a "Check for updates" link, for when you don't want to wait for the
 * next scheduled check.
 */
function fc_updater_action_link( $links ) {

    if ( ! current_user_can( 'update_plugins' ) ) return $links;

    $url = wp_nonce_url(
        admin_url( 'admin-post.php?action=fc_check_updates' ),
        'fc_check_updates'
    );

    $links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';

    return $links;
}

add_action( 'admin_post_fc_check_updates', 'fc_updater_handle_check' );

/**
 * Handles that link. Only for users who can update plugins, and only via
 * the link itself — the nonce stops it being triggered from elsewhere.
 */
function fc_updater_handle_check() {

    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_die( 'You do not have permission to check for plugin updates.' );
    }

    check_admin_referer( 'fc_check_updates' );

    // Throw away our cached answer and WordPress's, then ask again.
    delete_transient( FC_UPDATER_CACHE_KEY );
    delete_site_transient( 'update_plugins' );
    wp_update_plugins();

    wp_safe_redirect( add_query_arg( 'fc-checked', '1', admin_url( 'plugins.php' ) ) );
    exit;
}

add_action( 'admin_notices', 'fc_updater_notice' );

/**
 * Says what the check found. Shown once, on the Plugins page, straight after
 * the link above has been used.
 */
function fc_updater_notice() {

    // Only a display flag, set by the redirect above. Never printed.
    if ( ! isset( $_GET['fc-checked'] ) ) return;

    if ( ! current_user_can( 'update_plugins' ) ) return;

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'plugins' ) return;

    $latest = fc_updater_latest_version();

    if ( ! $latest ) {
        $message = 'Could not reach GitHub to check for updates. Please try again shortly.';
        $class   = 'notice-warning';
    } elseif ( version_compare( FC_PUBLISHER_VERSION, $latest['version'], '<' ) ) {
        $message = 'FactCrescendo Publisher ' . $latest['version'] . ' is available. The update link is in the list below.';
        $class   = 'notice-success';
    } else {
        $message = 'FactCrescendo Publisher is up to date (version ' . FC_PUBLISHER_VERSION . ').';
        $class   = 'notice-success';
    }

    echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
