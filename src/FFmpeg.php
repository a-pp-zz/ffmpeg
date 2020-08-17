<?php
namespace AppZz\VideoConverter;

use AppZz\Helpers\Arr;
use AppZz\VideoConverter\Exceptions\FFmpegException;
use AppZz\Helpers\Filesystem;
use AppZz\CLI\Process;

/**
 * @package FFmpeg
 * @version 1.3.5
 * @author CoolSwitcher
 * @license MIT
 * @link https://github.com/a-pp-zz/video-converter
 */
class FFmpeg {

	/**
	 * pathto to ffmpeg-bin
	 * @var string
	 */
	public static $binary = 'ffmpeg';

	/**
	 * pathto input file
	 * @var string
	 */
	private $_input;

	/**
	 * pathto output file
	 * @var string
	 */
	private $_output;

	/**
	 * Trigger for get process stat
	 * @var Closure
	 */
	private $_trigger;

	/**
	 * Metadata of current file
	 * @var array
	 */
	private $_metadata;

	/**
	 * default params
	 * @var array
	 */
	private $_default_params = [
		'debug'        =>FALSE,
		'log_dir'      =>FALSE,
		'output_dir'   =>FALSE,
		'ovewrite'     =>FALSE,
		'experimental' =>TRUE,
		'passthrough'  =>FALSE,
		'vb'           =>'2000k',
		'fps'          =>0,
		'format'       =>NULL,
		'ab'           =>'96k',
		'ar'           =>'44100',
		'ac'           =>2,
		'pix_fmt'      =>'yuv420p',
		'metadata'     =>FALSE,
		'streams'      =>FALSE,
		'loglevel'     =>'info',
		'extra'        =>'',
		'deint'        =>0
	];

	private $_params = [];

	/**
	 * allowed setters
	 * @var array
	 */
	private $_allowed_setters = [
		'vcodec', 'acodec', 'scodec', 'mapping', 'vn', 'an', 'sn',
		'width', 'size', 'fps', 'format', 'vframes',
		'vb', 'ab', 'ar', 'ac', 'to', 'ss', 'ss_input', 'ss_output',
		'experimental', 'output_dir', 'overwrite', 'crf', 'preset',
		'pix_fmt', 'vn', 'an', 'sn', 'extra', 'watermark', 'scale',
		'passthrough', 'prefix', 'metadata', 'vf', 'deint'
	];

	/**
	 * logfile resource
	 * @var resource
	 */
	private $_logfile;

	/**
	 * Formatted cli command to execute
	 * @var string
	 */
	private $_cmd;

	/**
	 * Info holder
	 * @var array
	 */
	private $_info = [];

	public function __construct ($input = NULL)
	{
		if ($input) {
			$this->input ($input);
		}

		$this->_params = $this->_default_params;
	}

	public static function factory ($input = NULL)
	{
		return new FFmpeg ($input);
	}

	/**
	 * set trigger
	 * @param  \Closure $trigger
	 * @return $this
	 */
	public function trigger (\Closure $trigger)
	{
		$this->_trigger = $trigger;
		return $this;
	}

	/**
	 * get param
	 * @param  string $param
	 * @return mixed
	 */
	public function get ($param, $default = FALSE)
	{
		return Arr::get ($this->_params, $param, $default);
	}

	/**
	 * Get fullpath to output
	 * @return string
	 */
	public function get_output ()
	{
		return $this->_output;
	}

	/**
	 * get metadata
	 * @return mixed
	 */
	public function get_metadata ()
	{
		return $this->_metadata;
	}

	public function __toString()
	{
		return $this->_cmd;
	}

	/**
	 * Get info of encoding params
	 * @param  mixed $stream_type
	 * @return FFmpeg
	 */
	public function get_info ($stream_type = NULL)
	{
		if ($stream_type) {
			return Arr::get($this->_info, $stream_type);
		}

		return $this->_info;
	}

