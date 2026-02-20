<?php
/* =========================
   Panel listado + filtros (SQLite)
   Descargar documento (proxy controlado por BD)
   Validación enlaces (HTTP status) + rotos
   CRUD ligero (ocultar, favorito, eliminar, revalidar, presets)
   ========================= */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/* =========================
   DB
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
  $db->busyTimeout(30000);
} catch (Exception $e) {
  http_response_code(500);
  echo "No se pudo abrir la base de datos: " . h($e->getMessage());
  exit;
}

/* =========================
   MIGRACIONES LIGERAS
   - añade columnas si faltan
   ========================= */
function has_column(SQLite3 $db, string $table, string $col): bool {
  $res = $db->query("PRAGMA table_info(".$table.");");
  if (!$res) return false;
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    if (($r["name"] ?? "") === $col) return true;
  }
  return false;
}

function add_column_if_missing(SQLite3 $db, string $table, string $col, string $defSql): void {
  if (!has_column($db, $table, $col)) {
    $db->exec("ALTER TABLE ".$table." ADD COLUMN ".$col." ".$defSql.";");
  }
}

/* 016: status/check */
add_column_if_missing($db, "docs", "last_http_code", "INTEGER");
add_column_if_missing($db, "docs", "last_checked",   "TEXT");
add_column_if_missing($db, "docs", "last_content_type", "TEXT");
add_column_if_missing($db, "docs", "last_error", "TEXT");
add_column_if_missing($db, "docs", "is_ok", "INTEGER"); // 1 ok, 0 roto, NULL sin revisar

/* 017: flags */
add_column_if_missing($db, "docs", "is_hidden", "INTEGER NOT NULL DEFAULT 0");
add_column_if_missing($db, "docs", "is_fav",    "INTEGER NOT NULL DEFAULT 0");

/* indexes */
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_ext ON docs(ext);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_found_in ON docs(found_in);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_ok ON docs(is_ok);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_hidden ON docs(is_hidden);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_docs_fav ON docs(is_fav);");

/* =========================
   Query helpers
   ========================= */
