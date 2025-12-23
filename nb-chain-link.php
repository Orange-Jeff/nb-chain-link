<?php
/**
 * Plugin Name: NB Chain Link
 * Plugin URI: https://netbound.ca/plugins/nb-chain-link
 * Description: Modern Web Rings - distributed link chains where sites link to each other. Host or join rings with no central server needed.
 * Version: 1.1.2
 * Author: Orange Jeff
 * Author URI: https://netbound.ca
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nb-chain-link
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * Version 1.1.2 - December 23, 2025
 * - Added proper plugin headers (License, Text Domain, Requires)
 * - Added menu icon
 *
 * Version 1.1.1 - December 23, 2025
 * - Fixed NetBound Tools menu integration (creates menu if doesn't exist)
 *
 * Version 1.1.0 - December 22, 2025 11:30am
 * - Complete rebuild as web ring system
 * - Ring types: Open, Moderated, Private, Curated
 * - Widget modes: Carousel, Live Links, Directory
 * - Themes: Light and Dark
 * - Width: Compact (350px) and Full (100%)
 * - Star rating system for members
 * - REST API for distributed hosting
 * - Health check cron for member status
 */

if (!defined('ABSPATH')) exit;

class NB_Chain_Link {

    private static $instance = null;
    private $version = '1.1.0';

    // Option keys
    private $site_info_key = 'nb_chain_link_site_info';
    private $hosted_rings_key = 'nb_chain_link_hosted';
    private $joined_rings_key = 'nb_chain_link_joined';
    private $widget_settings_key = 'nb_chain_link_widget';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_shortcode('nb_chain_link', array($this, 'render_widget_shortcode'));
        add_shortcode('nb_chain_link_directory', array($this, 'render_directory_shortcode'));

        // REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Cron for health checks
        add_action('nb_chain_link_health_check', array($this, 'run_health_checks'));
        if (!wp_next_scheduled('nb_chain_link_health_check')) {
            wp_schedule_event(time(), 'hourly', 'nb_chain_link_health_check');
        }

