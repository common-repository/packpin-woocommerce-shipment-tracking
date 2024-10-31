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

/**
 * Class Packpin_Pptrack_Helper_Data
 *
 * API calls helpers
 * see https://packpin.com/docs for more documentation
 */
class PackpinAPI
{

    /**
     * Api routes
     */
    const API_PATH_CARRIERS = 'carriers';
    const API_PATH_TRACKINGS = 'trackings';
    const API_PATH_CONNECTORS = 'connectors';
    const API_PATH_TRACKINGS_BATCH = 'trackings/batch';
    const API_PATH_TRACKING_INFO = 'trackings/%s/%s';
    const API_PATH_TEST = 'test/1';
    const API_PATH_PLAN_INFO = 'planinfo';

    /**
     * Possible HTTP Response code message
     * @var array
     */
    protected $_responseHeaderCodes = array(
        '200' => 'OK - The request was successful (some API calls may return 201 instead)',
        '201' => 'Created - The request was successful and a resource was created',
        '204' => 'No Content - The request was successful but there is no representation to return (that is, the response is empty).',
        '400' => 'Bad Request - The request could not be understood or was missing required parameters.',
        '401' => 'Unauthorized - Authentication failed or user does not have permissions for the requested operation.',
        '402' => 'Payment Required - Payment required.',
        '403' => 'Forbidden - Access denied.',
        '404' => 'Not Found - Resource was not found.',
        '405' => 'Method Not Allowed - Requested method is not supported for the specified resource.',
        '429' => 'Too Many Requests - Exceeded API limits. Pause requests, wait one minute, and try again.',
        '500' => 'Server error - Server error. Contact info@packpin.com',
        '503' => 'Service Unavailable - The service is temporary unavailable (e.g. scheduled Platform Maintenance). Try again later.',
    );

    /**
     * HTTP Codes considered as OK
     * @var array
     */
    protected $_okResponseHeaderCodes = array('200', '201', '204');

    /**
     * Packpin API backend
     *
     * @var string
     */
    protected $_apiBackend;

    /**
     * Packpin API key
     *
     * @var string
     */
    protected $_apiKey;

    /**
     * Last API call status code
     *
     * @var integer
     */
    protected $_lastStatusCode;

    /**
     * Need to init key from a var
     *
     * @var integer
     */
    public function __construct($apiKey = "")
    {
        if (!empty($apiKey))
            $this->_apiKey = $apiKey;
    }

    protected function _getApiKey()
    {
        if ($this->_apiKey === null) {
            $opts = get_option('packpin_tracking_settings');
            $this->_apiKey = $opts['api_key'];
        }

        return $this->_apiKey;
    }

