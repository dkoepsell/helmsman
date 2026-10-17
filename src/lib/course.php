<?php
// Course model: settings, roster, content, release/visibility rules, grading math.
if (!defined('APP')) { http_response_code(404); exit; }

const TYPES = [
  'page' => 'Page', 'video' => 'Video lecture', 'assignment' => 'Assignment', 'quiz' => 'Quiz',
  'discussion' => 'Discussion', 'link' => 'External link', 'file' => 'File', 'header' => 'Section header',
];
const TYPE_STORE = ['page' => 'pages', 'video' => 'videos', 'assignment' => 'assignments', 'quiz' => 'quizzes',
  'discussion' => 'discussions', 'link' => 'links', 'file' => 'files'];

function course_defaults() {
  return [
    'title' => 'Untitled course', 'code' => '', 'tz' => site()['tz'], 'join_code' => '', 'signup_open' => true,
    'auto_approve' => false, 'weighted' => true, 'groups' => [['id' => 'g_default', 'title' => 'Assignments', 'weight' => 100]],
    'scale' => [[90, 'A'], [80, 'B'], [70, 'C'], [60, 'D'], [0, 'F']], 'late_pct' => 0, 'late_max' => 0,
    'missing_zero' => true, 'hide_total' => false, 'created' => now(), 'archived' => false, 'banner' => '',
  ];
}

function course($cid) {
  $cid = safe_id($cid);
  if (!$cid) return null;
  if (!array_key_exists($cid, $GLOBALS['_course_cache'] ?? [])) {
    $c = jread("c/$cid/course", null);
    $GLOBALS['_course_cache'][$cid] = $c ? ($c + course_defaults()) : null;
  }
  return $GLOBALS['_course_cache'][$cid];
}
function save_course($c) {
  $c = jupdate("c/{$c['id']}/course", function ($disk) use ($c) {
    // Never drop the files folder key, even if $c was loaded before it existed.
    if (empty($c['files_key']) && !empty($disk['files_key'])) $c['files_key'] = $disk['files_key'];
    return $c;
  });
  $GLOBALS['_course_cache'][$c['id']] = $c + course_defaults();
}

function courses_all() {
  $out = [];
  $dir = dpath('c');
  if (!is_dir($dir)) return [];
  foreach (scandir($dir) as $d) {
    if ($d[0] === '.') continue;
    if ($c = course($d)) $out[] = $c;
  }
  usort($out, function ($a, $b) { return $b['created'] <=> $a['created']; });
  return $out;
}

function new_join_code() {
  $w = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
  for ($i = 0; $i < 6; $i++) $s .= $w[random_int(0, strlen($w) - 1)];
  return $s;
}

function create_course($title, $code = '') {
  $cid = newid('c');
  $c = ['id' => $cid] + course_defaults();
  $c['title'] = $title; $c['code'] = $code; $c['join_code'] = new_join_code(); $c['files_key'] = bin2hex(random_bytes(12));
  jwrite("c/$cid/course", $c);
  jwrite("c/$cid/content", content_defaults());
  jwrite("c/$cid/roster", []);
  return $cid;
}

function content_defaults() {
  return ['modules' => [], 'pages' => [], 'videos' => [], 'assignments' => [], 'quizzes' => [], 'discussions' => [],
    'links' => [], 'files' => [], 'announcements' => [], 'syllabus' => ''];
}

function content($cid) {
  if (!isset($GLOBALS['_content_cache'][$cid])) $GLOBALS['_content_cache'][$cid] = jread("c/$cid/content", []) + content_defaults();
  return $GLOBALS['_content_cache'][$cid];
}
// Every content change goes through here so concurrent admin tabs can't clobber each other.
function update_content($cid, callable $fn) {
  $new = jupdate("c/$cid/content", function ($c) use ($fn) { return $fn($c + content_defaults()); });
  $GLOBALS['_content_cache'][$cid] = $new + content_defaults();
  return $GLOBALS['_content_cache'][$cid];
}

