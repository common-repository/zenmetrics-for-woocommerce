<?php
/**
 * Plugin Name: Zenmetrics for WooCommerce
 * Description: Get valuable insights and discover marketing strategies for your store.
 * Version: 1.0.4
 * Author: zenmetrics
 * Author URI: http://www.zenmetrics.io/
 */


defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

if ( ! class_exists( 'Zenmetrics_WooCommerce' ) ) :

class Zenmetrics_WooCommerce {

    #####################
    ### CONSTRUCT API ###
    #####################
    public static $events_queue = array();
    public static $api_url = "https://app.zenmetrics.io/";
    public static $cookie_array = array();

    public static function init() {

        add_action( 'init', __CLASS__ .'::zen_set_cookies');

        ### PLUGIN SETTINGS
        add_filter( 'woocommerce_settings_tabs_array', __CLASS__ . '::zen_add_to_woo_settings', 50 );
        add_action( 'woocommerce_settings_tabs_zenmetrics', __CLASS__ . '::zen_settings_tab' );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), __CLASS__ . '::zen_add_settings_link' );
        add_action( 'wp_ajax_zen_sync_product_chunk', __CLASS__ . '::zen_sync_product_chunk');
        add_action( 'wp_ajax_zen_sync_order_chunk', __CLASS__ . '::zen_sync_order_chunk');

        ### UPDATE AND VALIDATE KEYS
        #add_action( 'update_option_woocommerce_currency', __CLASS__ . '::update_general_settings', 10, 1 );
        add_action( 'woocommerce_update_options_zenmetrics', __CLASS__ . '::update_settings' );

        ### GENERAL TRACKING
        add_filter( 'wp_head' ,__CLASS__ . '::zen_load_tracker');
        add_filter( 'wp_head' ,__CLASS__ . '::action_woocommerce_tracking');

        ### CART EVENTS
        add_action( 'woocommerce_add_to_cart', __CLASS__ . '::action_woocommerce_add_to_cart', 10, 3 );
        add_action( 'woocommerce_remove_cart_item', __CLASS__ . '::action_woocommerce_remove_from_cart', 10, 3 );
        add_action( 'woocommerce_cart_item_restored', __CLASS__ . '::action_woocommerce_undo_remove_from_cart', 10, 2 );
        add_action( 'woocommerce_after_cart_item_quantity_update', __CLASS__ . '::action_woocommerce_update_cart', 10, 3 );

        ### PRODUCTS
        add_action( 'wp_insert_post',  __CLASS__ . '::action_woocommerce_add_product', 10, 3 );
        add_action( 'comment_post', __CLASS__ . '::action_woocommerce_add_product_review', 10, 2 );
        add_action( 'before_delete_post', __CLASS__ . '::action_woocommerce_delete_product' );
        add_action( 'wp_trash_post',  __CLASS__ . '::action_woocommerce_delete_product'  );

        ### CATEGORIES
        add_action( 'edited_term',  __CLASS__ . '::action_woocommerce_update_category', 10, 3 );
        add_action( 'delete_term',  __CLASS__ . '::action_woocommerce_delete_category', 10, 3 );

        ### ORDERS
        add_action( 'woocommerce_checkout_order_processed', __CLASS__ . '::action_woocommerce_add_new_order', 1, 1);
        add_action( 'woocommerce_order_status_changed', __CLASS__ . '::action_woocommerce_order_status_changed', 10, 3);

    }

    #######################
    ### PLUGIN SETTINGS ###
    #######################

    public static function zen_add_to_woo_settings( $settings_tabs ) {
        $settings_tabs['zenmetrics'] = "Zenmetrics";
        return $settings_tabs;
    }

    public static function zen_settings_tab() {

        if(get_option('zen_verified') == "verified")
        {
            $items_per_chunk = 2;

            $products = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => array('publish', 'trash'),
                'posts_per_page' => -1,
            ));

            $orders = get_posts(array(
                'post_type'      => 'shop_order',
                'post_status'    => 'any',
                'posts_per_page' => -1,
            ));

            $product_chunk_number = ceil(count($products)/$items_per_chunk);
            $order_chunk_number   = ceil(count($orders)/$items_per_chunk);
            $chunk_number = $product_chunk_number + $order_chunk_number;

            wp_enqueue_script( 'jquery' );
        }
        include_once( 'views/zenmetrics-settings.php' );
    }

    public static function zen_add_settings_link( $links ) {
        $links[] = '<a href="' . esc_url( admin_url('admin.php?page=wc-settings&tab=zenmetrics') ) . '" title="' . esc_attr( __( 'Settings', 'woocommerce' ) ) . '">Settings</a>';
        return $links;
    }

    public static function get_settings() {

        $settings = array(
            'section_title' => array(
                'type'      => 'title',
                'id'        => 'zenmetrics_section_title'
            ),

            'api_token' => array(
                'name'      => 'API token',
                'type'      => 'text',
                'desc_tip'  => false,
                'id'        => 'zen_api_token',
                'default'   => ''
            ),

            'api_token_secret' => array(
                'name'      => 'API token secret',
                'type'      => 'text',
                'desc_tip'  => false,
                'id'        => 'zen_api_secret',
                'default'   => ''
            ),

            'section_end' => array(
                'type'      => 'sectionend',
                'id'        => 'zenmetrics_section_end'
            )
        );

        return apply_filters( 'zenmetrics_for_woocommerce_settings', $settings );

    }

    ################################
    ### UPDATE AND VALIDATE KEYS ###
    ################################

    public static function update_general_settings($array) {
        $api_data = array(
            "settings" => array(
                "currency"  => get_option('woocommerce_currency'),
            )
        );
        $status = self::zen_send_api_call($api_data);
    }

    public static function update_settings() {

        $response = self::zen_validate_keys($_POST['zen_api_token'],$_POST['zen_api_secret']);
        woocommerce_update_options( self::get_settings() );

        if($response == TRUE) {

            update_option("zen_verified", "verified");

        } else {

            update_option("zen_verified", "not verified");
            echo '<div class="notice notice-error"><p><b>Your tokens could not be verified.</b></p></div>';
        }
    }

    public static function zen_validate_keys($api_key,$api_secret) {

        $response = wp_remote_post( self::$api_url.'verify-keys/'.$api_key.'/'.$api_secret.'/', array(
            'headers'   =>  array('content-type' => 'application/json'),
            'method'    => 'GET',
            'timeout'   => 90,
            'sslverify' => false,
        ));

        $body = json_decode($response['body']);
        if(isset($body->verified)) {
            return $body->verified;
        } else {
            return false;
        }
    }

    #######################
    ### HISTORICAL DATA ###
    #######################

    public static function zen_sync_product_chunk(){

        if(isset($_REQUEST['this_chunk']) && isset($_REQUEST['items_per_chunk']))
        {
            $limit  = (int)$_REQUEST['items_per_chunk'];
            $chunk  = (int)$_REQUEST['this_chunk'];
            $offset = $chunk * $limit;

            $products = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => array('publish', 'trash'),
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'order'          => 'ASC',
                'orderby'        => 'ID',
            ));

            $product_list = array();$i=1;
            foreach ( $products as $product )
            {
                $wooc = wc_get_product($product->ID);
                $product_list[$i] = self::zen_get_product_information($wooc);
                $i++;
            }
            self::zen_send_api_call(
                array('sync_products' => $product_list)
            );
        }
        return true;
    }

    public static function zen_sync_order_chunk(){

        if(isset($_REQUEST['this_chunk']) && isset($_REQUEST['items_per_chunk']))
        {
            $limit  = (int)$_REQUEST['items_per_chunk'];
            $chunk  = (int)$_REQUEST['this_chunk'];
            $offset = $chunk * $limit;

            $orders = get_posts(array(
                'post_type'      => 'shop_order',
                'post_status'    => 'any',
                'posts_per_page' => $limit,
                'offset'         => $offset,
                'order'          => 'ASC',
                'orderby'        => 'ID',
            ));

            $order_list = array();$i=1;
            foreach ( $orders as $order )
            {
                $order_list[$i] = self::zen_get_order_information($order->ID);
                $i++;
            }
            self::zen_send_api_call(
                array('sync_orders' => $order_list)
            );
        }
        return true;
    }

    ########################
    ### GENERAL TRACKING ###
    ########################

    public static function zen_load_tracker() {
        if (!is_admin()){
            include_once(plugin_dir_path(__FILE__) . 'js/js.php');
        }
    }

    public static function generate_zen_id(){
        $x = (int) round(microtime(true)*1000);
        $y = substr(dechex(floor((1 + rand()) * 65536)),0,4);
        $z = substr(dechex(floor((1 + rand()) * 65536)),0,4);
        $q = substr(dechex(floor((1 + rand()) * 65536)),0,4);
        return 'w'.$x.$y.$z.$q;
    }

    public static function get_unique_visitor_id(){
        return (isset($_COOKIE['__zenvid']) ? $_COOKIE['__zenvid'] : self::generate_zen_id());
    }

    public static function get_unique_session_id(){
        if(isset($_COOKIE['__zensid']))
        {
            if(isset($_COOKIE['__zenexp']) && $_COOKIE['__zenexp'] < time())
            {
                # New session
                $unique_session_id = self::generate_zen_id();
            }
            else {
                # Renew old session
                $unique_session_id = $_COOKIE['__zensid'];
            }
        }
        else{
            $unique_session_id = self::generate_zen_id();
        }
        return $unique_session_id;
    }

    public static function zen_set_cookies(){
        $unique_visitor_id = self::get_unique_visitor_id();
        $unique_session_id = self::get_unique_session_id();
        setcookie('__zenvid', $unique_visitor_id, time() + (86400 * 365), "/", COOKIE_DOMAIN);
        setcookie('__zensid', $unique_session_id, time() + 1800, "/", COOKIE_DOMAIN);
        setcookie('__zenexp', time() + 1800, time() + 5400, "/", COOKIE_DOMAIN);
        self::$cookie_array = array($unique_visitor_id, $unique_session_id);
    }

    public static function zen_get_visitor_information() {

        if(!isset($_COOKIE['__zenvid']) || !isset($_COOKIE['__zensid']))
        {
            $ids = self::$cookie_array;
        }
        else{
            $ids = array($_COOKIE['__zenvid'], $_COOKIE['__zensid']);
        }

        $zen_session = array(
            "session" => array(
                "visitor_id" => $ids[0],
                "session_id" => $ids[1],
            )
        );

        $user_id = get_current_user_id();
        if ($user_id != 0)
        {
            $zen_session["session"]["user"] = $user_id;
        }

        return $zen_session;
    }

    public static function action_woocommerce_tracking(){

        $single_event = false;

        if(class_exists('WooCommerce'))
        {
            if(!$single_event && is_shop())
            {
                self::zen_add_to_queue('view_storefront');
                $single_event = TRUE;
            }

            if(!$single_event && is_product())
            {
                self::zen_add_to_queue('view_product', get_queried_object_id());
                $single_event = TRUE;
            }

            if(!$single_event && is_product_category())
            {
                self::zen_add_to_queue('view_category', get_queried_object_id());
                $single_event = TRUE;
            }

            if(!$single_event && is_cart())
            {
                self::zen_add_to_queue('view_cart');
                $single_event = TRUE;
            }

            if(!$single_event && is_order_received_page())
            {
                self::zen_add_to_queue('checkout_complete');
                $single_event = TRUE;
            }

            elseif(!$single_event && is_checkout_pay_page())
            {
                self::zen_add_to_queue('checkout_payment');
                $single_event = TRUE;
            }

            elseif(!$single_event && is_checkout())
            {
                self::zen_add_to_queue('checkout_start');
                $single_event = TRUE;
            }
        }

        if(!$single_event && is_front_page())
        {
            self::zen_add_to_queue('view_homepage');
            $single_event = TRUE;
        }

        elseif(!$single_event && is_home())
        {
            self::zen_add_to_queue('view_homepage');
            $single_event = TRUE;
        }

        elseif(!$single_event && is_page())
        {
            self::zen_add_to_queue('view_page');
            $single_event = TRUE;
        }

        if(!$single_event && is_single())
        {
            self::zen_add_to_queue('view_article');
            $single_event = TRUE;
        }

        if(count(self::$events_queue) > 0)
        {
            $api_data = array(
                'event' => self::$events_queue[0]
            );

            $clientData = self::zen_get_visitor_information();
            $status = self::zen_send_api_call(array_merge($clientData,$api_data));
        }
    }

    public static function zen_add_to_queue($event_type, $id='')
    {
        $type_array = array(
            "event_type" => $event_type,
            "start_time" => gmdate("Y-m-d H:i:s"),
        );

        if($id != '')
        {
            $type_array['id'] = $id;
        }
        array_push(self::$events_queue, $type_array);
    }

    ###################
    ### CART EVENTS ###
    ###################

    public static function action_woocommerce_add_to_cart($array, $item_id, $qty) {

        $api_data = array(
            'event' => array(
                'event_type' => 'add_to_cart',
                "start_time" => gmdate("Y-m-d H:i:s"),
                'product_id' => $item_id,
                'quantity'	 => $qty
            )
        );
        $clientData = self::zen_get_visitor_information();
        $status = self::zen_send_api_call(array_merge($clientData,$api_data));
    }

    public static function action_woocommerce_remove_from_cart($cart_item_key, $cart) {
        $item_id = $cart->cart_contents[ $cart_item_key ]['product_id'];
        $qty     = $cart->cart_contents[ $cart_item_key ]['quantity'];
        $api_data = array(
            'event' => array(
                'event_type'    => 'remove_from_cart',
                'start_time'    => gmdate("Y-m-d H:i:s"),
                'product_id'    => $item_id,
                'quantity'      => $qty
            )
        );
        $clientData = self::zen_get_visitor_information();
        $status = self::zen_send_api_call(array_merge($clientData,$api_data));
    }

    public static function action_woocommerce_undo_remove_from_cart($cart_item_key, $cart) {
        $item_id = $cart->cart_contents[ $cart_item_key ]['product_id'];
        $qty     = $cart->cart_contents[ $cart_item_key ]['quantity'];
        $api_data = array(
            'event' => array(
                'event_type'    => 'undo_remove_from_cart',
                'start_time'    => gmdate("Y-m-d H:i:s"),
                'product_id'    => $item_id,
                'quantity'      => $qty
            )
        );
        $clientData = self::zen_get_visitor_information();
        $status = self::zen_send_api_call(array_merge($clientData,$api_data));
    }

    public static function action_woocommerce_update_cart($cart_item_key, $quantity, $old_quantity) {
        global $woocommerce;
        $item_id = $woocommerce->cart->cart_contents[ $cart_item_key ]['product_id'];
        $api_data = array(
            'event' => array(
                'event_type'    => 'update_cart',
                "start_time"    => gmdate("Y-m-d H:i:s"),
                'product_id'    => $item_id,
                'quantity'      => $quantity
            )
        );
        $clientData = self::zen_get_visitor_information();
        $status = self::zen_send_api_call(array_merge($clientData,$api_data));
    }

    ################
    ### PRODUCTS ###
    ################

    public static function action_woocommerce_delete_product($post_id) {
        $post_type = get_post_type($post_id);
        if($post_type == 'product') {
            $api_data = array(
                'product' => array(
                    'event_type' => 'remove_product',
                    'product_id' => $post_id,
                )
            );
            self::zen_send_api_call($api_data);
        }
    }

    public static function action_woocommerce_add_product_review($comment_ID, $comment_approved) {
        if($comment_approved)
        {
            $comment = get_comment($comment_ID);
            $post_id = $comment->comment_post_ID;
            $product = wc_get_product( $post_id );

            $info_array = array('event_type' => 'update_product');
            $product_array = self::zen_get_product_information($product);

            $q = array_merge($info_array, $product_array);
            $api_data = array('product' => $q);
            self::zen_send_api_call($api_data);
        }
    }

    public static function action_woocommerce_add_product($post_id, $post, $update) {
        if ($post->post_type == 'product' && $post->post_status == 'publish')
        {
            $event_type = ($update) ? 'update_product' : 'add_product';
            $info_array = array(
                'event_type' => $event_type,
            );

            $product = wc_get_product( $post->ID );
            $product_array = self::zen_get_product_information($product);
            $q = array_merge($info_array, $product_array);
            $api_data = array('product' => $q);
            self::zen_send_api_call($api_data);
        }
    }

    public static function zen_get_product_information($product){

        if($product->get_status() == 'publish'){
            $wooc_status = 'Enabled';
        }else{
            $wooc_status = 'Disabled';
        }
        $product_hash = array(
            'product_id'    => $product->get_id(),
            'name'          => $product->get_name(),
            'short_description' => $product->get_short_description(),
            'status'        => $wooc_status,
            'permalink'     => $product->get_permalink(),
            'type'          => ucwords($product->get_type()),
            'price'         => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price'    => $product->get_sale_price(),
            'on_sale'       => $product->is_on_sale(),
            'tax_status'    => $product->get_tax_status(),
            'in_stock'      => $product->is_in_stock(),
            'review_count'  => $product->get_review_count(),
            'average_rating'=> $product->get_average_rating(),
            'rating_count'  => $product->get_rating_count(),
            'related_ids'   => implode(",",wc_get_related_products($product->get_id())),
            'upsell_ids'    => implode(",",$product->get_upsell_ids()),
            'crosssell_ids' => implode(",",$product->get_cross_sell_ids())
        );

        // fetch image URL
        $image_id = $product->get_image_id();
        $image = wp_get_attachment_image_src($image_id, 'full');
        if($image) $product_hash['image_url'] = $image[0];

        // fetch the categories
        $categories_list = self::zen_get_category_information($product->get_id());
        if(!empty($categories_list)) $product_hash['categories'] = $categories_list;

        // fetch variations
        $variations_list = self::zen_get_variation_information($product);
        if(!empty($variations_list)) $product_hash['variations'] = $variations_list;

        // fetch sales information
        $sales_period = self::zen_get_sale_information($product);
        if(!empty($sales_period)) $product_hash['sales_period'] = $sales_period;

        // return
        return $product_hash;
    }

    public static function zen_get_variation_information($product){
        $variations_list = array();
        $variations = $product->get_children();
        if(!empty($variations))
        {
            $i = 1;
            foreach($variations as $variation)
            {
                $variation = new WC_Product_Variation($variation);
                $items = array(
                    'variation_id'  => $variation->get_id(),
                    'price'         => $variation->get_price(),
                    'regular_price' => $variation->get_regular_price(),
                    'sale_price'    => $variation->get_sale_price(),
                    'name'          => '',
                );

                // Get variation name
                $attributes = $variation->get_variation_attributes();
                foreach($attributes as $key => $value){
                    $items['name'] = $items['name'] . $value . " ";
                }
                $items['name'] = trim(ucwords(str_replace("-", " ",$items['name'])));

                // fetch sales information
                $sales_period = self::zen_get_sale_information($variation);
                if(!empty($sales_period)) $items['sales_period'] = $sales_period;

                // Add variation
                $variations_list[$i] = $items;
                $i++;
            }
        }

        return $variations_list;
    }

    public static function zen_get_sale_information($product){

        $from_date = $product->get_date_on_sale_from();
        $from_date = ($from_date ? get_gmt_from_date($from_date) : False);
        $to_date   = $product->get_date_on_sale_to();
        $to_date   = ($to_date ? get_gmt_from_date($to_date) : False);

        if ($from_date && $to_date)
        {
            $sales_information = array(
                'start_date'  => $from_date,
                'end_date'    => $to_date
            );

            return $sales_information;
        }
    }


    ##################
    ### CATEGORIES ###
    ##################

    public static function zen_get_category_information($product_id){
        $categories_list = array();
        $categories = wp_get_post_terms($product_id, 'product_cat');
        if(!empty($categories))
        {
            $i = 1;
            foreach($categories as $cat)
            {
                $items = array(
                    'category_id' => $cat->term_id,
                    'name' => $cat->name
                );
                if ($cat->parent > 0)
                {
                    $items['parent'] = $cat->parent;
                }

                $categories_list[$i] = $items;
                $i++;
            }
        }

        return $categories_list;
    }

    public static function action_woocommerce_update_category($term_id, $tt_id, $taxonomy) {
        if($taxonomy == 'product_cat') {
            $term = get_term($term_id);
            $api_data = array(
                'category' => array(
                    'event_type'    => 'update_category',
                    'category_id'   => $term_id,
                    'name'          => $term->name,
                )
            );

            if ($term->parent > 0)
            {
                $api_data['category']['parent'] = $term->parent;
            }

            self::zen_send_api_call($api_data);
        }
    }

    public static function action_woocommerce_delete_category($term_id, $tt_id, $taxonomy) {
        if($taxonomy == 'product_cat') {
            $api_data = array(
                'category' => array(
                    'event_type' 	=> 'delete_category',
                    'category_id' 	=> $term_id,
                )
            );

            self::zen_send_api_call($api_data);
        }
    }

    ##############
    ### ORDERS ###
    ##############

    public static function action_woocommerce_add_new_order($order_id){

        $order_data = self::zen_get_order_information($order_id);
        $order_data['event_type'] = 'add_order';

        // Check if user is in administration panel
        if (!is_admin()) {
            $clientData = self::zen_get_visitor_information();
        }
        else {
            $clientData = array();
        }

        // Prepare order
        $api_data   = array('order' => array_merge($clientData,$order_data));
        $status     = self::zen_send_api_call($api_data);
    }

    public static function zen_get_order_information($order_id){

        $order      = new WC_Order($order_id);
        $order_data = self::zen_get_order_meta_information($order);
        $order_item = $order->get_items();
        $lineitems  = array();$i=1;

        foreach($order_item as  $key => $product)
        {
            $product_info = array(
                'lineitem_id'       => $key,
                'product_id'        => $product['product_id'],
                'quantity'          => $product['qty'],
                'product_price'     => (float)$product['line_subtotal'],
                'product_tax'       => (float)$product['line_subtotal_tax'],
            );
            if(!empty($product['variation_id'])){
                $product_info['variation_id'] = $product['variation_id'];
            }
            $lineitems[$i] = $product_info;
            $i++;
        }

        // Add lineitems, event type, customer to order data
        $order_data['lineitems'] = $lineitems;
        $order_data['customer'] = self::zen_get_customer_information($order);

        return $order_data;
    }

    public static function zen_get_order_meta_information($order, $order_merge_params = array()){

        // prepare basic order data
        $date = get_post_time('Y-m-d H:i:s', true, $order->get_id());
        $purchase_params = array(
            'order_id'          => $order->get_id(),
            'order_number'      => $order->get_order_number(),
            'currency'          => $order->get_currency(),
            'payment_method'    => $order->get_payment_method_title(),
            'order_status'      => self::zen_get_order_status($order->get_status()),
            'order_date'        => $date,
            'subtotal'          => (float)$order->get_subtotal(),
            'subtotal_tax'      => (float)$order->get_cart_tax() + $order->get_discount_tax(),
            'shipping_total'    => (float)$order->get_shipping_total(),
            'shipping_tax'      => (float)$order->get_shipping_tax(),
            'discount_total'    => (float)$order->get_total_discount($ex_tax = true),
            'discount_tax'      => (float)$order->get_discount_tax(),
        );

        if(!empty($order_merge_params)){
            $purchase_params = array_merge($purchase_params, $order_merge_params);
        }

        $coupons_applied = $order->get_used_coupons();
        if(count($coupons_applied) > 0){
            $purchase_params['coupons'] = self::zen_get_coupon_information($coupons_applied);
        }

        return $purchase_params;
    }

    public static function zen_get_order_status($status){
        if( $status == 'on-hold' )
        {
            return 'on hold';
        }else {
            return $status;
        }
    }

    public static function zen_get_coupon_information($order_coupons){
        $coupons_applied = array();$i=1;
        foreach(  $order_coupons as $coupon_name )
        {
            $coupon_post_obj = get_page_by_title($coupon_name, OBJECT, 'shop_coupon');
            $coupon_id = $coupon_post_obj->ID;

            $coupon = new WC_Coupon($coupon_id);
            $coupon_data = array(
                'coupon_id'     => $coupon_id,
                'coupon_code'   => $coupon->get_code(),
                'coupon_type'   => $coupon->get_discount_type(),
                'amount'        => $coupon->get_amount(),
            );

            $coupons_applied[$i] = $coupon_data;
            $i++;
        }
        return $coupons_applied;
    }

    public static function zen_get_customer_information($order){

        $customer = $order->get_user();

        if($customer)
        {
            $customer_details = array(
                'customer_id'   => $customer->ID,
                'first_name'    => $customer->user_firstname,
                'last_name'     => $customer->user_lastname,
                'email'         => $customer->user_email,
                'date_created'  => $customer->user_registered,
            );
        }else
        {
            $customer_details = array(
                'guest'         => 1,
                'first_name'    => $order->get_billing_first_name(),
                'last_name'     => $order->get_billing_last_name(),
                'email'         => $order->get_billing_email(),
            );
        }

        $customer_details['phone']     = $order->get_billing_phone();
        $customer_details['city']      = $order->get_billing_city();
        $customer_details['state']     = $order->get_billing_state();
        $customer_details['postcode']  = $order->get_billing_postcode();
        $customer_details['country']   = $order->get_billing_country();
        $customer_details['address_1'] = $order->get_billing_address_1();
        $customer_details['address_2'] = $order->get_billing_address_2();

        return $customer_details;
    }

    public function action_woocommerce_order_status_changed($order_id, $old_status = false, $new_status = false){
        if($new_status)
        {
            $api_data = array(
                'order' => array(
                    'event_type'    => 'update_order',
                    'order_id'      => $order_id,
                    'order_status'  => self::zen_get_order_status($new_status),
                )
            );

            $status = self::zen_send_api_call($api_data);
        }
    }

    #################
    ### API CALLS ###
    #################

    public static function zen_send_api_call($data) {

        if(get_option('zen_verified') == "verified")
        {
            $options   = array("api_key" => get_option('zen_api_token'));
            $call_data = array_merge($options, $data);

            $prepared_call = self::prepareCall($call_data);
            $prepared_call = json_encode($prepared_call);

            $message = self::zenSendMessage($prepared_call);

            if( $message )
            {
                $queue = get_transient('zen_queue');
                if( $queue )
                {
                    delete_transient('zen_queue');
                    foreach( $queue as $item )
                    {
                        self::zenSendMessage($item);
                    }
                }
            }

            return $prepared_call;
        }
    }

    public static function prepareCall($data){

        $data = json_encode($data);
        $data = base64_encode($data);
        $sign = md5($data.get_option("zen_api_secret"));

        return array(
            "d" => $data,
            "s" => $sign
        );

    }

    public static function zenSendMessage($message){
        // Prepare headers for call
        $args = array(
            'headers' => array('Content-Type' => 'application/json' ),
            'body' => $message,
        );

        // Make call
        $response = wp_safe_remote_post( esc_url_raw( self::$api_url.'tl/' ), $args );
        $response_code = wp_remote_retrieve_response_code( $response );
        #$response_body = wp_remote_retrieve_body( $response );

        // Check call response
        if( $response_code != 200 )
        {
            $queue = get_transient('zen_queue');
            if(!$queue){$queue = array();}
            $queue[] = $message;
            set_transient('zen_queue', $queue);
            return false;
        }
        else{
            return true;
        }
    }

}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    Zenmetrics_WooCommerce::init();
}

endif;
?>
