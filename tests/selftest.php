<?php
// heLMSman self-test. Run from the repository root:  php tests/selftest.php
// Needs only the PHP CLI. Uses a throwaway data folder; touches nothing else.
define('APP', 1);
$tmp = sys_get_temp_dir() . '/helmsman-selftest-' . bin2hex(random_bytes(4));
define('DATA_DIR', $tmp);
$src = __DIR__ . '/../src';
foreach (['compat', 'core', 'course', 'import', 'views'] as $f) require "$src/lib/$f.php";
data_init();
$_SESSION = ['csrf' => 'x'];

$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; } }
function section($s) { echo "· $s\n"; }

section('built-in inflate matches zlib');
if (function_exists('gzdeflate')) {
  mt_srand(7);
  $samples = ['', 'a', str_repeat('heLMSman ', 5000), random_bytes(40000), file_get_contents("$src/lib/views.php")];
  for ($i = 0; $i < 10; $i++) { $s = ''; $n = mt_rand(0, 30000); for ($j = 0; $j < $n; $j++) $s .= chr(mt_rand(97, 100)); $samples[] = $s; }
  foreach ($samples as $s) foreach ([0, 1, 6, 9] as $lvl) ok(PhpInflate::run(gzdeflate($s, $lvl)) === $s, "inflate len " . strlen($s) . " level $lvl");
} else echo "  (zlib not installed here; skipped comparison)\n";

section('zip writer + built-in zip reader round trip');
$zp = "$tmp/rt.zip";
$w = new MiniZipWriter($zp); $w->addFromString('a/b.txt', 'hello'); $w->addFromString('ü.txt', str_repeat('x', 1000)); $w->close();
$r = new MiniZip(); ok($r->open($zp), 'open written zip');
ok($r->numFiles === 2, 'two entries');
ok($r->getFromName('a/b.txt') === 'hello', 'read entry back');
ok($r->getFromName('ü.txt') === str_repeat('x', 1000), 'utf-8 name');

section('path and upload-name safety');
ok(safe_rel_path('../../users.json.php') === 'users.json.php', 'strips ..');
ok(safe_rel_path('/etc/passwd') === 'etc/passwd', 'strips leading slash');
ok(safe_rel_path('a/./b/../c.png') === 'a/b/c.png', 'drops dot segments');
ok(safe_rel_path('.htaccess') === '', 'drops hidden files');
ok(safe_rel_path('x.php ') === 'x.php' && safe_rel_path('x.php.') === 'x.php', 'trims trailing space and dot');
foreach (['x.php', 'x.PHP', 'x.php.jpg', 'x.phtml', 'x.phar', '.htaccess', '.user.ini', 'x.pHp5'] as $bad) ok(!upload_ok_name($bad), "blocks $bad");
foreach (['report.pdf', 'graph.owl', 'notes.txt', 'photo.JPG'] as $good) ok(upload_ok_name($good), "allows $good");

section('quiz scoring');
$mc = ['type' => 'mc', 'points' => 2, 'choices' => [['id' => 'a', 'correct' => false], ['id' => 'b', 'correct' => true]]];
ok(grade_answer($mc, 'b') === [2.0, true] && grade_answer($mc, 'a') === [0, false], 'multiple choice');
$multi = ['type' => 'multi', 'points' => 1, 'choices' => [['id' => '1', 'correct' => true], ['id' => '2', 'correct' => false], ['id' => '3', 'correct' => true]]];
ok(grade_answer($multi, ['3', '1'])[1] === true && grade_answer($multi, ['1'])[1] === false, 'multiple answers need exact set');
ok(grade_answer(['type' => 'short', 'points' => 1, 'answers' => ['worker bee']], '  Worker   BEE ')[1] === true, 'short answer ignores case/space');
ok(grade_answer(['type' => 'numeric', 'points' => 1, 'value' => 3.14, 'tolerance' => 0.01], '3.15')[1] === true, 'numeric within margin');
ok(grade_answer(['type' => 'numeric', 'points' => 1, 'value' => 3.14, 'tolerance' => 0.01], 'pi')[1] === false, 'numeric rejects text');
ok(grade_answer(['type' => 'essay', 'points' => 4], 'words')[0] === null, 'essay left for instructor');