	/**
	 * set params
	 * @param string $param param key
	 * @param mixed $value
	 * @param mixed $extra
	 */
	public function set ($param, $value = '', $extra = NULL)
	{
		if ( ! $this->_input)
			throw new FFmpegException ('No input file!');

		if ( ! empty ($param)) {
			if (in_array($param, $this->_allowed_setters)) {

				switch ($param)
				{
					case 'watermark':
						$value = $this->_set_watermark($value, $extra);
					break;

					case 'ss':
						$param .= $extra ? '_input' : '_output';
						$value = $this->_check_time($value);
					break;

					case 'to':
						$value = $this->_check_time($value);
					break;

					case 'width':
						$this->_set_size($value, $extra);
						$param = NULL;
					break;

					case 'vcodec':
						$this->_set_video_params ($extra);
					break;

					case 'acodec':
						$this->_set_audio_params ($extra);
					break;

					case 'mapping':
						$this->_set_mapping($value, $extra);
						$param = NULL;
					break;

					case 'vf':
						$this->_set_vf($value, $extra);
						$param = NULL;
					break;
				}

				$this->_set($param, $value);
			}
			else {
				throw new FFmpegException ('Setting param ' .$param. ' not allowed');
			}
		}

		return $this;
	}

	/**
	 * Fullpath to input file
	 * @param  string $filename
	 * @return $this
	 */
	public function input ($filename)
	{
		if ( ! empty ($filename) AND is_file ($filename)) {
			$this->_input = $filename;

			$this->_metadata = FFprobe::factory($this->_input)->probe (TRUE);

			if (empty ($this->_metadata))
				throw new FFmpegException ('Error get metadata from file!');

			unset ($this->_output);
			return $this;
		}
		else {
			throw new FFmpegException ('Input file '.$filename.' not exists');
		}
	}

	/**
	 * Relative filename of output file
	 * @param  string $filename
	 * @return $this
	 */
	public function output ($filename)
	{
		if ( ! empty ($filename)) {
			$this->_output = $filename;
		}

		return $this;
	}

	/**
	 * Trim video
	 * @param  mixed $start
	 * @param  mixed $end
	 * @return FFmpeg
	 */
	public function trim ($start, $end)
	{
		$this->set ('ss', $start, FALSE);
		$this->set ('to', $end);
		return $this;
	}

	/**
	 * Screenshot params
	 * @param  mixed $ss float<1|time|second
	 * @param  integer $size
	 * @return FFmpeg
	 */
	public function screenshot ($ss = 0, $size = 0)
	{
		if (is_numeric ($ss) AND $ss < 1 AND $ss > 0)
		{
			$ss = Arr::get($this->_metadata, 'duration') * $ss;
		}

		$extra = '-t 0.0001';

		$this->_params = $this->_default_params;

		if (intval ($size) > 0) {
			$this->set('scale', $size . ':-1');
		}

		$this->set('an', TRUE);
		$this->set('format', 'jpg');
		$this->set('ss', $ss, TRUE);
		$this->set('vframes', 1);
		$this->set('extra', $extra);
		$this->_set_loglevel('quiet');

		return $this;
	}

	/**
	 * Set debug params
	 * @param  boolean $enabled
	 * @param  string  $log_dir
	 * @param  boolean $delete  delete if success
	 * @return FFmpeg
	 */
	public function debug ($enabled = FALSE, $log_dir = NULL, $delete = TRUE)
	{
		$this->_set ('debug', $enabled);
		$this->_set_loglevel('debug');
		$this->_set_log_file($log_dir, $delete);
		return $this;
	}

