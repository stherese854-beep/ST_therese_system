<?php
// ============================================================
//  PAGE HEAD  (includes/head.php)
// ============================================================
//  Outputs the <head> with Bootstrap 5 (from CDN) + our CSS.
//  Set $page_title before including, e.g. $page_title = "Dashboard".
//
//  NOTE: Bootstrap is loaded from a CDN, so your computer needs
//  an internet connection the first time. (Everything else works
//  fully offline on XAMPP.)
// ============================================================
$page_title = $page_title ?? 'St. Therese Dental Clinic';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>"><!-- sent with JS POST requests -->
    <title><?= e($page_title) ?> — St. Therese Dental Clinic</title>

    <!-- Bootstrap 5 CSS (CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Icons: Bootstrap Icons + js/icons.js, which swaps the pages' emojis for these icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="js/icons.js?v=<?= @filemtime(__DIR__ . '/../js/icons.js') ?: time() ?>"></script>
    <!-- Our custom theme (versioned by file time so browsers always fetch
         the latest copy after a deploy, instead of serving a stale cache) -->
    <link href="css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?: time() ?>" rel="stylesheet">
    <?php if (isset($pdo) && function_exists('theme_style_tag')) echo theme_style_tag($pdo, 'system');   // admin's chosen colours ?>
    <?php if (isset($pdo) && function_exists('text_scale_style')) echo text_scale_style($pdo);   // text size (own choice, else clinic default) ?>
    <script>
    // ---- Number fields: digits only ----
    // Phone numbers, ages, codes, counts... (type="tel", type="number" or
    // data-digits) only accept 0-9: other keys are ignored, and pasted or
    // autofilled text keeps only its digits.
    (function () {
        function isDigits(t) { return t && t.tagName === 'INPUT' && (t.type === 'tel' || t.type === 'number' || t.hasAttribute('data-digits')); }
        // beforeinput sees every typed/inserted character (keyboard, on-screen
        // keyboards, autofill...), so anything that is not a digit is refused.
        document.addEventListener('beforeinput', function (e) {
            if (!isDigits(e.target) || !e.data) return;
            if (/\D/.test(e.data)) {
                e.preventDefault();
                var d = e.data.replace(/\D/g, '');            // keep the digits of pasted/inserted text
                if (d && e.target.type !== 'number' && typeof e.target.setRangeText === 'function') {
                    e.target.setRangeText(d, e.target.selectionStart, e.target.selectionEnd, 'end');
                    e.target.dispatchEvent(new Event('input', { bubbles: true }));
                } else if (d && e.target.type === 'number') {
                    e.target.value = (e.target.value || '') + d;
                }
            }
        });
        document.addEventListener('input', function (e) {
            var t = e.target;
            if (!isDigits(t) || t.type === 'number') return;
            var v = t.value.replace(/\D/g, '');
            if (t.maxLength > 0) v = v.slice(0, t.maxLength);
            if (v !== t.value) t.value = v;
        });
        document.addEventListener('paste', function (e) {
            var t = e.target;
            if (!isDigits(t) || t.type !== 'number') return;
            var txt = (e.clipboardData || window.clipboardData).getData('text');
            if (/\D/.test(txt)) { e.preventDefault(); t.value = txt.replace(/\D/g, ''); }
        });
    })();

    // ---- Action buttons: icon only, with a label on hover ----
    // Small buttons (.btn-sm) and buttons inside table rows show just their
    // icon; the words move into a tooltip that appears when you point at the
    // button (and into aria-label for screen readers). "📅 Book" -> the
    // calendar icon + tooltip "Book"; a plain "View" or "Edit" gets a matching icon. Buttons
    // whose text a script changes (they have an id), buttons in pop-ups and
    // anything marked data-keep-text are left as they are.
    (function () {
        var ICONS = { 'view': 'eye', 'edit': 'pencil', 'book': 'calendar-event', 'delete': 'trash3', 'remove': 'trash3',
                      'restore': 'arrow-counterclockwise', 'undo': 'arrow-counterclockwise', 'approve': 'check-lg',
                      'publish': 'check-lg', 'hide': 'slash-circle', 'email': 'envelope-at', 'sms': 'phone',
                      'message': 'envelope', 'print': 'printer', 'export': 'download', 'upload': 'upload',
                      'filter': 'search', 'search': 'search', 'clear': 'x-lg', 'arrived': 'check-lg',
                      'did attend': 'check-lg', 'confirm no-show': 'x-lg', 'see all': 'eye', 'pause': 'pause-fill' };
        var NAMES = { 'trash3': 'Delete', 'check-lg': 'Approve', 'x-lg': 'Close', 'pencil': 'Edit',
                      'arrow-counterclockwise': 'Restore', 'upload': 'Upload' };
        function iconize(b) {
            if (b.dataset.iconized || b.id || b.hasAttribute('data-keep-text') || b.dataset.tip) return;   // already an icon with its own label
            if (b.closest('.modal, .bulk-bar, [data-keep-text], #topbarWidgets, .wizard-step')) return;
            if (b.querySelector('img, svg, input, select')) return;
            var txt = b.textContent.replace(/\s+/g, ' ').trim();
            var lead = b.firstElementChild && b.firstElementChild.matches('i.bi')
                       && !(b.firstChild.nodeType === 3 && b.firstChild.nodeValue.trim()) ? b.firstElementChild : null;
            var icon = '', label = '';
            if (lead) {                                                  // "<i class=bi-calendar-event> Book"
                icon = lead.outerHTML;
                label = txt || b.getAttribute('title') || b.getAttribute('aria-label')
                        || NAMES[(lead.className.match(/bi-([\w-]+)/) || [])[1]] || '';
            } else {
                if (!txt) return;
                var key = txt.toLowerCase().replace(/[→▲▼]/g, '').trim();
                var name = ICONS[key] || ICONS[key.split(' ')[0]] || '';
                var m = txt.match(/^([^\p{L}\p{N}\s]+)\s*(.*)$/u);       // a leading symbol, e.g. "↩ Undo"
                if (name) icon = '<i class="bi bi-' + name + '" aria-hidden="true"></i>';
                else if (m) { icon = m[1]; txt = m[2]; }
                label = txt;
            }
            if (!icon) return;
            label = label.replace(/\s*[→▲▼]+\s*$/, '').trim();
            b.dataset.iconized = '1';
            if (label) { b.setAttribute('aria-label', label); b.dataset.tip = label; b.removeAttribute('title'); }
            b.innerHTML = icon;
            b.classList.add('icon-btn');
            markActions(b);
        }
        // Only the row's LAST cell (its action buttons) is kept on one line.
        function markActions(b) {
            var td = b.closest('td');
            if (td && td === td.parentElement.lastElementChild) td.classList.add('td-actions');
        }
        function run() {
            document.querySelectorAll('.btn-sm, td .btn').forEach(iconize);
            document.querySelectorAll('td .icon-btn').forEach(markActions);
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();

        // One tooltip for the whole page. It lives on <body>, so it is never
        // cut off by a scrolling table the way a CSS tooltip would be.
        var tip = null;
        function show(el) {
            if (!el.dataset.tip && el.getAttribute('title')) { el.dataset.tip = el.getAttribute('title'); el.removeAttribute('title'); }
            if (!el.dataset.tip) return;
            if (!tip) { tip = document.createElement('div'); tip.className = 'ui-tip'; document.body.appendChild(tip); }
            tip.textContent = el.dataset.tip;
            tip.style.display = 'block';
            var r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
            tip.style.left = Math.max(6, Math.min(window.innerWidth - w - 6, r.left + r.width / 2 - w / 2)) + 'px';
            tip.style.top  = (r.top - h - 8 < 4 ? r.bottom + 8 : r.top - h - 8) + 'px';
        }
        function hide() { if (tip) tip.style.display = 'none'; }
        document.addEventListener('mouseover', function (e) {
            var el = e.target.closest && e.target.closest('[data-tip], .icon-btn[title]');
            if (el) show(el);
        });
        document.addEventListener('mouseout', function (e) {
            var el = e.target.closest && e.target.closest('[data-tip]');
            if (el && !el.contains(e.relatedTarget)) hide();
        });
        document.addEventListener('focusin', function (e) { var el = e.target.closest && e.target.closest('[data-tip]'); if (el) show(el); });
        document.addEventListener('focusout', hide);
        window.addEventListener('scroll', hide, true);
        document.addEventListener('click', hide);
    })();

    // ---- Type-to-search drop-downs: <select data-search="placeholder"> ----
    // A search box appears above the drop-down. Typing (e.g. "Bi") opens the
    // list showing only the matching choices; click one, or press Enter to
    // take the first match. The drop-down's own onchange then runs as usual.
    (function () {
        function setup(sel) {
            if (sel.dataset.searchReady) return;
            sel.dataset.searchReady = '1';
            var inp = document.createElement('input');
            inp.type = 'search'; inp.autocomplete = 'off';
            inp.className = 'form-control form-control-sm mb-1 select-search';
            inp.placeholder = sel.dataset.search || 'Type to search…';
            sel.parentNode.insertBefore(inp, sel);
            var picked = false;
            function pick(v) {
                if (picked) return; picked = true;
                sel.value = v; sel.size = 1;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                setTimeout(function () { picked = false; }, 400);
            }
            inp.addEventListener('input', function () {
                var q = inp.value.toLowerCase().trim(), n = 0;
                [].forEach.call(sel.options, function (o) {
                    var hit = q === '' || o.text.toLowerCase().indexOf(q) !== -1;
                    o.hidden = !hit; if (hit) n++;
                });
                sel.size = q === '' ? 1 : Math.max(2, Math.min(8, n));   // show the matches as an open list
            });
            inp.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                var first = [].find.call(sel.options, function (o) { return !o.hidden && !o.disabled; });
                if (first) pick(first.value);
            });
            // Clicking a match picks it, even if it was already the selected one.
            sel.addEventListener('click', function (e) {
                if (sel.size > 1 && e.target.tagName === 'OPTION') pick(e.target.value);
            });
        }
        function run() { document.querySelectorAll('select[data-search]').forEach(setup); }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
    })();

    // ---- Search inside a table: <input data-filter-rows="css selector of the tables"> ----
    // Hides the rows that don't contain the typed text (hidden rows don't print).
    document.addEventListener('input', function (e) {
        var inp = e.target;
        if (!inp.matches || !inp.matches('[data-filter-rows]')) return;
        var q = inp.value.toLowerCase().trim(), shown = 0, total = 0;
        document.querySelectorAll(inp.dataset.filterRows).forEach(function (t) {
            t.querySelectorAll('tbody tr').forEach(function (tr) {
                if (tr.querySelector('td[colspan]')) return;          // "nothing found" rows
                total++;
                var hit = q === '' || tr.textContent.toLowerCase().indexOf(q) !== -1;
                tr.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
        });
        var c = inp.parentNode.querySelector('[data-filter-count]');
        if (c) c.textContent = q === '' ? '' : shown + ' of ' + total + ' shown';
    });

    // ---- No blank information: empty table cells show a grey "–" ----
    // Any table cell with nothing in it, or that just says "N/A" / "-", shows a grey
    // "–" instead, including tables filled in later by a script. Cells that
    // hold buttons, inputs, pictures, the calendar, etc. are left alone.
    (function () {
        function fill(root) {
            if (!root.querySelectorAll) return;
            root.querySelectorAll('table td').forEach(function (td) {
                if (td.closest('#calendar, [data-no-na]') || td.dataset.na) return;
                if (td.querySelector('button, input, select, textarea, img, svg, canvas, a, form, .badge-pill')) return;
                var t = td.textContent.replace(/\s+/g, ' ').trim();
                if (t === '' || /^(-|—|–|n\/a)$/i.test(t)) {
                    td.dataset.na = '1';
                    td.innerHTML = '<span class="na" title="No information">–</span>';
                }
            });
        }
        // A table with NO rows shows one row of "–" under every column (instead of an
        // icon + message). The message is kept as the row's tooltip for screen readers.
        function emptyTables(root) {
            if (!root || !root.querySelectorAll) return;
            var tables = root.tagName === 'TABLE' ? [root] : root.querySelectorAll('table');
            Array.prototype.forEach.call(tables, function (tb) {
                if (tb.closest('#calendar, [data-no-na]')) return;
                var body = tb.tBodies[0];
                if (!body || body.rows.length !== 1) return;
                var tr = body.rows[0];
                if (tr.dataset.emptyRow || tr.cells.length !== 1 || tr.cells[0].colSpan < 2) return;
                var head = tb.tHead && tb.tHead.rows[0];
                var n = head ? Array.prototype.reduce.call(head.cells, function (a, c) { return a + (c.colSpan || 1); }, 0) : tr.cells[0].colSpan;
                var msg = tr.textContent.replace(/\s+/g, ' ').trim();
                tr.dataset.emptyRow = '1';
                tr.title = msg;
                tr.setAttribute('aria-label', msg || 'No data');
                tr.innerHTML = new Array(Math.max(n, 1) + 1).join('<td data-na="1"><span class="na">–</span></td>');
            });
        }
        function start() {
            fill(document); emptyTables(document);
            new MutationObserver(function (list) {
                list.forEach(function (m) { m.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) return;
                    fill(n.tagName === 'TD' ? n.parentNode : n);
                    emptyTables(n.closest ? (n.closest('table') || n) : n);
                }); });
            }).observe(document.body, { childList: true, subtree: true });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    })();

    // ---- Download PDF file (the "🖨 Print / PDF" drop-down, see print_menu() in config/auth.php) ----
    // Turns the page — or one part of it — into a real .pdf file and downloads it.
    // body.pdf-mode hides menus, buttons and filters the same way printing does.
    // Uses html2pdf.js (loaded only the first time it is needed).
    // Printing or saving a PDF from the Print / PDF menu goes into the Activity Log.
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.print-menu [data-log]');
        if (!b) return;
        var fd = new FormData(), t = document.querySelector('meta[name="csrf-token"]');
        fd.append('kind', b.dataset.log);
        fd.append('page', document.title.replace(/\s+—\s+St\. Therese Dental Clinic$/, ''));
        fd.append('file', b.dataset.file || '');
        try { fetch('log_event', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true,
                                   headers: t ? { 'X-CSRF-Token': t.content } : {} }); } catch (err) {}
    }, true);

    window.downloadPdf = function (target, filename, opts) {
        opts = opts || {};
        function loadLib() {
            if (window.html2pdf) return Promise.resolve();
            return new Promise(function (ok, fail) {
                var sc = document.createElement('script');
                sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
                sc.onload = ok; sc.onerror = function () { fail(new Error('load')); };
                document.head.appendChild(sc);
            });
        }
        var el = typeof target === 'string' ? document.querySelector(target) : target;
        if (!el) el = document.querySelector('main') || document.body;
        var busy = document.createElement('div'); busy.className = 'pdf-busy'; busy.textContent = '📄 Making your PDF…';
        document.body.appendChild(busy);
        if (opts.before) opts.before();
        return loadLib().then(function () {
            document.body.classList.add('pdf-mode');
            busy.style.display = 'none';
            // Lay the PDF out at the content's own width (wide tables are not cut off), then
            // keep A4's shape: the whole page is simply scaled to fit the paper.
            var w = Math.ceil(el.getBoundingClientRect().width);
            el.querySelectorAll('table').forEach(function (t) {          // a wide table must fit too
                if (t.offsetParent) w = Math.max(w, t.scrollWidth + 70);
            });
            w = Math.min(1400, Math.max(760, w));
            var m = 28, pageW = w + 2 * m;
            return window.html2pdf().set({
                margin: [m, m, m + 10, m],
                filename: (filename || 'document').replace(/[^\w\-]+/g, '-').replace(/-+/g, '-') + '.pdf',
                image: { type: 'jpeg', quality: 0.95 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff',
                               // a chart that is hidden has no size and would stop the PDF — skip it
                               ignoreElements: function (n) { return n.tagName === 'CANVAS' && (!n.width || !n.height); } },
                jsPDF: { unit: 'px', format: [pageW, Math.round(pageW * (opts.landscape ? 0.7071 : 1.4142))],
                         orientation: opts.landscape ? 'landscape' : 'portrait', hotfixes: ['px_scaling'] },
                pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.viz-card', '.kpi', '.dc-item', '.odo-arch', '.stat-card'] }
            }).from(el).save();
        }).catch(function () {
            alert('The PDF could not be made (no internet?). Use Print and choose "Save as PDF" instead.');
        }).then(function () {
            document.body.classList.remove('pdf-mode');
            busy.remove();
            if (opts.after) opts.after();
        });
    };

    // ---- Scroll only the list, not the whole page ----
    // Every data table sits in its own scroll box (one is added if the page has none),
    // and each box — plus panels marked data-fit-screen — is sized to end at the
    // bottom of the screen. So the search bar / filters above never scroll away.
    // data-fit-screen="90" leaves 90px below it (e.g. for a Save bar).
    (function () {
        function wrapTables(root) {
            (root.querySelectorAll ? root : document).querySelectorAll('table.data, table.table').forEach(function (t) {
                if (t.closest('.table-responsive, .tbl-scroll, [data-fit-screen], .match-scroll, #report-area, .modal, [data-no-scroll], .hf-view, #calendar')) return;
                var w = document.createElement('div'); w.className = 'tbl-scroll';
                t.parentNode.insertBefore(w, t); w.appendChild(t);
            });
        }
        function fit() {
            var vh = window.innerHeight, phone = window.innerWidth < 768;
            document.querySelectorAll('.table-responsive, .tbl-scroll, [data-fit-screen]').forEach(function (el) {
                if (el.closest('[data-fit-screen]') !== el && el.closest('[data-fit-screen]')) return;   // inside a fitted panel
                if (el.closest('.match-scroll, .modal')) return;
                if (!el.offsetParent) return;                                                         // hidden right now
                var extra = parseInt(el.getAttribute('data-fit-screen') || '0', 10) || 0;
                var top = el.getBoundingClientRect().top + window.scrollY;
                var h = vh - top - 24 - extra;
                if (phone || h < 300) h = Math.round(vh * (phone ? 0.7 : 0.75)) - extra;
                el.style.maxHeight = Math.max(240, h) + 'px';
            });
        }
        window.fitScreen = fit;
        function start() { wrapTables(document); fit(); setTimeout(fit, 400); }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
        window.addEventListener('load', fit);
        var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(fit, 120); });
    })();

    // ---- Delete several at once: tick boxes + "Delete selected" bar ----
    function bulkPicks(id) { return Array.prototype.slice.call(document.querySelectorAll('.bulk-pick[form="' + id + '"]')); }
    function bulkSync(id) {
        var f = document.getElementById(id); if (!f) return;
        var picks = bulkPicks(id), n = picks.filter(function (c) { return c.checked; }).length;
        f.style.display = picks.length ? '' : 'none';              // nothing to delete -> no bar
        f.querySelector('.bulk-count').textContent = n ? n + ' selected' : '';
        f.querySelector('.bulk-go').disabled = !n;
        var all = f.querySelector('.bulk-all');
        if (all) { all.checked = n > 0 && n === picks.length; all.indeterminate = n > 0 && n < picks.length; }
        f.classList.toggle('has-picks', n > 0);
    }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList && t.classList.contains('bulk-pick')) bulkSync(t.getAttribute('form'));
        if (t.classList && t.classList.contains('bulk-all')) {
            bulkPicks(t.dataset.bulk).forEach(function (c) { c.checked = t.checked; });
            bulkSync(t.dataset.bulk);
        }
    });
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form.bulk-bar').forEach(function (f) { bulkSync(f.id); });
    });
    window.bulkConfirm = function (f) {
        var n = bulkPicks(f.id).filter(function (c) { return c.checked; }).length;
        if (!n) return false;
        return confirm((f.dataset.verb || 'Delete') + ' ' + n + ' selected ' + (f.dataset.noun || 'items') + '?'
                       + (f.dataset.warning ? '\n\n' + f.dataset.warning : ''));
    };

    // ---- Email fields: a real address (name@domain.tld) ----
    // The browser alone accepts "jomar@123"; this refuses it before sending.
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || t.tagName !== 'INPUT' || t.type !== 'email') return;
        var v = t.value.trim();
        t.setCustomValidity(v === '' || /^[^\s@]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/.test(v)
            ? '' : 'Please enter a real email address, for example name@gmail.com.');
    });

    // ---- "Confirm password" fields: <input data-pw-match="idOfTheFirstField"> ----
    function pwMatchCheck(c) {
        var p = document.getElementById(c.getAttribute('data-pw-match'));
        var bad = p && (p.value !== '' || c.value !== '') && p.value !== c.value;
        c.setCustomValidity(bad ? 'The passwords do not match.' : '');
        var w = document.getElementById(c.id + '-warn');
        if (w) w.style.display = (bad && c.value !== '') ? 'block' : 'none';
    }
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.getAttribute) return;
        if (t.hasAttribute('data-pw-match')) pwMatchCheck(t);
        if (t.id) document.querySelectorAll('[data-pw-match="' + t.id + '"]').forEach(pwMatchCheck);
    });

    // ---- Password strength (same scoring as password_strength() in config/auth.php) ----
    // Any <input data-pw-meter> gets a live "Weak / Medium / Strong" line; only Strong is accepted.
    function pwLevel(p) {
        var s = 0; if (p.length >= 8) s++; if (p.length >= 12) s++;
        if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^A-Za-z0-9]/.test(p)) s++;
        return (p.length < 8 || s <= 2) ? 'weak' : (s === 3 ? 'medium' : 'strong');
    }
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.hasAttribute || !t.hasAttribute('data-pw-meter')) return;
        var box = t.nextElementSibling && t.nextElementSibling.classList && t.nextElementSibling.classList.contains('pw-meter')
                ? t.nextElementSibling : null;
        if (!box) { box = document.createElement('div'); box.className = 'pw-meter'; box.style.cssText = 'font-size:.78rem;margin:3px 0 8px;'; t.insertAdjacentElement('afterend', box); }
        if (t.value === '') { box.textContent = ''; t.setCustomValidity(''); return; }
        var lv = pwLevel(t.value);
        box.style.color = lv === 'strong' ? '#138a4e' : (lv === 'medium' ? '#b07d12' : '#c0392b');
        box.textContent = lv === 'strong' ? '✓ Strong — good to go'
            : (lv === 'medium' ? 'Medium — not strong enough yet' : 'Weak — not allowed')
              + ': use 8+ characters with upper- and lower-case letters, a number and a symbol.';
        t.setCustomValidity(lv === 'strong' ? '' : 'Please choose a Strong password.');
    });

    // Browser-side mirror of validate_phone() in config/auth.php, so people see
    // the problem straight away. The server check is still the one that counts.
    function phoneProblem(v, required) {
        var d = (v || '').replace(/[\s\-().]/g, '');
        if (d === '') return required ? 'Please enter a contact number.' : '';
        if (!/^\+?\d+$/.test(d)) return 'The contact number can only contain digits (spaces, dashes and +63 are fine).';
        d = d.replace(/^\+/, '');
        if (d.indexOf('63') === 0 && d.length >= 11) d = '0' + d.slice(2);
        if (!/^09\d{9}$/.test(d)) return 'Please enter a real Philippine mobile number: 11 digits starting with 09, e.g. 0917 123 4567.';
        var runs = '0123456789012345678', rev = runs.split('').reverse().join(''), tail = d.slice(2);
        if (/^(\d)\1+$/.test(tail) || /^(\d)\1{6}$/.test(d.slice(-7)) || runs.indexOf(tail) >= 0 || rev.indexOf(tail) >= 0)
            return 'That does not look like a real contact number. Please enter your actual number.';
        return '';
    }
    </script>

    <!-- ============================================================
         CONFIRMATION MODAL (used everywhere: deletes, archive, logout...)
         ============================================================
         askConfirm({title, message, okText, danger}) -> Promise<boolean>

         Existing code calls confirm('...') inside onsubmit="return ..."
         (and confirmDelete()). The browser's plain pop-up is replaced by
         this modal: the first submit is held back while the modal is
         open; "Yes" re-submits the same form (same button) and the same
         confirm() call then answers true. Links carrying data-confirm
         (e.g. Sign Out) ask first, then follow the link.
         ============================================================ -->
    <style>
    #cm-backdrop { position: fixed; inset: 0; background: rgba(15,35,40,.45); z-index: 20000;
                   display: none; align-items: center; justify-content: center; padding: 16px; }
    #cm-backdrop.open { display: flex; animation: cmFade .15s ease-out; }
    #cm-box { background: #fff; border-radius: 16px; width: 100%; max-width: 420px; box-shadow: 0 18px 50px rgba(0,0,0,.28);
              padding: 26px 24px 20px; text-align: center; font-family: inherit; animation: cmPop .18s ease-out; }
    #cm-icon { width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 12px; display: flex; align-items: center;
               justify-content: center; font-size: 1.6rem; background: #e6f4f1; color: #0f766e; }
    #cm-box.danger #cm-icon { background: #fdecec; color: #c0392b; }
    #cm-title { font-size: 1.15rem; font-weight: 700; margin: 0 0 6px; color: #1d2b33; }
    #cm-msg { font-size: .93rem; color: #5b6770; white-space: pre-line; margin: 0 0 20px; line-height: 1.5; }
    #cm-actions { display: flex; gap: 10px; }
    #cm-actions button { flex: 1; border: none; border-radius: 10px; padding: 11px 12px; font-weight: 600; font-size: .95rem; cursor: pointer; }
    #cm-cancel { background: #eef2f5; color: #34434c; }
    #cm-cancel:hover { background: #e2e8ed; }
    #cm-ok { background: #0f766e; color: #fff; }
    #cm-ok:hover { filter: brightness(1.08); }
    #cm-box.danger #cm-ok { background: #c0392b; }
    @keyframes cmFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes cmPop  { from { transform: translateY(8px) scale(.97); opacity: 0; } to { transform: none; opacity: 1; } }
    @media print { #cm-backdrop { display: none !important; } }
    </style>
    <script>
    (function () {
        var DANGER = /delete|remove|archive|discard|reset|restore every|cannot be undone|permanently|did not attend/i;
        var resolver = null, lastFocus = null;

        function build() {
            if (document.getElementById('cm-backdrop')) return;
            var wrap = document.createElement('div');
            wrap.id = 'cm-backdrop';
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            wrap.setAttribute('aria-labelledby', 'cm-title');
            wrap.innerHTML =
                '<div id="cm-box"><div id="cm-icon"></div><h3 id="cm-title"></h3><p id="cm-msg"></p>' +
                '<div id="cm-actions"><button type="button" id="cm-cancel">Cancel</button>' +
                '<button type="button" id="cm-ok">Confirm</button></div></div>';
            document.body.appendChild(wrap);
            document.getElementById('cm-cancel').onclick = function () { close(false); };
            document.getElementById('cm-ok').onclick = function () { close(true); };
            wrap.addEventListener('mousedown', function (e) { if (e.target === wrap) close(false); });
            document.addEventListener('keydown', function (e) {
                if (!wrap.classList.contains('open')) return;
                if (e.key === 'Escape') { e.preventDefault(); close(false); }
                if (e.key === 'Tab') {                         // keep focus inside the modal
                    var c = document.getElementById('cm-cancel'), o = document.getElementById('cm-ok');
                    if (e.shiftKey && document.activeElement === c) { e.preventDefault(); o.focus(); }
                    else if (!e.shiftKey && document.activeElement === o) { e.preventDefault(); c.focus(); }
                }
            });
        }

        function close(answer) {
            document.getElementById('cm-backdrop').classList.remove('open');
            if (lastFocus && lastFocus.focus) lastFocus.focus();
            var r = resolver; resolver = null;
            if (r) r(answer);
        }

        // Tidy the old pop-up wording for the modal ("⚠️ WARNING: ..." etc.).
        function clean(msg) {
            return String(msg || 'Are you sure?').replace(/^\s*⚠️\s*(WARNING:\s*)?/i, '').trim();
        }

        window.askConfirm = function (opts) {
            if (typeof opts === 'string') opts = { message: opts };
            opts = opts || {};
            build();
            var msg = clean(opts.message);
            var danger = opts.danger !== undefined ? opts.danger : DANGER.test(msg);
            var box = document.getElementById('cm-box');
            box.classList.toggle('danger', !!danger);
            document.getElementById('cm-icon').textContent = opts.icon || (danger ? '🗑' : '❔');
            document.getElementById('cm-title').textContent = opts.title || (danger ? 'Please confirm' : 'Are you sure?');
            document.getElementById('cm-msg').textContent = msg;
            document.getElementById('cm-ok').textContent = opts.okText ||
                (danger ? (/delete/i.test(msg) ? 'Delete' : 'Yes, continue') : 'Yes, continue');
            document.getElementById('cm-cancel').textContent = opts.cancelText || 'Cancel';
            lastFocus = document.activeElement;
            // If a Bootstrap modal is open, sit inside it — Bootstrap keeps focus
            // trapped in its modal and would otherwise steal it from our buttons.
            var wrap = document.getElementById('cm-backdrop');
            var host = document.querySelector('.modal.show') || document.body;
            if (wrap.parentNode !== host) host.appendChild(wrap);
            wrap.classList.add('open');
            document.getElementById(danger ? 'cm-cancel' : 'cm-ok').focus();   // safe default for deletes
            return new Promise(function (res) { resolver = res; });
        };

        // ---- confirm() inside a form's onsubmit -> modal, then re-submit ----
        var pendingForm = null, pendingSubmitter = null;
        document.addEventListener('submit', function (e) {
            if (e.target.dataset.cmConfirmed === '1') return;       // second pass after "Yes"
            var f = e.target;
            pendingForm = f; pendingSubmitter = e.submitter || null;
            // Forget it once this submit is over, so an unrelated confirm() later
            // is never mistaken for part of this form.
            setTimeout(function () { if (pendingForm === f && f.dataset.cmConfirmed !== '1') pendingForm = null; }, 0);
        }, true);

        var nativeConfirm = window.confirm.bind(window);
        window.confirm = function (message) {
            var form = pendingForm;
            if (form && form.dataset.cmConfirmed === '1') {          // user already said yes
                delete form.dataset.cmConfirmed;
                return true;
            }
            if (!form) return nativeConfirm(message);                // not from a form submit
            pendingForm = null;
            var submitter = pendingSubmitter;
            askConfirm({ message: message }).then(function (ok) {
                if (!ok) return;
                form.dataset.cmConfirmed = '1';
                pendingForm = form;
                if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                else form.submit();
            });
            return false;                                            // hold the submit for now
        };
        // Older helper some pages use: same modal.
        window.confirmDelete = function (message) { return window.confirm(message || 'Are you sure you want to delete this?'); };

        // ---- Links that need confirming (e.g. Sign Out): <a data-confirm="..."> ----
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[data-confirm]');
            if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
            e.preventDefault();
            askConfirm({
                title: a.dataset.confirmTitle || '',
                message: a.dataset.confirm,
                okText: a.dataset.confirmOk || '',
                icon: a.dataset.confirmIcon || '',
                danger: a.dataset.confirmDanger === '1'
            }).then(function (ok) { if (ok) window.location.href = a.href; });
        });
    })();
    </script>
