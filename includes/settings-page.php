<?php
/**
 * Settings > Fact Crescendo in WP Admin.
 *
 * Most things work on their own; this page is for the few choices a site
 * may want to change.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'fc_register_settings_page' );
add_action( 'admin_init', 'fc_register_settings' );

function fc_register_settings_page() {
    add_options_page( 'Fact Crescendo', 'Fact Crescendo', 'manage_options', 'fc-publisher-settings', 'fc_render_settings_page' );
}

/** A setting that is one of a fixed list; anything else becomes the default. */
function fc_register_choice( $name, $choices, $default ) {
    register_setting( 'fc_publisher_settings_group', $name, [
        'type'              => 'string',
        'default'           => $default,
        'sanitize_callback' => function( $v ) use ( $choices, $default ) {
            return in_array( (string) $v, $choices, true ) ? (string) $v : $default;
        },
    ] );
}

function fc_register_settings() {
    fc_register_choice( 'fc_site_key', array_merge( [ '' ], array_keys( fc_site_profiles() ) ), '' );
    fc_register_choice( 'fc_card_design', [ 'card', 'simple', 'off' ], 'card' );
    fc_register_choice( 'fc_card_share', [ '1', '0' ], '1' );
    fc_register_choice( 'fc_featured_stamps', [ '1', '0' ], '1' );
    fc_register_choice( 'fc_featured_corner', [ 'bottom-left', 'bottom-right', 'top-left', 'top-right' ], 'bottom-left' );
    fc_register_choice( 'fc_whatsapp_banner_style', [ '', 'card', 'image', 'custom', 'off' ], '' ); // '' = not chosen yet
    fc_register_choice( 'fc_enable_audio', [ '1', '0' ], '1' );
    fc_register_choice( 'fc_auto_embeds', [ '1', '0' ], '1' );

    register_setting( 'fc_publisher_settings_group', 'fc_embed_notes', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default'           => fc_embed_default_notes(),
    ] );
    register_setting( 'fc_publisher_settings_group', 'fc_logo_url', [
        'type'              => 'string',
        'sanitize_callback' => 'fc_sanitize_url',
        'default'           => '',
    ] );
    register_setting( 'fc_publisher_settings_group', 'fc_whatsapp_banner_html', [
        'type'              => 'string',
        'sanitize_callback' => 'fc_sanitize_banner_html',
        'default'           => '',
    ] );
}

/**
 * Banner HTML is sanitized against an explicit allowlist rather than
 * granted a raw "unfiltered_html" bypass. This still allows the styled
 * banner markup (style blocks, inline SVG icons, custom classes) while
 * removing script tags, event handler attributes, and anything outside
 * this list — regardless of who is saving it.
 */
function fc_sanitize_banner_html( $input, $keep_styles = false ) {
    $common = [ 'class' => true, 'style' => true, 'id' => true, 'aria-label' => true, 'aria-hidden' => true, 'role' => true ];
    $allowed = [
        'style'   => [], // see below
        'a'       => $common + [ 'href' => true, 'target' => true, 'rel' => true ],
        'img'     => $common + [ 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'decoding' => true ],
        'svg'     => $common + [ 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'xmlns' => true, 'focusable' => true ],
        'path'    => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ],
        'g'       => [ 'fill' => true, 'class' => true, 'transform' => true ],
        'circle'  => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'class' => true ],
        'rect'    => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true, 'class' => true ],
        'polygon' => [ 'points' => true, 'fill' => true, 'class' => true ],
    ];
    foreach ( [ 'div', 'span', 'figure', 'p', 'h2', 'h3', 'h4', 'h5', 'strong', 'b', 'em', 'i', 'small', 'br', 'button', 'ul', 'ol', 'li' ] as $tag ) {
        $allowed[ $tag ] = $common;
    }
    // Style blocks only from people allowed raw HTML (on multisite, not
    // every site admin is). $keep_styles is for showing code already saved.
    if ( $keep_styles !== true && ! current_user_can( 'unfiltered_html' ) ) unset( $allowed['style'] );

    return wp_kses( (string) $input, $allowed, [ 'http', 'https', 'tel' ] );
}

