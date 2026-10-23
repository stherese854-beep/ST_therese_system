<?php
// ============================================================
//  PROFILE PICTURE: PREVIEW, MOVE AND ZOOM  (includes/avatar_cropper.php)
// ============================================================
//  Any <input type="file" data-avatar-crop> (My Information / My Profile):
//  choosing a picture opens "Adjust your picture" — drag to move it, zoom
//  with the slider, the mouse wheel or two fingers. "Use this picture"
//  shows it in the profile circle straight away ("Not saved yet") and puts
//  the adjusted square picture (a JPG, 512 px) in the form; Upload saves it.
//  The server still checks it like any upload (includes/security.php).
// ============================================================
if (!empty($GLOBALS['__avatarCropperDone'])) return;
$GLOBALS['__avatarCropperDone'] = true;
?>
<div class="ac-overlay" id="acOverlay" hidden>
    <div class="ac-box" role="dialog" aria-modal="true" aria-labelledby="acTitle">
        <div class="ac-title" id="acTitle">Adjust your picture</div>
        <div class="ac-hint">Drag to move it · zoom with the slider<span class="ac-hint-more"> (or scroll / pinch)</span></div>
        <div class="ac-stage" id="acStage"><img id="acImg" alt="" draggable="false"></div>
        <div class="ac-zoom">
            <span aria-hidden="true">−</span>
            <input type="range" id="acZoom" min="1" max="4" step="0.01" value="1" aria-label="Zoom">
            <span aria-hidden="true">+</span>
        </div>
        <div class="ac-actions">
            <button type="button" class="btn btn-light" id="acCancel" data-keep-text>Cancel</button>
            <button type="button" class="btn btn-teal" id="acUse" data-keep-text>Use this picture</button>
        </div>
    </div>
