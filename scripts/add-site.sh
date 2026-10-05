#!/usr/bin/env bash
# =====================================================================
# Menambahkan website WordPress baru ke dalam sistem SEO Agent
# Penggunaan:
#   ./scripts/add-site.sh <slug> <nama> <domain> <wp_base_url> [gsc_property]
# Contoh:
#   ./scripts/add-site.sh bengkel "Bengkel Jaya" bengkeljaya.com https://bengkeljaya.com sc-domain:bengkeljaya.com
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

if [ $# -lt 4 ]; then
  echo "Penggunaan: ./scripts/add-site.sh <slug> <nama> <domain> <wp_base_url> [gsc_property]"
  echo "Contoh:     ./scripts/add-site.sh bengkel \"Bengkel Jaya\" bengkeljaya.com https://bengkeljaya.com sc-domain:bengkeljaya.com"
  exit 1
fi

SLUG="$1"
NAME="$2"
DOMAIN="$3"
WP_URL="$4"
GSC_PROP="${5:-sc-domain:$DOMAIN}"

docker compose exec -T postgres psql -U n8n -d seo -c "
INSERT INTO seo_sites (slug, name, domain, wp_base_url, gsc_property)
VALUES ('$SLUG', '$NAME', '$DOMAIN', '$WP_URL', '$GSC_PROP')
ON CONFLICT (slug) DO UPDATE
SET name = EXCLUDED.name,
    domain = EXCLUDED.domain,
    wp_base_url = EXCLUDED.wp_base_url,
    gsc_property = EXCLUDED.gsc_property,
    updated_at = now();
"

echo "✅ Website '$NAME' ($DOMAIN) berhasil didaftarkan!"
echo "   Slug: $SLUG"
echo "   GSC Property: $GSC_PROP"
