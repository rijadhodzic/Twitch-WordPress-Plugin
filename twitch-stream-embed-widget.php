<?php
/**
 * Plugin Name: Stream & Chat for Twitch
 * Description: Embed a Twitch stream with live status, caching, responsive layout, and theme toggle.
 * Version: 1.6
 * Author: fx0
 * Author URI: https://www.armareforger.de
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stream-chat-for-twitch
 */

defined('ABSPATH') or die('No script kiddies please!');

// Load textdomain
function twitch_stream_embed_load_textdomain() {
    load_plugin_textdomain('stream-chat-for-twitch', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'twitch_stream_embed_load_textdomain');

// Enqueue CSS
function twitch_stream_embed_enqueue_styles() {
    wp_register_style('twitch-stream-embed-style', plugins_url('style.css', __FILE__), [], '1.6');
    wp_enqueue_style('twitch-stream-embed-style');
}
add_action('wp_enqueue_scripts', 'twitch_stream_embed_enqueue_styles');

// Admin settings
function twitch_stream_embed_settings_menu() {
    add_options_page(
        esc_html__('Twitch Stream Settings', 'stream-chat-for-twitch'),
        esc_html__('Twitch Stream', 'stream-chat-for-twitch'),
        'manage_options',
        'twitch-stream-settings',
        'twitch_stream_embed_settings_page'
    );
}
add_action('admin_menu', 'twitch_stream_embed_settings_menu');

function twitch_stream_embed_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Twitch Stream Settings', 'stream-chat-for-twitch'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('twitch_stream_embed_settings');
            do_settings_sections('twitch-stream-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function twitch_stream_embed_register_settings() {
    register_setting('twitch_stream_embed_settings', 'twitch_channel_name', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('twitch_stream_embed_settings', 'twitch_client_id', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('twitch_stream_embed_settings', 'twitch_client_secret', ['sanitize_callback' => 'sanitize_text_field']);

    add_settings_section('twitch_stream_embed_section', esc_html__('API Settings', 'stream-chat-for-twitch'), null, 'twitch-stream-settings');

    add_settings_field('twitch_channel_name', esc_html__('Twitch Channel Name', 'stream-chat-for-twitch'), function () {
        echo '<input type="text" name="twitch_channel_name" value="' . esc_attr(get_option('twitch_channel_name')) . '" class="regular-text">';
    }, 'twitch-stream-settings', 'twitch_stream_embed_section');

    add_settings_field('twitch_client_id', esc_html__('Twitch Client ID', 'stream-chat-for-twitch'), function () {
        echo '<input type="text" name="twitch_client_id" value="' . esc_attr(get_option('twitch_client_id')) . '" class="regular-text">';
    }, 'twitch-stream-settings', 'twitch_stream_embed_section');

    add_settings_field('twitch_client_secret', esc_html__('Twitch Client Secret', 'stream-chat-for-twitch'), function () {
        echo '<input type="text" name="twitch_client_secret" value="' . esc_attr(get_option('twitch_client_secret')) . '" class="regular-text">';
    }, 'twitch-stream-settings', 'twitch_stream_embed_section');
}
add_action('admin_init', 'twitch_stream_embed_register_settings');

// Check live status with caching
function is_twitch_stream_live($channel, $client_id, $client_secret) {
    $cache_key = 'twitch_live_status_' . md5($channel);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $token_response = wp_remote_post("https://id.twitch.tv/oauth2/token", [
        'body' => [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'client_credentials'
        ]
    ]);

    if (is_wp_error($token_response)) return false;

    $token_data = json_decode(wp_remote_retrieve_body($token_response), true);
    $access_token = $token_data['access_token'] ?? '';

    if (!$access_token) return false;

    $stream_response = wp_remote_get("https://api.twitch.tv/helix/streams?user_login=" . $channel, [
        'headers' => [
            'Client-ID' => $client_id,
            'Authorization' => 'Bearer ' . $access_token
        ]
    ]);

    if (is_wp_error($stream_response)) return false;

    $stream_data = json_decode(wp_remote_retrieve_body($stream_response), true);
    $is_live = !empty($stream_data['data']);

    set_transient($cache_key, $is_live, 60);
    return $is_live;
}

// Embed HTML output function
function twitch_stream_embed_output($channel, $theme, $is_live) {
    $host = isset($_SERVER['HTTP_HOST']) ? esc_attr(sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))) : '';
    ob_start();

    echo '<div class="twitch-status">';
    echo $is_live
        ? '<span class="twitch-status-icon live"></span> <strong>' . esc_html__('Live', 'stream-chat-for-twitch') . '</strong>'
        : '<span class="twitch-status-icon offline"></span> <strong>' . esc_html__('Offline', 'stream-chat-for-twitch') . '</strong>';
    echo '</div>';

    if ($is_live) {
        ?>
        <div class="twitch-embed-wrapper">
            <iframe src="https://player.twitch.tv/?channel=<?php echo esc_attr($channel); ?>&parent=<?php echo esc_attr($host); ?>&theme=<?php echo esc_attr($theme); ?>"
                frameborder="0" allowfullscreen scrolling="no">
            </iframe>
            <iframe src="https://www.twitch.tv/embed/<?php echo esc_attr($channel); ?>/chat?parent=<?php echo esc_attr($host); ?>&theme=<?php echo esc_attr($theme); ?>"
                frameborder="0" scrolling="no" class="twitch-chat">
            </iframe>
        </div>
        <?php
    }

    return ob_get_clean();
}

// Shortcode
function twitch_stream_embed_shortcode($atts) {
    $atts = shortcode_atts([
        'theme' => 'dark'
    ], $atts, 'twitch_stream');

    $theme = $atts['theme'] === 'light' ? 'light' : 'dark';

    $channel = get_option('twitch_channel_name');
    $client_id = get_option('twitch_client_id');
    $client_secret = get_option('twitch_client_secret');

    if (!$channel || !$client_id || !$client_secret) {
        return '<p>' . esc_html__('Twitch settings are incomplete.', 'stream-chat-for-twitch') . '</p>';
    }

    $is_live = is_twitch_stream_live($channel, $client_id, $client_secret);
    return twitch_stream_embed_output($channel, $theme, $is_live);
}
add_shortcode('twitch_stream', 'twitch_stream_embed_shortcode');
