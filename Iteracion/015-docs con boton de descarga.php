<?php
/* =========================
   014 + 015 (1 solo archivo)
   - Listado de docs desde SQLite con filtros
   - Descargar documento vía ?action=download&u=...
   Estilo claro + principal Khaki + logo
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
  $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
  $db->busyTimeout(30000);
} catch (Exception $e) {
  http_response_code(500);
  echo "No se pudo abrir la base de datos: " . h($e->getMessage());
  exit;
}

/* =========================
   Query helpers
   ========================= */
function qs_list(array $overrides = []){
  $base = $_GET;
  unset($base["action"], $base["u"]); // paginación/filtros NO deben llevar action/u
  foreach ($overrides as $k => $v) {
    $base[$k] = $v;
  }
  return http_build_query($base);
}

function qs_download(string $docUrl){
  $base = $_GET; // conserva filtros actuales
  $base["action"] = "download";
  $base["u"] = $docUrl;
  return http_build_query($base);
}

/* =========================
   015 - Descarga (en el mismo archivo)
   ========================= */
$action = (string)($_GET["action"] ?? "");
if ($action === "download") {
  $u = trim((string)($_GET["u"] ?? ""));

  if ($u === "" || !preg_match('#^https?://#i', $u)) {
    http_response_code(400);
    echo "URL inválida.";
    exit;
  }

  /* Verificar que exista en BD */
  $stmt = $db->prepare("SELECT url_doc, ext FROM docs WHERE url_doc = :u LIMIT 1;");
  $stmt->bindValue(":u", $u, SQLITE3_TEXT);
  $res = $stmt->execute();
  $row = $res->fetchArray(SQLITE3_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo "Ese documento no existe en la base de datos.";
    exit;
  }

  $url_doc = (string)$row["url_doc"];
  $ext     = (string)$row["ext"];

  /* Nombre de archivo */
  $path = parse_url($url_doc, PHP_URL_PATH);
  $base = $path ? basename($path) : "";
  $base = $base ?: ("documento" . $ext);

  $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base);
  if ($filename === "" || $filename === "_") {
    $filename = "documento" . $ext;
  }

  /* Límite para no reventar servidor */
  $MAX_BYTES = 30 * 1024 * 1024; // 30MB

  /* Temporal */
  $tmp = tempnam(sys_get_temp_dir(), "docdl_");
  if ($tmp === false) {
    http_response_code(500);
    echo "No se pudo crear archivo temporal.";
    exit;
  }

  $fp = fopen($tmp, "wb");
  if ($fp === false) {
    @unlink($tmp);
    http_response_code(500);
    echo "No se pudo abrir archivo temporal.";
    exit;
  }

  $ctype = "application/octet-stream";
  $ok = false;

  /* Preferir cURL */
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

    /* Corte por tamaño durante descarga */
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

    $err = curl_error($ch);
    curl_close($ch);

    $ok = ($exec !== false && $httpCode >= 200 && $httpCode < 300 && $err === "");
  }

  /* Fallback: allow_url_fopen */
  if (!$ok) {
    ftruncate($fp, 0);
    rewind($fp);

    $ctx = stream_context_create([
      "http" => [
        "method" => "GET",
        "timeout" => 90,
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120\r\n"
      ]
    ]);

    $in = @fopen($url_doc, "rb", false, $ctx);
    if ($in) {
      $bytes = 0;
      while (!feof($in)) {
        $chunk = fread($in, 8192);
        if ($chunk === false) break;
        $bytes += strlen($chunk);
        if ($bytes > $MAX_BYTES) { fclose($in); $in = null; break; }
        fwrite($fp, $chunk);
      }
      if ($in) {
        fclose($in);
        $ok = true;
      }
    }
  }

  fclose($fp);

  if (!$ok) {
    @unlink($tmp);
    http_response_code(502);
    echo "No se pudo descargar el documento (o excede el límite de tamaño).";
    exit;
  }

  $size = filesize($tmp);
  if ($size === false || $size <= 0) {
    @unlink($tmp);
    http_response_code(502);
    echo "Descarga vacía o inválida.";
    exit;
  }
  if ($size > $MAX_BYTES) {
    @unlink($tmp);
    http_response_code(413);
    echo "El archivo es demasiado grande para descargarlo desde aquí.";
    exit;
  }

  while (ob_get_level() > 0) { @ob_end_clean(); }

  header("Content-Type: " . $ctype);
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header("Content-Length: " . (string)$size);
  header("X-Content-Type-Options: nosniff");

  readfile($tmp);
  @unlink($tmp);
  exit;
}

