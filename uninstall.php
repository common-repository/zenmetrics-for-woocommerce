<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

delete_option('zen_verified');
delete_option('zen_api_token');
delete_option('zen_api_secret');
delete_transient('zen_queue');

?>
