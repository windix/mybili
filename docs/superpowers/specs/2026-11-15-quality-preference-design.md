# 画质偏好 + 降级检测 + 手动重新下载（PR 1/2）

## 意图

用户有 B 站大会员，但部分视频被“静默降级”下载：例如 BV1vAMX68Egb 源为 4K，实际下到 852×480 AV1 190kbps。
原因：cookie 文件里有 `SESSDATA`，yt-dlp 认为已登录、跳过 `try_look`，但下载那一刻 B 站不认该会话，只返回低档格式；yt-dlp 退出码仍为 0，mybili 视为成功。
同时 yt-dlp 默认同档优先 AV1，而用户主要在 iPhone / Apple TV 观看，AV1 在 Apple TV 和较老 iPhone 上无硬解。

本 PR 让用户：
1. 设置画质上限与编码偏好，新下载按偏好选择格式；
2. 新下载完成后自动判断是否被降级，标记出来并通知；
3. 在列表里筛出“未达标”的视频，手动（单个或批量）按当前偏好重新下载；老视频同样可以手动重新下载。

PR 2（不在本文范围）：杜比视界（无则 HDR 真彩）额外版本。本 PR 的主文件只下 SDR。

## 目标 / 非目标 / 反目标

- 目标：新下载按设置的分辨率上限和编码优先级选格式，主文件只取 SDR。
- 目标：每次新下载记录实际选中的 B 站档位（qn）与格式 ID，并判定 `ok` / `downgraded`。
- 目标：`downgraded` 的视频可在视频管理页筛出；视频页和视频管理页都能手动重新下载（单个 / 批量）。
- 目标：重新下载不先删旧文件；新文件下载成功且画质不低于旧文件时才替换。
- 非目标：自动重新下载（用户明确选择手动）。
- 非目标：对老视频自动判定或批量扫描达标状态（用户明确：老视频只需手动更新的入口）。老视频状态为“未知”。
- 非目标：杜比视界 / HDR（PR 2）。
- 非目标：音频稿件（au）的画质偏好；音频稿件维持 `bestaudio/best`。
- 反目标：重新下载失败或仍被降级时丢失原文件。
- 反目标：为判定达标额外调用 B 站接口（增加 412 风控风险）。

## 约束

- 继续由 yt-dlp 负责签名、接口与下载（方案 A），只调整其格式选择参数并读取其输出。
- 生产环境 Docker：yt-dlp 二进制为 `yt-dlp_linux`（latest release），ffmpeg 6.0.1 static。
- 测试不访问 B 站：命令拼装、yt-dlp 输出解析、判定逻辑都需能用固定样本单测。
- 基于 PR #1（`feat/media-info`）的分支，依赖 `media_info` 与 `MediaProbeService`。

## 方案对比（已选 A）

- **A. 保留 yt-dlp 选格式，我们生成 `-f`/`-S` 并读取 info-json 与输出（已选）**：改动最小，B 站接口变化仍由 yt-dlp 跟进；判定依据来自 yt-dlp 对 B 站 `support_formats` 的比对，无需额外 API 调用。
- B. 自己调用 playurl 选格式后把 `format_id` 交给 yt-dlp：逻辑可控但每分P多一次 API 调用，412 风险更高，且需与 yt-dlp 双份维护。
- C. 自己实现 DASH 下载：工作量与维护成本过高。

## 设计

### 1. 设置

`SettingKey` 新增（默认值写入 `SettingsController` 默认表，校验规则同步）：

| key | 取值 | 默认 |
|---|---|---|
| `video_max_resolution` | `unlimited` / `2160` / `1440` / `1080` / `720` | `unlimited` |
| `video_codec_preference` | `hevc` / `avc` / `av1` | `hevc` |

编码优先级语义：所选编码最优先，其次按 `hevc > avc > av1` 的剩余顺序（选 `avc` → `avc > hevc > av1`；选 `av1` → `av1 > hevc > avc`）。
设置页（`SettingsView.vue`）新增“画质偏好”区块，中英文案。

### 2. yt-dlp 命令

新增 `App\Services\VideoFormatSelector`（纯函数，便于单测）：

- `buildFormatArgs(string $maxResolution, string $codec): array` 返回 yt-dlp 参数数组，语义：
  - 只选 SDR 视频：`bv*[dynamic_range=SDR]`（yt-dlp 对未标注的视频格式默认 `SDR`，B 站 HDR/DV 分别标 `HDR10`/`DV`），无 SDR 时回退到任意 `bv*`。
  - 分辨率上限按 B 站档位（qn）而非画面高度过滤，避免竖屏视频（如 1080×1920）被误判：`2160→[quality<=120]`、`1440→[quality<=116]`（B 站无独立 2K 档，按 1080P 最高档）、`1080→[quality<=116]`、`720→[quality<=74]`；`unlimited` 不加。无满足上限的格式时回退到不限。（实现者的决定，见“我替用户做的决定”）
  - 排序：先按 B 站档位 `quality`（qn 越高越好），同档位按编码优先级，再按 yt-dlp 默认。编码在 yt-dlp 中的写法为 `vcodec:h265` / `vcodec:h264` / `vcodec:av01`；**实现者需用 `yt-dlp -F` + `-S` 对一个真实 B 站视频验证排序结果确为“同档位下首选编码 → 其余按 hevc>avc>av1”，必要时改为在 `-f` 中按编码串联多个选择器**。
  - 音频：`ba`（维持 yt-dlp 默认音频排序）。
- `DownloadVideoPartFileAction::execDownloadVideo` 改为使用上述参数，并加 `--write-info-json`。命令仍用 `escapeshellarg` 拼接。
- `download-video.sh` 已无调用方，保持不动（不在本 PR 清理）。

### 3. 记录与判定

`video_parts` 新增列：

