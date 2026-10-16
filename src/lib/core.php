<?php
// Core: config, storage, time, auth, CSRF, notifications, layout helpers.
// Compatible with PHP 7.4+.

if (!defined('APP')) { http_response_code(404); exit; }

const APP_NAME = 'heLMSman';
define('APP_VERSION', '1.0.0');

// ---------- polyfills ----------
if (!function_exists('str_contains')) {
  function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
  function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
  function str_ends_with($h, $n) { return $n === '' || substr($h, -strlen($n)) === $n; }
}

// ---------- storage ----------
// Every data file is stored as *.php with an exit header, so even if the web
// server ignored .htaccess, fetching a data file directly reveals nothing.
const GUARD = "<?php http_response_code(404); exit; ?>\n";

function data_init() {
  if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
  $ht = DATA_DIR . '/.htaccess';
  if (!is_file($ht)) {
    @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
  }
  if (!is_file(DATA_DIR . '/index.html')) { @file_put_contents(DATA_DIR . '/index.html', ''); }
}

function dpath($rel) { return DATA_DIR . '/' . $rel; }

// Creates a folder (and parents) with an empty index.html in each new level, so
// even a server that ignores .htaccess never lists folder contents.
function ensure_dir($dir) {
  if (is_dir($dir)) return;
  ensure_dir(dirname($dir));
  @mkdir($dir, 0775);
  if (is_dir($dir) && !is_file("$dir/index.html")) @file_put_contents("$dir/index.html", '');
}

function jread($rel, $default = []) {
  $f = dpath($rel) . '.php';
  if (!is_file($f)) return $default;
  $s = @file_get_contents($f);
  if ($s === false) return $default;
  $p = strpos($s, "\n");
  $d = json_decode($p === false ? '' : substr($s, $p + 1), true);
  return is_array($d) ? $d : $default;
}

function jwrite($rel, $data) {
  $f = dpath($rel) . '.php';
  ensure_dir(dirname($f));
  $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  if (file_put_contents($tmp, GUARD . $json, LOCK_EX) === false) throw new Exception('Cannot write data file. Check folder permissions.');
  if (!@rename($tmp, $f)) { @unlink($f); rename($tmp, $f); }
}

// Read-modify-write under an exclusive lock. $fn receives data, returns new data.
function jupdate($rel, callable $fn, $default = []) {
  $lockf = dpath($rel) . '.lock';
  ensure_dir(dirname($lockf));
  $lh = fopen($lockf, 'c');
  if ($lh) flock($lh, LOCK_EX);
  try {
    $data = jread($rel, $default);
    $new = $fn($data);
    if ($new !== null) { jwrite($rel, $new); $data = $new; }
  } finally {
    if ($lh) { flock($lh, LOCK_UN); fclose($lh); }
  }
  return $data;
}

function jdelete($rel) { @unlink(dpath($rel) . '.php'); @unlink(dpath($rel) . '.lock'); }

function rrmdir($dir) {
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = "$dir/$f";
    is_dir($p) ? rrmdir($p) : @unlink($p);
  }
  @rmdir($dir);
}

function newid($prefix) { return $prefix . bin2hex(random_bytes(5)); }
function safe_id($s) { return preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$s); }

// ---------- site settings ----------
function site() {
  static $s = null;
  if ($s === null) $s = jread('site', []) + ['name' => 'Courses', 'tz' => 'America/Chicago', 'tagline' => ''];
  return $s;
}

// ---------- time ----------
function now() { return time(); }

function tz_obj($tz) {
  try { return new DateTimeZone($tz ?: 'America/Chicago'); } catch (Exception $e) { return new DateTimeZone('UTC'); }
}

function fmt_dt($ts, $tz = null, $fmt = 'D M j, g:i a') {
  if (!$ts) return '';
  $d = new DateTime('@' . (int)$ts);
  $d->setTimezone(tz_obj($tz ?: site()['tz']));
  return $d->format($fmt);
}
function fmt_date($ts, $tz = null) { return fmt_dt($ts, $tz, 'D M j, Y'); }

