function updraft_delete(key, nonce, showremote) {
	jQuery('#updraft_delete_timestamp').val(key);
	jQuery('#updraft_delete_nonce').val(nonce);
	if (showremote) {
		jQuery('#updraft-delete-remote-section, #updraft_delete_remote').removeAttr('disabled').show();
	} else {
		jQuery('#updraft-delete-remote-section, #updraft_delete_remote').hide().attr('disabled','disabled');
	}
	jQuery('#updraft-delete-modal').dialog('open');
}

function updraft_openrestorepanel(toggly) {
	//jQuery('.download-backups').slideDown(); updraft_historytimertoggle(1); jQuery('html,body').animate({scrollTop: jQuery('#updraft_lastlogcontainer').offset().top},'slow');
	updraft_console_focussed_tab = 2;
	updraft_historytimertoggle(toggly);
	jQuery('#updraft-navtab-status-content').hide();
	jQuery('#updraft-navtab-expert-content').hide();
	jQuery('#updraft-navtab-settings-content').hide();
	jQuery('#updraft-navtab-backups-content').show();
	jQuery('#updraft-navtab-backups').addClass('nav-tab-active');
	jQuery('#updraft-navtab-expert').removeClass('nav-tab-active');
	jQuery('#updraft-navtab-settings').removeClass('nav-tab-active');
	jQuery('#updraft-navtab-status').removeClass('nav-tab-active');
}

function updraft_delete_old_dirs() {
	//jQuery('#updraft_delete_old_dirs_pagediv').slideUp().remove();
	//updraft_iframe_modal('delete_old_dirs', updraftlion.delete_old_dirs);
	return true;
}

function updraft_restore_setoptions(entities) {
	var howmany = 0;
	jQuery('input[name="updraft_restore[]"]').each(function(x,y){
		var entity = jQuery(y).val();
		var epat = entity+'=([0-9,]+)';
		var eregex = new RegExp(epat);
		var ematch = entities.match(eregex);
		if (ematch) {
			jQuery(y).removeAttr('disabled').data('howmany', ematch[1]).parent().show();
			howmany++;
			if ('db' == entity) { howmany += 4.5;}
			if (jQuery(y).is(':checked')) {
				// This element may or may not exist. The purpose of explicitly calling show() is that Firefox, when reloading (including via forwards/backwards navigation) will remember checkbox states, but not which DOM elements were showing/hidden - which can result in some being hidden when they should be shown, and the user not seeing the options that are/are not checked.
				jQuery('#updraft_restorer_'+entity+'options').show();
			}
		} else {
			jQuery(y).attr('disabled','disabled').parent().hide();
		}
	});
	var dmatch = entities.match(/meta_foreign=([12])/);
	if (dmatch) {
		jQuery('#updraft_restore_meta_foreign').val(dmatch[1]);
	} else {
		jQuery('#updraft_restore_meta_foreign').val('0');
	}
	var height = 336+howmany*20;
	jQuery('#updraft-restore-modal').dialog("option", "height", height);
}

var updraft_restore_stage = 1;
var lastlog_lastmessage = "";
var lastlog_lastdata = "";
var lastlog_jobs = "";
var lastlog_sdata = { action: 'updraft_ajax', subaction: 'lastlog' };
var updraft_activejobs_nextupdate = (new Date).getTime() + 1000;
// Bits: main tab displayed (1); restore dialog open (uses downloader) (2); tab not visible (4)
// TODO: Detect downloaders directly instead of using this bit
var updraft_page_is_visible = 1;
var updraft_console_focussed_tab = 1;

function updraft_check_page_visibility(firstload) {
	if ('hidden' == document["visibilityState"]) {
		updraft_page_is_visible = 0;
	} else {
		updraft_page_is_visible = 1;
		if (1 !== firstload) { updraft_activejobs_update(true); }
	};
}

// See http://caniuse.com/#feat=pagevisibility for compatibility (we don't bother with prefixes)
if (typeof document.hidden !== "undefined") {
	document.addEventListener('visibilitychange', function() {updraft_check_page_visibility(0);}, false);
}

updraft_check_page_visibility(1);

function updraft_activejobs_update(force) {
	var timenow = (new Date).getTime();
	if (false == force && timenow < updraft_activejobs_nextupdate) { return; }
	updraft_activejobs_nextupdate = timenow + 5500;
	var downloaders = '';
	jQuery('#ud_downloadstatus .updraftplus_downloader, #ud_downloadstatus2 .updraftplus_downloader').each(function(x,y){
		var dat = jQuery(y).data('downloaderfor');
		if (typeof dat == 'object') {
			if (downloaders != '') { downloaders = downloaders + ':'; }
			downloaders = downloaders + dat.base + ',' + dat.nonce + ',' + dat.what + ',' + dat.index;
		}
	});
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'activejobs_list', nonce: updraft_credentialtest_nonce, downloaders: downloaders }, function(response) {
 		try {
			resp = jQuery.parseJSON(response);
			timenow = (new Date).getTime();
			updraft_activejobs_nextupdate = timenow + 180000;
			// More rapid updates needed if a) we are on the main console, or b) a downloader is open (which can only happen on the restore console)
			if (updraft_page_is_visible == 1 && (1 == updraft_console_focussed_tab || (2 == updraft_console_focussed_tab && downloaders != ''))) {
				if (lastlog_lastdata == response) {
					updraft_activejobs_nextupdate = timenow + 4500;
				} else {
					updraft_activejobs_nextupdate = timenow + 1250;
				}
			}

			//if (repeat) { setTimeout(function(){updraft_activejobs_update(true);}, nexttimer);}
			lastlog_lastdata = response;
			if (resp.l != null) { jQuery('#updraft_lastlogcontainer').html(resp.l); }
			jQuery('#updraft_activejobs').html(resp.j);
			if (resp.j != null && resp.j != '') {
				jQuery('#updraft_activejobsrow').show();
				if ('' == lastlog_jobs) {
					setTimeout(function(){jQuery('#updraft_backup_started').slideUp();}, 3500);
				}
			} else {
				if (!jQuery('#updraft_activejobsrow').is(':hidden')) {
					if (typeof lastbackup_laststatus != 'undefined') { updraft_showlastbackup(); }
					jQuery('#updraft_activejobsrow').hide();
				}
			}
			lastlog_jobs = resp.j;
			// Download status
			if (resp.ds != null && resp.ds != '') {
				jQuery(resp.ds).each(function(x, dstatus){
					if (dstatus.base != '') {
						updraft_downloader_status_update(dstatus.base, dstatus.timestamp, dstatus.what, dstatus.findex, dstatus, response);
					}
				});
			}
		} catch(err) {
			console.log(updraftlion.unexpectedresponse+' '+response);
			console.log(err);
		}
	});
}