function qs_list(array $overrides = []){
  $base = $_GET;
  unset($base["action"], $base["u"], $base["confirm"]);
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

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

function redirect_back(){
  $qs = qs_list([]);
  $to = strtok($_SERVER["REQUEST_URI"] ?? "", "?");
  if ($qs !== "") $to .= "?" . $qs;
  header("Location: " . $to);
  exit;
}

/* =========================
   HTTP CHECK
   ========================= */
function http_check_url(string $url, string $referer = ""): array {
  $ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120";
  $maxSeconds = 30;

  $out = [
    "ok" => false,
    "code" => 0,
    "ctype" => "",
    "err" => ""
  ];

  if (!preg_match('#^https?://#i', $url)) {
    $out["err"] = "url_invalid";
    return $out;
  }

  if (function_exists("curl_init")) {
    // 1) HEAD
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

    if ($resp !== false && $code > 0) {
      $out["code"] = $code;
      $out["ctype"] = $ctype;
      $out["err"] = $err;
      $out["ok"] = ($code >= 200 && $code < 400);
      // Si HEAD da 405, probamos GET parcial
      if ($code !== 405) return $out;
    }

    // 2) GET parcial (Range 0-0)
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

    // descartamos body
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

  $out["err"] = "curl_not_available";
  return $out;
}

/* =========================
   ACTIONS
   ========================= */
$action = (string)($_GET["action"] ?? "");
$u = trim((string)($_GET["u"] ?? ""));

if ($action !== "") {
  // Seguridad mínima
  if ($u !== "" && !preg_match('#^https?://#i', $u)) {
    http_response_code(400);
    echo "URL inválida.";
    exit;
  }

  // Cargar doc (acciones por fila)
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

  /* DOWNLOAD */
  if ($action === "download") {
    if (!$docRow) {
      http_response_code(404);
      echo "Ese documento no existe en la base de datos.";
      exit;
    }

    $url_doc = (string)$docRow["url_doc"];
    $ext     = (string)$docRow["ext"];
    $referer = (string)($docRow["found_in"] ?? "");

    $path = parse_url($url_doc, PHP_URL_PATH);
    $base = $path ? basename($path) : "";
    $base = $base ?: ("documento" . $ext);

    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base);
    if ($filename === "" || $filename === "_") $filename = "documento" . $ext;

    $MAX_BYTES = 30 * 1024 * 1024; // 30MB

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
      if ($referer !== "") curl_setopt($ch, CURLOPT_REFERER, $referer);

      // corte por tamaño
      curl_setopt($ch, CURLOPT_NOPROGRESS, false);
      if (defined("CURLOPT_XFERINFOFUNCTION")) {
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function($resource, $dltotal, $dlnow, $ultotal, $ulnow) use ($MAX_BYTES) {
          if ($dlnow > $MAX_BYTES) return 1;
          return 0;
        });
      } else {
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dltotal, $dlnow, $ultotal, $ulnow) use ($MAX_BYTES) {
          if ($dlnow > $MAX_BYTES) return 1;
          return 0;
        });
      }

      $exec = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      $ctInfo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
      if ($ctInfo !== "") $ctype = $ctInfo;
      $err = (string)curl_error($ch);
      curl_close($ch);

      $ok = ($exec !== false && $httpCode >= 200 && $httpCode < 300 && $err === "");
    }

    fclose($fp);

    if (!$ok) { @unlink($tmp); http_response_code(502); echo "No se pudo descargar el documento."; exit; }

    $size = filesize($tmp);
    if ($size === false || $size <= 0) { @unlink($tmp); http_response_code(502); echo "Descarga vacía."; exit; }
    if ($size > $MAX_BYTES) { @unlink($tmp); http_response_code(413); echo "Archivo demasiado grande."; exit; }

    while (ob_get_level() > 0) { @ob_end_clean(); }

    header("Content-Type: " . $ctype);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header("Content-Length: " . (string)$size);
    header("X-Content-Type-Options: nosniff");

    readfile($tmp);
    @unlink($tmp);
    exit;
  }

  /* CHECK ONE */
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

  /* TOGGLE FAV */
  if ($action === "toggle_fav") {
    if (!$docRow) { http_response_code(404); echo "No existe en BD."; exit; }
    $st = $db->prepare("UPDATE docs SET is_fav = CASE WHEN is_fav=1 THEN 0 ELSE 1 END WHERE url_doc = :u;");
    $st->bindValue(":u", (string)$docRow["url_doc"], SQLITE3_TEXT);
    $st->execute();
    redirect_back();
  }

  /* TOGGLE HIDDEN */
  if ($action === "toggle_hidden") {
    if (!$docRow) { http_response_code(404); echo "No existe en BD."; exit; }
    $st = $db->prepare("UPDATE docs SET is_hidden = CASE WHEN is_hidden=1 THEN 0 ELSE 1 END WHERE url_doc = :u;");
    $st->bindValue(":u", (string)$docRow["url_doc"], SQLITE3_TEXT);
    $st->execute();
    redirect_back();
  }

  /* DELETE */
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
     - se ejecuta más abajo (necesita la query del listado)
   */
}

/* =========================
   INPUTS (GET) para el listado
   ========================= */
$q     = trim((string)($_GET["q"] ?? ""));
$ext   = trim((string)($_GET["ext"] ?? ""));
$found = trim((string)($_GET["found"] ?? ""));

$sort  = (string)($_GET["sort"] ?? "newest");
$page  = (int)($_GET["page"] ?? 1);
$per   = (int)($_GET["per"] ?? 25);

$stFilter = (string)($_GET["st"] ?? "");             // ok | bad | unchecked | ""
$favOnly  = (string)($_GET["fav"] ?? "");            // 1
$showHidden = (string)($_GET["show_hidden"] ?? "");  // 1

if ($page < 1) $page = 1;
if ($per < 10) $per = 10;
if ($per > 100) $per = 100;

$offset = ($page - 1) * $per;

/* ORDER BY seguro */
$orders = [
  "newest" => "first_seen DESC",
  "oldest" => "first_seen ASC",
  "ext"    => "ext ASC, first_seen DESC",
  "found"  => "found_in ASC, first_seen DESC",
  "status" => "COALESCE(last_http_code, 0) DESC, first_seen DESC",
];
$orderBy = $orders[$sort] ?? $orders["newest"];

/* WHERE dinámico */
$where = [];
$params = [];

if ($q !== "") { $where[] = "url_doc LIKE :q"; $params[":q"] = "%" . $q . "%"; }
if ($ext !== "") { $where[] = "ext = :ext"; $params[":ext"] = $ext; }
if ($found !== "") { $where[] = "found_in LIKE :found"; $params[":found"] = "%" . $found . "%"; }

if ($favOnly === "1") { $where[] = "is_fav = 1"; }

if ($showHidden !== "1") {
  $where[] = "is_hidden = 0";
}

