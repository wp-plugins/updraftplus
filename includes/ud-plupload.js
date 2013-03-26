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
		
		}else{
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