</head>
<body>
<?php include __DIR__ . '/inapp_banner.php';   // "open in your browser" notice inside Messenger/Facebook/etc. ?>
<?php
// ---- Toast pop-up (shows a one-time flash message, or a ?toast= URL message) ----
$__flash = function_exists('take_flash') ? take_flash() : null;
// Only known codes are accepted, so nobody can craft a link that shows their
// own text (e.g. a fake "call this number") on the clinic's site.
$__urlToasts = [
    'loggedout' => ['msg' => 'You have been logged out.', 'type' => 'info'],
    'pwreset'   => ['msg' => 'Password reset! You can now sign in with your new password.', 'type' => 'success'],
];
if (!$__flash && isset($_GET['toast'], $__urlToasts[$_GET['toast']]) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $__flash = $__urlToasts[$_GET['toast']];
}
if ($__flash):
    $__toastColors = ['success'=>'#138a4e','error'=>'#c0392b','danger'=>'#c0392b','info'=>'#0f766e','warning'=>'#c79a5c'];
    $__bg = $__toastColors[$__flash['type']] ?? '#138a4e';
?>
<!-- Centred with left/right + margin:auto (NOT left:50%), so on a phone it can use
     the full width instead of being squeezed into a tall, narrow box. Tap to close. -->
<div id="app-toast" role="status" title="Tap to close" onclick="this.style.opacity='0';var t=this;setTimeout(function(){t.remove()},300)"
     style="background:<?= $__bg ?>;">
    <?= e($__flash['msg']) ?>