function obj($cid, $type, $id) {
  $store = TYPE_STORE[$type] ?? null;
  if (!$store) return null;
  $c = content($cid);
  return $c[$store][$id] ?? null;
}

// ---------- roster ----------
function roster($cid) { return jread("c/$cid/roster", []); }
function enrollment($cid, $uid) { $r = roster($cid); return $r[$uid] ?? null; }
function set_enrollment($cid, $uid, $status) {
  jupdate("c/$cid/roster", function ($r) use ($uid, $status) {
    if ($status === null) { unset($r[$uid]); return $r; }
    $r[$uid] = ['status' => $status, 'joined' => $r[$uid]['joined'] ?? now()];
    return $r;
  });
}
function active_students($cid) {
  $out = [];
  $users = users();
  foreach (roster($cid) as $uid => $e) {
    if ($e['status'] === 'active' && isset($users[$uid]) && $users[$uid]['status'] === 'active') $out[$uid] = $users[$uid];
  }
  uasort($out, function ($a, $b) { return strcasecmp(sort_name($a['name']), sort_name($b['name'])); });
  return $out;
}
function sort_name($n) { $p = preg_split('/\s+/', trim($n)); return count($p) > 1 ? end($p) . ' ' . $p[0] : $n; }

function my_courses() {
  $me = me(); if (!$me) return [];
  $out = [];
  foreach (courses_all() as $c) {
    if ($me['role'] === 'admin') { $out[] = $c + ['_status' => 'staff']; continue; }
    $e = enrollment($c['id'], $me['id']);
    if ($e && $e['status'] !== 'dropped') $out[] = $c + ['_status' => $e['status']];
  }
  return $out;
}

// Gate for any course page. Returns course. Students must be active members.
function require_course($cid) {
  require_login();
  $c = course($cid);
  if (!$c) { http_response_code(404); throw new HttpError('Course not found.'); }
  if (is_admin()) return $c;
  $e = enrollment($cid, me()['id']);
  if (!$e || $e['status'] !== 'active') {
    http_response_code(403);
    throw new HttpError($e && $e['status'] === 'pending'
      ? 'Your request to join this course is waiting for the instructor’s approval. You’ll get a notification when it’s approved.'
      : 'You are not enrolled in this course.');
  }
  if (!empty($c['archived'])) throw new HttpError('This course is closed.');
  return $c;
}

// ---------- release & visibility ----------
function item_release($mod, $item) { return max((int)($mod['unlock_at'] ?? 0), (int)($item['unlock_at'] ?? 0)); }

function item_open($mod, $item, $t = null) {
  $t = $t ?? now();
  return !empty($mod['published']) && !empty($item['published']) && item_release($mod, $item) <= $t;
}
function module_open($mod, $t = null) { return !empty($mod['published']) && (int)($mod['unlock_at'] ?? 0) <= ($t ?? now()); }

// Where does an object appear in modules? Returns list of [mod, item].
function placements($content, $type, $id) {
  $out = [];
  foreach ($content['modules'] as $m) foreach ($m['items'] as $it) if ($it['type'] === $type && ($it['ref'] ?? '') === $id) $out[] = [$m, $it];
  return $out;
}

