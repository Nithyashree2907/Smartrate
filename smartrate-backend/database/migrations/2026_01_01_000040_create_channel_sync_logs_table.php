<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel_name', 50)->comment('Target OTA');
            $table->string('action', 100)->comment('e.g. INVENTORY_AND_RATE_SYNC');
            $table->json('payload_sent')->comment('Exact JSON body dispatched');
            $table->integer('http_status')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sync_logs');
    }
};
