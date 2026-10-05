// Membuat file credential n8n (JSON) dari .env. Dijalankan oleh scripts/import.sh di dalam image n8n:
//   docker compose run --rm --no-deps -T -u root -v "$PWD/.env:/tmp/.env:ro" --entrypoint node n8n /import/scripts/render-credentials.js
// Output (stdout) langsung di-import lalu dihapus; n8n menyimpannya terenkripsi dengan N8N_ENCRYPTION_KEY.
const fs = require('fs');

const env = {};
for (const line of fs.readFileSync('/tmp/.env', 'utf8').split(/\r?\n/)) {
  const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
  if (!m) continue;
  let v = m[2];
  if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
  else v = v.replace(/\s+#.*$/, '');
  env[m[1]] = v;
}

if (!env.POSTGRES_PASSWORD) {
  console.error('POSTGRES_PASSWORD kosong di .env');
  process.exit(1);
}

const credentials = [
  {
    id: 'seoPgCredential1',
    name: 'Postgres · SEO DB',
    type: 'postgres',
    data: {
      host: 'postgres',
      port: 5432,
      database: 'seo',
      user: env.POSTGRES_USER || 'n8n',
      password: env.POSTGRES_PASSWORD,
      ssl: 'disable',
      allowUnauthorizedCerts: false,
      sshTunnel: false,
    },
  },
  {
    id: 'seoOllamaCred001',
    name: 'Ollama · lokal',
    type: 'ollamaApi',
    data: { baseUrl: env.OLLAMA_URL || 'http://ollama:11434' },
  },
  {
    id: 'seoGeminiCred001',
    name: 'Google Gemini · API key',
    type: 'googlePalmApi',
    data: { host: 'https://generativelanguage.googleapis.com', apiKey: env.GEMINI_API_KEY || '' },
  },
];

process.stdout.write(JSON.stringify(credentials, null, 2));
