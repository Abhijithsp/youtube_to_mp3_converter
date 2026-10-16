<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            // Even out loudness while converting
            $table->boolean('normalize_volume')->default(false)->after('remove_silence');
            // Cut a video with chapters into one file per chapter
            $table->boolean('split_chapters')->default(false)->after('normalize_volume');
            // Position of this track when it came from a split video (1, 2, ...)
            $table->unsignedSmallInteger('chapter_index')->nullable()->after('split_chapters');
            // Lyrics from LRCLIB: plain text, and timed LRC lines for the player
            $table->text('lyrics')->nullable()->after('waveform');
            $table->text('synced_lyrics')->nullable()->after('lyrics');
            // null = not looked up yet, found, none, instrumental
            $table->string('lyrics_status', 20)->nullable()->after('synced_lyrics');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['normalize_volume', 'split_chapters', 'chapter_index', 'lyrics', 'synced_lyrics', 'lyrics_status']);
        });
    }
};
