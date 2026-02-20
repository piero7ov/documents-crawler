<?php
/* =========================
   Interfaz PHP: listado de docs + filtros (SQLite)
   Requisitos:
   - docs.sqlite en la misma carpeta que este archivo
   - Extensión SQLite3 habilitada en PHP (XAMPP suele traerla)
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
   Inputs (GET)
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

/* =========================
   ORDER BY seguro
   ========================= */
$orders = [
  "newest" => "first_seen DESC",
  "oldest" => "first_seen ASC",
  "ext"    => "ext ASC, first_seen DESC",
  "found"  => "found_in ASC, first_seen DESC",
];

$orderBy = $orders[$sort] ?? $orders["newest"];

/* =========================
   WHERE dinámico (con bind)
   ========================= */
$where = [];
$params = [];

if ($q !== "") {
  $where[] = "url_doc LIKE :q";
  $params[":q"] = "%" . $q . "%";
}

if ($ext !== "") {
  $where[] = "ext = :ext";
  $params[":ext"] = $ext;
}

if ($found !== "") {
  $where[] = "found_in LIKE :found";
  $params[":found"] = "%" . $found . "%";
}

$whereSql = "";
if (count($where) > 0) {
  $whereSql = "WHERE " . implode(" AND ", $where);
}

/* =========================
   Opciones de filtros (ext)
   ========================= */
$extOptions = [];
$resExt = $db->query("SELECT ext, COUNT(*) AS total FROM docs GROUP BY ext ORDER BY total DESC, ext ASC;");
while ($row = $resExt->fetchArray(SQLITE3_ASSOC)) {
  $extOptions[] = $row;
}

/* =========================
   Conteos
   ========================= */
$totalAll = 0;
$rAll = $db->querySingle("SELECT COUNT(*) FROM docs;");
if (is_numeric($rAll)) $totalAll = (int)$rAll;

$stmtCount = $db->prepare("SELECT COUNT(*) AS n FROM docs $whereSql;");
foreach ($params as $k => $v) {
  $stmtCount->bindValue($k, $v, SQLITE3_TEXT);
}
$rCount = $stmtCount->execute();
$rowCount = $rCount->fetchArray(SQLITE3_ASSOC);
$totalFiltered = (int)($rowCount["n"] ?? 0);

$totalPages = (int)ceil($totalFiltered / $per);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

/* =========================
   Query resultados
   ========================= */
$sql = "
  SELECT url_doc, ext, found_in, how_detected, first_seen
  FROM docs
  $whereSql
  ORDER BY $orderBy
  LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v, SQLITE3_TEXT);
}
$stmt->bindValue(":limit", $per, SQLITE3_INTEGER);
$stmt->bindValue(":offset", $offset, SQLITE3_INTEGER);

$res = $stmt->execute();

$rows = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $rows[] = $row;
}

/* =========================
   Helpers de URL (paginación)
   ========================= */
function build_query(array $overrides = []){
  $base = $_GET;
  foreach ($overrides as $k => $v) {
    $base[$k] = $v;
  }
  return http_build_query($base);
}

/* =========================
   Branding
   ========================= */
