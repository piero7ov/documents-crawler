# pip3 install requests lxml --break-system-packages

import requests
from lxml import html

url = "https://www.sanjoseobreropiura.com/"

# 1. Petición HTTP
response = requests.get(
    url,
    timeout=10,
    headers={
        "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) Chrome/120"
    }
)
response.raise_for_status()

# 2. Parsear HTML
tree = html.fromstring(response.content)

# 3. Leer <title>
title = tree.xpath("//title/text()")
print("WEB TITLE:")
print(title[0].strip() if title else "No title found")

# 4. Leer todos los <a href="">
links = tree.xpath("//a/@href")

print("\nLINKS:")
for i, href in enumerate(links, start=1):
    print(f"{i}: {href}")

