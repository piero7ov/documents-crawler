# pip3 install requests lxml --break-system-packages

import os
import sqlite3
import time
import random
from collections import deque
from urllib.parse import (
    urljoin, urlparse, urldefrag,
    urlsplit, urlunsplit, parse_qsl, urlencode
)

import requests
from lxml import html


# ============================================================
# DOCUMENTS-CRAWLER (fin educativo)
# ------------------------------------------------------------
# Objetivo:
#   - Recorrer (crawl) páginas de un sitio web (mismo dominio)
#   - Extraer enlaces <a href="..."> que parezcan documentos
#     (por extensión: .pdf, .docx, .xlsx, etc.)
#   - Guardar resultados en SQLite:
#       * docs  -> documentos detectados
#       * pages -> páginas visitadas (para poder reanudar)
#
# Características clave:
#   - Entrada por consola: START_URL y MAX_PAGES
#   - Normalización de URLs para reducir duplicados:
#       * quitar #fragment
#       * limpiar tracking utm_* / fbclid / gclid ...
#       * quitar www.
#       * forzar https (si venía http)
#       * ordenar query params
#   - Delay aleatorio (no invasivo) entre requests
#   - Reanudación: carga "visited" desde tabla pages (solo mismo dominio)
# ============================================================


# =========================
# CONFIG (defaults)
# =========================
# URL inicial sugerida si el usuario no escribe nada
DEFAULT_START_URL = "https://eerlab.berkeley.edu/"

# Límite de páginas HTML a visitar en ESTA ejecución
DEFAULT_MAX_PAGES = 50

# Timeout (segundos) para requests HTTP
TIMEOUT = 10

# Delay suave (anti-spam): entre estos segundos en cada request
DELAY_MIN = 0.6
DELAY_MAX = 1.4

# Cabecera User-Agent para evitar bloqueos básicos
UA_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120"
}

# Extensiones típicas de documentos a detectar
DOC_EXTS = {
    ".pdf",
    ".doc", ".docx",
    ".xls", ".xlsx",
    ".ppt", ".pptx",
    ".odt", ".ods", ".odp",
    ".csv", ".txt",
    ".zip", ".rar", ".7z"
}

# Extensiones que NO queremos encolar como páginas a visitar
# (assets estáticos que no aportan nuevos links útiles)
SKIP_EXTS = {
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".ico",
    ".css", ".js", ".map",
    ".mp4", ".webm", ".mp3", ".wav",
    ".woff", ".woff2", ".ttf", ".otf", ".eot"
}

# Parámetros típicos de tracking que generan duplicados falsos
TRACKING_KEYS = {"fbclid", "gclid", "mc_cid", "mc_eid"}
TRACKING_PREFIXES = ("utm_",)


# =========================
# NORMALIZADOR
# =========================
def normalize_url(u: str) -> str:
    """
    Normaliza una URL para reducir duplicados.

    Reglas:
      1) Quita fragmentos: https://x.com/a#section -> https://x.com/a
      2) scheme/netloc en minúsculas
      3) Fuerza https si era http (opcional, pero reduce duplicados)
      4) Quita "www."
      5) Quita trailing slash en paths (excepto "/")
      6) Limpia query params de tracking (utm_*, fbclid, gclid...)
      7) Ordena query params para que ?b=2&a=1 == ?a=1&b=2
      8) Devuelve URL sin fragment

    Nota:
      No “comprueba” si la URL existe; solo la normaliza a nivel string.
    """
    if not u:
        return u

    # 1) Eliminar fragmento (#algo)
    u = urldefrag(u).url
    p = urlsplit(u)

    # 2) Normalizar scheme y netloc a lower
    scheme = (p.scheme or "").lower()
    netloc = (p.netloc or "").lower()

    # 3) Forzar https si viene http (reduce duplicados entre http/https)
    if scheme == "http":
        scheme = "https"

    # 4) Quitar www.
    if netloc.startswith("www."):
        netloc = netloc[4:]

    # 5) Normalizar path (sin trailing slash salvo root)
    path = p.path or "/"
    if path != "/" and path.endswith("/"):
        path = path[:-1]

    # 6-7) Limpiar y ordenar query params
    q = []
    if p.query:
        for k, v in parse_qsl(p.query, keep_blank_values=True):
            kl = k.lower()

            # descartar tracking tipo fbclid, gclid, etc.
            if kl in TRACKING_KEYS:
                continue

            # descartar utm_*
            if any(kl.startswith(pref) for pref in TRACKING_PREFIXES):
                continue

            q.append((k, v))

        # ordenar para hacer la URL estable
        q.sort(key=lambda x: (x[0].lower(), x[1]))

    query = urlencode(q, doseq=True)

    # 8) reconstruir URL (sin fragment)
    return urlunsplit((scheme, netloc, path, query, ""))


