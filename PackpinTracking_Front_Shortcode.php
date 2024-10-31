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
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('PackpinTracking_Front_Shortcode')) {
    /**
     * class PackpinTracking_Front_Shortcode
     */
    class PackpinTracking_Front_Shortcode
    {
        /**
         * Construct the shortcode
         */
        public function __construct()
        {
            add_shortcode('pptrack_output', array(&$this, 'generate_shortcode'));
            add_action('wp_head', array(&$this, 'pptrack_css'));
        }

        /**
         * This is where magic happens
         *
         * @return string|void
         */
        public function generate_shortcode()
        {
            global $wpdb, $woocommerce;

            if(empty($_GET['h']) && empty($_GET['email']) && empty($_GET['order'])){
                return $this->template('track_form');
            }

            $tbl = $wpdb->prefix . "pptrack_codes";

            if($h = $_GET['h']){
                if (empty($h) || !preg_match('/^[a-f\d]{8}-(?:[a-f\d]{4}-){3}[a-f\d]{12}$/i', $h))
                    return __('Missing/wrong hash code.', 'packpin');

                $tableTrack = $wpdb->get_row("SELECT * FROM $tbl WHERE codehash = '$h'", ARRAY_A);

                if (!$tableTrack)
                    return __('Missing/wrong hash code.', 'packpin');

                $order = new WC_Order($tableTrack['post_id']);
            }else{
                $email = $_GET['email'];
                $order = $_GET['order'];

                if(empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
                    return __('Missing/wrong email.', 'packpin');

                if(empty($order))
                    return __('Missing/wrong order id.', 'packpin');

                $order = (int) $order;

                $tableTrack = $wpdb->get_row("SELECT * FROM $tbl WHERE post_id = '$order'", ARRAY_A);

                if (!$tableTrack)
                    return __('Missing/wrong order.', 'packpin');

                $order = new WC_Order($tableTrack['post_id']);

                if($order->billing_email !== $email)
                    return __('Missing/wrong email.', 'packpin');
            }

            $orderNumber = $order->id;
            $email = $order->billing_email;

            $options = get_option('packpin_tracking_settings');

            list($dateAdded, $timeAdded) = explode(" ", $tableTrack["added"]);

            $api = new PackpinAPI();
            $track = $api->getTrackingInfo($tableTrack['carrier'], $tableTrack['code']);
            $statusHelper = new PackpinStatus();

            $carriers = $api->getCarrierList();
            $carrier = array();
            if($carriers['body']){
                foreach ($carriers['body'] as $c) {
                    if ($c['code'] == $tableTrack['carrier'])
                        $carrier = $c;
                }
            }
            $trackInfo = ($track['body'] && $track['body']['status'] !== "no_info") ? $track['body'] : false;

            if ($options['crossselling_switch'] && $options['crossselling_switch'] == "products") {
                $products = array();
                $crossselling_ids = array();
                $items = $order->get_items();
                foreach ($items as $i) {
                    $pid = $i['product_id'];
                    $crosssell_ids = get_post_meta($pid, '_crosssell_ids');
                    $products[$pid] = $crosssell_ids;
                    $crossselling_ids = array_merge($crossselling_ids, $crosssell_ids[0]);
                }

                $per_page = ($options['crossselling_front_perpage']) ? $options['crossselling_front_perpage'] : 4;
                $crossselling_ids = array_unique($crossselling_ids);
                if (count($crossselling_ids) > $per_page) {
                    $crossselling_ids_rand = $this->array_random($crossselling_ids, $per_page);
                } else {
                    $crossselling_ids_rand = $crossselling_ids;
                }
            } else {
                $crossselling_ids_rand = array();
            }

            $banner = false;
            if ($options['crossselling_switch'] && $options['crossselling_switch'] == "banner") {
                $image = $options['cs_banner_image'];
                $url = $options['cs_banner_url'];
                $banner = ($image) ? array(
                    'image' => $image,
                    'url' => $url
                ) : false;
            }

            $trackForm = $this->template('track_form', array(
               'email'        => $email,
               'orderNumber'  => $orderNumber
            ));

            // TODO : Move to template function, fix "global" vars
            ob_start();
            include_once(sprintf("%s/templates/pptrack_output.php", dirname(__FILE__)));
            $buffer = ob_get_contents();
            ob_end_clean();
            return $buffer;
        }

        /**
         * Generate the <head> part for CSS/JS inclusion
         */
        public function pptrack_css()
        {
            global $post;

            if ($post->post_name !== "pptrack")
                return;

            $url = plugin_dir_url(__FILE__);
            echo '<link rel="stylesheet" href="' . $url . 'assets/css/pptrack.css' . '" />';
            echo '<script src="' . $url . 'assets/js/pptrack.js' . '" /></script>';
        }

        /**
         * Helper to get $num random items from an array
         *
         * @param $arr
         * @param int $num
         * @return array
         */
        private function array_random($arr, $num = 1)
        {
            shuffle($arr);

            $r = array();
            for ($i = 0; $i < $num; $i++) {
                $r[] = $arr[$i];
            }
            return $num == 1 ? $r[0] : $r;
        }

        /**
         * Simple template function
         *
         * @param $name
         * @param array $vars
         * @return string
         */
        private function template($name, $vars = []){
            ob_start();
            extract($vars);
            include_once(sprintf("%s/templates/%s.php", dirname(__FILE__), $name));
            $buffer = ob_get_contents();
            ob_end_clean();
            return $buffer;
        }
    }
}
