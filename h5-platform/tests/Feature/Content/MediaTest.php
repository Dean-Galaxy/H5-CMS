<?php

namespace Tests\Feature\Content;

use App\Enums\Role;
use App\Modules\Content\Models\Asset;
use App\Modules\Content\Services\MediaStore;
use Symfony\Component\Process\Process;

final class MediaTest extends ContentTestCase
{
    private function upload(string $kind, string $name, string $bytes): Asset
    {
        $m = app(MediaStore::class);
        $a = $m->start(['kind' => $kind, 'filename' => $name, 'bytes' => strlen($bytes)]);
        $m->append($a->id, 0, $bytes);
        $m->finish($a->id);

        return $m->process($a->id);
    }

    public function test_upload_is_private_sequential_owned_and_rejects_traversal_type_size(): void
    {
        $m = app(MediaStore::class);
        $this->postJson('/api/v1/admin/content/uploads', ['kind' => 'video', 'filename' => '../1.mp4', 'bytes' => 9])->assertUnprocessable();
        $this->postJson('/api/v1/admin/content/uploads', ['kind' => 'video', 'filename' => '1.php', 'bytes' => 9])->assertUnprocessable();
        $this->postJson('/api/v1/admin/content/uploads', ['kind' => 'subtitle', 'filename' => '1.srt', 'bytes' => 3000000])->assertUnprocessable();
        $a = $m->start(['kind' => 'video', 'filename' => '1.mp4', 'bytes' => 4]);
        $this->assertSame('uploading', $a->state);
        $m->append($a->id, 0, 'ab');
        $m->append($a->id, 0, 'ab');
        $this->postJson('/api/v1/admin/content/uploads/'.$a->id.'/finish', [])->assertUnprocessable();
        $other = $this->makeStaff(Role::ContentOperator);
        $this->asStaff($other);
        $this->postJson('/api/v1/admin/content/uploads/'.$a->id.'/finish', [])->assertForbidden();
        $this->asStaff($this->owner);
        $m->append($a->id, 2, 'cd');
        $m->finish($a->id);
        $this->assertSame('failed', $m->process($a->id)->state);
        $this->assertSame('UNSUPPORTED_VIDEO', $a->fresh()->error_code);
        $this->assertDatabaseCount('content_publications', 0);
    }

    public function test_cancel_is_owned_and_a_canceled_partial_upload_cannot_finish(): void
    {
        $m = app(MediaStore::class);
        $a = $m->start(['kind' => 'video', 'filename' => '1.mp4', 'bytes' => 4]);
        $m->append($a->id, 0, 'ab');
        $this->asStaff($this->makeStaff(Role::ContentOperator));
        $this->postJson('/api/v1/admin/content/uploads/'.$a->id.'/cancel', [])->assertForbidden();
        $this->asStaff($this->owner);
        $this->postJson('/api/v1/admin/content/uploads/'.$a->id.'/cancel', [])->assertOk()->assertJsonPath('state', 'canceled');
        $this->postJson('/api/v1/admin/content/uploads/'.$a->id.'/finish', [])->assertOk()->assertJsonPath('state', 'canceled');
        $this->assertSame('canceled', $m->process($a->id)->state);
        $this->assertNull($a->fresh()->output_key);
    }

    public function test_subtitles_reorder_without_retiming_and_reject_markup_bad_utf8_or_timestamps(): void
    {
        $bytes = "1\n00:00:02,000 --> 00:00:03,000\nLater\n\n2\n00:00:00,000 --> 00:00:01,000\nEarlier\n";
        $a = $this->upload('subtitle', '1.srt', $bytes);
        $this->assertSame('ready', $a->state);
        $this->assertTrue($a->metadata['source_reordered']);
        $this->assertFalse($a->metadata['timestamps_modified']);
        $vtt = file_get_contents(app(MediaStore::class)->path($a->output_key.'/subtitles.vtt'));
        $this->assertTrue(strpos($vtt, 'Earlier') < strpos($vtt, 'Later'));
        $this->assertSame($bytes, file_get_contents(app(MediaStore::class)->path($a->source_key)));
        foreach (["1\n00:00:01,000 --> 00:00:02,000\n<script>alert(1)</script>\n", "1\n00:00:04,000 --> 00:00:03,000\nbad\n", "\xFF\xFF"] as $bad) {
            $this->assertSame('failed', $this->upload('subtitle', 'bad.srt', $bad)->state);
        }
    }

    public function test_actual_small_h264_aac_is_decoded_and_packaged_privately(): void
    {
        $source = app(MediaStore::class)->path('fixture-source.mp4');
        $p = new Process([config('content.ffmpeg'), '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=64x64:r=10:d=2', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $source]);
        $p->setTimeout(30);
        $p->mustRun();
        $a = $this->upload('video', '1.mp4', file_get_contents($source));
        $this->assertSame('ready', $a->state);
        $this->assertTrue($a->metadata['decode_checked']);
        $this->assertGreaterThan(0, $a->metadata['hls_timestamp_base']);
        $this->assertFileExists(app(MediaStore::class)->path($a->output_key.'/index.m3u8'));
        $this->assertFileExists(app(MediaStore::class)->path($a->output_key.'/segment-00000.ts'));
        $this->assertSame(0, fileperms(app(MediaStore::class)->path($a->output_key.'/media.mp4')) & 0077);
    }

    public function test_real_image_is_reencoded_and_subtitle_out_of_duration_blocks_publish(): void
    {
        $im = imagecreatetruecolor(32, 48);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);
        $a = $this->upload('cover', 'cover.png', $bytes.'injected trailing metadata');
        $this->assertSame('ready', $a->state);
        $clean = file_get_contents(app(MediaStore::class)->path($a->output_key.'/image.png'));
        $this->assertStringNotContainsString('injected', $clean);
        $this->assertSame('failed', $this->upload('cover', 'fake.png', 'not an image')->state);
        $d = $this->fixture(1);
        $sub = $this->readyAsset('subtitle');
        $sub->update(['metadata' => ['last_end' => 99]]);
        $d->episodes()->first()->update(['subtitle_asset_id' => $sub->id, 'subtitle_language' => 'en']);
        $this->publish($d, 1)->assertUnprocessable();
    }

    public function test_draft_preview_and_private_range_require_mfa_and_content_permission(): void
    {
        $d = $this->fixture(1);
        $ep = $d->episodes()->first();
        $this->get('/admin/content/episodes/'.$ep->id.'/preview')->assertOk()->assertSee('员工草稿预览');
        $this->get('/api/v1/admin/content/preview/'.$ep->id.'/media.mp4', ['Range' => 'bytes=10-19'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 10-19/1024');
        $this->asStaff($this->makeStaff(Role::Support));
        $this->get('/api/v1/admin/content/preview/'.$ep->id.'/media.mp4', ['Range' => 'bytes=10-19'])->assertForbidden();
        auth('staff')->logout();
        app('session.store')->save();
        $this->get('/api/v1/admin/content/preview/'.$ep->id.'/media.mp4')->assertUnauthorized();
    }
}
