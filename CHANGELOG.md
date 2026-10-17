# Changelog

## 1.0.2 (2026-10-17)

- Quizzes that draw questions from a Canvas question bank now import. Each attempt draws
  its own set of questions from the bank, and the draw counts toward points.
- Canvas practice quizzes and surveys import as practice: students can take them as often
  as allowed, see the answers and explanations after each attempt, and they stay out of
  the gradebook. A "Practice quiz" checkbox in the quiz editor turns this on or off.
- Answer feedback written in Canvas (general and correct-answer comments) now imports and
  shows after an attempt.
- Canvas announcements now import as announcements instead of discussions.
- Fixed a PHP 8.1+ deprecation notice from the course banner colors.

## 1.0.1 (2026-10-17)

- Fixed: Course settings would not save. The "New code" button was a form inside the
  settings form, which made the Save button stop working.
- The browser test now checks every page for forms nested inside other forms, and
  checks that course settings save.

## 1.0.0 (2026-10-16)

First public release.

- Canvas / Common Cartridge (.imscc) import: modules, pages, syllabus, files,
  assignments with groups, weights and rubrics, graded discussions, classic quizzes,
  and release dates.
- Student accounts by instructor invitation or by sign-up with instructor approval.
- Assignments with file, text and link submissions, attempts, late penalty, rubrics,
  feedback, and grades released as entered or held for posting.
- Quizzes: multiple choice, true/false, multiple answers, short answer, number and
  essay; time limits, attempts, autosave, and manual grading of essays.
- Threaded discussions, optionally graded, with a "post first" option.
- Weighted gradebook with letter grades and CSV export, plus zip download of submissions.
- Release scheduling by module and item, a drag-and-drop calendar, and shifting every
  date at once.
- Video lecture items and an editor button for YouTube, Vimeo, Google Drive, Loom,
  Panopto and uploaded video.
- Announcements (which can be scheduled), on-site notifications, and Student view.
- Built-in fallbacks for the zip, zlib and mbstring PHP extensions, for shared hosting.
- Security hardening following an independent code review before release (see SECURITY.md).
