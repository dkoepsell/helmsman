<?php
// Instructor grading: submission lists, the grader, gradebook, exports.
if (!defined('APP')) { http_response_code(404); exit; }

function grading_summary($cid, $oid) {
  $students = active_students($cid);
  $subs = subs_for($cid, $oid);
  $submitted = 0; $graded = 0; $unposted = 0;
  foreach ($students as $uid => $u) {
    $s = $subs[$uid] ?? null; if (!$s) continue;
    if (!empty($s['attempts'])) $submitted++;
    if (!empty($s['grade']) && isset($s['grade']['score'])) { $graded++; if (empty($s['grade']['posted'])) $unposted++; }
  }
  $n = count($students);
  $o = '<h3>Submissions</h3><div class="stats"><div><b>' . $submitted . '</b><span>of ' . $n . ' submitted</span></div><div><b>' . $graded . '</b><span>graded</span></div><div><b>' . needs_grading_count($cid, $oid) . '</b><span>to grade</span></div></div>';
  $o .= '<p><a class="btn block primary" href="' . h(u('grading', ['c' => $cid, 'id' => $oid])) . '">Open grader</a></p>';
  if ($unposted) $o .= '<p>' . post_btn(u('post_grades', ['c' => $cid, 'id' => $oid]), 'Post ' . $unposted . ' hidden grade' . ($unposted == 1 ? '' : 's'), 'btn block') . '</p>';
  return $o;
}

function r_grading() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $oid = safe_id(get('id'));
  $g = gradables($cid)[$oid] ?? null;
  if (!$g) throw new HttpError('Not a graded item.');
  $students = active_students($cid);
  $subs = subs_for($cid, $oid);
  $unposted = 0;
  foreach ($subs as $s) if (!empty($s['grade']) && empty($s['grade']['posted'])) $unposted++;
  $filter = get('f', 'all');
  ob_start(); ?>
  <div class="head-row"><h1><?= h($g['title']) ?></h1><div class="row">
    <a class="btn sm" href="<?= h(item_url($cid, $g['type'], $oid)) ?>">View item</a>
    <?php if ($g['type'] === 'assignment'): ?><a class="btn sm" href="<?= h(u('download_subs', ['c' => $cid, 'id' => $oid])) ?>">Download all files (.zip)</a><?php endif; ?>
    <?php if ($unposted): ?><?= post_btn(u('post_grades', ['c' => $cid, 'id' => $oid]), 'Post ' . $unposted . ' hidden grade' . ($unposted == 1 ? '' : 's'), 'btn sm primary') ?><?php endif; ?>
  </div></div>
  <p class="muted"><?= fmt_num($g['points']) ?> points<?= $g['due_at'] ? ' · due ' . h(fmt_dt($g['due_at'], $course['tz'])) : '' ?> · grades are <?= $g['post_policy'] === 'manual' ? '<b>hidden until you post them</b>' : 'released as you grade' ?></p>
  <div class="tabs"><?php foreach (['all' => 'Everyone', 'todo' => 'Needs grading', 'missing' => 'Not submitted'] as $k => $v): ?><a class="tab<?= $filter === $k ? ' on' : '' ?>" href="<?= h(u('grading', ['c' => $cid, 'id' => $oid, 'f' => $k])) ?>"><?= $v ?></a><?php endforeach; ?></div>
  <table class="tbl"><thead><tr><th>Student</th><th>Submitted</th><th>Status</th><th class="num">Score</th><th></th></tr></thead><tbody>
  <?php $shown = 0; foreach ($students as $uid => $u):
    $s = $subs[$uid] ?? ['attempts' => [], 'grade' => null, 'comments' => []];
    $s += ['attempts' => [], 'grade' => null, 'comments' => []];
    $r = grade_row($course, $g, $s, true);
    $todo = !empty($s['attempts']) && (empty($s['grade']) || ($s['grade']['graded_at'] ?? 0) < last_attempt_at($s) || !empty($s['grade']['needs_manual']));
    if ($filter === 'todo' && !$todo) continue;
    if ($filter === 'missing' && !empty($s['attempts'])) continue;
    $shown++; ?>
    <tr><td><a href="<?= h(u('grade', ['c' => $cid, 'id' => $oid, 'uid' => $uid])) ?>"><?= h($u['name']) ?></a></td>
      <td class="small"><?= last_attempt_at($s) ? h(fmt_dt(last_attempt_at($s), $course['tz'])) . (count($s['attempts']) > 1 ? ' <span class="muted">(' . count($s['attempts']) . ' attempts)</span>' : '') : '<span class="muted">—</span>' ?></td>
      <td><?= $todo ? '<span class="pill warn">Needs grading</span>' : '' ?> <?= h($r['status']) ?><?= $r['late'] ? ' <span class="pill err">' . $r['late'] . 'd late</span>' : '' ?><?= !empty($s['grade']) && empty($s['grade']['posted']) ? ' <span class="pill">hidden</span>' : '' ?></td>
      <td class="num"><?= $r['raw'] !== null ? fmt_num($r['raw']) . (!empty($r['penalty']) ? ' <small class="muted">→ ' . fmt_num($r['score']) . '</small>' : '') : ($r['excused'] ? 'EX' : '–') ?> / <?= fmt_num($g['points']) ?></td>
      <td><a class="btn sm" href="<?= h(u('grade', ['c' => $cid, 'id' => $oid, 'uid' => $uid])) ?>">Grade</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if (!$shown) echo empty_state($students ? 'No one in this list.' : 'No students enrolled yet.'); ?>
  <?php layout('Grading: ' . $g['title'], ob_get_clean(), ['course' => $cid, 'active' => 'grades']);
}

