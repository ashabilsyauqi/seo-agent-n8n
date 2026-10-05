#!/usr/bin/env bash
# =====================================================================
# Simpan workflow yang sudah Anda edit di n8n kembali ke folder n8n/workflows
# (supaya bisa di-commit ke git / di-deploy ke VPS).
#   ./scripts/export.sh
# File lama disalin dulu ke n8n/backup/<tanggal>/.
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "n8n/backup/$STAMP"
cp n8n/workflows/*.json "n8n/backup/$STAMP/" 2>/dev/null || true

docker compose exec -T n8n sh -c 'rm -rf /tmp/wf-export && mkdir -p /tmp/wf-export && n8n export:workflow --all --separate --pretty --output=/tmp/wf-export/' >/dev/null
rm -rf n8n/.export && mkdir -p n8n/.export
docker compose cp n8n:/tmp/wf-export/. n8n/.export/ >/dev/null

# Petakan ID workflow -> nama file di repo
declare_map() {
  case "$1" in
    seoWfIngestion01) echo 01-ingestion.json ;;
    seoWfChatAgent01) echo 02-chat-agent.json ;;
    seoToolScrape001) echo 10-tool-scrape-competitor.json ;;
    seoToolSearch001) echo 11-tool-search-internal.json ;;
    seoToolGetPage01) echo 12-tool-get-internal-page.json ;;
    *) echo "" ;;
  esac
}
for f in n8n/.export/*.json; do
  id=$(basename "$f" .json)
  target=$(declare_map "$id")
  if [ -n "$target" ]; then
    mv "$f" "n8n/workflows/$target" && echo "   ✓ $target"
  else
    mkdir -p n8n/workflows/extra && mv "$f" "n8n/workflows/extra/$id.json" && echo "   + extra/$id.json (workflow baru)"
  fi
done
rm -rf n8n/.export
echo "✅ Export selesai. Backup versi sebelumnya: n8n/backup/$STAMP/"
