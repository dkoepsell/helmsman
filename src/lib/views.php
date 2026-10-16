<?php
// Views shared by everyone, and the student-facing course views.
if (!defined('APP')) { http_response_code(404); exit; }

// ======================= account / auth =======================

function r_setup() {
  if (setup_done()) redirect(u('login'));
  $err = '';
  if (is_post()) {
    $name = clean_name(post('name')); $email = strtolower(post('email')); $pw = (string)($_POST['password'] ?? '');
    $site = mb_substr(trim(post('site')), 0, 80);
    if ($name === '' || !valid_email($email)) $err = 'Enter your name and a valid email.';
    elseif (strlen($pw) < 8 || strlen($pw) > 200) $err = 'Use a password of 8 to 200 characters.';
    else {
      $u = ['id' => newid('u'), 'name' => $name, 'email' => $email, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'admin', 'status' => 'active', 'created' => now()];
      save_user($u);
      jwrite('site', ['name' => $site ?: 'Courses', 'tz' => post('tz') ?: 'America/Chicago']);
      login_session($u['id']);
      flash('Your instructor account is ready. Next: import your Canvas course or create a blank one.');
      redirect(u('home'));
    }
  }
  ob_start(); ?>
  <div class="auth"><h1>Set up your course site</h1>
  <p class="muted">This creates the instructor (admin) account. You only do this once.</p>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>Site name <input name="site" value="<?= h(post('site', 'My Courses')) ?>" required></label>
    <label>Your name <input name="name" value="<?= h(post('name')) ?>" required autocomplete="name"></label>
    <label>Email (your login) <input type="email" name="email" value="<?= h(post('email')) ?>" required autocomplete="email"></label>
    <label>Password <input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
    <label>Time zone <?= tz_select('tz', 'America/Chicago') ?></label>
    <button class="btn primary">Create instructor account</button>
  </form></div>
  <?php layout('Set up', ob_get_clean());
}

