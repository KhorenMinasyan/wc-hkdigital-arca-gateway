<?php
/*
Plugin Name: HKDigital Arca Payment Gateway
Plugin URI: #
Description: Add Credit Card Payment Gateway for WooCommerce
Version: 3.0.0
Author: HK Digital Agency LLC
Author URI: https://hkdigital.am
License: MIT License
*/

require_once( __DIR__.'/ArcaPluginUpdater.php' );
if ( is_admin() ) new ArcaPluginUpdater( __FILE__, 'KhorenMinasyan', "wc-hkdigital-arca-gateway" );

add_filter( 'woocommerce_payment_gateways', 'hkd_add_gateway_class' );
function hkd_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_HKD_Arca_Gateway';
    return $gateways;
}

add_action( 'plugins_loaded', 'hkd_init_gateway_class' );
function hkd_init_gateway_class() {

    class WC_HKD_Arca_Gateway extends WC_Payment_Gateway {

        private $ownerSiteUrl = 'https://idram.hkdigital.am';
        private $api_url      = 'https://ipaytest.arca.am:8445/payment/rest';

        /**
         * WC_HKD_Arca_Gateway constructor.
         */
        public function __construct() {
            global $woocommerce;

            $this->id                 = 'hkd_arca';
            $this->icon               = '';
            $this->has_fields         = false;
            $this->method_title       = 'HKDigital Arca Gateway';
            $this->method_description = 'will be displayed on the options page';

            if (isset($_POST['hkd_arca_checkout_id'])) update_option('hkd_arca_checkout_id', $_POST['hkd_arca_checkout_id']);

            $this->init_form_fields();
            $this->init_settings();

            $this->title                 = $this->get_option( 'title' );
            $this->description           = $this->get_option( 'description' );
            $this->enabled               = $this->get_option( 'enabled' );
            $this->hkd_arca_checkout_id  = get_option( 'hkd_arca_checkout_id' );
            $this->language              = $this->get_option( 'language' );
            $this->testmode              = 'yes' === $this->get_option( 'testmode' );
            $this->user_name             = $this->testmode ? $this->get_option( 'test_user_name' ) : $this->get_option( 'live_user_name' );
            $this->password              = $this->testmode ? $this->get_option( 'test_password' ) : $this->get_option( 'live_password' );
            $this->debug                 = 'yes' === $this->get_option( 'debug' );

            if ($this->debug) { if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '<' ) ) $this->log = $woocommerce->logger(); else $this->log = new WC_Logger(); }

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_arca_successful', array( $this, 'webhook_arca_successful' ) );
            add_action( 'woocommerce_api_arca_failed', array( $this, 'webhook_arca_failed' ) );
        }

        public function init_form_fields(){
            $debug = __( 'Log HKD ARCA Gateway events, inside <code>woocommerce/logs/arca.txt</code>', 'hkd_gateway' );
            if ( !version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
                if ( version_compare( WOOCOMMERCE_VERSION, '2.2.0', '<' ) )
                    $debug = str_replace( 'arca', $this->id . '-' . date('Y-m-d') . '-' . sanitize_file_name( wp_hash( $this->id ) ), $debug );
                elseif( function_exists('wc_get_log_file_path') ) {
                    $debug = str_replace( 'woocommerce/logs/arca.txt', '<a href="/wp-admin/admin.php?page=wc-status&tab=logs&log_file=' . $this->id . '-' . date('Y-m-d') . '-' . sanitize_file_name( wp_hash( $this->id ) ) . '-log" target="_blank">' . wc_get_log_file_path( $this->id ) . '</a>' , $debug );
                }
            }

            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __( 'Enable/Disable', 'hkd_gateway' ),
                    'label'       => __( 'Enable Gateway', 'hkd_gateway' ),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => __( 'Title', 'hkd_gateway' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'hkd_gateway' ),
                    'default'     => 'Credit Card',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'hkd_gateway' ),
                    'type'        => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'hkd_gateway' ),
                    'default'     => 'Pay with your credit card via our super-cool payment gateway.',
                ),
                'language' => array(
                    'title'       => __( 'Language', 'hkd_gateway' ),
                    'type'        => 'select',
                    'options'     => [
                        'hy' => 'Armenia',
                        'en' => 'English',
                        'ru' => 'Russian',
                    ],
                    'description' => '',
                    'default'     => 'am',
                ),
                'testmode' => array(
                    'title'       => __( 'Test mode', 'hkd_gateway' ),
                    'label'       => __( 'Enable Test Mode', 'hkd_gateway' ),
                    'type'        => 'checkbox',
                    'description' => __( 'Place the payment gateway in test mode using test API keys.', 'hkd_gateway' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'debug' => array(
                    'title' => __( 'Debug Log', 'hkd_gateway' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable logging', 'hkd_gateway' ),
                    'default' => 'no',
                    'description' => $debug,
                ),
                'test_user_name' => array(
                    'title'       => __( 'Test User Name', 'hkd_gateway' ),
                    'type'        => 'text'
                ),
                'test_password' => array(
                    'title'       => __( 'Test Password', 'hkd_gateway' ),
                    'type'        => 'password',
                ),
                'live_user_name' => array(
                    'title'       => __( 'Live User Name', 'hkd_gateway' ),
                    'type'        => 'text'
                ),
                'live_password' => array(
                    'title'       => __( 'Live Password', 'hkd_gateway' ),
                    'type'        => 'password'
                ),
            );
        }

        public function process_payment( $order_id ) {
            global $woocommerce;

            $order  = wc_get_order( $order_id );
            $amount = $order->get_total();

            $response = wp_remote_post(
                $this->api_url. '/register.do?amount='.(int)$amount.'&currency=051'.'&orderNumber='.$order_id.'&password='.$this->password.
                '&userName='.$this->user_name. '&description=Order N'.$order_id. '&failUrl'.get_site_url().'/wc-api/arca_failed
                &returnUrl='.get_site_url().'/wc-api/arca_successful&language='.$this->language
            );

            if( !is_wp_error( $response ) ) {
                $body = json_decode( $response['body'] );
                if ( $body->errorCode == 0 ) {
                    $order->update_status( 'on-hold' );
                    wc_reduce_stock_levels( $order_id );
                    $woocommerce->cart->empty_cart();

                    return array('result' => 'success', 'redirect' => $body->formUrl);
                } else {
                    if ( $this->debug ) $this->log->add( $this->id, 'Оплата заказа #'.$order_id.' отменена или завершилась неудачей.' );
                    $order->update_status( 'failed', $body->errorMessage );
                    wc_add_notice(  __( 'Please try again.', 'hkd_gateway' ), 'error' );
                }
            } else {
                if ( $this->debug ) $this->log->add( $this->id, 'Оплата заказа #'.$order_id.' отменена или завершилась неудачей.' );
                $order->update_status( 'failed' );
                wc_add_notice(  __( 'Connection error.', 'hkd_gateway' ), 'error' );
            }
        }

        public function admin_options()
        {
            $validate = $this->validateFields();if(!$validate['status']){$message = $validate['message'];}
            if (!empty($message)) { ?><div id="message" class="updated fade"><p><?php echo $message; ?></p></div><?php } ?>
            <div class="wrap-content" style="width: 69%;display: inline-block;"><table class="form-table"><thead><tr><th scope="row"><h3>ARCA</h3></th></tr></thead>
            <?php if($validate['status']){$this->generate_settings_html();?><tr valign="top"><th scope="row">Callback Url</th>
            <td><?=get_site_url()?>/wc-api/arca_successful</td></tr><?php }else{?><tr valign="top"><th scope="row">Insert Token To Activate Gateway</th>
            <td><input type="text" name="hkd_arca_checkout_id" id="hkd_arca_checkout_id" value="<?php echo $this->hkd_arca_checkout_id; ?>" /></td></tr>
            <?php } ?></table></div><div class="wrap-content" style="width: 29%;display: inline-block;"><iframe width="400" height="500" src="<?=$this->ownerSiteUrl?>?iframe=ad"></iframe></div><?php
        }

        /**
         * @return array|mixed|object
         */
        public function validateFields() {
            if($this->hkd_arca_checkout_id=='')return['message'=>'you must fill token','status'=>false];$response=wp_remote_post($this->ownerSiteUrl.
            '/wp-json/hkd-payment/v1/checkout/',['body'=>['domain'=>$_SERVER['SERVER_NAME'],'checkoutId'=>$this->hkd_arca_checkout_id]]);if(!is_wp_error($response))
            {return json_decode($response['body'],true);}else{return['message'=>'token not valid!','status'=>false];}
        }

        public function webhook_arca_successful() {
            if(isset($_GET['orderId'])) {
                $response = wp_remote_post(
                        $this->api_url.'/getOrderStatus.do?orderId='.$_GET['orderId'].'&language='.$this->language .
                        '&password='.$this->password.'&userName='.$this->user_name
                );

                if( !is_wp_error( $response ) ) {
                    $body = json_decode( $response['body'] );
                    if ( $body->errorCode == 0 ) {
                        $order  = wc_get_order( $body->OrderNumber );
                        if ( $this->debug ) $this->log->add( $this->id, 'Order #'.$body->OrderNumber.' successfully added to processing: #'.$_GET['orderId'].'. Error: '.$body->errorMessage );
                        $order->update_status( 'processing' );
                        wp_redirect($this->get_return_url( $order ));
                    } else {
                        if ( $this->debug ) $this->log->add( $this->id, 'something went wrong with Arca callback: #'.$_GET['orderId'].'. Error: '.$body->errorMessage );
                    }
                } else {
                    if ( $this->debug ) $this->log->add( $this->id, 'something went wrong with Arca callback: #'.$_GET['orderId'] );
                }
            }
        }

        public function webhook_arca_failed() {
            if(isset($_GET['orderId'])) {
                $response = wp_remote_post(
                    $this->api_url. '/getOrderStatus.do?orderId='.$_GET['orderId'].'&language='.$this->language.
                    '&password='.$this->password.'&userName='.$this->user_name
                );
                if( !is_wp_error( $response ) ) {
                    $body = json_decode( $response['body'] );
                    if ( $body->errorCode == 0 ) {
                        $order  = wc_get_order( $body->OrderNumber );
                        $order->update_status( 'failed', $body->errorMessage );
                        wp_redirect($this->get_return_url( $order ));
                    } else {
                        if ( $this->debug ) $this->log->add( $this->id, 'something went wrong with Arca callback: #'.$_GET['orderId'].'. Error: '.$body->errorMessage );
                    }
                } else {
                    if ( $this->debug ) $this->log->add( $this->id, 'something went wrong with Arca callback: #'.$_GET['orderId'] );
                }
            }
        }
    }
}
