# SmartRate Sync — Hotel Channel Controller & Automated Yield Engine

Implementation of `setup.md`: a mini **hotel channel manager ("site controller")** with an
Angular single-screen dashboard on top of a Laravel concurrency engine. Two things happen
automatically when inventory runs low:

1. **Double-booking prevention** — MySQL `SELECT ... FOR UPDATE` row locks serialize booking
   webhooks from different OTAs; losing requests get `409 Conflict`.
2. **Automated yield** — when a date drops below 3 remaining rooms, the rate is raised +20%
   instantly and every connected "OTA" receives the update.

## Repository layout

| Path | What it is |
| --- | --- |
| `docker-compose.yml` | MySQL 8 container (`smartrate-mysql`, host port **3307**) |
| `smartrate-backend/` | Laravel 11+ REST API (PHP 8.2, MySQL, row locking, sync dispatcher) |
| `smartrate-frontend/` | Angular 20 SPA single-screen dashboard + Bootstrap 5 |
| `setup.md` | Original product/engineering spec |

## Backend highlights

- **4 tables only** — `room_types`, `daily_inventories` (composite UNIQUE `idx_room_date`),
  `channel_bookings` (idempotency key `external_booking_id`), `channel_sync_logs`.
- `POST /api/webhook/ota-booking` — `DB::transaction()` + `lockForUpdate()`, 409 vs surge rule.
- `PATCH /api/inventory/override` — staff inline edit, row-locked, clears the surge flag.
- `GET /api/grid` — 7-day matrix + last 15 sync logs, keyed by `(room_type_id, target_date)` index.
- `POST /api/mock-ota/{channel}` — fake Agoda/Rakuten/Booking.com endpoints with 25–60 ms jitter.
- After every commit, `ChannelSyncService` pushes to all three mock OTAs and logs latency.

## Running locally

```bash
# 1. Database (requires Docker Desktop)
docker compose up -d

# 2. Backend seed + two dev servers
#    (8000 = main API; 8009 = mock OTA servers — separate because the PHP built-in
#     web server is single-threaded on Windows and would deadlock a plain loopback call)
cd smartrate-backend
php artisan migrate:fresh --seed --force
php artisan serve --port=8000            # terminal 1
php artisan serve --port=8009            # terminal 2

# 3. Frontend
cd ../smartrate-frontend
npm install
npm start                                # http://localhost:4200
```

`.env` (auto-configured already) points at `DB_HOST=127.0.0.1`, `DB_PORT=3307`,
`DB_DATABASE=smartrate`, `DB_USERNAME=smartrate`, `DB_PASSWORD=smartrate`,
`APP_URL=http://127.0.0.1:8000`, `OTA_RECEIVER_BASE_URL=http://127.0.0.1:8009`.

## Using the demo screen

- **⚡ Fire Booking** simulates one OTA webhook against the selected room/date.
- **🔥 Fire 2x Concurrent** fires two booking webhooks with `Promise.all`. With exactly
  1 room left, the audit log shows `200 OK — Room Booked & Synced` and
  `409 Conflict — Prevented Double-Booking via MySQL Row Lock`.
- Click any price in the matrix to inline-edit it; the override is pushed to every OTA.

## Verified checks

- `EXPLAIN SELECT * FROM daily_inventories WHERE room_type_id = 3 AND target_date = '...'`
  returns `type: const`, `key: idx_room_date`, `rows: 1`.
- Concurrency race, idempotent webhook retries (`200 duplicate`), +20% surge rule,
  price override, and per-OTA dispatch logging all verified end-to-end via HTTP.