	/**
	 * Prepare params
	 * @return FFmpeg
	 */
	public function prepare ()
	{
		if ( ! $this->_input) {
			throw new FFmpegException ('No input file!');
		}

		$this->_set_output_by_format ();

		$streams = Arr::get($this->_metadata, 'streams');

		if (empty ($streams)) {
			throw new FFmpegException ('Streams not found in file!');
		}

		$params_cli = new \stdClass;

		$passed   = (array) $this->get ('passthrough');
		$mapping  = $this->get ('mapping');
		$langs    = $this->get ('langs');
		$width    = $this->get ('width');
		$crf      = $this->get ('crf');
		$preset   = $this->get ('preset');
		$fps      = $this->get ('fps');
		$ss_i     = $this->get ('ss_input');
		$ss_o     = $this->get ('ss_output');
		$to       = $this->get ('to');
		$vframes  = $this->get ('vframes');
		$loglevel = $this->get ('loglevel');
		$metadata = $this->get ('metadata');
		$fmt      = $this->get('format');
		$size     = $this->get ('size');
		$scale    = $this->get ('scale');
		$vn       = $this->get ('vn', FALSE);
		$an       = $this->get ('an', FALSE);
		$sn       = $this->get ('sn', FALSE);
		$extra    = $this->get('extra');
		$deint    = $this->get('deint');

		$duration_human = Arr::get($this->_metadata, 'duration_human');
		$is_interlaced = Arr::get($this->_metadata, 'is_interlaced');

		if ($deint AND $is_interlaced) {
			$this->set('vf', 'yadif', 1);
		}

		$vf = $this->get('vf');

		$vc_active = $ac_active = FALSE;

		if ( ! $fps) {
			$fps = Arr::get ($this->_metadata, 'fps', 30);
		}

		if ($vframes) {
			$params_cli->transcode[] = sprintf ('-vframes %d', $vframes);
		}

		if ($loglevel) {
			$params_cli->input[] = "-loglevel {$loglevel}";
		}

		if ($ss_i !== FALSE) {
			$params_cli->input[] = "-ss {$ss_i}";
		}

		$params_cli->input[] = sprintf ("-i %s", escapeshellarg ($this->_input));

		if ($this->get('vcodec') != 'copy' AND ($watermark = $this->get('watermark')) !== FALSE) {
			$params_cli->input[]  = sprintf ("-i %s", escapeshellarg ($watermark->file) );
			$params_cli->filter[] = sprintf ("%s", $watermark->overlay);
			$params_cli->map[]    = '-map 1';
		}

		if (empty($mapping)) {
			$mapping = [
				'video'    => ['count'=>1, 'langs'=>[]],
				'audio'    => ['count'=>1, 'langs'=>[]],
				'subtitle' => ['count'=>1, 'langs'=>[]]
			];
		}

		if ($vn) {
			$mapping['video']['count'] = 0;
		}

		if ($an) {
			$mapping['audio']['count'] = 0;
		}

		if ($sn) {
			$mapping['subtitle']['count'] = 0;
		}

		if ($metadata == 'copy') {
			$params_cli->map[] = '-map_metadata 0';
		} else {
			$params_cli->map[] = '-map_metadata -1';
		}

		foreach ($streams as $stream_type=>$stream) {

			$stream_counter = 0;

			$cur_stream_langs = (array) Arr::path($mapping, $stream_type . '.langs', '.', []);
			$cur_stream_count = Arr::path($mapping, $stream_type . '.count', '.', 0);
			$cur_stream_index = (int) Arr::path($mapping, $stream_type . '.index', '.', -2);

			if ( ! isset ($mapping[$stream_type])) {
				continue;
			}

			$stream_type_alpha = substr ($stream_type, 0, 1);
			$def_codec  = $this->get ($stream_type_alpha.'codec', 'copy');

			$def_bitrate = $this->get($stream_type_alpha . 'b');

			if (is_numeric($def_bitrate)) {
				$def_bitrate = $this->_human_bitrate($def_bitrate);
			}

			if ($cur_stream_count > 0) {
				foreach ($stream as $index=>$stream_data) {

					if ($stream_counter === $cur_stream_count) {
						break;
					}

					$stream_index = (int) Arr::get($stream_data, 'index', -1);

					if ($cur_stream_index >=0 AND ($stream_index != $cur_stream_index)) {
						continue;
					}

					$stream_lang = Arr::get($stream_data, 'language');

					if ( ! $stream_lang OR $stream_lang == 'unk') {
						$stream_lang = 'eng';
					}

					$stream_title = Arr::get($stream_data, 'title', '');

					if ( ! in_array (Arr::get ($stream_data, 'codec_name'), $passed)) {
						$codec      = $def_codec;
						$bitrate    = $def_bitrate;
						$codec_name = $codec;
					}
					else {
						$codec = 'copy';
					}

					if ($codec == 'copy') {
						$bitrate = Arr::get($stream_data, 'bit_rate', 0);

						if ($bitrate AND is_numeric($bitrate)) {
							$bitrate = $this->_human_bitrate($bitrate);
						}

						$codec_name = Arr::get($stream_data, 'codec_name', '');
					}

					/*
					if ($stream_type == 'subtitle') {
						$stream_title_new = $stream_title;
					}
					else {
						if ($bitrate) {
							$stream_title_new = sprintf ('%s [%s @ %s]', mb_convert_case ($full_lang, MB_CASE_TITLE), $codec_name, $bitrate);
						} else {
							$stream_title_new = sprintf ('%s @ %s', mb_convert_case ($full_lang, MB_CASE_TITLE), $codec_name);
						}
					}
					*/

					$params_cli->transcode[] = sprintf ('-c:%s:%d %s', $stream_type_alpha, $stream_counter, $codec);

					if ($metadata === TRUE) {
						$params_cli->transcode[] = sprintf ('-metadata:s:%s:%d title="%s" -metadata:s:%s:%d language="%s"', $stream_type_alpha, $stream_counter, escapeshellarg($stream_title), $stream_type_alpha, $stream_counter, $stream_lang);
					}

					$add_map_stream = TRUE;

					if ( ! empty ($cur_stream_langs) AND ! empty ($stream_lang)) {
						if ( ! in_array($stream_lang, $cur_stream_langs)) {
							$add_map_stream = FALSE;
							array_pop ($params_cli->transcode);
						}
					}

					if ($add_map_stream) {
						$params_cli->map[] = sprintf('-map 0:%d', $stream_index);
						$stream_counter++;
						$bitrate = ($stream_type == 'video' AND $crf) ? 'crf ' . $crf : $bitrate;
						$this->_set_info ($stream_type, $index, $codec, $bitrate, $duration_human);
					}

					if ($stream_type == 'audio' AND ! $ac_active AND $codec != 'copy') {
						$ac_active = TRUE;
					}
					elseif ($stream_type == 'video' AND ! $vc_active AND $codec != 'copy') {
						$vc_active = TRUE;
					}
				}
			} else {
				switch ($stream_type) {
					case 'video':
						$vn = TRUE;
					break;

					case 'audio':
						$an = TRUE;
					break;

					case 'subtitle':
						$sn = TRUE;
					break;
				}
			}
		}

		if ($vn) {
			$params_cli->transcode[] = '-vn';
		}

		if ($an) {
			$params_cli->transcode[] = '-an';
		}

		if ($sn) {
			$params_cli->transcode[] = '-sn';
		}

		if ($vc_active) {

			if (($pix_fmt = $this->get('pix_fmt'))) {
				$params_cli->transcode[] = sprintf ('-pix_fmt %s', $pix_fmt);
			}

			if ($crf) {
				$params_cli->transcode[] = sprintf ('-crf %d', $crf);
			}
			else {
				$params_cli->transcode[] = sprintf ('-b:v %s', $this->get ('vb'));
			}

			if ($preset) {
				$params_cli->transcode[] = sprintf ('-preset %s', $preset);
			}

			if ($fps) {
				$params_cli->transcode[] = sprintf ('-r %d', $fps);
			}

			if ($size) {
				$params_cli->transcode[] = sprintf ('-s %s', $size);
			}

			if ($scale) {
				$params_cli->filter[] = 'scale=' . $scale . ':flags=bicubic';
			}

			if ($vf) {
				foreach ($vf as $value) :
					$params_cli->filter[] = $value;
				endforeach;
				unset ($value);
			}

			if ($this->get ('faststart')) {
				$params_cli->output[] = '-movflags faststart';
			}
		}

		if ( ! empty ($params_cli->filter)) {
			$params_cli->filter = sprintf ('-filter_complex %s', escapeshellarg(implode (',', array_reverse($params_cli->filter))));
		}

		if ($ac_active) {
			$params_cli->transcode[] = sprintf ("-b:a %s -ar %d -ac %d", $this->get ('ab'), $this->get ('ar'), $this->get ('ac'));
		}

		if ($this->get ('overwrite')) {
			$params_cli->output[] = '-y';
		}

		if ($this->get ('experimental')) {
			$params_cli->output[] = '-strict experimental';
		}

		if ($extra) {
			$params_cli->transcode[] = $extra;
		}

		if ($ss_o !== FALSE) {
			$params_cli->output[] = "-ss {$ss_o}";
		}

		if ($to) {
			$params_cli->output[] = "-to {$to}";
		}

		$params_cli->output[] = escapeshellarg ($this->_output);

		$params = [];

		if ( ! empty ($params_cli->input)) {
			$params[] = implode (' ', $params_cli->input);
		}

		if ( ! empty ($params_cli->map)) {
			$params[] = implode (' ', $params_cli->map);
		}

		if ( ! empty ($params_cli->filter)) {
			$params[] = trim ($params_cli->filter);
		}

		if ( ! empty ($params_cli->transcode)) {
			$params[] = implode (' ', $params_cli->transcode);
		}

		if ( ! empty ($params_cli->output)) {
			$params[] = implode (' ', $params_cli->output);
		}

		$this->_cmd = FFmpeg::$binary . ' ' . implode (' ', $params);

		return $this;
	}