| 列 | 类型 | 含义 |
|---|---|---|
| `download_qn` | int nullable | 实际选中视频流的 B 站档位（info-json `requested_formats[]` 中视频流的 `quality`） |
| `download_format_id` | string nullable | info-json 顶层 `format_id`，如 `30077+30280` |
| `quality_status` | string nullable | `ok` / `downgraded`；`null` = 老视频或未知 |
| `missing_qualities` | json nullable | 被降级时缺失的档位（qn 数组，未知名称原样保留字符串） |

新增 `App\Services\DownloadQualityInspector`（纯函数）：

- `parseInfoJson(array $info): array{qn: ?int, format_id: ?string}`
- `parseMissingFormats(string $ytDlpOutput): array` 解析 `Format(s) A, B are missing; you have to become a premium member`，把名称映射为 qn：`8K 超高清→127, 杜比视界→126, HDR 真彩→125, 4K 超高清→120, 1080P 60帧→116, 1080P 高码率→112, 1080P 高清→80, 720P 60帧→74, 720P 准高清→64, 480P 标清→32, 360P 流畅→16`；未识别的名称保留原字符串。
- `judge(?int $selectedQn, array $missing, string $maxResolution): string` 返回 `downgraded` 当且仅当缺失列表中存在一个“在偏好范围内”且 qn 高于 `$selectedQn` 的档位；`126`/`125`（HDR/DV）不计入；分辨率上限按 qn→高度映射过滤（127→4320, 120→2160, 116/112/80→1080, 74/64→720, 32→480, 16→360）；未识别名称的条目视为在范围内（保守标记）。否则 `ok`。

`DownloadVideoPartFileAction`：新下载成功后读取 info-json（`savePath` 去掉 `.mp4` 后加 `.info.json`）与命令输出，写入上述四列后删除 info-json。info-json 缺失或无法解析时 `download_qn`/`download_format_id` 为 null，判定仍基于输出。

### 4. 通知

`SendVideoDownloadedMessage`：`quality_status = downgraded` 时在消息中追加一行警告（如“⚠️ 画质未达标：缺少 4K 超高清、1080P 60帧，可能是 cookie 未生效”）。

### 5. 列表与手动重新下载

- 视频列表接口 `GET /api/videos` 新增筛选 `quality=downgraded`（存在任一分P `quality_status=downgraded`）。视频管理页筛选栏加“画质：全部 / 未达标”。
- 新接口 `POST /api/videos/{id}/redownload`，body `extend_ids` 同删除接口，`setting_read_only` 时 403。对每个视频的已下载分P入队重新下载（音频稿件不支持，跳过）。
- 视频管理页：批量操作栏加“重新下载所选”；视频页操作区加“按当前偏好重新下载”按钮（多P时重下全部已下载分P）。
- 视频页文件信息行旁显示状态：`downgraded` 时显示“未达标”标记；有 `download_qn` 时显示档位名（如“1080P 高码率”）。

### 6. 重新下载流程

重新下载的含义：**让这个文件符合当前偏好**（用户决定，方案 A）。

- `download_queue` 新增 `redownload` boolean 列（默认 false）。`DownloadQueueService::enqueueVideo` 增加参数 `bool $redownload = false`；同一分P已有 pending/running 任务时：若新请求是重下而现有不是，则把现有任务的 `redownload` 置为 true。
- `DownloadVideoJob` 把标记传给 `DownloadVideoPartFileAction::execute(VideoPart $part, bool $redownload = false)`。
- 重下时跳过“文件已存在即返回”的分支，下载到临时路径 `<savePath 去掉 .mp4>.redownload.mp4`（info-json 同理用临时前缀），成功后按新下载判定：
  - **判定为 `ok` → 替换**旧文件（rename 覆盖），不论新文件分辨率比旧文件高或低（覆盖“改了上限/编码后重下”的场景）；更新 hash、`media_info`（force probe）、四个画质列；触发 `VideoPartDownloaded`。
  - **判定为 `downgraded` → 不替换**：删除临时文件，保留旧文件及其四列不变；任务直接失败（不重试），`error_msg` 为“重新下载仍被降级（缺少 {档位名列表}），可能是 cookie 未生效，已保留原文件”。
- 实现者确认 `VideoPartDownloaded` 的监听器对重复触发是幂等的（弹幕、封面、通知）。
- 老视频（`quality_status = null`）同样适用：重下后获得判定结果。

## 已定的“如何做”（用户决定）

- 方案 A；分两个 PR，本 PR 不含杜比视界。
- 只对新视频做判定；老视频只提供手动重下入口。
- 未达标：列出 + 手动单个/批量重下，不自动重下。
- 默认编码偏好 HEVC（用户设备 iPhone / Apple TV）。
- 重下替换规则：新下载未被降级即替换（不比较新旧分辨率）；被降级则保留原文件。

## 留给实现者

- yt-dlp `-f`/`-S` 的具体写法（须按第 2 节验证要求实测确认）。
- 设置页、筛选栏、按钮的具体 UI 布局，遵循现有页面风格，手机宽度可用。
- 重下临时文件的命名冲突与清理（任务失败、进程中断后的残留）。


## 我替用户做的决定（builder check 后）

- 分辨率上限按档位（qn）过滤，而不是 `height`：竖屏视频的实际高度大于档位标称值，按高度过滤会被误降级且不被检测到。
- `download_qn` / `download_format_id` / `quality_status` / `missing_qualities` 始终描述磁盘上的那个文件；重下被拒时只在下载队列的 `error_msg` 中说明原因，不改这四列。
- 重下因画质原因被拒时直接 `markFailedByVideoPart`（不进入 `MAX_RETRIES` 重试）；只有 yt-dlp 本身失败才走原有重试。实现上用独立异常类型区分。
