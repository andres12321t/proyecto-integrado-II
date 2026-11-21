import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { CatalogRecord, MediaRecord } from '../../core/catalog-config';

@Component({
  selector: 'app-dashboard',
  imports: [RouterLink],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class DashboardComponent implements OnInit {
  private readonly api = inject(ApiService);

  protected readonly loading = signal(true);
  protected readonly error = signal('');
  protected readonly mediaCount = signal(0);
  protected readonly activeGenres = signal(0);
  protected readonly activeDirectors = signal(0);
  protected readonly activeStudios = signal(0);
  protected readonly recentMedia = signal<MediaRecord[]>([]);

  ngOnInit(): void {
    forkJoin({
      medias: this.api.list<MediaRecord>('medias'),
      generos: this.api.list<CatalogRecord>('generos'),
      directores: this.api.list<CatalogRecord>('directores'),
      productoras: this.api.list<CatalogRecord>('productoras'),
    }).subscribe({
      next: ({ medias, generos, directores, productoras }) => {
        this.mediaCount.set(medias.length);
        this.activeGenres.set(generos.filter((item) => item['estado'] === 'Activo').length);
        this.activeDirectors.set(directores.filter((item) => item['estado'] === 'Activo').length);
        this.activeStudios.set(productoras.filter((item) => item['estado'] === 'Activo').length);
        this.recentMedia.set(medias.slice(0, 5));
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(this.api.errorMessage(error));
        this.loading.set(false);
      },
    });
  }
}
