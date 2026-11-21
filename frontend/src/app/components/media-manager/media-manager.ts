import { Component, OnInit, inject, signal } from '@angular/core';
import { UpperCasePipe } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { forkJoin } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { CatalogOption, MediaRecord } from '../../core/catalog-config';

interface MediaDraft {
  serial: string;
  titulo: string;
  sinopsis: string;
  url_pelicula: string;
  imagen_portada: string;
  ano_estreno: string;
  genero_id: string;
  director_id: string;
  productora_id: string;
  tipo_id: string;
}

function emptyDraft(): MediaDraft {
  return {
    serial: '',
    titulo: '',
    sinopsis: '',
    url_pelicula: '',
    imagen_portada: '',
    ano_estreno: '',
    genero_id: '',
    director_id: '',
    productora_id: '',
    tipo_id: '',
  };
}

@Component({
  selector: 'app-media-manager',
  imports: [UpperCasePipe],
  templateUrl: './media-manager.html',
  styleUrls: ['./media-manager.css', './player-dialog.css'],
})
export class MediaManagerComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly sanitizer = inject(DomSanitizer);

  protected readonly medias = signal<MediaRecord[]>([]);
  protected readonly generos = signal<CatalogOption[]>([]);
  protected readonly directores = signal<CatalogOption[]>([]);
  protected readonly productoras = signal<CatalogOption[]>([]);
  protected readonly tipos = signal<CatalogOption[]>([]);
  protected readonly draft = signal<MediaDraft>(emptyDraft());
  protected readonly selectedId = signal<number | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal('');
  protected readonly selectedMedia = signal<MediaRecord | null>(null);
  protected readonly playbackUrl = signal('');
  protected readonly youtubeEmbedUrl = signal<SafeResourceUrl | null>(null);
  protected readonly playbackError = signal('');

  ngOnInit(): void {
    this.loadAll();
  }

  protected loadAll(): void {
    this.loading.set(true);
    this.error.set('');
    forkJoin({
      medias: this.api.list<MediaRecord>('medias'),
      generos: this.api.list<CatalogOption>('generos'),
      directores: this.api.list<CatalogOption>('directores'),
      productoras: this.api.list<CatalogOption>('productoras'),
      tipos: this.api.list<CatalogOption>('tipos'),
    }).subscribe({
      next: ({ medias, generos, directores, productoras, tipos }) => {
        this.medias.set(medias);
        this.generos.set(generos.filter((item) => item.estado === 'Activo'));
        this.directores.set(directores.filter((item) => item.estado === 'Activo'));
        this.productoras.set(productoras.filter((item) => item.estado === 'Activo'));
        this.tipos.set(tipos);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(this.api.errorMessage(error));
        this.loading.set(false);
      },
    });
  }

  protected setField(field: keyof MediaDraft, event: Event): void {
    const target = event.target;
    if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) {
      return;
    }
    this.draft.update((draft) => ({ ...draft, [field]: target.value }));
  }

  protected edit(media: MediaRecord): void {
    this.draft.set({
      serial: media.serial,
      titulo: media.titulo,
      sinopsis: media.sinopsis,
      url_pelicula: media.url_pelicula,
      imagen_portada: media.imagen_portada,
      ano_estreno: String(media.ano_estreno),
      genero_id: String(media.genero_id),
      director_id: String(media.director_id),
      productora_id: String(media.productora_id),
      tipo_id: String(media.tipo_id),
    });
    this.selectedId.set(media.id);
    this.notice.set('');
    this.error.set('');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  protected save(event: Event): void {
    event.preventDefault();
    if (this.saving()) return;
    const draft = this.draft();
    const payload = {
      serial: draft.serial.trim(),
      titulo: draft.titulo.trim(),
      sinopsis: draft.sinopsis.trim(),
      url_pelicula: draft.url_pelicula.trim(),
      imagen_portada: draft.imagen_portada.trim(),
      ano_estreno: Number(draft.ano_estreno),
      genero_id: Number(draft.genero_id),
      director_id: Number(draft.director_id),
      productora_id: Number(draft.productora_id),
      tipo_id: Number(draft.tipo_id),
    };

    if (Object.values(payload).some((value) => value === '' || (typeof value === 'number' && (!Number.isInteger(value) || value < 1)))) {
      this.error.set('Completa todos los campos con valores válidos antes de guardar.');
      return;
    }

    this.saving.set(true);
    this.error.set('');
    this.notice.set('');
    const selectedId = this.selectedId();
    const request = selectedId === null
      ? this.api.create<MediaRecord>('medias', payload)
      : this.api.update<MediaRecord>('medias', selectedId, payload);

    request.subscribe({
      next: () => {
        this.saving.set(false);
        this.notice.set(`La producción se ${selectedId === null ? 'agregó' : 'actualizó'} correctamente.`);
        this.resetForm();
        this.loadAll();
      },
      error: (error: unknown) => {
        this.saving.set(false);
        this.error.set(this.api.errorMessage(error));
      },
    });
  }

  protected remove(media: MediaRecord): void {
    if (!window.confirm(`¿Eliminar "${media.titulo}"? Esta acción no se puede deshacer.`)) return;
    this.error.set('');
    this.notice.set('');
    this.api.delete('medias', media.id).subscribe({
      next: () => {
        this.notice.set('La producción se eliminó correctamente.');
        if (this.selectedId() === media.id) this.resetForm();
        this.loadAll();
      },
      error: (error: unknown) => this.error.set(this.api.errorMessage(error)),
    });
  }

  protected play(media: MediaRecord): void {
    this.playbackError.set('');
    this.youtubeEmbedUrl.set(null);

    let url: URL;
    try {
      url = new URL(media.url_pelicula);
    } catch {
      this.selectedMedia.set(media);
      this.playbackUrl.set('');
      this.playbackError.set('La URL guardada no es válida.');
      return;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
      this.selectedMedia.set(media);
      this.playbackUrl.set('');
      this.playbackError.set('Solo se pueden reproducir enlaces HTTP o HTTPS.');
      return;
    }

    this.selectedMedia.set(media);
    this.playbackUrl.set(url.href);
    const videoId = this.youtubeVideoId(url);
    if (videoId) {
      this.youtubeEmbedUrl.set(
        this.sanitizer.bypassSecurityTrustResourceUrl(
          `https://www.youtube.com/embed/${videoId}?autoplay=1&playsinline=1&rel=0`,
        ),
      );
    }
  }

  protected closePlayer(): void {
    this.selectedMedia.set(null);
    this.playbackUrl.set('');
    this.youtubeEmbedUrl.set(null);
    this.playbackError.set('');
  }

  protected handlePlaybackError(): void {
    this.playbackError.set(
      'No se pudo reproducir este enlace como video. Verifica que sea un archivo MP4 o WebM accesible desde el navegador.',
    );
  }

  protected resetForm(): void {
    this.draft.set(emptyDraft());
    this.selectedId.set(null);
  }

  private youtubeVideoId(url: URL): string | null {
    const hostname = url.hostname.toLowerCase();
    let videoId: string | null = null;

    if (hostname === 'youtu.be' || hostname === 'www.youtu.be') {
      videoId = url.pathname.split('/').filter(Boolean)[0] ?? null;
    } else if (hostname === 'youtube.com' || hostname === 'www.youtube.com' || hostname === 'm.youtube.com') {
      if (url.pathname === '/watch') {
        videoId = url.searchParams.get('v');
      } else {
        videoId = url.pathname.match(/^\/(?:embed|shorts)\/([^/]+)/)?.[1] ?? null;
      }
    }

    return videoId && /^[A-Za-z0-9_-]{11}$/.test(videoId) ? videoId : null;
  }

  protected optionLabel(option: CatalogOption): string {
    return option.nombre ?? option.nombres ?? '';
  }
}
