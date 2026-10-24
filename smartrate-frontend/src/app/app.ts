import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { GridResponse, GridService, RoomTypeGrid } from './grid.service';

interface AuditEntry {
  id: number | string;
  badge: string | null;
  badgeClass: string;
  text: string;
  suffix: string | null;
}

interface EditTarget {
  roomTypeId: number;
  targetDate: string;
}

const CHANNELS = ['Agoda', 'Rakuten', 'Booking.com'] as const;

let auditSeq = 0;

@Component({
  selector: 'app-root',
  styleUrl: './app.css',
  templateUrl: './app.html',
  imports: [FormsModule],
})
export class App implements OnInit {
  private readonly api = inject(GridService);

  protected readonly grid = signal<GridResponse | null>(null);
  protected readonly loading = signal(true);

  // Simulator bar state
  protected readonly channelOptions = CHANNELS;
  protected readonly selChannel = signal<string>('Agoda');
  protected readonly selRoomId = signal<number | null>(null);
  protected readonly selDate = signal<string | null>(null);
  protected readonly firing = signal(false);

  // Client-side demo feed (webhook receipts, race outcomes, overrides)
  private readonly clientEvents = signal<AuditEntry[]>([]);

  protected readonly audit = computed<AuditEntry[]>(() => [...this.clientEvents(), ...this.serverAudit()]);

  protected readonly serverAudit = computed<AuditEntry[]>(() => {
    const data = this.grid();
    if (!data) return [];
    return data.sync_logs.map((log) => ({
      id: log.id,
      badge: `[${log.http_status ?? '??'} ${log.http_status === 200 ? 'OK' : log.http_status === 409 ? 'CONFLICT' : 'ERR'}]`,
      badgeClass: log.http_status === 200 ? 'text-success' : 'text-danger',
      text: `Pushed ${log.payload_sent.room_type_code} (${this.dayLabel(log.payload_sent.target_date ?? '')}: `
        + `${log.payload_sent.available_count} left, ${this.yen(log.payload_sent.current_price ?? 0)})`,
      suffix: `-> ${this.channelLabel(log.channel_name)} (${log.response_time_ms ?? '?'}ms)`,
    }));
  });

  // Inline price edit state
  protected readonly editing = signal<EditTarget | null>(null);
  protected readonly draftPrice = signal<number | null>(null);

  async ngOnInit(): Promise<void> {
    await this.refresh();
  }

  protected selectRoom(event: Event): void {
    this.selRoomId.set(Number((event.target as HTMLSelectElement).value));
  }

  protected selectDate(event: Event): void {
    this.selDate.set((event.target as HTMLSelectElement).value);
  }

  protected selectChannel(event: Event): void {
    this.selChannel.set((event.target as HTMLSelectElement).value);
  }

  protected roomName(roomId: number): string {
    return this.grid()?.room_types.find((rt) => rt.id === roomId)?.name ?? `Room #${roomId}`;
  }

  protected roomCode(roomId: number): string {
    return this.grid()?.room_types.find((rt) => rt.id === roomId)?.code ?? `#${roomId}`;
  }

  /**
   * Fire one OTA webhook (single booking).
   */
  protected async fireBooking(): Promise<void> {
    const roomId = this.selRoomId();
    const date = this.selDate();
    if (!roomId || !date) return;

    const id = `SIM-${Date.now()}`;
    this.firing.set(true);
    try {
      const result = await firstValueFrom(this.api.fireBooking({
        external_booking_id: id,
        channel_name: this.selChannel(),
        room_type_id: roomId,
        check_in_date: date,
      }));

      this.pushEvent(
        result.duplicate
          ? `<- Retry for #${id} ignored by idempotency key (no double spend)`
          : `<- Received Booking #${id} from ${result.booking.channel_name} `
            + `(${this.roomCode(roomId)}, ${this.dayLabel(date)})`,
        '[WEBHOOK]',
        'text-warning', null, null,
      );
    } catch (err) {
      if (this.isConflict(err)) {
        this.pushEvent(
          `<- Booking #${id} rejected: Out of inventory — double booking prevented by MySQL row lock`,
          '[409 CONFLICT]',
          'text-danger', null, null,
        );
      } else {
        this.pushEvent(`<- Booking #${id} failed: ${String(err).slice(0, 120)}`, '[ERROR]', 'text-danger');
      }
    } finally {
      this.firing.set(false);
      await this.refresh();
    }
  }

