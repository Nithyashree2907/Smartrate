<?php

declare(strict_types=1);

use App\Http\Controllers\BookingWebhookController;
use App\Http\Controllers\GridController;
use App\Http\Controllers\InventoryOverrideController;
use App\Http\Controllers\MockOtaController;
use Illuminate\Support\Facades\Route;

Route::get('/grid', [GridController::class, 'index']);

// Inbound reservation webhooks from external OTAs (idempotent, row-locked).
Route::post('/webhook/ota-booking', [BookingWebhookController::class, 'store']);

// Hotel-staff inline edits from the Angular rate matrix.
Route::patch('/inventory/override', [InventoryOverrideController::class, 'update']);

// Internal dummy endpoints standing in for external OTA servers.
Route::post('/mock-ota/{channel}', [MockOtaController::class, 'update']);
