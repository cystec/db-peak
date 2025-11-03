<?php
/**
 * DB Peek (single file) — MySQL-only, read-only by default
 * Features: login, list tables, schema view, browse/paginate/sort, run SQL (SELECT by default), CSV export
 * License: MIT
 */

declare(strict_types=1);
session_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---------- Config helpers ----------
function envs(string $k, ?string $default=null): ?string { $v=getenv($k); return $v===false?$default:$v; }

const APP_TITLE = 'DB Peek';
const APP_VER   = '0.3-mysql-only';

$CFG = [
    'mysql_host'   => envs('MYSQL_HOST', '127.0.0.1'),
    'mysql_db'     => envs('MYSQL_DB',   'test'),
    'mysql_user'   => envs('MYSQL_USER', 'root'),
    'mysql_pass'   => envs('MYSQL_PASS', ''),
    'mysql_charset'=> envs('MYSQL_CHARSET', 'utf8mb4'),
    'app_user'     => envs('APP_USER', 'user'),
    'app_pass'     => envs('APP_PASS', 'change-this'),
    'rows_per_page'=> (int)envs('ROWS_PER_PAGE', '50'),
    'allow_write'  => envs('ALLOW_WRITE', '') === '1',
    'allow_ips'    => envs('ALLOW_IPS', ''),              // e.g. "127.0.0.1,203.0.113.5"
    'access_token' => envs('ACCESS_TOKEN', ''),           // require ?k=TOKEN
];

// ---------- Basic gatekeeping ----------
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_ok(): bool { return isset($_POST['csrf']) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }
function require_login(): void { if (empty($_SESSION['auth_ok'])) { header('Location: ?a=login'); exit; } }
function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $scheme.'://'.$host.$path;
}

// IP allowlist
if ($CFG['allow_ips'] !== '') {
    $ips = array_filter(array_map('trim', explode(',', $CFG['allow_ips'])));
    $client = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($client, $ips, true)) {
        http_response_code(403);
        echo "Forbidden (IP not allowed)";
        exit;
    }
}

// Access token
if ($CFG['access_token'] !== '') {
    if (!isset($_GET['k']) || !hash_equals($CFG['access_token'], (string)$_GET['k'])) {
        http_response_code(403);
        echo "Forbidden (missing/invalid token)";
        exit;
    }
}

// ---------- PDO (MySQL only) ----------
function pdo_mysql(array $cfg): PDO {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['mysql_host'], $cfg['mysql_db'], $cfg['mysql_charset']);
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $cfg['mysql_user'], $cfg['mysql_pass'], $opt);
}

try { $pdo = pdo_mysql($CFG); }
catch (Throwable $e) {
    http_response_code(500);
    echo "<!doctype html><meta charset='utf-8'><pre>DB connection failed.\n\n".
         "Host: ".h($CFG['mysql_host'])."\n".
         "DB:   ".h($CFG['mysql_db'])."\n".
         "Err:  ".h($e->getMessage())."</pre>";
    exit;
}

// ---------- DB utils ----------
function qid(string $id): string { return '`'.str_replace('`','``',$id).'`'; }
function tables(PDO $pdo): array {
    $rows = $pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM);
    return array_map(fn($r)=>$r[0], $rows ?: []);
}
function columns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("DESCRIBE ".qid($table));
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}
function count_rows(PDO $pdo, string $table): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM ".qid($table));
    return (int)$stmt->fetchColumn();
}
function is_select_only(string $sql): bool {
    // Accept leading comments/whitespace; ensure the first real statement starts with SELECT
    $s = ltrim($sql);
    // strip leading SQL line comments
    $s = preg_replace('~^(--[^\n]*\n|/\*.*?\*/\s*)+~s', '', $s) ?? $s;
    return (bool)preg_match('/^\s*SELECT\b/i', $s);
}

