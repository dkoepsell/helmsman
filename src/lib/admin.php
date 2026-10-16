<?php
// Instructor (admin) views: dashboard, courses, people, settings, files.
if (!defined('APP')) { http_response_code(404); exit; }

function admin_dashboard() {
  $cs = courses_all();
  ob_start(); ?>
  <div class="head-row"><h1>Instructor dashboard</h1>
    <div class="row"><a class="btn primary" href="<?= h(u('import')) ?>">Import Canvas course (.imscc)</a><a class="btn" href="<?= h(u('new_course')) ?>"><?= icon('plus') ?> Blank course</a></div></div>
  <?= exposure_warning() ?>
  <?php if (!$cs) echo empty_state('No courses yet. Import your Canvas export to get started.', '<a class="btn primary" href="' . h(u('import')) . '">Import .imscc</a>'); ?>
  <div class="cards wide">
  <?php foreach ($cs as $c):
    $r = roster($c['id']);
    $act = count(array_filter($r, function ($x) { return $x['status'] === 'active'; }));
    $pen = count(array_filter($r, function ($x) { return $x['status'] === 'pending'; }));
    $tg = 0; foreach (gradables($c['id']) as $g) $tg += needs_grading_count($c['id'], $g['id']); ?>
    <div class="card course-card<?= $c['archived'] ? ' archived' : '' ?>">
      <a href="<?= h(u('course', ['c' => $c['id']])) ?>"><div class="cc-band"<?= course_band_style($c) ?>></div></a>
      <div class="cc-body"><a href="<?= h(u('course', ['c' => $c['id']])) ?>"><b><?= h($c['title']) ?></b></a><span class="muted"><?= h($c['code']) ?><?= $c['archived'] ? ' · closed' : '' ?></span>
        <div class="row small">
          <a href="<?= h(u('people', ['c' => $c['id']])) ?>"><?= $act ?> student<?= $act == 1 ? '' : 's' ?></a>
          <?php if ($pen): ?><a class="pill warn" href="<?= h(u('people', ['c' => $c['id']])) ?>"><?= $pen ?> waiting for approval</a><?php endif; ?>
          <?php if ($tg): ?><a class="pill warn" href="<?= h(u('grades', ['c' => $c['id']])) ?>"><?= $tg ?> to grade</a><?php endif; ?>
        </div>
        <div class="muted small">Join code <code><?= h($c['join_code']) ?></code><?= $c['signup_open'] ? '' : ' (sign-ups closed)' ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php layout('Dashboard', ob_get_clean());
}

function staff_rail($cid) {
  $c = course($cid);
  $r = roster($cid);
  $pen = count(array_filter($r, function ($x) { return $x['status'] === 'pending'; }));
  $act = count(array_filter($r, function ($x) { return $x['status'] === 'active'; }));
  $o = '<h3>Instructor</h3><ul class="rail-list">';
  $o .= '<li><a href="' . h(u('people', ['c' => $cid])) . '">' . icon('users') . " $act student" . ($act == 1 ? '' : 's') . '</a>' . ($pen ? ' <a class="pill warn" href="' . h(u('people', ['c' => $cid])) . '">' . $pen . ' to approve</a>' : '') . '</li>';
  $tg = [];
  foreach (gradables($cid) as $g) if ($n = needs_grading_count($cid, $g['id'])) $tg[] = '<li><a href="' . h(u('grading', ['c' => $cid, 'id' => $g['id']])) . '">' . icon('grade') . ' ' . h($g['title']) . '</a> <span class="pill warn">' . $n . '</span></li>';
  $o .= $tg ? implode('', $tg) : '<li class="muted">' . icon('check') . ' Nothing to grade</li>';
  $ph = placeholder_pages($cid);
  if ($ph) {
    $o .= '<li class="rail-sub">Placeholders to fill</li>';
    foreach (array_slice($ph, 0, 8) as $p) $o .= '<li><a href="' . h(u('edit', ['c' => $cid, 't' => 'page', 'id' => $p['id']])) . '">' . icon('edit') . ' ' . h($p['title']) . '</a> <span class="pill">' . $p['n'] . '</span></li>';
  }
  $novid = 0; foreach (content($cid)['videos'] as $v) if (empty($v['url'])) $novid++;
  if ($novid) $o .= '<li class="muted">' . icon('video') . " $novid video item" . ($novid > 1 ? 's' : '') . ' still need a link</li>';
  $o .= '</ul>';
  $o .= '<div class="card joinbox"><div class="muted small">Students sign up at</div><code class="sel">' . h(base_url() . u('signup', ['code' => $c['join_code']])) . '</code><div class="muted small">Course code <b>' . h($c['join_code']) . '</b>' . ($c['signup_open'] ? '' : ' · sign-ups are closed') . '</div></div>';
  $o .= '<p><a class="btn block" href="' . h(u('files', ['c' => $cid])) . '">' . icon('file') . ' Course files</a></p>';
  return $o;
}

function placeholder_pages($cid) {
  $out = [];
  foreach (content($cid)['pages'] as $p) if (preg_match_all('~\[[^\]<]{0,60}\b(link|time|zoom)[^\]<]{0,30}\]~i', $p['body'], $m)) $out[] = ['id' => $p['id'], 'title' => $p['title'], 'n' => count($m[0])];
  return $out;
}

