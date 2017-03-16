<?php
namespace AppZz\VideoConverter;

use AppZz\Helpers\Arr;
use AppZz\VideoConverter\Exceptions\FFmpegException;
use AppZz\Helpers\Filesystem;
use AppZz\CLI\Process;

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
		'pix_fmt'        =>'yuv420p',
	);

	/**
	 * allowed setters for magic method __call
	 * @var array
	 */
	private $_allowed_setters = array (
		'vcodec', 'acodec', 'scodec', 'width', 'size',
		'format', 'fps', 'vb', 'ab', 'ar', 'ac',		
		'experimental', 'debug', 'log_dir', 
		'output_dir', 'overwrite', 'crf', 'preset',
		'pix_fmt', 'progress', 'mapping', 'passed_streams', 'prefix'
	);

	/**
	 * logfile resource
	 * @var resource
	 */
	private $_logfile;

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
	public function get_param ( $param ) {
		if ( ! empty ($param) AND isset ($this->_params[$param]))
			return $this->_params[$param];
		else
			return FALSE;
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
			} else {
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
				throw new FFprobeException ('Error get metadata from file!');

			unset ($this->_output);		
			return $this;
		} else {
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
		if (empty ($filename))
			throw new FFmpegException ('Output file not exists');
		$this->_output = $filename;
		return $this;
	}	

	/**
	 * Set watermark param
	 * @param  [type] $file    [description]
	 * @param  string $align   [description]
	 * @param  array  $margins [description]
	 * @return [type]          [description]
	 */
	public function watermark ($file = NULL, $align = 'bottom-right', $margins = array (0, 0))
	{
		$overlay = 'overlay=';
		
		switch ($align) {
			case 'top-left':
				$overlay .= abs ($margins[0]) . ':' . abs ($margins[1]);
			break;			
			case 'top-right':
				$overlay .= 'main_w-overlay_w-' . $margins[0] . ':' . abs ($margins[1]);
			break;	
			case 'bottom-left':
				$overlay .= abs ($margins[0]) . ':' . 'main_h-overlay_h-' . abs ($margins[1]);
			break;			
			case 'bottom-right':
			default:
				$overlay .= 'main_w-overlay_w-' . abs ($margins[0]) . ':' . 'main_h-overlay_h-' . abs ($margins[1]);
			break;										
		}
		
		$wm = new \stdClass;
		$wm->file = $file;
		$wm->overlay = $overlay;
		return $this->_set_param ('watermark', $wm);	
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
	 * calculate corect size by dar
	 */
	public function set_size_by_dar ()
	{
		$width = $this->get_param ('width');
		$width_meta  = (int) Arr::get ($this->_metadata, 'width', 0);
		$height_meta = (int) Arr::get ($this->_metadata, 'height', 0);
		$dar = Arr::get ($this->_metadata, 'dar_num');

		if ( ! $dar)
			$dar = $width_meta / $height_meta;

		$size = NULL;
		
		if ($width == 1280 AND $width_meta >= $width) {
			$size = 'hd720';
		} elseif ($width == 1920 AND $width_meta >= $width) {
			$size = 'hd1080';
		} elseif ($dar) {
			$width  = min (array($width, $width_meta));
			$height = intval ($width / $dar);
			
			if ($height%2 !=0) {
				$height++;
			}
			
			$size = sprintf ('%dx%d', $width, $height);
		}		
		
		$this->_set_param('size', $size);
		return $this;
	}	

	/**
	 * Transcode videofile
	 * @param  array   $streams_needed streams to transcode
	 * @param  integer $max_streams    [description]
	 * @param  [type]  $language       [description]
	 * @return [type]                  [description]
	 */
	public function transcode ($streams_needed = array ('video', 'audio', 'subtitle'), $max_streams = 1, $language = NULL)
	{
		if ( ! $this->_input)
			throw new FFprobeException ('No input file!');

		$this->_set_output_by_format ('video');

		$transcoded = array ('video'=>0, 'audio'=>0, 'subtitle'=>0);
		$streams = Arr::get($this->_metadata, 'streams');

		if (empty ($streams))
			throw new FFprobeException ('Streams not found in file!');	

		$lang_streams     = Arr::path ($this->_metadata, 'languages.' . $language, '.', array ());		
		$stream_counter   = 0;
		$params_transcode = array ();
		$params_array     = array ();
		$maps_array       = array ();
		$filter_complex   = array ();
		$audio_stream_exist = FALSE;
		$passed = $this->get_param ('passed_streams');
		$passed = (array) $passed;		
		$mapping = $this->get_param ('mapping');
		$progress = $this->get_param ('progress');

		foreach ($this->_metadata['streams'] as $stream_type=>$stream) {

			if ( ! in_array ($stream_type, $streams_needed)) {
				continue;
			}	

			$stream_type_alpha = substr ($stream_type, 0, 1); 			
			$codec  = $this->get_param ($stream_type_alpha.'codec');

			if ($mapping === TRUE) {
				foreach ($stream as $stream_index=>$stream_data) {			

					$current_stream_counter = Arr::get($transcoded, $stream_type, 0);

					if ($current_stream_counter === $max_streams)
							continue;

					$try_transcode = ( empty ($language) OR sizeof ($lang_streams) === 0 OR $stream_type == 'video' ) ? TRUE : FALSE;
					if ( $stream_type == 'subtitle' OR ! in_array (Arr::get ($stream_data, 'codec_name'), $passed ) ) {
						$transcoded[$stream_type] = ++$current_stream_counter;
					}
					else {
						$codec = 'copy';
					}
					
					if ($try_transcode === TRUE OR in_array ($stream_index, $lang_streams)) {
						$params_transcode[] = sprintf ("-c:%s:%d %s", substr ($stream_type, 0, 1), $stream_counter, $codec);
						$maps_array[]       = $stream_counter;							
						$stream_counter++;
					}
				}
			} else {
				$params_transcode[] = sprintf ("-c:%s %s", substr ($stream_type, 0, 1), $codec);
				$transcoded[$stream_type] = 1;
			}
		}

		/*
			Дорожки не нуждаются в перекодировании
		 */
		if ( $transcoded['video'] == 0 AND $transcoded['audio'] == 0 ) {
			return -1;
		}

		$params_array[] = sprintf ("-i %s", escapeshellarg ($this->_input));
		
		if (($pix_fmt = $this->get_param('pix_fmt'))) {
			$params_array[] = sprintf ("-pix_fmt %s", $pix_fmt);
		}

		if (($watermark = $this->get_param('watermark')) !== FALSE) {
			$params_array[]   = sprintf ("-i %s", escapeshellarg ($watermark->file) );
			$filter_complex[] = sprintf ("%s", $watermark->overlay);
		}
		
		if (sizeof ($maps_array) > 0)
			$params_array[] = '-map 0:' . implode (' -map 0:', $maps_array );

		if (sizeof ($params_transcode) > 0)
			$params_array[] = implode (' ', $params_transcode);

		if ($transcoded['video'] >=1) {			
			$crf = $this->get_param ('crf');
			$preset = $this->get_param ('preset');
			$fps = $this->get_param ('fps');
			$size = $this->get_param ('size');
			$width = $this->get_param ('width');
			
			if ($crf) {
				$params_array[] = sprintf ( "-crf %d", $crf);
			}
			else {
				$params_array[] = sprintf ( "-b:v %s", $this->get_param ('vb'));
			}

			if ($preset) {
				$params_array[] = sprintf ( "-preset %s", $preset);
			}
			
			if ($fps) {
				$params_array[] = sprintf ( "-r %d", $fps);			
			}
			
			if ($size) {
				$params_array[] = '-s ' . $size;
			}
			
			if ($this->get_param ('faststart')) {			
				$params_array[] = '-movflags faststart';			
			}	
			
			if ($width) {
				$filter_complex[] = sprintf ("scale=%d:%d", $width, -1);
			}
		}

		if ( !empty ($filter_complex) ) {
			$params_array[] = sprintf ("-filter_complex %s", escapeshellarg(implode (',', array_reverse($filter_complex))) );			
		}

		if ($transcoded['audio']>=1) {
			$params_array[] = sprintf ( "-b:a %s -ar %d -ac %d", $this->get_param ('ab'), $this->get_param ('ar'), $this->get_param ('ac') );	
		}

		if ($this->get_param ('overwrite')) {
			$params_array[] = '-y';			
		}

		if ($this->get_param ('experimental')) {
			$params_array[] = '-strict experimental';			
		}

		$params_array[] = sprintf ( "-f %s", $this->get_param('format') );
		$params_array[] = escapeshellarg ($this->_output);

		$cmd = FFmpeg::$binary . ' ' . implode (' ', $params_array);

		$logfile = $this->_set_log_file();

		if ($progress) {
			$this->_set_log_file();
			$this->_call_trigger('Converting', 'start');
			$process = Process::factory($cmd, Process::STDERR)
							->trigger('all', array($this, 'get_progress'))
							->run();

			$exitcode = $process->get_exitcode();							

			if ($this->_logfile) {
				fclose ($this->_logfile);			
			}
			
			if ($exitcode === 0) {
				if ($logfile) {
					unlink ($logfile);
				}
				$this->_call_trigger('Finished', 'finish');
			} else {
				$this->_call_trigger('Error', 'error');
			}

		} else {
			
			if ($logfile) {
				$cmd .= sprintf (' 2> %s', escapeshellarg($logfile));
			}			
			
			system ($cmd, $exitcode);
			$exitcode = intval ($exitcode);
			
			if ($retval === 0)
				unlink ($logfile);			
		}

		return $exitcode === 0 ? TRUE : FALSE;
	}

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
			$time = $h*360 + $m*60 + $s;
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

		if ($ss < 1 AND $ss > 0)
		{
			$ss = Arr::get($this->_metadata, 'duration') * $ss;
		}		

		$this->_set_output_by_format('image');

		$scale = intval ($size) > 0 ? ' -vf scale='.$size.':-1' : '';
		$cmd   = sprintf('%s -loglevel quiet -ss %s -i %s -y -t 0.001 -vframes 1 -an -y -f image2%s %s', FFmpeg::$binary, number_format ($ss, 2, '.', ''), escapeshellarg($this->_input), $scale, escapeshellarg($this->_output));

		system ($cmd, $ret);
		
		if (intval($ret) !== 0)
			throw new FFmpegException ('Screenshot not created');
		
		return TRUE;
	}		

	private function _set_param ($param, $value = '')
	{
		if ( !empty ($param))
			$this->_params[$param] = $value;
		return $this;
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

	private function _set_output_by_format ($format = 'image')
	{
		$this->_set_output();
		
		if ( $format == 'image' ) {
			$this->_output = Filesystem::new_extension ($this->_output, 'jpg');
		}
		else {
			$this->_set_output();
			$this->_output = Filesystem::new_extension ($this->_output, $this->get_param('format'));				
			$prefix = $this->get_param('prefix');
			
			if ( ! empty ($prefix))
			{
				$this->_output = Filesystem::add_prefix ($this->_output, $prefix);
			}

			if ($this->get_param('overwrite') !== TRUE OR $this->_output == $this->_input)
			{
				$this->_output = Filesystem::check_filename ($this->_output);	
			}			
		}

		if (file_exists($this->_output) AND ! is_writable($this->_output))
			throw new FFmpegException ($this->_output . ' is not writeable');		

		return $this;
	}

	private function _set_log_file ()
	{
		$debug = $this->get_param('debug');
		$log_dir = $this->get_param('log_dir');

		if ($debug AND $log_dir)
		{
			if ( ! is_writable($log_dir))
				throw new FFprobeException ('Logdir not writeable');		

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

	public static function take_screenshot ($input, $ss = 0, $size = 0, $output = FALSE, $output_dir = FALSE) {
		$f = FFmpeg::factory()
				->input($input);

		if ($output)
			$f->output($output);

		if ($output_dir)
			$f->set_output_dir($output_dir);					

		return $f->screenshot ($ss, $size);
	}			
}