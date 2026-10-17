#!/usr/bin/env python3
"""Builds tests/fixtures/sample-course.imscc: a small Canvas-style course export
used by the tests and the demo. Run: python3 tests/fixtures/make_sample.py"""
import io, os, zipfile, struct, zlib

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'sample-course.imscc')

def banner(text, sub):
    """Course banner. Uses Pillow when available (nicer); otherwise a plain dependency-free PNG."""
    try:
        from PIL import Image, ImageDraw, ImageFont
        import random
        im = Image.new('RGB', (1200, 300), (20, 33, 61)); d = ImageDraw.Draw(im)
        d.rectangle([0, 290, 1200, 300], fill=(122, 34, 50))
        def font(names, size):
            for n in names:
                try: return ImageFont.truetype(n, size)
                except Exception: pass
            return ImageFont.load_default()
        serif = font(['DejaVuSerif-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf'], 54)
        sans = font(['DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'], 18)
        sans2 = font(['DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'], 22)
        d.text((56, 58), 'SAMPLE COURSE', fill=(224, 179, 106), font=sans)
        d.text((56, 98), text, fill=(246, 243, 236), font=serif)
        d.text((58, 190), sub, fill=(196, 206, 224), font=sans2)
        random.seed(3)
        pts = [(random.randint(860, 1160), random.randint(30, 260)) for _ in range(8)]
        for i in range(len(pts) - 1): d.line([pts[i], pts[i + 1]], fill=(70, 88, 128), width=2)
        for i, (x, y) in enumerate(pts):
            r = 9 if i % 3 else 12
            d.ellipse([x - r, y - r, x + r, y + r], fill=(224, 179, 106) if i % 2 else (200, 210, 228))
        buf = io.BytesIO(); im.save(buf, 'PNG', optimize=True); return buf.getvalue()
    except ImportError:
        pass
    w, h = 1200, 240
    rows = []
    for y in range(h):
        row = bytearray([0])
        for x in range(w):
            if y >= h - 10: c = (122, 34, 50)
            elif 40 < y < 46 and 56 < x < 260: c = (224, 179, 106)
            else:
                t = x / w
                c = (int(20 + 14 * t), int(33 + 18 * t), int(61 + 30 * t))
            row += bytes(c)
        rows.append(bytes(row))
    raw = b''.join(rows)
    def chunk(t, d): return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw, 9)) + chunk(b'IEND', b'')

def page(ident, title, body, state='active'):
    return f'''<html>\n<head>\n<meta http-equiv="Content-Type" content="text/html; charset=utf-8">\n<title>{title}</title>\n<meta name="identifier" content="{ident}"/>\n<meta name="workflow_state" content="{state}"/>\n</head>\n<body>\n{body}\n</body>\n</html>\n'''

PAGES = [
  ('pg_welcome', 'welcome', 'Welcome', '<p><img src="$IMS-CC-FILEBASE$/images/banner.png" alt="Course banner" style="max-width:100%"></p><h2>Welcome</h2><p>This two-week sample course (a made-up beekeeping workshop) shows what heLMSman imports from a Canvas export: modules, pages, assignments with rubrics, graded discussions and quizzes.</p><p>Start with <a href="$WIKI_REFERENCE$/pages/week-1-overview">the Week 1 overview</a>.</p>'),
  ('pg_w1', 'week-1-overview', 'Week 1 Overview: Meet the Colony', '<h3>Goals</h3><ul><li>Name the three kinds of bee in a hive.</li><li>Do a calm, safe hive inspection.</li></ul><h3>Watch</h3><p>1.1 Inside the hive [video link]</p><h3>Do</h3><p>Submit your <a href="$CANVAS_OBJECT_REFERENCE$/assignments/as_log">hive inspection log</a>.</p>'),
  ('pg_w2', 'week-2-overview', 'Week 2 Overview: Through the Seasons', '<h3>Goals</h3><ul><li>Plan a year of hive care, from spring build-up to winter.</li></ul><p>Take the <a href="$CANVAS_OBJECT_REFERENCE$/quizzes/qz_bees">honey bee quiz</a>.</p>'),
  ('pg_notes', 'instructor-notes', 'Instructor notes (hidden)', '<p>Only instructors see this page.</p>'),
]

