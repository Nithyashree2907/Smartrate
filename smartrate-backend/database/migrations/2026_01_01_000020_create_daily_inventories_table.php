<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->date('target_date');
            $table->integer('available_count');
            $table->integer('current_price')->comment('Active rate in JPY (dynamically adjusted)');
            $table->boolean('is_surge_active')->default(false);
            $table->timestamps();

            // Powers the 7-day calendar lookup; guarantees one row per room/date
            // and gives the row-lock (FOR UPDATE) a single narrow cell target.
            $table->unique(['room_type_id', 'target_date'], 'idx_room_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_inventories');
    }
};
