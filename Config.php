<?php

class Config
{
    const MKV_MRG = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Support\mkvtoolnix\mkvmerge.exe';
    const VID_ENC = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\NVEnc\NVEncC64.exe';
    const AUD_ENC = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\ffmpeg\ffmpeg.exe';
    const MKV_MUX = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\ffmpeg\ffmpeg.exe';
    const FFPROBE = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\ffmpeg\ffprobe.exe';

    // DEFAULTS (Can be overridden via CLI)
    const DEFAULT_WRK_PATH = 'R:\temp-stuff\_encodes\\'; // Where we save the encoder work files and final output
    const DEFAULT_JOB_PATH = './output/';                // Where we save the .ps1 batch job files
}
