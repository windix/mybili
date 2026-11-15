# Quality Preference + Downgrade Detection + Redownload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新下载按用户设置的画质上限/编码偏好选 SDR 格式，记录实际档位并判定是否被降级；提供未达标筛选与单个/批量“按当前偏好重新下载”，重下未被降级才替换原文件。

**Architecture:** 纯函数类 `VideoFormatSelector`（设置 → yt-dlp `-f`/`-S` 参数）与 `DownloadQualityInspector`（info-json/输出 → 档位、缺失档位、判定）。`DownloadVideoPartFileAction` 通过可替换的 `runYtDlp()` 执行命令，写入 `video_parts` 新列；重下走临时文件并按判定决定替换。下载队列增加 `redownload` 标记。前端在设置页、视频管理页、视频页加入口。

**Tech Stack:** Laravel 11 / PHP 8.3, PHPUnit 11 + SQLite, yt-dlp, Vue 3 + TS + Tailwind。

**Spec:** `docs/superpowers/specs/2026-11-15-quality-preference-design.md`

## Global Constraints

- Worktree `/Users/windix/lab/mybili-quality`，分支 `feat/quality-preference`（基于 `feat/media-info`），完成后 PR 到 `main`（仓库 `windix/mybili`）。
- PHP 测试：`$PHPUNIT <args>` = `/private/tmp/claude-501/-Users-windix-lab-mybili/c5ff0070-b370-4460-b75e-a59bf1e9af18/scratchpad/phpunit.sh` 的同款脚本，但 `cd` 到本 worktree（docker `composer:2` + ffmpeg）。
- 测试不访问 B 站：yt-dlp 调用必须通过 `DownloadVideoPartFileAction::runYtDlp(string $command): array{0: array, 1: int}` 并在测试中替换。
- 已实测（本机 yt-dlp 2026.10.26，未登录）：`-f "bv*[dynamic_range=SDR][quality<=80]+ba/bv*+ba/b" -S "quality,vcodec:h265"` → `30077+30280`（HEVC）；`vcodec:h264` → `30080`（AVC）；`vcodec:av01` → `100026`（AV1）；偏好 h265 但无 HEVC → AVC；偏好 h264 但无 AVC → HEVC。info-json 无 `requested_formats`，须用顶层 `format_id`（`+` 分隔）在 `formats[]` 中查视频流的 `quality`。
- 缺失提示原文：`[BiliBili] Format(s) 4K 超高清, 1080P 60帧 are missing; you have to become a premium member to download them. ...`
- 档位名表（qn → 名称）：`127 8K 超高清, 126 杜比视界, 125 HDR 真彩, 120 4K 超高清, 116 1080P 60帧, 112 1080P 高码率, 80 1080P 高清, 74 720P 60帧, 64 720P 准高清, 32 480P 标清, 16 360P 流畅`。
- 分辨率上限 → qn 上限：`unlimited→无, 2160→120, 1440→116, 1080→116, 720→74`。
- 编码设置 → `-S`：`hevc→vcodec:h265`, `avc→vcodec:h264`, `av1→vcodec:av01`。
- 写操作接口在 `config('services.bilibili.setting_read_only')` 为真时 `abort(403)`。
- 文案中英双语加入 `resources/js/language.ts`。

## Review Focus

- info-json 缺失或损坏：`download_qn`/`download_format_id` 为 null，判定仍按输出进行，不抛异常（Task 3 `test_missing_info_json_still_judges_from_output`）。
- 缺失提示含未知档位名：保守判为 downgraded（Task 2 `test_judge_unknown_missing_name_is_downgraded`）。
- 上次重下中断残留的临时文件：yt-dlp 遇到已存在文件会跳过下载，必须在执行前删除残留（Task 4 `test_redownload_removes_stale_temp_file_before_running`）。
- 重下时原文件已不在磁盘：判定 ok 时直接安装新文件（Task 4 `test_redownload_installs_when_old_file_missing`）。
- 全新安装未保存过画质设置：命令使用默认 `unlimited` + `hevc`（Task 3 `test_command_uses_default_preferences`）。

---

### Task 1: 画质设置 + VideoFormatSelector

