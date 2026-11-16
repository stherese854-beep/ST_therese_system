<?php
// ============================================================
//  BOOKING POP-UP  (includes/book_modal.php)
//  Any link to the booking page ("book" / "book?...") opens the booking wizard in a
//  pop-up on top of the current page instead of going to another page.
//  Included only for a logged-in patient (visitors still go to the login page).
//  The wizard itself is book.php?embed=1 (same page, minus the header and exit arrow).
// ============================================================
?>
<style>
#bkpop { position:fixed; inset:0; z-index:20000; background:rgba(15,40,40,.55); align-items:center; justify-content:center; padding:12px; }
#bkpop .bkpop-box { display:flex; flex-direction:column; width:100%; max-width:640px; height:min(92vh,860px); background:#f6f1e9; border-radius:16px; box-shadow:0 18px 60px rgba(0,0,0,.35); overflow:hidden; }
#bkpop .bkpop-bar { flex:0 0 auto; display:flex; align-items:center; justify-content:space-between; padding:8px 10px 8px 16px; background:#fff; border-bottom:1px solid #e3e9ee; font-weight:700; color:#14504a; }
#bkpop #bkpop-close { width:32px; height:32px; border:0; border-radius:50%; background:#eef2f5; font-size:1.2rem; line-height:1; cursor:pointer; }
#bkpop iframe { flex:1 1 auto; width:100%; border:0; background:#f6f1e9; }
/* phones: the pop-up fills the whole screen */
@media (max-width: 640px) {
  #bkpop { padding:0; }
  #bkpop .bkpop-box { max-width:none; height:100vh; height:100dvh; border-radius:0; }
}
</style>
<div id="bkpop" hidden>
    <div class="bkpop-box">
        <div class="bkpop-bar"><span>Book Appointment</span>
            <button type="button" id="bkpop-close" aria-label="Close" title="Close">&times;</button></div>
        <iframe id="bkpop-frame" title="Book Appointment"></iframe>
    </div>
</div>
<script>
(function () {
    var pop = document.getElementById('bkpop'), frame = document.getElementById('bkpop-frame');
    function open(href) {
        var url = href + (href.indexOf('?') === -1 ? '?' : '&') + 'embed=1';
        frame.src = url;
        pop.hidden = false; pop.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function close() {
        pop.hidden = true; pop.style.display = 'none';
        frame.src = 'about:blank';
        document.body.style.overflow = '';
    }
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button || e.ctrlKey || e.metaKey || e.shiftKey) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a) return;
        var h = a.getAttribute('href');
        if (h === 'book' || h.indexOf('book?') === 0) { e.preventDefault(); open(h); }
    });
    document.getElementById('bkpop-close').addEventListener('click', close);
    pop.addEventListener('mousedown', function (e) { if (e.target === pop) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) close(); });
})();
</script>
