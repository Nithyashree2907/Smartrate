<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChannelSyncLog;
use App\Models\DailyInventory;
use App\Models\RoomType;
use Illuminate\Support\Facades\Http;

final class ChannelSyncService
{
    /**
     * Target OTA endpoints simulating external travel-platform servers.
     */
    private const CHANNELS = ['agoda', 'rakuten', 'booking'];

    private const ACTION = 'INVENTORY_AND_RATE_SYNC';

    /**
     * Push a single room/date snapshot to every connected OTA.
     * Called AFTER the booking/override transaction has committed, so we never
     * broadcast state that could still roll back.
     */
    public function broadcastAll(DailyInventory $inventory): void
    {
        foreach (self::CHANNELS as $channel) {
            $this->dispatch($channel, $inventory);
        }
    }

    public function dispatch(string $channel, DailyInventory $inventory): void
    {
        $inventory->refresh();

        $payload = [
            'action' => self::ACTION,
            'room_type_code' => (string) RoomType::query()->whereKey($inventory->room_type_id)->value('code'),
            'target_date' => $inventory->target_date->toDateString(),
            'available_count' => $inventory->available_count,
            'current_price' => $inventory->current_price,
            'currency' => 'JPY',
        ];

        $startedAt = hrtime(true);

        try {
            $response = Http::timeout(5)
                ->post($this->endpoint($channel), $payload);

            $httpStatus = $response->status();
        } catch (\Throwable) {
            $httpStatus = 500;
        }

        $elapsedMs = (int) ceil((hrtime(true) - $startedAt) / 1_000_000);

        ChannelSyncLog::create([
            'channel_name' => $channel,
            'action' => self::ACTION,
            'payload_sent' => $payload,
            'http_status' => $httpStatus,
            'response_time_ms' => $elapsedMs,
        ]);
    }

    private function endpoint(string $channel): string
    {
        // Locally the mock OTA servers are standalone `artisan serve`
        // processes (see config/services.php) so a loopback call from an
        // in-flight request never deadlocks the PHP built-in web server.
        return rtrim(config('services.ota.base_url', config('app.url')), '/')
            .'/api/mock-ota/'.$channel;
    }
}