**Files:**
- Modify: `app/Enums/SettingKey.php`（`VIDEO_MAX_RESOLUTION = 'video_max_resolution'`, `VIDEO_CODEC_PREFERENCE = 'video_codec_preference'`，label：`画质上限`、`编码偏好`）
- Modify: `app/Http/Controllers/SettingsController.php`（默认值 `unlimited` / `hevc`；校验 `sometimes|string|in:unlimited,2160,1440,1080,720` 与 `sometimes|string|in:hevc,avc,av1`）
- Create: `app/Services/VideoFormatSelector.php`
- Test: `tests/Unit/VideoFormatSelectorTest.php`, `tests/Feature/QualitySettingsTest.php`

**Interfaces:**
- Produces:
  - `VideoFormatSelector::buildFormatArgs(string $maxResolution, string $codec): string` — 返回已 `escapeshellarg` 的参数串：`-f '<selector>' -S 'quality,<vcodec>'`。selector：有上限时 `bv*[dynamic_range=SDR][quality<=Q]+ba/bv*[dynamic_range=SDR]+ba/bv*+ba/b`，无上限时 `bv*[dynamic_range=SDR]+ba/bv*+ba/b`。未知取值按默认处理。
  - `VideoFormatSelector::fromSettings(): string` — 从 `SettingsService` 读两项（缺省 `unlimited`/`hevc`）后调 `buildFormatArgs`。
  - `VideoFormatSelector::QN_CAP`（resolution → qn）常量。

- [ ] **Step 1: 写失败测试**
  - `test_build_unlimited_hevc`：`buildFormatArgs('unlimited','hevc') === "-f 'bv*[dynamic_range=SDR]+ba/bv*+ba/b' -S 'quality,vcodec:h265'"`
  - `test_build_1080_avc`：含 `[quality<=116]` 与 `vcodec:h264`，完整串断言。
  - `test_build_720_av1`：含 `[quality<=74]`、`vcodec:av01`。
  - `test_build_unknown_values_fall_back_to_defaults`：`('foo','bar')` 等于 `('unlimited','hevc')` 结果。
  - `test_from_settings_uses_saved_values`（Feature，RefreshDatabase）：`SettingsService::put` 两项为 `2160`/`av1` → 含 `[quality<=120]` 与 `vcodec:av01`。
  - `test_settings_endpoint_returns_quality_defaults`：`GET /api/settings` JSON 含 `video_max_resolution=unlimited`、`video_codec_preference=hevc`。
  - `test_settings_endpoint_rejects_invalid_resolution`：只验证规则：POST 含 `video_max_resolution=999` 的完整有效设置体 → 422。（完整有效体以 `GET /api/settings` 返回值为基础修改。）
- [ ] **Step 2:** `$PHPUNIT tests/Unit/VideoFormatSelectorTest.php tests/Feature/QualitySettingsTest.php` → FAIL
- [ ] **Step 3:** 实现
- [ ] **Step 4:** 同命令 → PASS
- [ ] **Step 5:** Commit `feat: add video quality preference settings and format selector`

### Task 2: DownloadQualityInspector

**Files:**
- Create: `app/Services/DownloadQualityInspector.php`
- Test: `tests/Unit/DownloadQualityInspectorTest.php`，fixture `tests/Fixtures/ytdlp-info-hevc-1080.json`（已存在：真实 info-json 精简版，`format_id = 30077+30280`）

**Interfaces:**
- Produces:
  - `const QUALITY_NAMES = [127 => '8K 超高清', ...]`（Global Constraints 表）
  - `qualityName(?int $qn): ?string`
  - `parseInfoJson(array $info): array{qn: ?int, format_id: ?string}` — `format_id` 按 `+` 拆分，在 `formats[]` 中找 `vcodec !== 'none'` 的那项取 `quality`。
  - `parseMissingFormats(string $output): array<int|string>` — 匹配 `Format\(s\) (.+?) are missing; you have to become a premium member`，按 `, ` 拆分，名称→qn，未知原样保留字符串；无匹配返回 `[]`。
  - `judge(?int $selectedQn, array $missing, string $maxResolution): string` — 返回 `'downgraded'` 当且仅当 `$missing` 中存在：字符串（未知名称），或 int qn 满足 `qn ∉ {125,126}` 且 `qn <= cap(若有)` 且 (`$selectedQn === null` 或 `qn > $selectedQn`)；否则 `'ok'`。cap 用 `VideoFormatSelector::QN_CAP`。