function r_post_grades() {
  $cid = safe_id(get('c')); require_admin(); require_course($cid);
  $oid = safe_id(get('id'));
  if (is_post()) { $n = post_grades($cid, $oid); flash("$n grade" . ($n == 1 ? '' : 's') . ' posted; students were notified.'); }
  redirect($_SERVER['HTTP_REFERER'] ?? u('grading', ['c' => $cid, 'id' => $oid]));
}

function r_grade() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $oid = safe_id(get('id')); $uid = safe_id(get('uid'));
  $g = gradables($cid)[$oid] ?? null;
  $u = user($uid);
  if (!$g || !$u) throw new HttpError('Not found.');
  $o = obj($cid, $g['type'], $oid);
  $me = me();
  $students = array_keys(active_students($cid));
  if (!in_array($uid, $students, true)) $students[] = $uid;
  $pos = array_search($uid, $students, true);
  $prev = $students[$pos - 1] ?? null; $next = $students[$pos + 1] ?? null;

  if (is_post()) {
    $fields = [];
    $rubric = $o['rubric'] ?? [];
    if ($g['type'] === 'quiz') {
      // Manual points for essay questions and per-question overrides, on the selected attempt.
      $ai = (int)post('attempt');
      $man = array_filter((array)($_POST['qman'] ?? []), function ($v) { return $v !== ''; });
      jupdate(qa_path($cid, $oid, $uid), function ($x) use ($ai, $man, $o) {
        if (!isset($x['attempts'][$ai])) return $x;
        $a = &$x['attempts'][$ai];
        $a['manual'] = array_map('floatval', $man);
        score_attempt($o, $a);
        return $x;
      }, ['attempts' => []]);
      $atts = quiz_attempts($cid, $oid, $uid);
      $best = null; $needs = false;
      foreach ($atts as $a) if (!empty($a['submitted'])) { $best = $best === null ? $a['score'] : max($best, $a['score']); $needs = $needs || !empty($a['needs_manual']); }
      $fields['needs_manual'] = $needs;
      $fields['score'] = post('score') !== '' && post('override') === '1' ? (float)post('score') : $best;
      $fields['override'] = post('override') === '1';
    } else {
      if ($rubric) {
        $rs = []; $sum = 0; $any = false;
        foreach ($rubric as $k) {
          $p = $_POST['rp'][$k['id']] ?? '';
          $c = trim($_POST['rc'][$k['id']] ?? '');
          $rs[$k['id']] = ['p' => $p === '' ? null : (float)$p, 'c' => $c];
          if ($p !== '') { $sum += (float)$p; $any = true; }
        }
        $fields['rubric'] = $rs;
        $fields['score'] = post('score') !== '' ? (float)post('score') : ($any ? $sum : null);
      } else {
        $fields['score'] = post('score') === '' ? null : (float)post('score');
      }
      $fields['needs_manual'] = false;
    }
    $fields['excused'] = post('excused') === '1';
    $fields['waive_late'] = post('waive_late') === '1';
    if ($fields['excused']) $fields['score'] = null;
    if ($fields['score'] !== null || $fields['excused']) set_grade($cid, $oid, $uid, $fields, $me['id']);
    else update_sub($cid, $oid, $uid, function ($s) use ($fields) { $s['grade'] = $s['grade'] ? array_merge($s['grade'], $fields) : null; return $s; });
    $fb = trim((string)($_POST['feedback'] ?? ''));
    if ($fb !== '') {
      $hold = ($g['post_policy'] ?? 'auto') === 'manual' && !grade_posted(get_sub($cid, $oid, $uid));
      update_sub($cid, $oid, $uid, function ($s) use ($fb, $me, $hold) { $s['comments'][] = ['by' => $me['id'], 'at' => now(), 'text' => $fb, 'hold' => $hold]; return $s; });
      if (!$hold) notify($uid, 'New feedback on ' . h($g['title']), item_url($cid, $g['type'], $oid) . '#comments');
    }
    flash('Saved grade for ' . h($u['name']) . '.');
    $go = post('next') && $next ? $next : $uid;
    redirect(u('grade', ['c' => $cid, 'id' => $oid, 'uid' => $go]));
  }

  $sub = get_sub($cid, $oid, $uid);
  $gr = $sub['grade'] ?: [];
  $row = grade_row($course, $g, $sub, true);
  ob_start(); ?>
  <div class="head-row"><h1><?= h($g['title']) ?></h1><a class="btn sm" href="<?= h(u('grading', ['c' => $cid, 'id' => $oid])) ?>">All students</a></div>
  <div class="grader-nav">
    <?= $prev ? '<a class="btn sm" href="' . h(u('grade', ['c' => $cid, 'id' => $oid, 'uid' => $prev])) . '">← Previous</a>' : '<span></span>' ?>
    <select onchange="location.href=this.value" aria-label="Student"><?php foreach (active_students($cid) as $sid => $su): $ss = get_sub($cid, $oid, $sid); ?><option value="<?= h(u('grade', ['c' => $cid, 'id' => $oid, 'uid' => $sid])) ?>"<?= $sid === $uid ? ' selected' : '' ?>><?= h($su['name']) ?><?= !empty($ss['grade']['score']) || (isset($ss['grade']['score']) && $ss['grade']['score'] !== null) ? ' ✓' : (!empty($ss['attempts']) ? ' •' : '') ?></option><?php endforeach; ?></select>
    <?= $next ? '<a class="btn sm" href="' . h(u('grade', ['c' => $cid, 'id' => $oid, 'uid' => $next])) . '">Next →</a>' : '<span></span>' ?>
  </div>
  <div class="grid-main grader">
  <div>
    <h2><?= h($u['name']) ?> <small class="muted"><?= h($u['email']) ?></small></h2>
    <?php if ($g['type'] === 'quiz'):
      $atts = quiz_attempts($cid, $oid, $uid);
      $sel = get('a', '') === '' ? null : (int)get('a');
      $subm = array_keys(array_filter($atts, function ($a) { return !empty($a['submitted']); }));
      if ($sel === null && $subm) { $bestI = $subm[0]; foreach ($subm as $i) if ($atts[$i]['score'] > $atts[$bestI]['score']) $bestI = $i; $sel = $bestI; }
      if (!$subm) echo empty_state('No attempts submitted yet.');
      else { ?>
        <div class="tabs"><?php foreach ($subm as $i): ?><a class="tab<?= $i === $sel ? ' on' : '' ?>" href="<?= h(u('grade', ['c' => $cid, 'id' => $oid, 'uid' => $uid, 'a' => $i])) ?>">Attempt <?= $i + 1 ?>: <?= fmt_num($atts[$i]['score']) ?></a><?php endforeach; ?></div>
        <?php $a = $atts[$sel]; $res = $a['results'] ?? []; ?>
        <p class="muted small">Started <?= h(fmt_dt($a['started'], $course['tz'])) ?>, submitted <?= h(fmt_dt($a['submitted'], $course['tz'])) ?> (<?= max(1, round(($a['submitted'] - $a['started']) / 60)) ?> min)</p>
        <form method="post" id="gform" class="stack"><?= csrf_field() ?><input type="hidden" name="attempt" value="<?= (int)$sel ?>">
        <?php $n = 0; foreach (array_intersect_key(attempt_questions($o, $a), array_flip(array_merge($a['order'] ?? [], array_column($o['questions'], 'id')))) as $qq): if ($qq['type'] === 'text') continue; $n++; $ans = $a['answers'][$qq['id']] ?? null; $r = $res[$qq['id']] ?? [null, null]; ?>
          <div class="question <?= $r[1] === true ? 'right' : ($r[1] === false ? 'wrong' : '') ?>"><div class="row between"><b>Q<?= $n ?></b><span class="small"><?= $r[0] === null ? '<span class="pill warn">Needs grading</span>' : fmt_num($r[0]) . ' / ' . fmt_num($qq['points']) ?></span></div>
            <div class="prose small"><?= $qq['text'] ?></div>
            <div class="answer"><?= quiz_answer_text($qq, $ans) ?></div>
            <label class="row small">Points <input type="number" step="any" min="0" max="<?= h($qq['points']) ?>" name="qman[<?= h($qq['id']) ?>]" value="<?= isset($a['manual'][$qq['id']]) ? h(fmt_num($a['manual'][$qq['id']])) : '' ?>" placeholder="<?= $r[0] === null ? '' : h(fmt_num($r[0])) ?>" class="w6"> <span class="muted">/ <?= fmt_num($qq['points']) ?><?= $qq['type'] !== 'essay' ? ' · leave empty to keep the automatic score' : '' ?></span></label>
          </div>
        <?php endforeach; ?>
        </form>
      <?php } ?>
    <?php else: ?>
      <?php if (!$sub['attempts']) echo empty_state($g['type'] === 'discussion' ? 'No posts yet.' : ($g['submits'] ? 'Nothing submitted yet.' : 'No online submission for this item. Enter a grade on the right.')); ?>
      <?php if ($g['type'] === 'discussion'):
        $mine = array_filter(posts($cid, $oid), function ($p) use ($uid) { return $p['uid'] === $uid && empty($p['deleted']); });
        foreach ($mine as $p) echo '<div class="post"><div class="muted small">' . h(fmt_dt($p['at'], $course['tz'])) . ($p['parent'] ? ' · reply' : ' · top-level post') . '</div><div class="post-body">' . user_text($p['body']) . '</div></div>';
      else:
        foreach (array_reverse($sub['attempts'], true) as $ai => $at): ?>
          <div class="attempt card"><div class="muted small">Attempt <?= $ai + 1 ?> · <?= h(fmt_dt($at['at'], $course['tz'])) ?><?= $g['due_at'] && $at['at'] > $g['due_at'] ? ' <span class="pill err">late</span>' : '' ?></div><?= attempt_html($cid, $oid, $uid, $ai, $at) ?>
          <?php foreach ($at['files'] ?? [] as $fi => $f): if (preg_match('/\.(pdf|png|jpe?g|gif|webp)$/i', $f['name'])): $furl = u('upfile', ['c' => $cid, 'o' => $oid, 's' => $uid, 'a' => $ai, 'i' => $fi]); ?>
            <?= preg_match('/\.pdf$/i', $f['name']) ? '<iframe class="pdf" src="' . h($furl) . '" title="' . h($f['name']) . '"></iframe>' : '<img class="subimg" src="' . h($furl) . '" alt="' . h($f['name']) . '">' ?>
          <?php endif; endforeach; ?>
          </div>
        <?php endforeach;
      endif; ?>
    <?php endif; ?>
  </div>
  <aside class="rail">
    <form method="post" class="stack card" id="<?= $g['type'] === 'quiz' ? 'gform2' : 'gform' ?>"><?= csrf_field() ?>
      <?php if ($g['type'] === 'quiz'): ?><input type="hidden" name="attempt" value="<?= (int)($sel ?? 0) ?>"><?php endif; ?>
      <h3>Grade</h3>
      <?php if ($g['type'] !== 'quiz' && !empty($o['rubric'])): ?><?= rubric_table($o['rubric'], $gr['rubric'] ?? [], true) ?><?php endif; ?>
      <label>Score <span class="row"><input type="number" step="any" name="score" id="score" value="<?= isset($gr['score']) && $gr['score'] !== null && ($g['type'] !== 'quiz' || !empty($gr['override'])) ? h(fmt_num($gr['score'])) : '' ?>" class="w6"<?= $g['type'] === 'quiz' ? ' placeholder="' . h(fmt_num($gr['score'] ?? '')) . '"' : '' ?>> / <?= fmt_num($g['points']) ?></span>
        <?php if ($g['type'] === 'quiz'): ?><small>The highest attempt counts (now <?= isset($gr['score']) ? fmt_num($gr['score']) : '–' ?>).</small><label class="check"><input type="checkbox" name="override" value="1" <?= !empty($gr['override']) ? 'checked' : '' ?>> Use the score typed above instead</label><?php elseif (!empty($o['rubric'])): ?><small>Leave empty to use the rubric total.</small><?php endif; ?></label>
      <?php if ($row['late']): ?><p class="small"><span class="pill err"><?= $row['late'] ?> day<?= $row['late'] > 1 ? 's' : '' ?> late</span><?= $course['late_pct'] > 0 ? ' Penalty: −' . fmt_num(min($course['late_max'] ?: 100, $course['late_pct'] * $row['late'])) . '%' : '' ?></p>
        <?php if ($course['late_pct'] > 0): ?><label class="check"><input type="checkbox" name="waive_late" value="1" <?= !empty($gr['waive_late']) ? 'checked' : '' ?>> Waive the late penalty</label><?php endif; ?><?php endif; ?>
      <label class="check"><input type="checkbox" name="excused" value="1" <?= !empty($gr['excused']) ? 'checked' : '' ?>> Excused (doesn’t count)</label>
      <label>Feedback to the student <textarea name="feedback" rows="5" placeholder="Written feedback (adds a comment the student sees<?= $g['post_policy'] === 'manual' ? ' when you post grades' : '' ?>)"></textarea></label>
      <div class="row"><button class="btn primary">Save</button><?php if ($next): ?><button class="btn" name="next" value="1">Save &amp; next</button><?php endif; ?></div>
      <?php if (!empty($gr['graded_at'])): ?><p class="muted small">Last graded <?= h(rel_time($gr['graded_at'])) ?> · <?= !empty($gr['posted']) ? 'visible to student' : '<b>hidden</b> until you post grades' ?></p><?php endif; ?>
    </form>
    <?= comments_html($cid, $oid, $sub, '', true) ?>
  </aside>
  </div>
  <?php if ($g['type'] === 'quiz'): ?>
  <script>
  // Quiz grading: the question points live in the left form; merge the right-hand fields in on save.
  function copyGrade(){var a=document.getElementById('gform'),b=document.getElementById('gform2');if(!a||!b||a===b)return;
    b.querySelectorAll('input,textarea,select').forEach(function(el){if(!el.name||el.name==='_csrf'||el.name==='attempt')return;
      if((el.type==='checkbox')&&!el.checked)return;var h=document.createElement('input');h.type='hidden';h.name=el.name;h.value=el.value;a.appendChild(h);});}
  document.getElementById('gform2').addEventListener('submit',function(e){var a=document.getElementById('gform');if(!a)return;e.preventDefault();copyGrade();
    if(e.submitter&&e.submitter.name){var h=document.createElement('input');h.type='hidden';h.name=e.submitter.name;h.value=e.submitter.value;a.appendChild(h);}a.submit();});
  </script>
  <?php endif; ?>
  <?php layout('Grade ' . $u['name'], ob_get_clean(), ['course' => $cid, 'active' => 'grades']);
}

