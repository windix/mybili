<?php

namespace Tests\Feature;

use App\Contracts\TelegramBotServiceInterface;
use App\Events\VideoPartDownloaded;
use App\Listeners\SendVideoDownloadedMessage;
use App\Models\DownloadQueue;
use App\Services\VideoManager\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\QualityTestHelpers;
use Tests\TestCase;

class RedownloadApiTest extends TestCase
{
    use RefreshDatabase, QualityTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    public function test_redownload_endpoint_queues_downloaded_parts_only(): void
    {
        $this->makeVideo(401);
        $this->makePart(401, 4011, ['page' => 1, 'video_download_path' => 'videos/401.mp4']);
        $this->makePart(401, 4012, ['page' => 2]);

        $this->postJson('/api/videos/401/redownload')
            ->assertOk()
            ->assertJson(['code' => 0, 'queued' => 1]);

        $this->assertSame(1, DownloadQueue::count());
        $this->assertTrue(DownloadQueue::first()->redownload);
    }

    public function test_redownload_endpoint_supports_extend_ids(): void
    {
        $this->makeVideo(402);
        $this->makeVideo(403);
        $this->makePart(402, 4021, ['video_download_path' => 'videos/402.mp4']);
        $this->makePart(403, 4031, ['video_download_path' => 'videos/403.mp4']);

        $this->postJson('/api/videos/402/redownload', ['extend_ids' => [403]])
            ->assertOk()
            ->assertJson(['queued' => 2]);
    }

    public function test_redownload_endpoint_skips_audio_videos(): void
    {
        $this->makeVideo(404, 12);

        $this->postJson('/api/videos/404/redownload')->assertOk()->assertJson(['queued' => 0]);
    }

    public function test_redownload_endpoint_forbidden_when_read_only(): void
    {
        config(['services.bilibili.setting_read_only' => true]);
        $this->makeVideo(405);

        $this->postJson('/api/videos/405/redownload')->assertForbidden();
    }

    public function test_index_filters_downgraded(): void
    {
        $this->makeVideo(406);
        $this->makeVideo(407);
        $this->makePart(406, 4061, ['quality_status' => 'downgraded']);
        $this->makePart(407, 4071, ['quality_status' => 'ok']);

        $ids = collect($this->getJson('/api/videos?quality=downgraded')->assertOk()->json('list'))->pluck('id')->all();

        $this->assertSame([406], $ids);
    }

    public function test_parts_expose_quality_fields(): void
    {
        $video = $this->makeVideo(408);
        $this->makePart(408, 4081, ['download_qn' => 112, 'quality_status' => 'downgraded', 'missing_qualities' => [120]]);

        $parts = app(VideoService::class)->getAllPartsVideoForUser($video->fresh());

        $this->assertSame(112, $parts[0]['download_qn']);
        $this->assertSame('1080P 高码率', $parts[0]['quality_name']);
        $this->assertSame('downgraded', $parts[0]['quality_status']);
        $this->assertSame(['4K 超高清'], $parts[0]['missing_quality_names']);
    }

    private function captureNotification(array $partAttrs): string
    {
        $this->makeVideo(409);
        $part     = $this->makePart(409, 4091, $partAttrs);
        $captured = null;
        $this->mock(TelegramBotServiceInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('sendHtmlMessage')->andReturnUsing(function ($html) use (&$captured) {
                $captured = $html;
                return true;
            });
        });

        (new SendVideoDownloadedMessage())->handle(new VideoPartDownloaded($part->fresh()));

        return (string) $captured;
    }

    public function test_notification_includes_downgrade_warning(): void
    {
        $html = $this->captureNotification(['quality_status' => 'downgraded', 'missing_qualities' => [120, 116]]);

        $this->assertStringContainsString('Quality downgraded', $html);
        $this->assertStringContainsString('4K 超高清', $html);
    }

    public function test_notification_without_warning_when_ok(): void
    {
        $html = $this->captureNotification(['quality_status' => 'ok']);

        $this->assertStringContainsString('Video Download Completed', $html);
        $this->assertStringNotContainsString('Quality downgraded', $html);
    }
}
