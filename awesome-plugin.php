<?php
/**
 * Plugin Name: Awesome Plugin
 * Description: Helper plugin for license checker
 * Version: 1.0.0
 * Author: DigiHold
 * Author URI: https://digihold.me
 * Text Domain: awesome-plugin
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * 
 * SETUP INSTRUCTIONS:
 * 1. Create the following folder structure in your plugin directory:
 *    /assets/
 *    /assets/css/
 *    /assets/js/
 * 
 * 2. Add the provided license.css file to /assets/css/license.css
 * 3. Add the provided license.js file to /assets/js/license.js
 * 
 * 4. Update the constants below for your product:
 *    - AWESOME_API_URL: Your DigiCommerce site URL
 *    - AWESOME_PLUGIN_SLUG: Your product slug (must match your product in DigiCommerce)
 *    - AWESOME_PLUGIN_NAME: Display name for your plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AWESOME_PLUGIN_VERSION', '1.0.0');
define('AWESOME_PLUGIN_FILE', __FILE__);
define('AWESOME_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AWESOME_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AWESOME_PLUGIN_BASENAME', plugin_basename(__FILE__));

// API Configuration - CHANGE THESE FOR YOUR PRODUCT
define('AWESOME_API_URL', 'https://your-website.com');
define('AWESOME_PLUGIN_SLUG', 'your-product-slug');
define('AWESOME_PLUGIN_NAME', 'Your Product Name');

/**
 * Main Plugin License Manager Class
 */
class Awesome_Plugin_License_Manager {
    
    private static $instance = null;
    private $api_url;
    private $product_slug;
    private $plugin_name;
    private $version;
    private $basename;
    private $plugin_file;
    
    // Cache durations
    private const LICENSE_CACHE_DURATION = 12 * HOUR_IN_SECONDS;
    private const UPDATE_CHECK_DURATION = 12 * HOUR_IN_SECONDS;

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Initialize plugin properties
        $this->product_slug = AWESOME_PLUGIN_SLUG;
        $this->plugin_name = AWESOME_PLUGIN_NAME;
        $this->version = AWESOME_PLUGIN_VERSION;
        $this->basename = AWESOME_PLUGIN_BASENAME;
        $this->plugin_file = AWESOME_PLUGIN_FILE;
        $this->api_url = trailingslashit(AWESOME_API_URL) . 'wp-json/digicommerce/v2';

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_license_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers
        add_action('wp_ajax_' . $this->product_slug . '_activate_license', array($this, 'ajax_activate_license'));
        add_action('wp_ajax_' . $this->product_slug . '_deactivate_license', array($this, 'ajax_deactivate_license'));
        add_action('wp_ajax_' . $this->product_slug . '_verify_license', array($this, 'ajax_verify_license'));

        // Update system hooks
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        add_filter('plugins_api', array($this, 'plugin_information'), 10, 3);