function tz_select($name, $val) {
  $zones = ['America/Chicago', 'America/New_York', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Mexico_City', 'Europe/London', 'Europe/Amsterdam', 'UTC'];
  if (!in_array($val, $zones, true)) $zones[] = $val;
  $o = '<select name="' . h($name) . '">';
  foreach ($zones as $z) $o .= '<option' . ($z === $val ? ' selected' : '') . '>' . h($z) . '</option>';
  return $o . '</select>';
}

function r_login() {
  if (!setup_done()) redirect(u('setup'));
  if (me()) redirect(u('home'));
  $err = '';
  if (is_post()) {
    $email = strtolower(post('email'));
    // Only failed attempts count, so a whole class logging in from one campus network is fine.
    if (!throttle_ok('ip:' . client_ip(), 60) || !throttle_ok('em:' . $email, 10)) {
      $err = 'Too many attempts. Wait 15 minutes and try again.';
    } else {
      $u = user_by_email($email);
      if ($u && password_verify((string)($_POST['password'] ?? ''), $u['hash'] ?? '') && $u['status'] !== 'disabled') {
        login_session($u['id']);
        $patch = ['last_login' => now()];
        if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) $patch['hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        patch_user($u['id'], $patch);
        if (!empty($u['must_change'])) { flash('Please choose a new password.'); redirect(u('account')); }
        $to = $_SESSION['after_login'] ?? ''; unset($_SESSION['after_login']);
        redirect($to && str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : u('home'));
      }
      throttle_add('ip:' . client_ip()); throttle_add('em:' . $email);
      $err = 'That email and password don’t match.';
    }
  }
  ob_start(); ?>
  <div class="auth"><h1>Log in</h1>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>Email <input type="email" name="email" value="<?= h(post('email')) ?>" required autofocus autocomplete="username"></label>
    <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary">Log in</button>
  </form>
  <p class="muted small">New student? <a href="<?= h(u('signup')) ?>">Request access with your course code</a>.<br>Forgot your password? Ask your instructor to reset it.</p>
  </div>
  <?php layout('Log in', ob_get_clean());
}

function r_logout() {
  if (is_post()) { $_SESSION = []; session_destroy(); }
  redirect(u('login'));
}

function find_course_by_code($code) {
  $code = strtoupper(trim($code));
  if ($code === '') return null;
  foreach (courses_all() as $c) if (strtoupper($c['join_code']) === $code && !$c['archived']) return $c;
  return null;
}

function r_signup() {
  $err = '';
  $code = strtoupper(trim(post('code', get('code'))));
  if (is_post()) {
    $c = find_course_by_code($code);
    $name = clean_name(post('name')); $email = strtolower(post('email')); $pw = (string)($_POST['password'] ?? '');
    if (!throttle_ok('signup:' . client_ip(), 60, 3600)) $err = 'Too many sign-ups from this network. Try again later.';
    elseif (!$c || empty($c['signup_open'])) $err = 'That course code isn’t valid, or the course isn’t taking sign-ups. Check the code with your instructor.';
    elseif ($name === '' || !valid_email($email)) $err = 'Enter your full name and a valid email.';
    elseif (strlen($pw) < 8 || strlen($pw) > 200) $err = 'Use a password of 8 to 200 characters.';
    elseif (user_by_email($email)) $err = 'An account with that email already exists. Log in, then use “Join a course” on your dashboard.';
    else {
      $u = ['id' => newid('u'), 'name' => $name, 'email' => $email, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'student', 'status' => 'active', 'created' => now()];
      save_user($u);
      $st = $c['auto_approve'] ? 'active' : 'pending';
      set_enrollment($c['id'], $u['id'], $st);
      if ($st === 'pending') notify_admins(h($name) . ' asked to join ' . h($c['title']), u('people', ['c' => $c['id']]));
      throttle_add('signup:' . client_ip(), 3600);
      login_session($u['id']);
      flash($st === 'pending' ? 'Account created. Your instructor will approve your access to <b>' . h($c['title']) . '</b>; you’ll see a notification here when that happens.' : 'Welcome to ' . h($c['title']) . '!');
      redirect(u('home'));
    }
  }
  ob_start(); ?>
  <div class="auth"><h1>Request course access</h1>
  <p class="muted">Your instructor gave you a course code. After you sign up, the instructor approves your access.</p>
  <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>Course code <input name="code" value="<?= h($code) ?>" required autocapitalize="characters" style="text-transform:uppercase;letter-spacing:.1em"></label>
    <label>Full name <input name="name" value="<?= h(post('name')) ?>" required autocomplete="name"></label>
    <label>Email <input type="email" name="email" value="<?= h(post('email')) ?>" required autocomplete="email"></label>
    <label>Password <input type="password" name="password" minlength="8" required autocomplete="new-password"><small>At least 8 characters.</small></label>
    <button class="btn primary">Sign up</button>
  </form>
  <p class="muted small">Already have an account? <a href="<?= h(u('login')) ?>">Log in</a>.</p></div>
  <?php layout('Sign up', ob_get_clean());
}

function r_join() {
  require_login();
  if (is_post()) {
    $c = find_course_by_code(post('code'));
    $me = me();
    if (!$c || empty($c['signup_open'])) flash('That course code isn’t valid, or the course isn’t taking sign-ups.', 'err');
    elseif (($e = enrollment($c['id'], $me['id'])) && $e['status'] === 'dropped') flash('You were removed from that course. Ask your instructor if you should be added back.', 'err');
    elseif ($e) flash('You already asked to join that course.', 'warn');
    else {
      $st = $c['auto_approve'] ? 'active' : 'pending';
      set_enrollment($c['id'], $me['id'], $st);
      if ($st === 'pending') notify_admins(h($me['name']) . ' asked to join ' . h($c['title']), u('people', ['c' => $c['id']]));
      flash($st === 'pending' ? 'Request sent. You’ll get a notification when you’re approved.' : 'You’ve joined ' . h($c['title']) . '.');
    }
  }
  redirect(u('home'));
}

function r_account() {
  require_login();
  $me = me();
  if (is_post()) {
    $name = clean_name(post('name'));
    $cur = (string)($_POST['current'] ?? ''); $pw = (string)($_POST['password'] ?? '');
    $patch = [];
    if ($name !== '') $patch['name'] = $name;
    if ($pw !== '') {
      if (empty($me['must_change']) && !password_verify($cur, $me['hash'])) { flash('Your current password is not correct.', 'err'); redirect(u('account')); }
      if (strlen($pw) < 8 || strlen($pw) > 200) { flash('Use a password of 8 to 200 characters.', 'err'); redirect(u('account')); }
      // Signs out every other session on this account; this one stays signed in.
      $patch += ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change' => null, 'sess_after' => now()];
      $_SESSION['auth_at'] = now();
      flash('Password changed.');
    } else flash('Saved.');
    patch_user($me['id'], $patch);
    redirect(u('account'));
  }
  ob_start(); ?>
  <h1>Your account</h1>
  <form method="post" class="stack narrow"><?= csrf_field() ?>
    <label>Name <input name="name" value="<?= h($me['name']) ?>" required></label>
    <label>Email <input value="<?= h($me['email']) ?>" disabled><small>Ask your instructor to change your login email.</small></label>
    <fieldset><legend><?= !empty($me['must_change']) ? 'Choose a new password' : 'Change password' ?></legend>
      <?php if (empty($me['must_change'])): ?><label>Current password <input type="password" name="current" autocomplete="current-password"></label><?php endif; ?>
      <label>New password <input type="password" name="password" minlength="8" autocomplete="new-password" <?= !empty($me['must_change']) ? 'required' : '' ?>></label>
    </fieldset>
    <button class="btn primary">Save</button>
  </form>
  <?php layout('Account', ob_get_clean());
}

// ======================= dashboard =======================

function r_home() {
  require_login();
  if (is_admin() && !previewing()) { admin_dashboard(); return; }
  $me = me();
  $cs = my_courses();
  ob_start(); ?>
  <h1>Hello, <?= h(explode(' ', $me['name'])[0]) ?></h1>
  <div class="grid2">
  <section>
    <h2>Your courses</h2>
    <?php if (!$cs) echo empty_state('You aren’t in any courses yet. Enter the code your instructor gave you.'); ?>
    <div class="cards">
    <?php foreach ($cs as $c): ?>
      <?php if ($c['_status'] === 'pending'): ?>
        <div class="card course-card pending"><div class="cc-band"></div><div class="cc-body"><b><?= h($c['title']) ?></b><span class="pill warn">Waiting for approval</span></div></div>
      <?php else: ?>
        <a class="card course-card" href="<?= h(u('course', ['c' => $c['id']])) ?>"><div class="cc-band"<?= course_band_style($c) ?>></div><div class="cc-body"><b><?= h($c['title']) ?></b><span class="muted"><?= h($c['code']) ?></span></div></a>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <form method="post" action="<?= h(u('join')) ?>" class="row join"><?= csrf_field() ?>
      <input name="code" placeholder="Course code" required style="text-transform:uppercase;max-width:12rem"><button class="btn">Join a course</button>
    </form>
  </section>
  <section>
    <h2>To do</h2>
    <?php
    $todo = [];
    foreach ($cs as $c) if ($c['_status'] === 'active') foreach (todo_items($c['id'], $me['id']) as $t) $todo[] = $t + ['course' => $c];
    usort($todo, function ($a, $b) { return ($a['g']['due_at'] ?: PHP_INT_MAX) <=> ($b['g']['due_at'] ?: PHP_INT_MAX); });
    echo todo_list(array_slice($todo, 0, 12), true);
    ?>
  </section>
  </div>
  <?php layout('Dashboard', ob_get_clean());
}

function course_band_style($c) {
  if (!empty($c['banner'])) return ' style="background-image:url(\'' . h(cfile_url($c['id'], $c['banner'])) . '\')"';
  $hue = hexdec(substr(md5($c['id']), 0, 2)) * 360 / 255;
  return ' style="background:linear-gradient(135deg,hsl(' . (int)$hue . ' 35% 30%),hsl(' . (int)(($hue + 40) % 360) . ' 35% 45%))"';
}

// Gradables a student still needs to do.
function todo_items($cid, $uid) {
  $out = [];
  foreach (gradables($cid) as $g) {
    $a = access($cid, $g['type'], $g['id']);
    if (!$a['ok']) continue;
    if ($g['type'] === 'assignment' && !$g['submits']) continue;
    $closed = ($g['lock_at'] && now() > $g['lock_at']);
    if ($closed) continue;
    $sub = get_sub($cid, $g['id'], $uid);
    if (!empty($sub['attempts'])) continue;
    if ($g['due_at'] && $g['due_at'] < now() - 7 * 86400) continue;
    $out[] = ['g' => $g, 'cid' => $cid];
  }
  return $out;
}

function todo_list($items, $showCourse = false) {
  if (!$items) return empty_state('Nothing due right now.');
  $o = '<ul class="todo">';
  foreach ($items as $t) {
    $g = $t['g']; $cid = $t['cid'];
    $late = $g['due_at'] && $g['due_at'] < now();
    $o .= '<li><a href="' . h(item_url($cid, $g['type'], $g['id'])) . '">' . icon($g['type']) . '<span><b>' . h($g['title']) . '</b>'
      . ($showCourse ? '<small class="muted">' . h($t['course']['code'] ?: $t['course']['title']) . '</small>' : '') . '</span>'
      . '<span class="due ' . ($late ? 'late' : '') . '">' . ($g['due_at'] ? ($late ? 'Was due ' : 'Due ') . h(fmt_dt($g['due_at'], course($cid)['tz'])) : 'No due date') . '</span></a></li>';
  }
  return $o . '</ul>';
}

// ======================= notifications =======================

function r_notifications() {
  require_login();
  $me = me();
  if (is_post()) {
    if (post('go')) {
      $id = post('go'); $link = '';
      jupdate("notes/{$me['id']}", function ($n) use ($id, &$link) { foreach ($n as &$x) if ($x['id'] === $id) { $x['read'] = true; $link = $x['link']; } return $n; });
      redirect($link && str_starts_with($link, '?') ? $link : u('notifications'));
    }
    jupdate("notes/{$me['id']}", function ($n) { foreach ($n as &$x) $x['read'] = true; return $n; });
    redirect(u('notifications'));
  }
  $notes = jread("notes/{$me['id']}", []);
  ob_start(); ?>
  <div class="head-row"><h1>Notifications</h1><?php if (array_filter($notes, function ($n) { return empty($n['read']); })) echo post_btn(u('notifications'), 'Mark all read'); ?></div>
  <?php if (!$notes) echo empty_state('No notifications yet. You’ll see grades, feedback, replies and course news here.'); ?>
  <ul class="notes">
  <?php foreach ($notes as $n): ?>
    <li class="<?= empty($n['read']) ? 'unread' : '' ?>">
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="go" value="<?= h($n['id']) ?>">
      <button class="note-btn"><span><?= $n['text'] ?></span><small><?= h(rel_time($n['at'])) ?></small></button></form>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php layout('Notifications', ob_get_clean());
}

// ======================= files =======================

function r_cfile() {
  $cid = safe_id(get('c'));
  require_course($cid);
  $rel = safe_rel_path(get('f'));
  // Students get only files that something they can already see links to.
  if (!staff($cid) && !isset(visible_file_paths($cid)[$rel])) { http_response_code(404); exit('Not found'); }
  $base = realpath(course_files_dir($cid));
  $p = $base ? realpath($base . '/' . $rel) : false;
  if (!$p || !str_starts_with($p, $base . DIRECTORY_SEPARATOR)) { http_response_code(404); exit('Not found'); }
  send_file($p, basename($p), true);
}

// Submission files: only the student who submitted and instructors.
function r_upfile() {
  $cid = safe_id(get('c'));
  require_course($cid);
  $uid = safe_id(get('s'));
  if (!staff($cid) && !(is_admin() && previewing($cid)) && me()['id'] !== $uid) { http_response_code(403); exit('Not allowed'); }
  $sub = get_sub($cid, get('o'), $uid);
  $a = $sub['attempts'][(int)get('a')] ?? null;
  $f = $a['files'][(int)get('i')] ?? null;
  if (!$f) { http_response_code(404); exit('Not found'); }
  send_file(dpath($f['stored']), $f['name'], get('dl') ? false : true);
}

// ======================= course pages =======================

function r_course() {
  $cid = safe_id(get('c'));
  $course = require_course($cid);
  $staff = staff($cid);
  $anns = array_slice(visible_announcements($cid), 0, 2);
  ob_start(); ?>
  <div class="course-hero<?= $course['banner'] ? ' has-img' : '' ?>"<?= course_band_style($course) ?>><div><h1><?= h($course['title']) ?></h1><?php if ($course['code']): ?><p><?= h($course['code']) ?></p><?php endif; ?></div></div>
  <div class="grid-main">
    <div>
      <?php foreach ($anns as $a): ?>
        <article class="ann-mini"><div class="muted small"><?= icon('megaphone') ?> Announcement · <?= h(fmt_dt(max($a['post_at'] ?? 0, $a['created'] ?? 0), $course['tz'])) ?></div><h3><?= h($a['title']) ?></h3><div class="prose clamp"><?= $a['body'] ?></div><a href="<?= h(u('announcements', ['c' => $cid])) ?>">All announcements →</a></article>
      <?php endforeach; ?>
      <?= modules_html($cid) ?>
    </div>
    <aside class="rail">
      <?php if ($staff): ?>
        <?= staff_rail($cid) ?>
      <?php else: ?>
        <h3>Coming up</h3><?= todo_list(todo_items($cid, me()['id'])) ?>
        <p><a class="btn block" href="<?= h(u('grades', ['c' => $cid])) ?>">View grades</a></p>
      <?php endif; ?>
    </aside>
  </div>
  <?php layout($course['title'], ob_get_clean(), ['course' => $cid, 'active' => 'course']);
}

function r_modules() {
  $cid = safe_id(get('c'));
  $course = require_course($cid);
  ob_start();
  echo '<div class="head-row"><h1>Modules</h1>' . (staff($cid) ? '<div class="row">' . post_btn(u('module_save', ['c' => $cid]), icon('plus') . ' Add module', 'btn', ['new' => 1]) . '</div>' : '') . '</div>';
  echo modules_html($cid);
  if (staff($cid)) echo unplaced_html($cid);
  layout('Modules', ob_get_clean(), ['course' => $cid, 'active' => 'modules']);
}

// Instructor-only list of content that sits in no module.
function unplaced_html($cid) {
  $c = content($cid); $tz = course($cid)['tz']; $rows = '';
  foreach (TYPE_STORE as $type => $store) foreach ($c[$store] as $id => $o) {
    if (placements($c, $type, $id)) continue;
    $state = empty($o['published']) && isset($o['published']) ? '<span class="pill">hidden</span>' : '<span class="pill warn">visible to students' . (!empty($o['unlock_at']) ? ' from ' . h(fmt_dt($o['unlock_at'], $tz)) : '') . '</span>';
    $rows .= '<li class="item"><span class="ti t-' . h($type) . '">' . icon($type) . '</span><div class="item-main"><a href="' . h(u('edit', ['c' => $cid, 't' => $type, 'id' => $id])) . '">' . h($o['title']) . '</a><div class="item-meta"><span class="muted">' . h(TYPES[$type]) . '</span>' . $state . '</div></div></li>';
  }
  if ($rows === '') return '';
  return '<section class="module unpub" style="margin-top:16px"><header class="mod-head"><h2>Not in any module</h2><span class="muted small">Only you see this list. Open an item to put it in a module.</span></header><ul class="items">' . $rows . '</ul></section>';
}

// Item status text for a student (submitted / score).
function item_status($cid, $type, $ref, $uid) {
  if (!in_array($type, ['assignment', 'quiz', 'discussion'], true)) return '';
  $g = gradables($cid)[$ref] ?? null;
  if (!$g) return '';
  $r = grade_row(course($cid), $g, get_sub($cid, $ref, $uid), false);
  if ($r['status'] === 'Graded') return '<span class="pill ok">' . fmt_num($r['score']) . ' / ' . fmt_num($g['points']) . '</span>';
  if ($r['status'] === 'Missing') return '<span class="pill err">Missing</span>';
  if ($r['status'] === 'Excused') return '<span class="pill">Excused</span>';
  if (str_starts_with($r['status'], 'Submitted')) return '<span class="pill ok">' . icon('check') . ' ' . h($r['status']) . '</span>';
  return '';
}

function modules_html($cid) {
  $course = course($cid);
  $content = content($cid);
  $staff = staff($cid);
  $uid = me()['id'];
  $tz = $course['tz'];
  if (!$content['modules']) {
    return empty_state($staff ? 'No modules yet. Add a module, or import a Canvas course from your dashboard.' : 'Nothing has been posted yet.');
  }
  $o = '<div class="modules">';
  $nm = count($content['modules']);
  foreach ($content['modules'] as $mi => $m) {
    if (!$staff && empty($m['published'])) continue;
    $open = module_open($m);
    $cls = 'module' . ($open ? '' : ' locked') . (empty($m['published']) ? ' unpub' : '');
    $o .= '<section class="' . $cls . '" id="' . h($m['id']) . '"><header class="mod-head"><h2>' . h($m['title']) . '</h2><div class="mod-meta">';
    if (!empty($m['unlock_at'])) $o .= '<span class="chip' . ($open ? '' : ' warn') . '">' . icon($open ? 'clock' : 'lock') . ($open ? 'Opened ' : 'Opens ') . h(fmt_dt($m['unlock_at'], $tz)) . '</span>';
    if ($staff) {
      $o .= '<span class="tools">'
        . post_btn(u('module_save', ['c' => $cid]), icon(!empty($m['published']) ? 'eye' : 'eyeoff'), 'icon-btn' . (!empty($m['published']) ? ' on' : ''), ['id' => $m['id'], 'toggle' => 1], !empty($m['published']) ? 'Published (click to hide)' : 'Hidden (click to publish)')
        . ($mi > 0 ? post_btn(u('module_save', ['c' => $cid]), icon('up'), 'icon-btn', ['id' => $m['id'], 'move' => -1], 'Move up') : '')
        . ($mi < $nm - 1 ? post_btn(u('module_save', ['c' => $cid]), icon('down'), 'icon-btn', ['id' => $m['id'], 'move' => 1], 'Move down') : '')
        . '<a class="icon-btn" href="' . h(u('module_edit', ['c' => $cid, 'id' => $m['id']])) . '" title="Edit module" aria-label="Edit module">' . icon('edit') . '</a>'
        . '</span>';
    }
    $o .= '</div></header><ul class="items">';
    $ni = count($m['items']);
    foreach ($m['items'] as $ii => $it) {
      if (!$staff && empty($it['published'])) continue;
      $indent = min(3, (int)($it['indent'] ?? 0));
      if ($it['type'] === 'header') {
        $o .= '<li class="sub ' . (empty($it['published']) ? 'unpub' : '') . '"><span>' . h($it['title']) . '</span>' . ($staff ? item_tools($cid, $m, $it, $ii, $ni) : '') . '</li>';
        continue;
      }
      $ok = item_open($m, $it);
      $rel = item_release($m, $it);
      $o2 = obj($cid, $it['type'], $it['ref']);
      $meta = '';
      if ($o2 && in_array($it['type'], ['assignment', 'quiz', 'discussion'], true)) {
        if (!empty($o2['due_at'])) $meta .= '<span class="due">Due ' . h(fmt_dt($o2['due_at'], $tz)) . '</span>';
        if ((float)($o2['points'] ?? 0) > 0) $meta .= '<span class="muted">' . fmt_num($o2['points']) . ' pts</span>';
      }
      if (!empty($it['unlock_at']) && $it['unlock_at'] > (int)($m['unlock_at'] ?? 0)) $meta .= '<span class="chip' . ($ok ? '' : ' warn') . '">' . icon($ok ? 'clock' : 'lock') . ($it['unlock_at'] > now() ? 'Opens ' : 'Opened ') . h(fmt_dt($it['unlock_at'], $tz)) . '</span>';
      if ($it['type'] === 'video' && $o2 && empty($o2['url'])) $meta .= $staff ? '<span class="pill warn">No video yet</span>' : '';
      $status = !$staff && $ok ? item_status($cid, $it['type'], $it['ref'], $uid) : '';
      $label = h($it['title']);
      $href = item_url($cid, $it['type'], $it['ref']);
      $ext = $it['type'] === 'link' ? ' target="_blank" rel="noopener"' : '';
      $o .= '<li class="item ind' . $indent . ($ok || $staff ? '' : ' locked') . (empty($it['published']) ? ' unpub' : '') . '">'
        . '<span class="ti t-' . h($it['type']) . '">' . icon($ok || $staff ? $it['type'] : 'lock') . '</span>'
        . '<div class="item-main">' . (($ok || $staff) ? '<a href="' . h($href) . '"' . $ext . '>' . $label . ($it['type'] === 'link' ? ' ↗' : '') . '</a>' : '<span>' . $label . '</span>')
        . '<div class="item-meta">' . $meta . $status . '</div></div>'
        . ($staff ? item_tools($cid, $m, $it, $ii, $ni) : '') . '</li>';
    }
    if (!$m['items']) $o .= '<li class="item muted"><em>No items yet.</em></li>';
    $o .= '</ul>';
    if ($staff) $o .= add_item_bar($cid, $m['id']);
    $o .= '</section>';
  }
  return $o . '</div>';
}

// Previous/next item navigation inside module order.
function neighbors($cid, $type, $ref) {
  $flat = [];
  $staff = staff($cid);
  foreach (content($cid)['modules'] as $m) foreach ($m['items'] as $it) {
    if ($it['type'] === 'header') continue;
    if (!$staff && !item_open($m, $it)) continue;
    $flat[] = $it;
  }
  foreach ($flat as $i => $it) if ($it['type'] === $type && $it['ref'] === $ref) return [$flat[$i - 1] ?? null, $flat[$i + 1] ?? null];
  return [null, null];
}
function nav_footer($cid, $type, $ref) {
  [$p, $n] = neighbors($cid, $type, $ref);
  if (!$p && !$n) return '';
  $o = '<nav class="pn">';
  $o .= $p ? '<a class="btn" href="' . h(item_url($cid, $p['type'], $p['ref'])) . '">← ' . h($p['title']) . '</a>' : '<span></span>';
  $o .= $n ? '<a class="btn" href="' . h(item_url($cid, $n['type'], $n['ref'])) . '">' . h($n['title']) . ' →</a>' : '<span></span>';
  return $o . '</nav>';
}

function edit_link($cid, $type, $id) {
  return staff($cid) ? '<a class="btn sm" href="' . h(u('edit', ['c' => $cid, 't' => $type, 'id' => $id])) . '">' . icon('edit') . ' Edit</a>' : '';
}

function r_page() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  require_course($cid);
  $p = require_access($cid, 'page', $id);
  ob_start(); ?>
  <div class="head-row"><h1><?= h($p['title']) ?></h1><?= edit_link($cid, 'page', $id) ?></div>
  <?php if (staff($cid) && preg_match_all('~\[[^\]<]{0,60}\blink\]~i', $p['body'], $mm)): ?><div class="flash warn">This page still has <?= count($mm[0]) ?> placeholder(s) like <code><?= h($mm[0][0]) ?></code>. Edit the page to replace them; students see these as written.</div><?php endif; ?>
  <article class="prose"><?= $p['body'] ?></article>
  <?= nav_footer($cid, 'page', $id) ?>
  <?php layout($p['title'], ob_get_clean(), ['course' => $cid, 'active' => 'modules']);
}