function base_url() {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

// ---------- site ----------
function r_site() {
  require_admin();
  if (is_post()) {
    $act = post('act');
    if ($act === 'site') {
      $s = site(); $s['name'] = mb_substr(post('name'), 0, 80) ?: 'Courses'; $s['tz'] = post('tz') ?: 'America/Chicago';
      jwrite('site', $s); flash('Site settings saved.');
    } elseif ($act === 'add_admin') {
      $email = strtolower(post('email'));
      if (!valid_email($email) || clean_name(post('name')) === '') flash('Enter a name and valid email.', 'err');
      elseif ($u = user_by_email($email)) { patch_user($u['id'], ['role' => 'admin']); flash(h($u['name']) . ' is now an instructor.'); }
      else {
        $pw = gen_password();
        save_user(['id' => newid('u'), 'name' => clean_name(post('name')), 'email' => $email, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'admin', 'status' => 'active', 'created' => now(), 'must_change' => true]);
        flash('Instructor account created. Temporary password: <code class="sel">' . h($pw) . '</code> (shown once).');
      }
    } elseif ($act === 'user') {
      $u = user(post('uid'));
      if ($u) {
        $patch = [];
        if (post('reset')) {
          if ($u['id'] === me()['id']) flash('Change your own password under Account.', 'err');
          else { $pw = gen_password(); $patch += ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => true, 'sess_after' => now()]; flash('New temporary password for ' . h($u['name']) . ': <code class="sel">' . h($pw) . '</code>'); }
        }
        if (post('toggle')) {
          if ($u['id'] === me()['id']) flash('You can’t disable your own account.', 'err');
          else { $st = $u['status'] === 'disabled' ? 'active' : 'disabled'; $patch += ['status' => $st, 'sess_after' => now()]; flash(h($u['name']) . ($st === 'disabled' ? ' can no longer log in.' : ' can log in again.')); }
        }
        if (post('email')) {
          $e = strtolower(post('email'));
          $o = user_by_email($e);
          if (!valid_email($e) || ($o && $o['id'] !== $u['id'])) flash('That email is invalid or already used.', 'err');
          else { $patch['email'] = $e; if (clean_name(post('name')) !== '') $patch['name'] = clean_name(post('name')); flash('Saved.'); }
        }
        if ($patch) patch_user($u['id'], $patch);
      }
    }
    redirect(u('site'));
  }
  $s = site();
  $all = users();
  uasort($all, function ($a, $b) { return [$a['role'] !== 'admin', sort_name($a['name'])] <=> [$b['role'] !== 'admin', sort_name($b['name'])]; });
  ob_start(); ?>
  <h1>Site settings</h1>
  <div class="grid2">
  <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="site"><h2>General</h2>
    <label>Site name <input name="name" value="<?= h($s['name']) ?>"></label>
    <label>Default time zone <?= tz_select('tz', $s['tz']) ?></label>
    <button class="btn primary">Save</button></form>
  <div class="card"><h2>Server check</h2><?= system_check() ?></div>
  </div>
  <h2>All accounts</h2>
  <input class="filter" data-filter="#users tbody tr" placeholder="Filter by name or email">
  <table class="tbl" id="users"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Courses</th><th>Last login</th><th></th></tr></thead><tbody>
  <?php foreach ($all as $u):
    $cl = []; foreach (courses_all() as $c) { $e = enrollment($c['id'], $u['id']); if ($e && $e['status'] !== 'dropped') $cl[] = h($c['code'] ?: $c['title']) . ($e['status'] === 'pending' ? ' (pending)' : ''); } ?>
    <tr class="<?= $u['status'] === 'disabled' ? 'dim' : '' ?>"><td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td><td><?= $u['role'] === 'admin' ? 'Instructor' : 'Student' ?><?= $u['status'] === 'disabled' ? ' · disabled' : '' ?></td><td class="small"><?= implode(', ', $cl) ?></td><td class="small"><?= !empty($u['last_login']) ? h(rel_time($u['last_login'])) : '—' ?></td>
    <td class="row nowrap">
      <details class="pop"><summary class="btn sm">Edit</summary><form method="post" class="stack card"><?= csrf_field() ?><input type="hidden" name="act" value="user"><input type="hidden" name="uid" value="<?= h($u['id']) ?>"><label>Name <input name="name" value="<?= h($u['name']) ?>"></label><label>Email <input name="email" value="<?= h($u['email']) ?>"></label><button class="btn sm primary">Save</button></form></details>
      <?= post_btn(u('site'), 'Reset password', 'btn sm', ['act' => 'user', 'uid' => $u['id'], 'reset' => 1]) ?>
      <?= $u['id'] !== me()['id'] ? confirm_btn(u('site'), $u['status'] === 'disabled' ? 'Enable' : 'Disable', $u['status'] === 'disabled' ? 'Let this person log in again?' : 'Block this person from logging in? Their work is kept.', 'btn sm' . ($u['status'] === 'disabled' ? '' : ' danger'), ['act' => 'user', 'uid' => $u['id'], 'toggle' => 1]) : '' ?>
    </td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <form method="post" class="card stack narrow"><?= csrf_field() ?><input type="hidden" name="act" value="add_admin"><h3>Add another instructor</h3>
    <label>Name <input name="name" required></label><label>Email <input type="email" name="email" required></label><button class="btn">Add instructor</button></form>
  <?php layout('Site settings', ob_get_clean());
}

