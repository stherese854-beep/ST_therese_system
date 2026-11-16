<?php
// ============================================================
//  "OPEN IN YOUR BROWSER" NOTICE  (includes/inapp_banner.php)
// ============================================================
//  Links tapped inside Messenger, Facebook, Instagram, etc. open in that
//  app's own mini browser. It keeps its OWN login cookies, so someone who
//  signs in there is NOT signed in when they later tap "Open in browser"
//  (Chrome / Safari) — no website can share a login between the two.
//
//  So when the page is opened inside one of those apps, this shows a short
//  notice asking them to switch to their real browser first:
//    - Android: a button that opens this same page in Chrome
//    - iPhone:  steps (••• → Open in browser), since iOS allows no button
//  Normal browsers never see it. It can be closed for the rest of the visit.
// ============================================================
$__ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (!preg_match('/FBAN|FBAV|FB_IAB|FBIOS|Messenger|Instagram|Line\/|MicroMessenger|TikTok|musical_ly|Snapchat/i', $__ua)) return;

$__isAndroid = stripos($__ua, 'Android') !== false;
$__host = $_SERVER['HTTP_HOST'] ?? '';
$__path = $_SERVER['REQUEST_URI'] ?? '/';
// Android "intent" link: opens the same address in Chrome.
$__chrome = 'intent://' . $__host . $__path . '#Intent;scheme=https;package=com.android.chrome;end';
?>
<div id="inapp-note" style="position:sticky;top:0;z-index:30000;background:#fff6e0;border-bottom:1px solid #e0b64a;
     color:#6b531f;font-family:system-ui,sans-serif;font-size:14px;line-height:1.4;padding:10px 14px;display:flex;gap:10px;align-items:flex-start;">
    <div style="font-size:20px;line-height:1;">🌐</div>
    <div style="flex:1;">
        <strong>For the best experience, open this page in your browser.</strong><br>
        You're viewing it inside an app (like Messenger). If you log in here, you won't stay logged in when you switch to Chrome or Safari.
        <?php if ($__isAndroid): ?>
            <div style="margin-top:8px;">
                <a href="<?= htmlspecialchars($__chrome, ENT_QUOTES, 'UTF-8') ?>"
                   style="display:inline-block;background:#0f766e;color:#fff;padding:7px 14px;border-radius:8px;text-decoration:none;font-weight:600;">Open in Chrome</a>
            </div>
        <?php else: ?>
            <div style="margin-top:6px;">Tap <strong>•••</strong> (top or bottom corner), then <strong>Open in browser</strong> / <strong>Open in Safari</strong>.</div>
        <?php endif; ?>
    </div>
    <button type="button" aria-label="Close" data-keep-text
            onclick="document.getElementById('inapp-note').remove();try{sessionStorage.setItem('inappNoteClosed','1')}catch(e){}"
            style="border:none;background:none;font-size:20px;line-height:1;color:#8a6d2f;cursor:pointer;">&times;</button>
</div>
<script>try{if(sessionStorage.getItem('inappNoteClosed')==='1'){var n=document.getElementById('inapp-note');if(n)n.remove();}}catch(e){}</script>
