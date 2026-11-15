<?php

namespace Tests\Support;

use App\Services\VideoManager\Actions\Video\DownloadVideoPartFileAction;

/**
 * 替换 yt-dlp 调用：把源文件复制到命令中的 -o 路径，并按需写入 info-json
 */
class FakeYtDlpDownloadAction extends DownloadVideoPartFileAction
{
    public array $commands = [];
    public array $fakeOutput = [];
    public int $exitCode = 0;
    public ?string $sourceFile = null;
    public ?string $infoJsonFixture = null;
    /** @var callable|null 在“下载”前调用，参数为输出路径 */
    public $beforeRun = null;

    protected function runYtDlp(string $command): array
    {
        $this->commands[] = $command;
        preg_match("/-o '([^']+)'/", $command, $m);
        $outPath = $m[1];

        if ($this->beforeRun) {
            ($this->beforeRun)($outPath);
        }
        if ($this->exitCode === 0 && $this->sourceFile) {
            copy($this->sourceFile, $outPath);
            if ($this->infoJsonFixture) {
                copy($this->infoJsonFixture, preg_replace('/\.mp4$/', '.info.json', $outPath));
            }
        }

        return [$this->fakeOutput, $this->exitCode];
    }
}
