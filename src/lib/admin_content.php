<?php
// Instructor content tools: modules, items, editors, release schedule, calendar moves.
if (!defined('APP')) { http_response_code(404); exit; }

function item_tools($cid, $m, $it, $ii, $ni) {
  $u = u('item_save', ['c' => $cid]);
  $f = ['mid' => $m['id'], 'iid' => $it['id']];
  $o = '<span class="tools">';
  $o .= post_btn($u, icon(!empty($it['published']) ? 'eye' : 'eyeoff'), 'icon-btn' . (!empty($it['published']) ? ' on' : ''), $f + ['toggle' => 1], !empty($it['published']) ? 'Published (click to hide)' : 'Hidden (click to publish)');
  if ($ii > 0) $o .= post_btn($u, icon('up'), 'icon-btn', $f + ['move' => -1], 'Move up');
  if ($ii < $ni - 1) $o .= post_btn($u, icon('down'), 'icon-btn', $f + ['move' => 1], 'Move down');
  $o .= '<details class="pop"><summary class="icon-btn" title="More">⋯</summary><div class="card menu-pop">';
  if ($it['type'] !== 'header') $o .= '<a href="' . h(u('edit', ['c' => $cid, 't' => $it['type'], 'id' => $it['ref']])) . '">' . icon('edit') . ' Edit</a>';
  else $o .= '<form method="post" action="' . h($u) . '" class="stack">' . csrf_field() . '<input type="hidden" name="mid" value="' . h($m['id']) . '"><input type="hidden" name="iid" value="' . h($it['id']) . '"><input name="title" value="' . h($it['title']) . '"><button class="btn sm">Rename</button></form>';
  $o .= post_btn($u, '→ Indent', 'linkish', $f + ['indent' => 1]) . post_btn($u, '← Outdent', 'linkish', $f + ['indent' => -1]);
  $mods = content($cid)['modules'];
  if (count($mods) > 1) {
    $o .= '<form method="post" action="' . h($u) . '" class="row">' . csrf_field() . '<input type="hidden" name="mid" value="' . h($m['id']) . '"><input type="hidden" name="iid" value="' . h($it['id']) . '"><select name="to">';
    foreach ($mods as $mm) if ($mm['id'] !== $m['id']) $o .= '<option value="' . h($mm['id']) . '">' . h(mb_substr($mm['title'], 0, 40)) . '</option>';
    $o .= '</select><button class="btn sm">Move</button></form>';
  }
  $o .= confirm_btn($u, icon('trash') . ' Remove', 'Remove this item from the module? Pages, videos and links are deleted; graded work with submissions is kept in the gradebook.', 'linkish danger', $f + ['delete' => 1]);
  return $o . '</div></details></span>';
}

function add_item_bar($cid, $mid) {
  $o = '<details class="add-item"><summary>' . icon('plus') . ' Add item</summary><form method="post" action="' . h(u('item_add', ['c' => $cid])) . '" class="row">' . csrf_field() . '<input type="hidden" name="mid" value="' . h($mid) . '"><select name="type">';
  foreach (TYPES as $k => $v) $o .= '<option value="' . $k . '"' . ($k === 'video' ? ' selected' : '') . '>' . h($v) . '</option>';
  return $o . '</select><input name="title" placeholder="Title" required><button class="btn sm primary">Add</button></form></details>';
}

function r_module_save() {
  $cid = safe_id(get('c')); require_admin(); require_course($cid);
  if (!is_post()) redirect(u('modules', ['c' => $cid]));
  $id = safe_id(post('id'));
  $anchor = $id;
  update_content($cid, function ($c) use ($id, &$anchor) {
    if (post('new')) {
      $anchor = newid('m');
      $c['modules'][] = ['id' => $anchor, 'title' => 'New module', 'published' => false, 'unlock_at' => 0, 'items' => []];
      return $c;
    }
    foreach ($c['modules'] as $i => &$m) {
      if ($m['id'] !== $id) continue;
      if (post('toggle')) $m['published'] = empty($m['published']);
      if (post('move')) {
        $j = $i + (int)post('move');
        if ($j >= 0 && $j < count($c['modules'])) { $tmp = $c['modules'][$j]; $c['modules'][$j] = $c['modules'][$i]; $c['modules'][$i] = $tmp; }
        return $c;
      }
      break;
    }
    return $c;
  });
  if (post('new')) redirect(u('module_edit', ['c' => $cid, 'id' => $anchor]));
  redirect(u('modules', ['c' => $cid]) . '#' . $anchor);
}

