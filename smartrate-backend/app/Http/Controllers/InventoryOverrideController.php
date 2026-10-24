<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyInventory;
use App\Services\ChannelSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InventoryOverrideController
{
    public function __construct(private readonly ChannelSyncService $sync)
    {
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'target_date' => ['required', 'date_format:Y-m-d'],
            'new_price' => ['required', 'integer', 'min:0'],
            'new_available_count' => ['required', 'integer', 'min:0'],
        ]);

        [$inventory, $error] = DB::transaction(function () use ($data) {
            $row = DailyInventory::query()
                ->forRoomDate($data['room_type_id'], $data['target_date'])
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return [null, [404, 'Inventory row not found for this room type and date.']];
            }

            $row->current_price = $data['new_price'];
            $row->available_count = $data['new_available_count'];

            // A manual override takes the rate back from any automated surge.
            $row->is_surge_active = false;

            $row->save();

            return [$row, null];
        });

        if ($inventory === null) {
            [$status, $message] = $error;

            return response()->json(['message' => $message], $status);
        }

        $this->sync->broadcastAll($inventory);

        return response()->json([
            'message' => 'Override saved & pushed to all channels.',
            'inventory' => [
                'room_type_id' => $inventory->room_type_id,
                'target_date' => $inventory->target_date->toDateString(),
                'available_count' => $inventory->available_count,
                'current_price' => $inventory->current_price,
                'is_surge_active' => $inventory->is_surge_active,
            ],
        ]);
    }
}
