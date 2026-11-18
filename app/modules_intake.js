/* S8 — «اتاق انس» در وب: فرم پذیرش (بدون هیچ فیلد قیمت)، فهرست پذیرش‌ها، صفحهٔ قطعی‌سازی/قیمت‌گذاری (حسابدار/مدیر)،
 * منوی محدود نقش «اتاق انس». معادل lib/intake_form.dart + intake_list_screen.dart + intake_confirm_screen.dart + ounce_room_shell.dart.
 * فقط از اکشن‌های موجود سرور استفاده می‌کند: entity.list|save|delete روی lot_intakes / lot_intake_pcts / pct_defs،
 * intake.submit، intake.preview_price، intake.confirm، intake.reject، intake.mark_sample. سرور مرجع مجوز است؛ این فایل فقط UI را محدود می‌کند.
 * بخش «خالص» (بدون DOM) در Node با backend/tests/web_s8_test.js تست می‌شود. */
(function (root) {
  'use strict';

  // ------------------------------------------------------------------ بخش خالص
  var STATUS = { draft: 'پیش‌نویس', submitted: 'ثبت‌شده', returned: 'برگشتی برای اصلاح', confirmed: 'قطعی‌شده', rejected: 'ردشده', sample_only: 'نمونه' };
  var DEFAULT_DEFS = [ // همان چهار پیش‌فرض سرور (اگر فهرست خالی/در دسترس نبود)
    { key: 'tuchin', label: 'توچین', active: true, pricing_allowed: false },
    { key: 'dombest', label: 'دهن‌بست', active: true, pricing_allowed: false },
    { key: 'crooked', label: 'کج', active: true, pricing_allowed: false },
    { key: 'khandan', label: 'خندان', active: true, pricing_allowed: false },
  ];
  // فقط همین ستون‌ها از کلاینت می‌روند؛ ستون‌های «مال سرور» (status, code, …) هرگز
  var INTAKE_DATA_KEYS = ['category_id', 'type_name', 'gross_kg', 'small_sacks', 'large_sacks', 'ounce', 'gram', 'owner_name', 'is_sample', 'note'];

  function toEn(s) {
    var fa = '۰۱۲۳۴۵۶۷۸۹', ar = '٠١٢٣٤٥٦٧٨٩', o = String(s == null ? '' : s);
    for (var i = 0; i < 10; i++) o = o.split(fa[i]).join(String(i)).split(ar[i]).join(String(i));
    return o.replace(/[٫]/g, '.').replace(/[٬،,]/g, '').trim();
  }
  /** عدد یا null (خالی/نامعتبر) */
  function parseNum(v) {
    if (v === null || v === undefined) return null;
    if (typeof v === 'number') return isFinite(v) ? v : null;
    var s = toEn(v);
    if (s === '' || !/^-?(\d+\.?\d*|\.\d+)$/.test(s)) return null;
    return Number(s);
  }
  function uuid4() {
    var b = new Uint8Array(16), i;
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) crypto.getRandomValues(b);
    else for (i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = ''; for (i = 0; i < 16; i++) h += (b[i] < 16 ? '0' : '') + b[i].toString(16);
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  /** کد موقت تا کد نهایی سرور: ۶ حرف اول uid */
  function tempCode(uid) { return String(uid || '').replace(/-/g, '').slice(0, 6).toUpperCase(); }

  /** اعتبارسنجی فرم (قواعد سرور + نوع/دسته/صاحب الزامی، Σدرصد ≤ ۱۰۰، بدون تکرار). '' = معتبر */
  function validateIntakeForm(v) {
    if (!(Number(v.category_id) > 0)) return 'دسته را انتخاب کنید';
    var type = String(v.type_name || '').trim();
    if (!type) return 'نوع پسته را وارد کنید';
    if (type.length > 120) return 'نوع پسته حداکثر ۱۲۰ حرف باشد';
    var gross = parseNum(v.gross_kg);
    if (gross === null || gross <= 0) return 'وزن کل (ناخالص) را درست وارد کنید';
    if (gross > 100000000) return 'وزن کل بیش از حد مجاز است';
    var sm = parseNum(v.small_sacks), lg = parseNum(v.large_sacks);
    if ((v.small_sacks !== '' && v.small_sacks != null && (sm === null || sm < 0 || sm % 1 !== 0)) ||
        (v.large_sacks !== '' && v.large_sacks != null && (lg === null || lg < 0 || lg % 1 !== 0))) return 'تعداد گونی باید عدد صحیح و نامنفی باشد';
    var oz = parseNum(v.ounce);
    if (v.ounce !== '' && v.ounce != null && (oz === null || oz < 0 || oz > 100000)) return 'انس نامعتبر است';
    var gr = parseNum(v.gram);
    if (v.gram !== '' && v.gram != null && (gr === null || gr <= 0 || gr > 100)) return '«گرم» باید بیشتر از ۰ و حداکثر ۱۰۰ باشد';
    var owner = String(v.owner_name || '').trim();
    if (!owner) return v.is_sample ? 'نام صاحب نمونه الزامی است' : 'نام صاحب پسته را وارد کنید';
    if (v.is_sample && !String(v.note || '').trim()) return 'برای «نمونه» توضیح بنویسید (این نمونه برای چیست/کیست؟)';
    if (owner.length > 150) return 'نام صاحب پسته حداکثر ۱۵۰ حرف باشد';
    if (String(v.note || '').length > 500) return 'یادداشت حداکثر ۵۰۰ حرف باشد';
    var seen = {}, sum = 0, rows = v.pcts || [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      if (!r.def_key) return 'نوع درصد را انتخاب کنید';
      if (seen[r.def_key]) return 'یک نوع درصد بیش از یک‌بار آمده است';
      seen[r.def_key] = true;
      var p = parseNum(r.pct);
      if (p === null || p <= 0 || p > 100) return 'درصد هر مشخصه باید بین ۰ و ۱۰۰ باشد';
      sum += p;
      if (r.kernel_g !== '' && r.kernel_g != null) {
        var g = parseNum(r.kernel_g);
        if (g === null || g <= 0 || g > 100) return '«گرم مغز در ۱۰۰ گرم» باید بیشتر از ۰ و حداکثر ۱۰۰ باشد';
      }
    }
    if (sum > 100.0000001) return 'مجموع درصدها نمی‌تواند بیشتر از ۱۰۰ باشد';
    return '';
  }

  /** داده‌ای که برای entity.save پذیرش فرستاده می‌شود (فقط ستون‌های مجاز؛ هیچ قیمتی) */
  function intakeData(v) {
    var owner = String(v.owner_name || '').trim(), note = String(v.note || '').trim();
    var d = {
      category_id: Number(v.category_id), type_name: String(v.type_name).trim(), gross_kg: parseNum(v.gross_kg),
      small_sacks: parseNum(v.small_sacks) || 0, large_sacks: parseNum(v.large_sacks) || 0,
      ounce: parseNum(v.ounce), gram: parseNum(v.gram), owner_name: owner === '' ? null : owner, is_sample: !!v.is_sample, note: note === '' ? null : note,
    };
    var out = {}; INTAKE_DATA_KEYS.forEach(function (k) { out[k] = d[k]; });
    return out;
  }
  /**
   * فهرست مرتب عملیات ذخیره: پذیرش ← درصدها ← حذف درصدهای برداشته‌شده ← (final) intake.submit.
   * ترتیب مهم است (صف آفلاین FIFO): همهٔ ذخیره‌ها قبل از submit. existingPcts: [{uid, def_key}] ردیف‌های ذخیره‌شدهٔ قبلی.
   */
  function buildOps(o) {
    var ops = [{ action: 'entity.save', payload: { entity: 'lot_intakes', uid: o.uid, data: intakeData(o.form) } }];
    var keep = {};
    (o.form.pcts || []).forEach(function (r) {
      var uid = r.uid || uuid4(); keep[uid] = true;
      var kg = r.kernel_g === '' || r.kernel_g == null ? null : parseNum(r.kernel_g);
      ops.push({ action: 'entity.save', payload: { entity: 'lot_intake_pcts', uid: uid, data: { intake_id: o.uid, def_key: r.def_key, pct: parseNum(r.pct), kernel_g: kg } } });
    });
    (o.existingPcts || []).forEach(function (e) {
      if (!keep[e.uid]) ops.push({ action: 'entity.delete', payload: { entity: 'lot_intake_pcts', uid: e.uid } });
    });
    if (o.final) ops.push({ action: 'intake.submit', payload: { uid: o.uid } });
    return ops;
  }
  /** نمای «در صف ارسال» از روی صف آفلاین: uid → {data, pcts:[{uid,data}], submit} */
  function queuedOverlay(queue) {
    var m = {};
    (queue || []).forEach(function (e) {
      var p = e.payload || {};
      if (e.action === 'entity.save' && p.entity === 'lot_intakes') { var x = m[p.uid] = m[p.uid] || { uid: p.uid, pcts: [] }; x.data = Object.assign({}, x.data || {}, p.data || {}); }
      else if (e.action === 'entity.save' && p.entity === 'lot_intake_pcts' && p.data && p.data.intake_id) {
        var y = m[p.data.intake_id] = m[p.data.intake_id] || { uid: p.data.intake_id, pcts: [] };
        y.pcts = y.pcts.filter(function (r) { return r.uid !== p.uid; }); y.pcts.push({ uid: p.uid, data: p.data });
      } else if (e.action === 'intake.submit' && p.uid) { var z = m[p.uid] = m[p.uid] || { uid: p.uid, pcts: [] }; z.submit = true; }
    });
    return m;
  }
  /** ورودی قیمت‌گذاری → {items برای سرور، items برای موتور محلی} (q خالی = بدون تیک ⇒ در قیمت اصلی) */
  function pricingInputs(rows, base) {
    var server = [], local = [];
    rows.forEach(function (r) {
      var q = r.priced ? r.q : null; // q: مقدار ذخیره‌ای (واحد شرکت) یا null
      var it = { def_key: r.def_key, q: q };
      if (q !== null && r.gram_mode) { it.gram_mode = true; var g = parseNum(r.g); if (g !== null) it.g = g; }
      if (q !== null) server.push(it);
      var li = { pct: r.pct, q: q };
      if (q !== null && r.gram_mode) { li.gram_mode = true; var g2 = parseNum(r.g); li.g = g2 !== null ? g2 : (r.kernel_g != null ? r.kernel_g : null); }
      local.push(li);
    });
    // خرید گرمیِ پسته اصلی (base = {gram_mode, g, intake_g})
    var b = {};
    if (base && base.gram_mode) { b.base_gram_mode = true; var bg = parseNum(base.g); b.base_g = bg !== null ? bg : (base.intake_g != null ? Number(base.intake_g) : null); }
    return { server: server, local: local, base: b };
  }

  var PURE = { STATUS: STATUS, DEFAULT_DEFS: DEFAULT_DEFS, INTAKE_DATA_KEYS: INTAKE_DATA_KEYS, validateIntakeForm: validateIntakeForm, intakeData: intakeData, buildOps: buildOps, queuedOverlay: queuedOverlay, pricingInputs: pricingInputs, parseNum: parseNum, uuid4: uuid4, tempCode: tempCode };
  if (typeof window === 'undefined') { if (typeof module !== 'undefined' && module.exports) module.exports = PURE; return; }

  // ------------------------------------------------------------------ بخش UI (مرورگر)
  var $ = function (id) { return document.getElementById(id); };
  var E = function (v) { return esc(v); }; // esc سراسری app.js
  function perm(p) { return !window.PBShell || PBShell.hasPerm(p); }
  function anyPerm(a) { return a.some(perm); }
  function canConfirm() { return perm('intake.confirm') && perm('intake.price'); }
  function canCreate() { return perm('ounce.intake'); }
  function canView() { return anyPerm(['ounce.view_own', 'ounce.view_all']); }
  function roomOnly() { return !!(window.PBShell && PBShell.isOunceRoomOnly && PBShell.isOunceRoomOnly()); }
  function content() { return $('content'); }
  function fmtKg(v) { return window.PBFmt ? PBFmt.kg(v, { dec: 3 }) : String(v) + ' کیلو'; }
  function fmtMoney(v) { return window.PBFmt ? PBFmt.money(v) : String(v); }
  function when(s) { try { return s ? new Date(String(s).replace(' ', 'T')).toLocaleString('fa-IR') : '—'; } catch (e) { return '—'; } }
  function setTitle(t) { var el = $('page-title'); if (el) el.textContent = t; }
  function clearChrome() { document.querySelectorAll('.fab').forEach(function (f) { f.remove(); }); }
  function isNetErr(e) { return !e || e.status === undefined; } // بدون status ⇒ شبکه/آفلاین (نه پاسخ سرور)

  // فرم یا صفحهٔ قیمت‌گذاری باز است ⇒ «در حال ویرایش» (poll لحظه‌ای صبر می‌کند و فرم دست‌نخورده می‌ماند)
  if (window.PBRealtime) PBRealtime.addEditingProbe(function () { return !!($('ii-form') || $('ic-form')); });

  // ---- کش ساده برای آفلاین (پیشوند pb_cache_ ⇒ با خروج پاک می‌شود)
  function cacheGet(k) { try { return JSON.parse(localStorage.getItem('pb_cache_intake:' + k) || 'null'); } catch (e) { return null; } }
  function cacheSet(k, v) { try { localStorage.setItem('pb_cache_intake:' + k, JSON.stringify(v)); } catch (e) { /* اختیاری */ } }
  async function listCached(key, payload) {
    try { var r = await api('entity.list', payload); cacheSet(key, r); return { r: r, stale: false }; }
    catch (e) { var c = isNetErr(e) ? cacheGet(key) : null; if (c) return { r: c, stale: true }; throw e; }
  }
  async function loadDefs() {
    try {
      var r = await api('entity.list', { entity: 'pct_defs', limit: 200, order: { col: 'sort', dir: 'ASC' } });
      cacheSet('defs', r);
      var it = (r.items || []).filter(function (d) { return !d.deleted; });
      return it.length ? it : DEFAULT_DEFS;
    } catch (e) { var c = cacheGet('defs'); return c && (c.items || []).length ? c.items : DEFAULT_DEFS; }
  }
  async function loadPcts(uid) {
    try { var r = await api('entity.list', { entity: 'lot_intake_pcts', filters: { intake_id: uid }, limit: 200, order: { col: 'id', dir: 'ASC' } }); return (r.items || []).filter(function (x) { return !x.deleted; }); }
    catch (e) { return null; }
  }
  function defLabel(defs, key) { var d = defs.find(function (x) { return x.key === key; }); return d ? d.label : key; }

  // ================================================================== فرم پذیرش
  async function openForm(c, opts) {
    opts = opts || {};
    clearChrome();
    var row = opts.row || null, uid = (row && row.uid) || uuid4();
    var ov = queuedOverlay(readQueue())[uid] || null; // پیش‌نویسِ هنوز ارسال‌نشده
    var base = Object.assign({}, row || {}, ov && ov.data ? ov.data : {});
    var defs = await loadDefs();
    var pcts = [], existingPcts = [];
    if (row) { var sp = await loadPcts(uid); if (sp) { pcts = sp.map(function (p) { return { uid: p.uid, def_key: p.def_key, pct: p.pct, kernel_g: p.kernel_g == null ? '' : p.kernel_g }; }); existingPcts = pcts.map(function (p) { return { uid: p.uid, def_key: p.def_key }; }); } }
    if (ov && ov.pcts.length) { // درصدهای در صف را با ذخیره‌شده‌ها ادغام کن
      ov.pcts.forEach(function (q) { var i = pcts.findIndex(function (p) { return p.uid === q.uid; }); var n = { uid: q.uid, def_key: q.data.def_key, pct: q.data.pct, kernel_g: q.data.kernel_g == null ? '' : q.data.kernel_g }; if (i >= 0) pcts[i] = n; else pcts.push(n); });
    }
    var selCat = Number(base.category_id) || (state.categories[0] && state.categories[0].id) || 0;
    var f = (n, d) => (base[n] == null ? (d == null ? '' : d) : base[n]);

    setTitle(row ? (row.status === 'returned' ? 'اصلاح پذیرش برگشتی' : 'ویرایش پیش‌نویس پذیرش') : 'پذیرش جدید');
    c.innerHTML = '<div id="ii-form">'
      + (row && row.status === 'returned' ? '<div class="card" style="background:#fff4e0"><b>برگشت‌خورده از مدیر/حسابدار:</b><div>' + E(row.reject_reason || '') + '</div><div class="muted">اصلاح کنید و دوباره «ثبت نهایی» بزنید؛ کد پسته تغییر نمی‌کند.</div></div>' : '')
      + '<div class="field"><label>دسته *</label><div class="tag-row" id="ii-cats">' + categoryTags(selCat) + '</div><button type="button" class="tag" id="ii-newcat">+ دستهٔ جدید</button></div>'
      + '<div class="field"><label>نوع *</label><div class="tag-row" id="ii-types"></div><input type="text" id="ii-type" maxlength="120" placeholder="یا نام نوع را بنویسید" value="' + E(f('type_name')) + '"><button type="button" class="tag" id="ii-newtype">+ نوع جدید در این دسته</button></div>'
      + '<div class="field"><label>وزن کل (ناخالص) به کیلو *</label><input type="text" inputmode="decimal" id="ii-gross" value="' + E(f('gross_kg')) + '"></div>'
      + '<div class="row2"><div class="field"><label>گونی کوچک</label><input type="text" inputmode="numeric" id="ii-small" value="' + E(f('small_sacks', 0)) + '"></div>'
      + '<div class="field"><label>گونی بزرگ</label><input type="text" inputmode="numeric" id="ii-large" value="' + E(f('large_sacks', 0)) + '"></div></div>'
      + '<div class="row2"><div class="field"><label>انس (اختیاری)</label><input type="text" inputmode="decimal" id="ii-ounce" value="' + E(f('ounce')) + '"></div>'
      + '<div class="field"><label>گرم (اختیاری)</label><input type="text" inputmode="decimal" id="ii-gram" value="' + E(f('gram')) + '"></div></div>'
      + '<label class="ii-chk"><input type="checkbox" id="ii-sample"' + (base.is_sample ? ' checked' : '') + '> نمونه (قطعی و وارد موجودی نمی‌شود)</label>'
      + '<div class="field"><label>نام صاحب پسته (الزامی)</label><input type="text" id="ii-owner" maxlength="150" value="' + E(f('owner_name')) + '"></div>'
      + '<div class="section-title">درصدها (مشخصات)</div><div id="ii-pcts"></div>'
      + '<div class="ii-actions-row"><button type="button" class="btn btn-secondary" id="ii-addpct">+ افزودن مشخصات</button><button type="button" class="tag" id="ii-newdef">+ تعریف مشخصهٔ جدید</button></div>'
      + '<div class="muted" id="ii-sum" style="margin:4px 0 10px"></div>'
      + '<div class="field"><label id="ii-note-l">یادداشت</label><textarea id="ii-note" rows="2" maxlength="500">' + E(f('note')) + '</textarea></div>'
      + '<div class="error" id="ii-err"></div>'
      + '<button type="button" class="btn btn-primary" id="ii-final">ثبت نهایی</button>'
      + '<button type="button" class="btn btn-secondary" id="ii-draft">ذخیرهٔ پیش‌نویس</button>'
      + '</div>';

    function typeTags() {
      var list = state.types.filter(function (t) { return t.category_id === selCat; });
      $('ii-types').innerHTML = list.map(function (t) { return '<span class="tag' + (t.name === $('ii-type').value.trim() ? ' active' : '') + '" data-name="' + E(t.name) + '">' + E(t.name) + '</span>'; }).join('');
    }
    typeTags();
    $('ii-cats').addEventListener('click', function (e) {
      var t = e.target.closest('[data-cid]'); if (!t) return;
      selCat = Number(t.dataset.cid);
      c.querySelectorAll('#ii-cats .tag[data-cid]').forEach(function (x) { x.classList.toggle('active', Number(x.dataset.cid) === selCat); });
      typeTags();
    });
    $('ii-types').addEventListener('click', function (e) {
      var t = e.target.closest('[data-name]'); if (!t) return;
      $('ii-type').value = t.dataset.name; typeTags();
    });
    $('ii-type').addEventListener('input', typeTags);
    // اتاق انس می‌تواند دسته/نوع جدید تعریف کند (نیازمند اینترنت)
    $('ii-newcat').onclick = async function () {
      if (!navigator.onLine) { toast('تعریف دستهٔ جدید به اینترنت نیاز دارد'); return; }
      var name = prompt('نام دستهٔ جدید:');
      if (!name || !name.trim()) return;
      try {
        var res = await api('categories.create', { name: name.trim() });
        state.categories.push({ id: res.id, name: name.trim(), preset_key: null }); selCat = res.id;
        $('ii-cats').innerHTML = categoryTags(selCat); typeTags();
      } catch (e) { toast(e.message); }
    };
    $('ii-newtype').onclick = async function () {
      if (!navigator.onLine) { toast('تعریف نوع جدید به اینترنت نیاز دارد'); return; }
      if (!(selCat > 0)) { toast('ابتدا دسته را انتخاب کنید'); return; }
      var name = prompt('نام نوع جدید:');
      if (!name || !name.trim()) return;
      try {
        var res = await api('types.create', { category_id: selCat, name: name.trim() });
        state.types.push({ id: res.id, category_id: selCat, name: name.trim() });
        $('ii-type').value = name.trim(); typeTags();
      } catch (e) { toast(e.message); }
    };
    var smp = $('ii-sample');
    function sampleHint() { var on = smp.checked; $('ii-note-l').textContent = on ? 'توضیح نمونه (الزامی: این نمونه برای چیست/کیست؟)' : 'یادداشت'; }
    smp.addEventListener('change', sampleHint); sampleHint();

    function activeDefs() { return defs.filter(function (d) { return d.active !== false; }); }
    function defOptions(sel) {
      var used = {}; pcts.forEach(function (p) { used[p.def_key] = true; });
      return defs.filter(function (d) { return d.key === sel || (d.active !== false && !used[d.key]); })
        .map(function (d) { return '<option value="' + E(d.key) + '"' + (d.key === sel ? ' selected' : '') + '>' + E(d.label) + '</option>'; }).join('');
    }
    function drawPcts() {
      var box = $('ii-pcts');
      box.innerHTML = pcts.length ? pcts.map(function (p, i) {
        return '<div class="card ii-pct" data-i="' + i + '"><div class="row2">'
          + '<div class="field"><label>نوع</label><select data-f="def_key">' + defOptions(p.def_key) + '</select></div>'
          + '<div class="field"><label>درصد</label><input type="text" inputmode="decimal" data-f="pct" value="' + E(p.pct) + '"></div></div>'
          + '<div class="row2"><div class="field"><label>گرم مغز در ۱۰۰ گرم (اختیاری)</label><input type="text" inputmode="decimal" data-f="kernel_g" value="' + E(p.kernel_g) + '"></div>'
          + '<div class="field"><label>&nbsp;</label><button type="button" class="btn btn-danger" data-rm="' + i + '" style="margin:0">حذف</button></div></div></div>';
      }).join('') : '<div class="muted">درصدی ثبت نشده است (اختیاری)</div>';
      sum();
    }
    function sum() {
      var s = 0; pcts.forEach(function (p) { var n = parseNum(p.pct); if (n !== null) s += n; });
      var el = $('ii-sum'); if (!el) return;
      el.textContent = 'جمع درصدها: ' + (Math.round(s * 1000) / 1000).toLocaleString('fa-IR') + '٪';
      el.style.color = s > 100.0000001 ? 'var(--red)' : '';
    }
    $('ii-pcts').addEventListener('input', function (e) {
      var card = e.target.closest('.ii-pct'); if (!card) return;
      pcts[Number(card.dataset.i)][e.target.dataset.f] = e.target.value; sum();
    });
    $('ii-pcts').addEventListener('change', function (e) {
      var card = e.target.closest('.ii-pct'); if (!card || e.target.dataset.f !== 'def_key') return;
      pcts[Number(card.dataset.i)].def_key = e.target.value; drawPcts();
    });
    $('ii-pcts').addEventListener('click', function (e) {
      var b = e.target.closest('[data-rm]'); if (!b) return;
      pcts.splice(Number(b.dataset.rm), 1); drawPcts();
    });
    $('ii-addpct').onclick = function () {
      var used = {}; pcts.forEach(function (p) { used[p.def_key] = true; });
      var d = activeDefs().find(function (x) { return !used[x.key]; });
      if (!d) { toast('همهٔ مشخصه‌های تعریف‌شده اضافه شده‌اند؛ «تعریف مشخصهٔ جدید» را بزنید'); return; }
      pcts.push({ uid: uuid4(), def_key: d.key, pct: '', kernel_g: '' }); drawPcts();
    };
    $('ii-newdef').onclick = async function () {
      if (!navigator.onLine) { toast('تعریف مشخصهٔ جدید به اینترنت نیاز دارد'); return; }
      var name = prompt('نام مشخصهٔ جدید (مثلاً پوکی):');
      if (!name || !name.trim()) return;
      try {
        var r = await api('entity.save', { entity: 'pct_defs', uid: uuid4(), data: { label: name.trim(), active: true } });
        if (r && r.item) { defs.push(r.item); pcts.push({ uid: uuid4(), def_key: r.item.key, pct: '', kernel_g: '' }); drawPcts(); }
      } catch (e) { toast(e.message); }
    };
    drawPcts();

    function collect() {
      return { category_id: selCat, type_name: $('ii-type').value, gross_kg: $('ii-gross').value, small_sacks: $('ii-small').value, large_sacks: $('ii-large').value,
        ounce: $('ii-ounce').value, gram: $('ii-gram').value, is_sample: $('ii-sample').checked, owner_name: $('ii-owner').value, note: $('ii-note').value,
        pcts: pcts.map(function (p) { return { uid: p.uid, def_key: p.def_key, pct: p.pct, kernel_g: p.kernel_g }; }) };
    }
    var busy = false;
    async function save(final) {
      if (busy) return;
      var form = collect(), err = validateIntakeForm(form);
      $('ii-err').textContent = err;
      if (err) return;
      if (final && !confirm('ثبت نهایی شود؟ بعد از ثبت، ویرایش پذیرش ممکن نیست.')) return;
      busy = true; $('ii-final').disabled = true; $('ii-draft').disabled = true;
      try {
        var ops = buildOps({ uid: uid, form: form, existingPcts: existingPcts, final: final }), queued = false, code = null;
        for (var i = 0; i < ops.length; i++) {
          var r = await api(ops[i].action, ops[i].payload);
          if (r && r.queued) queued = true;
          if (ops[i].action === 'intake.submit' && r && r.code) code = r.code;
        }
        if (final) done(code, queued); else { toast(queued ? 'پیش‌نویس در صف ذخیره شد' : '✓ پیش‌نویس ذخیره شد'); showList(c, {}); }
      } catch (e) {
        $('ii-err').textContent = e.message || 'ذخیره نشد'; busy = false;
        var a = $('ii-final'), b = $('ii-draft'); if (a) a.disabled = false; if (b) b.disabled = false;
      }
    }
    function done(code, queued) {
      var typed = $('ii-type') ? $('ii-type').value.trim() : '';
      c.innerHTML = '<div class="card ii-done"><div style="font-size:42px">✅</div><h3>' + (queued ? 'در صف ارسال ذخیره شد' : 'پذیرش ثبت شد') + '</h3>'
        + '<div>' + E(typed) + '</div>'
        + (code ? '<div class="ii-code">کد پسته: <b>' + E(code) + '</b></div>'
          : '<div class="ii-code">کد موقت: <b>' + E(tempCode(uid)) + '</b><div class="muted">کد نهایی را سرور پس از ارسال می‌دهد و در فهرست دیده می‌شود.</div></div>')
        + '<button type="button" class="btn btn-primary" id="ii-next">پذیرش بعدی</button><button type="button" class="btn btn-secondary" id="ii-tolist">پذیرش‌ها</button></div>';
      $('ii-next').onclick = function () { openForm(c, {}); };
      $('ii-tolist').onclick = function () { showList(c, {}); };
    }
    $('ii-final').onclick = function () { save(true); };
    $('ii-draft').onclick = function () { save(false); };
    // Enter ⇒ فیلد بعدی (ورود سریع روی دسکتاپ)
    $('ii-form').addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') return;
      e.preventDefault();
      var all = Array.prototype.slice.call($('ii-form').querySelectorAll('input:not([type=checkbox]):not(:disabled), select, textarea')), i = all.indexOf(e.target);
      if (i >= 0 && all[i + 1]) all[i + 1].focus();
    });
    if (window.PBRealtime) PBRealtime.setView(null); // فرم باز است: تازه‌سازی خودکار ممنوع (probe هم گارد می‌کند)
  }

  // ================================================================== فهرست
  var FILTERS = [['', 'همه'], ['queued', 'در صف ارسال'], ['draft', 'پیش‌نویس'], ['submitted', 'ثبت‌شده'], ['returned', 'برگشتی'], ['confirmed', 'قطعی‌شده'], ['rejected', 'ردشده'], ['sample_only', 'نمونه']];
  var listState = { filter: '', q: '' };

  async function showList(c, opts) {
    if (opts && opts.filter != null) listState.filter = opts.filter;
    clearChrome();
    setTitle(roomOnly() ? 'پذیرش‌ها' : 'پذیرش‌های اتاق انس');
    var offset = 0, items = [], total = 0, stale = false, token = 0;
    c.innerHTML = '<div class="seg-tabs ii-filters">' + FILTERS.map(function (f) { return '<button type="button" class="seg-tab' + (listState.filter === f[0] ? ' active' : '') + '" data-f="' + f[0] + '">' + f[1] + '</button>'; }).join('') + '</div>'
      + '<div class="field"><input type="search" id="il-q" placeholder="جستجو: کد، صاحب، نوع، یادداشت" value="' + E(listState.q) + '"></div>'
      + '<div id="il-body" class="spinner">در حال بارگذاری...</div><div id="il-more"></div>';
    c.querySelector('.ii-filters').addEventListener('click', function (e) { var b = e.target.closest('[data-f]'); if (!b) return; listState.filter = b.dataset.f; showList(c, {}); });
    var qt = null;
    $('il-q').addEventListener('input', function () { clearTimeout(qt); var v = this.value; qt = setTimeout(function () { listState.q = v; draw(true); }, 350); });
    if (canCreate() && !roomOnly()) fab('+', function () { openForm(content(), {}); });
    else if (canCreate()) fab('+', function () { roomGo('ir-new'); });

    async function draw(reset) {
      var my = ++token;
      if (reset) { offset = 0; items = []; }
      var body = $('il-body'); if (!body) return;
      try {
        var pl = { entity: 'lot_intakes', limit: 50, offset: offset };
        if (listState.q.trim()) pl.q = listState.q.trim();
        if (listState.filter && listState.filter !== 'queued') pl.filters = { status: listState.filter };
        var res = await listCached('list:' + listState.filter + ':' + listState.q.trim() + ':' + offset, pl);
        if (my !== token || !$('il-body')) return;
        stale = res.stale; total = res.r.total || 0;
        items = items.concat(res.r.items || []);
        offset = res.r.next_offset != null ? res.r.next_offset : items.length;
        paint();
      } catch (e) { if (my === token && $('il-body')) $('il-body').innerHTML = '<div class="empty-state">' + E(e.message || 'خطا') + '</div>'; }
    }
    function paint() {
      var ov = queuedOverlay(readQueue()), seen = {}, rows = [];
      items.forEach(function (r) { seen[r.uid] = true; rows.push(Object.assign({}, r, { _q: ov[r.uid] ? !!ov[r.uid].submit : false })); });
      if (!listState.q.trim() && (!listState.filter || listState.filter === 'queued' || listState.filter === 'draft' || listState.filter === 'submitted')) {
        Object.keys(ov).forEach(function (u) { if (!seen[u] && ov[u].data) rows.unshift(Object.assign({ uid: u, status: ov[u].submit ? 'submitted' : 'draft', code: null, _local: true, _q: true }, ov[u].data)); });
      }
      if (listState.filter === 'queued') rows = rows.filter(function (r) { return r._q; });
      var body = $('il-body'); body.className = '';
      body.innerHTML = (stale ? '<div class="locked-note">آفلاین: آخرین فهرست ذخیره‌شده نمایش داده می‌شود</div>' : '')
        + (rows.length ? rows.map(function (r) {
          return '<div class="list-item ii-row" data-uid="' + E(r.uid) + '" style="cursor:pointer"><div>'
            + '<div class="title">' + E(r.type_name || '—') + (r.is_sample ? ' <span class="badge">نمونه</span>' : '') + (r._q ? ' <span class="badge unpaid">⏳ در صف ارسال</span>' : '') + '</div>'
            + '<div class="meta">' + (r.code ? 'کد ' + E(r.code) : (r._local ? 'کد موقت ' + E(tempCode(r.uid)) : 'بدون کد')) + (r.owner_name ? ' · ' + E(r.owner_name) : '') + (r.created_by_name ? ' · ' + E(r.created_by_name) : '') + '</div></div>'
            + '<div style="text-align:left"><div>' + fmtKg(r.gross_kg) + '</div><div class="meta"><span class="badge ' + (r.status === 'confirmed' ? 'paid' : (r.status === 'rejected' ? 'unpaid' : '')) + '">' + E(STATUS[r.status] || r.status) + '</span></div></div></div>';
        }).join('') : '<div class="empty-state">موردی پیدا نشد</div>');
      body.querySelectorAll('.ii-row').forEach(function (el) {
        el.addEventListener('click', function () { var r = rows.find(function (x) { return x.uid === el.dataset.uid; }); if (r) openRow(c, r); });
      });
      var more = $('il-more');
      more.innerHTML = items.length < total ? '<button type="button" class="btn btn-secondary" id="il-moreb">بیشتر (' + (total - items.length).toLocaleString('fa-IR') + ')</button>' : '';
      if ($('il-moreb')) $('il-moreb').onclick = function () { draw(false); };
    }
    if (window.PBRealtime) PBRealtime.setView({ refresh: function () { return draw(true); }, full: false, entities: ['lot_intakes', 'lot_intake_pcts'] });
    await draw(true);
  }

  function openRow(c, r) {
    if (r.status === 'submitted' && canConfirm() && !r.is_sample && !r._local) return openConfirm(c, r);
    if ((r.status === 'draft' || r.status === 'returned') && perm('ounce.edit_own') && !r._q) return openForm(c, { row: r._local ? null : r });
    if (r.status === 'draft' && r._q) { toast('این پیش‌نویس در صف ارسال است؛ بعد از ارسال می‌توانید آن را باز کنید'); return; }
    return openDetail(c, r);
  }

  async function openDetail(c, r) {
    clearChrome(); setTitle('جزئیات پذیرش'); if (window.PBRealtime) PBRealtime.setView(null);
    var defs = await loadDefs(), pcts = (await loadPcts(r.uid)) || [];
    c.innerHTML = '<div class="card">'
      + row2('کد پسته', r.code || (r._local ? tempCode(r.uid) + ' (موقت)' : '—')) + row2('وضعیت', STATUS[r.status] || r.status)
      + row2('نوع', r.type_name) + row2('وزن کل', fmtKg(r.gross_kg)) + row2('گونی کوچک / بزرگ', (r.small_sacks || 0) + ' / ' + (r.large_sacks || 0))
      + (r.ounce != null ? row2('انس', r.ounce) : '') + (r.gram != null ? row2('گرم', r.gram) : '')
      + row2('صاحب پسته', r.owner_name || (r.is_sample ? 'نمونه' : '—')) + row2('ثبت‌کننده', r.created_by_name || '—')
      + (r.confirmed_by_name ? row2('تصمیم‌گیرنده', r.confirmed_by_name + ' · ' + when(r.confirmed_at)) : '')
      + (r.reject_reason ? row2('دلیل رد', r.reject_reason) : '') + (r.note ? row2('یادداشت', r.note) : '')
      + pcts.map(function (p) { return row2(defLabel(defs, p.def_key), p.pct + '٪' + (p.kernel_g != null ? ' · مغز ' + p.kernel_g + ' گرم' : '')); }).join('')
      + '</div><button type="button" class="btn btn-secondary" id="id-back">بازگشت</button>';
    $('id-back').onclick = function () { showList(c, {}); };
  }
  function row2(a, b) { return '<div class="card-row"><span>' + E(a) + '</span><span>' + E(b) + '</span></div>'; }

  // ================================================================== قطعی‌سازی / قیمت‌گذاری (T2)
  async function openConfirm(c, r) {
    clearChrome(); setTitle('قیمت‌گذاری و قطعی‌سازی'); if (window.PBRealtime) PBRealtime.setView(null);
    c.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
    var defs = await loadDefs(), pcts = await loadPcts(r.uid);
    if (!pcts) { c.innerHTML = '<div class="empty-state">برای قیمت‌گذاری اینترنت لازم است.</div><button type="button" class="btn btn-secondary" id="ic-back0">بازگشت</button>'; $('ic-back0').onclick = function () { showList(c, {}); }; return; }
    var rows = pcts.map(function (p) {
      var d = defs.find(function (x) { return x.key === p.def_key; }) || {};
      return { def_key: p.def_key, label: d.label || p.def_key, pct: Number(p.pct), kernel_g: p.kernel_g == null ? null : Number(p.kernel_g), allowed: true, priced: false, q: null, qtext: '', gram_mode: false, g: '' };
    });
    c.innerHTML = '<div id="ic-form">'
      + '<div class="card">' + row2('کد پسته', r.code || '—') + row2('نوع', r.type_name) + row2('وزن کل', fmtKg(r.gross_kg)) + row2('صاحب پسته', r.owner_name || '—') + row2('ثبت‌کننده', r.created_by_name || '—') + '</div>'
      + '<div class="field"><label>قیمت پایه هر کیلو (' + E(window.PBFmt ? PBFmt.unitLabel() : '') + ') *</label><input type="text" inputmode="decimal" id="ic-base" placeholder="قیمت نوع اصلی"></div>'
      + '<label class="ii-chk"><input type="checkbox" id="ic-bgm"> خرید گرمی (پسته اصلی)</label>'
      + '<div class="field hidden" id="ic-bg-box"><label>گرم مغز پسته اصلی در ۱۰۰ گرم' + (r.gram != null ? ' (ثبت اتاق انس: ' + r.gram + ')' : '') + '</label><input type="text" inputmode="decimal" id="ic-bg" value="' + E(r.gram != null ? String(r.gram) : '') + '"></div>'
      + '<div class="section-title">درصدها</div><div id="ic-rows">' + (rows.length ? '' : '<div class="muted">درصدی ثبت نشده؛ کل پسته با قیمت پایه محاسبه می‌شود.</div>') + '</div>'
      + '<div class="card" id="ic-result"><div class="muted">پس از وارد کردن قیمت پایه محاسبه می‌شود.</div></div>'
      + '<div class="field hidden" id="ic-wh-box"><label>انبار مقصد</label><select id="ic-wh"></select></div>'
      + '<div class="error" id="ic-err"></div>'
      + '<button type="button" class="btn btn-primary" id="ic-confirm" disabled>قطعی‌سازی و افزودن به موجودی</button>'
      + '<div class="row2"><button type="button" class="btn btn-secondary" id="ic-sample">فقط نمونه</button><button type="button" class="btn btn-danger" id="ic-reject">رد / برگشت</button></div>'
      + '<button type="button" class="btn btn-secondary" id="ic-back">بازگشت</button></div>';

    var box = $('ic-rows');
    box.innerHTML = rows.map(function (x, i) {
      return '<div class="card ic-row" data-i="' + i + '"><div class="card-row"><b>' + E(x.label) + '</b><span>' + x.pct.toLocaleString('fa-IR') + '٪' + (x.kernel_g != null ? ' · مغز ' + x.kernel_g.toLocaleString('fa-IR') + ' گرم' : '') + '</span></div>'
        + (x.allowed ? '<label class="ii-chk"><input type="checkbox" data-f="priced"> قیمت جدا برای این درصد</label>'
          + '<div class="ic-sub hidden"><div class="field"><label>قیمت هر کیلو (' + E(window.PBFmt ? PBFmt.unitLabel() : '') + ')</label><input type="text" inputmode="decimal" data-f="qtext"></div>'
          + '<label class="ii-chk"><input type="checkbox" data-f="gram_mode"> خرید گرمی</label>'
          + '<div class="field ic-g hidden"><label>گرم مغز در ۱۰۰ گرم' + (x.kernel_g != null ? ' (ثبت اتاق انس: ' + x.kernel_g + ')' : '') + '</label><input type="text" inputmode="decimal" data-f="g" value="' + E(x.kernel_g != null ? String(x.kernel_g) : '') + '"></div></div>'
          : '<div class="muted" style="font-size:12px">در قیمت اصلی محاسبه می‌شود</div>') + '</div>';
    }).join('');

    var P = null, srv = null, seq = 0, timer = null, srvErr = '', whUid = '';
    function paintWh() {
      var list = (srv && srv.res && srv.res.warehouses) || [], box = $('ic-wh-box'), sel = $('ic-wh'); if (!box || !sel) return;
      if (!list.length) { box.classList.add('hidden'); return; }
      if (!whUid || !list.some(function (w) { return w.uid === whUid; })) whUid = list[0].uid; // پیش‌فرض اول است
      sel.innerHTML = list.map(function (w) { return '<option value="' + E(w.uid) + '"' + (w.uid === whUid ? ' selected' : '') + '>' + E(w.name) + (w.is_default ? ' (پیش‌فرض)' : '') + '</option>'; }).join('');
      box.classList.remove('hidden');
    }
    var bs = { gram_mode: false, g: r.gram != null ? String(r.gram) : '', intake_g: r.gram != null ? Number(r.gram) : null };
    function curKey() { var inp = pricingInputs(rows, bs); return JSON.stringify({ P: P, items: inp.server, base: inp.base }); }
    function localCalc() {
      if (P === null || !srv || !srv.res) return null;
      return PBPricing.priceBlend({ base_price: P, weight_kg: srv.res.weight_kg, ref_gram: srv.res.ref_gram, items: pricingInputs(rows, bs).local, base_gram_mode: bs.gram_mode, base_g: pricingInputs(rows, bs).base.base_g });
    }
    function paint() {
      var res = $('ic-result'); if (!res) return;
      var okBtn = $('ic-confirm'), lc = localCalc(), key = curKey();
      if (P === null) { res.innerHTML = '<div class="muted">پس از وارد کردن قیمت پایه محاسبه می‌شود.</div>'; okBtn.disabled = true; return; }
      var fresh = srv && srv.key === key;
      var html = '';
      if (srvErr) html += '<div class="error">' + E(srvErr) + '</div>';
      if (lc && lc.ok) {
        html += row2('میانگین هر کیلو', fmtMoney(lc.avg_per_kg)) + row2('وزن خالص', fmtKg(srv.res.weight_kg)) + row2('مبلغ کل', fmtMoney(lc.total)) + row2('گرم مرجع (R)', srv.res.ref_gram);
        if (!fresh) html += '<div class="muted">در حال تأیید با سرور...</div>';
        else if (lc.avg_per_kg !== srv.res.avg_per_kg || lc.total !== srv.res.total) html += '<div class="error">محاسبهٔ سرور فرق دارد (' + E(fmtMoney(srv.res.avg_per_kg)) + ')؛ مرجع، سرور است. دوباره بررسی کنید.</div>';
      } else if (lc && !lc.ok) html += '<div class="error">' + E(PBPricing.ERRORS[lc.error] || 'ورودی نامعتبر') + '</div>';
      else if (!srvErr) html += '<div class="muted">در حال محاسبه با سرور...</div>';
      res.innerHTML = html; paintWh();
      okBtn.disabled = !(fresh && lc && lc.ok && lc.avg_per_kg === srv.res.avg_per_kg && lc.total === srv.res.total);
    }
    function schedulePreview() {
      clearTimeout(timer); $('ic-confirm').disabled = true; srvErr = '';
      if (P === null) { paint(); return; }
      var my = ++seq, key = curKey(), inp = pricingInputs(rows, bs);
      paint();
      timer = setTimeout(async function () {
        try {
          var res = await api('intake.preview_price', Object.assign({ uid: r.uid, base_price: P, items: inp.server }, inp.base));
          if (my !== seq || !$('ic-form')) return; // پاسخ کهنه نادیده
          srv = { key: key, res: res }; srvErr = '';
        } catch (e) { if (my !== seq) return; srv = null; srvErr = e.message || 'پیش‌نمایش ناموفق'; }
        paint();
      }, 500);
    }
    var base = $('ic-base');
    base.addEventListener('input', function () {
      var pos = base.selectionStart, before = base.value.length;
      base.value = PBFmt.liveGroup(base.value);
      try { var np = Math.max(0, pos + base.value.length - before); base.setSelectionRange(np, np); } catch (e) { /* نادیده */ }
      var v = PBFmt.parseMoney(base.value); P = v !== null && v > 0 ? v : null; schedulePreview();
    });
    $('ic-wh').addEventListener('change', function (e) { whUid = e.target.value; });
    $('ic-bgm').addEventListener('change', function (e) { bs.gram_mode = e.target.checked; $('ic-bg-box').classList.toggle('hidden', !bs.gram_mode); schedulePreview(); });
    $('ic-bg').addEventListener('input', function (e) { bs.g = e.target.value; schedulePreview(); });
    function onRow(e) {
      var card = e.target.closest('.ic-row'); if (!card) return;
      var x = rows[Number(card.dataset.i)], f = e.target.dataset.f; if (!f) return;
      if (f === 'priced' || f === 'gram_mode') x[f] = e.target.checked; else x[f] = e.target.value;
      if (f === 'qtext') { e.target.value = PBFmt.liveGroup(e.target.value); x.q = PBFmt.parseMoney(e.target.value); }
      if (f === 'priced') {
        card.querySelector('.ic-sub').classList.toggle('hidden', !x.priced);
        if (!x.priced) { x.q = null; } else {
          var qi = card.querySelector('[data-f=qtext]'); x.q = PBFmt.parseMoney(qi.value);
          // گرمِ ثبت‌شده در اتاق انس خودکار اعمال می‌شود (دوباره وارد نمی‌شود)
          if (x.kernel_g != null && !x.gram_mode) { x.gram_mode = true; x.g = String(x.kernel_g); card.querySelector('[data-f=gram_mode]').checked = true; card.querySelector('.ic-g').classList.remove('hidden'); card.querySelector('[data-f=g]').value = x.g; }
        }
      }
      if (f === 'gram_mode') card.querySelector('.ic-g').classList.toggle('hidden', !x.gram_mode);
      schedulePreview();
    }
    box.addEventListener('input', onRow); box.addEventListener('change', onRow);

    $('ic-back').onclick = function () { clearTimeout(timer); showList(c, {}); };
    $('ic-confirm').onclick = async function () {
      var inp = pricingInputs(rows, bs);
      var qd = rows.some(function (x) { return x.priced && x.q === null; });
      if (qd) { $('ic-err').textContent = 'برای درصدهای «قیمت جدا» مبلغ را وارد کنید'; return; }
      if (!confirm('پذیرش قطعی می‌شود و به موجودی انبار اضافه می‌گردد. ادامه می‌دهید؟')) return;
      $('ic-confirm').disabled = true; $('ic-err').textContent = '';
      try {
        var res = await api('intake.confirm', Object.assign({ uid: r.uid, base_price: P, items: inp.server, warehouse_uid: whUid || undefined }, inp.base));
        toast(res && res.already ? 'این پذیرش قبلاً قطعی شده بود' : '✓ پذیرش قطعی شد و به موجودی اضافه گردید');
        showList(c, {});
      } catch (e) { $('ic-err').textContent = e.message || 'قطعی‌سازی ناموفق'; paint(); }
    };
    $('ic-reject').onclick = async function () {
      var reason = prompt('دلیل رد/برگشت پذیرش (الزامی، حداکثر ۲۵۰ حرف):');
      if (reason === null) return; reason = reason.trim();
      if (!reason || reason.length > 250) { $('ic-err').textContent = 'دلیل رد را وارد کنید (حداکثر ۲۵۰ حرف)'; return; }
      // تأیید = برگشت برای اصلاح (اتاق انس ویرایش می‌کند)؛ انصراف = رد قطعی (بسته می‌شود)
      var fin = !confirm('«برگشت برای اصلاح»؟\n\nOK = به اتاق انس برگردد تا ویرایش و دوباره ثبت کند\nCancel = ادامه برای رد قطعی');
      if (fin && !confirm('رد قطعی: پذیرش بسته می‌شود و دیگر قابل ویرایش نیست. مطمئنید؟')) return;
      try { await api('intake.reject', { uid: r.uid, reason: reason, final: fin }); toast(fin ? 'پذیرش رد قطعی شد' : 'پذیرش برای اصلاح برگشت داده شد'); showList(c, {}); } catch (e) { $('ic-err').textContent = e.message || 'رد ناموفق'; }
    };
    $('ic-sample').onclick = async function () {
      if (!confirm('این پسته «فقط نمونه» می‌شود و وارد موجودی نخواهد شد. ادامه می‌دهید؟')) return;
      var sn = prompt('توضیح نمونه (الزامی): این نمونه برای چیست/کیست؟');
      if (sn === null) return; sn = sn.trim();
      if (!sn || sn.length > 400) { $('ic-err').textContent = 'برای نمونه توضیح لازم است (حداکثر ۴۰۰ حرف)'; return; }
      try { await api('intake.mark_sample', { uid: r.uid, note: sn }); toast('به‌عنوان نمونه ثبت شد'); showList(c, {}); } catch (e) { $('ic-err').textContent = e.message || 'ناموفق'; }
    };
  }

  // ================================================================== صفحهٔ اصلی (کارت «در انتظار قطعی‌سازی»)
  async function decorateHome(c) {
    if (!canConfirm()) return;
    try {
      var r = await api('entity.list', { entity: 'lot_intakes', filters: { status: 'submitted', is_sample: false }, limit: 1 });
      var n = Number(r.total) || 0;
      if (!n || !$('content') || $('ii-pending')) return;
      var card = document.createElement('div');
      card.id = 'ii-pending'; card.className = 'card'; card.style.cssText = 'cursor:pointer;background:#fff4e0';
      card.innerHTML = '<div class="card-row"><b>🧪 پذیرش‌های در انتظار قطعی‌سازی</b><b>' + n.toLocaleString('fa-IR') + ' ›</b></div>';
      card.onclick = function () { listState.filter = 'submitted'; PBShell.openModule('ounce.intakes'); };
      c.insertBefore(card, c.firstChild);
    } catch (e) { /* بدون کارت */ }
  }

  // ================================================================== منوی محدود «اتاق انس»
  var ROOM_TABS = [['ir-new', '➕', 'پذیرش جدید'], ['ir-list', '🧾', 'پذیرش‌ها']];
  function roomGo(tab) {
    var c = content(); if (!c) return;
    document.querySelectorAll('#room-tabbar .tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab); });
    if (tab === 'ir-list') showList(c, {}); else openForm(c, {});
  }
  function applyRole() {
    var only = roomOnly();
    document.body.classList.toggle('room-only', only);
    var nav = $('room-tabbar');
    if (!only) { if (nav) nav.remove(); return; }
    if (!nav) {
      nav = document.createElement('nav'); nav.id = 'room-tabbar'; nav.className = 'tabbar';
      nav.innerHTML = ROOM_TABS.map(function (t) { return '<button type="button" class="tab" data-tab="' + t[0] + '"><span>' + t[1] + '</span><small>' + t[2] + '</small></button>'; }).join('');
      nav.addEventListener('click', function (e) { var b = e.target.closest('.tab'); if (b) roomGo(b.dataset.tab); });
      $('app-view').appendChild(nav);
    }
  }

  // ---- ثبت ماژول‌ها در منوی کشویی (برای کاربران غیر-اتاق‌انس)
  var HI = {
    'ounce.intake_new': async function (c) { await openForm(c, {}); },
    'ounce.intakes': async function (c) { await showList(c, {}); },
  };
  var prev = window.PBModules;
  window.PBModules = {
    has: function (k) { return typeof HI[k] === 'function' || (prev && prev.has(k)); },
    open: function (k, c) { return typeof HI[k] === 'function' ? HI[k](c) : prev.open(k, c); },
  };

  root.PBIntake = {
    roomOnly: roomOnly, applyRole: applyRole, roomTab: function (tab) { roomGo(tab === 'home' || !tab ? 'ir-new' : (tab === 'ir-list' ? 'ir-list' : 'ir-new')); },
    decorateHome: decorateHome, openList: function (filter) { listState.filter = filter || ''; PBShell.openModule('ounce.intakes'); },
    _pure: PURE,
  };
})(typeof window !== 'undefined' ? window : this);
