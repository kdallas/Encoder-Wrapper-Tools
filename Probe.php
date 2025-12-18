<?php

class Probe
{
    // Returns: ['width', 'height', 'video_codec', 'is_hdr', 'hdr_mastering', 'primaries', 'audio_codec', 'audio_channels', 'subtitles' => [], 'chapters' => bool]
    public static function analyze($filePath) {
        if (!file_exists(Config::FFPROBE)) {
            throw new Exception("ffprobe not found at: " . Config::FFPROBE);
        }

        // Command: Read 50 packets to ensure we catch Video frames for HDR data
        // Added -show_chapters
        // Added "index,disposition,tags" to stream entries
        $cmd = sprintf('"%s" -hide_banner -loglevel warning -print_format json -show_frames -read_intervals "%%+#50" -show_entries "frame=side_data_list" -show_chapters -show_entries "stream=index,codec_type,width,height,codec_name,channels,color_primaries,color_transfer,color_space,disposition,tags" -i "%s" 2>&1',
            Config::FFPROBE,
            $filePath
        );

        $rawOutput = shell_exec($cmd);
        
        $first = strpos($rawOutput, '{');
        $last  = strrpos($rawOutput, '}');
        
        if ($first === false || $last === false) return null;
        
        $json = substr($rawOutput, $first, ($last - $first + 1));
        $data = json_decode($json);

        if (!$data) return null;

        // Init
        $width = 0; $height = 0; 
        $primaries = null; 
        $videoCodec = 'unknown';
        $audioCodec = null; 
        $audioChannels = 0;
        $subtitles = [];

        // Iterate streams
        if (isset($data->streams)) {
            foreach ($data->streams as $stream) {
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
                        $subtitles[] = [
                            'index' => $stream->index,
                            'codec' => $stream->codec_name ?? 'unknown',
                            'lang'  => $stream->tags->language ?? 'und',
                            'title' => $stream->tags->title ?? '',
                            'forced' => $stream->disposition->forced ?? 0,
                            'sdh'    => $stream->disposition->hearing_impaired ?? 0
                        ];
                    }
                }
            }
        }

        // Fallback default if NO audio stream was found
        if ($audioCodec === null) {
            $audioCodec = 'opus';
        }

        // Check for Chapters
        $hasChapters = !empty($data->chapters);

        // Parse Frame Info (Iterate to find HDR Metadata)
        $hdrString = null;
        if (!empty($data->frames)) {
            foreach ($data->frames as $frame) {
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