// Can the current viewer open this object? ['ok'=>bool, 'release'=>ts, 'why'=>string]
function access($cid, $type, $id) {
  $content = content($cid);
  $o = obj($cid, $type, $id);
  if (!$o) return ['ok' => false, 'release' => 0, 'why' => 'Not found.'];
  if (staff($cid)) return ['ok' => true, 'release' => 0, 'why' => ''];
  $pl = placements($content, $type, $id);
  if (!$pl) {
    $ok = ($o['published'] ?? true) && (int)($o['unlock_at'] ?? 0) <= now();
    return ['ok' => $ok, 'release' => (int)($o['unlock_at'] ?? 0), 'why' => $ok ? '' : 'This item is not available yet.'];
  }
  $earliest = PHP_INT_MAX; $anyPublished = false;
  foreach ($pl as [$m, $it]) {
    if (item_open($m, $it)) return ['ok' => true, 'release' => item_release($m, $it), 'why' => ''];
    if (!empty($m['published']) && !empty($it['published'])) { $anyPublished = true; $earliest = min($earliest, item_release($m, $it)); }
  }
  if ($anyPublished) return ['ok' => false, 'release' => $earliest, 'why' => 'Available ' . fmt_dt($earliest, course($cid)['tz']) . '.'];
  return ['ok' => false, 'release' => 0, 'why' => 'This item is not available.'];
}

function require_access($cid, $type, $id) {
  $a = access($cid, $type, $id);
  if (!$a['ok']) { http_response_code(403); throw new HttpError(h($a['why'])); }
  return obj($cid, $type, $id);
}

function visible_announcements($cid) {
  $list = array_values(content($cid)['announcements']);
  $staff = staff($cid);
  $list = array_filter($list, function ($a) use ($staff) { return $staff || (int)($a['post_at'] ?? 0) <= now(); });
  usort($list, function ($a, $b) { return max($b['post_at'] ?? 0, $b['created'] ?? 0) <=> max($a['post_at'] ?? 0, $a['created'] ?? 0); });
  return $list;
}

// ---------- gradables ----------
// Assignments, quizzes, and graded discussions, in module order.
function gradables($cid) {
  $c = content($cid);
  $seen = []; $out = [];
  $add = function ($type, $id) use (&$seen, &$out, $c) {
    $k = "$type:$id";
    if (isset($seen[$k])) return;
    $store = TYPE_STORE[$type];
    $o = $c[$store][$id] ?? null;
    if (!$o) return;
    if ($type === 'discussion' && (float)($o['points'] ?? 0) <= 0) return;
    $seen[$k] = 1;
    $out[$id] = ['id' => $id, 'type' => $type, 'title' => $o['title'], 'points' => (float)($o['points'] ?? 0),
      'group' => $o['group'] ?? '', 'due_at' => (int)($o['due_at'] ?? 0), 'lock_at' => (int)($o['lock_at'] ?? 0),
      'submits' => $type === 'assignment' ? !in_array('none', $o['sub_types'] ?? ['upload'], true) && !empty($o['sub_types']) : true,
      'post_policy' => $o['post_policy'] ?? 'auto', 'omit' => !empty($o['omit']) || !empty($o['practice']), 'practice' => !empty($o['practice'])];
  };
  foreach ($c['modules'] as $m) foreach ($m['items'] as $it) if (in_array($it['type'], ['assignment', 'quiz', 'discussion'], true)) $add($it['type'], $it['ref']);
  foreach (['assignment' => 'assignments', 'quiz' => 'quizzes', 'discussion' => 'discussions'] as $t => $s) foreach ($c[$s] as $id => $o) $add($t, $id);
  return $out;
}

// Everything that counts or is due, leaving out practice quizzes.
function graded_items($cid) { return array_filter(gradables($cid), function ($g) { return empty($g['practice']); }); }

// ---------- submissions & grades ----------
function sub_path($cid, $oid, $uid) { return "c/$cid/subs/" . safe_id($oid) . '/' . safe_id($uid); }
function get_sub($cid, $oid, $uid) {
  return jread(sub_path($cid, $oid, $uid), []) + ['attempts' => [], 'grade' => null, 'comments' => []];
}
function update_sub($cid, $oid, $uid, callable $fn) {
  return jupdate(sub_path($cid, $oid, $uid), function ($s) use ($fn) {
    return $fn($s + ['attempts' => [], 'grade' => null, 'comments' => []]);
  });
}
function subs_for($cid, $oid) {
  $out = [];
  $dir = dpath("c/$cid/subs/" . safe_id($oid));
  if (!is_dir($dir)) return [];
  foreach (scandir($dir) as $f) if (str_ends_with($f, '.php')) {
    $uid = substr($f, 0, -4);
    $out[$uid] = get_sub($cid, $oid, $uid);
  }
  return $out;
}

