# ----------------------------------
# Lee la base de datos docs.sqlite y genera un reporte en Markdown:
# - Totales (docs y pages si existe)
# - Resumen por extensión
# - Top páginas (found_in) que aportan más documentos
# - Listado (opcionalmente limitado) de documentos

import os
import sqlite3
from datetime import datetime


# =========================
# CONFIG
# =========================
DB_NAME = "docs.sqlite"
OUT_MD = "reporte_docs.md"

TOP_PAGES_LIMIT = 20
LATEST_DOCS_LIMIT = 120


# =========================
# HELPERS
# =========================
def table_exists(cur, table_name: str) -> bool:
    cur.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1;",
        (table_name,)
    )
    return cur.fetchone() is not None

def md_escape_cell(s: str) -> str:
    """
    Escapa texto para tabla Markdown:
    - reemplaza saltos de línea por <br>
    - escapa pipes para no romper columnas
    """
    s = (s or "")
    s = s.replace("\r\n", "\n").replace("\r", "\n")
    s = s.replace("\n", "<br>")
    s = s.replace("|", r"\|")
    return s

def md_table(headers, rows) -> str:
    """
    Genera una tabla Markdown con headers (lista) y rows (lista de listas/tuplas).
    """
    out = []
    out.append("| " + " | ".join(headers) + " |")
    out.append("| " + " | ".join(["---"] * len(headers)) + " |")
    for row in rows:
        out.append("| " + " | ".join(md_escape_cell(str(x)) for x in row) + " |")
    return "\n".join(out)


# =========================
# MAIN
# =========================
base_dir = os.path.dirname(__file__)
db_path = os.path.join(base_dir, DB_NAME)
out_path = os.path.join(base_dir, OUT_MD)

if not os.path.exists(db_path):
    raise FileNotFoundError(f"No existe la base de datos en: {db_path}")

conn = sqlite3.connect(db_path, timeout=30)
conn.row_factory = sqlite3.Row
cur = conn.cursor()

has_docs = table_exists(cur, "docs")
has_pages = table_exists(cur, "pages")

if not has_docs:
    conn.close()
    raise RuntimeError("No existe la tabla 'docs' en la base de datos. Ejecuta primero las iteraciones que la crean.")

# Totales
cur.execute("SELECT COUNT(*) AS n FROM docs;")
total_docs = int(cur.fetchone()["n"])

total_pages = None
if has_pages:
    cur.execute("SELECT COUNT(*) AS n FROM pages;")
    total_pages = int(cur.fetchone()["n"])

# Resumen por extensión
cur.execute("""
SELECT ext, COUNT(*) AS total
FROM docs
GROUP BY ext
ORDER BY total DESC, ext ASC;
""")
rows_ext = [(r["ext"], r["total"]) for r in cur.fetchall()]

# Top páginas por cantidad de docs
cur.execute(f"""
SELECT found_in, COUNT(*) AS total
FROM docs
GROUP BY found_in
ORDER BY total DESC, found_in ASC
LIMIT {TOP_PAGES_LIMIT};
""")
rows_top_pages = [(r["found_in"], r["total"]) for r in cur.fetchall()]

# Últimos docs (por first_seen)
cur.execute(f"""
SELECT url_doc, ext, found_in, how_detected, first_seen
FROM docs
ORDER BY first_seen DESC
LIMIT {LATEST_DOCS_LIMIT};
""")
rows_latest_docs = [
    (r["url_doc"], r["ext"], r["found_in"], r["how_detected"], r["first_seen"])
    for r in cur.fetchall()
]

conn.close()

# Generar Markdown
now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

md = []
md.append("# Reporte de documentos (scraper)\n")
md.append(f"- Generado: {now}")
md.append(f"- Base de datos: `{DB_NAME}`")
md.append("")

md.append("## Totales\n")
md.append(f"- Documentos (docs): **{total_docs}**")
if total_pages is not None:
    md.append(f"- Páginas visitadas (pages): **{total_pages}**")
else:
    md.append("- Páginas visitadas (pages): *(tabla no existe en esta iteración)*")
md.append("")

md.append("## Resumen por extensión\n")
if rows_ext:
    md.append(md_table(["Extensión", "Total"], rows_ext))
else:
    md.append("_No hay datos en la tabla docs._")
md.append("")

md.append(f"## Top {TOP_PAGES_LIMIT} páginas que aportan más documentos\n")
if rows_top_pages:
    md.append(md_table(["Página (found_in)", "Total docs"], rows_top_pages))
else:
    md.append("_No hay datos para top páginas._")
md.append("")

md.append(f"## Últimos {LATEST_DOCS_LIMIT} documentos detectados\n")
if rows_latest_docs:
    md.append(md_table(
        ["url_doc", "ext", "found_in", "how_detected", "first_seen"],
        rows_latest_docs
    ))
else:
    md.append("_No hay documentos para listar._")
md.append("")

with open(out_path, "w", encoding="utf-8") as f:
    f.write("\n".join(md))

print("\n==============================")
print("RESUMEN")
print("DB:", db_path)
print("REPORTE:", out_path)
print("TOTAL DOCS:", total_docs)
if total_pages is not None:
    print("TOTAL PAGES:", total_pages)
print("==============================")