//function to display pop-up window containing the log
function updraft_popuplog(backup_nonce){ 
		
		popuplog_sdata = {
			action: 'updraft_ajax',
			subaction: 'poplog',
			nonce: updraft_credentialtest_nonce,
			backup_nonce: backup_nonce
		};

		jQuery.get(ajaxurl, popuplog_sdata, function(response){

			var resp = jQuery.parseJSON(response);
			
			var download_url = '?page=updraftplus&action=downloadlog&force_download=1&updraftplus_backup_nonce='+resp.nonce;
			
			jQuery('#updraft-poplog-content').html('<pre style="white-space: pre-wrap;">'+resp.html+'</pre>'); //content of the log file
			
			var log_popup_buttons = {};
			log_popup_buttons[updraftlion.download] = function() { window.location.href = download_url; };
			log_popup_buttons[updraftlion.close] = function() { jQuery(this).dialog("close"); };
			
			//Set the dialog buttons: Download log, Close log
			jQuery('#updraft-poplog').dialog("option", "buttons", log_popup_buttons);
			//[
				//{ text: "Download", click: function() { window.location.href = download_url } },
				//{ text: "Close", click: function(){ jQuery( this ).dialog("close");} }
			//] 
			
			jQuery('#updraft-poplog').dialog("option", "title", 'log.'+resp.nonce+'.txt'); //Set dialog title
			jQuery('#updraft-poplog').dialog("open");
			
		});
}

function updraft_showlastlog(repeat){
	lastlog_sdata.nonce = updraft_credentialtest_nonce;
	jQuery.get(ajaxurl, lastlog_sdata, function(response) {
		nexttimer = 1500;
		if (lastlog_lastmessage == response) { nexttimer = 4500; }
		if (repeat) { setTimeout(function(){updraft_showlastlog(true);}, nexttimer);}
		jQuery('#updraft_lastlogcontainer').html(response);
		lastlog_lastmessage = response;
	});
}
var lastbackup_sdata = {
	action: 'updraft_ajax',
	subaction: 'lastbackup',
};
function updraft_showlastbackup(){
	lastbackup_sdata.nonce = updraft_credentialtest_nonce;
	
	jQuery.get(ajaxurl, lastbackup_sdata, function(response) {
		if (lastbackup_laststatus == response) {
			setTimeout(function(){updraft_showlastbackup();}, 7000);
		} else {
			jQuery('#updraft_last_backup').html(response);
		}
		lastbackup_laststatus = response;
	});
}
var updraft_historytimer = 0;
var calculated_diskspace = 0;
var updraft_historytimer_notbefore = 0;
function updraft_historytimertoggle(forceon) {
	if (!updraft_historytimer || forceon == 1) {
		updraft_updatehistory(0, 0);
		updraft_historytimer = setInterval(function(){updraft_updatehistory(0, 0);}, 30000);
		if (!calculated_diskspace) {
			updraftplus_diskspace();
			calculated_diskspace=1;
		}
	} else {
		clearTimeout(updraft_historytimer);
		updraft_historytimer = 0;
	}
}
function updraft_updatehistory(rescan, remotescan) {
	
	var unixtime = Math.round(new Date().getTime() / 1000);
	
	if (1 == rescan || 1 == remotescan) {
		updraft_historytimer_notbefore = unixtime + 30;
	} else {
		if (unixtime < updraft_historytimer_notbefore) {
			console.log("Update history skipped: "+unixtime.toString()+" < "+updraft_historytimer_notbefore.toString());
			return;
		}
	}
	
	if (rescan == 1) {
		if (remotescan == 1) {
			jQuery('#updraft_existing_backups').html('<p style="text-align:center;"><em>'+updraftlion.rescanningremote+'</em></p>');
		} else {
			jQuery('#updraft_existing_backups').html('<p style="text-align:center;"><em>'+updraftlion.rescanning+'</em></p>');
		}
	}
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'historystatus', nonce: updraft_credentialtest_nonce, rescan: rescan, remotescan: remotescan }, function(response) {
		try {
			resp = jQuery.parseJSON(response);
// 			if (resp.n != null) { jQuery('#updraft_showbackups').html(resp.n); }
			if (resp.n != null) { jQuery('#updraft-navtab-backups').html(resp.n); }
			if (resp.t != null) { jQuery('#updraft_existing_backups').html(resp.t); }
		} catch(err) {
			console.log(updraftlion.unexpectedresponse+' '+response);
			console.log(err);
		}
	});
}

function updraft_check_same_times() {
	var dbmanual = 0;
	var file_interval = jQuery('#updraft_interval').val();
	if (file_interval == 'manual') {
		jQuery('#updraft_files_timings').css('opacity', '0.25');
	} else {
		jQuery('#updraft_files_timings').css('opacity', 1);
	}
	
	if ('weekly' == file_interval || 'fortnightly' == file_interval || 'monthly' == file_interval) {
		jQuery('#updraft_startday_files').show();
	} else {
		jQuery('#updraft_startday_files').hide();
	}
	
	var db_interval = jQuery('#updraft_interval_database').val();
	if (db_interval == 'manual') {
		dbmanual = 1;
		jQuery('#updraft_db_timings').css('opacity', '0.25');
	}
	
	if ('weekly' == db_interval || 'fortnightly' == db_interval || 'monthly' == db_interval) {
		jQuery('#updraft_startday_db').show();
	} else {
		jQuery('#updraft_startday_db').hide();
	}
	
	if (db_interval == file_interval) {
		jQuery('#updraft_db_timings').css('opacity','0.25');
	} else {
		if (0 == dbmanual) jQuery('#updraft_db_timings').css('opacity', '1');
	}
}

