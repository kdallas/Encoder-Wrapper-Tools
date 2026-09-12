# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Refer to README.md for user-facing documentation** — CLI arguments (including `--skip-size` and its `--vid-only`/`--aud-only` rescue companions), video/audio profiles, example commands, and the generated-script execution workflow. This file covers only internal architecture and conventions.

## Project Overview

A PHP 8.0+ CLI tool that generates PowerShell scripts for batch HEVC/Opus video transcoding and MKV remuxing. It orchestrates `NVEncC` (GPU video encode), `ffmpeg` (audio encode, subtitle extraction, custom mux), `mkvmerge` (final mux), and `mkvpropedit` (metadata editing). Designed for Windows with Git Bash path compatibility.

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

1. **Standard encode** (`generateBatchFiles`): up to five `.ps1` scripts per file (`_vid`, `_aud`, `_sub`, `_mux`, `_del`). Each is omitted when it has nothing to do — no `_sub` without matching subtitles, no `_aud` for an `--aud-only` file, no `_vid` for a video-passthrough file. The final `_mux` is mkvmerge (see "Muxing" below). The per-track audio smart-logic (copy vs encode vs downmix) lives in the track loop here; the same loop applies `--langs`/`--default-lang` selection. Video passthrough (`--video=copy` global, or per-file via `--vid-only` rescue) shares one code path keyed on `$isVideoCopy`; audio passthrough (`--aud-only`) maps the source file's audio directly in the mux. Size filtering (`--skip-size`) happens earlier in `scanAndGroupTargets()`, which tags rescued files in `$skipMatchedPaths`.
2. **Custom mux / Assembler** (`generateCustomMuxFiles`): groups files from multiple `--path` inputs by normalized filename (casefolded, punctuation → spaces) and applies ffmpeg map/disposition args read from a text file.
3. **Custom props** (`generateCustomPropsFiles`): copies each file to the work path, then runs `mkvpropedit` with args from a text file.

**Path handling convention:**
- Internally, all paths use forward slashes (`C:/path/file.mkv`).
- `sanitizePath()` normalizes: backslashes → forward, strips quotes, converts Git Bash `/c/path` → `C:/path`, resolves relative paths against CWD for Phar compatibility.
- `toWinPath()` converts back to backslashes only at the output boundary (`.ps1` content, tool invocation).
- File-existence/size checks try the Unix-style path first, then the Windows-style path.

## Muxing: why the final mux is mkvmerge

The standard workflow's final mux is **mkvmerge** (`MKV_MRG`), consuming the raw `.h265` directly. There is no intermediate `.mkv`. This replaced an older design that ran mkvmerge to build a `<name>__.mkv`, then muxed that with ffmpeg (`MKV_MUX`).

Two independent reasons ffmpeg cannot be the final muxer:

1. **ffmpeg cannot mux raw HEVC at all.** It fails with `Can't write packet with unknown timestamp`, or writes corrupt output even with `-r` (reproduced on ffmpeg 5.1.2 and 8.0.1). The old `__.mkv` existed purely to hand ffmpeg a timestamped Matroska to read.
2. **ffmpeg drops MKV attachments** — embedded subtitle fonts are silently lost.

mkvmerge reads raw HEVC natively and derives the frame rate from the SPS VUI (`default_duration=41708333` for 24000/1001), so no `--default-duration` hint is needed. `MKV_MUX` survives only for the custom-mux workflow, which feeds it ffmpeg args from a user-supplied file.

### mkvmerge CLI semantics (v96) — the rules that corrupt output silently

These differ from ffmpeg's model and are not obvious:

- **Per-file options bind to the *following* input file**, not the preceding one. `--language 1:eng file.mkv` applies to `file.mkv`; `file.mkv --language 1:eng` silently does nothing. This is the inverse of ffmpeg's post-file `-disposition:a:N`.
- **Track-selection flags** (`-D`/`-A`/`-S`, `--no-chapters` — the `--no-*` / `-x-tracks` family) apply to the **next file only**.
- **Track-property options** (`--language`, `--default-track-flag`, `--track-name`, `--sync`, `--forced-display-flag`, …) **persist to every following file**. This is the dangerous one — a stale `--default-track-flag 1:1` is re-applied to later inputs. **Every input must re-declare its own properties explicitly**; never rely on a value carrying through.
- Track IDs are the **input file's** IDs (`mkvmerge -i`), not output positions.
- An option naming a TID the file does not contribute is a **warning, not an error** (exit 0), so mistakes degrade quietly rather than failing loudly.
- An input contributing **zero** tracks is accepted (exit 0). This is what lets the source be appended as a chapters/attachments-only input via `-D -A -S`, so no separate attachment probe is needed.
- Output track order is sorted **by type** (video → audio → subtitles), then input order. No `--track-order` is required.