function last_attempt_at($sub) { $a = end($sub['attempts']); return $a ? (int)$a['at'] : 0; }

function late_days($g, $sub) {
  if (!$g['due_at']) return 0;
  $at = last_attempt_at($sub);
  if (!$at || $at <= $g['due_at']) return 0;
  return (int)ceil(($at - $g['due_at']) / 86400);
}

function grade_posted($sub) { return !empty($sub['grade']) && !empty($sub['grade']['posted']); }

// One student's row for one gradable.
function grade_row($course, $g, $sub, $asStaff) {
  $hasSub = !empty($sub['attempts']);
  $gr = $sub['grade'] ?? null;
  $visible = $gr && ($asStaff || !empty($gr['posted']));
  $row = ['g' => $g, 'raw' => null, 'score' => null, 'status' => '', 'late' => 0, 'excused' => false, 'counts' => false, 'pending_post' => false];
  $row['late'] = $hasSub ? late_days($g, $sub) : 0;
  if ($gr && !$asStaff && empty($gr['posted'])) $row['pending_post'] = true;
  if ($visible && !empty($gr['excused'])) { $row['excused'] = true; $row['status'] = 'Excused'; return $row; }
  if ($visible && isset($gr['score']) && $gr['score'] !== null && $gr['score'] !== '') {
    $raw = (float)$gr['score'];
    $pen = 0;
    if ($row['late'] && $course['late_pct'] > 0 && empty($gr['waive_late'])) {
      $pen = $course['late_pct'] * $row['late'];
      if ($course['late_max'] > 0) $pen = min($pen, $course['late_max']);
      $pen = min($pen, 100);
    }
    $row['raw'] = $raw; $row['penalty'] = $pen;
    $row['score'] = round($raw * (1 - $pen / 100), 2);
    $row['status'] = 'Graded'; $row['counts'] = !$g['omit'];
    return $row;
  }
  if ($hasSub) { $row['status'] = $row['late'] ? 'Submitted late' : 'Submitted'; return $row; }
  if ($g['submits'] && $g['due_at'] && now() > $g['due_at']) {
    $row['status'] = 'Missing';
    if ($course['missing_zero'] && !$g['omit']) { $row['score'] = 0; $row['counts'] = true; }
    return $row;
  }
  $row['status'] = $g['due_at'] && now() <= $g['due_at'] ? 'Upcoming' : '';
  return $row;
}

function letter_for($course, $pct) {
  if ($pct === null) return '';
  foreach ($course['scale'] as $s) if ($pct >= $s[0] - 1e-9) return $s[1];
  return '';
}

// Course total for one student. $rows keyed by gradable id.
function compute_total($course, $rows) {
  $groups = [];
  foreach ($course['groups'] as $gp) $groups[$gp['id']] = ['title' => $gp['title'], 'weight' => (float)$gp['weight'], 'earned' => 0, 'possible' => 0];
  $firstGroup = array_key_first($groups);
  foreach ($rows as $r) {
    if (!$r['counts'] || $r['g']['points'] <= 0) continue;
    $gid = isset($groups[$r['g']['group']]) ? $r['g']['group'] : $firstGroup;
    if ($gid === null) continue;
    $groups[$gid]['earned'] += $r['score'];
    $groups[$gid]['possible'] += $r['g']['points'];
  }
  foreach ($groups as &$gp) $gp['pct'] = $gp['possible'] > 0 ? 100 * $gp['earned'] / $gp['possible'] : null;
  unset($gp);
  $pct = null;
  if ($course['weighted']) {
    $wsum = 0; $acc = 0;
    foreach ($groups as $gp) if ($gp['pct'] !== null && $gp['weight'] > 0) { $wsum += $gp['weight']; $acc += $gp['weight'] * $gp['pct']; }
    if ($wsum > 0) $pct = $acc / $wsum;
  } else {
    $e = 0; $p = 0;
    foreach ($groups as $gp) { $e += $gp['earned']; $p += $gp['possible']; }
    if ($p > 0) $pct = 100 * $e / $p;
  }
  return ['groups' => $groups, 'pct' => $pct, 'letter' => letter_for($course, $pct)];
}