if ($stFilter === "ok") {
  $where[] = "is_ok = 1";
} elseif ($stFilter === "bad") {
  $where[] = "is_ok = 0";
} elseif ($stFilter === "unchecked") {
  $where[] = "is_ok IS NULL";
}

$whereSql = (count($where) > 0) ? ("WHERE " . implode(" AND ", $where)) : "";

/* opciones ext */
$extOptions = [];
$resExt = $db->query("SELECT ext, COUNT(*) AS total FROM docs GROUP BY ext ORDER BY total DESC, ext ASC;");
while ($row = $resExt->fetchArray(SQLITE3_ASSOC)) { $extOptions[] = $row; }

/* conteos */
$totalAll = (int)($db->querySingle("SELECT COUNT(*) FROM docs;") ?? 0);

$stmtCount = $db->prepare("SELECT COUNT(*) AS n FROM docs $whereSql;");
foreach ($params as $k => $v) { $stmtCount->bindValue($k, $v, SQLITE3_TEXT); }
$rCount = $stmtCount->execute();
$rowCount = $rCount->fetchArray(SQLITE3_ASSOC);
$totalFiltered = (int)($rowCount["n"] ?? 0);

$totalPages = (int)ceil($totalFiltered / $per);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

/* resultados */
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

  redirect_back();
}

/* =========================
   Presets
   ========================= */
function preset_link(array $overrides){
  return "?" . h(qs_list($overrides));
}

/* Branding */
$logoUrl = "https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/solologo_negro.png";