function system_check() {
  $rows = [
    [APP_NAME . ' version', APP_VERSION, true],
    ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '7.4', '>=')],
    ['Reading .imscc files', class_exists('ZipArchive') ? 'Zip extension' : 'Built-in reader (no zip extension needed)', true],
    ['Decompression', function_exists('gzinflate') ? 'zlib' : 'Built-in (slower, fine for course files)', true],
    ['XML support (needed to import)', function_exists('simplexml_load_string') ? 'Available' : 'Missing', function_exists('simplexml_load_string')],
    ['Multibyte text', extension_loaded('mbstring') ? 'mbstring' : 'Built-in fallback', true],
    ['Largest upload', fmt_size(upload_limit()), upload_limit() >= 8 * 1048576],
    ['Data folder writable', is_writable(DATA_DIR) ? 'Yes' : 'No', is_writable(DATA_DIR)],
    ['Data folder protection', data_protected_hint(), data_exposure() !== 'exposed'],
  ];
  $o = '<table class="tbl small">';
  foreach ($rows as $r) $o .= '<tr><td>' . h($r[0]) . '</td><td>' . ($r[2] ? '' : '<span class="pill err">!</span> ') . h($r[1]) . '</td></tr>';
  $o .= '</table>';
  if (upload_limit() < 64 * 1048576) $o .= '<p class="muted small">Video files larger than ' . fmt_size(upload_limit()) . ' can’t be uploaded here. Put lecture videos on YouTube (unlisted), Vimeo or Google Drive and paste the link; that also saves your hosting bandwidth.</p>';
  return $o;
}
// Checks over HTTP whether files in the data folder can be downloaded directly.
// Returns 'outside' | 'protected' | 'exposed' | 'unknown'. Cached for a day.
function data_exposure($force = false) {
  $app = realpath(__DIR__ . '/..'); $data = realpath(DATA_DIR);
  if (!$app || !$data || !str_starts_with($data . DIRECTORY_SEPARATOR, $app . DIRECTORY_SEPARATOR)) return 'outside';
  $cache = jread('probe', []);
  if (!$force && !empty($cache['at']) && $cache['at'] > now() - 86400) return $cache['result'];
  $token = 'helmsman-probe-' . bin2hex(random_bytes(8));
  @file_put_contents(DATA_DIR . '/probe.txt', $token);
  $url = base_url() . str_replace(DIRECTORY_SEPARATOR, '/', substr($data, strlen($app) + 1)) . '/probe.txt';
  $body = null;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
    $b = curl_exec($ch);
    if ($b !== false) $body = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 ? $b : '';
    curl_close($ch);
  } elseif (ini_get('allow_url_fopen')) {
    $b = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
    if ($b !== false) $body = $b;
  }
  @unlink(DATA_DIR . '/probe.txt');
  $result = $body === null ? 'unknown' : (trim($body) === $token ? 'exposed' : 'protected');
  jwrite('probe', ['at' => now(), 'result' => $result]);
  return $result;
}
function data_protected_hint() {
  $r = data_exposure();
  if ($r === 'outside') return 'Outside the web folder (best)';
  if ($r === 'protected') return 'Blocked from direct download (checked)';
  if ($r === 'exposed') return 'EXPOSED: this server ignores .htaccess. See the warning on your dashboard.';
  return 'Could not test from the server itself; .htaccess is in place';
}
function exposure_warning() {
  if (data_exposure() !== 'exposed') return '';
  return '<div class="flash err"><b>Your data folder can be downloaded directly.</b> This server ignores the <code>.htaccess</code> file that protects it. Data files themselves stay unreadable, but uploaded files could be fetched by anyone who guesses their address. Fix: copy <code>config.sample.php</code> to <code>config.php</code>, set <code>DATA_DIR</code> to a folder outside your public web folder, and move the <code>data</code> folder there. (On nginx, deny access to <code>/data/</code> in the server configuration.)</div>';
}

// ---------- courses ----------
function r_new_course() {
  require_admin();
  if (is_post()) {
    $t = post('title');
    if ($t === '') { flash('Give the course a title.', 'err'); redirect(u('new_course')); }
    $cid = create_course($t, post('code'));
    flash('Course created. Add modules, then share the join code with students.');
    redirect(u('course', ['c' => $cid]));
  }
  ob_start(); ?>
  <h1>New blank course</h1>
  <form method="post" class="stack narrow"><?= csrf_field() ?>
    <label>Title <input name="title" required></label>
    <label>Short code <input name="code" placeholder="e.g. WKSP 101"></label>
    <button class="btn primary">Create course</button></form>
  <?php layout('New course', ob_get_clean());
}

