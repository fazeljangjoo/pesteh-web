/* «کارتکس لات» در وب (فقط‌خواندنی؛ هم‌تراز lib/lot_cartex_ui.dart ویندوز/اندروید). اکشن سرور: stock.lot_cartex {year_start?} و stock.lot_cartex_settings.
 * همهٔ محاسبه سمت سرور است (lot_cartex.php)؛ این فایل فقط نمایش می‌دهد. هیچ قیمت/مبلغی در پاسخ/نمایش نیست.
 * پنج تب: دید کلی · موجود در انبار · کوت‌ریزی‌شده · فراوری‌شده · پسته‌های خارج‌شده (تاریخ/نوع خروج فقط در تب آخر).
 * نقش «کارتکس» (فقط مجوز cartex.view): فقط همین صفحه + خروج (PBCartex.roleOnly / applyRole / roleTab).
 * ثبت: HI['lot.cartex'] در PBModules (منوی کشویی) + PBCartex.open() برای دکمهٔ صفحهٔ انبار. */
(function (root) {
  'use strict';

  // ------------------------------------------------------------------ بخش خالص (قابل تست در Node)
  var TABS = [
    ['overview', 'دید کلی'],
    ['normal', 'موجود در انبار'],
    ['kot', 'کوت‌ریزی‌شده'],
    ['processed', 'فراوری‌شده'],
    ['exited', 'پسته‌های خارج‌شده'],
  ];
  var CARTEX_ONLY_PERMS = ['cartex.view'];
  var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

  /** کارکنی که فقط cartex.view دارد (هم‌تراز StaffPerms.isCartexOnly اپ). perms = آرایهٔ مجوزهای کارکن. */
  function isCartexOnlyPerms(perms) {
    if (!Array.isArray(perms) || !perms.length) return false;
    if (perms.indexOf('cartex.view') === -1) return false;
    return perms.every(function (p) { return CARTEX_ONLY_PERMS.indexOf(p) !== -1; });
  }
  function daysInJMonth(m) { return m <= 6 ? 31 : (m <= 11 ? 30 : 29); }
  /** نرمال‌سازی ساده برای جست‌وجوی نام (ي/ی، ك/ک، حذف نیم‌فاصله و فاصله). */
  function normName(s) {
    return String(s == null ? '' : s).replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[‌‍\s]/g, '').toLowerCase();
  }
  function hit(q, party) { return !q || normName(party).indexOf(normName(q)) !== -1; }

  var PURE = { TABS: TABS, isCartexOnlyPerms: isCartexOnlyPerms, daysInJMonth: daysInJMonth, normName: normName, hit: hit };
  if (typeof window === 'undefined') { if (typeof module !== 'undefined' && module.exports) module.exports = PURE; return; }

  // ------------------------------------------------------------------ بخش UI
  var $ = function (id) { return document.getElementById(id); };
  var E = function (v) { return esc(v); }; // esc سراسری app.js
  function num(n) { return (Number(n) || 0).toLocaleString('fa-IR', { maximumFractionDigits: 3 }); }
  function og(v) { return v === null || v === undefined || v === '' ? '—' : num(v); } // انس/گرم اختیاری؛ ندارد ⇒ «—»
  function jd(iso) { return iso ? isoToJalaliDisplay(String(iso).slice(0, 10)) : '—'; }
  function color(key) { return window.PBCode ? PBCode.groupColor(key) : '#757575'; }
  function setTitle(t) { var el = $('page-title'); if (el) el.textContent = t; }
  function clearChrome() { document.querySelectorAll('.fab').forEach(function (f) { f.remove(); }); }
  function fail(c) { return function (err) { c.innerHTML = '<div class="empty-state">' + E((err && err.message) || 'خطا در بارگذاری') + '</div>'; }; }
  function perm() { return !window.PBShell || PBShell.hasPerm(['cartex.view', 'stock.view']); }
  function roleOnly() {
    var st = window.PBShell && PBShell.staffPerms ? PBShell.staffPerms() : null;
    return isCartexOnlyPerms(st);
  }

  var S = { tab: 'overview', q: '', yearStart: null, report: null };

  function table(head, rows) {
    return '<div class="table-wrap"><table class="mini-table"><thead><tr>' + head.map(function (h) { return '<th>' + E(h) + '</th>'; }).join('')
      + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>';
  }
  function tr(cells) { return '<tr>' + cells.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }
  function party(p) { return E(p || '—'); }

  function stockTable(rows) {
    return table(['نام طرف', 'وزن خالص (کیلو)', 'مانده (کیلو)', 'انس', 'گرم', 'تاریخ ورود', 'فروخته (کیلو)'],
      rows.map(function (x) { return tr([party(x.party), num(x.weight_kg), x.remaining_kg == null ? '—' : num(x.remaining_kg), og(x.ounce), og(x.gram), jd(x.entry_date), (Number(x.sold_kg) || 0) > 0 ? num(x.sold_kg) : '—']); }));
  }
  function overviewTable(rows) {
    return table(['نام طرف', 'وزن خالص (کیلو)', 'مانده (کیلو)', 'انس', 'گرم', 'تاریخ ورود', 'وضعیت'],
      rows.map(function (x) { return tr([party(x.party), num(x.weight_kg), x.remaining_kg == null ? '—' : num(x.remaining_kg), og(x.ounce), og(x.gram), jd(x.entry_date), E(x.exit_label || '—')]); }));
  }
  function exitedTable(rows) {
    return table(['نام طرف', 'وزن فروخته (کیلو)', 'تاریخ ورود', 'تاریخ خروج', 'نوع خروج', 'انس', 'گرم'],
      rows.map(function (x) { return tr([party(x.party), num(x.weight_kg), jd(x.entry_date), jd(x.exit_date), E(x.exit_label || '—'), og(x.ounce), og(x.gram)]); }));
  }
  function lotsTable(rows, shareLabel) {
    return table(['نام طرف', shareLabel, 'انس', 'گرم', 'تاریخ ورود', 'مانده (کیلو)'],
      rows.map(function (x) { return tr([party(x.party), num(x.weight_kg), og(x.ounce), og(x.gram), jd(x.entry_date), x.remaining_kg == null ? '—' : num(x.remaining_kg)]); }));
  }
  function outputsTable(rows) {
    return table(['کد', 'نام طرف', 'وزن (کیلو)', 'انس', 'گرم', 'تاریخ'],
      rows.map(function (o) { return tr([E(o.code || '—'), party(o.party), num(o.weight_kg), og(o.ounce), og(o.gram), jd(o.entry_date)]); }));
  }

  function groupBox(col, headerHtml, bodyHtml) {
    return '<div class="card" style="border:1px solid ' + col + ';padding:0;overflow:hidden">'
      + '<div style="background:' + col + '22;padding:8px 10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">'
      + '<span style="width:12px;height:12px;border-radius:50%;background:' + col + ';display:inline-block"></span>' + headerHtml + '</div>'
      + '<div style="padding:4px 8px 8px">' + bodyHtml + '</div></div>';
  }
  function typeCard(t, count, inner) {
    return '<details class="card" open><summary style="font-weight:700;cursor:pointer">' + E(t.type_name || '(بدون نوع)') + ' · ' + num(t.total_kg) + ' کیلو'
      + ' <span class="muted" style="font-weight:400;font-size:12px">(' + num(count) + ' ردیف)</span></summary>' + inner + '</details>';
  }
  function emptyMsg(msg) { return '<div class="empty-state">' + E(msg) + '</div>'; }

  function tabOverview(r) {
    var out = '';
    r.types.forEach(function (t) {
      var rows = (t.overview || []).filter(function (x) { return hit(S.q, x.party); });
      if (rows.length) out += typeCard(t, rows.length, overviewTable(rows));
    });
    return out || emptyMsg(S.q ? 'موردی پیدا نشد' : 'در این بازه پسته‌ای ثبت نشده');
  }
  function tabNormal(r) {
    var out = '';
    r.types.forEach(function (t) {
      var rows = (t.normal || []).filter(function (x) { return hit(S.q, x.party); });
      if (rows.length) out += typeCard(t, rows.length, stockTable(rows));
    });
    return out || emptyMsg(S.q ? 'موردی پیدا نشد' : 'در این بازه پستهٔ موجودی در انبار نیست');
  }
  function tabKot(r) {
    var out = '';
    r.types.forEach(function (t) {
      var groups = '', n = 0;
      (t.kot || []).forEach(function (k) {
        var items = (k.items || []).filter(function (x) { return hit(S.q, x.party); });
        if (!items.length) return;
        n += items.length;
        var head = '<b>کوت ' + E(k.code || ('#' + k.kot_id)) + '</b><span>ساخت ' + jd(k.date) + '</span><span>وزن ' + num(k.weight_kg) + ' کیلو</span><span>انس ' + og(k.ounce) + '</span><span>گرم ' + og(k.gram) + '</span>';
        var all = (k.all_items || []).length
          ? '<details style="margin-top:6px"><summary class="muted" style="font-size:12px;cursor:pointer">همهٔ لات‌های درون کوت</summary>'
            + table(['کد', 'نام طرف', 'نوع', 'سهم (کیلو)', 'انس', 'گرم', 'تاریخ'], k.all_items.map(function (x) { return tr([E(x.code || '—'), party(x.party), E(x.type_name || '—'), num(x.weight_kg), og(x.ounce), og(x.gram), jd(x.entry_date)]); })) + '</details>'
          : '';
        groups += groupBox(color(k.color_key), head, lotsTable(items, 'سهم در کوت (کیلو)') + all);
      });
      if (groups) out += typeCard(t, n, groups);
    });
    return out || emptyMsg(S.q ? 'موردی پیدا نشد' : 'در این بازه لاتی کوت نشده');
  }
  function tabProcessed(r) {
    var out = '';
    r.types.forEach(function (t) {
      var groups = '', n = 0;
      (t.processed || []).forEach(function (g) {
        if (!(g.items || []).some(function (x) { return hit(S.q, x.party); }) && !(g.outputs || []).some(function (x) { return hit(S.q, x.party); })) return;
        n += (g.items || []).length;
        var head = '<b>' + E(g.label) + '</b><span>' + jd(g.date) + '</span><span>ورودی ' + num(g.in_kg) + ' کیلو</span>' + ((g.outputs || []).length ? '<span>ساخته‌شده ' + num(g.out_kg) + ' کیلو</span>' : '');
        var body = '<div class="muted" style="font-size:12px;font-weight:700;margin-top:6px">پستهٔ مبدأ</div>' + lotsTable(g.items || [], 'وزن فراوری‌شده (کیلو)')
          + '<div class="muted" style="font-size:12px;font-weight:700;margin-top:6px">لات‌های ساخته‌شده از آن</div>'
          + ((g.outputs || []).length ? outputsTable(g.outputs) : '<div class="muted" style="font-size:12px;padding:4px 0">هنوز لاتی از این فراوری ساخته نشده</div>');
        groups += groupBox(color(g.color_key), head, body);
      });
      if (groups) out += typeCard(t, n, groups);
    });
    return out || emptyMsg(S.q ? 'موردی پیدا نشد' : 'در این بازه لاتی به فراوری/شکست نرفته');
  }
  function tabExited(r) {
    var out = '';
    r.types.forEach(function (t) {
      var rows = (t.exited || []).filter(function (x) { return hit(S.q, x.party); });
      if (rows.length) out += typeCard(t, rows.length, exitedTable(rows));
    });
    return out || emptyMsg(S.q ? 'موردی پیدا نشد' : 'در این بازه پسته‌ای خارج نشده');
  }
  var RENDER = { overview: tabOverview, normal: tabNormal, kot: tabKot, processed: tabProcessed, exited: tabExited };

  function bodyHtml() {
    var r = S.report;
    if (!r) return '';
    return (RENDER[S.tab] || tabOverview)(r);
  }
  function drawBody() { var b = $('cx-body'); if (b) b.innerHTML = bodyHtml(); }

  function settingsForm(r) {
    var m = (r.settings && r.settings.month) || 7, d = (r.settings && r.settings.day) || 1;
    var monthOpts = MONTHS.map(function (n, i) { return '<option value="' + (i + 1) + '"' + (i + 1 === m ? ' selected' : '') + '>' + n + '</option>'; }).join('');
    function dayOpts(mm, dd) { var o = ''; for (var i = 1; i <= daysInJMonth(mm); i++) o += '<option value="' + i + '"' + (i === dd ? ' selected' : '') + '>' + num(i) + '</option>'; return o; }
    return '<div class="card" id="cx-set" style="display:none"><div class="muted" style="font-size:12px;margin-bottom:6px">روز و ماه شروع سال پسته‌ای (پیش‌فرض: ۱ مهر تا ۳۱ شهریور)</div>'
      + '<div class="card-row"><select id="cx-m" class="input">' + monthOpts + '</select><select id="cx-d" class="input">' + dayOpts(m, d) + '</select>'
      + '<button type="button" id="cx-save" class="btn">ذخیره</button></div></div>';
  }

  async function show(c) {
    clearChrome();
    setTitle('کارتکس لات');
    c.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
    var payload = {};
    if (S.yearStart) payload.year_start = S.yearStart;
    var r = await api('stock.lot_cartex', payload);
    r.types = r.types || [];
    S.report = r;
    var cur = null;
    if (r.range && r.range.from) cur = yearOf(r.range.from, (r.settings && r.settings.month) || 7, (r.settings && r.settings.day) || 1);
    var yearSel = (r.years && r.years.length)
      ? '<select id="cx-year" class="input">' + r.years.map(function (y) { return '<option value="' + y.year_start + '"' + (y.year_start === cur ? ' selected' : '') + '>' + E(y.label) + '</option>'; }).join('') + '</select>'
      : '';
    c.innerHTML = '<div class="card">'
      + '<div class="card-row" style="gap:6px;flex-wrap:wrap">' + yearSel
      + '<button type="button" id="cx-cfg" class="btn btn-secondary" style="margin-top:0">تنظیم بازه</button>'
      + '<button type="button" id="cx-ref" class="btn btn-secondary" style="margin-top:0">تازه‌سازی</button></div>'
      + '<div class="field" style="margin-top:6px"><input type="search" id="cx-q" placeholder="جست‌وجو بر اساس نام طرف" value="' + E(S.q) + '"></div>'
      + (r.range && r.range.from ? '<div class="muted" style="font-size:12px">' + E(r.range.label || '') + ' · از ' + jd(r.range.from) + ' تا ' + jd(r.range.to) + '</div>' : '')
      + '</div>' + settingsForm(r)
      + '<div class="seg-tabs ii-filters" id="cx-tabs">' + TABS.map(function (t) { return '<button type="button" class="seg-tab' + (S.tab === t[0] ? ' active' : '') + '" data-t="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>'
      + '<div id="cx-body">' + bodyHtml() + '</div>';
    $('cx-tabs').addEventListener('click', function (e) {
      var b = e.target.closest('[data-t]'); if (!b) return;
      S.tab = b.dataset.t;
      c.querySelectorAll('#cx-tabs .seg-tab').forEach(function (x) { x.classList.toggle('active', x.dataset.t === S.tab); });
      drawBody();
    });
    var qt = null;
    $('cx-q').addEventListener('input', function () { clearTimeout(qt); var v = this.value; qt = setTimeout(function () { S.q = v.trim(); drawBody(); }, 250); });
    $('cx-ref').addEventListener('click', function () { show(c).catch(fail(c)); });
    var ys = $('cx-year');
    if (ys) ys.addEventListener('change', function () { S.yearStart = Number(this.value) || null; show(c).catch(fail(c)); });
    $('cx-cfg').addEventListener('click', function () { var f = $('cx-set'); if (f) f.style.display = f.style.display === 'none' ? '' : 'none'; });
    var ms = $('cx-m');
    ms.addEventListener('change', function () {
      var dd = Number($('cx-d').value) || 1, mm = Number(this.value), mx = daysInJMonth(mm); if (dd > mx) dd = mx;
      var o = ''; for (var i = 1; i <= mx; i++) o += '<option value="' + i + '"' + (i === dd ? ' selected' : '') + '>' + num(i) + '</option>';
      $('cx-d').innerHTML = o;
    });
    $('cx-save').addEventListener('click', async function () {
      try {
        await api('stock.lot_cartex_settings', { save: true, month: Number($('cx-m').value), day: Number($('cx-d').value) });
        S.yearStart = null;
        await show(c);
      } catch (e) { toast((e && e.message) || 'خطا'); }
    });
  }

  /** سال شمسیِ شروع بازه‌ای که تاریخ میلادی ymd در آن می‌افتد (هم‌تراز lotCartexStartYearOf). */
  function yearOf(ymd, month, day) {
    var j = isoToJalaliYMD(ymd);
    return (j[1] < month || (j[1] === month && j[2] < day)) ? j[0] - 1 : j[0];
  }

  function open() {
    var c = $('content'); if (!c) return;
    document.querySelectorAll('.tab').forEach(function (b) { b.classList.remove('active'); });
    if (window.PBRealtime) PBRealtime.setView({ refresh: function () { return show(c); }, full: true, entities: [] });
    show(c).catch(fail(c));
  }

  // ---- نقش «کارتکس»: فقط همین صفحه
  function applyRole() {
    var only = roleOnly();
    document.body.classList.toggle('cartex-only', only);
  }
  function roleTab() { open(); }

  var HI = {
    'lot.cartex': async function (c) { await show(c); },
  };
  var prev = window.PBModules;
  window.PBModules = {
    has: function (k) { return typeof HI[k] === 'function' || (prev && prev.has(k)); },
    open: function (k, c) { return typeof HI[k] === 'function' ? HI[k](c) : prev.open(k, c); },
  };
  root.PBCartex = { open: open, roleOnly: roleOnly, applyRole: applyRole, roleTab: roleTab, canView: perm, _pure: PURE };
})(typeof window !== 'undefined' ? window : this);
