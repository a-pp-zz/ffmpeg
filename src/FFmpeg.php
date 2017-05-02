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
		'vb'             =>'2000k',
		'fps'            =>30,
		'format'         =>'mp4',
		'ab'             =>'96k',
		'ar'             =>'44100',
		'ac'             =>2,
		'pix_fmt'        =>'yuv420p',
		'langs'			 =>FALSE,
		'metadata'		 =>FALSE,
		'streams'	     =>FALSE,
		'langs' => [
			'rus' => 'Русский',
			'eng' => 'Английский',		
		]
	);

	/**
	 * allowed setters for magic method __call
	 * @var array
	 */
	private $_allowed_setters = array (
		'vcodec', 'acodec', 'scodec',
		'width', 'size', 'format', 'fps',
		'vb', 'ab', 'ar', 'ac',		
		'experimental', 'debug', 'log_dir', 
		'output_dir', 'overwrite', 'crf', 'preset',
		'pix_fmt', 'progress', 'streams',
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
	public function get_param ($param)
	{
		if ( ! empty ($param) AND isset ($this->_params[$param]))
			return $this->_params[$param];
		else
			return FALSE;
	}

	public function set_stream ($type = 'video', $count = 1, $langs = [])
	{
		if ( is_bool($type))
			$this->_params['streams'] = $type;
		else
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
				throw new FFmpegException ('Error get metadata from file!');

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
	 * get cmd
	 * @return mixed
	 */
	public function get_cmd () 
	{
		return $this->_cmd;
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
			
			if ($height%2 !=0) {
				$height++;
			}
			
			$size = sprintf ('%dx%d', $width, $height);
		}		
		
		$this->_set_param('size', $size);
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

		$this->_set_output_by_format ('video');

		$streams = Arr::get($this->_metadata, 'streams');

		if (empty ($streams))
			throw new FFmpegException ('Streams not found in file!');	

		$passed = (array) $this->get_param ('passed_streams');
		$vcodec_activated = $acodec_activated = FALSE;

		$params_cli = new \stdClass;
		$params_cli->map[] = '-map_metadata -1';

		$streams_param = $this->get_param('streams');
		$langs = $this->get_param('langs');

		$params_cli->input[] = sprintf ("-i %s", escapeshellarg ($this->_input));
		
		if (($watermark = $this->get_param('watermark')) !== FALSE) {
			$params_cli->input[] = sprintf ("-i %s", escapeshellarg ($watermark->file) );
			$params_cli->filter[] = sprintf ("%s", $watermark->overlay);
			$params_cli->map[] = '-map 1';
		}

		if ( ! is_array ($streams_param)) {
			$params_cli->transcode[] = sprintf ('-c:v %s -c:a %s -c:s %s', $this->get_param('vcodec'), $this->get_param('acodec'), $this->get_param('scodec'));
			$vcodec_activated = $acodec_activated = TRUE;
			
			if ($streams_param === TRUE) {
				$params_cli->map[] = '-map 0';
			}
		} else {

			#print_r ($streams);
			#exit;

			foreach ($streams as $stream_type=>$stream) {

				$stream_counter = 0;

				//$cur_stream_type = $stream_type;				

				$cur_stream_langs = (array) Arr::path($streams_param, $stream_type . '.langs', '.', []);
				$cur_stream_count = Arr::path($streams_param, $stream_type . '.count', '.', 0);

				#echo $stream_type . $stream_counter, PHP_EOL;
				#echo $cur_stream_count, PHP_EOL;				

				#if ($cur_stream_count === 0 OR ($stream_counter >= $cur_stream_count)) {
					//echo 'dddddddddd';
					#continue;
				#}

				if ( ! isset ($streams_param[$stream_type])) {
					continue;
				}	

				$stream_type_alpha = substr ($stream_type, 0, 1); 			
				$def_codec  = $this->get_param ($stream_type_alpha.'codec');
				$def_bitrate = $this->get_param($stream_type_alpha . 'b');

				if (is_numeric($def_bitrate)) {
					$def_bitrate = $this->_human_bitrate($def_bitrate);
				}

				if ($cur_stream_count) {
					foreach ($stream as $stream_index=>$stream_data) {	

						if ($stream_counter === $cur_stream_count) {
							break;
						}		

						$stream_lang  = Arr::get($stream_data, 'language');
						$stream_index = Arr::get($stream_data, 'index', 0);						
						$stream_title = Arr::get($stream_data, 'title', '');
						$full_lang = Arr::get($langs, $stream_lang, $stream_lang);	
						
						if ( ! in_array (Arr::get ($stream_data, 'codec_name'), $passed)) {
							//$transcoded[$stream_type] = ++$current_stream_counter;
							$codec = $def_codec;
							$bitrate = $def_bitrate;
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
							if ($bitrate)
								$stream_title_new = sprintf ('%s [%s @ %s]', mb_convert_case ($full_lang, MB_CASE_TITLE), $codec_name, $bitrate);					
							else
								$stream_title_new = sprintf ('%s @ %s', mb_convert_case ($full_lang, MB_CASE_TITLE), $codec_name);					
						}
						
						$params_cli->transcode[] = sprintf ('-c:%s:%d %s -metadata:s:%s:%d title="%s" -metadata:s:%s:%d language="%s"', $stream_type_alpha, $stream_counter, $codec, $stream_type_alpha, $stream_counter, escapeshellarg($stream_title_new), $stream_type_alpha, $stream_counter, $stream_lang);

						//print_r ($params_cli->transcode);

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
							$this->_set_info ($stream_type, $stream_index);					
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
			$params_cli->transcode[] = sprintf ("-pix_fmt %s", $pix_fmt);
		}

		if ($vcodec_activated) {	

			$crf = $this->get_param ('crf');
			$preset = $this->get_param ('preset');
			$fps = $this->get_param ('fps');
			$size = $this->get_param ('size');
			$width = $this->get_param ('width');
			
			if ($crf) {
				$params_cli->transcode[] = sprintf ( "-crf %d", $crf);
			}
			else {
				$params_cli->transcode[] = sprintf ( "-b:v %s", $this->get_param ('vb'));
			}

			if ($preset) {
				$params_cli->transcode[] = sprintf ( "-preset %s", $preset);
			}
			
			if ($fps) {
				$params_cli->transcode[] = sprintf ( "-r %d", $fps);			
			}
			
			if ($size) {
				$params_cli->transcode[] = sprintf ('-s %s', $size);
				$params_cli->filter[] = sprintf ("scale=%s", $size);
			} elseif ($width) {
				$params_cli->filter[] = sprintf ("scale=%d:%d", $width, -1);
			}
			
			if ($this->get_param ('faststart')) {			
				$params_cli->output[] = '-movflags faststart';			
			}				
		}

		if ( ! empty ($params_cli->filter)) {
			$params_cli->filter = sprintf ("-filter_complex %s", escapeshellarg(implode (',', array_reverse($params_cli->filter))));
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

		$fmt = $this->get_param('format');

		if ( ! empty ($fmt = $this->get_param('format')))
			$params_cli->output[] = sprintf ("-f %s", $fmt);
		
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
	public function transcode ()
	{
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
			} else {
				$this->_call_trigger('Error', 'error');
			}
		} else {
			
			if ($logfile) {
				$cmd = $this->_cmd . ' ' . sprintf ('2> %s', escapeshellarg($logfile));
			}			
			
			system ($cmd, $exitcode);
			$exitcode = intval ($exitcode);
			
			if ($retval === 0)
				unlink ($logfile);			
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

	public static function take_screenshot ($input, $ss = 0, $size = 0, $output = FALSE, $output_dir = FALSE)
	{
		$f = FFmpeg::factory()
				->input($input);

		if ($output)
			$f->output($output);

		if ($output_dir)
			$f->set_output_dir($output_dir);					

		return $f->screenshot ($ss, $size);
	}				

	private function _set_param ($param, $value = '')
	{
		if ( !empty ($param))
			$this->_params[$param] = $value;
		return $this;
	}	

	private function _set_info ($stream_type, $stream_index = 0, $codec, $bitrate = 0)
	{
		if ($stream_index === 0) {
			$info_text = sprintf ('%s: %s', mb_convert_case($stream_type, MB_CASE_TITLE), $codec);

			if ($bitrate) {
				$info_text .= ' @ ' . $bitrate;					
			}

			$this->_info[] = $info_text;
		} else {
			if ($stream_type == 'video') {
				$info_text_tpl = '%s: %dx%d %s =>%dx%d %s';
				$width = Arr::get($this->_metadata, 'width', 0);
				$height = Arr::get($this->_metadata, 'height', 0);
			} else {
				$info_text_tpl = '%s: %s => %s';
			}
		}
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

	private function _human_filesize ($bytes, $decimals = 2)
	{
	    $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
	    $factor = floor((strlen($bytes) - 1) / 3);
	    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
	}	

	private function _human_bitrate ($bytes, $decimals = 0)
	{
	    $size = array('bps','kbps','mbps');
	    $factor = floor((strlen($bytes) - 1) / 3);
	    return sprintf("%.{$decimals}f", $bytes / pow(1000, $factor)) . ' ' . @$size[$factor];
	}		
}