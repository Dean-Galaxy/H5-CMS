<?php

namespace App\Modules\Content\Console;

use App\Modules\Content\Models\EpisodeRecord;
use App\Modules\Content\Services\MediaStore;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class OptimizePlayback extends Command
{
    protected $signature = 'content:optimize-playback
        {--episode=* : Explicit episode UUIDs for a controlled batch}
        {--limit=1 : Maximum videos to optimize}
        {--audit : Only count published episodes that still need optimization}';

    protected $description = 'Optimize existing private videos without changing their episode bindings or publication';

    public function handle(MediaStore $media): int
    {
        $ids = $this->option('episode');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if (! $limit || array_filter($ids, fn ($id) => ! Str::isUuid($id))) {
            return self::INVALID;
        }

        if ($this->option('audit')) {
            return $this->runBatch($media, $ids, $limit);
        }
        $busy = 75;
        $result = $media->withWorkerLock(fn () => $this->runBatch($media, $ids, $limit), $busy);
        if ($result === $busy) {
            $this->warn('Media processing is busy; retry this batch after the active job.');
        }

        return $result;
    }

    private function runBatch(MediaStore $media, array $ids, int $limit): int
    {
        $query = EpisodeRecord::query()->with('video')->orderBy('number')->orderBy('id');
        if (! $this->option('audit') || $ids) {
            $query->whereNotNull('video_asset_id');
        }
        if ($ids) {
            $query->whereIn('id', $ids);
            if ($query->count() !== count(array_unique($ids))) {
                $this->error('At least one requested episode has no linked video.');

                return self::INVALID;
            }
        }
        if ($this->option('audit')) {
            $query->whereNotNull('published_at')->whereHas('drama', fn ($drama) => $drama->where('state', 'published'));
        }

        $processed = 0;
        $remaining = 0;
        $failed = false;
        foreach ($query->lazy(100) as $episode) {
            $video = $episode->video;
            if ($video && $media->optimized($video)) {
                continue;
            }
            if ($this->option('audit')) {
                $remaining++;
                continue;
            }
            if (! $video || $video->state !== 'ready') {
                continue;
            }
            try {
                $media->optimizeExisting($video);
                $this->info($episode->id.' '.$episode->number.' optimized');
            } catch (\RuntimeException $error) {
                $this->error($episode->id.' '.$episode->number.' '.$error->getMessage());
                $failed = true;
            }
            if (++$processed >= $limit || $failed) {
                break;
            }
        }

        if ($this->option('audit')) {
            $this->info('Published episodes pending optimization: '.$remaining);

            return $remaining ? self::FAILURE : self::SUCCESS;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
