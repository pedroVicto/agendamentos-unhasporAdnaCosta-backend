#!/bin/sh
set -e

# Plataformas de deploy (Railway, Render, Fly, Cloud Run) injetam a porta
# em $PORT e esperam que o container obedeça. Local, cai no 80.
export PORT="${PORT:-80}"

# Só ${PORT} é substituído — as variáveis do nginx ($uri, $document_root...)
# precisam chegar intactas no arquivo final.
envsubst '${PORT}' \
    < /etc/nginx/templates/default.conf.template \
    > /etc/nginx/conf.d/default.conf

# Migrações são opt-in: RUN_MIGRATIONS=1 no deploy roda o phinx antes de
# abrir a porta. Sem isso o container só sobe a aplicação.
if [ "${RUN_MIGRATIONS}" = "1" ]; then
    echo "[entrypoint] rodando migrações..."
    php /var/www/html/vendor/bin/phinx migrate -c /var/www/html/phinx.php
fi

exec "$@"
