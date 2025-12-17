# Encoder-Wrapper-Tools
PHP CLI wrapper for creating PowerShell encoding jobs for use with 3rd party utilities.

The paths to the encoding/muxing tools are hard-coded for the time being.

`Config.php`:

    const MKV_MRG = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Support\mkvtoolnix\mkvmerge.exe';
    
    const VID_ENC = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\NVEnc\NVEncC64.exe';
    
    const AUD_ENC = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\ffmpeg\ffmpeg.exe';
    
    const FFPROBE = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\ffmpeg\ffprobe.exe';
    
These are the only dependencies.  More customisation options to come.