function r_module_edit() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $id = safe_id(get('id'));
  $mod = null; foreach (content($cid)['modules'] as $m) if ($m['id'] === $id) $mod = $m;
  if (!$mod) throw new HttpError('Module not found.');
  if (is_post()) {
    if (post('delete')) {
      update_content($cid, function ($c) use ($id) {
        $gone = null;
        foreach ($c['modules'] as $m) if ($m['id'] === $id) $gone = $m;
        $c['modules'] = array_values(array_filter($c['modules'], function ($m) use ($id) { return $m['id'] !== $id; }));
        // Anything that now sits in no module is hidden, so it can't become visible by accident.
        foreach ($gone['items'] ?? [] as $it) {
          if (empty($it['ref']) || !isset(TYPE_STORE[$it['type']])) continue;
          if (!placements($c, $it['type'], $it['ref']) && isset($c[TYPE_STORE[$it['type']]][$it['ref']])) $c[TYPE_STORE[$it['type']]][$it['ref']]['published'] = false;
        }
        return $c;
      });
      flash('Module deleted. Its pages and assignments are kept but hidden; add them to another module to show them again.');
      redirect(u('modules', ['c' => $cid]));
    }
    $t = post('title'); $un = input_ts(post('unlock_at'), $course['tz']); $pub = post('published') === '1';
    update_content($cid, function ($c) use ($id, $t, $un, $pub) {
      foreach ($c['modules'] as &$m) if ($m['id'] === $id) { $m['title'] = $t ?: $m['title']; $m['unlock_at'] = $un; $m['published'] = $pub; }
      return $c;
    });
    flash('Module saved.');
    redirect(u('modules', ['c' => $cid]) . '#' . $id);
  }
  ob_start(); ?>
  <h1>Edit module</h1>
  <form method="post" class="stack narrow"><?= csrf_field() ?>
    <label>Title <input name="title" value="<?= h($mod['title']) ?>" required></label>
    <label>Opens to students <input type="datetime-local" name="unlock_at" value="<?= h(dt_input($mod['unlock_at'] ?? 0, $course['tz'])) ?>"><small>Leave empty to open as soon as it’s published. Times are <?= h($course['tz']) ?>.</small></label>
    <label class="check"><input type="checkbox" name="published" value="1" <?= !empty($mod['published']) ? 'checked' : '' ?>> Published</label>
    <div class="row"><button class="btn primary">Save</button><a class="btn" href="<?= h(u('modules', ['c' => $cid])) ?>">Cancel</a></div>
  </form>
  <form method="post" data-confirm="Delete this module? Items in it are hidden but not deleted." class="narrow"><?= csrf_field() ?><input type="hidden" name="delete" value="1"><button class="btn sm danger">Delete module</button></form>
  <?php layout('Edit module', ob_get_clean(), ['course' => $cid, 'active' => 'modules']);
}

function r_item_add() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  if (!is_post()) redirect(u('modules', ['c' => $cid]));
  $type = post('type'); $title = post('title') ?: 'Untitled';
  $mid = safe_id(post('mid'));
  if (!isset(TYPES[$type])) throw new HttpError('Unknown item type.');
  $ref = null;
  update_content($cid, function ($c) use ($type, $title, $mid, $course, &$ref) {
    if ($type !== 'header') {
      $ref = newid(substr($type, 0, 1));
      $o = ['id' => $ref, 'title' => $title, 'published' => true];
      switch ($type) {
        case 'page': $o['body'] = ''; break;
        case 'video': $o += ['url' => '', 'body' => '', 'duration' => '', 'slides' => '']; break;
        case 'assignment': $o += ['body' => '', 'points' => 10, 'group' => $course['groups'][0]['id'], 'due_at' => 0, 'lock_at' => 0, 'sub_types' => ['upload'], 'allowed_ext' => '', 'attempts' => 0, 'post_policy' => 'auto', 'rubric' => []]; break;
        case 'quiz': $o += ['body' => '', 'questions' => [], 'points' => 0, 'group' => $course['groups'][0]['id'], 'due_at' => 0, 'lock_at' => 0, 'time_limit' => 0, 'attempts' => 1, 'shuffle' => false, 'show_answers' => 'after_submit', 'post_policy' => 'auto']; break;
        case 'discussion': $o += ['body' => '', 'points' => 0, 'group' => $course['groups'][0]['id'], 'due_at' => 0, 'lock_at' => 0, 'require_initial' => false, 'post_policy' => 'auto']; break;
        case 'link': $o['url'] = ''; break;
        case 'file': $o['path'] = ''; break;
      }
      $c[TYPE_STORE[$type]][$ref] = $o;
    }
    foreach ($c['modules'] as &$m) if ($m['id'] === $mid) {
      $it = ['id' => newid('i'), 'type' => $type, 'title' => $title, 'indent' => $type === 'header' ? 0 : 1, 'published' => $type === 'header', 'unlock_at' => 0];
      if ($ref) $it['ref'] = $ref;
      $m['items'][] = $it;
    }
    return $c;
  });
  if ($ref) { flash('Added. Fill it in, then publish it (it stays hidden until you do).'); redirect(u('edit', ['c' => $cid, 't' => $type, 'id' => $ref])); }
  redirect(u('modules', ['c' => $cid]) . '#' . $mid);
}

function r_item_save() {
  $cid = safe_id(get('c')); require_admin(); require_course($cid);
  if (!is_post()) redirect(u('modules', ['c' => $cid]));
  $mid = safe_id(post('mid')); $iid = safe_id(post('iid'));
  $removed = null;
  update_content($cid, function ($c) use ($mid, $iid, &$removed, $cid) {
    foreach ($c['modules'] as $mi => &$m) {
      if ($m['id'] !== $mid) continue;
      foreach ($m['items'] as $i => &$it) {
        if ($it['id'] !== $iid) continue;
        if (post('toggle')) $it['published'] = empty($it['published']);
        if (post('indent')) $it['indent'] = max(0, min(3, (int)($it['indent'] ?? 0) + (int)post('indent')));
        if (post('title') !== '' && $it['type'] === 'header') $it['title'] = post('title');
        if (post('move')) {
          $j = $i + (int)post('move');
          if ($j >= 0 && $j < count($m['items'])) { $tmp = $m['items'][$j]; $m['items'][$j] = $m['items'][$i]; $m['items'][$i] = $tmp; }
          return $c;
        }
        if (post('to')) {
          if (!in_array(safe_id(post('to')), array_column($c['modules'], 'id'), true)) return null;
          $item = $it; array_splice($m['items'], $i, 1);
          foreach ($c['modules'] as &$m2) if ($m2['id'] === safe_id(post('to'))) $m2['items'][] = $item;
          return $c;
        }
        if (post('delete')) {
          $removed = $it;
          array_splice($m['items'], $i, 1);
          if ($it['type'] !== 'header') {
            $still = placements($c, $it['type'], $it['ref']);
            $hasWork = in_array($it['type'], ['assignment', 'quiz', 'discussion'], true) && (is_dir(dpath("c/$cid/subs/" . $it['ref'])) || ($it['type'] === 'discussion' && posts($cid, $it['ref'])));
            if (!$still && !$hasWork) unset($c[TYPE_STORE[$it['type']]][$it['ref']]);
            elseif (!$still && $hasWork) $c[TYPE_STORE[$it['type']]][$it['ref']]['published'] = false;
          }
          return $c;
        }
        break;
      }
    }
    return $c;
  });
  if ($removed) flash('Removed “' . h($removed['title']) . '”.');
  redirect(u('modules', ['c' => $cid]) . '#' . $mid);
}

