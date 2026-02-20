<?php
/* ============================================================
   DOCUMENTS-CRAWLER PANEL (fin educativo)
   ------------------------------------------------------------
   Este archivo es el "panel final" en PHP que trabaja contra
   la base de datos SQLite generada por el crawler (Python).

   Incluye:
     - Listado + filtros + paginación (SQLite)
     - Descargar documento (proxy controlado por BD)
     - Validación de enlaces (HTTP status) para detectar rotos
     - CRUD ligero: ocultar, favorito, eliminar
     - Presets rápidos (solo PDF, rotos, favoritos, sin revisar, etc.)

   Requisitos:
     - PHP con extensión SQLite3 habilitada
     - cURL habilitado (para validar y descargar)
   ============================================================ */


/* =========================
   Helper de salida segura
   -------------------------
   Previene XSS en HTML escapando cualquier string que se imprima.
   ========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }


/* =========================
   DB: ruta y apertura
   -------------------------
   - La BD debe llamarse docs.sqlite y estar junto a este archivo.
   - Se abre en modo READ/WRITE para permitir validaciones y CRUD.
   ========================= */
$dbPath = __DIR__ . "/docs.sqlite";

if (!file_exists($dbPath)) {
  http_response_code(500);
  echo "No existe docs.sqlite en: " . h($dbPath);
  exit;
}

if (!class_exists("SQLite3")) {
  http_response_code(500);
  echo "SQLite3 no está disponible en este PHP. Revisa que la extensión SQLite3 esté habilitada.";
  exit;
}

try {
  // RW para acciones CRUD / validación
  $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
  // Evita (en parte) el típico "database is locked" en accesos concurrentes
  $db->busyTimeout(30000);
} catch (Exception $e) {
  http_response_code(500);
  echo "No se pudo abrir la base de datos: " . h($e->getMessage());
  exit;
}


/* =========================
   MIGRACIONES LIGERAS
   -------------------------
   Si la BD viene de una versión anterior del proyecto, puede que
   NO tenga columnas necesarias para el panel.

   Por eso:
     - verificamos columnas existentes (PRAGMA table_info)
     - si falta una columna, la añadimos con ALTER TABLE

   Esto hace el panel "retrocompatible".
   ========================= */


/**
 * Comprueba si una tabla tiene una columna concreta.
 * @param SQLite3 $db
 * @param string $table Nombre tabla (ej: "docs")
 * @param string $col   Nombre columna (ej: "is_fav")
 * @return bool
 */
function has_column(SQLite3 $db, string $table, string $col): bool {
  $res = $db->query("PRAGMA table_info(".$table.");");
  if (!$res) return false;

  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    if (($r["name"] ?? "") === $col) return true;
  }
  return false;
}

/**
 * Añade una columna si no existe.
 * Ejemplo:
 *   add_column_if_missing($db, "docs", "is_fav", "INTEGER NOT NULL DEFAULT 0");
 */
function add_column_if_missing(SQLite3 $db, string $table, string $col, string $defSql): void {
  if (!has_column($db, $table, $col)) {
    $db->exec("ALTER TABLE ".$table." ADD COLUMN ".$col." ".$defSql.";");
  }
}


/* ------------------------------------------------------------
   Columnas necesarias para 016: validación de enlaces (status)
   ------------------------------------------------------------ */
add_column_if_missing($db, "docs", "last_http_code", "INTEGER");           // último HTTP code (200, 404...)
add_column_if_missing($db, "docs", "last_checked",   "TEXT");              // cuándo se validó por última vez
add_column_if_missing($db, "docs", "last_content_type", "TEXT");           // content-type devuelto por el servidor
add_column_if_missing($db, "docs", "last_error", "TEXT");                  // error de curl / motivo
add_column_if_missing($db, "docs", "is_ok", "INTEGER");                    // 1 ok, 0 roto, NULL sin revisar


/* ------------------------------------------------------------
   Columnas necesarias para 017: flags CRUD ligeros
   ------------------------------------------------------------ */
add_column_if_missing($db, "docs", "is_hidden", "INTEGER NOT NULL DEFAULT 0"); // oculto = 1
add_column_if_missing($db, "docs", "is_fav",    "INTEGER NOT NULL DEFAULT 0"); // favorito = 1


