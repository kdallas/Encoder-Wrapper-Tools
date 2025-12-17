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
        // Initialize Defaults
        $this->wrkPath = Config::DEFAULT_WRK_PATH;
        $this->jobPath = Config::DEFAULT_JOB_PATH;

        try {
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

    private function parseArguments($argv) {
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            
            if (str_starts_with($arg, '--path=')) {
                $this->pathInput = substr($arg, 7);
                for ($j = $i + 1; $j < count($argv); $j++) {
                    if (str_starts_with($argv[$j], '-')) break; 
                    $this->pathInput .= " " . $argv[$j];
                    $i++;
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
            // Override Work Files Path (Where .h265 goes)
            elseif (str_starts_with($arg, '--out-path=')) {
                $raw = substr($arg, 11);
                // Ensure Windows Backslashes for the PowerShell script
                $this->wrkPath = $this->normalizePath($raw, '\\'); 
            }
            // Override Batch Script Path (Where .ps1 goes)
            elseif (str_starts_with($arg, '--job-path=')) {
                $raw = substr($arg, 11);
                // Standard slashes are fine for PHP writing files
                $this->jobPath = $this->normalizePath($raw, '/');
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

    private function normalizePath($path, $separator) {
        // Replace all slash types with the desired separator
        $clean = str_replace(['/', '\\'], $separator, $path);
        // Ensure trailing slash
        if (!str_ends_with($clean, $separator)) {
            $clean .= $separator;
        }
        return $clean;
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
        if (preg_match('/^\/([a-zA-Z])\/(.*)/', $this->pathInput, $matches)) {
            $drive = strtoupper($matches[1]);
            $this->pathInput = $drive . ':/' . $matches[2];
        }

        $targetPath = str_replace('\\', '/', $this->pathInput);
        $srcExts = ['mkv','mp4'];

        if (is_file($targetPath)) {
            echo "Target identified as Single File.\n";
            return [$targetPath];
        } 
        elseif (is_dir($targetPath)) {
            if (!str_ends_with($targetPath, '/')) {
                $targetPath .= '/';
            }

            echo "Scanning: $targetPath (Recursive: " . ($this->recursive ? 'ON' : 'OFF') . ")\n";
            $scanned = ScanDir::scan($targetPath, $srcExts, $this->recursive);

            if (empty($scanned)) {
                throw new Exception("No valid files found in directory.");
            }

            return $scanned;
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
        $mergeBat = $this->jobPath . $this->prefixInput . '_mux.ps1';
        $cleanBat = $this->jobPath . $this->prefixInput . '_del.ps1';

        // Reset output files
        if(file_exists($videoBat)) unlink($videoBat);
        if(file_exists($audioBat)) unlink($audioBat);
        if(file_exists($mergeBat)) unlink($mergeBat);
        if(file_exists($cleanBat)) unlink($cleanBat);

        $mergeOptions = '-c copy -map 0:v:0 -map 1:a:0';
        $maxLength = 80;

        foreach ($files as $fullPath) {
            $fullPathUnix = str_replace('\\', '/', $fullPath);
            $fileName = basename($fullPathUnix);

            // Probe Logic
            $probeData = Probe::analyze($fullPathUnix);
            if (!$probeData) {
                echo "Warning: Could not analyze file $fileName. Using defaults.\n";
                $probeData = ['width' => 1920, 'height' => 1080, 'is_hdr' => false, 'primaries' => null];
            }

            // Audio Logic
            $audioExt = 'opus'; // Default for our encoding profiles
        
            if ($this->audioProfileKey === 'copy') {
                // Map ffmpeg codec names to file extensions
                $codecMap = [
                    'dts' => 'dts',
                    'ac3' => 'ac3',
                    'eac3' => 'eac3',
                    'aac' => 'aac',
                    'flac' => 'flac',
                    'truehd' => 'thd',
                    'mp3' => 'mp3'
                ];

                $detected = strtolower($probeData['audio_codec'] ?? '');

                if (array_key_exists($detected, $codecMap)) {
                    $audioExt = $codecMap[$detected];
                } else {
                    // Fallback for unknown codecs (e.g. pcm_s16le), mka is safe for almost anything
                    $audioExt = 'mka'; 
                    echo "  [Audio]: Unknown source codec '$detected'. Defaulting to .mka\n";
                }
                echo "  [Audio]: Copy mode detected. Source: $detected -> Ext: .$audioExt\n";
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
            // Use $this->wrkPath instead of Config::OUT_PATH
            $winSrc = str_replace('/', '\\', $fullPathUnix);
            $outVid = $this->wrkPath . $this->swapExt($fileName, 'h265');
            $outAud = $this->wrkPath . $this->swapExt($fileName, $audioExt);
            $preMux = $this->wrkPath . $this->swapExt($fileName, 'mkv', '__');
            $finMkv = $this->wrkPath . $this->swapExt($fileName, 'mkv');

            $currentVidOptions = $this->finalVidOptions . " --level $level $colorParams $hdrParams";

            $videoJob = sprintf('%s %s -i "%s" -o "%s"' . "\n", 
                Config::VID_ENC, $currentVidOptions, $winSrc, $outVid
            );

            $audioJob = sprintf('%s -i "%s" %s "%s"' . "\n", 
                Config::AUD_ENC, $winSrc, $this->finalAudOptions, $outAud
            );

            $preMxJob = sprintf('%s -o "%s" "%s"' . "\n", 
                Config::MKV_MRG, $preMux, $outVid
            );

            $muxerJob = sprintf('%s -i "%s" -i "%s" %s "%s"' . "\n", 
                Config::MKV_MUX, $preMux, $outAud, $mergeOptions, $finMkv
            );

            // SEPARATE CLEANUP JOB
            $cleanJob  = sprintf('Remove-Item "%s"' . "\n", $outVid);
            $cleanJob .= sprintf('Remove-Item "%s"' . "\n", $outAud);
            $cleanJob .= sprintf('Remove-Item "%s"' . "\n", $preMux);

            // Output to Screen
            $displaySrc = (strlen($winSrc) > $maxLength) ? '...' . substr($winSrc, -$maxLength) : $winSrc;
            echo "Queuing: $displaySrc\n";

            // Write to Files
            file_put_contents($videoBat, $videoJob, FILE_APPEND);
            file_put_contents($audioBat, $audioJob, FILE_APPEND);
            file_put_contents($mergeBat, $preMxJob, FILE_APPEND);
            file_put_contents($mergeBat, $muxerJob, FILE_APPEND);
            file_put_contents($cleanBat, $cleanJob, FILE_APPEND);
        }

        echo "\nDone. Created:\n- $videoBat\n- $audioBat\n- $mergeBat\n- $cleanBat\n";
    }

    private function swapExt($filename, $newExt, $suffix='') {
        $info = pathinfo($filename);
        return $info['filename'] . $suffix . '.' . $newExt;
    }
}
