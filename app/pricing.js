/* S8.T2 — موتور قیمت‌گذاری درصدی (وب). ترجمهٔ خط‌به‌خطِ pb_price_blend (backend/api/pricing_engine.php)
 * و lib/pricing_engine.dart. منبع حقیقت = PHP؛ هر تغییر باید با backend/tests/pricing_cases.json هم‌خوان بماند.
 * ورودی: {base_price P, weight_kg W, ref_gram R (پیش‌فرض 50), items:[{pct, q|null, gram_mode, g}]}
 * حالت A: میانگین = P·(1 − Σp_i/100) + Σ q_i·p_i/100 (Σ فقط روی درصدهای دارای q)
 * حالت B: q مؤثر = (q / R)·g وقتی gram_mode فعال است (0 < g ≤ 100)
 * گرد: میانگین کیلویی به عدد صحیح؛ مبلغ کل = round(میانگین گردشده × W). خروجی: {ok, error?, avg_per_kg, total, lines}. */
(function (root) {
  'use strict';

  var ERRORS = {
    bad_base_price: 'قیمت پایه نامعتبر است',
    bad_weight_kg: 'وزن نامعتبر است',
    bad_ref_gram: 'گرم مرجع نامعتبر است',
    bad_items: 'اقلام قیمت‌گذاری نامعتبر است',
    bad_pct: 'درصد نامعتبر است',
    bad_q: 'قیمت درصد نامعتبر است',
    bad_base_gram: '«گرم مغز» پسته اصلی باید بیشتر از ۰ و حداکثر ۱۰۰ باشد',
    bad_gram: '«گرم مغز» باید بیشتر از ۰ و حداکثر ۱۰۰ باشد',
    sum_over_100: 'مجموع درصدها نمی‌تواند بیشتر از ۱۰۰ باشد',
  };

  var NUM_RE = /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/;
  /** معادل is_numeric PHP برای مقدار JSON (عدد یا رشتهٔ عددی؛ bool/null/لیست نه) */
  function isNumeric(v) {
    if (typeof v === 'number') return isFinite(v);
    if (typeof v === 'string') return NUM_RE.test(v) && isFinite(Number(v));
    return false;
  }
  function toNum(v) { return typeof v === 'number' ? v : Number(String(v).trim()); }
  /** معادل round($x, 6) PHP (نیم‌دور از صفر) */
  function round6(x) { return (x < 0 ? -Math.round(-x * 1e6) : Math.round(x * 1e6)) / 1e6; }
  function roundInt(x) { return x < 0 ? -Math.round(-x) : Math.round(x); }
  /** معادل !empty() PHP */
  function truthy(v) {
    if (v === null || v === undefined || v === false) return false;
    if (typeof v === 'number') return v !== 0;
    if (typeof v === 'string') return v !== '' && v !== '0';
    if (Array.isArray(v)) return v.length > 0;
    if (typeof v === 'object') return Object.keys(v).length > 0;
    return true;
  }
  function isBlank(v) { return v === null || v === undefined || v === ''; } // توجه: === (نه ==) تا 0 خالی حساب نشود

  function fail(code) { return { ok: false, error: code }; }

  function priceBlend(input) {
    input = input || {};
    var ks = ['base_price', 'weight_kg'];
    for (var i = 0; i < ks.length; i++) {
      var v = input[ks[i]];
      if (v === null || v === undefined || !isNumeric(v) || toNum(v) < 0) return fail('bad_' + ks[i]);
    }
    var P = toNum(input.base_price), W = toNum(input.weight_kg);
    var R = (input.ref_gram !== null && input.ref_gram !== undefined) ? (isNumeric(input.ref_gram) ? toNum(input.ref_gram) : 0) : 50;
    if (R <= 0) return fail('bad_ref_gram');

    var raw = (input.items === null || input.items === undefined) ? [] : input.items;
    if (!Array.isArray(raw)) return fail('bad_items');
    // خرید گرمیِ پسته اصلی: قیمت پایهٔ مؤثر = (P / R) · base_g
    if (truthy(input.base_gram_mode)) {
      var bg = (!isBlank(input.base_g) && isNumeric(input.base_g)) ? toNum(input.base_g) : 0;
      if (bg <= 0 || bg > 100) return fail('bad_base_gram');
      P = (P / R) * bg;
    }

    var sumAll = 0, sumPriced = 0, acc = 0, lines = [];
    for (var k = 0; k < raw.length; k++) {
      var it = raw[k];
      if (!it || typeof it !== 'object' || Array.isArray(it) || it.pct === null || it.pct === undefined || !isNumeric(it.pct)) return fail('bad_pct');
      var p = toNum(it.pct);
      if (p < 0 || p > 100) return fail('bad_pct');
      sumAll += p;

      var gm = truthy(it.gram_mode);
      var g = !isBlank(it.g) ? (isNumeric(it.g) ? toNum(it.g) : 0) : null;
      if (isBlank(it.q)) { lines.push({ pct: p, q: null, eff_q: null, gram_mode: gm, g: g }); continue; }
      if (!isNumeric(it.q) || toNum(it.q) < 0) return fail('bad_q');
      var q = toNum(it.q), eff = q;
      if (gm) {
        if (g === null || g <= 0 || g > 100) return fail('bad_gram');
        eff = (q / R) * g;
      }
      sumPriced += p;
      acc += eff * p / 100;
      lines.push({ pct: p, q: q, eff_q: eff, gram_mode: gm, g: g });
    }
    if (sumAll > 100.0000001) return fail('sum_over_100');

    var avg = roundInt(round6(P * (1 - sumPriced / 100) + acc));
    var total = roundInt(round6(avg * W));
    return { ok: true, avg_per_kg: avg, total: total, lines: lines };
  }

  var api = { priceBlend: priceBlend, ERRORS: ERRORS, isNumeric: isNumeric };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.PBPricing = api;
})(typeof window !== 'undefined' ? window : this);
