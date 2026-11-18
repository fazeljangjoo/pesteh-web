<?php
declare(strict_types=1);
/**
 * پشتیبانی — پنل ادمین برنامه (backend/admin/support.php)
 * گفتگوهای assistant_threads / assistant_messages را نشان می‌دهد و پاسخ ادمین را با sender='support' ثبت می‌کند.
 * احراز هویت و CSRF همان پنل ادمین است.
 */
require __DIR__ . '/functions.php';
require_login();

$pdo = db();
$flash = '';
$err = '';

// ---------- اقدام‌ها (POST + CSRF) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $tid = (int)($_POST['thread_id'] ?? 0);
    try {
        if ($tid <= 0) throw new RuntimeException('گفتگو نامعتبر است.');
        if ($action === 'reply') {
            $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? '')));
            $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
            if ($body === '') throw new RuntimeException('پیام خالی است.');
            if (mb_strlen($body) > 2000) throw new RuntimeException('پیام بیش از ۲۰۰۰ حرف است.');
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT customer_id FROM assistant_threads WHERE id = ? FOR UPDATE');
            $st->execute([$tid]);
            $cid = (int)($st->fetchColumn() ?: 0);
            if ($cid <= 0) throw new RuntimeException('گفتگو پیدا نشد.');
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO assistant_messages (thread_id, customer_id, sender, body, created_at) VALUES (?, ?, 'support', ?, ?)")
                ->execute([$tid, $cid, $body, $now]);
            // پیام ادمین: برای مشتری شمارندهٔ «خوانده‌نشده» بالا می‌رود، برای ادمین صفر می‌شود، گفتگو باز می‌شود
            $pdo->prepare("UPDATE assistant_threads SET last_msg_at = ?, unread_user = unread_user + 1, unread_support = 0, status = 'open' WHERE id = ?")
                ->execute([$now, $tid]);
            $pdo->commit();
            header('Location: support.php?t=' . $tid . '&ok=1');
            exit;
        }
        if ($action === 'close' || $action === 'open') {
            $status = $action === 'close' ? 'closed' : 'open';
            $pdo->prepare('UPDATE assistant_threads SET status = ? WHERE id = ?')->execute([$status, $tid]);
            header('Location: support.php?t=' . $tid);
            exit;
        }
        throw new RuntimeException('اقدام نامعتبر است.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('support.php: ' . $e->getMessage());
        $err = $e instanceof RuntimeException ? $e->getMessage() : 'خطا در ثبت. دوباره تلاش کنید.';
    }
}

if (isset($_GET['ok'])) $flash = 'پاسخ ثبت شد.';