        // AJAX handlers
        add_action('wp_ajax_nb_chain_link_rate', array($this, 'ajax_submit_rating'));
    }

    // =========================================================================
    // DATA GETTERS/SETTERS
    // =========================================================================

    public function get_site_info() {
        $defaults = array(
            'name' => get_bloginfo('name'),
            'url' => home_url('/'),
            'page_url' => home_url('/'),
            'image' => '',
            'excerpt' => get_bloginfo('description')
        );
        return wp_parse_args(get_option($this->site_info_key, array()), $defaults);
    }

    public function save_site_info($data) {
        update_option($this->site_info_key, $data);
    }

    public function get_hosted_rings() {
        return get_option($this->hosted_rings_key, array());
    }

    public function save_hosted_rings($rings) {
        update_option($this->hosted_rings_key, $rings);
    }

    public function get_joined_rings() {
        return get_option($this->joined_rings_key, array());
    }

    public function save_joined_rings($rings) {
        update_option($this->joined_rings_key, $rings);
    }

    public function get_widget_settings() {
        $defaults = array(
            'mode' => 'carousel',    // carousel, live, directory
            'theme' => 'light',      // light, dark
            'width' => 'compact'     // compact, full
        );
        return wp_parse_args(get_option($this->widget_settings_key, array()), $defaults);
    }

    public function save_widget_settings($settings) {
        update_option($this->widget_settings_key, $settings);
    }

    // =========================================================================
    // REST API ENDPOINTS
    // =========================================================================

    public function register_rest_routes() {
        $namespace = 'nb-chain-link/v1';

        // Ping - health check
        register_rest_route($namespace, '/ping', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_ping'),
            'permission_callback' => '__return_true'
        ));

        // Get ring data
        register_rest_route($namespace, '/ring/(?P<ring_id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_ring'),
            'permission_callback' => '__return_true'
        ));

        // Join ring
        register_rest_route($namespace, '/ring/(?P<ring_id>[a-zA-Z0-9_-]+)/join', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_join_ring'),
            'permission_callback' => '__return_true'
        ));

        // Submit rating
        register_rest_route($namespace, '/ring/(?P<ring_id>[a-zA-Z0-9_-]+)/rate', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_submit_rating'),
            'permission_callback' => '__return_true'
        ));
    }

    public function api_ping($request) {
        return rest_ensure_response(array(
            'status' => 'ok',
            'version' => $this->version,
            'site' => get_bloginfo('name')
        ));
    }

    public function api_get_ring($request) {
        $ring_id = $request->get_param('ring_id');
        $secret = $request->get_param('secret');

        $hosted = $this->get_hosted_rings();

        if (!isset($hosted[$ring_id])) {
            return new WP_Error('not_found', 'Ring not found', array('status' => 404));
        }

        $ring = $hosted[$ring_id];

        // Check private ring access
        if ($ring['type'] === 'private' && $ring['secret'] !== $secret) {
            return new WP_Error('forbidden', 'Invalid invite code', array('status' => 403));
        }

        // Return public ring data (excluding sensitive info)
        return rest_ensure_response(array(
            'ring_id' => $ring_id,
            'name' => $ring['name'],
            'type' => $ring['type'],
            'members' => $ring['members'],
            'updated' => $ring['updated']
        ));
    }

    public function api_join_ring($request) {
        $ring_id = $request->get_param('ring_id');
        $hosted = $this->get_hosted_rings();

        if (!isset($hosted[$ring_id])) {
            return new WP_Error('not_found', 'Ring not found', array('status' => 404));
        }

        $ring = &$hosted[$ring_id];

        // Curated rings don't allow join requests
        if ($ring['type'] === 'curated') {
            return new WP_Error('forbidden', 'This ring is curated - members are added by host only', array('status' => 403));
        }

        // Check private ring secret
        $secret = $request->get_param('secret');
        if ($ring['type'] === 'private' && $ring['secret'] !== $secret) {
            return new WP_Error('forbidden', 'Invalid invite code', array('status' => 403));
        }

        $url = esc_url_raw($request->get_param('url'));
        $name = sanitize_text_field($request->get_param('name'));
        $page_url = esc_url_raw($request->get_param('page_url'));
        $image = esc_url_raw($request->get_param('image'));
        $excerpt = sanitize_text_field($request->get_param('excerpt'));

        if (empty($url) || empty($name)) {
            return new WP_Error('invalid', 'URL and name are required', array('status' => 400));
        }

        // Check if already a member
        foreach ($ring['members'] as $member) {
            if ($member['url'] === $url) {
                return rest_ensure_response(array('status' => 'already_member'));
            }
        }

        // Check pending
        foreach ($ring['pending'] as $pending) {
            if ($pending['url'] === $url) {
                return rest_ensure_response(array('status' => 'pending'));
            }
        }

        $member_data = array(
            'url' => $url,
            'name' => $name,
            'page_url' => $page_url ?: $url,
            'image' => $image,
            'excerpt' => $excerpt,
            'joined' => time(),
            'status' => 'active',
            'fails' => 0,
            'ratings' => array()
        );

        // Open rings auto-approve
        if ($ring['type'] === 'open') {
            $ring['members'][] = $member_data;
            $ring['updated'] = time();
            $this->save_hosted_rings($hosted);
            return rest_ensure_response(array('status' => 'approved'));
        }

        // Moderated/Private rings go to pending
        $ring['pending'][] = $member_data;
        $this->save_hosted_rings($hosted);
        return rest_ensure_response(array('status' => 'pending'));
    }

    public function api_submit_rating($request) {
        $ring_id = $request->get_param('ring_id');
        $target_url = esc_url_raw($request->get_param('target_url'));
        $rating = intval($request->get_param('rating'));
        $rater_url = esc_url_raw($request->get_param('rater_url'));

        if ($rating < 1 || $rating > 5) {
            return new WP_Error('invalid', 'Rating must be 1-5', array('status' => 400));
        }

        $hosted = $this->get_hosted_rings();

        if (!isset($hosted[$ring_id])) {
            return new WP_Error('not_found', 'Ring not found', array('status' => 404));
        }

        // Verify rater has the plugin via ping
        $ping_response = wp_remote_get(trailingslashit($rater_url) . 'wp-json/nb-chain-link/v1/ping', array(
            'timeout' => 10,
            'sslverify' => false
        ));

        if (is_wp_error($ping_response) || wp_remote_retrieve_response_code($ping_response) !== 200) {
            return new WP_Error('forbidden', 'Rater must have NB Chain Link installed', array('status' => 403));
        }

        // Find and rate the target
        $found = false;
        foreach ($hosted[$ring_id]['members'] as &$member) {
            if ($member['url'] === $target_url) {
                // Store rating by rater URL to prevent duplicate ratings
                if (!isset($member['ratings'])) {
                    $member['ratings'] = array();
                }
                $member['ratings'][$rater_url] = $rating;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return new WP_Error('not_found', 'Target member not found', array('status' => 404));
        }

        $hosted[$ring_id]['updated'] = time();
        $this->save_hosted_rings($hosted);

        return rest_ensure_response(array('status' => 'rated'));
    }

    // =========================================================================
    // HEALTH CHECKS & SYNC
    // =========================================================================

    public function run_health_checks() {
        // Check hosted ring members
        $hosted = $this->get_hosted_rings();
        $changed = false;

        foreach ($hosted as $ring_id => &$ring) {
            if (empty($ring['members'])) continue;

            // Pick 3 random members to check
            $member_count = count($ring['members']);
            $check_count = min(3, $member_count);
            $members_to_check = array_rand($ring['members'], $check_count);
            if (!is_array($members_to_check)) {
                $members_to_check = array($members_to_check);
            }

            foreach ($members_to_check as $index) {
                $member = &$ring['members'][$index];
                $ping_url = trailingslashit($member['url']) . 'wp-json/nb-chain-link/v1/ping';

                $response = wp_remote_get($ping_url, array(
                    'timeout' => 10,
                    'sslverify' => false
                ));

                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                    $member['fails'] = ($member['fails'] ?? 0) + 1;
                    if ($member['fails'] >= 3) {
                        $member['status'] = 'dead';
                    }
                    $changed = true;
                } else {
                    // Recovery
                    if ($member['status'] === 'dead' || ($member['fails'] ?? 0) > 0) {
                        $member['fails'] = 0;
                        $member['status'] = 'active';
                        $changed = true;
                    }
                }
            }
        }

        if ($changed) {
            $this->save_hosted_rings($hosted);
        }

        // Sync joined rings
        $this->sync_all_joined_rings();
    }

    public function sync_all_joined_rings() {
        $joined = $this->get_joined_rings();
        $changed = false;

        foreach ($joined as $key => &$ring) {
            $api_url = trailingslashit($ring['host_url']) . 'wp-json/nb-chain-link/v1/ring/' . $ring['ring_id'];

            if (!empty($ring['secret'])) {
                $api_url .= '?secret=' . urlencode($ring['secret']);
            }

            $response = wp_remote_get($api_url, array(
                'timeout' => 15,
                'sslverify' => false
            ));

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($data['members'])) {
                    $ring['members'] = $data['members'];
                    $ring['name'] = $data['name'] ?? $ring['name'];
                    $ring['last_sync'] = time();
                    $ring['pending'] = false;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->save_joined_rings($joined);
        }
    }

    // =========================================================================
    // ADMIN PAGE
    // =========================================================================

    public function add_admin_menu() {
        // Check if NetBound Tools menu exists, create if not
        global $menu;
        $netbound_exists = false;
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === 'netbound-tools') {
                $netbound_exists = true;
                break;
            }
        }

        if (!$netbound_exists) {
            add_menu_page(
                'NetBound Tools',
                'NetBound Tools',
                'manage_options',
                'netbound-tools',
                array($this, 'render_admin_page'),
                'dashicons-superhero',
                30
            );
        }

        // Add as submenu under NetBound Tools
        add_submenu_page(
            'netbound-tools',
            'Chain Link',
            '🔗 Chain Link',
            'manage_options',
            'nb-chain-link',
            array($this, 'render_admin_page')
        );
    }

    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['nb_chain_link_action'])) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'nb_chain_link_admin')) return;

        $action = sanitize_text_field($_POST['nb_chain_link_action']);

        switch ($action) {
            case 'save_site_info':
                $this->save_site_info(array(
                    'name' => sanitize_text_field($_POST['site_name']),
                    'url' => esc_url_raw($_POST['site_url']),
                    'page_url' => esc_url_raw($_POST['page_url']),
                    'image' => esc_url_raw($_POST['image']),
                    'excerpt' => sanitize_textarea_field($_POST['excerpt'])
                ));
                add_settings_error('nb_chain_link', 'saved', 'Site info saved!', 'success');
                break;

            case 'save_widget_settings':
                $this->save_widget_settings(array(
                    'mode' => sanitize_text_field($_POST['widget_mode']),
                    'theme' => sanitize_text_field($_POST['widget_theme']),
                    'width' => sanitize_text_field($_POST['widget_width'])
                ));
                add_settings_error('nb_chain_link', 'saved', 'Widget settings saved!', 'success');
                break;

            case 'create_ring':
                $ring_id = sanitize_title($_POST['ring_id']);
                $hosted = $this->get_hosted_rings();

                if (isset($hosted[$ring_id])) {
                    add_settings_error('nb_chain_link', 'exists', 'Ring ID already exists!', 'error');
                    break;
                }

                $site_info = $this->get_site_info();

                $hosted[$ring_id] = array(
                    'name' => sanitize_text_field($_POST['ring_name']),
                    'type' => sanitize_text_field($_POST['ring_type']),
                    'secret' => sanitize_text_field($_POST['ring_secret']),
                    'members' => array(
                        // Host is always first member
                        array(
                            'url' => $site_info['url'],
                            'name' => $site_info['name'],
                            'page_url' => $site_info['page_url'],
                            'image' => $site_info['image'],
                            'excerpt' => $site_info['excerpt'],
                            'joined' => time(),
                            'status' => 'active',
                            'fails' => 0,
                            'ratings' => array()
                        )
                    ),
                    'pending' => array(),
                    'created' => time(),
                    'updated' => time()
                );

                $this->save_hosted_rings($hosted);
                add_settings_error('nb_chain_link', 'created', "Ring '$ring_id' created! Use shortcode: [nb_chain_link ring=\"$ring_id\"]", 'success');
                break;

            case 'join_ring':
                $host_url = esc_url_raw($_POST['host_url']);
                $ring_id = sanitize_text_field($_POST['join_ring_id']);
                $secret = sanitize_text_field($_POST['join_secret']);

                // Send join request to host
                $site_info = $this->get_site_info();
                $api_url = trailingslashit($host_url) . 'wp-json/nb-chain-link/v1/ring/' . $ring_id . '/join';

                $response = wp_remote_post($api_url, array(
                    'timeout' => 15,
                    'sslverify' => false,
                    'body' => array(
                        'url' => $site_info['url'],
                        'name' => $site_info['name'],
                        'page_url' => $site_info['page_url'],
                        'image' => $site_info['image'],
                        'excerpt' => $site_info['excerpt'],
                        'secret' => $secret
                    )
                ));

                if (is_wp_error($response)) {
                    add_settings_error('nb_chain_link', 'error', 'Could not connect to host: ' . $response->get_error_message(), 'error');
                    break;
                }

                $code = wp_remote_retrieve_response_code($response);
                $data = json_decode(wp_remote_retrieve_body($response), true);

                if ($code !== 200) {
                    $msg = isset($data['message']) ? $data['message'] : 'Unknown error';
                    add_settings_error('nb_chain_link', 'error', "Join failed: $msg", 'error');
                    break;
                }

                // Save to joined rings
                $joined = $this->get_joined_rings();
                $key = md5($host_url . $ring_id);
                $joined[$key] = array(
                    'host_url' => $host_url,
                    'ring_id' => $ring_id,
                    'name' => $ring_id, // Will be updated on sync
                    'secret' => $secret,
                    'members' => array(),
                    'last_sync' => 0,
                    'pending' => ($data['status'] === 'pending')
                );
                $this->save_joined_rings($joined);

                // Trigger immediate sync
                $this->sync_all_joined_rings();

                $status_msg = $data['status'] === 'approved' ? 'Joined!' : 'Request pending approval';
                add_settings_error('nb_chain_link', 'joined', $status_msg, 'success');
                break;

            case 'approve_member':
                $ring_id = sanitize_text_field($_POST['ring_id']);
                $member_url = esc_url_raw($_POST['member_url']);
                $hosted = $this->get_hosted_rings();

                if (isset($hosted[$ring_id])) {
                    foreach ($hosted[$ring_id]['pending'] as $i => $pending) {
                        if ($pending['url'] === $member_url) {
                            $hosted[$ring_id]['members'][] = $pending;
                            unset($hosted[$ring_id]['pending'][$i]);
                            $hosted[$ring_id]['pending'] = array_values($hosted[$ring_id]['pending']);
                            $hosted[$ring_id]['updated'] = time();
                            $this->save_hosted_rings($hosted);
                            add_settings_error('nb_chain_link', 'approved', 'Member approved!', 'success');
                            break;
                        }
                    }
                }
                break;

            case 'reject_member':
                $ring_id = sanitize_text_field($_POST['ring_id']);
                $member_url = esc_url_raw($_POST['member_url']);
                $hosted = $this->get_hosted_rings();

                if (isset($hosted[$ring_id])) {
                    foreach ($hosted[$ring_id]['pending'] as $i => $pending) {
                        if ($pending['url'] === $member_url) {
                            unset($hosted[$ring_id]['pending'][$i]);
                            $hosted[$ring_id]['pending'] = array_values($hosted[$ring_id]['pending']);
                            $this->save_hosted_rings($hosted);
                            add_settings_error('nb_chain_link', 'rejected', 'Member rejected', 'info');
                            break;
                        }
                    }
                }
                break;

            case 'remove_member':
                $ring_id = sanitize_text_field($_POST['ring_id']);
                $member_url = esc_url_raw($_POST['member_url']);
                $hosted = $this->get_hosted_rings();

                if (isset($hosted[$ring_id])) {
                    foreach ($hosted[$ring_id]['members'] as $i => $member) {
                        if ($member['url'] === $member_url) {
                            unset($hosted[$ring_id]['members'][$i]);
                            $hosted[$ring_id]['members'] = array_values($hosted[$ring_id]['members']);
                            $hosted[$ring_id]['updated'] = time();
                            $this->save_hosted_rings($hosted);
                            add_settings_error('nb_chain_link', 'removed', 'Member removed', 'info');
                            break;
                        }
                    }
                }
                break;

            case 'delete_ring':
                $ring_id = sanitize_text_field($_POST['ring_id']);
                $hosted = $this->get_hosted_rings();

                if (isset($hosted[$ring_id])) {
                    unset($hosted[$ring_id]);
                    $this->save_hosted_rings($hosted);
                    add_settings_error('nb_chain_link', 'deleted', 'Ring deleted', 'info');
                }
                break;

            case 'leave_ring':
                $key = sanitize_text_field($_POST['ring_key']);
                $joined = $this->get_joined_rings();

                if (isset($joined[$key])) {
                    unset($joined[$key]);
                    $this->save_joined_rings($joined);
                    add_settings_error('nb_chain_link', 'left', 'Left the ring', 'info');
                }
                break;

            case 'add_curated_member':
                $ring_id = sanitize_text_field($_POST['ring_id']);
                $hosted = $this->get_hosted_rings();

                if (isset($hosted[$ring_id])) {
                    $new_member = array(
                        'url' => esc_url_raw($_POST['member_url']),
                        'name' => sanitize_text_field($_POST['member_name']),
                        'page_url' => esc_url_raw($_POST['member_page_url']) ?: esc_url_raw($_POST['member_url']),
                        'image' => esc_url_raw($_POST['member_image']),
                        'excerpt' => sanitize_textarea_field($_POST['member_excerpt']),
                        'joined' => time(),
                        'status' => 'active',
                        'fails' => 0,
                        'ratings' => array()
                    );

                    $hosted[$ring_id]['members'][] = $new_member;
                    $hosted[$ring_id]['updated'] = time();
                    $this->save_hosted_rings($hosted);
                    add_settings_error('nb_chain_link', 'added', 'Member added to ring!', 'success');
                }
                break;
        }
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'nb-chain-link') === false) return;

        wp_enqueue_media();
        wp_enqueue_style('nb-chain-link-admin', plugins_url('css/admin.css', __FILE__), array(), $this->version);
        wp_enqueue_script('nb-chain-link-admin', plugins_url('js/admin.js', __FILE__), array('jquery'), $this->version, true);
    }

    public function render_admin_page() {
        $site_info = $this->get_site_info();
        $hosted = $this->get_hosted_rings();
        $joined = $this->get_joined_rings();
        $widget_settings = $this->get_widget_settings();

        ?>
        <div class="wrap nb-chain-link-admin">
            <h1>🔗 NB Chain Link <span class="version">v<?php echo esc_html($this->version); ?></span></h1>
            <p class="description">Modern Web Rings - Create or join distributed link chains</p>

            <?php settings_errors('nb_chain_link'); ?>

            <div class="nb-admin-grid">

                <!-- My Site Info -->
                <div class="nb-admin-box">
                    <h2>📍 My Site Info</h2>
                    <p class="description">This info is shared when you join rings</p>
                    <form method="post">
                        <?php wp_nonce_field('nb_chain_link_admin'); ?>
                        <input type="hidden" name="nb_chain_link_action" value="save_site_info">

                        <table class="form-table">
                            <tr>
                                <th>Site Name</th>
                                <td><input type="text" name="site_name" value="<?php echo esc_attr($site_info['name']); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Site URL</th>
                                <td><input type="url" name="site_url" value="<?php echo esc_attr($site_info['url']); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Widget Page URL</th>
                                <td>
                                    <input type="url" name="page_url" value="<?php echo esc_attr($site_info['page_url']); ?>" class="regular-text">
                                    <p class="description">Where visitors can find your web ring widget</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Banner Image</th>
                                <td>
                                    <input type="url" name="image" id="site_image" value="<?php echo esc_attr($site_info['image']); ?>" class="regular-text">
                                    <button type="button" class="button nb-media-upload" data-target="site_image">Select</button>
                                    <p class="description">Recommended: 200x80px</p>
                                    <?php if ($site_info['image']): ?>
                                        <img src="<?php echo esc_url($site_info['image']); ?>" style="max-width:200px;margin-top:10px;display:block;">
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Short Description</th>
                                <td><textarea name="excerpt" rows="2" class="large-text"><?php echo esc_textarea($site_info['excerpt']); ?></textarea></td>
                            </tr>
                        </table>

                        <p><button type="submit" class="button button-primary">Save Site Info</button></p>
                    </form>
                </div>

                <!-- Widget Settings -->
                <div class="nb-admin-box">
                    <h2>⚙️ Widget Defaults</h2>
                    <p class="description">Default display settings (shortcodes can override)</p>
                    <form method="post">
                        <?php wp_nonce_field('nb_chain_link_admin'); ?>
                        <input type="hidden" name="nb_chain_link_action" value="save_widget_settings">

                        <table class="form-table">
                            <tr>
                                <th>Display Mode</th>
                                <td>
                                    <select name="widget_mode">
                                        <option value="carousel" <?php selected($widget_settings['mode'], 'carousel'); ?>>Carousel - Preview members, click to visit</option>
                                        <option value="live" <?php selected($widget_settings['mode'], 'live'); ?>>Live Links - Prev/Next navigate immediately</option>
                                        <option value="directory" <?php selected($widget_settings['mode'], 'directory'); ?>>Directory - Show all members as list</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Theme</th>
                                <td>
                                    <select name="widget_theme">
                                        <option value="light" <?php selected($widget_settings['theme'], 'light'); ?>>Light - White background</option>
                                        <option value="dark" <?php selected($widget_settings['theme'], 'dark'); ?>>Dark - Dark background</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Width</th>
                                <td>
                                    <select name="widget_width">
                                        <option value="compact" <?php selected($widget_settings['width'], 'compact'); ?>>Compact - 350px</option>
                                        <option value="full" <?php selected($widget_settings['width'], 'full'); ?>>Full - 100% width</option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <p><button type="submit" class="button button-primary">Save Widget Settings</button></p>
                    </form>
                </div>

                <!-- Create Ring -->
                <div class="nb-admin-box">
                    <h2>➕ Create New Ring</h2>
                    <form method="post">
                        <?php wp_nonce_field('nb_chain_link_admin'); ?>
                        <input type="hidden" name="nb_chain_link_action" value="create_ring">

                        <table class="form-table">
                            <tr>
                                <th>Ring ID</th>
                                <td>
                                    <input type="text" name="ring_id" required pattern="[a-z0-9_-]+" class="regular-text">
                                    <p class="description">Lowercase, no spaces (e.g., chess-ring)</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Display Name</th>
                                <td><input type="text" name="ring_name" required class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Ring Type</th>
                                <td>
                                    <select name="ring_type" id="ring_type">
                                        <option value="open">Open - Anyone joins instantly</option>
                                        <option value="moderated">Moderated - You approve members</option>
                                        <option value="private">Private - Invite code required</option>
                                        <option value="curated">Curated - You add members manually (no plugin needed)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="secret_row" style="display:none;">
                                <th>Invite Code</th>
                                <td><input type="text" name="ring_secret" class="regular-text"></td>
                            </tr>
                        </table>

                        <p><button type="submit" class="button button-primary">Create Ring</button></p>
                    </form>
                </div>

                <!-- Join Ring -->
                <div class="nb-admin-box">
                    <h2>🤝 Join a Ring</h2>
                    <form method="post">
                        <?php wp_nonce_field('nb_chain_link_admin'); ?>
                        <input type="hidden" name="nb_chain_link_action" value="join_ring">

                        <table class="form-table">
                            <tr>
                                <th>Host Site URL</th>
                                <td>
                                    <input type="url" name="host_url" required class="regular-text" placeholder="https://host-site.com">
                                    <p class="description">The site that hosts the ring</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Ring ID</th>
                                <td><input type="text" name="join_ring_id" required class="regular-text" placeholder="chess-ring"></td>
                            </tr>
                            <tr>
                                <th>Invite Code</th>
                                <td>
                                    <input type="text" name="join_secret" class="regular-text">
                                    <p class="description">Only needed for private rings</p>
                                </td>
                            </tr>
                        </table>

                        <p><button type="submit" class="button button-primary">Send Join Request</button></p>
                    </form>
                </div>

            </div>

            <!-- Rings I Host -->
            <?php if (!empty($hosted)): ?>
            <div class="nb-admin-section">
                <h2>🏠 Rings I Host</h2>

                <?php foreach ($hosted as $ring_id => $ring): ?>
                <div class="nb-ring-card">
                    <div class="nb-ring-header">
                        <h3><?php echo esc_html($ring['name']); ?></h3>
                        <span class="nb-ring-type nb-type-<?php echo esc_attr($ring['type']); ?>"><?php echo ucfirst($ring['type']); ?></span>
                        <code class="nb-shortcode">[nb_chain_link ring="<?php echo esc_attr($ring_id); ?>"]</code>
                    </div>

                    <div class="nb-ring-stats">
                        <span><?php echo count($ring['members']); ?> members</span>
                        <?php if (!empty($ring['pending'])): ?>
                            <span class="nb-pending">⏳ <?php echo count($ring['pending']); ?> pending</span>
                        <?php endif; ?>
                        <?php if ($ring['type'] === 'private'): ?>
                            <span>🔑 Code: <code><?php echo esc_html($ring['secret']); ?></code></span>
                        <?php endif; ?>
                    </div>

                    <!-- Pending Approvals -->
                    <?php if (!empty($ring['pending'])): ?>
                    <div class="nb-pending-list">
                        <h4>Pending Approvals</h4>
                        <?php foreach ($ring['pending'] as $pending): ?>
                        <div class="nb-member-item nb-pending-item">
                            <span class="nb-member-name"><?php echo esc_html($pending['name']); ?></span>
                            <span class="nb-member-url"><?php echo esc_html($pending['url']); ?></span>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('nb_chain_link_admin'); ?>
                                <input type="hidden" name="ring_id" value="<?php echo esc_attr($ring_id); ?>">
                                <input type="hidden" name="member_url" value="<?php echo esc_attr($pending['url']); ?>">
                                <button type="submit" name="nb_chain_link_action" value="approve_member" class="button button-small">✓ Approve</button>
                                <button type="submit" name="nb_chain_link_action" value="reject_member" class="button button-small">✗ Reject</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Member List -->
                    <div class="nb-member-list">
                        <h4>Members</h4>
                        <?php foreach ($ring['members'] as $member): ?>
                        <div class="nb-member-item <?php echo ($member['status'] ?? 'active') === 'dead' ? 'nb-dead' : ''; ?>">
                            <?php if (!empty($member['image'])): ?>
                                <img src="<?php echo esc_url($member['image']); ?>" class="nb-member-thumb">
                            <?php endif; ?>
                            <span class="nb-member-name"><?php echo esc_html($member['name']); ?></span>
                            <span class="nb-member-url"><?php echo esc_html($member['url']); ?></span>
                            <?php if (($member['status'] ?? 'active') === 'dead'): ?>
                                <span class="nb-status-dead">💀 Dead</span>
                            <?php endif; ?>
                            <?php
                            // Calculate average rating
                            $avg_rating = 0;
                            if (!empty($member['ratings'])) {
                                $avg_rating = array_sum($member['ratings']) / count($member['ratings']);
                            }
                            if ($avg_rating > 0):
                            ?>
                                <span class="nb-rating">⭐ <?php echo number_format($avg_rating, 1); ?></span>
                            <?php endif; ?>

                            <?php if ($member['url'] !== $this->get_site_info()['url']): ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('nb_chain_link_admin'); ?>
                                <input type="hidden" name="ring_id" value="<?php echo esc_attr($ring_id); ?>">
                                <input type="hidden" name="member_url" value="<?php echo esc_attr($member['url']); ?>">
                                <button type="submit" name="nb_chain_link_action" value="remove_member" class="button button-small" onclick="return confirm('Remove this member?')">Remove</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Add Curated Member -->
                    <?php if ($ring['type'] === 'curated'): ?>
                    <div class="nb-add-curated">
                        <h4>Add Member Manually</h4>
                        <form method="post" class="nb-curated-form">
                            <?php wp_nonce_field('nb_chain_link_admin'); ?>
                            <input type="hidden" name="nb_chain_link_action" value="add_curated_member">
                            <input type="hidden" name="ring_id" value="<?php echo esc_attr($ring_id); ?>">

                            <input type="text" name="member_name" placeholder="Site Name" required>
                            <input type="url" name="member_url" placeholder="https://site.com" required>
                            <input type="url" name="member_page_url" placeholder="Page URL (optional)">
                            <input type="url" name="member_image" placeholder="Banner Image URL">
                            <input type="text" name="member_excerpt" placeholder="Short description">
                            <button type="submit" class="button">Add Member</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Delete Ring -->
                    <div class="nb-ring-actions">
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('nb_chain_link_admin'); ?>
                            <input type="hidden" name="ring_id" value="<?php echo esc_attr($ring_id); ?>">
                            <button type="submit" name="nb_chain_link_action" value="delete_ring" class="button button-link-delete" onclick="return confirm('Delete this ring? This cannot be undone.')">Delete Ring</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Rings I've Joined -->
            <?php if (!empty($joined)): ?>
            <div class="nb-admin-section">
                <h2>🔗 Rings I've Joined</h2>

                <?php foreach ($joined as $key => $ring): ?>
                <div class="nb-ring-card nb-joined-ring">
                    <div class="nb-ring-header">
                        <h3><?php echo esc_html($ring['name']); ?></h3>
                        <?php if ($ring['pending']): ?>
                            <span class="nb-ring-type nb-type-pending">⏳ Pending Approval</span>
                        <?php else: ?>
                            <span class="nb-ring-type nb-type-member">✓ Member</span>
                        <?php endif; ?>
                        <code class="nb-shortcode">[nb_chain_link ring="<?php echo esc_attr($key); ?>"]</code>
                    </div>

                    <div class="nb-ring-stats">
                        <span>Host: <?php echo esc_html($ring['host_url']); ?></span>
                        <span>Ring ID: <?php echo esc_html($ring['ring_id']); ?></span>
                        <span><?php echo count($ring['members']); ?> members</span>
                        <?php if ($ring['last_sync']): ?>
                            <span>Last sync: <?php echo human_time_diff($ring['last_sync']); ?> ago</span>
                        <?php endif; ?>
                    </div>

                    <!-- Member Preview -->
                    <?php if (!empty($ring['members'])): ?>
                    <div class="nb-member-list nb-collapsed">
                        <h4>Members <button type="button" class="button button-small nb-toggle-members">Show</button></h4>
                        <div class="nb-members-content" style="display:none;">
                            <?php foreach ($ring['members'] as $member): ?>
                            <div class="nb-member-item">
                                <span class="nb-member-name"><?php echo esc_html($member['name']); ?></span>
                                <span class="nb-member-url"><?php echo esc_html($member['url']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="nb-ring-actions">
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('nb_chain_link_admin'); ?>
                            <input type="hidden" name="ring_key" value="<?php echo esc_attr($key); ?>">
                            <button type="submit" name="nb_chain_link_action" value="leave_ring" class="button button-link-delete" onclick="return confirm('Leave this ring?')">Leave Ring</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- API Info -->
            <div class="nb-admin-section nb-api-info">
                <h2>🔌 API Endpoints</h2>
                <p>Your site's API base: <code><?php echo esc_html(rest_url('nb-chain-link/v1/')); ?></code></p>
                <ul>
                    <li><code>GET /ping</code> - Health check</li>
                    <li><code>GET /ring/{id}</code> - Get ring data</li>
                    <li><code>POST /ring/{id}/join</code> - Request to join</li>
                    <li><code>POST /ring/{id}/rate</code> - Submit rating</li>
                </ul>
            </div>

        </div>
        <?php
    }

    // =========================================================================
    // FRONTEND SHORTCODES
    // =========================================================================

    public function enqueue_frontend_assets() {
        wp_enqueue_style('nb-chain-link', plugins_url('css/widget.css', __FILE__), array(), $this->version);
        wp_enqueue_script('nb-chain-link', plugins_url('js/widget.js', __FILE__), array('jquery'), $this->version, true);
        wp_localize_script('nb-chain-link', 'nbChainLink', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nb_chain_link_rate'),
            'site_url' => home_url('/')
        ));
    }

    public function render_widget_shortcode($atts) {
        $atts = shortcode_atts(array(
            'ring' => '',
            'mode' => '',
            'theme' => '',
            'width' => ''
        ), $atts);

        if (empty($atts['ring'])) {
            return '<p class="nb-chain-link-error">Please specify a ring ID</p>';
        }

        // Get ring data (hosted or joined)
        $ring_id = $atts['ring'];
        $ring_data = null;
        $is_host = false;

        $hosted = $this->get_hosted_rings();
        if (isset($hosted[$ring_id])) {
            $ring_data = $hosted[$ring_id];
            $is_host = true;
        } else {
            $joined = $this->get_joined_rings();
            if (isset($joined[$ring_id])) {
                $ring_data = $joined[$ring_id];
            }
        }

        if (!$ring_data || empty($ring_data['members'])) {
            return '<p class="nb-chain-link-error">Ring not found or empty</p>';
        }

        // Get display settings (shortcode overrides defaults)
        $defaults = $this->get_widget_settings();
        $mode = !empty($atts['mode']) ? $atts['mode'] : $defaults['mode'];
        $theme = !empty($atts['theme']) ? $atts['theme'] : $defaults['theme'];
        $width = !empty($atts['width']) ? $atts['width'] : $defaults['width'];

        // Filter out dead members
        $members = array_filter($ring_data['members'], function($m) {
            return ($m['status'] ?? 'active') !== 'dead';
        });
        $members = array_values($members);

        if (empty($members)) {
            return '<p class="nb-chain-link-error">No active members in this ring</p>';
        }

        // Build widget HTML based on mode
        $widget_class = "nb-chain-link-widget nb-mode-{$mode} nb-theme-{$theme} nb-width-{$width}";
        $ring_name = $ring_data['name'] ?? $ring_id;

        ob_start();

        if ($mode === 'directory') {
            echo $this->render_directory_widget($members, $ring_name, $ring_id, $widget_class);
        } else {
            echo $this->render_carousel_widget($members, $ring_name, $ring_id, $widget_class, $mode);
        }

        return ob_get_clean();
    }

    private function render_carousel_widget($members, $ring_name, $ring_id, $widget_class, $mode) {
        $members_json = esc_attr(json_encode($members));
        $current_site = $this->get_site_info()['url'];

        // Find current site index
        $current_index = 0;
        foreach ($members as $i => $m) {
            if ($m['url'] === $current_site) {
                $current_index = $i;
                break;
            }
        }

        // Start at next member
        $display_index = ($current_index + 1) % count($members);
        $member = $members[$display_index];

        $html = '<div class="' . esc_attr($widget_class) . '" data-ring="' . esc_attr($ring_id) . '" data-mode="' . esc_attr($mode) . '" data-members="' . $members_json . '" data-index="' . $display_index . '">';

        // Main display area
        $html .= '<div class="nb-widget-main">';
        $html .= '<button class="nb-nav nb-prev" title="Previous">◀</button>';

        $html .= '<div class="nb-member-display">';
        if (!empty($member['image'])) {
            $html .= '<div class="nb-member-image"><img src="' . esc_url($member['image']) . '" alt=""></div>';
        }
        $html .= '<div class="nb-member-info">';
        $html .= '<div class="nb-member-name">' . esc_html($member['name']) . '</div>';
        if (!empty($member['excerpt'])) {
            $html .= '<div class="nb-member-excerpt">' . esc_html($member['excerpt']) . '</div>';
        }

        // Star rating display
        $avg_rating = 0;
        if (!empty($member['ratings'])) {
            $avg_rating = array_sum($member['ratings']) / count($member['ratings']);
        }
        if ($avg_rating > 0) {
            $html .= '<div class="nb-member-rating">' . $this->render_stars($avg_rating) . ' (' . number_format($avg_rating, 1) . ')</div>';
        }

        $html .= '</div>'; // .nb-member-info
        $html .= '</div>'; // .nb-member-display

        $html .= '<button class="nb-nav nb-next" title="Next">▶</button>';
        $html .= '</div>'; // .nb-widget-main

        // Action bar
        $html .= '<div class="nb-widget-actions">';
        $html .= '<button class="nb-btn nb-random" title="Random site">🎲 Random</button>';
        $html .= '<a href="' . esc_url($member['page_url'] ?: $member['url']) . '" class="nb-btn nb-visit" target="_blank">Visit →</a>';

        $html .= '</div>'; // .nb-widget-actions

        // Ring name footer
        $html .= '<div class="nb-widget-footer">';
        $html .= '<span class="nb-ring-name">' . esc_html($ring_name) . '</span>';
        $html .= '</div>';

        $html .= '</div>'; // .nb-chain-link-widget

        return $html;
    }

    private function render_directory_widget($members, $ring_name, $ring_id, $widget_class) {
        $html = '<div class="' . esc_attr($widget_class) . '" data-ring="' . esc_attr($ring_id) . '">';

        $html .= '<div class="nb-directory-header">';
        $html .= '<h3>' . esc_html($ring_name) . '</h3>';
        $html .= '<span class="nb-member-count">' . count($members) . ' sites</span>';
        $html .= '</div>';

        $html .= '<div class="nb-directory-list">';

        foreach ($members as $member) {
            $html .= '<div class="nb-directory-item">';

            if (!empty($member['image'])) {
                $html .= '<img src="' . esc_url($member['image']) . '" class="nb-dir-thumb" alt="">';
            }

            $html .= '<div class="nb-dir-info">';
            $html .= '<a href="' . esc_url($member['page_url'] ?: $member['url']) . '" class="nb-dir-name" target="_blank">' . esc_html($member['name']) . '</a>';

            if (!empty($member['excerpt'])) {
                $html .= '<div class="nb-dir-excerpt">' . esc_html($member['excerpt']) . '</div>';
            }

            // Rating
            $avg_rating = 0;
            if (!empty($member['ratings'])) {
                $avg_rating = array_sum($member['ratings']) / count($member['ratings']);
            }
            if ($avg_rating > 0) {
                $html .= '<div class="nb-dir-rating">' . $this->render_stars($avg_rating) . ' (' . number_format($avg_rating, 1) . ')</div>';
            }

            $html .= '</div>'; // .nb-dir-info

            // Rate button for logged-in users with plugin
            if (is_user_logged_in()) {
                $html .= '<button class="nb-rate-btn" data-url="' . esc_attr($member['url']) . '" title="Rate this site">⭐</button>';
            }

            $html .= '</div>'; // .nb-directory-item
        }

        $html .= '</div>'; // .nb-directory-list
        $html .= '</div>'; // .nb-chain-link-widget

        return $html;
    }

    private function render_stars($rating) {
        $full = floor($rating);
        $half = ($rating - $full) >= 0.5 ? 1 : 0;
        $empty = 5 - $full - $half;

        $stars = str_repeat('⭐', $full);
        if ($half) $stars .= '⭐';
        $stars .= str_repeat('☆', $empty);

        return $stars;
    }

    public function render_directory_shortcode($atts) {
        // Force directory mode
        $atts['mode'] = 'directory';
        return $this->render_widget_shortcode($atts);
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_submit_rating() {
        check_ajax_referer('nb_chain_link_rate', 'nonce');

        $ring_id = sanitize_text_field($_POST['ring_id']);
        $target_url = esc_url_raw($_POST['target_url']);
        $rating = intval($_POST['rating']);

        if ($rating < 1 || $rating > 5) {
            wp_send_json_error('Invalid rating');
        }

        // Check if this is a hosted ring
        $hosted = $this->get_hosted_rings();
        if (isset($hosted[$ring_id])) {
            // Rate locally
            $site_info = $this->get_site_info();

            foreach ($hosted[$ring_id]['members'] as &$member) {
                if ($member['url'] === $target_url) {
                    if (!isset($member['ratings'])) {
                        $member['ratings'] = array();
                    }
                    $member['ratings'][$site_info['url']] = $rating;
                    break;
                }
            }

            $hosted[$ring_id]['updated'] = time();
            $this->save_hosted_rings($hosted);
            wp_send_json_success('Rating saved');
        }

        // For joined rings, send to host
        $joined = $this->get_joined_rings();
        if (isset($joined[$ring_id])) {
            $host_url = $joined[$ring_id]['host_url'];
            $actual_ring_id = $joined[$ring_id]['ring_id'];
            $site_info = $this->get_site_info();

            $api_url = trailingslashit($host_url) . 'wp-json/nb-chain-link/v1/ring/' . $actual_ring_id . '/rate';

            $response = wp_remote_post($api_url, array(
                'timeout' => 15,
                'sslverify' => false,
                'body' => array(
                    'target_url' => $target_url,
                    'rating' => $rating,
                    'rater_url' => $site_info['url']
                )
            ));

            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                wp_send_json_error($data['message'] ?? 'Rating failed');
            }

            wp_send_json_success('Rating submitted');
        }

        wp_send_json_error('Ring not found');
    }
}

// Initialize
NB_Chain_Link::get_instance();

// Deactivation - clear cron
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('nb_chain_link_health_check');
});
