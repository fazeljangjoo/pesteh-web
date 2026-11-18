/* T2 (ردیابی پسته) — آینهٔ JS از backend/api/code_kind.php (منبع حقیقت = سرور) و lib/code_kind.dart.
 * تست مشترک: backend/tests/code_kind_cases.json ← tests/web_code_kind_test.js.
 * window.PBCode = { kindOf, chipHtml, groupColor, mixGroupKeys, mixGroupCounts, letterForName, ... }.
 * هیچ مبلغی در این فایل نیست. توابع خالص‌اند (بدون DOM)؛ فقط chipHtml رشتهٔ HTML می‌سازد (با escape). */
(function (root) {
  'use strict';

  // نوع → حرف (کدهای جدید) — باید با PB_CODE_LETTERS سرور یکی باشد
  var LETTERS = { khandan: 'KH', dombest: 'D', other: 'G', mix: 'M', separation: 'S', workshop: 'W', outside: 'B', consignment: 'C', received: 'R', orchard: 'H' };
  var LEGACY_PREFIX = { MX: 'mix', K: 'separation', PR: 'outside', OR: 'orchard' };
  var KIND_LABELS = {
    khandan: 'خندان', dombest: 'دهنبست', other: 'سایر دسته‌ها', mix: 'مخلوط', separation: 'جداسازی',
    workshop: 'کارگاه داخلی', outside: 'کارگاه بیرونی', consignment: 'امانی', received: 'دریافتی برای دیگران',
    orchard: 'باغ', custom: 'حرف سفارشی', unknown: 'نامشخص',
  };
  // رنگ نوع (تم روشن؛ همان مقدارهای lib/code_kind.dart)
  var KIND_COLORS = {
    khandan: '#2E7D32', dombest: '#6D4C41', other: '#607D8B', mix: '#7B1FA2', separation: '#0277BD', workshop: '#EF6C00',
    outside: '#00838F', consignment: '#AD1457', received: '#3949AB', orchard: '#827717', custom: '#455A64', unknown: '#757575',
  };
  // پالت ثابت ۱۰ رنگ «گروه مخلوط» (اندیس = key mod 10)
  var GROUP_PALETTE = ['#E53935', '#1E88E5', '#43A047', '#FB8C00', '#8E24AA', '#00ACC1', '#D81B60', '#6D4C41', '#3949AB', '#C0CA33'];

  function upperAscii(s) { return String(s).replace(/[a-z]/g, function (ch) { return ch.toUpperCase(); }); }

  // تشخیص نوع از روی کد (همان pb_code_kind_of). isMix فقط وقتی از کد چیزی فهمیده نشود: 1 ⇒ مخلوط.
  function kindOf(code, isMix) {
    var c = upperAscii(String(code == null ? '' : code).trim());
    var m = /^([A-Z]{1,2})(-|\d)/.exec(c);
    if (c === '' || !m) return Number(isMix) === 1 ? { kind: 'mix', letter: 'M', legacy: false } : { kind: 'unknown', letter: '', legacy: false };
    var L = m[1], rest = c.slice(L.length);
    if (Object.prototype.hasOwnProperty.call(LEGACY_PREFIX, L)) return { kind: LEGACY_PREFIX[L], letter: L, legacy: true };
    if (L === 'KH') return { kind: 'khandan', letter: 'KH', legacy: /^\d{1,11}$/.test(rest) };
    for (var k in LETTERS) if (Object.prototype.hasOwnProperty.call(LETTERS, k) && LETTERS[k] === L) return { kind: k, letter: L, legacy: false };
    return { kind: 'custom', letter: L, legacy: false };
  }

  // کد موقتِ محلیِ ارسال‌نشده (N + ۱ تا ۴ رقم)؛ داخل kindOf نیست تا با سرور هم‌خوان بماند
  function isPendingCode(code) { return /^N\d{1,4}$/.test(String(code == null ? '' : code).trim()); }

  // نام دسته → حرف (pb_code_letter_for_name)
  function letterForName(name) {
    var n = String(name == null ? '' : name).replace(/[\u200C\u200D \u00A0]/g, '');
    n = n.replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/ە/g, 'ه');
    if (n.indexOf('خندان') !== -1) return LETTERS.khandan;
    if (n.indexOf('دهنبست') !== -1) return LETTERS.dombest;
    return LETTERS.other;
  }

  function kindLabel(info) {
    if (!info.legacy) return KIND_LABELS[info.kind] || KIND_LABELS.unknown;
    switch (info.letter) {
      case 'MX': return 'مخلوط (قدیمی)';
      case 'K': return 'جداسازی/کارگاه (قدیمی)';
      case 'PR': return 'کارگاه بیرونی (قدیمی)';
      case 'OR': return 'باغ (قدیمی)';
      case 'KH': return 'خندان (قدیمی/عمومی)';
    }
    return KIND_LABELS[info.kind] || KIND_LABELS.unknown;
  }

  function kindColor(info) { return KIND_COLORS[info.kind] || KIND_COLORS.unknown; }

  function groupIndex(key) { var n = GROUP_PALETTE.length, k = Math.trunc(Number(key)) || 0; return ((k % n) + n) % n; }
  function groupColor(key) { return GROUP_PALETTE[groupIndex(key)]; }

  function asInt(v) { if (v === null || v === undefined || v === '') return null; var n = Number(v); return isFinite(n) ? Math.trunc(n) : null; }

  // کلید گروه مخلوط برای هر لات (قواعد PLAN):
  //  • is_mix=1 ⇒ کلید خودش؛ • is_mix=0 داخل چند مخلوط ⇒ بزرگ‌ترین id مخلوط؛ • بدون مخلوط یا is_mix=2/3 ⇒ null.
  // lots: [{id, is_mix}] ؛ sources: [{mix_id|mix_purchase_id, source_id|source_purchase_id}] ؛ خروجی: { [lotId]: key|null }
  function mixGroupKeys(lots, sources) {
    var newest = {};
    (sources || []).forEach(function (s) {
      var mix = asInt(s.mix_purchase_id != null ? s.mix_purchase_id : s.mix_id);
      var src = asInt(s.source_purchase_id != null ? s.source_purchase_id : s.source_id);
      if (mix === null || src === null) return;
      if (newest[src] === undefined || mix > newest[src]) newest[src] = mix;
    });
    var out = {};
    (lots || []).forEach(function (l) {
      var id = asInt(l.id);
      if (id === null) return;
      var kind = asInt(l.is_mix) || 0;
      if (kind === 1) out[id] = id;
      else if (kind === 0) out[id] = newest[id] !== undefined ? newest[id] : null;
      else out[id] = null;
    });
    return out;
  }

  // شمارش لات‌های هر گروه مخلوط برای راهنمای انبار (T3a؛ آینهٔ Dart mixGroupCounts).
  // lots: [{id}] ؛ keys: { [lotId]: key|null } (خروجی mixGroupKeys یا فیلد mix_group سرور)
  // خروجی: [{key, mixLotId, count}] با ترتیب key نزولی؛ mixLotId = key اگر خودِ مخلوط در lots باشد وگرنه null.
  function mixGroupCounts(lots, keys) {
    var seen = {}, counts = {};
    (lots || []).forEach(function (l) {
      var id = asInt(l.id);
      if (id === null || seen[id]) return;
      seen[id] = true;
      var k = keys ? keys[id] : null;
      if (k === null || k === undefined) return;
      counts[k] = (counts[k] || 0) + 1;
    });
    return Object.keys(counts).map(Number).sort(function (a, b) { return b - a; }).map(function (k) {
      return { key: k, mixLotId: seen[k] ? k : null, count: counts[k] };
    });
  }

  function esc(s) { return String(s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); }

  // چیپ HTML: حرف نوع داخل مربع رنگی + کد کامل (استایل: .code-chip در style.css؛ رنگ از data-k)
  function chipHtml(code, isMix) {
    var text = String(code == null ? '' : code).trim();
    if (text === '') text = '-';
    var pending = isPendingCode(text);
    var info = pending ? { kind: 'pending', letter: '…', legacy: false } : kindOf(text, isMix);
    var letter = pending ? '…' : (info.letter || '?');
    var tip = pending ? 'هنوز به سرور ارسال نشده' : kindLabel(info);
    return '<span class="code-chip" data-k="' + esc(info.kind) + '" title="' + esc(tip) + '">' +
      '<b class="cc-l">' + esc(letter) + '</b><span class="cc-c" dir="ltr">' + esc(text) + '</span></span>';
  }

  var api = {
    LETTERS: LETTERS, KIND_LABELS: KIND_LABELS, KIND_COLORS: KIND_COLORS, GROUP_PALETTE: GROUP_PALETTE,
    kindOf: kindOf, kindLabel: kindLabel, kindColor: kindColor, isPendingCode: isPendingCode, letterForName: letterForName,
    groupIndex: groupIndex, groupColor: groupColor, mixGroupKeys: mixGroupKeys, mixGroupCounts: mixGroupCounts, chipHtml: chipHtml,
  };
  root.PBCode = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : this);
