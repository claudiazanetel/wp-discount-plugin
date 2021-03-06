<?php

class VoucherPostType {

    public static $PLUGIN_NAME = 'wp_fidel_it';
    public static $TYPE_NAME = 'voucher';
    public static $VALID_TO = 'valid_to';
    public static $IS_REDEEMED = 'is_redeemed';

    public static function init()
    {
        // register voucher type
        add_action('init', [__CLASS__, 'register']);
        // register voucher's columns
        add_filter('manage_'.static::$TYPE_NAME.'_posts_columns' , [__CLASS__, 'columns']);
        // registers admin voucher columns renderer
        add_action('manage_'.static::$TYPE_NAME.'_posts_custom_column', [__CLASS__, 'renderVoucherColumns'], 10, 2);
        // register box to edit voucher's fields
        add_action('add_meta_boxes', [__CLASS__, 'registerEditVoucherView']);
        // register save voucher function
        add_action('save_post_'.static::$TYPE_NAME, [__CLASS__, 'saveVoucher']);
        // ensure the post is private
        add_filter('wp_insert_post_data', [__CLASS__, 'makePrivate']);
        // register voucher clean up
        add_action('before_delete_post', [__CLASS__, 'deleteVoucher']);
        // redeem voucher
        add_action('the_content', [__CLASS__, 'redeem']);
    }

    public static function register()
    {
        $labels = [
            'name'                  => __('Vouchers', static::$PLUGIN_NAME),
            'singular_name'         => __('Voucher', static::$PLUGIN_NAME),
            'add_new'               => __('Add New', static::$PLUGIN_NAME),
            'add_new_item'          => __('Add New Voucher', static::$PLUGIN_NAME),
            'edit_item'             => __('Edit Voucher', static::$PLUGIN_NAME),
            'new_item'              => __('New Voucher', static::$PLUGIN_NAME ),
            'view_item'             => __('View Voucher', static::$PLUGIN_NAME ),
            'search_items'          => __('Search Voucher', static::$PLUGIN_NAME ),
            'not_found'             => __('No Voucher found', static::$PLUGIN_NAME ),
            'not_found_in_trash'    => __('No Voucher found in Trash', static::$PLUGIN_NAME ),
            'parent_item_colon'     => __('Parent Voucher:', static::$PLUGIN_NAME ),
            'menu_name'             => __('Vouchers', static::$PLUGIN_NAME ),
        ];

        $args = [
            'labels'                => $labels,
            'hierarchical'          => false,
            'description'           => 'Vouchers',
            'supports'              => ['title','editor','thumbnail'],
            'taxonomies'            => [],
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'menu_icon'             => 'dashicons-chart-bar',
            'show_in_nav_menus'     => true,
            'publicly_queryable'    => true,
            'exclude_from_search'   => false,
            'has_archive'           => true,
            'query_var'             => true,
            'can_export'            => true,
            'rewrite'               => ['slug' => 'vouchers'],
            'capability_type'       => 'post',
        ];

        register_post_type(static::$TYPE_NAME, $args);
    }

    public static function columns($columns)
    {
        return array_merge($columns, [
            static::$VALID_TO       => __('Valid To', static::$PLUGIN_NAME),
            static::$IS_REDEEMED    => __('Redeemed?', static::$PLUGIN_NAME)
        ]);
    }

    public static function renderVoucherColumns($column, $post_id) {
        $value = self::readField($column, $post_id);

        if($column == self::$IS_REDEEMED) {
            echo(__($value?'Yes':'No', static::$PLUGIN_NAME));
        } else {
            echo $value;
        }
    }

    public static function registerEditVoucherView() {
        add_meta_box(
            'voucher_box',
            __('Voucher data'),
            [__CLASS__, 'renderEditVoucherView'],
            static::$TYPE_NAME
        );
    }

    /**
     * This function renders the voucher's data on the admin page
     */
    public static function renderEditVoucherView()
    {
        global $post;

        $valid_to = self::readField(static::$VALID_TO, $post->ID);
        echo("<label for='".static::$VALID_TO."'>Valid To:</label><input type='text' name='".static::$VALID_TO."' class='".static::$VALID_TO."' value='{$valid_to}' />");
        
        $is_redeemed = self::readField(static::$IS_REDEEMED, $post->ID) ? 'checked' : '';
        echo("<label for='".static::$IS_REDEEMED."'>Is redeemed?</label><input type='checkbox' name='".static::$IS_REDEEMED."' class='".static::$IS_REDEEMED."' {$is_redeemed} />");
    }

