# SmartRate Sync — Single-Screen Hotel Channel Controller & Automated Yield Engine

> **Target Role:** Temairazu, Inc. — Web Application Engineer  
> **Domain:** B2B Hospitality SaaS / Hotel Site Controller (Channel Manager) & OTA Integration  
> **Core Value Proposition:** A minimal, high-concurrency hotel inventory & dynamic pricing middleware that prevents double-bookings across Online Travel Agencies (Agoda, Booking.com, Rakuten Travel) and automatically adjusts room rates on a single screen.

---

## 1. Executive Summary & The 20-Second Interview Pitch

### Why This Project Exists
Unlike generic "Hotel Management Apps" (which focus on guest check-ins, room service, or 15+ CRUD screens), **SmartRate Sync** is a **Hotel Channel Manager ("Site Controller")**—the exact B2B product category Temairazu leads. It sits between a hotel's inventory and external travel platforms.

### The 20-Second Elevator Pitch
*"When a hotel only has 2 rooms left on a Saturday, two things must happen within milliseconds: the room price needs to increase automatically to maximize revenue, and every connected travel site like Agoda and Booking.com must receive the updated room count immediately so nobody double-books. My project automates both workflows on a single screen using Laravel row-level database locking and an Angular real-time control grid."*

---

## 2. Temairazu Tech Stack Alignment

| Category | Temairazu Requirement | How It Is Implemented in SmartRate Sync |
| :--- | :--- | :--- |
| **Backend Language** | PHP | PHP 8.2+ with strict typing and OOP architecture |
| **Backend Framework** | Laravel / Proprietary MVC | Laravel 11 REST API + Database Transactions & Queue/Sync workers |
| **Frontend Stack** | TypeScript, JavaScript, Angular | Angular Single-Page Application (SPA) with reactive state |
| **Web Fundamentals** | HTML, CSS, Bootstrap | Responsive single-screen matrix grid styled with Bootstrap 5 |
| **Database & Queries** | SQL, MySQL (or PostgreSQL) | MySQL 8.0 using `SELECT ... FOR UPDATE` row locking & composite indexes |
| **System Integration** | Hotel Booking & Metasearch APIs | Inbound OTA booking webhooks + outbound REST rate/inventory dispatchers |
| **Optional Python Bridge**| *(Candidate Preference)* | Optional 50-line Python (FastAPI) microservice for surge multiplier calculation |
| **Cloud & Infra** | AWS | Dockerized locally; deployable on AWS EC2 + AWS RDS (Free Tier) |
| **Engineering Practices** | Git, Cursor, Agile/Scrum, Code Review | Feature-branch PR workflow, SQL `EXPLAIN` performance optimization, idempotency |

---

## 3. System Architecture & Data Flow

```text
 ┌─────────────────────────────────────────────────────────────────────────┐
 │                   SINGLE-SCREEN CONTROL DASHBOARD                       │
 │                 (Angular + TypeScript + Bootstrap)                      │
 │  [7-Day Color-Coded Rate Grid]   [Simulate OTA Webhook]   [Sync Logs]   │
 └───────────────────┬─────────────────────────────────────────▲───────────┘
                     │ REST API (JSON)                         │
                     ▼                                         │
 ┌─────────────────────────────────────────────────────────────┴───────────┐
 │                     CORE SITE CONTROLLER BACKEND                        │
 │                             (PHP 8.2 + Laravel)                         │
 │                                                                         │
 │  1. Concurrency-Safe Booking Receiver (DB::transaction + lockForUpdate) │
 │  2. Automated Yield Rule Engine (<3 rooms left = +20% Surge Price)      │
 │  3. Outbound Channel Sync Dispatcher (Pushes updates to Mock OTAs)      │
 └───────┬─────────────────────────────────┬───────────────────────┬───────┘
         │ SQL (PDO)                       │ Optional REST Call    │ Webhook
         ▼                                 ▼                       ▼
 ┌───────────────────┐         ┌──────────────────────┐  ┌─────────────────┐
 │   MySQL Database  │         │ Python FastAPI Micro │  │ Mock OTA APIs   │
 │   (4 Core Tables) │         │ (Optional Yield/     │  │ - /mock-agoda   │
 │   Indexed & Locked│         │  Competitor Engine)  │  │ - /mock-rakuten │
 └───────────────────┘         └──────────────────────┘  └─────────────────┘
```