function r_import() {
  require_admin();
  if (is_post()) {
    $f = uploaded_files('cc')[0] ?? null;
    if (!$f) { flash('Choose your .imscc file.', 'err'); redirect(u('import')); }
    if ($f['error'] !== UPLOAD_ERR_OK) { flash(upload_error_text($f['error']), 'err'); redirect(u('import')); }
    if (!preg_match('/\.(imscc|zip)$/i', $f['name'])) { flash('That doesn’t look like a .imscc file.', 'err'); redirect(u('import')); }
    @set_time_limit(300);
    $res = import_imscc($f['tmp'], ['infer_release' => post('infer') === '1']);
    $c = course($res['cid']);
    foreach (list_course_files($res['cid']) as $file) if (preg_match('~(banner_home|course_banner|banner\.)~i', $file['path'])) { $c['banner'] = $file['path']; save_course($c); break; }
    $_SESSION['import_log'] = $res['log'];
    redirect(u('import_done', ['c' => $res['cid']]));
  }
  ob_start(); ?>
  <h1>Import a Canvas course</h1>
  <div class="grid2">
  <form method="post" enctype="multipart/form-data" class="card stack"><?= csrf_field() ?>
    <label>Course export file (.imscc) <input type="file" name="cc" accept=".imscc,.zip" required></label>
    <label class="check"><input type="checkbox" name="infer" value="1" checked> Give each module a release date taken from its assignments’ “available from” dates</label>
    <button class="btn primary">Import</button>
    <p class="muted small">Largest file this server accepts: <?= fmt_size(upload_limit()) ?>.</p>
  </form>
  <div class="prose small"><h3>What comes across</h3><ul>
    <li>Modules, in order, with section headers and indents</li><li>Pages, the syllabus, and images and files</li>
    <li>Assignments with points, due and close dates, submission types, groups and weights, and rubrics</li>
    <li>Discussions (graded ones keep their points)</li><li>Classic quizzes: multiple choice, true/false, multiple answers, short answer, numeric and essay</li>
    <li>Unpublished items stay hidden</li></ul>
    <h3>What doesn’t</h3><ul><li>Student data (exports contain none)</li><li>New Quizzes (export them as classic quizzes first)</li><li>External tools (LTI)</li></ul>
    <p>Each import creates a new course, so you can re-import to start over.</p></div>
  </div>
  <?php layout('Import', ob_get_clean());
}

function r_import_done() {
  require_admin();
  $cid = safe_id(get('c'));
  $c = course($cid);
  if (!$c) redirect(u('home'));
  $log = $_SESSION['import_log'] ?? [];
  ob_start(); ?>
  <h1>Import finished</h1>
  <div class="card"><ul class="checklist"><?php foreach ($log as $l): ?><li><?= icon('check') ?> <?= $l ?></li><?php endforeach; ?></ul></div>
  <h2>Next steps</h2>
  <ol class="steps">
    <li><a href="<?= h(u('schedule', ['c' => $cid])) ?>">Check the release schedule</a> (when each module opens, and due dates).</li>
    <li><a href="<?= h(u('course', ['c' => $cid])) ?>">Fill in video links and placeholders</a>; they’re listed on the course home page.</li>
    <li><a href="<?= h(u('course_settings', ['c' => $cid])) ?>">Review grading weights and late policy</a>.</li>
    <li>Share the sign-up link with students: <code class="sel"><?= h(base_url() . u('signup', ['code' => $c['join_code']])) ?></code>, or <a href="<?= h(u('people', ['c' => $cid])) ?>">add them yourself</a>.</li>
    <li>Use <b>Student view</b> in the course menu to see exactly what students will see.</li>
  </ol>
  <p><a class="btn primary" href="<?= h(u('course', ['c' => $cid])) ?>">Open the course</a></p>
  <?php layout('Import finished', ob_get_clean(), ['course' => $cid]);
}

