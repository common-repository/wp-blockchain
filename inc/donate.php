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

add_action('wpbc_donate', 'wpbc_donate');
add_shortcode('wpbc_donate', 'wpbc_donate');
function wpbc_donate($atts = array(), $content = ''){
	$addr = trim(get_option('wpbc_donate_btc', ''));
	if ($addr == '')
		return '';
	if (empty($content))
		$content = __('Donate some Satoshis!', 'wpbc');
	if (empty($atts))
		$atts = array();
	$atts += array(
		'icon' => 'btc',
		'link' => false,
		'target' => false
	);
	ob_start();
	if (!empty($atts['link']))
		echo '<a rel="nofollow" href="'.$atts['link'].'"'.($atts['target'] ? ' target="'.$atts['target'].'"' : '').' class="wpbc-donate-button">';
	else
		echo '<span class="wpbc-donate-button">';
	?>
	<i class="fa fa-<?= $atts['icon'] ?>"></i> <span class="wpbc-donate-content"><?= wp_strip_all_tags($content) ?></span><span class="wpbc-donate-address"><?= $addr ?></span>
	<?php
	if ($atts['link'])
		echo '</a>';
	else
		echo '</span>';
	return ob_get_clean();
}

add_action('wp_head', 'wpbc_head');
function wpbc_head(){
	$addr = trim(get_option('wpbc_donate_btc', ''));
	if ($addr != ''){
		echo '<meta name="bitcoin" content="'.esc_attr($addr).'" />'.PHP_EOL;
		echo '<link rel="bitcoin" href="bitcoin:'.esc_attr($addr).'" />'.PHP_EOL;
		echo '<meta name="microtip" content="'.esc_attr($addr).'" data-currency="btc">'.PHP_EOL;
	}
}

