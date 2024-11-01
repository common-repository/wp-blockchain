<?php
/*
Plugin Name: WP Blockchain
Plugin URI: https://wp-blockchain.com
Description: Timestamp all your posts, pages, users and custom post types in the Bitcoin blockchain. By Good Rebels Inc.
Version: 3.2.1
Author: Good Rebels Inc.
Author URI: https://www.goodrebels.com/about/
Text Domain: wpbc
Domain Path: /languages
License: GPLv2 or later

Copyright (c) 2018, Good Rebels Inc.

WP Blockchain is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <https://www.gnu.org/licenses/gpl-2.0.html>.

Please feel free to contact us at wp-blockchain@goodrebels.com.
*/

if (!defined('ABSPATH'))
	exit();

if (!defined('WPBC_PATH')){
	define('WPBC_PATH', untrailingslashit(__DIR__));
	define('WPBC_PLUGIN', __FILE__);
	require WPBC_PATH.'/inc/preinit.php';
}

