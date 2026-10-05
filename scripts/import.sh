#!/usr/bin/env bash
# =====================================================================
# Import (ulang) workflow dari folder n8n/workflows ke n8n, lalu publish.
#   ./scripts/import.sh                    # workflow saja
#   ./scripts/import.sh --with-credentials # + credential dari .env (menimpa yang ada di n8n)
#
# PERHATIAN: workflow dengan ID yang sama DITIMPA. Kalau Anda sudah mengedit
# workflow di n8n, jalankan ./scripts/export.sh dulu supaya perubahan tidak hilang.
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

WORKFLOW_IDS="seoToolScrape001 seoToolSearch001 seoToolGetPage01 seoToolSites001 seoToolGSC001 seoWfIngestion01 seoWfChatAgent01"

if [ "${1:-}" = "--with-credentials" ]; then
  echo "🔑 Import credential (Postgres, Ollama, Gemini) dari .env ..."
  trap 'rm -f n8n/.credentials.json' EXIT
  docker compose run --rm --no-deps -T -u root -v "$PWD/.env:/tmp/.env:ro" --entrypoint node n8n \
    /import/scripts/render-credentials.js > n8n/.credentials.json
  docker compose exec -T n8n n8n import:credentials --input=/import/.credentials.json
  rm -f n8n/.credentials.json
fi

echo "📦 Import workflow ..."
docker compose exec -T n8n n8n import:workflow --separate --input=/import/workflows

echo "🚀 Publish workflow (tool, jadwal ingestion harian, chat) ..."
for id in $WORKFLOW_IDS; do
  docker compose exec -T n8n n8n publish:workflow --id="$id" >/dev/null && echo "   ✓ $id"
done

echo "🔄 Restart n8n supaya workflow yang dipublish aktif ..."
docker compose restart n8n >/dev/null
docker compose up -d --wait n8n >/dev/null
echo "✅ Import selesai."
