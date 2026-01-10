# **Batch Video Encoder Wrapper Tools**

This readme provides a comprehensive overview of the PHP-based batch video encoding system designed to automate high-quality HEVC/Opus transcoding and complex remuxing workflows.

A robust, configurable batch automation tool written in PHP. It generates PowerShell scripts to process video files (MKV/MP4) using `ffmpeg`, `mkvmerge`, `mkvpropedit`, and `NVEncC`.

It automates the complex chain of **Video Encoding** (NVEnc/HEVC), **Audio Processing** (Smart Copy/Opus/AAC), **Subtitle Extraction**, **Chapter Preservation**, and final **Muxing**. Beyond standard encoding, it now supports advanced **"Assembler"** workflows for merging streams from multiple sources and editing file metadata in batches.

<br>

---
<br>

## **Features**

- **Batch & Recursive Processing**: Automatically scans directories for `.mkv` and `.mp4` files, with optional recursive subdirectory support.
- **Multi-Source "Assembler" Mode**: Can scan multiple input paths simultaneously (`--path="A/" --path="B/"`) and group files by fuzzy filename matching to merge video from one source with audio/subs from another.
- **Custom Muxing Engine**: Supports "Passthrough" mode where complex `ffmpeg` mapping logic (maps, dispositions, metadata) is read from a text file and applied to batches of files.
- **Metadata Editing**: Includes a mode for `mkvpropedit` to perform in-place updates of track flags and titles without full file remuxing.
- **Intelligent HDR Handling**: Detects HDR10 metadata (Mastering Display Metadata) and automatically configures NVEncC with correct transfer characteristics and color matrices.
- **Smart Audio Logic**: Analyzes source audio streams; if the source is already Opus or AAC and matches the target profile (e.g., Opus 2.0 -> Opus 2.0), it defaults to a lossless copy (`.mka`) instead of re-encoding.
- **Subtitle & Chapter Extraction**: Preserves original chapters and extracts English-language subtitles while filtering out SDH (Hearing Impaired) tracks.
- **Dynamic Video Spec Selection**: Automatically calculates the appropriate H.265 level (4.1 for 1080p, 5.0 for 4K) based on output resolution.
- **Modular Script Generation**: Creates separated PowerShell scripts (`_vid`, `_aud`, `_sub`, `_mux`, `_del`) for modular execution.
- **Git Bash / Windows Friendly:** Accepts paths in both Windows (`C:\Path`) and Git Bash (`/c/Path`) formats.
- **Video Post-Processing (VPP)**: Built-in support for debanding and edge-leveling filters.

<br>

## **Requirements**