</div>
<style>
#app-toast { position: fixed; top: 22px; left: 0; right: 0; margin: 0 auto; width: fit-content;
             max-width: min(560px, calc(100% - 24px)); z-index: 99999; color: #fff; padding: 14px 26px;
             border-radius: 14px; box-shadow: 0 12px 34px rgba(0,0,0,.28); font-size: 1rem; font-weight: 600;
             line-height: 1.4; text-align: center; cursor: pointer; opacity: 0; transform: translateY(-18px);
             transition: opacity .3s, transform .3s; box-sizing: border-box; overflow-wrap: anywhere; }
@media (max-width: 640px) {
    #app-toast { top: 10px; width: auto; left: 12px; right: 12px; max-width: none; padding: 12px 16px;
                 font-size: .92rem; border-radius: 12px; }
}
</style>
<script>
(function(){
    var t = document.getElementById('app-toast');
    if (!t) return;
    // Longer messages stay a little longer (about 4–9 seconds).
    var ms = Math.min(9000, Math.max(4000, t.textContent.trim().length * 55));
    setTimeout(function(){ t.style.opacity='1'; t.style.transform='translateY(0)'; }, 80);
    setTimeout(function(){ t.style.opacity='0'; t.style.transform='translateY(-18px)'; }, ms);
    setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, ms + 400);
})();
</script>
<?php endif; ?>