function r_video() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  require_course($cid);
  $v = require_access($cid, 'video', $id);
  ob_start(); ?>
  <div class="head-row"><h1><?= h($v['title']) ?></h1><?= edit_link($cid, 'video', $id) ?></div>
  <?php if (!empty($v['duration'])): ?><p class="muted"><?= icon('clock') ?> <?= h($v['duration']) ?></p><?php endif; ?>
  <?= !empty($v['url']) ? video_embed($v['url'], $v['title']) : empty_state(staff($cid) ? 'No video added yet. Click Edit to paste a link or upload a file.' : 'This video hasn’t been posted yet.') ?>
  <?php if (!empty($v['body'])): ?><article class="prose"><?= $v['body'] ?></article><?php endif; ?>
  <?php if (!empty($v['slides'])): ?><p><a class="btn" href="<?= h($v['slides']) ?>" target="_blank" rel="noopener">Slides ↗</a></p><?php endif; ?>
  <?= nav_footer($cid, 'video', $id) ?>
  <?php layout($v['title'], ob_get_clean(), ['course' => $cid, 'active' => 'modules']);
}

function r_link() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  require_course($cid);
  $l = require_access($cid, 'link', $id);
  $url = $l['url'] ?? '';
  if (!preg_match('~^(https?:|mailto:)~i', $url)) throw new HttpError('This link is not set up yet.');
  redirect($url);
}

function r_file() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  require_course($cid);
  $f = require_access($cid, 'file', $id);
  $base = realpath(course_files_dir($cid));
  $p = $base ? realpath($base . '/' . safe_rel_path($f['path'])) : false;
  if (!$p || !str_starts_with($p, $base . DIRECTORY_SEPARATOR)) throw new HttpError('File missing.');
  send_file($p, basename($p), true);
}

function r_syllabus() {
  $cid = safe_id(get('c'));
  $course = require_course($cid);
  $c = content($cid);
  ob_start(); ?>
  <div class="head-row"><h1>Syllabus</h1><?= staff($cid) ? '<a class="btn sm" href="' . h(u('edit', ['c' => $cid, 't' => 'syllabus'])) . '">' . icon('edit') . ' Edit</a>' : '' ?></div>
  <article class="prose"><?= $c['syllabus'] ?: '<p class="muted">No syllabus posted.</p>' ?></article>
  <h2>Course schedule</h2>
  <?= schedule_table($cid) ?>
  <?php layout('Syllabus', ob_get_clean(), ['course' => $cid, 'active' => 'syllabus']);
}

function schedule_table($cid) {
  $tz = course($cid)['tz'];
  $rows = [];
  foreach (gradables($cid) as $g) {
    if (!staff($cid)) { $a = access($cid, $g['type'], $g['id']); if (!$a['ok'] && !$a['release']) continue; }
    $rows[] = $g;
  }
  usort($rows, function ($a, $b) { return ($a['due_at'] ?: PHP_INT_MAX) <=> ($b['due_at'] ?: PHP_INT_MAX); });
  if (!$rows) return '<p class="muted">No graded work yet.</p>';
  $o = '<table class="tbl"><thead><tr><th>Due</th><th>Item</th><th class="num">Points</th></tr></thead><tbody>';
  foreach ($rows as $g) $o .= '<tr><td>' . ($g['due_at'] ? h(fmt_dt($g['due_at'], $tz)) : '—') . '</td><td><a href="' . h(item_url($cid, $g['type'], $g['id'])) . '">' . h($g['title']) . '</a></td><td class="num">' . fmt_num($g['points']) . '</td></tr>';
  return $o . '</tbody></table>';
}

function r_announcements() {
  $cid = safe_id(get('c'));
  $course = require_course($cid);
  $staff = staff($cid);
  $list = visible_announcements($cid);
  ob_start(); ?>
  <div class="head-row"><h1>Announcements</h1><?= $staff ? '<a class="btn primary" href="' . h(u('edit', ['c' => $cid, 't' => 'announcement'])) . '">' . icon('plus') . ' New announcement</a>' : '' ?></div>
  <?php if (!$list) echo empty_state('No announcements yet.'); ?>
  <?php foreach ($list as $a): $sched = (int)($a['post_at'] ?? 0) > now(); ?>
    <article class="card ann">
      <div class="muted small"><?= $sched ? '<span class="pill warn">Scheduled for ' . h(fmt_dt($a['post_at'], $course['tz'])) . '</span>' : h(fmt_dt(max($a['post_at'] ?? 0, $a['created']), $course['tz'])) ?></div>
      <h2><?= h($a['title']) ?></h2><div class="prose"><?= $a['body'] ?></div>
      <?php if ($staff): ?><div class="row"><a class="btn sm" href="<?= h(u('edit', ['c' => $cid, 't' => 'announcement', 'id' => $a['id']])) ?>">Edit</a><?= confirm_btn(u('delete_obj', ['c' => $cid]), 'Delete', 'Delete this announcement?', 'btn sm danger', ['t' => 'announcement', 'id' => $a['id']]) ?></div><?php endif; ?>
    </article>
  <?php endforeach; ?>
  <?php layout('Announcements', ob_get_clean(), ['course' => $cid, 'active' => 'announcements']);
}

