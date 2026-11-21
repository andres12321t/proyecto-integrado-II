# Frontend de CineAdmin

Interfaz administrativa desarrollada con Angular 22 para gestionar películas, series y sus catálogos. La aplicación consume la API PHP del proyecto mediante un proxy de desarrollo; no requiere habilitar CORS para el uso local.

## Desplegar en Render

El archivo [`render.yaml`](../render.yaml) define el servicio PHP y el sitio estático Angular. Sube el proyecto a GitHub y crea un **Blueprint** en Render seleccionando ese repositorio.

1. Configura las variables `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS` del servicio `cineadmin-api` con las credenciales de tu MySQL externo. `DB_PORT` normalmente es `3306`. Importa previamente el esquema y los datos de la aplicación en esa base.
2. En `cineadmin-frontend`, establece `API_URL` con el origen HTTPS del servicio API, por ejemplo `https://mi-api.onrender.com` (sin `/api` ni `/` final), y vuelve a desplegar.
3. En `cineadmin-api`, establece `FRONTEND_ORIGIN` con el origen HTTPS del sitio estático, también sin `/` final. Esto habilita CORS únicamente para ese frontend.

Las variables marcadas como secretas se configuran desde el panel de Render y no deben añadirse al repositorio. La API puede verificarse en `https://mi-api.onrender.com/health`.

## Requisitos

- Node.js 24 o una versión compatible con Angular 22.
- La API PHP y MySQL iniciados.

## Ejecutar en desarrollo

Inicia primero la API en una terminal:

```powershell
Set-Location 'C:\Users\hp\Desktop\proyecto integrado II\backend'
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t public public/index.php
```

En otra terminal, inicia Angular:

```powershell
Set-Location 'C:\Users\hp\Desktop\proyecto integrado II\frontend'
$env:Path = 'C:\Program Files\nodejs;' + $env:Path
& 'C:\Program Files\nodejs\npm.cmd' start
```

Abre `http://localhost:4200`. Si PHP o Node.js están instalados en otra ruta, reemplaza la ruta correspondiente en los comandos. El proxy de [`proxy.conf.json`](./proxy.conf.json) envía las solicitudes `/api` a `http://127.0.0.1:8000`.

## Componentes

- `DashboardComponent`: resumen del catálogo y producciones recientes.
- `MediaManagerComponent`: alta, listado, edición, eliminación y reproducción de películas y series; ofrece únicamente géneros, directores y productoras activos. Reproduce enlaces directos a video y enlaces de YouTube.
- `CatalogManagerComponent`: CRUD para géneros, directores, productoras y tipos.
- `ApiService`: cliente HTTP para las rutas y respuestas JSON del backend.

## Validar y compilar

Desde esta carpeta:

```powershell
$env:Path = 'C:\Program Files\nodejs;' + $env:Path
& 'C:\Program Files\nodejs\npm.cmd' run build
& 'C:\Program Files\nodejs\npm.cmd' test -- --watch=false
```
