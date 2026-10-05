# SEO Agent (n8n): Content Gap

Versi n8n dari `ai-agent-seo`. Alur dan tujuannya sama: tempel URL kompetitor di chat, agent mengambil halaman itu, mencari konten Difitech yang paling mirip di knowledge base, lalu menulis laporan content gap 7 bagian (intent, struktur H2/H3, gap, keyword, keunggulan, action plan, outline revisi).

Ini **Fase 0–2** dari plan: stack lokal, knowledge base, dan chat agent content gap. Search Console, usulan perubahan, dan SEO Bridge masuk Fase 3–4.

## Isi stack

| Service | Fungsi | Alamat |
|---|---|---|
| **n8n** 2.41.6 | Editor workflow, AI Agent, chat | http://localhost:5678 |
| **Postgres 17 + pgvector** | DB internal n8n + DB `seo` (vektor, halaman, cache scrape, memori chat, antrean perubahan) | `localhost:5433` (user `n8n`, password di `.env`) |
| **Ollama** | Embedding `bge-m3` (multilingual, 1024 dimensi) | hanya di jaringan Docker |

## Workflow

| Workflow | Isi |
|---|---|
| **SEO · 01 · Ingestion Knowledge Base** | WordPress REST API (semua post & page) → Markdown → chunk per section → embedding bge-m3 → pgvector. Incremental (hanya halaman berubah), diproses per 10 halaman, jalan otomatis tiap hari 02:00 WIB. Halaman yang dihapus di WordPress ikut dihapus dari knowledge base. |
| **SEO · 02 · Chat Agent Content Gap** | Chat → AI Agent (Gemini utama + Gemini cadangan otomatis) + memori Postgres + 5 tool (Multi-Site + GSC). |
| **SEO · Tool · list_managed_sites** | Daftar seluruh website WordPress yang sedang dikelola, domain, jumlah halaman di DB, dan status GSC. |
| **SEO · Tool · gsc_search_performance** | Data organik nyata dari Google Search Console via Service Account: `top_queries`, `top_pages`, `striking_distance` (ranking 8-20), dan `low_ctr` (CTR di bawah benchmark untuk rewrite title/meta). |
| **SEO · Tool · scrape_competitor_url** | Validasi URL (tolak localhost/IP privat, arahkan domain sendiri ke knowledge base) → Jina Reader → (Firecrawl bila key diisi) → bersihkan menu/footer → `[COMPETITOR_PAGE]`. Cache 6 jam. |
| **SEO · Tool · search_internal_content** | Embed query → cosine similarity di pgvector (mendukung filter website) → `[INTERNAL_SEARCH_RESULTS]` atau `NO_RELEVANT_INTERNAL_CONTENT` (skor < 0,50). |
| **SEO · Tool · get_internal_page** | Isi utuh + outline satu halaman internal → `[INTERNAL_PAGE]`. |

Setiap tool adalah workflow biasa, jadi bisa dibuka dan diuji sendiri dengan tombol **Execute workflow**.

## Menjalankan di Mac (pertama kali)

Butuh **Docker Desktop**. Pilih versi chip Intel atau Apple sesuai Mac Anda: https://www.docker.com/products/docker-desktop/. Di Docker Desktop → Settings → Resources, beri memory minimal **6 GB** dan CPU sebanyak mungkin, karena embedding memakai CPU.

```bash
cd ~/Desktop/seo-agent-n8n
chmod +x scripts/*.sh

# 1. Ambil GEMINI_API_KEY, plugin SEO Bridge & article prompt dari proyek lama (key tidak ditampilkan)
./scripts/copy-from-old-project.sh

# 2. Setup: start Postgres + Ollama, download bge-m3 (±1,2 GB), start n8n, import & publish workflow
./scripts/setup.sh

# 3. Buka n8n, lalu buat akun owner (email + password; tersimpan lokal di Postgres)
open http://localhost:5678
```

Setelah itu, di n8n:

1. Buka **SEO · 01 · Ingestion Knowledge Base**, lalu klik **Execute workflow**.
   - Ingestion pertama untuk seluruh difitech.id (±370 halaman) memakan waktu **sekitar 30–90 menit** di CPU Mac. Hasilnya tersimpan per 10 halaman.
   - Mau coba cepat dulu? Isi `INGEST_MAX_PAGES=20` di `.env`, jalankan `docker compose up -d`, eksekusi workflow-nya, lalu kembalikan ke `0` dan eksekusi lagi. Halaman yang sudah di-index tidak di-embed ulang.
2. Buka **SEO · 02 · Chat Agent Content Gap**, klik **Open chat**, lalu tempel misalnya:
   `Analisis content gap https://olakses.com/strategi-iklan-shopee-ads-untuk-pemula-budget-targeting-dan-optimasi/`
3. Lihat jejak tiap langkah di tab **Executions**: tool apa yang dipanggil, input/outputnya, model mana yang dipakai.

## Command sehari-hari

```bash
docker compose up -d                 # start semua
docker compose down                  # stop (data aman di volume Docker)
docker compose ps                    # status
docker compose logs -f n8n           # log n8n
./scripts/export.sh                  # simpan workflow yang Anda edit di n8n -> n8n/workflows/*.json
./scripts/import.sh                  # import ulang workflow dari folder (MENIMPA versi di n8n)
./scripts/import.sh --with-credentials   # + tulis ulang credential dari .env (mis. setelah ganti GEMINI_API_KEY)

# Lihat isi knowledge base
docker compose exec postgres psql -U n8n -d seo -c "select count(*) halaman, sum(chunk_count) chunk, max(indexed_at) from seo_pages;"

# Kosongkan knowledge base (lalu jalankan ingestion lagi)
docker compose exec postgres psql -U n8n -d seo -c "truncate seo_chunks, seo_pages;"

# Hapus TOTAL semua data (DB, model, akun n8n). Hati-hati.
docker compose down -v
```

