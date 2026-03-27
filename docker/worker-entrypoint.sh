#!/usr/bin/env sh
set -eu

cd /app

if [ "${TMC_WORKER_INIT_DB:-0}" = "1" ]; then
  echo "[worker-entrypoint] initializing database before worker start"
  php /app/init_db.php
fi

echo "[worker-entrypoint] starting dedicated tick worker"
exec php /app/worker/tick_worker.php
