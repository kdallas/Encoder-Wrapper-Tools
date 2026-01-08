<?php

class BatchEncoder
{
    // Inputs
    private $pathInput = "";
    private $prefixInput = "";
    private $titleInput = null;
    private $recursive = false;

    // Track Selection Inputs
    private $audioLangs = [];  // e.g. ['eng', 'jpn']
    private $defaultLang = ""; // e.g. 'eng'

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
            $this->wrkPath = $this->sanitizePath(Config::get('DEFAULT_WRK_PATH'), true);
            $this->jobPath = $this->sanitizePath(Config::get('DEFAULT_JOB_PATH'), true);

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
     * Converts everything to Forward Slashes (/) for internal consistency 
     * and Git Bash compatibility.
     */
    private function sanitizePath($path, $isDir = false) {
        // Unify Slashes to /
        $clean = str_replace('\\', '/', $path);

        // Remove surrounding quotes (Single or Double)
        $clean = trim($clean, ' "\'');

        // Fix Git Bash "Drive Letter" paths (e.g., /c/Windows -> C:/Windows)
        // Only apply if it looks like a drive path, NOT a UNC path (//Server)
        if (!str_starts_with($clean, '//') && preg_match('/^\/([a-zA-Z])\/(.*)/', $clean, $matches)) {
            $drive = strtoupper($matches[1]);
            $clean = $drive . ':/' . $matches[2];
        }

        // Trailing Slash Logic (only for directories)
        // We use a robust check: is_dir(Unix) OR is_dir(Win)
        if (!$isDir) {
            $isDir = is_dir($clean) || is_dir($this->toWinPath($clean));
        }

        if ($isDir && !str_ends_with($clean, '/')) {
            $clean .= '/';
        }
        
        if (!$isDir && str_ends_with($clean, '/')) {
            $clean = rtrim($clean, '/');
        }

        return $clean;
    }

    /**
     * OUTPUT HELPER
     * Converts internal forward slashes to Windows backslashes
     * Used for: .ps1 generation, UNC file checks, and FFmpeg commands.
     */
    private function toWinPath($path) {
        return str_replace('/', '\\', $path);
    }

