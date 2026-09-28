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
    <!-- Our custom theme (versioned by file time so browsers always fetch
         the latest copy after a deploy, instead of serving a stale cache) -->
    <link href="css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?: time() ?>" rel="stylesheet">
    <script>
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
<div id="app-toast" style="position:fixed;top:26px;left:50%;transform:translate(-50%,-18px);z-index:99999;background:<?= $__bg ?>;color:#fff;padding:18px 34px;border-radius:14px;box-shadow:0 12px 34px rgba(0,0,0,.28);font-size:1.05rem;font-weight:600;text-align:center;max-width:90%;opacity:0;transition:opacity .3s,transform .3s;">
    <?= e($__flash['msg']) ?>
</div>
<script>
(function(){
    var t = document.getElementById('app-toast');
    if (!t) return;
    setTimeout(function(){ t.style.opacity='1'; t.style.transform='translate(-50%,0)'; }, 80);
    setTimeout(function(){ t.style.opacity='0'; t.style.transform='translate(-50%,-18px)'; }, 3800);
    setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 4200);
})();
</script>
<?php endif; ?>

<?php
// ---- Catch up on no-show detection ----
// Runs the first time the system is opened each day. A nightly scheduled
// task would never fire here, because the clinic's computer is switched
// off at night — so we scan on page load instead and catch up on any days
// that were missed. It only actually does work once per day.
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
