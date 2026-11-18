<?php
require __DIR__ . '/lib.php';
require __DIR__ . '/config.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
pb_session();

$adm = pb_read('admin', []);
$setup = empty($adm['hash']);
$err = '';

if (isset($_GET['out'])) { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !pb_authed()) {
    $wait = pb_blocked();
    if ($wait > 0) {
        $err = 'تلاش‌های ناموفق زیاد بود. حدود ' . ceil($wait / 60) . ' دقیقهٔ دیگر دوباره امتحان کنید.';
    } elseif ($setup) {
        $tok = (string) ($_POST['token'] ?? ''); $p1 = (string) ($_POST['p1'] ?? ''); $p2 = (string) ($_POST['p2'] ?? '');
        if (!hash_equals(PB_SETUP_TOKEN, trim($tok))) { pb_fail(); $err = 'کد راه‌اندازی درست نیست.'; }
        elseif (mb_strlen($p1) < 8) $err = 'رمز باید دست‌کم ۸ نویسه باشد.';
        elseif ($p1 !== $p2) $err = 'رمز و تکرار آن یکسان نیستند.';
        else {
            pb_write('admin', ['hash' => password_hash($p1, PASSWORD_DEFAULT)]);
            pb_clear_fails(); session_regenerate_id(true); $_SESSION['ok'] = 1;
            header('Location: ./'); exit;
        }
    } else {
        if (password_verify((string) ($_POST['pw'] ?? ''), $adm['hash'])) {
            pb_clear_fails(); session_regenerate_id(true); $_SESSION['ok'] = 1;
            header('Location: ./'); exit;
        }
        pb_fail(); usleep(400000); $err = 'رمز اشتباه است.';
    }
}

$base = '../assets/';
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#1c2d10">
<title>پنل مدیریت پسته بازار</title>
<link rel="icon" type="image/png" href="../assets/pesteh-bazar-icon-256.png">
<link rel="stylesheet" href="admin.css?v=1">
<link rel="stylesheet" href="../assets/pricing-card.css?v=1">
<link rel="stylesheet" href="../assets/announce.css?v=1">
</head>
<?php if (!pb_authed()): ?>
<body class="login">
<main class="lg">
  <div class="lg-card">
    <img class="lg-logo" src="../assets/pesteh-bazar-icon-256.png" alt="" width="72" height="72">
    <h1>پنل مدیریت پسته بازار</h1>
    <p class="lg-sub"><?= $setup ? 'بار اول است. یک رمز برای پنل بسازید.' : 'برای ورود رمز را وارد کنید.' ?></p>
    <?php if ($err): ?><div class="lg-err" role="alert"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?php if ($setup): ?>
        <label>کد راه‌اندازی<input name="token" dir="ltr" required placeholder="داخل فایل admin/config.php"></label>
        <label>رمز جدید (دست‌کم ۸ نویسه)<input type="password" name="p1" dir="ltr" minlength="8" required autocomplete="new-password"></label>
        <label>تکرار رمز<input type="password" name="p2" dir="ltr" minlength="8" required autocomplete="new-password"></label>
        <button class="btn-main" type="submit">ساخت رمز و ورود</button>
      <?php else: ?>
        <label>رمز عبور<input type="password" name="pw" dir="ltr" required autofocus autocomplete="current-password"></label>
        <button class="btn-main" type="submit">ورود</button>
      <?php endif; ?>
    </form>
  </div>
</main>
</body>
</html>
<?php exit; endif; ?>
<body class="app">
<div id="toast" role="status" aria-live="polite"></div>

<aside class="side">
  <a class="side-brand" href="../" target="_blank" rel="noopener"><img src="../assets/pesteh-bazar-icon-256.png" alt="" width="44" height="44"><span>پسته بازار<small>پنل مدیریت</small></span></a>
  <nav class="tabs" id="tabs">
    <button data-tab="prices" class="on"><i>💰</i><span>قیمت پلن‌ها</span></button>
    <button data-tab="camps"><i>🎉</i><span>تخفیف مناسبتی</span></button>
    <button data-tab="msgs"><i>📣</i><span>پیام‌های سایت</span></button>
    <button data-tab="set"><i>⚙️</i><span>تنظیمات</span></button>
  </nav>
  <a class="side-site" href="../" target="_blank" rel="noopener">مشاهده سایت ↗</a>
</aside>

<div class="main">
  <header class="top">
    <div><h1 id="ttl">قیمت پلن‌ها</h1><p id="sub" class="top-sub"></p></div>
    <div class="savebar">
      <span id="dirty" class="dirty" hidden>تغییرات ذخیره‌نشده</span>
      <button id="save" class="btn-main" disabled>ذخیره و انتشار در سایت</button>
    </div>
  </header>

  <div id="loading" class="loading">در حال بارگذاری…</div>
  <div id="view"></div>
</div>

<script>window.PB_CSRF = <?= json_encode(pb_csrf()) ?>;</script>
<script>window.PB_ADMIN = true;</script>
<script src="../assets/pb-calc.js?v=1"></script>
<script src="../assets/announce.js?v=1"></script>
<script src="admin.js?v=1"></script>
</body>
</html>
