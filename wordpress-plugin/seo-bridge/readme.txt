=== SEO Bridge for AI Agent ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Jembatan aman antara SEO Agent (Difitech) dan WordPress.

== Apa yang bisa dilakukan ==
Hanya aksi SEO yang di-whitelist, lewat REST API `seo-bridge/v1`:

* seo-meta       : SEO title & meta description (Yoast, Rank Math, SEOPress, AIOSEO, atau tanpa plugin SEO)
* internal-link  : ubah frasa yang sudah ada menjadi link (konten Gutenberg/Classic & widget Text Editor Elementor)
* image-alt      : alt text gambar Media Library
* faq            : FAQ schema JSON-LD
* redirect       : redirect 301/302 sederhana
* draft          : artikel baru, SELALU berstatus draft
* rollback       : kembalikan perubahan dari change log

Tidak ada eksekusi PHP/SQL bebas, tidak ada akses file. Setiap perubahan dicatat
(Settings → SEO Bridge) beserta nilai sebelumnya, dan bisa di-rollback.

== Pemasangan ==
1. Upload zip di Plugins → Add New → Upload Plugin, lalu aktifkan.
2. Buat user khusus agent dengan role Editor.
3. Login sebagai user itu → Profile → Application Passwords → buat password baru.
4. Isi username + Application Password di tab Website aplikasi SEO Agent → Tes koneksi WordPress.
5. Opsional: Settings → SEO Bridge untuk mematikan aksi tertentu atau mode baca saja.

== Troubleshooting ==
* "Login WordPress gagal" padahal password benar: sebagian hosting Apache/CGI membuang header
  Authorization. Tambahkan di .htaccess (di atas blok WordPress):
      SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
* Security plugin (Wordfence, iThemes, dll.) bisa memblokir Application Passwords / REST API —
  izinkan untuk user agent.
* Permalink "Plain": plugin tetap bisa diakses lewat ?rest_route=/seo-bridge/v1/... (otomatis).
* WordPress 6.9+: aksi utama juga terdaftar di Abilities API (kategori "seo-bridge"), sehingga
  bisa dipanggil klien MCP lewat MCP Adapter dengan izin yang sama.
