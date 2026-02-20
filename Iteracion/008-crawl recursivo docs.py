# pip3 install requests lxml --break-system-packages
# 008 - Crawl recursivo: visitar páginas internas y guardar links a documentos en SQLite

import os
import sqlite3
from collections import deque
from urllib.parse import urljoin, urlparse, urldefrag

import requests
from lxml import html


# =========================
# CONFIG
# =========================
START_URL = "https://eerlab.berkeley.edu/"  
MAX_PAGES = 50                                  
TIMEOUT = 10

UA_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120"
}

# Extensiones típicas de documentos
DOC_EXTS = {
    ".pdf",
    ".doc", ".docx",
    ".xls", ".xlsx",
    ".ppt", ".pptx",
    ".odt", ".ods", ".odp",
    ".csv", ".txt",
    ".zip", ".rar", ".7z"
}

# Extensiones que NO queremos meter a la cola (assets típicos)
SKIP_EXTS = {
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico",
    ".css", ".js", ".map",
    ".mp4", ".webm", ".mp3", ".wav",
    ".woff", ".woff2", ".ttf", ".otf", ".eot"
}


# =========================
# HELPERS
# =========================
def get_ext(url_abs: str) -> str:
    """Devuelve extensión de documento si coincide con DOC_EXTS, si no ''."""
    try:
        path = urlparse(url_abs).path.lower()
    except Exception:
        return ""
    for ext in DOC_EXTS:
        if path.endswith(ext):
            return ext
    return ""

def is_skip_asset(url_abs: str) -> bool:
    """True si es un asset típico (imagen, css, js, etc.) para no meterlo a la cola."""
    try:
        path = urlparse(url_abs).path.lower()
    except Exception:
        return True
    for ext in SKIP_EXTS:
        if path.endswith(ext):
            return True
    return False

def is_same_domain(url_abs: str, base_netloc: str) -> bool:
    """True si el netloc es el mismo (mismo dominio)."""
    try:
        return urlparse(url_abs).netloc == base_netloc
    except Exception:
        return False

def normalize_abs(base_url: str, href: str) -> str:
    """
    Convierte href -> absoluta usando base_url, quita fragmentos (#...).
    """
    abs_url = urljoin(base_url, href)
    abs_url = urldefrag(abs_url).url  # quita #fragment
    return abs_url


# =========================
# SQLITE (misma DB que 006)
# =========================
db_path = os.path.join(os.path.dirname(__file__), "docs.sqlite")
conn = sqlite3.connect(db_path)
cur = conn.cursor()

cur.execute("""
CREATE TABLE IF NOT EXISTS docs (
  url_doc      TEXT PRIMARY KEY,
  ext          TEXT NOT NULL,
  found_in     TEXT NOT NULL,
  how_detected TEXT NOT NULL,
  first_seen   TEXT NOT NULL DEFAULT (datetime('now'))
);
""")
cur.execute("CREATE INDEX IF NOT EXISTS idx_docs_ext ON docs(ext);")
cur.execute("CREATE INDEX IF NOT EXISTS idx_docs_found_in ON docs(found_in);")
conn.commit()


# =========================
# CRAWL (cola + visited)
# =========================
base_netloc = urlparse(START_URL).netloc

queue = deque([START_URL])
visited_pages = set()

inserted_docs = 0
seen_docs_this_run = 0

while queue and len(visited_pages) < MAX_PAGES:
    page_url = queue.popleft()

    # Evitar repetir páginas
    if page_url in visited_pages:
        continue
    visited_pages.add(page_url)

    print(f"\n[{len(visited_pages)}/{MAX_PAGES}] Visitando: {page_url}")

    # 1) Descargar página
    try:
        r = requests.get(page_url, timeout=TIMEOUT, headers=UA_HEADERS)
    except Exception as e:
        print("Error request:", e)
        continue

    if r.status_code >= 400:
        print("HTTP:", r.status_code)
        continue

    content_type = (r.headers.get("Content-Type") or "").lower()

    # Solo parseamos HTML
    if "text/html" not in content_type and "application/xhtml" not in content_type:
        print("No es HTML (Content-Type:", content_type.split(";")[0], ")")
        continue

    # 2) Parsear HTML y extraer enlaces
    try:
        tree = html.fromstring(r.content)
        hrefs = tree.xpath("//a/@href")
    except Exception as e:
        print("  ❌ Error parseando HTML:", e)
        continue

    print("  Links encontrados:", len(hrefs))

    # 3) Procesar enlaces
    for href in hrefs:
        href = (href or "").strip()
        if not href:
            continue

        low = href.lower()
        if low.startswith(("mailto:", "tel:", "javascript:")):
            continue

        abs_url = normalize_abs(page_url, href)

        # Solo http(s)
        if not abs_url.startswith(("http://", "https://")):
            continue

        # A) Si es documento por extensión -> guardar en SQLite
        ext = get_ext(abs_url)
        if ext:
            seen_docs_this_run += 1
            cur.execute("""
                INSERT OR IGNORE INTO docs (url_doc, ext, found_in, how_detected)
                VALUES (?, ?, ?, ?);
            """, (abs_url, ext, page_url, "ext"))

            if cur.rowcount == 1:
                inserted_docs += 1
            continue

        # B) Si NO es documento -> si es del mismo dominio y no es asset, meter en cola
        if not is_same_domain(abs_url, base_netloc):
            continue

        if is_skip_asset(abs_url):
            continue

        # Dedup por visited + cola (para no meter 100 veces lo mismo)
        if abs_url not in visited_pages and abs_url not in queue:
            queue.append(abs_url)

# Guardar cambios SQLite
conn.commit()

# Totales en DB
cur.execute("SELECT COUNT(*) FROM docs;")
total_db = cur.fetchone()[0]

conn.close()

print("\n==============================")
print("RESUMEN")
print("PÁGINAS VISITADAS:", len(visited_pages))
print("DOCS DETECTADOS (esta ejecución):", seen_docs_this_run)
print("DOCS NUEVOS INSERTADOS:", inserted_docs)
print("TOTAL DOCS EN BD:", total_db)
print("DB:", db_path)
print("==============================")