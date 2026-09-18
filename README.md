# Kawaii Mandala — generador de interiores KDP

Aplicación web (Laravel 13) para crear libros de mandalas y exportar el **interior** listo para Amazon KDP: PDF de **8.5 × 8.5 in**, cada mandala en página derecha y su reverso en blanco.

Flujo: crear libro → cargar (o generar con Activepieces) los mandalas → revisar la estructura de páginas → exportar PDF.
Fuera de alcance: cubierta KDP, lomo, pagos, multiusuario.

> El documento de requisitos original proponía Symfony 7.4; se implementó en **Laravel 13 / PHP 8.3+** (probado con PHP 8.5). Las reglas del libro no cambian.

## Requisitos

- PHP ≥ 8.3 con extensiones `gd`, `fileinfo`, `mbstring`, `pdo_mysql`, `curl`
- Composer 2
- MySQL/MariaDB (Laragon incluye MySQL)

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Configuración `.env`

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kawaiimandala
DB_USERNAME=root
DB_PASSWORD=

KAWAII_DEFAULT_AUTHOR="Marvin Baptista"
KAWAII_DEFAULT_MANDALA_COUNT=22
KAWAII_ALLOW_LOW_RES_EXPORT=false   # solo desarrollo: permite exportar con imágenes < 2250 px