- [ ] **Step 1: 写失败测试**
  - `test_parse_info_json_fixture`：fixture → `['qn' => 80, 'format_id' => '30077+30280']`
  - `test_parse_info_json_without_formats`：`['format_id' => 'x']` → qn null
  - `test_parse_missing_formats`：原文提示 → `[120, 116]`
  - `test_parse_missing_formats_none`：`'[info] Downloading 1 format(s): 30077+30280'` → `[]`
  - `test_judge_ok_when_nothing_missing`：`judge(80, [], 'unlimited') === 'ok'`
  - `test_judge_downgraded_when_higher_missing`：`judge(32, [120,116,112,80,64], 'unlimited') === 'downgraded'`
  - `test_judge_ok_when_missing_above_cap`：`judge(116, [120], '1080') === 'ok'`
  - `test_judge_ignores_hdr_and_dv`：`judge(120, [126,125], 'unlimited') === 'ok'`
  - `test_judge_unknown_missing_name_is_downgraded`：`judge(120, ['神秘档位'], 'unlimited') === 'downgraded'`
  - `test_quality_name`：`qualityName(112) === '1080P 高码率'`，`qualityName(null) === null`
- [ ] **Step 2:** `$PHPUNIT tests/Unit/DownloadQualityInspectorTest.php` → FAIL
- [ ] **Step 3:** 实现
- [ ] **Step 4:** → PASS
- [ ] **Step 5:** Commit `feat: add download quality inspector`

### Task 3: 新下载记录档位与判定

**Files:**
- Create: `database/migrations/2026_11_15_100000_add_quality_columns.php`（`video_parts`: `download_qn` int nullable, `download_format_id` string nullable, `quality_status` string nullable, `missing_qualities` json nullable；`download_queue`: `redownload` boolean default false）
- Modify: `app/Models/VideoPart.php`（fillable + cast `missing_qualities => array`）, `app/Models/DownloadQueue.php`（fillable + cast `redownload => boolean`）
- Modify: `app/Services/VideoManager/Actions/Video/DownloadVideoPartFileAction.php`
- Test: `tests/Feature/DownloadQualityRecordTest.php`

**Interfaces:**
- Consumes: `VideoFormatSelector::fromSettings`, `DownloadQualityInspector::*`
- Produces:
  - `protected function runYtDlp(string $command): array` — `exec` 封装，返回 `[$outputLines, $exitCode]`；`execDownloadVideo` 改用它（412 检测保留）。
  - `execDownloadVideo(string $url, string $savePath, int $page): array` 命令 = `yt-dlp_linux <formatArgs> --write-info-json --playlist-items <page> [--cookies=<path>] -o <savePath> <url>`。
  - `public function recordQuality(VideoPart $part, string $savePath, array $output): void` — 读 `infoJsonPath($savePath)`（`preg_replace('/\.mp4$/', '.info.json', $savePath)`），解析并写四列（`missing_qualities` 为空数组时存 null），删除 info-json；不 save（由调用方保存）。
- 新下载成功分支：`recordQuality` → `updateVideoPartDownloaded(..., true)`（其中 save）。

- [ ] **Step 1: 写失败测试**（RefreshDatabase + `Event::fake()`；用 `Mockery::mock(DownloadVideoPartFileAction::class)->makePartial()->shouldAllowMockingProtectedMethods()`，并 `app()->instance(...)` 或直接调用；`runYtDlp` 替身：把 `MediaProbeFillTest::makeTestMp4` 生成的文件复制到命令里的 `-o` 路径、按需写 fixture info-json，返回预设输出。`BilibiliService::getVideoFestivalJumpUrl` 需 mock 返回 null。）
  - `test_new_download_records_quality_ok`：输出不含缺失提示，info-json=fixture → `download_qn=80`, `download_format_id='30077+30280'`, `quality_status='ok'`, `missing_qualities=null`，info-json 文件已删除。
  - `test_new_download_records_downgraded`：输出含 `Format(s) 4K 超高清, 1080P 60帧 are missing; you have to become a premium member` → `quality_status='downgraded'`, `missing_qualities=[120,116]`。
  - `test_missing_info_json_still_judges_from_output`：不写 info-json，输出含缺失提示 → `download_qn=null`, `quality_status='downgraded'`。
  - `test_command_uses_default_preferences`：捕获传给 `runYtDlp` 的命令，断言含 `bv*[dynamic_range=SDR]+ba`、`vcodec:h265`、`--write-info-json`。
