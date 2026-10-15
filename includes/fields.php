<?php
/**
 * Fact-check fields — built into the plugin, no ACF needed.
 *
 * In the block editor the fields are a "Fact Check" panel in the Post
 * sidebar (assets/editor-panel.js). In the classic editor they are a box
 * under the post. Either way they save to the same names ACF used
 * (fc_rating, fc_claim and so on), so posts written while ACF was in charge
 * are read back unchanged, nothing has to be migrated, and ACF can be
 * switched off or left on.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── What the fields are ──────────────────────────────────────────────────────

/** The ratings an editor can pick, in the order the dropdown shows them. */
function fc_rating_choices() {
    return [
        'false'           => 'False',
        'partly-false'    => 'Partly False',
        'misleading'      => 'Misleading',
        'missing-context' => 'Missing Context',
        'satire'          => 'Satire',
        'altered'         => 'Altered',
        'insight'         => 'Insight',
        'news'            => 'News',
    ];
}

/**
 * Every field the editor fills in, with how it is stored and cleaned.
 *
 * fc_is_fact_check is stored as '1' or '0', exactly as ACF stored it.
 */
function fc_field_definitions() {
    return [
        'fc_is_fact_check'     => [ 'type' => 'boolean', 'sanitize' => 'fc_sanitize_flag' ],
        'fc_rating'            => [ 'type' => 'string',  'sanitize' => 'fc_sanitize_rating' ],
        'fc_claim'             => [ 'type' => 'string',  'sanitize' => 'sanitize_textarea_field' ],
        'fc_fact'              => [ 'type' => 'string',  'sanitize' => 'sanitize_textarea_field' ],
        'fc_claim_source_url'  => [ 'type' => 'string',  'sanitize' => 'fc_sanitize_url' ],
        'fc_claim_author'      => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field' ],
        'fc_claim_author_type' => [ 'type' => 'string',  'sanitize' => 'fc_sanitize_author_type' ],
    ];
}

/** Web links only. A wrapper because WordPress passes extra arguments. */
function fc_sanitize_url( $value ) {
    return esc_url_raw( trim( (string) $value ), [ 'http', 'https' ] );
}

function fc_sanitize_flag( $value ) {
    return ( $value === true || $value === 1 || $value === '1' || $value === 'true' ) ? '1' : '0';
}

/** Anything outside the dropdown's list is dropped rather than stored. */
function fc_sanitize_rating( $value ) {
    $value = strtolower( trim( (string) $value ) );
    return array_key_exists( $value, fc_rating_choices() ) ? $value : '';
}

function fc_sanitize_author_type( $value ) {
    return $value === 'Organization' ? 'Organization' : 'Person';
}

/**
 * Reads one fact-check field for a post.
 *
 * The one place the rest of the plugin reads these values from. Plain post
 * meta, so it works the same whether a post was saved by this plugin or by
 * the older ACF version.
 */
function fc_meta( $key, $post_id ) {
    return get_post_meta( $post_id, $key, true );
}

/** True when the post has been marked as a fact check. */
function fc_is_fact_check( $post_id ) {
    return fc_meta( 'fc_is_fact_check', $post_id ) === '1';
}


// ── Registration (also makes the fields visible in the REST API) ─────────────

add_action( 'init', 'fc_register_fields' );

function fc_register_fields() {

    $can_edit = function( $allowed, $meta_key, $post_id ) {
        return current_user_can( 'edit_post', $post_id );
    };

    foreach ( fc_field_definitions() as $key => $def ) {
        register_post_meta( 'post', $key, [
            'type'              => $def['type'],
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => $def['sanitize'],
            'auth_callback'     => $can_edit,
        ] );
    }

    // Written by the narration feature, read-only from the editor's side.
    foreach ( [ 'fc_audio_url', 'fc_audio_status' ] as $key ) {
        register_post_meta( 'post', $key, [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => $can_edit,
        ] );
    }
}