function student_rows($cid, $uid, $asStaff) {
  $course = course($cid);
  $rows = [];
  foreach (gradables($cid) as $g) {
    if ($g['practice']) continue;
    if (!$asStaff) { $a = access($cid, $g['type'], $g['id']); if (!$a['ok'] && !$g['due_at']) continue; }
    $rows[$g['id']] = grade_row($course, $g, get_sub($cid, $g['id'], $uid), $asStaff);
  }
  return $rows;
}

// Saves a grade and (depending on post policy) notifies the student.
function set_grade($cid, $oid, $uid, $fields, $actor = null) {
  $g = gradables($cid)[$oid] ?? null;
  $auto = !$g || ($g['post_policy'] ?? 'auto') === 'auto';
  $wasPosted = false; $nowPosted = false;
  update_sub($cid, $oid, $uid, function ($s) use ($fields, $auto, &$wasPosted, &$nowPosted, $actor) {
    $gr = $s['grade'] ?: [];
    $wasPosted = !empty($gr['posted']);
    $gr = array_merge($gr, $fields);
    $gr['graded_at'] = now();
    if ($actor) $gr['by'] = $actor;
    if ($auto) $gr['posted'] = true;
    $nowPosted = !empty($gr['posted']);
    $s['grade'] = $gr;
    return $s;
  });
  if ($nowPosted && $g) {
    notify($uid, ($wasPosted ? 'Grade updated: ' : 'Graded: ') . h($g['title']), u(TYPE_ROUTE[$g['type']], ['c' => $cid, 'id' => $oid]));
  }
}

function post_grades($cid, $oid) {
  $g = gradables($cid)[$oid] ?? null;
  $n = 0;
  foreach (subs_for($cid, $oid) as $uid => $s) {
    if (!empty($s['grade']) && empty($s['grade']['posted'])) {
      update_sub($cid, $oid, $uid, function ($x) { $x['grade']['posted'] = true; return $x; });
      if ($g) notify($uid, 'Graded: ' . h($g['title']), u(TYPE_ROUTE[$g['type']], ['c' => $cid, 'id' => $oid]));
      $n++;
    }
  }
  return $n;
}

const TYPE_ROUTE = ['page' => 'page', 'video' => 'video', 'assignment' => 'assignment', 'quiz' => 'quiz', 'discussion' => 'discussion', 'link' => 'link', 'file' => 'file'];

function item_url($cid, $type, $ref) {
  if ($type === 'link') return u('link', ['c' => $cid, 'id' => $ref]);
  if ($type === 'file') return u('file', ['c' => $cid, 'id' => $ref]);
  return u(TYPE_ROUTE[$type] ?? 'page', ['c' => $cid, 'id' => $ref]);
}

function needs_grading_count($cid, $oid) {
  $n = 0;
  foreach (subs_for($cid, $oid) as $s) {
    if (empty($s['attempts'])) continue;
    $last = last_attempt_at($s);
    if (empty($s['grade']) || ($s['grade']['graded_at'] ?? 0) < $last || !empty($s['grade']['needs_manual'])) $n++;
  }
  return $n;
}

