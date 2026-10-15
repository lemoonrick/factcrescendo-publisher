<?php
/**
 * Settings Page: FC Publisher
 * Settings > FC Publisher in WP Admin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'fc_register_settings_page' );
add_action( 'admin_init', 'fc_register_settings' );

function fc_register_settings_page() {
    add_options_page(
        'FC Publisher Settings',
        'FC Publisher',
        'manage_options',
        'fc-publisher-settings',
        'fc_render_settings_page'
    );
}

function fc_register_settings() {
    register_setting( 'fc_publisher_settings_group', 'fc_logo_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ] );

    register_setting( 'fc_publisher_settings_group', 'fc_enable_audio', [
        'type'              => 'string',
        'sanitize_callback' => function( $v ) { return $v === '1' ? '1' : '0'; },
        'default'           => '1',
    ] );

    register_setting( 'fc_publisher_settings_group', 'fc_whatsapp_banner_html', [
        'type'              => 'string',
        'sanitize_callback' => 'fc_sanitize_banner_html',
        'default'           => '',
    ] );

    register_setting( 'fc_publisher_settings_group', 'fc_show_whatsapp_banner', [
        'type'              => 'string',
        'sanitize_callback' => function( $v ) { return $v === '1' ? '1' : '0'; },
        'default'           => '1',
    ] );

    // Which site-list entry to use. Empty means "work it out from the
    // address", which is right everywhere except a staging copy.
    register_setting( 'fc_publisher_settings_group', 'fc_site_key', [
        'type'              => 'string',
        'sanitize_callback' => function( $v ) {
            $v = (string) $v;
            return array_key_exists( $v, fc_site_profiles() ) ? $v : '';
        },
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
function fc_sanitize_banner_html( $input ) {
    $allowed = [
        'style'  => [],
        'div'    => [ 'class' => true, 'style' => true, 'id' => true ],
        'span'   => [ 'class' => true, 'style' => true ],
        'figure' => [ 'class' => true, 'style' => true ],
        'a'      => [ 'href' => true, 'target' => true, 'rel' => true, 'class' => true, 'style' => true ],
        'img'    => [ 'src' => true, 'alt' => true, 'class' => true, 'width' => true, 'height' => true, 'style' => true, 'crossorigin' => true ],
        'h1'     => [ 'class' => true, 'style' => true ],
        'h2'     => [ 'class' => true, 'style' => true ],
        'h3'     => [ 'class' => true, 'style' => true ],
        'h4'     => [ 'class' => true, 'style' => true ],
        'p'      => [ 'class' => true, 'style' => true ],
        'button' => [ 'class' => true, 'style' => true, 'type' => true ],
        'svg'    => [ 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'xmlns' => true, 'class' => true, 'style' => true ],
        'path'   => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ],
        'circle' => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true ],
        'rect'   => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true ],
    ];
    return wp_kses( $input, $allowed );
}

function fc_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    ?>
    <style>
    .fc-settings-wrap { max-width: 760px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }
    .fc-settings-header { display: flex; align-items: center; gap: 12px; margin: 24px 0 4px; }
    .fc-settings-header svg { width: 26px; height: 26px; color: #e31b23; flex-shrink: 0; }
    .fc-settings-header h1 { margin: 0; font-size: 22px; font-weight: 700; color: #0f172a; padding: 0; }
    .fc-settings-sub { color: #64748b; font-size: 14px; margin: 0 0 28px; }
    .fc-settings-section {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
        padding: 24px 28px; margin-bottom: 20px;
        animation: fc-fade-in 0.3s ease both;
    }
    .fc-settings-section h2 { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
    .fc-settings-section .fc-section-desc { font-size: 13px; color: #64748b; margin: 0 0 18px; }
    .fc-toggle { display: flex; align-items: center; gap: 12px; cursor: pointer; user-select: none; margin: 4px 0; }
    .fc-toggle * { box-sizing: border-box; }
    .fc-toggle input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; margin: 0; padding: 0; pointer-events: none; }
    .fc-toggle-track { position: relative; display: inline-block; width: 44px; height: 26px; min-width: 44px; background: #cbd5e1; border-radius: 999px; transition: background 0.2s ease; flex-shrink: 0; }
    .fc-toggle-thumb { position: absolute; top: 50%; left: 3px; width: 20px; height: 20px; margin-top: -10px; background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.25); transition: transform 0.2s ease; }
    .fc-toggle input[type="checkbox"]:checked ~ .fc-toggle-track { background: #64748b; }
    .fc-toggle input[type="checkbox"]:checked ~ .fc-toggle-track .fc-toggle-thumb { transform: translateX(18px); }
    .fc-toggle-text { font-size: 13px; font-weight: 600; color: #334155; line-height: 1.4; }
    .fc-field { margin-bottom: 18px; }
    .fc-field:last-child { margin-bottom: 0; }
    .fc-field label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
    .fc-field input[type="text"], .fc-field input[type="url"], .fc-field input[type="password"], .fc-field textarea {
        width: 100%; max-width: 480px; font-size: 13px; padding: 9px 12px;
        border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .fc-field textarea { max-width: 100%; font-family: ui-monospace, monospace; }
    .fc-field input:focus, .fc-field textarea:focus {
        outline: none; border-color: #e31b23; box-shadow: 0 0 0 3px rgba(227,27,35,0.1);
    }
    .fc-field-hint { font-size: 12px; color: #94a3b8; margin-top: 6px; }
    .fc-field-hint code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; }
    .fc-submit-row { margin-top: 4px; }
    .fc-submit-row .button-primary {
        background: #e31b23; border-color: #e31b23; box-shadow: none; text-shadow: none;
        border-radius: 8px; padding: 6px 18px; height: auto; font-weight: 600;
    }
    .fc-submit-row .button-primary:hover { background: #c8151c; border-color: #c8151c; }
    .fc-code-block {
        background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;
        padding: 14px 16px; font-size: 12.5px; font-family: ui-monospace, monospace;
        color: #334155; overflow-x: auto; margin: 8px 0 0;
    }
    @keyframes fc-fade-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
    </style>

    <div class="wrap fc-settings-wrap">
        <div class="fc-settings-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            <h1>FC Publisher Settings</h1>
        </div>
        <p class="fc-settings-sub">Most of this is automatic. Each site recognises itself from the site list built into the plugin.</p>

        <?php settings_errors( 'fc_publisher_settings_group' ); ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'fc_publisher_settings_group' ); ?>

            <?php
            $fc_profiles = fc_site_profiles();
            $fc_chosen   = (string) get_option( 'fc_site_key', '' );
            $fc_key      = fc_site_key();
            $fc_site     = fc_site_profile();
            ?>
            <div class="fc-settings-section">
                <h2>This Site</h2>
                <?php if ( $fc_key !== '' ) : ?>
                <p class="fc-section-desc">Recognised as <strong><?php echo esc_html( $fc_site['label'] ); ?></strong> (<?php echo esc_html( $fc_key ); ?>)<?php echo $fc_chosen !== '' ? ' &mdash; chosen by hand below' : ''; ?>. The WhatsApp banner and number come from this entry.</p>
                <?php else : ?>
                <p class="fc-section-desc">This site's address isn't in the site list, so no WhatsApp banner is added. That's expected on staging copies and on sites that don't use a banner. To test as one of the listed sites, pick it below.</p>
                <?php endif; ?>
                <div class="fc-field">
                    <label for="fc_site_key">Use the details of</label>
                    <select id="fc_site_key" name="fc_site_key">
                        <option value="">Automatic (from this site's address)</option>
                        <?php foreach ( $fc_profiles as $key => $profile ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $fc_chosen, $key ); ?>><?php echo esc_html( ( $profile['label'] ?? $key ) . ' — ' . $key ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="fc-field-hint">Leave on Automatic on live sites.</p>
                </div>
            </div>

            <div class="fc-settings-section">
                <h2>Branding</h2>
                <p class="fc-section-desc">Used in the Fact Card shown at the top of every fact-check article.</p>
                <div class="fc-field">
                    <label for="fc_logo_url">Site Logo URL</label>
                    <input type="url" id="fc_logo_url" name="fc_logo_url"
                        value="<?php echo esc_attr( get_option( 'fc_logo_url', '' ) ); ?>"
                        placeholder="https://yoursite.com/logo.png">
                </div>
            </div>

            <div class="fc-settings-section">
                <h2>WhatsApp Banner</h2>
                <p class="fc-section-desc">Added at the end of every fact-check article. Articles that already have a WhatsApp link pasted into them don't get a second one.</p>

                <div class="fc-field">
                    <label class="fc-toggle">
                        <input type="hidden" name="fc_show_whatsapp_banner" value="0">
                        <input type="checkbox" name="fc_show_whatsapp_banner" value="1" <?php checked( get_option( 'fc_show_whatsapp_banner', '1' ), '1' ); ?>>
                        <span class="fc-toggle-track"><span class="fc-toggle-thumb"></span></span>
                        <span class="fc-toggle-text">Show the WhatsApp banner on this site</span>
                    </label>
                </div>

                <?php if ( $fc_site['banner_image'] !== '' ) : ?>
                <div class="fc-field">
                    <label>This site's banner</label>
                    <img src="<?php echo esc_url( $fc_site['banner_image'] ); ?>" alt="" style="max-width:360px; width:100%; height:auto; border-radius:8px; border:1px solid #e5e7eb;">
                </div>
                <?php endif; ?>

                <details class="fc-field">
                    <summary style="cursor:pointer; font-weight:600; font-size:13px; color:#334155;">Use a different banner (advanced)</summary>
                    <p class="fc-field-hint">Leave this empty to use the built-in banner above. Anything pasted here replaces it.</p>
                    <textarea id="fc_whatsapp_banner_html" name="fc_whatsapp_banner_html" rows="7"><?php echo esc_textarea( get_option( 'fc_whatsapp_banner_html', '' ) ); ?></textarea>
                    <p class="fc-field-hint">Style blocks, links, images, and inline SVG icons are allowed. Script tags and event handlers are stripped automatically.</p>
                </details>
            </div>

            <div class="fc-settings-section">
                <h2>Audio Narration</h2>
                <p class="fc-section-desc">The "Listen to this article" player reads the article aloud with the visitor's own browser voice. Free, and shown on English sites only. This site's language: <strong><?php echo esc_html( fc_site_lang() ); ?></strong>.</p>

                <div class="fc-field">
                    <label class="fc-toggle">
                        <input type="hidden" name="fc_enable_audio" value="0">
                        <input type="checkbox" name="fc_enable_audio" value="1" <?php checked( get_option( 'fc_enable_audio', '1' ), '1' ); ?>>
                        <span class="fc-toggle-track"><span class="fc-toggle-thumb"></span></span>
                        <span class="fc-toggle-text">Show the audio player on this site</span>
                    </label>
                    <p class="fc-field-hint">Turn this off for sites that don't need narration. When off, no player appears and nothing about the article layout changes.</p>
                </div>
            </div>

            <div class="fc-submit-row">
                <?php submit_button( 'Save Settings' ); ?>
            </div>
        </form>

        <div class="fc-settings-section">
            <h2>Where the Fact Card Appears</h2>
            <p class="fc-section-desc">By default the card sits at the very top of the article. If you write a byline as the first line, the card would land above it &mdash; so type this on the line where the card should go instead:</p>
            <div class="fc-code-block">[fact_card]</div>
            <p class="fc-field-hint">Put it just below the byline. Leave it out entirely on posts without a byline and nothing changes. Anything written above it is skipped by the audio player, so the byline is not read aloud.</p>
        </div>

        <div class="fc-settings-section">
            <h2>Stamp Image Override</h2>
            <p class="fc-section-desc">Stamps ship with the plugin and load from this site, so they keep working even if another site is down. To use different images here, add this to your theme's functions.php:</p>
            <div class="fc-code-block">add_filter( 'fc_stamp_map', function( $map ) {<br>&nbsp;&nbsp;&nbsp;&nbsp;$map['false'] = 'https://yoursite.com/False.png';<br>&nbsp;&nbsp;&nbsp;&nbsp;return $map;<br>} );</div>
        </div>

        <div class="fc-settings-section">
            <h2>REST API</h2>
            <p class="fc-section-desc">Fact-check data for any post, including audio status, is available at:</p>
            <div class="fc-code-block"><?php echo esc_url( rest_url( 'fc/v1/post/{post_id}' ) ); ?></div>
        </div>
    </div>
    <?php
}