### Key Engineering Highlights to Mention in Code Reviews
1. **Pessimistic Row-Level Locking (`lockForUpdate`):** Prevents race conditions when two travel sites try to book the last available room at the exact same millisecond.
2. **Webhook Idempotency (`external_booking_id`):** Guarantees that if an OTA retries a webhook due to network lag, the reservation is never duplicated or double-decremented.
3. **Composite Indexing (`idx_room_date`):** Optimizes the 7-day calendar lookup on `(room_type_id, target_date)` so queries execute in `< 5ms`.

---

## 4. Database Schema (Strictly 4 MySQL Tables)

### Table 1: `room_types`
Stores the hotel's room categories and baseline pricing.
* `id` (BIGINT, Primary Key, Auto Increment)
* `code` (VARCHAR(20), Unique) — e.g., `STD_SGL`, `DLX_TWN`, `EXEC_STE`
* `name` (VARCHAR(100)) — e.g., `Standard Single`, `Deluxe Twin`, `Executive Suite`
* `base_price` (INT) — Base price in JPY (e.g., `10000`)
* `max_inventory` (INT) — Total physical rooms of this type (e.g., `10`)
* `created_at` / `updated_at` (TIMESTAMPS)

### Table 2: `daily_inventories`
The core operational table powering the 7-day availability and pricing matrix.
* `id` (BIGINT, Primary Key, Auto Increment)
* `room_type_id` (BIGINT, Foreign Key -> `room_types.id`)
* `target_date` (DATE)
* `available_count` (INT) — Remaining rooms for this date
* `current_price` (INT) — Active rate in JPY (dynamically adjusted)
* `is_surge_active` (BOOLEAN, Default: `false`) — Flags if the +20% yield rule is triggered
* `created_at` / `updated_at` (TIMESTAMPS)
* **Index:** `UNIQUE INDEX idx_room_date (room_type_id, target_date)`

### Table 3: `channel_bookings`
Stores reservations arriving via OTA webhooks.
* `id` (BIGINT, Primary Key, Auto Increment)
* `external_booking_id` (VARCHAR(64), Unique) — Idempotency key (e.g., `AGODA-99281`)
* `channel_name` (ENUM: `'Agoda'`, `'Rakuten'`, `'Booking.com'`)
* `room_type_id` (BIGINT, Foreign Key -> `room_types.id`)
* `check_in_date` (DATE)
* `booked_price` (INT)
* `created_at` / `updated_at` (TIMESTAMPS)

### Table 4: `channel_sync_logs`
Tracks outbound API calls synchronizing inventory and prices to external OTAs.
* `id` (BIGINT, Primary Key, Auto Increment)
* `channel_name` (VARCHAR(50)) — Target OTA (`Agoda`, `Rakuten`, `Booking.com`)
* `action` (VARCHAR(100)) — e.g., `INVENTORY_AND_RATE_SYNC`
* `payload_sent` (JSON) — Exact JSON body dispatched
* `http_status` (INT) — e.g., `200`, `409`, `500`
* `response_time_ms` (INT) — Execution latency in milliseconds
* `created_at` / `updated_at` (TIMESTAMPS)

---

## 5. Backend API Specification (PHP + Laravel)

All routes reside in `routes/api.php`. No authentication boilerplate is required so reviewers can test immediately.

### 1. `GET /api/grid`
* **Purpose:** Fetches the 7-day room matrix and the 15 most recent sync logs.
* **Query Optimization:** Uses eager loading (`RoomType::with('dailyInventories')`) filtered over a 7-day window.

### 2. `POST /api/webhook/ota-booking`
* **Purpose:** Simulates an incoming reservation webhook from Agoda, Booking.com, or Rakuten Travel.
* **Request Payload:**
  ```json
  {
    "external_booking_id": "AG-2026-8841",
    "channel_name": "Agoda",
    "room_type_id": 2,
    "check_in_date": "2026-10-25"
  }
  ```