// ---------- course files ----------
// Course files live in a folder with a random name, so even on a server that
// ignores .htaccess nobody can fetch them by guessing a path.
function course_files_dir($cid) {
  $c = course($cid);
  if ($c && empty($c['files_key'])) {
    $c = jupdate("c/$cid/course", function ($x) use ($cid) {
      if (!empty($x['files_key'])) return null;
      $x['files_key'] = bin2hex(random_bytes(12));
      $old = dpath("c/$cid/files");
      if (is_dir($old)) @rename($old, dpath("c/$cid/files-" . $x['files_key']));
      return $x;
    });
    $GLOBALS['_course_cache'][$cid] = $c + course_defaults();
  }
  return dpath("c/$cid/files-" . ($c['files_key'] ?? 'x'));
}
function cfile_url($cid, $path) { return u('cfile', ['c' => $cid, 'f' => $path]); }

function safe_rel_path($p) {
  $p = str_replace('\\', '/', (string)$p);
  $parts = [];
  foreach (explode('/', $p) as $seg) {
    $seg = rtrim(trim($seg), '. ');
    if ($seg === '' || $seg === '.' || $seg === '..') continue;
    $seg = preg_replace('/[^\w.\- ()]+/u', '_', $seg);
    if ($seg === '' || $seg[0] === '.') continue;
    $parts[] = $seg;
  }
  return implode('/', $parts);
}

function list_course_files($cid) {
  $base = course_files_dir($cid);
  $out = [];
  if (!is_dir($base)) return $out;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
    $out[] = ['path' => $rel, 'size' => $f->getSize(), 'mtime' => $f->getMTime()];
  }
  usort($out, function ($a, $b) { return strcmp($a['path'], $b['path']); });
  return $out;
}

function save_course_file($cid, $f, $sub = 'uploads') {
  if ($f['error'] !== UPLOAD_ERR_OK) throw new HttpError(upload_error_text($f['error']));
  // Check the name after cleaning it, since cleaning can change the extension ("x.php " -> "x.php").
  $name = safe_rel_path(basename(str_replace('\\', '/', $f['name'])));
  if ($name === '' || !upload_ok_name($name) || !upload_ok_name($f['name'])) throw new HttpError('That file type is not allowed.');
  $dir = course_files_dir($cid) . '/' . $sub;
  ensure_dir($dir);
  $base = pathinfo($name, PATHINFO_FILENAME); $ext = pathinfo($name, PATHINFO_EXTENSION);
  $final = $name; $i = 1;
  while (is_file("$dir/$final")) { $final = $base . '-' . $i++ . ($ext ? ".$ext" : ''); }
  if (!move_uploaded_file($f['tmp'], "$dir/$final")) throw new HttpError('Could not save the file.');
  return "$sub/$final";
}

// Course-file paths a student may open: those linked from content they can see.
function visible_file_paths($cid) {
  if (isset($GLOBALS['_vis_files'][$cid])) return $GLOBALS['_vis_files'][$cid];
  $paths = [];
  $scan = function ($text) use (&$paths, $cid) {
    if (!$text) return;
    if (preg_match_all('~r=cfile(?:&amp;|&)c=' . preg_quote($cid, '~') . '(?:&amp;|&)f=([^"\'&\s<>)]+)~', $text, $m)) {
      foreach ($m[1] as $p) $paths[safe_rel_path(rawurldecode(html_entity_decode($p)))] = 1;
    }
  };
  $c = content($cid); $course = course($cid);
  if (!empty($course['banner'])) $paths[safe_rel_path($course['banner'])] = 1;
  $scan($c['syllabus']);
  foreach (visible_announcements($cid) as $a) $scan($a['body'] ?? '');
  foreach (TYPE_STORE as $type => $store) foreach ($c[$store] as $id => $o) {
    if (!access($cid, $type, $id)['ok']) continue;
    $scan($o['body'] ?? '');
    $scan($o['url'] ?? '');
    if ($type === 'file' && !empty($o['path'])) $paths[safe_rel_path($o['path'])] = 1;
    foreach ($o['questions'] ?? [] as $q) $scan($q['text'] ?? '');
  }
  return $GLOBALS['_vis_files'][$cid] = $paths;
}

