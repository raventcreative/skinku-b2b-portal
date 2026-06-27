---
name: frontend-security-review
description: >
  Gunakan skill ini setiap kali senior developer ingin menganalisis, mereview, atau mengaudit kode
  frontend (JavaScript, TypeScript, React, Vue, Angular, HTML, CSS) yang ditulis oleh developer
  junior atau dihasilkan AI (Copilot, ChatGPT, Cursor, dll) untuk menemukan celah keamanan,
  anti-pattern, dan kode "slop" berkualitas rendah. Trigger ketika ada kata seperti "review kode",
  "audit keamanan", "cek celah", "analisis junior", "AI slop", "security check frontend",
  "XSS", "CSRF", "sanitasi input", "evaluasi PR", "code review keamanan". Skill ini mencakup
  checklist sistematis, pola bahaya yang harus dicari, dan format laporan output yang jelas.
---

# Frontend Security Code Review — Senior Developer Skill

Panduan sistematis untuk **mengaudit kode frontend** dari developer junior atau output AI,
dengan fokus pada **celah keamanan**, **kualitas kode**, dan **AI slop patterns**.

---

## 1. MINDSET SAAT REVIEW

Sebelum membaca kode, tanamkan perspektif ini:

- **Anggap semua input user sebagai berbahaya** — validasi di frontend hanya UX, bukan keamanan nyata.
- **AI-generated code sering "terlihat benar tapi salah secara konteks"** — sintaks valid, logika keliru.
- **Junior dev sering copy-paste tanpa memahami implikasi** — cari pola copy-paste Stack Overflow lama.
- **Security bukan fitur tambahan** — temukan asumsi implisit yang salah.

---

## 2. FASE ANALISIS (Lakukan Berurutan)

### Fase 1 — Identifikasi Konteks
Sebelum membaca baris kode, tentukan:
- Framework apa? (React, Vue, Angular, Vanilla JS, Next.js, dll)
- Data apa yang mengalir? (user input, API response, URL params, localStorage)
- Siapa yang bisa berinteraksi dengan UI ini? (public user, admin, authenticated only)
- Ada integrasi pihak ketiga? (payment, OAuth, third-party scripts)

### Fase 2 — Scan Cepat untuk Red Flags
Lakukan grep/scan visual untuk pola paling berbahaya dulu (lihat Bagian 3).

### Fase 3 — Analisis Alur Data
Ikuti data dari sumbernya hingga ke render/storage:
```
[Sumber] → [Transformasi] → [Render/Aksi]
URL Param → dipakai di innerHTML → XSS ✗
API Response → di-escape → ditampilkan → Aman ✓
```

### Fase 4 — Review Logika Bisnis
Cari celah logis yang bukan murni teknis tapi berdampak keamanan:
- Apakah otorisasi hanya dilakukan di frontend?
- Apakah ada fitur yang bisa di-bypass dengan manipulasi state?
- Apakah error message membocorkan informasi sensitif?

### Fase 5 — Evaluasi Kualitas AI Slop
Nilai apakah kode menunjukkan tanda-tanda dihasilkan AI tanpa review manusia (lihat Bagian 5).

---

## 3. CHECKLIST CELAH KEAMANAN FRONTEND

### 🔴 KRITIS — Harus Diperbaiki Segera

#### XSS (Cross-Site Scripting)
```
BAHAYA — cari pola ini:
- innerHTML =  (tanpa sanitasi)
- outerHTML =
- document.write(
- insertAdjacentHTML(
- dangerouslySetInnerHTML={{ __html: userInput }}  [React]
- v-html="userInput"  [Vue]
- [innerHTML]="userInput"  [Angular]
- eval(
- setTimeout(stringVariable,
- new Function(userInput)
- location.href = userInput  (open redirect → bisa jadi XSS)

AMAN — pola yang benar:
- textContent =  (untuk teks biasa)
- innerText =
- createElement() + appendChild()
- DOMPurify.sanitize(input) sebelum innerHTML
- React: {userInput} (auto-escape)
```

#### Injeksi & Evaluasi Berbahaya
```
BAHAYA:
- eval(userInput)
- Function(userInput)()
- setTimeout(userInput, delay)  ← jika argumen pertama string
- setInterval(userInput, delay)
- document.cookie diset tanpa HttpOnly/Secure flag
  (tapi ingat: flag ini diset server-side, bukan JS)

BAHAYA di template literal:
- `SELECT * FROM users WHERE id = ${userInput}`  ← SQL Injection
  (meski jarang di frontend, sering muncul di BFF/Server Actions)
```

