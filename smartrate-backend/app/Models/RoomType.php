<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomType extends Model
{
    protected $fillable = ['code', 'name', 'base_price', 'max_inventory'];

    public function dailyInventories(): HasMany
    {
        return $this->hasMany(DailyInventory::class);
    }
}