function quiz_answer_text($qq, $ans) {
  if ($ans === null || $ans === '' || $ans === []) return '<span class="muted">(no answer)</span>';
  if (in_array($qq['type'], ['mc', 'tf', 'multi'], true)) {
    $o = '<ul class="small">';
    $sel = array_map('strval', (array)$ans);
    foreach ($qq['choices'] as $c) {
      $picked = in_array((string)$c['id'], $sel, true);
      $o .= '<li class="' . ($picked ? 'picked' : 'muted') . '">' . ($picked ? '● ' : '○ ') . h($c['text']) . (!empty($c['correct']) ? ' <span class="pill ok">correct</span>' : '') . '</li>';
    }
    return $o . '</ul>';
  }
  if ($qq['type'] === 'essay') return '<div class="subtext">' . user_text((string)$ans) . '</div>';
  $acc = $qq['type'] === 'numeric' ? fmt_num($qq['value']) . ($qq['tolerance'] ? ' ± ' . fmt_num($qq['tolerance']) : '') : implode(' · ', $qq['answers']);
  return '<code>' . h((string)$ans) . '</code> <span class="muted small">(accepted: ' . h($acc) . ')</span>';
}

function r_gradebook() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $gs = graded_items($cid);
  $students = active_students($cid);
  ob_start(); ?>
  <div class="head-row"><h1>Gradebook</h1><div class="row"><a class="btn sm" href="<?= h(u('gradebook_csv', ['c' => $cid])) ?>">Export CSV</a><a class="btn sm" href="<?= h(u('course_settings', ['c' => $cid])) ?>">Weights &amp; policies</a></div></div>
  <?php if (!$students): echo empty_state('No students yet. Add them under People.'); else: ?>
  <p class="muted small">Click a score to grade. <span class="pill warn">!</span> needs grading · <span class="pill">h</span> hidden from student · <span class="pill err">M</span> missing · <span class="pill">L</span> late.</p>
  <div class="tbl-wrap gb"><table class="tbl gbt"><thead><tr><th class="stick">Student</th>
    <?php foreach ($gs as $g): ?><th class="gcol"><a href="<?= h(u('grading', ['c' => $cid, 'id' => $g['id']])) ?>" title="<?= h($g['title']) ?>"><?= h(mb_strimwidth($g['title'], 0, 28, '…')) ?></a><small><?= fmt_num($g['points']) ?> pts<?= $g['omit'] ? ' · not counted' : '' ?></small></th><?php endforeach; ?>
    <th class="num">Total</th></tr></thead><tbody>
  <?php $colSum = []; foreach ($students as $uid => $u): $rows = []; ?>
    <tr><th class="stick"><a href="<?= h(u('student', ['c' => $cid, 'uid' => $uid])) ?>"><?= h($u['name']) ?></a></th>
    <?php foreach ($gs as $g): $s = get_sub($cid, $g['id'], $uid); $r = grade_row($course, $g, $s, true); $rows[$g['id']] = $r;
      $todo = !empty($s['attempts']) && (empty($s['grade']) || ($s['grade']['graded_at'] ?? 0) < last_attempt_at($s) || !empty($s['grade']['needs_manual']));
      if ($r['score'] !== null && $r['status'] === 'Graded') $colSum[$g['id']][] = $r['score']; ?>
      <td class="num"><a href="<?= h(u('grade', ['c' => $cid, 'id' => $g['id'], 'uid' => $uid])) ?>"><?= $r['status'] === 'Graded' ? fmt_num($r['score']) : ($r['excused'] ? 'EX' : ($r['status'] === 'Missing' ? '<span class="pill err">M</span>' : (!empty($s['attempts']) ? '<span class="muted">sub</span>' : '–'))) ?></a><?= $todo ? ' <span class="pill warn">!</span>' : '' ?><?= !empty($s['grade']) && empty($s['grade']['posted']) ? ' <span class="pill">h</span>' : '' ?><?= $r['late'] ? ' <span class="pill">L</span>' : '' ?></td>
    <?php endforeach; $t = compute_total($course, $rows); ?>
    <td class="num"><b><?= $t['pct'] === null ? '–' : fmt_num(round($t['pct'], 1)) . '%' ?></b> <?= h($t['letter']) ?></td></tr>
  <?php endforeach; ?>
  </tbody><tfoot><tr><th class="stick">Class average</th><?php foreach ($gs as $g): $v = $colSum[$g['id']] ?? []; ?><td class="num muted"><?= $v ? fmt_num(round(array_sum($v) / count($v), 1)) : '' ?></td><?php endforeach; ?><td></td></tr></tfoot></table></div>
  <?php endif; ?>
  <?php layout('Gradebook', ob_get_clean(), ['course' => $cid, 'active' => 'grades']);
}