def build():
    z = zipfile.ZipFile(OUT, 'w', zipfile.ZIP_DEFLATED)
    w = z.writestr
    res = []
    for ident, slug, title, body in PAGES:
        w(f'wiki_content/{slug}.html', page(ident, title, body, 'unpublished' if ident == 'pg_notes' else 'active'))
        res.append(f'<resource identifier="{ident}" type="webcontent" href="wiki_content/{slug}.html"><file href="wiki_content/{slug}.html"/></resource>')
    w('web_resources/images/banner.png', banner('Backyard Beekeeping', 'Hive basics, seasonal care and the honey harvest'))
    res.append('<resource identifier="img_banner" type="webcontent" href="web_resources/images/banner.png"><file href="web_resources/images/banner.png"/></resource>')
    w('web_resources/handouts/inspection-checklist.txt', 'Hive inspection checklist\n- Queen or fresh eggs seen\n- Brood pattern\n- Honey and pollen stores\n- Signs of pests or disease\n- Temperament\n')
    res.append('<resource identifier="f_tt" type="webcontent" href="web_resources/handouts/inspection-checklist.txt"><file href="web_resources/handouts/inspection-checklist.txt"/></resource>')

    # A file nothing links to (students must not be able to fetch it) and an
    # assignment outside every module that must stay closed until its date.
    w('web_resources/instructor/answer-key.txt', 'Q1: it masks the alarm scent')
    w('as_bonus/bonus.html', '<html><body><p>Optional: write a swarm-catching plan.</p></body></html>')
    w('as_bonus/assignment_settings.xml', '<?xml version="1.0" encoding="UTF-8"?><assignment identifier="as_bonus" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>Bonus: swarm plan</title><unlock_at>2030-01-01T14:00:00</unlock_at><workflow_state>published</workflow_state><assignment_group_identifierref>ag_hw</assignment_group_identifierref><points_possible>2.0</points_possible><submission_types>online_text_entry</submission_types><omit_from_final_grade>true</omit_from_final_grade></assignment>')
    res.append('<resource identifier="as_bonus" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="as_bonus/bonus.html"><file href="as_bonus/bonus.html"/><file href="as_bonus/assignment_settings.xml"/></resource>')
    w('course_settings/course_settings.xml', '<?xml version="1.0" encoding="UTF-8"?><course identifier="c1" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>Backyard Beekeeping (Sample)</title><course_code>BEE 101 Sample</course_code><apply_assignment_group_weights>true</apply_assignment_group_weights></course>')
    w('course_settings/assignment_groups.xml', '<?xml version="1.0" encoding="UTF-8"?><assignmentGroups xmlns="http://canvas.instructure.com/xsd/cccv1p0"><assignmentGroup identifier="ag_hw"><title>Field work</title><group_weight>60.0</group_weight></assignmentGroup><assignmentGroup identifier="ag_quiz"><title>Quizzes</title><group_weight>25.0</group_weight></assignmentGroup><assignmentGroup identifier="ag_disc"><title>Discussion</title><group_weight>15.0</group_weight></assignmentGroup></assignmentGroups>')
    w('course_settings/rubrics.xml', '<?xml version="1.0" encoding="UTF-8"?><rubrics xmlns="http://canvas.instructure.com/xsd/cccv1p0"><rubric identifier="rb_map"><title>Inspection log</title><points_possible>10</points_possible><criteria><criterion><criterion_id>c1</criterion_id><description>Observations</description><long_description>Records queen or eggs, brood, stores and pests.</long_description><points>6</points></criterion><criterion><criterion_id>c2</criterion_id><description>Next steps</description><long_description>Says what to do before the next inspection, and why.</long_description><points>4</points></criterion></criteria></rubric></rubrics>')
    w('course_settings/syllabus.html', '<html><body><h2>Backyard Beekeeping (Sample)</h2><p>A made-up two-week workshop for trying heLMSman.</p><h3>Grading</h3><p>Field work 60%, quizzes 25%, discussion 15%.</p></body></html>')
    res.insert(0, '<resource identifier="course_settings" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="course_settings/canvas_export.txt"><file href="course_settings/course_settings.xml"/><file href="course_settings/module_meta.xml"/><file href="course_settings/assignment_groups.xml"/><file href="course_settings/rubrics.xml"/><file href="course_settings/syllabus.html"/></resource>')
    w('course_settings/canvas_export.txt', 'Sample export.')

    w('as_log/inspection-log.html', '<html><body><p>Inspect a hive (yours or a mentor&#8217;s) and record what you saw. Upload a photo or PDF of your log, or type it out.</p></body></html>')
    w('as_log/assignment_settings.xml', '<?xml version="1.0" encoding="UTF-8"?><assignment identifier="as_log" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>Hive inspection log</title><due_at>2027-02-06T05:59:00</due_at><lock_at/><unlock_at>2027-02-01T14:00:00</unlock_at><workflow_state>published</workflow_state><assignment_group_identifierref>ag_hw</assignment_group_identifierref><rubric_identifierref>rb_map</rubric_identifierref><points_possible>10.0</points_possible><grading_type>points</grading_type><submission_types>online_upload,online_text_entry</submission_types><allowed_extensions>pdf,png,jpg,txt</allowed_extensions></assignment>')
    res.append('<resource identifier="as_log" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="as_log/inspection-log.html"><file href="as_log/inspection-log.html"/><file href="as_log/assignment_settings.xml"/></resource>')
    w('as_plan/season-plan.html', '<html><body><p>Write a one-page plan for your first year of hive care, or link to a shared document.</p></body></html>')
    w('as_plan/assignment_settings.xml', '<?xml version="1.0" encoding="UTF-8"?><assignment identifier="as_plan" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>Season plan</title><due_at>2027-02-13T05:59:00</due_at><unlock_at>2027-02-08T14:00:00</unlock_at><workflow_state>published</workflow_state><assignment_group_identifierref>ag_hw</assignment_group_identifierref><points_possible>20.0</points_possible><submission_types>online_text_entry,online_url</submission_types><muted>true</muted></assignment>')
    res.append('<resource identifier="as_plan" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="as_plan/season-plan.html"><file href="as_plan/season-plan.html"/><file href="as_plan/assignment_settings.xml"/></resource>')

    w('dt_intro.xml', '<?xml version="1.0" encoding="UTF-8"?><topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1"><title>Introduce yourself</title><text texttype="text/html">&lt;p&gt;Tell us why you want to keep bees, and where your hive will go.&lt;/p&gt;</text></topic>')
    w('dt_intro_meta.xml', '<?xml version="1.0" encoding="UTF-8"?><topicMeta identifier="dt_intro_meta" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><topic_id>dt_intro</topic_id><title>Introduce yourself</title><type>topic</type><require_initial_post>true</require_initial_post><workflow_state>active</workflow_state><assignment identifier="dt_intro_as"><title>Introduce yourself</title><due_at>2027-02-04T05:59:00</due_at><assignment_group_identifierref>ag_disc</assignment_group_identifierref><points_possible>5.0</points_possible></assignment></topicMeta>')
    res.append('<resource identifier="dt_intro" type="imsdt_xmlv1p1"><file href="dt_intro.xml"/><dependency identifierref="dt_intro_meta"/></resource>')
    res.append('<resource identifier="dt_intro_meta" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="dt_intro_meta.xml"><file href="dt_intro_meta.xml"/></resource>')

    def item(i, qtype, text, pts, choices=None, correct=None, extra=''):
        meta = f'<itemmetadata><qtimetadata><qtimetadatafield><fieldlabel>question_type</fieldlabel><fieldentry>{qtype}</fieldentry></qtimetadatafield><qtimetadatafield><fieldlabel>points_possible</fieldlabel><fieldentry>{pts}</fieldentry></qtimetadatafield></qtimetadata></itemmetadata>'
        pres = f'<presentation><material><mattext texttype="text/html">&lt;p&gt;{text}&lt;/p&gt;</mattext></material>'
        if choices:
            card = 'Multiple' if qtype == 'multiple_answers_question' else 'Single'
            pres += f'<response_lid ident="response1" rcardinality="{card}"><render_choice>' + ''.join(f'<response_label ident="{cid}"><material><mattext texttype="text/plain">{ct}</mattext></material></response_label>' for cid, ct in choices) + '</render_choice></response_lid>'
        else:
            pres += '<response_str ident="response1" rcardinality="Single"><render_fib><response_label ident="answer1"/></render_fib></response_str>'
        pres += '</presentation>'
        return f'<item ident="{i}" title="Question">{meta}{pres}<resprocessing><outcomes><decvar maxvalue="100" minvalue="0" varname="SCORE" vartype="Decimal"/></outcomes>{extra}</resprocessing></item>'
    def rc(cond): return f'<respcondition continue="No"><conditionvar>{cond}</conditionvar><setvar action="Set" varname="SCORE">100</setvar></respcondition>'
    items = [
      item('q1', 'multiple_choice_question', 'Why do beekeepers use a smoker?', 1, [('101', 'It masks the bees’ alarm scent'), ('102', 'It puts the bees to sleep'), ('103', 'It kills mites')], extra=rc('<varequal respident="response1">101</varequal>')),
      item('q2', 'true_false_question', 'All worker bees are female.', 1, [('201', 'True'), ('202', 'False')], extra=rc('<varequal respident="response1">201</varequal>')),
      item('q3', 'multiple_answers_question', 'Which of these do honey bees make?', 2, [('301', 'Honey'), ('302', 'Silk'), ('303', 'Beeswax')], extra=rc('<and><varequal respident="response1">301</varequal><not><varequal respident="response1">302</varequal></not><varequal respident="response1">303</varequal></and>')),
      item('q4', 'short_answer_question', 'What is a male honey bee called?', 1, extra=rc('<varequal respident="response1">drone</varequal>')),
      item('q5', 'numerical_question', 'How many legs does a honey bee have?', 1, extra=rc('<or><varequal respident="response1">6</varequal></or>')),
      item('q6', 'essay_question', 'What would you check on your first inspection of a new hive, and why?', 4),
    ]
    w('non_cc_assessments/qz_bees.xml.qti', '<?xml version="1.0" encoding="UTF-8"?><questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2"><assessment ident="qz_bees" title="Honey bee quiz"><section ident="root_section">' + ''.join(items) + '</section></assessment></questestinterop>')
    w('qz_bees/assessment_meta.xml', '<?xml version="1.0" encoding="UTF-8"?><quiz identifier="qz_bees" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>Honey bee quiz</title><description>&lt;p&gt;Ten minutes, two attempts. Your best score counts.&lt;/p&gt;</description><shuffle_answers>true</shuffle_answers><time_limit>10</time_limit><allowed_attempts>2</allowed_attempts><show_correct_answers>true</show_correct_answers><available>true</available><assignment_group_identifierref>ag_quiz</assignment_group_identifierref><assignment><due_at>2027-02-13T05:59:00</due_at><assignment_group_identifierref>ag_quiz</assignment_group_identifierref></assignment></quiz>')
    res.append('<resource identifier="qz_bees" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="qz_bees/assessment_meta.xml"><file href="qz_bees/assessment_meta.xml"/><file href="non_cc_assessments/qz_bees.xml.qti"/></resource>')

    # A practice quiz with one fixed question (with feedback) plus a group that draws 2 of 4
    # questions from a question bank, as Canvas exports them.
    bank_items = ''.join(item(f'b{k}', 'true_false_question', f'Bank statement {k}: honey bees have {n} wings.', 1,
                              [(f'9{k}1', 'True'), (f'9{k}2', 'False')], extra=rc(f'<varequal respident="response1">9{k}{1 if n == 4 else 2}</varequal>'))
                         for k, n in enumerate([4, 2, 4, 6], 1))
    w('non_cc_assessments/bank_bees.xml.qti', '<?xml version="1.0" encoding="UTF-8"?><questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2"><objectbank ident="bank_bees"><qtimetadata><qtimetadatafield><fieldlabel>bank_title</fieldlabel><fieldentry>Bee facts</fieldentry></qtimetadatafield></qtimetadata>' + bank_items + '</objectbank></questestinterop>')
    fixed = item('p1', 'multiple_choice_question', 'Which caste lays the eggs?', 1, [('801', 'The queen'), ('802', 'Workers'), ('803', 'Drones')], extra=rc('<varequal respident="response1">801</varequal>'))
    fixed = fixed.replace('</resprocessing></item>', '</resprocessing><itemfeedback ident="general_fb"><flow_mat><material><mattext texttype="text/html">&lt;p&gt;Only the queen lays fertilized eggs.&lt;/p&gt;</mattext></material></flow_mat></itemfeedback></item>')
    group = '<section ident="grp1" title="Bee facts draw"><selection_ordering><selection><sourcebank_ref>bank_bees</sourcebank_ref><selection_number>2</selection_number><selection_extension><points_per_item>1.0</points_per_item></selection_extension></selection></selection_ordering></section>'
    w('non_cc_assessments/qz_practice.xml.qti', '<?xml version="1.0" encoding="UTF-8"?><questestinterop xmlns="http://www.imsglobal.org/xsd/ims_qtiasiv1p2"><assessment ident="qz_practice" title="Practice: bee basics"><section ident="root_section">' + fixed + group + '</section></assessment></questestinterop>')
    w('qz_practice/assessment_meta.xml', '<?xml version="1.0" encoding="UTF-8"?><quiz identifier="qz_practice" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><title>Practice: bee basics</title><quiz_type>practice_quiz</quiz_type><allowed_attempts>-1</allowed_attempts><show_correct_answers>true</show_correct_answers><available>true</available><assignment_group_identifierref>ag_quiz</assignment_group_identifierref></quiz>')
    res.append('<resource identifier="qz_practice" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="qz_practice/assessment_meta.xml"><file href="qz_practice/assessment_meta.xml"/><file href="non_cc_assessments/qz_practice.xml.qti"/></resource>')
    # An announcement (Canvas exports these as discussion topics of type "announcement").
    w('ann_welcome.xml', '<?xml version="1.0" encoding="UTF-8"?><topic xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imsdt_v1p1"><title>Welcome, beekeepers</title><text texttype="text/html">&lt;p&gt;Glad you are here.&lt;/p&gt;</text></topic>')
    w('ann_welcome_meta.xml', '<?xml version="1.0" encoding="UTF-8"?><topicMeta identifier="ann_welcome_meta" xmlns="http://canvas.instructure.com/xsd/cccv1p0"><topic_id>ann_welcome</topic_id><title>Welcome, beekeepers</title><type>announcement</type><posted_at>2027-01-25T15:00:00</posted_at><workflow_state>active</workflow_state></topicMeta>')
    res.append('<resource identifier="ann_welcome" type="imsdt_xmlv1p1"><file href="ann_welcome.xml"/><dependency identifierref="ann_welcome_meta"/></resource>')
    res.append('<resource identifier="ann_welcome_meta" type="associatedcontent/imscc_xmlv1p1/learning-application-resource" href="ann_welcome_meta.xml"><file href="ann_welcome_meta.xml"/></resource>')

    def mi(ident, ctype, title, ref=None, indent=1, state='active', url=None):
        return (f'<item identifier="{ident}"><content_type>{ctype}</content_type><workflow_state>{state}</workflow_state><title>{title}</title>'
                + (f'<identifierref>{ref}</identifierref>' if ref else '') + (f'<url>{url}</url>' if url else '') + f'<indent>{indent}</indent></item>')
    w('course_settings/module_meta.xml', '<?xml version="1.0" encoding="UTF-8"?><modules xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
      + '<module identifier="m_start"><title>Start Here</title><workflow_state>active</workflow_state><items>'
      + mi('i1', 'WikiPage', 'Welcome', 'pg_welcome') + mi('i2', 'DiscussionTopic', 'Introduce yourself', 'dt_intro')
      + mi('i3', 'Attachment', 'Inspection checklist (handout)', 'f_tt') + mi('i4', 'ExternalUrl', 'Western honey bee (Wikipedia)', url='https://en.wikipedia.org/wiki/Western_honey_bee')
      + mi('i5', 'WikiPage', 'Instructor notes (hidden)', 'pg_notes', state='unpublished') + '</items></module>'
      + '<module identifier="m_w1"><title>Week 1: Meet the Colony</title><workflow_state>active</workflow_state><items>'
      + mi('i6', 'ContextModuleSubHeader', 'Learn', indent=0) + mi('i7', 'WikiPage', 'Week 1 Overview: Meet the Colony', 'pg_w1')
      + mi('i8', 'ContextModuleSubHeader', 'Do', indent=0) + mi('i9', 'Assignment', 'Hive inspection log', 'as_log') + '</items></module>'
      + '<module identifier="m_w2"><title>Week 2: Through the Seasons</title><workflow_state>active</workflow_state><items>'
      + mi('i10', 'WikiPage', 'Week 2 Overview: Through the Seasons', 'pg_w2') + mi('i11', 'Quizzes::Quiz', 'Honey bee quiz', 'qz_bees')
      + mi('i12', 'Assignment', 'Season plan', 'as_plan') + mi('i13', 'Quizzes::Quiz', 'Practice: bee basics', 'qz_practice') + '</items></module></modules>')

    w('imsmanifest.xml', '<?xml version="1.0" encoding="UTF-8"?>\n<manifest identifier="sample" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1" xmlns:lomimscc="http://ltsc.ieee.org/xsd/imsccv1p1/LOM/manifest"><metadata><schema>IMS Common Cartridge</schema><schemaversion>1.1.0</schemaversion><lomimscc:lom><lomimscc:general><lomimscc:title><lomimscc:string>Backyard Beekeeping (Sample)</lomimscc:string></lomimscc:title></lomimscc:general></lomimscc:lom></metadata><organizations><organization identifier="org_1" structure="rooted-hierarchy"><item identifier="LearningModules"/></organization></organizations><resources>' + ''.join(res) + '</resources></manifest>')
    z.close()
    print('wrote', OUT)

if __name__ == '__main__':
    build()
