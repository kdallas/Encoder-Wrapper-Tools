<?php

class Probe
{
    // Returns: ['width', 'height', 'video_codec', 'is_hdr', 'hdr_mastering', 'primaries', 'audio_codec', 'audio_channels', 'subtitles' => [], 'chapters' => bool]
    public static function analyze($filePath) {
        if (!file_exists(Config::FFPROBE)) {
            throw new Exception("ffprobe not found at: " . Config::FFPROBE);
        }

        // --- PASS 1: Metadata (Headers) ---
        // FIX: We removed the restrictive "show_entries" filter for streams.
        // We now use -show_streams -show_chapters to get EVERYTHING (including tags/language).
        $cmd1 = sprintf('"%s" -hide_banner -loglevel warning -print_format json -show_chapters -show_streams -i "%s" 2>&1',
            Config::FFPROBE, $filePath
        );
        $data1 = self::getJsonOutput($cmd1);

        // --- PASS 2: HDR Data (Packets) ---
        // We scan 50 packets to find the Video Frame Side Data (Mastering Display Metadata).
        $cmd2 = sprintf('"%s" -hide_banner -loglevel warning -print_format json -show_frames -read_intervals "%%+#50" -show_entries "frame=side_data_list" -i "%s" 2>&1',
            Config::FFPROBE, $filePath
        );
        $data2 = self::getJsonOutput($cmd2);

        if (!$data1) return null; // Data1 is critical. Data2 is optional (HDR only).

        // Init
        $width = 0; $height = 0; 
        $primaries = null; 
        $videoCodec = 'unknown';
        $audioCodec = null; 
        $audioChannels = 0;
        $subtitles = [];

        // Helper: Case-insensitive property getter for Tags
        $getTag = function($obj, $key) {
            if (empty($obj) || !is_object($obj)) return null;
            // Check exact match first
            if (isset($obj->$key)) return $obj->$key;
            // Check case-insensitive
            foreach ($obj as $k => $v) {
                if (strcasecmp($k, $key) === 0) return $v;
            }
            return null;
        };

        // Iterate streams (From Pass 1)
        if (isset($data1->streams)) {
            foreach ($data1->streams as $stream) {
                if (isset($stream->codec_type)) {
                    if ($stream->codec_type === 'video') {
                        $width = intval($stream->width ?? 0);
                        $height = intval($stream->height ?? 0);
                        $primaries = $stream->color_primaries ?? null;
                        $videoCodec = $stream->codec_name ?? 'unknown';
                    } 
                    elseif ($stream->codec_type === 'audio') {
                        // Capture ONLY the first audio stream we encounter
                        if ($audioCodec === null) { 
                            $audioCodec = $stream->codec_name ?? 'opus';
                            $audioChannels = intval($stream->channels ?? 0);
                        }
                    }
                    elseif ($stream->codec_type === 'subtitle') {
                        // Capture Subtitle Data
                        $tags = $stream->tags ?? null;
                        $disp = $stream->disposition ?? null;
                        
                        // Now that we have the full stream object, getTag will find 'language' or 'LANGUAGE'
                        $lang = $getTag($tags, 'language') ?? 'und';
                        $title = $getTag($tags, 'title') ?? '';
                        
                        $forced = isset($disp->forced) ? $disp->forced : 0;
                        $sdh    = isset($disp->hearing_impaired) ? $disp->hearing_impaired : 0;

                        $subtitles[] = [
                            'index'  => $stream->index,
                            'codec'  => $stream->codec_name ?? 'unknown',
                            'lang'   => $lang,
                            'title'  => $title,
                            'forced' => $forced,
                            'sdh'    => $sdh
                        ];
                    }
                }
            }
        }

        // Fallback default if NO audio stream was found
        if ($audioCodec === null) {
            $audioCodec = 'opus';
        }

        // Check for Chapters (From Pass 1)
        $hasChapters = !empty($data1->chapters);

        // Parse HDR (From Pass 2)
        $hdrString = null;
        if ($data2 && !empty($data2->frames)) {
            foreach ($data2->frames as $frame) {
                if (!empty($frame->side_data_list)) {
                    foreach ($frame->side_data_list as $sd) {
                        if (isset($sd->side_data_type) && $sd->side_data_type === "Mastering display metadata") {
                            $hdrString = self::formatMasteringString($sd);
                            break 2; 
                        }
                    }
                }
            }
        }

        return [
            'width'          => $width,
            'height'         => $height,
            'video_codec'    => $videoCodec,
            'is_hdr'         => ($hdrString !== null),
            'hdr_mastering'  => $hdrString,
            'primaries'      => $primaries,
            'audio_codec'    => $audioCodec,
            'audio_channels' => $audioChannels,
            'subtitles'      => $subtitles,
            'has_chapters'   => $hasChapters,
        ];
    }

    private static function getJsonOutput($cmd) {
        $rawOutput = shell_exec($cmd);
        $first = strpos($rawOutput, '{');
        $last  = strrpos($rawOutput, '}');
        if ($first === false || $last === false) return null;
        $json = substr($rawOutput, $first, ($last - $first + 1));
        return json_decode($json);
    }

    private static function formatMasteringString($sd) {
        $get = fn($val) => explode('/', $val ?? '0')[0];
        $gx = $get($sd->green_x ?? null); $gy = $get($sd->green_y ?? null);
        $bx = $get($sd->blue_x ?? null);  $by = $get($sd->blue_y ?? null);
        $rx = $get($sd->red_x ?? null);   $ry = $get($sd->red_y ?? null);
        $wx = $get($sd->white_point_x ?? null); $wy = $get($sd->white_point_y ?? null);
        $maxL = $get($sd->max_luminance ?? null);
        $minL = $get($sd->min_luminance ?? null);
        return "G({$gx},{$gy})B({$bx},{$by})R({$rx},{$ry})WP({$wx},{$wy})L({$maxL},{$minL})";
    }
}