- **PHP 8.0+ (CLI)**: Required to execute the runner script: [PHP for Windows](https://windows.php.net/download/).
- **NVEncC**: Used for GPU hardware-accelerated HEVC video encoding: [Rigaya NVEnc](https://github.com/rigaya/NVEnc/releases).
- **FFmpeg Utils**: (`ffmpeg.exe` & `ffprobe.exe`) Required for audio encoding, stream analysis, and muxing: [Gyan builds](https://www.gyan.dev/ffmpeg/builds/).
- **MKVToolNix**: (`mkvmerge.exe` & `mkvpropedit.exe`) Used for container operations and metadata editing: [MKVToolNix](https://mkvtoolnix.download/downloads.html#windows).
- **Windows PowerShell**: Required to execute the generated `.ps1` batch files.

<br>

## **Configuration**

The system is configured via `.env.yaml` with fallback to paths in `Config.php`. Ensure the following paths are correct for your environment:

- **Tools**: Define absolute paths for MKV_MRG, MKV_PED, VID_ENC, AUD_ENC, and FFPROBE.
- **Default Paths**: Set DEFAULT_WRK_PATH for temporary/final media files and DEFAULT_JOB_PATH for the generated scripts.

`.env-example.yaml`:
```yaml
# Tool Paths
MKV_MRG: "E:/Apps/mkvtoolnix/mkvmerge.exe"
MKV_PED: "E:/Apps/mkvtoolnix/mkvpropedit.exe"
VID_ENC: "E:/Apps/NVEnc/NVEncC64.exe"
AUD_ENC: "E:/Apps/ffmpeg/ffmpeg.exe"
MKV_MUX: "E:/Apps/ffmpeg/ffmpeg.exe"
FFPROBE: "E:/Apps/ffmpeg/ffprobe.exe"

# Defaults
DEFAULT_WRK_PATH: "E:/temp/"
DEFAULT_JOB_PATH: "./output/"
```

`Config.php`:
```php
class Config
{
    // --- DEFAULTS (Fallback if .env.yaml is missing) ---
    private static $defaults = [
        'MKV_MRG' => 'E:/Apps/mkvtoolnix/mkvmerge.exe',
        'MKV_PED' => 'E:/Apps/mkvtoolnix/mkvpropedit.exe',
        'VID_ENC' => 'E:/Apps/NVEnc/NVEncC64.exe',
        'AUD_ENC' => 'E:/Apps/ffmpeg/ffmpeg.exe',
        'MKV_MUX' => 'E:/Apps/ffmpeg/ffmpeg.exe', // Used for final muxing
        'FFPROBE' => 'E:/Apps/ffmpeg/ffprobe.exe',

        // DEFAULTS (Also set in .env, then can be overridden via CLI)
        'DEFAULT_WRK_PATH' => 'E:/temp/',  // Where temporary encoding artifacts (h265, opus, mkv) are stored
        'DEFAULT_JOB_PATH' => './output/', // Where the generated .ps1 scripts are saved
    ];
}
```

<br>

## **Usage**

Run the application from the terminal using the following syntax:

```bash
./run.php --path="[Source Path]" --prefix="[Output Name]" [Options]
```

## **Core Arguments**

| **Argument &nbsp; &nbsp; &nbsp; &nbsp;** | **Description** |
| --- | --- |
| `--path` | **Required.** The source path. Can be used multiple times for multi-source merging. |
| `--prefix` | **Required.** A unique name for the job batch (names the output `.ps1` files). |
| `--recursive` | Enables recursive scanning of subdirectories. |
| `--custom-mux` | Path to a text file containing raw `ffmpeg` args for custom remuxing jobs. |
| `--custom-props` | Path to a text file containing `mkvpropedit` args for metadata updates. |
| `--title` | Sets the global Title metadata. Use `--title=""` to strip the title entirely. |
| `--video` | Selects the video profile (e.g., `2pass`, `cqp`, `copy`). Default: `default`. |
| `--audio` | Selects the audio profile (e.g., `opus-5.1`, `copy`). Default: `default`. |
| `--resize` | Resizes the output video (Format: `WxH`, e.g., `--resize=1920x1080`). |
| `--crop` | Crops the input video (Format: `Left,Top,Right,Bottom`). |
| `--vpp` | Apply hardware video pre-processing filters (`edge`, `deband`, `both`, `none`). |
| `--q` | Overrides the constant quality (CQP) value (e.g., `--q=18`). |
| `--bitrate` | Overrides the target bitrate for 2pass mode (e.g., `--bitrate=2000`). |

<br>

---

## **Advanced Workflows**

### **1. Custom Muxing (The Assembler)**

This mode allows you to merge streams from multiple files (e.g., Video from Source A + Audio from Source B) using a custom mapping definition.

* **How it works**: The script scans all provided `--path` inputs. It groups files by their "fuzzy" filename (ignoring punctuation/case).
* **Usage**: Provide a `params.txt` file containing standard `ffmpeg` arguments.

**Command:**
```bash
./run.php --path="X:/VideoSource/" --path="G:/AudioSource/" --prefix="MyRemux" --custom-mux="mux-params.txt"
```

**Example `mux-params.txt` :**
```text
-map 0:v:0
-map 1:a:1 -map 1:a:0
-map 1:s -map 1:t?
-map_chapters 1
-c copy
-disposition:a:0 default
-metadata:s:a:0 title="Japanese"
-metadata title=""
```
*Result: Generates a batch script that combines the video from the first path with audio/subs from the second path, applying the specific map order and metadata flags defined in the text file.*

### **2. Custom Property Editing**

This mode allows you to modify file headers (Default flags, Track Names) in-place without remuxing the entire file.

* **How it works**: It copies the source file to the output directory, then runs `mkvpropedit` on the copy.
* **Usage**: Provide a `props.txt` file containing `mkvpropedit` selectors.

**Command:**
```bash
./run.php --path="X:/Library/" --prefix="FixFlags" --custom-props="props.txt"
```

**Example `props.txt` :**
```text
--edit track:a1 --set flag-default=0
--edit track:a2 --set flag-default=1
--edit track:s1 --set name="Full Subs"
```

<br>

---

## **Profiles**

### **Video Profiles**

- **2pass**: Variable Bitrate (VBR) targeting a specific bitrate (default: 1200).
- **cqp**: Constant Quality mode (default Q: 20) using 10-bit depth.
- **copy**: Passthrough mode. Copies the video stream as-is without encoding.

### **Audio Profiles**

- **opus-8-6**: Downmixes 7.1 source and encodes to 5.1 channel Opus at 320Kb VBR (better than AC3 640Kb).
- **opus-5.1**: Encodes to 5.1 channel Opus at 224Kb.
- **opus-pans**: Downmixes to stereo at 128Kb with a center channel volume boost.
- **opus-stereo**: Encodes to 2.0 channel Opus at 100Kb.
- **copy**: Directly copies the source audio stream (wraps in `.mka` container).
- **default**: Smart Encode (Opus 5.1 or Stereo depending on source).

### **Smart Copy Notes**
- If you select `default` or `opus-stereo`, but the source file is **already** in that format (e.g., source is Opus 2.0 and you requested `opus-stereo`), the script will automatically switch to **Copy Mode** to prevent quality loss.
- Both "upmix" and downmix checks ensure the source and target aren't the same channel layout or trying to increase channels (e.g. 2.0 to 5.1).
- Similarly, if the source is AAC and the number of channels isn't changing from 5.1 to 2.0 -- then **Copy Mode** is used. This is due to the performance of AAC and Opus being comparable for little yielded improvement in compression.

<br>

## **Example Commands**

**Note:** Assuming your `run.php` is set to executable (`chmod +x run.php`), or substitute the commands with: `php run.php` below.

**Standard High-Quality Recursive Batch:**
```sh
./run.php --path="D:\TV\ShowName" --prefix=ShowName --video=cqp --q=18 --recursive
```

**720p Encode (from higher resolution source):**
```sh
./run.php --path="C:\Movies\MyMovie.mkv" --prefix=MyMovie --video=2pass --resize=1280x720
```

**Cropped Movie with Debanding:**
```sh
./run.php --path="C:\Files\Movie.mkv" --prefix=Clipped --crop=0,140,0,140 --vpp=deband
```

**TV Show using Linux path:**
```sh
./run.php --path="/e/Downloads/TV_Show/" --prefix="Season1"
```

**Anime Optimization (Deband + Stereo Audio):**
```sh
./run.php --path="/e/Anime/Series/" --prefix="AnimeBatch" --video=balanced --vpp=deband --audio=opus-stereo
```

**Default Video Encode (vbrhq 1200) with 7.1 to 5.1 Downmix:**
```sh
./run.php --path="C:\Movies\MyMovie.mkv" --prefix=MyMovie --audio=opus-8-6
```

**Clean Remux (Strip Title + Copy Streams):**
```sh
./run.php --path="C:\Movies\MyMovie.mkv" --prefix=CleanMovie --video=copy --audio=copy --title=""
```

**Multi-Source Merge (Video from X, Audio from G):**
```sh
./run.php --path="X:/Video/" --path="G:/Audio/" --prefix="MergeBatch" --custom-mux="mux.txt"
```

<br>

## **Output**

Upon completion, the application generates five PowerShell scripts in the job directory:

- **\[Prefix\]\_vid.ps1**: Runs the hardware video encoding.
- **\[Prefix\]\_aud.ps1**: Processes the audio streams.
- **\[Prefix\]\_sub.ps1**: Extracts and prepares subtitle files.
- **\[Prefix\]\_mux.ps1**: Merges video, audio, subs, and chapters into the final MKV.
- **\[Prefix\]\_del.ps1**: Cleans up all intermediate temporary files.

## **Workflow**

You can run the first 3 jobs sequentially or in parallel (e.g., run `_vid` and `_aud` at the same time).

```sh
./Prefix_vid.ps1
./Prefix_aud.ps1
./Prefix_sub.ps1
# Wait for above to finish
./Prefix_mux.ps1
# Verify file, then clean up
./Prefix_del.ps1
```

<br>

---
<br>

## **Why? aka quick history...**

Yes, there are many great wrapper tools already for encoding and multiplexing! Handbrake is a good example. I started using NVEnc as a CLI tool long before thinking about encoding audio. For me it was only about taking old media that was typically in H.264 (but occupied a lot of storage) and trying to get those file sizes down without any perceptual loss in quality.

NVEnc was the first time I had seen the potential for leveraging my Nvidia GPU for video transcoding whilst really tweaking the params -- and I was blown away by its performance. Initially the quality wasn't as good for equal bitrates compared with pure CPU-based encodes (which is a complicated topic), but current iterations of cards with huge arrays of CUDA cores has made it possible to transcode in minutes what used to take several hours. So any meagre quality differences aside, the encode times make it all worth it.

Naturally using this tool in isolation meant having to re-mux the source audio manually into a new MKV.  Once I started encoding whole directories of content, it was becoming tiresome. But I didn't want to lose all the preset parameters I'd been using or try to shoehorn them into another wrapper tool.  Naturally this evolved to batch files, manually created, then eventually crudely automated into a single CLI script with fixed hand-coded params. Written in PHP since it's my daily driver, (if you want to fork and migrate to Python or a Bash script -- knock yourself out).