* **Core Transaction Workflow:**
  1. Check if `external_booking_id` already exists in `channel_bookings` (Idempotency check).
  2. Open `DB::transaction()`.
  3. Lock the target inventory row:
     ```php
     $inventory = DailyInventory::where('room_type_id', $request->room_type_id)
         ->where('target_date', $request->check_in_date)
         ->lockForUpdate()
         ->firstOrFail();
     ```
  4. If `$inventory->available_count <= 0`, rollback and return `HTTP 409 Conflict (Out of Inventory — Double Booking Prevented)`.
  5. Decrement `available_count` by 1.
  6. **Automated Yield Rule:**
     ```php
     if ($inventory->available_count < 3 && !$inventory->is_surge_active) {
         $inventory->current_price = (int) round($inventory->current_price * 1.20);
         $inventory->is_surge_active = true;
     }
     ```
  7. Save inventory, create the `ChannelBooking` record, and commit the transaction.
  8. Dispatch outbound sync calls to the mock OTA endpoints and record entries in `channel_sync_logs`.

### 3. `PATCH /api/inventory/override`
* **Purpose:** Allows hotel staff to manually edit a room price or inventory count inline on the Angular grid and broadcast the update to all OTAs.
* **Request Payload:**
  ```json
  {
    "room_type_id": 1,
    "target_date": "2026-10-26",
    "new_price": 13500,
    "new_available_count": 4
  }
  ```

### 4. `POST /api/mock-ota/{channel}`
* **Purpose:** Internal dummy endpoint simulating external OTA servers (`agoda`, `rakuten`, `booking`).
* **Behavior:** Adds a random `usleep(25000, 60000)` (25–60ms) delay and returns `200 OK` with a confirmation timestamp.

---

## 6. Single-Screen Frontend Specification (Angular + TypeScript)

Temairazu's signature UX philosophy is **single-screen visibility** with **color-coded pricing tiers**. Everything lives inside a single `DashboardComponent`.

### UI Wireframe
```text
+-----------------------------------------------------------------------------------+
| SMART-RATE SYNC (Mini-Temairazu Site Controller)        [Status: 3 Channels Live] |
+-----------------------------------------------------------------------------------+
| SIMULATOR BAR:                                                                    |
| [Channel: Agoda v] [Room: Deluxe Twin v] [Date: Oct 25 v]  [⚡ Fire Booking]      |
|                                                            [🔥 Fire 2x Concurrent]|
+-----------------------------------------------------------------------------------+
| 7-DAY INVENTORY & DYNAMIC YIELD MATRIX (Click any price to inline-edit)           |
+------------------+------------+------------+------------+------------+------------+
| Room Type        | Oct 24     | Oct 25     | Oct 26     | Oct 27     | Oct 28 ... |
+------------------+------------+------------+------------+------------+------------+
| Standard Single  | 🟢 6 left  | 🟢 5 left  | 🟠 2 left  | 🔴 0 left  | 🟢 8 left  |
| (Base: ¥10,000)  | ¥10,000    | ¥10,000    | ¥12,000 ⚡ | SOLD OUT   | ¥10,000    |
+------------------+------------+------------+------------+------------+------------+
| Deluxe Twin      | 🟢 4 left  | 🟠 1 left  | 🟢 5 left  | 🟢 5 left  | 🟠 2 left  |
| (Base: ¥15,000)  | ¥15,000    | ¥18,000 ⚡ | ¥15,000    | ¥15,000    | ¥18,000 ⚡ |
+------------------+------------+------------+------------+------------+------------+
| LIVE OTA CHANNEL SYNC AUDIT LOG                                                   |
| [200 OK] (34ms) -> Pushed Deluxe Twin (Oct 25: 1 left, ¥18,000) -> Rakuten|
| [200 OK] (41ms) -> Pushed Deluxe Twin (Oct 25: 1 left, ¥18,000) -> Booking|
| [WEBHOOK]       <- Received Booking #AG-9921 from Agoda (Deluxe, Oct 25)|
+-----------------------------------------------------------------------------------+
```

