<?php
namespace AppZz\VideoConverter;
use AppZz\Helpers\Arr;
use AppZz\VideoConverter\Exceptions\FFprobeException;

class FFprobe {

	private $_input;
	private $_fields = [
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
		'pix_fmt'
	];

	public static $binary = 'ffprobe';

	public function __construct ($input = '')
	{
		$this->_input = $input;
	}

	public static function factory ($input = '')
	{
		return new FFprobe ($input);
	}

	public function fields (array $fields = [])
	{
		$this->_fields = array_merge ($this->_fields, $fields);
		return $this;
	}

	public function probe ($pretty_output = TRUE)
	{
		if ( ! $this->_input) {
			throw new FFprobeException ('No input file');
		}

		$cmd  = FFprobe::$binary . ' ' . escapeshellarg ($this->_input) . ' -of json -loglevel quiet -show_format -show_streams -show_chapters -show_error 2>&1';

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
		if ( ! isset($result['streams'])) {
			return FALSE;
		}

		$needed 			   = [];
		$needed['duration']    = (double) Arr::path($result, 'format.duration', '.', 0);
		$needed['duration_human'] = FFprobe::ts_format($needed['duration'], [], TRUE);
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

		if (empty ($date) AND !empty ($creation_time)) {
			$date = $creation_time;
		}

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
		$chapters = Arr::get($result, 'chapters', []);

		foreach ($streams as $stream_index => $stream )
		{
			$language = Arr::path ($stream, 'tags.language', '.', 'unk');
			$title = Arr::path ($stream, 'tags.title');

			if (empty ($language)) {
				$language = 'unk';
			}

			if ( ! empty ($this->_fields)) {
				$stream_data = array_intersect_key($stream, array_flip($this->_fields));
			} else {
				$stream_data = $stream;
			}

			if (isset($stream_data['bit_rate'])) {
				$stream_data['bit_rate_human'] = FFprobe::human_bitrate($stream_data['bit_rate']);
			}

			$codec_type     = Arr::get ($stream_data, 'codec_type');
			$r_frame_rate   = Arr::get ($stream_data, 'r_frame_rate', 0);
			$avg_frame_rate = Arr::get ($stream_data, 'avg_frame_rate', 0);
			unset($stream_data['codec_type']);

			$stream_data['language'] = $language;
			$stream_data['title'] = $title;

			if ($codec_type == 'video') {

				if (Arr::get ($stream, 'codec_name') == 'mjpeg') {
					continue;
				}

				$needed['width']  = Arr::get ($stream, 'width', 0);
				$needed['height'] = Arr::get ($stream, 'height', 0);
				$needed['is_hd']  = intval ($needed['width']>=1280);
				$needed['is_hdr'] = intval (Arr::get ($stream, 'color_primaries') == 'bt2020');
				$needed['is_10bit'] = intval (Arr::get ($stream_data, 'pix_fmt') == 'yuv420p10le');
				$needed['dar']    = Arr::get ($stream, 'display_aspect_ratio');

				$field_order = Arr::get ($stream, 'field_order', 'progressive');
				$needed['is_interlaced'] = intval ($field_order != 'progressive');

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

				if (isset($stream_data['avg_frame_rate'])) {
					unset($stream_data['avg_frame_rate']);
				}

				if (isset($stream_data['r_frame_rate'])) {
					unset($stream_data['r_frame_rate']);
				}
			}

			$needed['streams'][$codec_type][] = $stream_data;
		}

		if ( ! empty ($needed['streams'])) {
			$streams_ord = ['video'=>1, 'audio'=>2, 'subtitle'=>3, 'data'=>4];
			uksort ($needed['streams'], function ($a, $b) use ($streams_ord) {
				return (Arr::get($streams_ord, $a) > Arr::get($streams_ord, $b));
			});
		}

		foreach ($chapters as $chapter_data) {
			$start_time = Arr::get ($chapter_data, 'start_time', 0);
			$end_time = Arr::get ($chapter_data, 'end_time', 0);
			$title = Arr::path ($chapter_data, 'tags.title');
			$start = FFprobe::ts_format($start_time, [], TRUE);
			$end = FFprobe::ts_format($end_time, [], TRUE);
			$needed['chapters'][] = [
				'title'=>$title,
				'start'=>$start,
				'end'=>$end,
				'start_seconds'=>$start_time,
				'end_seconds'=>$end_time
			];
		}

		$result = $needed;
		unset ($needed);
	}

	public static function ts_format ($duration, $after = [], $show_seconds = FALSE)
	{
		$duration = intval ($duration);

		if ($duration < 60)
		{
			$show_seconds = TRUE;
		}

		$hh = $mm = $ss = 0;
		$after = (array) $after;
		$after_hh = Arr::get ($after, 'hh', ':');
		$after_mm = Arr::get ($after, 'mm', ':');
		$after_ss = Arr::get ($after, 'ss', '');

		if ($duration >= 3600)
		{
			$hh = intval($duration / 3600);
			$duration -= ($hh * 3600);
		}

		if ($duration >= 60)
		{
			$mm = intval($duration / 60);
			$ss = $duration - ($mm * 60);
		}
		else
		{
			$mm = 0;
			$ss = $duration;
		}

		$fmt = '<hour><after_hour><min><after_min><sec><after_sec>';

		$srch = ['<hour>', '<after_hour>', '<min>', '<after_min>', '<sec>', '<after_sec>'];
		$repl = ['', '', sprintf ('%02d', $mm), $after_mm, '', ''];

		if ($show_seconds)
		{
			$repl[4] = sprintf ('%02d', $ss);
			$repl[5] = $after_ss;
		}
		elseif ($repl[3] == ':')
		{
			$repl[3] = '';
		}

		if ($hh OR $after_hh == ':')
		{
			$repl[0] = sprintf ('%02d', $hh);
			$repl[1] = $after_hh;
		}

		return str_replace ($srch, $repl, $fmt);
	}

	public static function human_bitrate ($bytes, $decimals = 0)
	{
	    $size = ['bps','kbps','mbps'];
	    $factor = floor((strlen($bytes) - 1) / 3);
	    return sprintf("%.{$decimals}f", $bytes / pow(1000, $factor)) . ' ' . Arr::get($size, $factor);
	}
}
