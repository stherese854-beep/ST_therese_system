<?php
// ============================================================
//  "WHAT ABOUT THE FAMILY?"  (includes/family_choice.php)
// ============================================================
//  Moving an account / patient to the Archive normally just asks
//  "Move to Archive?". When that person books for family members,
//  this pop-up asks instead, listing them by name:
//    - Keep them as the clinic's own patients   (default, nothing lost)
//    - Archive them too                          (restored together)
//  The choice goes to the server as family=keep|archive.
//
//  Use: a delete form gets  data-family='["Juan (son)", ...]'  and a
//  hidden <input name="family" value="keep">. Include this file once
//  near the end of the page.
// ============================================================
?>
<div id="fam-ask" class="fam-ask" hidden role="dialog" aria-modal="true" aria-labelledby="fam-title">
    <div class="fam-box">
        <h5 id="fam-title" class="mb-1">Move <span id="fam-who"></span> to the Archive?</h5>
        <p class="text-muted2 mb-2" style="font-size:.88rem;">They also book for these family members:</p>
        <ul id="fam-list" class="mb-3"></ul>
        <label class="fam-opt"><input type="radio" name="fam-pick" value="keep" checked>
            <span><b>Keep them as the clinic's own patients</b><br>
            <small class="text-muted2">They stay active with all their records and visits. Staff can book for them.</small></span></label>
        <label class="fam-opt"><input type="radio" name="fam-pick" value="archive">
            <span><b>Archive them too</b><br>
            <small class="text-muted2">They come back together when this account is restored.</small></span></label>
        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-light" data-keep-text id="fam-cancel">Cancel</button>
            <button type="button" class="btn btn-danger" data-keep-text id="fam-go">Move to Archive</button>
        </div>
    </div>
</div>
<style>
.fam-ask { position: fixed; inset: 0; z-index: 100000; background: rgba(15,30,30,.5); display: flex; align-items: center; justify-content: center; padding: 16px; }
.fam-ask[hidden] { display: none; }
.fam-box { background: #fff; border-radius: 14px; padding: 20px 22px; width: 100%; max-width: 460px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
.fam-box ul { margin: 0; padding-left: 20px; font-weight: 600; }
.fam-opt { display: flex; gap: 10px; align-items: flex-start; border: 1px solid #e3e9ec; border-radius: 10px; padding: 10px 12px; margin-bottom: 8px; cursor: pointer; }
.fam-opt input { margin-top: 4px; }
</style>
<script>
(function () {
    var box = document.getElementById('fam-ask'), pending = null;
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.dataset || !f.dataset.family || f.dataset.famOk) return;
        var names = [];
        try { names = JSON.parse(f.dataset.family); } catch (err) {}
        if (!names.length) return;
        e.preventDefault(); e.stopImmediatePropagation();
        pending = f;
        document.getElementById('fam-who').textContent = f.dataset.who || 'this account';
        var ul = document.getElementById('fam-list'); ul.innerHTML = '';
        names.forEach(function (n) { var li = document.createElement('li'); li.textContent = n; ul.appendChild(li); });
        box.querySelector('input[value="keep"]').checked = true;
        box.hidden = false;
    }, true);
    document.getElementById('fam-cancel').onclick = function () { box.hidden = true; pending = null; };
    document.getElementById('fam-go').onclick = function () {
        if (!pending) return;
        pending.querySelector('input[name="family"]').value = box.querySelector('input[name="fam-pick"]:checked').value;
        pending.dataset.famOk = '1';
        box.hidden = true;
        pending.submit();
    };
})();
</script>
