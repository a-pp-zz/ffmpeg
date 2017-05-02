<?php
namespace AppZz\VideoConverter;
use AppZz\Helpers\Arr;
use AppZz\VideoConverter\Exceptions\FFprobeException;

class FFprobe {

	private $_input;
	public static $binary = 'ffprobe';

	public function __construct ($input = '')
	{
		$this->_input  = $input;
	}

	public static function factory ($input = '')
	{
		return new FFprobe ($input);
	}

	public function probe ($pretty_output = TRUE)
	{
		if ( !$this->_input)
			throw new FFprobeException ('No input file');

		$cmd  = FFprobe::$binary . ' ' . escapeshellarg ($this->_input) . ' -of json -loglevel quiet -show_format -show_streams -show_error 2>&1';
		$json = shell_exec ($cmd);
		$result = json_decode ($json, TRUE);

		if ( ! is_array ($result)) {
			throw new FFprobeException ('Error analizing file');
		}

		if (isset ($result['error'])) {
			throw new FFprobeException (Arr::path($result, 'error.string'));
		}

		if ($pretty_output) {
			$this->_pretty_output($result);
		}

		return $result;
	}

	private function _pretty_output (&$result) 	
	{
		if ( !isset($result['streams'])) {
			return FALSE;	
		}

		$needed 			   = [];
		$needed['duration']    = (double) Arr::path($result, 'format.duration', '.', 0);
		$needed['size']        = (int) Arr::path($result, 'format.size', '.', 0);
		$needed['bit_rate']    = (int) Arr::path($result, 'format.bit_rate', '.', 0);
		$needed['format_name'] = Arr::path ($result, 'format.format_name', '.', '');
		$needed['make']        = Arr::path ($result, 'format.tags.make', '.', '');
		$needed['model']       = Arr::path ($result, 'format.tags.model', '.', '');		
		$location              = Arr::path ($result, 'format.tags.location', '.', NULL); 
		$date                  = Arr::path ($result, 'format.tags.date', '.', '');
		$creation_time         = Arr::path ($result, 'format.tags.creation_time', '.', '');

		if ( !empty ($location) AND preg_match ('#([\-\+]{1})(.*)([\-\+]{1})(.*)[\-\+]{1}.*/$#', $location, $loc))
		{
			$needed['latitude']  = (double) ($loc[1].$loc[2]);
			$needed['longitude'] = (double) ($loc[3].$loc[4]);
		}

		if (empty ($date) AND !empty ($creation_time))
			$date = $creation_time;

		if ( ! empty ($date)) {
			$date = str_replace ("T", " ", $date);
			$date = preg_replace ("#\+0\d{3}$#", "", $date);
			$date = strtotime (trim($date));
		}
		else {
			$date = filemtime ($this->_input);
		}

		$needed['date'] = $date;
		$streams = Arr::get($result, 'streams', []);

		$stream_prop = [
			'index',
			'codec_type',
			'codec_name',
			'r_frame_rate',
			'avg_frame_rate',
			'width',
			'height',
			'display_aspect_ratio',
			'duration',									
			'bit_rate',
			'sample_rate',
			'channels',
		];

		foreach ($streams as $stream_index => $stream )
		{				
			$language = Arr::path ($stream, 'tags.language', '.', 'unk');
			$title = Arr::path ($stream, 'tags.title');
			
			if (empty ($language))
				$language = 'unk';

			$stream_data = array_intersect_key($stream, array_flip($stream_prop));
			$codec_type = Arr::get ($stream_data, 'codec_type');
			$r_frame_rate   = Arr::get ($stream_data, 'r_frame_rate', 0);
			$avg_frame_rate = Arr::get ($stream_data, 'avg_frame_rate', 0);			
			unset($stream_data['codec_type']);	

			$stream_data['language'] = $language;
			$stream_data['title'] = $title;	

			if ($codec_type == 'video') {	
				$needed['width']  = Arr::get ($stream, 'width', 0);
				$needed['height'] = Arr::get ($stream, 'height', 0);
				$needed['is_hd']  = intval ($needed['width']>=1280);
				$needed['dar']    = Arr::get ($stream, 'display_aspect_ratio');				
				
				if ($needed['dar']) {
					list ($w_dar,  $h_dar) = explode (':', $needed['dar']);
					$needed['dar_num'] = $w_dar / $h_dar;
				}				

				if (empty($needed['duration'])) {
					$needed['duration'] = (double) Arr::get($stream_data, 'duration', 0); 
				}

				if (preg_match ('#\/0{1,}$#', $r_frame_rate)) {
					$r_frame_rate = 0;
					unset($stream_data['r_frame_rate']);
				}

				if (preg_match ('#\/0{1,}$#', $avg_frame_rate)) {
					$avg_frame_rate = 0;				
					unset($stream_data['avg_frame_rate']);
				}

				if ( ! empty ($r_frame_rate)) {
					$r_frame_rate = ceil (eval ( 'return ('. $r_frame_rate . ');'));
				}				

				if ( ! empty ($avg_frame_rate)) {
					$avg_frame_rate = ceil (eval ('return ('. $avg_frame_rate . ');'));
				}						 						

				$stream_data['fps'] = max ($r_frame_rate, $avg_frame_rate);			

			} else {
				
				if (isset($stream_data['avg_frame_rate']))
					unset($stream_data['avg_frame_rate']);
				
				if (isset($stream_data['r_frame_rate']))
					unset($stream_data['r_frame_rate']);
			}

			$needed['streams'][$codec_type][] = $stream_data;
		}
		
		$result = $needed;
		unset ($needed);
	}
}