// ======================= assignments =======================

function r_assignments() {
  $cid = safe_id(get('c'));
  $course = require_course($cid);
  $staff = staff($cid);
  $uid = me()['id'];
  $gs = gradables($cid);
  ob_start();
  echo '<div class="head-row"><h1>Assignments</h1>' . ($staff ? '<span class="muted small">Add assignments, quizzes and graded discussions from the Modules page so they have a place and release date.</span>' : '') . '</div>';
  $any = false;
  foreach ($course['groups'] as $gp) {
    $rows = array_filter($gs, function ($g) use ($gp, $course) { return ($g['group'] ?: $course['groups'][0]['id']) === $gp['id'] || (!in_array($g['group'], array_column($course['groups'], 'id'), true) && $gp === $course['groups'][0]); });
    $shown = '';
    foreach ($rows as $g) {
      $a = access($cid, $g['type'], $g['id']);
      if (!$staff && !$a['ok']) continue;
      $right = $staff ? (($n = needs_grading_count($cid, $g['id'])) ? '<a class="pill warn" href="' . h(u('grading', ['c' => $cid, 'id' => $g['id']])) . '">' . $n . ' to grade</a>' : '') : item_status($cid, $g['type'], $g['id'], $uid);
      $shown .= '<li class="item"><span class="ti t-' . $g['type'] . '">' . icon($g['type']) . '</span><div class="item-main"><a href="' . h(item_url($cid, $g['type'], $g['id'])) . '">' . h($g['title']) . '</a><div class="item-meta">'
        . ($g['due_at'] ? '<span class="due">Due ' . h(fmt_dt($g['due_at'], $course['tz'])) . '</span>' : '') . '<span class="muted">' . fmt_num($g['points']) . ' pts</span>' . $right . '</div></div></li>';
    }
    if ($shown === '') continue;
    $any = true;
    echo '<section class="module"><header class="mod-head"><h2>' . h($gp['title']) . '</h2>' . ($course['weighted'] ? '<span class="chip">' . fmt_num($gp['weight']) . '% of grade</span>' : '') . '</header><ul class="items">' . $shown . '</ul></section>';
  }
  if (!$any) echo empty_state('No assignments yet.');
  layout('Assignments', ob_get_clean(), ['course' => $cid, 'active' => 'assignments']);
}

const SUB_LABEL = ['upload' => 'File upload', 'text' => 'Text entry', 'url' => 'Website or video link', 'none' => 'No online submission'];

function r_assignment() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  $course = require_course($cid);
  $a = require_access($cid, 'assignment', $id);
  $staff = staff($cid);
  $me = me();
  $tz = $course['tz'];
  $g = gradables($cid)[$id];
  $types = $a['sub_types'] ?? ['upload'];
  $canSubmitType = !in_array('none', $types, true) && $types;
  $locked = !empty($a['lock_at']) && now() > $a['lock_at'];
  $sub = get_sub($cid, $id, $me['id']);
  $maxAtt = (int)($a['attempts'] ?? 0);
  $attLeft = $maxAtt ? $maxAtt - count($sub['attempts']) : PHP_INT_MAX;

  if (is_post() && !$staff) {
    if (previewing($cid)) { flash('Submitting is disabled in student view.', 'warn'); redirect(u('assignment', ['c' => $cid, 'id' => $id])); }
    if (post('comment') !== '') {
      $text = mb_substr(post('comment'), 0, 5000);
      update_sub($cid, $id, $me['id'], function ($s) use ($text, $me) { $s['comments'][] = ['by' => $me['id'], 'at' => now(), 'text' => $text]; return $s; });
      notify_admins(h($me['name']) . ' commented on ' . h($a['title']), u('grade', ['c' => $cid, 'id' => $id, 'uid' => $me['id']]));
      flash('Comment added.');
      redirect(u('assignment', ['c' => $cid, 'id' => $id]) . '#comments');
    }
    if (!$canSubmitType || $locked || $attLeft <= 0) throw new HttpError('Submissions are closed for this assignment.');
    $att = ['at' => now(), 'files' => [], 'text' => '', 'url' => ''];
    $kind = post('kind');
    if ($kind === 'upload' && in_array('upload', $types, true)) {
      $files = uploaded_files('files');
      if (!$files) { flash('Choose at least one file.', 'err'); redirect(u('assignment', ['c' => $cid, 'id' => $id])); }
      $allowed = array_filter(array_map(function ($x) { return strtolower(trim($x, " .")); }, explode(',', $a['allowed_ext'] ?? '')));
      foreach ($files as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($allowed && !in_array($ext, $allowed, true)) { flash('Allowed file types: ' . h(implode(', ', $allowed)), 'err'); redirect(u('assignment', ['c' => $cid, 'id' => $id])); }
      }
      foreach ($files as $f) $att['files'][] = store_upload($f, "c/$cid/up/" . safe_id($id));
    } elseif ($kind === 'text' && in_array('text', $types, true)) {
      $att['text'] = mb_substr((string)($_POST['text'] ?? ''), 0, 200000);
      if (trim($att['text']) === '') { flash('Your text submission is empty.', 'err'); redirect(u('assignment', ['c' => $cid, 'id' => $id])); }
    } elseif ($kind === 'url' && in_array('url', $types, true)) {
      $att['url'] = post('url');
      if (!preg_match('~^https?://~i', $att['url'])) { flash('Enter a full link starting with https://', 'err'); redirect(u('assignment', ['c' => $cid, 'id' => $id])); }
    } else throw new HttpError('Choose a submission type.');
    if (post('note') !== '') $att['note'] = mb_substr(post('note'), 0, 2000);
    $att['late'] = !empty($a['due_at']) && now() > $a['due_at'];
    $accepted = false;
    update_sub($cid, $id, $me['id'], function ($s) use ($att, $maxAtt, &$accepted) {
      if ($maxAtt && count($s['attempts']) >= $maxAtt) return null;
      $s['attempts'][] = $att; $accepted = true; return $s;
    });
    if (!$accepted) throw new HttpError('You’ve used all your attempts for this assignment.');
    notify_admins(h($me['name']) . ' submitted ' . h($a['title']) . ($att['late'] ? ' (late)' : ''), u('grade', ['c' => $cid, 'id' => $id, 'uid' => $me['id']]));
    flash('Submitted. You can see your submission below' . ($attLeft - 1 > 0 && $attLeft !== PHP_INT_MAX ? ' (' . ($attLeft - 1) . ' attempt' . ($attLeft - 1 == 1 ? '' : 's') . ' left)' : '') . '.');
    redirect(u('assignment', ['c' => $cid, 'id' => $id]));
  }

  ob_start(); ?>
  <div class="head-row"><h1><?= h($a['title']) ?></h1><div class="row"><?= edit_link($cid, 'assignment', $id) ?><?php if ($staff): ?><a class="btn sm primary" href="<?= h(u('grading', ['c' => $cid, 'id' => $id])) ?>"><?= icon('grade') ?> Grade (<?= needs_grading_count($cid, $id) ?>)</a><?php endif; ?></div></div>
  <dl class="facts">
    <div><dt>Due</dt><dd><?= $a['due_at'] ? h(fmt_dt($a['due_at'], $tz)) : 'No due date' ?></dd></div>
    <div><dt>Points</dt><dd><?= fmt_num($a['points']) ?></dd></div>
    <div><dt>Submitting</dt><dd><?= h(implode(', ', array_map(function ($t) { return SUB_LABEL[$t] ?? $t; }, $types))) ?><?= !empty($a['allowed_ext']) ? ' <small class="muted">(' . h($a['allowed_ext']) . ')</small>' : '' ?></dd></div>
    <?php if (!empty($a['lock_at'])): ?><div><dt>Closes</dt><dd><?= h(fmt_dt($a['lock_at'], $tz)) ?></dd></div><?php endif; ?>
    <?php if ($maxAtt): ?><div><dt>Attempts</dt><dd><?= $maxAtt ?></dd></div><?php endif; ?>
  </dl>
  <div class="grid-main">
    <div>
      <article class="prose"><?= $a['body'] ?></article>
      <?php if (!empty($a['rubric'])): ?><h3>Rubric</h3><?= rubric_table($a['rubric'], null) ?><?php endif; ?>
    </div>
    <aside class="rail">
    <?php if ($staff): echo grading_summary($cid, $id); else:
      $row = grade_row($course, $g, $sub, false); ?>
      <h3>Your work</h3>
      <?php if ($row['status'] === 'Graded'): ?>
        <div class="score-big"><?= fmt_num($row['score']) ?><small>/ <?= fmt_num($g['points']) ?></small></div>
        <?php if (!empty($row['penalty'])): ?><p class="muted small">Late penalty −<?= fmt_num($row['penalty']) ?>% (raw <?= fmt_num($row['raw']) ?>)</p><?php endif; ?>
        <?php if (!empty($a['rubric']) && !empty($sub['grade']['rubric'])): ?><?= rubric_table($a['rubric'], $sub['grade']['rubric']) ?><?php endif; ?>
      <?php elseif ($row['status']): ?><p><span class="pill <?= $row['status'] === 'Missing' ? 'err' : ($row['status'] === 'Upcoming' ? '' : 'ok') ?>"><?= h($row['status']) ?></span><?= $row['pending_post'] ? ' <small class="muted">Grade not released yet.</small>' : '' ?></p>
      <?php endif; ?>
      <?php foreach (array_reverse($sub['attempts'], true) as $ai => $at): ?>
        <div class="attempt"><div class="muted small">Attempt <?= $ai + 1 ?> · <?= h(fmt_dt($at['at'], $tz)) ?><?= !empty($at['late']) ? ' <span class="pill err">Late</span>' : '' ?></div><?= attempt_html($cid, $id, $me['id'], $ai, $at) ?></div>
      <?php endforeach; ?>
      <?php if ($canSubmitType && !$locked && $attLeft > 0): ?>
        <?= submit_form($cid, $id, $a, $types, (bool)$sub['attempts']) ?>
      <?php elseif ($canSubmitType && $locked): ?><p class="muted">This assignment closed <?= h(fmt_dt($a['lock_at'], $tz)) ?>.</p>
      <?php elseif ($canSubmitType && $attLeft <= 0): ?><p class="muted">You’ve used all your attempts.</p>
      <?php elseif (!$canSubmitType): ?><p class="muted">Nothing to submit online. Your instructor will enter a grade.</p><?php endif; ?>
      <?= comments_html($cid, $id, $sub, u('assignment', ['c' => $cid, 'id' => $id])) ?>
    <?php endif; ?>
    </aside>
  </div>
  <?= nav_footer($cid, 'assignment', $id) ?>
  <?php layout($a['title'], ob_get_clean(), ['course' => $cid, 'active' => 'assignments']);
}

