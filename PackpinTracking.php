<?php

/**
 * Packpin Woocommerce Shipment Tracking
 *
 * Integrates Packpin tracking solution into your Wordpress & WooCommerce installation.
 *
 * @package   Packpin_Woocommerce_Shipment_Tracking
 * @author    Packpin <info@packpin.com>
 * @license   GPL-2.0+
 * @link      http://packpin.com
 * @copyright 2015 Packpin B.V.
 *
 * @wordpress-plugin
 * Plugin Name:       Packpin Woocommerce Shipment Tracking
 * Plugin URI:        https://wordpress.org/plugins/packpin-woocommerce-shipment-tracking/
 * Description:       Integrates Packpin tracking solution into your Wordpress & WooCommerce installation
 * Version:           1.2.2
 * Author:            Packpin <info@packpin.com>
 * Author URI:        http://packpin.com
 * Text Domain:       packpin
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 */

define ('PPWSI_VERSION', '1.2.2');
define ('PPWSI_DB_VERSION', '1.1');

/**
 * Security
 */
if (!defined('ABSPATH')) exit;

/**
 * Check if WooCommerce is active
 **/
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

if (!class_exists('PackpinTracking')) {
    /**
     * class PackpinTracking
     */
    final class PackpinTracking
    {
        /**
         * Packpin plugin settings
         *
         * @type array
         */

        protected $options;

        /**
         * Constructor
         */
        public function __construct()
        {
            global $wpdb;

            $this->requirements();

            $this->api = new PackpinAPI();
            $this->setOptions();
            $this->track_table_name = $wpdb->prefix . "pptrack_codes";
            $this->track_page_slug = 'pptrack';

            add_action('add_meta_boxes', array(&$this, 'add_meta_box'));
            add_action('woocommerce_process_shop_order_meta', array(&$this, 'save_meta_box'), 0, 2);
            add_action('woocommerce_view_order', array(&$this, 'display_tracking_info'));
            add_action('woocommerce_email_before_order_table', array(&$this, 'email_display'));
            add_action('woocommerce_email_packpin_notification_table', array(&$this, 'notification_email_display'));
            add_action('init', array(&$this, 'register_shipped_order_status'));
            add_filter('wc_order_statuses', array(&$this, 'add_shipped_order_statuses'));
            add_filter('woocommerce_locate_template', array(&$this, 'woocommerce_locate_template'), 10, 3);
            add_action('admin_notices', array(&$this, 'admin_notice'));
            add_action('admin_init', array(&$this, 'admin_notice_ignore'));
            add_action('init', array(&$this, 'initiate_woocommerce_email'));
            add_filter('woocommerce_email_classes', array(&$this, 'add_woocommerce_email_types'));
            add_filter('admin_notices', array(&$this, 'api_error_admin_notice_show'));
            add_filter('admin_notices', array(&$this, 'admin_notice_show'));
            add_action('admin_head', array($this, 'admin_register_post_head'));
            add_filter( 'plugin_action_links_'.plugin_basename( __FILE__ ), array($this, 'admin_add_pp_settings_link'), 10, 2 );

            register_activation_hook(__FILE__, array($this, 'install'));
            register_deactivation_hook( __FILE__, array($this, 'deactivate') );
        }

        /**
         * Initialize various components for the plugin
         */
        public function requirements()
        {

            require_once( plugin_dir_path( __FILE__ ) . "include/PackpinHelpers.php" );
            require_once( plugin_dir_path( __FILE__ ) . "include/PackpinAPI.php" );
            require_once( plugin_dir_path( __FILE__ ) . "include/PackpinStatus.php" );
            require_once( plugin_dir_path( __FILE__ ) . "PackpinTracking_Admin_Settings.php" );
            require_once( plugin_dir_path( __FILE__ ) . "PackpinTracking_Front_Shortcode.php" );
            require_once( plugin_dir_path( __FILE__ ) . "PackpinTracking_REST.php" );

            $PackpinTracking_Admin_Settings = new PackpinTracking_Admin_Settings();
            $PackpinTracking_Front_Shortcode = new PackpinTracking_Front_Shortcode();
            $PackpinTracking_REST = new PackpinTracking_REST();
        }

        /**
         * Installation callback
         */
        public function install()
        {
            global $wpdb;

            // Insert pptrack page
            // Delete old one just in case there's something
            $ppTrack = $this->get_id_by_slug($this->track_page_slug);
            if ($ppTrack)
                wp_delete_post($ppTrack, true);

            $page = array(
                'post_content' => '[pptrack_output]',
                'post_name' => $this->track_page_slug,
                'post_title' => 'Track your order',
                'post_status' => 'Publish',
                'post_type' => 'page',
                'ping_status' => 'closed',
                'comment_status' => 'closed'
            );
            wp_insert_post($page);

            // Create/update db table
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->track_table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                code tinytext NOT NULL,
                carrier tinytext NOT NULL,
                codehash tinytext NOT NULL,
                additional longtext NOT NULL,
                post_id bigint(20) NOT NULL,
                added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY id (id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            add_option('pptrack_db_version', PPWSI_DB_VERSION);

            delete_transient('packpin_carrier_list');

            flush_rewrite_rules();
        }

        /**
         * Deactivation callback
         */
        public function deactivate()
        {
            delete_transient('packpin_carrier_list');
        }

        /**
         * Uninstall callback
         */
        public function uninstall()
        {
            delete_transient('packpin_carrier_list');
        }

        /**
         * Add the meta box for shipment info on the order page
         *
         * @access public
         */
        public function add_meta_box()
        {
            add_meta_box('Packpin_WooCommerce', __('Packpin', 'packpin'), array(&$this, 'meta_box'), 'shop_order', 'side', 'high');
        }

        /**
         * Show the meta box for shipment info on the order page
         *
         * @access public
         */
        public function meta_box()
        {
            global $post;

            $apiKey = $this->getOptions('api_key');

            if(empty($apiKey)){
                echo '<div id="packpin_wrapper">
                        <strong>'.__('Please setup Packpin Woocommerce plugin!', 'packpin').'</strong><br/><br/>
                        <a class="ppErrorLink" href="/wp-admin/options-general.php?page=packpin_woocommerce_shipment_tracking">'.__('Fix this error here!', 'packpin').'</a>
                      </div>';
                return;
            }

            $selected_carrier = get_post_meta($post->ID, '_packpin_carrier', true);

            $packpin_carrier_list = $this->api->getCarrierList();
            $selected = $this->getOptions('carriers', array());
            $isSelected = (count($selected) > 0);

            $toShow = $packpin_carrier_list['body'];

            if(!$toShow){
                echo '<div id="packpin_wrapper">
                        <strong>'.__('Cannot Access Packpin API! You must enter a valid API key!', 'packpin').'</strong><br/><br/>
                        <a class="ppErrorLink" href="/wp-admin/options-general.php?page=packpin_woocommerce_shipment_tracking">'.__('Fix this error here!', 'packpin').'</a>
                      </div>';
                return;
            }

            if($selected_carrier && count($selected) > 0 && !in_array($selected_carrier, $selected)){
                echo '<div id="packpin_wrapper">
                        <strong>'.sprintf(__('The carrier for this order (%s) is not selected in Packpin Settings!', 'packpin'), ucfirst(stripslashes($selected_carrier))).'</strong><br/><br/>
                        <a class="ppErrorLink" href="/wp-admin/options-general.php?page=packpin_woocommerce_shipment_tracking">'.__('You should fix your settings!', 'packpin').'</a>
                      </div>';
                return;
            }

            echo '<div id="packpin_wrapper">';

            echo '<p class="form-field"><label for="packpin_carrier">' . __('Carrier:', 'packpin') . '</label><br/>';
            if(count($selected) == 1){
                foreach ($toShow as $c) {
                    if(in_array($c['code'], $selected)){
                        echo '<strong>'.$c['name'].'</strong>';
                        woocommerce_wp_hidden_input(array(
                            'id' => 'packpin_carrier',
                            'value' => $c['code']
                        ));
                    }
                }
            }else{
                echo '<select data-placeholder="'.__('Choose a carrier', 'packpin').'" id="packpin_carrier" name="packpin_carrier" class="chosen_select" style="width:100%">';

                //$selected_text = (empty($selected_carrier)) ? 'selected="selected"' : "";
                //echo '<option disabled ' . $selected_text . ' value=""> ' . __('Please choose a carrier', 'packpin') . '</option>';
                echo '<option></option>';

                if(count($selected) == 1 && empty($selected_carrier)){
                    $selected_carrier = $selected[0]['code'];
                }

                foreach ($toShow as $c) {
                    if($isSelected && !in_array($c['code'], $selected))
                        continue;

                    echo strtr('<option value="{c}"{s}>{n}</option>', array(
                        '{c}' => $c['code'],
                        '{n}' => $c['name'],
                        '{s}' => ($selected_carrier == $c['code']) ? ' selected="selected"' : ''
                    ));
                }
                echo '</select>';
            }

            $packpin_code = get_post_meta($post->ID, '_packpin_code', true);
            woocommerce_wp_text_input(array(
                'id' => 'packpin_code',
                'label' => __('Tracking Code', 'packpin'),
                'placeholder' => "Enter tracking code",
                'class' => 'packpin-code',
                'value' => $packpin_code
            ));

            echo '<table id="packpin_links"><tr><td>';
            $hash = get_post_meta($post->ID, '_packpin_hash', true);
            if (!empty($hash)) {
                echo '<a href="' . get_permalink($this->get_id_by_slug('pptrack')) . '?h=' . $hash . '" target="_blank">'.__('See tracking status', 'packpin').'</a>';
                woocommerce_wp_hidden_input(array(
                    'id' => 'packpin_hash',
                    'value' => $hash
                ));
            }
            echo '</td><td>';
            echo '<a href="#" id="packpin_reset_info">Reset</a>';
            echo '</td></tr></table>';

            echo '<input type="hidden" id="packpin_reset" name="packpin_reset" disabled value="true"/>';

            woocommerce_wp_hidden_input(array(
                'id' => 'packpin_code_old',
                'value' => $packpin_code
            ));

            woocommerce_wp_hidden_input(array(
                'id' => 'packpin_carrier_old',
                'value' => $selected_carrier
            ));
            echo '</div>';
        }

        /**
         * Register CSS/JS for Wordpress admin
         */
        public function admin_register_post_head()
        {
            $screen = get_current_screen();
            if($screen->post_type !== "shop_order")
                return;

            $url = plugin_dir_url(__FILE__);
            echo '<link rel="stylesheet" href="' . $url . 'assets/css/woo_shop_order.css" />';
            echo '<script src="' . $url . 'assets/js/woo_shop_order.js' . '" /></script>';
        }

        /**
         * Callback for order page meta box
         *
         * @param $post_id
         * @param $post
         */
        public function save_meta_box($post_id, $post)
        {
            global $wpdb;

            // No repeating
            $hashField = '_packpin_hash';
            $carrierField = '_packpin_carrier';
            $codeField = '_packpin_code';

            if(isset($_POST['packpin_reset'])){
                delete_post_meta($post_id, $carrierField);
                delete_post_meta($post_id, $codeField);
                delete_post_meta($post_id, $hashField);
                $wpdb->delete(
                    $this->track_table_name,
                    array(
                        'post_id' => $post_id
                    ),
                    array('%d')
                );
                PackpinHelpers::notice(__('Deleted the tracking info!<br/><small>Keep in mind, the info was only deleted from your website!</small>', 'packpin'));
                return;
            }

            $packpin_carrier = wc_clean($_POST['packpin_carrier']);
            $packpin_code = wc_clean($_POST['packpin_code']);

            if (!empty($packpin_carrier) && !empty($packpin_code)) {
                if ($packpin_code == wc_clean($_POST['packpin_code_old']) && $packpin_carrier == wc_clean($_POST['packpin_carrier_old']))
                    return;

                $hash = wc_clean($_POST['packpin_hash']);

                $apiError = false;

                if (!empty($hash)) {
                    // Update
                    $res = $this->api->addTrackingCode($packpin_carrier, $packpin_code, null, null, null, null, $post_id);
                    if($res){
                        $wpdb->update(
                            $this->track_table_name,
                            array(
                                'code' => $packpin_code,
                                'carrier' => $packpin_carrier,
                                'additional' => json_encode($res),
                                'updated' => current_time('mysql')
                            ),
                            array('codehash' => $hash),
                            array(
                                '%s', '%s', '%s', '%s'
                            ),
                            array('%s')
                        );

                        PackpinHelpers::notice(__('Tracking info was successfully updated!', 'packpin'));
                    }else{
                        $apiError = true;
                    }
                } else {
                    $res = $this->api->addTrackingCode($packpin_carrier, $packpin_code, null, null, null, null, $post_id);
                    if($res){
                        //Insert new
                        $hash = PackpinHelpers::generateHash();
                        update_post_meta($post_id, $hashField, $hash);

                        $wpdb->insert(
                            $this->track_table_name,
                            array(
                                'code' => $packpin_code,
                                'carrier' => $packpin_carrier,
                                'codehash' => $hash,
                                'additional' => json_encode($res),
                                'post_id' => $post_id,
                                'added' => current_time('mysql'),
                                'updated' => current_time('mysql')
                            ),
                            array(
                                '%s', '%s', '%s', '%s', '%d', '%s', '%s'
                            )
                        );

                        PackpinHelpers::notice(__('Tracking info was successfully added!', 'packpin'));
                    }else{
                        $apiError = true;
                    }
                }

                if(!$apiError){
                    update_post_meta($post_id, $carrierField, $packpin_carrier);
                    update_post_meta($post_id, $codeField, $packpin_code);
                    $order = new WC_Order($post_id);
                    $order->update_status('shipped', 'order_note');
                }
            }
        }

        /**
         * Callback for showing order tracking page link
         * TODO : Show more info about the order
         *
         * @param $order_id
         */
        public function display_tracking_info($order_id)
        {
            global $wpdb;

            $tbl = $wpdb->prefix . "pptrack_codes";
            $tableTrack = $wpdb->get_row("SELECT * FROM $tbl WHERE post_id = '$order_id'", ARRAY_A);
            if (!$tableTrack)
                return;

            $bg = get_option('woocommerce_email_background_color');
            $base = get_option('woocommerce_email_base_color');
            $base_text = wc_light_or_dark($base, '#202020', '#ffffff');

            echo strtr('<a style="box-shadow: 0 1px 4px rgba(0,0,0,0.1) !important; background-color: {base}; color: {base_text}; border-radius: 3px !important; text-decoration: none; padding: 5px 10px;" href="{u}">{l}</a>', array(
                '{u}' => get_permalink($this->get_id_by_slug('pptrack')) . '?h=' . $tableTrack['codehash'],
                '{l}' => __('See your order tracking info', 'packpin'),
                '{base}' => $base,
                '{base_text}' => $base_text
            ));
        }

        /**
         * Initialize WC Mailer class
         */
        public function initiate_woocommerce_email()
        {
            // Just when you update the order_status on backoffice
            if (isset($_POST['order_status'])) {
                WC()->mailer();
            }
        }

        /**
         * Initialize mail type
         *
         * @param $email_classes
         * @return mixed
         */
        public function add_woocommerce_email_types($email_classes)
        {
            require_once(plugin_dir_path( __FILE__ ) . 'include/WC_Shipped_Order_Email.php');
            require_once(plugin_dir_path( __FILE__ ) . 'include/WC_Shipping_Notification_Email.php');
            $email_classes['WC_Shipped_Order_Email'] = new WC_Shipped_Order_Email();
            $email_classes['WC_Shipping_Notification_Email'] = new WC_Shipping_Notification_Email();
            return $email_classes;
        }

        /**
         * Callback for displaying order tracking page link in email
         *
         * @param $order
         */
        public function email_display($order)
        {
            $this->display_tracking_info($order->id);
        }

        public function notification_email_display($order)
        {
            global $wpdb;

            $tbl = $wpdb->prefix . "pptrack_codes";
            $tableTrack = $wpdb->get_row("SELECT * FROM $tbl WHERE post_id = '$order->id'", ARRAY_A);
            if (!$tableTrack)
                return;

            $pptrackUrl = get_permalink($this->get_id_by_slug('pptrack')) . '?h=' . $tableTrack['codehash'];

            $carriers = $this->api->getCarrierList();
            $carrier = array();
            foreach ($carriers['body'] as $c) {
                if ($c['code'] == $tableTrack['carrier'])
                    $carrier = $c;
            }

            ob_start();
            include_once(sprintf("%s/templates/email_tracking_info.php", dirname(__FILE__)));
            $buffer = ob_get_contents();
            ob_end_clean();
            echo $buffer;
        }

        /**
         * Initialize new Wordpress status
         */
        public function register_shipped_order_status()
        {
            register_post_status('wc-shipped', array(
                'label' => 'Shipped',
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop('Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>')
            ));
        }

        /**
         * Add the Wordpress based status to WooCommerce
         *
         * @param $order_statuses
         * @return array
         */
        public function add_shipped_order_statuses($order_statuses)
        {
            $new_order_statuses = array();
            foreach ($order_statuses as $key => $status) {

                $new_order_statuses[$key] = $status;

                if ('wc-processing' === $key) {
                    $new_order_statuses['wc-shipped'] = 'Shipped';
                }
            }

            return $new_order_statuses;
        }

        /**
         * Initialize Admin notice after installation of plugin
         */
        public function admin_notice()
        {
            global $current_user;
            $user_id = $current_user->ID;
            /* Check that the user hasn't already clicked to ignore the message */
            if (isset($_GET['page']) && $_GET['page'] == "packpin_woocommerce_shipment_tracking")
                return;

            if (!empty($options['api_key']))
                return;

            if (!get_user_meta($user_id, 'dismiss_pptrack_woo')) {
                echo '<div class="updated"><p>';
                add_user_meta($user_id, 'dismiss_pptrack_woo', 'true', true);
                sprintf(__('Thanks for installing Packpin plugin for WooCommerce!<br/>To start using Packpin tracking functionality, please go to <a href="%1$s">Settings</a> page and configure your API key, or you can <a href="%1$s">hide this notice</a> for the time being.', 'packpin'), 'options-general.php?page=packpin_woocommerce_shipment_tracking', '?dismiss_pptrack_woo=0');
                echo "</p></div>";
            }
        }

        /**
         * Callback to dismiss the admin notice
         */
        public function admin_notice_ignore()
        {
            global $current_user;
            $user_id = $current_user->ID;
            /* If user clicks to ignore the notice, add that to their user meta */
            if (isset($_GET['dismiss_pptrack_woo']) && '0' == $_GET['dismiss_pptrack_woo']) {
                add_user_meta($user_id, 'dismiss_pptrack_woo', 'true', true);
            }
        }

        /**
         * Show API error as an admin notice
         *
         * @return bool
         */
        public function api_error_admin_notice_show() {
            $error = get_option('ppApiError');
            if(!empty($error)) {
                delete_option('ppApiError');
                echo sprintf("<div class='error'><p>%s</p></div>", stripslashes($error));
            }
            return false;
        }

        /**
         * Show an admin notice
         *
         * @return bool
         */
        public function admin_notice_show() {
            $error = get_option('ppNotice');
            if(!empty($error)) {
                update_option('ppNotice', '');
                echo sprintf("<div class='notice notice-success'><p>%s</p></div>", stripslashes($error));
            }
            return false;
        }

        /**
         * Helper function to override template placement
         *
         * @param $template
         * @param $template_name
         * @param $template_path
         * @return string
         */
        public function woocommerce_locate_template($template, $template_name, $template_path)
        {
            global $woocommerce;

            $_template = $template;

            if (!$template_path) $template_path = $woocommerce->template_url;

            $plugin_path = $this->plugin_path() . '/woocommerce/';

            // Look within passed path within the theme - this is priority
            $template = locate_template(
                array(
                    $template_path . $template_name,
                    $template_name
                )
            );

            // Modification: Get the template from this plugin, if it exists
            if (!$template && file_exists($plugin_path . $template_name))
                $template = $plugin_path . $template_name;

            // Use default template
            if (!$template)
                $template = $_template;

            // Return what we found
            return $template;
        }

        /**
         * Add settings link to plugin page
         *
         * @param $links
         * @return mixed
         */
        public function admin_add_pp_settings_link( $links ) {
            array_unshift( $links, '<a href="options-general.php?page=packpin_woocommerce_shipment_tracking">' . __( 'Settings' ) . '</a>' );
            return $links;
        }


        /**
         * Helper function to get plugin path
         *
         * @return string
         */
        private function plugin_path()
        {
            return untrailingslashit(plugin_dir_path(__FILE__));
        }

        /**
         * Helper function to get page ID by slug
         *
         * @param $page_slug
         * @return int|null
         */
        private function get_id_by_slug($page_slug)
        {
            $page = get_page_by_path($page_slug);
            if ($page) {
                return $page->ID;
            } else {
                return null;
            }
        }

        /**
         * Helper function to set plugin options
         *
         * @param string $key
         * @return null
         */
        private function setOptions($key = 'packpin_tracking_settings')
        {
            $this->options = get_option($key);
        }

        /**
         * Helper function to set plugin options
         *
         * @param string $option
         * @return array|mixed|null|void
         */
        private function getOptions($option = '', $default = '')
        {
            return (!$option) ? $this->options : ( (isset($this->options[$option])) ? $this->options[$option] : $default );
        }

    }

    /**
     * Register this class globally (??)
     */
    $GLOBALS['PackpinTracking'] = new PackpinTracking;

}

