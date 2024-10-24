<?php

/*
 * Load WooCommerce GATE Payment gateway functions
 *
 * @package WALLETOR_GATE
 */

namespace WALLETOR_GATE\Inc;

use WALLETOR_GATE\Inc\Traits\Singleton;
use WC_Payment_Gateway;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

class WC_Gate extends WC_Payment_Gateway {

    use Singleton;

    //Construct function
    protected function __construct() {

        $this->id = 'wc_gate';
        $this->icon = WALLETOR_GATE_ASSETS_DIR_URL . 'images/gate_pay.png'; // URL of the icon that will be displayed on checkout
        $this->has_fields = false; // If you need custom fields on checkout
        $this->method_title = 'Pay with GatePay';
        $this->method_description = 'Pay using GatePay payment gateway.';

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        //load class hooks
        $this->setup_hooks();
    }

    /*
     * Function to load action and filter hooks
     */

    protected function setup_hooks() {

        //actions and filters
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_blocks_loaded', [$this, 'gateway_block_support']);
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', [$this, 'woo_order_query'], 10, 2);
    }

    /*
     * Filter hook function to create an invoice
     *
     * @param $order WooCommerce order object
     *
     * @return $result Array of result
     */

    public function create_gate_invoice($order) {

        if (empty($this->get_option('merchant_user_id')) || empty($this->get_option('client_id'))) {
            $result = ['status' => false, 'message' => __('Merchant user ID or the Client ID is not set by the admin.', 'woocommerce')];
            return $result;
        }

        $total = $order->get_total();
        $currency = get_woocommerce_currency() . "T";
        $chain = $this->get_chain($currency);

        if (empty($chain)) {
            $result = ['status' => false, 'message' => __('No chain available for the currency: ' . $currency, 'woocommerce')];
            return $result;
        }

        $items = $order->get_items();
        $goods = [];

        foreach ($items as $item) {
            $product = $item->get_product();
            $goods[] = $product->get_title();
        }

        $goods_names = $this->limit_string(implode(", ", $goods), 140);

        $data = [
            'merchantTradeNo' => (string) floor(microtime(true) * 1000),
            'currency' => $currency,
            'orderAmount' => $total,
            'env' => ['terminalType' => 'APP'],
            'goods' => ["goodsName" => $goods_names],
            'chain' => $chain->chain,
            'merchantUserId' => (int) $this->get_option('merchant_user_id'), //3915898,
            'fullCurrType' => $chain->full_curr_type,
            'returnUrl' => WALLETOR_GATE_SITE_BASE_URL,
            'cancelUrl' => WALLETOR_GATE_SITE_BASE_URL
        ];

        $invoice = $this->make_api_call(
                'https://openplatform.gateapi.io/v1/pay/checkout/order',
                'POST',
                $data
        );

        if (isset($invoice->status) && $invoice->status != 'SUCCESS') {
            $result = ['status' => false, 'message' => __($invoice->errorMessage, 'woocommerce')];
            return $result;
        }

        $result = ['status' => true, 'invoice' => $invoice];

        return $result;
    }

    /*
     * Function to make API calls to Gate.io
     *
     * @param $url String URL of the API end point
     * @param $method String API call method, either GET or POST
     * @param $data Array of data that we may need to send to API
     * @param $transient WooCommerce cart hash, so that we can get data from WP transient
     *
     * @return $result API call result, json decoded object
     */