function submit_form($cid, $id, $a, $types, $resubmit) {
  $o = '<div class="card submit-box"><h4>' . ($resubmit ? 'Resubmit' : 'Submit') . '</h4>';
  if (count($types) > 1) {
    $o .= '<div class="tabs" role="tablist">';
    foreach ($types as $i => $t) $o .= '<button type="button" class="tab' . ($i ? '' : ' on') . '" data-tab="sub-' . $t . '">' . h(SUB_LABEL[$t]) . '</button>';
    $o .= '</div>';
  }
  foreach ($types as $i => $t) {
    $o .= '<form method="post" enctype="multipart/form-data" class="stack tabpane' . ($i ? ' hidden' : '') . '" id="sub-' . $t . '">' . csrf_field() . '<input type="hidden" name="kind" value="' . $t . '">';
    if ($t === 'upload') {
      $acc = implode(',', array_map(function ($e) { return '.' . trim($e, ' .'); }, array_filter(explode(',', $a['allowed_ext'] ?? ''))));
      $o .= '<label>Files <input type="file" name="files[]" multiple required' . ($acc ? ' accept="' . h($acc) . '"' : '') . '><small>Up to ' . fmt_size(upload_limit()) . ' in total.</small></label>';
    } elseif ($t === 'text') {
      $o .= '<label>Your response <textarea name="text" rows="10" required></textarea></label>';
    } elseif ($t === 'url') {
      $o .= '<label>Link <input type="url" name="url" placeholder="https://" required><small>For a video demonstration, paste a YouTube (unlisted), Google Drive or other share link.</small></label>';
    }
    $o .= '<label>Note to instructor <small>(optional)</small><input name="note" maxlength="2000"></label><button class="btn primary">Submit</button></form>';
  }
  return $o . '</div>';
}

function attempt_html($cid, $oid, $uid, $ai, $at) {
  $o = '';
  if (!empty($at['files'])) {
    $o .= '<ul class="files">';
    foreach ($at['files'] as $fi => $f) {
      $url = u('upfile', ['c' => $cid, 'o' => $oid, 's' => $uid, 'a' => $ai, 'i' => $fi]);
      $o .= '<li>' . icon('file') . ' <a href="' . h($url) . '" target="_blank">' . h($f['name']) . '</a> <small class="muted">' . fmt_size($f['size']) . '</small></li>';
    }
    $o .= '</ul>';
  }
  if (!empty($at['text'])) $o .= '<div class="subtext">' . user_text($at['text']) . '</div>';
  if (!empty($at['url'])) $o .= '<p>' . icon('link') . ' <a href="' . h($at['url']) . '" target="_blank" rel="noopener">' . h($at['url']) . '</a></p>' . video_embed($at['url'], 'Submission', true);
  if (!empty($at['note'])) $o .= '<p class="muted small">Note: ' . h($at['note']) . '</p>';
  if (!empty($at['quiz'])) $o .= '<p class="small">Quiz attempt ' . (int)$at['quiz'] . '</p>';
  return $o;
}

function comments_html($cid, $oid, $sub, $action, $staffView = false) {
  $users = users();
  $o = '<div class="comments" id="comments"><h4>Feedback &amp; comments</h4>';
  $posted = $staffView || grade_posted($sub);
  $shown = 0;
  foreach ($sub['comments'] as $cm) {
    $isStaff = ($users[$cm['by']]['role'] ?? '') === 'admin';
    if ($isStaff && !$posted && !empty($cm['hold'])) continue;
    $shown++;
    $o .= '<div class="cmt' . ($isStaff ? ' staff' : '') . '"><div class="muted small"><b>' . h($users[$cm['by']]['name'] ?? 'Someone') . '</b> · ' . h(rel_time($cm['at'])) . '</div>' . user_text($cm['text']) . '</div>';
  }
  if (!$shown) $o .= '<p class="muted small">No comments yet.</p>';
  if (!$staffView) $o .= '<form method="post" action="' . h($action) . '" class="stack">' . csrf_field() . '<textarea name="comment" rows="2" placeholder="Ask a question or reply to feedback" required></textarea><button class="btn sm">Add comment</button></form>';
  return $o . '</div>';
}

function rubric_table($rubric, $scores, $editable = false) {
  $o = '<table class="tbl rubric"><thead><tr><th>Criterion</th><th class="num">Points</th></tr></thead><tbody>';
  $tot = 0; $max = 0;
  foreach ($rubric as $k) {
    $max += (float)$k['points'];
    $val = $scores[$k['id']] ?? null;
    if (is_array($val)) { $cmt = $val['c'] ?? ''; $val = $val['p'] ?? null; } else $cmt = '';
    if ($val !== null && $val !== '') $tot += (float)$val;
    $o .= '<tr><td><b>' . h($k['title']) . '</b>' . (!empty($k['desc']) ? '<div class="muted small">' . nl2br(h($k['desc'])) . '</div>' : '')
      . ($editable ? '<input class="rub-cmt" name="rc[' . h($k['id']) . ']" value="' . h($cmt) . '" placeholder="Comment on this criterion (optional)">' : ($cmt !== '' ? '<div class="small rub-c">' . user_text($cmt) . '</div>' : '')) . '</td>'
      . '<td class="num">' . ($editable ? '<input class="rub-pts" type="number" step="any" min="0" max="' . h($k['points']) . '" name="rp[' . h($k['id']) . ']" value="' . h($val ?? '') . '">' : ($scores !== null ? fmt_num($val) . ' / ' : '')) . ' ' . ($editable ? '/ ' : '') . fmt_num($k['points']) . '</td></tr>';
  }
  $o .= '</tbody><tfoot><tr><th>Total</th><th class="num">' . ($scores !== null || $editable ? '<span class="rub-total">' . fmt_num($tot) . '</span> / ' : '') . fmt_num($max) . '</th></tr></tfoot></table>';
  return $o;
}

// ======================= discussions =======================

function r_discussions() {
  $cid = safe_id(get('c'));
  $course = require_course($cid);
  $staff = staff($cid);
  $list = [];
  foreach (content($cid)['modules'] as $m) foreach ($m['items'] as $it) if ($it['type'] === 'discussion') $list[$it['ref']] = 1;
  foreach (content($cid)['discussions'] as $id => $d) $list[$id] = 1;
  ob_start();
  echo '<h1>Discussions</h1><ul class="items card">';
  $n = 0;
  foreach (array_keys($list) as $id) {
    $d = obj($cid, 'discussion', $id); if (!$d) continue;
    $a = access($cid, 'discussion', $id);
    if (!$staff && !$a['ok']) continue;
    $n++;
    $posts = posts($cid, $id);
    $last = $posts ? max(array_column($posts, 'at')) : 0;
    echo '<li class="item"><span class="ti t-discussion">' . icon('discussion') . '</span><div class="item-main"><a href="' . h(u('discussion', ['c' => $cid, 'id' => $id])) . '">' . h($d['title']) . '</a><div class="item-meta"><span class="muted">' . count($posts) . ' post' . (count($posts) == 1 ? '' : 's') . ($last ? ', latest ' . h(rel_time($last)) : '') . '</span>' . ((float)$d['points'] > 0 ? '<span class="muted">' . fmt_num($d['points']) . ' pts</span>' : '') . (!empty($d['due_at']) ? '<span class="due">Due ' . h(fmt_dt($d['due_at'], $course['tz'])) . '</span>' : '') . '</div></div></li>';
  }
  echo '</ul>';
  if (!$n) echo empty_state('No discussions are open yet.');
  layout('Discussions', ob_get_clean(), ['course' => $cid, 'active' => 'discussions']);
}

