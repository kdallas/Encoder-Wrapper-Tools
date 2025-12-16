#!/usr/bin/php
<?php

/*
USAGE EXAMPLES:
	./run.php --path="S:\Videos\Kids TV\My Little TV Show\Season 7" --prefix=MLTV7
*/

require './ScanDir.php';

class Job {
	const VID_ENC = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\NVEnc\NVEncC64.exe'; // v7.54
	const AUD_ENC = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Encoders\ffmpeg\ffmpeg.exe'; // v7.0.1
	const MKV_MRG = 'E:\Apps\StaxRip-x64-2.0.6.0\Apps\Support\mkvtoolnix\mkvmerge.exe'; // v85.0.7
}

// https://www.php.net/manual/en/function.getopt.php
$longopts = [
	/*
	"required:",     // Required value
	"optional::",    // Optional value
	"option",        // No value
	*/
	"path:",
	"prefix:",
];

$options = getopt(null, $longopts);

foreach ($longopts as $opt) {
	$optChk = trim($opt, ':');
	if (empty($options[$optChk])) {
		echo "Missing --$optChk value\n\n";
		return;
	}
}

//$winPath = 'S:\Videos\Kids TV\My Little TV Show\Season 7';
$winPath = $options['path'];
$drv = strtolower(substr($winPath, 0, 1));
$lnxPath  = '/mnt/' . $drv . '/' . str_replace('\\', '/', substr($winPath, 3));

$lnxOutPath = './';
$outPath = 'R:\temp-stuff\_encodes\\';

$srcExts = ['mkv','mp4'];
$extPattern = '/'.implode('|',$srcExts).'/';
$srcFiles = ScanDir::scan($lnxPath, $srcExts);

// filePrefix: - was typically named so that FileBot can match it
//             - now used to distinguish one lot of batch job files from another
//$filePrefix = 'MLTV7';
$filePrefix = $options['prefix'];
$videoBat = $lnxOutPath.$filePrefix.'_vid.ps1';
$audioBat = $lnxOutPath.$filePrefix.'_aud.ps1';
$mergeBat = $lnxOutPath.$filePrefix.'_mux.ps1';

$winSrcFiles = $outAudFiles = $outVidFiles = [];

foreach($srcFiles as $file) {
	$winSrcFiles[] = str_replace($lnxPath, $winPath.'\\', $lnxPath.basename($file)); // replace Linux path with Windows path
	$outVidFiles[] = $outPath . basename(preg_replace($extPattern, 'h265', $file));
	// $outAudFiles[] = $outPath . basename(preg_replace($extPattern, 'aac', $file));
	$outAudFiles[] = $outPath . basename(preg_replace($extPattern, 'opus', $file));
	$preMrgFiles[] = $outPath . str_replace('.mkv', '_.mkv', basename(preg_replace($extPattern, 'mkv', $file)));
	$finishFiles[] = $outPath . basename(preg_replace($extPattern, 'mkv', $file));
}


/** level 4.1 bitrates, 10bit -- https://en.wikipedia.org/wiki/Advanced_Video_Coding#Levels **/
$bitrate = '1200';
//$vidEncOptions = '--vbrhq '.$bitrate.' --codec h265 --preset quality --level 4.1 --output-depth 10 --aq --lookahead 32 --avhw --vpp-edgelevel --vpp-deband';
//$vidEncOptions = '--cqp 22 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --vpp-edgelevel strength=9.0,threshold=24.0';
//$vidEncOptions = '--cqp 24 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel strength=9.0,threshold=24.0';
//$vidEncOptions = '--cqp 28 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel strength=9.0,threshold=24.0 --vpp-resize super --output-res 1280x720';
//$vidEncOptions = '--cqp 28 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw';
//$vidEncOptions = '--cqp 25 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband';
//$vidEncOptions = '--vbrhq 4000 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband';

// -- additional HDR params between "--level 4.1" & "--output-depth 10" with 4K bitrate higher so level goes to 5.0
// -- colour data provided by ffprobe
//$vidEncOptions = '--cqp 24 --codec h265 --preset quality --level 5.0 --colormatrix bt2020nc --transfer smpte2084 --colorprim bt2020 --master-display "G(13250,34500)B(7500,3000)R(34000,16000)WP(15635,16450)L(10000000,1)" --max-cll "0,0" --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw';

