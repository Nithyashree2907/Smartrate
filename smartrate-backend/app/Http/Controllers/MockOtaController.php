<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MockOtaController
{
    public function update(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'room_type_code' => ['required', 'string'],
            'target_date' => ['required', 'date'],
            'available_count' => ['required', 'integer'],
            'current_price' => ['required', 'integer'],
            'currency' => ['required', 'string'],
        ]);

        if (! in_array($channel, ['agoda', 'rakuten', 'booking'], true)) {
            return response()->json(['message' => "Unknown OTA channel '{$channel}'."], 404);
        }

        // Simulated external network latency: 25-60ms.
        usleep(random_int(25_000, 60_000));

        return response()->json([
            'status' => 'accepted',
            'channel' => $channel,
            'acknowledged_at' => now()->toIso8601String(),
            'room_type_code' => $data['room_type_code'],
            'target_date' => $data['target_date'],
        ], 200);
    }
}