function r_discussion() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  $course = require_course($cid);
  $d = require_access($cid, 'discussion', $id);
  $staff = staff($cid);
  $me = me();
  $locked = !empty($d['locked']) || (!empty($d['lock_at']) && now() > $d['lock_at']);
  $path = "c/$cid/disc/$id";

  if (is_post()) {
    if (previewing($cid)) { flash('Posting is disabled in student view.', 'warn'); redirect(u('discussion', ['c' => $cid, 'id' => $id])); }
    if (post('del')) {
      $pid = post('del');
      jupdate($path, function ($x) use ($pid, $staff, $me) {
        foreach ($x['posts'] as &$p) if ($p['id'] === $pid && ($staff || $p['uid'] === $me['id'])) { $p['deleted'] = true; $p['body'] = ''; }
        return $x;
      }, ['posts' => []]);
      redirect(u('discussion', ['c' => $cid, 'id' => $id]));
    }
    if ($locked && !$staff) throw new HttpError('This discussion is closed.');
    $body = mb_substr((string)($_POST['body'] ?? ''), 0, 20000);
    if (trim($body) === '') redirect(u('discussion', ['c' => $cid, 'id' => $id]));
    $parent = safe_id(post('parent'));
    $edit = safe_id(post('edit'));
    $parentAuthor = null;
    jupdate($path, function ($x) use ($body, $parent, $edit, $me, $staff, &$parentAuthor) {
      if ($edit) { foreach ($x['posts'] as &$p) if ($p['id'] === $edit && ($p['uid'] === $me['id'])) { $p['body'] = $body; $p['edited'] = now(); } return $x; }
      foreach ($x['posts'] as $p) if ($p['id'] === $parent) $parentAuthor = $p['uid'];
      $x['posts'][] = ['id' => newid('p'), 'parent' => $parent ?: null, 'uid' => $me['id'], 'body' => $body, 'at' => now()];
      return $x;
    }, ['posts' => []]);
    if (!$edit) {
      $link = u('discussion', ['c' => $cid, 'id' => $id]);
      if ($parentAuthor && $parentAuthor !== $me['id']) notify($parentAuthor, h($me['name']) . ' replied to you in ' . h($d['title']), $link);
      if (!$staff && (float)$d['points'] > 0) update_sub($cid, $id, $me['id'], function ($s) { $s['attempts'][] = ['at' => now(), 'post' => true]; return $s; });
    }
    redirect(u('discussion', ['c' => $cid, 'id' => $id]) . '#end');
  }

  $posts = posts($cid, $id);
  $hasPosted = false; foreach ($posts as $p) if ($p['uid'] === $me['id'] && empty($p['deleted'])) $hasPosted = true;
  $hide = !$staff && !empty($d['require_initial']) && !$hasPosted;
  $kids = [];
  foreach ($posts as $p) $kids[$p['parent'] ?: '_'][] = $p;
  $users = users();
  $render = function ($pid, $depth) use (&$render, $kids, $users, $me, $staff, $cid, $id, $locked, $course) {
    $o = '';
    foreach ($kids[$pid] ?? [] as $p) {
      $u = $users[$p['uid']] ?? ['name' => 'Former student', 'role' => 'student'];
      $mine = $p['uid'] === $me['id'];
      $o .= '<div class="post' . (($u['role'] ?? '') === 'admin' ? ' staff' : '') . '" id="' . h($p['id']) . '"><div class="post-head"><span class="avatar">' . h(initials($u['name'])) . '</span><b>' . h($u['name']) . '</b>' . (($u['role'] ?? '') === 'admin' ? ' <span class="pill">Instructor</span>' : '') . ' <span class="muted small">' . h(fmt_dt($p['at'], $course['tz'])) . (!empty($p['edited']) ? ' · edited' : '') . '</span></div>';
      $o .= !empty($p['deleted']) ? '<p class="muted"><em>Post removed.</em></p>' : '<div class="post-body">' . user_text($p['body']) . '</div>';
      if (empty($p['deleted'])) {
        $o .= '<div class="post-actions">';
        if (!$locked || $staff) $o .= '<details class="reply"><summary>Reply</summary><form method="post" class="stack">' . csrf_field() . '<input type="hidden" name="parent" value="' . h($p['id']) . '"><textarea name="body" rows="3" required></textarea><button class="btn sm primary">Post reply</button></form></details>';
        if ($mine && !$locked) $o .= '<details class="reply"><summary>Edit</summary><form method="post" class="stack">' . csrf_field() . '<input type="hidden" name="edit" value="' . h($p['id']) . '"><textarea name="body" rows="3" required>' . h($p['body']) . '</textarea><button class="btn sm">Save</button></form></details>';
        if ($mine || $staff) $o .= confirm_btn(u('discussion', ['c' => $cid, 'id' => $id]), 'Delete', 'Remove this post?', 'linkish', ['del' => $p['id']]);
        $o .= '</div>';
      }
      if (!empty($kids[$p['id']])) $o .= '<div class="replies">' . $render($p['id'], $depth + 1) . '</div>';
      $o .= '</div>';
    }
    return $o;
  };

  ob_start(); ?>
  <div class="head-row"><h1><?= h($d['title']) ?></h1><div class="row"><?= edit_link($cid, 'discussion', $id) ?><?php if ($staff && (float)$d['points'] > 0): ?><a class="btn sm primary" href="<?= h(u('grading', ['c' => $cid, 'id' => $id])) ?>"><?= icon('grade') ?> Grade</a><?php endif; ?></div></div>
  <?php if ((float)$d['points'] > 0 || !empty($d['due_at'])): ?><dl class="facts"><?php if ((float)$d['points'] > 0): ?><div><dt>Points</dt><dd><?= fmt_num($d['points']) ?></dd></div><?php endif; ?><?php if (!empty($d['due_at'])): ?><div><dt>Due</dt><dd><?= h(fmt_dt($d['due_at'], $course['tz'])) ?></dd></div><?php endif; ?></dl><?php endif; ?>
  <article class="prose prompt"><?= $d['body'] ?></article>
  <?php if (!$staff && (float)$d['points'] > 0) echo '<p>' . item_status($cid, 'discussion', $id, $me['id']) . '</p>'; ?>
  <?php if ($locked): ?><div class="flash warn">This discussion is closed to new posts.</div><?php endif; ?>
  <?php if (!$locked || $staff): ?>
  <form method="post" class="card stack newpost"><?= csrf_field() ?><label><?= $hasPosted ? 'Add another post' : 'Your post' ?><textarea name="body" rows="5" required></textarea></label><button class="btn primary">Post</button></form>
  <?php endif; ?>
  <h2><?= count(array_filter($posts, function ($p) { return empty($p['deleted']); })) ?> posts</h2>
  <?= $hide ? empty_state('Post your own response first; then you’ll see your classmates’ posts.') : ($render('_', 0) ?: empty_state('No posts yet. Start the conversation.')) ?>
  <div id="end"></div>
  <?= nav_footer($cid, 'discussion', $id) ?>
  <?php layout($d['title'], ob_get_clean(), ['course' => $cid, 'active' => 'discussions']);
}

// ======================= quizzes =======================

function r_quiz() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  $course = require_course($cid);
  $q = require_access($cid, 'quiz', $id);
  $staff = staff($cid);
  $me = me();
  $tz = $course['tz'];
  $atts = quiz_attempts($cid, $id, $me['id']);
  $max = (int)($q['attempts'] ?? 1);
  $submitted = array_filter($atts, function ($a) { return !empty($a['submitted']); });
  $open = null; foreach ($atts as $i => $a) if (empty($a['submitted'])) $open = $i;
  $locked = !empty($q['lock_at']) && now() > $q['lock_at'];
  $canStart = !$staff && !$locked && $open === null && (!$max || count($submitted) < $max) && !empty($q['questions']);

  if (is_post() && !$staff) {
    if (previewing($cid)) { flash('Quizzes can’t be taken in student view. Use “Preview quiz” as instructor.', 'warn'); redirect(u('quiz', ['c' => $cid, 'id' => $id])); }
    if ($open !== null) redirect(u('quiz_take', ['c' => $cid, 'id' => $id]));
    if (!$canStart) throw new HttpError('You can’t start another attempt.');
    $order = array_column($q['questions'], 'id');
    $choiceOrder = [];
    if (!empty($q['shuffle'])) foreach ($q['questions'] as $qq) if (!empty($qq['choices']) && $qq['type'] !== 'tf') { $ids = array_column($qq['choices'], 'id'); shuffle($ids); $choiceOrder[$qq['id']] = $ids; }
    jupdate(qa_path($cid, $id, $me['id']), function ($x) use ($order, $choiceOrder, $max) {
      // Re-check under the lock so two browsers can't start extra attempts at once.
      $done = 0;
      foreach ($x['attempts'] as $a) { if (empty($a['submitted'])) return null; $done++; }
      if ($max && $done >= $max) return null;
      $x['attempts'][] = ['started' => now(), 'order' => $order, 'corder' => $choiceOrder, 'answers' => [], 'submitted' => 0];
      return $x;
    }, ['attempts' => []]);
    redirect(u('quiz_take', ['c' => $cid, 'id' => $id]));
  }

  ob_start(); ?>
  <div class="head-row"><h1><?= h($q['title']) ?></h1><div class="row"><?= edit_link($cid, 'quiz', $id) ?><?php if ($staff): ?><a class="btn sm" href="<?= h(u('quiz_take', ['c' => $cid, 'id' => $id, 'preview' => 1])) ?>"><?= icon('eye') ?> Preview quiz</a><a class="btn sm primary" href="<?= h(u('grading', ['c' => $cid, 'id' => $id])) ?>"><?= icon('grade') ?> Results</a><?php endif; ?></div></div>
  <dl class="facts">
    <div><dt>Due</dt><dd><?= !empty($q['due_at']) ? h(fmt_dt($q['due_at'], $tz)) : 'No due date' ?></dd></div>
    <div><dt>Points</dt><dd><?= fmt_num(quiz_points($q)) ?></dd></div>
    <div><dt>Questions</dt><dd><?= count(array_filter($q['questions'], function ($x) { return $x['type'] !== 'text'; })) ?></dd></div>
    <div><dt>Time limit</dt><dd><?= !empty($q['time_limit']) ? (int)$q['time_limit'] . ' min' : 'None' ?></dd></div>
    <div><dt>Attempts</dt><dd><?= $max ?: 'Unlimited' ?></dd></div>
    <?php if (!empty($q['lock_at'])): ?><div><dt>Closes</dt><dd><?= h(fmt_dt($q['lock_at'], $tz)) ?></dd></div><?php endif; ?>
  </dl>
  <article class="prose"><?= $q['body'] ?? '' ?></article>
  <?php if ($staff): echo grading_summary($cid, $id); else: ?>
    <?php if ($open !== null): ?><p><a class="btn primary" href="<?= h(u('quiz_take', ['c' => $cid, 'id' => $id])) ?>">Resume attempt</a></p>
    <?php elseif ($canStart): ?><form method="post"><?= csrf_field() ?><button class="btn primary"><?= $submitted ? 'Take again' : 'Start quiz' ?></button><?= !empty($q['time_limit']) ? ' <span class="muted">The timer starts when you click.</span>' : '' ?></form>
    <?php elseif ($locked): ?><p class="muted">This quiz closed <?= h(fmt_dt($q['lock_at'], $tz)) ?>.</p>
    <?php elseif ($max && count($submitted) >= $max): ?><p class="muted">You’ve used all your attempts.</p><?php endif; ?>
    <?php if ($submitted): $sub = get_sub($cid, $id, $me['id']); $posted = grade_posted($sub); ?>
      <h2>Your attempts</h2><table class="tbl"><thead><tr><th>Attempt</th><th>Submitted</th><th class="num">Score</th><th></th></tr></thead><tbody>
      <?php foreach ($atts as $i => $a) if (!empty($a['submitted'])): ?>
        <tr><td><?= $i + 1 ?></td><td><?= h(fmt_dt($a['submitted'], $tz)) ?></td><td class="num"><?= $posted ? fmt_num($a['score']) . ' / ' . fmt_num(quiz_points($q)) . (!empty($a['needs_manual']) ? ' <small class="muted">(some answers still being graded)</small>' : '') : '<span class="muted">Not released</span>' ?></td><td><a href="<?= h(u('quiz_take', ['c' => $cid, 'id' => $id, 'view' => $i])) ?>">Review</a></td></tr>
      <?php endif; ?></tbody></table>
      <?= comments_html($cid, $id, $sub, u('assignment_comment', ['c' => $cid, 'id' => $id])) ?>
    <?php endif; ?>
  <?php endif; ?>
  <?= nav_footer($cid, 'quiz', $id) ?>
  <?php layout($q['title'], ob_get_clean(), ['course' => $cid, 'active' => 'assignments']);
}

// Comments on non-assignment gradables (quizzes) post here.
function r_assignment_comment() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  require_course($cid);
  $me = me();
  $g = gradables($cid)[$id] ?? null;
  if (!$g) throw new HttpError('Not found.');
  require_access($cid, $g['type'], $id);
  if (is_post() && post('comment') !== '' && !previewing($cid) && !staff($cid)) {
    $text = mb_substr(post('comment'), 0, 5000);
    update_sub($cid, $id, $me['id'], function ($s) use ($text, $me) { $s['comments'][] = ['by' => $me['id'], 'at' => now(), 'text' => $text]; return $s; });
    notify_admins(h($me['name']) . ' commented on ' . h($g['title']), u('grade', ['c' => $cid, 'id' => $id, 'uid' => $me['id']]));
  }
  redirect(item_url($cid, $g['type'], $id) . '#comments');
}

