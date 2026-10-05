-- =====================================================================
-- Migrasi Multi-Site & Google Search Console
-- Menjadikan knowledge base mendukung banyak website WordPress
-- =====================================================================
\connect seo

CREATE TABLE IF NOT EXISTS seo_sites (
    slug             text PRIMARY KEY,             -- e.g. 'difitech', 'bengkel-jaya'
    name             text NOT NULL,                -- e.g. 'Difitech'
    domain           text NOT NULL UNIQUE,         -- e.g. 'difitech.id'
    wp_base_url      text NOT NULL,                -- e.g. 'https://difitech.id'
    wp_post_types    text NOT NULL DEFAULT 'posts,pages',
    gsc_property     text,                         -- e.g. 'sc-domain:difitech.id' atau 'https://difitech.id/'
    is_active        boolean NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);

-- Seed default site (Difitech)
INSERT INTO seo_sites (slug, name, domain, wp_base_url, wp_post_types, gsc_property)
VALUES ('difitech', 'Difitech', 'difitech.id', 'https://difitech.id', 'posts,pages', 'sc-domain:difitech.id')
ON CONFLICT (slug) DO UPDATE
SET domain = EXCLUDED.domain,
    wp_base_url = EXCLUDED.wp_base_url,
    gsc_property = COALESCE(seo_sites.gsc_property, EXCLUDED.gsc_property);

-- Tambah kolom site_slug ke seo_pages
ALTER TABLE seo_pages ADD COLUMN IF NOT EXISTS site_slug text DEFAULT 'difitech' REFERENCES seo_sites(slug) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS seo_pages_site_slug_idx ON seo_pages(site_slug);

-- Tambah kolom site_slug ke seo_chunks
ALTER TABLE seo_chunks ADD COLUMN IF NOT EXISTS site_slug text DEFAULT 'difitech' REFERENCES seo_sites(slug) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS seo_chunks_site_slug_idx ON seo_chunks(site_slug);
CREATE INDEX IF NOT EXISTS seo_chunks_metadata_site_idx ON seo_chunks ((metadata->>'site_slug'));

-- Tambah kolom site_slug ke scrape_cache
ALTER TABLE scrape_cache ADD COLUMN IF NOT EXISTS site_slug text DEFAULT 'difitech';

-- Align changes table
ALTER TABLE changes ADD COLUMN IF NOT EXISTS site_slug text DEFAULT 'difitech';
CREATE INDEX IF NOT EXISTS changes_site_slug_idx ON changes(site_slug);
