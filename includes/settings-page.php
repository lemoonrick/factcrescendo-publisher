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
    register_setting(
        'fc_publisher_settings_group',
        'fc_whatsapp_banner_html',
        [
            'type'              => 'string',
            'sanitize_callback' => 'fc_sanitize_banner_html',
            'default'           => '',
        ]
    );
    register_setting(
        'fc_publisher_settings_group',
        'fc_logo_url',
        [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]
    );
}

function fc_sanitize_banner_html( $input ) {
    // Allows admins to save complex HTML, SVGs, and CSS without stripping tags
    if ( current_user_can( 'unfiltered_html' ) ) {
        return $input;
    }
    return wp_kses_post( $input );
}

function fc_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    ?>
    <div class="wrap">
        <h1>⚙️ FC Publisher Settings</h1>
        <p style="color:#555; max-width:640px;">
            Paste the WhatsApp banner HTML for <strong>this site's language</strong> below. Done once, works forever.
        </p>

        <?php settings_errors( 'fc_publisher_settings_group' ); ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'fc_publisher_settings_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="fc_logo_url">Site Logo URL</label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="fc_logo_url"
                            name="fc_logo_url"
                            value="<?php echo esc_attr( get_option( 'fc_logo_url', '' ) ); ?>"
                            style="width:100%; max-width:600px; font-size:13px;"
                            placeholder="https://yoursite.com/logo.png"
                        >
                        <p class="description">Used in the Fact Card at the top of each article. Paste the direct URL to your site logo image.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fc_whatsapp_banner_html">WhatsApp Banner HTML</label>
                    </th>
                    <td>
                        <textarea
                            id="fc_whatsapp_banner_html"
                            name="fc_whatsapp_banner_html"
                            rows="7" cols="80"
                            style="font-family:monospace; font-size:13px;"
                        ><?php echo esc_textarea( get_option( 'fc_whatsapp_banner_html', '' ) ); ?></textarea>
                        <p class="description">Paste the full <code>&lt;figure&gt;...&lt;/figure&gt;</code> block for this site, or the new styled banner HTML.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <hr>
        <h2>Stamp Image Override (optional)</h2>
        <p style="max-width:640px; color:#555;">
            Default stamps load from <code>factcrescendo.com</code>. To override on this site, add to <code>functions.php</code>:
        </p>
        <pre style="background:#f6f6f6; padding:16px; max-width:640px; border-radius:6px; font-size:13px; border:1px solid #ddd;">add_filter( 'fc_stamp_map', function( $map ) {
    $map['false'] = 'https://yoursite.com/False.png';
    return $map;
} );</pre>

        <hr>
        <h2>REST API Endpoint</h2>
        <p style="max-width:640px; color:#555;">
            Fact-check data for any post is available at:<br>
            <code><?php echo esc_url( rest_url( 'fc/v1/post/{post_id}' ) ); ?></code>
        </p>
    </div>
    <?php
}
