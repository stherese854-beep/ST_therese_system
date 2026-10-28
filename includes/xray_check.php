<?php
// ============================================================
//  "IS THIS REALLY A PANORAMIC X-RAY?"  (includes/xray_check.php)
// ============================================================
//  The clinic keeps PANORAMIC dental X-rays (Panorex): one wide picture
//  of all the teeth and both jaws. A computer cannot prove a picture is
//  one, but a panoramic X-ray has plain signs a wrong picture does not.
//  So the upload is stopped when:
//    - it is a COLOUR picture (a selfie, a photo of the mouth, a
//      screenshot) — X-rays are black-and-white. A tint over the whole
//      picture (a bluish film photographed on a light box) is fine.
//    - it is mostly WHITE paper (a document, a form, a receipt)
//    - it is BLANK (almost one shade: a black / white / covered photo)
//    - it is NOT WIDE: a panoramic X-ray is about twice as wide as it is
//      tall. A chest, hand or single-tooth X-ray (upright or square), or a
//      photo of the film held upright, is stopped.
//
//  It runs twice with the same rules: in the browser as soon as a file
//  is picked (xray_check_script), and on the server when PHP's GD module
//  is there (xray_image_problem). A real one that is stopped by mistake
//  can still be uploaded: the uploader ticks "It is a panoramic X-ray —
//  upload anyway", and that is noted in the Activity Log.
// ============================================================

const XRAY_NAME        = 'Panoramic X-ray (Panorex)';   // what the clinic calls it everywhere
const XRAY_SAMPLE      = 96;     // the picture is looked at in at most 96 x 96 points
const XRAY_COLOUR_DIFF = 28;     // a point this far from grey (after removing the overall tint) is "coloured"
const XRAY_MAX_COLOUR  = 0.12;   // more than 12% coloured points -> a colour picture
const XRAY_MAX_WHITE   = 0.55;   // more than 55% near-white points -> paper / a document
const XRAY_MIN_SPREAD  = 10;     // light-to-dark spread below this -> blank
const XRAY_MIN_WIDE    = 1.3;    // width ÷ height below this -> not a panoramic (they are ~2, a phone photo of one ~1.33+)

/** What is wrong, in words for the uploader (the browser shows the same words). */
function xray_messages() {
    return [
        'colour' => 'This looks like a colour photo, not a panoramic X-ray (X-rays are black-and-white).',
        'blank'  => 'This picture is almost blank (nearly one shade), not a panoramic X-ray.',
        'white'  => 'This looks like a document, a drawing or a mostly white picture, not a panoramic X-ray.',
        'shape'  => 'This does not look like a panoramic X-ray: a panoramic (Panorex) shows all the teeth in one wide picture, '
                  . 'about twice as wide as it is tall. A chest, hand or single-tooth X-ray is not accepted. '
                  . 'If it is a photo of the film, take it sideways so the X-ray fills the picture.',
    ];
}

/**
 * Light, colour, spread and shape of the picture at $path, measured with GD.
 * null when GD is missing or the picture cannot be read (then only the browser checks).
 */
function xray_image_stats($path) {
    if (!function_exists('imagecreatefromstring')) return null;
    $data = @file_get_contents($path);
    $img  = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img);
    // A phone photo is often stored on its side with a "turn it" note (EXIF); the browser
    // follows it, so the server does too. Without the EXIF module a JPEG's turn is unknown,
    // so only its long side counts (the browser check still sees the real shape).
    $wide = $w / max(1, $h);
    $isJpeg = substr($data, 0, 2) === "\xFF\xD8";
    if ($isJpeg) {
        $o = function_exists('exif_read_data') ? (int)(@exif_read_data($path)['Orientation'] ?? 1) : 0;
        if ($o >= 5 && $o <= 8) $wide = $h / max(1, $w);            // turned 90°
        elseif ($o === 0)       $wide = max($w, $h) / max(1, min($w, $h));
    }
    $scale = min(1, XRAY_SAMPLE / max($w, $h));
    $sw = max(1, (int)round($w * $scale)); $sh = max(1, (int)round($h * $scale));
    $small = imagecreatetruecolor($sw, $sh);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
    imagedestroy($img);
    $px = [];
    for ($y = 0; $y < $sh; $y++) for ($x = 0; $x < $sw; $x++) {
        $c = imagecolorat($small, $x, $y);
        $px[] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    }
    imagedestroy($small);
    $st = xray_stats_of($px);
    if ($st) $st['wide'] = $wide;
    return $st;
}

/** The numbers, from a list of [r, g, b] points (the browser does the same sums). */
function xray_stats_of(array $px) {
    $n = count($px);
    if (!$n) return null;
    $lum = []; $cast = [0, 0, 0];
    foreach ($px as $i => [$r, $g, $b]) {
        $l = (299 * $r + 587 * $g + 114 * $b) / 1000;
        $lum[$i] = $l;
        $cast[0] += $r - $l; $cast[1] += $g - $l; $cast[2] += $b - $l;
    }
    $cast = array_map(fn($v) => $v / $n, $cast);              // the tint over the whole picture
    $mean = array_sum($lum) / $n;
    $coloured = $white = 0; $var = 0;
    foreach ($px as $i => [$r, $g, $b]) {
        $l = $lum[$i];
        $diff = max(abs($r - $l - $cast[0]), abs($g - $l - $cast[1]), abs($b - $l - $cast[2]));
        if ($diff > XRAY_COLOUR_DIFF) $coloured++;
        if ($l > 235) $white++;
        $var += ($l - $mean) ** 2;
    }
    return ['colour' => $coloured / $n, 'white' => $white / $n, 'spread' => sqrt($var / $n)];
}

