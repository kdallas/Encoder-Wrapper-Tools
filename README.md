# **Batch Video Encoder**

This readme provides a comprehensive overview of the PHP-based batch video encoding system designed to automate high-quality HEVC/Opus transcoding.

A robust, configurable batch encoding automation tool written in PHP. It generates PowerShell scripts to process video files (MKV/MP4) using `ffmpeg`, `mkvmerge`, and `NVEncC`.

It automates the complex chain of **Video Encoding** (NVEnc/HEVC), **Audio Processing** (Smart Copy/Opus/AAC), **Subtitle Extraction**, **Chapter Preservation**, and final **Muxing**.

## **Features**

- **Batch & Recursive Processing**: Automatically scans directories for .mkv and .mp4 files, with optional recursive subdirectory support.
- **Intelligent HDR Handling**: Detects HDR10 metadata (Mastering Display Metadata) and automatically configures NVEncC with correct transfer characteristics and color matrices.
- **Smart Audio Logic**: Analyzes source audio streams (Opus, AAC, AC3, DTS-HD, TrueHD, Atmos); if the source is already Opus or AAC and matches the target profile, it defaults to a lossless copy instead of re-encoding.
- **Subtitle & Chapter Extraction**: Preserves original chapters and extracts English-language subtitles while filtering out SDH (Hearing Impaired) tracks.
- **Dynamic Video Spec Selection**: Automatically calculates the appropriate H.265 level (4.1 for 1080p, 5.0 for 4K) based on output resolution.
- **Batch Generation:** Creates separated PowerShell scripts (`_vid`, `_aud`, `_sub`, `_mux`, `_del`) for modular execution (e.g., run video on GPU and audio on CPU in parallel).
- **Git Bash / Windows Friendly:** Accepts paths in both Windows (`C:\Path`) and Git Bash (`/c/Path`) formats.
- **Video Post-Processing (VPP)**: Built-in support for debanding and edge-leveling filters.

## **Requirements**

- **PHP 8.0+ (CLI)**: Required to execute the runner script.
- **NVEncC**: Used for hardware-accelerated HEVC video encoding.
- **FFmpeg & FFprobe**: Required for audio encoding, stream analysis, and muxing.
- **MKVToolNix**: (`mkvmerge`) Used for initial video-only stream packaging.
- **Windows PowerShell**: Required to execute the generated .ps1 batch files.

## **Configuration**

The system is configured via Config.php. Ensure the following paths are correct for your environment:

- **Tools**: Define absolute paths for MKV_MRG, VID_ENC, AUD_ENC, and FFPROBE.
- **Default Paths**: Set DEFAULT_WRK_PATH for temporary/final media files and DEFAULT_JOB_PATH for the generated scripts.

```php
class Config
{
    // Tool Paths
    const MKV_MRG = 'E:/Apps/StaxRip/Apps/Support/mkvtoolnix/mkvmerge.exe';
    const VID_ENC = 'E:/Apps/StaxRip/Apps/Encoders/NVEnc/NVEncC64.exe';
    const AUD_ENC = 'E:/Apps/StaxRip/Apps/Encoders/ffmpeg/ffmpeg.exe';
    const MKV_MUX = 'E:/Apps/StaxRip/Apps/Encoders/ffmpeg/ffmpeg.exe'; // Used for final muxing
    const FFPROBE = 'E:/Apps/StaxRip/Apps/Encoders/ffmpeg/ffprobe.exe';

    // Default Directories
    const DEFAULT_WRK_PATH = 'R:/temp/';  // Where temporary encoding artifacts (h265, opus, mkv) are stored
    const DEFAULT_JOB_PATH = './output/'; // Where the generated .ps1 scripts are saved
}
```

## **Usage**

Run the application from the terminal using the following syntax:

```bash
./run.php --path="[Source Path]" --prefix="[Output Name]" [Options]
```

## **Core Arguments**