/* =========================
   Inputs (GET) para el listado
   ========================= */
$q     = trim((string)($_GET["q"] ?? ""));
$ext   = trim((string)($_GET["ext"] ?? ""));
$found = trim((string)($_GET["found"] ?? ""));

$sort  = (string)($_GET["sort"] ?? "newest");
$page  = (int)($_GET["page"] ?? 1);
$per   = (int)($_GET["per"] ?? 25);

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
];
$orderBy = $orders[$sort] ?? $orders["newest"];

/* WHERE dinámico */
$where = [];
$params = [];

if ($q !== "") { $where[] = "url_doc LIKE :q"; $params[":q"] = "%" . $q . "%"; }
if ($ext !== "") { $where[] = "ext = :ext"; $params[":ext"] = $ext; }
if ($found !== "") { $where[] = "found_in LIKE :found"; $params[":found"] = "%" . $found . "%"; }

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
  SELECT url_doc, ext, found_in, how_detected, first_seen
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

$logoUrl = "https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/solologo_negro.png";
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Documentos detectados</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{ --khaki:#F0E68C; --bg:#f7f7f2; --card:#fff; --text:#121212; --muted:#5a5a5a; --line:#e6e6de; --line2:#deded2; --shadow:0 10px 30px rgba(0,0,0,.06); --radius:14px; }
    *{ box-sizing:border-box; }
    body{ margin:0; background:var(--bg); color:var(--text); font-family:system-ui,-apple-system,"Segoe UI",sans-serif; }
    .wrap{ max-width:1120px; margin:0 auto; padding:18px 16px; }
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
    .actions{ display:flex; gap:10px; flex-wrap:wrap; }
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
    .pager{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .pager a{ padding:8px 10px; border:1px solid var(--line2); border-radius:12px; background:#fff; }
    .pager .active{ background:rgba(240,230,140,.9); font-weight:800; border-color:rgba(0,0,0,.18); }
    @media (max-width:980px){ .grid{ grid-template-columns:1fr 1fr; } .brand .sub{ max-width:82vw; } }
    @media (max-width:540px){ .grid{ grid-template-columns:1fr; } }
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
    <form method="get">
      <div class="grid">
        <div class="full">
          <label for="q">Buscar en URL del documento (url_doc contiene...)</label>
          <input id="q" name="q" value="<?php echo h($q); ?>" placeholder="Ej: boletin, pdf, formulario...">
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
          <label for="sort">Orden</label>
          <select id="sort" name="sort">
            <option value="newest" <?php echo ($sort==="newest"?"selected":""); ?>>Más recientes</option>
            <option value="oldest" <?php echo ($sort==="oldest"?"selected":""); ?>>Más antiguos</option>
            <option value="ext" <?php echo ($sort==="ext"?"selected":""); ?>>Por extensión</option>
            <option value="found" <?php echo ($sort==="found"?"selected":""); ?>>Por found_in</option>
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

        <div class="actions">
          <button type="submit">Aplicar filtros</button>
          <a class="link-muted" href="?">Limpiar</a>
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
            <th>Origen (found_in)</th>
            <th>Fecha</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) === 0): ?>
            <tr><td colspan="5" style="color:var(--muted);">No hay resultados con estos filtros.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php $docUrl = (string)$r["url_doc"]; ?>
              <tr>
                <td style="min-width:320px;word-break:break-word;">
                  <a href="<?php echo h($docUrl); ?>" target="_blank" rel="noopener"><?php echo h($docUrl); ?></a>
                  <div style="color:var(--muted);font-size:12px;margin-top:6px;">
                    how_detected: <?php echo h($r["how_detected"]); ?>
                  </div>
                </td>
                <td><span class="tag"><?php echo h($r["ext"]); ?></span></td>
                <td style="min-width:320px;"><?php echo h($r["found_in"]); ?></td>
                <td><?php echo h($r["first_seen"]); ?></td>
                <td style="white-space:nowrap;">
                  <a class="btn" href="<?php echo h($docUrl); ?>" target="_blank" rel="noopener">Abrir</a>
                  <a class="btn primary" href="?<?php echo h(qs_download($docUrl)); ?>">Descargar</a>
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