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


var wpbc_sending = {};
jQuery.fn.wpbc_val = function(arg){
	var fn = (jQuery.inArray(jQuery(this).prop("tagName"), ['BUTTON', 'SUBMIT', 'SELECT', 'INPUT', 'TEXTAREA']) >= 0 ? 'val' : 'html');
	return arg != undefined ? jQuery(this)[fn](arg) : jQuery(this)[fn]();
};

// time ticking
var wpbc_tick = {
	tick: null,
	past_ticking: 0,
	ticked: 0,
	interval: null,
	ajaxing: false,
	last_tick: 0,
	cbs: []
};

function wpbc_stamp(btn){
	btn = jQuery(btn);
	var stamp_code = btn.data('wpbc-stamp_code');

	var ajax_action = 'stamp_'+stamp_code;
	if (wpbc_sending[ajax_action])
		return false;

	wpbc_sending[ajax_action] = btn.addClass('wpbc-stamping-loading').wpbc_val();
	if (btn.data('wpbc-saving-label'))
		btn.wpbc_val(btn.data('wpbc-saving-label'));

	wpbc_ajax_run({
		wpbc: 'stamp',
		stamp_code: stamp_code

	}, function(data){
		var oval = wpbc_sending[ajax_action];
		wpbc_sending[ajax_action] = false;

		btn.wpbc_val(oval);
		btn.removeClass('wpbc-stamping-loading');

		if (data.success){
/*				if (saved_label){
				btn.wpbc_val(saved_label);
				setTimeout(function(){
					if (!wpbc_sending[ajax_action])
						btn.wpbc_val(oval);
				}, 2000);

			} else */

			if (data.stamp_code && data.statuses)
				for (var theme in data.statuses)
					jQuery('.wpbc-button-theme-'+theme+'.wpbc-stamping-button-code-'+data.stamp_code).replaceWith(jQuery(data.statuses[theme]));
		}
	});
	return false;
}

function wpbc_add_cb(cb){
	wpbc_tick.cbs.push(cb);
}

function wpbc_tick_trigger(cb){

	var live_ids = {}, live_ids_i = 0;
	jQuery('.wpbc-stamp-live').each(function(){
		live_ids[live_ids_i] = jQuery(this).data('wpbc-live-id');
		live_ids_i++;
	});

	if (!cb) // this is the response to a startup check
		return !WPBC.no_live_update && live_ids_i;

	if (live_ids_i)
		wpbc_ajax_run({wpbc: 'stamp_live', live_ids: live_ids}, function(data){
			cb();
			var did = 0;
			if (data.success && data.live_ids){

				// save open trees
				var open_trees = [];
				jQuery('.wpbc-merkle-tree-open').each(function(){
					open_trees.push(jQuery(this).data('wpbc-bc_id'));
				});

				for (var i=0; i<data.live_ids.length; i++){
					var live_id = data.live_ids[i];
					did += jQuery('.wpbc-stamp-live[data-wpbc-live-id="'+live_id.live_id+'"]').html(live_id.html).length;
				}

				// restore previously opened trees
				var tree = null;
				for (var i=0; i<open_trees.length; i++){
					tree = jQuery('.wpbc-merkle-tree[data-wpbc-bc_id="'+open_trees[i]+'"]')
					wpbc_toggle_tree(tree.closest('.wpbc-tx').find('.wpbc-hash-tree-link'), true);
				}

				if (did){
					for (var i=0; i<wpbc_tick.cbs.length; i++)
						wpbc_tick.cbs[i].call();

					if (wpbc_stop_ticking())
						wpbc_start_ticking(true);
				}
			}
		});
}

// TICKING --------------------

function wpbc_filter_period(period){
	jQuery('.wpbc-live-delay').each(function(){
		var cperiod = parseInt(jQuery(this).data('wpbc-delay'), 10) * 1000;
		//console.info('cperiod: '+cperiod+' (period: '+period+')');
		if (cperiod && cperiod < period){
			//console.info('delay period: '+period+' -> '+cperiod);
			period = cperiod;
		}
	});
	return period;
}

function wpbc_start_ticking(is_restart, is_resume){

	if (wpbc_tick.tick || (wpbc_tick.paused && !is_resume))
		return;

	wpbc_tick.paused = false;

	wpbc_tick.tick = true;

	var period = Math.max(wpbc_filter_period(WPBC.max_ticking_period), WPBC.min_ticking_period);
	//console.log('period: '+period);

	var do_in = Math.max(wpbc_tick.last_tick + period - (new Date()).getTime(), is_restart ? 1000 : 1);
	//console.info("TICKING START IN "+do_in);

	function after_trigger(do_in){
		////console.info('after_trigger');

		var anchor = jQuery('.wpbc-live-ind');
		if (!anchor.length)
			return;

		if (!do_in)
			do_in = period;

		////console.info(do_in);

		var timer = jQuery('<span class="wpbc-live-timer" data-wpbc-live-timer="'+((new Date()).getTime() + do_in)+'"></span>').click(function(){
			if (wpbc_tick.tick){
				wpbc_stop_ticking(true);
				timer.html(WPBC.live_paused);
			} else {
				wpbc_start_ticking(false, true);
			}
		});

		function update_timer(){
			if (anchor.hasClass('.wpbc-live-ind-loading') || wpbc_tick.ajaxing){
				wpbc_clear_tick_timers();

			} else
				timer.html(WPBC.live_waiting.replace('%d', Math.max(Math.ceil((timer.data('wpbc-live-timer') - (new Date()).getTime())/1000), 1)));
		}

		update_timer();
		wpbc_tick.timer_to = setInterval(function(){
			update_timer();
		}, 1000);

		anchor.html('').append(timer).stop().animate({opacity: 1});

	}

	wpbc_clear_tick_timers();
	after_trigger(do_in);

	wpbc_tick.timer_to_first = setTimeout(function(){
		wpbc_tick.timer_to_first = null;
		if (!wpbc_tick.tick)
			return;

		wpbc_do_tick(after_trigger);

		wpbc_tick.tick = (new Date()).getTime();

		wpbc_tick.interval = setInterval(function(){
			wpbc_do_tick(after_trigger);
		}, period);
	}, do_in);
}

