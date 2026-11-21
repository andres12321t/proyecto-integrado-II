import { Routes } from '@angular/router';
import { DashboardComponent } from './components/dashboard/dashboard';
import { MediaManagerComponent } from './components/media-manager/media-manager';
import { CatalogManagerComponent } from './components/catalog-manager/catalog-manager';

export const routes: Routes = [
  { path: '', component: DashboardComponent, title: 'Resumen | CineAdmin' },
  { path: 'medias', component: MediaManagerComponent, title: 'Películas y series | CineAdmin' },
  { path: 'catalogo/:resource', component: CatalogManagerComponent, title: 'Catálogos | CineAdmin' },
  { path: '**', redirectTo: '' },
];
