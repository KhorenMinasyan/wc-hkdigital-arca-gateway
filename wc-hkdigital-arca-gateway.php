<?php
/*
Plugin Name: HKDigital Arca Gateway
Plugin URI: https://github.com/greghub/ameria-woocommerce-gateway
Description: Add Credit Card Payment Gateway for WooCommerce
Version: 3.0.0
Author: Khoren Minasyan
Author URI: https://github.com/greghub/
License: MIT License
*/

require_once( __DIR__.'/PluginUpdater.php' );
if ( is_admin() ) {
    new PluginUpdater( __FILE__, 'KhorenMinasyan', "wc-hkdigital-arca-gateway" );
}

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'hkd_add_gateway_class' );
function hkd_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_HKD_Arca_Gateway';
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'hkd_init_gateway_class' );
function hkd_init_gateway_class() {

    class WC_HKD_Arca_Gateway extends WC_Payment_Gateway {


        private $api_url = 'https://ipaytest.arca.am:8445/payment/rest/registerPreAuth.do';
        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct() {
            $this->id                 = 'hkd_arca';
            $this->icon               = '';
            $this->has_fields         = false;
            $this->method_title       = 'HKDigital Arca Gateway';
            $this->method_description = 'will be displayed on the options page';

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled     = $this->get_option( 'enabled' );
            $this->testmode    = 'yes' === $this->get_option( 'testmode' );
            $this->user_name   = $this->testmode ? $this->get_option( 'test_user_name' ) : $this->get_option( 'live_user_name' );
            $this->password    = $this->testmode ? $this->get_option( 'test_password' ) : $this->get_option( 'live_password' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            // We need custom JavaScript to obtain a token
//            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

             add_action( 'woocommerce_api_complete', array( $this, 'webhook' ) );
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Credit Card',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with your credit card via our super-cool payment gateway.',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'test_user_name' => array(
                    'title'       => 'Test User Name',
                    'type'        => 'text'
                ),
                'test_password' => array(
                    'title'       => 'Test Password',
                    'type'        => 'password',
                ),
                'live_user_name' => array(
                    'title'       => 'Live User Name',
                    'type'        => 'text'
                ),
                'live_password' => array(
                    'title'       => 'Live Password',
                    'type'        => 'password'
                )
            );
        }


        public function validate_fields() {

        }

        public function process_payment( $order_id ) {
            global $woocommerce;

            $order  = wc_get_order( $order_id );
            $amount = $order->get_total();

            $response = wp_remote_post(
                $this->api_url.
                '?amount='.(int)$amount.
                '&orderNumber='.rand(1000, 9999).
                '&password='.$this->password.
                '&userName='.$this->user_name.
                '&description=Order N&returnUrl='.get_site_url().'/wc-api/complete');

            if( !is_wp_error( $response ) ) {

                $body = json_decode( $response['body'] );

                if ( $body->errorCode == 0 ) {

                    $order->update_status( 'on-hold', __( 'Awaiting offline payment', 'wc-gateway-offline' ) );
                    wc_reduce_stock_levels( $order_id );
                    $woocommerce->cart->empty_cart();

                    return array(
                        'result' => 'success',
                        'redirect' => $body->formUrl
                    );

                } else {
                    wc_add_notice(  'Please try again.', 'error' );
                }

            } else {
                wc_add_notice(  'Connection error.', 'error' );
            }
        }

        /*
         * In case you need a webhook, like PayPal IPN etc
         */
        public function webhook() {
//            $order = wc_get_order( $_GET['id'] );
//            $order->payment_complete();
//            wc_reduce_stock_levels( $_GET['id'] );

            update_option('webhook_debug', $_GET);
        }
    }
}