function dt_input($ts, $tz) { // for <input type=datetime-local>
  if (!$ts) return '';
  $d = new DateTime('@' . (int)$ts);
  $d->setTimezone(tz_obj($tz));
  return $d->format('Y-m-d\TH:i');
}
function input_ts($s, $tz) {
  $s = trim((string)$s);
  if ($s === '') return 0;
  try { $d = new DateTime($s, tz_obj($tz)); return $d->getTimestamp(); } catch (Exception $e) { return 0; }
}

function rel_time($ts) {
  $d = $ts - now();
  $a = abs($d);
  if ($a < 60) $s = 'just now';
  elseif ($a < 3600) $s = floor($a / 60) . ' min';
  elseif ($a < 86400) $s = floor($a / 3600) . ' hr';
  else $s = floor($a / 86400) . ' day' . (floor($a / 86400) == 1 ? '' : 's');
  if ($s === 'just now') return $s;
  return $d > 0 ? "in $s" : "$s ago";
}

// ---------- output helpers ----------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function u($route, $params = []) {
  $q = ['r' => $route] + array_filter($params, function ($v) { return $v !== null && $v !== ''; });
  return '?' . http_build_query($q);
}

function redirect($url) { header('Location: ' . $url); exit; }

function flash($msg, $type = 'ok') { $_SESSION['flash'][] = [$type, $msg]; }
function take_flash() { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function post($k, $d = '') { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $d; }
function get($k, $d = '') { return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : $d; }
function is_post() { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

// Student-authored text: escape, keep line breaks, autolink URLs.
function user_text($s) {
  $s = h($s);
  $s = preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]])~i', '<a href="$1" target="_blank" rel="noopener">$1</a>', $s);
  return nl2br($s);
}

function plain_excerpt($html, $n = 160) {
  $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
  return mb_strlen($t) > $n ? mb_substr($t, 0, $n) . '…' : $t;
}

function fmt_size($b) {
  if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
  if ($b >= 1024) return round($b / 1024) . ' KB';
  return $b . ' B';
}

function fmt_num($n) {
  if ($n === null || $n === '') return '';
  $n = round((float)$n, 2);
  return (floor($n) == $n) ? (string)(int)$n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

function ini_bytes($v) {
  $v = trim((string)$v); if ($v === '') return 0;
  $n = (float)$v; $u = strtolower(substr($v, -1));
  if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
  return (int)$n;
}
function upload_limit() {
  $a = ini_bytes(ini_get('upload_max_filesize')); $b = ini_bytes(ini_get('post_max_size'));
  if (!$a) return $b; if (!$b) return $a; return min($a, $b);
}

// ---------- sessions, auth, CSRF ----------
function start_session() {
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
  }
  session_name('helmsman_sid');
  session_start();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function csrf_field() { return '<input type="hidden" name="_csrf" value="' . h($_SESSION['csrf']) . '">'; }
function csrf_ok() { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '')); }

function users() { return jread('users', []); }
// Setup runs once. The site file is written at setup, so a damaged users file can't reopen it.
function setup_done() { return is_file(dpath('site') . '.php') || users(); }
function user($id) { $u = users(); return $u[$id] ?? null; }
function user_by_email($email) {
  $email = strtolower(trim($email));
  foreach (users() as $u) if (strtolower($u['email']) === $email) return $u;
  return null;
}
// For new accounts only. To change an existing account use patch_user, which
// updates just the given fields so a stale copy can't undo someone else's change.
function save_user($u) { jupdate('users', function ($all) use ($u) { $all[$u['id']] = $u; return $all; }); }
function patch_user($id, $fields) {
  jupdate('users', function ($all) use ($id, $fields) {
    if (!isset($all[$id])) return null;
    foreach ($fields as $k => $v) { if ($v === null) unset($all[$id][$k]); else $all[$id][$k] = $v; }
    return $all;
  });
}
function clean_name($s) { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, 100); }
function valid_email($e) { return strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL); }

// Starts a fresh authenticated session (new session id and CSRF token).
function login_session($uid) {
  session_regenerate_id(true);
  $_SESSION['uid'] = $uid;
  $_SESSION['auth_at'] = now();
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
  unset($_SESSION['preview']);
}