// Visit the site in the background every 3.5 minutes - ensures that backups can progress if you've got the UD settings page open
setInterval(function() {jQuery.get(updraft_siteurl+'/wp-cron.php');}, 210000);

function updraft_activejobs_delete(jobid) {
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'activejobs_delete', jobid: jobid, nonce: updraft_credentialtest_nonce }, function(response) {
		try {
			var resp = jQuery.parseJSON(response);
			if (resp.ok == 'Y') {
				jQuery('#updraft-jobid-'+jobid).html(resp.m).fadeOut('slow').remove();
			} else if (resp.ok == 'N') {
				alert(resp.m);
			} else {
				alert(updraftlion.unexpectedresponse+' '+response);
			}
		} catch(err) {
			console.log(err);
			alert(updraftlion.unexpectedresponse+' '+response);
		}
	});
}

function updraftplus_diskspace_entity(key) {
	jQuery('#updraft_diskspaceused_'+key).html('<em>'+updraftlion.calculating+'</em>');
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'diskspaceused', entity: key, nonce: updraft_credentialtest_nonce }, function(response) {
		jQuery('#updraft_diskspaceused_'+key).html(response);
	});
}

function updraft_iframe_modal(getwhat, title) {
	jQuery('#updraft-iframe-modal-innards').html('<iframe width="100%" height="430px" src="'+ajaxurl+'?action=updraft_ajax&subaction='+getwhat+'&nonce='+updraft_credentialtest_nonce+'"></iframe>');
	jQuery('#updraft-iframe-modal').dialog('option', 'title', title).dialog('open');
}

function updraft_html_modal(showwhat, title) {
	jQuery('#updraft-iframe-modal-innards').html(showwhat);
	jQuery('#updraft-iframe-modal').dialog('option', 'title', title).dialog('open');
}

function updraftplus_diskspace() {
	jQuery('#updraft_diskspaceused').html('<em>'+updraftlion.calculating+'</em>');
	jQuery.get(ajaxurl, { action: 'updraft_ajax', entity: 'updraft', subaction: 'diskspaceused', nonce: updraft_credentialtest_nonce }, function(response) {
		jQuery('#updraft_diskspaceused').html(response);
	});
}
var lastlog_lastmessage = "";
function updraftplus_deletefromserver(timestamp, type, findex) {
	if (!findex) findex=0;
	var pdata = {
		action: 'updraft_download_backup',
		stage: 'delete',
		timestamp: timestamp,
		type: type,
		findex: findex,
		_wpnonce: updraft_download_nonce
	};
	jQuery.post(ajaxurl, pdata, function(response) {
		if (response != 'deleted') {
			alert('We requested to delete the file, but could not understand the server\'s response '+response);
		}
	});
}
function updraftplus_downloadstage2(timestamp, type, findex) {
	location.href=ajaxurl+'?_wpnonce='+updraft_download_nonce+'&timestamp='+timestamp+'&type='+type+'&stage=2&findex='+findex+'&action=updraft_download_backup';
}

function updraft_downloader(base, nonce, what, whicharea, set_contents, prettydate, async) {
	if (typeof set_contents !== "string") set_contents=set_contents.toString();
	var set_contents = set_contents.split(',');
	for (var i=0; i<set_contents.length; i++) {
		// Create somewhere for the status to be found
		var stid = base+nonce+'_'+what+'_'+set_contents[i];
		var show_index = parseInt(set_contents[i]); show_index++;
		var itext = (set_contents[i] == 0) ? '' : ' ('+show_index+')';
		if (!jQuery('#'+stid).length) {
			var prdate = (prettydate) ? prettydate : nonce;
			jQuery(whicharea).append('<div style="clear:left; border: 1px solid; padding: 8px; margin-top: 4px; max-width:840px;" id="'+stid+'" class="updraftplus_downloader"><button onclick="jQuery(\'#'+stid+'\').fadeOut().remove();" type="button" style="float:right; margin-bottom: 8px;">X</button><strong>Download '+what+itext+' ('+prdate+')</strong>:<div class="raw">'+updraftlion.begunlooking+'</div><div class="file" id="'+stid+'_st"><div class="dlfileprogress" style="width: 0;"></div></div>');
			jQuery('#'+stid).data('downloaderfor', { base: base, nonce: nonce, what: what, index: i });
			// Legacy: set up watcher
			//(function(base, nonce, what, i) {
			//	setTimeout(function(){updraft_downloader_status(base, nonce, what, i);}, 300);
			//})(base, nonce, what, set_contents[i]);
			setTimeout(function() {updraft_activejobs_update(true);}, 1500);
		}
		// Now send the actual request to kick it all off
		jQuery.ajax({
			url: ajaxurl,
			timeout: 10000,
			type: 'POST',
			async: async,
			data: jQuery('#uddownloadform_'+what+'_'+nonce+'_'+set_contents[i]).serialize()
		});
	}
	// We don't want the form to submit as that replaces the document
	return false;
}