// ---------- the editor ----------
function editor_field($cid, $name, $html, $rows = 14) {
  return '<div class="editor" data-upload="' . h(u('upload_asset', ['c' => $cid])) . '">'
    . '<div class="ed-bar" role="toolbar">'
    . '<button type="button" data-cmd="formatBlock" data-val="h2" title="Heading">H2</button><button type="button" data-cmd="formatBlock" data-val="h3" title="Subheading">H3</button><button type="button" data-cmd="formatBlock" data-val="p" title="Paragraph">¶</button>'
    . '<span class="sep"></span><button type="button" data-cmd="bold" title="Bold"><b>B</b></button><button type="button" data-cmd="italic" title="Italic"><i>I</i></button>'
    . '<button type="button" data-cmd="insertUnorderedList" title="Bullets">• List</button><button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>'
    . '<span class="sep"></span><button type="button" data-act="link" title="Link">Link</button><button type="button" data-cmd="unlink" title="Remove link">Unlink</button>'
    . '<button type="button" data-act="image" title="Upload image">Image</button><button type="button" data-act="video" title="Embed a video lecture">▶ Video</button><button type="button" data-act="file" title="Upload a file and link to it">File</button>'
    . '<span class="sep"></span><button type="button" data-act="source" title="Edit HTML">&lt;/&gt; HTML</button>'
    . '</div><div class="ed-area prose" contenteditable="true"></div>'
    . '<textarea name="' . h($name) . '" class="ed-src mono" rows="' . (int)$rows . '">' . h($html) . '</textarea>'
    . '<input type="file" class="ed-file" hidden></div>';
}

function availability_fields($cid, $type, $id) {
  $course = course($cid);
  $pl = placements(content($cid), $type, $id);
  if (!$pl) {
    $o = obj($cid, $type, $id);
    $mods = content($cid)['modules'];
    $out = '<fieldset class="card"><legend>Release</legend><p class="small">This item isn’t in any module' . (empty($o['published']) ? ', so students can’t see it.' : '. Students can open it' . (!empty($o['unlock_at']) ? ' from ' . h(fmt_dt($o['unlock_at'], $course['tz'])) : '') . '.') . '</p>';
    if ($mods) {
      $out .= '<label>Put it in a module <select name="_place"><option value="">Leave it out of modules</option>';
      foreach ($mods as $m) $out .= '<option value="' . h($m['id']) . '">' . h(mb_substr($m['title'], 0, 50)) . '</option>';
      $out .= '</select><small>It is added hidden; publish it from the module.</small></label>';
    }
    return $out . '</fieldset>';
  }
  [$m, $it] = $pl[0];
  $o = '<fieldset class="card"><legend>Release</legend>';
  $o .= '<label class="check"><input type="checkbox" name="_published" value="1" ' . (!empty($it['published']) ? 'checked' : '') . '> Published</label>';
  $o .= '<label>Opens to students <input type="datetime-local" name="_unlock_at" value="' . h(dt_input($it['unlock_at'] ?? 0, $course['tz'])) . '"><small>In module “' . h($m['title']) . '”' . (!empty($m['unlock_at']) ? ', which opens ' . h(fmt_dt($m['unlock_at'], $course['tz'])) . '. The later of the two dates applies' : '') . '. Leave empty to open with the module.' . (empty($m['published']) ? ' <b>The module itself is hidden.</b>' : '') . '</small></label>';
  return $o . '</fieldset>';
}
function save_availability($c, $type, $id, $title, $tz) {
  $place = safe_id(post('_place'));
  if ($place && !placements($c, $type, $id)) {
    foreach ($c['modules'] as &$pm) if ($pm['id'] === $place) $pm['items'][] = ['id' => newid('i'), 'type' => $type, 'ref' => $id, 'title' => $title, 'indent' => 1, 'published' => false, 'unlock_at' => 0];
    unset($pm);
    $c[TYPE_STORE[$type]][$id]['published'] = true;
  }
  foreach ($c['modules'] as &$m) foreach ($m['items'] as &$it) {
    if ($it['type'] === $type && ($it['ref'] ?? '') === $id) {
      $it['title'] = $title;
      if (array_key_exists('_unlock_at', $_POST)) { $it['published'] = post('_published') === '1'; $it['unlock_at'] = input_ts(post('_unlock_at'), $tz); }
    }
  }
  return $c;
}