### Chapter merging hazard

**ffmpeg takes chapters from the single input named by `-map_chapters`; mkvmerge merges chapters from *every* input.** The audio job does not pass `-map_chapters -1`, so ffmpeg copies the source's chapters into the `.mka`. Muxing that `.mka` alongside the source without `--no-chapters` on the audio input produces **doubled chapters**. That flag is load-bearing.

### Input shapes in `generateBatchFiles()`

The mux is built per-file in four shapes. All emit each input's options *before* that input's path:

| Shape | Condition | Inputs, in order |
|---|---|---|
| A | normal encode | `.h265` → `outAud` (`-D -S --no-chapters`) → subtitle files → source (`-D -A -S --no-global-tags --no-track-tags`, for chapters + attachments) |
| B | `--video=copy`, or a `--vid-only` rescue | source (`-A -S`, video + chapters + attachments) → `outAud` |
| C | a `--aud-only` rescue | `.h265` → source (`-D -S --audio-tracks …`, audio + chapters + attachments) |
| D | `--video=copy` **and** `--aud-only` | source once (`-S`) — the ffmpeg path used to open it twice |

`--default-track-flag` is always emitted: mkvmerge's natural defaults differ from ffmpeg's, so omitting it changes the output. Video gets `0:1`, and audio tracks get `:1`/`:0` from the same `$isDef` decision that drives ffmpeg's `-disposition:a:N`.

## Opus and container notes

- **Opus pre-skip / `CodecDelay`** is 312 samples ≈ 6.5 ms. Ogg signals it via granule positions, Matroska via `CodecDelay` (6500000 ns). The spec leaves the interaction with block placement ambiguous, so ffmpeg and mkvmerge resolved it differently — this was the origin of the "very slight pops" that motivated the `__.mkv` intermediate in 2022.
- **`Error parsing Opus packet header`** is ffmpeg's own Opus parser flushing at EOF — innocuous, exactly once per file regardless of duration, and independent of which muxer wrote the file (ffmpeg trac #11433). It appears whenever ffmpeg *reads* Opus-in-MKV, but no longer during muxing now that mkvmerge does the final mux.
- **ffprobe stream index vs mkvmerge track ID.** `Probe` records ffprobe's `stream.index` and the mux passes those to mkvmerge as track IDs. For files from ffmpeg/mkvmerge/NVEncC these coincide, but they are different numbering schemes and will diverge on files with unusual Matroska TrackNumbers.

## Verifying a mux change

Payload equality is the only convincing test — container metadata legitimately differs between muxers.

```bash
# video payload (no decode)
ffmpeg -v error -i out.mkv -map 0:v -c copy -f hevc - | md5sum
# audio decoded to PCM — avoids Ogg/Matroska framing differences
ffmpeg -v error -i out.mkv -map 0:a -f s16le - | md5sum
```

Compare those hashes against the reference, then use `mkvmerge -J` for duration, track flags/languages, chapters and attachments. Note `mkvmerge -J` reports tags only as `num_entries` — use `mkvmerge -i` for tag counts. File size and container duration will differ between muxers by ~0.1%; identical payload hashes are what matter.

## Code formatting

`.php-cs-fixer.dist.php` pins `@PER-CS3x0`. Three environment gotchas are encoded in that config: files are CRLF on disk (LF in git), so `setLineEnding("\r\n")` is required or every line is rewritten; the cache is disabled because the SMB share cannot be written; and php-cs-fixer cannot write in place on that share at all (`is_writable()` false-negative) — format on local disk and copy back.

## Important constraints

- **No dependencies** — pure PHP with no Composer packages. All tool invocations are via `exec()`/`shell_exec()`.
- **Windows-first** — the generated `.ps1` files are PowerShell scripts; `Config` defaults point to Windows paths.
- **Backup files are gitignored** — `*-bak*` patterns exclude the numerous `.php-bak*.php` files. Only the canonical `.php` files are source of truth.
- **`.env.yaml` is local-only** — never committed; each environment has its own tool paths. `.env-example.yaml` is the documented template.
