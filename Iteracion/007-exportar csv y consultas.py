import os
import sqlite3
import csv

# Ruta a tu base de datos (la que ya se generó en 006)
db_path = os.path.join(os.path.dirname(__file__), "docs.sqlite")

# CSV de salida
csv_path = os.path.join(os.path.dirname(__file__), "docs_export.csv")

if not os.path.exists(db_path):
    raise FileNotFoundError(f"No existe la base de datos en: {db_path}")

conn = sqlite3.connect(db_path)
cur = conn.cursor()

# =========================
# 1) Resumen por extensión
# =========================
print("=== RESUMEN POR EXT ===")
cur.execute("""
SELECT ext, COUNT(*) AS total
FROM docs
GROUP BY ext
ORDER BY total DESC, ext ASC;
""")
rows = cur.fetchall()

if not rows:
    print("No hay registros en la tabla docs.")
else:
    for ext, total in rows:
        print(f"{ext}: {total}")

# =========================
# 2) Exportar a CSV
# =========================
cur.execute("""
SELECT url_doc, ext, found_in, how_detected, first_seen
FROM docs
ORDER BY first_seen DESC;
""")
data = cur.fetchall()

with open(csv_path, "w", newline="", encoding="utf-8") as f:
    w = csv.writer(f)
    # cabecera
    w.writerow(["url_doc", "ext", "found_in", "how_detected", "first_seen"])
    # filas
    w.writerows(data)

conn.close()

print("\n Export listo:")
print(csv_path)
print("TOTAL FILAS EXPORTADAS:", len(data))