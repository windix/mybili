<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_parts', function (Blueprint $table) {
            $table->json('media_info')->nullable()->comment('ffprobe 读取的媒体文件信息');
        });
        Schema::table('audio_parts', function (Blueprint $table) {
            $table->json('media_info')->nullable()->comment('ffprobe 读取的媒体文件信息');
        });
    }

    public function down(): void
    {
        Schema::table('video_parts', function (Blueprint $table) {
            $table->dropColumn('media_info');
        });
        Schema::table('audio_parts', function (Blueprint $table) {
            $table->dropColumn('media_info');
        });
    }
};
