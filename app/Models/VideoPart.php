<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Storage;

class VideoPart extends Model
{
    protected $table    = 'video_parts';
    protected $fillable = ['video_id', 'cid', 'page', 'from', 'part', 'duration', 'vid', 'weblink', 'width', 'height', 'rotate', 'first_frame', 'created_at', 'video_downloaded_at','danmaku_downloaded_at', 'media_info', 'download_qn', 'download_format_id', 'quality_status', 'missing_qualities'];

    protected $casts = [
        'media_info'        => 'array',
        'missing_qualities' => 'array',
        'download_qn'       => 'integer',
    ];


    protected $appends = [
        'video_download_url',
    ];

    public function video()
    {
        return $this->belongsTo(Video::class, 'video_id', 'id');
    }

    public function danmakus()
    {
        return $this->hasMany(Danmaku::class, 'cid', 'cid');
    }

    public function getVideoDownloadUrlAttribute()
    {
        return $this->video_download_path ? Storage::url($this->video_download_path) : null;
    }
}
