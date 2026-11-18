/* ===========================================================
   منطق مشترک قیمت و تخفیف — هم سایت و هم پنل مدیریت از همین فایل استفاده می‌کنند
   ساختار تنظیمات:
   cfg.plans.rial / cfg.plans.fx : { base: قیمت ماهانه (تومان)، special: {3,6,12} قیمت دلخواه با تخفیف }
   cfg.campaigns : [{ title, percent, plans:['rial','fx'], durations:[3,6,12], active, end }]
   =========================================================== */
(function (root) {
  var faNum = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 0 });
  var PB = {};

  PB.fa = function (n) { return faNum.format(n); };
  PB.money = function (n) { return faNum.format(n); };

  // عدد فارسی/عربی/انگلیسی با جداکننده را به عدد تبدیل می‌کند؛ خالی یا نامعتبر => null
  PB.parse = function (s) {
    if (s == null) return null;
    if (typeof s === 'number') return isFinite(s) && s > 0 ? Math.round(s) : null;
    s = String(s).replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
      .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; })
      .replace(/[^\d]/g, '');
    if (!s) return null;
    var n = parseInt(s, 10);
    return n > 0 ? n : null;
  };

  // کمپینِ فعال و مرتبط با این پلن و مدت؛ اگر چند تا بود بیشترین درصد
  PB.campaignFor = function (cfg, plan, months, nowSec, tier) {
    var best = null;
    (cfg.campaigns || []).forEach(function (c) {
      if (!c || !c.active || !(c.percent > 0)) return;
      if (c.end && nowSec && c.end <= nowSec) return;
      if (c.start && nowSec && c.start > nowSec) return;
      if ((c.plans || []).indexOf(plan) < 0) return;
      if ((c.tiers || ['green']).indexOf(tier || 'green') < 0) return; // کمپین قدیمی بدون tiers = فقط سبز
      if ((c.durations || []).indexOf(months) < 0) return;
      if (!best || c.percent > best.percent) best = c;
    });
    return best;
  };

  // محاسبهٔ کامل یک پلن و یک مدت
  PB.calc = function (cfg, plan, months, nowSec, tier) {
    // tier: 'green' (پیش‌فرض، cfg.plans) | 'gold' (cfg.tiers.gold)
    var p = tier === 'gold' ? (((cfg.tiers || {}).gold || {})[plan] || {}) : ((cfg.plans || {})[plan] || {});
    var base = PB.parse(p.base);
    if (!base) return null;
    var normal = base * months;
    var sp = PB.parse((p.special || {})[months]);
    var special = sp && sp < normal ? sp : null;
    var afterSpecial = special || normal;
    var camp = PB.campaignFor(cfg, plan, months, nowSec, tier);
    var fin = camp ? Math.round(afterSpecial * (1 - camp.percent / 100) / 1000) * 1000 : afterSpecial;
    if (fin < 1000) fin = afterSpecial;
    var pct1 = special ? Math.max(1, Math.round((1 - special / normal) * 100)) : 0;
    var total = Math.round((1 - fin / normal) * 100);
    return {
      months: months, normal: normal, special: special, afterSpecial: afterSpecial,
      campaign: camp, final: fin, pct1: pct1, pct2: camp ? camp.percent : 0,
      total: total, saved: normal - fin, perMonth: Math.round(fin / months)
    };
  };

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function leftText(end, nowSec) {
    var s = end - nowSec;
    if (s <= 0) return '';
    var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    if (d >= 1) return PB.fa(d) + ' روز' + (h ? ' و ' + PB.fa(h) + ' ساعت' : '') + ' دیگر';
    if (h >= 1) return PB.fa(h) + ' ساعت' + (m ? ' و ' + PB.fa(m) + ' دقیقه' : '') + ' دیگر';
    return PB.fa(Math.max(1, m)) + ' دقیقه دیگر';
  }
  PB.leftText = leftText;

  // ساخت بلوک قیمت داخل یک عنصر
  PB.render = function (box, r, nowSec) {
    box.textContent = '';
    box.classList.toggle('nop', !r);
    if (!r) {
      var w = el('div', 'pp');
      w.appendChild(el('span', 'pp-call', 'تماس برای قیمت'));
      w.appendChild(el('span', 'pp-pm', 'قیمت امروز را از پشتیبانی بپرسید'));
      box.appendChild(w);
      return;
    }
    var wrap = el('div', 'pp'), hasDisc = r.special || r.campaign;

    if (hasDisc) {
      var lad = el('div', 'pp-ladder');
      // ردیف ۱: قیمت عادی
      var row = el('div', 'pp-row');
      row.appendChild(el('span', 'pp-l', 'قیمت عادی ' + PB.fa(r.months) + ' ماه'));
      row.appendChild(el('s', 'pp-v', PB.money(r.normal)));
      lad.appendChild(row);
      // ردیف ۲: تخفیف اول
      if (r.special) {
        row = el('div', 'pp-row pp-d1');
        row.appendChild(el('span', 'pp-l', 'تخفیف ویژهٔ پلن'));
        row.appendChild(el('b', 'pp-badge', PB.fa(r.pct1) + '٪ تخفیف'));
        var v = el(r.campaign ? 's' : 'span', 'pp-v', PB.money(r.special));
        row.appendChild(v);
        lad.appendChild(row);
      }
      // ردیف ۳: تخفیف مناسبتی (دوم)
      if (r.campaign) {
        row = el('div', 'pp-row pp-d2');
        row.appendChild(el('span', 'pp-l', '🎉 ' + r.campaign.title));
        row.appendChild(el('b', 'pp-badge pp-b2', (r.special ? '+ ' : '') + PB.fa(r.pct2) + '٪ ' + (r.special ? 'تخفیف دوم' : 'تخفیف')));
        lad.appendChild(row);
      }
      wrap.appendChild(lad);
    }

    var fin = el('div', 'pp-final');
    fin.appendChild(el('span', 'pp-big', PB.money(r.final)));
    fin.appendChild(el('small', null, 'تومان'));
    wrap.appendChild(fin);

    if (hasDisc && r.total > 0) {
      var t = el('div', 'pp-tot');
      t.appendChild(el('b', null, PB.fa(r.total) + '٪ ارزان‌تر'));
      t.appendChild(document.createTextNode(' · ' + PB.money(r.saved) + ' تومان صرفه‌جویی'));
      wrap.appendChild(t);
    }
    wrap.appendChild(el('div', 'pp-pm', 'یعنی ماهی ' + PB.money(r.perMonth) + ' تومان'));
    if (r.campaign && r.campaign.end && nowSec) {
      var lt = leftText(r.campaign.end, nowSec);
      if (lt) wrap.appendChild(el('div', 'pp-timer', '⏳ تخفیف ' + r.campaign.title + ': ' + lt));
    }
    box.appendChild(wrap);
  };

  // بارگذاری تنظیمات عمومی از سرور (یک بار برای همهٔ اسکریپت‌های صفحه)
  PB.load = function () {
    if (!PB._p) PB._p = fetch('api/config.php?t=' + Date.now(), { cache: 'no-store' }).then(function (r) {
      if (!r.ok) throw new Error('http'); return r.json();
    });
    return PB._p;
  };

  root.PB = PB;
})(window);
