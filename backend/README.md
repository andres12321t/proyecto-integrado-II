# Backend de la API de películas

## Desplegar en Render

El backend se puede ejecutar como servicio Docker en Render y conectarse a una instancia MySQL externa. Render no ofrece MySQL administrado. Una opción compatible con MySQL es [TiDB Cloud Starter](https://docs.pingcap.com/tidbcloud/select-cluster-tier/#starter), que no requiere tarjeta si mantienes el gasto en cero. La cuota gratuita incluye 5 GiB de almacenamiento por filas y 50 millones de unidades de solicitud al mes; al agotarla, las conexiones nuevas se restringen hasta el siguiente mes. Revisa sus [límites y cuotas](https://www.pingcap.com/tidb-cloud-starter-pricing-details/) antes de crear la instancia.

1. Sube el proyecto a un repositorio de GitHub y crea en Render un **Web Service** conectado a ese repositorio.
2. Selecciona **Docker** como entorno y establece `backend` como directorio raíz. Render utilizará el [`Dockerfile`](./Dockerfile).
3. En TiDB Cloud crea una instancia **Starter** con compatibilidad **MySQL** y gasto máximo de `0`. Exporta la base local `iud_peliculas_db_ea1` desde phpMyAdmin e impórtala en TiDB con el cliente MySQL; usa los datos de conexión **Public** que muestra TiDB. La conexión pública requiere TLS.
4. En **Environment**, configura `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS` con los datos de TiDB. Para TiDB Starter, el puerto suele ser `4000` y el usuario incluye el prefijo de instancia. `DB_SSL_CA` se establece en el blueprint a `/etc/ssl/certs/ca-certificates.crt`; la imagen instala el almacén de certificados y PDO verifica el certificado del servidor.
5. Después de desplegar el frontend, configura `FRONTEND_ORIGIN` con su URL completa, por ejemplo `https://mi-frontend.onrender.com` (sin `/` al final).
6. Comprueba el servicio en `/health`; los endpoints estarán bajo `/api`, por ejemplo `/api/medias`.

El servicio escucha el puerto asignado por Render. La configuración CORS solo permite el origen indicado en `FRONTEND_ORIGIN`.
TiDB también requiere autorizar las IP de cliente. Después de crear el servicio API, copia todos los rangos de **Connect → Outbound** en Render y añádelos a la lista de acceso del endpoint público de TiDB.

## Conexión a MySQL

La conexión está implementada con PDO en `config/database.php`. La base de datos predeterminada es `iud_peliculas_db_ea1`; los valores de conexión se pueden configurar mediante variables de entorno:

| Variable | Predeterminado |
| --- | --- |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | `iud_peliculas_db_ea1` |
| `DB_USER` | `root` |
| `DB_PASS` | Vacío |
| `DB_SSL_CA` | Sin configurar; establece la ruta al certificado CA si el proveedor exige validar TLS |

El usuario y contraseña predeterminados corresponden a una instalación local común de XAMPP. Si tu servidor usa otros datos, configura las variables de entorno antes de ejecutar PHP. No guardes contraseñas reales en el código ni las compartas en el chat.

### Probar la conexión en PowerShell

Con MySQL iniciado, abre una terminal nueva de VS Code en la carpeta `backend`, configura las variables para la sesión actual y ejecuta la prueba:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_NAME = 'iud_peliculas_db_ea1'
$env:DB_USER = 'root'
$env:DB_PASS = ''
& 'C:\xampp\php\php.exe' .\test_connection.php
```

Si aparece el error «php no se reconoce», usa la ruta completa al ejecutable como en el comando anterior. Si XAMPP está instalado en otra carpeta, reemplaza `C:\xampp\php\php.exe` por la ruta correspondiente. La prueba consulta el nombre de la base seleccionada; PHP debe tener habilitado el controlador `pdo_mysql`.

## API REST: películas

El recurso `/api/medias` implementa operaciones CRUD sobre la tabla `medias`. La tabla ya existe en `iud_peliculas_db_ea1`; el endpoint está separado en `public/medias.php`, y `public/index.php` enruta las solicitudes de todos los recursos. Inicia el servidor de desarrollo desde la carpeta `backend`:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t public public/index.php
```

Deja esa terminal abierta mientras usas la API. La base de la URL es `http://127.0.0.1:8000/api/medias`.

| Método | Ruta | Acción |
| --- | --- | --- |
| `GET` | `/api/medias` | Listar películas |
| `GET` | `/api/medias/{id}` | Consultar una película |
| `POST` | `/api/medias` | Crear una película |
| `PUT` | `/api/medias/{id}` | Reemplazar todos los campos editables |
| `PATCH` | `/api/medias/{id}` | Actualizar solo los campos enviados |
| `DELETE` | `/api/medias/{id}` | Eliminar una película |

Los cuerpos de `POST`, `PUT` y `PATCH` deben enviarse como JSON con `Content-Type: application/json`. La creación y el reemplazo requieren todos los campos:

```json
{
  "serial": "PEL-001",
  "titulo": "Mi película",
  "sinopsis": "Descripción de la película.",
  "url_pelicula": "https://ejemplo.com/pelicula",
  "imagen_portada": "https://ejemplo.com/portada.jpg",
  "ano_estreno": 2024,
  "genero_id": 1,
  "director_id": 1,
  "productora_id": 1,
  "tipo_id": 1
}
```

Los identificadores de género, director, productora y tipo deben existir en sus tablas. Además, al crear una producción o cambiar sus referencias con `PUT`/`PATCH`, el género, director y productora seleccionados deben tener `estado = "Activo"`; el tipo solo debe existir, porque esa tabla no tiene estado. Las respuestas exitosas contienen los datos en `data`; los errores devuelven un objeto `error` con un mensaje. Las películas incluyen también los nombres de sus relaciones. `PUT` reemplaza el recurso y exige todos los campos; `PATCH` permite enviar solo los que cambian.

Para probar la regla de referencias activas, inicia el servidor en una terminal de VS Code y ejecuta desde otra terminal `.\test_medias_referencias_activas.cmd`. La prueba crea referencias y una producción temporales, comprueba que las referencias inactivas se rechacen con HTTP `422` en `POST`, `PUT` y `PATCH`, y limpia los registros temporales.

## API REST: géneros

El recurso `/api/generos` implementa operaciones CRUD sobre la tabla `generos`:

| Método | Ruta | Acción |
| --- | --- | --- |
| `GET` | `/api/generos` | Listar géneros |
| `GET` | `/api/generos/{id}` | Consultar un género |
| `POST` | `/api/generos` | Crear un género |
| `PUT` | `/api/generos/{id}` | Reemplazar todos los campos |
| `PATCH` | `/api/generos/{id}` | Actualizar solo los campos enviados |
| `DELETE` | `/api/generos/{id}` | Eliminar un género |

Ejemplo de cuerpo JSON para crear:

```json
{
  "nombre": "Comedia",
  "estado": "Activo",
  "descripcion": "Historias humorísticas."
}
```

`nombre` es obligatorio y único, con un máximo de 100 caracteres. `estado` acepta `Activo` o `Inactivo` y, si se omite al crear, su valor predeterminado es `Activo`. `descripcion` acepta texto o `null` y puede omitirse al crear. `PUT` exige los tres campos; `PATCH` recibe uno o más. Un nombre duplicado produce HTTP `409`. No se puede eliminar un género que esté siendo utilizado por películas o series.

Para probar el CRUD con Postman, importa `postman/Generos.postman_collection.json` y ejecuta las solicitudes en orden. Para probar desde otra terminal de VS Code, con el servidor anterior iniciado, ejecuta el lanzador:

```powershell
.\test_generos.cmd
```

El lanzador ejecuta las pruebas con una excepción de política limitada a ese proceso; no cambia la política permanente de PowerShell. El script prueba listar, crear, consultar, duplicados, actualizar con `PUT` y `PATCH`, validar estado y eliminar un género temporal. Limpia el registro temporal incluso si alguna comprobación falla. También puedes iniciarlo directamente con `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\test_generos.ps1`.

## API REST: tipos

El recurso `/api/tipos` implementa operaciones CRUD sobre la tabla `tipos`:

| Método | Ruta | Acción |
| --- | --- | --- |
| `GET` | `/api/tipos` | Listar tipos |
| `GET` | `/api/tipos/{id}` | Consultar un tipo |
| `POST` | `/api/tipos` | Crear un tipo |
| `PUT` | `/api/tipos/{id}` | Reemplazar todos los campos |
| `PATCH` | `/api/tipos/{id}` | Actualizar solo los campos enviados |
| `DELETE` | `/api/tipos/{id}` | Eliminar un tipo |

`nombre` es obligatorio, único y admite hasta 50 caracteres. `descripcion` es opcional y acepta texto o `null`. `PUT` exige ambos campos; `PATCH` permite actualizar uno. No se puede eliminar un tipo asociado a películas o series.

Importa `postman/Tipos.postman_collection.json` en Postman y ejecuta sus solicitudes en orden. Desde una segunda terminal de VS Code también puedes ejecutar `.\test_tipos.cmd`; el script prueba las operaciones CRUD, nombres duplicados y la protección de referencias, y elimina los registros temporales al finalizar.

## API REST: productoras

El recurso `/api/productoras` implementa operaciones CRUD sobre la tabla `productoras`:

| Método | Ruta | Acción |
| --- | --- | --- |
| `GET` | `/api/productoras` | Listar productoras |
| `GET` | `/api/productoras/{id}` | Consultar una productora |
| `POST` | `/api/productoras` | Crear una productora |
| `PUT` | `/api/productoras/{id}` | Reemplazar todos los campos |
| `PATCH` | `/api/productoras/{id}` | Actualizar solo los campos enviados |
| `DELETE` | `/api/productoras/{id}` | Eliminar una productora |

`nombre` es obligatorio, único y admite hasta 100 caracteres. `slogan` es opcional y admite hasta 255 caracteres. `descripcion` es opcional y admite texto o `null`. `estado` acepta `Activo` o `Inactivo` y, si se omite al crear, su valor predeterminado es `Activo`. `PUT` requiere los cuatro campos; `PATCH` solo requiere los que se modifican. No se puede eliminar una productora relacionada con películas o series.

Importa `postman/Productoras.postman_collection.json` en Postman y ejecuta sus solicitudes en orden. Desde una segunda terminal de VS Code también puedes ejecutar `.\test_productoras.cmd`; el script prueba las operaciones CRUD, los nombres duplicados y los valores predeterminados, y elimina los registros temporales al finalizar.

## API REST: directores

El recurso `/api/directores` implementa operaciones CRUD sobre la tabla `directores`:

| Método | Ruta | Acción |
| --- | --- | --- |
| `GET` | `/api/directores` | Listar directores |
| `GET` | `/api/directores/{id}` | Consultar un director |
| `POST` | `/api/directores` | Crear un director |
| `PUT` | `/api/directores/{id}` | Reemplazar todos los campos |
| `PATCH` | `/api/directores/{id}` | Actualizar solo los campos enviados |
| `DELETE` | `/api/directores/{id}` | Eliminar un director |

La tabla contiene los campos `nombres` y `estado`. `nombres` es obligatorio, admite hasta 150 caracteres y no puede estar vacío. `estado` acepta `Activo` o `Inactivo` y, si se omite al crear, su valor predeterminado es `Activo`. `PUT` exige ambos campos; `PATCH` permite actualizar uno. Los nombres no son únicos según el esquema actual de la base. No se puede eliminar un director utilizado por películas o series.

Para probar el CRUD, importa `postman/Directores.postman_collection.json` y ejecuta sus solicitudes en orden. Desde una segunda terminal de VS Code puedes ejecutar `.\test_directores.cmd`; el script realiza pruebas CRUD con un registro temporal y lo elimina al terminar, sin cambiar la política permanente de PowerShell.

### Probar el CRUD con Postman

1. Inicia el servidor PHP con el comando anterior y deja abierta esa terminal.
2. En Postman, importa `postman/Peliculas.postman_collection.json` (botón **Import**).
3. Ejecuta las solicitudes en orden: **1. Listar**, **2. Crear**, **3. Consultar**, **4. PUT** y **5. PATCH**. La solicitud de creación guarda automáticamente el ID para las siguientes.
4. Ejecuta **6. Eliminar** únicamente si quieres borrar permanentemente el registro de prueba que se creó.

La colección usa `http://127.0.0.1:8000` y los IDs de referencia 1 y 2 que existen en la base de datos actual. Puedes cambiar `base_url` en las variables de la colección si inicias el servidor en otro puerto.

### Ejecutar pruebas CRUD desde la terminal de VS Code

Abre dos terminales en VS Code. En la primera, desde la carpeta `backend`, inicia el servidor y déjalo abierto:

```powershell
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t public public/index.php
```

En la segunda, también desde `backend`, ejecuta el lanzador:

```powershell
.\test_crud.cmd
```

El lanzador ejecuta el script de pruebas con una excepción de política limitada a ese proceso; no cambia la política permanente de PowerShell. Verifica listar, crear, consultar por ID, reemplazar con PUT, actualizar con PATCH y eliminar. Usa referencias existentes, imprime el resultado de cada comprobación y elimina el registro temporal incluso si falla una prueba. También puedes invocarlo directamente con:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\test_crud.ps1
```