	/**
	 * Run transcoding
	 * @return bool
	 */
	public function run ($progress = FALSE)
	{
		if (empty($this->_cmd)) {
			throw new FFmpegException ('Empty cmdline');
		}

		if ($this->_logfile) {
			fputs ($this->_logfile->handle,  $this->_cmd . "\n\n");
		}

		if ($progress) {
			$this->_call_trigger($this->get_info(), 'start');

			$process = Process::factory($this->_cmd, Process::STDERR)
							->trigger('all', array($this, 'get_progress'))
							->run();

			$exitcode = $process->get_exitcode();

			if ($this->_logfile AND $this->_logfile->handle) {
				fclose ($this->_logfile->handle);
			}

			if ($exitcode === 0) {
				$this->_call_trigger('Finished', 'finish');
			}
			else {
				$this->_call_trigger('Error', 'error');
			}
		}
		else {

			if ($this->_logfile AND $this->_logfile->path) {
				$this->_cmd . ' ' . sprintf ('2> %s', escapeshellarg($this->_logfile->path));
			}

			system ($this->_cmd, $exitcode);
			$exitcode = intval ($exitcode);
		}

		if ($exitcode === 0 AND $this->_logfile AND $this->_logfile->path AND $this->_logfile->delete) {
			@unlink ($this->_logfile->path);
		}

		$this->_params = $this->_default_params;

		return ($exitcode === 0);
	}

