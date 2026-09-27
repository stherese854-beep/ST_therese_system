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
