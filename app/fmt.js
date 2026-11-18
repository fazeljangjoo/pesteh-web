/* S35.T35.10 — universal formatter (web). ONE source of truth for money/weight/percent display.
 * Storage unit = tenant money_unit ('rial'|'toman'); display unit = company setting display_unit. Label is ALWAYS shown by default.
 * Mirrors app/lib/fmt.dart and server/backend/api/pb_fmt.php (shared vectors: tests/fmt_vectors.json). */
(function (root) {
  'use strict';
  var cfg = { storedUnit: 'toman', displayUnit: 'toman', digits: 'fa' };
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var LABEL = { rial: 'ریال', toman: 'تومان' };

  function configure(o) {
    if (!o) return;
    if (o.storedUnit) cfg.storedUnit = o.storedUnit === 'rial' ? 'rial' : 'toman';
    if (o.displayUnit) cfg.displayUnit = o.displayUnit === 'rial' ? 'rial' : 'toman';
    if (o.digits) cfg.digits = o.digits === 'en' ? 'en' : 'fa';
  }
  function roundHalfAway(x) { return x < 0 ? -Math.round(-x) : Math.round(x); }
  function toEn(s) {
    return String(s).replace(/[۰-۹]/g, function (c) { return String(FA.indexOf(c)); })
      .replace(/[٠-٩]/g, function (c) { return String(c.charCodeAt(0) - 0x0660); })
      .replace(/[٬،]/g, ',').replace(/٫/g, '.');
  }
  function digits(s, d) {
    d = d || cfg.digits;
    if (d !== 'fa') return s;
    return s.replace(/\d/g, function (c) { return FA[+c]; }).replace(/,/g, '٬').replace(/\./g, '٫');
  }
  function group(intStr) {
    var out = '';
    for (var i = 0; i < intStr.length; i++) { if (i > 0 && (intStr.length - i) % 3 === 0) out += ','; out += intStr[i]; }
    return out;
  }
  /** Grouped plain number (en separators then localized). dec = max decimals (trailing zeros trimmed). */
  function num(v, dec, d) {
    v = Number(v);
    if (!isFinite(v)) return '';
    dec = dec == null ? 0 : dec;
    var neg = v < 0;
    var s = Math.abs(v).toFixed(dec);
    if (s.indexOf('.') >= 0) s = s.replace(/0+$/, '').replace(/\.$/, '');
    var p = s.split('.');
    var r = group(p[0]) + (p.length > 1 ? '.' + p[1] : '');
    if (neg && /[1-9]/.test(r)) r = '-' + r;
    return digits(r, d);
  }
  /** stored amount (tenant unit) -> display-unit integer. rial->toman = /10 (rounded half away); toman->rial = x10. */
  function toDisplay(stored, storedUnit, displayUnit) {
    storedUnit = storedUnit || cfg.storedUnit; displayUnit = displayUnit || cfg.displayUnit;
    var v = Number(stored) || 0;
    if (storedUnit === displayUnit) return roundHalfAway(v);
    return storedUnit === 'rial' ? roundHalfAway(v / 10) : roundHalfAway(v * 10);
  }
  /** display-unit amount typed by the user -> stored amount. */
  function fromDisplay(shown, storedUnit, displayUnit) {
    storedUnit = storedUnit || cfg.storedUnit; displayUnit = displayUnit || cfg.displayUnit;
    var v = Number(shown) || 0;
    if (storedUnit === displayUnit) return roundHalfAway(v);
    return storedUnit === 'rial' ? roundHalfAway(v * 10) : roundHalfAway(v / 10);
  }
  function money(stored, o) {
    o = o || {};
    var su = o.storedUnit || cfg.storedUnit, du = o.displayUnit || cfg.displayUnit;
    var s = num(toDisplay(stored, su, du), 0, o.digits);
    return o.label === false ? s : s + ' ' + LABEL[du];
  }
  function kg(v, o) { o = o || {}; var s = num(v, o.dec == null ? 3 : o.dec, o.digits); return o.label === false ? s : s + ' کیلو'; }
  function percent(v, o) { o = o || {}; var d = o.digits || cfg.digits; return num(v, o.dec == null ? 2 : o.dec, d) + (d === 'fa' ? '٪' : '%'); }
  /** parse what the user typed (any digits/separators) in DISPLAY unit -> stored amount; null when empty/invalid. */
  function parseMoney(text, o) {
    o = o || {};
    var t = toEn(text == null ? '' : text).replace(/[^0-9.\-]/g, '');
    if (t === '' || t === '-' || t === '.') return null;
    var n = Number(t);
    if (!isFinite(n)) return null;
    return fromDisplay(n, o.storedUnit, o.displayUnit);
  }
  /** live grouping for an <input>: keeps digits/one dot, groups the integer part (en digits, ',' separators). */
  function liveGroup(text) {
    var t = toEn(text).replace(/[^0-9.]/g, '');
    var i = t.indexOf('.');
    var ip = i < 0 ? t : t.slice(0, i), dp = i < 0 ? '' : '.' + t.slice(i + 1).replace(/\./g, '');
    ip = ip.replace(/^0+(?=\d)/, '');
    return group(ip) + dp;
  }
  var api = { configure: configure, config: function () { return { storedUnit: cfg.storedUnit, displayUnit: cfg.displayUnit, digits: cfg.digits }; },
    num: num, money: money, kg: kg, percent: percent, toDisplay: toDisplay, fromDisplay: fromDisplay, parseMoney: parseMoney, liveGroup: liveGroup, toEn: toEn, unitLabel: function (u) { return LABEL[u || cfg.displayUnit]; } };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.PBFmt = api;
})(typeof window !== 'undefined' ? window : this);
