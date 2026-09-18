# Despliegue (Coolify)

- Coolify: http://192.168.100.143:8000 → proyecto **kawaiimandala** → entorno *production*.
- Build pack: **Docker Compose** (`docker-compose.yml` + `Dockerfile`), fuente GitHub App.
- Dominio: https://kawaiimandala.medio-digital.net (Cloudflare Tunnel → Traefik → `app:8080`).
- Servicios: `app` (nginx + php-fpm), `worker` (queue:work), `scheduler` (schedule:work), `mariadb` (11.4).
- Volúmenes persistentes: `kawaii_mariadb` (base de datos) y `kawaii_storage` (imágenes y PDFs).

## CI/CD
Cada push a `main` dispara el webhook de la GitHub App y Coolify reconstruye y despliega
automáticamente (Auto Deploy). El contenedor `app` ejecuta `migrate --force` al arrancar.

## Primer arranque
- Siembra los 22 flujos (`MandalaFlowSeeder`) si la tabla está vacía.
- Crea el administrador desde `ADMIN_EMAIL` / `ADMIN_PASSWORD` si no hay usuarios.

## Activepieces
Para activarlo: `ACTIVEPIECES_ENABLED=1` en las variables de Coolify y redeploy.
