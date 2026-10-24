import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';

export interface GridInventory {
  available_count: number;
  current_price: number;
  is_surge_active: boolean;
}

export interface RoomTypeGrid {
  id: number;
  code: string;
  name: string;
  base_price: number;
  max_inventory: number;
  inventories: Record<string, GridInventory>;
}

export interface SyncLog {
  id: number;
  channel_name: string;
  action: string;
  payload_sent: {
    room_type_code?: string;
    target_date?: string;
    available_count?: number;
    current_price?: number;
  };
  http_status: number | null;
  response_time_ms: number | null;
  created_at: string;
}

export interface GridResponse {
  days: string[];
  room_types: RoomTypeGrid[];
  sync_logs: SyncLog[];
}

export interface BookingResult {
  message: string;
  duplicate: boolean;
  booking: {
    external_booking_id: string;
    channel_name: string;
    room_type_id: number;
    check_in_date: string;
    booked_price: number;
    remaining_available_count: number;
    current_price: number;
    is_surge_active: boolean;
  };
}

const API = 'http://127.0.0.1:8000/api';

@Injectable({ providedIn: 'root' })
export class GridService {
  constructor(private readonly http: HttpClient) {}

  getGrid(): Observable<GridResponse> {
    return this.http.get<GridResponse>(`${API}/grid`);
  }

  fireBooking(payload: {
    external_booking_id: string;
    channel_name: string;
    room_type_id: number;
    check_in_date: string;
  }): Observable<BookingResult> {
    return this.http.post<BookingResult>(`${API}/webhook/ota-booking`, payload);
  }

  overrideInventory(payload: {
    room_type_id: number;
    target_date: string;
    new_price: number;
    new_available_count: number;
  }): Observable<unknown> {
    return this.http.patch(`${API}/inventory/override`, payload);
  }
}
