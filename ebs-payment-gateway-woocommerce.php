<?php
/*
Plugin Name: WooCommerce EBS gateway
Plugin URI: http://www.mrova.com/
Description: Extends WooCommerce with mrova EBS gateway.
Version: 1.0
Author: mRova
Author URI: http://www.mrova.com/

    Copyright: © 2009-2013 mRova.
    License: GNU General Public License v3.0
    License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

    add_action('plugins_loaded', 'woocommerce_mrova_ebs_init', 0);

    function woocommerce_mrova_ebs_init() {

        if ( !class_exists( 'WC_Payment_Gateway' ) ) return;


        if($_GET['msg']!=''){
            add_action('the_content', 'showMessage');
        }

        function showMessage($content){
            return '<div class="box '.htmlentities($_GET['type']).'-box">'.htmlentities(urldecode($_GET['msg'])).'</div>'.$content;
        }
    /**
     * Gateway class
     */
    class WC_Mrova_EBS extends WC_Payment_Gateway {
        protected $msg = array();
        public function __construct(){
            // Go wild in here
            $this -> id = 'ebs';
            $this -> method_title = __('EBS', 'mrova');

            $this -> has_fields = false;

            $this -> init_form_fields();
            $this -> init_settings();
            /*
            account_id, return_url, mode, reference_no, amount, description
            name, address, city, state, postal_code, country, phone, email,
            secure_hash
            */
            $this -> title = $this -> get_option('title');
            $this -> description = $this -> get_option('description');
            
            $this -> account_id = $this -> get_option('account_id');
            $this -> secret_key = $this -> get_option('secret_key');
            
            $this -> redirect_page_id = $this -> get_option('redirect_page_id');
            
            $this -> liveurl = 'https://secure.ebs.in/pg/ma/sale/pay';
            $this -> mode = $this -> get_option('mode');

            $this -> msg['message'] = "";
            $this -> msg['class']   = "";

            add_action('init', array(&$this, 'check_ebs_response'));
            //update for woocommerce >2.0
            add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_ebs_response' ) );

            add_action('valid-ebs-request', array(&$this, 'successful_request'));
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
            } else {
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
            }
            add_action('woocommerce_receipt_ccavenue', array(&$this, 'receipt_page'));
            add_action('woocommerce_thankyou_ccavenue',array(&$this, 'thankyou_page'));
        }

        function init_form_fields(){

            $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'mrova'),
                    'type' => 'checkbox',
                    'label' => __('Enable EBS Payment Module.', 'mrova'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'mrova'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'mrova'),
                    'default' => __('EBS', 'mrova')),
                'description' => array(
                    'title' => __('Description:', 'mrova'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'mrova'),
                    'default' => __('Pay securely by Credit or Debit card or internet banking through EBS Secure Servers.', 'mrova')),
                'account_id' => array(
                    'title' => __('Account ID', 'mrova'),
                    'type' => 'text',
                    'description' => __('Please enter your ebs account id')),
                'secret_key' => array(
                    'title' => __('Working Key', 'mrova'),
                    'type' => 'text',
                    'description' =>  __('Please enter your ebs secret key', 'mrova'),
                    ),          
                'mode' => array(
                    'title' => __( 'Mode', 'mrova' ), 
                    'type' => 'checkbox', 
                    'label' => __( 'Enable Test Mode', 'mrova' ), 
                    'default' => 'yes',
                    'description' => __( 'This controls for selecting the payment mode as TEST or LIVE.', 'mrova' )
                    ),
                'redirect_page_id' => array(
                    'title' => __('Return Page'),
                    'type' => 'select',
                    'options' => $this -> get_pages('Select Page'),
                    'description' => "URL of success page"
                    )
                );


}
        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options(){
            echo '<h3>'.__('EBS Payment Gateway', 'mrova').'</h3>';
            echo '<p>'.__('EBS is most popular payment gateway for online shopping in India').'</p>';
            echo '<table class="form-table">';
            $this -> generate_settings_html();
            echo '</table>';

        }
        /**
         *  There are no payment fields for EBS, but we want to show the description if set.
         **/
        function payment_fields(){
            if($this -> description) echo wpautop(wptexturize($this -> description));
        }
        /**
         * Receipt Page
         **/
        function receipt_page($order){
            echo '<p>'.__('Thank you for your order, please click the button below to pay with EBS.', 'mrova').'</p>';
            echo $this -> generate_ebs_form($order);
        }
        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id){
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => add_query_arg('order',
                $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
            );
        }
        /**
         * Check for valid EBS server callback
         **/
        function check_ebs_response(){
            global $woocommerce;
            if(isset($_REQUEST['order_id']) && isset($_REQUEST['DR'])){
                $redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);

                $order_id = (int)$_REQUEST['order_id'];
                
                $this -> msg['class'] = 'error';
                $this -> msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

                if($order_id != ''){
                    try{
                        $order = new WC_Order($order_id);

                        $path = plugin_dir_path(__FILE__);
                        require($path.'Rc43.php');
                        
                        $DR = preg_replace("/\s/","+",$_GET['DR']);
                        $rc4 = new Crypt_RC4($this->secret_key);
                        $QueryString = base64_decode($DR);
                        $rc4->decrypt($QueryString);
                        $QueryString = explode('&',$QueryString);
                        
                        $response = array();
                        foreach($QueryString as $param){
                            $param = explode('=',$param);
                            $response[$param[0]] = urldecode($param[1]);
                        }

                        $responseMsg = $response['ResponseMessage'];

                        if($response['ResponseCode']==0){
                            if($response['IsFlagged'] == "NO" && $response['Amount'] == $order->order_total){
                                $notes  = $responseMsg.'. Transaction ID: '.$response['TransactionID'];
                                $status = 'Received';                                       

                                $this -> msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
                                $this -> msg['class'] = 'success';
                                if($order -> status == 'processing'){

                                }else{
                                    $order -> payment_complete();
                                    $order -> add_order_note($notes);
                                    $woocommerce -> cart -> empty_cart();

                                }
                            }
                            else {
                                $status = 'On-hold';
                                $this -> msg['message'] = $responseMsg.". The payment has been kept on hold until the manual verification is completed and authorized by EBS";
                                $this -> msg['class'] = 'info';
                                $order->add_order_note(__($this -> msg['message'], 'woocommerce'));
                                $order->payment_complete();  
                                
                            }
                        }
                        else{
                            $note  = $response['ResponseMessage'].'. Transaction ID: '.$response['TransactionID'];
                            $this -> msg['class'] = 'error';
                            $this -> msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                               
                            $order -> update_status('failed');
                            $order->add_order_note(__($notes, 'woocommerce'));
                          
                        }
                    
                    }catch(Exception $e){
                            // $errorOccurred = true;
                        $msg = "Error";
                    }

                }
                $redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
                //For wooCoomerce 2.0
                $redirect_url = add_query_arg( array('msg'=> urlencode($this -> msg['message']), 'type'=>$this -> msg['class']), $redirect_url );

                wp_redirect( $redirect_url );
                exit;
            }
        }
       /*
        //Removed For WooCommerce 2.0
       function showMessage($content){
            return '<div class="box '.$this -> msg['class'].'-box">'.$this -> msg['message'].'</div>'.$content;
        }*/
        /**
         * Generate CCAvenue button link
         **/
        public function generate_ebs_form($order_id){
            global $woocommerce;
            $order = new WC_Order($order_id);
            $redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);
          //For wooCoomerce 2.0
            $redirect_url = add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
            
            $order_id = $order->id;     
            $description = $order->customer_note;
            if(empty($description)){
                $description = "Order is ".$order_id;
            }
            $account_id = $this->account_id ;
            $secret_key = $this-> secret_key;
            $mode = $this-> mode;
            $amount = $order->order_total;
            $mode = ($mode == "yes") ? "TEST" : "LIVE";
            
            $hash = $secret_key."|".$account_id."|".$amount."|".$order_id."|".$redirect_url."|".$mode;
            $secure_hash = md5($hash);
            
            $ebs_args = array(
                'account_id' => $account_id,
                'secret_key' => $secret_key,
                'mode' => $mode,
                'reference_no' => $order_id,
                'amount' => $amount,
                'description' => $description,
                'name' => $order->billing_first_name." ".$order->billing_last_name,
                'address' => $order->billing_address_1." ".$order->billing_address_2,
                'city' => $order->billing_city,
                'state' => $order->billing_state,
                'postal_code' => $order->billing_postcode,
                'country' => $order->billing_country,
                'email' => $order->billing_email,
                'phone' => $order->billing_phone,
                'ship_name' => $order->shipping_first_name.' '.$order->shipping_last_name,
                'ship_address' => $order->shipping_address_1.' '.$order->shipping_address_2,
                'ship_city' => $order->shipping_city,
                'ship_state' => $order->shipping_state,
                'ship_country' => $order->shipping_country,
                'ship_postal_code' => $order->shipping_postcode,
                'return_url' => $return_url,
                'secure_hash' => $secure_hash);