    /**
     * Make API request
     *
     * @param string $route
     * @param string $method
     * @param array $body
     *
     * @return bool|array
     */
    protected function _apiRequest($route, $method = 'GET', $body = array())
    {
        $body['plugin_type'] = 'woocommerce';
        $body['plugin_version'] = $this->getExtensionVersion();
        $body['plugin_shop_version'] = get_bloginfo('version');
        $body['plugin_user'] = get_bloginfo('name');
        $body['plugin_email'] = get_bloginfo('admin_email');
        $body['plugin_url'] = get_bloginfo('url');

        /**
         * Backend
         */
        $this->_apiBackend = (defined('PACKPIN_LOCAL')) ? 'http://local.api.packpin.com/v2/' : 'https://api.packpin.com/v2/';
        $url = $this->_apiBackend . $route;

        $ch = curl_init($url);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_PUT, true);
        } elseif ($method != 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        //timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 25);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $headers = array(
            'packpin-api-key: ' . $this->_getApiKey(),
            'Content-Type: application/json',
        );
        if ($body) {
            $dataString = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
            $headers[] = 'Content-Length: ' . strlen($dataString);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        $this->_lastStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $this->_throwFatalError(curl_error($ch));
        }
        curl_close($ch);
        unset($ch);

        $this->_tryLog($route.$response);

        if(empty($response)){
            $this->_throwNonFatalError('Empty '.$route.' response!');

            return array(
                'body' => array()
            );
        }

        return $response;
    }

    /**
     * Get info about single tracking object
     *
     * @param string $carrierCode
     * @param string $trackingCode
     *
     * @return array
     */
    public function getTrackingInfo($carrierCode, $trackingCode)
    {
        $url = sprintf(self::API_PATH_TRACKING_INFO, $carrierCode, $trackingCode);

        $hash = $carrierCode . $trackingCode;
        $cacheKey = 'packpin_tracking_' . $hash;

        if (false === ($info = $this->_getTransient($cacheKey))) {
            $res = $this->_apiRequest($url, 'GET');
            if ($res && !empty($res)) {
                $info = json_decode($res, true);
                set_transient($cacheKey, $info, 10 * MINUTE_IN_SECONDS);
            }
        }

        return $this->checkForErrors($info);
    }

    /**
     * Get list of available carriers
     *
     * @return array
     */
    public function getCarrierList()
    {
        $url = self::API_PATH_CARRIERS;

        if (false === ($info = $this->_getTransient('packpin_carrier_list'))) {
            $res = $this->_apiRequest($url, 'GET');
            if ($res && !empty($res)) {
                $info = json_decode($res, true);
                set_transient('packpin_carrier_list', $info, 6 * HOUR_IN_SECONDS);
            }
        }

        return $this->checkForErrors($info);
    }

    /**
     * Add new tracking code
     *
     * @param string $carrierCode
     * @param string $trackingCode
     * @param string|null $description
     * @param string|null $postalCode
     * @param string|null $destinationCountry
     * @param string|null $shipDate
     * @param null $orderId
     * @return array
     */
    public function addTrackingCode($carrierCode, $trackingCode, $description = null, $postalCode = null, $destinationCountry = null, $shipDate = null, $orderId = null)
    {
        $info = array();

        $url = self::API_PATH_TRACKINGS;
        $body = array(
            'code' => $trackingCode,
            'carrier' => $carrierCode,
            'description' => $description,
            'track_postal_code' => $postalCode,
            'track_ship_date' => $shipDate,
            'track_destination_country' => $destinationCountry,
            'order_id' => $orderId
        );

        $res = $this->_apiRequest($url, 'POST', $body);
        if ($res) {
            $info = json_decode($res, true);
        }

        return $this->checkForErrors($info);
    }

    public function removeTrackingCode($carrierCode, $trackingCode)
    {
        $info = array();

        $url = sprintf(self::API_PATH_TRACKING_INFO, $carrierCode, $trackingCode);

        $res = $this->_apiRequest($url, 'DELETE');

        if ($res) {
            $info = json_decode($res, true);
        } else {
            $info = array(
                "statusCode" => $this->_lastStatusCode
            );
        }

        return $this->checkForErrors($info);
    }

    public function enableConnector($status, $api_key)
    {
        $info = array();

        $url = self::API_PATH_CONNECTORS;
        $body = array(
            'plugin_type' => 'woocoomerce',
            'path' => get_bloginfo('url'),
            'enabled' => $status,
            'key' => $api_key
        );

        $res = $this->_apiRequest($url, 'POST', $body);

        if ($res) {
            $info = json_decode($res, true);
        }

        if (!$info) {
            $info = array(
                'statusCode' => 400,
                'body' => array(
                    'reason' => 'Could not connect to Wordpress shop API'
                ),
            );
        }

        return $this->checkForErrors($info);
    }

    public function testApiKey()
    {
        $info = array();

        $url = self::API_PATH_TEST;
        $res = $this->_apiRequest($url, 'GET');
        if ($res) {
            $info = json_decode($res, true);
        }

        return $this->checkForErrors($info);
    }

    public function getExtensionVersion()
    {
        return PPWSI_VERSION;
    }

    /**
     * Get details about current plan
     *
     * @return array
     */
    public function getPlanDetails()
    {
        $info = array();
        $url = self::API_PATH_PLAN_INFO;

        $res = $this->_apiRequest($url, 'GET');
        if ($res) {
            $info = json_decode($res, true);
        }

        return $this->checkForErrors($info);
    }

    protected function checkForErrors($info = array()){
        if(!in_array($info["statusCode"], $this->_okResponseHeaderCodes)){
            if(is_admin()){
                $this->_throwNonFatalError(sprintf('<em>Code</em>: %s <em>Message</em>: %s <em>Description</em>: %s', $info["statusCode"], $this->_responseHeaderCodes[$info["statusCode"]], $info["body"]["reason"]));
            }
            return false;
        }

        return $info;
    }

    protected function _throwFatalError($msg)
    {
        wp_die('A fatal error occured while accessing Packpin API:<br/><b>' . $msg . '</b><br/> Contact info@packpin.com for assistance!<br/><br/><a href="javascript:window.history.back();">Go back</a>', 'Fatal Packpin API Error!');
    }

    protected function _throwNonFatalError($msg){
        update_option("ppApiError", "Packpin API Request Failed! Contact info@packpin.com for assistance!<br/>" . $msg);
    }

    protected function _getTransient($key){
        return (defined('PACKPIN_LOCAL')) ? false : (($val = get_transient($key)) ? $val : false);
    }

    protected function _tryLog($msg){
        if(!defined('PACKPIN_LOCAL'))
            return;

        syslog(5, 'PackpinWoo Error: '.$msg);
    }

}