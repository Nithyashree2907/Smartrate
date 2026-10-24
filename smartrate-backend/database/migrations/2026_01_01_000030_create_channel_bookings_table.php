<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('external_booking_id', 64)->unique()->comment('Idempotency key, e.g. AGODA-99281');
            $table->enum('channel_name', ['Agoda', 'Rakuten', 'Booking.com']);
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->date('check_in_date');
            $table->integer('booked_price');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_bookings');
    }
};
