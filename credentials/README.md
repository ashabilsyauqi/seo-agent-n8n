# Kredensial Google Search Console (Service Account)

Untuk menghubungkan Google Search Console:

1. Buka [Google Cloud Console](https://console.cloud.google.com/).
2. Buat Project baru (atau gunakan project yang sudah ada) dan aktifkan **Google Search Console API**.
3. Buka **IAM & Admin** → **Service Accounts** → Buat Service Account (misal: `seo-agent@project-id.iam.gserviceaccount.com`).
4. Klik tab **Keys** pada service account tersebut → **Add Key** → **Create new key** (pilih **JSON**). File JSON akan terunduh ke komputer Anda.
5. Simpan / salin file tersebut ke folder ini dengan nama:
   ```
   gsc-service-account.json
   ```
   (Lokasi: `credentials/gsc-service-account.json`).
6. Buka [Google Search Console](https://search.google.com/search-console).
7. Di setiap property website yang ingin dianalisis:
   - Masuk ke **Settings** → **Users and permissions** → **Add user**.
   - Masukkan email service account tadi (misal: `seo-agent@project-id.iam.gserviceaccount.com`).
   - Berikan izin **Restricted** (cukup read-only untuk membaca performa, klik, impresi, CTR, dan posisi).
8. Selesai! AI Agent akan langsung otomatis bisa membaca data Search Console untuk website tersebut.
