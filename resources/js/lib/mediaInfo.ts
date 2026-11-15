import type { MediaInfo } from '@/api/fav';

const VIDEO_CODECS: Record<string, string> = { h264: 'AVC', hevc: 'HEVC', av1: 'AV1', vp9: 'VP9' };
const AUDIO_CODECS: Record<string, string> = { aac: 'AAC', flac: 'FLAC', eac3: 'E-AC-3', opus: 'Opus', mp3: 'MP3' };

const formatBitrate = (bps: number): string =>
    bps >= 1_000_000 ? `${(bps / 1_000_000).toFixed(1)} Mbps` : `${Math.round(bps / 1000)} kbps`;

const formatSize = (bytes: number): string => {
    const gb = bytes / 1024 ** 3;
    return gb >= 1 ? `${gb.toFixed(1)} GB` : `${Math.round(bytes / 1024 ** 2)} MB`;
};

const formatContainer = (name: string): string => {
    if (name.includes('mp4')) return 'MP4';
    if (name.includes('matroska')) return 'MKV';
    if (name.includes('webm')) return 'WebM';
    return name.split(',')[0].toUpperCase();
};

/**
 * 把后端 media_info 格式化为最多三段文字：视频 / 音频 / 容器+大小
 */
export function formatMediaInfo(info: MediaInfo, t: (key: string) => string): string[] {
    const segments: string[] = [];

    if (info.video) {
        const v = info.video;
        const items = [`${v.width}×${v.height}`, VIDEO_CODECS[v.codec] ?? v.codec.toUpperCase()];
        if (v.fps) items.push(`${Math.round(v.fps)}fps`);
        if (v.bitrate) items.push(formatBitrate(v.bitrate));
        segments.push(items.join(' · '));
    }

    if (info.audio) {
        const a = info.audio;
        const items = [AUDIO_CODECS[a.codec] ?? a.codec.toUpperCase()];
        if (a.bitrate) items.push(formatBitrate(a.bitrate));
        if (a.sample_rate) items.push(`${+(a.sample_rate / 1000).toFixed(1)}kHz`);
        if (a.channels === 1) items.push(t('video.mediaMono'));
        else if (a.channels === 2) items.push(t('video.mediaStereo'));
        else if (a.channels) items.push(`${a.channels}ch`);
        segments.push(items.join(' · '));
    }

    const containerItems: string[] = [];
    if (info.container) containerItems.push(formatContainer(info.container));
    if (info.size) containerItems.push(formatSize(info.size));
    if (containerItems.length) segments.push(containerItems.join(' · '));

    return segments;
}
