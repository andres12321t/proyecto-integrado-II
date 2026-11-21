export interface CatalogField {
  key: string;
  label: string;
  type: 'text' | 'textarea' | 'status';
  required?: boolean;
  maxLength?: number;
}

export interface CatalogDefinition {
  title: string;
  singular: string;
  endpoint: string;
  nameField: string;
  fields: CatalogField[];
  columns: { key: string; label: string }[];
}

export const CATALOGS: Record<string, CatalogDefinition> = {
  generos: {
    title: 'Géneros',
    singular: 'género',
    endpoint: 'generos',
    nameField: 'nombre',
    fields: [
      { key: 'nombre', label: 'Nombre', type: 'text', required: true, maxLength: 100 },
      { key: 'estado', label: 'Estado', type: 'status', required: true },
      { key: 'descripcion', label: 'Descripción', type: 'textarea' },
    ],
    columns: [
      { key: 'nombre', label: 'Nombre' },
      { key: 'estado', label: 'Estado' },
      { key: 'descripcion', label: 'Descripción' },
    ],
  },
  directores: {
    title: 'Directores',
    singular: 'director',
    endpoint: 'directores',
    nameField: 'nombres',
    fields: [
      { key: 'nombres', label: 'Nombres', type: 'text', required: true, maxLength: 150 },
      { key: 'estado', label: 'Estado', type: 'status', required: true },
    ],
    columns: [
      { key: 'nombres', label: 'Nombres' },
      { key: 'estado', label: 'Estado' },
    ],
  },
  productoras: {
    title: 'Productoras',
    singular: 'productora',
    endpoint: 'productoras',
    nameField: 'nombre',
    fields: [
      { key: 'nombre', label: 'Nombre', type: 'text', required: true, maxLength: 100 },
      { key: 'estado', label: 'Estado', type: 'status', required: true },
      { key: 'slogan', label: 'Slogan', type: 'text', maxLength: 255 },
      { key: 'descripcion', label: 'Descripción', type: 'textarea' },
    ],
    columns: [
      { key: 'nombre', label: 'Nombre' },
      { key: 'estado', label: 'Estado' },
      { key: 'slogan', label: 'Slogan' },
    ],
  },
  tipos: {
    title: 'Tipos',
    singular: 'tipo',
    endpoint: 'tipos',
    nameField: 'nombre',
    fields: [
      { key: 'nombre', label: 'Nombre', type: 'text', required: true, maxLength: 50 },
      { key: 'descripcion', label: 'Descripción', type: 'textarea' },
    ],
    columns: [
      { key: 'nombre', label: 'Nombre' },
      { key: 'descripcion', label: 'Descripción' },
    ],
  },
};

export interface CatalogRecord {
  id: number;
  fecha_creacion?: string;
  fecha_actualizacion?: string;
  [key: string]: unknown;
}

export interface MediaRecord {
  id: number;
  serial: string;
  titulo: string;
  sinopsis: string;
  url_pelicula: string;
  imagen_portada: string;
  ano_estreno: number;
  genero_id: number;
  genero: string;
  director_id: number;
  director: string;
  productora_id: number;
  productora: string;
  tipo_id: number;
  tipo: string;
  fecha_creacion: string;
  fecha_actualizacion: string;
}

export interface CatalogOption {
  id: number;
  nombre?: string;
  nombres?: string;
  estado?: string;
}
