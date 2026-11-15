# Media Info Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在视频页显示当前分P/音频文件的实际媒体信息（分辨率、帧率、编码、码率、音频参数、容器、大小），数据存库并懒补齐。

**Architecture:** 新增 `MediaProbeService` 调 ffprobe 并把输出规整为固定结构，存入 `video_parts.media_info` / `audio_parts.media_info`（JSON）。下载成功后写入；视频详情接口返回前对缺失项补齐。前端用纯函数把 `media_info` 格式化为一行文字显示在标题下方。

**Tech Stack:** Laravel 11 / PHP 8.3, PHPUnit 11, SQLite（测试）, ffprobe, Vue 3 + TS + Tailwind。

**Spec:** `docs/superpowers/specs/2026-11-15-media-info-design.md`

## Global Constraints

- 所有工作在 worktree `/Users/windix/lab/mybili-media-info`，分支 `feat/media-info`，完成后 PR 到 `main`。
- 本机无 PHP。PHP 测试统一用：
  `docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "apk add --no-cache ffmpeg >/dev/null && composer install -q --ignore-platform-reqs && php vendor/bin/phpunit <args>"`
  （下文记为 `$PHPUNIT <args>`；如 composer:2 缺扩展导致启动失败，改用 `dunglas/frankenphp:1.13.4-php8.3.36-alpine` 并在其中安装 composer。）
- 前端验证：`pnpm install && pnpm build` 成功。
- ffprobe 命令：`ffprobe -v quiet -print_format json -show_streams -show_format <escapeshellarg(path)>`。
- ffprobe 失败/文件缺失时只记日志、返回 null，绝不抛异常打断下载或页面请求。
- 文案同时加入 `resources/js/language.ts` 的中文和英文两处 `video:` 段。

## Review Focus

- 文件被删除但 `media_info` 仍有值 / `video_download_path` 有值但文件不存在：页面不应报错，补齐时返回 null（Task 2 测试 `test_fill_returns_null_when_file_missing`）。
- 只有音轨或只有视频轨的文件（音频稿件、异常合并）：对应段为 null，前端不显示该段（Task 1 `test_parse_audio_only`；Task 4 格式化函数处理 null）。
- `avg_frame_rate` 为 `0/0` 或缺失：fps 为 null，不出现 NaN/除零（Task 1 `test_parse_handles_zero_frame_rate`）。
- 视频流 bit_rate 缺失（mkv 常见）：video.bitrate 为 null，不用 format 级码率冒充（Task 1 `test_parse_mkv_without_stream_bitrate`）。
- `audio_download_path` 可能带 `/storage/` 前缀（`VideoService::removeVideoFilesAndResetState` 已处理此情况）：路径解析需兼容（Task 2 `test_resolve_path_handles_storage_prefix`）。

---

### Task 1: MediaProbeService 解析 ffprobe 输出

**Files:**
- Create: `app/Services/MediaProbeService.php`
- Create: `tests/Unit/MediaProbeServiceParseTest.php`

**Interfaces:**
- Produces:
  - `MediaProbeService::parse(array $ffprobe): array` — 纯函数，输入 ffprobe JSON decode 后的数组，返回：
    ```
    ['container' => ?string /* format.format_name */, 'size' => ?int, 'duration' => ?float, 'bitrate' => ?int,
     'video' => null|['codec'=>string,'profile'=>?string,'width'=>int,'height'=>int,'fps'=>?float,'bitrate'=>?int],
     'audio' => null|['codec'=>string,'profile'=>?string,'bitrate'=>?int,'sample_rate'=>?int,'channels'=>?int,'channel_layout'=>?string],
     'probed_at' => string /* now()->toIso8601String() */]
    ```
    取第一个 `codec_type=video`（跳过 `disposition.attached_pic=1` 的封面流）和第一个 `codec_type=audio`。fps 由 `avg_frame_rate`（`"60000/1001"`）计算并保留 2 位小数，分母为 0 时 null。数值字段为字符串时转 int/float。
  - `MediaProbeService::probe(string $absPath): ?array` — 文件不存在返回 null；exec 上述 ffprobe 命令，非 0 退出或 JSON 无效返回 null 并 `Log::warning`；否则 `parse()`。