function me() {
  static $cache = false;
  if ($cache !== false) return $cache;
  $id = $_SESSION['uid'] ?? null;
  $u = $id ? user($id) : null;
  // Disabled accounts, and sessions older than a password reset, are signed out.
  if ($u && (($u['status'] ?? '') === 'disabled' || (int)($u['sess_after'] ?? 0) > (int)($_SESSION['auth_at'] ?? 0))) { $u = null; unset($_SESSION['uid']); }
  return $cache = $u;
}
function is_admin() { $u = me(); return $u && $u['role'] === 'admin'; }
// Admin previewing a course as a student sees exactly what students see.
function previewing($cid = null) {
  $p = $_SESSION['preview'] ?? null;
  return is_admin() && $p && ($cid === null || $p === $cid);
}
function staff($cid = null) { return is_admin() && !previewing($cid); }

function require_login() {
  if (!me()) { $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? ''; redirect(u('login')); }
}
function require_admin() {
  require_login();
  if (!is_admin()) { http_response_code(403); throw new HttpError('Instructor access only.'); }
}

class HttpError extends Exception {}

// Counts failures (or sign-ups) per key inside a time window.
function throttle_ok($key, $max, $window = 900) {
  $t = jread('throttle', []);
  $e = $t[$key] ?? null;
  return !$e || $e['t'] < now() - $window || $e['n'] < $max;
}
function throttle_add($key, $window = 900) {
  jupdate('throttle', function ($t) use ($key, $window) {
    $now = now();
    foreach ($t as $k => $v) if ($v['t'] < $now - 86400) unset($t[$k]);
    $e = $t[$key] ?? null;
    if (!$e || $e['t'] < $now - $window) $e = ['n' => 0, 't' => $now];
    $e['n']++; $t[$key] = $e;
    return $t;
  });
}
function throttle_clear($key) { jupdate('throttle', function ($t) use ($key) { unset($t[$key]); return $t; }); }
function client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0'; }

function gen_password() {
  $w = 'abcdefghjkmnpqrstuvwxyz23456789';
  $s = '';
  for ($i = 0; $i < 10; $i++) $s .= $w[random_int(0, strlen($w) - 1)];
  return substr($s, 0, 5) . '-' . substr($s, 5);
}

// ---------- notifications (on-site) ----------
function notify($uid, $text, $link = '') {
  if (!$uid) return;
  jupdate("notes/$uid", function ($n) use ($text, $link) {
    array_unshift($n, ['id' => newid('n'), 'at' => now(), 'text' => $text, 'link' => $link, 'read' => false]);
    return array_slice($n, 0, 200);
  });
}
function notify_admins($text, $link = '') {
  foreach (users() as $u) if ($u['role'] === 'admin' && ($u['status'] ?? '') === 'active') notify($u['id'], $text, $link);
}
function unread_count($uid) {
  $c = 0; foreach (jread("notes/$uid", []) as $n) if (empty($n['read'])) $c++; return $c;
}

// ---------- file uploads ----------
const BLOCKED_EXT = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'shtml'];

function upload_ok_name($name) {
  $name = basename(str_replace('\\', '/', (string)$name));
  $parts = explode('.', strtolower($name));
  array_shift($parts);
  foreach ($parts as $p) if (in_array($p, BLOCKED_EXT, true)) return false; // also catches x.php.jpg
  return $name !== '' && $name[0] !== '.';
}

// Normalizes $_FILES[$field] (single or multiple) to a list.
function uploaded_files($field) {
  if (empty($_FILES[$field])) return [];
  $f = $_FILES[$field];
  $out = [];
  if (is_array($f['name'])) {
    foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'tmp' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
  } else {
    $out[] = ['name' => $f['name'], 'tmp' => $f['tmp_name'], 'error' => $f['error'], 'size' => $f['size']];
  }
  return array_values(array_filter($out, function ($x) { return $x['error'] !== UPLOAD_ERR_NO_FILE; }));
}

