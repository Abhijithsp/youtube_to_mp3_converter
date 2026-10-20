<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            // Converted files have no YouTube video
            $table->text('youtube_url')->nullable()->change();
            $table->string('youtube_id')->nullable()->change();

            // youtube, upload (sent from the browser) or local (a file on this computer)
            $table->string('source', 10)->default('youtube')->after('user_id');
            // The file that was converted, and its name as the user knows it
            $table->text('source_path')->nullable()->after('source');
            $table->string('source_name')->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_path', 'source_name']);
        });
    }
};
