# documents-crawler
> **Herramienta para scrapear documentos (fin educativo)**

`documents-crawler` es un mini proyecto que **rastrea (crawl) un sitio web** y detecta enlaces que **parecen documentos** (PDF, DOCX, XLSX, etc.) guardándolos en **SQLite**.  
Incluye un **panel web en PHP** para **listar, filtrar, validar enlaces (rotos/OK), descargar** y hacer **acciones CRUD ligeras** (favoritos, ocultar, eliminar).

---

## Estructura del proyecto

```

documents-crawler/
├─ crawler input.py
├─ index.php
├─ styles.css
└─ docs.sqlite

````

- **crawler input.py** → crawler por consola (pide `START_URL` y `MAX_PAGES`) y guarda resultados en `docs.sqlite`
- **docs.sqlite** → base de datos SQLite (tablas `docs` y `pages`)
- **index.php** → panel web para consultar/gestionar lo guardado
- **styles.css** → estilos del panel (tema claro + color principal khaki)

---

## Requisitos

### Para el crawler (Python)
- **Python 3**
- Paquetes:
  ```bash
  pip install requests lxml
````

### Para el panel (PHP)

* **PHP** (por ejemplo con **XAMPP**) con:

  * Extensión **SQLite3** habilitada
  * **cURL** habilitado (para validar enlaces y descargar)

---

## Uso

### 1) Ejecutar el crawler y llenar la base de datos

En la carpeta del proyecto:

```bash
py "crawler input.py"
```

Te pedirá por consola:

* `START_URL` (Enter para usar el default)
* `MAX_PAGES` (Enter para usar el default)

El crawler:

* navega páginas **solo del mismo dominio**
* aplica un **delay suave** entre requests (no invasivo)
* detecta docs por **extensión** en la URL
* normaliza URLs (quita `#fragment`, tracking `utm_*`, etc.) para evitar duplicados
* guarda:

  * documentos en `docs`
  * páginas visitadas (y metadatos) en `pages` para poder **reanudar**

> Nota: SQLite puede crear archivos `docs.sqlite-wal` y `docs.sqlite-shm` (modo WAL). Es normal.

---

### 2) Abrir el panel web

1. Copia la carpeta `documents-crawler` dentro de tu:

   * `C:\xampp\htdocs\`
2. Inicia **Apache** en XAMPP
3. Abre en el navegador:

   * `http://localhost/documents-crawler/index.php`

---

## Funcionalidades del panel (index.php)

### Listado + filtros

* Buscar por:

  * **URL del documento** (`url_doc`)
  * **Origen** donde se encontró (`found_in`)
  * **Extensión**
* Orden:

  * más recientes / más antiguos / por extensión / por origen / por status
* Paginación + tamaño de página
* Presets rápidos:

  * solo PDF, solo DOCX, rotos, favoritos, sin revisar

### Validación de enlaces (rotos / OK)

* **Revalidar** un documento (guarda HTTP code + content-type + fecha)
* **Validar esta página** (revalida los docs visibles en el listado actual)

### Descarga (proxy controlado)

* Botón **Descargar** que intenta bajar el documento desde el servidor (PHP) y lo entrega como `attachment`.

### CRUD ligero

* Marcar / quitar **favorito**
* **Ocultar / mostrar** registros
* **Eliminar** de la BD

---

## Esquema de base de datos (SQLite)

### Tabla `docs`

Campos principales:

* `url_doc` (PK) → URL del documento
* `ext` → extensión detectada (ej: `.pdf`)
* `found_in` → página donde se encontró
* `how_detected` → método (ej: `ext`)
* `first_seen` → fecha de inserción

Campos de validación / panel:

* `last_http_code`, `last_checked`, `last_content_type`, `last_error`, `is_ok`
* `is_hidden`, `is_fav`

### Tabla `pages`

* `url_page` (PK)
* `status_code`, `content_type`, `title`
* `first_seen`, `last_seen`

---

## Notas de uso responsable (fin educativo)

* Respeta las políticas del sitio y evita rastrear agresivamente.
* Ajusta `MAX_PAGES` y los delays si estás probando con servidores sensibles.

---

## Autor

Desarrollado por **Piero Olivares (PieroDev)**.

