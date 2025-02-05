<?php
/**
 * Plugin Name: Awesome Plugin
 * Description: Helper plugin for DigiCommerce license checker
 * Version: 1.0.0
 * Author: DigiCommerce
 * Author URI: https://digicommerce.me
 * Text Domain: awesome-plugin
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define('DIGI_API_URL', 'https://test.ppvaya.com');
define('DIGI_PLUGIN_SLUG', 'awesome-plugin');
define('DIGI_PLUGIN_NAME', 'Awesome Plugin');
define('DIGI_PLUGIN_BASENAME', plugin_basename(__FILE__));

class Plugin_License_Checker {
    private static $instance = null;
    private $api_url;
    private $plugin_slug;
    private $plugin_name;
    private $version;
    private $basename;
    private $plugin_path;
	private const LICENSE_CACHE_DURATION = 12 * HOUR_IN_SECONDS;
	private const UPDATE_CHECK_DURATION = 12 * HOUR_IN_SECONDS;
	private const API_FAILURE_DURATION = HOUR_IN_SECONDS;

    public static function init() {
        return self::instance();
    }

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // First, load the textdomain
        add_action('init', array($this, 'load_plugin_textdomain'));
        
        // Then initialize everything else
        add_action('init', array($this, 'initialize'), 20);
        
        // These actions don't involve translations, so they can be added here
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_plugins_transient'));
        add_filter('plugins_api', array($this, 'modify_plugin_details'), 10, 3);

		// Cache clearing
		add_action('upgrader_process_complete', array($this, 'clear_caches'), 10, 2);
		add_action('activated_plugin', array($this, 'clear_caches'));
		add_action('deactivated_plugin', array($this, 'clear_caches'));
		add_action('deleted_plugin', array($this, 'clear_caches'));
	
		// Clear caches when WordPress core updates
		add_action('update_option_WPLANG', array($this, 'clear_caches'));
		add_action('wp_version_check', array($this, 'clear_caches'));
	
		// Clear caches when switching themes (which might affect compatibility)
		add_action('switch_theme', array($this, 'clear_caches'));
    }

    public function initialize() {
        $this->plugin_slug = DIGI_PLUGIN_SLUG;
        $this->plugin_name = DIGI_PLUGIN_NAME;
        $this->basename = DIGI_PLUGIN_BASENAME;
        $this->plugin_path = WP_PLUGIN_DIR . '/' . $this->basename;

        // Get plugin version
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_data = get_plugin_data($this->plugin_path);
        $this->version = $plugin_data['Version'];
        
        // Set API URL from constant
        $this->api_url = trailingslashit(DIGI_API_URL) . 'wp-json/digicommerce/v2';

        // Add menu and AJAX handlers after translations are loaded
        add_action('admin_menu', array($this, 'add_license_menu'));
        add_action('wp_ajax_' . $this->plugin_slug . '_activate_license', array($this, 'ajax_activate_license'));
        add_action('wp_ajax_' . $this->plugin_slug . '_deactivate_license', array($this, 'ajax_deactivate_license'));
        add_action('wp_ajax_' . $this->plugin_slug . '_verify_license', array($this, 'ajax_verify_license'));
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            DIGI_PLUGIN_SLUG,
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

	public function clear_caches($upgrader = null, $options = array()) {
		// If this is a plugin update, only clear if it's our plugin
		if (!empty($options['type']) && $options['type'] === 'plugin' && 
			!empty($options['plugins']) && is_array($options['plugins'])) {
			if (!in_array($this->basename, $options['plugins'])) {
				return;
			}
		}
	
		delete_transient($this->plugin_slug . '_license_check');
		delete_transient($this->plugin_slug . '_update_check');
		wp_cache_delete($this->plugin_slug . '_license_status', 'options');
		
		// Clear any API failure caches
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like('_transient_' . $this->plugin_slug . '_api_failure_') . '%'
			)
		);
	}

    public function add_license_menu() {
        add_options_page(
            $this->plugin_name . ' ' . esc_html__('License', 'awesome-plugin'),
            $this->plugin_name . ' ' . esc_html__('License', 'awesome-plugin'),
            'manage_options',
            $this->plugin_slug . '-license',
            array($this, 'render_license_page')
        );
    }

    public function render_license_page() {
        $license_data = get_option($this->plugin_slug . '_license_data', array());
        $license_key = get_option($this->plugin_slug . '_license_key');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($this->plugin_name . ' ' . esc_html__('License', 'awesome-plugin')); ?></h1>

            <div class="license-message" id="license-message" style="display: none;"></div>

            <div class="plugin-license-box">
                <div class="license-header">
                    <h2><?php _e('License Information', 'awesome-plugin'); ?></h2>
                </div>
                <div class="license-body">
                    <?php if (empty($license_key)) : ?>
                        <form id="license-form" method="post">
                            <label for="license_key"><?php _e('License Key', 'awesome-plugin'); ?></label>
                            <input type="text" id="license_key" name="license_key" class="regular-text" 
							placeholder="<?php esc_attr_e('Enter your license key', 'awesome-plugin'); ?>" required>
                            
                            <button type="submit" id="activate-license" class="button button-primary">
                                <?php _e('Activate License', 'awesome-plugin'); ?>
                            </button>
                        </form>
                    <?php else : ?>
                        <div id="license-info">
                            <div class="license-info">
                                <div class="license-key">
                                    <label><?php _e('License Key:', 'awesome-plugin'); ?></label>
                                    <code><?php echo esc_html(substr($license_key, 0, 8) . '********************************'); ?></code>
                                </div>
                                
                                <div class="license-status">
                                    <label><?php _e('Status:', 'awesome-plugin'); ?></label>
                                    <span class="status-<?php echo esc_attr($license_data['status'] ?? 'invalid'); ?>">
                                        <?php echo esc_html(ucfirst($license_data['status'] ?? 'invalid')); ?>
                                    </span>
                                </div>

                                <?php if (!empty($license_data['expires_at'])) : ?>
                                    <div class="license-expiry">
                                        <label><?php _e('Expires:', 'awesome-plugin'); ?></label>
                                        <span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($license_data['expires_at']))); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="license-actions">
                                <button type="button" id="verify-license" class="button button-secondary">
                                    <?php _e('Verify License', 'awesome-plugin'); ?>
                                </button>

                                <button type="button" id="deactivate-license" class="button">
                                    <?php _e('Deactivate License', 'awesome-plugin'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

	private function request($endpoint, $license_key = null) {
		// Check for recent API failures
		$failure_key = $this->plugin_slug . '_api_failure_' . $endpoint;
		if (get_transient($failure_key)) {
			return new WP_Error('api_throttled', 'API requests temporarily disabled');
		}

		// Build request URL
		$request_url = $this->api_url . '/' . $endpoint;
	
		// Prepare the request body
		$body = array(
			'site_url' => home_url()
		);
	
		if ($license_key) {
			$body['license_key'] = $license_key;
		}
	
		// Auto-detect development sites
		if ($this->is_development_site()) {
			$body['site_type'] = 'development';
		}
		
		$request_args = array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
				'X-Site-URL' => home_url(),
				'X-Plugin-Slug' => $this->plugin_slug
			),
			'body' => wp_json_encode($body),
			'data_format' => 'body'
		);
	
		$response = wp_remote_post($request_url, $request_args);
	
		if (is_wp_error($response)) {
			// Cache failure for 1 hour
			set_transient($failure_key, true, HOUR_IN_SECONDS);
			return $response;
		}
	
		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		
		if ($response_code !== 200) {
			$error_message = wp_remote_retrieve_response_message($response);
			return new WP_Error('server_error', $error_message ?: 'Unknown server error');
		}
		
		$json = json_decode($response_body, true);
		if (!$json) {
			return new WP_Error('json_error', 'Invalid response format');
		}
	
		return $json;
	}

    /**
     * Check if current site is a development site
     */
    private function is_development_site() {
        $site_url = home_url();
        $host = parse_url($site_url, PHP_URL_HOST);
        
        // Common development TLDs and patterns
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
            '/^172\.(1[6-9]|2[0-9]|3[0-1])\./'
        );
        
        foreach ($dev_patterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }
        
        return false;
    }

    public function modify_plugins_transient($transient) {
		if (empty($transient->checked)) {
			return $transient;
		}
	
		// Check update cache
		$cache_key = $this->plugin_slug . '_update_check';
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			if (isset($cached['update'])) {
				$transient->response[$this->basename] = (object)$cached['update'];
			}
			return $transient;
		}
	
		// Skip if license is not valid
		if (!$this->is_license_valid()) {
			return $transient;
		}
	
		$license_key = get_option($this->plugin_slug . '_license_key');
		$response = $this->request('license/updates', $license_key);
	
		if (is_wp_error($response) || empty($response)) {
			return $transient;
		}
	
		// Handle icon
		$icons = !empty($response['icons']) ? (array)$response['icons'] : array();
	
		// Check if update is available
		if (!empty($response['new_version']) && version_compare($this->version, $response['new_version'], '<')) {
			$update_data = array(
				'slug' => $this->plugin_slug,
				'plugin' => $this->basename,
				'new_version' => $response['new_version'],
				'package' => $response['package'] ?? '',
				'tested' => $response['tested'] ?? '',
				'icons' => $icons,
			);
			
			$transient->response[$this->basename] = (object)$update_data;
			
			// Cache results for 12 hours
			set_transient($cache_key, array('update' => $update_data), self::LICENSE_CACHE_DURATION);
		}
	
		return $transient;
	}

    public function modify_plugin_details($result, $action, $args) {
		if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
			return $result;
		}
	
		if (!$this->is_license_valid()) {
			return $result;
		}
	
		// Get license info for updates
		$license_key = get_option($this->plugin_slug . '_license_key');
		$response = $this->request('license/updates', $license_key);
	
		if (is_wp_error($response)) {
			return $result;
		}
	
		// Format description - WordPress style formatting
		$description = '';
		if (!empty($response['description'])) {
			$description = $response['description'];
			// Convert \n\n to double line breaks
			$description = str_replace('\n\n', "\n\n", $description);
			// Convert remaining \n to single line breaks
			$description = str_replace('\n', "\n", $description);
			// Fix bullet points that have extra spaces
			$description = preg_replace('/\* \n/', "* ", $description);
			// Remove any double spaces
			$description = preg_replace('/\s+/', ' ', $description);
			// Ensure proper spacing around headings
			$description = preg_replace('/\n(#+)/', "\n\n$1", $description);
		}
	
		// Format installation - WordPress style formatting
		$installation = '';
		if (!empty($response['installation'])) {
			$installation = $response['installation'];
			$installation = str_replace('\n\n', "\n\n", $installation);
			$installation = str_replace('\n', "\n", $installation);
			// Format numbered list items
			$installation = preg_replace('/(\d+)\. \n/', "$1. ", $installation);
		}
	
		// Format changelog - WordPress style formatting with HTML structure
		$changelog = '';
		if (!empty($response['changelog']) && !empty($response['new_version'])) {
			$changelog_items = explode("\n", str_replace('\n', "\n", $response['changelog']));
			
			$changelog = sprintf(
				'<h4>%s</h4>' . "\n" .
				'<p><em>Release Date %s</em></p>' . "\n" .
				'<ul>' . "\n",
				esc_html($response['new_version']),
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
	
		// Merge the data
		$info = (object) array(
			// Basic plugin information
			'name' => $this->plugin_name,
			'slug' => $this->plugin_slug,
			'version' => $response['new_version'] ?? '',
			'download_link' => $response['package'] ?? '',
			
			// Metadata
			'last_updated' => $response['last_updated'] ?? '',
			'requires' => $response['requires'] ?? '',
			'requires_php' => $response['requires_php'] ?? '',
			'tested' => $response['tested'] ?? '',
			
			// Content
			'homepage' => $response['homepage'] ?? '',
			'sections' => array(
				'description' => $description,
				'installation' => $installation,
				'changelog' => $changelog,
				'upgrade_notice' => isset($response['upgrade_notice']) ? str_replace('\n', "\n", $response['upgrade_notice']) : ''
			),
			
			// Author information
			'author' => $response['author'] ?? '',
			'author_homepage' => $response['homepage'] ?? '',
			
			// Visual assets
			'banners' => (array) ($response['banners'] ?? array()),
			
			// Contributors
			'contributors' => (array) ($response['contributors'] ?? array())
		);
	
		// Remove empty sections
		foreach ($info->sections as $key => $section) {
			if (empty($section)) {
				unset($info->sections[$key]);
			}
		}
	
		return $info;
	}

    public function ajax_activate_license() {    
        check_ajax_referer($this->plugin_slug . '_license_nonce', 'nonce');
	
		if (!current_user_can('manage_options')) {
			wp_send_json_error('Insufficient permissions');
		}

		$license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
		
		if (empty($license_key)) {
			wp_send_json_error('License key is required');
		}

		$response = $this->request('license/activate', $license_key);
		
		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();
			wp_send_json_error($error_message);
		}

		if (isset($response['status']) && $response['status'] === 'success') {
			update_option($this->plugin_slug . '_license_key', $license_key);
			$license_data = array(
				'status' => 'active',
				'expires_at' => $response['expires_at'] ?? null,
				'last_check' => current_time('mysql')
			);
			update_option($this->plugin_slug . '_license_data', $license_data);
			
			$transient_data = array(
				'status' => 'active',
				'expires_at' => $response['expires_at'] ?? null
			);
			set_transient(
				$this->plugin_slug . '_license_check', 
				$transient_data, 
				self::LICENSE_CACHE_DURATION
			);

			wp_send_json_success(array(
				'message' => esc_html__('License activated successfully', 'awesome-plugin'),
				'license' => array(
					'status' => 'active',
					'expires_at' => $response['expires_at'] ?? null,
					'key' => $license_key
				)
			));
		}

		$error_message = $response['message'] ?? 'License activation failed';
		wp_send_json_error($error_message);
	}

    public function ajax_deactivate_license() {
        try {
			check_ajax_referer($this->plugin_slug . '_license_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
            }

            $license_key = get_option($this->plugin_slug . '_license_key');
            if (!$license_key) {
                wp_send_json_error('No license key found');
            }

            $response = $this->request('license/deactivate', $license_key);

            if (is_wp_error($response)) {
                wp_send_json_error($response->get_error_message());
            }

            if (isset($response['status']) && $response['status'] === 'success') {
				$this->clear_caches();
				delete_option($this->plugin_slug . '_license_key');
				delete_option($this->plugin_slug . '_license_status');
                wp_send_json_success(array(
                    'message' => esc_html__('License deactivated successfully', 'awesome-plugin')
                ));
            }

            wp_send_json_error($response['message'] ?? 'License deactivation failed');

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

	private function verify_license() {
		// Try transient cache first, but with better invalidation
		$cache_key = $this->plugin_slug . '_license_check';
		$cached = get_transient($cache_key);
		
		$license_key = get_option($this->plugin_slug . '_license_key');
		if (!$license_key) {
			return false;
		}
	
		$response = $this->request('license/verify', $license_key);
		
		// If it's a WP_Error, THEN we can use cached data as fallback
		if (is_wp_error($response)) {
			$fallback = get_option($this->plugin_slug . '_license_status');
			return $fallback ?: false;
		}
	
		// Check for specific API response codes
		if (isset($response['code']) && $response['code'] === 'verification_failed') {
			// Clear all stored data since license is no longer valid
			delete_option($this->plugin_slug . '_license_key');
			delete_option($this->plugin_slug . '_license_status');
			delete_transient($cache_key);
			return false;
		}
	
		$license_data = array(
			'status' => $response['status'] ?? 'invalid',
			'expires_at' => $response['expires_at'] ?? null,
			'last_check' => current_time('mysql')
		);
	
		// Only cache and store if the license is valid
		if ($license_data['status'] === 'active') {
			set_transient($cache_key, $license_data, self::LICENSE_CACHE_DURATION);
			update_option($this->plugin_slug . '_license_status', $license_data);
		} else {
			// Clear cached data if license is not active
			delete_transient($cache_key);
			delete_option($this->plugin_slug . '_license_status');
		}
	
		return $license_data;
	}

    private function is_license_valid() {
        $license_data = $this->verify_license();
        if (!$license_data) {
            return false;
        }

        if ($license_data['status'] !== 'active') {
            return false;
        }

        if (!empty($license_data['expires_at'])) {
            $expires = strtotime($license_data['expires_at']);
            if ($expires && $expires < time()) {
                return false;
            }
        }

        return true;
    }

    public function ajax_verify_license() {
		try {
			check_ajax_referer($this->plugin_slug . '_license_nonce', 'nonce');
	
			if (!current_user_can('manage_options')) {
				wp_send_json_error( __('Insufficient permissions', 'awesome-plugin') );
			}
	
			$license_data = $this->verify_license();
			
			// If verification failed or license is invalid/expired
			if (!$license_data || 
				$license_data['status'] !== 'active' || 
				(!empty($license_data['expires_at']) && strtotime($license_data['expires_at']) < time())) {
				
				// Delete all license data
				delete_option($this->plugin_slug . '_license_key');
				delete_option($this->plugin_slug . '_license_data');
				delete_transient($this->plugin_slug . '_license_check');
				
				wp_send_json_error( __('License verification failed. Your license is no longer valid.', 'awesome-plugin') );
			}
	
			wp_send_json_success(array(
				'message' => __('License verified successfully', 'awesome-plugin'),
				'license' => array(
					'status' => $license_data['status'],
					'expires_at' => $license_data['expires_at'],
					'key' => get_option($this->plugin_slug . '_license_key')
				)
			));
	
		} catch (Exception $e) {
			wp_send_json_error($e->getMessage());
		}
	}

    public function enqueue_assets($hook) {
        if ('settings_page_' . $this->plugin_slug . '-license' !== $hook) {
            return;
        }

        wp_enqueue_style(
            $this->plugin_slug . '-license',
            plugin_dir_url(__FILE__) . 'assets/css/license.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            $this->plugin_slug . '-license',
            plugin_dir_url(__FILE__) . 'assets/js/license.js',
            array(),
            $this->version,
            true
        );

		wp_localize_script(
            $this->plugin_slug . '-license',
            'licenseManager',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce($this->plugin_slug . '_license_nonce'),
                'pluginSlug' => $this->plugin_slug,
                'i18n' => array(
                    'activating' => esc_html__('Activating...', 'awesome-plugin'),
                    'deactivating' => esc_html__('Deactivating...', 'awesome-plugin'),
                    'verifying' => esc_html__('Verifying...', 'awesome-plugin'),
                    'confirmDeactivate' => esc_html__('Are you sure you want to deactivate this license?', 'awesome-plugin'),
                    'error' => esc_html__('An error occurred. Please try again.', 'awesome-plugin'),
					'enterLicenseKey' => esc_html__('Please enter a license key', 'awesome-plugin')
                )
            )
        );
    }
}

/**
 * Returns the main instance of the plugin
 */
function Plugin_License_Checker() {
	return Plugin_License_Checker::instance();
}

// Starting the plugin
Plugin_License_Checker();