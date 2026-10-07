<?php

namespace App\Modules\Content\Http;

use App\Contracts\RegionResolver;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Services\Availability;
use App\Modules\Content\Services\ContentGuard;
use App\Modules\Content\Services\MediaStore;
use App\Modules\Content\Services\PlaybackService;
use App\Support\ApiProblem;
use Illuminate\Http\Request;

final class MediaController
{
    public function __construct(private MediaStore $store, private Availability $availability) {}

    public function image(string $id)
    {
        $region = app(RegionResolver::class)->resolve()->country_code;
        if ($region === 'ZZ') {
            throw new ApiProblem('REGION_BLOCKED', 403, '无法核实地区');
        }
        $visible = $this->availability->query($region);
        $shown = (clone $visible)->where('cover_asset_id', $id)->exists()
            || Banner::where('active', true)->where('image_asset_id', $id)->whereIn('drama_id', (clone $visible)->select('id'))->exists();
        if (! $shown) {
            throw new ApiProblem('NOT_FOUND', 404, '图片不可用');
        }
        $asset = Asset::where('state', 'ready')->whereIn('kind', ['cover', 'banner'])->findOrFail($id);

        return $this->file($asset, 'image.png', 'image/png');
    }

    public function playback(Request $r, string $id, string $file, PlaybackService $playback)
    {
        if (! $r->hasValidSignature()) {
            throw new ApiProblem('NOT_AUTHORIZED', 403, '播放链接已失效或被修改');
        }
        $region = app(RegionResolver::class)->resolve()->country_code;
        $grant = $playback->readGrant($id, $region);
        $ep = $grant->session->episode;
        $url = fn ($part) => $playback->url($grant, $part);
        $response = $this->serve($ep, $file, $url, true);
        // A manifest/HEAD/failed Range does not earn the free-N continuation exception.
        if ($r->isMethod('GET') && ($file === 'media.mp4' || str_starts_with($file, 'segment-'))) {
            $response->prepare($r);
            if (in_array($response->getStatusCode(), [200, 206], true) && (int) $response->headers->get('Content-Length') > 0) {
                $playback->readGrant($id, $region, true);
            }
        }

        return $response;
    }

    public function preview(string $id)
    {
        ContentGuard::staff();
        $ep = EpisodeRecord::with(['drama', 'video', 'subtitle'])->findOrFail($id);
        ContentGuard::staff($ep->drama);

        return response()->view('content.preview', ['episode' => $ep])->header('Cache-Control', 'no-store, private');
    }

    public function previewMedia(Request $r, string $id, string $file)
    {
        ContentGuard::staff();
        $ep = EpisodeRecord::with(['drama', 'video', 'subtitle'])->findOrFail($id);
        ContentGuard::staff($ep->drama);

        return $this->serve($ep, $file, fn ($part) => route('content.preview-media', ['id' => $id, 'file' => $part]));
    }

    private function serve(EpisodeRecord $ep, string $file, callable $url, bool $hls = false)
    {
        if (! $ep->video || $ep->video->state !== 'ready') {
            throw new ApiProblem('MEDIA_NOT_READY', 409, '视频尚未就绪');
        }
        $headers = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
        if ($file === 'master.m3u8') {
            $hasSub = $ep->subtitle_asset_id && $ep->subtitle?->state === 'ready';
            $text = "#EXTM3U\n#EXT-X-VERSION:3\n";
            if ($hasSub) {
                $text .= '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="'.($ep->subtitle_language === 'zh_CN' ? 'Chinese' : 'English').'",LANGUAGE="'.($ep->subtitle_language === 'zh_CN' ? 'zh-Hans' : 'en').'",AUTOSELECT=YES,DEFAULT=YES,URI="'.$url('captions.m3u8')."\"\n";
            }
            $text .= '#EXT-X-STREAM-INF:BANDWIDTH=12000000'.($hasSub ? ',SUBTITLES="subs"' : '')."\n".$url('index.m3u8')."\n";

            return response($text, 200, ['Content-Type' => 'application/vnd.apple.mpegurl', ...$headers]);
        }
        if ($file === 'captions.m3u8') {
            if (! $ep->subtitle || $ep->subtitle->state !== 'ready') {
                throw new ApiProblem('NOT_FOUND', 404, '字幕不可用');
            }
            $duration = (int) $ep->video->metadata['duration_seconds'];
            $text = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:".$duration."\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:".$duration.",\n".$url('subtitles.vtt')."\n#EXT-X-ENDLIST\n";

            return response($text, 200, ['Content-Type' => 'application/vnd.apple.mpegurl', ...$headers]);
        }
        if ($file === 'index.m3u8') {
            $path = $this->store->path($ep->video->output_key.'/index.m3u8');
            if (! is_file($path)) {
                throw new ApiProblem('MEDIA_NOT_READY', 409, '播放清单尚未就绪');
            }
            $lines = explode("\n", file_get_contents($path));
            foreach ($lines as &$line) {
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    if (! preg_match('/^segment-\d{5}\.ts$/D', $line)) {
                        throw new ApiProblem('MEDIA_NOT_READY', 409, '播放清单无效');
                    }
                    $line = $url($line);
                }
            }

            return response(implode("\n", $lines), 200, ['Content-Type' => 'application/vnd.apple.mpegurl', ...$headers]);
        }
        if ($file === 'subtitles.vtt') {
            if (! $ep->subtitle || $ep->subtitle->state !== 'ready') {
                throw new ApiProblem('MEDIA_NOT_READY', 409, '字幕尚未就绪');
            }
            if ($hls) {
                $path = $this->store->path($ep->subtitle->output_key.'/'.$file);
                if (! is_file($path)) {
                    throw new ApiProblem('MEDIA_NOT_READY', 409, '字幕文件不可用');
                }
                $text = file_get_contents($path);
                $map = (int) ($ep->video->metadata['hls_timestamp_base'] ?? 0);
                $text = preg_replace('/^WEBVTT\n/', 'WEBVTT'."\nX-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:".$map."\n", $text, 1);

                return response($text, 200, ['Content-Type' => 'text/vtt; charset=utf-8', ...$headers]);
            }

            return $this->file($ep->subtitle, $file, 'text/vtt; charset=utf-8');
        }
        if ($file !== 'media.mp4' && ! preg_match('/^segment-\d{5}\.ts$/D', $file)) {
            throw new ApiProblem('NOT_FOUND', 404, '媒体对象不存在');
        }

        return $this->file($ep->video, $file, $file === 'media.mp4' ? 'video/mp4' : 'video/mp2t');
    }

    private function file(Asset $asset, string $file, string $type)
    {
        $path = $this->store->path($asset->output_key.'/'.$file);
        if (! is_file($path)) {
            throw new ApiProblem('MEDIA_NOT_READY', 409, '媒体文件不可用');
        }

        return response()->file($path, ['Content-Type' => $type, 'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }
}