/* Status badge helper */
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
  <style>
    :root{
      --khaki:#F0E68C;
      --bg:#f7f7f2;
      --card:#fff;
      --text:#121212;
      --muted:#5a5a5a;
      --line:#e6e6de;
      --line2:#deded2;
      --shadow:0 10px 30px rgba(0,0,0,.06);
      --radius:14px;
    }
    *{ box-sizing:border-box; }
    body{ margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,"Segoe UI",sans-serif; }
    .wrap{ max-width:1310px; margin:0 auto; padding:18px 16px; }
    header{ background:linear-gradient(180deg, rgba(240,230,140,.65), rgba(240,230,140,.18)); border-bottom:1px solid var(--line); }
    .headbar{ display:flex; align-items:center; gap:12px; justify-content:space-between; flex-wrap:wrap; }
    .brand{ display:flex; align-items:center; gap:12px; min-width:0; }
    .brand img{ width:34px; height:34px; object-fit:contain; }
    .brand h1{ margin:0; font-size:16px; }
    .brand .sub{ font-size:12px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:62vw; }
    .pill{ display:inline-flex; gap:8px; padding:8px 10px; background:rgba(255,255,255,.65); border:1px solid var(--line); border-radius:999px; font-size:12px; color:var(--muted); box-shadow:0 2px 12px rgba(0,0,0,.04); }
    .card{ background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:14px; margin-top:14px; }
    .grid{ display:grid; grid-template-columns:1.2fr 1fr 1fr 1fr; gap:12px; align-items:end; }
    .grid > div{ min-width:0; }
    .grid .full{ grid-column:1 / -1; }
    label{ display:block; font-size:12px; margin-bottom:6px; color:#2b2b2b; }
    input,select{ width:100%; padding:10px 12px; border:1px solid var(--line2); border-radius:12px; }
    input:focus,select:focus{ border-color:rgba(0,0,0,.28); box-shadow:0 0 0 4px rgba(240,230,140,.45); outline:none; }
    .actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    button{ padding:10px 14px; border-radius:12px; border:1px solid rgba(0,0,0,.16); background:var(--khaki); font-weight:700; cursor:pointer; }
    a{ color:#111; text-decoration:none; }
    a:hover{ text-decoration:underline; }
    .link-muted{ color:var(--muted); text-decoration:underline; text-decoration-color:rgba(90,90,90,.35); }
    table{ width:100%; border-collapse:collapse; font-size:13px; }
    th,td{ padding:10px; border-bottom:1px solid #efefe6; vertical-align:top; }
    th{ background:#fbfbf7; font-weight:800; border-bottom:1px solid #ececdd; text-align:left; }
    .tag{ display:inline-block; padding:3px 10px; border-radius:999px; background:rgba(240,230,140,.55); border:1px solid rgba(0,0,0,.12); font-size:12px; font-weight:700; }
    .btn{ display:inline-block; padding:8px 10px; border-radius:12px; border:1px solid var(--line2); background:#fff; font-weight:700; font-size:12px; white-space:nowrap; }
    .btn.primary{ background:rgba(240,230,140,.85); border-color:rgba(0,0,0,.14); }
    .btn.danger{ background:#fff; border-color:#d6b7b7; }
    .btn:hover{ filter:brightness(1.02); text-decoration:none; }
    .pager{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .pager a{ padding:8px 10px; border:1px solid var(--line2); border-radius:12px; background:#fff; }
    .pager .active{ background:rgba(240,230,140,.9); font-weight:800; border-color:rgba(0,0,0,.18); }
    .presetbar{ display:flex; gap:8px; flex-wrap:wrap; }
    .badge{ display:inline-block; padding:3px 10px; border-radius:999px; border:1px solid var(--line2); background:#fff; font-size:12px; font-weight:800; }
    .badge.ok{ background:rgba(46, 204, 113, .12); border-color:rgba(46,204,113,.35); }
    .badge.bad{ background:rgba(231, 76, 60, .10); border-color:rgba(231,76,60,.30); }
    .badge.gray{ background:rgba(0,0,0,.06); border-color:rgba(0,0,0,.10); }

    /* =========================
       FIX: Acciones no se cortan
       ========================= */
    td.actions-cell{ min-width: 360px; }
    .actions-wrap{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      align-items:center;
    }

    @media (max-width:980px){ .grid{ grid-template-columns:1fr 1fr; } .brand .sub{ max-width:82vw; } }
    @media (max-width:540px){ .grid{ grid-template-columns:1fr; } td.actions-cell{ min-width: 300px; } }
  </style>
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
      <div class="pill">
        <span>Total: <b><?php echo (int)$totalAll; ?></b></span>
        <span>Filtrados: <b><?php echo (int)$totalFiltered; ?></b></span>
      </div>
    </div>
  </div>
</header>

<div class="wrap">

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
        <div class="full">
          <label for="q">Buscar en URL del documento (url_doc contiene...)</label>
          <input id="q" name="q" value="<?php echo h($q); ?>" placeholder="Ej: parking, handbook, form...">
        </div>

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

        <div>
          <label for="found">Buscar en found_in (página origen contiene...)</label>
          <input id="found" name="found" value="<?php echo h($found); ?>" placeholder="Ej: /pdf/ o /resources">
        </div>

        <div>
          <label for="st">Estado enlace (016)</label>
          <select id="st" name="st">
            <option value="" <?php echo ($stFilter===""?"selected":""); ?>>(todos)</option>
            <option value="ok" <?php echo ($stFilter==="ok"?"selected":""); ?>>OK</option>
            <option value="bad" <?php echo ($stFilter==="bad"?"selected":""); ?>>Rotos</option>
            <option value="unchecked" <?php echo ($stFilter==="unchecked"?"selected":""); ?>>Sin revisar</option>
          </select>
        </div>

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

        <div>
          <label for="per">Filas por página</label>
          <select id="per" name="per">
            <?php foreach ([10,25,50,100] as $n): ?>
              <option value="<?php echo $n; ?>" <?php echo ($per===$n?"selected":""); ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

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

        <div class="actions">
          <button type="submit">Aplicar filtros</button>
          <a class="btn" href="?<?php echo h(qs_list(["action"=>"check_page"])); ?>">Validar esta página</a>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    <?php
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

                <td><span class="tag"><?php echo h($r["ext"]); ?></span></td>

                <td>
                  <span class="<?php echo h($badge[1]); ?>"><?php echo h($badge[0]); ?></span>
                  <?php if (!empty($r["last_content_type"])): ?>
                    <div style="color:var(--muted);font-size:12px;margin-top:6px;">
                      <?php echo h($r["last_content_type"]); ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td style="min-width:320px;"><?php echo h($r["found_in"]); ?></td>
                <td><?php echo h($r["first_seen"]); ?></td>

                <td class="actions-cell">
                  <div class="actions-wrap">
                    <a class="btn" href="<?php echo h($docUrl); ?>" target="_blank" rel="noopener">Abrir</a>
                    <a class="btn primary" href="?<?php echo h(qs_action("download", $docUrl)); ?>">Descargar</a>
                    <a class="btn" href="?<?php echo h(qs_action("check", $docUrl)); ?>">Revalidar</a>

                    <a class="btn" href="?<?php echo h(qs_action("toggle_fav", $docUrl)); ?>">
                      <?php echo $isFav ? "Quitar favorito" : "Favorito"; ?>
                    </a>

                    <a class="btn" href="?<?php echo h(qs_action("toggle_hidden", $docUrl)); ?>">
                      <?php echo $isHidden ? "Mostrar" : "Ocultar"; ?>
                    </a>

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