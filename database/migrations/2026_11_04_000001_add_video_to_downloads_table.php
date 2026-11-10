<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->enum('format', ['mp3', 'flac', 'wav', 'mp4'])->default('mp3')->change();
            // Chosen resolution of a video download, e.g. 1080
            $table->unsignedSmallInteger('video_height')->nullable()->after('quality');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn('video_height');
            $table->enum('format', ['mp3', 'flac', 'wav'])->default('mp3')->change();
        });
    }
};
