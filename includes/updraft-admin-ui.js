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
			if (entity == 'db') { howmany += 4.5;}
		} else {
			jQuery(y).attr('disabled','disabled').parent().hide();
		}
	});
	var height = 276+howmany*20;
	jQuery('#updraft-restore-modal').dialog("option", "height", height);
}


var updraft_restore_stage = 1;
var lastlog_lastmessage = "";
var lastlog_lastdata = "";
var lastlog_sdata = {
	action: 'updraft_ajax',
	subaction: 'lastlog',
};

function updraft_activejobs_update(repeat) {
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'activejobs_list', nonce: updraft_credentialtest_nonce }, function(response) {
		try {
			resp = jQuery.parseJSON(response);
			nexttimer = 1500;
			if (lastlog_lastdata == response) { nexttimer = 4500; }
			if (repeat) { setTimeout(function(){updraft_activejobs_update(true);}, nexttimer);}
			lastlog_lastdata = response;
			if (resp.l != null) { jQuery('#updraft_lastlogcontainer').html(resp.l); }
			jQuery('#updraft_activejobs').html(resp.j);
			if (resp.j != null && resp.j != '') {
				jQuery('#updraft_activejobsrow').show();
			} else {
				if (!jQuery('#updraft_activejobsrow').is(':hidden')) {
					if (typeof lastbackup_laststatus != 'undefined') { updraft_showlastbackup(); }
					jQuery('#updraft_activejobsrow').hide();
				}
			}
		} catch(err) {
			console.log(updraftlion.unexpectedresponse+' '+response);
		}
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
function updraft_historytimertoggle(forceon) {
	if (!updraft_historytimer || forceon == 1) {
		updraft_updatehistory(0);
		updraft_historytimer = setInterval(function(){updraft_updatehistory(0);}, 30000);
		if (!calculated_diskspace) {
			updraftplus_diskspace();
			calculated_diskspace=1;
		}
	} else {
		clearTimeout(updraft_historytimer);
		updraft_historytimer = 0;
	}
}
function updraft_updatehistory(rescan) {
	if (rescan == 1) {
		jQuery('#updraft_existing_backups').html('<p style="text-align:center;"><em>'+updraftlion.rescanning+'</em></p>');
	}
	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'historystatus', nonce: updraft_credentialtest_nonce, rescan: rescan }, function(response) {
		try {
			resp = jQuery.parseJSON(response);
			if (resp.n != null) { jQuery('#updraft_showbackups').html(resp.n); }
			if (resp.t != null) { jQuery('#updraft_existing_backups').html(resp.t); }
		} catch(err) {
			console.log(updraftlion.unexpectedresponse+' '+response);
		}
	});
}

