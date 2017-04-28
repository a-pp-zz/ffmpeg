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

	public function raw ()
	{
		if ( !$this->_input)
			throw new MediaInfoException ('No input file');

		$cmd = sprintf ('%s --Full --Output=XML %s', MediaInfo::$binary, escapeshellarg ($this->_input));
		$result = system ($cmd, $retval);
		$retval = intval ($retval);

		if ($retval !== 0) {
			throw new MediaInfoException ('Error analizing file');
		}

		$result = simplexml_load_string ($result, "SimpleXMLElement", LIBXML_NOCDATA);
		$result = json_decode(json_encode($result), TRUE);		

		return $result;
	}

	private function _pretty_output (&$result) 	
	{
		if ( !isset($result['streams'])) {
			return FALSE;	
		}

		$needed 			   = [];
		$needed['duration']    = (double) Arr::path($result, 'format.duration', 0);
		$needed['size']        = (int) Arr::path($result, 'format.size', 0);
		$needed['bit_rate']    = (int) Arr::path($result, 'format.bit_rate', 0);
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

		if ( empty ($date) AND !empty ($creation_time))
			$date = $creation_time;

		if ( !empty ($date) ) {
			$date = str_replace ("T", " ", $date);
			$date = preg_replace ("#\+0\d{3}$#", "", $date);
			$date = strtotime ($date);
		}
		else {
			$date = filemtime ($this->_input);
		}

		$needed['date'] = $date;

		if (is_array(Arr::get( $result, 'streams')))
		{
			foreach ( $result['streams'] as $stream_index => $stream )
			{				
				$language = Arr::path ( $stream, 'tags.language', '.', 'unk' );
				if ( empty ($language) )
					$language = 'unk';

				$codec_type = Arr::get ( $stream, 'codec_type' );

				if ( $codec_type == 'video' ) {	

					$r_frame_rate   = Arr::get ( $stream, 'r_frame_rate', 0 );
					$avg_frame_rate = Arr::get ( $stream, 'avg_frame_rate', 0 );

					if ( preg_match ('#\/0{1,}$#', $r_frame_rate) )
						$r_frame_rate = 0;

					if ( preg_match ('#\/0{1,}$#', $avg_frame_rate) )
						$avg_frame_rate = 0;						

					if ( !empty ( $r_frame_rate ) ) {
						$r_frame_rate = ceil ( eval ( 'return ('. $r_frame_rate . ');' ) );
					}				

					if ( !empty ( $avg_frame_rate ) ) {
						$avg_frame_rate = ceil ( eval ( 'return ('. $avg_frame_rate . ');' ) );
					}						 						

					$fps = max ( $r_frame_rate, $avg_frame_rate );

					$needed['width']  = Arr::get ( $stream, 'width', 0);
					$needed['height'] = Arr::get ( $stream, 'height', 0);
					$needed['is_hd']  = intval ($needed['width']>=1280);
					$needed['dar']    = Arr::get ( $stream, 'display_aspect_ratio');

					if ( $needed['dar']) {
						list ($w_dar,  $h_dar) = explode (':', $needed['dar']);
						$needed['dar_num'] = $w_dar / $h_dar;
					}		

					$duration = (double) Arr::get ( $stream, 'duration', 0);				

					$needed['streams']['video'][] = array ( 
						'index'      => (int) Arr::get ( $stream, 'index', $stream_index),
						'codec_name' => Arr::get ( $stream, 'codec_name', ''),
						'duration'   => $duration,
						'bit_rate'   => (int) Arr::get ( $stream, 'bit_rate', 0),
						'fps'        => $fps,
						'width'      => (int) Arr::get ( $stream, 'width', 0),
						'height'     => (int) Arr::get ( $stream, 'height', 0),
						'language'   => $language
					);

					if (empty($needed['duration']) AND $duration) {
						$needed['duration'] = $duration; 
					}
				}
				elseif ( $stream['codec_type'] == 'audio' ) {
					$needed['streams']['audio'][] = array (
						'index'       => (int) Arr::get ( $stream, 'index', $stream_index),
						'codec_name'  => Arr::get ( $stream, 'codec_name', ''),
						'sample_rate' => (int) Arr::get ( $stream, 'sample_rate', 0),
						'bit_rate'    => (int) Arr::get ( $stream, 'bit_rate', 0),
						'channels'    => (int) Arr::get ( $stream, 'channels', 0),
						'language'    => $language
					);
				}
				elseif ( $stream['codec_type'] == 'subtitle' ) {
					$needed['streams']['subtitle'][] = array (
						'index'       => (int) Arr::get ( $stream, 'index', $stream_index),
						'codec_name'  =>Arr::get ( $stream, 'codec_name', ''),
						'language'		=>$language
					);
				}
				$needed['languages'][$language][] = (int) Arr::get ( $stream, 'index', $stream_index);						
			}
			$result = $needed;
			unset ($needed);
		}
	}
}