    public function make_api_call($url, $method = 'GET', $data = [], $transient = '') {

        if (!empty($transient)) {
            if (!empty($invoice = get_transient("__GATE_" . $transient))) {
                return $invoice;
            }
        }

        $client_id = $this->get_option('client_id');

        $json_data = '';

        if (!empty($data)) {
            $json_data = json_encode($data);
        }

        $nonce = wp_create_nonce(WALLETOR_GATE_TEXT_DOMAIN . "_nonce");
        $script_tz = date_default_timezone_get();

        date_default_timezone_set("UTC");
        $timestamp = (string) floor(microtime(true) * 1000);
        date_default_timezone_set($script_tz);

        $signature = $this->generate_signature($nonce, $timestamp, $json_data);

        $headers = [
            'Content-Type: application/json',
            'X-GatePay-Certificate-ClientId: ' . $client_id,
            'X-GatePay-Timestamp: ' . $timestamp,
            'x-GatePay-Nonce: ' . $nonce,
            'x-GatePay-Signature: ' . $signature
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        }

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        }

        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result);

        if (!empty($transient) && $result->status == 'SUCCESS') {
            $expiration = 1800;
            set_transient("__GATE_" . $transient, $result, $expiration);
        }

        return $result;
    }

    /*
     * Function to limit the string to specific length
     */

    public function limit_string($string, $limit, $end = "...") {

        $string = explode(' ', $string, $limit);
        if (count($string) >= $limit) {
            array_pop($string);
            $string = implode(" ", $string) . $end;
        } else {
            $string = implode(" ", $string);
        }

        return $string;
    }

    /*
     * Function to get GatePay chains
     *
     * @param $currency String GatePay Currency against that we have to get chains
     *
     * @return $chain First Chain Object
     */

    public function get_chain($currency) {

        $chains = $this->make_api_call('https://openplatform.gateapi.io/v1/pay/address/chains?currency=' . $currency);

        $chain = [];
        if (isset($chains->status) && $chains->status != 'SUCCESS') {
            return $chain;
        }

        if (isset($chains->data) && isset($chains->data->chains)) {
            $chain = array_shift($chains->data->chains);
        }

        return $chain;
    }

    /*
     * Filter function to add meta query in wc_get_orders
     */

    public function woo_order_query($query, $query_vars) {

        if (!empty($query_vars['_gate_invoice'])) {
            $query['meta_query'][] = array(
                'key' => '_gate_invoice',
                'value' => esc_attr($query_vars['_gate_invoice']),
                'compare' => '!='
            );
        }

        return $query;
    }

    /*
     * Function to update all orders statuses
     */

    public function update_orders_status() {

        $args = array(
            'limit' => 3,
            'status' => 'on-hold',
            '_gate_invoice' => ''
        );

        $orders = wc_get_orders($args);

        // NOT empty
        if (!empty($orders)) {
            foreach ($orders as $order) {
                $invoice = get_post_meta($order->get_id(), '_gate_invoice', true);
                $invoice_data = $this->make_api_call('https://openplatform.gateapi.io/v1/pay/order/query', 'POST', ['prepayId' => $invoice]);
                if (isset($invoice_data->data) && isset($invoice_data->data->status)) {
                    if ($invoice_data->data->status == 'PAID') {
                        $order->update_status('completed', __('Paid', 'woocommerce'));
                    } else if ($invoice_data->data->status != 'PENDING') {
                        delete_post_meta($order->get_id(), '_gate_invoice');
                    }
                }
            }
        }
    }

    /*
     * Function to create signature for Gate.io
     */

    public function generate_signature($nonce = '', $timestamp = '', $body = '') {

        $payment_key = $this->get_option('payment_key');

        $payload = "$timestamp\n$nonce\n$body\n";
        $signature = hash_hmac('sha512', $payload, $payment_key, true);

        return bin2hex($signature);
    }

    /*
     * Function to add gateway block support
     */

    public function gateway_block_support() {

        // registering the class we have just included
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'register_woo_payment_blocks'], 10, 1);
    }

    /*
     * Function to register the payment block into Woo Registry
     */

    public function register_woo_payment_blocks(PaymentMethodRegistry $payment_method_registry) {

        $payment_method_registry->register(WC_Gate_Block::get_instance());
    }

    /*
     * Function to Initialize form fields
     */

    public function init_form_fields() {

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable GatePay Payment Gateway', 'woocommerce'),
                'default' => 'no'
            ],
            'payment_key' => [
                'title' => __('Payment Key', 'woocommerce'),
                'type' => 'text',
                'description' => __('GatePay API Payment Key', 'woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'client_id' => [
                'title' => __('Client Id', 'woocommerce'),
                'type' => 'text',
                'description' => __('GatePay API Client Id', 'woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'merchant_user_id' => [
                'title' => __('Merchant User ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('GatePay merchant user id', 'woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'title' => [
                'title' => __('Title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('Pay with GatePay', 'woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce') . '<br><br><b>' . __('IPN callback webhook URL: ', 'woocommerce') . '</b>' . WALLETOR_GATE_SITE_BASE_URL . 'wc-api/' . $this->id . '/',
                'default' => __('Pay using GatePay payment gateway', 'woocommerce'),
            ],
        ];
    }

    /*
     * Function to Process the payment
     *
     * @param $order_id WooCommerce order id
     */

    public function process_payment($order_id) {

        $order = wc_get_order($order_id);
        $invoice = $this->create_gate_invoice($order);

        if (!$invoice['status']) {
            // Return error
            return array(
                'result' => 'failure',
                'messages' => $invoice['message'],
            );
        }

        $redirect = $invoice['invoice']->data->location;
        update_post_meta($order_id, '_gate_invoice', $invoice['invoice']->data->prepayId);

        // Mark as on-hold (we're awaiting the payment)
        $order->update_status('on-hold', __('Awaiting GatePay payment', 'woocommerce'));

        // Reduce stock levels
        //wc_reduce_stock_levels($order_id);
        //$order->payment_complete();
        // Remove cart
        WC()->cart->empty_cart();

        // Return thank you page redirect
        return array(
            'result' => 'success',
            'redirect' => !empty($redirect) ? $redirect : $this->get_return_url($order),
        );
    }
}
