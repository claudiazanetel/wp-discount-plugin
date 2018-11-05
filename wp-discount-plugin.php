<?php

    /*
    Plugin Name: Wordpress Vouchers Plugin
    Plugin URI: https://github.com/claudiazanetel/wp-discount-plugin
    Version: 0.0.1
    Author: Claudia Zanetel
    Description: Manage discount vouchers
    Text Domain: wp-fidel-it
    License: MIT
    */

    require_once(__DIR__ . '/VoucherPostType.php');

    VoucherPostType::init();