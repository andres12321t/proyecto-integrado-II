import { Component } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

@Component({
  selector: 'app-root',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.css',
})
export class App {
  protected readonly catalogLinks = [
    { label: 'Géneros', path: '/catalogo/generos', icon: '✦' },
    { label: 'Directores', path: '/catalogo/directores', icon: '◎' },
    { label: 'Productoras', path: '/catalogo/productoras', icon: '▣' },
    { label: 'Tipos', path: '/catalogo/tipos', icon: '▤' },
  ];
}