function group_select($course, $val) {
  $o = '<select name="group">';
  foreach ($course['groups'] as $g) $o .= '<option value="' . h($g['id']) . '"' . ($g['id'] === $val ? ' selected' : '') . '>' . h($g['title']) . ($course['weighted'] ? ' (' . fmt_num($g['weight']) . '%)' : '') . '</option>';
  return $o . '</select>';
}

function r_edit() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $type = get('t'); $id = safe_id(get('id'));
  $tz = $course['tz'];
  $content = content($cid);

  if ($type === 'syllabus') {
    if (is_post()) { $b = (string)($_POST['body'] ?? ''); update_content($cid, function ($c) use ($b) { $c['syllabus'] = $b; return $c; }); flash('Syllabus saved.'); redirect(u('syllabus', ['c' => $cid])); }
    ob_start(); ?>
    <h1>Edit syllabus</h1><form method="post" class="stack"><?= csrf_field() ?><?= editor_field($cid, 'body', $content['syllabus'], 20) ?><div class="row"><button class="btn primary">Save</button><a class="btn" href="<?= h(u('syllabus', ['c' => $cid])) ?>">Cancel</a></div></form>
    <?php layout('Edit syllabus', ob_get_clean(), ['course' => $cid, 'active' => 'syllabus']); return;
  }

  if ($type === 'announcement') {
    $a = $id ? ($content['announcements'][$id] ?? null) : null;
    if (is_post()) {
      $new = !$a;
      $a = ($a ?: ['id' => newid('a'), 'created' => now(), 'notified' => false]);
      $a['title'] = post('title') ?: 'Announcement';
      $a['body'] = (string)($_POST['body'] ?? '');
      $a['post_at'] = input_ts(post('post_at'), $tz);
      update_content($cid, function ($c) use ($a) { $c['announcements'][$a['id']] = $a; return $c; });
      deliver_announcements($cid);
      flash($a['post_at'] > now() ? 'Announcement scheduled for ' . h(fmt_dt($a['post_at'], $tz)) . '.' : ($new ? 'Announcement posted; students were notified.' : 'Announcement saved.'));
      redirect(u('announcements', ['c' => $cid]));
    }
    ob_start(); ?>
    <h1><?= $a ? 'Edit' : 'New' ?> announcement</h1>
    <form method="post" class="stack"><?= csrf_field() ?>
      <label>Title <input name="title" value="<?= h($a['title'] ?? '') ?>" required></label>
      <?= editor_field($cid, 'body', $a['body'] ?? '', 10) ?>
      <label class="narrow">Post at <input type="datetime-local" name="post_at" value="<?= h(dt_input($a['post_at'] ?? 0, $tz)) ?>"><small>Leave empty to post now. A future time schedules it; students are notified when it goes up.</small></label>
      <div class="row"><button class="btn primary">Save</button><a class="btn" href="<?= h(u('announcements', ['c' => $cid])) ?>">Cancel</a></div>
    </form>
    <?php layout('Announcement', ob_get_clean(), ['course' => $cid, 'active' => 'announcements']); return;
  }

  if (!isset(TYPE_STORE[$type])) throw new HttpError('Unknown item type.');
  $o = obj($cid, $type, $id);
  if (!$o) throw new HttpError('Item not found.');
  $back = item_url($cid, $type, $id);

  if (is_post()) {
    $o['title'] = post('title') ?: $o['title'];
    if (array_key_exists('body', $_POST)) $o['body'] = (string)$_POST['body'];
    switch ($type) {
      case 'video':
        $o['url'] = post('url'); $o['duration'] = post('duration'); $o['slides'] = post('slides');
        if ($f = uploaded_files('vfile')[0] ?? null) { $o['url'] = cfile_url($cid, save_course_file($cid, $f, 'videos')); }
        break;
      case 'link': $o['url'] = post('url'); if ($o['url'] && !preg_match('~^(https?:|mailto:)~i', $o['url'])) $o['url'] = 'https://' . $o['url']; break;
      case 'file':
        if ($f = uploaded_files('file')[0] ?? null) $o['path'] = save_course_file($cid, $f);
        elseif (post('path')) $o['path'] = safe_rel_path(post('path'));
        break;
      case 'assignment':
        $o['points'] = max(0, (float)post('points')); $o['group'] = safe_id(post('group'));
        $o['due_at'] = input_ts(post('due_at'), $tz); $o['lock_at'] = input_ts(post('lock_at'), $tz);
        $st = array_values(array_intersect((array)($_POST['sub_types'] ?? []), ['upload', 'text', 'url']));
        $o['sub_types'] = $st ?: ['none'];
        $o['allowed_ext'] = preg_replace('/[^a-z0-9,]/', '', strtolower(str_replace(' ', '', post('allowed_ext'))));
        $o['attempts'] = max(0, (int)post('attempts'));
        $o['post_policy'] = post('post_policy') === 'manual' ? 'manual' : 'auto';
        $o['omit'] = post('omit') === '1';
        $rub = [];
        foreach ((array)($_POST['k_title'] ?? []) as $i => $t) {
          $t = trim($t); if ($t === '') continue;
          $rub[] = ['id' => safe_id($_POST['k_id'][$i] ?? '') ?: newid('k'), 'title' => $t, 'desc' => trim($_POST['k_desc'][$i] ?? ''), 'points' => (float)($_POST['k_points'][$i] ?? 0)];
        }
        $o['rubric'] = $rub;
        break;
      case 'discussion':
        $o['points'] = max(0, (float)post('points')); $o['group'] = safe_id(post('group'));
        $o['due_at'] = input_ts(post('due_at'), $tz); $o['lock_at'] = input_ts(post('lock_at'), $tz);
        $o['require_initial'] = post('require_initial') === '1'; $o['locked'] = post('locked') === '1';
        break;
      case 'quiz':
        $o['group'] = safe_id(post('group'));
        $o['due_at'] = input_ts(post('due_at'), $tz); $o['lock_at'] = input_ts(post('lock_at'), $tz);
        $o['time_limit'] = max(0, (int)post('time_limit')); $o['attempts'] = max(0, (int)post('attempts'));
        $o['shuffle'] = post('shuffle') === '1';
        $wasPractice = !empty($o['practice']);
        $o['practice'] = post('practice') === '1';
        if ($o['practice']) $o['omit'] = true; elseif ($wasPractice) $o['omit'] = false; $o['show_answers'] = in_array(post('show_answers'), ['after_submit', 'after_due', 'never'], true) ? post('show_answers') : 'after_submit';
        $o['post_policy'] = post('post_policy') === 'manual' ? 'manual' : 'auto';
        $qs = json_decode((string)($_POST['questions_json'] ?? '[]'), true);
        if (is_array($qs)) $o['questions'] = normalize_questions($qs);
        $o['points'] = quiz_points($o);
        break;
    }
    $store = TYPE_STORE[$type];
    update_content($cid, function ($c) use ($store, $o, $type, $id, $tz) {
      $c[$store][$id] = $o;
      return save_availability($c, $type, $id, $o['title'], $tz);
    });
    flash('Saved.');
    redirect(post('stay') ? u('edit', ['c' => $cid, 't' => $type, 'id' => $id]) : $back);
  }

  ob_start(); ?>
  <div class="head-row"><h1>Edit <?= h(strtolower(TYPES[$type])) ?></h1><a class="btn sm" href="<?= h($back) ?>">View</a></div>
  <form method="post" enctype="multipart/form-data" class="stack editform" id="editform"><?= csrf_field() ?>
  <div class="grid-main">
  <div class="stack">
    <label>Title <input name="title" value="<?= h($o['title']) ?>" required></label>
    <?php if ($type === 'video'): ?>
      <fieldset class="card"><legend>Video</legend>
        <label>Video link <input name="url" value="<?= h($o['url'] ?? '') ?>" placeholder="YouTube, Vimeo, Google Drive, Loom, Panopto or a .mp4 address" data-video-preview="#vprev"><small>Unlisted YouTube or Vimeo works best: no storage or bandwidth on your hosting, and students get captions and speed controls.</small></label>
        <label>Or upload a video file <input type="file" name="vfile" accept="video/*,audio/*"><small>Up to <?= fmt_size(upload_limit()) ?> on this server.</small></label>
        <div id="vprev"><?= !empty($o['url']) ? video_embed($o['url'], $o['title']) : '' ?></div>
        <div class="grid2"><label>Length <input name="duration" value="<?= h($o['duration'] ?? '') ?>" placeholder="e.g. 14 min"></label><label>Slides link <input name="slides" value="<?= h($o['slides'] ?? '') ?>" placeholder="https://"></label></div>
      </fieldset>
      <label>Notes under the video (optional)</label><?= editor_field($cid, 'body', $o['body'] ?? '', 8) ?>
    <?php elseif ($type === 'link'): ?>
      <label>Address <input name="url" value="<?= h($o['url'] ?? '') ?>" placeholder="https://" required></label>
    <?php elseif ($type === 'file'): ?>
      <?php if (!empty($o['path'])): ?><p>Current file: <a href="<?= h(cfile_url($cid, $o['path'])) ?>" target="_blank"><?= h($o['path']) ?></a></p><?php endif; ?>
      <label>Upload a file <input type="file" name="file"></label>
      <label>Or choose an existing course file <select name="path"><option value="">—</option><?php foreach (list_course_files($cid) as $f) echo '<option' . ($f['path'] === ($o['path'] ?? '') ? ' selected' : '') . '>' . h($f['path']) . '</option>'; ?></select></label>
    <?php elseif ($type === 'quiz'): ?>
      <label>Instructions</label><?= editor_field($cid, 'body', $o['body'] ?? '', 6) ?>
      <fieldset class="card"><legend>Questions</legend>
        <?php if (!empty($o['pools'])): ?><div class="flash warn small">Besides the questions below, each attempt draws <?= implode(' and ', array_map(function ($p) { return (int)$p['pick'] . ' question' . ($p['pick'] == 1 ? '' : 's') . ' at random from “' . h($p['title'] ?: 'question bank') . '” (' . (int)$p['count'] . ' questions, ' . fmt_num($p['points']) . ' pt each)'; }, $o['pools'])) ?>. The bank came from your Canvas import and is kept as it is.</div><?php endif; ?>
        <div id="qbuilder" data-json="<?= h(json_encode($o['questions'] ?? [])) ?>"></div>
        <input type="hidden" name="questions_json" id="questions_json">
      </fieldset>
    <?php else: ?>
      <?= editor_field($cid, 'body', $o['body'] ?? '', $type === 'page' ? 22 : 12) ?>
    <?php endif; ?>
    <?php if ($type === 'assignment'): ?>
      <fieldset class="card"><legend>Rubric (optional)</legend>
        <table class="tbl"><thead><tr><th>Criterion</th><th>Description</th><th class="num">Points</th><th></th></tr></thead><tbody id="rubric">
        <?php foreach ($o['rubric'] ?? [] as $k): ?><tr><td><input type="hidden" name="k_id[]" value="<?= h($k['id']) ?>"><input name="k_title[]" value="<?= h($k['title']) ?>"></td><td><textarea name="k_desc[]" rows="2"><?= h($k['desc']) ?></textarea></td><td class="num"><input type="number" step="any" name="k_points[]" value="<?= h(fmt_num($k['points'])) ?>" class="w6"></td><td><button type="button" class="linkish" data-remove-row>Remove</button></td></tr><?php endforeach; ?>
        </tbody></table>
        <button type="button" class="btn sm" data-add-row="#rubric" data-template="rub-row"><?= icon('plus') ?> Add criterion</button>
        <template id="rub-row"><tr><td><input type="hidden" name="k_id[]" value=""><input name="k_title[]" placeholder="e.g. Scope statement"></td><td><textarea name="k_desc[]" rows="2"></textarea></td><td class="num"><input type="number" step="any" name="k_points[]" value="1" class="w6"></td><td><button type="button" class="linkish" data-remove-row>Remove</button></td></tr></template>
        <p class="muted small">When a rubric is set, scoring each criterion fills in the total for you.</p>
      </fieldset>
    <?php endif; ?>
  </div>
  <aside class="stack">
    <?= availability_fields($cid, $type, $id) ?>
    <?php if (in_array($type, ['assignment', 'discussion', 'quiz'], true)): ?>
      <fieldset class="card"><legend>Grading &amp; dates</legend>
        <?php if ($type !== 'quiz'): ?><label>Points <input type="number" step="any" min="0" name="points" value="<?= h(fmt_num($o['points'] ?? 0)) ?>" class="w6"><?php if ($type === 'discussion'): ?><small>0 means ungraded.</small><?php endif; ?></label><?php else: ?><p>Points: <b id="qpoints"><?= fmt_num(quiz_points($o)) ?></b> <small class="muted">(sum of questions)</small></p><?php endif; ?>
        <label>Assignment group <?= group_select($course, $o['group'] ?? '') ?></label>
        <label>Due <input type="datetime-local" name="due_at" value="<?= h(dt_input($o['due_at'] ?? 0, $tz)) ?>"></label>
        <label>Closes (no submissions after) <input type="datetime-local" name="lock_at" value="<?= h(dt_input($o['lock_at'] ?? 0, $tz)) ?>"><small>Empty means late work is accepted any time.</small></label>
        <?php if ($type !== 'discussion'): ?>
        <label>Release grades <select name="post_policy"><option value="auto">As soon as I grade each one</option><option value="manual" <?= ($o['post_policy'] ?? '') === 'manual' ? 'selected' : '' ?>>When I click “Post grades”</option></select></label>
        <?php endif; ?>
      </fieldset>
    <?php endif; ?>
    <?php if ($type === 'assignment'): ?>
      <fieldset class="card"><legend>Submissions</legend>
        <?php $st = $o['sub_types'] ?? ['upload']; foreach (['upload' => 'File upload', 'text' => 'Text entry', 'url' => 'Website or video link'] as $k => $v): ?><label class="check"><input type="checkbox" name="sub_types[]" value="<?= $k ?>" <?= in_array($k, $st, true) ? 'checked' : '' ?>> <?= $v ?></label><?php endforeach; ?>
        <small class="muted">Uncheck all for work graded without an online submission (e.g. participation).</small>
        <label>Allowed file types <input name="allowed_ext" value="<?= h($o['allowed_ext'] ?? '') ?>" placeholder="pdf,docx,owl"><small>Comma-separated. Empty allows any safe type.</small></label>
        <label>Attempts allowed <input type="number" min="0" name="attempts" value="<?= (int)($o['attempts'] ?? 0) ?>" class="w6"><small>0 = unlimited resubmissions.</small></label>
        <label class="check"><input type="checkbox" name="omit" value="1" <?= !empty($o['omit']) ? 'checked' : '' ?>> Don’t count toward the final grade</label>
      </fieldset>
    <?php elseif ($type === 'discussion'): ?>
      <fieldset class="card"><legend>Options</legend>
        <label class="check"><input type="checkbox" name="require_initial" value="1" <?= !empty($o['require_initial']) ? 'checked' : '' ?>> Students must post before seeing replies</label>
        <label class="check"><input type="checkbox" name="locked" value="1" <?= !empty($o['locked']) ? 'checked' : '' ?>> Closed to new posts</label>
      </fieldset>
    <?php elseif ($type === 'quiz'): ?>
      <fieldset class="card"><legend>Quiz options</legend>
        <label>Time limit (minutes) <input type="number" min="0" name="time_limit" value="<?= (int)($o['time_limit'] ?? 0) ?>" class="w6"><small>0 = no limit.</small></label>
        <label>Attempts allowed <input type="number" min="0" name="attempts" value="<?= (int)($o['attempts'] ?? 1) ?>" class="w6"><small>0 = unlimited. The highest score counts.</small></label>
        <label class="check"><input type="checkbox" name="shuffle" value="1" <?= !empty($o['shuffle']) ? 'checked' : '' ?>> Shuffle answer choices</label>
        <label class="check"><input type="checkbox" name="practice" value="1" <?= !empty($o['practice']) ? 'checked' : '' ?>> Practice quiz: doesn’t count toward the grade, and shows answers and explanations after every attempt</label>
        <label>Show correct answers <select name="show_answers"><?php foreach (['after_submit' => 'After the student’s last attempt', 'after_due' => 'After the due date', 'never' => 'Never'] as $k => $v) echo '<option value="' . $k . '"' . (($o['show_answers'] ?? '') === $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select></label>
      </fieldset>
    <?php endif; ?>
    <div class="card stack"><button class="btn primary">Save</button><button class="btn" name="stay" value="1">Save and keep editing</button><a class="btn" href="<?= h($back) ?>">Cancel</a></div>
  </aside>
  </div>
  </form>
  <?php layout('Edit ' . $o['title'], ob_get_clean(), ['course' => $cid, 'active' => 'modules']);
}