function score_attempt($quiz, &$att) {
  $score = 0; $needs = false; $res = [];
  foreach ($quiz['questions'] as $qq) {
    if ($qq['type'] === 'text') continue;
    $ans = $att['answers'][$qq['id']] ?? null;
    if (isset($att['manual'][$qq['id']])) { $p = (float)$att['manual'][$qq['id']]; $res[$qq['id']] = [$p, null]; $score += $p; continue; }
    [$p, $ok] = grade_answer($qq, $ans);
    if ($p === null) { $needs = $needs || ($ans !== null && trim((string)$ans) !== '') || (float)$qq['points'] > 0; $res[$qq['id']] = [null, null]; continue; }
    $res[$qq['id']] = [$p, $ok]; $score += $p;
  }
  $att['score'] = round($score, 2); $att['needs_manual'] = $needs; $att['results'] = $res;
}

// Keeps the gradebook entry in sync with the best attempt.
function sync_quiz_grade($cid, $qid, $uid) {
  $atts = quiz_attempts($cid, $qid, $uid);
  $best = null; $needs = false;
  foreach ($atts as $a) if (!empty($a['submitted'])) { $best = $best === null ? $a['score'] : max($best, $a['score']); $needs = $needs || !empty($a['needs_manual']); }
  if ($best === null) return;
  set_grade($cid, $qid, $uid, ['score' => $best, 'needs_manual' => $needs]);
}

function r_quiz_take() {
  $cid = safe_id(get('c')); $id = safe_id(get('id'));
  $course = require_course($cid);
  $q = require_access($cid, 'quiz', $id);
  $staff = staff($cid);
  $me = me();
  $path = qa_path($cid, $id, $me['id']);
  $isPreview = $staff && get('preview');
  $viewIdx = get('view', '') === '' ? null : (int)get('view');

  if ($isPreview) {
    $att = ['started' => now(), 'order' => array_column($q['questions'], 'id'), 'answers' => [], 'corder' => []];
    if (is_post()) { $att['answers'] = $_POST['ans'] ?? []; $att['submitted'] = now(); score_attempt($q, $att); return quiz_view($cid, $q, $att, 'review', true); }
    return quiz_view($cid, $q, $att, 'take', true);
  }
  if ($staff) redirect(u('quiz', ['c' => $cid, 'id' => $id]));

  $atts = quiz_attempts($cid, $id, $me['id']);
  if ($viewIdx !== null) {
    $a = $atts[$viewIdx] ?? null;
    if (!$a || empty($a['submitted'])) redirect(u('quiz', ['c' => $cid, 'id' => $id]));
    return quiz_view($cid, $q, $a, 'review', false);
  }
  $idx = null; foreach ($atts as $i => $a) if (empty($a['submitted'])) $idx = $i;
  if ($idx === null) redirect(u('quiz', ['c' => $cid, 'id' => $id]));
  $att = $atts[$idx];
  $limit = (int)($q['time_limit'] ?? 0) * 60;
  $deadline = $limit ? $att['started'] + $limit : 0;
  if (!empty($q['lock_at'])) $deadline = $deadline ? min($deadline, (int)$q['lock_at']) : (int)$q['lock_at'];

  if (is_post()) {
    // Keep only answers to this quiz's questions, with sane sizes.
    $raw = is_array($_POST['ans'] ?? null) ? $_POST['ans'] : [];
    $answers = [];
    foreach ($q['questions'] as $qq) {
      if (!array_key_exists($qq['id'], $raw)) continue;
      $v = $raw[$qq['id']];
      $answers[$qq['id']] = is_array($v) ? array_slice(array_map(function ($x) { return mb_substr((string)$x, 0, 100); }, $v), 0, 50) : mb_substr((string)$v, 0, 30000);
    }
    $final = post('final') === '1' || ($deadline && now() > $deadline);
    jupdate($path, function ($x) use ($idx, $answers, $final, $q, $deadline) {
      $a = &$x['attempts'][$idx];
      if (!empty($a['submitted'])) return $x;
      // Accept answers saved up to 60 seconds past the deadline (network grace).
      if (!$deadline || now() <= $deadline + 60) $a['answers'] = $answers;
      if ($final) { $a['submitted'] = now(); score_attempt($q, $a); }
      return $x;
    }, ['attempts' => []]);
    if (post('autosave')) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
    if ($final) {
      update_sub($cid, $id, $me['id'], function ($s) use ($idx) { $s['attempts'][] = ['at' => now(), 'quiz' => $idx + 1]; return $s; });
      sync_quiz_grade($cid, $id, $me['id']);
      flash('Quiz submitted.');
      redirect(u('quiz_take', ['c' => $cid, 'id' => $id, 'view' => $idx]));
    }
    redirect(u('quiz_take', ['c' => $cid, 'id' => $id]));
  }
  quiz_view($cid, $q, $att, 'take', false, $deadline);
}

function quiz_view($cid, $q, $att, $mode, $isPreview, $deadline = 0) {
  $course = course($cid);
  $byId = []; foreach ($q['questions'] as $qq) $byId[$qq['id']] = $qq;
  $order = array_values(array_filter($att['order'] ?? [], function ($x) use ($byId) { return isset($byId[$x]); }));
  foreach ($byId as $qid => $qq) if (!in_array($qid, $order, true)) $order[] = $qid;
  $review = $mode === 'review';
  $sub = get_sub($cid, $q['id'], me()['id']);
  $released = $isPreview || grade_posted($sub);
  $show = $q['show_answers'] ?? 'after_submit';
  // "After each attempt" waits until no attempts are left (or the quiz has closed),
  // so correct answers can't be read off one attempt and used on the next.
  $done = 0; foreach (quiz_attempts($cid, $q['id'], me()['id']) as $qa) if (!empty($qa['submitted'])) $done++;
  $maxA = (int)($q['attempts'] ?? 1);
  $over = (!empty($q['lock_at']) && now() > $q['lock_at']) || (!empty($q['due_at']) && now() > $q['due_at']);
  $showAnswers = $isPreview || ($released && (($show === 'after_submit' && (($maxA && $done >= $maxA) || $over)) || ($show === 'after_due' && $over)));
  $res = $att['results'] ?? [];
  ob_start(); ?>
  <div class="head-row"><h1><?= h($q['title']) ?></h1><?php if ($isPreview): ?><span class="pill warn">Instructor preview: nothing is saved</span><?php endif; ?></div>
  <?php if ($review): ?>
    <div class="card quiz-score"><b>Score: <?= $released ? fmt_num($att['score']) . ' / ' . fmt_num(quiz_points($q)) : 'not released yet' ?></b><?= !empty($att['needs_manual']) && $released ? '<span class="muted"> · essay answers still to be graded</span>' : '' ?></div>
  <?php elseif ($deadline): ?>
    <div class="timer" data-deadline="<?= (int)$deadline ?>" data-now="<?= now() ?>"><?= icon('clock') ?> <span>--:--</span> left</div>
  <?php endif; ?>
  <form method="post" class="quiz" id="quizform" <?= !$review && !$isPreview ? 'data-autosave="1"' : '' ?>><?= csrf_field() ?><input type="hidden" name="final" value="0">
  <?php $n = 0; foreach ($order as $qid): $qq = $byId[$qid]; $ans = $att['answers'][$qid] ?? null; $r = $res[$qid] ?? null; ?>
    <?php if ($qq['type'] === 'text'): ?><div class="qtext prose"><?= $qq['text'] ?></div><?php continue; endif; $n++; ?>
    <fieldset class="question<?= $review && $showAnswers && $r && $r[1] === true ? ' right' : '' ?><?= $review && $showAnswers && $r && $r[1] === false ? ' wrong' : '' ?>">
      <legend>Question <?= $n ?> <span class="muted">· <?= fmt_num($qq['points']) ?> pt<?= $qq['points'] == 1 ? '' : 's' ?></span><?php if ($review && $released && $r): ?><span class="qscore"><?= $r[0] === null ? 'Needs grading' : fmt_num($r[0]) . ' / ' . fmt_num($qq['points']) ?></span><?php endif; ?></legend>
      <div class="prose"><?= $qq['text'] ?></div>
      <?php
      $dis = $review ? ' disabled' : '';
      $nm = 'ans[' . h($qid) . ']';
      switch ($qq['type']) {
        case 'mc': case 'tf': case 'multi':
          $choices = $qq['choices'];
          if (!empty($att['corder'][$qid])) { $cm = []; foreach ($choices as $c) $cm[$c['id']] = $c; $choices = array_values(array_filter(array_map(function ($i) use ($cm) { return $cm[$i] ?? null; }, $att['corder'][$qid]))); }
          foreach ($choices as $c) {
            $multi = $qq['type'] === 'multi';
            $checked = $multi ? in_array((string)$c['id'], array_map('strval', (array)$ans), true) : (string)$ans === (string)$c['id'];
            $mark = $review && $showAnswers && !empty($c['correct']) ? ' <span class="pill ok">Correct answer</span>' : '';
            echo '<label class="choice' . ($checked ? ' picked' : '') . '"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="' . $nm . ($multi ? '[]' : '') . '" value="' . h($c['id']) . '"' . ($checked ? ' checked' : '') . $dis . '> <span>' . h($c['text']) . '</span>' . $mark . '</label>';
          }
          break;
        case 'short': case 'numeric':
          echo '<input class="short" name="' . $nm . '" value="' . h(is_string($ans) ? $ans : '') . '"' . ($qq['type'] === 'numeric' ? ' inputmode="decimal"' : '') . $dis . '>';
          if ($review && $showAnswers) echo '<p class="small muted">Accepted: ' . h($qq['type'] === 'numeric' ? fmt_num($qq['value']) . ($qq['tolerance'] ? ' ± ' . fmt_num($qq['tolerance']) : '') : implode(' · ', $qq['answers'])) . '</p>';
          break;
        case 'essay':
          echo $review ? '<div class="subtext">' . user_text(is_string($ans) ? $ans : '') . '</div>' : '<textarea name="' . $nm . '" rows="8">' . h(is_string($ans) ? $ans : '') . '</textarea>';
          break;
      }
      if ($review && $showAnswers && !empty($qq['feedback'])) echo '<div class="qfb small">' . user_text($qq['feedback']) . '</div>';
      ?>
    </fieldset>
  <?php endforeach; ?>
  <?php if (!$review): ?>
    <div class="row sticky-actions"><button class="btn primary" name="final" value="1" data-confirm="Submit your answers? You can’t change them afterwards."><?= $isPreview ? 'Submit (preview)' : 'Submit quiz' ?></button><?php if (!$isPreview): ?><span class="muted small save-state">Answers save automatically.</span><?php endif; ?></div>
  <?php else: ?>
    <p><a class="btn" href="<?= h(u('quiz', ['c' => $cid, 'id' => $q['id']])) ?>">Back to quiz</a></p>
  <?php endif; ?>
  </form>
  <?php layout($q['title'], ob_get_clean(), ['course' => $cid, 'active' => 'assignments']);
}

