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
            $table->enum('format', ['mp3', 'flac', 'wav'])->default('mp3')->change();

            // Optional cut, in seconds from the start of the video
            $table->unsignedInteger('trim_start')->nullable()->after('quality');
            $table->unsignedInteger('trim_end')->nullable()->after('trim_start');
            $table->boolean('remove_silence')->default(false)->after('trim_end');

            $table->unsignedSmallInteger('year')->nullable()->after('album');
            $table->string('genre')->nullable()->after('year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['trim_start', 'trim_end', 'remove_silence', 'year', 'genre']);
            $table->enum('format', ['mp3', 'flac'])->default('mp3')->change();
        });
    }
};
