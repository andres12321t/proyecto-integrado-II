import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

interface ApiResponse<T> {
  data: T;
}

declare global {
  interface Window {
    CINEADMIN_CONFIG?: {
      apiBaseUrl?: string;
    };
  }
}

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = window.CINEADMIN_CONFIG?.apiBaseUrl ?? '/api';

  list<T>(resource: string): Observable<T[]> {
    return this.http
      .get<ApiResponse<T[]>>(`${this.baseUrl}/${resource}`)
      .pipe(map((response) => response.data));
  }

  create<T>(resource: string, payload: object): Observable<T> {
    return this.http
      .post<ApiResponse<T>>(`${this.baseUrl}/${resource}`, payload)
      .pipe(map((response) => response.data));
  }

  update<T>(resource: string, id: number, payload: object): Observable<T> {
    return this.http
      .put<ApiResponse<T>>(`${this.baseUrl}/${resource}/${id}`, payload)
      .pipe(map((response) => response.data));
  }

  delete(resource: string, id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/${resource}/${id}`);
  }

  errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      const body = error.error as { error?: { message?: string } } | null;
      return body?.error?.message ?? `Error de comunicación con la API (HTTP ${error.status}).`;
    }
    return 'Ocurrió un error inesperado al comunicarse con la API.';
  }
}