/** An on/off switch. The hidden field sends "0" when it is off. */
function fc_settings_switch( $name, $default, $label, $hint = '' ) {
    ?>
    <label class="fc-switch">
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
        <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( get_option( $name, $default ), '1' ); ?>>
        <span class="fc-switch-track" aria-hidden="true"></span>
        <span><?php echo esc_html( $label ); ?><?php if ( $hint !== '' ) : ?><small><?php echo esc_html( $hint ); ?></small><?php endif; ?></span>
    </label>
    <?php
}

/** A list of choices shown as large, easy-to-click options. */
function fc_settings_choices( $name, $current, $choices ) {
    echo '<div class="fc-choices">';
    foreach ( $choices as $value => $c ) {
        printf(
            '<label class="fc-choice"><input type="radio" name="%s" value="%s" %s><span><strong>%s</strong>%s</span></label>',
            esc_attr( $name ), esc_attr( $value ), checked( $current, $value, false ),
            esc_html( $c[0] ), isset( $c[1] ) ? '<small>' . esc_html( $c[1] ) . '</small>' : ''
        );
    }
    echo '</div>';
}

function fc_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $profiles = fc_site_profiles();
    $chosen   = (string) get_option( 'fc_site_key', '' );
    $detected = fc_normalise_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
    $site     = fc_site_profile();
    $banner   = fc_whatsapp_banner_choice();
    ?>
    <style>
    .fc-set { max-width: 720px; }
    .fc-set h1 { font-size: 23px; font-weight: 600; margin: 20px 0 6px; }
    .fc-set .fc-lead { font-size: 14px; color: #50575e; margin: 0 0 24px; }
    .fc-box { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 24px; margin: 0 0 20px; }
    .fc-box h2 { font-size: 16px; margin: 0 0 4px; }
    .fc-box > p { color: #50575e; margin: 0 0 18px; font-size: 13px; }
    .fc-box .fc-row { margin: 18px 0 0; }
    .fc-box .fc-row > label:not(.fc-switch), .fc-box .fc-label { display: block; font-weight: 600; margin: 0 0 8px; }
    .fc-box select, .fc-box input[type=url] { min-width: 320px; max-width: 100%; }
    .fc-box textarea { width: 100%; font-family: ui-monospace, Menlo, monospace; font-size: 12px; }
    .fc-hint { color: #646970; font-size: 12px; margin: 6px 0 0; }
    .fc-ok { color: #00a32a; font-weight: 600; }
    .fc-warn { color: #b32d2e; }

    .fc-choices { display: grid; gap: 8px; }
    .fc-choice { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border: 1px solid #dcdcde; border-radius: 8px; cursor: pointer; }
    .fc-choice:hover { border-color: #8c8f94; }
    .fc-choice:has(input:checked) { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; background: #f6f9fc; }
    .fc-choice input { margin: 2px 0 0; }
    .fc-choice small, .fc-switch small { display: block; color: #646970; font-weight: 400; margin-top: 2px; }

    .fc-switch { display: flex; gap: 12px; align-items: flex-start; cursor: pointer; font-weight: 600; }
    .fc-switch input[type=checkbox] { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .fc-switch-track { flex: none; width: 40px; height: 22px; border-radius: 22px; background: #c3c4c7; position: relative; transition: background .15s; margin-top: 1px; }
    .fc-switch-track::after { content: ""; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; }
    .fc-switch input:checked + .fc-switch-track { background: #2271b1; }
    .fc-switch input:checked + .fc-switch-track::after { transform: translateX(18px); }
    .fc-switch input:focus-visible + .fc-switch-track { outline: 2px solid #2271b1; outline-offset: 2px; }

    .fc-preview { margin-top: 12px; padding: 16px; border: 1px dashed #c3c4c7; border-radius: 8px; background: #fafafa; }
    .fc-stamps { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-top: 12px; }
    .fc-stamps img { height: 70px; width: auto; }
    .fc-set details { margin: 0 0 20px; }
    .fc-set summary { cursor: pointer; font-weight: 600; }
    .fc-set code { font-size: 12px; }
    </style>

    <div class="wrap fc-set">
        <h1>Fact Crescendo</h1>
        <p class="fc-lead">Everything works on its own. Change only what this site needs, then press Save.</p>

        <?php settings_errors( 'fc_publisher_settings_group' ); ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'fc_publisher_settings_group' ); ?>

            <div class="fc-box">
                <h2>This site</h2>
                <p>Sets the WhatsApp number and language.</p>
                <label class="fc-label" for="fc_site_key">Site</label>
                <select id="fc_site_key" name="fc_site_key">
                    <option value=""><?php echo esc_html( isset( $profiles[ $detected ] ) ? 'Automatic — ' . $profiles[ $detected ]['label'] : 'Automatic — not recognised' ); ?></option>
                    <?php foreach ( $profiles as $key => $profile ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $chosen, $key ); ?>><?php echo esc_html( $profile['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $site['label'] !== '' ) : ?>
                <p class="fc-hint">Using <strong><?php echo esc_html( $site['label'] ); ?></strong> · WhatsApp <?php echo esc_html( $site['whatsapp_number'] ); ?></p>
                <?php else : ?>
                <p class="fc-hint fc-warn">This address isn't in the site list. Pick a site to show its WhatsApp banner.</p>
                <?php endif; ?>
            </div>

            <div class="fc-box">
                <h2>Fact card</h2>
                <p>Shown at the top of each fact check. Editors can change it on any post.</p>
                <?php fc_settings_choices( 'fc_card_design', fc_card_design(), [
                    'card'   => [ 'Fact Crescendo card', 'Photo, rating, claim and fact. Recommended.' ],
                    'simple' => [ 'Simple card', 'The photo with a claim and fact strip below it.' ],
                    'off'    => [ 'No card' ],
                ] ); ?>
                <div class="fc-row"><?php fc_settings_switch( 'fc_card_share', '1', 'Download and Share buttons', 'Shown under the card.' ); ?></div>
                <details class="fc-row">
                    <summary>Use a different logo</summary>
                    <p class="fc-hint">Leave empty to use the Fact Crescendo logo.</p>
                    <input type="url" id="fc_logo_url" name="fc_logo_url" value="<?php echo esc_attr( get_option( 'fc_logo_url', '' ) ); ?>" placeholder="https://">
                </details>
            </div>

            <div class="fc-box">
                <h2>Featured image stamp</h2>
                <p>Set a plain photo as the featured image. When the post is saved, the stamp is added for you.</p>
                <?php fc_settings_switch( 'fc_featured_stamps', '1', 'Add stamps to featured images', 'Turn off if you make featured images in Canva.' ); ?>
                <div class="fc-stamps">
                    <img src="<?php echo esc_url( FC_PUBLISHER_URL . 'assets/featured/fact-checked.png' ); ?>" alt="Fact checked stamp">
                    <img src="<?php echo esc_url( FC_PUBLISHER_URL . 'assets/featured/insight.png' ); ?>" alt="Insight badge">
                </div>
                <p class="fc-hint">Fact checked for most ratings · Insight for Insight · none for News.</p>
                <div class="fc-row">
                    <label for="fc_featured_corner">Position</label>
                    <select id="fc_featured_corner" name="fc_featured_corner">
                        <?php foreach ( [ 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right', 'top-left' => 'Top left', 'top-right' => 'Top right' ] as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( fc_featured_corner(), $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ( ! fc_featured_can_draw() ) : ?>
                <p class="fc-hint fc-warn">This server can't draw images, so no stamps are added. Ask your host to turn on PHP GD.</p>
                <?php endif; ?>
            </div>

            <div class="fc-box">
                <h2>WhatsApp banner</h2>
                <p>Shown at the end of each fact check.</p>
                <?php fc_settings_choices( 'fc_whatsapp_banner_style', $banner, [
                    'card'   => [ 'New banner', 'Uses this site\'s WhatsApp number. Recommended.' ],
                    'image'  => [ 'Old picture banner' ],
                    'custom' => [ 'My own banner', 'Paste the banner code below.' ],
                    'off'    => [ 'No banner' ],
                ] ); ?>
                <div class="fc-row" id="fc-own-banner" <?php echo $banner === 'custom' ? '' : 'hidden'; ?>>
                    <label for="fc_whatsapp_banner_html">Banner code</label>
                    <textarea id="fc_whatsapp_banner_html" name="fc_whatsapp_banner_html" rows="8"><?php echo esc_textarea( get_option( 'fc_whatsapp_banner_html', '' ) ); ?></textarea>
                    <p class="fc-hint">Styles, links, images and icons are kept. Scripts are removed.</p>
                </div>
                <?php $preview = fc_get_whatsapp_banner(); ?>
                <div class="fc-preview">
                    <?php if ( $preview !== '' ) : echo $preview; // Built and escaped by the plugin. ?>
                    <?php else : ?><span class="fc-hint">No banner will show. <?php echo $banner === 'off' ? '' : 'Pick a site above, or paste your banner code.'; ?></span><?php endif; ?>
                </div>
                <p class="fc-hint">Preview of the saved settings. Press Save to update it.</p>
            </div>

            <div class="fc-box">
                <h2>Embeds</h2>
                <p>Paste the article as it is and press Save. A link on its own line becomes the post or video: Archive.org, Awesome Screenshot, Instagram, Facebook, Vimeo, YouTube and X. Our own picture links become pictures, and code pasted as text becomes a Custom HTML block. It is saved into the article, so it keeps working even without this plugin.</p>
                <?php fc_settings_switch( 'fc_auto_embeds', '1', 'Turn links into embeds', 'Older articles are never changed.' ); ?>
                <div class="fc-row">
                    <label for="fc_embed_notes">Author notes to delete</label>
                    <textarea id="fc_embed_notes" name="fc_embed_notes" rows="6"><?php echo esc_textarea( get_option( 'fc_embed_notes', fc_embed_default_notes() ) ); ?></textarea>
                    <p class="fc-hint">One per line, any language. Deleted only when the line holds nothing else and sits just above an embed or picture. Capitals, colons and brackets don't matter.</p>
                </div>
            </div>

            <div class="fc-box">
                <h2>Listen to this article</h2>
                <p>Reads the article aloud with the visitor's browser voice. English sites only.</p>
                <?php fc_settings_switch( 'fc_enable_audio', '1', 'Show the Listen button', fc_site_lang() === 'en' ? '' : 'This site isn\'t English, so it won\'t show.' ); ?>
            </div>

            <?php submit_button( 'Save' ); ?>
        </form>

        <details>
            <summary>Tips</summary>
            <p>To move the fact card below a byline, type <code>[fact_card]</code> where it should go.</p>
            <p>Fact-check data for apps: <code><?php echo esc_html( rest_url( 'fc/v1/posts' ) ); ?></code></p>
        </details>

        <details>
            <summary>For developers</summary>
            <p>Filters: <code>fc_site_profiles</code>, <code>fc_card_labels</code>, <code>fc_card_logo_url</code>, <code>fc_whatsapp_banner_text</code>, <code>fc_featured_stamp_files</code>, <code>fc_stamp_map</code>, <code>fc_claimreview_schema</code>.</p>
        </details>
    </div>

    <script>
    // Show the code box only when "My own banner" is picked.
    document.querySelectorAll('input[name="fc_whatsapp_banner_style"]').forEach(function (r) {
        r.addEventListener('change', function () {
            document.getElementById('fc-own-banner').hidden = r.value !== 'custom';
        });
    });
    </script>
    <?php
}
