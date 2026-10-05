#!/usr/bin/env bash
# =====================================================================
# Setup pertama kali (Mac / Linux / VPS):
#   1. membuat .env dari .env.example + mengisi password & kunci enkripsi acak
#   2. menjalankan Postgres (pgvector) + Ollama, download model embedding bge-m3
#   3. menjalankan n8n, meng-import credential + semua workflow, lalu mem-publish-nya
# Aman dijalankan ulang (tidak mengganti password/kunci yang sudah ada).
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

command -v docker >/dev/null 2>&1 || { echo "❌ Docker belum terpasang. Install Docker Desktop: https://www.docker.com/products/docker-desktop/"; exit 1; }
docker info >/dev/null 2>&1 || { echo "❌ Docker belum berjalan. Buka aplikasi Docker Desktop dulu, tunggu sampai statusnya Running."; exit 1; }

if [ ! -f .env ]; then
  cp .env.example .env
  echo "📝 .env dibuat dari .env.example"
fi

fill_if_empty() { # $1=KEY $2=VALUE
  if grep -qE "^$1=[[:space:]]*$" .env; then
    sed -i.bak -E "s|^$1=[[:space:]]*$|$1=$2|" .env && rm -f .env.bak
    echo "🔐 $1 diisi otomatis"
  fi
}
fill_if_empty POSTGRES_PASSWORD "$(openssl rand -hex 16)"
fill_if_empty N8N_ENCRYPTION_KEY "$(openssl rand -hex 32)"
chmod 600 .env

if ! grep -qE '^GEMINI_API_KEY=.+' .env; then
  echo "⚠️  GEMINI_API_KEY masih kosong di .env. Chat agent butuh key ini."
  echo "    Isi sekarang lalu jalankan ulang script ini, atau isi nanti di n8n: Credentials → 'Google Gemini · API key'."
fi

echo "🐘 Menjalankan Postgres + Ollama ..."
docker compose up -d --wait postgres ollama

echo "⬇️  Download model embedding (sekali saja, ±1,2 GB) ..."
docker compose run --rm ollama-init

echo "⚙️  Menjalankan n8n ..."
docker compose up -d --wait n8n

./scripts/import.sh --with-credentials

echo
echo "✅ Selesai. Buka http://localhost:${N8N_PUBLISH_PORT:-5678}"
echo "   Pertama kali: buat akun owner n8n (email + password, tersimpan lokal)."
echo "   Lalu jalankan workflow 'SEO · 01 · Ingestion Knowledge Base' dan coba chat di 'SEO · 02 · Chat Agent Content Gap'."