// ---------- View chrome ----------
function render_head(string $title): void {
    $app = APP_TITLE.' v'.APP_VER;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    if (session_status() === PHP_SESSION_ACTIVE) {
        @ini_set('session.cookie_httponly','1');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            @ini_set('session.cookie_secure','1');
        }
    }
    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>".h($title)." • ".h($app)."</title>";
    echo "<style>
    :root{--bg:#0f1115;--panel:#171a21;--muted:#8b949e;--fg:#e6edf3;--accent:#4aa3ff;--line:#262a33}
    *{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Arial;background:var(--bg);color:var(--fg)}
    a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
    header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--panel);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}
    .brand{font-weight:700}.muted{color:var(--muted)}
    .wrap{max-width:1200px;margin:0 auto;padding:16px}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:14px}
    .row{display:flex;gap:14px;flex-wrap:wrap}.grow{flex:1 1 300px}
    table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid var(--line);vertical-align:top}th{text-align:left;font-weight:600}
    input,select,textarea,button{background:#0d0f14;color:var(--fg);border:1px solid var(--line);border-radius:8px;padding:8px 10px}
    input:focus,select:focus,textarea:focus{outline:1px solid var(--accent);border-color:var(--accent)}
    .btn{display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:#0d0f14}
    .btn.primary{background:var(--accent);border-color:var(--accent);color:#061b33;font-weight:700}
    .pill{display:inline-block;padding:3px 8px;border-radius:999px;border:1px solid var(--line);background:#10131a;font-size:12px}
    .notice{padding:10px 12px;background:#10131a;border:1px dashed var(--line);border-radius:8px}
    .right{text-align:right}
    </style></head><body>";
    echo "<header><div class='brand'>".h(APP_TITLE)." <span class='muted'>".h(APP_VER)."</span></div>";
    echo "<nav class='muted'>";
    if (!empty($_SESSION['auth_ok'])) {
        echo "<a href='?'>Home</a> • <a href='?a=q'>Query</a> • <a href='?a=logout' onclick=\"return confirm('Log out?')\">Logout</a>";
    } else {
        echo "Please sign in";
    }
    echo "</nav></header><div class='wrap'>";
}
function render_foot(): void {
    echo "<div class='muted' style='margin-top:18px'>Keep this tool private.</div></div></body></html>";
}

// ---------- Auth routes ----------
$action = $_GET['a'] ?? 'home';
if ($action === 'logout') { session_destroy(); header('Location: ?a=login'); exit; }

if ($action === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Bad CSRF'); }
    $u = (string)($_POST['user'] ?? '');
    $p = (string)($_POST['pass'] ?? '');
    if (hash_equals($CFG['app_user'], $u) && hash_equals($CFG['app_pass'], $p)) {
        session_regenerate_id(true);
        $_SESSION['auth_ok'] = true;
        header('Location: ?'); exit;
    }
    $_SESSION['auth_ok'] = false;
    $err = 'Invalid credentials';
}

if ($action === 'login' || empty($_SESSION['auth_ok'])) {
    render_head('Sign in');
    echo "<div class='card' style='max-width:420px;margin:40px auto;'><h2>Sign in</h2>";
    if (!empty($err)) echo "<div class='notice' style='color:#ff6b6b'>".h($err)."</div>";
    if ($CFG['app_pass']==='change-this') echo "<div class='notice'>Default password is active. Set <code>APP_PASS</code>.</div>";
    echo "<form method='post'><input type='hidden' name='csrf' value='".h(csrf_token())."'>";
    echo "<div style='display:grid;gap:10px'><label>Username<br><input name='user' autocomplete='username' required></label>";
    echo "<label>Password<br><input name='pass' type='password' autocomplete='current-password' required></label>";
    echo "<button class='btn primary'>Enter</button></div></form></div>";
    render_foot(); exit;
}

// ---------- Home (tables list) ----------
if ($action === 'home') {
    render_head('Home');
    $tabs = tables($pdo);
    echo "<div class='row'>";
    echo "<div class='grow card'><h3>Connection</h3><div>Driver: <span class='pill'>MySQL</span></div>";
    echo "<div class='muted' style='margin-top:6px'>Host: <code>".h($CFG['mysql_host'])."</code> • DB: <code>".h($CFG['mysql_db'])."</code></div>";
    echo "<div class='muted'>Mode: <span class='pill'>".($CFG['allow_write']?'read/write':'read-only')."</span></div></div>";
    echo "<div class='grow card'><h3>Quick</h3><a class='btn' href='?a=q'>Open query</a></div></div>";
    echo "<div class='card'><h3>Tables (".count($tabs).")</h3>";
    if (!$tabs) {
        echo "<div class='notice'>No tables found.</div>";
    } else {
        echo "<div style='display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px'>";
        foreach ($tabs as $t) {
            $cnt = 0;
            try { $cnt = count_rows($pdo,$t); } catch (Throwable $e) {}
            echo "<div class='card' style='margin:0'><div style='display:flex;justify-content:space-between;gap:8px;align-items:center'>";
            echo "<div><strong>".h($t)."</strong><div class='muted'>".h((string)$cnt)." rows</div></div>";
            echo "<div class='right'><a class='btn' href='?a=browse&t=".urlencode($t)."'>Browse</a> <a class='btn' href='?a=schema&t=".urlencode($t)."'>Schema</a> <a class='btn' href='?a=csv&t=".urlencode($t)."'>CSV</a></div>";
            echo "</div></div>";
        }
        echo "</div>";
    }
    echo "</div>";
    render_foot(); exit;
}

// ---------- Schema ----------
if ($action === 'schema') {
    $t = (string)($_GET['t'] ?? '');
    render_head('Schema • '.$t);
    if ($t==='') { echo "<div class='card'>Missing table.</div>"; render_foot(); exit; }
    try { $cols = columns($pdo, $t); }
    catch (Throwable $e) { echo "<div class='card'>Error: ".h($e->getMessage())."</div>"; render_foot(); exit; }
    echo "<div class='card'><div class='right'><a class='btn' href='?a=browse&t=".urlencode($t)."'>Browse</a> <a class='btn' href='?a=csv&t=".urlencode($t)."'>CSV</a></div>";
    echo "<h3>Schema: ".h($t)."</h3><table><thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>";
    foreach ($cols as $c) {
        echo "<tr><td>".h($c['Field'])."</td><td>".h($c['Type'])."</td><td>".h($c['Null'])."</td><td>".h($c['Key'])."</td><td>".h((string)$c['Default'])."</td><td>".h($c['Extra']??'')."</td></tr>";
    }
    echo "</tbody></table></div>";
    render_foot(); exit;
}

// ---------- Browse ----------
if ($action === 'browse') {
    $t = (string)($_GET['t'] ?? '');
    if ($t==='') { header('Location: ?'); exit; }
    $page = max(1,(int)($_GET['p']??1));
    $per  = max(1,min(500,(int)($_GET['per'] ?? $CFG['rows_per_page'])));
    $order = (string)($_GET['o'] ?? '');
    $dir   = strtoupper((string)($_GET['d'] ?? 'ASC')); $dir = in_array($dir,['ASC','DESC'],true)?$dir:'ASC';
    $offset = ($page-1)*$per;

    $cols = array_map(fn($c)=>$c['Field'], columns($pdo,$t));
    $order_sql = '';
    if ($order && in_array($order, $cols, true)) $order_sql = " ORDER BY ".qid($order)." $dir";
    $sql = "SELECT * FROM ".qid($t).$order_sql." LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $total = count_rows($pdo,$t);
    $pages = max(1,(int)ceil($total/$per));

    render_head('Browse • '.$t);
    echo "<div class='card'>";
    echo "<div class='right'><a class='btn' href='?a=schema&t=".urlencode($t)."'>Schema</a> <a class='btn' href='?a=csv&t=".urlencode($t)."'>CSV</a> <a class='btn' href='?a=q&pref=SELECT%20*%20FROM%20".urlencode($t)."%20LIMIT%20100%3B'>Query</a></div>";
    echo "<h3>Browsing: ".h($t)."</h3><div class='muted'>Total rows: ".h((string)$total)."</div>";

    $base = base_url().'?a=browse&t='.urlencode($t).'&per='.$per.($order ? '&o='.urlencode($order).'&d='.$dir : '');
    echo "<div style='display:flex;gap:10px;align-items:center;margin:8px 0'>";
    echo "<form method='get' class='toolbar' action=''><input type='hidden' name='a' value='browse'><input type='hidden' name='t' value='".h($t)."'>";
    echo "Per page <input type='number' name='per' value='".h((string)$per)."' min='1' max='500' style='width:90px'>";
    echo " Order <select name='o'><option value=''>--</option>";
    foreach ($cols as $cn) { $sel=$cn===$order?'selected':''; echo "<option $sel>".h($cn)."</option>"; }
    echo "</select> <select name='d'><option ".($dir==='ASC'?'selected':'').">ASC</option><option ".($dir==='DESC'?'selected':'').">DESC</option></select>";
    echo " <button class='btn'>Apply</button></form>";
    echo "<div style='margin-left:auto'>Page: <a class='btn' href='".$base."&p=1'>&laquo; First</a> ";
    echo "<a class='btn' href='".$base."&p=".max(1,$page-1)."'>&lsaquo; Prev</a> ";
    echo "<span class='pill'>".h("$page / $pages")."</span> ";
    echo "<a class='btn' href='".$base."&p=".min($pages,$page+1)."'>Next &rsaquo;</a> ";
    echo "<a class='btn' href='".$base."&p=".$pages."'>Last &raquo;</a></div></div>";

    if (!$rows) {
        echo "<div class='notice'>No rows on this page.</div>";
    } else {
        echo "<div style='overflow:auto'><table><thead><tr>";
        foreach (array_keys($rows[0]) as $cn) {
            $dir2 = ($order===$cn && $dir==='ASC') ? 'DESC' : 'ASC';
            $link = base_url().'?a=browse&t='.urlencode($t)."&p=1&per=$per&o=".urlencode($cn)."&d=$dir2";
            echo "<th><a href='".h($link)."'>".h($cn)."</a></th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $r) { echo "<tr>";
            foreach ($r as $v) echo "<td>".(is_null($v)?"<span class='muted'>NULL</span>":h((string)$v))."</td>";
            echo "</tr>"; }
        echo "</tbody></table></div>";
    }
    echo "</div>";
    render_foot(); exit;
}

// ---------- CSV export (read-only) ----------
if ($action === 'csv') {
    $t = (string)($_GET['t'] ?? '');
    if ($t==='') { http_response_code(400); echo "Missing table"; exit; }
    $stmt = $pdo->query("SELECT * FROM ".qid($t)." LIMIT 1");
    $first = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmt = $pdo->query("SELECT * FROM ".qid($t));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.basename($t).'-export.csv"');
    $out = fopen('php://output', 'w');
    if ($first) fputcsv($out, array_keys($first));
    if ($first) fputcsv($out, array_values($first));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, $row); }
    fclose($out); exit;
}

// ---------- Query (SELECT by default) ----------
if ($action === 'q') {
    $pref = (string)($_GET['pref'] ?? '');
    $err = null; $took_ms = null; $result_sets = [];

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!csrf_ok()) { http_response_code(400); exit('Bad CSRF'); }
        $sql = trim((string)($_POST['sql'] ?? ''));
        $start = microtime(true);
        try {
            if (!$CFG['allow_write'] && !is_select_only($sql)) {
                throw new RuntimeException('Write queries are disabled (set ALLOW_WRITE=1 to enable).');
            }
            // single statement only (no multi)
            $stmt = $pdo->query($sql);
            if ($stmt instanceof PDOStatement) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result_sets[] = ['rows'=>$rows, 'sql'=>$sql];
            } else {
                $result_sets[] = ['rows'=>[], 'sql'=>$sql];
            }
        } catch (Throwable $e) { $err = $e->getMessage(); }
        finally { $took_ms = (int)round((microtime(true)-$start)*1000); }
    }

    render_head('Query');
    echo "<div class='card'><h3>Query</h3>";
    echo "<form method='post' id='qform'><input type='hidden' name='csrf' value='".h(csrf_token())."'>";
    echo "<textarea name='sql' rows='10' style='width:100%;font-family:ui-monospace,monospace' placeholder='SELECT * FROM your_table LIMIT 100;'>".h($pref ?: ($_POST['sql'] ?? ''))."</textarea>";
    echo "<div style='display:flex;gap:8px;align-items:center;margin-top:8px'><span class='muted'>Mode: ".($CFG['allow_write']?'<span class=\"pill\">read/write</span>':'<span class=\"pill\">read-only</span>')."</span>";
    echo "<button class='btn primary' style='margin-left:auto'>Run (Ctrl+Enter)</button></div></form>";
    echo "<script>document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){document.getElementById('qform').submit();}});</script>";
    if ($err) echo "<div class='notice' style='color:#ff6b6b'><strong>Error:</strong> ".h($err)."</div>";
    elseif ($_SERVER['REQUEST_METHOD']==='POST') echo "<div class='notice'>Completed in ".h((string)$took_ms)." ms</div>";
    foreach ($result_sets as $i=>$res) {
        echo "<h4>Result ".($i+1)."</h4><div class='muted'><code>".h($res['sql'])."</code></div>";
        $rows = $res['rows'];
        if (!$rows) { echo "<div class='notice'>No rows.</div>"; continue; }
        echo "<div style='overflow:auto'><table><thead><tr>";
        foreach (array_keys($rows[0]) as $cn) echo "<th>".h($cn)."</th>";
        echo "</tr></thead><tbody>";
        foreach ($rows as $r) { echo "<tr>"; foreach ($r as $v) echo "<td>".(is_null($v)?"<span class='muted'>NULL</span>":h((string)$v))."</td>"; echo "</tr>"; }
        echo "</tbody></table></div>";
    }
    echo "</div>";
    render_foot(); exit;
}

// ---------- 404 ----------
http_response_code(404);
render_head('Not found');
echo "<div class='card'>Unknown action.</div>";
render_foot();
