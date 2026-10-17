<?php
// Canvas / IMS Common Cartridge (.imscc) importer.
if (!defined('APP')) { http_response_code(404); exit; }

function xml_load($s) {
  if ($s === false || $s === null || trim($s) === '') return null;
  // Drop namespace declarations so plain element names work everywhere.
  $s = preg_replace('/\sxmlns(:[\w.-]+)?\s*=\s*("[^"]*"|\'[^\']*\')/', '', $s);
  $s = preg_replace('/\sxsi:schemaLocation\s*=\s*("[^"]*"|\'[^\']*\')/', '', $s);
  $prev = libxml_use_internal_errors(true);
  $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
  libxml_clear_errors(); libxml_use_internal_errors($prev);
  return $x ?: null;
}
function xs($node) { return $node === null ? '' : trim((string)$node); }

// Canvas exports timestamps in UTC without a zone suffix.
function cc_time($s) {
  $s = trim((string)$s);
  if ($s === '') return 0;
  try { $d = new DateTime($s, new DateTimeZone('UTC')); return $d->getTimestamp(); } catch (Exception $e) { return 0; }
}

function html_body($html) {
  if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $m)) return trim($m[1]);
  return trim(preg_replace('~^.*?<html[^>]*>|</html>.*$~is', '', $html));
}
function html_title($html) {
  return preg_match('~<title>(.*?)</title>~is', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') : '';
}
function html_meta($html, $name) {
  return preg_match('~<meta\s+name="' . preg_quote($name, '~') . '"\s+content="([^"]*)"~i', $html, $m) ? $m[1] : '';
}

/**
 * Import a cartridge into a NEW course. Returns ['cid'=>..., 'log'=>[...]].
 * $opt: ['infer_release' => bool]
 */
function import_imscc($zipPath, $opt = []) {
  if (!function_exists('simplexml_load_string')) throw new HttpError('This server’s PHP is missing XML support (SimpleXML), which is needed to read course cartridges. In your hosting control panel, try a newer PHP version, or ask your host to enable SimpleXML.');
  $zip = open_zip($zipPath);
  if (!$zip) throw new HttpError('That file could not be opened as a course cartridge (.imscc is a zip file).');
  $read = function ($name) use ($zip) {
    $name = ltrim(rawurldecode($name), '/');
    $s = $zip->getFromName($name);
    return $s === false ? null : $s;
  };
  $log = [];
  $manifest = xml_load($read('imsmanifest.xml'));
  if (!$manifest) throw new HttpError('This cartridge has no readable imsmanifest.xml.');

  // ---- resources ----
  $res = [];
  foreach ($manifest->resources->resource ?? [] as $r) {
    $id = (string)$r['identifier'];
    $files = []; foreach ($r->file as $f) $files[] = (string)$f['href'];
    $deps = []; foreach ($r->dependency as $d) $deps[] = (string)$d['identifierref'];
    $res[$id] = ['id' => $id, 'type' => (string)$r['type'], 'href' => (string)$r['href'], 'files' => $files, 'deps' => $deps];
  }

  // ---- course settings ----
  $raw = $read('imsmanifest.xml');
  $title = preg_match('~<(?:\w+:)?title>\s*<(?:\w+:)?string[^>]*>(.*?)</~s', $raw, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8') : 'Imported course';
  $code = '';
  $cs = xml_load($read('course_settings/course_settings.xml'));
  if ($cs) { if (xs($cs->title)) $title = xs($cs->title); $code = xs($cs->course_code); }
  $cid = create_course($title, $code);
  $course = course($cid);
  $log[] = 'Created course “' . h($title) . '”.';

  $groups = [];
  $ag = xml_load($read('course_settings/assignment_groups.xml'));
  if ($ag) foreach ($ag->assignmentGroup as $g) {
    $groups[] = ['id' => safe_id((string)$g['identifier']) ?: newid('g'), 'title' => xs($g->title), 'weight' => (float)xs($g->group_weight)];
  }
  if ($groups) {
    $course['groups'] = $groups;
    $course['weighted'] = $cs ? (xs($cs->apply_assignment_group_weights) === 'true') : true;
    $sum = array_sum(array_column($groups, 'weight'));
    if ($sum <= 0) $course['weighted'] = false;
    $log[] = count($groups) . ' assignment groups' . ($course['weighted'] ? ' with weights (' . implode(', ', array_map(function ($g) { return h($g['title']) . ' ' . fmt_num($g['weight']) . '%'; }, $groups)) . ')' : '') . '.';
  }
  $defaultGroup = $course['groups'][0]['id'];
  $groupIds = array_column($course['groups'], 'id');
  $groupOf = function ($ref) use ($groupIds, $defaultGroup) { $ref = safe_id($ref); return in_array($ref, $groupIds, true) ? $ref : $defaultGroup; };

  // rubrics
  $rubrics = [];
  $rx = xml_load($read('course_settings/rubrics.xml'));
  if ($rx) foreach ($rx->rubric as $rb) {
    $crit = [];
    foreach ($rb->criteria->criterion ?? [] as $cr) {
      $crit[] = ['id' => newid('k'), 'title' => xs($cr->description), 'desc' => xs($cr->long_description), 'points' => (float)xs($cr->points)];
    }
    $rubrics[(string)$rb['identifier']] = $crit;
  }

  // ---- files (web_resources) ----
  $fileRes = []; // resource id => rel path
  $nfiles = 0; $skipped = 0;
  $base = course_files_dir($cid);
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (!str_starts_with($name, 'web_resources/') || str_ends_with($name, '/')) continue;
    $rel = safe_rel_path(substr($name, strlen('web_resources/')));
    if ($rel === '' || !upload_ok_name(basename($rel))) { $skipped++; continue; }
    $dest = "$base/$rel";
    ensure_dir(dirname($dest));
    $in = $zip->getStream($name); $out = fopen($dest, 'wb');
    if ($in && $out) { stream_copy_to_stream($in, $out); $nfiles++; }
    if ($in) fclose($in); if ($out) fclose($out);
  }
  foreach ($res as $r) if (str_starts_with($r['href'], 'web_resources/')) $fileRes[$r['id']] = safe_rel_path(substr(rawurldecode($r['href']), strlen('web_resources/')));
  if ($nfiles) $log[] = "$nfiles files copied (images, documents)." . ($skipped ? " $skipped skipped because of their file type." : '');

  $content = content_defaults();
  $slugToPage = [];
  $pending = []; // html bodies whose links need rewriting after all ids are known

  // ---- pages ----
  foreach ($res as $r) {
    if ($r['type'] !== 'webcontent' || !preg_match('~^wiki_content/(.+)\.html?$~i', rawurldecode($r['href']), $m)) continue;
    $html = $read($r['href']); if ($html === null) continue;
    $id = safe_id($r['id']);
    $slug = $m[1];
    $content['pages'][$id] = ['id' => $id, 'title' => html_title($html) ?: $slug, 'body' => html_body($html), 'slug' => $slug,
      'published' => html_meta($html, 'workflow_state') !== 'unpublished', 'updated' => now()];
    $slugToPage[strtolower($slug)] = $id;
  }

  // ---- assignments ----
  foreach ($res as $r) {
    $settingsFile = null;
    foreach ($r['files'] as $f) if (str_ends_with($f, 'assignment_settings.xml')) $settingsFile = $f;
    if (!$settingsFile) continue;
    $x = xml_load($read($settingsFile)); if (!$x) continue;
    $id = safe_id($r['id']);
    $html = $read($r['href']) ?? '';
    $types = array_filter(array_map('trim', explode(',', xs($x->submission_types))));
    $st = [];
    foreach ($types as $t) {
      if ($t === 'online_upload') $st[] = 'upload';
      elseif ($t === 'online_text_entry') $st[] = 'text';
      elseif ($t === 'online_url') $st[] = 'url';
      elseif ($t === 'media_recording') { $st[] = 'upload'; $st[] = 'url'; }
    }
    $st = array_values(array_unique($st)) ?: ['none'];
    $att = (int)xs($x->allowed_attempts);
    $a = ['id' => $id, 'title' => xs($x->title), 'body' => html_body($html), 'points' => (float)xs($x->points_possible),
      'group' => $groupOf(xs($x->assignment_group_identifierref)), 'due_at' => cc_time(xs($x->due_at)), 'lock_at' => cc_time(xs($x->lock_at)),
      'unlock_at' => cc_time(xs($x->unlock_at)), 'sub_types' => $st, 'allowed_ext' => xs($x->allowed_extensions), 'attempts' => $att > 0 ? $att : 0,
      'post_policy' => xs($x->muted) === 'true' ? 'manual' : 'auto', 'rubric' => $rubrics[xs($x->rubric_identifierref)] ?? [],
      'published' => xs($x->workflow_state) !== 'unpublished', 'omit' => xs($x->omit_from_final_grade) === 'true',
      'grading_type' => xs($x->grading_type) ?: 'points'];
    $content['assignments'][$id] = $a;
  }

  // ---- discussions ----
  foreach ($res as $r) {
    if ($r['type'] !== 'imsdt_xmlv1p1') continue;
    $x = xml_load($read($r['files'][0] ?? $r['href'])); if (!$x) continue;
    $id = safe_id($r['id']);
    $d = ['id' => $id, 'title' => xs($x->title), 'body' => html_entity_decode(xs($x->text), ENT_QUOTES, 'UTF-8'), 'points' => 0,
      'group' => $defaultGroup, 'due_at' => 0, 'lock_at' => 0, 'require_initial' => false, 'published' => true, 'post_policy' => 'auto'];
    // Canvas puts real HTML in <text> already decoded by SimpleXML; only decode if it still looks escaped.
    $d['body'] = str_contains(xs($x->text), '<') ? xs($x->text) : html_entity_decode(xs($x->text), ENT_QUOTES, 'UTF-8');
    $isAnnouncement = false; $postedAt = 0;
    foreach ($r['deps'] as $dep) {
      $mf = $res[$dep]['href'] ?? ($res[$dep]['files'][0] ?? null);
      $mx = $mf ? xml_load($read($mf)) : null;
      if (!$mx) continue;
      if (xs($mx->type) === 'announcement') { $isAnnouncement = true; $postedAt = cc_time(xs($mx->posted_at) ?: xs($mx->delayed_post_at)); }
      $d['require_initial'] = xs($mx->require_initial_post) === 'true';
      if (xs($mx->workflow_state) === 'unpublished') $d['published'] = false;
      if (isset($mx->assignment)) {
        $as = $mx->assignment;
        $d['points'] = (float)xs($as->points_possible);
        $d['due_at'] = cc_time(xs($as->due_at)); $d['lock_at'] = cc_time(xs($as->lock_at));
        $d['unlock_at'] = cc_time(xs($as->unlock_at));
        $d['group'] = $groupOf(xs($as->assignment_group_identifierref));
      }
      if (xs($mx->delayed_post_at)) $d['unlock_at'] = cc_time(xs($mx->delayed_post_at));
    }
    if ($isAnnouncement) {
      // Canvas exports announcements as discussion topics; bring them in as announcements.
      $content['announcements'][$id] = ['id' => $id, 'title' => $d['title'], 'body' => $d['body'], 'post_at' => $postedAt, 'created' => $postedAt ?: now(), 'notified' => true];
      continue;
    }
    $content['discussions'][$id] = $d;
  }

  // ---- quizzes ----
  $quizAlias = [];
  $qwarn = 0;
  $pools = []; // pool id => questions, written to their own files after links are rewritten
  $bankCache = [];
  $loadBank = function ($bankId) use ($read, &$bankCache) {
    $bankId = safe_id($bankId);
    if (!isset($bankCache[$bankId])) {
      $s = $read("non_cc_assessments/$bankId.xml.qti");
      $x = $s ? xml_load($s) : null;
      $title = '';
      if ($x) foreach ($x->xpath('//objectbank/qtimetadata/qtimetadatafield') ?: [] as $f) if (xs($f->fieldlabel) === 'bank_title') $title = xs($f->fieldentry);
      $qs = []; $w = 0;
      foreach ($x ? ($x->xpath('//item') ?: []) : [] as $it) { [$q, $bad] = qti_item($it); $qs[] = $q; $w += $bad; }
      $bankCache[$bankId] = ['title' => $title, 'questions' => $qs, 'warn' => $w];
    }
    return $bankCache[$bankId];
  };
  foreach ($res as $r) {
    $meta = null; $folder = null;
    foreach ($r['files'] as $f) if (preg_match('~^(.+)/assessment_meta\.xml$~', $f, $m)) { $meta = $f; $folder = $m[1]; }
    if (!$meta) continue;
    $mx = xml_load($read($meta));
    $qtiStr = $read("non_cc_assessments/$folder.xml.qti") ?? $read("$folder/assessment_qti.xml");
    foreach ($r['files'] as $f) if (!$qtiStr && str_ends_with($f, '.qti')) $qtiStr = $read($f);
    $id = safe_id($r['id']);
    [$questions, $warn, $qpools] = parse_qti($qtiStr, $loadBank);
    $qwarn += $warn;
    $poolMeta = [];
    foreach ($qpools as $p) {
      $pid = newid('pool');
      $pools[$pid] = $p['questions'];
      $poolMeta[] = ['id' => $pid, 'title' => $p['title'], 'pick' => min((int)$p['pick'], count($p['questions'])), 'points' => (float)$p['points'], 'count' => count($p['questions'])];
    }
    $as = $mx ? $mx->assignment : null;
    $q = ['id' => $id, 'title' => $mx ? xs($mx->title) : $id, 'body' => $mx ? xs($mx->description) : '', 'questions' => $questions, 'pools' => $poolMeta,
      'group' => $groupOf($mx ? (xs($mx->assignment_group_identifierref) ?: ($as ? xs($as->assignment_group_identifierref) : '')) : ''),
      'due_at' => cc_time($mx ? (xs($mx->due_at) ?: ($as ? xs($as->due_at) : '')) : ''),
      'lock_at' => cc_time($mx ? (xs($mx->lock_at) ?: ($as ? xs($as->lock_at) : '')) : ''),
      'unlock_at' => cc_time($mx ? xs($mx->unlock_at) : ''),
      'time_limit' => $mx ? (int)xs($mx->time_limit) : 0,
      'attempts' => $mx ? max(0, (int)xs($mx->allowed_attempts)) : 1,
      'shuffle' => $mx ? xs($mx->shuffle_answers) === 'true' : false,
      'show_answers' => ($mx && xs($mx->show_correct_answers) === 'false') ? 'never' : 'after_submit',
      'published' => !$mx || xs($mx->available) !== 'false', 'post_policy' => 'auto'];
    if ((int)($mx ? xs($mx->allowed_attempts) : 1) === -1) $q['attempts'] = 0;
    $q['points'] = quiz_points($q);
    $qtype = xs($mx ? $mx->quiz_type : '');
    if ($qtype === 'survey') $q['group'] = $defaultGroup;
    // Canvas practice quizzes and surveys don't count; practice quizzes show answers after every attempt.
    if (in_array($qtype, ['practice_quiz', 'survey'], true)) { $q['omit'] = true; $q['practice'] = $qtype === 'practice_quiz'; }
    $content['quizzes'][$id] = $q;
    foreach ($res as $r2) if (in_array($r['id'], $r2['deps'], true) || ($r2['href'] ?? '') === "$folder/assessment_qti.xml") $quizAlias[$r2['id']] = $id;
    $quizAlias[$folder] = $id;
  }
  $npools = 0; foreach ($content['quizzes'] as $qz) $npools += count($qz['pools']);
  if ($content['quizzes']) $log[] = count($content['quizzes']) . ' quizzes with ' . array_sum(array_map(function ($q) { return count($q['questions']); }, $content['quizzes'])) . ' questions' . ($npools ? ", plus $npools question bank draw" . ($npools > 1 ? 's' : '') . ' (each attempt gets its own random selection)' : '') . '.' . ($qwarn ? " $qwarn question(s) used types this site doesn’t auto-grade (matching, fill-in-multiple-blanks, dropdowns); they were imported as essay questions for you to grade by hand." : '');

  // ---- weblinks ----
  foreach ($res as $r) {
    if ($r['type'] !== 'imswl_xmlv1p1') continue;
    $x = xml_load($read($r['files'][0] ?? $r['href'])); if (!$x) continue;
    $id = safe_id($r['id']);
    $content['links'][$id] = ['id' => $id, 'title' => xs($x->title), 'url' => (string)$x->url['href']];
  }

  // ---- syllabus ----
  $syl = $read('course_settings/syllabus.html');
  if ($syl) $content['syllabus'] = html_body($syl);

  // ---- modules ----
  $mm = xml_load($read('course_settings/module_meta.xml'));
  $typeMap = ['WikiPage' => 'page', 'Assignment' => 'assignment', 'DiscussionTopic' => 'discussion', 'Quizzes::Quiz' => 'quiz',
    'Quiz' => 'quiz', 'ExternalUrl' => 'link', 'ContextExternalTool' => 'link', 'Attachment' => 'file', 'ContextModuleSubHeader' => 'header'];
  $resolve = function ($type, $ref, $title, $url = '') use (&$content, $fileRes, $quizAlias, $res) {
    $ref = (string)$ref;
    switch ($type) {
      case 'page': return isset($content['pages'][safe_id($ref)]) ? safe_id($ref) : null;
      case 'assignment': return isset($content['assignments'][safe_id($ref)]) ? safe_id($ref) : null;
      case 'discussion': return isset($content['discussions'][safe_id($ref)]) ? safe_id($ref) : null;
      case 'quiz': $q = $quizAlias[$ref] ?? safe_id($ref); return isset($content['quizzes'][$q]) ? $q : null;
      case 'link':
        if (isset($content['links'][safe_id($ref)])) return safe_id($ref);
        if ($url) { $id = newid('l'); $content['links'][$id] = ['id' => $id, 'title' => $title, 'url' => $url]; return $id; }
        return null;
      case 'file':
        if (!isset($fileRes[$ref])) return null;
        $id = safe_id($ref);
        $content['files'][$id] = ['id' => $id, 'title' => $title ?: basename($fileRes[$ref]), 'path' => $fileRes[$ref]];
        return $id;
    }
    return null;
  };
  $unmatched = 0;
  if ($mm) {
    foreach ($mm->module as $mod) {
      $m = ['id' => safe_id((string)$mod['identifier']) ?: newid('m'), 'title' => xs($mod->title), 'published' => xs($mod->workflow_state) !== 'unpublished',
        'unlock_at' => cc_time(xs($mod->unlock_at)), 'items' => []];
      foreach ($mod->items->item ?? [] as $it) {
        $ct = xs($it->content_type);
        $type = $typeMap[$ct] ?? null;
        if (!$type) { $unmatched++; continue; }
        $item = ['id' => safe_id((string)$it['identifier']) ?: newid('i'), 'type' => $type, 'title' => xs($it->title),
          'indent' => (int)xs($it->indent), 'published' => xs($it->workflow_state) !== 'unpublished', 'unlock_at' => 0];
        if ($type !== 'header') {
          $ref = $resolve($type, xs($it->identifierref), xs($it->title), xs($it->url));
          if (!$ref) { $unmatched++; continue; }
          $item['ref'] = $ref;
        }
        $m['items'][] = $item;
      }
      $content['modules'][] = $m;
    }
  } else {
    // Plain Common Cartridge: walk the organization tree.
    $org = $manifest->organizations->organization ?? null;
    $root = $org ? ($org->item ?? null) : null;
    foreach (($root ? $root->item : []) as $mod) {
      $m = ['id' => newid('m'), 'title' => xs($mod->title), 'published' => true, 'unlock_at' => 0, 'items' => []];
      $walk = function ($node, $indent) use (&$walk, &$m, $res, $resolve, &$content) {
        foreach ($node->item as $it) {
          $ref = (string)$it['identifierref'];
          if ($ref === '') {
            $m['items'][] = ['id' => newid('i'), 'type' => 'header', 'title' => xs($it->title), 'indent' => 0, 'published' => true, 'unlock_at' => 0];
            $walk($it, $indent + 1);
            continue;
          }
          $r = $res[$ref] ?? null; if (!$r) continue;
          $type = null;
          if (isset($content['pages'][safe_id($ref)])) $type = 'page';
          elseif (isset($content['assignments'][safe_id($ref)])) $type = 'assignment';
          elseif (isset($content['discussions'][safe_id($ref)])) $type = 'discussion';
          elseif (isset($content['links'][safe_id($ref)])) $type = 'link';
          elseif (str_starts_with($r['href'], 'web_resources/')) $type = 'file';
          else $type = 'quiz';
          $id = $resolve($type, $ref, xs($it->title));
          if ($id) $m['items'][] = ['id' => newid('i'), 'type' => $type, 'ref' => $id, 'title' => xs($it->title), 'indent' => $indent, 'published' => true, 'unlock_at' => 0];
        }
      };
      $walk($mod, 1);
      $content['modules'][] = $m;
    }
  }

  // Move per-object unlock dates onto their module items (that's where release lives here).
  $inferred = 0;
  foreach ($content['modules'] as &$m) {
    $dates = []; $allDated = true; $gradables = 0;
    foreach ($m['items'] as &$it) {
      if (!in_array($it['type'], ['assignment', 'discussion', 'quiz'], true)) continue;
      $o = $content[TYPE_STORE[$it['type']]][$it['ref']];
      $gradables++;
      if (!empty($o['unlock_at'])) { $it['unlock_at'] = $o['unlock_at']; $dates[] = $o['unlock_at']; }
      elseif ($it['type'] !== 'discussion') $allDated = false;
    }
    unset($it);
    if (($opt['infer_release'] ?? true) && !$m['unlock_at'] && $dates && $allDated) { $m['unlock_at'] = min($dates); $inferred++; }
    // An item date equal to its module's date adds nothing; drop it so moving the module moves everything.
    if ($m['unlock_at']) foreach ($m['items'] as &$it2) if ((int)$it2['unlock_at'] === (int)$m['unlock_at']) $it2['unlock_at'] = 0;
    unset($it2);
  }
  unset($m);
  // Objects that sit in a module now carry their release date on the module item.
  // Objects outside every module keep their own date, so they stay closed until then.
  foreach (['assignment' => 'assignments', 'discussion' => 'discussions', 'quiz' => 'quizzes'] as $t => $s) foreach ($content[$s] as $oid => &$o) {
    if (placements($content, $t, $oid)) unset($o['unlock_at']);
  }
  unset($o);
  if ($inferred) $log[] = "$inferred modules were given release dates taken from their assignments’ “available from” dates. Review them under Release schedule.";

  // ---- rewrite Canvas placeholders in every HTML body ----
  $rw = function ($html) use ($cid, $slugToPage, $content, $quizAlias) {
    return cc_rewrite_links($html, $cid, $slugToPage, $content, $quizAlias);
  };
  foreach ($content['announcements'] as $k => $o) $content['announcements'][$k]['body'] = $rw($o['body']);
  foreach (['pages', 'assignments', 'discussions', 'quizzes'] as $s) foreach ($content[$s] as $k => $o) {
    $content[$s][$k]['body'] = $rw($o['body'] ?? '');
    if ($s === 'quizzes') foreach ($o['questions'] as $qi => $q) $content[$s][$k]['questions'][$qi]['text'] = $rw($q['text']);
  }
  $content['syllabus'] = $rw($content['syllabus']);
  foreach ($pools as $pid => $qs) {
    foreach ($qs as &$pq) { $pq['text'] = $rw($pq['text']); $pq['id'] = newid('q'); }
    unset($pq);
    jwrite("c/$cid/pools/$pid", ['questions' => $qs]);
  }

  $log[] = count($content['modules']) . ' modules, ' . count($content['pages']) . ' pages, ' . count($content['assignments']) . ' assignments, ' . count($content['discussions']) . ' discussions' . ($content['announcements'] ? ', ' . count($content['announcements']) . ' announcements' : '') . '.';
  $unpub = 0; foreach ($content['modules'] as $m) foreach ($m['items'] as $it) if (empty($it['published'])) $unpub++;
  if ($unpub) $log[] = "$unpub item(s) were unpublished in Canvas and stay hidden from students.";
  if ($unmatched) $log[] = "$unmatched module item(s) pointed at content this site can’t import (for example external tools) and were skipped.";

  jwrite("c/$cid/content", $content);
  unset($GLOBALS['_content_cache'][$cid]);
  save_course($course);
  $zip->close();
  return ['cid' => $cid, 'log' => $log];
}

function cc_rewrite_links($html, $cid, $slugToPage, $content, $quizAlias) {
  if ($html === '' || $html === null) return '';
  $html = preg_replace_callback('~\$IMS(?:-|_)CC(?:-|_)FILEBASE\$/([^"\'?#\s<>]+)(\?[^"\'#\s<>]*)?~', function ($m) use ($cid) {
    return h(cfile_url($cid, safe_rel_path(rawurldecode(html_entity_decode($m[1])))));
  }, $html);
  $html = preg_replace_callback('~\$WIKI_REFERENCE\$/pages/([^"\'?#\s<>]+)([^"\'\s<>]*)~', function ($m) use ($cid, $slugToPage) {
    $slug = strtolower(rawurldecode($m[1]));
    $id = $slugToPage[$slug] ?? null;
    return $id ? h(u('page', ['c' => $cid, 'id' => $id])) : h(u('modules', ['c' => $cid]));
  }, $html);
  $html = preg_replace_callback('~\$CANVAS_OBJECT_REFERENCE\$/(assignments|discussion_topics|quizzes|modules|announcements)/([^"\'?#\s<>]+)~', function ($m) use ($cid, $content, $quizAlias) {
    $ref = safe_id(rawurldecode($m[2]));
    switch ($m[1]) {
      case 'assignments': if (isset($content['assignments'][$ref])) return h(u('assignment', ['c' => $cid, 'id' => $ref])); break;
      case 'discussion_topics': if (isset($content['discussions'][$ref])) return h(u('discussion', ['c' => $cid, 'id' => $ref])); break;
      case 'quizzes': $q = $quizAlias[$ref] ?? $ref; if (isset($content['quizzes'][$q])) return h(u('quiz', ['c' => $cid, 'id' => $q])); break;
      case 'announcements': return h(u('announcements', ['c' => $cid]));
    }
    return h(u('modules', ['c' => $cid]));
  }, $html);
  $html = preg_replace_callback('~\$CANVAS_COURSE_REFERENCE\$/([^"\'\s<>]*)~', function ($m) use ($cid) {
    if (str_starts_with($m[1], 'assignments')) return h(u('assignments', ['c' => $cid]));
    if (str_starts_with($m[1], 'discussion_topics')) return h(u('discussions', ['c' => $cid]));
    if (str_starts_with($m[1], 'grades')) return h(u('grades', ['c' => $cid]));
    if (str_starts_with($m[1], 'modules')) return h(u('modules', ['c' => $cid]));
    if (str_starts_with($m[1], 'assignments/syllabus')) return h(u('syllabus', ['c' => $cid]));
    return h(u('course', ['c' => $cid]));
  }, $html);
  return $html;
}

// Returns [questions[], unsupportedCount, pools[]].
// A pool is a Canvas question group: pick N questions per attempt, either from questions
// listed in the group or from a question bank ($loadBank returns the bank's questions).
function parse_qti($s, $loadBank = null) {
  $x = xml_load($s);
  if (!$x) return [[], 0, []];
  $out = []; $warn = 0; $pools = [];
  $root = $x->xpath('//assessment/section') ?: [];
  $children = $root ? $root[0]->children() : [];
  if (!$root) { foreach ($x->xpath('//item') ?: [] as $it) { [$q, $bad] = qti_item($it); $out[] = $q; $warn += $bad; } return [$out, $warn, []]; }
  foreach ($children as $name => $node) {
    if ($name === 'item') { [$q, $bad] = qti_item($node); $out[] = $q; $warn += $bad; continue; }
    if ($name !== 'section') continue;
    $sel = $node->xpath('./selection_ordering/selection');
    $pick = $sel ? (int)xs($sel[0]->selection_number) : 0;
    $pts = $sel ? $sel[0]->xpath('./selection_extension/points_per_item') : [];
    $points = $pts ? (float)$pts[0] : 1.0;
    $bankRef = $sel ? xs($sel[0]->sourcebank_ref) : '';
    if ($bankRef !== '' && $loadBank) {
      $bank = $loadBank($bankRef);
      $warn += $bank['warn'];
      if ($bank['questions']) $pools[] = ['title' => (string)$node['title'] ?: $bank['title'], 'pick' => $pick ?: count($bank['questions']), 'points' => $points, 'questions' => $bank['questions']];
      continue;
    }
    $items = [];
    foreach ($node->xpath('.//item') ?: [] as $it) { [$q, $bad] = qti_item($it); $items[] = $q; $warn += $bad; }
    if ($pick > 0 && $pick < count($items)) $pools[] = ['title' => (string)$node['title'], 'pick' => $pick, 'points' => $points, 'questions' => $items];
    else foreach ($items as $q) { if ($sel) $q['points'] = $points; $out[] = $q; }
  }
  return [$out, $warn, $pools];
}

// One QTI item to a question. Returns [question, 1 if its type isn't auto-gradable here].
function qti_item($it) {
  $meta = [];
  foreach ($it->xpath('.//qtimetadatafield') ?: [] as $f) $meta[xs($f->fieldlabel)] = xs($f->fieldentry);
  $qt = $meta['question_type'] ?? '';
  $mat = $it->xpath('./presentation/material/mattext');
  $text = $mat ? (string)$mat[0] : '';
  if ($text !== '' && !str_contains($text, '<')) $text = '<p>' . h($text) . '</p>';
  $pts = isset($meta['points_possible']) ? (float)$meta['points_possible'] : 1;
  $fb = '';
  foreach (['general_fb', 'correct_fb'] as $fid) {
    $m = $it->xpath('./itemfeedback[@ident="' . $fid . '"]//mattext');
    if ($m && trim((string)$m[0]) !== '') { $fb = trim((string)$m[0]); break; }
  }
  $q = ['id' => newid('q'), 'text' => $text, 'points' => $pts, 'type' => 'essay', 'choices' => [], 'answers' => [], 'feedback' => $fb];
  $labels = [];
  foreach ($it->xpath('.//response_label') ?: [] as $lab) {
    $lt = $lab->xpath('./material/mattext');
    $labels[(string)$lab['ident']] = $lt ? trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string)$lt[0], ENT_QUOTES, 'UTF-8')))) : '';
  }
  // correct = varequal not inside <not>, in a condition that awards points
  $correct = [];
  foreach ($it->xpath('.//respcondition') ?: [] as $rc) {
    $score = $rc->xpath('./setvar');
    $sv = $score ? (float)$score[0] : 0;
    foreach ($rc->xpath('./conditionvar//varequal') ?: [] as $ve) {
      $inNot = $ve->xpath('ancestor::not');
      if (!$inNot && $sv > 0) $correct[] = trim((string)$ve);
    }
  }
  $warn = 0;
  switch ($qt) {
    case 'multiple_choice_question': case 'true_false_question': case 'multiple_answers_question':
      $q['type'] = $qt === 'multiple_answers_question' ? 'multi' : ($qt === 'true_false_question' ? 'tf' : 'mc');
      if ($qt === 'multiple_answers_question') {
        $correct = [];
        foreach ($it->xpath('.//respcondition/conditionvar/and/varequal') ?: [] as $ve) $correct[] = trim((string)$ve);
      }
      foreach ($labels as $lid => $lt) $q['choices'][] = ['id' => (string)$lid, 'text' => $lt, 'correct' => in_array((string)$lid, $correct, true)];
      break;
    case 'short_answer_question':
      $q['type'] = 'short'; $q['answers'] = array_values(array_unique($correct)); break;
    case 'numerical_question':
      $q['type'] = 'numeric';
      $gte = $it->xpath('.//vargte'); $lte = $it->xpath('.//varlte');
      if ($gte && $lte) { $lo = (float)$gte[0]; $hi = (float)$lte[0]; $q['value'] = ($lo + $hi) / 2; $q['tolerance'] = ($hi - $lo) / 2; }
      else { $q['value'] = (float)($correct[0] ?? 0); $q['tolerance'] = 0; }
      break;
    case 'essay_question': case 'file_upload_question': $q['type'] = 'essay'; break;
    case 'text_only_question': $q['type'] = 'text'; $q['points'] = 0; break;
    default: $q['type'] = 'essay'; $warn = 1;
  }
  return [$q, $warn];
}
