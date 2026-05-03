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
        Schema::create('qbittorrent_speed_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at');
            $table->unsignedBigInteger('download_speed');
            $table->unsignedBigInteger('upload_speed');
            $table->index('sampled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qbittorrent_speed_samples');
    }
};