function upload_error_text($code) {
  $m = [UPLOAD_ERR_INI_SIZE => 'The file is larger than this server allows (' . fmt_size(upload_limit()) . ').',
    UPLOAD_ERR_FORM_SIZE => 'The file is too large.', UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.', UPLOAD_ERR_CANT_WRITE => 'Server could not save the file.'];
  return $m[$code] ?? 'Upload failed.';
}

// Store a private upload under data/<dir>/<random>.bin, return metadata.
function store_upload($f, $dir) {
  if ($f['error'] !== UPLOAD_ERR_OK) throw new HttpError(upload_error_text($f['error']));
  if (!upload_ok_name($f['name'])) throw new HttpError('That file type is not allowed: ' . h($f['name']));
  $full = dpath($dir);
  ensure_dir($full);
  $stored = bin2hex(random_bytes(16)) . '.bin';
  if (!move_uploaded_file($f['tmp'], "$full/$stored")) throw new HttpError('Could not save the uploaded file.');
  return ['name' => basename(str_replace('\\', '/', $f['name'])), 'stored' => "$dir/$stored", 'size' => (int)$f['size'], 'at' => now()];
}

function mime_for($name) {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $m = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'vtt' => 'text/vtt',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'owl' => 'application/rdf+xml', 'ttl' => 'text/turtle', 'json' => 'application/json',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip' => 'application/zip'];
  return $m[$ext] ?? 'application/octet-stream';
}

// Streams a file with Range support (so video seeking works).
function send_file($path, $name, $inline = true) {
  if (!is_file($path)) { http_response_code(404); echo 'File not found'; exit; }
  $mime = mime_for($name);
  $safeInline = $inline && (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/') || $mime === 'application/pdf' || $mime === 'text/vtt');
  $size = filesize($path);
  $start = 0; $end = $size - 1;
  while (ob_get_level()) ob_end_clean();
  header('X-Content-Type-Options: nosniff');
  header('Content-Type: ' . $mime);
  header('Accept-Ranges: bytes');
  header('Cache-Control: private, max-age=60');
  header('Content-Disposition: ' . ($safeInline ? 'inline' : 'attachment') . "; filename=\"" . str_replace(['"', "\r", "\n"], '', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
  if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
    elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
  }
  $len = $end - $start + 1;
  header('Content-Length: ' . $len);
  $fh = fopen($path, 'rb');
  fseek($fh, $start);
  while ($len > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $len)); echo $chunk; $len -= strlen($chunk); flush(); }
  fclose($fh);
  exit;
}

// ---------- video embeds ----------
function video_embed($url, $title = 'Video', $strict = false) {
  $url = trim((string)$url);
  if ($url === '') return '';
  $t = h($title);
  if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
    $start = '';
    if (preg_match('~[?&#]t=(\d+)~', $url, $tm)) $start = '?start=' . (int)$tm[1];
    return '<div class="vid"><iframe src="https://www.youtube-nocookie.com/embed/' . h($m[1]) . $start . '" title="' . $t . '" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></div>';
  }
  if (preg_match('~vimeo\.com/(?:video/)?(\d+)(?:/([0-9a-f]+))?~', $url, $m)) {
    $hp = isset($m[2]) ? '?h=' . h($m[2]) : '';
    return '<div class="vid"><iframe src="https://player.vimeo.com/video/' . h($m[1]) . $hp . '" title="' . $t . '" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
  }
  if (preg_match('~drive\.google\.com/file/d/([A-Za-z0-9_-]+)~', $url, $m)) {
    return '<div class="vid"><iframe src="https://drive.google.com/file/d/' . h($m[1]) . '/preview" title="' . $t . '" allow="autoplay; fullscreen" allowfullscreen></iframe></div>';
  }
  if ($strict) return ''; // student-supplied links: embed only the known video hosts above
  if (preg_match('~\.(mp4|m4v|webm|mov|ogv)(\?|$)~i', $url) || str_contains($url, 'r=cfile')) {
    return '<div class="vid vid-native"><video controls preload="metadata" src="' . h($url) . '"></video></div>';
  }
  if (preg_match('~\.(mp3|m4a|wav|ogg)(\?|$)~i', $url)) {
    return '<audio controls preload="metadata" src="' . h($url) . '" style="width:100%"></audio>';
  }
  if (preg_match('~^https://~i', $url) && preg_match('~(embed|player|panopto|kaltura|loom\.com/share|zoom\.us/rec)~i', $url)) {
    $src = preg_replace('~loom\.com/share/~', 'loom.com/embed/', $url);
    return '<div class="vid"><iframe src="' . h($src) . '" title="' . $t . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
  }
  return '<p><a class="btn" href="' . h($url) . '" target="_blank" rel="noopener">Open video: ' . $t . ' ↗</a></p>';
}