	/**
	 * Get output from pipe, calc progress and call trigger
	 * @param  mixed $data
	 * @return bool
	 */
	public function get_progress ($data)
	{
		$duration = Arr::get($this->_metadata, 'duration', 0);

		$ss_i = $this->get('ss_input');
		$ss_o = $this->get('ss_output');
		$to = $this->get('to');

		if ($to AND $ss_o) {
			$duration = $this->_time_to_seconds ($to) - $this->_time_to_seconds ($ss_o);
		} elseif ($ss_i) {
			$duration -= $this->_time_to_seconds ($ss_i);
		}

		$buffer = Arr::get($data, 'buffer');
		$message = Arr::get($data, 'message');

		if ($buffer AND $this->_logfile) {
			fputs ($this->_logfile->handle,  implode ("\n", $buffer) . "\n");
		}

		if (empty($message)) {
			return FALSE;
		}

		if (preg_match("/time=([\d:\.]*)/", $message, $m) ) {
			$current_duration = Arr::get($m, 1, '0:0:0');

			if (empty($current_duration))
				return 0;

			$time = $this->_time_to_seconds ($current_duration);

			$progress  = $time / max ($duration, 0.01);
			$progress  = (int) ($progress * 100);
			$this->_call_trigger($progress, 'progress');
		}

		return TRUE;
	}