- [ ] **Step 2:** `$PHPUNIT tests/Feature/DownloadQualityRecordTest.php` → FAIL
- [ ] **Step 3:** 实现 migration、模型、action 改动
- [ ] **Step 4:** `$PHPUNIT` 全量 → PASS
- [ ] **Step 5:** Commit `feat: record selected quality and detect downgraded downloads`

### Task 4: 重新下载流程

**Files:**
- Create: `app/Exceptions/RedownloadNotImprovedException.php`
- Modify: `app/Services/DownloadQueueService.php`（`enqueueVideo(VideoPart $videoPart, int $priority = 0, bool $redownload = false)`；`updateOrCreate` 写入 `redownload`；已有 pending/running 且新请求为重下时把其 `redownload` 置 true 并返回 null）
- Modify: `app/Services/ProcessDownloadQueue` 派发处（`grep -rn "new DownloadVideoJob" app`）把 `$item->redownload` 传给 Job
- Modify: `app/Jobs/DownloadVideoJob.php`（构造 `public VideoPart $videoPart, public bool $redownload = false`；`execute($part, $this->redownload)`；捕获 `RedownloadNotImprovedException` → `markFailedByVideoPart` 并 return）
- Modify: `DownloadVideoPartFileAction::execute(VideoPart $videoPart, bool $redownload = false)`
- Test: `tests/Feature/RedownloadTest.php`

**Interfaces:**
- Consumes: Task 3 `runYtDlp`, `recordQuality`
- Produces: `execute(VideoPart, bool $redownload = false)`；`enqueueVideo(..., bool $redownload = false)`；`RedownloadNotImprovedException`

重下逻辑（`$redownload === true`）：
1. 跳过“已有文件即返回”；`$finalPath = buildVideoPartFilePath($part)`（若 `getVideoPartValidFilePath` 返回旧路径，最终仍安装到该旧路径）；`$tmpPath = preg_replace('/\.mp4$/', '.redownload.mp4', $finalPath)`。
2. 删除残留 `$tmpPath` 及其 `.info.json`，执行下载到 `$tmpPath`。
3. 用一个临时 VideoPart 克隆（`$probe = $part->replicate()`）调用 `recordQuality($probe, $tmpPath, $output)` 得到判定。
4. `ok`：`rename($tmpPath, $finalPath)`，把四列从 `$probe` 拷回 `$part`，`updateVideoPartDownloaded($part, $finalPath, true)`，触发 `VideoPartDownloaded`。
5. `downgraded`：删除 `$tmpPath`，`$part` 不变，抛 `RedownloadNotImprovedException`，消息：`重新下载仍被降级（缺少 {名称以、连接}），可能是 cookie 未生效，已保留原文件`。

- [ ] **Step 1: 写失败测试**
  - `test_redownload_replaces_when_ok`：已有旧文件（`media_info.video.width=320`，旧 `download_qn=32`）；替身下载生成新文件并返回无缺失输出 + fixture info-json → 旧路径文件 hash 已变、`download_qn=80`、`quality_status='ok'`、临时文件不存在。
  - `test_redownload_keeps_old_when_downgraded`：替身输出含缺失提示 → 抛 `RedownloadNotImprovedException`，旧文件 hash 不变，`download_qn` 仍为旧值，临时文件不存在。
  - `test_redownload_installs_when_old_file_missing`：`video_download_path` 指向不存在文件 → ok 时文件出现在 `buildVideoPartFilePath` 路径。
  - `test_redownload_removes_stale_temp_file_before_running`：预先写入 `$tmpPath` 内容 `stale`；替身在被调用时断言 `$tmpPath` 不存在。
  - `test_enqueue_redownload_upgrades_pending_item`：先 `enqueueVideo($p)` 再 `enqueueVideo($p, 0, true)` → 该条 `redownload === true`，仍只有一条记录。
  - `test_job_marks_failed_without_retry_on_not_improved`：partial mock action `execute` 抛异常 → 调用 `(new DownloadVideoJob($p, true))->process()` 后队列项 `status=failed`、`retry_count=0`。
- [ ] **Step 2:** `$PHPUNIT tests/Feature/RedownloadTest.php` → FAIL
- [ ] **Step 3:** 实现
- [ ] **Step 4:** `$PHPUNIT` 全量 → PASS
- [ ] **Step 5:** Commit `feat: redownload video parts with safe replacement`

### Task 5: API、列表筛选、通知

