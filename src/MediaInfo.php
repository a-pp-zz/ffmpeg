<?php
namespace AppZz\VideoConverter;
use AppZz\Helpers\Arr;
use AppZz\VideoConverter\Exceptions\MediaInfoException;

class MediaInfo {

	private $_input;
	public static $binary = 'mediainfo';

	public function __construct ($input = '')
	{
		$this->_input  = $input;
	}

	public static function factory ($input = '')
	{
		return new MediaInfo ($input);
	}

	public function analyze ($raw = FALSE)
	{
		if ( !$this->_input)
			throw new MediaInfoException ('No input file');

		$output = tempnam ('/tmp', 'mediainfo_');

		$cmd = sprintf ('%s --Full --Output=XML %s 2>/dev/null > %s', MediaInfo::$binary, escapeshellarg ($this->_input), escapeshellarg($output));
		system ($cmd, $retval);
		$retval = intval ($retval);

		if ($retval !== 0) {
			throw new MediaInfoException ('Error analizing file');
		}

		if ( ! file_exists($output))
			throw new MediaInfoException ('Output file not exists');

		if (filesize($output) === 0)
			throw new MediaInfoException ('Bad output file');

		$result = file_get_contents ($output);
		unlink ($output);

		$result = simplexml_load_string ($result, "SimpleXMLElement", LIBXML_NOCDATA);
		$result = json_decode(json_encode($result), TRUE);		

		$result = Arr::path($result, 'File.track');		

		if ( ! $result)
			throw new MediaInfoException ('Error analizing file');

		if ( ! $raw)
			$this->_populate($result);

		return $result;
	}

	private function _populate (&$result)
	{		
		$needed = array (
			'id',
			'stream_type',
			'movie_name',
			'file_size',
			'format',
			'format_info',
			'format_profile',
			'commercial_name',
			'codec_id',
			'codec',
			'codec_family',
			'codec_info',
			'internet_media_type',
			'width',
			'height',
			'sampled_width',
			'sampled_height',
			'pixel_aspect_ratio',
			'display_aspect_ratio',
			'colorimetry',
			'color_space',
			'resolution',
			'duration',
			'duration_human',
			'bit_rate',
			'bit_depth',
			'overall_bit_rate',
			'channel_s_',
			'channels',
			'sampling_rate',
			'frame_rate',
			'stream_size',
			'title',
			'movie_name',
			'language',
			'forced',
			'@attributes',						
		);

		foreach ($result as $keys=>&$values) {
			$values = array_change_key_case($values, CASE_LOWER);
			$values = array_intersect_key($values, array_flip($needed));		
			foreach ($values as $key=>&$value) {

				if (is_array($value))
					$value = array_unique($value);
				
				if ($key == '@attributes') {
					$values['stream_type'] = strtolower (Arr::get($value, 'type'));
					unset ($values['@attributes']);
				}

				switch ($key) {						
					case 'id':						
						if (is_array($value))
							$value = reset ($value);
					break;	
					case 'frame_rate':
					case 'sampling_rate':
					case 'resolution':
					case 'bit_depth':
					case 'width':
					case 'height':
					case 'sampled_width':
					case 'sampled_height':
						$value = $this->_parse_number($value);
					break;
					case 'pixel_aspect_ratio':
					case 'display_aspect_ratio':
						$value = $this->_parse_number($value);
						$value = eval ("return ({$value});");
					break;
					case 'duration':
						$d = $this->_populate_duration($value);
						$values['duration'] = $d->duration;
						$values['duration_human'] = $d->duration_human;
					break;
					case 'file_size':
					case 'stream_size':
						$value = $this->_parse_number($value);
						$values[$key.'_human'] = $this->_human_filesize($value);
					break;
					case 'bit_rate':
					case 'overall_bit_rate':
						$value = $this->_parse_number($value);
						$values[$key.'_human'] = $this->_human_bitrate($value);
					break;					
					case 'channel_s_':
						$value['channels'] = $this->_parse_number($value);
						unset ($values['channel_s_']);
					break;
					case 'forced':
						$value = $value == 'Yes' ? 1 : 0;
					break;	
					case 'language':
						$value = $this->_populate_language ($value);
					break;
				}				
				
				/*
				if (in_array($new_key, $need)) {

					switch ($new_key) {						
						case 'id':						
							if (is_array($value))
								$values['id'] = reset ($value);
						break;	
						case 'duration':
							print_r ($value);
							$d = $this->_populate_duration($value);
							print_r ($d);
							$values['duration'] = $d->duration;
							$values['duration_human'] = $d->duration_human;
						break;
						case 'channel_s_':
							$values['channels'] = $this->_parse_number($value);
							unset ($values['channel_s_']);
						break;	
						default:	
							$values[$new_key] = $value;
						break;	
					}
	
					if ($new_key != $key) {
						unset ($values[$key]);					
					}
				} else {
					unset ($values[$key]);
				}
				*/
			}
		}
	}

	private function _parse_number ($numbers)
	{
		$numbers = (array) $numbers;	
		
		foreach ($numbers as $key=>$value) {
			if (is_numeric($value))
				return $value;
		}

		return FALSE;
	}

	private function _populate_duration ($durations)
	{
		$ret = new \stdClass;
		$ret->duration = 0;
		$ret->duration_human = '00:00:00';
		$pattern = '#(?<hh>\d{2})\:(?<mm>\d{2})\:(?<ss>\d{2})\.?.*#iu';

		$durations = (array) $durations;

		foreach ($durations as $duration) {
			
			if (preg_match ($pattern, $duration, $parts)) {
				$hh = Arr::get($parts, 'hh', 0);
				$mm = Arr::get($parts, 'mm', 0);
				$ss = Arr::get($parts, 'ss', 0);

				$ret->duration_human = sprintf('%02d:%02d:%02d', $hh, $mm, $ss);

				$hh = sprintf ('%d', $hh);
				$mm = sprintf ('%d', $mm);
				$ss = sprintf ('%d', $ss);

				$ret->duration = ($hh * 3600) + ($mm*60) + $ss;
			}	
		}

		return $ret;
	}

	private function _populate_language ($languages)
	{
		$languages = (array) $languages;

		foreach ($languages as $language)
		{
			if (strlen($language) === 3)
				return $language;
		}

		return FALSE;
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