	/**
	 * Screenshot via static method
	 * @param  string  $input
	 * @param  mixed $ss
	 * @param  integer $width
	 * @param  mixed $output
	 * @param  mixed $output_dir
	 * @return bool
	 */
	public static function take_screenshot ($input, $ss = 0, $width = 0, $output = FALSE, $output_dir = FALSE)
	{
		$f = FFmpeg::factory()
				->input($input)
				->set('overwrite', TRUE);

		if ($output) {
			$f->output($output);
		}

		if ($output_dir) {
			$f->set('output_dir', $output_dir);
		}

		$f->screenshot($ss, $width);
		$f->prepare();

		return $f->run(FALSE);
	}

	/**
	 * Merge files to the one with same codec
	 * Need some works for checking, now only basic usage
	 * @param  array  $files
	 * @param  string $output
	 * @return bool
	 */
	public static function concat (array $files, $output)
	{
		$input = tempnam(sys_get_temp_dir(), 'ffmpeg_cc');
		$fh = fopen ($input, 'wb');

		foreach ($files as $file) {
			$file = escapeshellarg($file);
			$file = str_replace (['"', '\\'], ["'", '/'], $file);
			$line = sprintf ("file %s\n", $file);
			fwrite ($fh, $line);
		}

		fclose ($fh);

		$cmd = sprintf ('%s -loglevel quiet -f concat -safe 0 -i %s -c copy -y %s', FFmpeg::$binary, escapeshellarg ($input), escapeshellarg($output));

		system ($cmd, $retval);
		@unlink ($input);

		if (intval($retval) === 0) {
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Extract audio from video
	 * @param  string  $file
	 * @param  array   $params
	 * @param  closure $callback
	 * @return mixed
	 */
	public static function extract_audio ($file, $params = [], \Closure $callback)
	{
		$metadata = FFprobe::factory($file)->probe(TRUE);
		$metadata = Arr::path($metadata, 'streams.audio');

		if ( ! $metadata) {
			return FALSE;
		}

		foreach ($metadata as $values) {
			$index = Arr::get ($values, 'index', FALSE);

			if ($index === FALSE) {
				continue;
			}

			$ff = FFmpeg::factory($file)
				 ->set('mapping', 'audio', ['count'=>1, 'index'=>$index])
				 ->set('acodec', Arr::get($params, 'codec', 'aac'), ['b'=>Arr::get($params, 'b', '128k'), 'ac'=>Arr::get($params, 'ac', 2), 'ar'=>Arr::get($params, 'ar', 44100)])
				 ->set('overwrite', TRUE)
				 ->set('metadata', TRUE)
				 ->set('prefix', '-stream-'.$index)
				 ->set('format', Arr::get($params, 'format', 'm4a'))
				 ->set('sn', TRUE)
				 ->set('vn', TRUE)
				 ->prepare();

			if ($callback) {
				$ff->trigger ($callback);
				$progress = TRUE;
			} else {
				$progress = FALSE;
			}

			$result = $ff->run ($progress);
		}
	}

	private function _set ($param, $value = FALSE)
	{
		if ($param) {
			$this->_params[$param] = $value;
		}

		return $this;
	}

	private function _set_vf ($filter, $value = 0)
	{
		if ($filter) {
			$this->_params['vf'][] = sprintf ('%s=%s', $filter, $value);
		}

		return $this;
	}

	private function _set_info ($stream_type, $stream_index = 0, $codec, $bitrate = 0, $duration = '00:00:00')
	{
		$codec_ori = Arr::path($this->_metadata, 'streams.' . $stream_type . '.' . $stream_index . '.codec_name');

		if ( ! $codec_ori) {
			return FALSE;
		}

		if ($stream_type == 'video') {
			$info_text_tpl = '%dx%d %s [%s] => %s %s';
			$width = Arr::get($this->_metadata, 'width', 0);
			$height = Arr::get($this->_metadata, 'height', 0);
			$size = $this->get('size');

			if (empty($size)) {
				$size = sprintf ('%dx%d', $width, $height);
			}

			$info_text = ($codec == 'copy') ? trim(sprintf ($info_text_tpl, $width, $height, $codec_ori, $codec, '')) : sprintf ($info_text_tpl, $width, $height, $codec_ori, $duration, $size, $codec);
		} else {
			$info_text_tpl = '%s => %s';
			$info_text = sprintf ($info_text_tpl, $codec_ori, $codec);
		}

		if ($codec != 'copy' AND $bitrate) {
			$info_text .= ' @ ' . $bitrate;
		}

		$this->_info[$stream_type][$stream_index] = $info_text;
	}

	private function _set_output ()
	{
		if ( empty ($this->_input))
			throw new FFmpegException ('Input file not exists');

		$output_dir = $this->get('output_dir');

		if (empty ($output_dir)) {
			$output_dir = dirname ($this->_input);
		}

		if ( ! is_writable($output_dir)) {
			throw new FFmpegException ($output_dir . ' is not writeable');
		}

		if (empty($this->_output)) {
			$this->_output = $this->_input;
		}

		$this->_output = $output_dir . DIRECTORY_SEPARATOR . basename ($this->_output);
	}

	private function _set_output_by_format ()
	{
		$this->_set_output();
		$format = $this->get('format');

		if ($format) {
			$this->_output = Filesystem::new_extension ($this->_output, $format);
		}

		$prefix = $this->get('prefix');

		if ( ! empty ($prefix))
		{
			$this->_output = Filesystem::add_prefix ($this->_output, $prefix);
		}

		if ($this->get('overwrite') !== TRUE OR $this->_output == $this->_input)
		{
			$this->_output = Filesystem::check_filename ($this->_output);
		}

		if (file_exists($this->_output) AND ! is_writable($this->_output)) {
			throw new FFmpegException ($this->_output . ' is not writeable');
		}

		return $this;
	}

	private function _set_log_file ($log_dir, $delete = TRUE)
	{
		if ($log_dir)
		{
			if ( ! is_dir ($log_dir)) {
				throw new FFmpegException ('Logdir not exists');
			}

			if ( ! is_writable($log_dir)) {
				throw new FFmpegException ('Logdir not writeable');
			}

			$logfile = $log_dir . DIRECTORY_SEPARATOR . pathinfo ($this->_input, PATHINFO_FILENAME)  . '.log';
			$this->_logfile = new \stdClass;
			$this->_logfile->handle = fopen ($logfile, 'wb');
			$this->_logfile->path = $logfile;
			$this->_logfile->delete = $delete;
			return $this;
		}
	}

	/**
	 * set watermark param
	 * @param  string $file
	 * @param  array  $params
	 * @return Object
	 */
	private function _set_watermark ($file, array $params = [])
	{
		$m0      = (int) Arr::path($params, 'margins.0');
		$m1      = (int) Arr::path($params, 'margins.1');
		$align   = Arr::get($params, 'align', 'bottom-right');
		$overlay = 'overlay=';

		switch ($align)
		{
			case 'top-left':
				$overlay .= abs ($m0) . ':' . abs ($m1);
			break;

			case 'top-right':
				$overlay .= 'main_w-overlay_w-' . $m0 . ':' . abs ($m1);
			break;

			case 'bottom-left':
				$overlay .= abs ($m0) . ':' . 'main_h-overlay_h-' . abs ($m1);
			break;

			case 'bottom-right':
			default:
				$overlay .= 'main_w-overlay_w-' . abs ($m0) . ':' . 'main_h-overlay_h-' . abs ($m1);
			break;
		}

		$wm = new \stdClass;
		$wm->file = $file;
		$wm->overlay = $overlay;
		return $wm;
	}

	/**
	 * set size and scale params
	 * @param boolean $dar [description]
	 */
	private function _set_size ($width, $dar = TRUE)
	{
		if ( ! $width) {
			return $this;
		}

		if ($dar === FALSE) {
			$this->_params['scale'] = sprintf ('%d:-1', $width);
			return $this;
		}

		$width_meta  = (int) Arr::get ($this->_metadata, 'width', 0);
		$height_meta = (int) Arr::get ($this->_metadata, 'height', 0);
		$dar         = Arr::get ($this->_metadata, 'dar_num');

		if ( ! $dar) {
			$dar = $width_meta / $height_meta;
		}

		$size = NULL;

		if ($width == 1280 AND $width_meta >= $width) {
			$size = 'hd720';
		} elseif ($width == 1920 AND $width_meta >= $width) {
			$size = 'hd1080';
		} elseif ($dar) {
			$width  = min (array($width, $width_meta));
			$height = intval ($width / $dar);

			if ($height%2 !== 0) {
				$height++;
			}

			$size = sprintf ('%dx%d', $width, $height);
		}

		$this->_params['size'] = $this->_params['scale'] = $size;
		return $this;
	}

	private function _set_mapping ($type = 'video', array $params)
	{
		$default = array ('count' => -1, 'langs' => []);
		$this->_params['mapping'][$type] = array_merge ($default, $params);
		return $this;
	}

	private function _set_video_params ($params = [])
	{
		$params = (array) $params;
		$vb  = Arr::get($params, 'b', '2000k');
		$crf = Arr::get($params, 'crf');
		$preset = Arr::get($params, 'preset');
		$pix_fmt = Arr::get($params, 'pix_fmt');

		if ($crf) {
			$this->set('crf', $crf);
		} elseif ($vb) {
			$this->set('vb', $vb);
		}

		if ($preset) {
			$this->set('preset', $preset);
		}

		if ($pix_fmt) {
			$this->set('pix_fmt', $pix_fmt);
		}

		return $this;
 	}

	private function _set_audio_params ($params = [])
	{
		$params = (array) $params;
		$ab  = Arr::get($params, 'b', '96k');
		$ac  = Arr::get($params, 'ac', 2);
		$ar  = Arr::get($params, 'ar', 44100);

		$this->set('ab', $ab);
		$this->set('ar', $ar);
		$this->set('ac', $ac);

		return $this;
 	}

	private function _call_trigger ($message = '', $action = 'message')
	{
		if ($this->_trigger) {

			$data = [
				'action'  =>$action,
				'message' =>$message,
				'input'   =>$this->_input,
				'output'  =>$this->_output,
			];

			if ($action == 'finish') {
				$metadata = FFprobe::factory($this->_output)->probe(TRUE);
				$data['i_duration'] = Arr::get ($this->_metadata, 'duration');
				$data['i_duration_human'] = Arr::get ($this->_metadata, 'duration_human');
				$data['o_duration'] = Arr::get ($metadata, 'duration');
				$data['o_duration_human'] = Arr::get ($metadata, 'duration_human');
			}

			call_user_func($this->_trigger, $data);
		}
	}

	private function _human_bitrate ($bytes, $decimals = 0)
	{
	    $size = ['bps','kbps','mbps'];
	    $factor = floor((strlen($bytes) - 1) / 3);
	    return sprintf("%.{$decimals}f", $bytes / pow(1000, $factor)) . ' ' . Arr::get($size, $factor);
	}

	private function _set_loglevel ($loglevel)
	{
        $allowed = [
			'quiet',
			'panic',
			'fatal',
			'error',
			'warning',
			'info',
			'verbose',
			'debug',
			'trace',
        ];

        if ( ! in_array($loglevel, $allowed)) {
        	throw new FFmpegException ('Wrong leglevel param');
        }

        $this->_set ('loglevel', $loglevel);

        return $this;
	}

	private function _time_to_seconds ($time)
	{
		if (is_numeric($time))
			return $time;

		$parts = explode(':', $time);

		if ( ! $parts)
			return 0;

		$h = (int)Arr::get ($parts, 0, 0);
		$m = (int)Arr::get ($parts, 1, 0);
		$s = (int)Arr::get ($parts, 2, 0);

		return $h * 3600 + $m * 60 + $s;
	}

	private function _check_time ($time)
	{
		if (is_numeric($time)) {
			return $time;
		}
		elseif (preg_match ('#^(\d{1,2}:)?\d{1,2}:?\d{1,2}$#iu', $time)) {
			return escapeshellarg($time);
		}
		else {
			throw new FFmpegException ('Wrong time param: ' . $time);
		}
	}
}
