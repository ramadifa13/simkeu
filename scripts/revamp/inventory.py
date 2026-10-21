#!/usr/bin/env python3
"""Inventory the pinned legacy commit, without executing application code.

Regex findings are navigation aids, not a PHP parser or proven permissions.
The output deliberately distinguishes inventory from manual/runtime verification.
"""
from collections import Counter, defaultdict
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
BASE = "6eab4d2fcbed51d831df6a913ebb04760f18bc35"


def git(*args):
    return subprocess.check_output(["git", "-C", str(ROOT), *args]).decode("utf-8")


def cell(value):
    return str(value).replace("|", "\\|").replace("\n", " ")


paths = git("ls-tree", "-r", "--name-only", BASE).splitlines()
own = [p for p in paths if not p.startswith("tcpdf/")]
sources = {p: git("show", f"{BASE}:{p}") for p in own
           if Path(p).suffix in {".php", ".js", ".sql", ".css", ".md"}}
php = {p: s for p, s in sources.items() if p.endswith(".php")}
pages = {p: s for p, s in php.items()
         if p.split("/")[0] in {"admin", "teller", "anggota", "api"} or "/" not in p}
counts = Counter(p.split("/")[0] if "/" in p else "root" for p in own)
lines = ["# Inventaris baseline legacy", "",
         f"Commit `{BASE}`. Dibuat dengan `python3 scripts/revamp/inventory.py`.", "",
         "**Pemindaian statis otomatis; tidak berarti semua halaman sudah dibaca mendalam, "
         "dijalankan, atau lulus parity.** Guard/action/table di bawah merupakan kandidat regex; "
         "guard pada include, dynamic SQL, dan field form dapat mengubah maknanya.", "",
         f"{len(paths)} file tracked; {len(own)} di luar TCPDF; {len(php)} file PHP aplikasi/test; "
         f"{len(pages)} kandidat entrypoint web.", "", "## Jumlah file per area (tanpa TCPDF)", "",
         "| Area | File |", "|---|---:|"]
lines += [f"| {area} | {count} |" for area, count in sorted(counts.items())]
lines += ["", "## Kandidat halaman dan jejak source", "",
          "Setiap baris perlu review runtime, policy, filter, output, dan pemetaan layar baru.", "",
          "| Source | Baris | Guard lokal terdeteksi | Field POST terdeteksi | Include/require literal |",
          "|---|---:|---|---|---|"]
for path, source in sorted(pages.items()):
    guards = sorted(set(re.findall(r"\b(?:isAdmin|isTeller|isAnggota|checkAdmin|checkTeller|checkAnggota|checkAuth|checkRole)\s*\(", source)))
    posts = sorted(set(re.findall(r"\$_POST\[['\"]([^'\"]+)['\"]\]", source)))
    includes = sorted(set(re.findall(r"(?:include|require)(?:_once)?\s+(?:__DIR__\s*\.\s*)?['\"]([^'\"]+)['\"]", source)))
    values = [f"`{path}`", len(source.splitlines()), ", ".join(guards) or "melalui include / review",
              ", ".join(posts) or "—", ", ".join(includes) or "—"]
    lines.append("| " + " | ".join(map(cell, values)) + " |")
lines += ["", "## Referensi tabel SQL — perlu verifikasi DDL", "",
          "Nama yang tidak didefinisikan CREATE pada source bukan otomatis tabel hilang di produksi. "
          "Parser ini tidak memahami dynamic SQL; hasil tidak boleh dipakai membuat schema otomatis.", "",
          "| Kandidat tabel | CREATE terlihat | Source pemakai |", "|---|---|---|"]
refs = defaultdict(set)
creates = defaultdict(set)
for path, source in sources.items():
    if not path.endswith((".php", ".sql")):
        continue
    for table in re.findall(r"\b(?:FROM|JOIN|INTO|UPDATE|ALTER\s+TABLE)\s+`?([a-z_][a-z_0-9]*)", source):
        refs[table].add(path)
    for table in re.findall(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z_0-9]*)", source, re.I):
        creates[table].add(path)
for table in sorted(refs):
    lines.append(f"| `{table}` | {cell(', '.join(sorted(creates[table])) or 'Tidak terdeteksi')} | "
                 f"{cell(', '.join(sorted(refs[table])))} |")
lines += ["", "## File identik byte-for-byte (tanpa TCPDF)", "",
          "Alias kecil memang dapat berbagi implementasi; jangan menghapus alias sebelum "
          "semua link/cetak/redirect dipetakan.", ""]
groups = defaultdict(list)
for row in git("ls-tree", "-r", BASE).splitlines():
    meta, path = row.split("\t", 1)
    if path in own:
        groups[meta.split()[2]].append(path)
for group in groups.values():
    if len(group) > 1:
        lines.append("- " + ", ".join(f"`{p}`" for p in group))
lines += ["", "## Semua file aplikasi di luar TCPDF", ""]
lines += [f"- `{p}`" for p in own]
out = ROOT / "docs/revamp/inventory.md"
out.write_text("\n".join(lines) + "\n", encoding="utf-8")
print(f"Wrote {out.relative_to(ROOT)}: {len(pages)} web candidates, {len(refs)} SQL name candidates.")
