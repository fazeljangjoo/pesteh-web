/* ===========================================================
   قیمت اشتراک‌ها — از پنل مدیریت (/admin) تنظیم می‌شود؛ این فایل را ویرایش نکنید.
   داده از api/config.php خوانده می‌شود. اگر نشد، «تماس برای قیمت» نمایش داده می‌شود.
   =========================================================== */
(function () {
  var cur = 12, cfg = null, off = 0, tier = 'green';

  function nowSec() { return Math.floor(Date.now() / 1000) + off; }

  function paint() {
    if (!window.PB) return;
    ['rial', 'fx'].forEach(function (pl) {
      var box = document.querySelector('[data-price="' + pl + '"]');
      if (!box) return;
      PB.render(box, cfg ? PB.calc(cfg, pl, cur, nowSec(), tier) : null, nowSec());
    });
    // برچسب درصد تخفیف روی دکمه‌های مدت (بهترین تخفیف هر مدت میان دو پلن)
    [].forEach.call(document.querySelectorAll('[data-dur]'), function (b) {
      var m = +b.getAttribute('data-dur'), best = 0;
      if (cfg) ['rial', 'fx'].forEach(function (pl) { var r = PB.calc(cfg, pl, m, nowSec(), tier); if (r && r.total > best) best = r.total; });
      var t = b.querySelector('.dt');
      if (best > 0) {
        if (!t) { t = document.createElement('span'); t.className = 'dt'; b.appendChild(t); }
        t.textContent = PB.fa(best) + '٪ تخفیف';
      } else if (t) t.remove();
    });
    document.documentElement.setAttribute('data-hasprice', cfg ? '1' : '0');
  }

  function bind() {
    var btns = [].slice.call(document.querySelectorAll('[data-dur]'));
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        cur = +b.getAttribute('data-dur');
        btns.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        paint();
      });
    });
    // S22.T22.4: کلید سبز / طلایی (قیمت هر نسخه از cfg.plans یا cfg.tiers.gold)
    var tb = [].slice.call(document.querySelectorAll('[data-tier]'));
    tb.forEach(function (b) {
      b.addEventListener('click', function () {
        tier = b.getAttribute('data-tier') === 'gold' ? 'gold' : 'green';
        tb.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        [].forEach.call(document.querySelectorAll('[data-gold]'), function (e) { e.hidden = tier !== 'gold'; });
        [].forEach.call(document.querySelectorAll('[data-green]'), function (e) { e.hidden = tier === 'gold'; });
        paint();
      });
    });
    var pk = [].slice.call(document.querySelectorAll('[data-pick]')), cs = [].slice.call(document.querySelectorAll('.pc'));
    pk.forEach(function (b) {
      b.addEventListener('click', function () {
        pk.forEach(function (x) { x.classList.toggle('on', x === b); });
        cs.forEach(function (c) { c.classList.toggle('pick', c.getAttribute('data-plan') === b.getAttribute('data-pick')); });
      });
    });
    if (!window.PB || !document.querySelector('[data-price]')) return;
    PB.load().then(function (c) {
      cfg = c; off = (c.now || 0) - Math.floor(Date.now() / 1000);
      paint();
      setInterval(paint, 60000); // شمارندهٔ پایان تخفیف
    }).catch(function () { paint(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind); else bind();
})();
