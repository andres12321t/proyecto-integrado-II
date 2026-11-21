import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { CATALOGS, CatalogDefinition, CatalogRecord } from '../../core/catalog-config';

@Component({
  selector: 'app-catalog-manager',
  templateUrl: './catalog-manager.html',
  styleUrl: './catalog-manager.css',
})
export class CatalogManagerComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);

  protected readonly definition = signal<CatalogDefinition | null>(null);
  protected readonly records = signal<CatalogRecord[]>([]);
  protected readonly formValues = signal<Record<string, string>>({});
  protected readonly selectedId = signal<number | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal('');

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const resource = params.get('resource') ?? '';
      const definition = CATALOGS[resource] ?? null;
      this.definition.set(definition);
      this.resetForm();
      if (definition) {
        this.loadRecords();
      } else {
        this.loading.set(false);
        this.error.set('El módulo solicitado no existe.');
      }
    });
  }

  protected loadRecords(): void {
    const definition = this.definition();
    if (!definition) return;

    this.loading.set(true);
    this.error.set('');
    this.api.list<CatalogRecord>(definition.endpoint).subscribe({
      next: (records) => {
        this.records.set(records);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(this.api.errorMessage(error));
        this.loading.set(false);
      },
    });
  }

  protected setValue(key: string, event: Event): void {
    const target = event.target;
    if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) {
      return;
    }
    this.formValues.update((values) => ({ ...values, [key]: target.value }));
  }

  protected edit(record: CatalogRecord): void {
    const definition = this.definition();
    if (!definition) return;
    const values: Record<string, string> = {};
    for (const field of definition.fields) {
      const value = record[field.key];
      values[field.key] = value === null || value === undefined ? '' : String(value);
    }
    this.formValues.set(values);
    this.selectedId.set(record.id);
    this.notice.set('');
    this.error.set('');
  }

  protected save(event: Event): void {
    event.preventDefault();
    const definition = this.definition();
    if (!definition || this.saving()) return;

    const values = this.formValues();
    const payload: Record<string, string | null> = {};
    for (const field of definition.fields) {
      const value = (values[field.key] ?? '').trim();
      payload[field.key] = value === '' && !field.required
        ? field.key === 'descripcion' ? null : ''
        : value;
    }

    this.saving.set(true);
    this.error.set('');
    this.notice.set('');
    const request = this.selectedId() === null
      ? this.api.create<CatalogRecord>(definition.endpoint, payload)
      : this.api.update<CatalogRecord>(definition.endpoint, this.selectedId()!, payload);

    request.subscribe({
      next: () => {
        this.saving.set(false);
        this.notice.set(`${definition.singular} ${this.selectedId() === null ? 'creado' : 'actualizado'} correctamente.`);
        this.resetForm();
        this.loadRecords();
      },
      error: (error: unknown) => {
        this.saving.set(false);
        this.error.set(this.api.errorMessage(error));
      },
    });
  }

  protected remove(record: CatalogRecord): void {
    const definition = this.definition();
    if (!definition) return;
    const name = String(record[definition.nameField] ?? definition.singular);
    if (!window.confirm(`¿Eliminar "${name}"? Esta acción no se puede deshacer.`)) return;

    this.error.set('');
    this.notice.set('');
    this.api.delete(definition.endpoint, record.id).subscribe({
      next: () => {
        this.notice.set(`${definition.singular} eliminado correctamente.`);
        if (this.selectedId() === record.id) this.resetForm();
        this.loadRecords();
      },
      error: (error: unknown) => this.error.set(this.api.errorMessage(error)),
    });
  }

  protected resetForm(): void {
    const values: Record<string, string> = {};
    for (const field of this.definition()?.fields ?? []) {
      values[field.key] = field.key === 'estado' ? 'Activo' : '';
    }
    this.formValues.set(values);
    this.selectedId.set(null);
  }

  protected displayValue(record: CatalogRecord, key: string): string {
    const value = record[key];
    return value === null || value === undefined || value === '' ? '—' : String(value);
  }
}