function r_course_settings() {
  $cid = safe_id(get('c'));
  require_admin(); $c = require_course($cid);
  if (is_post()) {
    $act = post('act');
    if ($act === 'delete') {
      if (post('confirm') !== $c['join_code']) { flash('Type the course code exactly to delete.', 'err'); redirect(u('course_settings', ['c' => $cid])); }
      rrmdir(dpath("c/$cid"));
      flash('Course deleted.');
      redirect(u('home'));
    }
    if ($act === 'newcode') { $c['join_code'] = new_join_code(); save_course($c); flash('New join code: ' . h($c['join_code'])); redirect(u('course_settings', ['c' => $cid])); }
    $c['title'] = post('title') ?: $c['title'];
    $c['code'] = post('code');
    $c['tz'] = post('tz') ?: $c['tz'];
    $c['signup_open'] = post('signup_open') === '1';
    $c['auto_approve'] = post('auto_approve') === '1';
    $c['weighted'] = post('weighted') === '1';
    $c['missing_zero'] = post('missing_zero') === '1';
    $c['hide_total'] = post('hide_total') === '1';
    $c['archived'] = post('archived') === '1';
    $c['late_pct'] = max(0, (float)post('late_pct'));
    $c['late_max'] = max(0, (float)post('late_max'));
    $c['banner'] = safe_rel_path(post('banner'));
    $groups = [];
    foreach ((array)($_POST['g_title'] ?? []) as $i => $t) {
      $t = trim($t); if ($t === '') continue;
      $groups[] = ['id' => safe_id($_POST['g_id'][$i] ?? '') ?: newid('g'), 'title' => $t, 'weight' => (float)($_POST['g_weight'][$i] ?? 0)];
    }
    if ($groups) $c['groups'] = $groups;
    $scale = [];
    foreach ((array)($_POST['s_letter'] ?? []) as $i => $l) { $l = trim($l); if ($l === '') continue; $scale[] = [(float)($_POST['s_min'][$i] ?? 0), $l]; }
    usort($scale, function ($a, $b) { return $b[0] <=> $a[0]; });
    if ($scale) $c['scale'] = $scale;
    save_course($c);
    flash('Settings saved.');
    redirect(u('course_settings', ['c' => $cid]));
  }
  $wsum = array_sum(array_column($c['groups'], 'weight'));
  ob_start(); ?>
  <h1>Course settings</h1>
  <form method="post" class="stack settings"><?= csrf_field() ?><input type="hidden" name="act" value="save">
  <div class="grid2">
    <fieldset class="card"><legend>Course</legend>
      <label>Title <input name="title" value="<?= h($c['title']) ?>" required></label>
      <label>Short code <input name="code" value="<?= h($c['code']) ?>"></label>
      <label>Time zone for dates <?= tz_select('tz', $c['tz']) ?></label>
      <label>Banner image <select name="banner"><option value="">Color band</option><?php foreach (list_course_files($cid) as $f) if (preg_match('/\.(png|jpe?g|gif|webp)$/i', $f['path'])) echo '<option' . ($f['path'] === $c['banner'] ? ' selected' : '') . '>' . h($f['path']) . '</option>'; ?></select></label>
      <label class="check"><input type="checkbox" name="archived" value="1" <?= $c['archived'] ? 'checked' : '' ?>> Course closed (students can no longer open it)</label>
    </fieldset>
    <fieldset class="card"><legend>Enrollment</legend>
      <p>Join code <code class="big"><?= h($c['join_code']) ?></code> <?= post_btn(u('course_settings', ['c' => $cid]), 'New code', 'btn sm', ['act' => 'newcode']) ?></p>
      <p class="small">Sign-up link: <code class="sel"><?= h(base_url() . u('signup', ['code' => $c['join_code']])) ?></code></p>
      <label class="check"><input type="checkbox" name="signup_open" value="1" <?= $c['signup_open'] ? 'checked' : '' ?>> Students can request access with the join code</label>
      <label class="check"><input type="checkbox" name="auto_approve" value="1" <?= $c['auto_approve'] ? 'checked' : '' ?>> Approve requests automatically (otherwise you approve each one under People)</label>
    </fieldset>
  </div>
  <fieldset class="card"><legend>Grading</legend>
    <label class="check"><input type="checkbox" name="weighted" value="1" <?= $c['weighted'] ? 'checked' : '' ?>> Weight the final grade by assignment group</label>
    <table class="tbl groups"><thead><tr><th>Assignment group</th><th class="num">Weight %</th><th></th></tr></thead><tbody id="groups">
    <?php foreach ($c['groups'] as $g): ?><tr><td><input type="hidden" name="g_id[]" value="<?= h($g['id']) ?>"><input name="g_title[]" value="<?= h($g['title']) ?>"></td><td class="num"><input type="number" step="any" name="g_weight[]" value="<?= h(fmt_num($g['weight'])) ?>" class="w6"></td><td><button type="button" class="linkish" data-remove-row>Remove</button></td></tr><?php endforeach; ?>
    </tbody><tfoot><tr><td><button type="button" class="btn sm" data-add-row="#groups" data-template="group-row"><?= icon('plus') ?> Add group</button></td><td class="num"><?= $c['weighted'] ? 'Total ' . fmt_num($wsum) . '%' . (abs($wsum - 100) > 0.01 ? ' <span class="pill warn">not 100</span>' : '') : '' ?></td><td></td></tr></tfoot></table>
    <template id="group-row"><tr><td><input type="hidden" name="g_id[]" value=""><input name="g_title[]" placeholder="Group name"></td><td class="num"><input type="number" step="any" name="g_weight[]" value="0" class="w6"></td><td><button type="button" class="linkish" data-remove-row>Remove</button></td></tr></template>
    <p class="muted small">Removing a group moves its assignments into the first group.</p>
    <div class="grid2">
      <div><h4>Letter grades</h4><table class="tbl"><thead><tr><th>Letter</th><th class="num">From %</th></tr></thead><tbody>
        <?php foreach (array_merge($c['scale'], [[null, ''], [null, '']]) as $s): ?><tr><td><input name="s_letter[]" value="<?= h($s[1]) ?>" class="w6"></td><td class="num"><input type="number" step="any" name="s_min[]" value="<?= $s[0] === null ? '' : h(fmt_num($s[0])) ?>" class="w6"></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="stack"><h4>Late and missing work</h4>
        <label>Late penalty per day (%) <input type="number" step="any" min="0" name="late_pct" value="<?= h(fmt_num($c['late_pct'])) ?>" class="w6"><small>0 means no automatic penalty. You can waive it for one student when grading.</small></label>
        <label>Maximum late penalty (%) <input type="number" step="any" min="0" name="late_max" value="<?= h(fmt_num($c['late_max'])) ?>" class="w6"><small>0 means no cap.</small></label>
        <label class="check"><input type="checkbox" name="missing_zero" value="1" <?= $c['missing_zero'] ? 'checked' : '' ?>> Count missing work as zero once it’s past due</label>
        <label class="check"><input type="checkbox" name="hide_total" value="1" <?= $c['hide_total'] ? 'checked' : '' ?>> Hide the running total from students</label>
      </div>
    </div>
  </fieldset>
  <button class="btn primary">Save settings</button>
  </form>
  <details class="card danger-zone"><summary>Delete this course</summary>
    <form method="post" class="stack narrow"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><p>This permanently deletes the course content, submissions and grades. Student accounts stay. Type the join code <b><?= h($c['join_code']) ?></b> to confirm.</p><input name="confirm" autocomplete="off"><button class="btn danger">Delete course permanently</button></form></details>
  <?php layout('Settings', ob_get_clean(), ['course' => $cid, 'active' => 'course_settings']);
}

