<?php
namespace AppZz\VideoConverter;

use AppZz\Helpers\Arr;
use AppZz\VideoConverter\Exceptions\FFmpegException;
use AppZz\Helpers\Filesystem;
use AppZz\CLI\Process;

/**
 * @package FFmpeg
 * @version 1.2.2
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
	private $_params = array (
		'debug'          =>FALSE,
		'log_dir'        =>FALSE,
		'output_dir'     =>FALSE,
		'ovewrite'       =>FALSE,
		'experimental'   =>TRUE,
		'passed_streams' =>FALSE,
		'vb'             =>'2000k',
		'fps'            =>0,
		'format'         =>NULL,
		'ab'             =>'96k',
		'ar'             =>'44100',
		'ac'             =>2,
		'pix_fmt'        =>'yuv420p',
		'langs'          =>FALSE,
		'metadata'       =>FALSE,
		'streams'        =>FALSE,
		'loglevel'       =>FALSE,
		'extra'          =>'',
		'langs'          => [
			'rus'            => 'Русский',
			'eng'            => 'Английский',
		]
	);

	/**
	 * allowed setters for magic method __call
	 * @var array
	 */
	private $_allowed_setters = array (
		'vcodec', 'acodec', 'scodec',
		'width', 'size', 'fps', 'format', 'vframes',
		'vb', 'ab', 'ar', 'ac', 'to',
		'experimental', 'debug', 'log_dir', 'streams',
		'output_dir', 'overwrite', 'crf', 'preset',
		'pix_fmt', 'progress', 'streams', 'extra',
		'passed_streams', 'prefix', 'langs', 'metadata'
	);

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
	 * @var boolean
	 */
	private $_info = [];

	public function __construct ($input = NULL)
	{
		if ($input)
		{
			$this->input ($input);
		}
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
	public function get_param ($param, $default = FALSE)
	{
		return Arr::get ($this->_params, $param, $default);
	}

	public function set_stream ($type = 'video', $count = 1, $langs = [])
	{
		$this->_params['streams'][$type] = array ('count'=>intval ($count), 'langs'=>(array) $langs);
		return $this;
	}

	/**
	 * magic method for setters, eg set_vcodec, set_ab, etc
	 * @param  string $method
	 * @param  array $params
	 * @return $this
	 */
	public function __call ($method, $params)
	{
		if (strpos($method, 'set_') !== FALSE) {
			$param = str_replace('set_', '', $method);
			$value = reset ($params);

			if (in_array($param, $this->_allowed_setters)) {
				$this->_set_param($param, $value);
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
	public function output ($filename, $start = FALSE, $end = FALSE)
	{
		if ( ! empty ($filename))
			$this->_output = $filename;
		return $this;
	}

	public function loglevel ($loglevel)
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

        if (in_array($loglevel, $allowed)) {
        	$this->_set_param ('loglevel', $loglevel);
        }
        else {
        	throw new FFmpegException ('Wrong leglevel param');
        }

        return $this;
	}

	/**
	 * Set watermark param
	 * @param  string $file
	 * @param  string $align   bottom-right|bottom-left|top-right|top-left
	 * @param  array  $margins
	 * @return FFmpeg
	 */
	public function watermark ($file = NULL, $align = 'bottom-right', $margins = array (0, 0))
	{
		$margins = (array) $margins;
		$m0 = Arr::get($margins, 0, 0);
		$m1 = Arr::get($margins, 1, 0);
		$overlay = 'overlay=';

		switch ($align) {
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
		$this->_set_param ('watermark', $wm);
		return $this;
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

	/**
	 * get cmd
	 * @return mixed
	 */
	public function get_cmd ()
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
	 * calculate correct size by dar
	 */
	public function set_size_by_dar ()
	{
		$width       = $this->get_param ('width');
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

		$this->_set_param('size', $size);
		return $this;
	}

	/**
	 * ss param
	 * @param mixed  $ss
	 * @param boolean $input
	 * @return $this
	 */
	public function set_ss ($ss, $input = TRUE)
	{
		$type = $input ? 'input' : 'output';
		$this->_set_param('ss_' . $type, $ss);
		return $this;
	}

	/**
	 * t param
	 * @param mixed  $t
	 * @param boolean $input
	 */
	public function set_t ($t, $input = TRUE)
	{
		$type = $input ? 'input' : 'output';
		$this->_set_param('t_' . $type, $t);
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
		$this->set_ss ($start, FALSE);
		$this->set_to ($end);
		return $this;
	}

	/**
	 * Cut from start
	 * @param  mixed $start
	 * @return FFmpeg
	 */
	public function start ($start)
	{
		$this->set_ss ($start, TRUE);
		return $this;
	}

	/**
	 * Prepare params
	 * @param  array   $streams_needed streams to transcode
	 * @param  integer $max_streams
	 * @param  string $language if specified, keeps streams with speciefied language (work if lang correctly specified in streams)
	 * @return $this
	 */
	public function prepare ()
	{
		if ( ! $this->_input)
			throw new FFmpegException ('No input file!');

		$this->_set_output_by_format ();

		$streams = Arr::get($this->_metadata, 'streams');

		if (empty ($streams))
			throw new FFmpegException ('Streams not found in file!');

		$passed = (array) $this->get_param ('passed_streams');
		$vcodec_activated = $acodec_activated = FALSE;

		$params_cli = new \stdClass;
		$params_cli->map[] = '-map_metadata -1';

		$streams_param = $this->get_param('streams');
		$langs         = $this->get_param('langs');
		$width         = $this->get_param ('width');
		$crf           = $this->get_param ('crf');
		$preset        = $this->get_param ('preset');
		$fps           = $this->get_param ('fps');
		$ss_i          = $this->get_param ('ss_input');
		$ss_o          = $this->get_param ('ss_output');
		$to            = $this->get_param ('to');
		$vframes       = $this->get_param ('vframes');
		$loglevel      = $this->get_param ('loglevel');
		$metadata      = $this->get_param ('metadata');
		$fmt           = $this->get_param('format');

		if ( ! $fps) {
			$fps = Arr::get ($this->_metadata, 'fps', 30);
		}

		if ($width) {
			$this->set_size_by_dar();
		}

		$size = $this->get_param ('size');

		if ($vframes) {
			$params_cli->transcode[] = sprintf ('-vframes %d', $vframes);
		}

		if ($loglevel) {
			$params_cli->input[] = "-loglevel {$loglevel}";
		}

		if ($ss_i !== FALSE) {
			$ss_i = $this->_check_time($ss_i);
			$params_cli->input[] = "-ss {$ss_i}";
		}

		$params_cli->input[] = sprintf ("-i %s", escapeshellarg ($this->_input));

		if ($this->get_param('vcodec') != 'copy' AND ($watermark = $this->get_param('watermark')) !== FALSE) {
			$params_cli->input[]  = sprintf ("-i %s", escapeshellarg ($watermark->file) );
			$params_cli->filter[] = sprintf ("%s", $watermark->overlay);
			$params_cli->map[]    = '-map 1';
		}

		if ( ! is_array ($streams_param)) {
			$vcodec = $this->get_param('vcodec', 'copy');
			$acodec = $this->get_param('acodec', 'copy');
			$scodec = $this->get_param('scodec', 'copy');

			$vbitrate = (int) Arr::path($this->_metadata, 'video.0.bit_rate');
			$abitrate = (int) Arr::path($this->_metadata, 'audio.0.bit_rate');

			$params_cli->transcode[] = sprintf ('-c:v %s -c:a %s -c:s %s', $vcodec, $acodec, $scodec);
			$params_cli->map[] = '-map 0';

			if ($acodec != 'copy') {
				$acodec_activated = TRUE;
			}

			if ($vcodec != 'copy') {
				$vcodec_activated = TRUE;
			}

			$this->_set_info ('video', 0, $vcodec, $vbitrate);
			$this->_set_info ('audio', 0, $acodec, $abitrate);
			$this->_set_info ('subtitle', 0, $scodec);
		}
		else {
			foreach ($streams as $stream_type=>$stream) {

				$stream_counter = 0;

				$cur_stream_langs = (array) Arr::path($streams_param, $stream_type . '.langs', '.', []);
				$cur_stream_count = Arr::path($streams_param, $stream_type . '.count', '.', 0);

				if ( ! isset ($streams_param[$stream_type])) {
					continue;
				}

				$stream_type_alpha = substr ($stream_type, 0, 1);
				$def_codec  = $this->get_param ($stream_type_alpha.'codec', 'copy');

				$def_bitrate = $this->get_param($stream_type_alpha . 'b');

				if (is_numeric($def_bitrate)) {
					$def_bitrate = $this->_human_bitrate($def_bitrate);
				}

				if ($cur_stream_count) {
					foreach ($stream as $index=>$stream_data) {

						if ($stream_counter === $cur_stream_count) {
							break;
						}

						$stream_lang  = Arr::get($stream_data, 'language');
						$stream_index = Arr::get($stream_data, 'index', 0);
						$stream_title = Arr::get($stream_data, 'title', '');
						$full_lang    = Arr::get($langs, $stream_lang, $stream_lang);

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

						if ($metadata) {
							$params_cli->transcode[] = sprintf ('-c:%s:%d %s -metadata:s:%s:%d title="%s" -metadata:s:%s:%d language="%s"', $stream_type_alpha, $stream_counter, $codec, $stream_type_alpha, $stream_counter, escapeshellarg($stream_title_new), $stream_type_alpha, $stream_counter, $stream_lang);
						}
						else {
							$params_cli->transcode[] = sprintf ('-c:%s:%d %s', $stream_type_alpha, $stream_counter, $codec);
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
							$this->_set_info ($stream_type, $index, $codec, $bitrate);
						}

						if ($stream_type == 'audio' AND ! $acodec_activated AND $codec != 'copy')
							$acodec_activated = TRUE;

						elseif ($stream_type == 'video' AND ! $vcodec_activated AND $codec != 'copy')
							$vcodec_activated = TRUE;
					}
				}
			}
		}

		if ($vcodec_activated AND ($pix_fmt = $this->get_param('pix_fmt'))) {
			$params_cli->transcode[] = sprintf ('-pix_fmt %s', $pix_fmt);
		}

		if ($vcodec_activated) {

			if ($crf) {
				$params_cli->transcode[] = sprintf ('-crf %d', $crf);
			}
			else {
				$params_cli->transcode[] = sprintf ('-b:v %s', $this->get_param ('vb'));
			}

			if ($preset) {
				$params_cli->transcode[] = sprintf ('-preset %s', $preset);
			}

			if ($fps) {
				$params_cli->transcode[] = sprintf ('-r %d', $fps);
			}

			if ($size) {
				$params_cli->transcode[] = sprintf ('-s %s', $size);
				$params_cli->filter[] = sprintf ('scale=%s', $size);
			}

			if ($this->get_param ('faststart')) {
				$params_cli->output[] = '-movflags faststart';
			}
		}

		if ( ! empty ($params_cli->filter)) {
			$params_cli->filter = sprintf ('-filter_complex %s', escapeshellarg(implode (',', array_reverse($params_cli->filter))));
		}

		if ($acodec_activated) {
			$params_cli->transcode[] = sprintf ("-b:a %s -ar %d -ac %d", $this->get_param ('ab'), $this->get_param ('ar'), $this->get_param ('ac'));
		}

		if ($this->get_param ('overwrite')) {
			$params_cli->output[] = '-y';
		}

		if ($this->get_param ('experimental')) {
			$params_cli->output[] = '-strict experimental';
		}

		/*
		if ( ! empty ($fmt = $this->get_param('format'))) {
			$params_cli->output[] = sprintf ("-f %s", $fmt);
		}
		*/

		$extra = $this->get_param('extra');

		if ($extra) {
			$params_cli->transcode[] = $extra;
		}

		if ($ss_o !== FALSE) {
			$ss_o = $this->_check_time($ss_o);
			$params_cli->output[] = "-ss {$ss_o}";
		}

		if ($to) {
			$to = $this->_check_time($to);
			$params_cli->output[] = "-to {$to}";
		}

		$params_cli->output[] = escapeshellarg ($this->_output);

		$params = [];

		if ( ! empty ($params_cli->input))
			$params[] = implode (' ', $params_cli->input);

		if ( ! empty ($params_cli->map))
			$params[] = implode (' ', $params_cli->map);

		if ( ! empty ($params_cli->filter))
			$params[] = trim ($params_cli->filter);

		if ( ! empty ($params_cli->transcode))
			$params[] = implode (' ', $params_cli->transcode);

		if ( ! empty ($params_cli->output))
			$params[] = implode (' ', $params_cli->output);

		$this->_cmd = FFmpeg::$binary . ' ' . implode (' ', $params);

		return $this;
	}

	/**
	 * Run transcoding
	 * @return bool
	 */
	public function run ()
	{
		if (empty($this->_cmd))
			throw new FFmpegException ('Empty cmdline');

		$progress = $this->get_param ('progress');
		$logfile = $this->_set_log_file();

		if ($progress) {
			$this->_set_log_file();
			$this->_call_trigger('Converting', 'start');

			$process = Process::factory($this->_cmd, Process::STDERR)
							->trigger('all', array($this, 'get_progress'))
							->run();

			$exitcode = $process->get_exitcode();

			if ($this->_logfile) {
				fclose ($this->_logfile);
			}

			if ($exitcode === 0) {

				if ($logfile) {
					@unlink ($logfile);
				}

				$this->_call_trigger('Finished', 'finish');
			}
			else {
				$this->_call_trigger('Error', 'error');
			}
		}
		else {

			if ($logfile) {
				$this->_cmd . ' ' . sprintf ('2> %s', escapeshellarg($logfile));
			}

			system ($this->_cmd, $exitcode);
			$exitcode = intval ($exitcode);

			if ($exitcode === 0 AND $logfile) {
				@unlink ($logfile);
			}
		}

		return $exitcode === 0 ? TRUE : FALSE;
	}

	/**
	 * get output from pipe, calc progress and call trigger
	 * @param  mixed $data
	 * @return bool
	 */
	public function get_progress ($data)
	{
		$duration = Arr::get($this->_metadata, 'duration', 0);
		$buffer = Arr::get($data, 'buffer');
		$message = Arr::get($data, 'message');

		if ($buffer AND $this->_logfile) {
			fputs ($this->_logfile,  implode ("\n", $buffer) . "\n");
		}

		if (empty($message)) {
			return FALSE;
		}

		if (preg_match("/time=([\d:\.]*)/", $message, $m) ) {
			$current_duration = Arr::get($m, 1, '0:0:0');
			if (empty($current_duration))
				return 0;
			$h = $m = $s = 0;
			list($h, $m, $s) = explode(':', $current_duration);
			$time = $h*3600 + $m*60 + $s;
			$progress  = $time / max ($duration, 0.01);
			$progress  = (int) ($progress * 100);
			$this->_call_trigger($progress, 'progress');
		} elseif ($this->get_param('debug')) {
			$this->_call_trigger($message, 'debug');
		}

		return TRUE;
	}

	public function screenshot ($ss = 0, $size = 0)
	{
		if ( ! $this->_input)
		{
			throw new FFmpegException ('No input file');
		}

		if (is_numeric ($ss) AND $ss < 1 AND $ss > 0)
		{
			$ss = Arr::get($this->_metadata, 'duration') * $ss;
		}

		$scale = intval ($size) > 0 ? sprintf (' -vf scale=%d:-1', $size) : '';
		$extra = '-an'.$scale;

		$this->_set_output_by_format();
		$this->set_stream('video', 0, NULL);
		$this->set_stream('audio', 0, NULL);
		$this->set_stream('subtitle', 0, NULL);
		$this->set_ss($ss, TRUE);
		$this->set_t('0.001', TRUE);
		$this->set_vframes(1);
		$this->set_progress(FALSE);
		$this->set_format('jpg');
		$this->set_extra($extra);

		if ( ! $this->get_param('loglevel')) {
			$this->loglevel('quiet');
		}

		return $this;
	}

	public static function take_screenshot ($input, $ss = 0, $width = 0, $output = FALSE, $output_dir = FALSE)
	{
		$f = FFmpeg::factory()
				->input($input)
				->set_overwrite(TRUE);

		if ($output)
			$f->output($output);

		if ($output_dir)
			$f->set_output_dir($output_dir);

		$f->screenshot($ss, $width);
		$f->prepare();

		return $f->run();
	}

	public static function concat (array $files, $output)
	{
		$input = tempnam(sys_get_temp_dir(), 'ffmpeg_cc');
		$fh = fopen ($input, 'wb');

		foreach ($files as $file) {
			$line = sprintf ("file %s\n", escapeshellarg($file));
			fwrite ($fh, $line);
		}

		fclose ($fh);

		$cmd = sprintf ('%s -loglevel quiet -f concat -safe 0 -i %s -c copy -y %s', FFmpeg::$binary, escapeshellarg ($input), escapeshellarg($output));

		system ($cmd, $retval);

		if (intval($retval) === 0) {
			return TRUE;
		}

		return FALSE;
	}

	private function _set_param ($param, $value = '')
	{
		if ( !empty ($param))
			$this->_params[$param] = $value;
		return $this;
	}

	private function _set_info ($stream_type, $stream_index = 0, $codec, $bitrate = 0)
	{
		$codec_ori = Arr::path($this->_metadata, 'streams.' . $stream_type . '.' . $stream_index . '.codec_name');

		if ( ! $codec_ori) {
			return FALSE;
		}

		if ($stream_type == 'video') {
			$info_text_tpl = '%dx%d %s => %s %s';
			$width = Arr::get($this->_metadata, 'width', 0);
			$height = Arr::get($this->_metadata, 'height', 0);
			$size = $this->get_param('size');

			if (empty($size))
				$size = sprintf ('%dx%d', $width, $height);

			$info_text = ($codec == 'copy') ? trim(sprintf ($info_text_tpl, $width, $height, $codec_ori, $codec, '')) : sprintf ($info_text_tpl, $width, $height, $codec_ori, $size, $codec);
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

		$output_dir = $this->get_param('output_dir');

		if (empty ($output_dir)) {
			$output_dir = dirname ($this->_input);
		}

		if ( ! is_writable($output_dir))
			throw new FFmpegException ($output_dir . ' is not writeable');

		if (empty($this->_output))
			$this->_output = $this->_input;

		$this->_output = $output_dir . DIRECTORY_SEPARATOR . basename ($this->_output);
	}

	private function _set_output_by_format ()
	{
		$this->_set_output();
		$format = $this->get_param('format');

		if ($format) {
			$this->_output = Filesystem::new_extension ($this->_output, $format);
		}

		$prefix = $this->get_param('prefix');

		if ( ! empty ($prefix))
		{
			$this->_output = Filesystem::add_prefix ($this->_output, $prefix);
		}

		if ($this->get_param('overwrite') !== TRUE OR $this->_output == $this->_input)
		{
			$this->_output = Filesystem::check_filename ($this->_output);
		}

		if (file_exists($this->_output) AND ! is_writable($this->_output)) {
			throw new FFmpegException ($this->_output . ' is not writeable');
		}

		return $this;
	}

	private function _set_log_file ()
	{
		$debug = $this->get_param('debug');
		$log_dir = $this->get_param('log_dir');

		if ($debug AND $log_dir)
		{
			if ( ! is_writable($log_dir))
				throw new FFmpegException ('Logdir not writeable');

			$logfile = $log_dir . DIRECTORY_SEPARATOR . pathinfo ($this->_input, PATHINFO_FILENAME)  . '.log';
			$this->_logfile = fopen ($logfile, 'wb');
			return $logfile;
		} else {
			return FALSE;
		}
	}

	private function _call_trigger ($message = '', $action = 'message')
	{
		if ($this->_trigger) {

			$data = array (
				'action'  =>$action,
				'message' =>$message,
				'input'   =>$this->_input,
				'output'  =>$this->_output,
			);

			call_user_func($this->_trigger, $data);
		}
	}

	private function _human_bitrate ($bytes, $decimals = 0)
	{
	    $size = array('bps','kbps','mbps');
	    $factor = floor((strlen($bytes) - 1) / 3);
	    return sprintf("%.{$decimals}f", $bytes / pow(1000, $factor)) . ' ' . Arr::get($size, $factor);
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
			throw new FFmpegException ('Wrong time param: '.$time);
		}
	}
}