function normalize_questions($qs) {
  $out = [];
  foreach ($qs as $q) {
    if (!is_array($q)) continue;
    $type = in_array($q['type'] ?? '', ['mc', 'tf', 'multi', 'short', 'numeric', 'essay', 'text'], true) ? $q['type'] : 'mc';
    $n = ['id' => safe_id($q['id'] ?? '') ?: newid('q'), 'type' => $type, 'text' => (string)($q['text'] ?? ''), 'points' => $type === 'text' ? 0 : max(0, (float)($q['points'] ?? 1)),
      'choices' => [], 'answers' => [], 'feedback' => (string)($q['feedback'] ?? '')];
    if (in_array($type, ['mc', 'tf', 'multi'], true)) {
      foreach ((array)($q['choices'] ?? []) as $c) {
        if (!is_array($c) || trim((string)($c['text'] ?? '')) === '') continue;
        $n['choices'][] = ['id' => safe_id($c['id'] ?? '') ?: newid('o'), 'text' => trim((string)$c['text']), 'correct' => !empty($c['correct'])];
      }
    }
    if ($type === 'short') $n['answers'] = array_values(array_filter(array_map('trim', (array)($q['answers'] ?? []))));
    if ($type === 'numeric') { $n['value'] = (float)($q['value'] ?? 0); $n['tolerance'] = abs((float)($q['tolerance'] ?? 0)); }
    $out[] = $n;
  }
  return $out;
}