function wpbc_clear_tick_timers(){
	if (wpbc_tick.timer_to){
		clearInterval(wpbc_tick.timer_to);
		wpbc_tick.timer_to = null;
	}

	if (wpbc_tick.timer_to_first){
		clearTimeout(wpbc_tick.timer_to_first);
		wpbc_tick.timer_to_first = null;
	}
}

function wpbc_do_tick(after_trigger){

	if (wpbc_tick.ajaxing)
		return;
	//console.info("TICKING (not since "+((new Date()).getTime() - wpbc_tick.last_tick)+"ms)");

	wpbc_tick.last_tick = (new Date()).getTime();
	wpbc_tick.ajaxing = true;

	jQuery('.wpbc-live-ind').html(WPBC.live_loading).addClass('wpbc-live-ind-loading');

	wpbc_tick_trigger(function(){
		wpbc_tick.ajaxing = false;

		jQuery('.wpbc-live-ind').html('').removeClass('wpbc-live-ind-loading');
		if (after_trigger)
			after_trigger();
	});
}

function wpbc_stop_ticking(pausing){
	if (!wpbc_tick.tick)
		return false;

	clearInterval(wpbc_tick.interval);
	wpbc_clear_tick_timers();

	//console.info("TICKING STOP");
	wpbc_tick.tick = null;
	wpbc_tick.paused = pausing;
	return true;
}

// widget ticking

jQuery(document).ready(function(){
	if (typeof WPBC == 'undefined' || !wpbc_tick_trigger())
		return;

	wpbc_tick.last_tick = (new Date()).getTime();

	// detect tab is focused, and tick
	var tab_focused = true;
	jQuery(window).on("blur focus", function(e) {
		var prevType = jQuery(this).data("wpbc-prevType");
		//console.info('CATCHED WINDOW '+e.type);

		if (prevType != e.type) {   //  reduce double fire issues
			//console.info('CATCHED NEW WINDOW '+e.type);
			switch (e.type) {
				case "blur":
					if (WPBC.permanent_ticking)
						return;

					tab_focused = false;
					jQuery('.wpbc-live-ind').stop().animate({opacity: 0});
					wpbc_stop_ticking();
					break;
				case "focus":
					tab_focused = true;
					wpbc_start_ticking();
					jQuery('.wpbc-live-ind').stop().animate({opacity: 1});
					break;
			}
		}
		jQuery(this).data("wpbc-prevType", e.type);
	});

	jQuery(window).on('click.wpbc_scroll_ticking mousemove.wpbc_scroll_ticking scroll.wpbc_scroll_ticking', function(){
		wpbc_start_ticking();
		jQuery(window).off('click.wpbc_scroll_ticking mousemove.wpbc_scroll_ticking scroll.wpbc_scroll_ticking');
	});
});

function wpbc_ajax_run(args, cb){
	args.domain_from = location.hostname.toString();
	args = jQuery.extend({}, WPBC.ajax_extra_args, args);
	return jQuery.ajax(WPBC.ajaxurl_clean, {
		data: args,
		success: function(data){
			if (data.error)
				alert(data.error);
			if (cb)
				cb(data);
		},
		xhrFields: {
			withCredentials: true
		},
		crossDomain: true,
		cache: false,
		error: function(jqXHR, textStatus, errorThrown){
			if (cb)
				cb({success: false, debug: 'error: ajax error'});
		}
	});
}

function wpbc_toggle_tree(link, no_effect){
	var tree = jQuery(link).closest('.wpbc-tx').find('.wpbc-merkle-tree').toggleClass('wpbc-merkle-tree-open');
	if (no_effect)
		tree.toggle();
	else
		tree.slideToggle();
	jQuery(link).parent().find('a').toggle();
	return false;
}



function wpbc_tr_unfold(button){
	var b = jQuery(button);
	var lines = b.closest('.wpbc-history-table').find('.wpbc-history-hidden tr.wpbc-history-tr');
	lines.insertBefore(b.closest('.wpbc-history-tr').hide());
	jQuery('body').addClass('wpbc-history-unfolded');
	return false;
}