/* ------------------------------------------------------------
   Índices: aceleran filtros y ordenaciones comunes
   ------------------------------------------------------------ */
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_ext ON docs(ext);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_found_in ON docs(found_in);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_ok ON docs(is_ok);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_hidden ON docs(is_hidden);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_fav ON docs(is_fav);");


/* =========================
   Query helpers
   -------------------------
   Construyen query strings preservando filtros actuales.

   - qs_list(): conserva los filtros actuales y permite sobrescribir claves
   - qs_action(): igual, pero añade action y la URL del documento (u)
   - redirect_back(): vuelve al listado actual (manteniendo filtros)
   ========================= */


/**
 * Construye query string para links de navegación (paginación/filtros).
 * - Quita campos de acción (action/u/confirm) para no "arrastrarlos".
 * - Permite sobrescribir/añadir claves con $overrides.
 */
function qs_list(array $overrides = []){
  $base = $_GET;
  unset($base["action"], $base["u"], $base["confirm"]);

  foreach ($overrides as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

/**
 * Construye query string para acciones sobre una fila (download/check/fav...).
 * - Conserva filtros y añade:
 *     action=<acción>
 *     u=<url_doc>
 */
function qs_action(string $action, string $docUrl, array $overrides = []){
  $base = $_GET;
  $base["action"] = $action;
  $base["u"] = $docUrl;

  foreach ($overrides as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

/**
 * Redirige al mismo listado (sin repetir POST, y manteniendo filtros).
 * Útil después de ejecutar acciones (toggle, delete, check...).
 */
function redirect_back(){
  $qs = qs_list([]);
  $to = strtok($_SERVER["REQUEST_URI"] ?? "", "?"); // ruta sin query
  if ($qs !== "") $to .= "?" . $qs;
  header("Location: " . $to);
  exit;
}


/* =========================
   HTTP CHECK (validación)
   -------------------------
   Valida enlaces de documentos para saber:
     - HTTP status code (200/404/403/...)
     - content-type
     - si está OK o roto

   Estrategia:
     1) Intentar HEAD (más barato)
     2) Si HEAD da 405, intentar GET parcial con Range 0-0
   ========================= */


/**
 * Comprueba una URL por HTTP y devuelve estado, content-type y error.
 * @param string $url      URL absoluta del documento
 * @param string $referer  Página de origen (found_in), por si el servidor lo exige
 * @return array ["ok"=>bool, "code"=>int, "ctype"=>string, "err"=>string]
 */
function http_check_url(string $url, string $referer = ""): array {
  $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120";
  $maxSeconds = 30;

  $out = [
    "ok" => false,
    "code" => 0,
    "ctype" => "",
    "err" => ""
  ];

  // Validación mínima del formato de URL
  if (!preg_match('#^https?://#i', $url)) {
    $out["err"] = "url_invalid";
    return $out;
  }

  // Preferimos cURL por control fino de redirects/timeouts/headers
  if (function_exists("curl_init")) {

    /* -------------------------
       1) HEAD request
       -------------------------
       - NOBODY = true => no descarga el cuerpo
       - sigue redirects
       - captura code y content-type
       */
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $maxSeconds);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($referer !== "") curl_setopt($ch, CURLOPT_REFERER, $referer);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    // Si HEAD respondió “bien”, usamos ese resultado
    if ($resp !== false && $code > 0) {
      $out["code"] = $code;
      $out["ctype"] = $ctype;
      $out["err"] = $err;
      $out["ok"] = ($code >= 200 && $code < 400);

      // Algunos servidores bloquean HEAD con 405 -> hacemos fallback
      if ($code !== 405) return $out;
    }

    /* -------------------------
       2) GET parcial (Range 0-0)
       -------------------------
       - Descarga solo 1 byte (o intenta)
       - Menos invasivo que GET completo
       - Sirve cuando HEAD está bloqueado
       */
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $maxSeconds);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_RANGE, "0-0");
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_HEADER, false);

    // Descarta el cuerpo recibido: solo nos importa status + headers
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data){ return strlen($data); });

    if ($referer !== "") curl_setopt($ch, CURLOPT_REFERER, $referer);

    $exec = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    $out["code"] = $code;
    $out["ctype"] = $ctype;
    $out["err"] = $err;

    if ($exec === false || $code <= 0) {
      $out["ok"] = false;
      if ($out["err"] === "") $out["err"] = "curl_failed";
      return $out;
    }

    $out["ok"] = ($code >= 200 && $code < 400);
    return $out;
  }

  // Si no hay cURL, no podemos validar como queremos
  $out["err"] = "curl_not_available";
  return $out;
}


