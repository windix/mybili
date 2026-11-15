# 视频页显示媒体文件信息（分辨率/编码/音频格式）

## 意图

在视频页面直接看到当前已下载文件的实际规格（分辨率、帧率、视频编码与码率、音频编码/码率/采样率/声道、容器格式、文件大小）。
这些数据同时是后续“画质偏好可配置 + 非最佳画质重新下载”功能的基础。

## 目标 / 非目标

- 目标：视频页标题下方显示当前分P（或音频稿件）的媒体信息，切换分P时跟随变化。
- 目标：老文件无需手动跑批量命令，打开视频页时自动补齐。
- 非目标：画质偏好设置、判断是否最佳画质、重新下载（下一阶段）。
- 非目标：记录 B 站侧的 format_id / qn 档位名（下一阶段视需要加）。

## 方案对比

- **A. 存库 + 懒补齐（已选）**：`video_parts` / `audio_parts` 新增 `media_info` JSON 列；下载成功后 ffprobe 写入；视频页请求时缺失则当场 probe 并保存。可查询、只 probe 一次。
- B. 每次请求实时 ffprobe：不改表，但每次都跑、多P开销大，且后续无法按画质筛选。
- C. 下载时记录 yt-dlp 选中格式：老文件拿不到，需 ffprobe 兜底；留到重新下载功能时再考虑。

## 设计

- Migration：`video_parts.media_info` 与 `audio_parts.media_info`，nullable JSON（text）。模型 cast 为 array。
- `App\Services\MediaProbeService`
  - `probe(string $absPath): ?array`：执行 `ffprobe -v quiet -print_format json -show_streams -show_format`（参数 escapeshellarg），解析为：
    ```
    {
      "container": "mov,mp4,m4a,3gp,3g2,mj2", "size": 123, "duration": 12.3, "bitrate": 2100000,
      "video": {"codec": "hevc", "profile": "Main", "width": 1920, "height": 1080, "fps": 59.94, "bitrate": 2000000} | null,
      "audio": {"codec": "aac", "profile": "LC", "bitrate": 192000, "sample_rate": 48000, "channels": 2, "channel_layout": "stereo"} | null,
      "probed_at": "ISO8601"
    }
    ```
  - 失败（文件不存在、ffprobe 不可用、输出无法解析）返回 null 并记日志，不抛异常。
  - `fillVideoPart(VideoPart)` / `fillAudioPart(AudioPart)`：有下载路径且文件存在时 probe 并保存。
- 写入时机：
  - `DownloadVideoPartFileAction::updateVideoPartDownloaded` 中文件存在时 probe（文件已存在 / 新下载两条路径都会经过）；文件不存在时清空 `media_info`。
  - `DownloadAudioPartFileAction::updateAudioPartDownloaded` 同理。
  - `VideoService::removeVideoFilesAndResetState` 清空 `media_info`。
  - `VideoController::show`：对已下载但 `media_info` 为空的分P/音频补齐后返回。
- 前端 `VideoView.vue`：标题下方一行标签，按当前分P显示；音频稿件显示音频段。格式化：`1920×1080 · HEVC · 60fps · 2.1 Mbps | AAC · 192 kbps · 48kHz · 立体声 | MP4 · 523 MB`。缺数据时不显示。中英文文案加入 `language.ts`。

## 留给实现者

- 容器名的友好显示映射（mov,mp4... → MP4，matroska,webm → MKV/WebM）。
- 帧率从 `avg_frame_rate` 解析（如 `60000/1001`）；码率缺失时用 format 级 bit_rate 兜底或不显示。