| **Argument** | **Description** |
| --- | --- |
| `--path` | **Required.** The path to a single file or a directory to scan. |
|   | The source file or directory to process. Accepts standard Windows paths (`C:\`) or Git Bash style (`/c/`). |
| `--prefix` | **Required.** Sets the filename prefix for the generated .ps1 job files. |
|   | A unique name for this batch job. Used to name the output `.ps1` files. |
| `--recursive` | Enables recursive scanning of subdirectories for MKV/MP4 files. |
| `--video` | Selects the video profile (e.g., `2pass`, `cqp`). |
|   | The video profile key to use (see Profiles). Default: `default`. |
| `--audio` | Selects the audio profile to use (e.g., opus-5.1, opus-stereo). Default: `default`. |
| `--resize` | Resizes the output video (Format: `WxH`, e.g., `--resize=1920x1080`). |
| `--crop` | Crops the input video (Format: `Left,Top,Right,Bottom`, e.g., `--crop=0,140,0,140`). |
| `--vpp` | Apply hardware video pre-processing filters (`edge`, `deband`, `both`, `none`). |
| `--q` | Sets the constant quality value for the cqp video profile. |
|   | Overrides the CQP/Quality value defined in the profile (e.g., `--q=18`). |
| `--bitrate` | Sets the target bitrate for the 2pass video profile. |

## **Profiles**

### **Video Profiles**

- **2pass**: Variable Bitrate (VBR) targeting a specific bitrate (default: 1200).
- **cqp**: Constant Quality mode (default Q: 20) using 10-bit depth and high-quality presets.

### **Audio Profiles**

- **opus-5.1**: Encodes to 5.1 channel Opus at 224Kb.
- **opus-pans**: Downmixes multi-channel audio to stereo at 128Kb with a volume boost for the center channel.
- **opus-stereo**: Encodes to 2.0 channel Opus at 100Kb.
- **copy**: Directly copies the source audio stream (pass-through).
- **default**: Attempts to encode to Opus 5.1 (Surround) or Stereo depending on source at 192Kb.

**Smart Copy Note:** If you select `default` or `opus-stereo`, but the source file is **already** in that format (e.g., source is Opus 2.0 and you requested `opus-stereo`), the script will automatically switch to **Copy Mode** to prevent quality loss.  Similarly, if the source is AAC and the number of channels isn't changing from 5.1 to 2.0 -- then **Copy Mode** is used.  This is due to the performance of AAC and Opus being comparable for little yielded improvement in compression.

## **Example Commands**

**Note:** Assuming your `run.php` is set to executable (`chmod +x run.php`), or substitute the commands with: `php run.php` below.

**720p Encode (from higher resolution source):**
```sh
./run.php --path="C:/Movies/MyMovie.mkv" --prefix=MyMovie --video=2pass --resize=1280x720
```

**High-Quality Recursive Batch:**
```sh
./run.php --path="D:/TV/ShowName" --prefix=ShowName --video=cqp --q=18 --recursive
```

**Cropped Movie with Debanding:**
```sh
./run.php --path="C:/Files/Movie.mkv" --prefix=Clipped --crop=0,140,0,140 --vpp=deband
```

**TV Show using Linux path:**
```sh
./run.php --path="/e/Downloads/TV_Show/" --prefix="Season1"
```

**Anime / Animation**
```sh
./run.php --path="/e/Anime/Series/" --prefix="AnimeBatch" --video=balanced --vpp=deband --audio=opus-stereo --recursive
```

## **Output**

Upon completion, the application generates five PowerShell scripts in the job directory:

- **\[Prefix\]\_vid.ps1**: Runs the hardware video encoding.
- **\[Prefix\]\_aud.ps1**: Processes the audio streams.
- **\[Prefix\]\_sub.ps1**: Extracts and prepares subtitle files.
- **\[Prefix\]\_mux.ps1**: Merges video, audio, subs, and chapters into the final MKV.
- **\[Prefix\]\_del.ps1**: Cleans up all intermediate temporary files.

## **Workflow**

You can run these sequentially or in parallel (e.g., run `_vid` and `_aud` at the same time).

```sh
./Prefix_vid.ps1
./Prefix_aud.ps1
./Prefix_sub.ps1
# Wait for above to finish
./Prefix_mux.ps1
# Verify file, then clean up
./Prefix_del.ps1
```