</div>
<style>
.ac-overlay { position: fixed; inset: 0; z-index: 20050; background: rgba(15,35,40,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.ac-overlay[hidden] { display: none; }
.ac-box { background: #fff; border-radius: 16px; width: min(380px, 100%); padding: 18px 18px 16px; box-shadow: 0 18px 50px rgba(0,0,0,.3); text-align: center; animation: acPop .18s ease-out; }
@keyframes acPop { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: none; } }
.ac-title { font-weight: 700; font-size: 1.05rem; color: #0d3b3b; }
.ac-hint { font-size: .8rem; color: #6b7a86; margin: 2px 0 12px; }
.ac-stage { position: relative; width: min(280px, 100%); aspect-ratio: 1 / 1; margin: 0 auto; border-radius: 50%; overflow: hidden;
            background: #e8eef0; touch-action: none; cursor: grab; box-shadow: 0 0 0 3px var(--teal-light, #14b8a6); }
.ac-stage.dragging { cursor: grabbing; }
.ac-stage img { position: absolute; left: 0; top: 0; transform-origin: 0 0; user-select: none; -webkit-user-drag: none; max-width: none; }
.ac-zoom { display: flex; align-items: center; gap: 10px; margin: 14px 6px 4px; color: #6b7a86; font-weight: 700; }
.ac-zoom input { flex: 1; accent-color: var(--teal-mid, #0f766e); }
.ac-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
.ac-note { font-size: .78rem; color: #b07d12; margin-top: 4px; }
.ac-note a { color: var(--teal-mid, #0f766e); }
@media (hover: none) { .ac-hint-more { display: none; } }
</style>
<script>
(function () {
    var ov = document.getElementById('acOverlay'), stage = document.getElementById('acStage'), img = document.getElementById('acImg'),
        zoom = document.getElementById('acZoom'), V = 280, OUT = 512;
    var input = null, srcUrl = '', nat = { w: 0, h: 0 }, base = 1, s = 1, x = 0, y = 0;

    function size() { V = stage.clientWidth || 280; }
    function clamp() {                                    // the picture always covers the circle
        x = Math.min(0, Math.max(V - nat.w * s, x));
        y = Math.min(0, Math.max(V - nat.h * s, y));
    }
    function draw() { clamp(); img.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + s + ')'; }
    function zoomTo(z, cx, cy) {                          // zoom, keeping the point under (cx, cy) still
        z = Math.min(4, Math.max(1, z));
        var ns = base * z; cx = cx == null ? V / 2 : cx; cy = cy == null ? V / 2 : cy;
        x = cx - (cx - x) * ns / s; y = cy - (cy - y) * ns / s; s = ns;
        zoom.value = z; draw();
    }
    function open(file, inp) {
        input = inp;
        if (srcUrl) URL.revokeObjectURL(srcUrl);
        srcUrl = URL.createObjectURL(file);
        img.onload = function () {
            ov.hidden = false; size();
            nat = { w: img.naturalWidth, h: img.naturalHeight };
            base = Math.max(V / nat.w, V / nat.h); s = base;
            x = (V - nat.w * s) / 2; y = (V - nat.h * s) / 2;
            zoom.value = 1; draw();
            document.getElementById('acUse').focus();
        };
        img.onerror = function () {
            alert('That picture cannot be opened here. Please choose a JPG or PNG photo.');
            inp.value = '';
        };
        img.src = srcUrl;
    }
    function close() { ov.hidden = true; }

    // Drag to move; two fingers to zoom.
    var pts = {}, last = null, pinch = null;
    stage.addEventListener('pointerdown', function (e) {
        stage.setPointerCapture(e.pointerId); pts[e.pointerId] = { x: e.clientX, y: e.clientY };
        stage.classList.add('dragging'); last = { x: e.clientX, y: e.clientY }; pinch = null;
    });
    stage.addEventListener('pointermove', function (e) {
        if (!pts[e.pointerId]) return;
        pts[e.pointerId] = { x: e.clientX, y: e.clientY };
        var ids = Object.keys(pts);
        if (ids.length >= 2) {                            // pinch
            var a = pts[ids[0]], b = pts[ids[1]], d = Math.hypot(a.x - b.x, a.y - b.y), r = stage.getBoundingClientRect();
            if (pinch) zoomTo(pinch.z * d / pinch.d, (a.x + b.x) / 2 - r.left, (a.y + b.y) / 2 - r.top);
            else pinch = { d: d, z: s / base };
            return;
        }
        if (last) { x += e.clientX - last.x; y += e.clientY - last.y; last = { x: e.clientX, y: e.clientY }; draw(); }
    });
    function up(e) { delete pts[e.pointerId]; if (!Object.keys(pts).length) { stage.classList.remove('dragging'); last = null; pinch = null; } }
    stage.addEventListener('pointerup', up); stage.addEventListener('pointercancel', up);
    stage.addEventListener('wheel', function (e) {
        e.preventDefault(); var r = stage.getBoundingClientRect();
        zoomTo(s / base * (e.deltaY < 0 ? 1.08 : 1 / 1.08), e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });
    zoom.addEventListener('input', function () { zoomTo(+zoom.value); });

    document.getElementById('acCancel').addEventListener('click', function () { if (input) input.value = ''; close(); });
    ov.addEventListener('click', function (e) { if (e.target === ov) { if (input) input.value = ''; close(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !ov.hidden) { if (input) input.value = ''; close(); } });

    // "Use this picture": the visible circle becomes a 512 px square JPG in the form,
    // and the profile circle shows it at once (not saved until Upload).
    document.getElementById('acUse').addEventListener('click', function () {
        var c = document.createElement('canvas'); c.width = c.height = OUT;
        var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, OUT, OUT);
        g.drawImage(img, -x / s, -y / s, V / s, V / s, 0, 0, OUT, OUT);
        c.toBlob(function (blob) {
            if (!blob || !input) { close(); return; }
            var file = new File([blob], 'profile-picture.jpg', { type: 'image/jpeg' });
            try { var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; } catch (err) {}
            input.setCustomValidity('');                  // the adjusted picture is a small JPG — fine to upload
            var box = input.closest('.pi-photo'), av = box && box.querySelector('.pi-avatar');
            if (av) {
                if (!av.dataset.acWas) av.dataset.acWas = av.outerHTML;   // to undo
                var pic = av.tagName === 'IMG' ? av : document.createElement('img');
                pic.className = 'pi-avatar'; pic.alt = 'New profile picture (not saved yet)';
                pic.style.cssText = 'object-fit:cover;border:3px solid var(--gold, #d4a017);';
                pic.src = URL.createObjectURL(blob);
                if (pic !== av) { pic.dataset.acWas = av.dataset.acWas; av.replaceWith(pic); }
            }
            var note = box && box.querySelector('.ac-note');
            if (box && !note) {
                note = document.createElement('div'); note.className = 'ac-note';
                input.closest('form').appendChild(note);
            }
            if (note) {
                note.innerHTML = '⚠ Preview — not saved yet. Click <b>Upload</b> to save it. <a href="#" data-ac-redo>Adjust again</a> · <a href="#" data-ac-undo>Undo</a>';
            }
            close();
        }, 'image/jpeg', 0.9);
    });

    // Adjust again / Undo (under the upload box)
    var lastFile = null;
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-ac-redo], [data-ac-undo]');
        if (!a) return;
        e.preventDefault();
        var box = a.closest('.pi-photo'), inp = box && box.querySelector('input[data-avatar-crop]');
        if (!inp) return;
        if (a.hasAttribute('data-ac-redo') && lastFile) { open(lastFile, inp); return; }
        var av = box.querySelector('.pi-avatar');            // Undo: the saved picture again, nothing chosen
        if (av && av.dataset.acWas) { var t = document.createElement('div'); t.innerHTML = av.dataset.acWas; av.replaceWith(t.firstChild); }
        inp.value = ''; var n = box.querySelector('.ac-note'); if (n) n.remove();
    });

    document.addEventListener('change', function (e) {
        var inp = e.target;
        if (!inp || !inp.matches || !inp.matches('input[type=file][data-avatar-crop]') || !inp.files || !inp.files[0]) return;
        var f = inp.files[0];
        if (!/^image\//.test(f.type) && !/\.(jpe?g|png|webp|gif)$/i.test(f.name)) return;   // not a picture: the normal check says so
        lastFile = f;
        open(f, inp);
    });
})();
</script>
