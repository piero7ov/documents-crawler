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
# CONFIG (defaults)
# =========================
DEFAULT_START_URL = "https://eerlab.berkeley.edu/"
DEFAULT_MAX_PAGES = 50
TIMEOUT = 10

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

TRACKING_KEYS = {"fbclid", "gclid", "mc_cid", "mc_eid"}
TRACKING_PREFIXES = ("utm_",)


# =========================
# NORMALIZADOR
# =========================
def normalize_url(u: str) -> str:
    if not u:
        return u

    u = urldefrag(u).url
    p = urlsplit(u)

    scheme = (p.scheme or "").lower()
    netloc = (p.netloc or "").lower()

    if scheme == "http":
        scheme = "https"

    if netloc.startswith("www."):
        netloc = netloc[4:]

    path = p.path or "/"
    if path != "/" and path.endswith("/"):
        path = path[:-1]

    q = []
    if p.query:
        for k, v in parse_qsl(p.query, keep_blank_values=True):
            kl = k.lower()
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

def table_exists(cur, table_name: str) -> bool:
    cur.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1;",
        (table_name,)
    )
    return cur.fetchone() is not None

def get_title_from_html(content: bytes) -> str:
    try:
        t = html.fromstring(content)
        title = t.xpath("//title/text()")
        if title:
            return title[0].strip()
    except Exception:
        pass
    return ""


# =========================
# INPUT POR CONSOLA
# =========================
start_in = input(f"START_URL (Enter para default: {DEFAULT_START_URL}): ").strip()
if start_in == "":
    START_URL = DEFAULT_START_URL
else:
    if not start_in.startswith(("http://", "https://")):
        start_in = "https://" + start_in
    START_URL = start_in

max_in = input(f"MAX_PAGES (Enter para default: {DEFAULT_MAX_PAGES}): ").strip()
if max_in == "":
    MAX_PAGES = DEFAULT_MAX_PAGES
else:
    try:
        MAX_PAGES = int(max_in)
        if MAX_PAGES <= 0:
            MAX_PAGES = DEFAULT_MAX_PAGES
    except Exception:
        MAX_PAGES = DEFAULT_MAX_PAGES

START_URL = normalize_url(START_URL)
base_netloc = urlparse(START_URL).netloc


# =========================
# SQLITE
# =========================
db_path = os.path.join(os.path.dirname(__file__), "docs.sqlite")

conn = sqlite3.connect(db_path, timeout=30)
conn.execute("PRAGMA busy_timeout = 30000;")
conn.execute("PRAGMA journal_mode = WAL;")
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

cur.execute("""
CREATE TABLE IF NOT EXISTS pages (
  url_page     TEXT PRIMARY KEY,
  status_code  INTEGER,
  content_type TEXT,
  title        TEXT,
  first_seen   TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen    TEXT NOT NULL DEFAULT (datetime('now'))
);
""")
cur.execute("CREATE INDEX IF NOT EXISTS idx_pages_status ON pages(status_code);")
conn.commit()


# =========================
# REANUDAR (visited desde SQLite, pero solo del mismo dominio)
# =========================
visited_pages = set()

if table_exists(cur, "pages"):
    cur.execute("SELECT url_page FROM pages;")
    for (u,) in cur.fetchall():
        if not u:
            continue
        # evitar que páginas de otros dominios bloqueen un start_url nuevo
        if is_same_domain(u, base_netloc):
            visited_pages.add(u)

queue = deque([START_URL])

inserted_docs = 0
seen_docs_this_run = 0
pages_new = 0
pages_updated = 0
pages_visited_this_run = 0


# =========================
# CRAWL
# =========================
while queue and pages_visited_this_run < MAX_PAGES:
    page_url = queue.popleft()

    if page_url in visited_pages:
        continue

    pages_visited_this_run += 1
    print(f"\n[{pages_visited_this_run}/{MAX_PAGES}] Visitando: {page_url}")

    time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    try:
        r = requests.get(page_url, timeout=TIMEOUT, headers=UA_HEADERS)
    except Exception as e:
        print("Error request:", e)
        continue

    status = r.status_code
    ctype = (r.headers.get("Content-Type") or "")
    ctype_low = ctype.lower()

    title = ""
    if "text/html" in ctype_low or "application/xhtml" in ctype_low:
        title = get_title_from_html(r.content)

    cur.execute("""
    INSERT INTO pages (url_page, status_code, content_type, title)
    VALUES (?, ?, ?, ?)
    ON CONFLICT(url_page) DO UPDATE SET
      status_code = excluded.status_code,
      content_type = excluded.content_type,
      title = excluded.title,
      last_seen = datetime('now');
    """, (page_url, status, ctype, title))

    if cur.rowcount == 1:
        pages_new += 1
    else:
        pages_updated += 1

    conn.commit()
    visited_pages.add(page_url)

    if status >= 400:
        print("HTTP:", status)
        continue

    if "text/html" not in ctype_low and "application/xhtml" not in ctype_low:
        print("No es HTML (Content-Type:", ctype.split(";")[0], ")")
        continue

    try:
        tree = html.fromstring(r.content)
        hrefs = tree.xpath("//a/@href")
    except Exception as e:
        print("Error parseando HTML:", e)
        continue

    print("Links encontrados:", len(hrefs))

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

        if not is_same_domain(abs_url, base_netloc):
            continue
        if is_skip_asset(abs_url):
            continue

        if abs_url not in visited_pages and abs_url not in queue:
            queue.append(abs_url)


# =========================
# FINAL
# =========================
cur.execute("SELECT COUNT(*) FROM docs;")
total_docs = cur.fetchone()[0]
cur.execute("SELECT COUNT(*) FROM pages;")
total_pages = cur.fetchone()[0]
conn.close()

print("\n==============================")
print("RESUMEN")
print("START_URL:", START_URL)
print("MAX_PAGES:", MAX_PAGES)
print("PÁGINAS VISITADAS (esta ejecución):", pages_visited_this_run)
print("PÁGINAS NUEVAS GUARDADAS:", pages_new)
print("PÁGINAS ACTUALIZADAS:", pages_updated)
print("PÁGINAS TOTALES EN BD:", total_pages)
print("DOCS DETECTADOS (esta ejecución):", seen_docs_this_run)
print("DOCS NUEVOS INSERTADOS:", inserted_docs)
print("DOCS TOTALES EN BD:", total_docs)
print("DB:", db_path)
print("==============================")