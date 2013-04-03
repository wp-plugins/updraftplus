jQuery(document).ready(function($){

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
	
		if (! /^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+\.(zip|gz|gz\.crypt)$/i.test(file.name)) {
			alert(file.name+': This file is a zip, but does not appear to be an UpdraftPlus backup archive (such files are .zip files which have a name like: backup_(time)_(site name)_(code)_(type).zip)');
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
	
	alert('Upload error (code '+error.code+") : "+error.message+" (make sure that you were trying to upload a zip file previously created by UpdraftPlus)");
	
});


// a file was uploaded 
uploader.bind('FileUploaded', function(up, file, response) {
	
	if (response.status == '200') {
		// this is your ajax response, update the DOM with it or something...
		if (response.response.substring(0,6) == 'ERROR:') {
			alert("Upload error: "+response.response.substring(6));
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
				alert(file.name+': This file does not appear to be an UpdraftPlus encrypted database archive (such files are .gz.crypt files which have a name like: backup_(time)_(site name)_(code)_db.crypt.gz)');
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
		
		alert('Upload error (code '+error.code+") : "+error.message+" (make sure that you were trying to upload a backup file previously created by UpdraftPlus)");
		
	});
	
	// a file was uploaded 
	uploader.bind('FileUploaded', function(up, file, response) {
		
		if (response.status == '200') {
			// this is your ajax response, update the DOM with it or something...
			if (response.response.substring(0,6) == 'ERROR:') {
				alert("Upload error: "+response.response.substring(6));
			} else if (response.response.substring(0,3) == 'OK:') {
				bkey = response.response.substring(3);
// 				$('#' + file.id + " .fileprogress").width("100%");
// 				$('#' + file.id + " span").append('<button type="button" onclick="updraftplus_downloadstage2(\'db\', \'db\'">Download to your computer</button>');
				// 				$('#' + file.id + " span").append('<form action="admin-ajax.php" onsubmit="return updraft_downloader(\'+bkey+''\', \'db\')" method="post"><input type="hidden" name="_wpnonce" value="'+updraft_downloader_nonce+'"><input type="hidden" name="action" value="updraft_download_backup" /><input type="hidden" name="type" value="db" /><input type="hidden" name="timestamp" value="'+bkey+'" /><input type="submit" value="Download" /></form>');
				$('#' + file.id + " .fileprogress").hide();
				$('#' + file.id).append('The file was uploaded. <a href="?page=updraftplus&action=downloadfile&updraftplus_file='+bkey+'&decrypt_key='+$('#updraftplus_db_decrypt').val()+'">Follow this link to attempt decryption and download the database file to your computer.</a> This decryption key will be attempted: '+$('#updraftplus_db_decrypt').val().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"));
			} else {
				alert('Unknown server response: '+response.response);
			}
		} else {
			alert('Unknown server response status: '+response.code);
		}
		
	});
	
});   