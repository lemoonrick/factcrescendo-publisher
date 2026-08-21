<?php
/**
 * AI Narration (ElevenLabs)
 *
 * Generates a premium MP3 voiceover for a fact-check post when the
 * "Generate Audio Narration" toggle is on, storing the file in the
 * standard media library and the URL in post meta.
 *
 * Security notes:
 * - The API key lives only in a WP option, never in post meta, never
 *   printed to any front-end page source, and is not REST-exposed.
 * - Voice ID is stripped to alphanumeric characters before it is placed
 *   into the request URL, closing off any URL/header injection route.
 * - Narration text length is hard-capped server-side regardless of
 *   settings, so a runaway article can't trigger an oversized, costly
 *   request.
 * - Generation only fires for users who can already edit the post —
 *   it rides on ACF's own save flow, which is nonce-protected.
 * - Regeneration is skipped unless the narrated content actually
 *   changed, so routine saves don't burn API quota.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'acf/save_post', 'fc_maybe_generate_audio', 20 );

function fc_maybe_generate_audio( $post_id ) {

    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( get_post_type( $post_id ) !== 'post' ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( ! function_exists( 'get_field' ) ) return;

    $is_fc       = (bool) get_field( 'fc_is_fact_check', $post_id );
    $wants_audio = (bool) get_field( 'fc_generate_audio', $post_id );

    if ( ! $is_fc || ! $wants_audio ) return;

    $api_key  = get_option( 'fc_elevenlabs_api_key', '' );
    $voice_id = get_option( 'fc_elevenlabs_voice_id', '21m00Tcm4TlvDq8ikWAM' ); // default: "Rachel"

    if ( empty( $api_key ) ) {
        update_post_meta( $post_id, 'fc_audio_status', 'error_no_key' );
        fc_set_admin_notice( 'Audio narration skipped — add an ElevenLabs API key under Settings > FC Publisher.', 'warning' );
        return;
    }

    $text = fc_build_narration_text( $post_id );
    if ( empty( trim( $text ) ) ) return;

    $source_hash   = md5( $text );
    $existing_hash = get_post_meta( $post_id, 'fc_audio_source_hash', true );

    // Nothing changed since the last successful generation — skip the API call.
    if ( $source_hash === $existing_hash && get_post_meta( $post_id, 'fc_audio_url', true ) ) {
        return;
    }

    update_post_meta( $post_id, 'fc_audio_status', 'generating' );

    $audio_bytes = fc_call_elevenlabs_api( $text, $voice_id, $api_key );

    if ( is_wp_error( $audio_bytes ) ) {
        update_post_meta( $post_id, 'fc_audio_status', 'error' );
        fc_set_admin_notice( 'Audio generation failed: ' . $audio_bytes->get_error_message(), 'error' );
        return;
    }

    if ( ! function_exists( 'wp_upload_bits' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! function_exists( 'wp_insert_attachment' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $filename = 'fc-audio-' . $post_id . '-' . time() . '.mp3';
    $upload   = wp_upload_bits( $filename, null, $audio_bytes );

    if ( ! empty( $upload['error'] ) ) {
        update_post_meta( $post_id, 'fc_audio_status', 'error' );
        fc_set_admin_notice( 'Audio file could not be saved: ' . $upload['error'], 'error' );
        return;
    }

    // Remove the previous audio file so we don't accumulate orphaned media.
    $old_attachment_id = get_post_meta( $post_id, 'fc_audio_attachment_id', true );
    if ( $old_attachment_id && get_post( $old_attachment_id ) ) {
        wp_delete_attachment( $old_attachment_id, true );
    }

    $filetype      = wp_check_filetype( $upload['file'], null );
    $attachment_id = wp_insert_attachment( [
        'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'audio/mpeg',
        'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ], $upload['file'], $post_id );

    update_post_meta( $post_id, 'fc_audio_url', esc_url_raw( $upload['url'] ) );
    update_post_meta( $post_id, 'fc_audio_attachment_id', $attachment_id );
    update_post_meta( $post_id, 'fc_audio_source_hash', $source_hash );
    update_post_meta( $post_id, 'fc_audio_generated_at', current_time( 'mysql' ) );
    update_post_meta( $post_id, 'fc_audio_status', 'ready' );

    fc_set_admin_notice( 'Audio narration generated successfully.', 'success' );
}


/**
 * Builds the plain-text script sent to the TTS engine.
 * Leads with title, claim, and verdict, then the article body —
 * capped so cost and request size stay predictable.
 */
