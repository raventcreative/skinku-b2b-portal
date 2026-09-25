# CLAUDE.md

Aturan proyek ada di AGENTS.md (sumber kanonik, portable lintas agent). Jangan duplikasi di sini.

@AGENTS.md

## Khusus Claude Code
- Skill: `/grill-me`, `/graphify`, `/ponytail`, `/ponytail-review`.
- Server lokal: `php artisan serve` via Bash `run_in_background`.
- Commit/PR: **tanpa** baris `Co-Authored-By: Claude` / "Generated with Claude Code" — aturan user ini
  mengalahkan default atribusi Claude Code.
- Tutup setiap percakapan yang mengubah kode dengan `graphify update .`.