/* =========================
   ACTIONS (por GET)
   -------------------------
   action=download  -> descarga el archivo
   action=check     -> valida UN documento
   action=check_page-> valida los docs visibles en pantalla
   action=toggle_fav / toggle_hidden / delete -> CRUD ligero

   Nota:
   - Para acciones por fila, usamos "u" con url_doc.
   - Tras acciones (excepto download) redirigimos al listado.
   ========================= */
$action = (string)($_GET["action"] ?? "");
$u = trim((string)($_GET["u"] ?? ""));

if ($action !== "") {

  /* Seguridad mínima:
     - Si nos pasan una URL en "u", debe ser http(s)
     - Evita cosas raras tipo file:// o javascript:
  */
  if ($u !== "" && !preg_match('#^https?://#i', $u)) {
    http_response_code(400);
    echo "URL inválida.";
    exit;
  }

  /* Cargar el registro del doc (si hay u):
     - Esto garantiza que SOLO actuamos sobre docs que existen en BD
     - y evita que cualquiera "descargue" una URL arbitraria
  */
  $docRow = null;
  if ($u !== "") {
    $st = $db->prepare("
      SELECT url_doc, ext, found_in, how_detected, first_seen,
             is_hidden, is_fav,
             last_http_code, last_checked, last_content_type, last_error, is_ok
      FROM docs
      WHERE url_doc = :u
      LIMIT 1;
    ");
    $st->bindValue(":u", $u, SQLITE3_TEXT);
    $rs = $st->execute();
    $docRow = $rs ? $rs->fetchArray(SQLITE3_ASSOC) : null;
  }

  /* =========================================================
     DOWNLOAD (proxy)
     ---------------------------------------------------------
     Descarga el documento en el servidor (PHP) y lo entrega como
     attachment al navegador.

     Medidas:
       - Solo se descarga si la URL existe en la BD.
       - Límite de tamaño MAX_BYTES (30MB) para evitar abusos.
       - Se usa referer (found_in) para servidores que lo requieran.
     ========================================================= */
  if ($action === "download") {
    if (!$docRow) {
      http_response_code(404);
      echo "Ese documento no existe en la base de datos.";
      exit;
    }

    $url_doc = (string)$docRow["url_doc"];
    $ext     = (string)$docRow["ext"];
    $referer = (string)($docRow["found_in"] ?? "");

    // Intentamos crear un nombre de archivo amigable a partir del path
    $path = parse_url($url_doc, PHP_URL_PATH);
    $base = $path ? basename($path) : "";
    $base = $base ?: ("documento" . $ext);

    // Sanitizamos el nombre (para no romper headers)
    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base);
    if ($filename === "" || $filename === "_") $filename = "documento" . $ext;

    // Límite por seguridad
    $MAX_BYTES = 30 * 1024 * 1024; // 30MB

    // Descargamos primero a un archivo temporal
    $tmp = tempnam(sys_get_temp_dir(), "docdl_");
    if ($tmp === false) { http_response_code(500); echo "No se pudo crear temporal."; exit; }

    $fp = fopen($tmp, "wb");
    if ($fp === false) { @unlink($tmp); http_response_code(500); echo "No se pudo abrir temporal."; exit; }

    $ctype = "application/octet-stream";
    $ok = false;

    if (function_exists("curl_init")) {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url_doc);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
      curl_setopt($ch, CURLOPT_TIMEOUT, 90);
      curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120");
      curl_setopt($ch, CURLOPT_FILE, $fp);
      curl_setopt($ch, CURLOPT_FAILONERROR, false);

      // Algunos sitios solo entregan si hay referer
      if ($referer !== "") curl_setopt($ch, CURLOPT_REFERER, $referer);

      // Corte por tamaño: si supera MAX_BYTES abortamos
      curl_setopt($ch, CURLOPT_NOPROGRESS, false);
      if (defined("CURLOPT_XFERINFOFUNCTION")) {
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function($resource, $dltotal, $dlnow, $ultotal, $ulnow) use ($MAX_BYTES) {
          if ($dlnow > $MAX_BYTES) return 1; // abort
          return 0;
        });
      } else {
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dltotal, $dlnow, $ultotal, $ulnow) use ($MAX_BYTES) {
          if ($dlnow > $MAX_BYTES) return 1; // abort
          return 0;
        });
      }

      $exec = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

      // Captura content-type real si lo hay
      $ctInfo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
      if ($ctInfo !== "") $ctype = $ctInfo;

      $err = (string)curl_error($ch);
      curl_close($ch);

      $ok = ($exec !== false && $httpCode >= 200 && $httpCode < 300 && $err === "");
    }

    fclose($fp);

    // Si falló la descarga, devolvemos error
    if (!$ok) { @unlink($tmp); http_response_code(502); echo "No se pudo descargar el documento."; exit; }

    // Validar tamaño final
    $size = filesize($tmp);
    if ($size === false || $size <= 0) { @unlink($tmp); http_response_code(502); echo "Descarga vacía."; exit; }
    if ($size > $MAX_BYTES) { @unlink($tmp); http_response_code(413); echo "Archivo demasiado grande."; exit; }

    // Limpiar buffers para no corromper descarga
    while (ob_get_level() > 0) { @ob_end_clean(); }

    // Headers de descarga
    header("Content-Type: " . $ctype);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header("Content-Length: " . (string)$size);
    header("X-Content-Type-Options: nosniff");

    // Emitimos el archivo y borramos temporal
    readfile($tmp);
    @unlink($tmp);
    exit;
  }


  /* =========================================================
     CHECK ONE (validación de un documento)
     ---------------------------------------------------------
     - Ejecuta http_check_url()
     - Actualiza columnas last_* e is_ok en la tabla docs
     - Redirige al listado (manteniendo filtros)
     ========================================================= */
  if ($action === "check") {
    if (!$docRow) { http_response_code(404); echo "No existe en BD."; exit; }

    $url_doc = (string)$docRow["url_doc"];
    $referer = (string)($docRow["found_in"] ?? "");

    $res = http_check_url($url_doc, $referer);

    $code = (int)$res["code"];
    $ctype = (string)$res["ctype"];
    $err = (string)$res["err"];
    $is_ok = $res["ok"] ? 1 : 0;

    $st = $db->prepare("
      UPDATE docs
      SET last_http_code = :c,
          last_content_type = :ct,
          last_error = :e,
          last_checked = datetime('now'),
          is_ok = :ok
      WHERE url_doc = :u;
    ");
    $st->bindValue(":c", $code, SQLITE3_INTEGER);
    $st->bindValue(":ct", $ctype, SQLITE3_TEXT);
    $st->bindValue(":e", $err, SQLITE3_TEXT);
    $st->bindValue(":ok", $is_ok, SQLITE3_INTEGER);
    $st->bindValue(":u", $url_doc, SQLITE3_TEXT);
    $st->execute();

    redirect_back();
  }


  /* =========================================================
     TOGGLE FAV (CRUD ligero)
     ---------------------------------------------------------
     Alterna is_fav (0/1) para el documento indicado.
     ========================================================= */
  if ($action === "toggle_fav") {
    if (!$docRow) { http_response_code(404); echo "No existe en BD."; exit; }
    $st = $db->prepare("UPDATE docs SET is_fav = CASE WHEN is_fav=1 THEN 0 ELSE 1 END WHERE url_doc = :u;");
    $st->bindValue(":u", (string)$docRow["url_doc"], SQLITE3_TEXT);
    $st->execute();
    redirect_back();
  }


  /* =========================================================
     TOGGLE HIDDEN (CRUD ligero)
     ---------------------------------------------------------
     Alterna is_hidden (0/1). Por defecto el listado oculta hidden.
     ========================================================= */
  if ($action === "toggle_hidden") {
    if (!$docRow) { http_response_code(404); echo "No existe en BD."; exit; }
    $st = $db->prepare("UPDATE docs SET is_hidden = CASE WHEN is_hidden=1 THEN 0 ELSE 1 END WHERE url_doc = :u;");
    $st->bindValue(":u", (string)$docRow["url_doc"], SQLITE3_TEXT);
    $st->execute();
    redirect_back();
  }


  /* =========================================================
     DELETE (CRUD ligero)
     ---------------------------------------------------------
     Elimina el registro de la BD.
     - Se apoya en confirm=1 (y además hay confirm() en JS).
     ========================================================= */
  if ($action === "delete") {
    if (!$docRow) { http_response_code(404); echo "No existe en BD."; exit; }
    $confirm = (string)($_GET["confirm"] ?? "");
    if ($confirm !== "1") {
      redirect_back();
    }
    $st = $db->prepare("DELETE FROM docs WHERE url_doc = :u;");
    $st->bindValue(":u", (string)$docRow["url_doc"], SQLITE3_TEXT);
    $st->execute();
    redirect_back();
  }

  /* CHECK PAGE
     - se ejecuta más abajo porque depende de $rows (resultado del listado)
   */
}


