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

KAWAII_AUTO_UPSCALE=true            # escala a 2550 px las imágenes de Activepieces menores

ACTIVEPIECES_ENABLED=0
ACTIVEPIECES_BASE_URL=https://activepieces.medio-digital.net
ACTIVEPIECES_MAX_CONCURRENT=1       # páginas generándose a la vez (1–5); más de 1 puede dar 429 de OpenAI
APP_PUBLIC_URL=                     # URL pública HTTPS de esta app, alcanzable desde Activepieces
QUEUE_CONNECTION=database           # el envío al webhook va en cola: hace falta un worker
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

Para `N` mandalas (por defecto **22**; aceptado de 1 a 22, uno por flujo de Activepieces). `BookPaginationService` es la única fuente de verdad:

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

Activepieces es solo una integración por webhook, encapsulada en `App\Services\ActivepiecesClient`. Si está apagado o caído se puede seguir subiendo imágenes a mano y exportando.

**Un flujo por página.** Cada posición de mandala (1…N) tiene su propio flujo en Activepieces con su propia estructura de mandala; el flujo recibe el **animal** del libro y genera esa página. Los mismos flujos sirven para todos los libros. El prompt para que Cowork cree los 22 flujos está en [`docs/PROMPT_COWORK_ACTIVEPIECES.md`](docs/PROMPT_COWORK_ACTIVEPIECES.md).

### Configuración
1. `.env`: `ACTIVEPIECES_ENABLED=1`, `ACTIVEPIECES_BASE_URL` y `APP_PUBLIC_URL` (URL pública **HTTPS** de esta app, alcanzable **desde el servidor de Activepieces**; con `localhost` hace falta un túnel como cloudflared/ngrok o desplegar la app). No hay secreto en `.env`: cada solicitud genera el suyo.
2. Cargar los 22 flujos («Mandala 01…22», carpeta *Kawaii Mandala*): `php artisan db:seed --class=MandalaFlowSeeder` (idempotente; vuelve a poner nombre, `flow_id` y URL de cada posición, pero respeta los flujos desactivados). Después se editan en **Configuración → Flujos** (`/settings/flows`); cada fila se puede desactivar. La página avisa si `APP_PUBLIC_URL` parece local o cuántos flujos faltan.
3. **Worker de cola** (el POST al webhook va en el job `SendMandalaRequest`): `php artisan queue:work --tries=1 --timeout=60` (Supervisor en producción).
4. **Scheduler** (marca `timeout` las solicitudes sin respuesta): cron `* * * * * php artisan schedule:run`, que ejecuta `activepieces:expire-stale` cada minuto.
5. En la ficha del libro: **Generar con AI** por slot (o *Regenerar/Reintentar*) y **Generar los pendientes por turno** (cola: se mantienen `ACTIVEPIECES_MAX_CONCURRENT` mandalas en curso y, al recibir cada imagen, se solicita el siguiente).

Cada libro admite hasta **22** mandalas porque hay un flujo por posición: la posición N usa siempre el webhook del «Mandala N».

### Estados de cada página
`pending → requested → done | failed | timeout`. Se guardan `error` / `error_code` (`openai_error`, `content_policy`, `empty_image`, `dispatch_error`, `timeout`…), la fecha de solicitud y de respuesta, el número de intento y el prompt final que usó el flujo (`final_prompt`; `prompt` es solo para notas extra del usuario, máx. 30 000 caracteres). Reintentar genera un token y secreto nuevos.

### Mensajes y errores
- Sin enlace para una página: el botón queda deshabilitado con «Sin enlace de flujo» (y un aviso con enlace a la configuración); la cola se niega a arrancar y lista las páginas sin enlace.
- Webhook con error HTTP o inalcanzable → el slot queda en *error* (`dispatch_error`) con el motivo (más intento N) y se registra en el log.
- Sin respuesta tras 10 min (`stale_after_minutes`) → el slot pasa a *timeout* y la cola se detiene; se puede reintentar. Si la imagen de esa misma solicitud llega tarde, se acepta.
- Error informado por el flujo (`error` y opcional `error_code`, p. ej. `content_policy`) → *error* con `[código] mensaje` y cola detenida.
- Imagen inválida recibida (base64 roto, no PNG…) → 422 y el slot queda en *error*.

### Contrato con el flujo
Payload que recibe el webhook de la página (POST JSON, el flujo responde `200 {}` al instante):

```json
{
  "book_uuid": "…", "position": 3, "flow_position": 3, "count": 22,
  "title": "…", "subtitle": "…", "animal_theme": "Capybara", "prompt": null,
  "style_profile": "kawaii_mandala_v1",
  "output": {"format":"png","width_px":2550,"height_px":2550,"provider_size":"1024x1024","background":"white","color_mode":"black_and_white"},
  "request_token": "…",
  "callback_url": "https://APP/api/activepieces/books/UUID/mandalas/3",
  "callback_secret": "…"
}
```

El flujo solo usa `animal_theme`, `prompt` (notas extra), `request_token`, `callback_url` y `callback_secret`. Al terminar hace `POST callback_url` con el secreto (cabecera `X-Callback-Secret`, `Authorization: Bearer …` o campo `callback_secret`):

```json
{ "request_token": "…", "image_base64": "<PNG 1024×1024 en base64, sin prefijo data:>", "prompt": "<prompt final>" }
```

(En caso de fallo: `{ "request_token": "…", "error": "motivo", "error_code": "openai_error" }`. `image_url` sigue aceptándose como alternativa a `image_base64`.)

Respuestas: `200` guardada (con `next_requested` si la cola pidió el siguiente; `duplicate: true` si ese token ya se había respondido y no se reprocesa) · `401` secreto incorrecto · `404` libro inexistente · `409` `request_token` distinto al vigente · `422` posición fuera de rango, base64 inválido o imagen que no es PNG · `503` integración apagada. Los errores de validación nunca son 5xx: el flujo solo reintenta el callback ante 5xx o errores de red. Solo se guarda el hash (sha256) del secreto; la ruta tiene rate limit (120/min).

Almacenamiento: `mandalas/001.png` (escalada a 2550) y `mandalas/originals/001.png` (la que devolvió el flujo, ~1024).

### Requisitos del servidor
Cada callback pesa ~1,7 MB (base64 de ~1,2 MB de PNG) y el escalado a 2550² usa memoria: Nginx `client_max_body_size` ≥ 10M; PHP `post_max_size` y `upload_max_filesize` ≥ 10M y `memory_limit` ≥ 512M; ningún proxy (Coolify/Traefik/Cloudflare) con límite de cuerpo inferior a 10 MB.

### Escalado a 300 DPI
Los modelos de imagen devuelven ~1024 px. Al recibir una imagen de Activepieces menor de 2550 px (y `KAWAII_AUTO_UPSCALE=true`) la app la centra en un lienzo cuadrado blanco, la escala a **2550×2550 px** y limpia el trazo (escala de grises de alto contraste) para que pase la validación de 300 DPI. Las subidas manuales nunca se modifican. Esto no añade detalle: es adecuado para colorear, pero no equivale a un escalado con IA.

## Tests

```bash
php artisan test
```

Los tests usan SQLite en memoria y un disco falso, sin tocar MySQL ni `storage`. Cubren: paginación (22 y 44 mandalas, mandalas siempre en impares, blancas siempre en pares), validaciones de libro, subida de mandalas, bloqueo de exportación (faltan mandalas / textos / baja resolución), estructura del PDF el seeder de los 22 flujos y el envío/callback de Activepieces (job en cola, secreto por solicitud, posición, imagen, token, idempotencia, timeout, concurrencia).