function r_delete_obj() {
  $cid = safe_id(get('c')); require_admin(); require_course($cid);
  if (is_post() && post('t') === 'announcement') {
    $id = safe_id(post('id'));
    update_content($cid, function ($c) use ($id) { unset($c['announcements'][$id]); return $c; });
    flash('Announcement deleted.');
    redirect(u('announcements', ['c' => $cid]));
  }
  redirect(u('course', ['c' => $cid]));
}

// Posts any scheduled announcements whose time has come (runs on course page loads).
function deliver_announcements($cid) {
  $due = [];
  foreach (content($cid)['announcements'] as $a) if (empty($a['notified']) && (int)($a['post_at'] ?? 0) <= now()) $due[] = $a['id'];
  if (!$due) return;
  $c = update_content($cid, function ($c) use ($due) { foreach ($due as $id) $c['announcements'][$id]['notified'] = true; return $c; });
  $course = course($cid);
  foreach ($due as $id) foreach (array_keys(active_students($cid)) as $uid) notify($uid, h($course['code'] ?: $course['title']) . ': ' . h($c['announcements'][$id]['title']), u('announcements', ['c' => $cid]));
}

// ---------- release schedule ----------
function r_schedule() {
  $cid = safe_id(get('c')); require_admin(); $course = require_course($cid);
  $tz = $course['tz'];
  if (is_post()) {
    if (post('shift') !== '') {
      $days = (int)post('shift');
      $sec = $days * 86400;
      $shift = function ($ts) use ($sec, $tz) {
        if (!$ts) return 0;
        // Shift by calendar days in the course time zone so DST changes keep the clock time.
        $d = (new DateTime('@' . $ts))->setTimezone(tz_obj($tz));
        $d->modify(($sec >= 0 ? '+' : '') . intdiv($sec, 86400) . ' days');
        return $d->getTimestamp();
      };
      update_content($cid, function ($c) use ($shift) {
        foreach ($c['modules'] as &$m) { $m['unlock_at'] = $shift($m['unlock_at'] ?? 0); foreach ($m['items'] as &$it) $it['unlock_at'] = $shift($it['unlock_at'] ?? 0); unset($it); }
        unset($m);
        foreach (['assignments', 'quizzes', 'discussions'] as $s) foreach ($c[$s] as &$o) { $o['due_at'] = $shift($o['due_at'] ?? 0); $o['lock_at'] = $shift($o['lock_at'] ?? 0); }
        unset($o);
        foreach ($c['announcements'] as &$a) if (!empty($a['post_at']) && empty($a['notified'])) $a['post_at'] = $shift($a['post_at']);
        return $c;
      });
      flash('Every date moved by ' . $days . ' day' . (abs($days) == 1 ? '' : 's') . '.');
      redirect(u('schedule', ['c' => $cid]));
    }
    update_content($cid, function ($c) use ($tz) {
      foreach ($c['modules'] as &$m) {
        if (isset($_POST['m_un'][$m['id']])) $m['unlock_at'] = input_ts($_POST['m_un'][$m['id']], $tz);
        $m['published'] = isset($_POST['m_pub'][$m['id']]);
        foreach ($m['items'] as &$it) {
          $k = $m['id'] . '|' . $it['id'];
          if (isset($_POST['i_un'][$k])) $it['unlock_at'] = input_ts($_POST['i_un'][$k], $tz);
          $it['published'] = isset($_POST['i_pub'][$k]);
          if (!empty($it['ref']) && isset(TYPE_STORE[$it['type']])) {
            $s = TYPE_STORE[$it['type']];
            if (isset($_POST['due'][$it['ref']])) $c[$s][$it['ref']]['due_at'] = input_ts($_POST['due'][$it['ref']], $tz);
            if (isset($_POST['lock'][$it['ref']])) $c[$s][$it['ref']]['lock_at'] = input_ts($_POST['lock'][$it['ref']], $tz);
          }
        }
      }
      return $c;
    });
    flash('Schedule saved.');
    redirect(u('schedule', ['c' => $cid]));
  }
  $content = content($cid);
  ob_start(); ?>
  <div class="head-row"><h1>Release schedule</h1><a class="btn sm" href="<?= h(u('calendar', ['c' => $cid])) ?>"><?= icon('cal') ?> Calendar view</a></div>
  <p class="muted">Each module opens at its date; an item can open later than its module. Students see nothing that is hidden or not yet open. All times are <?= h($tz) ?>. Empty means “no date”.</p>
  <form method="post"><?= csrf_field() ?>
  <div class="tbl-wrap"><table class="tbl sched"><thead><tr><th>Shown</th><th>Module / item</th><th>Opens</th><th>Due</th><th>Closes</th></tr></thead><tbody>
  <?php foreach ($content['modules'] as $m): ?>
    <tr class="mod-row"><td><input type="checkbox" name="m_pub[<?= h($m['id']) ?>]" <?= !empty($m['published']) ? 'checked' : '' ?> aria-label="Module published"></td><td><b><?= h($m['title']) ?></b></td><td><input type="datetime-local" name="m_un[<?= h($m['id']) ?>]" value="<?= h(dt_input($m['unlock_at'] ?? 0, $tz)) ?>"></td><td></td><td></td></tr>
    <?php foreach ($m['items'] as $it): $k = $m['id'] . '|' . $it['id']; $o = !empty($it['ref']) ? obj($cid, $it['type'], $it['ref']) : null; $grad = in_array($it['type'], ['assignment', 'quiz', 'discussion'], true); ?>
      <tr><td><input type="checkbox" name="i_pub[<?= h($k) ?>]" <?= !empty($it['published']) ? 'checked' : '' ?> aria-label="Item published"></td>
        <td class="ind<?= min(3, (int)($it['indent'] ?? 0)) ?>"><span class="ti t-<?= h($it['type']) ?>"><?= icon($it['type']) ?></span> <?= h($it['title']) ?></td>
        <td><?= $it['type'] !== 'header' ? '<input type="datetime-local" name="i_un[' . h($k) . ']" value="' . h(dt_input($it['unlock_at'] ?? 0, $tz)) . '">' : '' ?></td>
        <td><?= $grad && $o ? '<input type="datetime-local" name="due[' . h($it['ref']) . ']" value="' . h(dt_input($o['due_at'] ?? 0, $tz)) . '">' : '' ?></td>
        <td><?= $grad && $o ? '<input type="datetime-local" name="lock[' . h($it['ref']) . ']" value="' . h(dt_input($o['lock_at'] ?? 0, $tz)) . '">' : '' ?></td></tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="row sticky-actions"><button class="btn primary">Save schedule</button></div>
  </form>
  <form method="post" class="card row"><?= csrf_field() ?><b>Teaching this again?</b> Move every date by <input type="number" name="shift" class="w6" required> days <button class="btn" data-confirm="Move every release date, due date and closing date by this many days?">Shift all dates</button><small class="muted">Use a negative number to move earlier. 52 weeks = 364 days keeps the weekdays.</small></form>
  <?php layout('Release schedule', ob_get_clean(), ['course' => $cid, 'active' => 'schedule']);
}

