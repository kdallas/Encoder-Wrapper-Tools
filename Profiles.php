<?php

class Profiles
{
    public static function getVideo() {
        return [
            '2pass' => function($args) {
                $bitrate = $args['bitrate'] ?? $args['bitvid'] ?? '1200';
                return "--vbrhq $bitrate --codec h265 --preset quality --level auto --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw";
            },
            'cqp' => function($args) {
                $q = $args['q'] ?? '20';
                return "--cqp $q --codec h265 --preset quality --level auto --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw";
            },
            'default' => "--vbrhq 1200 --codec h265 --preset quality --level auto --output-depth 10"
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
