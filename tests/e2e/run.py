#!/usr/bin/env python3
"""Browser end-to-end test for heLMSman.

Starts PHP's built-in server on a throwaway copy of src/, then walks through the
instructor and student flows in headless Chromium and fails on any PHP or JS error.

Requirements: php, python3, `pip install playwright` and `playwright install chromium`.
Usage:  python3 tests/e2e/run.py            (run the test)
        python3 tests/e2e/run.py --shots    (also save README screenshots to docs/screenshots)
"""
import os, re, shutil, socket, subprocess, sys, tempfile, time
from playwright.sync_api import sync_playwright

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
FIXTURE = os.path.join(ROOT, 'tests', 'fixtures', 'sample-course.imscc')
SHOTS = os.path.join(ROOT, 'docs', 'screenshots') if '--shots' in sys.argv else None
OUT = os.path.join(ROOT, 'tests', 'e2e', 'out')
errors = []

def free_port():
    s = socket.socket(); s.bind(('127.0.0.1', 0)); p = s.getsockname()[1]; s.close(); return p

def check(page, label):
    html = page.content()
    for bad in ('Something went wrong', 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'):
        if bad in html:
            errors.append(f'{label}: {bad} at {page.url}')
            print('!!', label, bad, page.url)
            return

def shot(page, name, full=True):
    os.makedirs(OUT, exist_ok=True)
    page.screenshot(path=os.path.join(OUT, name), full_page=full)
    if SHOTS:
        os.makedirs(SHOTS, exist_ok=True)
        page.screenshot(path=os.path.join(SHOTS, name), full_page=False)

def main():
    if not os.path.exists(FIXTURE):
        subprocess.check_call([sys.executable, os.path.join(ROOT, 'tests', 'fixtures', 'make_sample.py')])
    work = tempfile.mkdtemp(prefix='helmsman-e2e-')
    app = os.path.join(work, 'helmsman')
    shutil.copytree(os.path.join(ROOT, 'src'), app, ignore=shutil.ignore_patterns('data', 'config.php'))
    # Keep data outside the web folder, as recommended (PHP's test server ignores .htaccess).
    with open(os.path.join(app, 'config.php'), 'w') as f:
        f.write("<?php define('DATA_DIR', " + repr(os.path.join(work, 'data')) + ");\n")
    port = free_port(); B = f'http://127.0.0.1:{port}/'
    log = open(os.path.join(work, 'php.log'), 'w')
    srv = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', app], stdout=log, stderr=subprocess.STDOUT)
    time.sleep(1)
    try:
        run(B)
    finally:
        srv.terminate(); log.close()
        for line in open(os.path.join(work, 'php.log')):
            if re.search(r'PHP (Warning|Notice|Fatal|Deprecated)|LMS error', line):
                errors.append('php log: ' + line.strip())
        shutil.rmtree(work, ignore_errors=True)
    if errors:
        print('\nFAILED:'); [print(' -', e) for e in errors]; sys.exit(1)
    print('\nAll end-to-end checks passed.')

def run(B):
    with sync_playwright() as pw:
        br = pw.chromium.launch()
        def page(w=1280, h=860, mobile=False):
            ctx = br.new_context(viewport={'width': w, 'height': h}, is_mobile=mobile, device_scale_factor=1)
            p = ctx.new_page()
            p.on('pageerror', lambda e: errors.append('JS: ' + str(e)))
            p.on('dialog', lambda d: d.accept())
            return p
        def login(p, email, pw):
            p.goto(B + '?r=login'); p.fill('input[name=email]', email); p.fill('input[name=password]', pw); p.click('button:has-text("Log in")')

        # --- instructor setup and import ---
        a = page()
        a.goto(B)
        a.fill('input[name=site]', 'Demo University Courses'); a.fill('input[name=name]', 'Pat Instructor')
        a.fill('input[name=email]', 'pat@example.edu'); a.fill('input[name=password]', 'instructor-pass')
        a.click('button:has-text("Create instructor")'); check(a, 'setup')
        a.goto(B + '?r=import'); a.set_input_files('input[name=cc]', FIXTURE); a.click('button:has-text("Import")'); check(a, 'import')
        cid = re.search(r'c=(c[0-9a-f]+)', a.url).group(1)
        assert 'quizzes with 6 questions' in a.content() or '1 quizzes with 6 questions' in a.content(), 'quiz import summary'
        # Move the course so week 1 is open and week 2 opens in a few days.
        a.goto(B + f'?r=schedule&c={cid}')
        days = -int((time.mktime(time.strptime('2027-02-01', '%Y-%m-%d')) - time.time()) // 86400) - 2
        a.fill('input[name=shift]', str(days)); a.click('button:has-text("Shift all dates")'); check(a, 'shift')
        for r in ('modules', 'syllabus', 'assignments', 'discussions', 'grades', 'calendar', 'people', 'schedule', 'course_settings', 'announcements', 'files', 'site'):
            a.goto(B + f'?r={r}&c={cid}'); check(a, r)

        # add a video lecture to week 1
        a.goto(B + f'?r=modules&c={cid}')
        sec = a.locator('section#m_w1'); sec.locator('summary:has-text("Add item")').click()
        sec.locator('.add-item select[name=type]').select_option('video')
        sec.locator('.add-item input[name=title]').fill('1.1 Inside the hive'); sec.locator('.add-item button:has-text("Add")').click()
        a.fill('input[name=url]', 'https://vimeo.com/76979871'); a.fill('input[name=duration]', '12 min'); a.check('input[name=_published]')
        a.click('aside button:has-text("Save and keep editing")'); check(a, 'video')
        # quiz builder screenshot
        a.goto(B + f'?r=edit&c={cid}&t=quiz&id=qz_bees'); check(a, 'quiz edit'); shot(a, 'quiz-builder.png')
        # announcement
        a.goto(B + f'?r=edit&c={cid}&t=announcement'); a.fill('input[name=title]', 'Welcome!')
        a.locator('.ed-area').click(); a.keyboard.type('Read the Welcome page first, then introduce yourself.')
        a.click('button:has-text("Save")'); check(a, 'announcement')
        # add students
        a.goto(B + f'?r=people&c={cid}')
        a.fill('textarea[name=people]', 'Ana Ruiz, ana@example.edu\nBen Ode <ben@example.edu>')
        a.click('button:has-text("Add and enroll")'); check(a, 'add students')
        creds = {l.split('\t')[1]: l.split('\t')[2] for l in a.locator('textarea.sel').input_value().strip().split('\n')[1:]}
        code = re.search(r'code=([A-Z0-9]{6})', a.content()).group(1)

        # --- a student signs up and is approved ---
        c = page(); c.goto(B + f'?r=signup&code={code}')
        c.fill('input[name=name]', 'Cara Diaz'); c.fill('input[name=email]', 'cara@example.edu'); c.fill('input[name=password]', 'cara-pass-1')
        c.click('button:has-text("Sign up")'); check(c, 'signup')
        c.goto(B + f'?r=course&c={cid}'); assert 'waiting for the instructor' in c.content(), 'pending student blocked'
        a.goto(B + f'?r=people&c={cid}'); a.click('button:has-text("Approve selected")'); check(a, 'approve')
        c.goto(B + '?r=notifications'); assert 'approved' in c.content(), 'approval notification'

        # --- student work ---
        s = page(); login(s, 'ana@example.edu', creds['ana@example.edu'])
        assert 'r=account' in s.url, 'must change temporary password'
        s.fill('input[name=password]', 'ana-new-pass'); s.click('button:has-text("Save")'); check(s, 'password change')
        s.goto(B + f'?r=course&c={cid}'); check(s, 'student course'); shot(s, 'student-course.png')
        assert 'Instructor notes' not in s.content(), 'hidden page hidden'
        s.goto(B + f'?r=page&c={cid}&id=pg_notes'); assert 'not available' in s.content().lower(), 'hidden page blocked'
        s.goto(B + f'?r=assignment&c={cid}&id=as_log')
        tmpf = os.path.join(tempfile.gettempdir(), 'inspection-log.txt'); open(tmpf, 'w').write('May 3, 10am, sunny.\nQueen not seen; fresh eggs in frames 4-6.\nGood brood pattern, two frames of honey.\nNo mites on sugar roll.\nNext: add a super in a week.')
        s.set_input_files('#sub-upload input[type=file]', tmpf); s.click('#sub-upload button:has-text("Submit")'); check(s, 'submit')
        s.goto(B + f'?r=discussion&c={cid}&id=dt_intro'); assert 'Post your own response first' in s.content(), 'post-first discussion'
        s.fill('form.newpost textarea', 'I want bees for my garden; the hive will sit by the back fence, facing east.'); s.click('form.newpost button'); check(s, 'post')

        # quiz (week 2 may not be open yet: open it for the test)
        a.goto(B + f'?r=module_edit&c={cid}&id=m_w2'); a.fill('input[name=unlock_at]', ''); a.click('button:has-text("Save")'); check(a, 'open week 2')
        s.goto(B + f'?r=quiz&c={cid}&id=qz_bees'); s.click('button:has-text("Start quiz")'); check(s, 'quiz start')
        qs = s.locator('fieldset.question')
        def pick(i, text): qs.nth(i).locator('label.choice', has_text=text).click()
        pick(0, 'alarm scent'); pick(1, 'True'); pick(2, 'Honey'); pick(2, 'Beeswax')
        qs.nth(3).locator('input.short').fill('Drone'); qs.nth(4).locator('input.short').fill('6')
        qs.nth(5).locator('textarea').fill('Look for eggs or the queen, the brood pattern, food stores and pests, so I know the colony is queenright and healthy.')
        s.wait_for_timeout(1800); shot(s, 'quiz-taking.png')
        s.click('button:has-text("Submit quiz")'); check(s, 'quiz submit')
        assert '6 / 10' in s.inner_text('.quiz-score'), 'auto-graded 6 of 10 before the essay'

        # --- instructor grades ---
        a.goto(B + f'?r=grading&c={cid}&id=as_log'); a.click('td a:has-text("Ana Ruiz")'); check(a, 'grader')
        a.locator('input.rub-pts').nth(0).fill('5'); a.locator('input.rub-pts').nth(1).fill('4')
        a.locator('input.rub-cmt').nth(0).fill('Note which frames you checked.')
        a.fill('textarea[name=feedback]', 'Careful log. Next time, record the weather and the time you spent in the hive.')
        shot(a, 'grader.png')
        a.click('form#gform button:has-text("Save")'); check(a, 'grade saved')
        a.goto(B + f'?r=grading&c={cid}&id=qz_bees'); a.click('td a:has-text("Ana Ruiz")'); check(a, 'quiz grader')
        a.locator('input[name^=qman]').nth(5).fill('3.5'); a.click('#gform2 button:has-text("Save")'); check(a, 'quiz graded')
        a.goto(B + f'?r=grades&c={cid}'); check(a, 'gradebook'); shot(a, 'gradebook.png')
        csv = a.request.get(B + f'?r=gradebook_csv&c={cid}').text()
        assert 'Ana Ruiz' in csv and '9.5' in csv, 'csv export has quiz score 9.5'
        z = a.request.get(B + f'?r=download_subs&c={cid}&id=as_log')
        assert z.status == 200 and z.body()[:2] == b'PK', 'submissions zip'
        a.goto(B + f'?r=calendar&c={cid}'); check(a, 'calendar'); shot(a, 'calendar.png')
        a.goto(B + f'?r=course&c={cid}'); shot(a, 'instructor-course.png')

        # --- student sees grades and feedback; access checks ---
        s.goto(B + f'?r=assignment&c={cid}&id=as_log'); check(s, 'student feedback')
        assert '9' in s.inner_text('.score-big') and 'record the weather' in s.content(), 'student sees grade and feedback'
        shot(s, 'student-feedback.png')
        s.goto(B + f'?r=grades&c={cid}'); check(s, 'student grades')
        for r in (f'?r=people&c={cid}', f'?r=gradebook_csv&c={cid}', '?r=site', f'?r=edit&c={cid}&t=page&id=pg_w1'):
            assert s.goto(B + r).status == 403, f'student blocked from {r}'
        c.goto(B + f'?r=upfile&c={cid}&o=as_log&s=nobody&a=0&i=0'); assert 'Not' in c.content()
        assert s.request.get(B + f'?r=cfile&c={cid}&f=instructor%2Fanswer-key.txt').status == 404, 'unlinked course file hidden from students'
        assert s.request.get(B + f'?r=cfile&c={cid}&f=images%2Fbanner.png').status == 200, 'linked course file visible'
        assert a.request.get(B + f'?r=cfile&c={cid}&f=instructor%2Fanswer-key.txt').status == 200, 'instructor sees every file'
        assert s.goto(B + f'?r=assignment&c={cid}&id=as_bonus').status == 403, 'assignment outside modules stays closed'
        assert 'Bonus problem' not in (s.goto(B + f'?r=assignments&c={cid}') and s.content()), 'closed assignment not listed'

        # --- calendar drag and drop moves a due date ---
        a.goto(B + f'?r=calendar&c={cid}')
        ev = a.locator('.ev[data-move="due:assignment:as_plan"]')
        if ev.count():
            day = ev.evaluate('e => e.closest(".day").dataset.date')
            target = a.locator('.day[data-date]').nth(20)
            ev.drag_to(target); a.wait_for_timeout(1200); check(a, 'calendar move')
            assert a.locator('.ev[data-move="due:assignment:as_plan"]').evaluate('e => e.closest(".day").dataset.date') != day, 'due date moved'

        # --- phone layout ---
        m = page(390, 844, True); login(m, 'ana@example.edu', 'ana-new-pass')
        m.goto(B + f'?r=course&c={cid}'); check(m, 'mobile'); shot(m, 'mobile.png', full=False)
        br.close()

if __name__ == '__main__':
    main()