    private function parseArguments($argv) {
        // Env Var Check
        $envPath = getenv('BATCH_PATH');
        if ($envPath !== false && !empty($envPath)) {
            $this->pathInput = $this->sanitizePath($envPath, false);
            echo "Input Source: Loaded from BATCH_PATH environment variable.\n";
        }

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            
            if (str_starts_with($arg, '--path=')) {
                if (empty($this->pathInput)) {
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
            } 
            elseif (str_starts_with($arg, '--prefix=')) {
                $this->prefixInput = substr($arg, 9);
                for ($j = $i + 1; $j < count($argv); $j++) {
                    if (str_starts_with($argv[$j], '-')) break;
                    $this->prefixInput .= " " . $argv[$j];
                    $i++;
                }
            }
            // Audio Lang Selection
            elseif (str_starts_with($arg, '--langs=')) {
                $raw = substr($arg, 8);
                $this->audioLangs = array_map('strtolower', explode(',', $raw));
            }
            // Default Lang Selection
            elseif (str_starts_with($arg, '--default-lang=')) {
                $this->defaultLang = strtolower(substr($arg, 15));
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
            elseif (str_starts_with($arg, '--title=')) {
                // " --title=" results in empty string, effectively removing it
                $this->titleInput = substr($arg, 8); 
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
        if (empty($this->pathInput)) { throw new Exception("Missing --path value (or BATCH_PATH env var)"); }
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
        // Check for Video Copy Mode
        if ($this->videoProfileKey === 'copy') {
             $this->finalVidOptions = 'copy';
        } else {
             $this->finalVidOptions = is_callable($rawVid) ? $rawVid($this->extraArgs) : $rawVid;
        }

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

        if (!empty($vppString) && $this->videoProfileKey !== 'copy') {
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

        // Robust Check: Try Unix path first, then Windows path
        $existsFile = is_file($targetPath) || is_file($this->toWinPath($targetPath));
        $existsDir  = is_dir($targetPath)  || is_dir($this->toWinPath($targetPath));

        if ($existsFile) {
            echo "Target identified as Single File.\n";
            return [$targetPath];
        } 
        elseif ($existsDir) {
            // Remove any trailing slashes since ScanDir adds DIRECTORY_SEPARATOR
            $targetPath = rtrim($targetPath, ' /\\');

            echo "Scanning: $targetPath (Recursive: " . ($this->recursive ? 'ON' : 'OFF') . ")\n";
            
            // FIX: Pass Windows-style path to the scanner to ensure UNC compatibility
            // ScanDir returns filenames/paths, which we then sanitize back to /
            $scanPath = $this->toWinPath($targetPath);
            $scanned = ScanDir::scan($scanPath, $srcExts, $this->recursive);

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

        $maxLength = 80;

        foreach ($files as $cleanPath) {
            // $cleanPath is guaranteed to be C:/Path/to/file.mkv
            $fileName = basename($cleanPath);

            // Probe Logic
            // FIX: Pass Windows Path to Probe for UNC compatibility
            $probeData = Probe::analyze($this->toWinPath($cleanPath));
            
            if (!$probeData) {
                echo "Warning: Could not analyze file $fileName. Using defaults.\n";
                $probeData = [
                    'width' => 1920, 'height' => 1080, 'video_codec' => 'unknown', 
                    'is_hdr' => false, 'primaries' => null,
                    'audio_codec' => 'opus', 'audio_channels' => 0,
                    'audio_tracks' => [], // Ensure array exists
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
            
            // --- Report Audio Track Selection ---
            $keepTracks = [];
            if (!empty($this->audioLangs)) {
                foreach ($probeData['audio_tracks'] as $track) {
                    $tLang = strtolower($track['lang']);
                    if (in_array($tLang, $this->audioLangs)) {
                        $keepTracks[] = $track;
                    }
                }
                if (empty($keepTracks)) {
                     echo "    Warning: No audio matched '" . implode(',',$this->audioLangs) . "'. Keeping ALL.\n";
                     $keepTracks = $probeData['audio_tracks'];
                } else {
                     echo "    Audio Selection: Keeping " . count($keepTracks) . " tracks matching '" . implode(',',$this->audioLangs) . "'.\n";
                }
            } else {
                // Default: Keep ALL tracks (New Behavior for Multi-Track support)
                $keepTracks = $probeData['audio_tracks'];
                // Only log if interesting
                if (count($keepTracks) > 1) echo "    Audio Selection: Keeping ALL " . count($keepTracks) . " tracks.\n";
            }
            // ----------------------------------------

            // --- SUBTITLE & CHAPTER LOGIC ---
            $subJobs = "";
            $subInputs = "";
            $subMaps   = "";
            $subClean  = "";

            // Input Index Tracker for Muxer
            // 0=Video (PreMux or Source), 1=Audio (OutAud)
            $nextMuxIndex = 2; 

            // Chapter Logic (Map from Source)
            $chapterMapArgs = "";
            if ($probeData['has_chapters']) {
                // Add Source File as Input to Muxer just for chapters
                $subInputs .= sprintf(' -i "%s"', $this->toWinPath($cleanPath));

                // Map chapters from this input (Index 2 usually)
                $chapterMapArgs = " -map_chapters $nextMuxIndex";
                $nextMuxIndex++; 
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
                    
                    // Added -map_chapters -1 to prevent chapters in sub file
                    $subJobs .= sprintf('%s -i "%s" -map 0:%d -c copy -map_chapters -1 "%s"' . "\n",
                        $this->toWinPath(Config::get('AUD_ENC')),
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

            // --- SMART AUDIO LOGIC ---
            // Note: Applying Smart Logic based on the FIRST track in selection for consistency
            // For complex mixed-codec scenarios, manual profiles should be used.
            $activeProfile = $this->audioProfileKey;
            $srcCodec = strtolower($probeData['audio_codec'] ?? '');
            $srcCh    = intval($probeData['audio_channels'] ?? 0);

            // SANITY CHECK: Prevent upmixing Stereo to 5.1 (opus-8-6)
            if ($activeProfile === 'opus-8-6' && $srcCh <= 2) {
                echo "  [Smart Audio]: Profile 'opus-8-6' selected but source is Stereo. Switching to 'opus-stereo'.\n";
                $activeProfile = 'opus-stereo';
            }

            // Check for Smart Copy conditions
            if ($srcCodec === 'opus') {
                if ($activeProfile === 'default') {
                    // Default usually encodes, but if source is Opus, we prefer Copy
                    $activeProfile = 'copy';
                    echo "  [Smart Audio]: Source is Opus (Default Profile). Switched to Copy.\n";
                }
                elseif (($activeProfile === 'opus-5.1' || $activeProfile === 'opus-8-6') && $srcCh === 6) {
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
                // Rule: Copy AAC unless downmixing (5.1->Stereo OR 7.1->5.1)
                $isDownmix = (($srcCh > 2) && ($activeProfile === 'opus-stereo' || $activeProfile === 'opus-pans'))
                          || (($srcCh > 6) && ($activeProfile === 'opus-8-6'));

                if (!$isDownmix) {
                    $activeProfile = 'copy';
                    echo "  [Smart Audio]: Source is AAC (No Downmix). Switched to Copy.\n";
                }
            }
            elseif ($activeProfile === 'opus-8-6' && $srcCh > 6) {
                echo "  [Smart Audio]: Source is $srcCodec ($srcCh channels). Will downmix to 5.1 Opus.\n";
            }
            // --------------------------

            // --- BUILD AUDIO MAPS & FLAGS ---
            $audMapStr = "";
            $audDispStr = "";
            $outAudIndex = 0;
            
            foreach ($keepTracks as $track) {
                // $audMapStr .= " -map 0:a:{$track['index']}"; // Map source index
                $audMapStr .= " -map 0:{$track['index']}"; // Use Global Index (0:1, 0:2) instead of Relative Audio Index (0:a:1)

                // Determine Flags
                $isDef = 0;
                if (!empty($this->defaultLang)) {
                    if (strtolower($track['lang']) === $this->defaultLang) {
                        $isDef = 1;
                        $this->defaultLang = ""; // Only flag the first match
                    }
                } elseif ($track['default']) {
                    $isDef = 1;
                }
                
                $audDispStr .= " -disposition:a:$outAudIndex " . ($isDef ? 'default' : '0');
                $outAudIndex++;
            }
            // --------------------------------------

            // --- AUDIO EXECUTION LOGIC ---
            $audioExt = 'opus'; // Default
            $finalAudOpts = $this->finalAudOptions; // Default

            if ($activeProfile === 'copy') {
                // MKA is the safest container for copying (supports multiple streams & all codecs)
                $audioExt = 'mka';
                
                $finalAudOpts = '-c:a copy';
                echo "  [Audio]: Copy mode detected.";
            } else {
                echo "  [Audio]: Encoding to {$activeProfile}.";
            }
            echo " Source: $srcCodec -> Ext: .$audioExt\n";

            // Video/Level Logic
            $targetW = $probeData['width'];
            $targetH = $probeData['height'];

            if (!empty($this->resizeInput) && preg_match('/^(\d+)x(\d+)$/', $this->resizeInput, $resMatch)) {
                $targetW = intval($resMatch[1]);
                $targetH = intval($resMatch[2]);
            }

            $colorParams = "";
            $hdrParams   = "";

            if ($probeData['is_hdr']) {
                $colorParams = "--transfer smpte2084 --colorprim bt2020 --colormatrix bt2020nc";
                if (!empty($probeData['hdr_mastering'])) {
                    $hdrParams = '--master-display "' . $probeData['hdr_mastering'] . '"';
                }
                echo "  [Auto-Spec]: Detected HDR. Using BT.2020 color matrix.\n";
            } else {
                if ($probeData['primaries'] === 'bt709') {
                    $colorParams = "--transfer bt709 --colorprim bt709 --colormatrix bt709";
                    echo "  [Auto-Spec]: SDR (bt709) Detected.\n";
                } else {
                    echo "  [Auto-Spec]: SDR (Unknown/Other).\n";
                }
            }

            // BUILD JOBS
            // Check for Video Copy Mode
            $isVideoCopy = ($this->videoProfileKey === 'copy');
            
            $outVid = $this->wrkPath . $this->swapExt($fileName, 'h265');
            $outAud = $this->wrkPath . $this->swapExt($fileName, $audioExt);
            $preMux = $this->wrkPath . $this->swapExt($fileName, 'mkv', '__');
            $finMkv = $this->wrkPath . $this->swapExt($fileName, 'mkv');

            $currentVidOptions = trim($this->finalVidOptions . " $colorParams $hdrParams");

            // Format Commands (Use toWinPath() here for the Batch File content)

            $preMxJob = "";
            $cleanJob = "";

            if (!$isVideoCopy) {
                // Video Encode Job
                $videoJob = sprintf('%s %s -i "%s" -o "%s"' . "\n", 
                    $this->toWinPath(Config::get('VID_ENC')), 
                    $currentVidOptions, 
                    $this->toWinPath($cleanPath), 
                    $this->toWinPath($outVid)
                );

                // Pre-Mux Job
                $preMxJob = sprintf('%s -o "%s" "%s"' . "\n", 
                    $this->toWinPath(Config::get('MKV_MRG')),
                    $this->toWinPath($preMux), 
                    $this->toWinPath($outVid)
                );
                
                // Cleanup items for Encode mode
                $cleanJob .= sprintf('Remove-Item "%s"' . "\n", $this->toWinPath($outVid));
                $cleanJob .= sprintf('Remove-Item "%s"' . "\n", $this->toWinPath($preMux));
            } else {
                echo "  [Video]: Copy Mode (Remux). Skipping Encode.\n";
            }

            $metaArgs = "";
            if ($this->titleInput !== null) {
                // Determine title arg (empty string strips it)
                $metaArgs = sprintf(' -metadata title="%s"', $this->titleInput);
            }

            // Audio Job (Updated with multi-track Maps)
            $audioJob = sprintf('%s -i "%s" %s %s %s %s "%s"' . "\n", 
                $this->toWinPath(Config::get('AUD_ENC')), 
                $this->toWinPath($cleanPath), 
                $audMapStr,    // Map specific tracks
                $finalAudOpts, 
                $audDispStr,   // Set flags
                $metaArgs,
                $this->toWinPath($outAud)
            );

            // Mux Job
            $muxCmd = $this->toWinPath(Config::get('MKV_MUX'));
            $muxInputs = "";
            $muxMaps = "";

            // 1. Video Input
            if ($isVideoCopy) {
                // Input 0: Source File (Map Source Video Track 0)
                $muxInputs .= sprintf(' -i "%s"', $this->toWinPath($cleanPath));
                $muxMaps   .= " -map 0:v:0"; 
            } else {
                // Input 0: Encoded Video (Map PreMux Video Track 0)
                $muxInputs .= sprintf(' -i "%s"', $this->toWinPath($preMux));
                $muxMaps   .= " -map 0:v:0";
            }

            // 2. Audio Input
            // Input 1: Audio File (Map ALL tracks from this new file)
            $muxInputs .= sprintf(' -i "%s"', $this->toWinPath($outAud));
            $muxMaps   .= " -map 1:a"; 

            // 3. Subtitles & Chapters Inputs
            // Appended dynamically (e.g. -i source for chapters, -i sub_eng.mkv...)
            $muxInputs .= $subInputs;

            // 4. Map Arguments
            // Appended dynamically (e.g. -map_chapters 2 -map 3:0...)
            $muxMaps   .= " $chapterMapArgs $subMaps";

            // Generate Final Command
            $muxerJob = sprintf('%s %s %s %s -c copy "%s"' . "\n",
                $muxCmd,
                $muxInputs,
                $muxMaps,
                $metaArgs,
                $this->toWinPath($finMkv)
            );

            // Remaining Cleanup
            $cleanJob .= sprintf('Remove-Item "%s"' . "\n", $this->toWinPath($outAud));
            $cleanJob .= $subClean;

            // Output to Screen
            $displaySrc = $this->toWinPath($cleanPath);
            $displaySrc = (strlen($displaySrc) > $maxLength) ? '...' . substr($displaySrc, -$maxLength) : $displaySrc;
            echo "Queuing: $displaySrc\n";

            // Write to Files (Restored Original Block Structure)
            // Only write Video/PreMux if we are NOT copying
            if (!$isVideoCopy) {
                file_put_contents($videoBat, $videoJob, FILE_APPEND);
                file_put_contents($mergeBat, $preMxJob, FILE_APPEND);
            }
            if (strlen($subJobs)) {
                file_put_contents($subBat, $subJobs, FILE_APPEND);
            }
            file_put_contents($audioBat, $audioJob, FILE_APPEND);
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