    /**
     * This function saves the voucher's data
     */
    public static function saveVoucher($post_id) { 
        $post = get_post($post_id);
        if(in_array($post->post_status, ["auto-draft", "trash"])) {
            return;
        }

        if(isset($_POST[static::$VALID_TO]) && !empty($_POST[static::$VALID_TO])) {
            update_post_meta($post_id, static::$VALID_TO, sanitize_text_field($_POST[static::$VALID_TO]));
        } else {
            update_post_meta($post_id, static::$VALID_TO, null);
        }

        if(isset($_POST[static::$IS_REDEEMED])) {
            update_post_meta($post_id, static::$IS_REDEEMED, 1);
        } else {
            update_post_meta($post_id, static::$IS_REDEEMED, 0);
            self::generateQrCode(get_post_permalink($post_id), $post_id);
        }
    }

    private static function generateQrCode($permalink, $post_id) {
        $post = get_post($post_id);

        // delete old qrCode
        self::deleteQrCode($post->ID);
        
        $downloaded = download_url("https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($permalink));
        
        if (is_wp_error($downloaded)) {
            wp_die($downloaded);
        }

        $file = [
            'name'     => $post->ID . "_" . $post->post_name . ".png",
            'type'     => 'image/png',
            'tmp_name' => $downloaded,
            'error'    => 0,
            'size'     => filesize($downloaded),
        ];
        $id = media_handle_sideload($file, $post->ID, "QRCode for " . $post->post_name);
        if (is_wp_error($id)) {
            @unlink($downloaded);
            wp_die($id);
        }
        set_post_thumbnail($post->ID, $id);
    }

    public static function makePrivate($data) {
        if($data['post_type'] == static::$TYPE_NAME && in_array($data['post_status'], ["future", "publish"])) {
            $data['post_status'] = 'private';
        }
        return $data;
    }

    /**
     * This function deletes the voucher's QR code
     */
    public static function deleteVoucher($post_id) {
        $post = get_post($post_id);
        if ($post->post_type != static::$TYPE_NAME) {
            return;
        }

        self::deleteQrCode($post->ID);
    }

    private static function readField($field, $post_id) {
        if($field == static::$VALID_TO) {
            return get_post_custom_values(static::$VALID_TO, $post_id) != null
                ? array_shift(get_post_custom_values(static::$VALID_TO, $post_id))
                : "";
        }
        if($field == static::$IS_REDEEMED) {
            $raw_is_redeemed = get_post_custom_values(static::$IS_REDEEMED, $post->ID) != null
                ? array_shift(get_post_custom_values(static::$IS_REDEEMED, $post->ID))
                : FALSE;
            return !empty($raw_is_redeemed) && $raw_is_redeemed ? TRUE : FALSE;
        }
    }

    private static function deleteQrCode($post_id) {
        foreach(get_attached_media('image', $post_id) as $attachment){
            wp_delete_attachment($attachment->ID, true);
        } 
    }

    public static function redeem($content) {
        global $post;

        if(!(get_post_type() == static::$TYPE_NAME && is_singular())) {
            return $content;
        }

        if(!current_user_can('administrator')) {
            wp_die(__("You don't have enough privileges to see this page", static::$PLUGIN_NAME));
        }

        $validTo = self::readField(static::$VALID_TO, $post->ID);
        $isRedeemed = self::readField(static::$IS_REDEEMED, $post->ID);
        
        
        if(!$isRedeemed && (empty($validTo) || strtotime($validTo) >= time())) {
            $status = "<p style='color:green; font-weight:bold;'>".__("This voucher is valid", static::$PLUGIN_NAME)."</p>";
            update_post_meta($post->ID, static::$IS_REDEEMED, 1);
        } else {
            $status = "<p style='color:red; font-weight:bold;'>".__("This voucher is no longer valid", static::$PLUGIN_NAME)."</p>";
            if($isRedeemed) {
                $status .= "<p style='color:red; font-weight:bold;'><code>".__("Already redeemed", static::$PLUGIN_NAME)."</code></p>";
            } else {
                $status .= "<p style='color:red; font-weight:bold;'><code>".__("Expired, was valid until ", static::$PLUGIN_NAME) . $validTo."</code></p>";
            }
        }

        return $status.$post->post_content;
    }
}