// Catch HTTP errors if the download status check returns them
jQuery(document).ajaxError(function( event, jqxhr, settings, exception ) {
	if (exception == null || exception == '') return;
	if (jqxhr.responseText == null || jqxhr.responseText == '') return;
	console.log("Error caught by UpdraftPlus ajaxError handler (follows) for "+settings.url);
	console.log(exception);
	if (settings.url.search(ajaxurl) == 0) {
		if (settings.url.search('subaction=downloadstatus') >= 0) {
			var timestamp = settings.url.match(/timestamp=\d+/);
			var type = settings.url.match(/type=[a-z]+/);
			var findex = settings.url.match(/findex=\d+/);
			var base = settings.url.match(/base=[a-z_]+/);
			findex = (findex instanceof Array) ? parseInt(findex[0].substr(7)) : 0;
			type = (type instanceof Array) ? type[0].substr(5) : '';
			base = (base instanceof Array) ? base[0].substr(5) : '';
			timestamp = (timestamp instanceof Array) ? parseInt(timestamp[0].substr(10)) : 0;
			if ('' != base && '' != type && timestamp >0) {
				var stid = base+timestamp+'_'+type+'_'+findex;
				jQuery('#'+stid+' .raw').html('<strong>'+updraftlion.error+'</strong> '+updraftlion.servererrorcode);
			}
		} else if (settings.url.search('subaction=restore_alldownloaded') >= 0) {
			//var timestamp = settings.url.match(/timestamp=\d+/);
			jQuery('#updraft-restore-modal-stage2a').append('<br><strong>'+updraftlion.error+'</strong> '+updraftlion.servererrorcode+': '+exception);
		}
	}
});

function updraft_restorer_checkstage2(doalert) {
	// How many left?
	var stilldownloading = jQuery('#ud_downloadstatus2 .file').length;
	if (stilldownloading > 0) {
		if (doalert) { alert(updraftlion.stilldownloading); }
		return;
	}
	// Allow pressing 'Restore' to proceed
	jQuery('#updraft-restore-modal-stage2a').html(updraftlion.processing);
	jQuery.get(ajaxurl, {
		action: 'updraft_ajax',
		subaction: 'restore_alldownloaded', 
		nonce: updraft_credentialtest_nonce,
		timestamp: jQuery('#updraft_restore_timestamp').val(),
		restoreopts: jQuery('#updraft_restore_form').serialize()
	}, function(data) {
		try {
			var resp = jQuery.parseJSON(data);
			if (null == resp) {
				jQuery('#updraft-restore-modal-stage2a').html(updraftlion.emptyresponse);
				return;
			}
			var report = resp.m;
			if (resp.w != '') {
				report = report + "<p><strong>" + updraftlion.warnings +'</strong><br>' + resp.w + "</p>";
			}
			if (resp.e != '') {
				report = report + "<p><strong>" + updraftlion.errors+'</strong><br>' + resp.e + "</p>";
			} else {
				updraft_restore_stage = 3;
			}
			jQuery('#updraft-restore-modal-stage2a').html(report);
		} catch(err) {
			console.log(data);
			console.log(err);
			jQuery('#updraft-restore-modal-stage2a').html(updraftlion.jsonnotunderstood);
		}
	});
}
var dlstatus_sdata = {
	action: 'updraft_ajax',
	subaction: 'downloadstatus',
};
dlstatus_lastlog = '';
function updraft_downloader_status(base, nonce, what, findex) {
// Short-circuit
return;

	if (findex == null || findex == 0 || findex == '') { findex='0'; }
	// Get the DOM id of the status div (add _st for the id of the file itself)
	var stid = base+nonce+'_'+what+'_'+findex;
	if (!jQuery('#'+stid).length) { return; }
//console.log(stid+": "+jQuery('#'+stid).length);
	dlstatus_sdata.nonce=updraft_credentialtest_nonce;
	dlstatus_sdata.timestamp = nonce;
	dlstatus_sdata.type = what;
	dlstatus_sdata.findex = findex;
	// This goes in because we want to read it back on any ajaxError event
	dlstatus_sdata.base = base;
	jQuery.get(ajaxurl, dlstatus_sdata, function(response) {
		nexttimer = 1250;
		if (dlstatus_lastlog == response) { nexttimer = 3000; }
		try {
			var resp = jQuery.parseJSON(response);
			var cancel_repeat = updraft_downloader_status_update(base, nonce, what, findex, resp, response);
			if (cancel_repeat == 0) {
				(function(base, nonce, what, findex) {
					setTimeout(function(){updraft_downloader_status(base, nonce, what, findex)}, nexttimer);
				})(base, nonce, what, findex);
			}
		} catch(err) {
			alert(updraftlion.notunderstood+' '+updraftlion.error+' '+err);
		}
	});
}

function updraft_downloader_status_update(base, nonce, what, findex, resp, response) {
	var stid = base+nonce+'_'+what+'_'+findex;
	var cancel_repeat = 0;
	if (resp.e != null) {
		jQuery('#'+stid+' .raw').html('<strong>'+updraftlion.error+'</strong> '+resp.e);
		console.log(resp);
	} else if (resp.p != null) {
		jQuery('#'+stid+'_st .dlfileprogress').width(resp.p+'%');
		//jQuery('#'+stid+'_st .dlsofar').html(Math.round(resp.s/1024));
		//jQuery('#'+stid+'_st .dlsize').html(Math.round(resp.t/1024));
		if (resp.m != null) {
			if (resp.p >=100 && base == 'udrestoredlstatus_') {
				jQuery('#'+stid+' .raw').html(resp.m);
				jQuery('#'+stid).fadeOut('slow', function() { jQuery(this).remove(); updraft_restorer_checkstage2(0);});
			} else if (resp.p < 100 || base != 'uddlstatus_') {
				jQuery('#'+stid+' .raw').html(resp.m);
			} else {
				jQuery('#'+stid+' .raw').html(updraftlion.fileready+' '+ updraftlion.youshould+' <button type="button" onclick="updraftplus_downloadstage2(\''+nonce+'\', \''+what+'\', \''+findex+'\')\">'+updraftlion.downloadtocomputer+'</button> '+updraftlion.andthen+' <button id="uddownloaddelete_'+nonce+'_'+what+'" type="button" onclick="updraftplus_deletefromserver(\''+nonce+'\', \''+what+'\', \''+findex+'\')\">'+updraftlion.deletefromserver+'</button>');
			}
		}
		dlstatus_lastlog = response;
	} else if (resp.m != null) {
			jQuery('#'+stid+' .raw').html(resp.m);
	} else {
		jQuery('#'+stid+' .raw').html(updraftlion.jsonnotunderstood+' ('+response+')');
		cancel_repeat = 1;
	}
	return cancel_repeat;
}