function r_gradebook_csv() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $gs = graded_items($cid);
  $fn = preg_replace('/[^A-Za-z0-9]+/', '-', $course['code'] ?: $course['title']) . '-grades-' . date('Y-m-d') . '.csv';
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $fn . '"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  $head = ['Student', 'Email'];
  foreach ($gs as $g) $head[] = $g['title'] . ' (' . fmt_num($g['points']) . ')';
  foreach ($course['groups'] as $gp) $head[] = $gp['title'] . ' %';
  $head[] = 'Total %'; $head[] = 'Letter';
  // Spreadsheet formula guard: text that starts like a formula gets a leading apostrophe.
  $safe = function ($v) { return is_string($v) && preg_match('/^(?:[=+@\t\r]|-(?![\d.]))/', $v) ? "'" . $v : $v; };
  fputcsv($out, array_map($safe, $head));
  foreach (active_students($cid) as $uid => $u) {
    $line = [$u['name'], $u['email']]; $rows = [];
    foreach ($gs as $g) { $r = grade_row($course, $g, get_sub($cid, $g['id'], $uid), true); $rows[$g['id']] = $r; $line[] = $r['excused'] ? 'EX' : ($r['score'] === null ? '' : fmt_num($r['score'])); }
    $t = compute_total($course, $rows);
    foreach ($t['groups'] as $gp) $line[] = $gp['pct'] === null ? '' : round($gp['pct'], 2);
    $line[] = $t['pct'] === null ? '' : round($t['pct'], 2); $line[] = $t['letter'];
    fputcsv($out, array_map($safe, $line));
  }
  exit;
}

