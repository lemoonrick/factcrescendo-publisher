<?php
/**
 * Stamps the featured image (1280 × 768).
 *
 * The editor sets a plain photo as the featured image. When the post is
 * saved, the plugin makes a stamped copy and sets that as the featured
 * image instead:
 *   fact-checked – the round "FACT CHECKED" stamp (most ratings)
 *   insight      – the "INSIGHT" badge (Insight articles)
 *   none         – left as it is (News, or the editor's own Canva design)
 *
 * The plain photo is kept, so the fact card can use it and a stamp never
 * appears twice. Changing the stamp, or picking a new photo, makes a new
 * copy and deletes the old one. Only copies this plugin made are deleted.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const FC_FEATURED_W = 1280;
const FC_FEATURED_H = 768;

/** True when the site has stamping switched on. */
function fc_featured_enabled() {
    return get_option( 'fc_featured_stamps', '1' ) === '1';
}

/** True when the server can draw images (PHP's GD extension). */
function fc_featured_can_draw() {
    return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecreatefrompng' ) && function_exists( 'imagejpeg' );
}

/** Which corner the stamp goes in. */
function fc_featured_corner() {
    $corner = (string) get_option( 'fc_featured_corner', 'bottom-left' );
    return in_array( $corner, [ 'top-left', 'top-right', 'bottom-left', 'bottom-right' ], true ) ? $corner : 'bottom-left';
}

/** Stamp picture files, by name. */
function fc_featured_stamp_files() {
    $dir = FC_PUBLISHER_DIR . 'assets/featured/';

    /** Filter: use your own stamp pictures (transparent PNG files on the server). */
    return apply_filters( 'fc_featured_stamp_files', [
        'fact-checked' => $dir . 'fact-checked.png',
        'insight'      => $dir . 'insight.png',
    ] );
}

/**
 * The stamp this post should get: 'fact-checked', 'insight' or 'none'.
 * An editor's choice on the post wins; otherwise it follows the rating.
 */
function fc_featured_badge( $post ) {
    $post   = get_post( $post );
    $choice = (string) fc_meta( 'fc_featured_badge', $post->ID );

    if ( $choice === 'none' ) return 'none';

    $files = fc_featured_stamp_files();
    if ( $choice !== '' ) return isset( $files[ $choice ] ) ? $choice : 'none';

    // Automatic. Older published posts are left alone: their featured
    // images were made in Canva and usually carry a stamp already.
    if ( ! fc_is_fact_check( $post->ID ) || fc_featured_is_older_post( $post ) ) return 'none';

    $rating = (string) fc_meta( 'fc_rating', $post->ID );
    if ( $rating === '' || $rating === 'news' ) return 'none';
    return $rating === 'insight' ? 'insight' : 'fact-checked';
}

/** True for posts published before featured-image stamping was added. */
function fc_featured_is_older_post( $post ) {
    $since = (int) get_option( 'fc_featured_since', 0 );
    if ( ! $since || $post->post_date_gmt === '0000-00-00 00:00:00' ) return false;
    return strtotime( $post->post_date_gmt . ' UTC' ) < $since;
}

// Remember when stamping arrived, for the rule above.
add_action( 'init', function () {
    if ( ! get_option( 'fc_featured_since' ) ) update_option( 'fc_featured_since', time(), false );
} );


// ── When a post is saved ─────────────────────────────────────────────────────

// Block editor: runs after WordPress has saved the fields and featured image.
add_action( 'rest_after_insert_post', function ( $post ) {
    fc_featured_sync( $post->ID );
} );

// Classic editor: the featured image is already saved by now.
add_action( 'save_post_post', function ( $post_id ) {
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return; // handled above
    fc_featured_sync( $post_id );
}, 20 );