section('import the sample Canvas course');
$fx = __DIR__ . '/fixtures/sample-course.imscc';
if (!is_file($fx)) { echo "  building fixture…\n"; passthru('python3 ' . escapeshellarg(__DIR__ . '/fixtures/make_sample.py')); }
$res = import_imscc($fx, ['infer_release' => true]);
$cid = $res['cid'];
$c = course($cid); $ct = content($cid);
ok($c['title'] === 'Backyard Beekeeping (Sample)', 'course title');
ok(count($c['groups']) === 3 && $c['weighted'], 'weighted groups');
ok(count($ct['modules']) === 3, 'three modules');
ok(count($ct['pages']) === 4 && empty($ct['pages']['pg_notes']['published']), 'pages, hidden page stays hidden');
ok(count($ct['assignments']) === 3, 'three assignments');
ok(!access($cid, 'assignment', 'as_bonus')['ok'] && !empty($ct['assignments']['as_bonus']['unlock_at']), 'assignment outside modules keeps its release date');
ok(empty($ct['assignments']['as_log']['unlock_at']) && $ct['modules'][1]['unlock_at'] > 0, 'module items carry their date on the module');
$vis = visible_file_paths($cid);
ok(isset($vis['images/banner.png']) && isset($vis['handouts/inspection-checklist.txt']), 'linked files visible to students');
ok(!isset($vis['instructor/answer-key.txt']), 'unlinked files hidden from students');
ok(!empty($c['files_key']) && is_dir(dpath("c/$cid/files-" . $c['files_key'])), 'course files in an unguessable folder');
ok(is_file(dpath("c/$cid/index.html")), 'folders get an index.html');
ok(count($ct['assignments']['as_log']['rubric']) === 2, 'rubric imported');
ok($ct['assignments']['as_log']['sub_types'] === ['upload', 'text'], 'submission types');
ok($ct['assignments']['as_plan']['post_policy'] === 'manual', 'muted assignment holds grades');
ok((float)$ct['discussions']['dt_intro']['points'] === 5.0 && $ct['discussions']['dt_intro']['require_initial'], 'graded discussion');
$q = $ct['quizzes']['qz_bees'] ?? null;
ok($q && count($q['questions']) === 6, 'quiz with six questions');
ok($q && array_column($q['questions'], 'type') === ['mc', 'tf', 'multi', 'short', 'numeric', 'essay'], 'question types');
ok($q && (float)$q['points'] === 10.0 && $q['time_limit'] === 10 && $q['attempts'] === 2, 'quiz settings');
ok(count($ct['links']) === 1 && count($ct['files']) === 1, 'external link and file items');
ok(strpos($ct['pages']['pg_welcome']['body'], 'r=page&amp;c=' . $cid . '&amp;id=pg_w1') !== false, 'wiki links rewritten');
ok(strpos($ct['pages']['pg_w1']['body'], 'r=assignment') !== false, 'assignment links rewritten');
ok(strpos($ct['pages']['pg_welcome']['body'], 'r=cfile') !== false && strpos($ct['pages']['pg_welcome']['body'], '$IMS') === false, 'file links rewritten');
ok(is_file(course_files_dir($cid) . '/images/banner.png'), 'files copied');
$c = course($cid); save_course(array_diff_key($c, ['files_key' => 1]));
ok(course($cid)['files_key'] === $c['files_key'], 'saving settings never drops the files key');
$w1 = $ct['modules'][1];
ok($w1['unlock_at'] === cc_time('2027-02-01T14:00:00'), 'module release date inferred');

section('practice quizzes, question banks, feedback, announcements');
$pq = $ct['quizzes']['qz_practice'] ?? null;
ok($pq && !empty($pq['practice']) && !empty($pq['omit']), 'practice quiz flagged and not counted');
ok($pq && count($pq['questions']) === 1 && count($pq['pools']) === 1 && $pq['pools'][0]['pick'] === 2 && $pq['pools'][0]['count'] === 4, 'bank draw imported as a pool');
ok($pq && quiz_points($pq) == 3 && quiz_question_count($pq) === 3, 'pool counts toward points and questions');
ok($pq && str_contains($pq['questions'][0]['feedback'], 'Only the queen'), 'question feedback imported');
ok(!isset(graded_items($cid)['qz_practice']) && isset(gradables($cid)['qz_practice']), 'practice quiz kept out of the gradebook');
[$order, $drawn] = draw_attempt($cid, $pq);
ok(count($order) === 3 && count($drawn) === 2 && $drawn[0]['points'] == 1, 'each attempt draws its own questions');
$att = ['order' => $order, 'drawn' => $drawn, 'answers' => []];
foreach ($drawn as $dq) foreach ($dq['choices'] as $ch) if ($ch['correct']) $att['answers'][$dq['id']] = $ch['id'];
$att['answers'][$pq['questions'][0]['id']] = '801';
score_attempt($pq, $att);
ok($att['score'] == 3 && attempt_points($pq, $att) == 3, 'drawn questions are scored');
$ann = $ct['announcements']['ann_welcome'] ?? null;
ok($ann && $ann['title'] === 'Welcome, beekeepers' && !isset($ct['discussions']['ann_welcome']), 'announcements imported as announcements');

section('grade math');
$course = $c; $course['groups'] = [['id' => 'a', 'title' => 'A', 'weight' => 75], ['id' => 'b', 'title' => 'B', 'weight' => 25]];
$mk = function ($gid, $pts, $score) { return ['g' => ['group' => $gid, 'points' => $pts, 'omit' => false], 'counts' => true, 'score' => $score]; };
$t = compute_total($course, [$mk('a', 10, 8), $mk('a', 10, 10), $mk('b', 20, 10)]);
ok(abs($t['pct'] - (0.75 * 90 + 0.25 * 50)) < 1e-9 && $t['letter'] === 'B', 'weighted total and letter');
$course['weighted'] = false;
$t = compute_total($course, [$mk('a', 10, 8), $mk('b', 30, 15)]);
ok(abs($t['pct'] - 57.5) < 1e-9, 'unweighted total');

rrmdir($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
