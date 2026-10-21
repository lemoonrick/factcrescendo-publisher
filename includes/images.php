<?php
/**
 * Photos are saved as WebP.
 *
 * Every JPEG or PNG added to the media library — pasted from a Google Doc,
 * dragged in, or uploaded in the Media screen — is turned into a WebP file
 * before WordPress stores it, so pages load faster and the whole site serves
 * the same modern format. Free: it uses the image tools already on the
 * server (PHP's GD or Imagick), no outside service.
 *
 * Left alone: GIFs (they may be animated), SVGs, files that are already
 * WebP, animated PNGs, and anything too large to convert safely. If the
 * server can't make WebP, or anything goes wrong, the original is kept and
 * the upload carries on as if this didn't exist.
 *
 * Only new uploads are touched. Pictures already in the media library, and
 * every article that uses them, stay exactly as they are.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** True when the site has WebP conversion switched on. */
function fc_webp_enabled() {
    return get_option( 'fc_webp_uploads', '1' ) === '1';
}

/** How sharp the WebP is, 40 (smallest) to 100. */
function fc_webp_quality() {
    return max( 40, min( 100, (int) get_option( 'fc_webp_quality', 82 ) ) );
}

/** True when PHP's own drawing tools (GD) can write WebP, as the featured-image stamping needs. */
function fc_webp_gd() {
    return function_exists( 'imagewebp' ) && function_exists( 'gd_info' ) && ! empty( gd_info()['WebP Support'] );
}

/** True when this server can make WebP files. */
function fc_webp_can() {
    return wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
}

add_filter( 'wp_handle_upload', 'fc_webp_on_upload', 10, 1 );

/*
 * WordPress picks the quality from the picture's ORIGINAL type (JPEG), so
 * without this the quality chosen in Settings would be ignored. This makes
 * every WebP WordPress writes — the converted photo and its smaller sizes —
 * use it.
 */
add_filter( 'wp_editor_set_quality', function ( $quality, $mime ) {
    if ( ! fc_webp_enabled() ) return $quality;
    return ( $mime === 'image/webp' || ! empty( $GLOBALS['fc_webp_converting'] ) ) ? fc_webp_quality() : $quality;
}, 10, 2 );

/**
 * Runs right after a file is uploaded and before WordPress registers it, so
 * everything that follows (sizes, the media library, the article) sees the
 * WebP file, never the original.
 */
function fc_webp_on_upload( $upload ) {
    if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) return $upload;
    if ( ! in_array( $upload['type'] ?? '', [ 'image/jpeg', 'image/png' ], true ) ) return $upload;
    if ( ! fc_webp_enabled() || ! fc_webp_can() ) return $upload;

    ob_start(); // Nothing printed here may reach the upload's answer.
    try {
        $new = fc_webp_convert( (string) $upload['file'], (string) $upload['type'] );
    } catch ( \Throwable $e ) {
        $new = '';
    }
    ob_end_clean();
    if ( $new === '' ) return $upload;

    wp_delete_file( $upload['file'] );
    $upload['file'] = $new;
    $upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . rawurlencode( basename( $new ) );
    $upload['type'] = 'image/webp';
    return $upload;
}

/** Makes the WebP copy next to the original. Returns its path, or '' to keep the original. */
function fc_webp_convert( $file, $type ) {
    if ( ! is_readable( $file ) ) return '';

    $info = @getimagesize( $file );
    if ( ! $info || $info[0] < 1 || $info[1] < 1 ) return '';
    if ( $info[0] * $info[1] > 40000000 || filesize( $file ) > 30 * MB_IN_BYTES ) return ''; // too big to convert safely

    // An animated PNG would lose its animation.
    if ( $type === 'image/png' && strpos( (string) file_get_contents( $file, false, null, 0, 4096 ), 'acTL' ) !== false ) return '';

    wp_raise_memory_limit( 'image' );
    $editor = wp_get_image_editor( $file );
    if ( is_wp_error( $editor ) ) return '';

    // Photos from phones are often stored sideways with a note saying how to turn them.
    if ( method_exists( $editor, 'maybe_exif_rotate' ) ) $editor->maybe_exif_rotate();

    $GLOBALS['fc_webp_converting'] = true;
    $editor->set_quality( fc_webp_quality() );

    $dir  = dirname( $file );
    $name = wp_unique_filename( $dir, pathinfo( $file, PATHINFO_FILENAME ) . '.webp' );
    $saved = $editor->save( trailingslashit( $dir ) . $name, 'image/webp' );
    unset( $GLOBALS['fc_webp_converting'] );
    if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_readable( $saved['path'] ) || filesize( $saved['path'] ) < 1 ) {
        if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) wp_delete_file( $saved['path'] );
        return '';
    }
    return $saved['path'];
}