// ---------- داده‌ها ----------
$tid = (int)($_GET['t'] ?? 0);
$threads = [];
$thread = null;
$messages = [];
try {
    $threads = $pdo->query("SELECT t.id, t.status, t.last_msg_at, t.unread_support, c.name, c.phone
        FROM assistant_threads t JOIN customers c ON c.id = t.customer_id
        ORDER BY (t.unread_support > 0) DESC, t.last_msg_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    if ($tid > 0) {
        $st = $pdo->prepare('SELECT t.id, t.customer_id, t.status, c.name, c.phone FROM assistant_threads t JOIN customers c ON c.id = t.customer_id WHERE t.id = ?');
        $st->execute([$tid]);
        $thread = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($thread) {
            $st = $pdo->prepare('SELECT id, sender, body, created_at FROM assistant_messages WHERE thread_id = ? ORDER BY id DESC LIMIT 200');
            $st->execute([$tid]);
            $messages = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
            // خوانده شد: پیام‌های مشتری خوانده‌شده و شمارندهٔ ادمین صفر
            $pdo->prepare("UPDATE assistant_messages SET read_at = ? WHERE thread_id = ? AND sender = 'user' AND read_at IS NULL")
                ->execute([date('Y-m-d H:i:s'), $tid]);
            $pdo->prepare('UPDATE assistant_threads SET unread_support = 0 WHERE id = ?')->execute([$tid]);
        }
    }
} catch (Throwable $e) {
    error_log('support.php read: ' . $e->getMessage());
    $err = $err ?: 'جدول گفتگوها هنوز ساخته نشده یا خطایی رخ داده است.';
}
$csrf = csrf_token();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>پشتیبانی — پنل مدیریت</title>
<style>
body{font-family:Vazirmatn,Tahoma,"Segoe UI",Arial,sans-serif;margin:0;background:#f4f6ef;color:#1d2a10;line-height:1.7}
header{background:#3f5f1c;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
header a{color:#fff}
main{display:flex;gap:16px;padding:16px;flex-wrap:wrap;align-items:flex-start}
.list{flex:1 1 280px;max-width:380px;background:#fff;border-radius:10px;overflow:hidden}
.chat{flex:2 1 360px;background:#fff;border-radius:10px;padding:14px}
.item{display:block;padding:10px 12px;border-bottom:1px solid #e5ead8;text-decoration:none;color:inherit}
.item.on{background:#eef5df}
.badge{background:#c0392b;color:#fff;border-radius:999px;padding:0 8px;font-size:12px}
.msg{margin:8px 0;padding:8px 12px;border-radius:10px;max-width:85%;white-space:normal;word-wrap:break-word}
.m-user{background:#eef2e6;margin-left:auto}
.m-support{background:#dbe9b8;margin-right:auto}
.meta{font-size:11px;color:#667;display:block}
textarea{width:100%;min-height:90px;border:1px solid #bfcaa6;border-radius:8px;padding:8px;font:inherit}
button{background:#5B7F2A;color:#fff;border:0;border-radius:8px;padding:8px 16px;font:inherit;cursor:pointer}
.ok{background:#e7f5e0;padding:8px;border-radius:8px;margin-bottom:8px}
.er{background:#fbe5e1;padding:8px;border-radius:8px;margin-bottom:8px}
</style>
</head>
<body>
<header>
  <strong>پشتیبانی مشتری‌ها</strong>
  <a href="index.php">بازگشت به پنل</a>
</header>
<main>
  <section class="list">
    <?php if (!$threads): ?>
      <p style="padding:12px">هنوز گفتگویی ثبت نشده است.</p>
    <?php endif; ?>
    <?php foreach ($threads as $t): ?>
      <a class="item<?= ((int)$t['id'] === $tid) ? ' on' : '' ?>" href="support.php?t=<?= (int)$t['id'] ?>">
        <strong><?= h((string)($t['name'] ?? '')) ?></strong>
        <span class="meta"><?= h(display_phone((string)$t['phone'])) ?> · <?= h((string)$t['last_msg_at']) ?> · <?= $t['status'] === 'closed' ? 'بسته' : 'باز' ?></span>
        <?php if ((int)$t['unread_support'] > 0): ?><span class="badge"><?= (int)$t['unread_support'] ?> جدید</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </section>

  <section class="chat">
    <?php if ($flash): ?><div class="ok"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="er"><?= h($err) ?></div><?php endif; ?>
    <?php if (!$thread): ?>
      <p>یک گفتگو را از فهرست انتخاب کنید.</p>
    <?php else: ?>
      <h3 style="margin-top:0"><?= h((string)($thread['name'] ?? '')) ?> — <?= h(display_phone((string)$thread['phone'])) ?></h3>
      <div>
        <?php foreach ($messages as $m): ?>
          <div class="msg <?= $m['sender'] === 'support' ? 'm-support' : 'm-user' ?>">
            <?= nl2br(h((string)$m['body'])) ?>
            <span class="meta"><?= $m['sender'] === 'support' ? 'پشتیبانی' : 'مشتری' ?> · <?= h((string)$m['created_at']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" action="support.php" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
        <input type="hidden" name="action" value="reply">
        <textarea name="body" maxlength="2000" required placeholder="پاسخ خود را بنویسید"></textarea>
        <button type="submit">ارسال پاسخ</button>
      </form>
      <form method="post" action="support.php" style="margin-top:8px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
        <input type="hidden" name="action" value="<?= $thread['status'] === 'closed' ? 'open' : 'close' ?>">
        <button type="submit" style="background:#777"><?= $thread['status'] === 'closed' ? 'باز کردن گفتگو' : 'بستن گفتگو' ?></button>
      </form>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