**Files:**
- Modify: `routes/api.php`（`Route::post('/videos/{id}/redownload', [VideoController::class, 'redownload'])`）
- Modify: `app/Http/Controllers/VideoController.php`（`redownload`：校验 `extend_ids` 同 destroy；read-only 403；调用 `VideoService::redownloadVideos(array $ids): int` 返回入队分P数；响应 `{code: 0, queued: N}`；`index` 接受 `quality` 参数并传入）
- Modify: `app/Services/VideoManager/VideoService.php`（`redownloadVideos`：跳过音频稿件，对 `video_download_path` 非空的分P `enqueueVideo($part, 0, true)`；`getVideosByPage` 处理 `quality=downgraded`：`whereHas('parts', quality_status = 'downgraded')`；`getAllPartsVideoForUser` 视频分P增加 `download_qn`, `quality_name`, `quality_status`, `missing_quality_names`(string[])）
- Modify: `app/Listeners/SendVideoDownloadedMessage.php`（downgraded 时追加 `⚠️ <b>Quality downgraded</b>: missing {names} — cookie may not be effective`）
- Test: `tests/Feature/RedownloadApiTest.php`

- [ ] **Step 1: 写失败测试**
  - `test_redownload_endpoint_queues_downloaded_parts_only`：视频有 2 分P，仅 1 个已下载 → `POST /api/videos/{id}/redownload` 返回 `queued=1`，队列有 1 条 `redownload=true`。
  - `test_redownload_endpoint_supports_extend_ids`：两个视频各 1 个已下载分P → `queued=2`。
  - `test_redownload_endpoint_forbidden_when_read_only`：`config(['services.bilibili.setting_read_only' => true])` → 403。
  - `test_index_filters_downgraded`：两个视频，仅一个有 downgraded 分P → `GET /api/videos?quality=downgraded` 的 `list` 只含该视频。
  - `test_parts_expose_quality_fields`：分P `download_qn=112, quality_status='downgraded', missing_qualities=[120]` → `getAllPartsVideoForUser` 返回 `quality_name='1080P 高码率'`、`missing_quality_names=['4K 超高清']`。
  - `test_notification_includes_downgrade_warning`：对 listener 的消息构造（将 `buildNotificationMessage` 改为 `public` 或通过 mock `TelegramBotServiceInterface::sendHtmlMessage` 捕获）→ 含 `Quality downgraded` 与 `4K 超高清`；ok 时不含。
- [ ] **Step 2:** `$PHPUNIT tests/Feature/RedownloadApiTest.php` → FAIL
- [ ] **Step 3:** 实现
- [ ] **Step 4:** `$PHPUNIT` 全量 → PASS
- [ ] **Step 5:** Commit `feat: redownload API, downgraded filter and notification warning`

### Task 6: 前端

**Files:**
- Modify: `resources/js/api/video.ts`（`redownloadVideo(id: number, extend_ids?: number[]): Promise<{code:number, queued?:number}>`；`VideoListParams.quality?: string`）
- Modify: `resources/js/api/fav.ts`（`VideoPartType` 加 `download_qn?`, `quality_name?`, `quality_status?`, `missing_quality_names?`）
- Modify: `resources/js/pages/SettingsView.vue`（下载设置区块内加“画质偏好”：两个 select，随现有保存逻辑提交）
- Modify: `resources/js/pages/VideoManagement.vue`（筛选：画质 全部/未达标 → `quality`；批量栏“重新下载所选”，确认后调用 API 并 toast `已加入 N 个分P`）
- Modify: `resources/js/pages/VideoView.vue`（文件信息行：有 `quality_name` 时首个标签显示档位名；`quality_status==='downgraded'` 时显示红色“未达标”标签，title 为缺失档位；操作区按钮“按当前偏好重新下载”（非音频稿件），确认弹窗说明“下载完成且未被降级才会替换原文件”）
- Modify: `resources/js/language.ts`（中英）

- [ ] **Step 1:** 实现
- [ ] **Step 2:** `pnpm install --frozen-lockfile && pnpm build` → 成功；`npx vue-tsc --noEmit -p tsconfig.app.json` → 仅 3 个既有错误（helper.ts / CookieView.vue）
- [ ] **Step 3:** Commit `feat: quality preference UI, downgraded filter and redownload buttons`

### Task 7: 收尾

- [ ] `$PHPUNIT` 全量 PASS、`pnpm build` 成功
- [ ] push，`gh pr create --repo windix/mybili --base main --head feat/quality-preference`，正文说明依赖 PR #1、改动、测试、未在浏览器验证的部分
