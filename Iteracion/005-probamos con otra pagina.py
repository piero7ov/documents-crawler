# pip3 install requests lxml --break-system-packages

import requests
from lxml import html
from urllib.parse import urljoin, urlparse

url = "https://eerlab.berkeley.edu/pdf/"

# Extensiones típicas de documentos (puedes añadir/quitar)
DOC_EXTS = {
    ".pdf",
    ".doc", ".docx",
    ".xls", ".xlsx",
    ".ppt", ".pptx",
    ".odt", ".ods", ".odp",
    ".csv", ".txt",
    ".zip", ".rar", ".7z"
}

def parece_documento(link_abs: str) -> bool:
    """
    Devuelve True si la URL parece apuntar a un documento
    (por extensión en el path).
    """
    try:
        path = urlparse(link_abs).path.lower()
    except Exception:
        return False

    for ext in DOC_EXTS:
        if path.endswith(ext):
            return True
    return False

# 1) Petición HTTP
response = requests.get(
    url,
    timeout=10,
    headers={
        "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) Chrome/120"
    }
)
response.raise_for_status()

# 2) Parsear HTML
tree = html.fromstring(response.content)

# 3) Leer <title>
title = tree.xpath("//title/text()")
print("WEB TITLE:")
print(title[0].strip() if title else "No title found")

# 4) Leer todos los <a href="">
links = tree.xpath("//a/@href")

# 5) Normalizar a URL absoluta + filtrar los que parecen documentos
docs = []
vistos = set()

for href in links:
    href = (href or "").strip()
    if href == "":
        continue

    # ignorar cosas que no son navegación web real
    low = href.lower()
    if low.startswith("mailto:") or low.startswith("tel:") or low.startswith("javascript:"):
        continue

    abs_url = urljoin(url, href)

    # quitar fragmentos (#algo) para deduplicar mejor
    abs_url_no_frag = abs_url.split("#", 1)[0]

    if abs_url_no_frag in vistos:
        continue
    vistos.add(abs_url_no_frag)

    if parece_documento(abs_url_no_frag):
        docs.append(abs_url_no_frag)

print("\nTOTAL LINKS ENCONTRADOS:", len(links))
print("TOTAL DOCS ENCONTRADOS:", len(docs))

print("\nDOC LINKS:")
for i, d in enumerate(docs, start=1):
    print(f"{i}: {d}")