# pip3 install requests lxml --break-system-packages

import os
import sqlite3
import time
import random
from collections import deque
from urllib.parse import urljoin, urlparse, urldefrag, urlsplit, urlunsplit, parse_qsl, urlencode

import requests
from lxml import html


# =========================
# CONFIG
# =========================
START_URL = "https://eerlab.berkeley.edu/"
MAX_PAGES = 50
TIMEOUT = 10

# Delay suave (anti “spameo”)
DELAY_MIN = 0.6
DELAY_MAX = 1.4

UA_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120"
}

DOC_EXTS = {
    ".pdf",
    ".doc", ".docx",
    ".xls", ".xlsx",
    ".ppt", ".pptx",
    ".odt", ".ods", ".odp",
    ".csv", ".txt",
    ".zip", ".rar", ".7z"
}

SKIP_EXTS = {
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico",
    ".css", ".js", ".map",
    ".mp4", ".webm", ".mp3", ".wav",
    ".woff", ".woff2", ".ttf", ".otf", ".eot"
}

# Tracking típico que genera "duplicados falsos"
TRACKING_KEYS = {"fbclid", "gclid", "mc_cid", "mc_eid"}
TRACKING_PREFIXES = ("utm_",)


# =========================
# NORMALIZADOR
# =========================
def normalize_url(u: str) -> str:
    """
    Normaliza URLs para evitar duplicados:
    - quita #fragment
    - fuerza https si venía http
    - lower en scheme y netloc
    - quita www.
    - limpia tracking params (utm_*, fbclid, gclid...)
    - ordena query params
    - quita trailing slash (excepto '/')
    """
    if not u:
        return u

    # 1) fuera fragmentos (#...)
    u = urldefrag(u).url

    p = urlsplit(u)

    scheme = (p.scheme or "").lower()
    netloc = (p.netloc or "").lower()

    # 2) http -> https (opcional, pero reduce duplicados)
    if scheme == "http":
        scheme = "https"

    # 3) quitar www. (reduce duplicados)
    if netloc.startswith("www."):
        netloc = netloc[4:]

    # 4) path sin trailing slash (salvo root)
    path = p.path or "/"
    if path != "/" and path.endswith("/"):
        path = path[:-1]

    # 5) limpiar + ordenar query params
    q = []
    if p.query:
        for k, v in parse_qsl(p.query, keep_blank_values=True):
            kl = k.lower()

            # drop tracking
            if kl in TRACKING_KEYS:
                continue
            if any(kl.startswith(pref) for pref in TRACKING_PREFIXES):
                continue

            q.append((k, v))

        q.sort(key=lambda x: (x[0].lower(), x[1]))

    query = urlencode(q, doseq=True)

    return urlunsplit((scheme, netloc, path, query, ""))


# =========================
# HELPERS
# =========================
def get_ext(url_abs: str) -> str:
    try:
        path = urlparse(url_abs).path.lower()
    except Exception:
        return ""
    for ext in DOC_EXTS:
        if path.endswith(ext):
            return ext
    return ""

def is_skip_asset(url_abs: str) -> bool:
    try:
        path = urlparse(url_abs).path.lower()
    except Exception:
        return True
    for ext in SKIP_EXTS:
        if path.endswith(ext):
            return True
    return False

def is_same_domain(url_abs: str, base_netloc: str) -> bool:
    try:
        return urlparse(url_abs).netloc.lower().lstrip("www.") == base_netloc.lower().lstrip("www.")
    except Exception:
        return False

def normalize_abs(base_url: str, href: str) -> str:
    abs_url = urljoin(base_url, href)
    abs_url = urldefrag(abs_url).url
    return normalize_url(abs_url)


# =========================
# SQLITE
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
base_netloc = urlparse(normalize_url(START_URL)).netloc

queue = deque([normalize_url(START_URL)])
visited_pages = set()

inserted_docs = 0
seen_docs_this_run = 0

while queue and len(visited_pages) < MAX_PAGES:
    page_url = queue.popleft()

    if page_url in visited_pages:
        continue
    visited_pages.add(page_url)

    print(f"\n[{len(visited_pages)}/{MAX_PAGES}] Visitando: {page_url}")

    # Delay suave
    time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    # Request
    try:
        r = requests.get(page_url, timeout=TIMEOUT, headers=UA_HEADERS)
    except Exception as e:
        print("Error request:", e)
        continue

    if r.status_code >= 400:
        print("HTTP:", r.status_code)
        continue

    content_type = (r.headers.get("Content-Type") or "").lower()
    if "text/html" not in content_type and "application/xhtml" not in content_type:
        print("No es HTML (Content-Type:", content_type.split(";")[0], ")")
        continue

    # Parse
    try:
        tree = html.fromstring(r.content)
        hrefs = tree.xpath("//a/@href")
    except Exception as e:
        print("Error parseando HTML:", e)
        continue

    print("Links encontrados:", len(hrefs))

    # Procesar links
    for href in hrefs:
        href = (href or "").strip()
        if not href:
            continue

        low = href.lower()
        if low.startswith(("mailto:", "tel:", "javascript:")):
            continue

        abs_url = normalize_abs(page_url, href)

        if not abs_url.startswith(("http://", "https://")):
            continue

        # A) Docs por extensión -> guardar (ya normalizada)
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

        # B) Si no es doc -> cola (mismo dominio + no asset)
        if not is_same_domain(abs_url, base_netloc):
            continue
        if is_skip_asset(abs_url):
            continue

        if abs_url not in visited_pages and abs_url not in queue:
            queue.append(abs_url)

# Final
conn.commit()
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