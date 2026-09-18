# PROMPT PARA COWORK — Flujos de Activepieces para generar los mandalas del libro

> Antes de pegarlo: rellena los dos datos de la sección 5.2 (`APP_PUBLIC_URL` y `ACTIVEPIECES_SHARED_SECRET`) o bórralos si aún no los tienes. Inicia sesión tú mismo en Activepieces y deja creada la conexión de OpenAI.

## Rol y límites
Vas a trabajar **en mi navegador** dentro de Activepieces: https://activepieces.medio-digital.net/
- Si te pide iniciar sesión, **detente y avísame**; no escribas contraseñas ni claves de API.
- Si falta la conexión de OpenAI (Connections), **detente y dime** que debo crearla yo; no pegues claves.
- Trabaja **solo** dentro del proyecto/carpeta nuevo que crees. No edites ni borres flujos, conexiones o proyectos existentes.
- Verifica cada paso en pantalla antes de seguir. Si algo no coincide con lo descrito, dímelo en lugar de improvisar.

## Objetivo
Mi aplicación (Laravel, "Kawaii Mandala") crea libros de mandalas para colorear. Cada libro tiene un **animal** principal (gato, león, colibrí…) y **N mandalas (por defecto 22), uno por página**. Necesito **22 flujos, uno por página (Mandala 01 … Mandala 22)**. Cada flujo:
1. Recibe una petición web de mi app para generar **un** mandala.
2. Construye un prompt = estilo común + **estructura propia de esa página** + **el animal del libro**.
3. Genera la imagen con **OpenAI (gpt-image)**.
4. Envía la imagen (o el error) de vuelta a mi app por HTTP, que la guarda/reemplaza en la página correspondiente.

Los mismos 22 flujos sirven para todos los libros: lo único que cambia es el animal que llega en la petición.

## 1) Proyecto
Crea un proyecto/carpeta llamado **"Kawaii Mandala"** y trabaja dentro.

## 2) Flujo plantilla "Mandala 01"
Pasos, en este orden:

**Paso 1 — Trigger: Catch Webhook.** Debe responder 200 de inmediato (modo asíncrono por defecto; **no** uses la variante `/sync`). Cuerpo JSON que recibirás:
```json
{
  "book_uuid": "…", "position": 3, "flow_position": 3, "count": 22,
  "title": "…", "subtitle": "…",
  "animal_theme": "Capybara",
  "prompt": null,
  "style_profile": "kawaii_mandala_v1",
  "output": {"format":"png","width_px":2550,"height_px":2550,"provider_size":"1024x1024","background":"white","color_mode":"black_and_white"},
  "request_token": "…",
  "callback_url": "https://…/api/activepieces/books/UUID/mandalas/3",
  "callback_secret": "…"
}
```

**Paso 2 — Code (JavaScript) "build_prompt".** Entradas: `body` del trigger. Define una constante `STRUCTURE` con la estructura de **esta** página (tabla del apartado 4; usa el marcador `{animal}`). Salida `finalPrompt`:
```js
const BASE = "Black and white coloring book page, kawaii style mandala, clean uniform black outlines on a pure white background, no shading, no grey tones, no solid black fills, no text or letters, perfectly centered circular composition, generous empty white space inside every shape so it can be colored, high contrast line art, symmetrical, printable.";
const STRUCTURE = "…estructura de esta página con {animal}…";
const animal = String(body.animal_theme || "animal").trim();
const structure = STRUCTURE.replaceAll("{animal}", animal.toLowerCase());
const extra = body.prompt ? " Additional notes: " + body.prompt : "";
return { finalPrompt: `${BASE} Main subject: a cute kawaii ${animal.toLowerCase()}. Composition: ${structure}${extra}` };
```

**Paso 3 — OpenAI → Generate Image** (conexión OpenAI existente). Modelo `gpt-image-1` (o el gpt-image más reciente disponible), tamaño **1024x1024**, calidad alta, fondo opaco/blanco, formato PNG, prompt = `finalPrompt`. **No escales**: mi app escala a 2550×2550. Activa **"Continue on failure"** (y reintentos: 2 intentos) en este paso.

**Paso 4 — Router (2 ramas).**
- *Rama OK* (el paso 3 tuvo éxito y trae imagen): **HTTP → POST** a `{{trigger.body.callback_url}}` con cabeceras `Content-Type: application/json` y `X-Callback-Secret: {{trigger.body.callback_secret}}`; cuerpo:
  ```json
  {"request_token":"{{trigger.body.request_token}}","image_base64":"<PNG en base64 SIN prefijo data:>","prompt":"{{build_prompt.finalPrompt}}"}
  ```
  Si el paso de OpenAI devuelve un archivo/URL en vez de base64, convierte a base64 con un paso Code, o usa `"image_url"` solo si la URL es pública y accesible desde internet.
- *Rama error* (el paso 3 falló o no trae imagen): **HTTP → POST** al mismo `callback_url`, mismas cabeceras, cuerpo:
  ```json
  {"request_token":"{{trigger.body.request_token}}","error":"<mensaje real del error de OpenAI>","error_code":"openai_error"}
  ```
  (códigos: `openai_error`, `content_policy`, `empty_image`).