<?php if (($_GET['toast'] ?? '') === 'updated' && empty($_SESSION['user_id'])): ?>
<!-- ---- Signed out because the clinic updated the system (see config/auth.php) ---- -->
<div id="upd-backdrop" role="dialog" aria-modal="true" aria-labelledby="upd-title"
     style="position:fixed;inset:0;z-index:100000;background:rgba(15,30,30,.55);display:flex;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:16px;max-width:430px;width:100%;padding:26px 22px 20px;text-align:center;box-shadow:0 18px 50px rgba(0,0,0,.3);">
    <div style="font-size:2.4rem;line-height:1;">🙏</div>
    <h4 id="upd-title" style="margin:10px 0 8px;color:var(--teal-mid,#0f766e);font-weight:700;">Sorry for the interruption!</h4>
    <p style="color:#3f5350;font-size:.98rem;margin:0 0 6px;">The clinic just updated the system, so we signed you out to show you the newest version.</p>
    <p style="color:#6b7b8c;font-size:.88rem;margin:0 0 16px;">Please sign in again. If you were in the middle of something, it may need to be done again — we apologize for the trouble.</p>
    <button type="button" class="btn btn-teal w-100" data-keep-text onclick="document.getElementById('upd-backdrop').remove()">OK, sign in again</button>
  </div>
