<?php
return array (
	'h264'=>array (
		'prefix'=>'.h264',
		'width'=>FALSE,
		'vb'=>'4000k',
		'ab'=>'128k',
		'ac'=>2,
		'passed_streams'=>['h264','h.264','vp8','flv','mjpeg','jpeg','m4a','mp3','aac','ogg']
	),
	'720p'=>array (
		'prefix'=>'.720p',
		'width'=>1280,
		'vb'=>'2500k',
		'ab'=>'128k',
		'ac'=>2,
	),
	'360p'=>array (
		'prefix'=>'.360p',
		'width'=>640,
		'vb'=>'2000k',
		'ab'=>'96k',
		'ac'=>2,
	),
	'default'=>array (
		'prefix'=>'',
		'width'=>FALSE,
		'vb'=>'2000k',
		'ab'=>'96k',
		'ac'=>2,
		'preset'=>'medium',
	),			
);