$logoUrl = "https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/solologo_negro.png";
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>014 - Documentos detectados</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root{
      --khaki: #F0E68C;
      --bg: #f7f7f2;
      --card: #ffffff;
      --text: #121212;
      --muted: #5a5a5a;
      --line: #e6e6de;
      --line2: #deded2;
      --shadow: 0 10px 30px rgba(0,0,0,.06);
      --radius: 14px;
    }

    *{ box-sizing: border-box; }

    body{
      margin:0;
      background: var(--bg);
      color: var(--text);
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    }

    .wrap{
      max-width: 1120px;
      margin: 0 auto;
      padding: 18px 16px;
    }

    header{
      background: linear-gradient(180deg, rgba(240,230,140,.65), rgba(240,230,140,.18));
      border-bottom: 1px solid var(--line);
    }

    .headbar{
      display:flex;
      align-items:center;
      gap: 12px;
      justify-content: space-between;
    }

    .brand{
      display:flex;
      align-items:center;
      gap: 12px;
      min-width: 0;
    }

    .brand img{
      width: 34px;
      height: 34px;
      object-fit: contain;
      flex: 0 0 auto;
    }

    .brand .titles{
      min-width: 0;
    }

    .brand h1{
      margin:0;
      font-size: 16px;
      letter-spacing: .2px;
      line-height: 1.2;
    }

    .brand .sub{
      margin-top: 2px;
      font-size: 12px;
      color: var(--muted);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 62vw;
    }

    .pill{
      display:inline-flex;
      align-items:center;
      gap: 8px;
      padding: 8px 10px;
      background: rgba(255,255,255,.65);
      border: 1px solid var(--line);
      border-radius: 999px;
      font-size: 12px;
      color: var(--muted);
      box-shadow: 0 2px 12px rgba(0,0,0,.04);
      white-space: nowrap;
    }

    .card{
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 14px;
      margin-top: 14px;
    }

    .row{
      display:flex;
      gap: 10px;
      align-items:center;
      justify-content: space-between;
      flex-wrap: wrap;
    }

    .muted{ color: var(--muted); font-size: 13px; }
    .small{ font-size: 12px; color: var(--muted); }

    .grid{
      display:grid;
      grid-template-columns: 1.2fr 1fr 1fr 1fr;
      gap: 12px;
      align-items: end;
    }

    .grid > div{ min-width: 0; }
    .grid .full{ grid-column: 1 / -1; }

    label{
      display:block;
      font-size: 12px;
      margin-bottom: 6px;
      color: #2b2b2b;
    }

    input, select{
      width: 100%;
      max-width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--line2);
      border-radius: 12px;
      background: #fff;
      color: var(--text);
      outline: none;
    }

    input:focus, select:focus{
      border-color: rgba(0,0,0,.28);
      box-shadow: 0 0 0 4px rgba(240,230,140,.45);
    }

    .actions{
      display:flex;
      gap: 10px;
      align-items:center;
      justify-content: flex-start;
      flex-wrap: wrap;
    }

    button{
      padding: 10px 14px;
      border-radius: 12px;
      border: 1px solid rgba(0,0,0,.16);
      background: var(--khaki);
      color: #111;
      cursor: pointer;
      font-weight: 600;
    }

    button:hover{ filter: brightness(1.02); }
    button:active{ transform: translateY(1px); }

    a{
      color: #111;
      text-decoration: none;
    }

    a:hover{ text-decoration: underline; }

    .link-muted{
      color: var(--muted);
      text-decoration: underline;
      text-decoration-color: rgba(90,90,90,.35);
    }

    .pager{
      display:flex;
      gap: 8px;
      align-items:center;
      flex-wrap: wrap;
    }

    .pager a{
      display:inline-block;
      padding: 8px 10px;
      border: 1px solid var(--line2);
      border-radius: 12px;
      background: #fff;
      color: #111;
    }

    .pager .active{
      background: rgba(240,230,140,.9);
      border-color: rgba(0,0,0,.18);
      font-weight: 700;
    }

    table{
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    th, td{
      padding: 10px;
      border-bottom: 1px solid #efefe6;
      vertical-align: top;
    }

    th{
      text-align:left;
      background: #fbfbf7;
      font-weight: 700;
      border-bottom: 1px solid #ececdd;
    }

    .tag{
      display:inline-block;
      padding: 3px 10px;
      border-radius: 999px;
      background: rgba(240,230,140,.55);
      border: 1px solid rgba(0,0,0,.12);
      font-size: 12px;
    }

    .doc-url{
      word-break: break-word;
    }

    @media (max-width: 980px){
      .grid{ grid-template-columns: 1fr 1fr; }
      .brand .sub{ max-width: 82vw; }
    }

    @media (max-width: 540px){
      .grid{ grid-template-columns: 1fr; }
      .headbar{ gap: 10px; }
      .pill{ width: 100%; justify-content: space-between; }
    }
  </style>
</head>
<body>

<header>
  <div class="wrap">
    <div class="headbar">
      <div class="brand">
        <img src="<?php echo h($logoUrl); ?>" alt="Logo">
        <div class="titles">
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
    <div class="row">
      <div class="muted">
        Página <b><?php echo (int)$page; ?></b> de <b><?php echo (int)$totalPages; ?></b>
        (mostrando <?php echo count($rows); ?> filas)
      </div>

      <div class="pager">
        <?php $prev = $page - 1; $next = $page + 1; ?>

        <?php if ($page > 1): ?>
          <a href="?<?php echo h(build_query(["page"=>$prev])); ?>">Anterior</a>
        <?php endif; ?>

        <?php
          $start = max(1, $page - 2);
          $end = min($totalPages, $page + 2);
          for ($p = $start; $p <= $end; $p++):
            $cls = ($p === $page) ? "active" : "";
        ?>
          <a class="<?php echo $cls; ?>" href="?<?php echo h(build_query(["page"=>$p])); ?>"><?php echo $p; ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <a href="?<?php echo h(build_query(["page"=>$next])); ?>">Siguiente</a>
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
            <th>Método</th>
            <th>Fecha</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) === 0): ?>
            <tr><td colspan="5" class="muted">No hay resultados con estos filtros.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="doc-url" style="min-width:320px;">
                  <a href="<?php echo h($r["url_doc"]); ?>" target="_blank" rel="noopener">
                    <?php echo h($r["url_doc"]); ?>
                  </a>
                </td>
                <td><span class="tag"><?php echo h($r["ext"]); ?></span></td>
                <td style="min-width:320px;"><?php echo h($r["found_in"]); ?></td>
                <td><?php echo h($r["how_detected"]); ?></td>
                <td><?php echo h($r["first_seen"]); ?></td>
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