/* =========================
   INPUTS (GET) para el listado
   -------------------------
   Filtros típicos:
     q        -> busca en url_doc (LIKE)
     ext      -> filtra por extensión exacta
     found    -> busca en found_in (LIKE)
     st       -> ok / bad / unchecked
     fav      -> solo favoritos
     show_hidden -> mostrar ocultos
     sort     -> orden
     page/per -> paginación
   ========================= */
$q     = trim((string)($_GET["q"] ?? ""));
$ext   = trim((string)($_GET["ext"] ?? ""));
$found = trim((string)($_GET["found"] ?? ""));

$sort  = (string)($_GET["sort"] ?? "newest");
$page  = (int)($_GET["page"] ?? 1);
$per   = (int)($_GET["per"] ?? 25);

$stFilter = (string)($_GET["st"] ?? "");              // ok | bad | unchecked | ""
$favOnly  = (string)($_GET["fav"] ?? "");             // 1
$showHidden = (string)($_GET["show_hidden"] ?? "");   // 1

// Normalización básica de paginación
if ($page < 1) $page = 1;
if ($per < 10) $per = 10;
if ($per > 100) $per = 100;

$offset = ($page - 1) * $per;


/* =========================
   ORDER BY seguro
   -------------------------
   Mapa de opciones permitidas para evitar inyección SQL en ORDER BY.
   ========================= */
