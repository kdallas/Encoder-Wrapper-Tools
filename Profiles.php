<?php

class Profiles
{
    /**
     * HELPER: Safely formats dynamic arguments for NVEncC.
     * Filters out known handled keys and audio keys to prevent passing FFmpeg
     * variables to the video encoder.
     */
    private static function formatVideoExtraArgs($args, $handledKeys = []) {
        $audioKeys = ['abitrate', 'bitaud']; 
        $exclude = array_merge($handledKeys, $audioKeys);
        
        $extraStr = "";
        foreach ($args as $key => $value) {
            if (in_array($key, $exclude)) {
                continue;
            }
            
            if ($value === true) {
                $extraStr .= " --{$key}";
            } elseif ($value !== false) {
                // Check if value needs quotes (contains spaces or special chars)
                // If it's a simple word like uhq or a number like 1600, no quotes.
                if (preg_match('/^[a-zA-Z0-9._-]+$/', $value)) {
                    $extraStr .= " --{$key} {$value}";
                } else {
                    $extraStr .= " --{$key} " . escapeshellarg($value);
                }
            }
        }
        return $extraStr;
    }

    public static function getVideo() {
        return [
            '2pass' => function($args) {
                $bitrate = $args['bitrate'] ?? $args['bitvid'] ?? '1200';
                $base = "--vbr $bitrate --multipass 2pass-full --codec h265 --preset quality --level auto --output-depth 10 --aq-temporal --aq --mv-precision Q-pel --lookahead 32 --avhw";

                // Append safe extra args (excluding the bitrates we just used)
                return $base . self::formatVideoExtraArgs($args, ['bitrate', 'bitvid']);
            },
            'cqp' => function($args) {
                $q = $args['q'] ?? '20';
                $base = "--cqp $q --codec h265 --preset quality --level auto --output-depth 10 --aq-temporal --aq --mv-precision Q-pel --lookahead 32 --avhw";

                return $base . self::formatVideoExtraArgs($args, ['q']);
            },
            'basic' => function($args) {
                // Intercept the bitrate so it neatly replaces the 1200 base if specified
                $bitrate = $args['bitrate'] ?? $args['bitvid'] ?? '1200';
                $base = "--vbr $bitrate --multipass 2pass-full --codec h265 --preset quality --level auto --output-depth 10";

                return $base . self::formatVideoExtraArgs($args, ['bitrate', 'bitvid']);
            },
            'copy'    => 'copy',
            'default' => "--vbr 1200 --multipass 2pass-full --codec h265 --preset quality --level auto --output-depth 10"
        ];
    }

    public static function getAudio() {
        return [
            'opus-8-6' => function($args) {
                $ab = $args['abitrate'] ?? $args['bitaud'] ?? '320k';
                return "-c:a libopus -b:a $ab " . '-vbr on -ac 6 -af "pan=5.1|FL=FL+0.5*BL+0.5*LFE|FR=FR+0.5*BR+0.5*LFE|FC=FC|BL=0.5*BL+0.5*LFE|BR=0.5*BR+0.5*LFE"';
            },
            'opus-5.1' => function($args) {
                $ab = $args['abitrate'] ?? $args['bitaud'] ?? '224k';
                return "-c:a libopus -b:a $ab " . '-af "channelmap=channel_layout=5.1"';
            },
            'aac-opus' => function($args) {
                $ab = $args['abitrate'] ?? $args['bitaud'] ?? '224k';
                return "-c:a libopus -b:a $ab ";
            },
            'opus-pans' => function($args) {
                $ab = $args['abitrate'] ?? $args['bitaud'] ?? '128k';
                return "-c:a libopus -b:a $ab " . '-af "volume=1.65,pan=stereo|FL=0.5*FC+0.707*FL+0.707*BL+0.5*LFE|FR=0.5*FC+0.707*FR+0.707*BR+0.5*LFE"';
            },
            'opus-stereo' => function($args) {
                $ab = $args['abitrate'] ?? $args['bitaud'] ?? '100k';
                return "-c:a libopus -b:a $ab -ac 2";
            },
            'copy'    => '-c:a copy',
            'default' => '-c:a libopus -b:a 192k',
        ];
    }
}
