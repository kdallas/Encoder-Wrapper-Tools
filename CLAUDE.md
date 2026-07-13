# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A PHP 8.0+ CLI tool that generates PowerShell scripts for batch HEVC/Opus video transcoding and MKV remuxing. It orchestrates `NVEncC` (GPU video encode), `ffmpeg` (audio encode/mux), `mkvmerge` (container ops), and `mkvpropedit` (metadata editing). Designed for Windows with Git Bash path compatibility.

## Commands

```bash
# Standard encode (generate .ps1 batch files)
./run.php --path="C:/Videos/" --prefix=MyBatch --video=cqp --q=18 --recursive

# Build Phar archive
php build.php

# Run the Phar directly (requires php in PATH)
./batch-encoder.phar --path="..." --prefix=...

# There is no test suite, linter, or package manager (no composer.json).
# The only "build" step is `php build.php` which packages files into batch-encoder.phar.
```

## Architecture

**Entry point & autoloading:** `run.php` uses `spl_autoload_register` to load classes by filename from the same directory. It instantiates `BatchEncoder` with `$argv` and calls `->run()`.

**Configuration flow:** `Config::get()` starts with hardcoded defaults, then overlays values from `.env.yaml` (if it exists alongside the script/Phar). Keys: `MKV_MRG`, `MKV_PED`, `VID_ENC`, `AUD_ENC`, `MKV_MUX`, `FFPROBE`, `DEFAULT_WRK_PATH`, `DEFAULT_JOB_PATH`. `.env.yaml` is gitignored; `.env-example.yaml` is the template.

**Core classes:**

| Class | Role |
|---|---|
| `BatchEncoder` | CLI arg parsing, path sanitization, media scanning & grouping, per-file profile resolution, audio smart-logic, script generation. The "brain" — ~900 lines. |
| `Probe` | Runs `ffprobe` in two passes: (1) JSON stream/chapter metadata, (2) frame side-data for HDR mastering display metadata. Returns unified array with video specs, per-track audio info, subtitle list, and chapter presence. |
| `Profiles` | Static `getVideo()` and `getAudio()` returning profile key → NVEnc or ffmpeg argument strings. Callable profiles accept dynamic overrides from `--q`, `--bitrate`, `--bitaud`, `--bitaud-51`, `--bitaud-20`. Separates video-specific args from audio-specific args via `formatVideoExtraArgs()`. |
| `ScanDir` | Recursive or flat directory scanner filtered by extension (mkv, mp4). Uses static state — call `ScanDir::scan($path, $exts, $recursive)`. |
| `Config` | Singleton-like static config loader. Lazy-loads on first `get()`. |

**Three workflows, dispatched by `BatchEncoder::run()`:**

1. **Standard encode** (`generateBatchFiles`): For each file found, probes media info, resolves video/audio profiles with per-track smart logic (auto-copy if source already matches target codec/channels), and generates five `.ps1` scripts: `_vid.ps1`, `_aud.ps1`, `_sub.ps1`, `_mux.ps1`, `_del.ps1`.

2. **Custom mux / Assembler** (`generateCustomMuxFiles`): Groups files from multiple `--path` inputs by fuzzy filename matching (normalized — casefolded, punctuation → spaces). Reads ffmpeg map/disposition args from a text file and generates a single `_mux.ps1` that merges streams from different sources.

3. **Custom props** (`generateCustomPropsFiles`): Copies each file to the work path, then runs `mkvpropedit` with args from a text file for in-place metadata editing. Generates `_props.ps1`.

**Path handling convention:**
- Internally, all paths use forward slashes (`C:/path/file.mkv`).
- `sanitizePath()` normalizes: backslashes → forward, strips quotes, converts Git Bash `/c/path` → `C:/path`, resolves relative paths against CWD for Phar compatibility.
- `toWinPath()` converts back to backslashes only at output boundary (`.ps1` file content).
- File-existence checks try both Unix-style and Windows-style paths.

**Audio smart-logic (per-track):** For each audio stream, the system re-probes codec/channels with ffprobe, then decides whether to copy or encode. Rules: Opus source matching the target profile → copy; AAC source without downmixing → copy; 7.1+ source with `opus-8-6` → downmix; `default` profile auto-selects based on channel count.

**Phar packaging:** `build.php` bundles all PHP files into `batch-encoder.phar` with `run.php` as stub. The `Config` class detects Phar execution via `Phar::running(false)` and resolves `.env.yaml` relative to the Phar's location. A pre-built Windows `.exe` (likely via php2exe or similar) lives in `build/windows-x64/`.

## Important constraints

- **No dependencies** — pure PHP with no Composer packages. All tool invocations are via `exec()`/`shell_exec()`.
- **Windows-first** — the generated `.ps1` files are PowerShell scripts. `Config` defaults point to Windows paths (`E:/`).
- **Backup files are gitignored** — `*-bak*` patterns in `.gitignore` exclude the numerous `.php-bak*.php` files. Only the canonical `.php` files are source of truth.
- **`.env.yaml` is local-only** — never committed; each environment has its own tool paths. The `.env-example.yaml` is the documented template.