$orders = [
  "newest" => "first_seen DESC",
  "oldest" => "first_seen ASC",
  "ext"    => "ext ASC, first_seen DESC",
  "found"  => "found_in ASC, first_seen DESC",
  "status" => "COALESCE(last_http_code, 0) DESC, first_seen DESC",
];
$orderBy = $orders[$sort] ?? $orders["newest"];


/* =========================
   WHERE dinámico + params
   -------------------------
   Construimos el WHERE en base a filtros, usando placeholders
   (bindValue) para evitar inyección SQL.
   ========================= */
$where = [];
$params = [];

if ($q !== "") { $where[] = "url_doc LIKE :q"; $params[":q"] = "%" . $q . "%"; }
if ($ext !== "") { $where[] = "ext = :ext"; $params[":ext"] = $ext; }
if ($found !== "") { $where[] = "found_in LIKE :found"; $params[":found"] = "%" . $found . "%"; }

if ($favOnly === "1") { $where[] = "is_fav = 1"; }

// Por defecto NO mostramos ocultos
if ($showHidden !== "1") {
  $where[] = "is_hidden = 0";
}

// Estado de validación (016)
if ($stFilter === "ok") {
  $where[] = "is_ok = 1";
} elseif ($stFilter === "bad") {
  $where[] = "is_ok = 0";
} elseif ($stFilter === "unchecked") {
  $where[] = "is_ok IS NULL";
}

$whereSql = (count($where) > 0) ? ("WHERE " . implode(" AND ", $where)) : "";


/* =========================
   Opciones de extensión
   -------------------------
   Se usa para rellenar el <select> de extensiones y mostrar conteos.
   ========================= */
$extOptions = [];
$resExt = $db->query("SELECT ext, COUNT(*) AS total FROM docs GROUP BY ext ORDER BY total DESC, ext ASC;");
while ($row = $resExt->fetchArray(SQLITE3_ASSOC)) { $extOptions[] = $row; }


/* =========================
   Conteos (totales)
   -------------------------
   - totalAll: total de docs en BD (sin filtros)
   - totalFiltered: total con filtros actuales (para paginación)
   ========================= */
$totalAll = (int)($db->querySingle("SELECT COUNT(*) FROM docs;") ?? 0);

