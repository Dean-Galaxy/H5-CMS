<?php

return [
    'ffmpeg' => env('CONTENT_FFMPEG', PHP_OS_FAMILY === 'Darwin' ? '/opt/homebrew/bin/ffmpeg' : '/usr/bin/ffmpeg'),
    'ffprobe' => env('CONTENT_FFPROBE', PHP_OS_FAMILY === 'Darwin' ? '/opt/homebrew/bin/ffprobe' : '/usr/bin/ffprobe'),
];