</div>
<?php endif; ?>

<?php
// ---- Patient warning pop-up (cancellation / missed-visit count, booking blocked) ----
// Each notice shows once. See includes/patient_notices.php.
$__notices = [];
if (isset($pdo) && function_exists('current_role') && current_role() === 'patient' && !empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/patient_notices.php';
    $__notices = take_patient_notices($pdo, $_SESSION['user_id']);
}
if ($__notices):
    $__lvl = ['info' => '#c0392b', 'warning' => '#c0392b', 'danger' => '#c0392b', 'good' => '#0f766e'];   // warnings are always red; good news is green
?>
<div id="pn-backdrop" role="dialog" aria-modal="true" aria-labelledby="pn-title-0">
  <div id="pn-box">
    <?php foreach ($__notices as $__i => $__n): $__c = $__lvl[$__n['level']] ?? $__lvl['warning']; ?>
      <div class="pn-item" style="border-left-color:<?= $__c ?>;">
        <div class="pn-title" id="pn-title-<?= $__i ?>" style="color:<?= $__c ?>;"><?= e($__n['title']) ?></div>
        <div class="pn-body"><?= nl2br(e($__n['body'])) ?></div>
      </div>
    <?php endforeach; ?>
    <?php $__allGood = !array_filter($__notices, fn($n) => $n['level'] !== 'good'); ?>
    <button type="button" id="pn-ok" class="btn w-100" style="background:<?= $__allGood ? '#0f766e' : '#c0392b' ?>;color:#fff;font-weight:600;"
            onclick="document.getElementById('pn-backdrop').remove();document.body.style.overflow='';">I understand</button>
  </div>