ACTIVEPIECES_ENABLED=0
ACTIVEPIECES_WEBHOOK_URL=
ACTIVEPIECES_SHARED_SECRET=         # mínimo 16 caracteres
APP_PUBLIC_URL=                     # URL pública de esta app, alcanzable desde Activepieces
```

Los demás valores (tamaño de página, márgenes, mínimos de resolución, límites) están en `config/kawaii.php`.

## Base de datos, admin y servidor

```bash
# crear la base (Laragon: HeidiSQL, o desde la consola de MySQL)
mysql -uroot -e "CREATE DATABASE kawaiimandala CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan migrate
php artisan kawaii:create-admin tu@email.com --name="Tu Nombre"   # pide la contraseña
php artisan serve                                                   # http://localhost:8000
```

Todas las rutas `/books*` requieren iniciar sesión (un único administrador).

Datos de prueba (**solo desarrollo**): `php artisan kawaii:demo-book` crea «Cute Capybara Mandalas» con 22 imágenes placeholder marcadas *NOT FOR PRODUCTION* (`--count=44`, `--empty` disponibles).

## Dónde se guardan los archivos

Disco `local` de Laravel (privado, nunca servido directamente; se accede mediante rutas autenticadas):

| Qué | Ruta |
|---|---|
| Imágenes de mandalas | `storage/app/private/books/{UUID}/mandalas/001.png` … |
| PDFs exportados | `storage/app/private/books/{UUID}/exports/{slug-titulo}-interior-8.5x8.5.pdf` |

Los nombres de las imágenes son internos (`001.png`, `002.jpg`…); el nombre original del archivo nunca se usa como ruta. Se valida el tipo real (PNG/JPG), dimensiones y tamaño (máx. 30 MB).

## Reglas de imagen

- PNG (preferido) o JPG, B/N sobre fondo blanco. Objetivo **2550 × 2550 px**; mínimo **2250 px** por lado (7.5 in a 300 DPI).
- Debajo del mínimo se muestra una advertencia por slot y la exportación queda **bloqueada** (salvo `KAWAII_ALLOW_LOW_RES_EXPORT=true`).
- Se conserva la relación de aspecto y se centra en el área útil de 190.5 × 190.5 mm. Las transparencias se aplanan sobre blanco.

## Book Pagination Rules

Para `N` mandalas (por defecto **22**; aceptado de 1 a 60). `BookPaginationService` es la única fuente de verdad:

```text
página 1                   = título interior            (derecha)
página 2                   = introducción               (izquierda)
mandala(i)                 = 3 + 2·(i − 1)              (siempre impar → derecha / recto)
en blanco tras mandala(i)  = mandala(i) + 1             (siempre par → izquierda / verso)
página del creador         = 2N + 3
página técnica en blanco   = 2N + 4                     (= total de páginas)
total de páginas           = 2N + 4
```

- Impar = `RIGHT / RECTO`, par = `LEFT / VERSO`.
- Con **22 mandalas → 48 páginas**: mandalas en 3, 5 … 45; blancas 4, 6 … 46; creador 47; blanca técnica 48.
- Con 44 mandalas → 92 páginas (mandala 44 en la 89).
- Las páginas de mandala solo contienen la imagen (sin encabezado, pie ni número). Las páginas en blanco no dibujan nada (solo la inicialización interna de mPDF, sin texto ni marcas).
- El PDF mide exactamente 215.9 × 215.9 mm (612 × 612 pt), sin sangrado ni marcas de corte, márgenes de 12.7 mm. Fuente: Roboto (local en `resources/fonts`).
- Si un texto (título, introducción, página del creador) no cabe en una página, se reduce el cuerpo de letra y, si aun así no cabe, la exportación se bloquea con un aviso (nunca se agregan páginas).

La vista **Estructura de páginas** (`/books/{uuid}/pages`) muestra el plan completo.

## Activepieces (opcional)

Activepieces solo es una integración por webhook, encapsulada en `App\Services\ActivepiecesClient`. Si está apagado o caído se pueden seguir subiendo imágenes a mano y exportando.

**Un flujo de Activepieces por mandala**: Laravel solicita un mandala concreto, el flujo genera la imagen y la devuelve por callback; Laravel la valida y la guarda en su slot.

1. Crea un flujo con trigger *Catch Webhook* y copia su URL a `ACTIVEPIECES_WEBHOOK_URL`.
2. Define `ACTIVEPIECES_SHARED_SECRET` (≥ 16 caracteres) y `APP_PUBLIC_URL`; pon `ACTIVEPIECES_ENABLED=1`.
3. En la ficha del libro aparecen **Generar con AI** (por slot, o *Regenerar/Reintentar*) y **Generar los pendientes por turno** (cola: se solicita un mandala, al recibir su imagen se solicita el siguiente; «Detener cola» la corta; un error también la detiene).

Payload que recibe el flujo (POST JSON):

```json
{
  "book_uuid": "…", "position": 3, "count": 22,
  "title": "…", "subtitle": "…", "animal_theme": "capybara", "prompt": null,
  "style_profile": "kawaii_mandala_v1",
  "output": {"format":"png","width_px":2550,"height_px":2550,"background":"white","color_mode":"black_and_white"},
  "request_token": "…",
  "callback_url": "https://APP/api/activepieces/books/UUID/mandalas/3",
  "callback_secret": "…"
}
```

El flujo debe responder rápido al webhook (la imagen **no** se espera en esa petición) y, al terminar, hacer `POST callback_url` con el secreto (cabecera `X-Callback-Secret`, `Authorization: Bearer …` o campo `callback_secret`) y:

```json
{ "request_token": "…", "image_url": "https://…/mandala.png", "prompt": "…" }
```

(`image_base64` es una alternativa a `image_url`; en caso de fallo: `{ "request_token": "…", "error": "motivo" }`.)

Respuestas: `401` secreto incorrecto · `503` integración apagada/sin secreto · `422` posición fuera de rango o imagen inválida · `409` `request_token` que no corresponde a la solicitud vigente · `200` guardada (incluye `next_requested` si la cola pidió el siguiente).

Si un mandala queda «solicitado» más de 10 minutos (`stale_after_minutes`) se puede volver a solicitar.

## Tests

```bash
php artisan test
```

Los tests usan SQLite en memoria y un disco falso, sin tocar MySQL ni `storage`. Cubren: paginación (22 y 44 mandalas, mandalas siempre en impares, blancas siempre en pares), validaciones de libro, subida de mandalas, bloqueo de exportación (faltan mandalas / textos / baja resolución), estructura del PDF y el callback de Activepieces (secreto, posición, imagen, token, cola).