# =========================
# HELPERS
# =========================
def get_ext(url_abs: str) -> str:
    """
    Devuelve la extensión detectada si la URL termina en una extensión de DOC_EXTS.
    Ejemplo:
      https://site.com/file/report.pdf -> ".pdf"
    Si no detecta, devuelve "".
    """
    try:
        path = urlparse(url_abs).path.lower()
    except Exception:
        return ""

    for ext in DOC_EXTS:
        if path.endswith(ext):
            return ext
    return ""


def is_skip_asset(url_abs: str) -> bool:
    """
    True si el link apunta a un asset que no queremos visitar (css/js/img/fonts/video...).
    Esto evita meter ruido en la cola del crawler.
    """
    try:
        path = urlparse(url_abs).path.lower()
    except Exception:
        return True

    for ext in SKIP_EXTS:
        if path.endswith(ext):
            return True
    return False


def is_same_domain(url_abs: str, base_netloc: str) -> bool:
    """
    True si url_abs pertenece al mismo dominio que base_netloc.

    Importante:
      - Quita 'www.' para comparar de forma más estable.
      - Evita que el crawler se “escape” a otros dominios.
    """
    try:
        return urlparse(url_abs).netloc.lower().lstrip("www.") == base_netloc.lower().lstrip("www.")
    except Exception:
        return False


def normalize_abs(base_url: str, href: str) -> str:
    """
    Convierte un href relativo a URL absoluta + la normaliza.
    - urljoin resuelve rutas relativas
    - urldefrag quita #fragment
    - normalize_url aplica las reglas de normalización
    """
    abs_url = urljoin(base_url, href)
    abs_url = urldefrag(abs_url).url
    return normalize_url(abs_url)


def table_exists(cur, table_name: str) -> bool:
    """
    Devuelve True si existe una tabla en SQLite.
    Se usa para comprobar si 'pages' existe antes de reanudar visited.
    """
    cur.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1;",
        (table_name,)
    )
    return cur.fetchone() is not None


def get_title_from_html(content: bytes) -> str:
    """
    Extrae el <title> del HTML (si existe).
    Se guarda en la tabla pages como metadato útil.
    """
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
# Pedimos START_URL:
# - Si el usuario solo presiona Enter: usamos el default.
# - Si no escribe http/https: asumimos https://
start_in = input(f"START_URL (Enter para default: {DEFAULT_START_URL}): ").strip()
if start_in == "":
    START_URL = DEFAULT_START_URL
else:
    if not start_in.startswith(("http://", "https://")):
        start_in = "https://" + start_in
    START_URL = start_in

# Pedimos MAX_PAGES:
# - Si Enter: default
# - Si escribe algo inválido: default
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

# Normalizamos START_URL una vez definido
START_URL = normalize_url(START_URL)

# Dominio base para evitar “escape” a otros sitios
base_netloc = urlparse(START_URL).netloc


# =========================
# SQLITE
# =========================
# docs.sqlite se crea automáticamente si no existe
db_path = os.path.join(os.path.dirname(__file__), "docs.sqlite")

# timeout + busy_timeout: reduce errores "database is locked"
# journal_mode WAL: mejor concurrencia (pueden aparecer archivos -wal y -shm)
conn = sqlite3.connect(db_path, timeout=30)
conn.execute("PRAGMA busy_timeout = 30000;")
conn.execute("PRAGMA journal_mode = WAL;")
cur = conn.cursor()

# Tabla docs: documentos detectados (evita duplicados por PK url_doc)
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

# Tabla pages: páginas visitadas (sirve para reanudar y para debug/estadísticas)
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
# REANUDAR (visited desde SQLite)
# =========================
# Cargamos visited_pages a partir de la tabla pages para NO repetir visitas.
# Importante:
#   - Filtramos solo páginas del mismo dominio, así puedes usar otro START_URL
#     en el futuro sin que te bloquee visited de un dominio antiguo.
visited_pages = set()

