# pip3 install requests lxml --break-system-packages

import os
import sqlite3
import requests
from lxml import html
from urllib.parse import urljoin, urlparse


url = "https://eerlab.berkeley.edu/pdf/"

DOC_EXTS = {
    ".pdf",
    ".doc", ".docx",
    ".xls", ".xlsx",
    ".ppt", ".pptx",
    ".odt", ".ods", ".odp",
    ".csv", ".txt",
    ".zip", ".rar", ".7z"
}

def get_ext(link_abs: str) -> str:
    """Devuelve la extensión del path si coincide con DOC_EXTS, si no devuelve ''."""
    try:
        path = urlparse(link_abs).path.lower()
    except Exception:
        return ""
    for ext in DOC_EXTS:
        if path.endswith(ext):
            return ext
    return ""

# =========================
# 1) Petición HTTP + parseo
# =========================
response = requests.get(
    url,
    timeout=10,
    headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120"}
)
response.raise_for_status()

tree = html.fromstring(response.content)

title = tree.xpath("//title/text()")
print("WEB TITLE:")
print(title[0].strip() if title else "No title found")

links = tree.xpath("//a/@href")

# ==========================================
# 2) Normalizar enlaces + filtrar documentos
# ==========================================
docs = []
vistos = set()

for href in links:
    href = (href or "").strip()
    if href == "":
        continue

    low = href.lower()
    if low.startswith(("mailto:", "tel:", "javascript:")):
        continue

    abs_url = urljoin(url, href)
    abs_url = abs_url.split("#", 1)[0]  # sin fragmento

    if abs_url in vistos:
        continue
    vistos.add(abs_url)

    ext = get_ext(abs_url)
    if ext:
        docs.append({
            "url_doc": abs_url,
            "ext": ext,
            "found_in": url,
            "how_detected": "ext"
        })

print("\nTOTAL LINKS ENCONTRADOS:", len(links))
print("TOTAL DOCS ENCONTRADOS:", len(docs))

# =========================
# 3) Guardar en SQLite
# =========================
db_path = os.path.join(os.path.dirname(__file__), "docs.sqlite")

conn = sqlite3.connect(db_path)
cur = conn.cursor()

# Crear tabla si no existe
cur.execute("""
CREATE TABLE IF NOT EXISTS docs (
  url_doc      TEXT PRIMARY KEY,
  ext          TEXT NOT NULL,
  found_in     TEXT NOT NULL,
  how_detected TEXT NOT NULL,
  first_seen   TEXT NOT NULL DEFAULT (datetime('now'))
);
""")

# Índices
cur.execute("CREATE INDEX IF NOT EXISTS idx_docs_ext ON docs(ext);")
cur.execute("CREATE INDEX IF NOT EXISTS idx_docs_found_in ON docs(found_in);")

# Insertar
inserted = 0
for d in docs:
    cur.execute("""
    INSERT OR IGNORE INTO docs (url_doc, ext, found_in, how_detected)
    VALUES (?, ?, ?, ?);
    """, (d["url_doc"], d["ext"], d["found_in"], d["how_detected"]))

    # rowcount = 1 si insertó, 0 si ya existía
    if cur.rowcount == 1:
        inserted += 1

conn.commit()

# Conteo total en BD
cur.execute("SELECT COUNT(*) FROM docs;")
total_db = cur.fetchone()[0]

conn.close()

print("\n✅ SQLite guardado en:", db_path)
print("NUEVOS INSERTADOS:", inserted)
print("TOTAL EN BD:", total_db)

print("\nDOC LINKS (esta ejecución):")
for i, d in enumerate(docs, start=1):
    print(f"{i}: {d['url_doc']}")