- [ ] **Step 1: 写失败测试**（继承 `Tests\TestCase`，fixture 以 PHP 数组内联）
  - `test_parse_mp4_hevc_aac`：video `{codec_name:hevc, profile:Main, width:1920, height:1080, avg_frame_rate:"60000/1001", bit_rate:"2000000"}` + audio `{codec_name:aac, profile:LC, bit_rate:"192000", sample_rate:"48000", channels:2, channel_layout:stereo}`，format `{format_name:"mov,mp4,m4a,3gp,3g2,mj2", size:"548405248", duration:"123.45", bit_rate:"2200000"}` → 断言 `video.fps === 59.94`、`video.bitrate === 2000000`、`audio.sample_rate === 48000`、`size === 548405248`、`container === 'mov,mp4,m4a,3gp,3g2,mj2'`。
  - `test_parse_audio_only`：仅 flac 音频流 → `video === null`，`audio.codec === 'flac'`。
  - `test_parse_skips_attached_pic`：第一个 video 流 `disposition.attached_pic=1`（mjpeg），第二个为 h264 → `video.codec === 'h264'`。
  - `test_parse_handles_zero_frame_rate`：`avg_frame_rate:"0/0"` → `video.fps === null`。
  - `test_parse_mkv_without_stream_bitrate`：video 流无 bit_rate → `video.bitrate === null`，`bitrate`（format 级）仍有值。
  - `test_probe_returns_null_for_missing_file`：`probe('/nonexistent.mp4') === null`。
  - `test_probe_real_file`：若 `exec('which ffprobe')` 为空则 `markTestSkipped`；否则用 `ffmpeg -f lavfi -i testsrc=size=320x240:rate=30 -f lavfi -i sine -t 1 -c:v libx264 -c:a aac <tmp>.mp4` 生成文件 → 断言 `video.width === 320`、`video.height === 240`、`video.fps === 30.0`、`audio.codec === 'aac'`。

- [ ] **Step 2:** `$PHPUNIT tests/Unit/MediaProbeServiceParseTest.php` → FAIL（class not found）
- [ ] **Step 3:** 实现 `app/Services/MediaProbeService.php`（`parse`、`probe`）
- [ ] **Step 4:** `$PHPUNIT tests/Unit/MediaProbeServiceParseTest.php` → 全部 PASS
- [ ] **Step 5:** Commit `feat: add MediaProbeService to parse ffprobe output`

### Task 2: 数据列 + 填充逻辑

**Files:**
- Create: `database/migrations/2026_11_15_000000_add_media_info_to_parts.php`
- Modify: `app/Models/VideoPart.php`, `app/Models/AudioPart.php`
- Modify: `app/Services/MediaProbeService.php`
- Create: `tests/Feature/MediaProbeFillTest.php`
- Modify: `phpunit.xml`（若测试需要：取消注释 `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`）

**Interfaces:**
- Consumes: `MediaProbeService::probe`
- Produces:
  - 列 `video_parts.media_info`、`audio_parts.media_info`：`$table->json('media_info')->nullable()`；模型 `$casts = ['media_info' => 'array']`，并加入 `$fillable`。
  - `MediaProbeService::resolvePath(?string $storedPath): ?string` — null/空返回 null；去掉前导 `/storage/` 或 `/` 后，用 `Storage::disk('public')->path()` 得绝对路径；若传入已是存在的绝对路径则原样返回。
  - `MediaProbeService::fillVideoPart(VideoPart $part, bool $force = false): ?array` 与 `fillAudioPart(AudioPart $part, bool $force = false): ?array` — 未下载或文件不存在时返回 null 且不改库；`media_info` 已有值且 `!$force` 时直接返回现值；否则 probe、保存、返回。

