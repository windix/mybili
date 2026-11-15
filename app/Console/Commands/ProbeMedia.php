<?php
namespace App\Console\Commands;

use App\Models\AudioPart;
use App\Models\VideoPart;
use App\Services\MediaProbeService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * 批量用 ffprobe 读取已下载视频/音频文件的媒体信息并写入 media_info
 */
class ProbeMedia extends Command
{
    protected $signature = 'app:probe-media {--video-id= : 只处理指定视频} {--force : 已有 media_info 也重新读取}';

    protected $description = '批量读取已下载文件的分辨率/编码等媒体信息（media_info）';

    public function handle(MediaProbeService $mediaProbeService): int
    {
        $force   = (bool) $this->option('force');
        $videoId = $this->option('video-id');

        $videoQuery = $this->scope(VideoPart::query()->whereNotNull('video_download_path'), $videoId, $force);
        $audioQuery = $this->scope(AudioPart::query()->whereNotNull('audio_download_path'), $videoId, $force);

        $total = $videoQuery->count() + $audioQuery->count();
        $this->info("Parts to probe: {$total}");

        $probed = 0;
        $failed = 0;
        $bar    = $this->output->createProgressBar($total);

        $videoQuery->chunkById(100, function ($parts) use ($mediaProbeService, $force, $bar, &$probed, &$failed) {
            foreach ($parts as $part) {
                $mediaProbeService->fillVideoPart($part, $force) ? $probed++ : $failed++;
                $bar->advance();
            }
        });
        $audioQuery->chunkById(100, function ($parts) use ($mediaProbeService, $force, $bar, &$probed, &$failed) {
            foreach ($parts as $part) {
                $mediaProbeService->fillAudioPart($part, $force) ? $probed++ : $failed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("probed: {$probed}, missing/failed: {$failed}");

        return self::SUCCESS;
    }

    private function scope(Builder $query, ?string $videoId, bool $force): Builder
    {
        if ($videoId) {
            $query->where('video_id', $videoId);
        }
        if (! $force) {
            $query->whereNull('media_info');
        }

        return $query;
    }
}
