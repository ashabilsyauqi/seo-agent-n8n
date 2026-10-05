-- =====================================================================
-- Database "seo": data aplikasi, terpisah dari database internal n8n.
-- Dijalankan otomatis oleh image postgres HANYA saat volume pgdata masih kosong.
-- Untuk menjalankan ulang di DB yang sudah ada:
--   docker compose exec -T postgres psql -U n8n -d seo < db/init/01-seo-database.sql
--   (hapus dulu baris CREATE DATABASE & \connect di bawah)
-- =====================================================================
CREATE DATABASE seo;
\connect seo

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Potongan (chunk) konten internal + embedding bge-m3 (1024 dimensi).
-- Format kolom mengikuti node "Postgres PGVector Store" n8n (id, text, metadata, embedding).
CREATE TABLE IF NOT EXISTS seo_chunks (
    id        uuid PRIMARY KEY DEFAULT uuid_generate_v4(),
    text      text,
    metadata  jsonb,
    embedding vector(1024)
);
CREATE INDEX IF NOT EXISTS seo_chunks_embedding_idx ON seo_chunks USING hnsw (embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS seo_chunks_url_idx ON seo_chunks ((metadata->>'url'));

-- Satu baris per halaman internal (dipakai get_internal_page, outline, ingestion incremental).
CREATE TABLE IF NOT EXISTS seo_pages (
    url              text PRIMARY KEY,
    wp_id            bigint,
    post_type        text,
    title            text,
    meta_description text,
    outline          text,
    markdown         text,
    word_count       integer,
    chunk_count      integer,
    content_hash     text,
    modified_gmt     timestamptz,
    indexed_at       timestamptz NOT NULL DEFAULT now()
);

-- Cache hasil scrape kompetitor (pertanyaan lanjutan tidak memicu scrape ulang).
CREATE TABLE IF NOT EXISTS scrape_cache (
    url        text PRIMARY KEY,
    provider   text,
    result     text NOT NULL,
    fetched_at timestamptz NOT NULL DEFAULT now()
);

-- Riwayat chat dibuat otomatis oleh node "Postgres Chat Memory" (tabel seo_chat_histories).

-- (Fase 3) Antrean usulan perubahan + audit trail. Agent hanya boleh INSERT status 'pending';
-- penerapan ke WordPress selalu lewat workflow persetujuan yang dipicu manusia.
CREATE TABLE IF NOT EXISTS changes (
    id           bigserial PRIMARY KEY,
    site         text NOT NULL DEFAULT 'default',
    kind         text NOT NULL,              -- seo_meta | internal_link | draft | ...
    target_url   text,
    payload      jsonb NOT NULL,
    reason       text,
    source       text,                       -- agent | manual | suggest
    status       text NOT NULL DEFAULT 'pending',  -- pending | approved | applied | failed | rejected | rolled_back
    before_value jsonb,
    result       jsonb,
    created_at   timestamptz NOT NULL DEFAULT now(),
    decided_by   text,
    decided_at   timestamptz,
    applied_at   timestamptz
);
CREATE INDEX IF NOT EXISTS changes_status_idx ON changes (status);