**Paso 5 (opcional) —** si el POST de callback falla, reintenta 2 veces; no hagas nada más (mi app marca timeout sola tras 10 min).

Respuesta esperada de mi app al callback: `200 {"ok":true,…}` · `401` secreto · `409` token distinto · `422` imagen/posición inválida.

## 3) Duplicar a 22 flujos
1. Prueba primero la plantilla (apartado 5).
2. Duplica el flujo 21 veces. Nombres exactos: **"Mandala 01" … "Mandala 22"**.
3. En cada copia cambia **solo** `STRUCTURE` en el paso 2 por la de su número.
4. **Publica/activa** cada flujo (necesito la URL de webhook de **producción**, no la de test).

## 4) Estructura por página (`{animal}` = animal del libro)
| # | STRUCTURE |
|---|---|
| 01 | A cute {animal} face in the exact center, surrounded by three concentric rings: petals, small dots, and scalloped edge. |
| 02 | A full-body sitting {animal} inside a central circle, framed by a ring of flowers and leaves. |
| 03 | A {animal} head in the center with a ring of tiny hearts and stars, eight-fold radial symmetry. |
| 04 | A central flower with eight mini {animal} faces evenly spaced around it, connected by two rings of leaves. |
| 05 | A {animal} holding a flower, with sunburst rays behind it and a scalloped decorative border. |
| 06 | A large lotus flower with a {animal} peeking from behind the petals, outer ring of geometric triangles. |
| 07 | A {animal} in the center surrounded by a ring of paw/footprint motifs and swirling vines. |
| 08 | A sleeping {animal} under a crescent moon, ring of stars and clouds, intricate zentangle-style patterns in the outer rings. |
| 09 | A {animal} sitting among mushrooms and wildflowers inside a circular garden mandala. |
| 10 | A kaleidoscope-style twelve-fold symmetrical mandala built from stylized {animal} silhouettes and petals. |
| 11 | A {animal} face surrounded by a ring of paisley shapes and small floral motifs. |
| 12 | A {animal} in the center with six butterflies evenly spaced around it inside petal rings. |
| 13 | A {animal} wearing a flower crown, framed by layered petal rings in alternating sizes. |
| 14 | A smiling {animal} inside a sun mandala with long alternating rays and small dots. |
| 15 | A {animal} with cupcakes, macarons and sweets arranged in a ring, scalloped frames, kawaii dessert theme. |
| 16 | A {animal} inside a circular wave pattern with shells, bubbles and small fish in concentric rings. |
| 17 | A {animal} surrounded by autumn leaves, acorns and small mushrooms arranged in rings. |
| 18 | A {animal} face in the center of a snowflake-like radial geometric mandala with crisp symmetrical patterns. |
| 19 | A {animal} on a small cloud in the center, rainbow arcs and puffy clouds forming concentric rings. |
| 20 | A {animal} hugging a big heart, framed by a floral mandala wreath with small hearts. |
| 21 | A {animal} in the center with feather and wing patterns radiating outward in overlapping rings. |
| 22 | A grand finale: highly detailed multi-ring mandala with a {animal} face at the center and many tiny {animal}-themed icons, flowers and geometric patterns in every ring. |

## 5) Pruebas
1. Con la plantilla, usa "Test flow" con un cuerpo de ejemplo (animal `Capybara`, posición 1) y verifica que el paso de OpenAI devuelve una imagen de line-art en blanco y negro sobre fondo blanco.
2. Para probar el callback necesito una URL pública de mi app. Datos (bórralos si no los tienes):
   - `APP_PUBLIC_URL = ___________`
   - `ACTIVEPIECES_SHARED_SECRET = ___________`

   Si los tienes, usa `callback_url = APP_PUBLIC_URL/api/activepieces/books/00000000-0000-0000-0000-000000000000/mandalas/1` con la cabecera `X-Callback-Secret` = el secreto y confirma que la respuesta es `404 Book not found` (demuestra que la app es alcanzable y el secreto es válido). Si no los tienes, **omite** este paso y dime que falta.
3. Prueba también la rama de error (por ejemplo, forzando un fallo en el paso de OpenAI) y confirma que se envía el POST con `error`.
4. Repite una prueba rápida en dos flujos duplicados (p. ej. 07 y 22) para confirmar que cada uno usa su `STRUCTURE`.

## 6) Entrega
Devuélveme una tabla:

| Mandala | Nombre del flujo | URL webhook (producción) | Publicado (sí/no) | Prueba OK (sí/no) |
|---|---|---|---|---|

y una lista de cualquier problema (conexión OpenAI faltante, error de un paso, límites del plan…).
Si ya estoy conectado a mi app (`/settings/flows`) en el navegador, pega tú cada URL en su fila y guarda; si no, deja solo la tabla y **no** inicies sesión por mí.

## No hagas
No tocar otros proyectos · no crear ni pegar credenciales · no cambiar el modelo de imagen ni el tamaño sin avisarme · no usar URLs `/sync` · no borrar flujos existentes.
