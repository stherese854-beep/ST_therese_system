<?php
// ============================================================
//  LANDING PAGE  (index.php)  -- this is the site homepage
// ============================================================
//  A modern marketing homepage for the Dental Appointment System.
//  It does NOT contain any login/register/booking forms — the
//  buttons simply link to the existing pages:
//     Login    -> login.php?mode=signin
//     Register -> login.php?mode=register
//     Book     -> book.php
//
//  The "Meet Our Dentists" section pulls the clinic's real active
//  dentists from the database (with a safe fallback if the DB is
//  unavailable), so it always renders.
// ============================================================

// Clean address: ".../index.php" -> ".../" (same page, tidier URL).
// Done here with a RELATIVE redirect, not in .htaccess, because behind
// Railway's proxy Apache would build the address with http and port 8080.
if (preg_match('~/index\.php$~i', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '')) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ./' . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}

require_once __DIR__ . '/includes/policies.php';   // Terms & Privacy text (edited by the admin)

// Try to load the clinic's dentists from the database (optional).
$dentists = [];
try {
    require_once __DIR__ . '/config/db.php';
    try { require_once __DIR__ . '/config/auth.php'; require_once __DIR__ . '/includes/reminders.php'; run_daily_reminders($pdo); }
    catch (Throwable $e) { /* reminders must never break the homepage */ }
    $dentists = $pdo->query("SELECT name, specialty, photo FROM users WHERE role='dentist' AND status='active' ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $dentists = [];
}
// Fallback dentists if the database returned nothing.
if (empty($dentists)) {
    $dentists = [
        ['name' => 'Dr. Ana Santos', 'specialty' => 'General & Cosmetic Dentistry'],
        ['name' => 'Dr. Ben Reyes',  'specialty' => 'Orthodontics'],
        ['name' => 'Dr. Carla Cruz', 'specialty' => 'Pediatric Dentistry'],
    ];
}
function initials2($n){ $p = preg_split('/\s+/', trim($n)); $a = $p[0][0] ?? ''; $b = isset($p[count($p)-1]) && count($p)>1 ? $p[count($p)-1][0] : ''; return strtoupper($a.$b); }
$expYears = [8, 12, 6, 10, 9, 7];

// ---- Editable landing content (loaded from Settings; falls back to defaults) ----
$LC = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key LIKE 'land_%'") as $r) $LC[$r['setting_key']] = $r['setting_value']; } catch (Throwable $e) {}
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function lc($k,$def=''){ global $LC; return (isset($LC[$k]) && trim($LC[$k])!=='') ? $LC[$k] : $def; }
function lc_rows($k,$def){ global $LC; if(empty($LC[$k])) return $def; $out=[]; foreach(preg_split('/\r?\n/',trim($LC[$k])) as $ln){ $ln=trim($ln); if($ln==='')continue; $out[]=array_map('trim',explode('|',$ln)); } return $out?:$def; }

// ---- Live preview for the admin's "Edit Landing Page" (nothing is saved) ----
// The editor posts its unsaved form here into a side frame; the posted values
// replace the saved ones for this one render only.
$isPreview = false; $pvPrimary = null; $pvBg = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'preview_landing'
    && function_exists('current_role') && current_role() === 'admin') {
    foreach ($_POST as $k => $v) if (strpos($k, 'land_') === 0 && is_string($v)) $LC[$k] = trim($v);
    $pvPrimary = $_POST['theme_primary'] ?? null;
    $pvBg      = $_POST['theme_bg'] ?? null;
    $isPreview = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>St. Therese Dental Clinic — Dental Appointment System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --ink:#11302d; --blue:#0f766e; --blue-600:#0c5f58; --teal:#0f766e; --teal-600:#0d5f58;
  --teal-dark:#0d3b3b; --teal-light:#14b8a6; --gold:#c79a5c; --gold-ink:#a9793a; --gold-soft:#f6efe0;
  --sky:#eef6f5; --mint:#e6f4f1; --muted:#5c706e; --line:#dbe8e6; --white:#fff;
  --grad:linear-gradient(135deg,#0d3b3b 0%,#0f766e 55%,#14b8a6 100%);
  --shadow-sm:0 4px 14px rgba(12,50,48,.06);
  --shadow:0 18px 44px rgba(12,50,48,.10);
  --shadow-lg:0 30px 70px rgba(12,50,48,.16);
  --r:18px; --r-lg:26px;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,sans-serif;color:var(--ink);background:var(--white);line-height:1.65;-webkit-font-smoothing:antialiased}
h1,h2,h3,h4,.display{font-family:'Poppins',sans-serif;line-height:1.15;letter-spacing:-.02em}
a{text-decoration:none;color:inherit}
img,svg{display:block}
section{scroll-margin-top:88px}
.wrap{max-width:1180px;margin:0 auto;padding:0 24px}
.eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--teal-600);background:var(--mint);padding:7px 14px;border-radius:100px}
.sec-head{max-width:660px;margin:0 auto 54px;text-align:center}
.sec-head h2{font-size:clamp(1.8rem,3.6vw,2.7rem);font-weight:700;margin:16px 0 12px}
.sec-head p{color:var(--muted);font-size:1.05rem}
.grad-text{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:8px;font-family:'Inter';font-weight:600;font-size:.95rem;padding:13px 24px;border-radius:100px;border:1.5px solid transparent;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,background .18s ease,color .18s ease;white-space:nowrap}
.btn svg{width:18px;height:18px}
.btn-primary{background:var(--grad);color:#fff;box-shadow:0 10px 24px rgba(15,118,110,.28)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 16px 32px rgba(15,118,110,.36)}
.btn-ghost{background:var(--white);color:var(--ink);border-color:var(--line)}
.btn-ghost:hover{border-color:var(--blue);color:var(--blue);transform:translateY(-2px)}
.btn-white{background:var(--white);color:var(--blue-600)}
.btn-white:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.btn-clear{background:rgba(255,255,255,.14);color:#fff;border-color:rgba(255,255,255,.4)}
.btn-clear:hover{background:rgba(255,255,255,.24)}

/* nav */
.nav{position:sticky;top:0;z-index:100;transition:box-shadow .25s,background .25s,backdrop-filter .25s}
.nav.scrolled{background:rgba(255,255,255,.82);backdrop-filter:saturate(180%) blur(16px);box-shadow:0 1px 0 rgba(12,50,48,.06),0 10px 30px rgba(12,50,48,.06)}
.nav-inner{display:flex;align-items:center;gap:24px;height:72px}
.brand{display:flex;align-items:center;gap:11px;font-family:'Poppins';font-weight:700;font-size:1.12rem;color:var(--ink)}
.brand .logo{width:40px;height:40px;border-radius:12px;background:var(--grad);display:grid;place-items:center;box-shadow:0 8px 18px rgba(15,118,110,.3)}
.brand .logo svg{width:22px;height:22px}
.brand small{display:block;font-family:'Inter';font-weight:500;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:var(--teal-600);margin-top:-2px}
.nav-menu{display:contents}
.nav-links{display:flex;align-items:center;gap:4px;margin-left:14px}
.nav-links a{font-size:.92rem;font-weight:500;color:var(--muted);padding:9px 14px;border-radius:100px;transition:color .18s,background .18s}
.nav-links a:hover{color:var(--ink);background:var(--sky)}
.nav-links a.active{color:var(--blue-600);background:var(--sky);font-weight:600}
.nav-actions{margin-left:auto;display:flex;align-items:center;gap:10px}
.nav-actions .btn{padding:10px 18px;font-size:.9rem}
.hamburger{display:none;margin-left:auto;width:44px;height:44px;border-radius:12px;border:1.5px solid var(--line);background:var(--white);cursor:pointer;align-items:center;justify-content:center}
.hamburger svg{width:22px;height:22px;color:var(--ink)}

/* hero */
.hero{position:relative;overflow:hidden;padding:70px 0 90px;background:
  radial-gradient(1000px 500px at 82% -8%,rgba(20,184,166,.14),transparent 60%),
  radial-gradient(760px 480px at 8% 6%,rgba(15,118,110,.12),transparent 60%),
  var(--white)}
.hero-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:56px;align-items:center}
.hero h1{font-size:clamp(2.3rem,5vw,3.6rem);font-weight:800;margin-bottom:20px}
.hero .lead{font-size:1.12rem;color:var(--muted);max-width:540px;margin-bottom:30px}
.hero-cta{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:34px}
.hero-trust{display:flex;gap:26px;flex-wrap:wrap;align-items:center}
.hero-trust .t{display:flex;align-items:center;gap:9px;font-size:.9rem;color:var(--muted);font-weight:500}
.hero-trust .t b{font-family:'Poppins';font-size:1.35rem;color:var(--ink);font-weight:700;line-height:1}
.check{width:22px;height:22px;border-radius:50%;background:var(--mint);display:grid;place-items:center;flex:none}
.check svg{width:13px;height:13px;color:var(--teal-600)}

/* hero visual (dental illustration) */
.hero-art{position:relative;height:470px;display:grid;place-items:center}
.blob{position:absolute;border-radius:44% 56% 60% 40%/50% 42% 58% 50%;background:var(--grad);filter:blur(2px);opacity:.16;animation:float 9s ease-in-out infinite;z-index:0}
.blob.b1{width:300px;height:300px;top:20px;right:10px}
.blob.b2{width:210px;height:210px;bottom:0;left:0;background:linear-gradient(120deg,var(--teal-light),var(--teal));opacity:.14;animation-delay:-3s}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}