// ---------- people ----------
function r_people() {
  $cid = safe_id(get('c'));
  require_admin(); $c = require_course($cid);
  if (is_post()) {
    $act = post('act');
    if ($act === 'approve' || $act === 'deny' || $act === 'remove') {
      $ids = (array)($_POST['uid'] ?? []);
      foreach ($ids as $uid) {
        $uid = safe_id($uid);
        if (!user($uid)) continue;
        if ($act === 'approve') { set_enrollment($cid, $uid, 'active'); notify($uid, 'You’re approved for ' . h($c['title']) . '. Welcome!', u('course', ['c' => $cid])); }
        else set_enrollment($cid, $uid, $act === 'deny' ? null : 'dropped');
      }
      flash(count($ids) . ' ' . ($act === 'approve' ? 'approved' : ($act === 'deny' ? 'declined' : 'removed from the course')) . '.');
    } elseif ($act === 'add') {
      $lines = preg_split('/\r?\n/', (string)($_POST['people'] ?? ''));
      $created = []; $enrolled = 0; $bad = [];
      foreach ($lines as $line) {
        $line = trim($line); if ($line === '') continue;
        if (!preg_match('/([^\s,;<>]+@[^\s,;<>]+)/', $line, $m)) { $bad[] = $line; continue; }
        $email = strtolower($m[1]);
        $name = clean_name(trim(preg_replace('/[<>]|' . preg_quote($m[1], '/') . '/', '', $line), " \t,;\"'"));
        if (!valid_email($email)) { $bad[] = $line; continue; }
        $u = user_by_email($email);
        if (!$u) {
          $pw = gen_password();
          $u = ['id' => newid('u'), 'name' => $name ?: explode('@', $email)[0], 'email' => $email, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'student', 'status' => 'active', 'created' => now(), 'must_change' => true];
          save_user($u);
          $created[] = [$u['name'], $email, $pw];
        }
        if ($u['role'] !== 'admin') { set_enrollment($cid, $u['id'], 'active'); $enrolled++; notify($u['id'], 'You were added to ' . h($c['title']) . '.', u('course', ['c' => $cid])); }
      }
      $_SESSION['new_accounts'] = $created;
      flash("$enrolled student" . ($enrolled == 1 ? '' : 's') . ' enrolled' . ($created ? ', ' . count($created) . ' new account' . (count($created) == 1 ? '' : 's') . ' created (passwords below)' : '') . '.' . ($bad ? ' Skipped lines without a valid email: ' . h(implode(' | ', $bad)) : ''), $bad ? 'warn' : 'ok');
    } elseif ($act === 'reset') {
      $u = user(post('uid'));
      if ($u && $u['role'] !== 'admin') {
        $pw = gen_password(); patch_user($u['id'], ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => true, 'sess_after' => now()]);
        $_SESSION['new_accounts'] = [[$u['name'], $u['email'], $pw]];
        flash('Password reset for ' . h($u['name']) . '.');
      }
    }
    redirect(u('people', ['c' => $cid]));
  }
  $users = users();
  $r = roster($cid);
  $by = ['pending' => [], 'active' => [], 'dropped' => []];
  foreach ($r as $uid => $e) if (isset($users[$uid])) $by[$e['status']][$uid] = $users[$uid] + ['joined' => $e['joined']];
  foreach ($by as &$l) uasort($l, function ($a, $b) { return strcasecmp(sort_name($a['name']), sort_name($b['name'])); });
  unset($l);
  $newAcc = $_SESSION['new_accounts'] ?? []; unset($_SESSION['new_accounts']);
  ob_start(); ?>
  <h1>People</h1>
  <?php if ($newAcc): ?>
    <div class="card highlight"><h3>New login details (shown only once)</h3><p class="small">Send each student their email and temporary password. They’ll be asked to choose a new password the first time they log in at <code><?= h(base_url()) ?></code>.</p>
    <textarea class="sel mono" rows="<?= min(12, count($newAcc) + 1) ?>" readonly><?php echo "Name\tEmail\tTemporary password\n"; foreach ($newAcc as $a) echo h($a[0]) . "\t" . h($a[1]) . "\t" . h($a[2]) . "\n"; ?></textarea></div>
  <?php endif; ?>
  <?php if ($by['pending']): ?>
  <form method="post" class="card"><?= csrf_field() ?><h2>Waiting for approval (<?= count($by['pending']) ?>)</h2>
    <table class="tbl"><thead><tr><th><input type="checkbox" data-check-all></th><th>Name</th><th>Email</th><th>Requested</th></tr></thead><tbody>
    <?php foreach ($by['pending'] as $uid => $u): ?><tr><td><input type="checkbox" name="uid[]" value="<?= h($uid) ?>" checked></td><td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td><td><?= h(rel_time($u['joined'])) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <div class="row"><button class="btn primary" name="act" value="approve">Approve selected</button><button class="btn danger" name="act" value="deny" data-confirm="Decline the selected requests?">Decline selected</button></div></form>
  <?php endif; ?>
  <div class="grid-main">
  <div>
  <h2>Students (<?= count($by['active']) ?>)</h2>
  <?php if (!$by['active']): echo empty_state('No students yet.'); else: ?>
  <form method="post"><?= csrf_field() ?>
  <table class="tbl"><thead><tr><th><input type="checkbox" data-check-all></th><th>Name</th><th>Email</th><th>Last login</th><th></th></tr></thead><tbody>
  <?php foreach ($by['active'] as $uid => $u): ?><tr><td><input type="checkbox" name="uid[]" value="<?= h($uid) ?>"></td><td><a href="<?= h(u('student', ['c' => $cid, 'uid' => $uid])) ?>"><?= h($u['name']) ?></a></td><td><?= h($u['email']) ?></td><td class="small"><?= !empty($u['last_login']) ? h(rel_time($u['last_login'])) : '<span class="muted">never</span>' ?></td>
    <td class="nowrap"><button class="btn sm" form="reset-<?= h($uid) ?>">Reset password</button></td></tr><?php endforeach; ?>
  </tbody></table>
  <button class="btn sm danger" name="act" value="remove" data-confirm="Remove the selected students from this course? Their submissions are kept and come back if you re-add them.">Remove selected</button>
  </form>
  <?php foreach ($by['active'] as $uid => $u): ?><form method="post" id="reset-<?= h($uid) ?>" data-confirm="Make a new temporary password for <?= h($u['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="act" value="reset"><input type="hidden" name="uid" value="<?= h($uid) ?>"></form><?php endforeach; ?>
  <?php endif; ?>
  <?php if ($by['dropped']): ?><details><summary class="muted">Removed students (<?= count($by['dropped']) ?>)</summary><form method="post"><?= csrf_field() ?><table class="tbl"><?php foreach ($by['dropped'] as $uid => $u): ?><tr><td><input type="checkbox" name="uid[]" value="<?= h($uid) ?>"></td><td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td></tr><?php endforeach; ?></table><button class="btn sm" name="act" value="approve">Re-add selected</button></form></details><?php endif; ?>
  </div>
  <aside class="rail">
    <form method="post" class="card stack"><?= csrf_field() ?><input type="hidden" name="act" value="add"><h3>Add students</h3>
      <label>One per line: name and email <textarea name="people" rows="7" placeholder="Ana Ruiz, ana@example.edu&#10;Ben Ode &lt;ben@example.edu&gt;" required></textarea></label>
      <small class="muted">New accounts get a temporary password that you’ll see once. Existing accounts are simply enrolled. You can paste straight from a spreadsheet.</small>
      <button class="btn primary">Add and enroll</button></form>
    <div class="card"><h3>Or let them sign up</h3><p class="small">Share this link; you approve each request here.</p><code class="sel"><?= h(base_url() . u('signup', ['code' => $c['join_code']])) ?></code><p class="small muted"><?= $c['signup_open'] ? ($c['auto_approve'] ? 'Requests are approved automatically.' : 'Requests wait for your approval.') : 'Sign-ups are currently closed (Settings).' ?></p></div>
  </aside>
  </div>
  <?php layout('People', ob_get_clean(), ['course' => $cid, 'active' => 'people']);
}

