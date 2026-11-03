#!/usr/bin/env bash
# Run on the EC2 host from the repo root: ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

git pull --ff-only
docker compose -f compose.prod.yml up -d --build
docker image prune -f >/dev/null

echo "Waiting for app health..."
for i in $(seq 1 40); do
  if curl -fsS http://127.0.0.1/health >/dev/null 2>&1; then
    break
  fi
  sleep 3
done

# TEI downloads the model on first start; wait until it serves before embedding.
echo "Waiting for embeddings service..."
for i in $(seq 1 100); do
  if docker compose -f compose.prod.yml exec -T app \
      php -r 'exit(@file_get_contents("http://tei/health") === false ? 1 : 0);' 2>/dev/null; then
    break
  fi
  sleep 3
done

# Embeds only products without a vector (no-op after the first run).
docker compose -f compose.prod.yml exec -T -u www-data app php artisan catalog:embed
docker compose -f compose.prod.yml ps
