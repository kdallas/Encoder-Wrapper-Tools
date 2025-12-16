<?php

class Probe
{
    // Returns: ['width', 'height', 'is_hdr', 'hdr_mastering', 'primaries', 'audio_codec']
    public static function analyze($filePath) {
        if (!file_exists(Config::FFPROBE)) {
            throw new Exception("ffprobe not found at: " . Config::FFPROBE);
        }

        // We select Video Stream 0 (v:0) AND Audio Stream 0 (a:0)
        // We look for codec_name to identify audio type
        // $cmd = sprintf('"%s" -hide_banner -loglevel warning -select_streams v:0 -show_entries "stream=width,height,color_space,color_primaries,color_transfer" -select_streams a:0 -show_entries "stream=codec_name" -select_streams v:0 -show_frames -read_intervals "%%+#1" -show_entries "frame=side_data_list" -print_format json -i "%s" 2>&1',
        //     Config::FFPROBE,
        //     $filePath
        // );

        // Note: The command above uses multiple -select_streams. 
        // A cleaner way often used is -show_streams and filtering in PHP, 
        // but explicit selection works if ordered correctly. 
        // To be safe and robust, let's just grab all streams and filter in PHP.
        
        $cmd = sprintf('"%s" -hide_banner -loglevel warning -print_format json -show_frames -read_intervals "%%+#1" -show_entries "frame=side_data_list" -show_entries "stream=codec_type,width,height,codec_name,color_primaries,color_transfer,color_space" -i "%s" 2>&1',
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

        // Initialize Defaults
        $width = 0; $height = 0; 
        $primaries = null; $transfer = null; $space = null;
        $audioCodec = 'opus'; // Default fallback

        // Iterate streams to find Video and Audio data
        if (isset($data->streams)) {
            foreach ($data->streams as $stream) {
                if ($stream->codec_type === 'video') {
                    $width = intval($stream->width ?? 0);
                    $height = intval($stream->height ?? 0);
                    $primaries = $stream->color_primaries ?? null;
                } elseif ($stream->codec_type === 'audio') {
                    // Capture the first audio stream we find
                    if ($audioCodec === 'opus') { // Only set if not already set (detect first)
                        $audioCodec = $stream->codec_name ?? 'opus';
                    }
                }
            }
        }

        // Get HDR Metadata from Frames
        $hdrString = null;
        if (!empty($data->frames[0]->side_data_list)) {
            foreach ($data->frames[0]->side_data_list as $sd) {
                if (isset($sd->side_data_type) && $sd->side_data_type === "Mastering display metadata") {
                    $hdrString = self::formatMasteringString($sd);
                    break;
                }
            }
        }

        return [
            'width'       => $width,
            'height'      => $height,
            'is_hdr'      => ($hdrString !== null),
            'hdr_mastering' => $hdrString,
            'primaries'   => $primaries,
            'audio_codec' => $audioCodec
        ];
    }

    private static function formatMasteringString($sd) {
        $get = fn($val) => explode('/', $val ?? '0')[0];
        // ... (Same logic as before) ...
        $gx = $get($sd->green_x ?? null); $gy = $get($sd->green_y ?? null);
        $bx = $get($sd->blue_x ?? null);  $by = $get($sd->blue_y ?? null);
        $rx = $get($sd->red_x ?? null);   $ry = $get($sd->red_y ?? null);
        $wx = $get($sd->white_point_x ?? null); $wy = $get($sd->white_point_y ?? null);
        $maxL = $get($sd->max_luminance ?? null);
        $minL = $get($sd->min_luminance ?? null);
        return "G({$gx},{$gy})B({$bx},{$by})R({$rx},{$ry})WP({$wx},{$wy})L({$maxL},{$minL})";
    }
}