// ---------- icons (inline SVG, no external requests) ----------
function icon($name) {
  $p = [
    'page' => '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/>',
    'assignment' => '<path d="M9 2h6v3H9z"/><path d="M5 4h14v18H5z"/><path d="m9 13 2 2 4-4"/>',
    'discussion' => '<path d="M4 4h16v11H9l-5 4z"/>',
    'quiz' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.7"/><path d="M12 17h.01"/>',
    'video' => '<rect x="2" y="5" width="15" height="14" rx="2"/><path d="m17 10 5-3v10l-5-3z"/>',
    'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    'file' => '<path d="M21 12.5 12.5 21a5 5 0 0 1-7-7L14 5.5a3.5 3.5 0 0 1 5 5L10.5 19a2 2 0 0 1-3-3L15 8.5"/>',
    'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/>',
    'cal' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
    'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'eyeoff' => '<path d="M3 3l18 18"/><path d="M10.6 5.1A9.8 9.8 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.6 6.6C3.8 8.4 2 12 2 12s4 7 10 7c1.7 0 3.2-.5 4.5-1.2"/>',
    'up' => '<path d="m6 15 6-6 6 6"/>', 'down' => '<path d="m6 9 6 6 6-6"/>',
    'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/>', 'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>', 'check' => '<path d="m5 12 5 5L20 7"/>',
    'header' => '<path d="M4 6h16M4 12h10M4 18h6"/>', 'megaphone' => '<path d="M3 11v3l13 5V6L3 11z"/><path d="M7 15v4h3"/>',
    'grade' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>', 'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7"/><path d="M16 4a4 4 0 0 1 0 8M22 21c0-3-2-5.5-4-6.5"/>',
    'home' => '<path d="M3 11 12 3l9 8v10H3z"/>', 'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 0 1-4 0v-.1A1.7 1.7 0 0 0 7 19.6a1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 1.2 14H1a2 2 0 0 1 0-4h.1A1.7 1.7 0 0 0 2.4 7a1.7 1.7 0 0 0-.3-1.8L2 5.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H7a1.7 1.7 0 0 0 1-1.5V1a2 2 0 0 1 4 0v.1A1.7 1.7 0 0 0 13 2.6a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V7a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  ];
  return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? $p['page']) . '</svg>';
}

// ---------- layout ----------
function layout($title, $body, $opts = []) {
  $me = me();
  $site = site();
  $cid = $opts['course'] ?? null;
  $course = $cid ? course($cid) : null;
  $active = $opts['active'] ?? '';
  $flash = take_flash();
  $unread = $me ? unread_count($me['id']) : 0;
  $ver = @filemtime(__DIR__ . '/../assets/app.css');
  ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($site['name']) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= (int)$ver ?>">
<meta name="csrf" content="<?= h($_SESSION['csrf'] ?? '') ?>">
</head><body class="<?= $cid ? 'has-course' : '' ?><?= previewing() ? ' is-preview' : '' ?>">
<?php if (previewing()): ?>
<div class="preview-bar"><?= icon('eye') ?> Student view: you are seeing this course exactly as an approved student does. Submitting is disabled.
<form method="post" action="<?= h(u('preview', ['c' => $_SESSION['preview'], 'off' => 1])) ?>"><?= csrf_field() ?><button class="btn sm light">Leave student view</button></form></div>
<?php endif; ?>
<header class="top">
  <a class="brand" href="<?= h(u('home')) ?>"><span class="mark"></span><?= h($site['name']) ?></a>
  <?php if ($course): ?><span class="crumb-course"><a href="<?= h(u('course', ['c' => $cid])) ?>"><?= h($course['code'] ?: $course['title']) ?></a></span><?php endif; ?>
  <nav class="topnav">
  <?php if ($me): ?>
    <a href="<?= h(u('calendar', $cid ? ['c' => $cid] : [])) ?>" title="Calendar"><?= icon('cal') ?><span>Calendar</span></a>
    <a href="<?= h(u('notifications')) ?>" class="bell" title="Notifications"><?= icon('bell') ?><span>Alerts</span><?php if ($unread): ?><b class="badge"><?= $unread > 99 ? '99+' : $unread ?></b><?php endif; ?></a>
    <?php if (is_admin() && !previewing()): ?><a href="<?= h(u('site')) ?>" title="Site settings"><?= icon('gear') ?><span>Site</span></a><?php endif; ?>
    <details class="menu"><summary><?= h(initials($me['name'])) ?></summary><div>
      <div class="menu-name"><?= h($me['name']) ?><small><?= h($me['email']) ?></small></div>
      <a href="<?= h(u('account')) ?>">Account</a>
      <form method="post" action="<?= h(u('logout')) ?>"><?= csrf_field() ?><button>Log out</button></form>
    </div></details>
  <?php else: ?>
    <a href="<?= h(u('login')) ?>">Log in</a>
  <?php endif; ?>
  </nav>
</header>
<div class="shell">
<?php if ($course && $me): ?>
  <aside class="side"><?= course_nav($course, $active) ?></aside>
<?php endif; ?>
<main class="main">
<?php foreach ($flash as $f): ?><div class="flash <?= h($f[0]) ?>"><?= $f[1] ?></div><?php endforeach; ?>
<?= $body ?>
</main></div>
<footer class="foot">Runs on <?= h(APP_NAME) ?> <?= h(APP_VERSION) ?></footer>
<script src="assets/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
</body></html><?php
}

