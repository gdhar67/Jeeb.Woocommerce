<?php

/*
Plugin Name: WooCommerce Jeeb Payment Gateway
Plugin URI: https://jeeb.io
Description: Pay With Jeeb Payment gateway for woocommerce
Version: 1.0.0
Author: Jeeb
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

add_action('plugins_loaded', 'woocommerce_paywith_jeeb_init', 0);


function woocommerce_paywith_jeeb_init(){

    if(!class_exists('WC_Payment_Gateway')) return;


    class WC_Paywith_Jeeb extends WC_Payment_Gateway{

        public function __construct(){
            $this->id = 'paywithjeeb';
            $this->method_title = 'Pay With Jeeb';
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this -> title = $this -> settings['title'];
            $this -> description = $this -> settings['description'];
            $this -> signature = $this -> settings['signature'];
            $this -> notify_url = WC()->api_request_url('WC_Paywith_Jeeb');
            $this -> currency_convert_url = "https://jeeb.io/api/convert/";
            $this -> create_invoice_url = "https://jeeb.io/api/bitcoin/issue/";
            $this -> payment_url = "https://jeeb.io/invoice/payment";
            $this -> confirm_payment = "http://jeeb.io/api/bitcoin/confirm/";
            $this -> test_currency_convert_url = "http://test.jeeb.io:9876/api/convert/";
            $this -> test_create_invoice_url = "http://test.jeeb.io:9876/api/bitcoin/issue/";
            $this -> test_payment_url = "http://test.jeeb.io:9876/invoice/payment";
            $this -> test_confirm_payment = "http://test.jeeb.io:9876/api/bitcoin/confirm/";
            $this -> test = $this -> settings['test'];
            // If testing is on
            if ( $this -> test === 'yes') {
              $this -> current_currency_convert_url = $this -> test_currency_convert_url;
              $this -> current_create_invoice_url = $this -> test_create_invoice_url;
              $this -> current_payment_url = $this -> test_payment_url;
              $this -> current_confirm_payment = $this -> test_confirm_payment;
              $this -> allowReject = (boolean) false;
            }
            // If testing is off
            else {
              $this -> current_currency_convert_url = $this -> currency_convert_url;
              $this -> current_create_invoice_url = $this -> create_invoice_url;
              $this -> current_payment_url = $this -> payment_url;
              $this -> current_confirm_payment = $this -> confirm_payment;
              $this -> allowReject = (boolean) true;
            }
            //Actions

            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, array(&$this, 'process_admin_options' ) );
            } else {
                add_action( 'woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options' ) );
            }

            add_action('woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page'));

            //Payment Listener/API hook
            add_action('init', array(&$this, 'paywith_jeeb_response'));
            //update for woocommerce >2.0
            add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'paywith_jeeb_response' ) );

            add_action('woocommerce_thankyou_order_received_text', array( &$this, 'payment_response'));

            add_action('woocommerce_api_wc_paywith_jeeb', array($this, 'ipn_callback'));
        }

        function init_form_fields(){

            $this -> form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Pay With Jeeb Payment Module.',
                    'default' => 'no'),
                'title' => array(
                    'title' => 'Title:',
                    'type'=> 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Pay With Jeeb'),
                'description' => array(
                    'title' => 'Description:',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay securely with bitcoins through Jeeb Payment Gateway.'),
                'signature' => array(
                    'title' => 'Signature',
                    'type' => 'text',
                    'description' => 'This signature is the one provided by Jeeb.'),
                'test' => array(
        				    'title'   => __( 'Test Jeeb', 'wcjeeb' ),
        				    'type'    => 'checkbox',
        				    'label'   => __( 'Connect to the Test Jeeb server for testing.', 'wcjeeb' ),
        				    'default' => 'no')
            );
        }

        public function admin_options(){
            echo '<h3>Pay With Jeeb Payment Gateway</h3>';
            echo '<p>Jeeb is most popular payment gateway for online shopping in Iran.</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this -> generate_settings_html();
            echo '</table>';

        }
        // Get bitcoin equivalent to irr
        function convert_irr_to_btc ( $order_id ) {
            global $woocommerce;
            $order = new WC_Order( $order_id );
            $amount = $order->total;

            $request = wp_remote_get( $this -> current_currency_convert_url . $this->signature.'/'.$amount.'/irr/btc',
            array(
              'timeout'     => 120
            ) );
            $body = wp_remote_retrieve_body( $request );
            $data = json_decode( $body , true);
            // var_dump ($data);
            // Return the equivalent bitcoin value acquired from Jeeb server.
            return (float) $data["result"];


        }

        // Create invoice for payment
        function create_invoice( $order_id , $btn) {
            global $woocommerce;
            $order = new WC_Order( $order_id );
            $data = array(
              "orderNo" => $order_id,
              "requestAmount" => $btn,
              "notificationUrl" => $this -> notify_url,
              "callBackUrl" => $this->get_return_url(),
              "allowReject" => $this -> allowReject
            );
            $data_string = json_encode($data);

            $url = $this -> current_create_invoice_url . $this->signature;
            $response = wp_remote_post(
                            $url,
                            array(
                                'method'      => 'POST',
                                'timeout'     => 45,
                                'headers'     => array( "content-type" => "application/json"),
                                'body' => $data_string
                            )
                        );

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body , true);
            // var_dump ($data);
            // Store the token in the database
            update_post_meta($order->id, 'jeeb_invoice_token', $data['result']['token'] );

            return $data['result']['token'];

        }

        function redirect_payment( $token ) {

          // Using Auto-submit form to redirect user with the token
          return "<form id='form' method='post' action='".$this -> current_payment_url."'>".
                  "<input type='hidden' autocomplete='off' name='token' value='".$token."'/>".
                 "</form>".
                 "<script type='text/javascript'>".
                      "document.getElementById('form').submit();".
                 "</script>";

        }

        // Displaying text on the receipt page and sending requests to Jeeb server.
        function receipt_page( $order ) {
            echo '<p>Thank you ! Your order is now pending payment. You should be automatically redirected to Jeeb to make payment.</p>';
            // Convert irr to btn
            echo $btn = $this->convert_irr_to_btc ( $order );
            // Create Invoice for payment in the Jeeb server
            echo $token = $this->create_invoice ( $order , $btn );
            // Redirecting user for the payment
            echo $this->redirect_payment ( $token );
        }

        // Process payment
        function process_payment( $order_id ) {

            $order = new WC_Order( $order_id );

            $order->update_status( 'pending', __( 'Awaiting Bitcoin payment', 'wcjeeb' ) );

            if ( version_compare( WOOCOMMERCE_VERSION, '2.1.0', '>=' ) ) {
                /* 2.1.0 */
                $checkout_payment_url = $order->get_checkout_payment_url( true );
            } else {
                /* 2.0.0 */
                $checkout_payment_url = get_permalink( get_option ( 'woocommerce_pay_page_id' ) );
            }

            return array(
                'result' 	=> 'success',
                'redirect'	=> $checkout_payment_url
            );


        }
        // Process the payment response acquired from Jeeb
        function payment_response( $order ) {
            global $woocommerce;

            if(isset($_POST["notificationUrl"])) {
              $order = new WC_Order($_POST["orderNo"]);
            }

            if ($_POST["stateId"]==3){
              echo "Your Payment was successful and we are awaiting for bitcoin network to confirm the payment.";
              // Order is Paid but not yet confirmed, put it On-Hold (Awaiting Payment).
              $order->update_status('on-hold', __('Bitcoin payment received, awaiting confirmation.', 'wcjeeb'));
              // Reduce stock level
              $order->reduce_order_stock();
              // Empty cart
              WC()->cart->empty_cart();

            }
            if ($_POST["stateId"]==5){
              echo "Your Payment was expired. To pay again please go to checkout page.";
              $order->add_order_note(__('Payment was unsuccessful', 'wcjeeb'));
              // Cancel order
              $order->cancel_order('Bitcoin payment expired.');
            }
            if ($_POST["stateId"]==7){
              echo "Partial payment Received";
              $order->add_order_note(__('Partial Payment was received', 'wcjeeb'));
              // Partial order received, waiting for full payment
              $order->cancel_order(__('Partial Payment was received, hence the payment was rejected.', 'wcjeeb'));
            }

        }

        // Process the notification acquired from Jeeb server
        public function ipn_callback(){
            @ob_clean();

            $postdata = file_get_contents("php://input");
            $json = json_decode($postdata, true);

            global $woocommerce;
            global $wpdb;

            $order = new WC_Order($json["orderNo"]);

            $token = $wpdb->get_row("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='jeeb_invoice_token' AND post_id='".$json["orderNo"]."'");

            if ( $json['stateId']== 1 ) {
              $order->add_order_note(__('Notification from Jeeb - Payment was created', 'wcjeeb'));
            }
            else if ( $json['stateId']== 2 ) {
              $order->add_order_note(__('Notification from Jeeb - Waiting for payment', 'wcjeeb'));
            }
            else if ( $json['stateId']== 3 ) {
              $order->add_order_note(__('Notification from Jeeb - Waiting for payment confirmation', 'wcjeeb'));
            }
            else if ( $json['stateId']== 4 ) {
              $order->add_order_note(__('Payment is now confirmed by bitcoin network', 'wcjeeb'));

              $data = array(
                "token" => $token->meta_value,
              );

              $data_string = json_encode($data);

              $url = $this -> current_confirm_payment .$json["signature"];
              $response = wp_remote_post(
                              $url,
                              array(
                                  'method'      => 'POST',
                                  'timeout'     => 45,
                                  'headers'     => array( "content-type" => "application/json"),
                                  'body' => $data_string
                              )
                          );

              $body = wp_remote_retrieve_body( $response );
              $response = json_decode( $body , true);
              var_dump($data);

              if($response['result']['isConfirmed']){
                $order->add_order_note(__('Confirm Payment with jeeb was successful', 'wcjeeb'));

                $order->payment_complete();

              }
              else {
                $order->add_order_note(__('Confirm Payment was rejected by Jeeb', 'wcjeeb'));

                $order->update_status('on-hold', __('Jeeb confirm payment failed', 'wcjeeb'));

              }
            }
            else if ( $json['stateId']== 5 ) {
              $order->add_order_note(__('Notification from Jeeb - Payment was expired', 'wcjeeb'));
            }
            else if ( $json['stateId']== 6 ) {
              $order->add_order_note(__('Notification from Jeeb - Over payment occurred', 'wcjeeb'));
            }
            else if ( $json['stateId']== 7 ) {
              $order->add_order_note(__('Notification from Jeeb - Under payment occurred', 'wcjeeb'));
            }
            else{
              $order->add_order_note(__('Notification from Jeeb could not be proccessed - Error in reading state Id ', 'wcjeeb'));
            }

        }

        // End of class
}

    /* Add the Gateway to WooCommerce */

    function woocommerce_add_paywith_jeeb_gateway($methods) {
        $methods[] = 'WC_Paywith_Jeeb';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways','woocommerce_add_paywith_jeeb_gateway');
}

?>