// ======================= grades (student) =======================

function r_grades() {
  $cid = safe_id(get('c'));
  $course = require_course($cid);
  if (staff($cid)) { r_gradebook(); return; }
  $me = me();
  $rows = student_rows($cid, $me['id'], false);
  $tot = compute_total($course, $rows);
  ob_start(); ?>
  <div class="head-row"><h1>Grades</h1><?php if (empty($course['hide_total']) && $tot['pct'] !== null): ?><div class="total-badge"><span><?= h(fmt_num(round($tot['pct'], 1))) ?>%</span><b><?= h($tot['letter']) ?></b></div><?php endif; ?></div>
  <table class="tbl grades"><thead><tr><th>Item</th><th>Due</th><th>Status</th><th class="num">Score</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $g = $r['g']; $sub = get_sub($cid, $g['id'], $me['id']); $nc = count(array_filter($sub['comments'], function ($c) use ($me) { return $c['by'] !== $me['id']; })); ?>
    <tr><td><a href="<?= h(item_url($cid, $g['type'], $g['id'])) ?>"><?= h($g['title']) ?></a><div class="muted small"><?= h(group_title($course, $g['group'])) ?><?= $nc && grade_posted($sub) ? ' · ' . $nc . ' comment' . ($nc > 1 ? 's' : '') : '' ?></div></td>
      <td><?= $g['due_at'] ? h(fmt_dt($g['due_at'], $course['tz'])) : '' ?></td>
      <td><?= $r['status'] ? '<span class="pill ' . ($r['status'] === 'Missing' ? 'err' : ($r['status'] === 'Graded' ? 'ok' : '')) . '">' . h($r['status']) . '</span>' : '' ?><?= $r['late'] && $r['status'] !== 'Missing' ? ' <span class="pill err">Late</span>' : '' ?></td>
      <td class="num"><?= $r['score'] !== null ? '<b>' . fmt_num($r['score']) . '</b>' : ($r['excused'] ? 'EX' : '–') ?> / <?= fmt_num($g['points']) ?><?= !empty($r['penalty']) ? '<div class="muted small">late −' . fmt_num($r['penalty']) . '%</div>' : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if (!$rows) echo empty_state('No graded work yet.'); ?>
  <?php if (empty($course['hide_total'])): ?>
  <h2>By category</h2>
  <table class="tbl"><thead><tr><th>Category</th><?php if ($course['weighted']): ?><th class="num">Weight</th><?php endif; ?><th class="num">Score</th></tr></thead><tbody>
  <?php foreach ($tot['groups'] as $gp): ?><tr><td><?= h($gp['title']) ?></td><?php if ($course['weighted']): ?><td class="num"><?= fmt_num($gp['weight']) ?>%</td><?php endif; ?><td class="num"><?= $gp['pct'] === null ? '–' : fmt_num(round($gp['pct'], 1)) . '% <span class="muted small">(' . fmt_num($gp['earned']) . '/' . fmt_num($gp['possible']) . ')</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="muted small">Your total counts only graded work<?= $course['missing_zero'] ? ' and missing work past its due date (as zero)' : '' ?>. Grades are released by your instructor.</p>
  <?php endif; ?>
  <?php layout('Grades', ob_get_clean(), ['course' => $cid, 'active' => 'grades']);
}

function group_title($course, $gid) {
  foreach ($course['groups'] as $g) if ($g['id'] === $gid) return $g['title'];
  return $course['groups'][0]['title'] ?? '';
}

// ======================= calendar =======================

function calendar_events($cid, $staff) {
  $course = course($cid);
  $content = content($cid);
  $ev = [];
  foreach ($content['modules'] as $m) {
    if (!$staff && empty($m['published'])) continue;
    if (!empty($m['unlock_at'])) $ev[] = ['at' => $m['unlock_at'], 'kind' => 'release', 'title' => 'Opens: ' . $m['title'], 'url' => u('modules', ['c' => $cid]) . '#' . $m['id'], 'move' => "mod:{$m['id']}"];
    foreach ($m['items'] as $it) {
      if (!$staff && empty($it['published'])) continue;
      if (!empty($it['unlock_at']) && $it['unlock_at'] != ($m['unlock_at'] ?? 0)) $ev[] = ['at' => $it['unlock_at'], 'kind' => 'release', 'title' => 'Opens: ' . $it['title'], 'url' => $it['type'] === 'header' ? '' : item_url($cid, $it['type'], $it['ref']), 'move' => "item:{$m['id']}:{$it['id']}"];
    }
  }
  foreach (gradables($cid) as $g) {
    if (!$staff) { $a = access($cid, $g['type'], $g['id']); if (!$a['ok'] && !$a['release']) continue; }
    if ($g['due_at']) $ev[] = ['at' => $g['due_at'], 'kind' => 'due', 'title' => 'Due: ' . $g['title'], 'url' => item_url($cid, $g['type'], $g['id']), 'move' => "due:{$g['type']}:{$g['id']}"];
    if ($staff && $g['lock_at']) $ev[] = ['at' => $g['lock_at'], 'kind' => 'lock', 'title' => 'Closes: ' . $g['title'], 'url' => item_url($cid, $g['type'], $g['id']), 'move' => "lock:{$g['type']}:{$g['id']}"];
  }
  foreach ($content['announcements'] as $a) {
    if (!empty($a['post_at']) && ($staff || $a['post_at'] <= now())) $ev[] = ['at' => $a['post_at'], 'kind' => 'ann', 'title' => 'Announcement: ' . $a['title'], 'url' => u('announcements', ['c' => $cid]), 'move' => "ann:{$a['id']}"];
  }
  foreach ($ev as &$e) { $e['cid'] = $cid; $e['tz'] = $course['tz']; }
  return $ev;
}

function r_calendar() {
  require_login();
  $cid = safe_id(get('c'));
  $courses = [];
  if ($cid) { $courses[] = require_course($cid); }
  else foreach (my_courses() as $c) if (in_array($c['_status'], ['active', 'staff'], true)) $courses[] = $c;
  $tz = $courses ? $courses[0]['tz'] : site()['tz'];
  $events = [];
  foreach ($courses as $c) $events = array_merge($events, calendar_events($c['id'], staff($c['id'])));
  $mParam = get('m');
  if (!preg_match('/^\d{4}-\d{2}$/', $mParam)) {
    // Default to the month of the next upcoming event, else this month.
    $next = null; foreach ($events as $e) if ($e['at'] >= now() && ($next === null || $e['at'] < $next)) $next = $e['at'];
    $mParam = (new DateTime('@' . ($next ?? now())))->setTimezone(tz_obj($tz))->format('Y-m');
  }
  $first = new DateTime($mParam . '-01 00:00', tz_obj($tz));
  $prev = (clone $first)->modify('-1 month')->format('Y-m');
  $next = (clone $first)->modify('+1 month')->format('Y-m');
  $start = (clone $first)->modify('-' . (int)$first->format('w') . ' days');
  $byDay = [];
  foreach ($events as $e) { $d = (new DateTime('@' . $e['at']))->setTimezone(tz_obj($e['tz'])); $byDay[$d->format('Y-m-d')][] = $e + ['time' => $d->format('g:i a')]; }
  foreach ($byDay as &$l) usort($l, function ($a, $b) { return $a['at'] <=> $b['at']; });
  unset($l);
  $staffCal = $cid && staff($cid);
  ob_start(); ?>
  <div class="head-row"><h1><?= h($first->format('F Y')) ?></h1>
    <div class="row"><a class="btn sm" href="<?= h(u('calendar', ['c' => $cid, 'm' => $prev])) ?>">←</a><a class="btn sm" href="<?= h(u('calendar', ['c' => $cid, 'm' => (new DateTime('now', tz_obj($tz)))->format('Y-m')])) ?>">Today</a><a class="btn sm" href="<?= h(u('calendar', ['c' => $cid, 'm' => $next])) ?>">→</a>
    <?php if ($staffCal): ?><a class="btn sm" href="<?= h(u('schedule', ['c' => $cid])) ?>"><?= icon('clock') ?> Edit all dates</a><?php endif; ?></div></div>
  <?php if ($staffCal): ?><p class="muted small">Drag any release, due date, closing time or scheduled announcement to another day to move it. The time of day stays the same. Students see releases and due dates only.</p><?php endif; ?>
  <div class="legend"><span class="ev release">Release</span><span class="ev due">Due</span><?php if ($staffCal): ?><span class="ev lock">Closes</span><?php endif; ?><span class="ev ann">Announcement</span></div>
  <div class="cal<?= $staffCal ? ' editable' : '' ?>" data-move-url="<?= h(u('cal_move', ['c' => $cid])) ?>">
    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dn): ?><div class="dow"><?= $dn ?></div><?php endforeach; ?>
    <?php $d = clone $start; $today = (new DateTime('now', tz_obj($tz)))->format('Y-m-d');
    for ($i = 0; $i < 42; $i++): $key = $d->format('Y-m-d'); $inMonth = $d->format('m') === $first->format('m');
      if ($i >= 35 && !$inMonth && $d->format('w') == 0) break; ?>
      <div class="day<?= $inMonth ? '' : ' out' ?><?= $key === $today ? ' today' : '' ?>" data-date="<?= $key ?>"><span class="dn"><?= $d->format('j') ?><span class="dw"> <?= $d->format('D') ?></span></span>
      <?php foreach ($byDay[$key] ?? [] as $e): ?>
        <a class="ev <?= h($e['kind']) ?>" href="<?= h($e['url'] ?: '#') ?>"<?= $staffCal ? ' draggable="true" data-move="' . h($e['move']) . '"' : '' ?> title="<?= h($e['time'] . ' · ' . $e['title']) ?>"><b><?= h($e['time']) ?></b> <?= h($e['title']) ?><?= !$cid && count($courses) > 1 ? ' <small>(' . h(course($e['cid'])['code'] ?: course($e['cid'])['title']) . ')</small>' : '' ?></a>
      <?php endforeach; ?>
      </div>
    <?php $d->modify('+1 day'); endfor; ?>
  </div>
  <?php layout('Calendar', ob_get_clean(), $cid ? ['course' => $cid, 'active' => 'calendar'] : []);
}
