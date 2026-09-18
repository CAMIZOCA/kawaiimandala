#!/bin/sh
set -e

cd /var/www/html

ROLE="${CONTAINER_ROLE:-app}"
echo "[entrypoint] rol: ${ROLE}"

if [ -z "${APP_KEY}" ]; then
    echo "[entrypoint] ERROR: APP_KEY no esta definida. Generala con 'php artisan key:generate --show'." >&2
    exit 1
fi

# El volumen persistente se monta sobre storage/ y oculta el arbol de la imagen.
mkdir -p \
    storage/app/public \
    storage/app/private/books \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# composer install corrio con --no-scripts: el manifiesto de paquetes se genera aqui.
php artisan package:discover --ansi

BOOT='require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'

wait_for_database() {
    i=1
    while [ "$i" -le 30 ]; do
        if php -r "${BOOT} Illuminate\Support\Facades\DB::connection()->getPdo();" >/dev/null 2>&1; then
            return 0
        fi
        echo "[entrypoint] esperando a la base de datos (${i}/30)..."
        i=$((i + 1))
        sleep 2
    done
    echo "[entrypoint] ERROR: la base de datos no respondio." >&2
    return 1
}

wait_for_database

if [ "${ROLE}" = "app" ]; then
    php artisan migrate --force --ansi

    # DatabaseSeeder usa factories (faker es dependencia de desarrollo), asi que
    # solo se siembran los 22 flujos, y solo en una base recien creada.
    FLOWS=$(php -r "${BOOT} echo Illuminate\Support\Facades\DB::table('mandala_flows')->count();")
    if [ "${FLOWS}" = "0" ]; then
        echo "[entrypoint] sembrando los flujos de Activepieces"
        php artisan db:seed --class=MandalaFlowSeeder --force --ansi
    fi

    # Administrador inicial opcional desde variables de entorno.
    USERS=$(php -r "${BOOT} echo Illuminate\Support\Facades\DB::table('users')->count();")
    if [ "${USERS}" = "0" ] && [ -n "${ADMIN_EMAIL}" ] && [ -n "${ADMIN_PASSWORD}" ]; then
        php artisan kawaii:create-admin "${ADMIN_EMAIL}" --name="${ADMIN_NAME:-Admin}" --password="${ADMIN_PASSWORD}" --ansi
    fi
fi

php artisan config:cache --ansi
php artisan route:cache --ansi
php artisan event:cache --ansi
php artisan view:cache --ansi

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
