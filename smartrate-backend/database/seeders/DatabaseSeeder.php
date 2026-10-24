<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DailyInventory;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Room catalog with a deliberately varied starting availability so the
     * color-coded grid shows green / orange / red cells from first load.
     */
    private const ROOM_TYPES = [
        ['code' => 'STD_SGL', 'name' => 'Standard Single', 'base_price' => 10000, 'max_inventory' => 10],
        ['code' => 'DLX_TWN', 'name' => 'Deluxe Twin', 'base_price' => 15000, 'max_inventory' => 8],
        ['code' => 'EXEC_STE', 'name' => 'Executive Suite', 'base_price' => 25000, 'max_inventory' => 4],
    ];

    /**
     * Starting available counts per day offset 0..6 (varies per room type so
     * the seed always demos every pricing tier at least once across 7 days).
     */
    private const START_COUNTS = [
        // STD_SGL: one low-inventory surge day (2 left), rest healthy
        'STD_SGL' => [9, 8, 10, 2, 10, 7, 9],
        // DLX_TWN: starts on a surge cell (1 left)
        'DLX_TWN' => [5, 4, 2, 6, 5, 5, 6],
        // EXEC_STE: small pool with one 1-left day
        'EXEC_STE' => [2, 3, 4, 4, 4, 3, 1],
    ];

    public function run(): void
    {
        DailyInventory::query()->delete();
        RoomType::query()->delete();

        foreach (self::ROOM_TYPES as $definition) {
            $roomType = RoomType::create([
                'code' => $definition['code'],
                'name' => $definition['name'],
                'base_price' => $definition['base_price'],
                'max_inventory' => $definition['max_inventory'],
            ]);

            foreach (range(0, 6) as $dayOffset) {
                $date = now()->startOfDay()->addDays($dayOffset);

                $available = self::START_COUNTS[$definition['code']][$dayOffset];

                DailyInventory::create([
                    'room_type_id' => $roomType->id,
                    'target_date' => $date,
                    'available_count' => $available,
                    'current_price' => $definition['base_price'],
                    'is_surge_active' => false,
                ]);
            }
        }
    }
}
