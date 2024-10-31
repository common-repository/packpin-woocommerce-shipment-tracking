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

class PackpinTracking_REST
{
    /**
     * Hook WordPress
     */
    public function __construct()
    {
        add_filter('query_vars', array($this, 'add_query_vars'), 0);
        add_action('parse_request', array($this, 'sniff_requests'), 0);
        add_action('init', array($this, 'add_endpoint'), 0);
    }

    /**
     *  Add public query vars
     * @param array $vars List of current public query vars
     * @return array $vars
     */
    public function add_query_vars($vars)
    {
        $vars[] = '__ppapi';
        $vars[] = 'ppapi_key';
        $vars[] = 'action';
        $vars[] = 'tracking_code';
        return $vars;
    }

    /**
     *  Add API Endpoint
     *    This is where the magic happens - brush up on your regex skillz
     * @return void
     */
    public function add_endpoint()
    {
        add_rewrite_rule('^pptrack_api/(.+)/?$', 'index.php?__ppapi=1&action=$matches[1]', 'top');
        add_rewrite_tag('%__ppapi%', '([^&/]+)');
        add_rewrite_tag('%action%', '([^&/]+)');
        add_rewrite_tag('%api_key%', '/^[a-f\d]{8}-(?:[a-f\d]{4}-){3}[a-f\d]{12}$/i');
        add_rewrite_tag('%tracking_code%', '([^&/]+)');
    }

    /**
     *  Sniff Requests
     *  This is where we hijack all API requests
     * @return die if API request
     */
    public function sniff_requests()
    {
        global $wp;
        if (isset($wp->query_vars['__ppapi'])) {
            $this->handle_request();
            exit;
        }
    }

    /**
     *  Handle Requests
     *  This is where we send off for an intense pug bomb package
     * @return void
     */
    protected function handle_request()
    {
        global $wp, $wpdb, $woocommerce;

        $api_key = $wp->query_vars['api_key'];
        if (!$api_key)
            $this->send_response('INVALID_API_KEY');

        if (!preg_match('/^[a-f\d]{8}-(?:[a-f\d]{4}-){3}[a-f\d]{12}$/i', $api_key))
            $this->send_response('INVALID_API_KEY');

        $user = $this->get_user_by_api_key($api_key);
        if (!$user)
            $this->send_response('INVALID_API_KEY');

        wp_set_current_user($user->ID);

        $action = $wp->query_vars['action'];
        if (!$action)
            $this->send_response('NO_ACTION');

        switch ($action) {
            case 'notification_email':
                $tracking_code = $wp->query_vars['tracking_code'];
                if (empty($tracking_code))
                    $this->send_response('NO_TRACKING_CODE');

                $tbl = $wpdb->prefix . "pptrack_codes";
                $tableTrack = $wpdb->get_row("SELECT * FROM $tbl WHERE code = '$tracking_code'", ARRAY_A);

                if (!$tableTrack)
                    $this->send_response('INVALID_TRACKING_CODE');

                WC()->mailer();
                require_once 'include/WC_Shipping_Notification_Email.php';
                $emailGen = new WC_Shipping_Notification_Email();
                $email = $emailGen->trigger($tableTrack['post_id']);

                if (!$email) {
                    $this->send_response('NOTIFICATION_EMAILS_DISABLED');
                }

                $this->send_response('NOTIFICATION_EMAIL', $email);
                break;
            case 'self':
                $this->send_response('USER', $user);
                break;
            case 'wp_info':
                $this->send_response('WP_INFO', array(
                    'wordpress_meta' => array(
                        'name' => get_option('blogname'),
                        'description' => get_option('blogdescription'),
                        'URL' => get_option('siteurl')
                    ),
                    'woocommerce_meta' => array(
                        'timezone' => wc_timezone_string(),
                        'currency' => get_woocommerce_currency(),
                        'currency_format' => get_woocommerce_currency_symbol(),
                        'currency_position' => get_option('woocommerce_currency_pos'),
                        'thousand_separator' => get_option('woocommerce_price_decimal_sep'),
                        'decimal_separator' => get_option('woocommerce_price_thousand_sep'),
                        'price_num_decimals' => wc_get_price_decimals(),
                        'tax_included' => wc_prices_include_tax(),
                        'weight_unit' => get_option('woocommerce_weight_unit'),
                        'dimension_unit' => get_option('woocommerce_dimension_unit'),
                        'ssl_enabled' => ('yes' === get_option('woocommerce_force_ssl_checkout')),
                        'permalinks_enabled' => ('' !== get_option('permalink_structure'))
                    ),
                    'pptrack_plugin_version' => PPWSI_VERSION,
                ));
            default:
                $this->send_response('Please specify a valid action.');
        }
    }

    /**
     *  Response Handler
     *  This sends a JSON response to the browser
     */
    protected function send_response($msg, $body = '')
    {
        if ($body) {
            $response['type'] = $msg;
            $response['body'] = $body;
        } else {
            $response['error'] = $msg;
        }

        header('Content-type: application/json; charset=utf-8');
        echo json_encode($response) . "\n";
        exit;
    }

    /**
     *  Return the user for the given consumer key
     */
    protected function get_user_by_api_key($api_key)
    {
        $user_query = new WP_User_Query(
            array(
                'meta_key' => 'packpin_wp_api_key',
                'meta_value' => $api_key,
            )
        );

        $users = $user_query->get_results();

        return (empty($users[0])) ? false : $users[0];
    }
}