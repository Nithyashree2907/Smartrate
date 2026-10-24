<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelSyncLog extends Model
{
    protected $fillable = [
        'channel_name',
        'action',
        'payload_sent',
        'http_status',
        'response_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'payload_sent' => 'array',
        ];
    }
}