function updraft_check_same_times() {
	var dbmanual = 0;
	var file_interval = jQuery('#updraft_interval').val();
	if (file_interval == 'manual') {
		jQuery('#updraft_files_timings').css('opacity','0.25');
	} else {
		jQuery('#updraft_files_timings').css('opacity',1);
	}
	
	if ('weekly' == file_interval || 'fortnightly' == file_interval || 'monthly' == file_interval) {
		jQuery('#updraft_startday_files').show();
	} else {
		jQuery('#updraft_startday_files').hide();
	}
	
	var db_interval = jQuery('#updraft_interval_database').val();
	if ( db_interval == 'manual') {
		dbmanual = 1;
		jQuery('#updraft_db_timings').css('opacity','0.25');
	}
	
	if ('weekly' == db_interval || 'fortnightly' == db_interval || 'monthly' == db_interval) {
		jQuery('#updraft_startday_db').show();
	} else {
		jQuery('#updraft_startday_db').hide();
	}
	
	if (db_interval == file_interval) {
		jQuery('#updraft_db_timings').css('opacity','0.25');
	} else {
		if (0 == dbmanual) jQuery('#updraft_db_timings').css('opacity','1');
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
	jQuery('#updraft-iframe-modal-innards').html('<iframe width="100%" height="440px" src="'+ajaxurl+'?action=updraft_ajax&subaction='+getwhat+'&nonce='+updraft_credentialtest_nonce+'"></iframe>');
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
function updraft_downloader(base, nonce, what, whicharea, set_contents, prettydate) {
	if (typeof set_contents !== "string") set_contents=set_contents.toString();
	var set_contents = set_contents.split(',');
	for (var i=0;i<set_contents.length; i++) {
		// Create somewhere for the status to be found
		var stid = base+nonce+'_'+what+'_'+set_contents[i];
		var show_index = parseInt(set_contents[i]); show_index++;
		var itext = (set_contents[i] == 0) ? '' : ' ('+show_index+')';
		if (!jQuery('#'+stid).length) {
			var prdate = (prettydate) ? prettydate : nonce;
			jQuery(whicharea).append('<div style="clear:left; border: 1px solid; padding: 8px; margin-top: 4px; max-width:840px;" id="'+stid+'" class="updraftplus_downloader"><button onclick="jQuery(\'#'+stid+'\').fadeOut().remove();" type="button" style="float:right; margin-bottom: 8px;">X</button><strong>Download '+what+itext+' ('+prdate+')</strong>:<div class="raw">'+updraftlion.begunlooking+'</div><div class="file" id="'+stid+'_st"><div class="dlfileprogress" style="width: 0;"></div></div>');
			// <b><span class="dlname">??</span></b> (<span class="dlsofar">?? KB</span>/<span class="dlsize">??</span> KB)
			(function(base, nonce, what, i) {
				setTimeout(function(){updraft_downloader_status(base, nonce, what, i);}, 300);
			})(base, nonce, what, set_contents[i]);
		}
		// Now send the actual request to kick it all off
		jQuery.post(ajaxurl, jQuery('#uddownloadform_'+what+'_'+nonce+'_'+set_contents[i]).serialize());
	}
	// We don't want the form to submit as that replaces the document
	return false;
}

// Catch HTTP errors if the download status check returns them
jQuery( document ).ajaxError(function( event, jqxhr, settings, exception ) {
	if (settings.url.search(ajaxurl) == 0) {
		if (settings.url.search('subaction=downloadstatus')) {
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
				alert(updraftlion.jsonnotunderstood+' ('+response+')');
				cancel_repeat = 1;
			}
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

jQuery(document).ready(function($){
	
	//setTimeout(function(){updraft_showlastlog(true);}, 1200);
	setTimeout(function() {updraft_activejobs_update(true);}, 1200);
	
	jQuery('.updraftplusmethod').hide();
	
	jQuery('#updraft_restore_db').change(function(){
		if (jQuery('#updraft_restore_db').is(':checked')) {
			jQuery('#updraft_restorer_dboptions').slideDown();
		} else {
			jQuery('#updraft_restorer_dboptions').slideUp();
		}
	});

	updraft_check_same_times();

	var updraft_delete_modal_buttons = {};
	updraft_delete_modal_buttons[updraftlion.delete] = function() {
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
					jQuery('#updraft_showbackups').load(ajaxurl+'?action=updraft_ajax&subaction=countbackups&nonce='+updraft_credentialtest_nonce);
					jQuery('#updraft_existing_backups_row_'+timestamp).slideUp().remove();
					jQuery("#updraft-delete-modal").dialog('close');
					alert(resp.message);
				}
			}
		});
	};
	updraft_delete_modal_buttons[updraftlion.cancel] = function() { jQuery(this).dialog("close"); };
	jQuery( "#updraft-delete-modal" ).dialog({
		autoOpen: false, height: 230, width: 430, modal: true,
		buttons: updraft_delete_modal_buttons
	});

	var updraft_restore_modal_buttons = {};
	updraft_restore_modal_buttons[updraftlion.restore] = function() {
		var anyselected = 0;
		var whichselected = [];
		// Make a list of what files we want
		jQuery('input[name="updraft_restore[]"]').each(function(x,y){
			if (jQuery(y).is(':checked') && !jQuery(y).is(':disabled')) {
				anyselected = 1;
				var howmany = jQuery(y).data('howmany');
				var restobj = [ jQuery(y).val(), howmany ];
				whichselected.push(restobj);
				//alert(jQuery(y).val());
			}
		});
		if (anyselected == 1) {
			if (updraft_restore_stage == 1) {
				jQuery('#updraft-restore-modal-stage1').slideUp('slow');
				jQuery('#updraft-restore-modal-stage2').show();
				updraft_restore_stage = 2;
				var pretty_date = jQuery('.updraft_restore_date').first().text();
				// Create the downloader active widgets
				for (var i=0; i<whichselected.length; i++) {
					updraft_downloader('udrestoredlstatus_', jQuery('#updraft_restore_timestamp').val(), whichselected[i][0], '#ud_downloadstatus2', whichselected[i][1], pretty_date);
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
		jQuery(this).dialog("close");
		jQuery('#updraft_backup_started').html('<em>'+updraftlion.requeststart+'</em>').slideDown('');
		jQuery.post(ajaxurl, { action: 'updraft_ajax', subaction: 'backupnow', nonce: updraft_credentialtest_nonce }, function(response) {
			jQuery('#updraft_backup_started').html(response);
			// Kick off some activity to get WP to get the scheduled task moving as soon as possible
			setTimeout(function() {jQuery.get(updraft_siteurl);}, 5100);
			setTimeout(function() {jQuery.get(updraft_siteurl+'/wp-cron.php');}, 13500);
			//setTimeout(function() {updraft_showlastlog();}, 6000);
			setTimeout(function() {updraft_activejobs_update();}, 6000);
			setTimeout(function() {
				jQuery('#updraft_lastlogmessagerow').fadeOut('slow', function() {
					jQuery(this).fadeIn('slow');
				});
			},
			3200
				);
				setTimeout(function() {jQuery('#updraft_backup_started').fadeOut('slow');}, 60000);
				// Should be redundant (because of the polling for the last log line), but harmless (invokes page load)
		});
	};
	backupnow_modal_buttons[updraftlion.cancel] = function() { jQuery(this).dialog("close"); };
	
	jQuery("#updraft-backupnow-modal" ).dialog({
		autoOpen: false, height: 265, width: 390, modal: true,
		buttons: backupnow_modal_buttons
	});

	var migrate_modal_buttons = {};
	migrate_modal_buttons[updraftlion.close] = function() { jQuery(this).dialog("close"); };
	jQuery( "#updraft-migrate-modal" ).dialog({
		autoOpen: false, height: 265, width: 390, modal: true,
		buttons: migrate_modal_buttons
	});

	jQuery('#enableexpertmode').click(function() {
		jQuery('.expertmode').fadeIn();
		updraft_activejobs_update();
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

	jQuery.get(ajaxurl, { action: 'updraft_ajax', subaction: 'ping', nonce: updraft_credentialtest_nonce }, function(data, response) {
		if ('success' == response && data != 'pong' && data.indexOf('pong')>=0) {
			jQuery('#ud-whitespace-warning').show();
		}
	});

	// Section: Plupload

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
			alert(file.name+": "+updraftlion.notarchive);
			uploader.removeFile(file);
			return;
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
		if (response.response.substring(0,6) == 'ERROR:') {
			alert(updraftlion.uploaderror+" "+response.response.substring(6));
		} else if (response.response.substring(0,3) == 'OK:') {
			updraft_updatehistory(1);
		} else {
			alert('Unknown server response: '+response.response);
		}
	} else {
		alert('Unknown server response status: '+response.code);
	}

});
			
});

// Next: the encrypted database pluploader

jQuery(document).ready(function($){
	
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
			
			if (! /^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(gz\.crypt)$/i.test(file.name)) {
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
// 				$('#' + file.id + " .fileprogress").width("100%");
// 				$('#' + file.id + " span").append('<button type="button" onclick="updraftplus_downloadstage2(\'db\', \'db\'">Download to your computer</button>');
				// 				$('#' + file.id + " span").append('<form action="admin-ajax.php" onsubmit="return updraft_downloader(\'+bkey+''\', \'db\')" method="post"><input type="hidden" name="_wpnonce" value="'+updraft_downloader_nonce+'"><input type="hidden" name="action" value="updraft_download_backup" /><input type="hidden" name="type" value="db" /><input type="hidden" name="timestamp" value="'+bkey+'" /><input type="submit" value="Download" /></form>');
				$('#' + file.id + " .fileprogress").hide();
				$('#' + file.id).append(updraftlion.uploaded+' <a href="?page=updraftplus&action=downloadfile&updraftplus_file='+bkey+'&decrypt_key='+$('#updraftplus_db_decrypt').val()+'">'+updraftlion.followlink+'</a> '+updraftlion.thiskey+' '+$('#updraftplus_db_decrypt').val().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"));
			} else {
				alert(updraftlion.unknownresp+' '+response.response);
			}
		} else {
			alert(updraftlion.ukrespstatus+' '+response.code);
		}
		
	});

	jQuery('#updraft-hidethis').remove();

});
