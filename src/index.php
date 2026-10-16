<?php
// Course site: a small self-hosted LMS. Upload this folder to your web host; visit it to set up.
define('APP', 1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';
if (!defined('DATA_DIR')) define('DATA_DIR', __DIR__ . '/data');

require __DIR__ . '/lib/compat.php';
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/course.php';
require __DIR__ . '/lib/import.php';
require __DIR__ . '/lib/views.php';
require __DIR__ . '/lib/admin.php';
require __DIR__ . '/lib/admin_content.php';
require __DIR__ . '/lib/admin_grading.php';

if (version_compare(PHP_VERSION, '7.4', '<')) { http_response_code(500); exit('This site needs PHP 7.4 or newer. Change the PHP version in your hosting control panel.'); }

data_init();
start_session();
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

$routes = [
  'setup' => 'r_setup', 'login' => 'r_login', 'logout' => 'r_logout', 'signup' => 'r_signup', 'join' => 'r_join',
  'home' => 'r_home', 'account' => 'r_account', 'notifications' => 'r_notifications', 'calendar' => 'r_calendar',
  'cfile' => 'r_cfile', 'upfile' => 'r_upfile',
  'course' => 'r_course', 'modules' => 'r_modules', 'page' => 'r_page', 'video' => 'r_video', 'link' => 'r_link', 'file' => 'r_file',
  'syllabus' => 'r_syllabus', 'announcements' => 'r_announcements', 'assignments' => 'r_assignments', 'assignment' => 'r_assignment',
  'assignment_comment' => 'r_assignment_comment', 'discussions' => 'r_discussions', 'discussion' => 'r_discussion',
  'quiz' => 'r_quiz', 'quiz_take' => 'r_quiz_take', 'grades' => 'r_grades',
  // instructor
  'site' => 'r_site', 'new_course' => 'r_new_course', 'import' => 'r_import', 'import_done' => 'r_import_done',
  'course_settings' => 'r_course_settings', 'people' => 'r_people', 'student' => 'r_student', 'preview' => 'r_preview',
  'files' => 'r_files', 'upload_asset' => 'r_upload_asset',
  'module_save' => 'r_module_save', 'module_edit' => 'r_module_edit', 'item_add' => 'r_item_add', 'item_save' => 'r_item_save',
  'edit' => 'r_edit', 'delete_obj' => 'r_delete_obj', 'schedule' => 'r_schedule', 'cal_move' => 'r_cal_move',
  'grading' => 'r_grading', 'grade' => 'r_grade', 'post_grades' => 'r_post_grades', 'gradebook' => 'r_grades',
  'gradebook_csv' => 'r_gradebook_csv', 'download_subs' => 'r_download_subs',
];

// Show the course menu on an error page only to people who belong in the course.
function error_course_nav() {
  $cid = safe_id(get('c'));
  if (!$cid || !course($cid) || !me()) return [];
  if (is_admin()) return ['course' => $cid];
  $e = enrollment($cid, me()['id']);
  return $e && $e['status'] === 'active' ? ['course' => $cid] : [];
}

$r = get('r', '');
if ($r === '') $r = setup_done() ? (me() ? 'home' : 'login') : 'setup';

try {
  if (!isset($routes[$r])) { http_response_code(404); throw new HttpError('Page not found.'); }
  if (is_post() && !csrf_ok()) {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
      throw new HttpError('That upload was larger than this server accepts (' . fmt_size(upload_limit()) . '). Try a smaller file, or use a link instead.');
    }
    http_response_code(400);
    throw new HttpError('Your session expired or the form was out of date. Go back, reload the page and try again.');
  }
  if (me()) {
    $u = me();
    if (empty($u['seen']) || $u['seen'] < now() - 300) patch_user($u['id'], ['seen' => now()]);
    if (!empty($u['must_change']) && !in_array($r, ['account', 'logout'], true)) redirect(u('account'));
  }
  $cid = safe_id(get('c'));
  if ($cid && course($cid)) deliver_announcements($cid);
  call_user_func($routes[$r]);
} catch (HttpError $e) {
  if (http_response_code() === 200) http_response_code(400);
  layout('Notice', '<div class="notice-page"><h1>Hmm.</h1><p>' . $e->getMessage() . '</p><p><a class="btn" href="' . h(u('home')) . '">Go to dashboard</a> <a class="btn" href="javascript:history.back()">Go back</a></p></div>', error_course_nav());
} catch (Throwable $e) {
  http_response_code(500);
  error_log('LMS error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  $detail = is_admin() ? '<pre class="small">' . h($e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine()) . '</pre>' : '';
  layout('Error', '<div class="notice-page"><h1>Something went wrong.</h1><p>The error was logged. Try again, and tell your instructor if it keeps happening.</p>' . $detail . '</div>');
}