// One student's overview for the instructor.
function r_student() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $uid = safe_id(get('uid')); $u = user($uid);
  if (!$u) throw new HttpError('Student not found.');
  $rows = student_rows($cid, $uid, true);
  $tot = compute_total($course, $rows);
  ob_start(); ?>
  <div class="head-row"><h1><?= h($u['name']) ?></h1><div class="total-badge"><span><?= $tot['pct'] === null ? '–' : fmt_num(round($tot['pct'], 1)) . '%' ?></span><b><?= h($tot['letter']) ?></b></div></div>
  <p class="muted"><?= h($u['email']) ?> · last login <?= !empty($u['last_login']) ? h(rel_time($u['last_login'])) : 'never' ?></p>
  <table class="tbl"><thead><tr><th>Item</th><th>Status</th><th class="num">Score</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $g = $r['g']; ?><tr><td><?= h($g['title']) ?></td><td><?= h($r['status']) ?><?= $r['late'] ? ' <span class="pill err">' . $r['late'] . 'd late</span>' : '' ?></td><td class="num"><?= $r['score'] !== null ? fmt_num($r['score']) : ($r['excused'] ? 'EX' : '–') ?> / <?= fmt_num($g['points']) ?></td><td><a class="btn sm" href="<?= h(u('grade', ['c' => $cid, 'id' => $g['id'], 'uid' => $uid])) ?>">Open</a></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php layout($u['name'], ob_get_clean(), ['course' => $cid, 'active' => 'people']);
}