//$vidEncOptions = '--vbrhq 1200 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel';
//$vidEncOptions = '--vbrhq 1500 --codec h265 --preset quality --level 4.1 --transfer bt709 --colorprim bt709 --colormatrix bt709 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel';
//$vidEncOptions = '--vbrhq '.$bitrate.' --codec h265 --preset quality --level 4.1 --transfer bt709 --colorprim bt709 --colormatrix bt709 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel';
//$vidEncOptions = '--vbrhq '.$bitrate.' --codec h265 --preset quality --level 4.1                                                        --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel';
//$vidEncOptions = '--vbrhq 600 --codec h265 --preset quality --level 3.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband --vpp-resize algo=nvvfx-superres --output-res 1280x720';
//$vidEncOptions = '--vbrhq 1000 --codec h265 --preset quality --level 3.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband';
//$vidEncOptions = '--vbrhq 1200 --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband --vpp-resize super --output-res 1280x720';
//$vidEncOptions = '--vbrhq '.$bitrate.' --codec h265 --preset quality --level 4.1 --transfer bt709 --colorprim bt709 --colormatrix bt709 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband';
//$vidEncOptions = '--vbrhq '.$bitrate.' --codec h265 --preset quality --level 4.1 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband';
//$vidEncOptions = '--cqp 29 --codec h265 --preset quality --level 4.1 --transfer bt709 --colorprim bt709 --colormatrix bt709 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel --vpp-deband';
$vidEncOptions = '--vbrhq '.$bitrate.' --codec h265 --preset quality --level 4.1 --transfer bt709 --colorprim bt709 --colormatrix bt709 --output-depth 10 --aq-temporal --mv-precision Q-pel --lookahead 32 --avhw --vpp-edgelevel';


//$audEncOptions = '-c:a copy'; // 5.1 ch src (AC3)
//$audEncOptions = '-c:a copy'; // 2.0 ch src (AAC) -- don't forget to set the AAC extension in $outAudFiles!

//$audEncOptions = '-c:a libopus -b:a 100k'; // 2.0 ch src, converted to Opus
//$audEncOptions = '-c:a libopus -b:a 80k'; // 2.0 ch src, Lo-Fi converted to Opus
//$audEncOptions = '-c:a libopus -b:a 100k -af "volume=1.65,pan=stereo|FL=0.5*FC+0.707*FL+0.707*BL+0.5*LFE|FR=0.5*FC+0.707*FR+0.707*BR+0.5*LFE"'; // 5.1 ch src, converted to 2.0 ch Opus
//$audEncOptions = '-c:a libopus -b:a 250k -af "channelmap=channel_layout=5.1"'; // 5.1 ch src, converted to Opus
$audEncOptions = '-c:a libopus -b:a 224k -af "channelmap=channel_layout=5.1"'; // 5.1 ch src, converted to Opus
//$audEncOptions = '-c:a libopus -b:a 160k'; // 2.0 ch src, converted to Opus
//$audEncOptions = '-c:a libopus -b:a 192k'; // 2.0 ch src, converted to Opus


$mergeOptions = '-c copy -map 0:v:0 -map 1:a:0';

$maxLength = 100;

for ($i=0; $i < count($winSrcFiles); $i++) {
	$videoJob = Job::VID_ENC . ' '     . $vidEncOptions   . ' -i "'  . $winSrcFiles[$i] . '" -o "'                    . $outVidFiles[$i] . '"'."\n";
	$audioJob = Job::AUD_ENC . ' -i "' . $winSrcFiles[$i] . '" '     . $audEncOptions                          . ' "' . $outAudFiles[$i] . '"'."\n";
	$preMxJob = Job::MKV_MRG . ' -o "' . $preMrgFiles[$i] . '" "'    . $outVidFiles[$i]                                                  . '"'."\n";
	$mergeJob = Job::AUD_ENC . ' -i "' . $preMrgFiles[$i] . '" -i "' . $outAudFiles[$i] . '" ' . $mergeOptions . ' "' . $finishFiles[$i] . '"'."\n";

    // set jobs path for cli output
	$paddedSrcPath = substr(str_pad($winSrcFiles[$i], $maxLength), 0, $maxLength);
	$paddedMrgPath = substr(str_pad($preMrgFiles[$i], $maxLength), 0, $maxLength);

	// create jobs batch files (new file on first line, then append)
	echo "Saving: $paddedSrcPath ... To: $videoBat\n";
	file_put_contents($videoBat, $videoJob, ($i===0 ? 0 : FILE_APPEND));

	echo "Saving: $paddedSrcPath ... To: $audioBat\n";
	file_put_contents($audioBat, $audioJob, ($i===0 ? 0 : FILE_APPEND));

	echo "Saving: $paddedMrgPath ... To: $mergeBat\n\n";
	file_put_contents($mergeBat, $preMxJob, ($i===0 ? 0 : FILE_APPEND));
	file_put_contents($mergeBat, $mergeJob, FILE_APPEND);
}