</div>
<style>
#pn-backdrop { position: fixed; inset: 0; z-index: 100000; background: rgba(15,30,30,.55);
               display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; }
#pn-box { background: #fff; border-radius: 16px; width: 100%; max-width: 480px; max-height: calc(100vh - 32px);
          overflow-y: auto; padding: 22px 22px 18px; box-shadow: 0 18px 50px rgba(0,0,0,.3); box-sizing: border-box; }
.pn-item { border-left: 5px solid; padding: 4px 0 4px 14px; margin-bottom: 16px; }
.pn-title { font-weight: 700; font-size: 1.08rem; margin-bottom: 6px; }
.pn-body { color: #3f5350; font-size: .93rem; line-height: 1.5; overflow-wrap: anywhere; }
@media (max-width: 480px) {
    #pn-backdrop { padding: 10px; align-items: flex-end; }
    #pn-box { padding: 18px 16px 14px; border-radius: 14px; max-height: calc(100vh - 20px); }
    .pn-title { font-size: 1rem; }
    .pn-body { font-size: .9rem; }
}
</style>
<script>document.body.style.overflow = 'hidden';</script>
<?php endif; ?>

<?php
// ---- Catch up on no-show detection ----
// Runs the first time the system is opened each day. A nightly scheduled
// task would never fire here, because the clinic's computer is switched
// off at night — so we scan on page load instead and catch up on any days
// that were missed. It only actually does work once per day.
// Day-before reminder emails: sent by the first page load of each day
// (there is no scheduler). Runs for any visitor, signed in or not.
if (isset($pdo)) {
    require_once __DIR__ . '/reminders.php';
    run_daily_reminders($pdo);
}

// Bookings nobody confirmed before their date -> Expired (any signed-in user,
// so a patient is never held up by an old "Pending" booking).
if (isset($pdo) && function_exists('is_logged_in') && is_logged_in()) {
    require_once __DIR__ . '/noshow_check.php';
    expire_stale_pending($pdo);
}
if (function_exists('is_logged_in') && is_logged_in()
    && in_array(current_role(), ['admin','dentist','staff'])) {
    require_once __DIR__ . '/noshow_check.php';
    run_noshow_scan($pdo);
}
?>

<?php
// Floating profile widget (name + avatar + dropdown) in the top-right corner.
// It only renders for logged-in users and floats above the page.
include __DIR__ . '/topbar.php';

// Hamburger button and backdrop — visible on mobile for logged-in users on
// pages that actually HAVE a sidebar to open (patients have one in their
// portal too). Pages without a sidebar — like the booking wizard — set
// $hide_hamburger = true before including this file, since the button
// would otherwise sit there doing nothing.
if (function_exists('is_logged_in') && is_logged_in() && empty($hide_hamburger)):
?>
<button class="hamburger no-print" id="sidebarToggle" aria-label="Open menu">☰</button>
<div class="sidebar-backdrop no-print" id="sidebarBackdrop"></div>
<script>
// Wait until the full page (including the sidebar HTML) has been rendered
// before attaching click handlers. Without this the sidebar element does
// not exist yet when the script runs.
document.addEventListener('DOMContentLoaded', function(){
    var btn      = document.getElementById('sidebarToggle');
    var backdrop = document.getElementById('sidebarBackdrop');
    var sidebar  = document.querySelector('.sidebar');
    if (!btn || !sidebar) return;

    function openSidebar(){
        sidebar.classList.add('open');
        backdrop.classList.add('open');
        btn.innerHTML = '&times;';
        btn.setAttribute('aria-label','Close menu');
    }
    function closeSidebar(){
        sidebar.classList.remove('open');
        backdrop.classList.remove('open');
        btn.innerHTML = '&#9776;';
        btn.setAttribute('aria-label','Open menu');
    }
    btn.addEventListener('click', function(){
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
    backdrop.addEventListener('click', closeSidebar);

    // Close sidebar when any nav link is tapped
    sidebar.querySelectorAll('a.nav-item').forEach(function(a){
        a.addEventListener('click', closeSidebar);
    });

    // Tapping the profile or the bell (top right) while the menu is open closes
    // the menu first, so the dropdown is not hidden behind it on a phone.
    ['pwBtn', 'notifBtn'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b) b.addEventListener('click', function () { if (sidebar.classList.contains('open')) closeSidebar(); });
    });
    window.closeSidebar = closeSidebar;
});
</script>
<?php endif; ?>

<script>
// Password show/hide toggle. Any <button class="pw-eye"> toggles the
// <input> before it inside the same .pw-wrap. When revealed, it hides
// itself again automatically after 5 seconds for privacy.
document.addEventListener('click', function(e){
    var btn = e.target.closest('.pw-eye');
    if (!btn) return;
    e.preventDefault();
    var input = btn.parentNode.querySelector('input');
    if (!input) return;

    // Helper: put the field back to hidden and cancel any pending timer.
    function hide(){
        input.type = 'password';
        btn.classList.remove('on');
        if (btn._pwTimer) { clearTimeout(btn._pwTimer); btn._pwTimer = null; }
    }

    if (input.type === 'password') {
        // Reveal it, then auto-hide after 5 seconds.
        input.type = 'text';
        btn.classList.add('on');
        if (btn._pwTimer) clearTimeout(btn._pwTimer);
        btn._pwTimer = setTimeout(hide, 5000);
    } else {
        // User clicked again to hide it early.
        hide();
    }
});
</script>
