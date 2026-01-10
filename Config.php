<?php

class Config
{
    private static $data = [];
    private static $isLoaded = false;

    // --- DEFAULTS (Fallback if .env.yaml is missing) ---
    private static $defaults = [
        'MKV_MRG' => 'E:/Apps/mkvtoolnix/mkvmerge.exe',
        'MKV_PED' => 'E:/Apps/mkvtoolnix/mkvpropedit.exe',
        'VID_ENC' => 'E:/Apps/NVEnc/NVEncC64.exe',
        'AUD_ENC' => 'E:/Apps/ffmpeg/ffmpeg.exe',
        'MKV_MUX' => 'E:/Apps/ffmpeg/ffmpeg.exe',
        'FFPROBE' => 'E:/Apps/ffmpeg/ffprobe.exe',

        // DEFAULTS (Also set in .env, then can be overridden via CLI)
        'DEFAULT_WRK_PATH' => 'E:/temp/',
        'DEFAULT_JOB_PATH' => './output/',
    ];

    public static function get($key) {
        if (!self::$isLoaded) {
            self::load();
        }
        return self::$data[$key] ?? null;
    }

    private static function load() {
        // Start with defaults
        self::$data = self::$defaults;

        // Determine Base Directory
        // Check if we are running inside a Phar archive
        $pharPath = Phar::running(false);

        if (!empty($pharPath)) {
            // PHAR MODE: Get the directory where the .phar file is located
            $baseDir = dirname($pharPath);
        } else {
            // STANDARD MODE: Get the directory where this script resides
            $baseDir = __DIR__;
        }

        // Check for .env file
        $envPath = $baseDir . '/.env.yaml';

        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Ignore comments
                if (str_starts_with(trim($line), '#')) continue;

                // Parse Key: Value
                if (str_contains($line, ':')) {
                    list($k, $v) = explode(':', $line, 2);
                    $key = trim($k);
                    $val = trim($v);
                    
                    // Remove wrapping quotes if present
                    $val = trim($val, " \"'");
                    
                    // Update setting
                    if (array_key_exists($key, self::$data)) {
                        self::$data[$key] = $val;
                    }
                }
            }
        }
        self::$isLoaded = true;
    }
}
