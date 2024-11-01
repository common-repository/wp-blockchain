/*
Plugin Name: WP Blockchain
Plugin URI: https://wp-blockchain.com
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


jQuery(document).ready(function(){
	jQuery('.wpbc-setting-type select').change(function(){
		var tr = jQuery(this).closest('tr');
		var enabled = tr.find('.wpbc-setting-type-mode select').val() !== '';
		tr[enabled ? 'addClass' : 'removeClass']('wpbc-setting-type-state-enabled')
			[!enabled ? 'addClass' : 'removeClass']('wpbc-setting-type-state-disabled');
	});

	wpbc_add_cb(function(){
		if (jQuery('body').hasClass('wpbc-history-unfolded')){
			var h = jQuery('.wpbc-history-hidden');
			h.find('tr.wpbc-history-tr').insertBefore(h.prev('tr').hide());
		}
	});

	jQuery('.wpbc-settings-reset-button').attr('checked', false).change(function(e){
		jQuery(this).blur();
		if (this.checked && !confirm(jQuery(this).data('wpbc-confirm'))){
			this.checked = false;
			return false;
		}
		jQuery(this).parent().css({color: this.checked ? 'red' : 'inherit'});
	});

	jQuery('body').on('click', '.wpbc-stamps-table-actions a', function(){
		var t = jQuery(this);
		var action = t.data('wpbc-action');
		var stamp_id = t.closest('tr').data('wpbc-stamp_id');
		if (confirm(t.data('wpbc-confirm')))
			wpbc_ajax_run({wpbc: action, stamp_id: stamp_id}, function(data){
				if (data.success){
					if (action == 'delete_stamp'){
						alert(data.msg);
						t.closest('tr').fadeOut('slow', function(){
							jQuery(this).remove();
						});
					}
				}
			});
		return false;
	});

	// credential fields
	jQuery('.wpbc-settings-bc-credentials select').change(function(){
		var fieldset = jQuery(this).closest('.wpbc-settings-bc-credentials');
		var parg = jQuery(this).find('option:selected').data('wpbc-provider-arg');
		var cfields = fieldset.find('.wpbc-settings-field-credential');
		var visible = jQuery();
		if (parg && parg.fields)
			for (var i in parg.fields){
				var cf = cfields.filter('.wpbc-settings-field-credential-'+i);
				cf.find('label').html(parg.fields[i]);
				visible = visible.add(cf);
			}
		visible.removeClass('wpbc-settings-field-hidden');
		cfields.not(visible).addClass('wpbc-settings-field-hidden');
		fieldset.find('.wpbc-settings-tip-wrap')[parg && parg.info ? 'removeClass' : 'addClass']('wpbc-settings-field-hidden').find('.wpbc-settings-tip-inner').html(parg && parg.info ? parg.info : '');
	});

	jQuery('.wpbc-colorpicker').wpColorPicker();

	jQuery('body').on('click', '.wpbc-notice .notice-dismiss', function(){
		var notice_id = jQuery(this).closest('.wpbc-notice').data('wpbc-notice-id');
		if (notice_id)
			wpbc_ajax_run({
				wpbc: 'dismiss_ad',
				notice_id: notice_id
			});
	});
});