if table_exists(cur, "pages"):
    cur.execute("SELECT url_page FROM pages;")
    for (u,) in cur.fetchall():
        if not u:
            continue
        if is_same_domain(u, base_netloc):
            visited_pages.add(u)

# Cola BFS (breadth-first): empezamos por START_URL
queue = deque([START_URL])

# Contadores de resumen (para imprimir al final)
inserted_docs = 0
seen_docs_this_run = 0
pages_new = 0
pages_updated = 0
pages_visited_this_run = 0


# =========================
# CRAWL (cola + visited)
# =========================
# Estrategia:
#   - Sacamos una URL de la cola
#   - Si ya fue visitada, la saltamos
#   - Si es HTML, extraemos todos los <a href>
#   - Si href parece documento -> guardamos en docs
#   - Si href parece página interna -> la encolamos
while queue and pages_visited_this_run < MAX_PAGES:
    page_url = queue.popleft()

    # Evitar repetir
    if page_url in visited_pages:
        continue

    pages_visited_this_run += 1
    print(f"\n[{pages_visited_this_run}/{MAX_PAGES}] Visitando: {page_url}")

    # Delay suave para no saturar el servidor
    time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

    # 1) Request
    try:
        r = requests.get(page_url, timeout=TIMEOUT, headers=UA_HEADERS)
    except Exception as e:
        print("Error request:", e)
        continue

    # 2) Metadatos de la respuesta
    status = r.status_code
    ctype = (r.headers.get("Content-Type") or "")
    ctype_low = ctype.lower()

    # Si es HTML, intentamos sacar <title>
    title = ""
    if "text/html" in ctype_low or "application/xhtml" in ctype_low:
        title = get_title_from_html(r.content)

    # 3) Guardar/actualizar página en DB (reanudar + historial)
    # - INSERT si no existe
    # - UPDATE si ya existía (refresh last_seen + status/ctype/title)
    cur.execute("""
    INSERT INTO pages (url_page, status_code, content_type, title)
    VALUES (?, ?, ?, ?)
    ON CONFLICT(url_page) DO UPDATE SET
      status_code = excluded.status_code,
      content_type = excluded.content_type,
      title = excluded.title,
      last_seen = datetime('now');
    """, (page_url, status, ctype, title))

    # Nota:
    #   rowcount en sqlite3 puede variar según versión,
    #   pero lo usamos solo como métrica aproximada.
    if cur.rowcount == 1:
        pages_new += 1
    else:
        pages_updated += 1

    conn.commit()
    visited_pages.add(page_url)

    # 4) Si hay error HTTP, no seguimos parseando
    if status >= 400:
        print("HTTP:", status)
        continue

    # 5) Si NO es HTML, no hay links que extraer
    if "text/html" not in ctype_low and "application/xhtml" not in ctype_low:
        print("No es HTML (Content-Type:", ctype.split(";")[0], ")")
        continue

    # 6) Parsear HTML y extraer hrefs
    try:
        tree = html.fromstring(r.content)
        hrefs = tree.xpath("//a/@href")
    except Exception as e:
        print("Error parseando HTML:", e)
        continue

    print("Links encontrados:", len(hrefs))

    # 7) Procesar cada href
    for href in hrefs:
        href = (href or "").strip()
        if not href:
            continue

        # Ignorar pseudo-links o contactos
        low = href.lower()
        if low.startswith(("mailto:", "tel:", "javascript:")):
            continue

        # Href -> URL absoluta normalizada
        abs_url = normalize_abs(page_url, href)

        # Solo http(s)
        if not abs_url.startswith(("http://", "https://")):
            continue

        # A) Si parece documento por extensión -> guardamos en docs
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

        # B) Si NO es doc, podría ser una página para seguir crawleando
        # - Solo mismo dominio
        # - No assets (css/js/img/etc.)
        if not is_same_domain(abs_url, base_netloc):
            continue
        if is_skip_asset(abs_url):
            continue

        # Evitar encolar duplicados
        if abs_url not in visited_pages and abs_url not in queue:
            queue.append(abs_url)


# =========================
# FINAL (resumen)
# =========================
# Métricas globales acumuladas en la BD
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