- [ ] **Step 1: 写失败测试**（`RefreshDatabase`，`Storage::fake('public')` 不适用于真实 ffprobe，改用 `storage_path('app/public/videos')` 下的临时文件并在 tearDown 删除；需要真实文件的用例在无 ffprobe 时 skip）
  - `test_media_info_cast_to_array`：创建 VideoPart，`media_info=['container'=>'x']` 保存后 `fresh()->media_info['container'] === 'x'`。
  - `test_fill_returns_null_when_file_missing`：`video_download_path='videos/nope.mp4'` → `fillVideoPart` 返回 null，`media_info` 仍为 null。
  - `test_fill_does_not_reprobe_when_present`：`media_info` 已有值，`fillVideoPart($p)` 返回原值（用 partial mock 断言 `probe` 未被调用）。
  - `test_fill_probes_and_saves`：生成真实 mp4 到 `videos/test.mp4`，path 设为 `videos/test.mp4` → 返回值和 `fresh()->media_info['video']['width']` 均为 320。
  - `test_resolve_path_handles_storage_prefix`：`resolvePath('/storage/videos/a.mp3') === Storage::disk('public')->path('videos/a.mp3')`，`resolvePath('videos/a.mp3')` 同值，`resolvePath(null) === null`。
- [ ] **Step 2:** `$PHPUNIT tests/Feature/MediaProbeFillTest.php` → FAIL
- [ ] **Step 3:** 写 migration（up 两表加列，down 删列）、模型 casts/fillable、`resolvePath`/`fillVideoPart`/`fillAudioPart`
- [ ] **Step 4:** `$PHPUNIT tests/Feature/MediaProbeFillTest.php` → PASS
- [ ] **Step 5:** Commit `feat: store media_info on video/audio parts`

### Task 3: 接入下载、删除、详情接口

**Files:**
- Modify: `app/Services/VideoManager/Actions/Video/DownloadVideoPartFileAction.php`（`updateVideoPartDownloaded`）
- Modify: `app/Services/VideoManager/Actions/Audio/DownloadAudioPartFileAction.php`（`updateAudioPartDownloaded`）
- Modify: `app/Services/VideoManager/VideoService.php`（`removeVideoFilesAndResetState`、`getAllPartsVideoForUser`）
- Create: `tests/Feature/VideoPartsMediaInfoTest.php`

**Interfaces:**
- Consumes: `fillVideoPart`, `fillAudioPart`（Task 2）
- Produces: `getAllPartsVideoForUser()` 返回的每个元素新增键 `'media_info' => ?array`（结构同 Task 1 `parse` 返回值）。前端 Task 4 依赖此键。

改动要点：
- `updateVideoPartDownloaded`：文件存在分支在 `$videoPart->save()` 前，若本次是新下载（hash 变化）则 `fillVideoPart($videoPart, force: true)`，否则 `fillVideoPart($videoPart)`；文件不存在分支设 `media_info = null`。音频同理（音频无 hash，新下载路径调用 force=true：在 `execute` 下载成功分支传入标记，或直接在下载成功后调用 `fillAudioPart($audioPart, true)`）。
- `removeVideoFilesAndResetState`：视频分P与音频都置 `media_info = null`。
- `getAllPartsVideoForUser`：对 `downloaded` 为真的项调用 fill（非 force）后把结果放入 `media_info`；未下载项为 null。通过构造注入或 `app(MediaProbeService::class)` 获取服务（与该文件现有 `app(DownloadVideoService::class)` 风格一致）。

- [ ] **Step 1: 写失败测试**（`RefreshDatabase`）
  - `test_parts_include_media_info_key`：Video + 一个未下载 VideoPart → `getAllPartsVideoForUser` 结果 `[0]` 含键 `media_info` 且为 null。
  - `test_parts_lazy_fill_media_info`：已下载 VideoPart 且有真实文件（无 ffprobe 则 skip）→ `[0]['media_info']['video']['height'] === 240`，且 DB 中已保存。
  - `test_reset_clears_media_info`：VideoPart 有 `media_info`，调用 `deleteVideos([$id], ['permanent' => false])` → `fresh()->media_info === null`。