// ── Classic editor: the box under the post ───────────────────────────────────

add_action( 'add_meta_boxes_post', 'fc_add_fields_box' );

function fc_add_fields_box() {
    // __back_compat_meta_box keeps it out of the block editor, which has the
    // sidebar panel instead. Without it the block editor would tuck this box
    // into a collapsed drawer at the bottom, and show the fields twice.
    add_meta_box( 'fc-fact-check', 'Fact Check Details', 'fc_render_fields_box', 'post', 'normal', 'high', [
        '__back_compat_meta_box' => true,
    ] );
}

function fc_render_fields_box( $post ) {
    $is_fc       = fc_is_fact_check( $post->ID );
    $rating      = (string) fc_meta( 'fc_rating', $post->ID );
    $claim       = (string) fc_meta( 'fc_claim', $post->ID );
    $fact        = (string) fc_meta( 'fc_fact', $post->ID );
    $source_url  = (string) fc_meta( 'fc_claim_source_url', $post->ID );
    $author      = (string) fc_meta( 'fc_claim_author', $post->ID );
    $author_type = (string) fc_meta( 'fc_claim_author_type', $post->ID );

    wp_nonce_field( 'fc_save_fields', 'fc_fields_nonce' );
    ?>
    <style>
    #fc-fact-check .fc-f { margin: 0 0 16px; }
    #fc-fact-check .fc-f:last-child { margin-bottom: 0; }
    #fc-fact-check label.fc-l { display: block; font-weight: 600; margin: 0 0 6px; }
    #fc-fact-check .fc-h { color: #646970; font-size: 12px; margin: 4px 0 0; }
    #fc-fact-check textarea, #fc-fact-check input[type=text], #fc-fact-check input[type=url], #fc-fact-check select { width: 100%; max-width: 100%; }
    #fc-fact-check .fc-switch { display: flex; align-items: center; gap: 8px; font-weight: 600; }
    #fc-fact-check .fc-missing { background: #fcf0f1; border-left: 4px solid #d63638; padding: 8px 12px; margin: 0 0 16px; }
    #fc-fact-check details { border-top: 1px solid #dcdcde; padding-top: 12px; }
    #fc-fact-check summary { cursor: pointer; font-weight: 600; margin-bottom: 12px; }
    #fc-fact-check [hidden] { display: none !important; }
    </style>

    <div class="fc-f">
        <label class="fc-switch">
            <input type="checkbox" id="fc_is_fact_check" name="fc_is_fact_check" value="1" <?php checked( $is_fc ); ?>>
            Mark as Fact Check
        </label>
        <p class="fc-h">Adds the fact card, author box, WhatsApp banner and Google fact-check data to this article.</p>
    </div>

    <div id="fc-fields" <?php echo $is_fc ? '' : 'hidden'; ?>>

        <p class="fc-missing" id="fc-missing" hidden></p>

        <div class="fc-f">
            <label class="fc-l" for="fc_rating">Rating</label>
            <select id="fc_rating" name="fc_rating">
                <option value="">Select a rating…</option>
                <?php foreach ( fc_rating_choices() as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rating, $value ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fc-f">
            <label class="fc-l" for="fc_claim">Claim</label>
            <textarea id="fc_claim" name="fc_claim" rows="3" placeholder="What is the claim being fact-checked?"><?php echo esc_textarea( $claim ); ?></textarea>
        </div>

        <div class="fc-f">
            <label class="fc-l" for="fc_fact">Fact</label>
            <textarea id="fc_fact" name="fc_fact" rows="3" placeholder="What is the verified fact / correction?"><?php echo esc_textarea( $fact ); ?></textarea>
        </div>

        <details <?php echo ( $source_url !== '' || $author !== '' ) ? 'open' : ''; ?>>
            <summary>Where the claim came from (optional, for Google)</summary>

            <div class="fc-f">
                <label class="fc-l" for="fc_claim_source_url">Where the claim appeared (link)</label>
                <input type="url" id="fc_claim_source_url" name="fc_claim_source_url" value="<?php echo esc_attr( $source_url ); ?>" placeholder="https://...">
                <p class="fc-h">The post, video or article where the claim was made.</p>
            </div>

            <div class="fc-f">
                <label class="fc-l" for="fc_claim_author">Who made the claim</label>
                <input type="text" id="fc_claim_author" name="fc_claim_author" value="<?php echo esc_attr( $author ); ?>" placeholder="e.g. Viral WhatsApp forward, @username, a news channel">
            </div>

            <div class="fc-f">
                <label class="fc-l" for="fc_claim_author_type">That source is a</label>
                <select id="fc_claim_author_type" name="fc_claim_author_type">
                    <option value="Person" <?php selected( $author_type !== 'Organization' ); ?>>Person or social media account</option>
                    <option value="Organization" <?php selected( $author_type, 'Organization' ); ?>>Organisation, channel or publication</option>
                </select>
            </div>
        </details>
    </div>

    <script>
    (function () {
        var toggle = document.getElementById('fc_is_fact_check');
        var fields = document.getElementById('fc-fields');
        var note   = document.getElementById('fc-missing');
        if (!toggle || !fields || !note) return;

        function check() {
            fields.hidden = !toggle.checked;
            var missing = [];
            if (!document.getElementById('fc_rating').value) missing.push('rating');
            if (!document.getElementById('fc_claim').value.trim()) missing.push('claim');
            if (!document.getElementById('fc_fact').value.trim()) missing.push('fact');
            note.hidden = !toggle.checked || missing.length === 0;
            note.textContent = 'Still needed: ' + missing.join(', ') + '. Without a rating, nothing is added to the article.';
        }

        toggle.addEventListener('change', check);
        fields.addEventListener('input', check);
        fields.addEventListener('change', check);
        check();
    })();
    </script>
    <?php
}


// ── Saving ───────────────────────────────────────────────────────────────────

add_action( 'save_post_post', 'fc_save_fields', 10, 2 );

function fc_save_fields( $post_id, $post ) {

    // Only when our box was on the screen that was submitted. Saves from the
    // REST API, quick edit or imports don't carry the nonce and are left alone,
    // so they can never blank a field by accident.
    if ( ! isset( $_POST['fc_fields_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fc_fields_nonce'] ) ), 'fc_save_fields' ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    foreach ( fc_field_definitions() as $key => $def ) {

        // An unticked checkbox sends nothing at all, which means "off".
        $raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
        if ( is_array( $raw ) ) $raw = '';

        $value = call_user_func( $def['sanitize'], $raw );

        if ( $value === '' ) {
            delete_post_meta( $post_id, $key );
        } else {
            // update_post_meta() strips one level of slashes, so add one back
            // or a backslash typed into a claim would quietly disappear.
            update_post_meta( $post_id, $key, wp_slash( $value ) );
        }
    }
}


// ── Block editor: the sidebar panel ──────────────────────────────────────────

add_action( 'enqueue_block_editor_assets', 'fc_enqueue_editor_panel' );

function fc_enqueue_editor_panel() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'post' ) return;

    wp_enqueue_script(
        'fc-editor-panel',
        FC_PUBLISHER_URL . 'assets/editor-panel.js',
        [ 'wp-plugins', 'wp-element', 'wp-data', 'wp-components', 'wp-editor' ],
        FC_PUBLISHER_VERSION,
        true
    );

    $ratings = [];
    foreach ( fc_rating_choices() as $value => $label ) {
        $ratings[] = [ 'value' => $value, 'label' => $label ];
    }
    wp_add_inline_script( 'fc-editor-panel', 'window.fcEditor = ' . wp_json_encode( [ 'ratings' => $ratings ] ) . ';', 'before' );
}
