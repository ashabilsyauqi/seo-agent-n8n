#!/usr/bin/env bash
# =====================================================================
# Ambil bahan dari proyek lama (ai-agent-seo):
#   - GEMINI_API_KEY (+ JINA/FIRECRAWL key bila ada) ke .env proyek ini
#   - plugin WordPress SEO Bridge -> wordpress-plugin/
#   - article_prompt.md -> prompts/ (dipakai Fase 4: draft artikel)
#
#   ./scripts/copy-from-old-project.sh                      # path default di bawah
#   ./scripts/copy-from-old-project.sh "/path/ke/ai-agent-seo"
# Nilai key tidak pernah ditampilkan di layar.
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

OLD="${1:-$HOME/Documents/Difitech/software dev/ai-agent-seo}"
[ -d "$OLD" ] || { echo "❌ Folder proyek lama tidak ditemukan: $OLD"; exit 1; }

[ -f .env ] || cp .env.example .env

copy_key() { # $1 = nama variabel
  local val
  val=$(grep -E "^$1=" "$OLD/.env" 2>/dev/null | tail -1 | cut -d= -f2- || true)
  if [ -n "$val" ]; then
    # tulis lewat file sementara supaya karakter khusus di key aman
    grep -vE "^$1=" .env > .env.tmp || true
    printf '%s=%s\n' "$1" "$val" >> .env.tmp
    mv .env.tmp .env
    echo "🔑 $1 disalin dari proyek lama"
  fi
}
if [ -f "$OLD/.env" ]; then
  copy_key GEMINI_API_KEY
  copy_key JINA_API_KEY
  copy_key FIRECRAWL_API_KEY
fi
chmod 600 .env

if [ -d "$OLD/wordpress-plugin/seo-bridge" ]; then
  mkdir -p wordpress-plugin
  cp -R "$OLD/wordpress-plugin/seo-bridge" wordpress-plugin/
  echo "🔌 Plugin SEO Bridge disalin ke wordpress-plugin/seo-bridge"
fi

if [ -f "$OLD/seo_agent/agents/prompts/article_prompt.md" ]; then
  cp "$OLD/seo_agent/agents/prompts/article_prompt.md" prompts/article_prompt.md
  echo "📝 article_prompt.md disalin ke prompts/"
fi
echo "✅ Selesai."