#### Penyimpanan Data Sensitif
```
BAHAYA — jangan simpan ini di localStorage/sessionStorage:
- Token JWT (gunakan httpOnly cookie)
- Password
- Data kartu kredit
- PII sensitif (NIK, nomor rekening)

BAHAYA di kode:
- localStorage.setItem('token', jwt)
- localStorage.setItem('password', pass)
- sessionStorage.setItem('creditCard', ...)

AMAN:
- Token di httpOnly cookie (dikelola server)
- Data sensitif di memory state saja (hilang saat refresh = by design)
```

#### CSRF
```
BAHAYA:
- Form POST tanpa CSRF token
- Endpoint yang mengubah data hanya bergantung pada cookie session
- Tidak ada validasi Origin/Referer header (ini di server, tapi cek apakah ada fetch tanpa credentials)

PERHATIKAN:
- fetch('/api/transfer', { method: 'POST', body: ... })
  → apakah ada header X-CSRF-Token?
- axios tanpa konfigurasi xsrfCookieName/xsrfHeaderName
```

---

### 🟠 TINGGI — Perbaiki dalam Sprint Ini

#### Eksposur Informasi Sensitif
```
BAHAYA:
- console.log(user.password)
- console.log(apiKey)
- console.error(fullError)  ← stack trace ke user
- Komentar berisi kredensial: // API_KEY = "abc123"
- process.env.SECRET_KEY diexpose ke bundle client
  (Next.js: hanya NEXT_PUBLIC_ yang boleh di client)

CARI:
- .env variables tanpa prefix NEXT_PUBLIC_ dipakai di komponen client
- Hardcoded API key di file JS
- Debug mode aktif di production build
```

#### Autentikasi & Otorisasi Frontend-Only
```
BAHAYA — logika ini hanya boleh ADA DI SERVER:
- if (user.role === 'admin') showAdminPanel()
  ← tampilan boleh, tapi DATA harus diproteksi server
- Routing guard yang hanya cek localStorage
- "Hanya tampilkan tombol delete jika admin" tanpa proteksi API
- JWT di-decode di frontend untuk cek role tanpa verifikasi signature

POLA SALAH:
const isAdmin = localStorage.getItem('isAdmin') === 'true'
// User bisa tulis localStorage.setItem('isAdmin', 'true') di console!
```

#### Dependensi & Supply Chain
```
CARI:
- package.json dengan versi ^lama yang punya CVE
- Import dari CDN tanpa Subresource Integrity (SRI):
  <script src="https://cdn.example.com/lib.js">  ← BAHAYA
  <script src="..." integrity="sha384-..." crossorigin="anonymous">  ← AMAN
- Dependensi yang tidak dipakai tapi ter-install
- npm audit menunjukkan vulnerability kritis
```

---

### 🟡 SEDANG — Masuk Backlog Keamanan

#### Konfigurasi & Header
```
PERIKSA apakah ada CSP (Content Security Policy):
- <meta http-equiv="Content-Security-Policy" content="...">
- Atau header dari server

BAHAYA di CSP:
- 'unsafe-inline' dalam script-src
- 'unsafe-eval' dalam script-src
- Wildcard (*) dalam connect-src

PERIKSA:
- HTTPS enforcement (redirect HTTP → HTTPS)
- Referrer-Policy header
- X-Frame-Options atau frame-ancestors di CSP
```

#### Validasi Input
```
INGAT: Validasi frontend = UX, bukan keamanan!
Tapi tetap cari:
- Input tanpa sanitasi yang langsung dipakai di API call
- File upload tanpa validasi tipe MIME di client (minimal untuk UX)
- Form yang tidak membatasi panjang input (DoS potensial di server)
- Regex validasi yang bisa ReDoS: /^(a+)+$/.test(input)
```

#### Error Handling
```
BAHAYA:
- catch(e) { alert(e.message) }  ← expose internal info ke user
- catch(e) { console.log(e) }   ← info bocor di console production
- Tidak ada error boundary → app crash expose stack trace

AMAN:
- catch(e) { showGenericError(); logToMonitoring(e) }
- Error boundary React yang menampilkan pesan ramah user
```

