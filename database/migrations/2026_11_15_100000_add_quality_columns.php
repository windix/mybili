<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_parts', function (Blueprint $table) {
            $table->integer('download_qn')->nullable()->comment('实际下载的 B 站档位');
            $table->string('download_format_id')->nullable()->comment('yt-dlp 选中的格式 ID');
            $table->string('quality_status')->nullable()->comment('ok / downgraded；null 为未知（老视频）');
            $table->json('missing_qualities')->nullable()->comment('被降级时缺失的档位');
        });
        Schema::table('download_queue', function (Blueprint $table) {
            $table->boolean('redownload')->default(false)->comment('是否为按当前偏好重新下载');
        });
    }

    public function down(): void
    {
        Schema::table('video_parts', function (Blueprint $table) {
            $table->dropColumn(['download_qn', 'download_format_id', 'quality_status', 'missing_qualities']);
        });
        Schema::table('download_queue', function (Blueprint $table) {
            $table->dropColumn('redownload');
        });
    }
};