function r_download_subs() {
  $cid = safe_id(get('c')); require_admin(); require_course($cid);
  $oid = safe_id(get('id'));
  $g = gradables($cid)[$oid] ?? null;
  if (!$g) throw new HttpError('Not found.');
  $tmp = dpath('tmp-' . bin2hex(random_bytes(6)) . '.zip.tmp');
  $zip = new MiniZipWriter($tmp);
  $users = users(); $n = 0;
  foreach (subs_for($cid, $oid) as $uid => $s) {
    $last = end($s['attempts']); if (!$last) continue;
    $who = preg_replace('/[^A-Za-z0-9]+/', '_', sort_name($users[$uid]['name'] ?? $uid));
    foreach ($last['files'] ?? [] as $f) { $zip->addFile(dpath($f['stored']), "$who/" . basename($f['name'])); $n++; }
    if (!empty($last['text'])) { $zip->addFromString("$who/text-entry.txt", $last['text']); $n++; }
    if (!empty($last['url'])) { $zip->addFromString("$who/link.txt", $last['url']); $n++; }
  }
  if (!$n) $zip->addFromString('README.txt', 'No submissions yet.');
  $zip->close();
  $name = preg_replace('/[^A-Za-z0-9]+/', '-', $g['title']) . '.zip';
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . $name . '"');
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp); @unlink($tmp);
  exit;
}