---

## 4. POLA KHUSUS PER FRAMEWORK

### React
```javascript
// ❌ XSS
<div dangerouslySetInnerHTML={{ __html: userComment }} />

// ✅ Aman
<div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(userComment) }} />
// Atau lebih baik:
<div>{userComment}</div>  // React auto-escape

// ❌ Open redirect
window.location.href = searchParams.get('redirect')

// ✅ Validasi redirect URL
const redirect = searchParams.get('redirect')
if (redirect?.startsWith('/')) window.location.href = redirect
// Atau whitelist domain yang diizinkan

// ❌ State otorisasi dari localStorage
const [isAdmin] = useState(localStorage.getItem('role') === 'admin')

// ✅ Dari server (server component / protected API)
const { data: user } = useQuery('/api/me')
const isAdmin = user?.role === 'admin'  // server yang validasi
```

### Vue
```javascript
// ❌ XSS
<div v-html="userInput"></div>

// ✅
<div v-html="$sanitize(userInput)"></div>  // dengan vue-sanitize
// Atau: {{ userInput }}  // auto-escape

// ❌ Eval di template
:onclick="eval(dynamicCode)"

// ✅
@click="handleClick"  // handler statis
```

### Next.js / Server Actions
```javascript
// ❌ Secret di client component
const API_KEY = process.env.SECRET_API_KEY  // undefined di client, tapi jangan hardcode

// ✅
// Di server component atau API route:
const API_KEY = process.env.SECRET_API_KEY  // aman, tidak dikirim ke client

// ❌ Server Action tanpa validasi
async function deleteUser(userId) {
  'use server'
  await db.delete(userId)  // siapa yang bisa memanggil ini?
}

// ✅
async function deleteUser(userId) {
  'use server'
  const session = await getServerSession()
  if (!session?.user?.isAdmin) throw new Error('Unauthorized')
  await db.delete(userId)
}
```

---

## 5. MENGENALI & MENILAI AI SLOP

"AI slop" = kode yang dihasilkan AI dan diterima tanpa review kritis. Ciri-cirinya:

### Tanda-tanda AI Slop dalam Kode

**Komentar berlebihan & tidak berguna:**
```javascript
// ❌ AI Slop
// This function adds two numbers
function add(a, b) {
  // Return the sum of a and b
  return a + b; // Addition operation
}

// ✅ Komentar bermakna
// Gunakan integer arithmetic untuk menghindari floating point error
function addCurrency(a, b) {
  return Math.round((a + b) * 100) / 100
}
```

**Pattern yang "terlihat aman" tapi salah konteks:**
```javascript
// ❌ AI generate sanitasi yang tidak tepat konteks
function sanitizeForSQL(input) {
  return input.replace(/'/g, "''")  // ← ini bukan cara aman, pakai parameterized query!
}

// ❌ Validasi email regex dari AI yang bisa ReDoS
const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/
// (regex ini OK tapi AI sering generate versi yang lebih kompleks & vulnerable)
```

**Error handling template yang tidak bermakna:**
```javascript
// ❌ AI Slop pattern
try {
  const data = await fetchUser(id)
  return data
} catch (error) {
  console.error('Error fetching user:', error)
  throw error  // ← re-throw setelah log? untuk apa?
}
```

**"Security theater" — validasi yang tidak benar-benar melindungi:**
```javascript
// ❌ AI sering generate ini sebagai "security measure"
function isValidInput(input) {
  if (input.includes('<script>')) return false  // ← trivial bypass: <SCRIPT>, <img onerror=...>
  if (input.includes('DROP TABLE')) return false  // ← harus pakai parameterized query!
  return true
}
```

**Import yang tidak perlu (hallucination):**
```javascript
// ❌ AI Slop — library tidak ada atau tidak sesuai
import { secureSanitize } from 'html-secure-utils'  // package ini tidak ada!
import { XSSFilter } from 'react-xss-protection'    // bukan cara yang benar
```

### Pertanyaan untuk Evaluasi Kode AI
Tanyakan pada kode yang dicurigai AI-generated:
1. Apakah logikanya sesuai dengan kebutuhan bisnis spesifik ini, atau generic?
2. Apakah error handling menangani kasus nyata, atau hanya template?
3. Apakah ada bukti developer memahami *mengapa* kode ini ditulis begini?
4. Apakah ada "magic number" atau string yang tidak dijelaskan?
5. Apakah kode ini bisa lulus review jika dipertanyakan oleh senior?

