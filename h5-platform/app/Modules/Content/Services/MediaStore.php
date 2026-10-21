<?php

namespace App\Modules\Content\Services;

use App\Enums\Role;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\DramaRecord;
use App\Modules\Content\Models\EpisodeRecord;
use App\Support\ApiProblem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

final class MediaStore
{
    public function path(string $key): string
    {
        if (! preg_match('~^[a-zA-Z0-9_/.-]+$~D', $key) || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new \LogicException('Invalid internal media key.');
        }
        $root = rtrim(config('h5.media_root'), '/');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new \RuntimeException('MEDIA_ROOT_UNAVAILABLE');
        }
        $root = realpath($root);
        if (! $root || str_starts_with($root.'/', realpath(public_path()).'/')) {
            throw new \LogicException('Media must be outside the public root.');
        }
        $path = $root.'/'.$key;
        // Reject symlinks even when the generated key is valid.
        $part = $root;
        foreach (explode('/', $key) as $name) {
            $part .= '/'.$name;
            if (is_link($part)) {
                throw new \LogicException('Media symlink rejected.');
            }
        }

        return $path;
    }

    public function playable(Asset $asset): bool
    {
        if ($asset->state !== 'ready' || ! $asset->output_key) {
            return false;
        }
        $root = $this->path($asset->output_key);
        foreach (['media.mp4', 'index.m3u8'] as $file) {
            if (! is_file($root.'/'.$file) || filesize($root.'/'.$file) === 0) {
                return false;
            }
        }
        $manifest = file_get_contents($root.'/index.m3u8');
        if (! str_contains($manifest, '#EXT-X-ENDLIST')) {
            return false;
        }
        $count = 0;
        foreach (explode("\n", $manifest) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^segment-\d{5}\.ts$/D', $line)) {
                return false;
            }
            $path = $this->path($asset->output_key.'/'.$line);
            if (! is_file($path) || filesize($path) === 0) {
                return false;
            }
            $count++;
        }

        return $count > 0;
    }

    private function directory(string $key): string
    {
        $path = $this->path($key);
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new \RuntimeException('MEDIA_ROOT_UNAVAILABLE');
        }

        return $path;
    }

    public function start(array $input): Asset
    {
        $staff = ContentGuard::staff();
        $data = ContentGuard::validate($input, ['kind' => 'required|in:video,cover,banner,subtitle', 'filename' => 'required|string|max:200', 'bytes' => 'required|integer|min:1|max:2147483648',
            'context_drama_id' => 'nullable|uuid', 'context_episode_id' => 'nullable|uuid', 'context_role' => 'nullable|in:episode_replacement_video,episode_replacement_subtitle']);
        $language = ContentGuard::validate($input, ['context_language' => 'nullable|in:en,zh_CN']);
        if (preg_match('~[\\\\/\x00-\x1F]~', $data['filename'])) {
            throw ValidationException::withMessages(['filename' => '文件名不得包含路径或控制字符']);
        }
        $extension = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));
        $allowed = match ($data['kind']) {
            'video' => ['mp4'], 'subtitle' => ['srt'], default => ['png', 'jpg', 'jpeg']
        };
        $max = match ($data['kind']) {
            'video' => 2147483648, 'subtitle' => 2097152, default => 20971520
        };
        if (! in_array($extension, $allowed, true) || $data['bytes'] > $max) {
            throw ValidationException::withMessages(['file' => '文件类型或大小不符合要求']);
        }
        if (! empty($data['context_role'])) {
            $drama = DramaRecord::findOrFail($data['context_drama_id'] ?? '');
            ContentGuard::staff($drama);
            $episode = $drama->episodes()->findOrFail($data['context_episode_id'] ?? '');
            $expectedKind = $data['context_role'] === 'episode_replacement_video' ? 'video' : 'subtitle';
            if ($data['kind'] !== $expectedKind) {
                throw ValidationException::withMessages(['kind' => '替换素材类型与分集位置不匹配']);
            }
            if ($expectedKind === 'subtitle' && empty($language['context_language'])) {
                throw ValidationException::withMessages(['context_language' => '字幕候选需登记实际语言']);
            }
        } elseif (! empty($data['context_drama_id']) || ! empty($data['context_episode_id'])) {
            throw ValidationException::withMessages(['context_role' => '替换上传必须同时指定剧目、分集和位置']);
        }

        return ContentGuard::mutate(function () use ($staff, $data, $extension, $language) {
            // Limit abandoned uploads per staff; ready files do not count.
            if (Asset::where('created_by', $staff->id)->where('state', 'uploading')->count() >= 100) {
                throw new ApiProblem('VALIDATION_FAILED', 422, '未完成上传过多，请先完成或取消旧上传');
            }
            $id = (string) Str::uuid7();
            $dir = $this->directory('assets/'.$id);
            $path = $dir.'/source.'.$extension;
            $handle = fopen($path, 'xb');
            if (! $handle) {
                throw new \RuntimeException('MEDIA_WRITE_FAILED');
            }
            fclose($handle);
            chmod($path, 0600);

            $metadata = ['original_filename' => $data['filename']];
            if (! empty($data['context_role'])) {
                $metadata += ['context_drama_id' => $data['context_drama_id'], 'context_episode_id' => $data['context_episode_id'], 'context_role' => $data['context_role']];
                if ($data['context_role'] === 'episode_replacement_subtitle') {
                    $metadata['context_language'] = $language['context_language'];
                }
            }

            return Asset::create(['id' => $id, 'created_by' => $staff->id, 'kind' => $data['kind'], 'extension' => $extension,
                'metadata' => $metadata, 'expected_bytes' => $data['bytes'], 'source_key' => 'assets/'.$id.'/source.'.$extension])->fresh();
        });
    }

    public function append(string $id, int $offset, string $bytes): array
    {
        ContentGuard::staff();
        if ($offset < 0 || strlen($bytes) < 1 || strlen($bytes) > 1048576) {
            throw ValidationException::withMessages(['chunk' => '每片需为 1 字节至 1 MiB']);
        }

        return ContentGuard::mutate(function () use ($id, $offset, $bytes) {
            $asset = Asset::findOrFail($id);
            $this->uploadOwner($asset);
            if ($asset->state !== 'uploading' || $offset + strlen($bytes) > $asset->expected_bytes) {
                throw new ApiProblem('STALE_VERSION', 409, '上传状态或长度不匹配');
            }
            $file = fopen($this->path($asset->source_key), 'c+b');
            if (! $file) {
                throw new \RuntimeException('MEDIA_WRITE_FAILED');
            }
            try {
                flock($file, LOCK_EX);
                $size = fstat($file)['size'];
                // Recover a crash between file write and the database commit; same bytes are idempotent.
                if ($offset < $size) {
                    fseek($file, $offset);
                    $existing = fread($file, strlen($bytes));
                    if ($size !== $offset + strlen($bytes) || ! hash_equals($existing, $bytes)) {
                        throw new ApiProblem('STALE_VERSION', 409, '分片偏移不匹配，请重新读取上传进度');
                    }
                } elseif ($offset === $size) {
                    fseek($file, 0, SEEK_END);
                    if (fwrite($file, $bytes) !== strlen($bytes)) {
                        throw new \RuntimeException('MEDIA_WRITE_FAILED');
                    }
                    fflush($file);
                    $size += strlen($bytes);
                } else {
                    throw new ApiProblem('STALE_VERSION', 409, '分片存在缺口');
                }
                $asset->update(['received_bytes' => $size]);
            } finally {
                flock($file, LOCK_UN);
                fclose($file);
            }

            return ['id' => $asset->id, 'received_bytes' => $size, 'state' => $asset->state];
        });
    }

    public function finish(string $id): Asset
    {
        ContentGuard::staff();

        return ContentGuard::mutate(function () use ($id) {
            $asset = Asset::findOrFail($id);
            $this->uploadOwner($asset);
            if ($asset->state !== 'uploading') {
                return $asset;
            }
            $path = $this->path($asset->source_key);
            clearstatcache(true, $path);
            if (filesize($path) !== $asset->expected_bytes) {
                throw ValidationException::withMessages(['file' => '文件还未上传完整']);
            }
            $asset->update(['state' => 'pending', 'received_bytes' => $asset->expected_bytes, 'sha256' => hash_file('sha256', $path)]);
            ContentGuard::changed('upload.completed', 'content_asset', $asset->id);

            return $asset;
        });
    }

    public function retry(string $id): Asset
    {
        ContentGuard::staff();

        return ContentGuard::mutate(function () use ($id) {
            $asset = Asset::findOrFail($id);
            if ($asset->state !== 'failed') {
                throw new ApiProblem('STALE_VERSION', 409, '仅处理失败的素材可以重试');
            }
            $asset->update(['state' => 'pending', 'attempts' => 0, 'error_code' => null]);

            return $asset;
        });
    }

    public function cancel(string $id): Asset
    {
        ContentGuard::staff();

        return ContentGuard::mutate(function () use ($id) {
            $asset = Asset::findOrFail($id);
            $this->uploadOwner($asset);
            $candidate = ! empty($asset->metadata['context_role']);
            if ($candidate) {
                ContentGuard::staff(DramaRecord::findOrFail($asset->metadata['context_drama_id']));
                if (EpisodeRecord::where('video_asset_id', $id)->orWhere('subtitle_asset_id', $id)->exists()) {
                    throw new ApiProblem('STALE_VERSION', 409, '当前已绑定素材不能作为候选取消');
                }
            }
            if ($asset->state !== 'uploading' && ! ($candidate && in_array($asset->state, ['pending', 'processing', 'failed', 'ready'], true))) {
                throw new ApiProblem('STALE_VERSION', 409, '只能取消未完成上传');
            }
            // Invalidate the worker claim; a late decoding result cannot revive a canceled candidate.
            $asset->update(['state' => 'canceled', 'processing_token' => null, 'processing_until' => null]);
            ContentGuard::changed('upload.canceled', 'content_asset', $asset->id);

            return $asset;
        });
    }

    private function uploadOwner(Asset $asset): void
    {
        $staff = ContentGuard::staff();
        if ($staff->id !== $asset->created_by && $staff->role !== Role::Owner) {
            throw new ApiProblem('NOT_AUTHORIZED', 403, '仅上传人或负责人可以继续此上传');
        }
    }

    public function processNext(): ?Asset
    {
        $id = Asset::where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'processing')->where('processing_until', '<=', now()))->orderBy('created_at')->value('id');

        return $id ? $this->process($id) : null;
    }

    public function process(string $id): Asset
    {
        $asset = ContentGuard::mutate(function () use ($id) {
            $a = Asset::findOrFail($id);
            if ($a->state !== 'pending' && ! ($a->state === 'processing' && $a->processing_until <= now())) {
                return $a;
            }
            if ($a->attempts >= 3) {
                $a->update(['state' => 'failed', 'error_code' => 'PROCESSING_RETRY_EXHAUSTED']);

                return $a;
            }
            $a->update(['state' => 'processing', 'processing_token' => (string) Str::uuid7(), 'processing_until' => now()->addMinutes(20), 'attempts' => $a->attempts + 1]);
            $a->setAttribute('_claimed', true);

            return $a;
        });
        if (! $asset->getAttribute('_claimed')) {
            return $asset;
        }
        unset($asset['_claimed']);
        $outKey = 'assets/'.$asset->id.'/processed-'.$asset->processing_token;
        $out = $this->directory($outKey);
        try {
            $source = $this->path($asset->source_key);
            $meta = match ($asset->kind) {
                'video' => $this->video($source, $out),
                'subtitle' => $this->subtitle($source, $out),
                default => $this->image($source, $out),
            };
            Asset::whereKey($id)->where('processing_token', $asset->processing_token)->update([
                'state' => 'ready', 'output_key' => $outKey, 'metadata' => json_encode([...($asset->metadata ?? []), ...$meta], JSON_THROW_ON_ERROR), 'error_code' => null, 'processing_until' => null,
            ]);
        } catch (\Throwable $e) {
            $allowed = ['UNSUPPORTED_VIDEO', 'VIDEO_DECODE_FAILED', 'INVALID_SUBTITLE', 'INVALID_IMAGE', 'MEDIA_TOOL_UNAVAILABLE', 'MEDIA_PROCESS_FAILED'];
            $code = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'MEDIA_PROCESS_FAILED';
            Asset::whereKey($id)->where('processing_token', $asset->processing_token)->update(['state' => 'failed', 'error_code' => $code, 'processing_until' => null]);
        }

        return $asset->fresh();
    }

    private function command(array $args, int $timeout = 900, string $error = 'MEDIA_PROCESS_FAILED'): string
    {
        if (! is_executable($args[0])) {
            throw new \RuntimeException('MEDIA_TOOL_UNAVAILABLE');
        }
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException($error);
        }

        return $process->getOutput();
    }

    private function video(string $source, string $out): array
    {
        $probe = config('content.ffprobe', '/opt/homebrew/bin/ffprobe');
        $ffmpeg = config('content.ffmpeg', '/opt/homebrew/bin/ffmpeg');
        $info = json_decode($this->command([$probe, '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_format', '-show_streams', '-of', 'json', $source], 120, 'UNSUPPORTED_VIDEO'), true, flags: JSON_THROW_ON_ERROR);
        $videos = array_values(array_filter($info['streams'] ?? [], fn ($s) => $s['codec_type'] === 'video'));
        $audios = array_values(array_filter($info['streams'] ?? [], fn ($s) => $s['codec_type'] === 'audio'));
        $duration = (float) ($info['format']['duration'] ?? 0);
        if (count($videos) !== 1 || $videos[0]['codec_name'] !== 'h264' || count($audios) > 1 || ($audios && $audios[0]['codec_name'] !== 'aac') || $duration <= 0 || $duration > 7200 || $videos[0]['width'] * $videos[0]['height'] > 16777216) {
            throw new \RuntimeException('UNSUPPORTED_VIDEO');
        }
        $this->command([$ffmpeg, '-nostdin', '-v', 'error', '-xerror', '-threads', '2', '-protocol_whitelist', 'file,pipe', '-i', $source, '-map', '0:v:0', '-map', '0:a:0?', '-f', 'null', '-'], 900, 'VIDEO_DECODE_FAILED');
        $base = [$ffmpeg, '-nostdin', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-i', $source, '-map', '0:v:0', '-map', '0:a:0?', '-map_metadata', '-1', '-c', 'copy'];
        $this->command([...$base, '-movflags', '+faststart', $out.'/media.mp4']);
        $this->command([...$base, '-hls_time', '4', '-hls_playlist_type', 'vod', '-hls_segment_filename', $out.'/segment-%05d.ts', $out.'/index.m3u8']);
        $times = [];
        foreach (['media.mp4', 'segment-00000.ts'] as $name) {
            $timing = json_decode($this->command([$probe, '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=start_time', '-of', 'json', $out.'/'.$name], 120), true, flags: JSON_THROW_ON_ERROR);
            $times[] = (float) ($timing['streams'][0]['start_time'] ?? throw new \RuntimeException('MEDIA_PROCESS_FAILED'));
        }
        // RFC 8216 3.5: map the original VTT timeline to the remuxed MPEG-TS timeline.
        $timestampBase = ((int) round(($times[1] - $times[0]) * 90000) + 8589934592) % 8589934592;
        foreach (glob($out.'/*') as $p) {
            chmod($p, 0600);
        }

        return ['duration' => $duration, 'duration_seconds' => (int) ceil($duration), 'width' => $videos[0]['width'], 'height' => $videos[0]['height'], 'video_codec' => 'h264', 'audio_codec' => $audios ? 'aac' : null, 'decode_checked' => true, 'hls_timestamp_base' => $timestampBase];
    }

    private function image(string $source, string $out): array
    {
        $info = @getimagesize($source);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] * $info[1] > 20000000 || min($info[0], $info[1]) < 16) {
            throw new \RuntimeException('INVALID_IMAGE');
        }
        if (! function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('MEDIA_TOOL_UNAVAILABLE');
        }
        $image = @imagecreatefromstring(file_get_contents($source));
        if (! $image) {
            throw new \RuntimeException('INVALID_IMAGE');
        }
        try {
            if (! imagepng($image, $out.'/image.png')) {
                throw new \RuntimeException('INVALID_IMAGE');
            }
        } finally {
            imagedestroy($image);
        }
        chmod($out.'/image.png', 0600);

        return ['width' => $info[0], 'height' => $info[1], 'mime' => 'image/png'];
    }

    private function subtitle(string $source, string $out): array
    {
        $text = file_get_contents($source);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new \RuntimeException('INVALID_SUBTITLE');
        }
        $text = trim(str_replace(["\xEF\xBB\xBF", "\r\n", "\r"], ['', "\n", "\n"], $text));
        $vtt = "WEBVTT\n\n";
        $lastStart = -1;
        $lastEnd = 0;
        $count = 0;
        $cues = [];
        $reordered = false;
        foreach (preg_split('/\n\s*\n/', $text) as $block) {
            $lines = explode("\n", $block);
            if (count($lines) < 3 || ! ctype_digit($lines[0]) || ! preg_match('/^(\d{2}):([0-5]\d):([0-5]\d),(\d{3}) --> (\d{2}):([0-5]\d):([0-5]\d),(\d{3})$/D', $lines[1], $m)) {
                throw new \RuntimeException('INVALID_SUBTITLE');
            }
            $start = $m[1] * 3600 + $m[2] * 60 + $m[3] + $m[4] / 1000;
            $end = $m[5] * 3600 + $m[6] * 60 + $m[7] + $m[8] / 1000;
            $body = implode("\n", array_slice($lines, 2));
            if ($end <= $start || $end > 7200 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/', $body)) {
                throw new \RuntimeException('INVALID_SUBTITLE');
            }
            $reordered = $reordered || $start < $lastStart;
            $count++;
            $lastStart = $start;
            $lastEnd = max($lastEnd, $end);
            $cues[] = ['start' => $start, 'order' => $count, 'time' => str_replace(',', '.', $lines[1]), 'body' => $body];
        }
        if (! $count) {
            throw new \RuntimeException('INVALID_SUBTITLE');
        }
        usort($cues, fn ($a, $b) => [$a['start'], $a['order']] <=> [$b['start'], $b['order']]);
        foreach ($cues as $i => $cue) {
            $vtt .= ($i + 1)."\n".$cue['time']."\n".$cue['body']."\n\n";
        }
        file_put_contents($out.'/subtitles.vtt', $vtt, LOCK_EX);
        chmod($out.'/subtitles.vtt', 0600);

        return ['cue_count' => $count, 'last_end' => $lastEnd, 'language_verified' => false, 'source_reordered' => $reordered, 'timestamps_modified' => false];
    }
}
