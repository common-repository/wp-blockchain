<?php
/*
WP Blockchain
https://wp-blockchain.com
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.
Please feel free to contact us at wp-blockchain@goodrebels.com.
*/

if (!defined('ABSPATH'))
	exit();

add_action('wpbc_before_page', function(){
	add_filter('wpseo_title', 'wpbc_document_title', 99999);
	add_filter('wpseo_robots', 'wpbc_noindex', 999);
});