### Color-Coding Logic in Angular
* **🟢 Green Badge (`available_count >= 3`):** Normal inventory, standard base pricing.
* **🟠 Orange Badge (`available_count == 1 or 2`):** Low inventory; displays `⚡ +20% Surge Active` indicator.
* **🔴 Red Badge (`available_count == 0`):** Sold out; disables further bookings for that date.

### The "2x Concurrent Race Condition" Demo Button
* Clicking **[🔥 Fire 2x Concurrent]** triggers `Promise.all([http.post(...), http.post(...)])` in TypeScript, sending two identical booking requests at the exact same millisecond.
* When only **1 room** is left, the live audit log immediately shows:
  * Request 1: `200 OK — Room Booked & Synced to Channels`
  * Request 2: `409 Conflict — Prevented Double-Booking via MySQL Row Lock`

---

## 7. Step-by-Step Build & Execution Plan

- [ ] **Phase 1: Database & Laravel Setup (Day 1–2)**
  - Initialize Laravel project (`composer create-project laravel/laravel smartrate-backend`).
  - Configure MySQL database connection in `.env`.
  - Create migrations for the 4 core tables including the `idx_room_date` composite unique index.
- [ ] **Phase 2: Database Seeder (Day 2)**
  - Build `DatabaseSeeder.php` to populate 3 room types (`Standard Single`, `Deluxe Twin`, `Executive Suite`) and generate 7 days of initial inventory counts and base prices.
- [ ] **Phase 3: Core Concurrency & Yield Engine (Day 3–5)**
  - Implement `BookingWebhookController` with `DB::transaction()` and `lockForUpdate()`.
  - Add the `< 3 rooms = +20% surge price` automation rule.
  - Implement outbound HTTP sync calls to `/api/mock-ota/{channel}` and log latency in `channel_sync_logs`.
- [ ] **Phase 4: Single-Screen Angular Dashboard (Day 6–8)**
  - Initialize Angular CLI project (`ng new smartrate-frontend`) and add Bootstrap 5.
  - Build the 7-day color-coded matrix table and inline price override input.
  - Build the Webhook Simulator bar with single-fire and 2x-concurrent test buttons.
- [ ] **Phase 5: Engineering Polish & Deployment (Day 9–10)**
  - Run `EXPLAIN SELECT * FROM daily_inventories WHERE room_type_id = 1 AND target_date = '2026-10-25';` in MySQL and include a screenshot in the repo showing `type: const` index usage.
  - Organize work into clean Git branches/PRs (`feat/mysql-row-locking`, `feat/dynamic-yield-rule`, `feat/angular-rate-matrix`).
  - Optional: Add a 50-line Python FastAPI container in `docker-compose.yml` to calculate dynamic surge multipliers.

---

## 8. Interview Talking Points & Q&A Prep

1. **"Why did you build a Channel Manager instead of a standard booking website?"**
   > *"I researched Temairazu's core product and saw that your engineering challenges center around high-reliability B2B API synchronization, automated yield management (TEMAIRAZU Jido), and single-screen usability for hotel operators. I wanted my project to solve the exact problems your team works on every day."*

2. **"How did you prevent double-bookings when multiple travel sites send webhooks simultaneously?"**
   > *"I wrapped the booking logic inside a MySQL transaction and used `SELECT ... FOR UPDATE` (`lockForUpdate()` in Laravel) on the specific `(room_type_id, target_date)` row. Because that pair has a composite unique index, MySQL locks only that single cell—blocking competing requests for a few milliseconds without locking the rest of the hotel's calendar."*

3. **"How can Python fit into this architecture?"**
   > *"While I built the core transactional backend in PHP and Laravel to match your production stack, I designed the yield-calculation step so it can call an internal Python FastAPI microservice. That way, PHP handles high-speed transactional integrity while Python can be used for data-heavy competitor price scraping or demand forecasting if needed."*