        // Cache management hooks
        add_action('upgrader_process_complete', array($this, 'clear_update_caches'), 10, 2);
        add_action('activated_plugin', array($this, 'clear_license_cache'));
        add_action('deactivated_plugin', array($this, 'clear_license_cache'));
        add_action('admin_init', array($this, 'maybe_clear_transients_on_version_change'));
    }

    /**
     * Add license management menu
     */
    public function add_license_menu() {
        add_options_page(
            $this->plugin_name . ' ' . __('License', 'awesome-plugin'),
            $this->plugin_name,
            'manage_options',
            $this->product_slug . '-license',
            array($this, 'render_license_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ('settings_page_' . $this->product_slug . '-license' !== $hook) {
            return;
        }

        // Enqueue CSS file
        wp_enqueue_style(
            $this->product_slug . '-license-admin',
            AWESOME_PLUGIN_URL . 'assets/css/license.css',
            array(),
            $this->version
        );

        // Enqueue JavaScript file
        wp_enqueue_script(
            $this->product_slug . '-license-admin',
            AWESOME_PLUGIN_URL . 'assets/js/license.js',
            array(),
            $this->version,
            true
        );

        // Localize script with data
        wp_localize_script(
            $this->product_slug . '-license-admin',
            'awesomeLicense',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce($this->product_slug . '_license_nonce'),
                'pluginSlug' => $this->product_slug,
                'strings' => array(
                    'activating' => __('Activating...', 'awesome-plugin'),
                    'deactivating' => __('Deactivating...', 'awesome-plugin'),
                    'verifying' => __('Verifying...', 'awesome-plugin'),
                    'confirmDeactivate' => __('Are you sure you want to deactivate this license?', 'awesome-plugin'),
                    'error' => __('An error occurred. Please try again.', 'awesome-plugin'),
                    'enterLicenseKey' => __('Please enter a license key', 'awesome-plugin'),
                    'licenseActivated' => __('License activated successfully!', 'awesome-plugin'),
                    'licenseDeactivated' => __('License deactivated successfully!', 'awesome-plugin'),
                    'licenseVerified' => __('License verified successfully!', 'awesome-plugin')
                )
            )
        );
    }



    /**
     * Make API request - Following DigiCommerce pattern
     */
    private function make_api_request($endpoint, $body = array()) {
        // Always include product slug for validation (this is the key fix!)
        $body['product_slug'] = $this->product_slug;

        return wp_remote_post(
            trailingslashit($this->api_url) . $endpoint,
            array(
                'timeout' => 15,
                'body'    => $body,
            )
        );
    }

    /**
     * Detect site type (production, staging, development)
     */
    private function is_development_site($site_url) {
        $host = wp_parse_url($site_url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $dev_patterns = array(
            '/\.local$/',
            '/\.test$/',
            '/\.localhost$/',
            '/\.dev$/',
            '/\.staging\./',
            '/\.development\./',
            '/^localhost/',
            '/\.example\./',
            '/\.invalid$/',
            '/^127\.\d+\.\d+\.\d+$/',
            '/^192\.168\./',
            '/^10\./',
            '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
        );

        foreach ($dev_patterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get bundle information if this is a bundle license
     */
    private function get_bundle_products() {
        $license = $this->get_license_details();
        if (!$license || 'active' !== $license['status']) {
            return false;
        }

        $license_key = get_option($this->product_slug . '_license_key');
        if (!$license_key) {
            return false;
        }

        // Make API request to get bundle products
        $response = $this->make_api_request(
            'license/bundle-products',
            array(
                'license_key' => $license_key,
                'site_url'    => home_url(),
            )
        );

        if (is_wp_error($response)) {
            return false;
        }

        $bundle_data = json_decode(wp_remote_retrieve_body($response), true);
        
        return isset($bundle_data['products']) ? $bundle_data['products'] : false;
    }

    /**
     * Check if this is a bundle/master license
     */
    private function is_bundle_license() {
        $bundle_products = $this->get_bundle_products();
        return !empty($bundle_products) && is_array($bundle_products) && count($bundle_products) > 1;
    }

    /**
     * Get license details with caching
     */
    private function get_license_details() {
        // Try to get cached license details first.
        $cached = get_transient($this->product_slug . '_license_details');
        if (false !== $cached) {
            return $cached;
        }

        $license_key = get_option($this->product_slug . '_license_key');
        if (!$license_key) {
            return false;
        }

        // Make API request to verify license.
        $response = $this->make_api_request(
            'license/verify',
            array(
                'license_key' => $license_key,
                'site_url'    => home_url(),
                'product_slug' => $this->product_slug,
            )
        );

        if (is_wp_error($response)) {
            // If API request fails, use cached data if available.
            $fallback = get_option($this->product_slug . '_license_status');
            if ($fallback) {
                return $fallback;
            }
            return false;
        }

        $license_data = json_decode(wp_remote_retrieve_body($response), true);

        // Cache the result for 12 hours.
        set_transient($this->product_slug . '_license_details', $license_data, self::LICENSE_CACHE_DURATION);

        // Also store as a fallback.
        update_option($this->product_slug . '_license_status', $license_data);

        return $license_data;
    }

    /**
     * AJAX: Activate license
     */
    public function ajax_activate_license() {
        check_ajax_referer($this->product_slug . '_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'awesome-plugin')));
        }

        $license_key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';

        if (empty($license_key)) {
            wp_send_json_error(array('message' => __('Please enter a license key.', 'awesome-plugin')));
        }

        $response = $this->make_api_request(
            'license/activate',
            array(
                'license_key' => $license_key,
                'site_url'    => home_url(),
                'site_type'   => $this->is_development_site(home_url()) ? 'development' : 'production',
                'product_slug' => $this->product_slug,
            )
        );

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        // Handle specific error for wrong product
        if (!empty($result['code']) && 'wrong_product' === $result['code']) {
            wp_send_json_error(array('message' => sprintf(__('This license is not valid for %s. Please check your license key.', 'awesome-plugin'), $this->plugin_name)));
        }

        if (!empty($result['status']) && 'success' === $result['status']) {
            update_option($this->product_slug . '_license_key', $license_key);
            delete_transient($this->product_slug . '_license_details');
            delete_transient($this->product_slug . '_update_check');
            wp_send_json_success(array('message' => __('License activated successfully!', 'awesome-plugin')));
        }

        wp_send_json_error(array('message' => $result['message'] ?? __('Failed to activate license.', 'awesome-plugin')));
    }

    /**
     * AJAX: Deactivate license
     */
    public function ajax_deactivate_license() {
        check_ajax_referer($this->product_slug . '_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'awesome-plugin')));
        }

        $license_key = get_option($this->product_slug . '_license_key');
        if (!$license_key) {
            wp_send_json_error(array('message' => __('No license key found.', 'awesome-plugin')));
        }

        $response = $this->make_api_request(
            'license/deactivate',
            array(
                'license_key' => $license_key,
                'site_url'    => home_url(),
                'product_slug' => $this->product_slug,
            )
        );

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($result['status']) && 'success' === $result['status']) {
            delete_option($this->product_slug . '_license_key');
            delete_option($this->product_slug . '_license_status');
            delete_transient($this->product_slug . '_license_details');
            delete_transient($this->product_slug . '_update_check');
            wp_send_json_success(array('message' => __('License deactivated successfully!', 'awesome-plugin')));
        }

        wp_send_json_error(array('message' => $result['message'] ?? __('Failed to deactivate license.', 'awesome-plugin')));
    }

    /**
     * AJAX: Verify license
     */
    public function ajax_verify_license() {
        try {
            check_ajax_referer($this->product_slug . '_license_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Insufficient permissions.', 'awesome-plugin')));
            }

            // Get license key and verify
            $license_key = get_option($this->product_slug . '_license_key');
            if (!$license_key) {
                wp_send_json_error(array('message' => __('No license key found.', 'awesome-plugin')));
            }

            $response = $this->make_api_request(
                'license/verify',
                array(
                    'license_key' => $license_key,
                    'site_url'    => home_url(),
                    'product_slug' => $this->product_slug,
                )
            );

            if (is_wp_error($response)) {
                wp_send_json_error(array('message' => $response->get_error_message()));
            }

            // Decode the response body
            $response_data = json_decode(wp_remote_retrieve_body($response), true);
            
            // Validate response data
            if (!is_array($response_data)) {
                throw new Exception(__('Invalid response from license server.', 'awesome-plugin'));
            }

            // Check for wrong product error
            if (!empty($response_data['code']) && 'wrong_product' === $response_data['code']) {
                // Delete all license data for wrong product
                delete_option($this->product_slug . '_license_key');
                delete_option($this->product_slug . '_license_status');
                delete_transient($this->product_slug . '_license_details');
                delete_transient($this->product_slug . '_update_check');

                wp_send_json_error(array( 
                    'message' => sprintf(__('This license is not valid for %s. License has been removed.', 'awesome-plugin'), $this->plugin_name)
                ));
            }

            // Update stored data
            $license_data = array(
                'status'     => isset($response_data['status']) ? sanitize_text_field($response_data['status']) : 'invalid',
                'expires_at' => isset($response_data['expires_at']) ? sanitize_text_field($response_data['expires_at']) : null,
                'last_check' => current_time('mysql'),
            );

            // If verification failed or license is invalid/expired
            if ('active' !== $license_data['status'] || 
                (!empty($license_data['expires_at']) && strtotime($license_data['expires_at']) < time())
            ) {
                // Delete all license data
                delete_option($this->product_slug . '_license_key');
                delete_option($this->product_slug . '_license_status');
                delete_transient($this->product_slug . '_license_details');
                delete_transient($this->product_slug . '_update_check');

                wp_send_json_error(array( 
                    'message' => __('License verification failed. Your license is no longer valid.', 'awesome-plugin')
                ));
            }

            // Update stored data and cache
            update_option($this->product_slug . '_license_status', $license_data);
            set_transient($this->product_slug . '_license_details', $license_data, self::LICENSE_CACHE_DURATION);

            wp_send_json_success(array(
                'message' => __('License verified successfully.', 'awesome-plugin'),
                'license' => array(
                    'status'     => $license_data['status'],
                    'expires_at' => $license_data['expires_at'],
                    'key'        => $license_key,
                ),
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Check for plugin updates
     */
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Check if this is a forced update check
        $force_check = false;
        if (isset($_GET['force-check']) || 
            (isset($_POST['action']) && $_POST['action'] === 'update-core') ||
            (wp_doing_ajax() && isset($_POST['action']) && $_POST['action'] === 'update-plugin')) {
            $force_check = true;
        }

        // Verify license first.
        $license = $this->get_license_details();
        if (!$license || 'active' !== $license['status']) {
            return $transient;
        }

        // Check if plugin is in WordPress checked list
        if (!isset($transient->checked[$this->basename])) {
            return $transient;
        }

        if (!$force_check) {
            $last_check = get_transient($this->product_slug . '_update_check');
            if (false !== $last_check) {
                return $transient;
            }
        }

        $response = $this->make_api_request(
            'license/updates',
            array(
                'license_key' => get_option($this->product_slug . '_license_key'),
                'site_url'    => home_url(),
                'product_slug' => $this->product_slug,
                'current_version' => $this->version,
            )
        );

        if (is_wp_error($response)) {
            return $transient;
        }

        $update_data = json_decode(wp_remote_retrieve_body($response), true);

        // Check for wrong product error
        if (!empty($update_data['code']) && 'wrong_product' === $update_data['code']) {
            // Clear license data and stop checking for updates
            delete_option($this->product_slug . '_license_key');
            delete_option($this->product_slug . '_license_status');
            delete_transient($this->product_slug . '_license_details');
            return $transient;
        }

        if (!empty($update_data['new_version']) && 
            !empty($update_data['package']) && 
            version_compare($this->version, $update_data['new_version'], '<')) {
            
            $update_object = (object) array(
                'slug'        => dirname($this->basename),
                'plugin'      => $this->basename,
                'icons'       => $update_data['icons'] ?? array(),
                'new_version' => $update_data['new_version'],
                'package'     => $update_data['package'],
                'tested'      => $update_data['tested'] ?? '',
                'requires'    => $update_data['requires'] ?? '',
                'id'          => $this->basename,
            );
            
            $transient->response[$this->basename] = $update_object;
        }

        // Cache the check for 12 hours.
        set_transient($this->product_slug . '_update_check', true, self::UPDATE_CHECK_DURATION);

        return $transient;
    }

    /**
     * Plugin info for updates
     */
    public function plugin_information($result, $action, $args) {
        // Only handle our plugin.
        if ('plugin_information' !== $action || $this->product_slug !== $args->slug) {
            return $result;
        }

        // Verify license.
        $license = $this->get_license_details();
        if (!$license || 'active' !== $license['status']) {
            return $result;
        }

        $response = $this->make_api_request(
            'license/updates',
            array(
                'license_key' => get_option($this->product_slug . '_license_key'),
                'site_url'    => home_url(),
                'product_slug' => $this->product_slug,
                'current_version' => $this->version,
            )
        );

        if (is_wp_error($response)) {
            return $result;
        }

        $info = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($info)) {
            return $result;
        }

        // Check for wrong product error
        if (!empty($info['code']) && 'wrong_product' === $info['code']) {
            return $result;
        }

        // Format description, installation, and changelog
        $description = '';
        if (!empty($info['description'])) {
            $description = $info['description'];
            $description = str_replace('\n\n', "\n\n", $description);
            $description = str_replace('\n', "\n", $description);
            $description = preg_replace('/\* \n/', "* ", $description);
            $description = preg_replace('/\s+/', ' ', $description);
            $description = preg_replace('/\n(#+)/', "\n\n$1", $description);
        }

        $installation = '';
        if (!empty($info['installation'])) {
            $installation = $info['installation'];
            $installation = str_replace('\n\n', "\n\n", $installation);
            $installation = str_replace('\n', "\n", $installation);
            $installation = preg_replace('/(\d+)\. \n/', "$1. ", $installation);
        }

        $changelog = '';
        if (!empty($info['changelog']) && !empty($info['new_version'])) {
            $changelog_items = explode("\n", str_replace('\n', "\n", $info['changelog']));
            
            $changelog = sprintf(
                '<h4>%s</h4>' . "\n" .
                '<p><em>%s %s</em></p>' . "\n" .
                '<ul>' . "\n",
                esc_html($info['new_version']),
                esc_html__('Release Date:', 'awesome-plugin'),
                esc_html(date_i18n(get_option('date_format')))
            );

            foreach ($changelog_items as $item) {
                $item = trim($item);
                if (!empty($item)) {
                    $changelog .= sprintf('<li>%s</li>' . "\n", esc_html($item));
                }
            }

            $changelog .= '</ul>';
        }

        // Build response object
        $plugin_info = (object) array(
            'name'          => $this->plugin_name,
            'slug'          => $this->product_slug,
            'version'       => $info['new_version'] ?? '',
            'download_link' => $info['package'] ?? '',
            'last_updated'  => $info['last_updated'] ?? '',
            'requires'      => $info['requires'] ?? '',
            'requires_php'  => $info['requires_php'] ?? '',
            'tested'        => $info['tested'] ?? '',
            'homepage'      => $info['homepage'] ?? '',
            'sections'      => array(
                'description'    => $description,
                'installation'   => $installation,
                'changelog'      => $changelog,
                'upgrade_notice' => isset($info['upgrade_notice']) ? str_replace('\n', "\n", $info['upgrade_notice']) : '',
            ),
            'author'        => $info['author'] ?? '',
            'author_homepage' => $info['homepage'] ?? '',
            'banners'       => (array) ($info['banners'] ?? array()),
            'contributors'  => (array) ($info['contributors'] ?? array()),
        );

        // Remove empty sections
        foreach ($plugin_info->sections as $key => $section) {
            if (empty($section)) {
                unset($plugin_info->sections[$key]);
            }
        }

        return $plugin_info;
    }

    /**
     * Clear license cache
     */
    public function clear_license_cache() {
        delete_transient($this->product_slug . '_license_details');
        delete_transient($this->product_slug . '_license_check');
    }

    /**
     * Clear update caches
     */
    public function clear_update_caches($upgrader = null, $options = array()) {
        // If this is a plugin update, only clear if it's our plugin
        if (!empty($options['type']) && $options['type'] === 'plugin' && 
            !empty($options['plugins']) && is_array($options['plugins'])) {
            if (!in_array($this->basename, $options['plugins'])) {
                return;
            }
        }

        delete_transient($this->product_slug . '_update_check');
        delete_site_transient('update_plugins');
    }

    /**
     * Check if version changed and clear transients if needed
     */
    public function maybe_clear_transients_on_version_change() {
        $stored_version = get_option($this->product_slug . '_stored_version');
        
        if ($stored_version !== $this->version) {
            $this->clear_license_cache();
            delete_transient($this->product_slug . '_update_check');
            delete_site_transient('update_plugins');
            update_option($this->product_slug . '_stored_version', $this->version);
        }
    }

    /**
     * Check if pro features are allowed
     */
    public function has_pro_access() {
        $license_status = get_option($this->product_slug . '_license_status');
        return !empty($license_status['status']) && ($license_status['status'] === 'active' || $license_status['status'] === 'expired');
    }

    /**
     * Render license management page
     */
    public function render_license_page() {
        $license_data = $this->get_license_details();
        $license_key = get_option($this->product_slug . '_license_key');
        $is_active = $license_data && isset($license_data['status']) && 'active' === $license_data['status'];
        $bundle_products = $this->get_bundle_products();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($this->plugin_name . ' ' . __('License Management', 'awesome-plugin')); ?></h1>
            
            <!-- Message container for JavaScript -->
            <div id="license-message"></div>

            <?php if ($is_active) : ?>
                <div class="notice notice-success">
                    <p>
                        <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                        <?php printf(__('Your %s license is active! Enjoy all the premium features.', 'awesome-plugin'), $this->plugin_name); ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning">
                    <p>
                        <span class="dashicons dashicons-warning" style="color: #ffb900;"></span>
                        <?php printf(__('Please activate your %s license to access premium features and updates.', 'awesome-plugin'), $this->plugin_name); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2><?php _e('License Information', 'awesome-plugin'); ?></h2>
                
                <?php if (empty($license_key) || !$is_active) : ?>
                    <!-- License Activation Form -->
                    <form id="license-form" method="post">
                        <?php wp_nonce_field($this->product_slug . '_license_nonce', 'license_nonce'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="license_key"><?php _e('License Key', 'awesome-plugin'); ?></label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="license_key" 
                                           name="license_key" 
                                           class="regular-text" 
                                           value="<?php echo esc_attr($license_key); ?>"
                                           placeholder="<?php esc_attr_e('Enter your license key', 'awesome-plugin'); ?>" 
                                           required>
                                    <p class="description">
                                        <?php printf(__('Enter your %s license key to activate updates and support.', 'awesome-plugin'), $this->plugin_name); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" id="activate-license" class="button button-primary">
                                <?php _e('Activate License', 'awesome-plugin'); ?>
                            </button>
                        </p>
                    </form>
                <?php else : ?>
                    <!-- Active License Information -->
                    <table class="form-table license-info-table">
                        <tr>
                            <th scope="row"><?php _e('License Key:', 'awesome-plugin'); ?></th>
                            <td>
                                <code class="license-key-display"><?php echo esc_html(substr($license_key, 0, 8) . str_repeat('*', 24)); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Status:', 'awesome-plugin'); ?></th>
                            <td>
                                <span class="license-status license-<?php echo esc_attr($license_data['status'] ?? 'invalid'); ?>">
                                    <?php echo esc_html(ucfirst($license_data['status'] ?? 'Invalid')); ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (!empty($license_data['expires_at'])) : ?>
                        <tr>
                            <th scope="row"><?php _e('Expires:', 'awesome-plugin'); ?></th>
                            <td>
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($license_data['expires_at']))); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($this->is_bundle_license() && !empty($bundle_products)) : ?>
                        <tr>
                            <th scope="row"><?php _e('Bundle Products:', 'awesome-plugin'); ?></th>
                            <td>
                                <ul>
                                    <?php foreach ($bundle_products as $product_slug) : ?>
                                        <li><?php echo esc_html(ucwords(str_replace('-', ' ', $product_slug))); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="description"><?php _e('This is a bundle license that works for multiple products.', 'awesome-plugin'); ?></p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <div class="license-actions">
                        <button type="button" id="verify-license" class="button button-secondary">
                            <?php _e('Verify License', 'awesome-plugin'); ?>
                        </button>
                        <button type="button" id="deactivate-license" class="button">
                            <?php _e('Deactivate License', 'awesome-plugin'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Plugin Information -->
            <div class="card">
                <h2><?php _e('Plugin Information', 'awesome-plugin'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Version:', 'awesome-plugin'); ?></th>
                        <td><?php echo esc_html($this->version); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Updates:', 'awesome-plugin'); ?></th>
                        <td>
                            <div class="plugin-status">
                                <?php if ($is_active) : ?>
                                    <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                                    <span class="status-text"><?php _e('Automatic updates enabled', 'awesome-plugin'); ?></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-no" style="color: #dc3232;"></span>
                                    <span class="status-text"><?php _e('Activate license to enable updates', 'awesome-plugin'); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Support:', 'awesome-plugin'); ?></th>
                        <td>
                            <div class="plugin-status">
                                <?php if ($is_active) : ?>
                                    <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                                    <span class="status-text"><?php _e('Premium support available', 'awesome-plugin'); ?></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-no" style="color: #dc3232;"></span>
                                    <span class="status-text"><?php _e('Activate license to access support', 'awesome-plugin'); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php if ($this->is_bundle_license()) : ?>
                    <tr>
                        <th scope="row"><?php _e('License Type:', 'awesome-plugin'); ?></th>
                        <td>
                            <div class="plugin-status">
                                <span class="dashicons dashicons-star-filled" style="color: #ffb900;"></span>
                                <span class="status-text"><?php _e('Bundle License', 'awesome-plugin'); ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php
    }
}

// Initialize the plugin
function awesome_plugin_init() {
    return Awesome_Plugin_License_Manager::instance();
}

// Start the plugin
add_action('plugins_loaded', 'awesome_plugin_init');

// Activation hook
register_activation_hook(__FILE__, function() {
    // Clear any existing caches on activation
    delete_transient(AWESOME_PLUGIN_SLUG . '_license_details');
    delete_transient(AWESOME_PLUGIN_SLUG . '_update_check');
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Clear caches on deactivation
    delete_transient(AWESOME_PLUGIN_SLUG . '_license_details');
    delete_transient(AWESOME_PLUGIN_SLUG . '_update_check');
});

// Helper function to check if features are enabled
function awesome_plugin_has_pro_access() {
    return Awesome_Plugin_License_Manager::instance()->has_pro_access();
}