$stmtCount = $db->prepare("SELECT COUNT(*) AS n FROM docs $whereSql;");
foreach ($params as $k => $v) { $stmtCount->bindValue($k, $v, SQLITE3_TEXT); }
$rCount = $stmtCount->execute();
$rowCount = $rCount->fetchArray(SQLITE3_ASSOC);
$totalFiltered = (int)($rowCount["n"] ?? 0);


/* =========================
   Cálculo de páginas
   ========================= */
$totalPages = (int)ceil($totalFiltered / $per);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;


/* =========================
   Query principal del listado
   ========================= */
$sql = "
  SELECT url_doc, ext, found_in, how_detected, first_seen,
         is_hidden, is_fav,
         last_http_code, last_checked, last_content_type, last_error, is_ok
  FROM docs
  $whereSql
  ORDER BY $orderBy
  LIMIT :limit OFFSET :offset
";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v, SQLITE3_TEXT); }
$stmt->bindValue(":limit", $per, SQLITE3_INTEGER);
$stmt->bindValue(":offset", $offset, SQLITE3_INTEGER);
$res = $stmt->execute();

$rows = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; }


/* =========================
   CHECK PAGE (validar docs visibles)
   -------------------------
   Valida todos los documentos que aparecen en el listado actual.
   Se ejecuta AQUÍ porque depende de $rows (resultado ya paginado).
   ========================= */