/** Makes the featured image match the stamp the post should have. */
function fc_featured_sync( $post_id ) {
    if ( ! fc_featured_enabled() ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // One run per post at a time. Two saves arriving together (the editor
    // can send two) would otherwise each make a copy.
    if ( ! fc_featured_lock( $post_id ) ) return;
    try {
        fc_featured_sync_locked( $post_id );
    } finally {
        delete_option( 'fc_lock_' . (int) $post_id );
    }
}

/** True when this run may go ahead. A lock older than two minutes is stale and ignored. */
function fc_featured_lock( $post_id ) {
    $key = 'fc_lock_' . (int) $post_id;
    if ( add_option( $key, time(), '', 'no' ) ) return true;
    if ( time() - (int) get_option( $key ) < 120 ) return false;
    update_option( $key, time(), false );
    return true;
}

function fc_featured_sync_locked( $post_id ) {
    $post  = get_post( $post_id );
    $thumb = fc_featured_id( $post_id );
    $made  = (int) get_post_meta( $post_id, '_fc_featured_made', true );

    // Featured image removed: our copy is no longer needed.
    if ( ! $thumb ) {
        fc_featured_forget( $post_id );
        return;
    }

    // The plain photo. If the featured image is a stamped copy (this post's or
    // another's), use the photo it was made from, never stamp a stamp.
    $source = (int) get_post_meta( $thumb, '_fc_source_id', true ) ?: $thumb;
    if ( ! wp_attachment_is_image( $source ) ) return;

    // A new photo was picked: the old copy goes.
    if ( $made && $thumb !== $made ) {
        fc_featured_delete_copy( $made, $post_id );
        delete_post_meta( $post_id, '_fc_featured_made' );
        delete_post_meta( $post_id, '_fc_featured_key' );
        $made = 0;
    }

    $badge = fc_featured_badge( $post );

    // No stamp wanted: put the plain photo back.
    if ( $badge === 'none' ) {
        if ( $thumb !== $source ) set_post_thumbnail( $post_id, $source );
        fc_featured_forget( $post_id );
        return;
    }

    $files = fc_featured_stamp_files();
    // Changes when the stamp, corner, photo or stamp artwork changes.
    $key   = implode( '|', [ $badge, fc_featured_corner(), $source, md5_file( $files[ $badge ] ) ] );

    // Already up to date.
    if ( $made && $thumb === $made && get_post_meta( $post_id, '_fc_featured_key', true ) === $key ) {
        fc_featured_tidy( $post_id, $made );
        return;
    }

    if ( ! fc_featured_can_draw() || ! current_user_can( 'upload_files' ) ) return;

    $new = fc_featured_make( $post_id, $source, $files[ $badge ], $badge );
    if ( ! $new ) return; // left as it was; nothing is lost

    set_post_thumbnail( $post_id, $new );
    update_post_meta( $post_id, '_fc_featured_made', $new );
    update_post_meta( $post_id, '_fc_featured_source', $source );
    update_post_meta( $post_id, '_fc_featured_key', $key );

    fc_featured_tidy( $post_id, $new ); // removes the old copy, and any strays
}

/** Keeps exactly one stamped copy for the post: deletes every other copy this plugin made for it. */
function fc_featured_tidy( $post_id, $keep ) {
    $copies = get_posts( [
        'post_type'        => 'attachment',
        'post_status'      => 'any',
        'numberposts'      => 20,
        'fields'           => 'ids',
        'meta_key'         => '_fc_generated_for',
        'meta_value'       => (int) $post_id,
        'suppress_filters' => true,
    ] );
    foreach ( $copies as $id ) {
        if ( (int) $id !== (int) $keep ) fc_featured_delete_copy( $id, $post_id );
    }
}

/** Deletes this post's stamped copy and the notes about it. */
function fc_featured_forget( $post_id ) {
    $thumb = fc_featured_id( $post_id );
    $made  = (int) get_post_meta( $post_id, '_fc_featured_made', true );
    if ( $made && $made !== $thumb ) fc_featured_delete_copy( $made, $post_id );
    fc_featured_tidy( $post_id, $thumb ); // strays too; never the current featured image
    delete_post_meta( $post_id, '_fc_featured_made' );
    delete_post_meta( $post_id, '_fc_featured_source' );
    delete_post_meta( $post_id, '_fc_featured_key' );
}

/** Deletes a stamped copy, but only one this plugin made for this post. */
function fc_featured_delete_copy( $attachment_id, $post_id ) {
    if ( (int) get_post_meta( $attachment_id, '_fc_generated_for', true ) !== (int) $post_id ) return;
    wp_delete_attachment( $attachment_id, true );
}

// Deleting a post also deletes its stamped copy.
add_action( 'before_delete_post', function ( $post_id ) {
    fc_featured_tidy( $post_id, 0 );
} );


// ── Keeping the Media Library tidy ───────────────────────────────────────────
// Stamped copies are the plugin's own work, not uploads. They are left out of
// the Media Library lists so editors see only their own pictures — one per
// article. They still work as featured images. To see them, return false:
//   add_filter( 'fc_hide_generated_copies', '__return_false' );

/** Adds "not made by this plugin" to a media query. */
function fc_hide_generated( $args ) {
    if ( ! apply_filters( 'fc_hide_generated_copies', true ) ) return $args;
    $args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : [];
    $args['meta_query'][] = [ 'key' => '_fc_generated_for', 'compare' => 'NOT EXISTS' ];
    return $args;
}
add_filter( 'ajax_query_attachments_args', 'fc_hide_generated' ); // the picture chooser
add_filter( 'rest_attachment_query', 'fc_hide_generated' );       // the editor's media lists

// The Media Library page's own list.
add_action( 'pre_get_posts', function ( $query ) {
    global $pagenow;
    if ( ! is_admin() || $pagenow !== 'upload.php' || ! $query->is_main_query() ) return;
    $args = fc_hide_generated( [ 'meta_query' => (array) $query->get( 'meta_query' ) ] );
    $query->set( 'meta_query', $args['meta_query'] );
} );


// ── Drawing ──────────────────────────────────────────────────────────────────

/**
 * Makes the stamped copy and adds it to the media library.
 * Returns its ID, or 0 if anything went wrong.
 */
function fc_featured_make( $post_id, $source_id, $stamp_file, $badge ) {
    $file = get_attached_file( $source_id );
    if ( ! $file || ! is_readable( $file ) || ! is_readable( $stamp_file ) ) return 0;

    $photo = fc_featured_load( $file );
    $stamp = @imagecreatefrompng( $stamp_file );
    if ( ! $photo || ! $stamp ) return 0;

    // Fill 1280 × 768, cropping evenly from the sides or top and bottom.
    $sw    = imagesx( $photo );
    $sh    = imagesy( $photo );
    $scale = max( FC_FEATURED_W / $sw, FC_FEATURED_H / $sh );
    $cw    = (int) round( FC_FEATURED_W / $scale );
    $ch    = (int) round( FC_FEATURED_H / $scale );

    $canvas = imagecreatetruecolor( FC_FEATURED_W, FC_FEATURED_H );
    imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
    imagecopyresampled( $canvas, $photo, 0, 0, (int) ( ( $sw - $cw ) / 2 ), (int) ( ( $sh - $ch ) / 2 ), FC_FEATURED_W, FC_FEATURED_H, $cw, $ch );

    // The stamp, in the chosen corner, set in as far as the Canva designs.
    $w      = imagesx( $stamp );
    $h      = imagesy( $stamp );
    $corner = fc_featured_corner();
    $x      = strpos( $corner, 'left' ) !== false ? 104 : FC_FEATURED_W - $w - 104;
    $y      = strpos( $corner, 'top' ) !== false ? 128 : FC_FEATURED_H - $h - 128;
    imagealphablending( $canvas, true );
    imagecopy( $canvas, $stamp, $x, $y, 0, 0, $w, $h );

    // Saved next to this month's uploads.
    $uploads = wp_upload_dir();
    if ( ! empty( $uploads['error'] ) ) return 0;

    // WebP like every other photo on the site, when the server can draw it.
    $webp = fc_webp_enabled() && fc_webp_gd();
    $ext  = $webp ? '.webp' : '.jpg';
    $name = sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) . '-' . $badge . $ext );
    $name = wp_unique_filename( $uploads['path'], $name );
    $path = trailingslashit( $uploads['path'] ) . $name;

    $ok = $webp ? imagewebp( $canvas, $path, max( 82, fc_webp_quality() ) ) : imagejpeg( $canvas, $path, 90 );
    imagedestroy( $canvas );
    imagedestroy( $photo );
    imagedestroy( $stamp );
    if ( ! $ok ) return 0;

    $id = wp_insert_attachment( [
        'post_mime_type' => $webp ? 'image/webp' : 'image/jpeg',
        'post_title'     => get_the_title( $source_id ),
        'post_status'    => 'inherit',
    ], $path, $post_id, true );

    if ( is_wp_error( $id ) || ! $id ) {
        wp_delete_file( $path );
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

    $alt = (string) get_post_meta( $source_id, '_wp_attachment_image_alt', true );
    if ( $alt !== '' ) update_post_meta( $id, '_wp_attachment_image_alt', $alt );

    // Marks it as ours, and remembers the plain photo it came from.
    update_post_meta( $id, '_fc_generated_for', $post_id );
    update_post_meta( $id, '_fc_source_id', $source_id );

    return (int) $id;
}

/** Opens a photo for drawing. Goes through WordPress so WebP etc. work too. */
function fc_featured_load( $file ) {
    $editor = wp_get_image_editor( $file );
    if ( is_wp_error( $editor ) ) return null;

    // Shrink big photos first; small ones are enlarged later.
    $editor->resize( FC_FEATURED_W, FC_FEATURED_H, true );

    $tmp   = trailingslashit( get_temp_dir() ) . 'fc-featured-' . wp_generate_password( 12, false ) . '.png';
    $saved = $editor->save( $tmp, 'image/png' );
    if ( is_wp_error( $saved ) ) return null;

    $img = @imagecreatefrompng( $saved['path'] );
    @unlink( $saved['path'] );
    return $img ?: null;
}
