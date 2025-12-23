# **Batch Video Encoder**

This readme provides a comprehensive overview of the PHP-based batch video encoding system designed to automate high-quality HEVC/Opus transcoding.

#

## **Features**

- **Batch & Recursive Processing**: Automatically scans directories for .mkv and .mp4 files, with optional recursive subdirectory support.
- **Intelligent HDR Handling**: Detects HDR10 metadata (Mastering Display Metadata) and automatically configures NVEncC with correct transfer characteristics and color matrices.
- **Smart Audio Logic**: Analyzes source audio; if the source is already Opus or AAC and matches the target profile, it defaults to a lossless copy instead of re-encoding.
- **Subtitle & Chapter Extraction**: Preserves original chapters and extracts English-language subtitles while filtering out SDH (Hearing Impaired) tracks.
- **Dynamic Video Spec Selection**: Automatically calculates the appropriate H.265 level (4.1 for 1080p, 5.0 for 4K) based on output resolution.
- **Video Post-Processing (VPP)**: Built-in support for debanding and edge-leveling filters.

## **Requirements**

- **PHP CLI**: Required to execute the runner script.
- **NVEncC**: Used for hardware-accelerated HEVC video encoding.
- **ffmpeg & ffprobe**: Required for audio encoding, stream analysis, and muxing.
- **mkvmerge**: Used for initial video-only stream packaging.
- **Windows PowerShell**: Required to execute the generated .ps1 batch files.

## **Configuration**

The system is configured via Config.php. Ensure the following paths are correct for your environment:

- **Tools**: Define absolute paths for MKV_MRG, VID_ENC, AUD_ENC, and FFPROBE.
- **Default Paths**: Set DEFAULT_WRK_PATH for temporary/final media files and DEFAULT_JOB_PATH for the generated scripts.

## **Usage**

Run the application from the terminal using the following syntax:

Bash

./run.php --path="\[Source Path\]" --prefix="\[Output Name\]" \[Options\]  

## **Core Arguments**

| **Argument** | **Description** |
| --- | --- |
| \--path | **Required.** The path to a single file or a directory to scan. |
| --- | --- |
| \--prefix | **Required.** Sets the filename prefix for the generated .ps1 job files. |
| --- | --- |
| \--recursive | Enables recursive scanning of subdirectories. |
| --- | --- |
| \--video | Selects the video profile (e.g., 2pass, cqp). |
| --- | --- |
| \--audio | Selects the audio profile (e.g., opus-5.1, opus-stereo). |
| --- | --- |
| \--resize | Resizes the output (format: WxH, e.g., 1920x1080). |
| --- | --- |
| \--crop | Crops the video (format: L,T,R,B). |
| --- | --- |
| \--vpp | Applies filters: none, deband, edge, or both. |
| --- | --- |
| \--q | Sets the constant quality value for the cqp video profile. |
| --- | --- |
| \--bitrate | Sets the target bitrate for the 2pass video profile. |
| --- | --- |

## **Profiles**

### **Video Profiles**

- **2pass**: Variable Bitrate (VBR) targeting a specific bitrate (default: 1200).
- **cqp**: Constant Quality mode (default Q: 20) using 10-bit depth and high-quality presets.

### **Audio Profiles**

- **opus-5.1**: Encodes to 5.1 channel Opus at 224k.
- **opus-stereo**: Encodes to 2.0 channel Opus at 100k.
- **opus-pans**: Downmixes multi-channel audio to stereo with a volume boost.
- **copy**: Directly copies the source audio stream.

## **Example Commands**

Standard 720p Encode:

./run.php --path="C:/Movies/MyMovie.mkv" --prefix=MyMovie --video=2pass --resize=1280x720

High-Quality Recursive Batch:

./run.php --path="D:/TV/ShowName" --prefix=ShowName --video=cqp --q=18 --recursive

Cropped Movie with Debanding:

./run.php --path="C:/Files/Movie.mkv" --prefix=Clipped --crop=0,140,0,140 --vpp=deband

## **Output**

Upon completion, the application generates five PowerShell scripts in the job directory:

- **\[Prefix\]\_vid.ps1**: Runs the hardware video encoding.
- **\[Prefix\]\_aud.ps1**: Processes the audio streams.
- **\[Prefix\]\_sub.ps1**: Extracts and prepares subtitle files.
- **\[Prefix\]\_mux.ps1**: Merges video, audio, subs, and chapters into the final MKV.
- **\[Prefix\]\_del.ps1**: Cleans up all intermediate temporary files.
