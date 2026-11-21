import { writeFile } from 'node:fs/promises';

const apiOrigin = process.env.API_URL?.trim();

if (!apiOrigin) {
  throw new Error('Define API_URL con el origen HTTPS del backend antes de compilar para Render.');
}

let parsedUrl;
try {
  parsedUrl = new URL(apiOrigin);
} catch {
  throw new Error('API_URL debe ser una URL válida, por ejemplo https://mi-api.onrender.com.');
}

if (parsedUrl.protocol !== 'https:' || parsedUrl.pathname !== '/' || parsedUrl.search || parsedUrl.hash) {
  throw new Error('API_URL debe ser solo el origen HTTPS del backend, sin ruta ni parámetros.');
}

const apiBaseUrl = `${parsedUrl.origin}/api`;
await writeFile(
  new URL('../public/runtime-config.js', import.meta.url),
  `window.CINEADMIN_CONFIG = ${JSON.stringify({ apiBaseUrl })};\n`,
);
