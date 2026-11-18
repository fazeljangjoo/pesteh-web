/* T6 — «مسیر پسته» در وب (فقط‌خواندنی). اکشن سرور: lot_chain.get {lot_id | code} (مجوز purchases.view؛ اپراتور کارگاه ندارد).
 * بخش خالص = پورت JS همان `lib/lot_chain_calc.dart` (T5)؛ هیچ مبلغی در پاسخ/نمایش نیست.
 * ثبت: HI['lot.chain'] در PBModules + window.PBTrace.openLot(ref) برای لینک از کارت لات انبار. */
(function (root) {
  'use strict';

  // ------------------------------------------------------------------ بخش خالص (قابل تست در Node)
  var STAGE_KINDS = { lot: 1, mix: 1, separation: 1, workshop_internal: 1, workshop_outside: 1 };
  var KIND_LABELS = {
    orchard: 'باغ', purchase: 'خرید', consign: 'امانی', lot: 'لات', mix: 'مخلوط', separation: 'جداسازی',
    workshop_internal: 'کارگاه داخلی', workshop_outside: 'کارگاه بیرونی', sale: 'فروش', sale_return: 'برگشت از فروش',
    purchase_return: 'برگشت از خرید', waste: 'ضایعات/افت',
  };
  var ORIGIN_LABELS = { orchard: 'باغ', purchase: 'خرید از فروشنده', consign: 'امانی', outside: 'کارگاه بیرونی/دریافتی' };
  function kindLabel(k) { return Object.prototype.hasOwnProperty.call(KIND_LABELS, k) ? KIND_LABELS[k] : String(k); }
  function originLabel(k) { return Object.prototype.hasOwnProperty.call(ORIGIN_LABELS, k) ? ORIGIN_LABELS[k] : String(k); }

  function n0(v) { return typeof v === 'number' && isFinite(v) ? v : 0; }
  function cmpStr(a, b) { a = a == null ? '' : String(a); b = b == null ? '' : String(b); return a < b ? -1 : a > b ? 1 : 0; }

  /** کمترین عمق هر key در درخت تو در توی up/down. */
  function chainDepths(tree) {
    var out = {};
    (function walk(els, d) {
      if (!Array.isArray(els)) return;
      els.forEach(function (e) {
        if (!e || typeof e !== 'object') return;
        var k = String(e.node);
        if (!Object.prototype.hasOwnProperty.call(out, k) || d < out[k]) out[k] = d;
        walk(e.children, d + 1);
      });
    })(tree, 1);
    return out;
  }

  /** شناسهٔ لات از key «lot:<id>» وگرنه null. */
  function lotIdOf(key) { var m = /^lot:(\d+)$/.exec(String(key)); return m ? parseInt(m[1], 10) : null; }

  /** پاسخ lot_chain.get ⇒ خط زمانی (ترتیب: پیش از لات = عمق بیشتر اول، سپس تاریخ، سپس key؛ پس از لات = عمق کمتر اول). */
  function chainToTimeline(r) {
    r = r || {};
    var nodes = {};
    (Array.isArray(r.nodes) ? r.nodes : []).forEach(function (n) { if (n && typeof n === 'object') nodes[String(n.key)] = n; });
    var lot = (r.lot && typeof r.lot === 'object') ? r.lot : {};
    var rootKey = 'lot:' + lot.id;
    var up = chainDepths(r.up), down = chainDepths(r.down);

    function step(key, depth) {
      var n = nodes[key] || { key: key, kind: 'lot', label: key };
      var meta = n.meta, kind = String(n.kind);
      var isMix = (meta && typeof meta === 'object' && typeof meta.is_mix === 'number') ? Math.trunc(meta.is_mix) : (kind === 'mix' ? 1 : 0);
      return { key: key, kind: kind, label: n.label == null ? key : String(n.label), code: n.code == null ? null : String(n.code),
        date: n.date == null ? null : String(n.date), kg: n0(n.kg), depth: depth, isMix: isMix, lotId: lotIdOf(key) };
    }
    function pick(depths, want, farFirst) {
      var out = [];
      Object.keys(depths).forEach(function (k) { if (k === rootKey) return; var s = step(k, depths[k]); if (want(s.kind)) out.push(s); });
      out.sort(function (a, b) {
        var d = farFirst ? b.depth - a.depth : a.depth - b.depth;
        if (d !== 0) return d;
        var t = cmpStr(a.date, b.date);
        return t !== 0 ? t : cmpStr(a.key, b.key);
      });
      return out;
    }
    var isStage = function (k) { return Object.prototype.hasOwnProperty.call(STAGE_KINDS, k); };
    var sum = (r.summary && typeof r.summary === 'object') ? r.summary : {};
    var origins = (Array.isArray(sum.origins) ? sum.origins : []).filter(function (o) { return o && typeof o === 'object'; })
      .map(function (o) { return { kind: String(o.kind), name: String(o.name), pct: n0(o.pct) }; });
    var selfMix = typeof lot.is_mix === 'number' ? Math.trunc(lot.is_mix) : 0;
    return {
      origins: origins,
      before: pick(up, isStage, true),
      self: { key: rootKey, kind: selfMix === 1 ? 'mix' : 'lot', label: lot.type_name == null ? '' : String(lot.type_name), code: lot.code == null ? null : String(lot.code),
        date: lot.date == null ? null : String(lot.date), kg: n0(lot.net_weight), depth: 0, isMix: selfMix, lotId: lotIdOf(rootKey) },
      after: pick(down, isStage, false),
      sales: pick(down, function (k) { return k === 'sale'; }, false),
      waste: pick(down, function (k) { return k === 'waste'; }, false),
      returned: pick(down, function (k) { return k === 'purchase_return'; }, false).concat(pick(up, function (k) { return k === 'sale_return'; }, false)),
      soldKg: n0(sum.sold_kg), remainingKg: n0(sum.remaining_kg), wasteKg: n0(sum.waste_kg), returnedKg: n0(sum.returned_kg),
      truncated: r.truncated === true,
    };
  }

  /** HTML خط زمانی. h = { esc, num, date, chip } تزریق می‌شود (تست‌پذیر)؛ همهٔ متن‌های سرور escape می‌شوند. */
  function timelineHtml(tl, h) {
    function stepHtml(s, cls) {
      var head = s.lotId !== null ? h.chip(s.code, s.isMix) : '<span class="badge">' + h.esc(kindLabel(s.kind)) + '</span>';
      var title = s.lotId !== null ? (s.label ? ' ' + h.esc(s.label) : '') : ' ' + h.esc(s.label);
      var meta = [];
      if (s.date) meta.push(h.esc(h.date(s.date)));
      if (s.kg) meta.push(h.esc(h.num(s.kg)) + ' کیلو');
      var attr = s.lotId !== null ? ' data-lot="' + s.lotId + '" style="cursor:pointer"' : '';
      return '<div class="trace-step ' + cls + '"' + attr + '><div>' + head + title + '</div>' + (meta.length ? '<div class="meta">' + meta.join(' · ') + '</div>' : '') + '</div>';
    }
    function list(arr, cls) { return arr.map(function (s) { return stepHtml(s, cls); }).join(''); }
    var o = '';
    if (tl.origins.length) {
      o += '<div class="card"><b>مبدأ</b>' + tl.origins.map(function (x) {
        return '<div class="card-row"><span>' + h.esc(originLabel(x.kind)) + ' — ' + h.esc(x.name) + '</span><span>' + h.esc(h.num(x.pct)) + '٪</span></div>';
      }).join('') + '</div>';
    } else {
      o += '<div class="card"><b>مبدأ</b><div class="muted">مبدأ این لات ثبت نشده است</div></div>';
    }
    o += '<div class="trace-line">';
    o += list(tl.before, 'before');
    o += stepHtml(tl.self, 'self');
    o += list(tl.after, 'after');
    o += '</div>';
    var dest = '';
    if (tl.sales.length) dest += '<div class="meta">فروش‌ها</div>' + list(tl.sales, 'dest');
    if (tl.waste.length) dest += '<div class="meta">ضایعات / افت</div>' + list(tl.waste, 'dest');
    if (tl.returned.length) dest += '<div class="meta">برگشتی‌ها</div>' + list(tl.returned, 'dest');
    o += '<div class="card"><b>مقصد</b>' + (dest || '<div class="muted">هنوز مقصدی ثبت نشده است</div>')
      + '<div class="card-row"><span>فروخته‌شده</span><span>' + h.esc(h.num(tl.soldKg)) + ' کیلو</span></div>'
      + '<div class="card-row"><span>باقی‌مانده</span><span>' + h.esc(h.num(tl.remainingKg)) + ' کیلو</span></div>'
      + '<div class="card-row"><span>ضایعات/افت</span><span>' + h.esc(h.num(tl.wasteKg)) + ' کیلو</span></div>'
      + '<div class="card-row"><span>برگشتی</span><span>' + h.esc(h.num(tl.returnedKg)) + ' کیلو</span></div></div>';
    if (tl.truncated) o += '<div class="empty-state">ادامهٔ مسیر نمایش داده نشد (زنجیره خیلی بزرگ است)</div>';
    return o;
  }

  var PURE = { kindLabel: kindLabel, originLabel: originLabel, chainDepths: chainDepths, lotIdOf: lotIdOf, chainToTimeline: chainToTimeline, timelineHtml: timelineHtml, STAGE_KINDS: STAGE_KINDS };
  if (typeof window === 'undefined') { if (typeof module !== 'undefined' && module.exports) module.exports = PURE; return; }

  // ------------------------------------------------------------------ بخش UI
  var $ = function (id) { return document.getElementById(id); };
  var E = function (v) { return esc(v); }; // esc سراسری app.js
  function num(n) { return (Number(n) || 0).toLocaleString('fa-IR', { maximumFractionDigits: 3 }); }
  function jd(iso) { return iso ? isoToJalaliDisplay(iso) : '—'; }
  function chip(code, isMix) { return window.PBCode ? PBCode.chipHtml(code || '', isMix) : '<span class="badge code-badge">' + E(code || '—') + '</span>'; }
  function setTitle(t) { var el = $('page-title'); if (el) el.textContent = t; }
  function clearChrome() { document.querySelectorAll('.fab').forEach(function (f) { f.remove(); }); }
  function fail(c) { return function (err) { c.innerHTML = '<div class="empty-state">' + E((err && err.message) || 'خطا در بارگذاری') + '</div>'; }; }

  var hist = []; // پشتهٔ مراجع (لات‌های بازدیدشده) برای دکمهٔ «بازگشت»
  var cur = null;

  function refPayload(ref) { return /^\d+$/.test(String(ref)) ? { lot_id: Number(ref) } : { code: String(ref) }; }

  async function showChain(c, ref, push) {
    if (push && cur !== null) hist.push(cur);
    cur = ref === undefined || ref === null || ref === '' ? null : ref;
    var form = '<div class="card"><div class="card-row"><input id="trace-code" class="input" placeholder="کد پسته (مثلاً KH… / M… / S-…)" value="' + (cur !== null && !/^\d+$/.test(String(cur)) ? E(cur) : '') + '" style="flex:1">'
      + '<button id="trace-go" type="button" class="btn">نمایش مسیر</button></div>'
      + (hist.length ? '<button id="trace-back" type="button" class="btn secondary" style="margin-top:6px">‹ بازگشت</button>' : '') + '</div>';
    var body = '';
    if (cur !== null) {
      var r = await api('lot_chain.get', refPayload(cur));
      body = timelineHtml(chainToTimeline(r), { esc: E, num: num, date: jd, chip: chip });
    } else {
      body = '<div class="empty-state">کد پسته را وارد کنید یا از کارت لات در «انبار» مسیر را باز کنید</div>';
    }
    c.innerHTML = form + body;
    var go = function () { var v = ($('trace-code').value || '').trim(); if (v) showChain(c, v, true).catch(fail(c)); };
    $('trace-go').addEventListener('click', go);
    $('trace-code').addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
    var back = $('trace-back');
    if (back) back.addEventListener('click', function () { var p = hist.pop(); cur = null; showChain(c, p, false).catch(fail(c)); });
    c.querySelectorAll('[data-lot]').forEach(function (el) {
      el.addEventListener('click', function () { var id = Number(el.dataset.lot); if (String(id) !== String(cur)) showChain(c, id, true).catch(fail(c)); });
    });
  }

  /** از کارت لات انبار (یا هر جای دیگر): مسیر لات با شناسهٔ سرور یا کد. */
  function openLot(ref) {
    clearChrome(); setTitle('مسیر پسته'); hist = []; cur = null;
    var c = $('content'); if (!c) return;
    c.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
    showChain(c, ref, false).catch(fail(c));
  }

  var HI = {
    'lot.chain': async function (c) { hist = []; await showChain(c, null, false); },
  };
  var prev = window.PBModules;
  window.PBModules = {
    has: function (k) { return typeof HI[k] === 'function' || (prev && prev.has(k)); },
    open: function (k, c) { return typeof HI[k] === 'function' ? HI[k](c) : prev.open(k, c); },
  };
  root.PBTrace = { openLot: openLot };
})(typeof window !== 'undefined' ? window : this);