  /**
   * The "2x Concurrent Race Condition" demo: two booking webhooks hit the
   * last room at the same millisecond. Promise.all starts both requests
   * literally together — the row lock serializes them server-side and only
   * one of them can win.
   */
  protected async fireRace(): Promise<void> {
    const roomId = this.selRoomId();
    const date = this.selDate();
    if (!roomId || !date) return;

    const stamp = Date.now();
    const base = {
      channel_name: this.selChannel(),
      room_type_id: roomId,
      check_in_date: date,
    };

    this.firing.set(true);
    try {
      const settled = await Promise.allSettled([
        firstValueFrom(this.api.fireBooking({ ...base, external_booking_id: `RACE-${stamp}-1` })),
        firstValueFrom(this.api.fireBooking({ ...base, external_booking_id: `RACE-${stamp}-2` })),
      ]);

      for (const result of settled) {
        if (result.status === 'fulfilled' && !result.value.duplicate) {
          this.pushEvent(
            `Request ${result.value.booking.external_booking_id} — Room Booked & Synced to Channels`,
            '[200 OK]', 'text-success', null, null,
          );
        } else if (this.isConflict(result)) {
          this.pushEvent(
            `Request — Prevented Double-Booking via MySQL Row Lock`,
            '[409 CONFLICT]', 'text-danger', null, null,
          );
        } else if (result.status === 'fulfilled') {
          this.pushEvent(
            `Retry #${result.value.booking.external_booking_id} ignored by idempotency key (no double spend)`,
            '[IDEMPOTENT]', 'text-warning', null, null,
          );
        } else {
          this.pushEvent('Request failed unexpectedly', '[ERROR]', 'text-danger');
        }
      }
    } finally {
      this.firing.set(false);
      await this.refresh();
    }
  }

  protected startEdit(roomType: RoomTypeGrid, date: string): void {
    const inv = roomType.inventories[date];
    if (!inv) return;
    this.editing.set({ roomTypeId: roomType.id, targetDate: date });
    this.draftPrice.set(inv.current_price);
  }

  protected isEditing(roomTypeId: number, date: string): boolean {
    const edit = this.editing();
    return edit !== null && edit.roomTypeId === roomTypeId && edit.targetDate === date;
  }

  protected cancelEdit(): void {
    this.editing.set(null);
    this.draftPrice.set(null);
  }

  protected onDraftPrice(event: Event): void {
    this.draftPrice.set(Number((event.target as HTMLInputElement).value));
  }

  /**
   * Staff inline override — writes the new rate and pushes it to every OTA.
   */
  protected async saveEdit(roomType: RoomTypeGrid, date: string): Promise<void> {
    const price = this.draftPrice();
    if (price === null || Number.isNaN(price) || price < 0) {
      this.cancelEdit();
      return;
    }
    const inv = roomType.inventories[date];
    if (!inv) {
      this.cancelEdit();
      return;
    }

    const edit = this.editing();
    try {
      await firstValueFrom(this.api.overrideInventory({
        room_type_id: edit!.roomTypeId,
        target_date: date,
        new_price: price,
        new_available_count: inv.available_count,
      }));
      this.pushEvent(
        `Manual override ${roomType.code} (${this.dayLabel(date)}) price -> ${this.yen(price)} pushed to all channels`,
        '[OVERRIDE]', 'text-primary', null, null,
      );
    } catch (err) {
      this.pushEvent(`Override failed: ${String(err).slice(0, 120)}`, '[ERROR]', 'text-danger');
    } finally {
      this.cancelEdit();
      await this.refresh();
    }
  }

  // ---- template helpers ---------------------------------------------------

  protected badgeClass(count: number | undefined): string {
    if (count === undefined || count <= 0) return 'bg-danger';
    if (count < 3) return 'bg-warning text-dark';
    return 'bg-success';
  }

  protected countEmoji(count: number | undefined): string {
    if (count === undefined || count <= 0) return '🔴';
    if (count < 3) return '🟠';
    return '🟢';
  }

  protected yen(amount: number | undefined | null): string {
    return amount === undefined || amount === null ? '' : `¥${amount.toLocaleString('en-US')}`;
  }

  protected dayLabel(iso: string): string {
    const d = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  protected channelLabel(channel: string): string {
    if (channel === 'booking') return 'Booking';
    return channel.charAt(0).toUpperCase() + channel.slice(1);
  }

  protected async refresh(): Promise<void> {
    const data = await firstValueFrom(this.api.getGrid());
    this.grid.set(data);
    this.loading.set(false);

    if (this.selRoomId() === null) {
      this.selRoomId.set(data.room_types[0]?.id ?? null);
    }
    if (this.selDate() === null || !data.days.includes(this.selDate()!)) {
      this.selDate.set(data.days[0] ?? null);
    }
  }

  private pushEvent(
    text: string,
    badge: string | null,
    badgeClass = 'text-muted',
    ms: number | null = null,
    _httpStatus?: number | null,
  ): void {
    this.clientEvents.update((events) => [
      { id: `ev-${++auditSeq}`, badge, badgeClass, text, suffix: ms === null ? null : `(${ms}ms)` },
      ...events,
    ]);
  }

  private isConflict(value: unknown): boolean {
    // HttpClient rejections come through as the error object; a settled race
    // result carries it in `reason`.
    const candidate = value !== null && typeof value === 'object' && 'reason' in value
      ? (value as { reason?: unknown }).reason
      : value;

    return candidate !== null && typeof candidate === 'object' && 'status' in candidate
      && (candidate as { status?: unknown }).status === 409;
  }
}