if ($action === "check_page") {
  foreach ($rows as $r) {
    $url_doc = (string)$r["url_doc"];
    $referer = (string)($r["found_in"] ?? "");
    $resCheck = http_check_url($url_doc, $referer);

    $code = (int)$resCheck["code"];
    $ctype = (string)$resCheck["ctype"];
    $err = (string)$resCheck["err"];
    $is_ok = $resCheck["ok"] ? 1 : 0;

    $stUp = $db->prepare("
      UPDATE docs
      SET last_http_code = :c,
          last_content_type = :ct,
          last_error = :e,
          last_checked = datetime('now'),
          is_ok = :ok
      WHERE url_doc = :u;
    ");
    $stUp->bindValue(":c", $code, SQLITE3_INTEGER);
    $stUp->bindValue(":ct", $ctype, SQLITE3_TEXT);
    $stUp->bindValue(":e", $err, SQLITE3_TEXT);
    $stUp->bindValue(":ok", $is_ok, SQLITE3_INTEGER);
    $stUp->bindValue(":u", $url_doc, SQLITE3_TEXT);
    $stUp->execute();
  }

  // Volvemos al listado para ver los nuevos estados
  redirect_back();
}


/* =========================
   Presets (atajos de filtro)
   -------------------------
   preset_link() genera una URL con overrides de filtros.
   ========================= */
function preset_link(array $overrides){
  return "?" . h(qs_list($overrides));
}


/* =========================
   Branding / UI helpers
   ========================= */
$logoUrl = "https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/solologo_negro.png";

/**
 * Devuelve [texto, clase_css] para el badge de status.
 */
function status_badge($is_ok, $code){
  if ($is_ok === null) return ["SIN REVISAR", "badge gray"];
  if ((int)$is_ok === 1) return ["OK " . (int)$code, "badge ok"];
  return ["ROTO " . (int)$code, "badge bad"];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Docs (SQLite)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Estilos separados en styles.css (tema claro + khaki) -->
  <link rel="stylesheet" href="styles.css">
</head>
<body>

<header>
  <div class="wrap">
    <div class="headbar">
      <div class="brand">
        <img src="<?php echo h($logoUrl); ?>" alt="Logo">
        <div>
          <h1>Documentos detectados (SQLite)</h1>
          <div class="sub">DB: <?php echo h(basename($dbPath)); ?></div>
        </div>
      </div>

      <!-- Contadores útiles (total y filtrados) -->
      <div class="pill">
        <span>Total: <b><?php echo (int)$totalAll; ?></b></span>
        <span>Filtrados: <b><?php echo (int)$totalFiltered; ?></b></span>
      </div>
    </div>
  </div>
</header>

<div class="wrap">

  <!-- =========================
       Barra de presets + filtros
       ========================= -->
  <div class="card">
    <div class="presetbar" style="margin-bottom:10px;">
      <a class="btn" href="<?php echo preset_link(["ext"=>".pdf","page"=>1]); ?>">Preset: solo PDF</a>
      <a class="btn" href="<?php echo preset_link(["ext"=>".docx","page"=>1]); ?>">Preset: solo DOCX</a>
      <a class="btn" href="<?php echo preset_link(["st"=>"bad","page"=>1]); ?>">Preset: rotos</a>
      <a class="btn" href="<?php echo preset_link(["fav"=>"1","page"=>1]); ?>">Preset: favoritos</a>
      <a class="btn" href="<?php echo preset_link(["st"=>"unchecked","page"=>1]); ?>">Preset: sin revisar</a>
      <a class="btn" href="?">Limpiar</a>
    </div>

    <form method="get">
      <div class="grid">

        <!-- Buscar por URL del documento -->
        <div class="full">
          <label for="q">Buscar en URL del documento (url_doc contiene...)</label>
          <input id="q" name="q" value="<?php echo h($q); ?>" placeholder="Ej: parking, handbook, form...">
        </div>

        <!-- Extensión -->
        <div>
          <label for="ext">Extensión</label>
          <select id="ext" name="ext">
            <option value="">(todas)</option>
            <?php foreach ($extOptions as $op): ?>
              <?php $val = (string)$op["ext"]; ?>
              <option value="<?php echo h($val); ?>" <?php echo ($ext === $val ? "selected" : ""); ?>>
                <?php echo h($val); ?> (<?php echo (int)$op["total"]; ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Buscar por origen -->
        <div>
          <label for="found">Buscar en found_in (página origen contiene...)</label>
          <input id="found" name="found" value="<?php echo h($found); ?>" placeholder="Ej: /pdf/ o /resources">
        </div>

        <!-- Estado (validación) -->
        <div>
          <label for="st">Estado enlace (016)</label>
          <select id="st" name="st">
            <option value="" <?php echo ($stFilter===""?"selected":""); ?>>(todos)</option>
            <option value="ok" <?php echo ($stFilter==="ok"?"selected":""); ?>>OK</option>
            <option value="bad" <?php echo ($stFilter==="bad"?"selected":""); ?>>Rotos</option>
            <option value="unchecked" <?php echo ($stFilter==="unchecked"?"selected":""); ?>>Sin revisar</option>
          </select>
        </div>

        <!-- Orden -->
        <div>
          <label for="sort">Orden</label>
          <select id="sort" name="sort">
            <option value="newest" <?php echo ($sort==="newest"?"selected":""); ?>>Más recientes</option>
            <option value="oldest" <?php echo ($sort==="oldest"?"selected":""); ?>>Más antiguos</option>
            <option value="ext" <?php echo ($sort==="ext"?"selected":""); ?>>Por extensión</option>
            <option value="found" <?php echo ($sort==="found"?"selected":""); ?>>Por found_in</option>
            <option value="status" <?php echo ($sort==="status"?"selected":""); ?>>Por status</option>
          </select>
        </div>

        <!-- Paginación: filas -->
        <div>
          <label for="per">Filas por página</label>
          <select id="per" name="per">
            <?php foreach ([10,25,50,100] as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo ($per===$n?"selected":""); ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Opciones -->
        <div>
          <label>Opciones (017)</label>
          <div class="actions">
            <label style="display:flex;align-items:center;gap:8px;margin:0;">
              <input type="checkbox" name="fav" value="1" <?php echo ($favOnly==="1"?"checked":""); ?> style="width:auto;">
              Solo favoritos
            </label>

            <label style="display:flex;align-items:center;gap:8px;margin:0;">
              <input type="checkbox" name="show_hidden" value="1" <?php echo ($showHidden==="1"?"checked":""); ?> style="width:auto;">
              Mostrar ocultos
            </label>
          </div>
        </div>

        <!-- Botones de aplicar / validar página -->
        <div class="actions">
          <button type="submit">Aplicar filtros</button>
          <a class="btn" href="?<?php echo h(qs_list(["action"=>"check_page"])); ?>">Validar esta página</a>
        </div>

      </div>
    </form>
  </div>


  <!-- =========================
       Tabla de resultados
       ========================= -->
  <div class="card">
    <?php
      // Ventana de paginación (2 páginas antes/después)
      $prev = $page - 1; $next = $page + 1;
      $start = max(1, $page - 2);
      $end = min($totalPages, $page + 2);
    ?>
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
      <div style="color:var(--muted);font-size:13px;">
        Página <b><?php echo (int)$page; ?></b> de <b><?php echo (int)$totalPages; ?></b>
        (mostrando <?php echo count($rows); ?> filas)
      </div>

      <div class="pager">
        <?php if ($page > 1): ?>
          <a href="?<?php echo h(qs_list(["page"=>$prev])); ?>">Anterior</a>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
          <a class="<?php echo ($p===$page)?"active":""; ?>" href="?<?php echo h(qs_list(["page"=>$p])); ?>"><?php echo $p; ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <a href="?<?php echo h(qs_list(["page"=>$next])); ?>">Siguiente</a>
        <?php endif; ?>
      </div>
    </div>

    <div style="overflow:auto;margin-top:12px;">
      <table>
        <thead>
          <tr>
            <th>Documento</th>
            <th>Ext</th>
            <th>Status (016)</th>
            <th>Origen (found_in)</th>
            <th>Fecha</th>
            <th>Acciones (017)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) === 0): ?>
            <tr><td colspan="6" style="color:var(--muted);">No hay resultados con estos filtros.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $docUrl = (string)$r["url_doc"];
                $is_ok = $r["is_ok"];
                $code = $r["last_http_code"];
                $badge = status_badge($is_ok, $code);

                $isFav = (int)($r["is_fav"] ?? 0) === 1;
                $isHidden = (int)($r["is_hidden"] ?? 0) === 1;
              ?>
              <tr>

                <!-- URL del documento + metadatos -->
                <td style="min-width:320px;word-break:break-word;">
                  <a href="<?php echo h($docUrl); ?>" target="_blank" rel="noopener"><?php echo h($docUrl); ?></a>

                  <div style="color:var(--muted);font-size:12px;margin-top:6px;">
                    how_detected: <?php echo h($r["how_detected"]); ?>
                    <?php if (!empty($r["last_checked"])): ?>
                      | last_checked: <?php echo h($r["last_checked"]); ?>
                    <?php endif; ?>
                  </div>

                  <?php if (!empty($r["last_error"])): ?>
                    <div style="color:var(--muted);font-size:12px;margin-top:6px;">
                      last_error: <?php echo h($r["last_error"]); ?>
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Extensión -->
                <td><span class="tag"><?php echo h($r["ext"]); ?></span></td>

                <!-- Estado (OK/ROTO/SIN REVISAR) + content-type -->
                <td>
                  <span class="<?php echo h($badge[1]); ?>"><?php echo h($badge[0]); ?></span>
                  <?php if (!empty($r["last_content_type"])): ?>
                    <div style="color:var(--muted);font-size:12px;margin-top:6px;">
                      <?php echo h($r["last_content_type"]); ?>
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Página donde se encontró -->
                <td style="min-width:320px;"><?php echo h($r["found_in"]); ?></td>

                <!-- Fecha (first_seen) -->
                <td><?php echo h($r["first_seen"]); ?></td>

                <!-- Acciones por fila -->
                <td class="actions-cell">
                  <div class="actions-wrap">
                    <a class="btn" href="<?php echo h($docUrl); ?>" target="_blank" rel="noopener">Abrir</a>

                    <!-- Descarga proxy (solo si existe en BD) -->
                    <a class="btn primary" href="?<?php echo h(qs_action("download", $docUrl)); ?>">Descargar</a>

                    <!-- Validar documento -->
                    <a class="btn" href="?<?php echo h(qs_action("check", $docUrl)); ?>">Revalidar</a>

                    <!-- Favorito -->
                    <a class="btn" href="?<?php echo h(qs_action("toggle_fav", $docUrl)); ?>">
                      <?php echo $isFav ? "Quitar favorito" : "Favorito"; ?>
                    </a>

                    <!-- Ocultar / mostrar -->
                    <a class="btn" href="?<?php echo h(qs_action("toggle_hidden", $docUrl)); ?>">
                      <?php echo $isHidden ? "Mostrar" : "Ocultar"; ?>
                    </a>

                    <!-- Eliminar -->
                    <a class="btn danger"
                       href="?<?php echo h(qs_action("delete", $docUrl, ["confirm"=>"1"])); ?>"
                       onclick="return confirm('Eliminar este registro de la BD?');">
                      Eliminar
                    </a>
                  </div>
                </td>

              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>