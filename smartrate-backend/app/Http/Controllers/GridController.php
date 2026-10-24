<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ChannelSyncLog;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;

final class GridController
{
    public function index(): JsonResponse
    {
        $start = now()->startOfDay();
        $end = $start->copy()->addDays(6);

        $rooms = RoomType::query()
            ->with(['dailyInventories' => fn ($query) => $query
                ->whereBetween('target_date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('target_date'),
            ])
            ->orderBy('id')
            ->get();

        return response()->json([
            'days' => array_map(
                fn ($i) => $start->copy()->addDays($i)->toDateString(),
                range(0, 6)
            ),
            'room_types' => $rooms->map(fn (RoomType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'base_price' => $type->base_price,
                'max_inventory' => $type->max_inventory,
                'inventories' => $type->dailyInventories->mapWithKeys(
                    fn ($inv) => [$inv->target_date->toDateString() => [
                        'available_count' => $inv->available_count,
                        'current_price' => $inv->current_price,
                        'is_surge_active' => $inv->is_surge_active,
                    ]]
                )->all(),
            ])->all(),
            'sync_logs' => ChannelSyncLog::query()
                ->orderByDesc('id')
                ->limit(15)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'channel_name' => $log->channel_name,
                    'action' => $log->action,
                    'payload_sent' => $log->payload_sent,
                    'http_status' => $log->http_status,
                    'response_time_ms' => $log->response_time_ms,
                    'created_at' => $log->created_at->toIso8601String(),
                ])->all(),
        ]);
    }
}
