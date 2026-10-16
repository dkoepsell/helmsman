(function () {
  'use strict';
  var CSRF = (document.querySelector('meta[name=csrf]') || {}).content || '';
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  // ---------- confirmations ----------
  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute('data-confirm');
    var sub = e.submitter;
    if (sub && sub.getAttribute('data-confirm')) msg = sub.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (f.method && f.method.toLowerCase() === 'post' && !f.hasAttribute('data-autosave')) {
      // avoid double submits
      setTimeout(function () { $$('button', f).forEach(function (b) { if (b.type !== 'button') b.disabled = true; }); }, 0);
      if (sub && sub.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = sub.name; h.value = sub.value; f.appendChild(h); }
    }
  }, true);

  // ---------- small helpers ----------
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-tab]');
    if (t) {
      var bar = t.parentElement;
      $$('[data-tab]', bar).forEach(function (b) { b.classList.toggle('on', b === t); var p = document.getElementById(b.getAttribute('data-tab')); if (p) p.classList.toggle('hidden', b !== t); });
    }
    var add = e.target.closest('[data-add-row]');
    if (add) {
      var tb = $(add.getAttribute('data-add-row')), tpl = document.getElementById(add.getAttribute('data-template'));
      if (tb && tpl) tb.appendChild(tpl.content.cloneNode(true));
    }
    var rm = e.target.closest('[data-remove-row]');
    if (rm) { var tr = rm.closest('tr'); if (tr) tr.remove(); }
    var sel = e.target.closest('.sel');
    if (sel && sel.tagName !== 'TEXTAREA') { var r = document.createRange(); r.selectNodeContents(sel); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    // close other open popovers
    $$('details.pop[open], details.menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
  $$('textarea.sel').forEach(function (t) { t.addEventListener('focus', function () { t.select(); }); });
  $$('[data-check-all]').forEach(function (cb) {
    cb.addEventListener('change', function () { $$('input[type=checkbox][name="uid[]"]', cb.closest('table')).forEach(function (x) { x.checked = cb.checked; }); });
  });
  $$('input.filter').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      $$(inp.getAttribute('data-filter')).forEach(function (tr) { tr.style.display = tr.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none'; });
    });
  });

  // ---------- video embeds (mirrors the PHP version) ----------
  function videoEmbed(url, title) {
    url = (url || '').trim(); if (!url) return '';
    var m, t = esc(title || 'Video');
    if ((m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/)))
      return '<div class="vid"><iframe src="https://www.youtube-nocookie.com/embed/' + m[1] + '" title="' + t + '" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe></div>';
    if ((m = url.match(/vimeo\.com\/(?:video\/)?(\d+)(?:\/([0-9a-f]+))?/)))
      return '<div class="vid"><iframe src="https://player.vimeo.com/video/' + m[1] + (m[2] ? '?h=' + m[2] : '') + '" title="' + t + '" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
    if ((m = url.match(/drive\.google\.com\/file\/d\/([A-Za-z0-9_-]+)/)))
      return '<div class="vid"><iframe src="https://drive.google.com/file/d/' + m[1] + '/preview" title="' + t + '" allow="autoplay; fullscreen" allowfullscreen></iframe></div>';
    if (/\.(mp4|m4v|webm|mov|ogv)(\?|$)/i.test(url) || /r=cfile/.test(url))
      return '<div class="vid vid-native"><video controls preload="metadata" src="' + esc(url) + '"></video></div>';
    if (/^https:\/\//i.test(url) && /(embed|player|panopto|kaltura|loom\.com\/share|zoom\.us\/rec)/i.test(url))
      return '<div class="vid"><iframe src="' + esc(url.replace('loom.com/share/', 'loom.com/embed/')) + '" title="' + t + '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
    return '<p><a href="' + esc(url) + '" target="_blank" rel="noopener">▶ ' + t + '</a></p>';
  }
  $$('[data-video-preview]').forEach(function (inp) {
    var box = $(inp.getAttribute('data-video-preview')), timer;
    inp.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { box.innerHTML = videoEmbed(inp.value, 'Preview'); }, 400); });
  });

  // ---------- rich text editor ----------
  function upload(ed, file, cb) {
    var fd = new FormData(); fd.append('file', file); fd.append('_csrf', CSRF);
    ed.classList.add('ed-busy');
    fetch(ed.getAttribute('data-upload'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': CSRF } })
      .then(function (r) { return r.json(); })
      .then(function (j) { ed.classList.remove('ed-busy'); if (!j.ok) { alert(j.error || 'Upload failed'); return; } cb(j); })
      .catch(function () { ed.classList.remove('ed-busy'); alert('Upload failed. The file may be larger than the server allows.'); });
  }
  $$('.editor').forEach(function (ed) {
    var area = $('.ed-area', ed), src = $('.ed-src', ed), fileIn = $('.ed-file', ed), pending = null, saved = null;
    area.innerHTML = src.value;
    function sync() { if (!ed.classList.contains('src')) src.value = area.innerHTML.trim() === '<br>' ? '' : area.innerHTML; }
    function saveSel() { var s = window.getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) saved = s.getRangeAt(0).cloneRange(); }
    function restoreSel() { area.focus(); if (saved) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(saved); } }
    function insert(html) {
      if (ed.classList.contains('src')) { var p = src.selectionStart || src.value.length; src.value = src.value.slice(0, p) + html + src.value.slice(p); return; }
      restoreSel(); document.execCommand('insertHTML', false, html); sync();
    }
    area.addEventListener('input', sync); area.addEventListener('blur', function () { saveSel(); sync(); });
    area.addEventListener('keyup', saveSel); area.addEventListener('mouseup', saveSel);
    var form = ed.closest('form'); if (form) form.addEventListener('submit', sync);
    area.addEventListener('paste', function (e) {
      // Paste plain text unless it's coming from another page in this editor.
      var html = e.clipboardData && e.clipboardData.getData('text/html');
      if (html && /class="?(vid|prose)|data-lms/.test(html)) return;
      if (html && e.clipboardData.getData('text/plain')) {
        e.preventDefault();
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        $$('script,style,meta,link', tmp).forEach(function (n) { n.remove(); });
        $$('*', tmp).forEach(function (n) { n.removeAttribute('style'); n.removeAttribute('class'); Array.prototype.slice.call(n.attributes).forEach(function (a) { if (/^on/i.test(a.name)) n.removeAttribute(a.name); }); });
        document.execCommand('insertHTML', false, tmp.innerHTML);
      }
    });
    $$('.ed-bar button', ed).forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var cmd = b.getAttribute('data-cmd'), act = b.getAttribute('data-act');
        if (cmd) { if (ed.classList.contains('src')) return; area.focus(); document.execCommand(cmd, false, b.getAttribute('data-val') ? '<' + b.getAttribute('data-val') + '>' : null); sync(); return; }
        if (act === 'source') {
          if (ed.classList.contains('src')) { area.innerHTML = src.value; ed.classList.remove('src'); b.classList.remove('on'); }
          else { sync(); ed.classList.add('src'); b.classList.add('on'); src.focus(); }
          return;
        }
        if (act === 'link') { saveSel(); var u = prompt('Link address (https://…)'); if (!u) return; if (!/^(https?:|mailto:|\?|#|\/)/i.test(u)) u = 'https://' + u; restoreSel(); var s = window.getSelection(); if (s.isCollapsed) insert('<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(u) + '</a>'); else { document.execCommand('createLink', false, u); sync(); } return; }
        if (act === 'image' || act === 'file') { saveSel(); pending = act; fileIn.accept = act === 'image' ? 'image/*' : ''; fileIn.click(); return; }
        if (act === 'video') {
          saveSel();
          var v = prompt('Paste a video link (YouTube, Vimeo, Google Drive, Loom, Panopto, .mp4).\nLeave empty and press OK to upload a video file instead.');
          if (v === null) return;
          if (v.trim() === '') { pending = 'video'; fileIn.accept = 'video/*,audio/*'; fileIn.click(); return; }
          var title = prompt('Title for this video (shown to screen readers and as the label)', 'Lecture video') || 'Lecture video';
          insert('<h3>' + esc(title) + '</h3>' + videoEmbed(v, title) + '<p></p>');
        }
      });
    });
    fileIn.addEventListener('change', function () {
      var f = fileIn.files[0]; if (!f) return;
      upload(ed, f, function (j) {
        if (pending === 'image') insert('<img src="' + esc(j.url) + '" alt="' + esc(j.name.replace(/\.[^.]+$/, '')) + '" style="max-width:100%;height:auto">');
        else if (pending === 'video') insert(videoEmbed(j.url, j.name) + '<p></p>');
        else insert('<a href="' + esc(j.url) + '" target="_blank">' + esc(j.name) + '</a>');
        fileIn.value = '';
      });
    });
  });

  // ---------- quiz builder ----------
  var qb = $('#qbuilder');
  if (qb) {
    var qs = []; try { qs = JSON.parse(qb.getAttribute('data-json') || '[]'); } catch (e) { qs = []; }
    var LBL = { mc: 'Multiple choice', tf: 'True / false', multi: 'Multiple answers', short: 'Short answer', numeric: 'Number', essay: 'Essay (graded by you)', text: 'Text only (no answer)' };
    var uid = function (p) { return p + Math.random().toString(16).slice(2, 12); };
    function blank(type) {
      var q = { id: uid('q'), type: type || 'mc', text: '', points: 1, choices: [], answers: [], value: 0, tolerance: 0, feedback: '' };
      if (q.type === 'tf') q.choices = [{ id: 'true', text: 'True', correct: true }, { id: 'false', text: 'False', correct: false }];
      if (q.type === 'mc' || q.type === 'multi') q.choices = [{ id: uid('o'), text: '', correct: true }, { id: uid('o'), text: '', correct: false }, { id: uid('o'), text: '', correct: false }];
      return q;
    }
    function total() { var t = 0; qs.forEach(function (q) { if (q.type !== 'text') t += parseFloat(q.points) || 0; }); var el = $('#qpoints'); if (el) el.textContent = Math.round(t * 100) / 100; }
    function render() {
      qb.innerHTML = '';
      qs.forEach(function (q, i) {
        var d = document.createElement('div'); d.className = 'qb-q';
        var opts = Object.keys(LBL).map(function (k) { return '<option value="' + k + '"' + (k === q.type ? ' selected' : '') + '>' + LBL[k] + '</option>'; }).join('');
        var h = '<div class="qb-head"><b>' + (q.type === 'text' ? 'Text' : 'Q' + (qs.slice(0, i + 1).filter(function (x) { return x.type !== 'text'; }).length)) + '</b><select data-k="type">' + opts + '</select>'
          + (q.type !== 'text' ? '<label class="row small" style="flex-direction:row;align-items:center">Points <input class="qb-pts" type="number" step="any" min="0" data-k="points" value="' + esc(q.points) + '"></label>' : '')
          + '<span class="grow"></span><button type="button" class="icon-btn" data-a="up" title="Move up">↑</button><button type="button" class="icon-btn" data-a="down" title="Move down">↓</button><button type="button" class="icon-btn" data-a="dup" title="Duplicate">⧉</button><button type="button" class="linkish danger" data-a="del">Delete</button></div>';
        h += '<textarea rows="3" data-k="text" placeholder="Question text (HTML allowed)">' + esc(q.text) + '</textarea>';
        if (q.type === 'mc' || q.type === 'multi' || q.type === 'tf') {
          (q.choices || []).forEach(function (c, ci) {
            h += '<div class="qb-choice"><label><input type="' + (q.type === 'multi' ? 'checkbox' : 'radio') + '" name="cor-' + q.id + '" data-c="' + ci + '"' + (c.correct ? ' checked' : '') + '> correct</label>'
              + '<input type="text" data-ct="' + ci + '" value="' + esc(c.text) + '" placeholder="Answer choice"' + (q.type === 'tf' ? ' readonly' : '') + '>' + (q.type !== 'tf' ? '<button type="button" class="icon-btn" data-a="rmc" data-c="' + ci + '" title="Remove choice">×</button>' : '') + '</div>';
          });
          if (q.type !== 'tf') h += '<div><button type="button" class="btn sm" data-a="addc">+ Choice</button></div>';
        } else if (q.type === 'short') {
          h += '<label>Accepted answers, one per line <small>Matching ignores capitals and extra spaces.</small><textarea rows="3" data-k="answers">' + esc((q.answers || []).join('\n')) + '</textarea></label>';
        } else if (q.type === 'numeric') {
          h += '<div class="row"><label>Correct value <input type="number" step="any" data-k="value" value="' + esc(q.value || 0) + '"></label><label>± margin <input type="number" step="any" min="0" data-k="tolerance" value="' + esc(q.tolerance || 0) + '"></label></div>';
        }
        if (q.type !== 'text') h += '<label>Feedback shown after answering <small>(optional)</small><input type="text" data-k="feedback" value="' + esc(q.feedback || '') + '"></label>';
        d.innerHTML = h;
        d.addEventListener('input', function (e) {
          var k = e.target.getAttribute('data-k'), ct = e.target.getAttribute('data-ct');
          if (k === 'answers') q.answers = e.target.value.split('\n'); else if (k && k !== 'type') q[k] = e.target.value;
          if (ct !== null) q.choices[+ct].text = e.target.value;
          total();
        });
        d.addEventListener('change', function (e) {
          var k = e.target.getAttribute('data-k'), c = e.target.getAttribute('data-c');
          if (k === 'type') { var n = blank(e.target.value); n.id = q.id; n.text = q.text; n.points = q.points; if ((q.type === 'mc' || q.type === 'multi') && (n.type === 'mc' || n.type === 'multi')) n.choices = q.choices; qs[i] = n; render(); return; }
          if (c !== null && e.target.type !== 'button') {
            if (q.type === 'multi') q.choices[+c].correct = e.target.checked;
            else q.choices.forEach(function (x, xi) { x.correct = xi === +c; });
          }
        });
        d.addEventListener('click', function (e) {
          var a = e.target.getAttribute('data-a'); if (!a) return;
          if (a === 'del' && confirm('Delete this question?')) qs.splice(i, 1);
          if (a === 'up' && i > 0) qs.splice(i - 1, 0, qs.splice(i, 1)[0]);
          if (a === 'down' && i < qs.length - 1) qs.splice(i + 1, 0, qs.splice(i, 1)[0]);
          if (a === 'dup') { var cp = JSON.parse(JSON.stringify(q)); cp.id = uid('q'); (cp.choices || []).forEach(function (c) { if (cp.type !== 'tf') c.id = uid('o'); }); qs.splice(i + 1, 0, cp); }
          if (a === 'addc') q.choices.push({ id: uid('o'), text: '', correct: false });
          if (a === 'rmc') q.choices.splice(+e.target.getAttribute('data-c'), 1);
          render();
        });
        qb.appendChild(d);
      });
      var add = document.createElement('div'); add.className = 'row';
      add.innerHTML = '<span class="muted small">Add:</span>' + Object.keys(LBL).map(function (k) { return '<button type="button" class="btn sm" data-new="' + k + '">' + LBL[k] + '</button>'; }).join('');
      add.addEventListener('click', function (e) { var t = e.target.getAttribute('data-new'); if (t) { qs.push(blank(t)); render(); var all = $$('.qb-q textarea', qb); if (all.length) all[all.length - 1].focus(); } });
      qb.appendChild(add);
      total();
    }
    render();
    var f = qb.closest('form');
    f.addEventListener('submit', function () {
      qs.forEach(function (q) { if (q.answers) q.answers = q.answers.map(function (s) { return s.trim(); }).filter(Boolean); });
      $('#questions_json').value = JSON.stringify(qs);
    });
  }

  // ---------- quiz taking: autosave + timer ----------
  var qf = $('form[data-autosave]');
  if (qf) {
    var st = $('.save-state'), t, dirty = false;
    function save() {
      var fd = new FormData(qf); fd.set('autosave', '1'); fd.set('final', '0');
      if (st) st.textContent = 'Saving…';
      fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; dirty = false; if (st) st.textContent = 'All answers saved.'; })
        .catch(function () { if (st) st.textContent = 'Couldn’t save. Check your connection; your answers are still on this page.'; });
    }
    qf.addEventListener('input', function () { dirty = true; clearTimeout(t); t = setTimeout(save, 1200); });
    qf.addEventListener('change', function () { dirty = true; clearTimeout(t); t = setTimeout(save, 400); });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    qf.addEventListener('submit', function () { dirty = false; });
  }
  var timer = $('.timer[data-deadline]');
  if (timer) {
    var skew = Date.now() / 1000 - parseInt(timer.getAttribute('data-now'), 10);
    var dl = parseInt(timer.getAttribute('data-deadline'), 10), span = $('span', timer), done = false;
    var tick = function () {
      var left = Math.round(dl - (Date.now() / 1000 - skew));
      if (left <= 0 && !done) {
        done = true; span.textContent = '0:00';
        var f = $('#quizform'); if (f) { f.querySelector('input[name=final]').value = '1'; f.removeAttribute('data-autosave'); f.submit(); }
        return;
      }
      var m = Math.floor(left / 60), s = left % 60;
      span.textContent = (m >= 60 ? Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
      timer.classList.toggle('low', left < 120);
    };
    tick(); setInterval(tick, 1000);
  }

  // ---------- rubric total -> score ----------
  var rub = $$('.rub-pts');
  if (rub.length) {
    var upd = function () {
      var t = 0, any = false; rub.forEach(function (i) { if (i.value !== '') { t += parseFloat(i.value) || 0; any = true; } });
      $$('.rub-total').forEach(function (e) { e.textContent = Math.round(t * 100) / 100; });
      var sc = $('#score'); if (sc) sc.placeholder = any ? String(Math.round(t * 100) / 100) : '';
    };
    rub.forEach(function (i) { i.addEventListener('input', upd); }); upd();
  }

  // ---------- calendar drag and drop ----------
  var cal = $('.cal.editable');
  if (cal) {
    var dragging = null;
    cal.addEventListener('dragstart', function (e) { var ev = e.target.closest('[data-move]'); if (!ev) return; dragging = ev; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ev.getAttribute('data-move')); });
    cal.addEventListener('dragover', function (e) { var d = e.target.closest('.day'); if (d && dragging) { e.preventDefault(); $$('.day.drop', cal).forEach(function (x) { if (x !== d) x.classList.remove('drop'); }); d.classList.add('drop'); } });
    cal.addEventListener('dragleave', function (e) { var d = e.target.closest('.day'); if (d && !d.contains(e.relatedTarget)) d.classList.remove('drop'); });
    cal.addEventListener('drop', function (e) {
      var d = e.target.closest('.day'); if (!d || !dragging) return; e.preventDefault(); d.classList.remove('drop');
      var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('move', dragging.getAttribute('data-move')); fd.append('date', d.getAttribute('data-date'));
      var el = dragging; dragging = null;
      d.appendChild(el); el.style.opacity = '.5';
      fetch(cal.getAttribute('data-move-url'), { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error(j.error); location.reload(); })
        .catch(function (err) { alert('Couldn’t move that: ' + (err.message || 'error')); location.reload(); });
    });
  }
})();