---

## 6. FORMAT LAPORAN REVIEW

Gunakan template ini saat memberikan feedback:

```
## Security Review Report

**Reviewer:** [Nama Senior Dev]
**Tanggal:** [YYYY-MM-DD]
**Kode yang direview:** [File/PR/komponen]
**Framework:** [React/Vue/dll]

---

### 🔴 KRITIS (Harus fix sebelum merge)

#### [Nama Masalah]
- **File:** `src/components/Comment.jsx:42`
- **Masalah:** innerHTML tanpa sanitasi memungkinkan XSS
- **Kode Bermasalah:**
  ```javascript
  container.innerHTML = userComment  // ← BAHAYA
  ```
- **Risiko:** Attacker bisa inject script, steal cookie, hijack session
- **Fix:**
  ```javascript
  import DOMPurify from 'dompurify'
  container.innerHTML = DOMPurify.sanitize(userComment)
  // Atau jika hanya teks: container.textContent = userComment
  ```
- **Referensi:** OWASP XSS Prevention Cheat Sheet

---

### 🟠 TINGGI

[Format sama seperti di atas]

---

### 🟡 SEDANG / SARAN

[Gunakan format ringkas jika banyak item]

---

### 📊 Ringkasan

| Severity | Jumlah |
|----------|--------|
| 🔴 Kritis | X |
| 🟠 Tinggi | X |
| 🟡 Sedang | X |
| ℹ️ Saran  | X |

**Keputusan:** ✅ Approve / ⚠️ Approve dengan catatan / ❌ Butuh revisi

---

### 💡 Catatan untuk Junior Dev

[Tulis penjelasan edukatif, bukan menghakimi. Jelaskan MENGAPA sesuatu berbahaya.]
```

---

## 7. TIPS KOMUNIKASI DENGAN JUNIOR DEV

Senior yang baik bukan yang paling banyak menemukan bug, tapi yang paling efektif membuat juniornya berkembang.

**DO:**
- Jelaskan *mengapa* sesuatu berbahaya, bukan hanya *apa*-nya
- Berikan contoh fix yang konkret
- Akui ketika ada trade-off ("solusi ini lebih aman tapi lebih verbose")
- Pisahkan isu keamanan dari preferensi style

**DON'T:**
- Jangan katakan "ini salah" tanpa penjelasan
- Jangan asumsi niat buruk — junior belum tahu, bukan tidak mau tahu
- Jangan review lebih dari 400 baris kode dalam satu sesi — kualitas review turun
- Jangan fix kode mereka tanpa jelaskan — itu bukan review, itu pair programming

---

## 8. REFERENSI CEPAT

| Ancaman | Tool/Library Rekomendasi |
|---------|--------------------------|
| XSS | DOMPurify, sanitize-html |
| CSP | helmet (server), meta CSP tag |
| Dependency audit | `npm audit`, Snyk, Dependabot |
| Secret scanning | git-secrets, truffleHog |
| OWASP Top 10 | https://owasp.org/Top10 |
| Frontend Security | https://cheatsheetseries.owasp.org |

### Perintah Cepat untuk Audit
```bash
# Scan dependensi untuk CVE
npm audit --audit-level=moderate

# Cari pattern berbahaya di codebase
grep -rn "innerHTML" src/ --include="*.js" --include="*.jsx" --include="*.tsx"
grep -rn "eval(" src/
grep -rn "dangerouslySetInnerHTML" src/
grep -rn "localStorage.setItem.*token\|localStorage.setItem.*password" src/
grep -rn "console.log\|console.error" src/ | grep -v ".test."

# Cari secret yang mungkin ter-commit
grep -rn "api_key\|apikey\|secret\|password\|token" src/ --include="*.js" -i | grep -v "// " | grep "="
```

---

> **Prinsip Utama:** Security di frontend adalah *defense in depth* — bukan garis pertahanan pertama,
> tapi juga bukan yang bisa diabaikan. Setiap celah di frontend adalah tiket masuk bagi attacker
> untuk mencoba lebih jauh ke sistem backend.