jQuery(document).ready(function($){

	var bigbutton_width = 180;
	jQuery('.updraft-bigbutton').each(function(x,y){
		var bwid = jQuery(y).width();
		if (bwid > bigbutton_width) bigbutton_width = bwid;
	});
	if (bigbutton_width > 180) jQuery('.updraft-bigbutton').width(bigbutton_width);

	//setTimeout(function(){updraft_showlastlog(true);}, 1200);
	setInterval(function() {updraft_activejobs_update(false);}, 1250);

	jQuery('.updraftplusmethod').hide();
	
	jQuery('#updraft_restore_db').change(function(){
		if (jQuery('#updraft_restore_db').is(':checked')) {
			jQuery('#updraft_restorer_dboptions').slideDown();
		} else {
			jQuery('#updraft_restorer_dboptions').slideUp();
		}
	});

	updraft_check_same_times();

	var updraft_message_modal_buttons = {};
	updraft_message_modal_buttons[updraftlion.close] = function() { jQuery(this).dialog("close"); };
	jQuery( "#updraft-message-modal" ).dialog({
		autoOpen: false, height: 350, width: 520, modal: true,
		buttons: updraft_message_modal_buttons
	});
	
	var updraft_delete_modal_buttons = {};
	updraft_delete_modal_buttons[updraftlion.deletebutton] = function() {
		jQuery('#updraft-delete-waitwarning').slideDown();
		timestamp = jQuery('#updraft_delete_timestamp').val();
		jQuery.post(ajaxurl, jQuery('#updraft_delete_form').serialize(), function(response) {
			jQuery('#updraft-delete-waitwarning').slideUp();
			var resp;
			try {
				resp = jQuery.parseJSON(response);
			} catch(err) {
				alert(updraftlion.unexpectedresponse+' '+response);
			}
			if (resp.result != null) {
				if (resp.result == 'error') {
					alert(updraftlion.error+' '+resp.message);
				} else if (resp.result == 'success') {
					//jQuery('#updraft_showbackups').load(ajaxurl+'?action=updraft_ajax&subaction=countbackups&nonce='+updraft_credentialtest_nonce);
					jQuery('#updraft-navtab-backups').load(ajaxurl+'?action=updraft_ajax&subaction=countbackups&nonce='+updraft_credentialtest_nonce);
					jQuery('#updraft_existing_backups_row_'+timestamp).slideUp().remove();
					jQuery("#updraft-delete-modal").dialog('close');
					alert(resp.message);
				}
			}
		});
	};
	updraft_delete_modal_buttons[updraftlion.cancel] = function() { jQuery(this).dialog("close"); };
	jQuery( "#updraft-delete-modal" ).dialog({
		autoOpen: false, height: 262, width: 430, modal: true,
		buttons: updraft_delete_modal_buttons
	});

	var updraft_restore_modal_buttons = {};
	updraft_restore_modal_buttons[updraftlion.restore] = function() {
		var anyselected = 0;
		var whichselected = [];
		// Make a list of what files we want
		var already_added_wpcore = 0;
		var meta_foreign = jQuery('#updraft_restore_meta_foreign').val();
		jQuery('input[name="updraft_restore[]"]').each(function(x,y){
			if (jQuery(y).is(':checked') && !jQuery(y).is(':disabled')) {
				anyselected = 1;
				var howmany = jQuery(y).data('howmany');
				var type = jQuery(y).val();
				if (1 == meta_foreign || (2 == meta_foreign && 'db' != type)) { type = 'wpcore'; }
				if ('wpcore' != type || already_added_wpcore == 0) {
					var restobj = [ type, howmany ];
					whichselected.push(restobj);
					//alert(jQuery(y).val());
					if ('wpcore' == type) { already_added_wpcore = 1; }
				}
			}
		});
		if (anyselected == 1) {
			// Work out what to download
			if (updraft_restore_stage == 1) {
				// meta_foreign == 1 : All-in-one format: the only thing to download, always, is wpcore
// 				if ('1' == meta_foreign) {
// 					whichselected = [];
// 					whichselected.push([ 'wpcore', 0 ]);
// 				} else if ('2' == meta_foreign) {
// 					jQuery(whichselected).each(function(x,y) {
// 						restobj = whichselected[x];
// 					});
// 					whichselected = [];
// 					whichselected.push([ 'wpcore', 0 ]);
// 				}
				jQuery('#updraft-restore-modal-stage1').slideUp('slow');
				jQuery('#updraft-restore-modal-stage2').show();
				updraft_restore_stage = 2;
				var pretty_date = jQuery('.updraft_restore_date').first().text();
				// Create the downloader active widgets

				for (var i=0; i<whichselected.length; i++) {
					updraft_downloader('udrestoredlstatus_', jQuery('#updraft_restore_timestamp').val(), whichselected[i][0], '#ud_downloadstatus2', whichselected[i][1], pretty_date, false);
				}

				// Make sure all are downloaded
			} else if (updraft_restore_stage == 2) {
				updraft_restorer_checkstage2(1);
			} else if (updraft_restore_stage == 3) {
				jQuery('#updraft-restore-modal-stage2a').html(updraftlion.restoreproceeding);
				jQuery('#updraft_restore_form').submit();
			}
		} else {
			alert('You did not select any components to restore. Please select at least one, and then try again.');
		}
	};
	updraft_restore_modal_buttons[updraftlion.cancel] = function() { jQuery(this).dialog("close"); };

	jQuery( "#updraft-restore-modal" ).dialog({
		autoOpen: false, height: 505, width: 590, modal: true,
		buttons: updraft_restore_modal_buttons
	});

	jQuery("#updraft-iframe-modal" ).dialog({
		autoOpen: false, height: 500, width: 780, modal: true
	});

	var backupnow_modal_buttons = {};
	backupnow_modal_buttons[updraftlion.backupnow] = function() {
		
		var backupnow_nodb = jQuery('#backupnow_nodb').is(':checked') ? 1 : 0;
		var backupnow_nofiles = jQuery('#backupnow_nofiles').is(':checked') ? 1 : 0;
		var backupnow_nocloud = jQuery('#backupnow_nocloud').is(':checked') ? 1 : 0;
		if (backupnow_nodb && backupnow_nofiles) {
			alert(updraftlion.excludedeverything);
			return;
		}
		
		jQuery(this).dialog("close");
		jQuery('#updraft_backup_started').html('<em>'+updraftlion.requeststart+'</em>').slideDown('');
		setTimeout(function() {
			jQuery('#updraft_lastlogmessagerow').fadeOut('slow', function() {
				jQuery(this).fadeIn('slow');
			});
		}, 1700);
		setTimeout(function() {updraft_activejobs_update(true);}, 1000);
		setTimeout(function() {jQuery('#updraft_backup_started').fadeOut('slow');}, 75000);
		jQuery.post(ajaxurl, {
			action: 'updraft_ajax',
			subaction: 'backupnow',
			nonce: updraft_credentialtest_nonce,
			backupnow_nodb: backupnow_nodb,
			backupnow_nofiles: backupnow_nofiles,
			backupnow_nocloud: backupnow_nocloud,
			backupnow_label: jQuery('#backupnow_label').val()
		}, function(response) {
			jQuery('#updraft_backup_started').html(response);
			// Kick off some activity to get WP to get the scheduled task moving as soon as possible
// 			setTimeout(function() {jQuery.get(updraft_siteurl);}, 5100);
// 			setTimeout(function() {jQuery.get(updraft_siteurl+'/wp-cron.php');}, 13500);
		});
	};
	backupnow_modal_buttons[updraftlion.cancel] = function() { jQuery(this).dialog("close"); };
	
	jQuery("#updraft-backupnow-modal" ).dialog({
		autoOpen: false, height: 355, width: 480, modal: true,
		buttons: backupnow_modal_buttons
	});

	var migrate_modal_buttons = {};
	migrate_modal_buttons[updraftlion.close] = function() { jQuery(this).dialog("close"); };
	jQuery( "#updraft-migrate-modal" ).dialog({
		autoOpen: false, height: 295, width: 420, modal: true,
		buttons: migrate_modal_buttons
	});
	
	jQuery( "#updraft-poplog" ).dialog({
		autoOpen: false, height: 600, width: '75%', modal: true,
	});
	
	jQuery('#enableexpertmode').click(function() {
		jQuery('.expertmode').fadeIn();
		jQuery('#enableexpertmode').off('click'); 
		return false;
	});

	jQuery('#updraft_include_others').click(function() {
		if (jQuery('#updraft_include_others').is(':checked')) {
			jQuery('#updraft_include_others_exclude').slideDown();
		} else {
			jQuery('#updraft_include_others_exclude').slideUp();
		}
	});
	
	jQuery('#updraft_include_uploads').click(function() {
		if (jQuery('#updraft_include_uploads').is(':checked')) {
			jQuery('#updraft_include_uploads_exclude').slideDown();
		} else {
			jQuery('#updraft_include_uploads_exclude').slideUp();
		}
	});
	
	jQuery('#updraft-service').change(function() {
		jQuery('.updraftplusmethod').hide();
		var active_class = jQuery(this).val();
		jQuery('.'+active_class).show();
	});

	jQuery('#updraftplus-phpinfo').click(function(e) {
		e.preventDefault();
		updraft_iframe_modal('phpinfo', updraftlion.phpinfo);
	});

	jQuery('#updraftplus-rawbackuphistory').click(function(e) {
		e.preventDefault();
		updraft_iframe_modal('rawbackuphistory', updraftlion.raw);
	});

	jQuery('#updraft-navtab-status').click(function(e) {
		e.preventDefault();
		jQuery(this).addClass('nav-tab-active');
		jQuery('#updraft-navtab-expert-content').hide();
		jQuery('#updraft-navtab-settings-content').hide();
		jQuery('#updraft-navtab-backups-content').hide();
		jQuery('#updraft-navtab-status-content').show();
		jQuery('#updraft-navtab-expert').removeClass('nav-tab-active');
		jQuery('#updraft-navtab-backups').removeClass('nav-tab-active');
		jQuery('#updraft-navtab-settings').removeClass('nav-tab-active');
		updraft_page_is_visible = 1;
		updraft_console_focussed_tab = 1;
		// Refresh the console, as its next update might be far away
		updraft_activejobs_update(true);
	});
	jQuery('#updraft-navtab-expert').click(function(e) {
		e.preventDefault();
		jQuery(this).addClass('nav-tab-active');
		jQuery('#updraft-navtab-settings-content').hide();
		jQuery('#updraft-navtab-status-content').hide();
		jQuery('#updraft-navtab-backups-content').hide();
		jQuery('#updraft-navtab-expert-content').show();
		jQuery('#updraft-navtab-status').removeClass('nav-tab-active');
		jQuery('#updraft-navtab-backups').removeClass('nav-tab-active');
		jQuery('#updraft-navtab-settings').removeClass('nav-tab-active');
		updraft_page_is_visible = 1;
		updraft_console_focussed_tab = 4;
	});
	jQuery('#updraft-navtab-settings, #updraft-navtab-settings2').click(function(e) {
		e.preventDefault();
		jQuery('#updraft-navtab-status-content').hide();
		jQuery('#updraft-navtab-backups-content').hide();
		jQuery('#updraft-navtab-expert-content').hide();
		jQuery('#updraft-navtab-settings-content').show();
		jQuery('#updraft-navtab-settings').addClass('nav-tab-active');
		jQuery('#updraft-navtab-expert').removeClass('nav-tab-active');
		jQuery('#updraft-navtab-backups').removeClass('nav-tab-active');
		jQuery('#updraft-navtab-status').removeClass('nav-tab-active');
		updraft_page_is_visible = 1;
		updraft_console_focussed_tab = 3;
	});
	jQuery('#updraft-navtab-backups').click(function(e) {
		e.preventDefault();
		updraft_openrestorepanel(1);
	});
	
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'ping', nonce: updraft_credentialtest_nonce }, function(data, response) {
		if ('success' == response && data != 'pong' && data.indexOf('pong')>=0) {
			jQuery('#ud-whitespace-warning').show();
		}
	});

	// Section: Plupload
	try {
		plupload_init();
	} catch (err) {
		console.log(err);
	}
	
	function plupload_init() {
	
		// create the uploader and pass the config from above
		var uploader = new plupload.Uploader(updraft_plupload_config);

		// checks if browser supports drag and drop upload, makes some css adjustments if necessary
		uploader.bind('Init', function(up){
			var uploaddiv = $('#plupload-upload-ui');
			
			if(up.features.dragdrop){
				uploaddiv.addClass('drag-drop');
				$('#drag-drop-area')
				.bind('dragover.wp-uploader', function(){ uploaddiv.addClass('drag-over'); })
				.bind('dragleave.wp-uploader, drop.wp-uploader', function(){ uploaddiv.removeClass('drag-over'); });
				
			} else {
				uploaddiv.removeClass('drag-drop');
				$('#drag-drop-area').unbind('.wp-uploader');
			}
		});
					
		uploader.init();

		// a file was added in the queue
		uploader.bind('FilesAdded', function(up, files){
		// 				var hundredmb = 100 * 1024 * 1024, max = parseInt(up.settings.max_file_size, 10);
		
		plupload.each(files, function(file){

			if (! /^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+(of[0-9]+)?)?\.(zip|gz|gz\.crypt)$/i.test(file.name) && ! /^log\.([0-9a-f]{12})\.txt$/.test(file.name)) {
				var accepted_file = false;
				for (var i = 0; i<updraft_accept_archivename.length; i++) {
					if (updraft_accept_archivename[i].test(file.name)) {
						var accepted_file = true;
					}
				}
				if (!accepted_file) {
					if (/\.(zip|tar|tar\.gz|tar\.bz2)$/i.test(file.name) || /\.sql(\.gz)?$/i.test(file.name)) {
						jQuery('#updraft-message-modal-innards').html('<p><strong>'+file.name+"</strong></p> "+updraftlion.notarchive2);
						jQuery('#updraft-message-modal').dialog('open');
					} else {
						alert(file.name+": "+updraftlion.notarchive);
					}
					uploader.removeFile(file);
					return;
				}
			}
			
			// a file was added, you may want to update your DOM here...
			$('#filelist').append(
				'<div class="file" id="' + file.id + '"><b>' +
				file.name + '</b> (<span>' + plupload.formatSize(0) + '</span>/' + plupload.formatSize(file.size) + ') ' +
				'<div class="fileprogress"></div></div>');
		});
			
			up.refresh();
			up.start();
		});
			
		uploader.bind('UploadProgress', function(up, file) {
			$('#' + file.id + " .fileprogress").width(file.percent + "%");
			$('#' + file.id + " span").html(plupload.formatSize(parseInt(file.size * file.percent / 100)));
		});

		uploader.bind('Error', function(up, error) {
			alert(updraftlion.uploaderr+' (code '+error.code+') : '+error.message+' '+updraftlion.makesure);
		});


		// a file was uploaded 
		uploader.bind('FileUploaded', function(up, file, response) {
			
			if (response.status == '200') {
				// this is your ajax response, update the DOM with it or something...
				try {
					resp = jQuery.parseJSON(response.response);
					if (resp.e) {
						alert(updraftlion.uploaderror+" "+resp.e);
					} else if (resp.dm) {
						alert(resp.dm);
						updraft_updatehistory(1, 0);
					} else if (resp.m) {
						updraft_updatehistory(1, 0);
					} else {
						alert('Unknown server response: '+response.response);
					}
					
				} catch(err) {
					console.log(response);
					alert(updraftlion.jsonnotunderstood);
				}

			} else {
				alert('Unknown server response status: '+response.code);
				console.log(response);
			}

		});
	}
	
	// Functions in the debugging console
	$('#updraftplus_httpget_go').click(function(e) {
		e.preventDefault();
		updraftplus_httpget_go(0);
	});

	$('#updraftplus_httpget_gocurl').click(function(e) {
		e.preventDefault();
		updraftplus_httpget_go(1);
	});
	
	$('#updraftplus_callwpaction_go').click(function(e) {
		e.preventDefault();
		params = { action: 'updraft_ajax', subaction: 'callwpaction', nonce: updraft_credentialtest_nonce, wpaction: $('#updraftplus_callwpaction').val() };
		$.get(ajaxurl, params, function(response) {
			try {
				resp = jQuery.parseJSON(response);
				if (resp.e) {
					alert(resp.e);
				} else if (resp.s) {
					// Silence
				} else if (resp.r) {
					$('#updraftplus_callwpaction_results').html(resp.r);
				} else {
					console.log(response);
					alert(updraftlion.jsonnotunderstood);
				}
				
			} catch(err) {
				console.log(response);
				alert(updraftlion.jsonnotunderstood);
			}
		});
	});
	
	function updraftplus_httpget_go(curl) {
		params = { action: 'updraft_ajax', subaction: 'httpget', nonce: updraft_credentialtest_nonce, uri: $('#updraftplus_httpget_uri').val() };
		params.curl = curl;
		$.get(ajaxurl, params, function(response) {
			try {
				resp = jQuery.parseJSON(response);
				if (resp.e) {
					alert(resp.e);
				}
				if (resp.r) {
					$('#updraftplus_httpget_results').html('<pre>'+resp.r+'</pre>');
				} else {
					console.log(response);
					//alert(updraftlion.jsonnotunderstood);
				}
				
			} catch(err) {
				console.log(err);
				console.log(response);
				alert(updraftlion.jsonnotunderstood);
			}
		});
	}
	// , 
	jQuery('#updraft_existing_backups').on('tripleclick', '.updraft_existingbackup_date', { threshold: 500 }, function(e) {
		e.preventDefault();
		var data = jQuery(this).data('rawbackup');
		if (data != null && data != '') {
			updraft_html_modal(data, updraftlion.raw);
		}
	});
	
});