// ---------- discussions ----------
function posts($cid, $did) { return jread("c/$cid/disc/" . safe_id($did), ['posts' => []])['posts']; }

// ---------- quiz attempts ----------
function qa_path($cid, $qid, $uid) { return "c/$cid/qa/" . safe_id($qid) . '/' . safe_id($uid); }
function quiz_attempts($cid, $qid, $uid) { return jread(qa_path($cid, $qid, $uid), ['attempts' => []])['attempts']; }

function quiz_points($quiz) {
  $p = 0;
  foreach ($quiz['questions'] ?? [] as $q) $p += (float)($q['points'] ?? 0);
  foreach ($quiz['pools'] ?? [] as $pl) $p += (int)$pl['pick'] * (float)$pl['points'];
  return $p;
}
function quiz_question_count($quiz) {
  $n = count(array_filter($quiz['questions'] ?? [], function ($x) { return $x['type'] !== 'text'; }));
  foreach ($quiz['pools'] ?? [] as $pl) $n += (int)$pl['pick'];
  return $n;
}

// ---------- question pools (Canvas question groups and banks) ----------
function pool_questions($cid, $pid) { return jread("c/$cid/pools/" . safe_id($pid), ['questions' => []])['questions']; }

// Picks this attempt's questions. Drawn questions are copied into the attempt, so later
// edits to the bank never change a student's past attempt.
function draw_attempt($cid, $quiz) {
  $order = array_column($quiz['questions'] ?? [], 'id');
  $drawn = [];
  foreach ($quiz['pools'] ?? [] as $pl) {
    $qs = pool_questions($cid, $pl['id']);
    shuffle($qs);
    foreach (array_slice($qs, 0, (int)$pl['pick']) as $q) {
      $q['points'] = (float)$pl['points'];
      $q['pool'] = $pl['id'];
      $drawn[] = $q; $order[] = $q['id'];
    }
  }
  return [$order, $drawn];
}

// id => question for one attempt: the quiz's fixed questions plus that attempt's draws.
function attempt_questions($quiz, $att) {
  $m = [];
  foreach ($quiz['questions'] ?? [] as $q) $m[$q['id']] = $q;
  foreach ($att['drawn'] ?? [] as $q) $m[$q['id']] = $q;
  return $m;
}
function attempt_points($quiz, $att) {
  if (empty($att['drawn'])) return quiz_points($quiz);
  $p = 0; foreach (attempt_questions($quiz, $att) as $q) $p += (float)($q['points'] ?? 0);
  return $p;
}

// Auto-grades one answer. Returns [points or null if manual, correct bool|null].
function grade_answer($q, $ans) {
  $pts = (float)($q['points'] ?? 0);
  switch ($q['type']) {
    case 'mc': case 'tf':
      foreach ($q['choices'] as $c) if (!empty($c['correct']) && (string)$c['id'] === (string)$ans) return [$pts, true];
      return [0, false];
    case 'multi':
      $want = []; foreach ($q['choices'] as $c) if (!empty($c['correct'])) $want[] = (string)$c['id'];
      $got = array_map('strval', is_array($ans) ? $ans : []);
      sort($want); sort($got);
      return $want === $got ? [$pts, true] : [0, false];
    case 'short':
      $a = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$ans)));
      foreach ($q['answers'] ?? [] as $ok) if ($a !== '' && $a === mb_strtolower(trim($ok))) return [$pts, true];
      return [0, false];
    case 'numeric':
      if (!is_numeric(trim((string)$ans))) return [0, false];
      $ok = abs((float)$ans - (float)($q['value'] ?? 0)) <= (float)($q['tolerance'] ?? 0) + 1e-9;
      return [$ok ? $pts : 0, $ok];
    case 'essay': default:
      return [null, null];
  }
}