function r_preview() {
  $cid = safe_id(get('c'));
  require_admin();
  if (is_post()) {
    if (get('off')) { unset($_SESSION['preview']); flash('Back to instructor view.'); }
    else { $_SESSION['preview'] = $cid; }
  }
  redirect(u('course', ['c' => $cid]));
}

// ---------- course files ----------
function r_files() {
  $cid = safe_id(get('c')); require_admin(); require_course($cid);
  if (is_post()) {
    if (post('replace')) {
      $rel = safe_rel_path(post('replace'));
      $base = realpath(course_files_dir($cid)); $p = $base ? realpath("$base/$rel") : false;
      $f = uploaded_files('file')[0] ?? null;
      if (!$p || !str_starts_with($p, $base . DIRECTORY_SEPARATOR) || !$f) { flash('Choose a file to upload.', 'err'); redirect(u('files', ['c' => $cid])); }
      if ($f['error'] !== UPLOAD_ERR_OK) { flash(upload_error_text($f['error']), 'err'); redirect(u('files', ['c' => $cid])); }
      if (!upload_ok_name($f['name'])) { flash('That file type is not allowed.', 'err'); redirect(u('files', ['c' => $cid])); }
      if (!move_uploaded_file($f['tmp'], $p)) { flash('Could not save the file.', 'err'); redirect(u('files', ['c' => $cid])); }
      flash('Replaced ' . h($rel) . '. Every page that shows it now uses the new version (refresh if your browser still shows the old one).');
      redirect(u('files', ['c' => $cid]));
    }
    if (post('del')) {
      $rel = safe_rel_path(post('del'));
      $base = realpath(course_files_dir($cid)); $p = $base ? realpath("$base/$rel") : false;
      if ($p && str_starts_with($p, $base . DIRECTORY_SEPARATOR)) { @unlink($p); flash('Deleted ' . h($rel) . '.'); }
    } else {
      $n = 0;
      foreach (uploaded_files('files') as $f) { save_course_file($cid, $f); $n++; }
      flash("$n file" . ($n == 1 ? '' : 's') . ' uploaded.');
    }
    redirect(u('files', ['c' => $cid]));
  }
  $files = list_course_files($cid);
  ob_start(); ?>
  <h1>Course files</h1>
  <form method="post" enctype="multipart/form-data" class="card row"><?= csrf_field() ?><input type="file" name="files[]" multiple required><button class="btn primary">Upload</button><span class="muted small">Up to <?= fmt_size(upload_limit()) ?> per upload.</span></form>
  <p class="muted small">Files here can be used in pages (image and file buttons in the editor), as video lectures, or as module items. Only enrolled students can open them.</p>
  <table class="tbl"><thead><tr><th>File</th><th class="num">Size</th><th>Address for links</th><th></th></tr></thead><tbody>
  <?php foreach ($files as $f): ?><tr><td><a href="<?= h(cfile_url($cid, $f['path'])) ?>" target="_blank"><?= h($f['path']) ?></a></td><td class="num"><?= fmt_size($f['size']) ?></td><td><code class="sel small"><?= h(cfile_url($cid, $f['path'])) ?></code></td><td class="row nowrap"><details class="pop"><summary class="btn sm">Replace</summary><form method="post" enctype="multipart/form-data" class="card stack"><?= csrf_field() ?><input type="hidden" name="replace" value="<?= h($f['path']) ?>"><label>New version of <?= h(basename($f['path'])) ?><input type="file" name="file" required></label><small class="muted">Keeps the same name and address, so pages using it update automatically.</small><button class="btn sm primary">Upload replacement</button></form></details><?= confirm_btn(u('files', ['c' => $cid]), 'Delete', 'Delete this file? Pages that show it will have a broken image or link.', 'btn sm danger', ['del' => $f['path']]) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if (!$files) echo empty_state('No files yet.'); ?>
  <?php layout('Files', ob_get_clean(), ['course' => $cid]);
}

// Editor uploads (images, video, documents) -> JSON.
function r_upload_asset() {
  $cid = safe_id(get('c'));
  header('Content-Type: application/json');
  try {
    require_admin(); require_course($cid);
    if (!is_post()) throw new HttpError('POST only');
    $f = uploaded_files('file')[0] ?? null;
    if (!$f) throw new HttpError('No file received. It may be larger than the server allows (' . fmt_size(upload_limit()) . ').');
    $path = save_course_file($cid, $f);
    $mime = mime_for($path);
    echo json_encode(['ok' => true, 'url' => cfile_url($cid, $path), 'name' => basename($path), 'path' => $path, 'kind' => explode('/', $mime)[0]]);
  } catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => strip_tags($e->getMessage())]);
  }
  exit;
}
