<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Models\Asset;
use App\Modules\Content\Services\MediaStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProcessMediaCommandTest extends ContentTestCase
{
    private function pendingSubtitle(bool $valid = true): Asset
    {
        $bytes = $valid ? "1\n00:00:00,000 --> 00:00:01,000\nSynthetic test\n" : 'invalid subtitle';
        $media = app(MediaStore::class);
        $asset = $media->start(['kind' => 'subtitle', 'filename' => 'command-test.srt', 'bytes' => strlen($bytes)]);
        $media->append($asset->id, 0, $bytes);

        return $media->finish($asset->id);
    }

    public function test_empty_queue_exits_successfully(): void
    {
        $this->artisan('content:process-media')->doesntExpectOutput()->assertExitCode(Command::SUCCESS);
    }

    public function test_ready_result_exits_successfully_with_existing_output(): void
    {
        $asset = $this->pendingSubtitle();
        $this->artisan('content:process-media')->expectsOutput($asset->id.' ready')->assertExitCode(Command::SUCCESS);
        $this->assertSame('ready', $asset->fresh()->state);
        $this->assertSame(1, $asset->fresh()->attempts);
    }

    public function test_actual_invalid_subtitle_exits_failure_with_safe_error_code(): void
    {
        $asset = $this->pendingSubtitle(false);
        $this->artisan('content:process-media')
            ->expectsOutput($asset->id.' failed INVALID_SUBTITLE')->assertExitCode(Command::FAILURE);
        $this->assertSame('failed', $asset->fresh()->state);
        $this->assertSame('INVALID_SUBTITLE', $asset->fresh()->error_code);
    }

    public function test_failure_stops_before_claiming_the_next_asset(): void
    {
        $failed = $this->pendingSubtitle(false);
        $next = $this->pendingSubtitle();
        $failed->update(['created_at' => now()->subSecond()]);
        $this->artisan('content:process-media', ['--limit' => 2])
            ->expectsOutput($failed->id.' failed INVALID_SUBTITLE')->assertExitCode(Command::FAILURE);
        $this->assertSame('pending', $next->fresh()->state);
        $this->assertSame(0, $next->fresh()->attempts);
        $this->assertNull($next->fresh()->processing_token);
        $this->assertNull($next->fresh()->output_key);
    }

    public function test_processing_result_also_stops_without_claiming_the_next_asset(): void
    {
        $raced = $this->pendingSubtitle();
        $next = $this->pendingSubtitle();
        $raced->update(['created_at' => now()->subSecond()]);
        $this->withRetrievedListener(function (Asset $asset) use ($raced) {
            if ($asset->id === $raced->id) {
                // Model another worker claiming between processNext's lookup and process's locked read.
                $asset->state = 'processing';
                $asset->processing_until = now()->addMinute();
            }
        }, function () use ($raced) {
            $this->artisan('content:process-media', ['--limit' => 2])
                ->expectsOutput($raced->id.' processing')->assertExitCode(Command::FAILURE);
        });
        $this->assertSame('pending', $next->fresh()->state);
        $this->assertSame(0, $next->fresh()->attempts);
    }

    public function test_invalid_limits_remain_invalid_and_do_not_claim(): void
    {
        $asset = $this->pendingSubtitle();
        foreach (['0', '-1', '201', '1.5', 'invalid'] as $limit) {
            $this->artisan('content:process-media', ['--limit' => $limit])
                ->doesntExpectOutput()->assertExitCode(Command::INVALID);
        }
        $this->assertSame('pending', $asset->fresh()->state);
        $this->assertSame(0, $asset->fresh()->attempts);
    }

    public function test_exception_is_left_to_the_framework_and_exits_nonzero(): void
    {
        $asset = $this->pendingSubtitle();
        $next = $this->pendingSubtitle();
        $asset->update(['created_at' => now()->subSecond()]);
        $this->withRetrievedListener(function (Asset $record) use ($asset) {
            if ($record->id === $asset->id) {
                throw new \RuntimeException('SYNTHETIC_PROCESSING_EXCEPTION');
            }
        }, function () {
            $exit = app(Kernel::class)->handle(new ArrayInput([
                'command' => 'content:process-media', '--limit' => 2,
            ]), new BufferedOutput);
            $this->assertSame(Command::FAILURE, $exit);
        });
        $this->assertSame('pending', $next->fresh()->state);
        $this->assertSame(0, $next->fresh()->attempts);
    }

    private function withRetrievedListener(callable $listener, callable $assertions): void
    {
        $original = Asset::getEventDispatcher();
        Asset::setEventDispatcher(clone $original);
        try {
            Asset::retrieved($listener);
            $assertions();
        } finally {
            Asset::setEventDispatcher($original);
        }
    }
}
