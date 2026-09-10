# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Refer to README.md for user-facing documentation** — CLI arguments (including `--skip-size` and its `--vid-only`/`--aud-only` rescue companions), video/audio profiles, example commands, and the generated-script execution workflow. This file covers only internal architecture and conventions.

## Project Overview

A PHP 8.0+ CLI tool that generates PowerShell scripts for batch HEVC/Opus video transcoding and MKV remuxing. It orchestrates `NVEncC` (GPU video encode), `ffmpeg` (audio encode/mux), `mkvmerge` (container ops), and `mkvpropedit` (metadata editing). Designed for Windows with Git Bash path compatibility.

## Commands

```bash
# Run the tool (see README for full argument reference)
php run.php --path="..." --prefix=...

# Build Phar archive
php build.php
```

There is no test suite, linter, or package manager (no composer.json). The only build step is `php build.php`, which packages the PHP files into `batch-encoder.phar` with `run.php` as stub. Verify changes by running `php run.php` against a temp directory of dummy/real media files and inspecting the generated `.ps1` output.

## Architecture

**Entry point & autoloading:** `run.php` uses `spl_autoload_register` to load classes by filename from the same directory. It instantiates `BatchEncoder` with `$argv` and calls `->run()`.

**Core classes:**

| Class | Role |
|---|---|
| `BatchEncoder` | CLI arg parsing, path sanitization, media scanning & grouping, per-file profile resolution, script generation. The "brain" — ~1000 lines. |
| `Probe` | Runs `ffprobe` in two passes: (1) JSON stream/chapter metadata, (2) frame side-data for HDR mastering display metadata. Returns unified array; returns null on unparseable files (callers fall back to defaults and continue). |
| `Profiles` | Static `getVideo()`/`getAudio()` returning profile key → argument strings. Callable profiles accept dynamic overrides from `--q`, `--bitrate`, `--bitaud*`. `formatVideoExtraArgs()` separates video-specific args from audio-specific args. |
| `ScanDir` | Recursive or flat directory scanner filtered by extension (mkv, mp4). Static state — call `ScanDir::scan($path, $exts, $recursive)`. |
| `Config` | Static config loader; hardcoded defaults overlaid by `.env.yaml` (gitignored, resolved alongside the script or via `Phar::running(false)` when packaged). |

**Three workflows, dispatched by `BatchEncoder::run()`** (user-facing behavior documented in README):

1. **Standard encode** (`generateBatchFiles`): five `.ps1` scripts per file (`_vid`, `_aud`, `_sub`, `_mux`, `_del`). The per-track audio smart-logic (copy vs encode vs downmix) lives in the track loop here; the same loop applies `--langs`/`--default-lang` selection. Video passthrough (`--video=copy` global, or per-file via `--vid-only` rescue) shares one code path keyed on `$isVideoCopy`; audio passthrough (`--aud-only`) maps the source file's audio directly in the mux. Size filtering (`--skip-size`) happens earlier in `scanAndGroupTargets()`, which tags rescued files in `$skipMatchedPaths`.
2. **Custom mux / Assembler** (`generateCustomMuxFiles`): groups files from multiple `--path` inputs by normalized filename (casefolded, punctuation → spaces) and applies ffmpeg map/disposition args read from a text file.
3. **Custom props** (`generateCustomPropsFiles`): copies each file to the work path, then runs `mkvpropedit` with args from a text file.

**Path handling convention:**
- Internally, all paths use forward slashes (`C:/path/file.mkv`).
- `sanitizePath()` normalizes: backslashes → forward, strips quotes, converts Git Bash `/c/path` → `C:/path`, resolves relative paths against CWD for Phar compatibility.
- `toWinPath()` converts back to backslashes only at the output boundary (`.ps1` content, tool invocation).
- File-existence/size checks try the Unix-style path first, then the Windows-style path.

## Important constraints

- **No dependencies** — pure PHP with no Composer packages. All tool invocations are via `exec()`/`shell_exec()`.
- **Windows-first** — the generated `.ps1` files are PowerShell scripts; `Config` defaults point to Windows paths.
- **Backup files are gitignored** — `*-bak*` patterns exclude the numerous `.php-bak*.php` files. Only the canonical `.php` files are source of truth.
- **`.env.yaml` is local-only** — never committed; each environment has its own tool paths. `.env-example.yaml` is the documented template.