- [ ] **Step 2:** `$PHPUNIT tests/Feature/VideoPartsMediaInfoTest.php` → FAIL
- [ ] **Step 3:** 实现上述改动
- [ ] **Step 4:** `$PHPUNIT` 全量 → PASS
- [ ] **Step 5:** Commit `feat: record media_info on download and expose in video detail`

### Task 4: 视频页显示

**Files:**
- Create: `resources/js/lib/mediaInfo.ts`
- Modify: `resources/js/api/fav.ts`（`VideoPartType` 增加 `media_info?: MediaInfo | null`，并导出 `MediaInfo` 类型，字段与 Task 1 结构一致）
- Modify: `resources/js/pages/VideoView.vue`（标题 `<h2>` 下方、渐变短线之后）
- Modify: `resources/js/language.ts`（中/英 `video:` 段）

**Interfaces:**
- Consumes: 接口 `video_parts[].media_info`（Task 3）
- Produces: `formatMediaInfo(info: MediaInfo, t: (k: string) => string): string[]` — 返回 0–3 段文字（视频段 / 音频段 / 容器+大小），调用方用 ` | ` 连接或渲染成三个标签。

格式规则（与 spec 示例一致：`1920×1080 · HEVC · 60fps · 2.1 Mbps | AAC · 192 kbps · 48kHz · 立体声 | MP4 · 523 MB`）：
- 视频段：`{w}×{h}` · 编码大写映射（`h264→AVC`、`hevc→HEVC`、`av1→AV1`、`vp9→VP9`，其他原样大写）· fps 四舍五入到整数 + `fps`（null 省略）· 码率（null 省略；≥1e6 用 `x.x Mbps`，否则 `x kbps`）。
- 音频段：编码（`aac→AAC`、`flac→FLAC`、`eac3→E-AC-3`、`opus→Opus`、`mp3→MP3`）· 码率 kbps（null 省略）· 采样率 `48kHz`/`44.1kHz` · 声道（1→`t('video.mediaMono')`，2→`t('video.mediaStereo')`，其他→`{n}ch`）。
- 容器段：`format_name` 含 `mp4`→`MP4`，含 `matroska`→`MKV`（含 webm 且不含 matroska →`WebM`），`mp3`→`MP3`，`flac`→`FLAC`，其他取逗号前第一段大写；大小用 `MB`/`GB`（1024 进制，1 位小数，MB 取整）。
- 某段为 null 则省略该段。
- 文案：中 `mediaInfoLabel: '文件信息'`, `mediaMono: '单声道'`, `mediaStereo: '立体声'`；英 `'File info'`, `'Mono'`, `'Stereo'`。

VideoView：`currentPart?.media_info` 存在时显示一行小号灰色标签（`text-xs text-gray-600`，每段一个 `bg-gray-100 rounded px-2 py-0.5` 标签，flex-wrap 适配手机宽度），前缀 `t('video.mediaInfoLabel')`。

- [ ] **Step 1:** 实现 `mediaInfo.ts`、类型、文案、模板
- [ ] **Step 2:** `pnpm install && pnpm build` → 成功无 TS 错误
- [ ] **Step 3:** 手动检查：本地或容器起应用，打开一个已下载视频页确认显示；切换分P时信息随之变化；音频稿件只显示音频与容器段
- [ ] **Step 4:** Commit `feat: show media info on video page`

### Task 5: 收尾

- [ ] `$PHPUNIT` 全量 PASS、`pnpm build` 成功
- [ ] `git push -u origin feat/media-info`，`gh pr create --base main --title "feat: 视频页显示媒体文件信息"`，正文概述改动 + 测试方式