// Next: the encrypted database pluploader

jQuery(document).ready(function($){
	
	try {
		plupload_init();
	} catch (err) {
		console.log(err);
	}
		
	function plupload_init() {
		// create the uploader and pass the config from above
		var uploader = new plupload.Uploader(updraft_plupload_config2);
		
		// checks if browser supports drag and drop upload, makes some css adjustments if necessary
		uploader.bind('Init', function(up){
			var uploaddiv = $('#plupload-upload-ui2');

			if(up.features.dragdrop){
				uploaddiv.addClass('drag-drop');
				$('#drag-drop-area2')
				.bind('dragover.wp-uploader', function(){ uploaddiv.addClass('drag-over'); })
				.bind('dragleave.wp-uploader, drop.wp-uploader', function(){ uploaddiv.removeClass('drag-over'); });
			} else {
				uploaddiv.removeClass('drag-drop');
				$('#drag-drop-area2').unbind('.wp-uploader');
			}
		});
		
		uploader.init();
		
		// a file was added in the queue
		uploader.bind('FilesAdded', function(up, files){
			// 				var hundredmb = 100 * 1024 * 1024, max = parseInt(up.settings.max_file_size, 10);
			
			plupload.each(files, function(file){
				
				if (! /^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-db([0-9]+)?\.(gz\.crypt)$/i.test(file.name)) {
					alert(file.name+': '+updraftlion.notdba);
					uploader.removeFile(file);
					return;
				}
				
				// a file was added, you may want to update your DOM here...
				$('#filelist2').append(
					'<div class="file" id="' + file.id + '"><b>' +
					file.name + '</b> (<span>' + plupload.formatSize(0) + '</span>/' + plupload.formatSize(file.size) + ') ' +
					'<div class="fileprogress"></div></div>');
			});
		
			up.refresh();
			up.start();
		});
		
		uploader.bind('UploadProgress', function(up, file) {
			$('#' + file.id + " .fileprogress").width(file.percent + "%");
			$('#' + file.id + " span").html(plupload.formatSize(parseInt(file.size * file.percent / 100)));
		});
		
		uploader.bind('Error', function(up, error) {
			alert(updraftlion.uploaderr+' (code '+error.code+") : "+error.message+" "+updraftlion.makesure);
		});
		
		// a file was uploaded 
		uploader.bind('FileUploaded', function(up, file, response) {
			
			if (response.status == '200') {
				// this is your ajax response, update the DOM with it or something...
				if (response.response.substring(0,6) == 'ERROR:') {
					alert(updraftlion.uploaderror+" "+response.response.substring(6));
				} else if (response.response.substring(0,3) == 'OK:') {
					bkey = response.response.substring(3);
					$('#' + file.id + " .fileprogress").hide();
					$('#' + file.id).append(updraftlion.uploaded+' <a href="?page=updraftplus&action=downloadfile&updraftplus_file='+bkey+'&decrypt_key='+$('#updraftplus_db_decrypt').val()+'">'+updraftlion.followlink+'</a> '+updraftlion.thiskey+' '+$('#updraftplus_db_decrypt').val().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"));
				} else {
					alert(updraftlion.unknownresp+' '+response.response);
				}
			} else {
				alert(updraftlion.ukrespstatus+' '+response.code);
			}
			
		});
	}

	jQuery('#updraft-hidethis').remove();

});

// https://github.com/richadams/jquery-tripleclick/
// @author Rich Adams <rich@richadams.me>
// Implements a triple-click event. Click (or touch) three times within 1s on the element to trigger.

;(function($)
{
	// Default options
	var defaults = {
		threshold: 1000, // ms
	}
	
	function tripleHandler(event)
	{
		var $elem = jQuery(this);
		
		// Merge the defaults and any user defined settings.
		settings = jQuery.extend({}, defaults, event.data);
		
		// Get current values, or 0 if they don't yet exist.
		var clicks = $elem.data("triclick_clicks") || 0;
		var start = $elem.data("triclick_start") || 0;
		
		// If first click, register start time.
		if (clicks === 0) { start = event.timeStamp; }
		
		// If we have a start time, check it's within limit
		if (start != 0
			&& event.timeStamp > start + settings.threshold)
		{
			// Tri-click failed, took too long.
			clicks = 0;
			start = event.timeStamp;
		}
		
		// Increment counter, and do finish action.
		clicks += 1;
		if (clicks === 3)
		{
			clicks = 0;
			start = 0;
			event.type = "tripleclick";
			
			// Let jQuery handle the triggering of "tripleclick" event handlers
			if (jQuery.event.handle === undefined) {
				jQuery.event.dispatch.apply(this, arguments);
			}
			else {
				// for jQuery before 1.9
				jQuery.event.handle.apply(this, arguments);
			}
		}
		
		// Update object data
		$elem.data("triclick_clicks", clicks);
		$elem.data("triclick_start", start);
	}
	
	var tripleclick = $.event.special.tripleclick =
	{
		setup: function(data, namespaces)
		{
			$(this).bind("touchstart click.triple", data, tripleHandler);
		},
  teardown: function(namespaces)
  {
	  $(this).unbind("touchstart click.triple", data, tripleHandler);
  }
	};
})(jQuery);