/** '' when it can be a panoramic X-ray, else what is wrong with it (shown to the uploader). */
function xray_image_problem($stats) {
    if (!$stats) return '';
    $m = xray_messages();
    if ($stats['colour'] > XRAY_MAX_COLOUR) return $m['colour'];
    if ($stats['spread'] < XRAY_MIN_SPREAD) return $m['blank'];
    if ($stats['white'] > XRAY_MAX_WHITE)   return $m['white'];
    if (isset($stats['wide']) && $stats['wide'] < XRAY_MIN_WIDE) return $m['shape'];
    return '';
}

/**
 * The browser check: measures the picked file right away and shows the result
 * under the file box. $form is a CSS selector for the upload form.
 */
function xray_check_script($form) {
    ob_start(); ?>
    <script>
    (function () {
        var form = document.querySelector(<?= json_encode($form) ?>);
        if (!form) return;
        var file = form.querySelector('input[type=file]'), out = form.querySelector('.xr-check'),
            ok = form.querySelector('.xr-confirm'), tick = ok.querySelector('input'), problem = '';
        var R = { size: <?= XRAY_SAMPLE ?>, diff: <?= XRAY_COLOUR_DIFF ?>, colour: <?= XRAY_MAX_COLOUR ?>, white: <?= XRAY_MAX_WHITE ?>,
                  spread: <?= XRAY_MIN_SPREAD ?>, wide: <?= XRAY_MIN_WIDE ?> };
        var M = <?= json_encode(xray_messages(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        function show(text, bad) {
            out.textContent = text; out.className = 'xr-check ' + (bad ? 'xr-bad' : 'xr-good'); out.hidden = !text;
            ok.hidden = !bad; if (!bad) tick.checked = false;
        }
        // The same sums as xray_stats_of() / xray_image_problem() on the server.
        function judge(img) {
            var s = Math.min(1, R.size / Math.max(img.naturalWidth, img.naturalHeight));
            var w = Math.max(1, Math.round(img.naturalWidth * s)), h = Math.max(1, Math.round(img.naturalHeight * s));
            var c = document.createElement('canvas'); c.width = w; c.height = h;
            var g = c.getContext('2d'); g.drawImage(img, 0, 0, w, h);
            var d = g.getImageData(0, 0, w, h).data, n = w * h, lum = new Float32Array(n), cast = [0, 0, 0], i, l;
            for (i = 0; i < n; i++) {
                l = (299 * d[i*4] + 587 * d[i*4+1] + 114 * d[i*4+2]) / 1000; lum[i] = l;
                cast[0] += d[i*4] - l; cast[1] += d[i*4+1] - l; cast[2] += d[i*4+2] - l;
            }
            cast = cast.map(function (v) { return v / n; });
            var mean = 0; for (i = 0; i < n; i++) mean += lum[i]; mean /= n;
            var col = 0, white = 0, v = 0;
            for (i = 0; i < n; i++) {
                l = lum[i];
                var df = Math.max(Math.abs(d[i*4] - l - cast[0]), Math.abs(d[i*4+1] - l - cast[1]), Math.abs(d[i*4+2] - l - cast[2]));
                if (df > R.diff) col++; if (l > 235) white++; v += (l - mean) * (l - mean);
            }
            col /= n; white /= n; var spread = Math.sqrt(v / n);
            if (col > R.colour) return M.colour;
            if (spread < R.spread) return M.blank;
            if (white > R.white) return M.white;
            if (img.naturalWidth / img.naturalHeight < R.wide) return M.shape;   // the browser follows a phone photo's turn
            return '';
        }
        file.addEventListener('change', function () {
            problem = ''; show('', false); form.querySelector('.xr-need').hidden = true;   // a new file: start over
            var f = file.files && file.files[0];
            if (!f || !/^image\//.test(f.type)) return;
            var url = URL.createObjectURL(f), img = new Image();
            img.onload = function () {
                try { problem = judge(img); } catch (e) { problem = ''; }
                URL.revokeObjectURL(url);
                show(problem ? '⚠ ' + problem : '✓ Looks like a panoramic X-ray.', !!problem);
            };
            img.onerror = function () { URL.revokeObjectURL(url); };
            img.src = url;
        });
        tick.addEventListener('change', function () { form.querySelector('.xr-need').hidden = true; });
        // false (and the warning shown) while a flagged picture is not confirmed. A form
        // in steps (online booking) calls it before moving on: form.xrayCheckOk().
        form.xrayCheckOk = function () {
            if (problem && !tick.checked) { form.querySelector('.xr-need').hidden = false; tick.focus(); return false; }
            return true;
        };
        form.addEventListener('submit', function (e) { if (!form.xrayCheckOk()) e.preventDefault(); });
    })();
    </script>
    <?php return ob_get_clean();
}
