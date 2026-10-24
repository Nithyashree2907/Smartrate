<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyInventory extends Model
{
    protected $fillable = [
        'room_type_id',
        'target_date',
        'available_count',
        'current_price',
        'is_surge_active',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'available_count' => 'integer',
            'current_price' => 'integer',
            'is_surge_active' => 'boolean',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Narrow lookup on the composite unique index (room_type_id, target_date).
     */
    public function scopeForRoomDate(Builder $query, int $roomTypeId, string $targetDate): Builder
    {
        return $query->where('room_type_id', $roomTypeId)->where('target_date', $targetDate);
    }
}
