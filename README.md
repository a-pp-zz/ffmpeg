# FFmpeg
FFmpeg php wrapper

## Install
composer require appzz/ffmpeg

## Usage
### Init
```php
use AppZz\VideoTools\FFmpeg;
require vendor/autoload.php;

/**
If needed specify path to local binary files
**/
FFmpeg::$binary = '/usr/local/bin/ffmpeg';
```
### Transcode file
```php
$ff = FFmpeg::factory('video.mkv');

//set output dir
$ff->set('output_dir', 'pathto/finished')

//set mapping params
$ff->set('mapping', 'video', ['count'=>10])
$ff->set('mapping', 'audio', ['count'=>10])
$ff->set('mapping', 'subtitle', ['count'=>10])

//set codec params
$ff->set('vcodec', 'h264', ['b'=>'2000k', 'crf'=>FALSE, 'profile'=>'fast'])
$ff->set('acodec', 'aac', ['b'=>'128k', 'ac'=>2, 'ar'=>48000])
$ff->set('scodec', 'copy')

//set output format
$ff->format('mp4');
$ff->prepare();
$result = $ff->run();
```