/* The big tooth illustration (pure SVG - no image file needed) */
/* 432px wide x (500/460 ratio) = 470px tall, which is exactly the .hero-art
   height, so the drawing fills the space without ever overflowing. */
.tooth-hero{position:relative;z-index:1;width:100%;max-width:432px;height:auto;overflow:visible;animation:float 7s ease-in-out infinite}
.tooth-hero .spark{transform-box:fill-box;transform-origin:center;animation:twinkle 3.2s ease-in-out infinite}
.tooth-hero .spark.s2{animation-delay:-1.1s}
.tooth-hero .spark.s3{animation-delay:-2.2s}
.tooth-hero .ring-dash{transform-box:fill-box;transform-origin:center;animation:spin 44s linear infinite}
@keyframes twinkle{0%,100%{opacity:.35;transform:scale(.82)}50%{opacity:1;transform:scale(1.12)}}
@keyframes spin{to{transform:rotate(360deg)}}

/* generic section spacing */
.pad{padding:88px 0}
.pad-sky{background:linear-gradient(180deg,#fff, #F2F8F7)}
.soft{background:var(--sky)}

/* about */
.about-grid{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center}
.about-visual{position:relative;border-radius:var(--r-lg);padding:36px;background:var(--grad);color:#fff;box-shadow:var(--shadow-lg);overflow:hidden}
.about-visual::after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.12);bottom:-90px;right:-70px}
.about-visual h3{font-size:1.5rem;font-weight:700;margin-bottom:12px;position:relative}
.about-visual p{color:rgba(255,255,255,.9);position:relative}
.mini-stats{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:26px;position:relative}
.mini-stats .s{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:16px;padding:16px}
.mini-stats .s b{font-family:'Poppins';font-size:1.7rem;font-weight:700;display:block}
.mini-stats .s span{font-size:.82rem;color:rgba(255,255,255,.85)}
.about-txt h2{font-size:clamp(1.7rem,3.4vw,2.5rem);font-weight:700;margin:16px 0 16px}
.about-txt p{color:var(--muted);margin-bottom:16px}
.ticks{list-style:none;margin-top:8px}
.ticks li{display:flex;gap:12px;align-items:flex-start;margin-bottom:12px;font-weight:500;color:var(--ink)}
.ticks li .check{margin-top:1px}

/* feature grid */
.grid{display:grid;gap:22px}
.g4{grid-template-columns:repeat(4,1fr)}
.g3{grid-template-columns:repeat(3,1fr)}
.g2{grid-template-columns:repeat(2,1fr)}
.feature{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:26px;transition:transform .2s,box-shadow .2s,border-color .2s}
.feature:hover{transform:translateY(-5px);box-shadow:var(--shadow);border-color:transparent}
.feature .ic{width:50px;height:50px;border-radius:14px;background:var(--sky);display:grid;place-items:center;margin-bottom:16px;transition:background .2s}
.feature:hover .ic{background:var(--grad)}
.feature .ic svg{width:24px;height:24px;color:var(--blue-600);transition:color .2s}
.feature:hover .ic svg{color:#fff}
.feature h3{font-size:1.06rem;font-weight:600;margin-bottom:7px}
.feature p{font-size:.9rem;color:var(--muted)}

/* steps */
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:22px;position:relative}
.step{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:28px 24px;text-align:center;position:relative;z-index:1}
.step .num{width:56px;height:56px;border-radius:50%;background:var(--grad);color:#fff;font-family:'Poppins';font-weight:700;font-size:1.3rem;display:grid;place-items:center;margin:0 auto 16px;box-shadow:0 10px 22px rgba(15,118,110,.3)}
.step h3{font-size:1.05rem;font-weight:600;margin-bottom:8px}
.step p{font-size:.88rem;color:var(--muted)}
.steps::before{content:"";position:absolute;top:56px;left:12%;right:12%;height:2px;background:linear-gradient(90deg,var(--blue),var(--teal));opacity:.25;z-index:0}

/* services */
.svc{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:24px;display:flex;gap:16px;align-items:flex-start;transition:transform .2s,box-shadow .2s}
.svc:hover{transform:translateY(-4px);box-shadow:var(--shadow)}
.svc .ic{width:52px;height:52px;border-radius:14px;flex:none;display:grid;place-items:center;background:var(--mint)}
.svc .ic svg{width:26px;height:26px;color:var(--teal-600)}
.svc h3{font-size:1.04rem;font-weight:600;margin-bottom:5px}
.svc p{font-size:.88rem;color:var(--muted)}

/* why choose */
.why{background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:26px;transition:transform .2s,box-shadow .2s}
.why:hover{transform:translateY(-4px);box-shadow:var(--shadow)}
.why .ic{width:46px;height:46px;border-radius:12px;background:var(--grad);display:grid;place-items:center;margin-bottom:14px}
.why .ic svg{width:22px;height:22px;color:#fff}
.why h3{font-size:1.05rem;font-weight:600;margin-bottom:7px}
.why p{font-size:.9rem;color:var(--muted)}

/* dentists */
/* dentists — a flex row that CENTRES itself, so it looks right whether the
   clinic has 1 dentist or 6. Cards grow to fill the row but never get wider
   than 360px, and they wrap onto a new (still centred) line when they run out
   of space. No code change needed when the admin adds another dentist. */
.docs{display:flex;flex-wrap:wrap;gap:22px;justify-content:center}
.docs .doc{flex:1 1 300px;max-width:360px}
.doc{background:var(--white);border:1px solid var(--line);border-radius:var(--r-lg);padding:26px;text-align:center;transition:transform .2s,box-shadow .2s}
.doc:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg)}
.doc .ph{width:96px;height:96px;border-radius:50%;margin:0 auto 16px;background:var(--grad);display:grid;place-items:center;color:#fff;font-family:'Poppins';font-weight:700;font-size:1.8rem;box-shadow:0 12px 26px rgba(15,118,110,.28);border:4px solid #fff;outline:2px solid var(--sky)}
.doc h3{font-size:1.12rem;font-weight:600}
.doc .spec{color:var(--teal-600);font-weight:600;font-size:.88rem;margin:3px 0 8px}
.doc .exp{font-size:.82rem;color:var(--muted);background:var(--sky);display:inline-block;padding:5px 12px;border-radius:100px;margin-bottom:12px}
.doc p{font-size:.88rem;color:var(--muted)}

/* testimonials */
.tcar{max-width:760px;margin:0 auto;position:relative}
.ttrack{overflow:hidden;border-radius:var(--r-lg)}
.tflex{display:flex;transition:transform .5s cubic-bezier(.4,0,.2,1)}
.tcard{min-width:100%;padding:44px;background:var(--white);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--shadow);text-align:center}
.stars{color:#F5B301;font-size:1.15rem;letter-spacing:2px;margin-bottom:16px}
.tcard .quote{font-size:1.18rem;line-height:1.6;color:var(--ink);font-weight:500;margin-bottom:22px}
.tcard .who{display:flex;align-items:center;justify-content:center;gap:12px}
.tcard .who .av{width:48px;height:48px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-family:'Poppins';font-weight:700}
.tcard .who b{display:block;font-weight:600}
.tcard .who span{font-size:.84rem;color:var(--muted)}
.tnav{display:flex;justify-content:center;align-items:center;gap:16px;margin-top:24px}
.tdots{display:flex;gap:8px}
.tdot{width:9px;height:9px;border-radius:50%;background:var(--line);border:none;cursor:pointer;transition:width .2s,background .2s;padding:0}
.tdot.on{width:26px;background:var(--grad)}
.tarrow{width:44px;height:44px;border-radius:50%;border:1.5px solid var(--line);background:var(--white);cursor:pointer;display:grid;place-items:center;transition:.18s}
.tarrow:hover{border-color:var(--blue);color:var(--blue);transform:scale(1.06)}
.tarrow svg{width:18px;height:18px}

/* faq */
.faq{max-width:800px;margin:0 auto}
.qa{background:var(--white);border:1px solid var(--line);border-radius:16px;margin-bottom:14px;overflow:hidden;transition:box-shadow .2s,border-color .2s}
.qa.open{box-shadow:var(--shadow);border-color:transparent}
.qa button{width:100%;text-align:left;background:none;border:none;cursor:pointer;padding:20px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px;font-family:'Poppins';font-weight:600;font-size:1.02rem;color:var(--ink)}
.qa .ans{max-height:0;overflow:hidden;transition:max-height .3s ease}
.qa .ans p{padding:0 22px 20px;color:var(--muted)}
.qa .chev{width:30px;height:30px;border-radius:50%;background:var(--sky);display:grid;place-items:center;flex:none;transition:transform .3s,background .3s}
.qa.open .chev{transform:rotate(180deg);background:var(--grad)}
.qa .chev svg{width:16px;height:16px;color:var(--blue-600)}
.qa.open .chev svg{color:#fff}

/* contact */
.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:stretch}
.cinfo{display:grid;gap:16px;align-content:start}
.crow{display:flex;gap:16px;align-items:flex-start;background:var(--white);border:1px solid var(--line);border-radius:16px;padding:18px 20px}
.crow .ic{width:46px;height:46px;border-radius:12px;background:var(--sky);display:grid;place-items:center;flex:none}
.crow .ic svg{width:22px;height:22px;color:var(--blue-600)}
.crow h4{font-size:.95rem;font-weight:600}
.crow p{font-size:.9rem;color:var(--muted)}
.cmap{border-radius:var(--r-lg);overflow:hidden;border:1px solid var(--line);min-height:340px;background:
  linear-gradient(135deg,#EAF2FE,#E7FBF6);position:relative;display:grid;place-items:center}
/* A real Google map fills the whole box */
.cmap iframe{width:100%;height:100%;min-height:340px;border:0;display:block}
.cmap .pin{text-align:center;color:var(--blue-600)}
.cmap .pin svg{width:54px;height:54px;margin:0 auto 8px}
.cmap .grid-lines{position:absolute;inset:0;background-image:linear-gradient(rgba(15,118,110,.08) 1px,transparent 1px),linear-gradient(90deg,rgba(15,118,110,.08) 1px,transparent 1px);background-size:40px 40px}
.socials{display:flex;gap:10px;margin-top:6px}
.socials a{width:44px;height:44px;border-radius:12px;background:var(--white);border:1px solid var(--line);display:grid;place-items:center;transition:.18s}
.socials a:hover{background:var(--grad);border-color:transparent;transform:translateY(-3px)}
.socials a svg{width:20px;height:20px;color:var(--ink);transition:color .18s}
.socials a:hover svg{color:#fff}

/* CTA */
.cta-band{background:var(--grad);border-radius:32px;padding:60px;text-align:center;color:#fff;position:relative;overflow:hidden;box-shadow:var(--shadow-lg)}
.cta-band::before,.cta-band::after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.12)}
.cta-band::before{width:280px;height:280px;top:-120px;left:-60px}
.cta-band::after{width:230px;height:230px;bottom:-110px;right:-40px}
.cta-band h2{font-size:clamp(1.8rem,3.6vw,2.6rem);font-weight:800;margin-bottom:14px;position:relative}
.cta-band p{color:rgba(255,255,255,.92);max-width:560px;margin:0 auto 28px;position:relative;font-size:1.06rem}
.cta-actions{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;position:relative}

/* footer */
.foot{background:#081B33;color:#B7C6DD;padding:64px 0 26px;margin-top:0}
.foot-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1.2fr;gap:40px;padding-bottom:40px;border-bottom:1px solid rgba(255,255,255,.08)}
.foot .brand{color:#fff}
.foot .brand small{color:#7FE3D3}
.foot p.desc{margin-top:14px;font-size:.9rem;color:#93A6C4;max-width:280px}
.foot h5{color:#fff;font-family:'Poppins';font-weight:600;font-size:1rem;margin-bottom:16px}
.foot ul{list-style:none}
.foot ul li{margin-bottom:10px}
.foot ul a{font-size:.9rem;color:#B7C6DD;transition:color .18s}
.foot ul a:hover{color:#fff}
.foot .cinline{font-size:.9rem;color:#B7C6DD;margin-bottom:10px;display:flex;gap:10px;align-items:flex-start}
.foot .cinline svg{width:17px;height:17px;color:#7FE3D3;flex:none;margin-top:3px}
.foot-bottom{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding-top:22px;font-size:.85rem;color:#8098BA}
.foot-bottom .lk{display:flex;gap:20px;flex-wrap:wrap}
.foot-bottom a:hover{color:#fff}

/* reveal animation */
.reveal{opacity:0;transform:translateY(26px);transition:opacity .6s ease,transform .6s ease}
.reveal.in{opacity:1;transform:none}

/* responsive */
@media(max-width:980px){
  /* Keep the hero side-by-side — image right, text left — just tighter */
  .hero-grid{grid-template-columns:1fr 1fr;gap:24px;align-items:center}
  .hero-art{height:320px;order:1}
  .tooth-hero{max-width:280px}
  .hero h1{font-size:clamp(1.6rem,5vw,2.4rem)}
  .hero .lead{font-size:.97rem}
  /* Other grids still go single column */
  .about-grid,.contact-grid,.hmo-grid{grid-template-columns:1fr;gap:40px}
  .g4{grid-template-columns:repeat(2,1fr)}
  .steps{grid-template-columns:repeat(2,1fr)}
  .steps::before{display:none}
  .foot-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
  /* On very small phones keep side-by-side but shrink the tooth more */
  .hero-grid{grid-template-columns:1fr 1fr;gap:14px;align-items:center}
  .hero-art{height:260px;display:flex;align-items:center;justify-content:center}
  .tooth-hero{max-width:220px}
  .hero h1{font-size:clamp(1.3rem,6vw,1.8rem)}
  .hero .lead{font-size:.88rem}
  .hero-cta{gap:8px}
  .hero-cta .btn{font-size:.82rem;padding:10px 16px}
  .hero-trust{gap:14px}
  .hero-trust .t{font-size:.78rem}
}
@media(max-width:900px){
  .nav-menu{display:none;position:absolute;top:72px;left:0;right:0;flex-direction:column;background:var(--white);padding:16px;gap:10px;box-shadow:var(--shadow);border-top:1px solid var(--line)}
  .nav.open .nav-menu{display:flex}
  .nav-menu .nav-links{display:flex;flex-direction:column;gap:4px;margin:0}
  .nav-menu .nav-links a{width:100%}
  .nav-menu .nav-actions{display:flex;flex-direction:column;gap:8px;margin:0}
  .nav-menu .nav-actions .btn{width:100%;justify-content:center}
  .hamburger{display:flex}
}
@media(max-width:760px){
  .g4,.g3,.g2,.steps{grid-template-columns:1fr}
  .foot-grid{grid-template-columns:1fr}
  .cta-band,.about-visual{padding:36px 24px}
  .pad{padding:64px 0}
  .hero{padding:48px 0 70px}
}
/* ===== HMO / accredited providers ===== */
.hmo-grid{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center}
.hmo-visual{position:relative;border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--shadow-lg)}
.hmo-visual img{width:100%;height:auto;display:block}
/* Fallback card, shown when the admin has not uploaded an HMO image yet */
.hmo-card{position:relative;overflow:hidden;border-radius:var(--r-lg);border:1px solid var(--line);
          background:linear-gradient(180deg,#fbfaf6,#f2efe6);padding:32px 28px 76px;box-shadow:var(--shadow-lg)}
.hmo-card h3{font-size:clamp(1.5rem,3vw,2.1rem);font-weight:800;color:var(--teal-dark);line-height:1.1}
.hmo-card .sub{font-size:.86rem;color:var(--muted);margin-top:8px}
.hmo-chips{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:22px;position:relative;z-index:1}
.hmo-chip{background:var(--white);border:1px solid var(--line);border-radius:10px;padding:11px 10px;text-align:center;
          font-size:.82rem;font-weight:600;color:var(--ink);box-shadow:var(--shadow-sm)}
.hmo-wave{position:absolute;left:-8%;right:-8%;bottom:-34px;height:88px;background:var(--grad);
          border-radius:50% 50% 0 0;z-index:0}
/* Right-hand text column */
.hmo-title{display:inline-block;background:var(--teal-dark);color:#fff;padding:10px 18px;border-radius:10px;
           font-family:'Poppins';font-weight:700;font-size:clamp(1.3rem,2.5vw,1.95rem);line-height:1.25;
           box-shadow:0 10px 24px rgba(12,50,48,.18)}
.hmo-body{color:var(--muted);margin:20px 0 18px}
.hmo-body strong{color:var(--ink)}
.hmo-ticks{list-style:none;margin-bottom:26px}
.hmo-ticks li{display:flex;gap:11px;align-items:flex-start;margin-bottom:11px;font-weight:500;color:var(--ink)}
.hmo-ticks li svg{width:18px;height:18px;color:var(--teal-600);flex:none;margin-top:3px}

@media(prefers-reduced-motion:reduce){
  *{animation:none!important;scroll-behavior:auto!important}
  .reveal{opacity:1;transform:none;transition:none}
}

/* ============================================================
   THEMED TO MATCH THE LOGIN / APP  (teal + gold)
   ============================================================ */
.eyebrow{color:var(--gold-ink);background:var(--gold-soft)}
.grad-text{background:linear-gradient(120deg,var(--teal),var(--teal-light));-webkit-background-clip:text;background-clip:text;color:transparent}
.stars{color:#d9a441}

/* Hero = dark teal branding panel (like the login) with a gold accent word */
.hero{background:
  radial-gradient(900px 460px at 84% -10%,rgba(20,184,166,.30),transparent 60%),
  radial-gradient(720px 460px at 6% 8%,rgba(199,154,92,.18),transparent 60%),
  linear-gradient(135deg,var(--teal-dark) 0%,var(--teal-600) 60%,var(--teal) 100%)}
.hero h1{color:#fff}
.hero .lead{color:#c3dbd7}
.hero .eyebrow{background:rgba(255,255,255,.10);color:#eccfa0}
.hero .grad-text{background:none;-webkit-text-fill-color:var(--gold);color:var(--gold)}
.hero-trust .t{color:#c3dbd7}
.hero-trust .t b{color:#fff}
.hero .check{background:rgba(255,255,255,.16)}
.hero .check svg{color:#eccfa0}
.hero .btn-primary{background:var(--gold);color:#123331;box-shadow:0 12px 26px rgba(199,154,92,.34)}
.hero .btn-primary:hover{background:#d2a862;color:#123331;transform:translateY(-2px);box-shadow:0 16px 32px rgba(199,154,92,.42)}
.hero .btn-ghost{background:rgba(255,255,255,.10);color:#fff;border-color:rgba(255,255,255,.34)}
.hero .btn-ghost:hover{background:rgba(255,255,255,.2);color:#fff;border-color:rgba(255,255,255,.5)}
.blob.b1{background:linear-gradient(120deg,var(--teal-light),var(--teal-light));opacity:.20}
.blob.b2{background:linear-gradient(120deg,#e6c88f,#c79a5c);opacity:.16}

/* Solid white navbar with black text (readable over the dark hero) */
.nav{background:rgba(255,255,255,.95);backdrop-filter:saturate(180%) blur(14px);box-shadow:0 1px 0 rgba(12,50,48,.06),0 8px 26px rgba(12,50,48,.05)}
.brand{color:#111}
.brand small{color:#5a5a5a}
.nav-links a{color:#1f1f1f}
.nav-links a:hover{color:#000;background:var(--sky)}
.nav-links a.active{color:var(--teal-600);background:var(--sky)}

/* Footer: deep teal instead of navy */
.foot{background:#0a2725}
.foot .brand small{color:#d9b989}
</style>
<?php if (function_exists('theme_style_tag')) echo theme_style_tag($pdo, 'landing', $pvPrimary, $pvBg, $isPreview); ?>
<?php if (function_exists('system_text_scale')) {      // text size: the clinic default (or the editor's unsaved pick in the preview)
    $lpScale = ($isPreview && text_scale_ok($_POST['ui_text_scale'] ?? '')) ? (int)$_POST['ui_text_scale'] : system_text_scale($pdo);
    echo '<style id="text-scale">html{font-size:' . $lpScale . '%}</style>';
} ?>
<?php if ($isPreview): ?><style>.reveal{opacity:1!important;transform:none!important}</style><?php endif; ?>
    <!-- Icons: Bootstrap Icons + js/icons.js, which swaps the pages' emojis for these icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="js/icons.js?v=<?= @filemtime(__DIR__ . '/js/icons.js') ?: time() ?>"></script>
</head>
<body>
<?php include __DIR__ . '/includes/inapp_banner.php';   // "open in your browser" notice inside Messenger/Facebook/etc. ?>

<!-- ============ NAV ============ -->
<nav class="nav" id="nav">
  <div class="wrap nav-inner">
    <a href="#home" class="brand">
      <span class="logo"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2.5c-2 0-2.8 1-4.4 1-1.3 0-2.6-.6-3.3.4-.9 1.2-.3 3.6.2 5.7.3 1.3.2 2 .6 3.6.5 2 .9 4 1.7 5.6.4.8 1 1.7 1.7 1.7.9 0 1-1.2 1.2-2.3.2-1 .4-1.7 1.3-1.7s1.1.7 1.3 1.7c.2 1.1.3 2.3 1.2 2.3.7 0 1.3-.9 1.7-1.7.8-1.6 1.2-3.6 1.7-5.6.4-1.6.3-2.3.6-3.6.5-2.1 1.1-4.5.2-5.7-.7-1-2-.4-3.3-.4-1.6 0-2.4-1-4.4-1Z" fill="#fff"/></svg></span>
      <span>St. Therese<small>Appointment System</small></span>
    </a>
    <div class="nav-menu" id="navMenu">
      <div class="nav-links" id="navLinks">
        <a href="#home" class="active">Home</a>
        <a href="#features">Features</a>
        <a href="#services">Services</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
      </div>
      <div class="nav-actions">
        <a href="login?mode=signin" class="btn btn-ghost">Login</a>
        <a href="login?mode=register" class="btn btn-ghost">Register</a>
      </div>
    </div>
    <button class="hamburger" id="ham" aria-label="Menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg>
    </button>
  </div>
</nav>

<!-- ============ HERO ============ -->
<section class="hero" id="home">
  <div class="wrap hero-grid">
    <div class="reveal in">
      <span class="eyebrow"><?= h(lc('land_hero_eyebrow','🦷 Modern Dental Care, Simplified')) ?></span>
      <h1><?= h(lc('land_hero_title','Making Dental Appointments')) ?> <span class="grad-text"><?= h(lc('land_hero_highlight','Simple, Fast & Convenient')) ?></span></h1>
      <p class="lead"><?= h(lc('land_hero_subtitle','Our Dental Appointment System lets patients easily schedule appointments online, manage upcoming visits, and receive confirmations — all in one place.')) ?></p>
      <div class="hero-cta">
        <a href="book" class="btn btn-primary">Book Appointment
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <a href="login?mode=signin" class="btn btn-ghost">Login</a>
      </div>
      <div class="hero-trust">
        <div class="t"><span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 6" stroke-linecap="round" stroke-linejoin="round"/></svg></span> <?= h(lc('land_hero_trust1','Book in under 2 minutes')) ?></div>
        <?php foreach (lc_rows('land_hero_stats', [['24/7','online access'],['100%','secure & private']]) as $st): ?>
          <div class="t"><b><?= h($st[0]) ?></b> <?= h($st[1] ?? '') ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="hero-art">
      <div class="blob b1"></div><div class="blob b2"></div>

      <?php $heroImg = lc('land_img_hero',''); ?>
      <?php if ($heroImg && is_file(__DIR__ . '/' . $heroImg)): ?>
        <!-- The admin uploaded a photo in Edit Landing Page, so we show that.
             It fills the hero box neatly (object-fit:cover crops evenly, never
             stretches) and is clipped to the rounded corners so nothing spills. -->
        <div style="position:absolute;inset:0;z-index:1;border-radius:26px;
                    overflow:hidden;box-shadow:0 30px 70px rgba(4,33,31,.45);">
          <img src="<?= h($heroImg) ?>" alt="St. Therese of Carmel Dental Clinic"
               style="width:100%;height:100%;object-fit:cover;display:block;">
        </div>
      <?php else: ?>

      <!-- Big dental illustration. This is drawn with SVG code, so there is no
           image file to load and it stays sharp at any screen size. -->
      <svg class="tooth-hero" viewBox="0 0 460 500" xmlns="http://www.w3.org/2000/svg"
           role="img" aria-label="Illustration of a healthy tooth">
        <defs>
          <linearGradient id="toothFill" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%"   stop-color="#ffffff"/>
            <stop offset="52%"  stop-color="#effbf9"/>
            <stop offset="100%" stop-color="#c9e9e4"/>
          </linearGradient>
          <linearGradient id="toothShine" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%"   stop-color="#ffffff" stop-opacity=".95"/>
            <stop offset="100%" stop-color="#ffffff" stop-opacity="0"/>
          </linearGradient>
          <filter id="toothGlow" x="-35%" y="-35%" width="170%" height="170%">
            <feDropShadow dx="0" dy="20" stdDeviation="26" flood-color="#04211f" flood-opacity=".45"/>
          </filter>
        </defs>

        <!-- soft rings behind the tooth -->
        <circle cx="230" cy="252" r="188" fill="rgba(255,255,255,.05)"/>
        <circle cx="230" cy="252" r="188" fill="none" stroke="rgba(255,255,255,.16)" stroke-width="1.5"/>
        <circle class="ring-dash" cx="230" cy="252" r="218" fill="none"
                stroke="rgba(199,154,92,.35)" stroke-width="1.5" stroke-dasharray="3 12"/>

        <!-- the tooth -->
        <g filter="url(#toothGlow)">
          <path fill="url(#toothFill)" d="M230 62
            C176 62, 143 49, 109 68
            C70 90, 60 140, 69 197
            C76 246, 90 280, 103 330
            C113 368, 121 412, 133 434
            C140 447, 151 450, 159 440
            C168 429, 170 404, 175 368
            C181 326, 190 306, 230 306
            C270 306, 279 326, 285 368
            C290 404, 292 429, 301 440
            C309 450, 320 447, 327 434
            C339 412, 347 368, 357 330
            C370 280, 384 246, 391 197
            C400 140, 390 90, 351 68
            C317 49, 284 62, 230 62 Z"/>
          <!-- glossy highlight on the crown -->
          <ellipse cx="139" cy="152" rx="25" ry="60" transform="rotate(-16 139 152)"
                   fill="url(#toothShine)" opacity=".85"/>
        </g>

        <!-- gold sparkles -->
        <g fill="#c79a5c">
          <g class="spark"    transform="translate(372,120)"><path d="M0-17 3.6-3.6 17 0 3.6 3.6 0 17-3.6 3.6-17 0-3.6-3.6Z"/></g>
          <g class="spark s2" transform="translate(78,300)"><path d="M0-12 2.6-2.6 12 0 2.6 2.6 0 12-2.6 2.6-12 0-2.6-2.6Z"/></g>
          <g class="spark s3" transform="translate(408,318)"><path d="M0-9 1.9-1.9 9 0 1.9 1.9 0 9-1.9 1.9-9 0-1.9-1.9Z"/></g>
        </g>

        <!-- little floating dots -->
        <circle cx="60" cy="140" r="5" fill="rgba(255,255,255,.4)"/>
        <circle cx="400" cy="420" r="4" fill="rgba(255,255,255,.32)"/>
        <circle cx="120" cy="430" r="6" fill="rgba(199,154,92,.5)"/>
      </svg>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ============ ABOUT ============ -->
<section class="pad" id="about">
  <div class="wrap about-grid">
    <div class="about-visual reveal">
      <h3><?= h(lc('land_about_card_title','Your trusted dental care in Naic, Cavite')) ?></h3>
      <p><?= h(lc('land_about_card_text',"St. Therese of Carmel Dental Clinic has cared for families in our community with gentle, professional, and affordable dental treatment.")) ?></p>
      <div class="mini-stats">
        <?php
        $statDef = [['General','Dentistry'],['Ortho','dontics'],['Mon-Sat','9AM - 5PM'],['Walk-in','& Booking'] ];
        // Only show proper "Number | Label" stats. Lines without a "|" (e.g. tick
        // sentences pasted into the wrong box) are skipped, and we cap at 4 boxes
        // so the card can never blow up to a huge size.
        $shown = 0;
        foreach(lc_rows('land_about_stats',$statDef) as $st){
            if (count($st) < 2 || trim($st[1] ?? '') === '') continue;   // needs both parts
            if ($shown >= 4) break;                                       // max 4 boxes
            echo '<div class="s"><b>'.h($st[0]).'</b><span>'.h($st[1]).'</span></div>';
            $shown++;
        }
        ?>
      </div>
    </div>
    <div class="about-txt reveal">
      <span class="eyebrow"><?= h(lc('land_about_eyebrow','About the Clinic')) ?></span>
      <h2><?= h(lc('land_about_heading','Caring for your smile with')) ?> <span class="grad-text"><?= h(lc('land_about_highlight','a gentle touch')) ?></span></h2>
      <p><?= h(lc('land_about_body','Led by Dr. Maricris Agbisit-Cuison, St. Therese of Carmel Dental Clinic offers general dentistry and orthodontics for the whole family. From routine cleanings to braces and tooth restorations, our team is dedicated to keeping your smile healthy and bright in a warm, welcoming clinic.')) ?></p>
      <ul class="ticks">
        <?php
        $tickDef = [['Experienced, caring dentist you can trust'],['General dentistry and orthodontic services'],['Open Monday to Saturday, easy to reach in Naic']];
        foreach(lc_rows('land_about_ticks',$tickDef) as $tk){ echo '<li><span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 6" stroke-linecap="round" stroke-linejoin="round"/></svg></span> '.h($tk[0]).'</li>'; }
        ?>
      </ul>
    </div>
  </div>
</section>

<!-- ============ FEATURES ============ -->
<section class="pad pad-sky" id="features">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_feat_eyebrow','Features')) ?></span>
      <h2><?= h(lc('land_feat_heading','Everything you need to')) ?> <span class="grad-text"><?= h(lc('land_feat_highlight','manage appointments')) ?></span></h2>
      <p><?= h(lc('land_feat_subtitle','Thoughtful tools that make booking and managing dental visits effortless for everyone.')) ?></p>
    </div>
    <div class="grid g4">
      <?php
      $featIcons = ['rect','clock','bell','user','lock','refresh','history','phone'];
      $featDef = [
        ['Online Appointment Booking','Book a visit anytime from your phone or computer in just a few taps.'],
        ['Real-Time Availability','See open slots instantly and pick the time that works best for you.'],
        ['Appointment Reminders','Get timely confirmations and reminders so you never miss a visit.'],
        ['Patient Account Management','Manage your profile, contact details, and preferences in one place.'],
        ['Secure Login','Your account and health information are protected and private.'],
        ['Easy Rescheduling','Plans changed? Reschedule or cancel in seconds without a phone call.'],
        ['Appointment History','Look back on past visits and treatments whenever you need them.'],
        ['Mobile-Friendly Access','A responsive design that looks and works great on any device.'],
      ];
      foreach(lc_rows('land_features',$featDef) as $i=>$f){ echo '<div class="feature reveal"><div class="ic">'.icon($featIcons[$i]??'tooth').'</div><h3>'.h($f[0]).'</h3><p>'.h($f[1]??'').'</p></div>'; }
      ?>
    </div>
  </div>
</section>

<!-- ============ HOW IT WORKS ============ -->
<section class="pad" id="how">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_how_eyebrow','How It Works')) ?></span>
      <h2><?= h(lc('land_how_heading','Book your visit in')) ?> <span class="grad-text"><?= h(lc('land_how_highlight','four simple steps')) ?></span></h2>
      <p><?= h(lc('land_how_subtitle','From sign-up to your scheduled visit — the whole process takes only minutes.')) ?></p>
    </div>
    <div class="steps">
      <?php
      $stepDef = [
        ['Register','Create your free patient account with a few basic details.'],
        ['Log In','Sign in securely to access your personal dashboard.'],
        ['Book','Choose a service, pick an available date and time, and confirm.'],
        ['Attend','Receive your confirmation and visit us at your scheduled time.'],
      ];
      foreach(lc_rows('land_steps',$stepDef) as $i=>$s){ echo '<div class="step reveal"><div class="num">'.($i+1).'</div><h3>'.h($s[0]).'</h3><p>'.h($s[1]??'').'</p></div>'; }
      ?>
    </div>
  </div>
</section>

<!-- ============ SERVICES ============ -->
<section class="pad pad-sky" id="services">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_svc_eyebrow','Dental Services')) ?></span>
      <h2><?= h(lc('land_svc_heading','Comprehensive care for')) ?> <span class="grad-text"><?= h(lc('land_svc_highlight','every smile')) ?></span></h2>
      <p><?= h(lc('land_svc_subtitle','Book any of our services online through the appointment system.')) ?></p>
    </div>
    <div class="grid g3">
      <?php
      $svcIcons = ['tooth','sparkle','extract','fill','canal','braces','whiten','implant','kid'];
      $svcDef = [
        ['Dental Check-up','Routine exams to keep your teeth and gums healthy.'],
        ['Teeth Cleaning','Professional cleaning to remove plaque and tartar.'],
        ['Tooth Extraction','Safe, gentle removal of damaged or problem teeth.'],
        ['Dental Fillings','Restore decayed teeth with durable, natural-looking fillings.'],
        ['Root Canal Treatment','Save an infected tooth and relieve pain effectively.'],
        ['Braces Consultation','Straighten your smile with an orthodontic assessment.'],
        ['Teeth Whitening','Brighten your smile with a professional whitening treatment.'],
        ['Dental Implants','Replace missing teeth with strong, lasting implants.'],
        ['Pediatric Dentistry','Friendly, specialized dental care for children.'],
      ];
      foreach(lc_rows('land_services',$svcDef) as $i=>$s){ echo '<div class="svc reveal"><div class="ic">'.icon($svcIcons[$i]??'tooth').'</div><div><h3>'.h($s[0]).'</h3><p>'.h($s[1]??'').'</p></div></div>'; }
      ?>
    </div>
  </div>
</section>

<!-- ============ WHY CHOOSE ============ -->
<section class="pad" id="why">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_why_eyebrow','Why Choose Our System')) ?></span>
      <h2><?= h(lc('land_why_heading','Built for')) ?> <span class="grad-text"><?= h(lc('land_why_highlight','convenience and trust')) ?></span></h2>
      <p><?= h(lc('land_why_subtitle','A better appointment experience from the very first click.')) ?></p>
    </div>
    <div class="grid g3">
      <?php
      $whyIcons = ['globe','clock','grid','shield','bolt','smile'];
      $whyDef = [
        ['24/7 Online Access','Book or manage appointments any time of day, from anywhere.'],
        ['Less Waiting Time','Skip the phone queue and reserve your slot in seconds.'],
        ['Convenient Management','Reschedule, cancel, and review visits from one dashboard.'],
        ['Secure Information','Your personal and health data are kept private and safe.'],
        ['Fast Confirmation','Get instant booking confirmations — no waiting to hear back.'],
        ['User-Friendly Interface','A clean, simple design anyone can use with ease.'],
      ];
      foreach(lc_rows('land_why',$whyDef) as $i=>$w){ echo '<div class="why reveal"><div class="ic">'.icon($whyIcons[$i]??'smile').'</div><h3>'.h($w[0]).'</h3><p>'.h($w[1]??'').'</p></div>'; }
      ?>
    </div>
  </div>
</section>

<!-- ============ HMO PROVIDERS ============ -->
<!-- The admin can switch this whole section off in Edit Landing Page
     (useful for clinics that have no HMO partners yet). -->
<?php if (lc('land_hmo_show','0') === '1'): ?>
<section class="pad" id="hmo">
  <div class="wrap hmo-grid">

    <!-- LEFT: the image the admin uploads (or a built-in card if there is none) -->
    <div class="reveal">
      <?php $hmoImg = lc('land_img_hmo',''); ?>
      <?php if ($hmoImg && is_file(__DIR__ . '/' . $hmoImg)): ?>
        <div class="hmo-visual">
          <img src="<?= h($hmoImg) ?>" alt="<?= h(lc('land_hmo_card_title','Accredited HMOs')) ?>">
        </div>
      <?php else: ?>
        <!-- No image uploaded yet, so we build a card from the HMO list instead. -->
        <div class="hmo-card">
          <h3><?= h(lc('land_hmo_card_title','Accredited HMO\'s')) ?></h3>
          <div class="sub"><?= h(lc('land_hmo_card_sub','Enjoy worry-free dental care with your HMO benefits.')) ?></div>
          <div class="hmo-chips">
            <?php foreach (lc_rows('land_hmo_list', [
                    ['Intellicare'],['ValuCare'],['MediCard'],['EastWest Healthcare'],
                    ['Cocolife'],['HealthAssist'],['MedAsia'],['Elite Group'],
                    ['Avega'],['WellCare']]) as $hm): ?>
              <div class="hmo-chip"><?= h($hm[0]) ?></div>
            <?php endforeach; ?>
          </div>
          <div class="hmo-wave"></div>
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: heading, text, tick list and button -->
    <div class="reveal">
      <span class="hmo-title"><?= h(lc('land_hmo_heading','Trusted by Major HMO Providers')) ?></span>
      <p class="hmo-body"><?= h(lc('land_hmo_body','At St. Therese Dental Clinic, we make dental care more accessible and worry-free through our partnerships with major HMO providers. Enjoy professional service, hassle-free transactions, and quality care tailored to your needs.')) ?></p>
      <ul class="hmo-ticks">
        <?php foreach (lc_rows('land_hmo_ticks', [
                ['Accredited by leading HMO providers'],
                ['Smooth and stress-free dental visits'],
                ['Reliable care from trusted professionals']]) as $tk): ?>
          <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?= h($tk[0]) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <a href="<?= h(lc('land_hmo_link','#contact')) ?>" class="btn btn-primary"><?= h(lc('land_hmo_btn','More Info')) ?></a>
    </div>

  </div>
</section>
<?php endif; ?>

<!-- ============ DENTISTS ============ -->
<section class="pad pad-sky" id="dentists">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_doc_eyebrow','Meet Our Dentists')) ?></span>
      <h2><?= h(lc('land_doc_heading','Caring professionals')) ?> <span class="grad-text"><?= h(lc('land_doc_highlight','you can trust')) ?></span></h2>
      <p><?= h(lc('land_doc_subtitle','Experienced dentists dedicated to keeping your smile healthy.')) ?></p>
    </div>
    <div class="docs">
      <?php
      $intros = [
        'Passionate about gentle, patient-first care and beautiful, healthy smiles.',
        'Specializes in aligning smiles with modern, comfortable orthodontic care.',
        'Makes every child feel at ease with friendly, patient pediatric care.',
        'Focused on restorative dentistry that keeps your natural smile strong.',
      ];
      foreach($dentists as $i=>$d){
        $spec = $d['specialty'] ?: 'General Dentistry';
        $yrs  = $expYears[$i % count($expYears)];
        $intro = $intros[$i % count($intros)];
        $photo = $d['photo'] ?? '';
        $avatar = ($photo && is_file(__DIR__ . '/' . $photo))
                ? '<img class="ph" src="' . h($photo) . '" alt="' . h($d['name']) . '" style="object-fit:cover;">'
                : '<div class="ph">' . initials2($d['name']) . '</div>';
        echo '<div class="doc reveal">' . $avatar
           .'<h3>'.htmlspecialchars($d['name']).'</h3>'
           .'<div class="spec">'.htmlspecialchars($spec).'</div>'
           .'<div class="exp">'.$yrs.'+ years experience</div>'
           .'<p>'.$intro.'</p></div>';
      }
      ?>
    </div>
  </div>
</section>

<!-- ============ TESTIMONIALS ============ -->
<section class="pad" id="testimonials">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_test_eyebrow','Testimonials')) ?></span>
      <h2><?= h(lc('land_test_heading','Loved by')) ?> <span class="grad-text"><?= h(lc('land_test_highlight','our patients')) ?></span></h2>
    </div>
    <div class="tcar reveal">
      <div class="ttrack"><div class="tflex" id="tflex">
        <?php
        // REAL patient reviews come first. A patient writes one in their portal and
        // an admin approves it in "Patient Reviews". If none are approved yet, we
        // fall back to the sample text the admin can edit in Edit Landing Page.
        $realReviews = [];
        try {
            $realReviews = $pdo->query(
                "SELECT r.name, r.rating, r.comment, r.created_at, u.photo
                 FROM reviews r LEFT JOIN users u ON r.user_id = u.id
                 WHERE r.status = 'Approved'
                 ORDER BY r.created_at DESC LIMIT 8"
            )->fetchAll();
        } catch (Throwable $e) { $realReviews = []; }

        if ($realReviews) {
            foreach ($realReviews as $r) {
                $stars  = str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']);
                $avatar = (!empty($r['photo']) && is_file(__DIR__ . '/' . $r['photo']))
                        ? '<img class="av" src="' . h($r['photo']) . '" alt="" style="object-fit:cover;">'
                        : '<div class="av">' . h(initials2($r['name'])) . '</div>';
                echo '<div class="tcard"><div class="stars">' . $stars . '</div>'
                   . '<p class="quote">"' . h($r['comment']) . '"</p>'
                   . '<div class="who">' . $avatar . '<div><b>' . h($r['name']) . '</b>'
                   . '<span>Patient since ' . date('Y', strtotime($r['created_at'])) . '</span></div></div></div>';
            }
        } else {
            $testDef = [
              ['Booking an appointment used to mean waiting on the phone. Now I do it in a minute from my couch. The reminders are a lifesaver!','Jasmine Dela Cruz','Patient since 2024'],
              ['So easy to use. I rescheduled my cleaning in seconds when work got busy, and my whole visit history is right there.','Mark Reyes','Patient since 2023'],
              ['I booked my daughter\'s checkup and mine at the same time. Clean, fast, and I always know exactly when our visits are.','Andrea Lim','Patient since 2025'],
            ];
            foreach (lc_rows('land_testimonials', $testDef) as $t) {
              echo '<div class="tcard"><div class="stars">★★★★★</div><p class="quote">"'.h($t[0]).'"</p>'
                 .'<div class="who"><div class="av">'.h(initials2($t[1]??'')).'</div><div><b>'.h($t[1]??'').'</b><span>'.h($t[2]??'').'</span></div></div></div>';
            }
        }
        ?>
      </div></div>
      <div class="tnav">
        <button class="tarrow" id="tprev" aria-label="Previous"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
        <div class="tdots" id="tdots"></div>
        <button class="tarrow" id="tnext" aria-label="Next"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      </div>
    </div>
  </div>
</section>

<!-- ============ FAQ ============ -->
<section class="pad pad-sky" id="faq">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_faq_eyebrow','FAQ')) ?></span>
      <h2><?= h(lc('land_faq_heading','Frequently asked')) ?> <span class="grad-text"><?= h(lc('land_faq_highlight','questions')) ?></span></h2>
    </div>
    <div class="faq">
      <?php
      $faqDef=[
        ['How do I create an account?','Click "Register" at the top of the page, enter your name, email, and a password, then verify your email. Your patient account is ready right away.'],
        ['How do I book an appointment?','Log in, click "Book Appointment," choose your service, pick an available date and time, and confirm. You will get a confirmation instantly.'],
        ['Can I reschedule my appointment?','Yes. From your dashboard you can reschedule or cancel an appointment in a few clicks — no phone call needed.'],
        ['Is my information secure?','Absolutely. Your login is protected and your personal and health information is kept private and secure.'],
        ['What happens after booking?','Your appointment is added to your dashboard and marked pending until the clinic confirms it. You will see the status update and reminders as your visit approaches.'],
      ];
      foreach(lc_rows('land_faqs',$faqDef) as $q){
        echo '<div class="qa reveal"><button onclick="toggleFaq(this)"><span>'.h($q[0]).'</span>'
           .'<span class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>'
           .'<div class="ans"><p>'.h($q[1]??'').'</p></div></div>';
      }
      ?>
    </div>
  </div>
</section>

<!-- ============ CONTACT ============ -->
<section class="pad" id="contact">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow"><?= h(lc('land_contact_eyebrow','Contact')) ?></span>
      <h2><?= h(lc('land_contact_heading','Get in')) ?> <span class="grad-text"><?= h(lc('land_contact_highlight','touch')) ?></span></h2>
      <p><?= h(lc('land_contact_subtitle',"Questions before booking? We're here to help.")) ?></p>
    </div>
    <div class="contact-grid">
      <div class="cinfo reveal">
        <div class="crow"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg></span><div><h4>Clinic Address</h4><p><?= h(lc('land_contact_address','123 Dental St., Naic, Cavite, Philippines')) ?></p></div></div>
        <div class="crow"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.7a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.7 2Z"/></svg></span><div><h4>Contact Number</h4><p><?= h(lc('land_contact_phone','(046) 123-4567')) ?></p></div></div>
        <div class="crow"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="3"/><path d="m3 6 9 7 9-7" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><h4>Email Address</h4><p><?= h(lc('land_contact_email','hello@stthereesedental.ph')) ?></p></div></div>
        <div class="crow"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><h4>Office Hours</h4><p><?= h(lc('land_contact_hours','Mon–Sat · 9:00 AM – 5:00 PM')) ?></p></div></div>
        <div class="socials">
          <?php $fbLink = function_exists('facebook_url') ? facebook_url(lc('land_contact_facebook',''))[0] : ''; ?>
          <a href="<?= h($fbLink !== '' ? $fbLink : '#') ?>" aria-label="Facebook"<?= $fbLink !== '' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7A10 10 0 0 0 22 12Z"/></svg></a>
          <a href="#" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg></a>
          <a href="#" aria-label="X"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.9 2H22l-7 8 8.2 12h-6.4l-5-6.6L6 22H2.9l7.5-8.6L2.5 2h6.6l4.5 6L18.9 2Z"/></svg></a>
        </div>
      </div>
      <div class="cmap reveal">
        <?php
          // The map is editable in Edit Landing Page -> Contact. The saved value
          // may be EITHER a ready-made Google "embed" URL, OR simply an address
          // or a "latitude,longitude" pair, which we turn into an embed URL.
          // Leave it blank to show the plain placeholder instead of a map.
          $mapVal = trim(lc('land_map', '14.2845224,120.9999204'));
          $mapSrc = '';
          if ($mapVal !== '') {
              $mapSrc = (stripos($mapVal, 'http') === 0)
                  ? $mapVal
                  : 'https://maps.google.com/maps?q=' . urlencode($mapVal) . '&z=16&hl=en&output=embed';
          }
        ?>
        <?php if ($mapSrc !== ''): ?>
          <iframe src="<?= h($mapSrc) ?>" title="Clinic location on Google Maps"
                  allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        <?php else: ?>
          <div class="grid-lines"></div>
          <div class="pin">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            <b>St. Therese of Carmel Dental Clinic</b><br>
            <span style="font-size:.85rem;color:var(--muted)"><?= h(lc('land_contact_address','General Mariano Alvarez, Cavite')) ?></span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============ CTA ============ -->
<section class="pad" id="cta" style="padding-top:20px">
  <div class="wrap">
    <div class="cta-band reveal">
      <h2><?= h(lc('land_cta_heading','Ready to schedule your dental appointment?')) ?></h2>
      <p><?= h(lc('land_cta_text','Create an account or log in to book your next appointment quickly and conveniently.')) ?></p>
      <div class="cta-actions">
        <a href="login?mode=register" class="btn btn-white">Register</a>
        <a href="login?mode=signin" class="btn btn-clear">Login</a>
        <a href="book" class="btn btn-clear">Book Appointment</a>
      </div>
    </div>
  </div>
</section>

<!-- ============ FOOTER ============ -->
<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="brand"><span class="logo"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2.5c-2 0-2.8 1-4.4 1-1.3 0-2.6-.6-3.3.4-.9 1.2-.3 3.6.2 5.7.3 1.3.2 2 .6 3.6.5 2 .9 4 1.7 5.6.4.8 1 1.7 1.7 1.7.9 0 1-1.2 1.2-2.3.2-1 .4-1.7 1.3-1.7s1.1.7 1.3 1.7c.2 1.1.3 2.3 1.2 2.3.7 0 1.3-.9 1.7-1.7.8-1.6 1.2-3.6 1.7-5.6.4-1.6.3-2.3.6-3.6.5-2.1 1.1-4.5.2-5.7-.7-1-2-.4-3.3-.4-1.6 0-2.4-1-4.4-1Z" fill="#fff"/></svg></span><span>St. Therese<small>Appointment System</small></span></div>
        <p class="desc"><?= h(lc('land_footer_desc','Making dental appointments simple, fast, and convenient for patients and clinic staff alike.')) ?></p>
      </div>
      <div>
        <h5>Quick Links</h5>
        <ul>
          <li><a href="#home">Home</a></li>
          <li><a href="#features">Features</a></li>
          <li><a href="#how">How It Works</a></li>
          <li><a href="#about">About</a></li>
          <li><a href="book">Book Appointment</a></li>
        </ul>
      </div>
      <div>
        <h5>Services</h5>
        <ul>
          <li><a href="#services">Dental Check-up</a></li>
          <li><a href="#services">Teeth Cleaning</a></li>
          <li><a href="#services">Braces Consultation</a></li>
          <li><a href="#services">Teeth Whitening</a></li>
          <li><a href="#services">Pediatric Dentistry</a></li>
        </ul>
      </div>
      <div>
        <h5>Contact</h5>
        <div class="cinline"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg> <?= h(lc('land_contact_address','123 Dental St., Naic, Cavite')) ?></div>
        <div class="cinline"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.1 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.7a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.7 2Z"/></svg> <?= h(lc('land_contact_phone','(046) 123-4567')) ?></div>
        <div class="cinline"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="3"/><path d="m3 6 9 7 9-7" stroke-linecap="round" stroke-linejoin="round"/></svg> <?= h(lc('land_contact_email','hello@stthereesedental.ph')) ?></div>
      </div>
    </div>
    <div class="foot-bottom">
      <div>© <?= date('Y') ?> St. Therese Dental Clinic. All rights reserved.</div>
      <div class="lk"><a href="#" onclick="showLandingPolicy('privacy');return false;">Privacy Policy</a><a href="#" onclick="showLandingPolicy('policy');return false;">Terms &amp; Conditions</a></div>
    </div>
  </div>
</footer>

<!-- ===== Privacy Policy / Terms & Conditions (same content as the booking page) ===== -->
<div id="landingPolicy" style="display:none;position:fixed;inset:0;z-index:9999;">
  <div onclick="closeLandingPolicy()" style="position:absolute;inset:0;background:rgba(6,28,27,.55);"></div>
  <div style="position:relative;max-width:680px;width:calc(100% - 24px);margin:5vh auto;background:var(--white);border-radius:16px;
              max-height:90vh;display:flex;flex-direction:column;box-shadow:0 30px 80px rgba(0,0,0,.35);">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 26px;
                border-bottom:1px solid #e6efee;">
      <h3 id="lp-title" style="margin:0;font-size:1.3rem;color:var(--ink);">Terms &amp; Conditions</h3>
      <button onclick="closeLandingPolicy()" aria-label="Close"
              style="border:none;background:#f1f5f5;width:34px;height:34px;border-radius:50%;font-size:1.2rem;cursor:pointer;color:#556;">&times;</button>
    </div>
    <div style="padding:22px 26px;overflow-y:auto;font-size:.93rem;line-height:1.7;color:#3f5350;">

      <div id="lp-policy">
        <?= policy_html(lc('land_terms', policy_defaults()['terms']), 'h4') ?>
      </div>

      <div id="lp-privacy" style="display:none;">
        <?= policy_html(lc('land_privacy', policy_defaults()['privacy']), 'h4') ?>
      </div>

    </div>
    <div style="padding:14px 26px;border-top:1px solid #e6efee;text-align:right;">
      <button onclick="closeLandingPolicy()" class="btn btn-primary">Close</button>
    </div>
  </div>
</div>

<script>
// Open the Privacy Policy / Terms modal in the footer.
function showLandingPolicy(which){
  document.getElementById('lp-policy').style.display  = (which === 'policy')  ? 'block' : 'none';
  document.getElementById('lp-privacy').style.display = (which === 'privacy') ? 'block' : 'none';
  document.getElementById('lp-title').textContent =
      (which === 'privacy') ? 'Privacy Policy' : 'Terms & Conditions';
  document.getElementById('landingPolicy').style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function closeLandingPolicy(){
  document.getElementById('landingPolicy').style.display = 'none';
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeLandingPolicy(); });

// sticky nav shadow
var nav=document.getElementById('nav');
addEventListener('scroll',function(){ nav.classList.toggle('scrolled',scrollY>10); });

// mobile menu
document.getElementById('ham').addEventListener('click',function(){ nav.classList.toggle('open'); });
document.querySelectorAll('#navLinks a, .nav-actions a').forEach(function(a){
  a.addEventListener('click',function(){ nav.classList.remove('open'); });
});

// active section highlight
var links=[].slice.call(document.querySelectorAll('#navLinks a'));
var map={}; links.forEach(function(a){ map[a.getAttribute('href').slice(1)]=a; });
var secs=['home','features','services','about','contact'].map(function(id){return document.getElementById(id);});
var so=new IntersectionObserver(function(es){
  es.forEach(function(e){
    if(e.isIntersecting){ links.forEach(function(l){l.classList.remove('active');}); if(map[e.target.id])map[e.target.id].classList.add('active'); }
  });
},{rootMargin:'-45% 0px -50% 0px'});
secs.forEach(function(s){ if(s) so.observe(s); });

// reveal on scroll
var ro=new IntersectionObserver(function(es){
  es.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); ro.unobserve(e.target); } });
},{threshold:.12});
document.querySelectorAll('.reveal').forEach(function(el){ ro.observe(el); });

// FAQ accordion
function toggleFaq(btn){
  var qa=btn.parentElement, ans=qa.querySelector('.ans'), open=qa.classList.contains('open');
  document.querySelectorAll('.qa').forEach(function(x){ x.classList.remove('open'); x.querySelector('.ans').style.maxHeight=null; });
  if(!open){ qa.classList.add('open'); ans.style.maxHeight=ans.scrollHeight+'px'; }
}

// testimonial carousel
var flex=document.getElementById('tflex'), dots=document.getElementById('tdots');
var n=flex.children.length, cur=0, timer;
for(var i=0;i<n;i++){ var d=document.createElement('button'); d.className='tdot'+(i===0?' on':''); (function(idx){d.onclick=function(){go(idx);};})(i); dots.appendChild(d); }
function go(i){ cur=(i+n)%n; flex.style.transform='translateX(-'+(cur*100)+'%)'; [].forEach.call(dots.children,function(x,ix){x.classList.toggle('on',ix===cur);}); reset(); }
document.getElementById('tnext').onclick=function(){go(cur+1);};
document.getElementById('tprev').onclick=function(){go(cur-1);};
function reset(){ clearInterval(timer); timer=setInterval(function(){go(cur+1);},6000); }
reset();
</script>

<?php
// ---------- Inline SVG icon helper (line style, 24x24) ----------
function icon($k){
  $I=[
    'rect'=>'<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18" stroke-linecap="round"/>',
    'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round"/>',
    'bell'=>'<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.7 21a2 2 0 0 1-3.4 0" stroke-linecap="round"/>',
    'user'=>'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke-linecap="round"/><circle cx="12" cy="7" r="4"/>',
    'lock'=>'<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round"/>',
    'refresh'=>'<path d="M3 12a9 9 0 0 1 15-6.7L21 8" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 3v5h-5" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 21v-5h5" stroke-linecap="round" stroke-linejoin="round"/>',
    'history'=>'<path d="M3 3v5h5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 7v5l4 2" stroke-linecap="round" stroke-linejoin="round"/>',
    'phone'=>'<rect x="7" y="2" width="10" height="20" rx="3"/><path d="M11 18h2" stroke-linecap="round"/>',
    'tooth'=>'<path d="M12 5.5c-1.6 0-2.2.8-3.6.8-1 0-2-.5-2.6.3-.7 1-.2 2.8.2 4.4.2 1 .1 1.5.4 2.8.4 1.6.7 3.1 1.3 4.4.3.6.8 1.3 1.3 1.3.7 0 .8-.9.9-1.8.2-.8.3-1.3 1-1.3s.8.5 1 1.3c.1.9.2 1.8.9 1.8.5 0 1-.7 1.3-1.3.6-1.3.9-2.8 1.3-4.4.3-1.2.2-1.8.4-2.8.4-1.6.9-3.4.2-4.4-.6-.8-1.6-.3-2.6-.3-1.4 0-2-.8-3.6-.8Z" stroke-linejoin="round"/>',
    'sparkle'=>'<path d="M12 3v4M12 17v4M3 12h4M17 12h4" stroke-linecap="round"/><path d="M12 8l1.5 2.5L16 12l-2.5 1.5L12 16l-1.5-2.5L8 12l2.5-1.5L12 8Z" stroke-linejoin="round"/>',
    'extract'=>'<path d="M12 5.5c-1.6 0-2.2.8-3.6.8-1 0-2-.5-2.6.3-.7 1-.2 2.8.2 4.4.2 1 .1 1.5.4 2.8.4 1.6.7 3.1 1.3 4.4.3.6.8 1.3 1.3 1.3.7 0 .8-.9.9-1.8.2-.8.3-1.3 1-1.3s.8.5 1 1.3c.1.9.2 1.8.9 1.8.5 0 1-.7 1.3-1.3.6-1.3.9-2.8 1.3-4.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 4l4 4M20 4l-4 4" stroke-linecap="round"/>',
    'fill'=>'<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18" fill="currentColor" stroke="none" opacity=".18"/><path d="M9 12h6M12 9v6" stroke-linecap="round"/>',
    'canal'=>'<path d="M12 2v9m0 0-3 9m3-9 3 9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="6" r="3"/>',
    'braces'=>'<path d="M4 8v8M20 8v8" stroke-linecap="round"/><rect x="7" y="9" width="10" height="6" rx="2"/><path d="M10 9v6M14 9v6"/>',
    'whiten'=>'<path d="M12 3l2.2 4.5L19 8l-3.5 3.4.8 4.9L12 14l-4.3 2.3.8-4.9L5 8l4.8-.5L12 3Z" stroke-linejoin="round"/>',
    'implant'=>'<path d="M12 2v13" stroke-linecap="round"/><path d="M8 6l4-3 4 3" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 15h6l-1.2 6H10.2L9 15Z" stroke-linejoin="round"/>',
    'kid'=>'<circle cx="12" cy="9" r="4"/><path d="M6 21c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke-linecap="round"/><path d="M10 8.5h.01M14 8.5h.01" stroke-linecap="round"/>',
    'globe'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18" />',
    'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    'shield'=>'<path d="M12 2l8 3v6c0 5-3.4 8.5-8 11-4.6-2.5-8-6-8-11V5l8-3Z" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>',
    'bolt'=>'<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z" stroke-linejoin="round"/>',
    'smile'=>'<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2" stroke-linecap="round"/><path d="M9 9h.01M15 9h.01" stroke-linecap="round"/>',
  ];
  $p=$I[$k] ?? $I['tooth'];
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">'.$p.'</svg>';
}
?>
</body>
</html>