function fc_build_narration_text( $post_id ) {
    $title  = get_the_title( $post_id );
    $claim  = get_field( 'fc_claim', $post_id );
    $fact   = get_field( 'fc_fact', $post_id );
    $rating = fc_get_rating_label( get_field( 'fc_rating', $post_id ) );

    $parts   = [];
    $parts[] = $title . '.';
    if ( $claim )  $parts[] = 'The claim under review: ' . $claim;
    if ( $rating ) $parts[] = 'Our verdict: ' . $rating . '.';
    if ( $fact )   $parts[] = 'Here is what we found. ' . $fact;

    $post = get_post( $post_id );
    $body = $post ? wp_strip_all_tags( $post->post_content ) : '';
    $body = preg_replace( '/\s+/', ' ', $body );

    $text = trim( implode( ' ', $parts ) . ' ' . $body );

    // Hard cap regardless of settings — keeps every request bounded.
    $max_chars = apply_filters( 'fc_audio_max_chars', 4500 );
    if ( mb_strlen( $text ) > $max_chars ) {
        $text = mb_substr( $text, 0, $max_chars );
    }

    return $text;
}


/**
 * Calls ElevenLabs' text-to-speech endpoint. Returns raw MP3 bytes
 * on success, or a WP_Error describing what went wrong.
 */
function fc_call_elevenlabs_api( $text, $voice_id, $api_key ) {

    // Strip to alphanumeric only — voice_id is settings-supplied but this
    // closes off any possibility of it altering the request URL/headers.
    $voice_id = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $voice_id );
    if ( empty( $voice_id ) ) {
        return new WP_Error( 'fc_bad_voice', 'No valid voice ID configured in FC Publisher settings.' );
    }

    $response = wp_remote_post( 'https://api.elevenlabs.io/v1/text-to-speech/' . $voice_id, [
        'timeout' => 60,
        'headers' => [
            'xi-api-key'   => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'audio/mpeg',
        ],
        'body' => wp_json_encode( [
            'text'           => $text,
            'model_id'       => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability'        => 0.5,
                'similarity_boost' => 0.75,
            ],
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        $body    = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );
        $detail  = isset( $decoded['detail']['message'] ) ? sanitize_text_field( $decoded['detail']['message'] ) : '';
        return new WP_Error( 'fc_api_error', trim( 'ElevenLabs returned HTTP ' . $code . '. ' . $detail ) );
    }

    return wp_remote_retrieve_body( $response );
}


/**
 * Lightweight per-user admin notice, shown once on the next page load.
 * Used instead of inline output so it survives the save_post redirect.
 */
function fc_set_admin_notice( $message, $type = 'info' ) {
    set_transient( 'fc_admin_notice_' . get_current_user_id(), [
        'message' => $message,
        'type'    => $type,
    ], 45 );
}

add_action( 'admin_notices', 'fc_render_admin_notice' );
function fc_render_admin_notice() {
    $key    = 'fc_admin_notice_' . get_current_user_id();
    $notice = get_transient( $key );
    if ( ! $notice ) return;
    delete_transient( $key );

    $class_map = [
        'success' => 'notice-success',
        'warning' => 'notice-warning',
        'error'   => 'notice-error',
        'info'    => 'notice-info',
    ];
    $class = $class_map[ $notice['type'] ] ?? 'notice-info';

    echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
}
