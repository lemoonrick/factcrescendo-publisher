<?php
/**
 * Updates from GitHub. A new version tag on GitHub makes every site show the usual
 * "Update available" prompt; an admin clicks Update (never automatic). Only
 * downloads from our own repository are accepted. The repository is public, so no
 * token is stored. Publishing a GitHub release is optional: it only adds notes to
 * "View details".
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** How long an answer from GitHub is kept. */
define( 'FC_UPDATER_CACHE_TTL', 3 * HOUR_IN_SECONDS );

/** How long a failed check is kept: shorter, so an outage clears quickly. */
define( 'FC_UPDATER_FAIL_TTL', HOUR_IN_SECONDS );

/** Where the answer is cached (name kept from earlier versions). */
define( 'FC_UPDATER_CACHE_KEY', 'fc_latest_release' );

/** Seconds to wait for GitHub. Short: this runs while an admin page loads. */
define( 'FC_UPDATER_TIMEOUT', 10 );


// ── Which plugin, and which repository ───────────────────────────────────────

/** The only repository updates may come from. Deliberately not changeable by a filter. */
function fc_updater_repo() {
    return 'lemoonrick/factcrescendo-publisher';
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
 * [ version, tag, zip, notes, published, url ], or null if we couldn't find one.
 *
 * One request for the list of tags; the highest version wins. Kept for 3 hours;
 * "Check again" on Dashboard → Updates asks straight away.
 *
 * @param bool $force Skip the cache and ask GitHub now.
 */
function fc_updater_latest_version( $force = false ) {
    static $fresh = null;   // the answer "Check again" already got during this page load

    // "Check again" on Dashboard → Updates: ask now, once per page load.
    $button = false;
    if ( ! $force && is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
        if ( $fresh !== null ) return $fresh ?: null;
        $force  = true;
        $button = true;
    }

    if ( ! $force ) {
        $cached = get_transient( FC_UPDATER_CACHE_KEY );

        // An empty string means "we asked recently and got nothing usable".
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }
    }

    $best = fc_updater_from_tag();

    if ( ! $best ) {
        set_transient( FC_UPDATER_CACHE_KEY, '', FC_UPDATER_FAIL_TTL );
        if ( $button ) $fresh = false;
        return null;
    }

    set_transient( FC_UPDATER_CACHE_KEY, $best, FC_UPDATER_CACHE_TTL );
    if ( $button ) $fresh = $best;

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
 * The notes and date of the release published for a tag, if one was
 * published. Only for the "View details" popup; remembered for 12 hours.
 */
function fc_updater_release_info( $tag_name ) {
    $key    = 'fc_release_' . md5( $tag_name );
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) return $cached;

    $body = fc_updater_github_get( '/releases/tags/' . rawurlencode( $tag_name ) );
    $info = [
        'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
        'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
    ];
    set_transient( $key, $info, 12 * HOUR_IN_SECONDS );
    return $info;
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
                'tag'       => (string) $tag['name'],
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

/** True only for downloads from this plugin's own GitHub repository. */
function fc_updater_trusted_url( $url ) {
    $parts = wp_parse_url( (string) $url );
    if ( ( $parts['scheme'] ?? '' ) !== 'https' ) return false;

    $repo = '/' . strtolower( fc_updater_repo() ) . '/';
    $path = strtolower( $parts['path'] ?? '' );
    switch ( strtolower( $parts['host'] ?? '' ) ) {
        case 'github.com':
        case 'codeload.github.com':
            return strpos( $path, $repo ) === 0;
        case 'api.github.com':
            return strpos( $path, '/repos' . $repo ) === 0;
    }
    return false;
}

// A last check just before WordPress downloads our update.
add_filter( 'upgrader_pre_download', 'fc_updater_check_download', 10, 4 );

function fc_updater_check_download( $reply, $package, $upgrader, $hook_extra = [] ) {
    if ( ( $hook_extra['plugin'] ?? '' ) !== fc_updater_basename() ) return $reply;
    if ( fc_updater_trusted_url( $package ) ) return $reply;
    return new WP_Error( 'fc_untrusted_package', 'This update did not come from the FactCrescendo GitHub repository, so it was not installed.' );
}

// Updates are never installed on their own: an admin clicks Update.
// That gives a staged rollout, and a bad release can't spread by itself.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
    return ( isset( $item->plugin ) && $item->plugin === fc_updater_basename() ) ? false : $update;
}, 20, 2 );


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

    // The installed version as read from disk. During an update FC_PUBLISHER_VERSION still
    // holds the old number, which would announce the version just installed.
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

    // In one list only: a leftover entry would keep "update available" showing after updating.
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

    // Notes only exist when a release was published for this tag.
    $info = fc_updater_release_info( $latest['tag'] ?? 'v' . $latest['version'] );
    if ( $latest['notes'] === '' ) $latest['notes'] = $info['notes'];
    if ( $latest['published'] === '' ) $latest['published'] = $info['published'];

    return (object) [
        'name'          => 'FactCrescendo Publisher',
        'slug'          => fc_updater_slug(),
        'version'       => $latest['version'],
        'author'        => '<a href="https://factcrescendo.com">FactCrescendo</a>',
        'homepage'      => $latest['url'],
        'download_link' => $latest['zip'],
        'last_updated'  => $latest['published'],
        'sections'      => [
            'description' => 'Fact-check publishing for FactCrescendo sites: fact-check fields, fact card, author box, WhatsApp banner, Google fact-check data, Google Doc import, embeds and WebP photos.',
            'changelog'   => fc_updater_format_notes( $latest['notes'] ),
        ],
    ];
}

/** Release notes come from GitHub: cleaned before display. */
function fc_updater_format_notes( $notes ) {

    $notes = trim( (string) $notes );

    if ( $notes === '' ) return 'No release notes were provided for this version.';

    return wpautop( wp_kses_post( $notes ) );
}


// ── Installing the update ────────────────────────────────────────────────────

add_filter( 'upgrader_source_selection', 'fc_updater_fix_folder_name', 10, 4 );

/**
 * GitHub's zip unpacks to "lemoonrick-factcrescendo-publisher-a1b2c3d". Renamed to the
 * plugin's folder, or the update would install as a second, inactive copy.
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

/** Clears the cached answers once this plugin has been updated. */
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

// A "Settings" link first, under the plugin's name on the Plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( FC_PUBLISHER_FILE ), function ( $links ) {
    if ( ! current_user_can( 'manage_options' ) ) return $links;
    $settings = '<a href="' . esc_url( admin_url( 'options-general.php?page=fc-publisher-settings' ) ) . '">Settings</a>';
    return array_merge( [ 'settings' => $settings ], $links );
}, 5 );

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