$ebs_args_array = array();
foreach($ebs_args as $key => $value){
    $ebs_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
}
return '<form action="'.$this -> liveurl.'" method="post" id="ebs_payment_form">
' . implode('', $ebs_args_array) . '
<input type="submit" class="button-alt" id="submit_ebs_payment_form" value="'.__('Pay via ebs', 'mrova').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'mrova').'</a>
<script type="text/javascript">
jQuery(function(){
    jQuery("body").block(
    {
        message: "<img src=\"'.$woocommerce->plugin_url().'/assets/images/ajax-loader.gif\" alt=\"Redirecting…\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to ebs to make payment.', 'mrova').'",
        overlayCSS:
        {
            background: "#fff",
            opacity: 0.6
        },
        css: {
            padding:        20,
            textAlign:      "center",
            color:          "#555",
            border:         "3px solid #aaa",
            backgroundColor:"#fff",
            cursor:         "wait",
            lineHeight:"32px"
        }
    });
jQuery("#submit_ebs_payment_form").click();

});
</script>
</form>';


}


        // get all pages
function get_pages($title = false, $indent = true) {
    $wp_pages = get_pages('sort_column=menu_order');
    $page_list = array();
    if ($title) $page_list[] = $title;
    foreach ($wp_pages as $page) {
        $prefix = '';
                // show indented child pages?
        if ($indent) {
            $has_parent = $page->post_parent;
            while($has_parent) {
                $prefix .=  ' - ';
                $next_page = get_page($has_parent);
                $has_parent = $next_page->post_parent;
            }
        }
                // add to page list array array
        $page_list[$page->ID] = $prefix . $page->post_title;
    }
    return $page_list;
}

}

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_mrova_ebs_gateway($methods) {
        $methods[] = 'WC_Mrova_EBS';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_mrova_ebs_gateway' );
}

?>
