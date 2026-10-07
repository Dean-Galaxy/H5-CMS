<?php

namespace App\Modules\Content\Console;

use App\Modules\Content\Services\MediaStore;
use Illuminate\Console\Command;

final class ProcessMedia extends Command
{
    protected $signature = 'content:process-media {--limit=1 : Maximum assets for this invocation}';

    protected $description = 'Process private pending Content uploads; does not publish content';

    public function handle(MediaStore $media): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if (! $limit) {
            return self::INVALID;
        }
        for ($i = 0; $i < $limit; $i++) {
            $asset = $media->processNext();
            if (! $asset) {
                break;
            }
            $this->line($asset->id.' '.$asset->state.($asset->error_code ? ' '.$asset->error_code : ''));
        }

        return self::SUCCESS;
    }
}