function initials($name) {
  $p = preg_split('/\s+/', trim($name));
  $s = mb_substr($p[0] ?? '?', 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : '');
  return mb_strtoupper($s);
}

function course_nav($course, $active) {
  $c = $course['id'];
  $staff = staff($c);
  $links = [
    ['course', 'Home', 'home'], ['announcements', 'Announcements', 'megaphone'], ['syllabus', 'Syllabus', 'page'],
    ['modules', 'Modules', 'header'], ['assignments', 'Assignments', 'assignment'], ['discussions', 'Discussions', 'discussion'],
    ['grades', $staff ? 'Gradebook' : 'Grades', 'grade'], ['calendar', 'Calendar', 'cal'],
  ];
  if ($staff) {
    $pending = count(array_filter(roster($c), function ($r) { return $r['status'] === 'pending'; }));
    $links[] = ['people', 'People' . ($pending ? " <b class=\"badge\">$pending</b>" : ''), 'users'];
    $links[] = ['schedule', 'Release schedule', 'clock'];
    $links[] = ['course_settings', 'Settings', 'gear'];
  }
  $out = '<div class="side-title">' . h($course['title']) . '</div><nav>';
  foreach ($links as $l) {
    $out .= '<a class="' . ($active === $l[0] ? 'on' : '') . '" href="' . h(u($l[0], ['c' => $c])) . '">' . icon($l[2]) . '<span>' . $l[1] . '</span></a>';
  }
  if ($staff) {
    $out .= '<form method="post" action="' . h(u('preview', ['c' => $c])) . '">' . csrf_field() . '<button class="side-preview">' . icon('eye') . '<span>Student view</span></button></form>';
  }
  return $out . '</nav>';
}

function empty_state($text, $action = '') {
  return '<div class="empty"><p>' . $text . '</p>' . $action . '</div>';
}

function confirm_btn($url, $label, $msg, $cls = 'btn sm danger', $fields = []) {
  $f = '';
  foreach ($fields as $k => $v) $f .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
  return '<form method="post" action="' . h($url) . '" class="inline" data-confirm="' . h($msg) . '">' . csrf_field() . $f . '<button class="' . h($cls) . '">' . $label . '</button></form>';
}
function post_btn($url, $label, $cls = 'btn sm', $fields = [], $title = '') {
  $f = '';
  foreach ($fields as $k => $v) $f .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
  return '<form method="post" action="' . h($url) . '" class="inline">' . csrf_field() . $f . '<button class="' . h($cls) . '"' . ($title ? ' title="' . h($title) . '" aria-label="' . h($title) . '"' : '') . '>' . $label . '</button></form>';
}