## Konfigurasi (`.env`)

| Variabel | Default | Keterangan |
|---|---|---|
| `GEMINI_API_KEY` | (kosong) | Disimpan **terenkripsi** sebagai credential n8n, tidak masuk ke environment container |
| `SITE_NAME`, `SITE_DOMAIN`, `WP_BASE_URL` | Difitech, difitech.id | Dipakai di system prompt dan ingestion |
| `WP_POST_TYPES` | `posts,pages` | Tambah CPT, mis. `posts,pages,product` |
| `INGEST_MAX_PAGES` | `0` (semua) | Batasi untuk uji cepat |
| `RAG_MIN_SCORE` | `0.50` | Ambang relevansi; di bawah ini dianggap "belum ada konten" |
| `RAG_TOP_K`, `RAG_MAX_CHARS_PER_PAGE` | 3, 900 | Banyak halaman & panjang snippet yang dikirim ke model |
| `CHUNK_SIZE` | 1500 | Ukuran potongan (karakter) saat ingestion |
| `SCRAPE_MAX_CHARS`, `SCRAPE_CACHE_HOURS` | 12000, 6 | Batas konten kompetitor & lama cache |
| `JINA_API_KEY`, `FIRECRAWL_API_KEY` | (kosong) | Opsional; Firecrawl dipakai bila Jina gagal |
| `OLLAMA_URL` | `http://ollama:11434` | Isi `http://host.docker.internal:11434` untuk memakai aplikasi Ollama di Mac (lebih cepat) |

Setelah mengubah `.env`, jalankan `docker compose up -d` (container n8n dibuat ulang otomatis). Jika yang diubah `GEMINI_API_KEY` atau `OLLAMA_URL`, jalankan juga `./scripts/import.sh --with-credentials`.

Model Gemini diatur di node **Gemini (utama)** (`models/gemini-3.8-flash`) dan **Gemini (cadangan)** (`models/gemini-3.5-flash`). Model bisa dipilih langsung dari daftar di node tersebut.

## Struktur folder

```
seo-agent-n8n/
├── docker-compose.yml          # n8n + postgres(pgvector) + ollama (+ ollama-init)
├── .env.example                # salin ke .env (setup.sh otomatis)
├── db/init/01-seo-database.sql # DB "seo": seo_chunks (vector 1024 + HNSW), seo_pages, scrape_cache, changes
├── n8n/
│   ├── workflows/              # 5 workflow (JSON, di-import oleh scripts/import.sh)
│   └── scripts/render-credentials.js
├── prompts/system_prompt.md    # salinan system prompt agent (sumber aslinya ada di node 🤖 SEO Agent)
├── scripts/                    # copy-from-old-project.sh, setup.sh, import.sh, export.sh
└── wordpress-plugin/seo-bridge # disalin dari proyek lama oleh copy-from-old-project.sh (Fase 3)
```

## Keamanan

- Agent **tidak punya akses tulis** ke website di versi ini. Ketiga tool hanya membaca.
- Konten kompetitor diperlakukan sebagai teks tidak tepercaya; system prompt melarang mengikuti instruksi di dalamnya.
- Scrape dilakukan oleh Jina/Firecrawl (server luar), dan URL lokal/IP privat ditolak sejak validasi. Jadi tool scrape tidak bisa diarahkan ke Postgres, Ollama, atau n8n sendiri.
- Workflow bisa membaca konfigurasi lewat `$env` (`N8N_BLOCK_ENV_ACCESS_IN_NODE=false`). Akun n8n hanya untuk tim internal yang dipercaya.
- Port n8n & Postgres hanya terbuka di `127.0.0.1`. Di VPS, n8n diakses lewat reverse proxy HTTPS (Fase 5).

## Troubleshooting

| Gejala | Solusi |
|---|---|
| `setup.sh`: "Docker belum berjalan" | Buka Docker Desktop, tunggu statusnya **Running** |
| Chat error `API key not valid` / 401 | Isi `GEMINI_API_KEY` di `.env` → `./scripts/import.sh --with-credentials`, atau edit credential **Google Gemini · API key** di n8n |
| Chat error `model not found` | Pilih model lain dari dropdown di node Gemini (utama/cadangan) |
| Ingestion sangat lambat | Naikkan CPU Docker Desktop, atau pakai Ollama native Mac (`OLLAMA_URL`, lihat tabel di atas) |
| Agent selalu bilang "belum ada konten" | Knowledge base kosong → jalankan ingestion; cek jumlah halaman dengan query di atas |
| Scrape kompetitor gagal / kosong | Situs berat JavaScript / anti-bot → isi `FIRECRAWL_API_KEY` |
| Port 5678 / 5433 sudah dipakai | Ganti `N8N_PUBLISH_PORT` / `POSTGRES_PUBLISH_PORT` di `.env` |

## Roadmap berikutnya

| Fase | Isi |
|---|---|
| 3 | Search Console (service account), tool `propose_*` → tabel `changes`, workflow persetujuan (n8n Form) → plugin SEO Bridge → rollback |
| 4 | Sinkronisasi inventaris WordPress (media/alt text, kategori, konten tipis/usang), draft artikel, laporan GSC mingguan, error workflow |
| 5 | Deploy VPS: Caddy/nginx HTTPS, `N8N_SECURE_COOKIE=true`, chat publik dengan login n8n untuk tim, backup Postgres, GitHub Actions |
