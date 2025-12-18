<?php

class BatchEncoder
{
    // Inputs
    private $pathInput = "";
    private $prefixInput = "";
    private $recursive = false;

    // Paths
    private $wrkPath = ""; // Destination for .h265/.opus/.mkv files
    private $jobPath = ""; // Destination for .ps1 files

    // Profiles & Modifiers
    private $videoProfileKey = "default";
    private $audioProfileKey = "default";
    private $resizeInput = "";
    private $cropInput = "";
    private $vppInput = "";
    private $extraArgs = [];

    // Calculated Options
    private $finalVidOptions = "";
    private $finalAudOptions = "";

    public function __construct($argv) {
        try {
            // Initialize Defaults (Sanitized immediately)
            $this->wrkPath = $this->sanitizePath(Config::DEFAULT_WRK_PATH, true);
            $this->jobPath = $this->sanitizePath(Config::DEFAULT_JOB_PATH, true);

            $this->parseArguments($argv);
            $this->validateInputs();
            $this->resolveProfiles();
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    public function run() {
        try {
            $files = $this->scanTargets();
            $this->generateBatchFiles($files);
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * CENTRAL PATH CLEANER
     * 1. Converts backslashes to forward slashes.
     * 2. Fixes Git Bash paths (/c/Users -> C:/Users).
     * 3. Enforces trailing slash if $isDir is true.
     */
    private function sanitizePath($path, $isDir = false) {
        // 1. Unify Slashes
        $clean = str_replace('\\', '/', $path);

        // 2. Fix Git Bash "Drive Letter" paths (e.g., /c/Windows -> C:/Windows)
        if (preg_match('/^\/([a-zA-Z])\/(.*)/', $clean, $matches)) {
            $drive = strtoupper($matches[1]);
            $clean = $drive . ':/' . $matches[2];
        }

        // 3. Trailing Slash Logic (only for directories)
        if ($isDir && !str_ends_with($clean, '/')) {
            $clean .= '/';
        }
        
        // Trim trailing slash for files (if user accidentally added one?) 
        // Not strictly necessary but keeps file paths clean.
        if (!$isDir && str_ends_with($clean, '/')) {
            $clean = rtrim($clean, '/');
        }

        return $clean;
    }

    /**
     * OUTPUT HELPER
     * Converts internal forward slashes to Windows backslashes
     * ONLY for writing into the .ps1 files.
     */
    private function toWinPath($path) {
        return str_replace('/', '\\', $path);
    }

    private function parseArguments($argv) {
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            
            if (str_starts_with($arg, '--path=')) {
                $raw = substr($arg, 7);
                // Stitch spaces if path was unquoted
                for ($j = $i + 1; $j < count($argv); $j++) {
                    if (str_starts_with($argv[$j], '-')) break; 
                    $raw .= " " . $argv[$j];
                    $i++;
                }
                // Sanitize immediately (False for $isDir, because it might be a file)
                $this->pathInput = $this->sanitizePath($raw, false);
            } 
            elseif (str_starts_with($arg, '--prefix=')) {
                $this->prefixInput = substr($arg, 9);
                for ($j = $i + 1; $j < count($argv); $j++) {
                    if (str_starts_with($argv[$j], '-')) break;
                    $this->prefixInput .= " " . $argv[$j];
                    $i++;
                }
            }
            elseif (str_starts_with($arg, '--out-path=')) {
                // User override for Work Path -> Force Dir
                $this->wrkPath = $this->sanitizePath(substr($arg, 11), true);
            }
            elseif (str_starts_with($arg, '--job-path=')) {
                // User override for Job Path -> Force Dir
                $this->jobPath = $this->sanitizePath(substr($arg, 11), true);
            }
            elseif (str_starts_with($arg, '--video=')) {
                $this->videoProfileKey = substr($arg, 8);
            }
            elseif (str_starts_with($arg, '--audio=')) {
                $this->audioProfileKey = substr($arg, 8);
            }
            elseif (str_starts_with($arg, '--resize=')) {
                $this->resizeInput = substr($arg, 9);
            }
            elseif (str_starts_with($arg, '--crop=')) {
                $this->cropInput = substr($arg, 7);
            }
            elseif (str_starts_with($arg, '--vpp=')) {
                $this->vppInput = substr($arg, 6);
            }
            elseif ($arg === '--recursive') {
                $this->recursive = true;
            }
            elseif (str_starts_with($arg, '--')) {
                // Dynamic args (e.g. --q=20)
                $cleanArg = substr($arg, 2); 
                $parts = explode('=', $cleanArg, 2);
                $this->extraArgs[$parts[0]] = $parts[1] ?? true; 
            }
        }
    }

    private function validateInputs() {
        if (empty($this->pathInput)) { throw new Exception("Missing --path value"); }
        if (empty($this->prefixInput)) { throw new Exception("Missing --prefix value"); }
    }

    private function resolveProfiles() {
        $vidProfiles = Profiles::getVideo();
        $audProfiles = Profiles::getAudio();

        if (!array_key_exists($this->videoProfileKey, $vidProfiles)) {
            throw new Exception("Unknown Video Profile '{$this->videoProfileKey}'");
        }
        if (!array_key_exists($this->audioProfileKey, $audProfiles)) {
            throw new Exception("Unknown Audio Profile '{$this->audioProfileKey}'");
        }

        $rawVid = $vidProfiles[$this->videoProfileKey];
        $this->finalVidOptions = is_callable($rawVid) ? $rawVid($this->extraArgs) : $rawVid;

        $rawAud = $audProfiles[$this->audioProfileKey];
        $this->finalAudOptions = is_callable($rawAud) ? $rawAud($this->extraArgs) : $rawAud;

        // VPP Logic
        $vppString = '';
        switch ($this->vppInput) {
            case 'none':
                $vppString = '';
                break;
            case 'deband':
                $vppString = '--vpp-deband';
                break;
            case 'both':
                $vppString = '--vpp-edgelevel --vpp-deband';
                break;
            case 'edge':
            default:
                // Default behavior if --vpp is missing or explicitly 'edge'
                // Change this to case 'edge': ... default: $vppString = ''; if you prefer no defaults.
                $vppString = '--vpp-edgelevel';
                break;
        }

        if (!empty($vppString)) {
            $this->finalVidOptions .= " " . $vppString;
            echo "Modifier [VPP]: {$this->vppInput} -> $vppString\n";
        }

        if (!empty($this->resizeInput)) {
            if (!preg_match('/^\d+x\d+$/', $this->resizeInput)) {
                throw new Exception("Invalid resize format '{$this->resizeInput}'. Use WxH.");
            }
            $this->finalVidOptions .= " --output-res " . $this->resizeInput;
            echo "Modifier [Resize]: {$this->resizeInput}\n";
        }

        if (!empty($this->cropInput)) {
            if (!preg_match('/^\d+,\d+,\d+,\d+$/', $this->cropInput)) {
                throw new Exception("Invalid crop format '{$this->cropInput}'. Use L,T,R,B.");
            }
            $this->finalVidOptions .= " --crop " . $this->cropInput;
            echo "Modifier [Crop]: {$this->cropInput}\n";
        }

        echo "Profile [Video]: {$this->videoProfileKey}\n";
        echo "Profile [Audio]: {$this->audioProfileKey}\n";
        echo "Output Path (Work): {$this->wrkPath}\n";
        echo "Output Path (Jobs): {$this->jobPath}\n\n";
    }

    private function scanTargets() {
        // NOTE: $this->pathInput is already sanitized (C:/Format/...)

        $targetPath = $this->pathInput; 
        $srcExts = ['mkv','mp4'];

        if (is_file($targetPath)) {
            echo "Target identified as Single File.\n";
            return [$targetPath];
        } 
        elseif (is_dir($targetPath)) {
            // Ensure trailing slash for directory scanning
            if (!str_ends_with($targetPath, '/')) {
                $targetPath .= '/';
            }

            echo "Scanning: $targetPath (Recursive: " . ($this->recursive ? 'ON' : 'OFF') . ")\n";
            $scanned = ScanDir::scan($targetPath, $srcExts, $this->recursive);

            if (empty($scanned)) {
                throw new Exception("No valid files found in directory.");
            }
            
            // ScanDir might return mixed slashes depending on OS; unify them here for safety.
            return array_map(fn($p) => $this->sanitizePath($p, false), $scanned);
        } 
        else {
            throw new Exception("Target path does not exist: $targetPath");
        }
    }

    private function generateBatchFiles($files) {
        // Ensure Batch Script Directory Exists
        if (!is_dir($this->jobPath)) {
            if (!mkdir($this->jobPath, 0777, true)) {
                throw new Exception("Failed to create batch job output directory: " . $this->jobPath);
            }
        }

        $videoBat = $this->jobPath . $this->prefixInput . '_vid.ps1';
        $audioBat = $this->jobPath . $this->prefixInput . '_aud.ps1';
        $subBat   = $this->jobPath . $this->prefixInput . '_sub.ps1';
        $mergeBat = $this->jobPath . $this->prefixInput . '_mux.ps1';
        $cleanBat = $this->jobPath . $this->prefixInput . '_del.ps1';

        // Reset output files
        if(file_exists($videoBat)) unlink($videoBat);
        if(file_exists($audioBat)) unlink($audioBat);
        if(file_exists($subBat))   unlink($subBat);
        if(file_exists($mergeBat)) unlink($mergeBat);
        if(file_exists($cleanBat)) unlink($cleanBat);

        $mergeOptions = '-c copy -map 0:v:0 -map 1:a:0';
        $maxLength = 80;

        foreach ($files as $cleanPath) {
            // $cleanPath is guaranteed to be C:/Path/to/file.mkv
            $fileName = basename($cleanPath);

            // Probe Logic
            $probeData = Probe::analyze($cleanPath);
            if (!$probeData) {
                echo "Warning: Could not analyze file $fileName. Using defaults.\n";
                $probeData = [
                    'width' => 1920, 'height' => 1080, 'video_codec' => 'unknown', 
                    'is_hdr' => false, 'primaries' => null,
                    'audio_codec' => 'opus', 'audio_channels' => 0,
                    'has_chapters' => false, 'subtitles' => [],
                ];
            }

            // --- REPORT SOURCE ANALYSIS ---
            echo "  [Source Report]:\n";
            echo "    Video: {$probeData['width']}x{$probeData['height']} ({$probeData['video_codec']})\n";
            if ($probeData['is_hdr']) {
                echo "    HDR:   Yes [{$probeData['hdr_mastering']}]\n";
            } else {
                echo "    HDR:   No\n";
            }
            echo "    Audio: {$probeData['audio_codec']} ({$probeData['audio_channels']}ch)\n";
            // -----------------------------

            // --- SUBTITLE & CHAPTER LOGIC ---
            $subJobs = "";
            $subInputs = "";
            $subMaps   = "";
            $subClean  = "";
            
            // Input Index Tracker for Muxer
            // 0=Video (PreMux), 1=Audio (OutAud)
            $nextMuxIndex = 2; 

            // Chapter Logic (Map from Source)
            $chapterMapArgs = "";
            if ($probeData['has_chapters']) {
                // Add Source File as Input to Muxer just for chapters
                $subInputs .= sprintf(' -i "%s"', $this->toWinPath($cleanPath));
                
                // Map chapters from this input (Index 2 usually)
                $chapterMapArgs = " -map_chapters $nextMuxIndex";
                
                $nextMuxIndex++; // Increment index
                echo "  [Chapter]: Mapping directly from Source.\n";
            }

            // Subtitle Logic
            foreach ($probeData['subtitles'] as $sub) {
                // Filter: Lang = English
                $isEng = in_array(strtolower($sub['lang']), ['eng', 'en', 'en-us']);
                // Filter: Not Hearing Impaired (SDH)
                $isSDH = ($sub['sdh'] == 1) || (stripos($sub['title'], 'sdh') !== false);
                
                if ($isEng && !$isSDH) {
                    $suffix = "_" . $sub['lang'];
                    if ($sub['forced']) $suffix .= "_forced";

                    // Append Track Index to ensure Uniqueness (e.g. _eng_3.mkv)
                    $suffix .= "_" . $sub['index'];

                    // Extract to temp MKV (Safest for PGS/ASS/SRT)
                    $subOut = $this->wrkPath . $this->swapExt($fileName, 'mkv', $suffix);
                    
                    // FIX: Added -map_chapters -1 to prevent chapters in sub file
                    $subJobs .= sprintf('%s -i "%s" -map 0:%d -c copy -map_chapters -1 "%s"' . "\n",
                        $this->toWinPath(Config::AUD_ENC),
                        $this->toWinPath($cleanPath),
                        $sub['index'],
                        $this->toWinPath($subOut)
                    );

                    // Add to Muxer
                    $subInputs .= sprintf(' -i "%s"', $this->toWinPath($subOut));
                    $subMaps   .= sprintf(' -map %d:0', $nextMuxIndex);
                    $subClean  .= sprintf('Remove-Item "%s"' . "\n", $this->toWinPath($subOut));
                    $nextMuxIndex++;

                    echo "  [Subtitle]: Keeping Track {$sub['index']} ({$sub['lang']}" . ($sub['forced']?' Forced':'') . ")\n";
                }
            }

            // --- SMART AUDIO LOGIC START ---
            $activeProfile = $this->audioProfileKey;
            $srcCodec = strtolower($probeData['audio_codec'] ?? '');
            $srcCh    = intval($probeData['audio_channels'] ?? 0);
            
            // Check for Smart Copy conditions (Opus source only)
            if ($srcCodec === 'opus') {
                if ($activeProfile === 'default') {
                    // Default usually encodes, but if source is Opus, we prefer Copy
                    $activeProfile = 'copy';
                    echo "  [Smart Audio]: Source is Opus (Default Profile). Switched to Copy.\n";
                }
                elseif ($activeProfile === 'opus-5.1' && $srcCh === 6) {
                    $activeProfile = 'copy';
                    echo "  [Smart Audio]: Source is Opus 5.1. Switched to Copy.\n";
                }
                elseif ($activeProfile === 'opus-stereo' && $srcCh === 2) {
                    $activeProfile = 'copy';
                    echo "  [Smart Audio]: Source is Opus Stereo. Switched to Copy.\n";
                }
                elseif ($activeProfile === 'opus-pans' && $srcCh === 2) {
                    // Pans profile is usually for downmixing. If source is already stereo, we copy.
                    $activeProfile = 'copy';
                    echo "  [Smart Audio]: Source is Opus Stereo (Pans Profile). Switched to Copy.\n";
                }
            } 
            elseif ($srcCodec === 'aac') {
                // Rule: Copy AAC unless downmixing (e.g. 5.1 to Stereo)
                $isDownmix = ($srcCh > 2) && ($activeProfile === 'opus-stereo' || $activeProfile === 'opus-pans');
                
                if (!$isDownmix) {
                    $activeProfile = 'copy';
                    echo "  [Smart Audio]: Source is AAC (No Downmix). Switched to Copy.\n";
                }
            }
            // --- SMART AUDIO LOGIC END ---

            // --- AUDIO EXECUTION LOGIC ---
            $audioExt = 'opus'; // Default
            $finalAudOpts = $this->finalAudOptions; // Default

            if ($activeProfile === 'copy') {
                // Map ffmpeg codec names to file extensions
                $codecMap = [
                    // --- Dolby ---
                    'ac3'         => 'ac3',   // Standard Dolby Digital
                    'eac3'        => 'eac3',  // Dolby Digital Plus (DDP) & Atmos
                    'mlp'         => 'thd',   // Meridian Lossless (TrueHD Core)
                    'truehd'      => 'thd',   // Dolby TrueHD & Atmos

                    // --- DTS ---
                    'dts'         => 'dts',   // Covers DTS, DTS-ES, DTS-HD HR, DTS-HD MA, and DTS:X

                    // --- Lossless / High Fidelity ---
                    'alac'        => 'm4a',   // Apple Lossless
                    'ape'         => 'ape',   // Monkey's Audio
                    'flac'        => 'flac',
                    'pcm_s16le'   => 'wav',
                    'pcm_s24le'   => 'wav',
                    'pcm_f32le'   => 'wav',
                    'pcm_bluray'  => 'wav',
                    'wavpack'     => 'wv',

                    // --- Standard / Legacy ---
                    'aac'         => 'aac',
                    'mp2'         => 'mp2',   // Common in DVDs/Broadcast
                    'mp3'         => 'mp3',
                    'opus'        => 'opus',
                    'vorbis'      => 'ogg',   // Common in older MKVs
                    'wmav2'       => 'wma',   // Windows Media Audio
                ];

                if (array_key_exists($srcCodec, $codecMap)) {
                    $audioExt = $codecMap[$srcCodec];
                } else {
                    // Fallback for unknown codecs (e.g. pcm_s16le), mka is safe for almost anything
                    $audioExt = 'mka';
                    echo "  [Audio]: Unknown source codec '$srcCodec'. Defaulting to .mka\n";
                }
                
                // Override options for Copy Mode
                $finalAudOpts = '-c:a copy';
                echo "  [Audio]: Copy mode detected. Source: $srcCodec -> Ext: .$audioExt\n";
            } else {
                echo "  [Audio]: Encoding to {$activeProfile}. Ext: .$audioExt\n";
            }

            // Video/Level Logic
            $targetW = $probeData['width'];
            $targetH = $probeData['height'];

            if (!empty($this->resizeInput) && preg_match('/^(\d+)x(\d+)$/', $this->resizeInput, $resMatch)) {
                $targetW = intval($resMatch[1]);
                $targetH = intval($resMatch[2]);
            }

            $level = ($targetW > 1920 || $targetH > 1080) ? '5.0' : '4.1';

            $colorParams = "";
            $hdrParams   = "";

            if ($probeData['is_hdr']) {
                $colorParams = "--transfer smpte2084 --colorprim bt2020 --colormatrix bt2020nc";
                if (!empty($probeData['hdr_mastering'])) {
                    $hdrParams = '--master-display "' . $probeData['hdr_mastering'] . '"';
                }
                echo "  [Auto-Spec]: Detected HDR. Using Level $level & BT.2020.\n";
            } else {
                if ($probeData['primaries'] === 'bt709') {
                    $colorParams = "--transfer bt709 --colorprim bt709 --colormatrix bt709";
                    echo "  [Auto-Spec]: SDR (bt709) Detected.\n";
                } else {
                    echo "  [Auto-Spec]: SDR (Unknown/Other).\n";
                }
            }

            // BUILD JOBS
            // Calculate Outputs (Forward Slashes Internal)
            $outVid = $this->wrkPath . $this->swapExt($fileName, 'h265');
            $outAud = $this->wrkPath . $this->swapExt($fileName, $audioExt);
            $preMux = $this->wrkPath . $this->swapExt($fileName, 'mkv', '__');
            $finMkv = $this->wrkPath . $this->swapExt($fileName, 'mkv');

            $currentVidOptions = $this->finalVidOptions . " --level $level $colorParams $hdrParams";

            // Format Commands (Use toWinPath() here for the Batch File content)
            
            // Video
            $videoJob = sprintf('%s %s -i "%s" -o "%s"' . "\n", 
                $this->toWinPath(Config::VID_ENC), 
                $currentVidOptions, 
                $this->toWinPath($cleanPath), 
                $this->toWinPath($outVid)
            );

            // Audio
            $audioJob = sprintf('%s -i "%s" %s "%s"' . "\n", 
                $this->toWinPath(Config::AUD_ENC), 
                $this->toWinPath($cleanPath), 
                $this->finalAudOptions, 
                $this->toWinPath($outAud)
            );

            // Pre-Mux (Video only into MKV)
            $preMxJob = sprintf('%s -o "%s" "%s"' . "\n", 
                $this->toWinPath(Config::MKV_MRG),
                $this->toWinPath($preMux), 
                $this->toWinPath($outVid)
            );

            // Muxer (Video + Audio + Source[Chapters?] + Subs)
            $baseMuxMap = "-c copy -map 0:v:0 -map 1:a:0 $chapterMapArgs $subMaps";

            $muxerJob = sprintf('%s -i "%s" -i "%s"%s %s "%s"' . "\n", 
                $this->toWinPath(Config::MKV_MUX),
                $this->toWinPath($preMux),
                $this->toWinPath($outAud),
                $subInputs,   // -i Source (if chapters) + -i sub1.mkv...
                $baseMuxMap,  // -map 0... -map 1... -map_chapters 2 ...
                $this->toWinPath($finMkv)
            );

            // Cleanup
            $cleanJob  = sprintf('Remove-Item "%s"' . "\n", $this->toWinPath($outVid));
            $cleanJob .= sprintf('Remove-Item "%s"' . "\n", $this->toWinPath($outAud));
            $cleanJob .= sprintf('Remove-Item "%s"' . "\n", $this->toWinPath($preMux));
            $cleanJob .= $subClean;

            // Output to Screen (Display Windows style for user familiarity)
            $displaySrc = $this->toWinPath($cleanPath);
            $displaySrc = (strlen($displaySrc) > $maxLength) ? '...' . substr($displaySrc, -$maxLength) : $displaySrc;
            echo "Queuing: $displaySrc\n";

            // Write to Files
            file_put_contents($videoBat, $videoJob, FILE_APPEND);
            file_put_contents($audioBat, $audioJob, FILE_APPEND);
            file_put_contents($subBat,   $subJobs,  FILE_APPEND);
            file_put_contents($mergeBat, $preMxJob, FILE_APPEND);
            file_put_contents($mergeBat, $muxerJob, FILE_APPEND);
            file_put_contents($cleanBat, $cleanJob, FILE_APPEND);
        }

        echo "\nDone. Created:\n- $videoBat\n- $audioBat\n- $subBat\n- $mergeBat\n- $cleanBat\n";
    }

    private function swapExt($filename, $newExt, $suffix='') {
        $info = pathinfo($filename);
        return $info['filename'] . $suffix . '.' . $newExt;
    }
}
