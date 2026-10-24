<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ChannelBooking;
use App\Models\DailyInventory;
use App\Services\ChannelSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class BookingWebhookController
{
    private const SURGE_THRESHOLD = 3;   // rooms left below which surge fires

    private const SURGE_MULTIPLIER = 1.20; // +20% automated yield rule

    public function __construct(private readonly ChannelSyncService $sync)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_booking_id' => ['required', 'string', 'max:64'],
            'channel_name' => ['required', 'in:Agoda,Rakuten,Booking.com'],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
        ]);

        // Fast pre-check so repeated webhook deliveries cost nothing.
        // (The UNIQUE constraint backs this up under concurrency.)
        $existing = ChannelBooking::query()
            ->where('external_booking_id', $data['external_booking_id'])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'Duplicate webhook delivery ignored (idempotency key matched).',
                'duplicate' => true,
                'booking' => $this->present($existing),
            ], 200);
        }

        try {
            [$booking, $error] = DB::transaction(function () use ($data) {
                // Lock exactly one row — the composite UNIQUE index
                // (room_type_id, target_date) keeps the lock a single cell.
                $inventory = DailyInventory::query()
                    ->forRoomDate($data['room_type_id'], $data['check_in_date'])
                    ->lockForUpdate()
                    ->first();

                if ($inventory === null) {
                    return [null, [422, 'No inventory row exists for this room type and date.']];
                }

                // Competing webhooks serialize here on the row lock, so this
                // read is authoritative at commit time.
                if ($inventory->available_count <= 0) {
                    return [null, [409, 'Out of inventory — double booking prevented by MySQL row lock.']];
                }

                $bookedPrice = $inventory->current_price;

                $inventory->available_count -= 1;

                // Automated Yield Rule: when the room is about to run dry,
                // raise the rate by 20% (once, until the floor resets).
                if ($inventory->available_count < self::SURGE_THRESHOLD
                    && ! $inventory->is_surge_active) {
                    $inventory->current_price = (int) round($inventory->current_price * self::SURGE_MULTIPLIER);
                    $inventory->is_surge_active = true;
                }

                $inventory->save();

                $booking = ChannelBooking::create([
                    'external_booking_id' => $data['external_booking_id'],
                    'channel_name' => $data['channel_name'],
                    'room_type_id' => $data['room_type_id'],
                    'check_in_date' => $data['check_in_date'],
                    'booked_price' => $bookedPrice,
                ]);

                return [$booking, null];
            });
        } catch (QueryException $e) {
            // Two deliveries competing for the SAME idempotency key lose here
            // against the UNIQUE constraint — that is a duplicate, not an error.
            $existing = ChannelBooking::query()
                ->where('external_booking_id', $data['external_booking_id'])
                ->first();

            if ($existing !== null) {
                return response()->json([
                    'message' => 'Duplicate webhook delivery ignored (idempotency key matched).',
                    'duplicate' => true,
                    'booking' => $this->present($existing),
                ], 200);
            }

            return response()->json(['message' => 'Booking failed: '.$e->getMessage()], 500);
        }

        if ($error !== null) {
            [$status, $message] = $error;

            return response()->json(['message' => $message], $status);
        }

        // Broadcast the new state to every mock OTA after commit, logging
        // latency per channel in channel_sync_logs.
        $this->sync->broadcastAll(DailyInventory::query()->forRoomDate(
            $data['room_type_id'],
            $data['check_in_date']
        )->first());

        return response()->json([
            'message' => 'Room booked & synced to all channels.',
            'duplicate' => false,
            'booking' => $this->present($booking),
        ], 201);
    }

    private function present(ChannelBooking $booking): array
    {
        $inventory = DailyInventory::query()
            ->forRoomDate($booking->room_type_id, $booking->check_in_date->toDateString())
            ->first();

        return [
            'external_booking_id' => $booking->external_booking_id,
            'channel_name' => $booking->channel_name,
            'room_type_id' => $booking->room_type_id,
            'check_in_date' => $booking->check_in_date->toDateString(),
            'booked_price' => $booking->booked_price,
            'remaining_available_count' => $inventory?->available_count,
            'current_price' => $inventory?->current_price,
            'is_surge_active' => (bool) $inventory?->is_surge_active,
        ];
    }
}
