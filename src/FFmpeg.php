<?php
namespace AppZz\VideoTools;
use AppZz\Helpers\Arr;
use AppZz\VideoTools\Exceptions\FFmpegException;
use AppZz\Helpers\Filesystem;
use AppZz\CLI\Process;
use Closure;

/**
 * FFmpeg Wrapper
 * @package VideoTools/FFmpeg
 * @version 1.4.x
 * @author CoolSwitcher
 * @license MIT
 * @link https://github.com/a-pp-zz/ffmpeg
 */
class FFmpeg
{
    /**
     * pathto to ffmpeg-bin
     * @var string
     */
    public static $binary = 'ffmpeg';

    /**
     * Scale filter
     * bicubic|lanczos
     * @var string
     */
    public static $scale_filter = 'bicubic';

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
        'debug' => FALSE,
        'log_dir' => FALSE,
        'output_dir' => FALSE,
        'overwrite' => FALSE,
        'experimental' => FALSE,
        'passthrough' => FALSE,
        'vb' => '2000k',
        'fps' => 0,
        'format' => NULL,
        'ab' => '96k',
        'ar' => '44100',
        'ac' => 2,
        'pix_fmt' => 'yuv420p',
        'metadata' => FALSE,
        'streams' => FALSE,
        'loglevel' => 'info',
        'extra' => '',
        'deint' => 0,
        'duration_output' => 0,
        'mapping' => [],
    ];

    private $_params = [];

    private $_vf_aliases = [];

    /**
     * allowed setters
     * @var array
     */
    private $_allowed_setters = [
        'vcodec',
        'acodec',
        'scodec',
        'codec',
        'mapping',
        'vn',
        'an',
        'sn',
        'width',
        'size',
        'fps',
        'format',
        'vframes',
        'vb',
        'ab',
        'ar',
        'ac',
        't',
        'ss',
        'experimental',
        'output_dir',
        'overwrite',
        'crf',
        'preset',
        'pix_fmt',
        'vn',
        'an',
        'sn',
        'extra',
        'watermark',
        'scale',
        'passthrough',
        'prefix',
        'metadata',
        'vf',
        'deint',
        'hw'
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

    /**
     * Progress holder
     * @var array
     */
    private $_progress = [];

    /**
     * @throws FFmpegException|Exceptions\FFprobeException
     */
    public function __construct($input = NULL)
    {
        if ($input) {
            $this->input($input);
        }

        $this->_params = $this->_default_params;
    }

    /**
     * @param $input
     * @return FFmpeg
     * @throws FFmpegException|Exceptions\FFprobeException
     */
    public static function factory($input = NULL)
    {
        return new FFmpeg ($input);
    }

    /**
     * @param Closure $trigger
     * @return $this
     */
    public function trigger(Closure $trigger)
    {
        $this->_trigger = $trigger;

        return $this;
    }

    /**
     * get param
     * @param string $param
     * @param bool $default
     * @return mixed
     */
    public function get($param, $default = FALSE)
    {
        return Arr::get($this->_params, $param, $default);
    }

    /**
     * Get fullpath to output
     * @return string
     */
    public function get_output()
    {
        return $this->_output;
    }

    /**
     * get metadata
     * @return mixed
     */
    public function get_metadata()
    {
        return $this->_metadata;
    }

    public function __toString()
    {
        return $this->_cmd;
    }

    /**
     * Get info of encoding params
     * @param string $stream_type
     * @return mixed
     */
    public function get_info(string $stream_type = '')
    {
        if ($stream_type) {
            return Arr::get($this->_info, $stream_type);
        }

        return $this->_info;
    }

    /**
     * Set params
     * @param string $param
     * @param mixed $value
     * @param mixed $extra
     * @return $this
     * @throws FFmpegException
     */
    public function set($param, $value = '', $extra = NULL)
    {
        if (!$this->_input) {
            throw new FFmpegException ('No input file!');
        }

        if (!empty ($param)) {
            if (in_array($param, $this->_allowed_setters)) {
                switch ($param) {
                    case 'watermark':
                        $this->_set_watermark($value, $extra);
                        $param = NULL;
                        break;

                    case 'ss':
                    case 't':
                        $value = $this->_check_time($value);
                        break;

                    case 'width':
                        $this->_set_size($value, $extra);
                        $param = NULL;
                        break;

                    case 'vcodec':
                        $this->_set_video_params($value, $extra);
                        $param = NULL;
                        break;

                    case 'acodec':
                        $value = $this->get('codec', $value);
                        $this->_set_audio_params($extra);
                        break;

                    case 'mapping':
                        $this->_set_mapping($value, $extra);
                        $param = NULL;
                        break;

                    case 'codec':
                        $this->_set('vcodec', $value);
                        $this->_set('acodec', $value);
                        $this->_set('scodec', $value);
                        break;
                }

                $this->_set($param, $value);
            } else {
                throw new FFmpegException ('Setting param ' . $param . ' not allowed');
            }
        }

        return $this;
    }

    /**
     * Fullpath to input file
     * @param $filename
     * @return $this
     * @throws Exceptions\FFprobeException
     * @throws FFmpegException
     */
    public function input($filename)
    {
        if (!empty ($filename) and is_file($filename)) {
            $this->_input = $filename;

            $this->_metadata = FFprobe::factory($this->_input)->probe(TRUE);

            if (empty ($this->_metadata)) {
                throw new FFmpegException ('Error get metadata from file!');
            }

            unset ($this->_output);

            return $this;
        } else {
            throw new FFmpegException ('Input file ' . $filename . ' not exists');
        }
    }

    /**
     * Relative filename of output file
     * @param string $filename
     * @return $this
     */
    public function output(string $filename = '')
    {
        if ( ! empty ($filename)) {
            $this->_output = $filename;
        }

        return $this;
    }

    /**
     * Trim video
     * @param $start
     * @param $end
     * @return $this
     * @throws FFmpegException
     */
    public function trim($start, $end)
    {
        $start = FFprobe::format_numbers($this->_time_to_seconds($start));
        $end = FFprobe::format_numbers($this->_time_to_seconds($end));

        if ($end <= $start) {
            throw new FFmpegException('Wrong start and end params');
        }

        $delta = $end - $start;
        $this->_set('duration_output', FFprobe::ts_format($delta, [], TRUE));
        $this->set('ss', $start);
        $this->set('t', $delta);

        return $this;
    }

    /**
     * Cut portion of video
     * @param $duration
     * @param $from_start
     * @return $this
     * @throws FFmpegException
     */
    public function cut($duration, $from_start = TRUE)
    {
        $duration = FFprobe::format_numbers($this->_time_to_seconds($duration));
        $this->set('t', $duration);
        $this->_set('duration_output', FFprobe::ts_format($duration, [], TRUE));

        if ($from_start) {
            $this->set('ss', 0);
        } else {
            $duration_total = Arr::get($this->_metadata, 'duration', 0);
            $this->set('ss', ($duration_total - $duration));
        }

        return $this;
    }

    /**
     * Screenshot params
     * @param float|string $ss float<1|time|second
     * @param int $size
     * @return $this
     * @throws FFmpegException
     */
    public function screenshot($ss = 0, int $size = 0)
    {
        $this->_reset_params(['overwrite', 'prefix', 'debug', 'loglevel', 'log_dir', 'output_dir']);

        if (is_float($ss) and $ss < 1 and $ss > 0) {
            $ss = Arr::get($this->_metadata, 'duration') * $ss;
        }

        $this->set('t', 0.0001, FALSE);

        if ($size > 0) {
            $this->set('scale', $size . ':-1');
        }

        $this->set('vn', TRUE);
        $this->set('an', TRUE);
        $this->set('format', 'image2');
        $this->set('ss', $ss, TRUE);
        $this->set('vframes', 1);

        return $this;
    }

    /**
     * Set debug params
     * @param boolean $enabled
     * @param string|null $log_dir
     * @param boolean $delete delete if success
     * @param string $loglevel
     * @return FFmpeg
     * @throws FFmpegException
     */
    public function debug(bool $enabled = FALSE, string $log_dir = NULL, bool $delete = TRUE, string $loglevel = 'repeat+info')
    {
        $this->_set('debug', $enabled);
        $this->_set_loglevel($loglevel);
        $this->_set_log_file($log_dir, $delete);

        return $this;
    }

    /**
     * Prepare params
     * @return FFmpeg
     * @throws FFmpegException
     */
    public function prepare()
    {
        if ( ! $this->_input) {
            throw new FFmpegException ('No input file!');
        }

        $this->_set_output_by_format();

        $streams = Arr::get($this->_metadata, 'streams');

        if (empty ($streams)) {
            throw new FFmpegException ('Streams not found in file!');
        }

        $params_cli = new \stdClass;

        $passed = (array)$this->get('passthrough');
        $mapping = $this->get('mapping');
        $crf = $this->get('crf');
        $preset = $this->get('preset');
        $fps = $this->get('fps');
        $ss = $this->get('ss');
        $t = $this->get('t');
        $vframes = $this->get('vframes');
        $loglevel = $this->get('loglevel');
        $metadata = $this->get('metadata');
        $fmt = $this->get('format');
        $size = $this->get('size');
        $scale = $this->get('scale');
        $vn = $this->get('vn', FALSE);
        $an = $this->get('an', FALSE);
        $sn = $this->get('sn', FALSE);
        $extra = $this->get('extra');
        $deint = $this->get('deint');
        $watermark = $this->get('watermark');
        $hw = $this->get('hw');

        $is_interlaced = Arr::get($this->_metadata, 'is_interlaced');

        $vc_active = $ac_active = FALSE;

        if ($vframes) {
            $params_cli->transcode[] = sprintf('-vframes %d', $vframes);
        }

        if ($hw) {
            $params_cli->input[] = "-hwaccel auto";
        }

        if ($loglevel) {
            $params_cli->input[] = "-loglevel {$loglevel}";
        }

        if ($ss !== FALSE) {
            $params_cli->input[] = "-ss {$ss}";
        }

        $params_cli->input[] = sprintf("-i %s", escapeshellarg($this->_input));

        if ($this->get('vcodec') != 'copy' and $watermark !== FALSE) {
            $params_cli->input[] = sprintf("-loop 1 -i %s", escapeshellarg($watermark['file']));
        } else {
            $watermark = NULL;
        }

        if (empty($mapping)) {
            $mapping = [
                'video' => ['count' => 1, 'langs' => []],
                'audio' => ['count' => 1, 'langs' => []],
                'subtitle' => ['count' => 1, 'langs' => []],
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

        foreach ($streams as $stream_type => $stream) {
            $stream_counter = 0;

            $cur_stream_langs = (array)Arr::path($mapping, $stream_type . '.langs', '.', []);
            $cur_stream_count = Arr::path($mapping, $stream_type . '.count', '.', 0);
            $cur_stream_index = (int)Arr::path($mapping, $stream_type . '.index', '.', -2);

            if (!isset ($mapping[$stream_type])) {
                continue;
            }

            $stream_type_alpha = substr($stream_type, 0, 1);
            $def_codec = $this->get($stream_type_alpha . 'codec', 'copy');
            $gl_codec = $this->get('codec');
            $def_bitrate = $this->get($stream_type_alpha . 'b');

            if (is_numeric($def_bitrate)) {
                $def_bitrate = FFprobe::human_bitrate($def_bitrate);
            }

            if ($cur_stream_count > 0) {
                foreach ($stream as $index => $stream_data) {
                    if ($stream_counter === $cur_stream_count) {
                        break;
                    }

                    $stream_index = (int)Arr::get($stream_data, 'index', -1);

                    if ($cur_stream_index >= 0 and ($stream_index != $cur_stream_index)) {
                        continue;
                    }

                    $stream_lang = Arr::get($stream_data, 'language');

                    if (!$stream_lang or $stream_lang == 'unk') {
                        $stream_lang = 'eng';
                    }

                    $stream_title = Arr::get($stream_data, 'title', '');

                    if (!in_array(Arr::get($stream_data, 'codec_name'), $passed)) {
                        $codec = $def_codec;
                        $bitrate = $def_bitrate;
                    } else {
                        $codec = 'copy';
                    }

                    if ($codec == 'copy') {
                        $bitrate = Arr::get($stream_data, 'bit_rate', 0);

                        if ($bitrate and is_numeric($bitrate)) {
                            $bitrate = FFprobe::human_bitrate($bitrate);
                        }
                    }

                    if (!empty ($gl_codec)) {
                        $params_cli->transcode[] = sprintf('-codec %s', $gl_codec);
                    } elseif (in_array($stream_type_alpha, ['v', 'a', 's'])) {
                        $params_cli->transcode[] = sprintf('-c:%s %s', $stream_type_alpha, $codec);
                    }

                    //$params_cli->transcode[] = sprintf('-c:%s:%d %s', $stream_type_alpha, $stream_counter, $codec);

                    if ($metadata === TRUE) {
                        $params_cli->transcode[] = sprintf('-metadata:s:%s:%d title="%s" -metadata:s:%s:%d language="%s"', $stream_type_alpha, $stream_counter, escapeshellarg($stream_title), $stream_type_alpha, $stream_counter, $stream_lang);
                    }

                    $add_map_stream = TRUE;

                    if (!empty ($cur_stream_langs) and !empty ($stream_lang)) {
                        if (!in_array($stream_lang, $cur_stream_langs)) {
                            $add_map_stream = FALSE;
                            array_pop($params_cli->transcode);
                        }
                    }

                    if ($add_map_stream) {
                        $map_index = sprintf('0:%d', $stream_index);

                        if ($stream_type == 'video' and $this->get('vcodec') != 'copy') {
                            if (!$fps) {
                                $fps = Arr::get($stream_data, 'fps', 0);
                            }

                            if ($deint and $is_interlaced) {
                                $this->_set_vf('yadif=1', $map_index);
                            }

                            if ($scale) {
                                $this->_set_vf($this->_set_scale_vf($scale), $map_index);
                            }

                            $this->_vf_aliases[$map_index] = 'bg';

                            if (!empty ($watermark) and !$this->get('vf')) {
                                $this->_set_vf('', $map_index);
                            }

                            if ($this->get('vf') !== FALSE) {
                                $map_index = NULL;
                            }
                        }

                        if (!empty ($map_index)) {
                            $params_cli->map[] = '-map ' . $map_index;
                        }

                        $stream_counter++;
                        $bitrate = ($stream_type == 'video' and $crf) ? 'crf ' . $crf : $bitrate;
                        $this->_set_info($stream_type, $index, $codec, $bitrate);
                    }

                    if ($stream_type == 'audio' and !$ac_active and $codec != 'copy') {
                        $ac_active = TRUE;
                    } elseif ($stream_type == 'video' and !$vc_active and $codec != 'copy') {
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

        if (!empty ($watermark)) {
            $this->_info['watermark'][] = basename($watermark['file']);
        }

        if ($vn) {
            if ($fmt != 'image2') {
                $params_cli->transcode[] = '-vn';
            } elseif ($scale) {
                $params_cli->filter = $this->_set_scale_vf($scale);
            }
        }

        if ($an) {
            $params_cli->transcode[] = '-an';
        }

        if ($sn) {
            $params_cli->transcode[] = '-sn';
        }

        if ($vc_active) {
            if (($pix_fmt = $this->get('pix_fmt'))) {
                $params_cli->transcode[] = sprintf('-pix_fmt %s', $pix_fmt);
            }

            if ($crf) {
                $params_cli->transcode[] = sprintf('-crf %d', $crf);
            } else {
                $params_cli->transcode[] = sprintf('-b:v %s', $this->get('vb'));
            }

            if ($preset) {
                $params_cli->transcode[] = sprintf('-preset %s', $preset);
            }

            if ($fps) {
                $params_cli->transcode[] = sprintf('-r %d', $fps);
            }

            if ($size) {
                $params_cli->transcode[] = sprintf('-s %s', $size);
            }

            $params_cli->filter = $this->_build_vf();

            if ($this->get('faststart')) {
                $params_cli->output[] = '-movflags faststart';
            }
        }

        if (!empty ($params_cli->filter)) {
            $params_cli->filter = sprintf('-filter_complex %s', escapeshellarg($params_cli->filter));
        }

        if ($ac_active) {
            $params_cli->transcode[] = sprintf("-b:a %s -ar %d -ac %d", $this->get('ab'), $this->get('ar'), $this->get('ac'));
        }

        if ($this->get('overwrite')) {
            $params_cli->output[] = '-y';
        }

        if ($this->get('experimental')) {
            $params_cli->output[] = '-strict experimental';
        }

        if ($fmt) {
            $params_cli->transcode[] = sprintf('-f %s', $fmt);
        }

        if ($extra) {
            $params_cli->transcode[] = $extra;
        }

        if ($t !== FALSE) {
            $params_cli->output[] = "-t {$t}";
        }

        $params_cli->output[] = escapeshellarg($this->_output);

        $params = [];

        if (!empty ($params_cli->input)) {
            $params[] = implode(' ', $params_cli->input);
        }

        if (!empty ($params_cli->map)) {
            $params[] = implode(' ', $params_cli->map);
        }

        if (!empty ($params_cli->filter)) {
            $params[] = trim($params_cli->filter);
        }

        if (!empty ($params_cli->transcode)) {
            $params_cli->transcode = array_unique($params_cli->transcode);
            $params[] = implode(' ', $params_cli->transcode);
        }

        if (!empty ($params_cli->output)) {
            $params[] = implode(' ', $params_cli->output);
        }

        $this->_cmd = FFmpeg::$binary . ' ' . implode(' ', $params);

        return $this;
    }

    /**
     * Run process
     * @param bool $progress
     * @return bool
     * @throws FFmpegException
     */
    public function run(bool $progress = FALSE)
    {
        if (empty($this->_cmd)) {
            throw new FFmpegException ('Empty cmdline');
        }

        if ($this->_logfile) {
            fputs($this->_logfile->handle, $this->_cmd . "\n\n");
        }

        $process = Process::factory($this->_cmd, Process::STDERR);

        if ($progress) {
            $process->trigger('all', function ($data) {
                call_user_func([$this, 'get_progress'], $data);
            });
            $this->_call_trigger($this->get_info(), 'start');
            $this->_call_trigger(0, 'progress');
        } else {
            $this->_call_trigger('Started', 'start');
        }

        $this->_progress['exec_time'] = microtime(true);
        $exitcode = $process->run(TRUE);

        if ($this->_logfile and $this->_logfile->handle) {
            if (!$progress) {
                fputs($this->_logfile->handle, $process->get_log(Process::STDERR, TRUE));
            }
            fclose($this->_logfile->handle);
        }

        if ($exitcode === 0) {
            $this->_progress['exec_time'] = FFprobe::ts_format(microtime(true) - $this->_progress['exec_time']);
            $fps = Arr::get($this->_progress, 'fps', []);

            if ( ! empty ($fps)) {
                $this->_progress['avg_fps'] = round ((array_sum ($fps) / count ($fps)), 2);
            } else {
                $this->_progress['avg_fps'] = 0;
            }

            $this->_progress['progress'] = 100;
            $this->_call_trigger('Finish', 'finish');

            if ($this->_logfile and $this->_logfile->path and $this->_logfile->delete) {
                unlink($this->_logfile->path);
            }
        } else {
            $this->_call_trigger('Error', 'error');
        }

        $this->_params = $this->_default_params;

        return ($exitcode === 0);
    }

    /**
     * Get output from pipe, calc progress and call trigger
     * @param mixed $data
     * @return bool
     */
    public function get_progress($data)
    {
        if (empty($data)) {
            return FALSE;
        }

        $data = Arr::get($data, Process::STDERR);
        $duration = Arr::get($this->_progress, 'duration');

        if (empty($duration)) {
            $duration = $this->_get_output_duration(FALSE);
            $this->_progress['duration'] = $duration;
        }

        $buffer = Arr::get($data, 'buffer');

        if (!empty ($buffer)) {
            $all_messages = implode("\n", $buffer);

            if ($this->_logfile) {
                fputs($this->_logfile->handle, $all_messages . "\n");
            }
        } else {
            return FALSE;
        }

        if (preg_match("#fps=\s?(?<fps>[\d:\.]*).*time=(?<time>[\d:\.]*)#D", $all_messages, $m)) {
            $time = Arr::get($m, 'time', '0:0:0');
            $fps = (float)Arr::get($m, 'fps', 0);
            $this->_progress['fps'][] = $fps;
            $time = $this->_time_to_seconds($time);

            $progress = $time / max($duration, 0.01);
            $progress = (int)($progress * 100);
            $this->_progress['progress'] = $progress;
            $this->_call_trigger($progress, 'progress');
        }

        return TRUE;
    }

    /**
     * Screenshot via static method
     * @param string $input
     * @param mixed $ss
     * @param int $width
     * @param mixed $output
     * @param mixed $output_dir
     * @return bool
     */
    public static function take_screenshot(string $input, $ss = 0, int $width = 0, $output = FALSE, $output_dir = FALSE)
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
     * @param array $files
     * @param string $output
     * @param $tempdir
     * @param $log
     * @return bool
     */
    public static function concat(array $files, string $output, $tempdir = NULL, &$log = NULL)
    {
        if (empty($tempdir)) {
            $tempdir = sys_get_temp_dir();
        }

        $input = tempnam($tempdir, 'ffmpeg_cc');
        $fh = fopen($input, 'wb');

        foreach ($files as $file) {
            $file = escapeshellarg($file);
            $file = str_replace(['"', '\\'], ["'", '/'], $file);
            $line = sprintf("file %s\n", $file);
            fputs($fh, $line);
        }

        fclose($fh);

        $cmd = sprintf('%s -loglevel repeat+info -f concat -safe 0 -i %s -c copy -y %s', FFmpeg::$binary, escapeshellarg($input), escapeshellarg($output));
        $process = Process::factory($cmd, Process::STDERR);
        $exitcode = $process->run(true);
        unlink($input);
        $log = $process->get_log(Process::STDERR, '');

        return ($exitcode === 0);
    }

    /**
     * Extract audio from video
     * @param string $file
     * @param array $params
     * @param Closure|null $callback
     * @return array|false
     * @throws Exceptions\FFprobeException
     * @throws FFmpegException
     */
    public static function extract_audio(string $file, array $params = [], Closure $callback = null)
    {
        $ret = [];
        $metadata = FFprobe::factory($file)->probe(TRUE);
        $metadata = Arr::path($metadata, 'streams.audio');

        if (!$metadata) {
            return FALSE;
        }

        foreach ($metadata as $values) {
            $index = Arr::get($values, 'index', FALSE);
            $codec_name = Arr::get($values, 'codec_name', 'mp4');

            if ($index === FALSE) {
                continue;
            }

            $ff = FFmpeg::factory($file)
                ->set('mapping', 'audio', ['count' => 1, 'index' => $index])
                ->set('acodec', Arr::get($params, 'codec', 'aac'),
                    [
                        'b' => Arr::get($params, 'b', '128k'),
                        'ac' => Arr::get($params, 'ac', 2),
                        'ar' => Arr::get($params, 'ar', 44100),
                    ])
                ->set('output_dir', Arr::get($params, 'output_dir'))
                ->set('overwrite', FALSE)
                ->set('metadata', TRUE)
                ->set('prefix', '-audio-' . $index)
                ->set('format', $codec_name)
                ->set('sn', TRUE)
                ->set('vn', TRUE)
                ->prepare();

            if ($callback) {
                $ff->trigger($callback);
                $progress = TRUE;
            } else {
                $progress = FALSE;
            }

            if ($ff->run($progress)) {
                $ret[] = $ff->get_output();
            }
        }

        return $ret;
    }

    /**
     * Param setter
     * @param string|bool|null $param
     * @param string|bool|int|float $value
     * @return $this
     */
    private function _set($param, $value = FALSE)
    {
        if ($param) {
            $this->_params[$param] = $value;
        }

        return $this;
    }

    /**
     * Set VF for selected stream
     * @param string $value
     * @param string $index
     * @return $this
     */
    private function _set_vf(string $value = '', string $index = '0:v')
    {
        if (!empty ($index)) {
            $this->_params['vf'][$index][] = $value;
        }

        return $this;
    }

    /**
     * Compile all video filters if exists
     * @return string
     */
    private function _build_vf()
    {
        $filter = '';
        $vf = $this->get('vf');

        if (!empty ($vf)) {
            foreach ($vf as $key => $value) {
                $alias = Arr::get($this->_vf_aliases, $key);

                if ($alias) {
                    $vf_inline = implode(',', $value);
                    $vf[$alias] = [
                        'index' => $key,
                        'value' => (!empty ($vf_inline) ? '[' . $key . ']' . $vf_inline : ''),
                    ];
                    unset ($vf[$key]);
                }
            }

            if (!empty ($vf['bg'])) {
                $filter .= !empty ($vf['bg']['value']) ? $vf['bg']['value'] : '';
                $watermark = $this->get('watermark');

                if ($watermark) {
                    $overlay = Arr::path($watermark, 'overlay');

                    if (isset ($vf['wm']) and !empty ($overlay)) {
                        $filter .= sprintf('%s%s', (!empty ($vf['bg']['value']) ? '[bg];' : ''), (!empty ($vf['wm']['value']) ? $vf['wm']['value'].'[wm];' : ''));
                        $filter .= sprintf('[%s][%s]%s', (!empty ($vf['bg']['value']) ? 'bg' : $vf['bg']['index']), (!empty ($vf['wm']['value']) ? 'wm' : $vf['wm']['index']), $overlay);
                    }
                }
            }
        }

        return $filter;
    }

    /**
     * @param string $stream_type
     * @param int $stream_index
     * @param string $codec
     * @param string|null $bitrate
     * @return $this
     */
    private function _set_info(string $stream_type, int $stream_index = 0, string $codec = '', string $bitrate = NULL)
    {
        $codec_ori = Arr::path($this->_metadata, 'streams.' . $stream_type . '.' . $stream_index . '.codec_name');

        if (!$codec_ori) {
            return FALSE;
        }

        $duration = Arr::get($this->_metadata, 'duration_human');
        $duration_o = $this->_get_output_duration(TRUE);

        switch ($stream_type) :
            case 'video':
                $info_text_tpl = '%dx%d %s [%s] => %s %s [%s]';
                $width = Arr::get($this->_metadata, 'width', 0);
                $height = Arr::get($this->_metadata, 'height', 0);
                $size = $this->get('size');

                if (empty($size)) {
                    $size = sprintf('%dx%d', $width, $height);
                }

                $info_text = trim(sprintf($info_text_tpl, $width, $height, $codec_ori, $duration, $size, $codec, $duration_o));
                break;
            case 'audio':
                $info_text_tpl = '%s [%s] => %s [%s]';
                $info_text = sprintf($info_text_tpl, $codec_ori, $duration, $codec, $duration_o);
                break;
            default:
                $info_text_tpl = '%s => %s';
                $info_text = sprintf($info_text_tpl, $codec_ori, $codec);
                break;
        endswitch;

        if ($codec != 'copy' and $bitrate) {
            $info_text .= ' @ ' . $bitrate;
        }

        $this->_info[$stream_type][$stream_index] = $info_text;
        return $this;
    }

    private function _set_output()
    {
        if (empty ($this->_input)) {
            throw new FFmpegException ('Input file not exists');
        }

        $output_dir = $this->get('output_dir');

        if ( ! empty ($this->_output) and empty ($output_dir)) {
            $output_dir = pathinfo ($this->_output, PATHINFO_DIRNAME);
        }

        if (empty ($output_dir)) {
            $output_dir = dirname($this->_input);
        }

        if ( ! is_writable($output_dir)) {
            throw new FFmpegException ($output_dir . ' is not writeable');
        }

        if (empty($this->_output)) {
            $this->_output = $this->_input;
        }

        $this->_output = $output_dir . DIRECTORY_SEPARATOR . basename($this->_output);
    }

    private function _set_output_by_format()
    {
        $this->_set_output();
        $format = $this->get('format');
        $unset = TRUE;

        switch ($format) :
            case 'image2':
                $format = 'jpg';
                $unset = FALSE;
                break;
            case 'aac':
                $format = 'm4a';
                break;
            case 'ac3':
            case 'eac3':
                $format = 'ac3';
                break;
            case 'mp3':
                $format = 'mp3';
                break;
            default:
                $format = 'mp4';
                $unset = FALSE;
                break;
        endswitch;

        $this->_output = Filesystem::new_extension($this->_output, $format);

        if (!empty ($unset)) {
            $this->_set('format', '');
        }

        $prefix = $this->get('prefix');

        if (!empty ($prefix)) {
            $this->_output = Filesystem::add_prefix($this->_output, $prefix);
        }

        if ($this->get('overwrite') !== TRUE or $this->_output == $this->_input) {
            $this->_output = Filesystem::check_filename($this->_output);
        }

        if (file_exists($this->_output) and !is_writable($this->_output)) {
            throw new FFmpegException ($this->_output . ' is not writeable');
        }

        return $this;
    }

    /**
     * @param string $log_dir
     * @param bool $delete
     * @return $this
     * @throws FFmpegException
     */
    private function _set_log_file(string $log_dir = '', bool $delete = TRUE)
    {
        if ($log_dir) {
            if (!is_dir($log_dir)) {
                throw new FFmpegException ('Logdir not exists');
            }

            if (!is_writable($log_dir)) {
                throw new FFmpegException ('Logdir not writeable');
            }

            $logfile = $log_dir . DIRECTORY_SEPARATOR . pathinfo($this->_input, PATHINFO_FILENAME) . '.log';
            $this->_logfile = new \stdClass;
            $this->_logfile->handle = fopen($logfile, 'wb');
            $this->_logfile->path = $logfile;
            $this->_logfile->delete = $delete;
        }

        return $this;
    }

    /**
     * Set watermark param
     *
     * @param string $file
     * @param array $params
     * @return $this
     * @throws FFmpegException
     */
    private function _set_watermark($file, array $params = [])
    {
        $m0 = (int)Arr::path($params, 'margins.0');
        $m1 = (int)Arr::path($params, 'margins.1');
        $align = Arr::get($params, 'align', 'bottom-right');
        $size = Arr::get($params, 'size', 0);
        $fade_in = (int)Arr::get($params, 'fade_in', -1);
        $fade_out = (int)Arr::get($params, 'fade_out', -1);
        $fade_duration = (float)Arr::get($params, 'fade_duration', 0.5);
        $opacity = Arr::get($params, 'opacity', 1);
        $overlay = 'overlay=';
        $index = '1:v';

        $fade_tpl = 'fade=%s:st=%d:d=%s:alpha=%d';

        if ($size) {
            $this->_set_vf($this->_set_scale_vf($size . ':-1'), $index);
        }

        if ($opacity > 0 and $opacity < 1) {
            $this->_set_vf('colorchannelmixer=aa=' . $opacity, $index);
        }

        if ($fade_in >= 0) {
            $vf = sprintf($fade_tpl, 'in', $fade_in, $fade_duration, 1);
            $this->_set_vf($vf, $index);
        }

        if ($fade_out > 0) {
            $vf = sprintf($fade_tpl, 'out', $fade_out, $fade_duration, 1);
            $this->_set_vf($vf, $index);
        }

        switch ($align) {
            case 'top-left':
                $overlay .= abs($m0) . ':' . abs($m1);
                break;

            case 'top-right':
                $overlay .= 'main_w-overlay_w-' . $m0 . ':' . abs($m1);
                break;

            case 'bottom-left':
                $overlay .= abs($m0) . ':' . 'main_h-overlay_h-' . abs($m1);
                break;

            case 'center':
                $overlay .= '(main_w-overlay_w)/2:(main_h-overlay_h)/2';
                break;

            case 'bottom-right':
            default:
                $overlay .= 'main_w-overlay_w-' . abs($m0) . ':' . 'main_h-overlay_h-' . abs($m1);
                break;
        }

        $overlay .= ':shortest=1';

        $this->_params['watermark'] = [
            'file' => $file,
            'overlay' => $overlay,
            'index' => $index,
        ];

        $this->_vf_aliases[$index] = 'wm';

        $vf = $this->get('vf');

        if (!isset($vf[$index])) {
            $this->_set_vf('', $index);
        }

        return $this;
    }

    /**
     * set size and scale params
     * @param mixed $height [description]
     */
    private function _set_size($width, $height = FALSE)
    {
        if ( ! $width) {
            return $this;
        }

        $width_meta = (int)Arr::get($this->_metadata, 'width', 0);
        $height_meta = (int)Arr::get($this->_metadata, 'height', 0);
        $is_vertical = (int)Arr::get($this->_metadata, 'is_vertical', 0);

        if (!$width_meta or !$height_meta) {
            throw new FFmpegException('Can\'t get video dimensions from metadata! No video stream?');
        }

        if (is_numeric($height) and intval($height) > 0) {
            $this->_params['scale'] = sprintf('%d:-1', $width);
            $this->_params['size']  = sprintf('%dx%d', $width, $height);

            if ($is_vertical) {
                $this->_params['size']  = sprintf('%dx%d', $height, $width);
                $this->_params['scale'] = sprintf('%d:%d', $height, $width);
            }
        } elseif ($height === TRUE) {

            $dar = Arr::get($this->_metadata, 'dar_num');

            if (!$dar) {
                $dar = $width_meta / $height_meta;
            }

            $size = NULL;

            if ($width_meta >= $width) {
                switch ($width):
                    case 1920:
                        $size = 'hd1080';
                        $height = 1080;
                    break;
                    case 1280:
                        $size = 'hd720';
                        $height = 720;
                    break;
                endswitch;
            }

            if (empty ($size) and $dar) {
                $width = min([$width, $width_meta]);
                $height = intval($width / $dar);

                if ($height % 2 !== 0) {
                    $height++;
                }

                $size = sprintf('%dx%d', $width, $height);
            }

            if ($is_vertical) {
                $size = sprintf('%dx%d', $height, $width);
            }

            $this->_params['size'] = $this->_params['scale'] = $size;
        } else {
            $this->_params['scale'] = sprintf('%d:-1', $width);
        }

        return $this;
    }

    private function _set_mapping($type = 'video', array $params)
    {
        $default = ['count' => -1, 'langs' => []];
        $this->_params['mapping'][$type] = array_merge($default, $params);

        return $this;
    }

    private function _set_scale_vf($scale)
    {
        return sprintf ('scale=%s:flags=%s', $scale, FFmpeg::$scale_filter);
    }

    private function _set_video_params($vcodec, $params = [])
    {
        $params = (array)$params;
        $vb = Arr::get($params, 'b', '2000k');
        $crf = Arr::get($params, 'crf');
        $preset = Arr::get($params, 'preset');
        $pix_fmt = Arr::get($params, 'pix_fmt');
        $hw = Arr::get($params, 'hw');
        $_10bit = Arr::get($params, '10bit');
        $codec = $this->get('codec');

        if (!empty ($codec)) {
            $vcodec = $codec;
        }

        $extra = '';

        if (!empty ($hw) and !empty ($vcodec) and $vcodec != 'copy') {
            $this->_set('hw', 1);

            if (preg_match('#.*(libx264|h264).*#iuD', $vcodec)) {
                $vcodec = 'h264';
            } elseif (preg_match('#.*(libx265|h265|hevc).*#iuD', $vcodec)) {
                $vcodec = 'hevc';

                if ($_10bit) {
                    $pix_fmt = 'yuv420p10le';
                    $extra = '-profile main10 -tag:v hvc1';
                }
            }

            switch ($hw) :
                case 'mac':
                    $vcodec .= '_videotoolbox';
                    if ($_10bit) {
                        $pix_fmt = 'p010le';
                    }
                    break;
                case 'intel':
                    $vcodec .= '_qsv';
                    break;
                case 'nvidia':
                    $vcodec .= '_nvenc';
                    break;
                case 'amd':
                    $vcodec .= '_amf';
                    break;
            endswitch;
        }

        $this->_set('vcodec', $vcodec);

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

        if ($extra) {
            $this->set('extra', $extra);
        }

        return $this;
    }

    private function _set_audio_params($params = [])
    {
        $params = (array)$params;
        $ab = Arr::get($params, 'b', '96k');
        $ac = Arr::get($params, 'ac', 2);
        $ar = Arr::get($params, 'ar', 44100);

        $this->set('ab', $ab);
        $this->set('ar', $ar);
        $this->set('ac', $ac);

        return $this;
    }

    private function _call_trigger($message = '', $action = 'message')
    {
        if ($this->_trigger) {
            $data = [
                'action' => $action,
                'message' => $message,
                'input' => $this->_input,
                'output' => $this->_output,
            ];

            if ($action == 'finish') {
                $metadata = FFprobe::factory($this->_output)->probe(TRUE);
                $data['i_duration'] = Arr::get($this->_metadata, 'duration');
                $data['i_duration_human'] = Arr::get($this->_metadata, 'duration_human');
                $data['o_duration'] = Arr::get($metadata, 'duration');
                $data['o_duration_human'] = Arr::get($metadata, 'duration_human');
                $data['avg_fps'] = Arr::get($this->_progress, 'avg_fps', 0);
                $data['exec_time'] = Arr::get($this->_progress, 'exec_time', 0);
            }

            call_user_func($this->_trigger, $data);
        }
    }

    /**
     * Set loglevel
     * @param string $loglevel
     * @return $this
     * @throws FFmpegException
     */
    private function _set_loglevel(string $loglevel)
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

        if (!preg_match('#^(repeat\+)?(' . implode('|', $allowed) . ')$#D', $loglevel)) {
            throw new FFmpegException ('Wrong leglevel param: ' . $loglevel);
        }

        $this->_set('loglevel', $loglevel);

        return $this;
    }

    /**
     * Time to seconds conv
     * @param $time
     * @return float|int|string
     */
    private function _time_to_seconds($time)
    {
        if (is_numeric($time)) {
            return $time;
        }

        $parts = explode(':', $time);

        if (!$parts) {
            return 0;
        }

        $h = (int)Arr::get($parts, 0, 0);
        $m = (int)Arr::get($parts, 1, 0);
        $s = (int)Arr::get($parts, 2, 0);

        return $h * 3600 + $m * 60 + $s;
    }

    /**
     * Time format checker
     * @param $time
     * @return float|int|string
     * @throws FFmpegException
     */
    private function _check_time($time)
    {
        if (is_numeric($time) or is_float($time)) {
            return $time;
        } elseif (preg_match('#^(\d{1,2}:)?\d{1,2}:?\d{1,2}$#iu', $time)) {
            return escapeshellarg($time);
        } else {
            throw new FFmpegException ('Wrong time param: ' . $time);
        }
    }

    /**
     * @param bool $human_readable
     * @return false|float|int|string
     */
    private function _get_output_duration(bool $human_readable = false)
    {
        $duration = $this->get('duration_output');

        if ($duration) {
            return $duration;
        }

        $duration = Arr::get($this->_metadata, 'duration', 0);

        if ($human_readable) {
            $duration = FFprobe::ts_format($duration, [], TRUE);
        }

        return $duration;
    }

    /**
     * Reset unneeded params
     * @param array $keep_params
     * @return array
     */
    private function _reset_params(array $keep_params = [])
    {
        if (!empty ($keep_params)) {
            $keep_params = array_fill_keys($keep_params, 1);
            $old_params = array_intersect_key($this->_params, $keep_params);
            $this->_params = array_merge($this->_default_params, $old_params);
        } else {
            $this->_params = $this->_default_params;
        }

        return $this->_params;
    }
}