// Drag-and-drop on the calendar. Keeps the original clock time.
function r_cal_move() {
  $cid = safe_id(get('c'));
  header('Content-Type: application/json');
  try {
    require_admin(); $course = require_course($cid);
    if (!is_post() || !csrf_ok()) throw new HttpError('Bad request');
    $date = post('date');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new HttpError('Bad date');
    $parts = explode(':', post('move'));
    $tz = $course['tz'];
    $re = function ($ts) use ($date, $tz) {
      $d = (new DateTime('@' . $ts))->setTimezone(tz_obj($tz));
      $n = new DateTime($date . ' ' . $d->format('H:i:s'), tz_obj($tz));
      return $n->getTimestamp();
    };
    update_content($cid, function ($c) use ($parts, $re) {
      switch ($parts[0]) {
        case 'mod': foreach ($c['modules'] as &$m) if ($m['id'] === ($parts[1] ?? '') && !empty($m['unlock_at'])) $m['unlock_at'] = $re($m['unlock_at']); break;
        case 'item': foreach ($c['modules'] as &$m) if ($m['id'] === ($parts[1] ?? '')) foreach ($m['items'] as &$it) if ($it['id'] === ($parts[2] ?? '') && !empty($it['unlock_at'])) $it['unlock_at'] = $re($it['unlock_at']); break;
        case 'due': case 'lock':
          $s = TYPE_STORE[$parts[1] ?? ''] ?? null; $f = $parts[0] === 'due' ? 'due_at' : 'lock_at';
          if ($s && !empty($c[$s][$parts[2] ?? '']) && !empty($c[$s][$parts[2]][$f])) $c[$s][$parts[2]][$f] = $re($c[$s][$parts[2]][$f]);
          break;
        case 'ann': if (!empty($c['announcements'][$parts[1] ?? '']['post_at'])) $c['announcements'][$parts[1]]['post_at'] = $re($c['announcements'][$parts[1]]['post_at']); break;
      }
      return $c;
    });
    echo json_encode(['ok' => true]);
  } catch (Exception $e) { http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}
