/* کارگاه ۳-ب — «گزارش خط شکست» در وب (فقط‌خواندنی؛ ثبت از اپ Flutter، مثل فراوری وب) + منوی محدود نقش «اپراتور کارگاه» (بدون هیچ قیمت).
 * اکشن‌ها: workshop.crack_list / workshop.crack_get (مجوز workshop.crack؛ سرور اپراتور را به ردیف‌های خودش محدود می‌کند)
 *          workshop.list (مجوز workshop.view؛ عملیات داخلی بدون قیمت).
 * AO حذف شده و AP (معنی تأییدنشده) عمداً نمایش داده نمی‌شود. سرور مرجع مجوز است؛ این فایل فقط UI را محدود می‌کند. */
(function (root) {
  'use strict';

  // ------------------------------------------------------------------ بخش خالص (قابل تست در Node)
  var GROUPS = [
    ['اطلاعات کلی بار', [['shift', 'شیفت'], ['operator_name', 'اپراتور'], ['customer_name', 'مشتری'], ['line_code', 'کد خط'], ['pistachio_type', 'کد/نوع پسته'], ['bale_count', 'تعداد عدل', 'n'], ['customer_code', 'کد مشتری'], ['shipment_code', 'کد محموله']]],
    ['ورودی به دستگاه', [['input_kg', 'وزن کل ورودی (کیلو)', 'n'], ['gram_pct', 'درصد گرم مغز', 'n'], ['@est', 'برآورد مغز خروجی (کیلو)', 'c'], ['moisture_pct', 'رطوبت ٪', 'n'], ['fines_pct', 'درصد ریز', 'n'], ['ounce_by', 'اونس‌کننده']]],
    ['اطلاعات دستگاه', [['start_time', 'ساعت شروع', 't'], ['end_time', 'ساعت پایان', 't'], ['run_minutes', 'زمان واقعی کارکرد (دقیقه)', 'n'], ['kernel_kg', 'وزن مغز خروجی (کیلو)', 'n'], ['hull_kg', 'وزن پوست', 'n'], ['soil_kg', 'وزن خاک', 'n'], ['@cap', 'ظرفیت واقعی (کیلو/ساعت)', 'c'], ['machine_setting', 'تنظیم/سرعت دستگاه'], ['hull_left_kg', 'وزن پوست باقی‌مانده', 'n']]],
    ['وزن بار خروجی دستگاه', [['sound_kg', 'مغز سالم', 'n'], ['wheat_kg', 'گندمی و موشو', 'n'], ['super_fine_kg', 'سوپر خیلی ریز', 'n'], ['flower_kg', 'گلدار', 'n'], ['split_kg', 'لپه و شکسته', 'n'], ['unbreakable_kg', 'ناشکن', 'n'], ['pink_kg', 'کال سوپر و صورتی', 'n'], ['waste_kernel_kg', 'مغز ضایعاتی', 'n'], ['analyst', 'آنالیزکننده']]],
    ['محاسبات', [['input_kg', 'وزن ورودی', 'n'], ['initial_kernel_kg', 'مغز اولیه (کیلو)', 'n'], ['@loss', 'مغزِ نشده = افت + پوست + خاک (L−U)', 'c'], ['@losspct', 'مغزِ نشده ٪ (از ورودی)', 'c'], ['power_kwh', 'مصرف برق (kWh)', 'n'], ['@stops', 'تعداد توقفات', 'c'], ['@stopmin', 'مجموع توقف (دقیقه)', 'c']]],
  ];
  var CALC_KEY = { '@est': 'estimated_kernel_kg', '@cap': 'capacity_kgh', '@loss': 'loss_kg', '@losspct': 'loss_pct', '@stops': 'stop_count', '@stopmin': 'stop_total_min' };
  var OP_LABELS = { dehull: 'پوست‌گیری', desoil: 'خاک‌گیری', split: 'جداسازی پوست و مغز', fines: 'ریزبرداری و بدشکل', crack: 'شکست' };
  var RANGES = [{ v: 'today', label: 'امروز', days: 0 }, { v: '7', label: '۷ روز', days: 7 }, { v: '30', label: '۳۰ روز', days: 30 }, { v: 'all', label: 'همه', days: null }];

  // کلیدی که نباید هرگز در UI بیاید (AP تأییدنشده؛ هر فیلد قیمتی/بها)
  var HIDDEN = /^(ap_unconfirmed|ao|ap|.*price.*|.*cost.*|.*fee.*|.*wage.*|.*value.*)$/i;
  function visibleKey(k) { return !HIDDEN.test(String(k)); }

  function isoOf(d) { var m = d.getMonth() + 1, day = d.getDate(); return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day; }
  function rangeParams(v, now) {
    var r = RANGES.filter(function (x) { return x.v === v; })[0];
    if (!r || r.days === null) return {};
    var to = now || new Date(), from = new Date(to.getFullYear(), to.getMonth(), to.getDate() - r.days);
    return { from: isoOf(from), to: isoOf(to) };
  }
  /** مقدار سلول: نوع n = عدد، t = ساعت، c = از calc سرور؛ خالی ⇒ '—'. */
  function cellValue(row, spec, fmtNum) {
    var k = spec[0], kind = spec[2] || 's', v;
    if (k.charAt(0) === '@') v = row.calc ? row.calc[CALC_KEY[k]] : null; else v = row[k];
    if (v === null || v === undefined || v === '') return '—';
    return (kind === 'n' || kind === 'c') ? fmtNum(Number(v)) : String(v);
  }
  // ---- مرحلهٔ ۴: «فایل خام شکست کارگاه‌ها» (کارگاه بیرونی با قبض؛ برگهٔ اکسل A..K + جمع). مقادیر G/J/K و جمع‌ها از سرور (pb_ow_calc / pb_ow_totals)
  var OUTSIDE_HEADS = ['تاریخ', 'کارگاه', 'کش', 'شمارهٔ قبض', 'عدل', 'نام مالک', 'وزن پستهٔ ارسالی', 'گرم ارسالی', 'وزن مغز برآوردی', 'مغز دریافتی', 'گرم دریافتی', 'تخمین مغز باقی‌مانده', 'افت'];
  function dash(v, f) { return (v === null || v === undefined || v === '') ? '—' : f(v); }
  /** سلول‌های یک ردیف؛ fmtNum/fmtDate/esc تزریق می‌شوند (تست‌پذیر). */
  function outsideCells(r, fmtNum, fmtDate, esc) {
    var k = r.calc || {};
    return [fmtDate(r.date), esc(r.party || '—'), esc(r.cash_note || '—'), esc(r.receipt_no || '—'), dash(r.bale_count, fmtNum), esc(r.owner || '—'),
      fmtNum(Number(r.sent_kg || 0)), dash(r.sent_gram_pct, fmtNum), dash(k.est_kernel_kg, fmtNum), dash(r.recv_kernel_kg, fmtNum), dash(r.recv_gram_pct, fmtNum),
      dash(k.remaining_kernel_kg, fmtNum), dash(k.loss_gram, fmtNum)];
  }
  function outsideTotalCells(t, fmtNum) {
    return ['جمع · گرم: میانگین', '', '', '', '', '', fmtNum(Number(t.sent_kg || 0)), dash(t.sent_gram_avg, fmtNum), fmtNum(Number(t.est_kernel_kg || 0)), fmtNum(Number(t.recv_kernel_kg || 0)),
      dash(t.recv_gram_avg, fmtNum), fmtNum(Number(t.remaining_kernel_kg || 0)), dash(t.loss_gram, fmtNum)];
  }
  // ---- مرحلهٔ ۵-ب: «موجودی پستهٔ خرجی» (فقط‌خواندنی، بدون قیمت؛ اکشن feed.stock با مجوز workshop.feed). همهٔ اعداد از سرور (pb_feed_calc / pb_feed_totals).
  var FEED_HEADS = ['تاریخ', 'منبع', 'کارگاه', 'مالک', 'شمارهٔ قبض', 'ارسالی (کیلو)', 'برگشتی (کیلو)', 'ضایعات (کیلو)', 'مانده در کارگاه (کیلو)'];
  var FEED_SOURCES = { outside: 'کارگاه بیرونی', crack: 'خط شکست', adjust: 'تعدیل دستی' };
  function feedSource(r) { var s = FEED_SOURCES[r.source] || String(r.source || '—'); return r.source === 'adjust' ? s + (r.kind === 'out' ? ' (خروج)' : ' (ورود)') : s; }
  function feedCells(r, fmtNum, fmtDate, esc) {
    return [r.date ? fmtDate(r.date) : '—', esc(feedSource(r)), esc(r.workshop || '—'), esc(r.owner || '—'), esc(r.receipt_no || '—'),
      fmtNum(Number(r.sent_kg || 0)), fmtNum(Number(r.returned_kg || 0)), fmtNum(Number(r.waste_kg || 0)), fmtNum(Number(r.remaining_kg || 0))];
  }
  function feedTotalCells(t, fmtNum) {
    return ['جمع', '', '', '', '', fmtNum(Number(t.sent_kg || 0)), fmtNum(Number(t.returned_kg || 0)), fmtNum(Number(t.waste_kg || 0)), fmtNum(Number(t.remaining_kg || 0))];
  }
  /** خط خلاصهٔ کارت صفحهٔ اصلی؛ summary از سرور. بدون ارسالی/برگشتی/ردیف باز ⇒ null (کارت نمایش داده نشود). */
  function feedHomeLine(s, fmtNum) {
    if (!s) return null;
    var sent = Number(s.total_sent_kg || 0), ret = Number(s.total_returned_kg || 0), open = Number(s.open_count || 0);
    if (!sent && !ret && !open) return null;
    return 'مانده در کارگاه‌ها: ' + fmtNum(Number(s.total_balance_kg || 0)) + ' کیلو · کارگاه: ' + fmtNum(Number(s.workshops_count || 0)) + ' · باز: ' + fmtNum(open);
  }
  // ---- مرحلهٔ ۶-۳: «کارتکس مالک» · «خلاصهٔ مدیریتی» · «مقایسهٔ کارگاه‌ها/اپراتورها» (فقط‌خواندنی، بدون قیمت). همهٔ اعداد از سرور (pb_cartex_* / pb_wsum_* / pb_wcmp_build).
  var CARTEX_HEADS = ['تاریخ', 'رویداد', 'مالک', 'عدل', 'ورود (کیلو)', 'خروج (کیلو)', 'انس', 'گرم', 'دهنبست', 'مانده مالک (کیلو)', 'مانده کل همهٔ مالک‌ها (کیلو)', 'توضیحات'];
  var CARTEX_OWNER_HEADS = ['مالک', 'افتتاحیه', 'ورود', 'خروج', 'مانده'];
  var CARTEX_EVENTS = { send: 'ارسال', receive: 'دریافت' };
  function ownerName(v) { return (v === null || v === undefined || String(v).trim() === '') ? 'بدون مالک' : String(v); }
  function cartexCells(r, fmtNum, fmtDate, esc) {
    return [r.date ? fmtDate(r.date) : '—', esc(CARTEX_EVENTS[r.event] || String(r.event || '—')), esc(ownerName(r.owner)), dash(r.bale_count, fmtNum),
      fmtNum(Number(r.in_kg || 0)), fmtNum(Number(r.out_kg || 0)), dash(r.ounce, fmtNum), dash(r.gram_pct, fmtNum), dash(r.dahanbast, fmtNum),
      fmtNum(Number(r.balance_kg || 0)), dash(r.balance_total_kg, fmtNum), esc(r.note || '—')];
  }
  function cartexTotalCells(t, fmtNum) {
    return ['جمع', '', '', '', fmtNum(Number(t.in_kg || 0)), fmtNum(Number(t.out_kg || 0)), '', '', '', fmtNum(Number(t.balance_kg || 0)), dash(t.balance_total_kg, fmtNum), ''];
  }
  function cartexOwnerCells(o, fmtNum, esc) {
    return [esc(ownerName(o.owner)), fmtNum(Number(o.opening_kg || 0)), fmtNum(Number(o.in_kg || 0)), fmtNum(Number(o.out_kg || 0)), fmtNum(Number(o.balance_kg || 0))];
  }
  // مشخصات خلاصه: [کلید، برچسب، نوع] — n = کیلو/عدد، p = درصد (null ⇒ «—»)
  var SUMMARY_INTERNAL = [['orders_count', 'تعداد عملیات', 'n'], ['delivered_kg', 'وزن تحویلی (کیلو)', 'n'], ['input_kg', 'وزن ورودی (کیلو)', 'n'], ['sound_kg', 'سالم', 'n'],
    ['deformed_fine_kg', 'بدشکل و ریز', 'n'], ['hull_kg', 'پوست', 'n'], ['kernel_kg', 'مغز', 'n'], ['soil_kg', 'خاک', 'n'], ['discolored_kg', 'بدرنگ', 'n'], ['loss_kg', 'افت وزن', 'n'],
    ['waste_total_kg', 'مجموع ضایعات', 'n'], ['diff_kg', 'اختلاف تراز', 'n'], ['undelivered_kg', 'تحویل‌نشده', 'n'], ['sound_pct', 'درصد سالم از ورودی', 'p'], ['check_count', 'عملیات نیازمند بررسی تراز', 'n']];
  var SUMMARY_CRACK = [['runs_count', 'تعداد نوبت کار', 'n'], ['input_kg', 'وزن ورودی (کیلو)', 'n'], ['kernel_kg', 'مغز خروجی', 'n'], ['hull_kg', 'پوست', 'n'], ['soil_kg', 'خاک', 'n'],
    ['sub_kernel_kg', 'مجموع زیرمجموعه‌های مغز', 'n'], ['returned_all_kg', 'برگشتی همهٔ خروجی‌ها', 'n'], ['loss_kg', 'مغزِ نشده = افت + پوست + خاک (کیلو)', 'n'], ['loss_pct', 'مغزِ نشده ٪ (از ورودی)', 'p'], ['kernel_pct', 'مغز از ورودی (٪)', 'p'],
    ['capacity_kgh', 'ظرفیت واقعی (کیلو/ساعت)', 'n'], ['run_minutes', 'زمان کارکرد (دقیقه)', 'n'], ['stop_minutes', 'مجموع توقف (دقیقه)', 'n']];
  var SUMMARY_CRACK_WARNING = '⚠️ «برگشتی همهٔ خروجی‌ها» = مغز + پوست + خاک + زیرمجموعه‌های مغز است؛ اگر زیرمجموعه‌ها بخشی از وزن مغز باشند دوبار شمرده می‌شود. اجزا جدا هم آمده‌اند تا قضاوت کنید.';
  /** [برچسب، مقدار] برای هر مشخصه؛ مقدار خالی/ناموجود ⇒ «—». */
  function summaryRows(spec, obj, fmtNum) {
    obj = obj || {};
    return spec.map(function (x) {
      var v = obj[x[0]];
      if (v === null || v === undefined || v === '') return [x[1], '—'];
      return [x[1], fmtNum(Number(v)) + (x[2] === 'p' ? '٪' : '')];
    });
  }
  // ---- S2-ج: کارت «تراز واقعی» و هشدارهای غیرمسدود؛ فقط نمایش کلیدهای calc سرور (real_loss_kg, shortfall_kg, …). کلید ناموجود ⇒ «—» و بدون هشدار.
  var COMPARE_CRACK_NOTE = '«افت» در ردیف‌های خط شکست = ورودی − مغز (مغزِ نشده؛ شامل پوست و خاک) است، نه افت واقعی.';
  function isNil(v) { return v === null || v === undefined || v === ''; }
  function kgPct(kg, pct, fmtNum) { if (isNil(kg)) return '—'; return fmtNum(Number(kg)) + ' کیلو' + (isNil(pct) ? '' : ' (' + fmtNum(Number(pct)) + '٪)'); }
  function pctOnly(v, fmtNum) { return isNil(v) ? '—' : fmtNum(Number(v)) + '٪'; }
  function isTrue(v) { return v === true || v === 1 || v === '1'; }
  /** [برچسب، مقدار] کارت «تراز واقعی» از calc سرور. */
  function realBalanceRows(calc, fmtNum) {
    var k = calc || {};
    return [['جمع تفکیک (ΣAA..AH)', isNil(k.sub_sum_kg) ? '—' : fmtNum(Number(k.sub_sum_kg)) + ' کیلو'],
      ['افت واقعی = L−(U+V+W)', kgPct(k.real_loss_kg, k.real_loss_pct, fmtNum)],
      ['کسری مغز نسبت به برآورد (N−U)', kgPct(k.shortfall_kg, k.shortfall_pct, fmtNum)],
      ['سالم از ورودی (AA÷L)', pctOnly(k.sound_pct_input, fmtNum)],
      ['سالم از مغز (AA÷U)', pctOnly(k.sound_pct_kernel, fmtNum)]];
  }
  /** متن هشدارهای نارنجی (غیرمسدود؛ ثبت همیشه ممکن است). */
  function crackWarnings(calc, fmtNum) {
    var k = calc || {}, w = [];
    if (isTrue(k.sub_mismatch)) w.push('مغز خروجی با جمع تفکیک نمی‌خواند (جمع تفکیک: ' + fmtNum(Number(k.sub_sum_kg || 0)) + ' کیلو)');
    if (isTrue(k.over_output)) w.push('جمع خروجی‌ها از ورودی بیشتر است');
    return w;
  }
  var COMPARE_HEADS = ['منبع', 'نام', 'تعداد', 'ورودی (کیلو)', 'خروجی خوب (کیلو)', 'افت (کیلو)', 'بازده ٪', 'افت ٪', 'ظرفیت (کیلو/ساعت)', 'میانگین افت گرم'];
  var COMPARE_SOURCES = { internal: 'کارگاه داخلی', outside: 'کارگاه بیرونی', crack: 'خط شکست' };
  var COMPARE_BY = [['workshop', 'کارگاه'], ['operator', 'اپراتور'], ['registrar', 'ثبت‌کننده']];
  function compareCells(r, fmtNum, esc) {
    return [esc(COMPARE_SOURCES[r.source] || String(r.source || '—')), esc(r.name || '—'), fmtNum(Number(r.count || 0)), fmtNum(Number(r.input_kg || 0)), fmtNum(Number(r.good_kg || 0)),
      fmtNum(Number(r.loss_kg || 0)), dash(r.yield_pct, fmtNum), dash(r.loss_pct, fmtNum), dash(r.capacity_kgh, fmtNum), dash(r.avg_gram_loss, fmtNum)];
  }
  /** CSV (UTF-8 با BOM تا اکسل فارسی را درست بخواند)؛ فیلد دارای , " یا خط‌جدید در گیومه. */
  function csvText(heads, rows) {
    function q(v) { var t = String(v === null || v === undefined ? '' : v); return /[",\r\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t; }
    return '\uFEFF' + [heads].concat(rows).map(function (r) { return r.map(q).join(','); }).join('\r\n');
  }
  // S4 — ورود درصدی خط شکست: فقط تابع‌های خالص (آینهٔ crPctToKg/crKgToPct در workshop_calc.dart).
  // فرم ثبت خط شکست در وب وجود ندارد (ثبت از اپ Flutter)؛ پس UI وب ساخته نشد و این‌ها فقط برای هم‌ترازی و تست‌اند.
  // مبنا = وزن ورودی L؛ تبدیل روی گرم صحیح: گرم = round(L_گرم × درصد ÷ ۱۰۰) نیم رو به بالا؛ فقط کیلو ذخیره می‌شود.
  var CR_PCT_KEYS = ['kernel_kg', 'hull_kg', 'soil_kg', 'hull_left_kg', 'sound_kg', 'wheat_kg', 'super_fine_kg', 'flower_kg', 'split_kg', 'unbreakable_kg', 'pink_kg', 'waste_kernel_kg'];
  function wsGramsInt(kg) { return Math.round((Number(kg) || 0) * 1000); }
  function crPctOk(p) { return typeof p === 'number' && isFinite(p) && p >= 0 && p <= 100; }
  function crPctToKg(inputKg, pct) {
    if (inputKg === null || inputKg === undefined || !crPctOk(pct)) return null;
    var lg = wsGramsInt(inputKg);
    if (lg <= 0) return null;
    return Math.floor(lg * pct / 100 + 0.5 + 1e-6) / 1000;
  }
  function crKgToPct(inputKg, kg) {
    if (inputKg === null || inputKg === undefined || kg === null || kg === undefined) return null;
    var lg = wsGramsInt(inputKg);
    if (lg <= 0) return null;
    return Math.round((wsGramsInt(kg) * 100 / lg) * 10000) / 10000;
  }
  // S6 — لات‌های ساخته‌شده از خروجی‌های خط شکست. ثبت فقط از اپ Flutter است؛ وب فقط نمایش می‌دهد (سرور: lots در crack_get/crack_list).
  var LOT_LABELS = { kernel_kg: 'مغز', hull_kg: 'پوست', soil_kg: 'خاک', sound_kg: 'مغز سالم', wheat_kg: 'گندمی و موشو', super_fine_kg: 'سوپر خیلی ریز', flower_kg: 'گلدار', split_kg: 'لپه و شکسته', unbreakable_kg: 'ناشکن', pink_kg: 'کال سوپر و صورتی', waste_kernel_kg: 'مغز ضایعاتی' };
  var LOT_SUB = ['sound_kg', 'wheat_kg', 'super_fine_kg', 'flower_kg', 'split_kg', 'unbreakable_kg', 'pink_kg', 'waste_kernel_kg'];
  /** آینهٔ pb_cr_lots_plan / crLotsPlan (Dart): fields انتخابی ⇒ {items:[[field,g]], total_g} یا {error}. */
  function crLotsPlan(cols, fields) {
    var f = []; (fields || []).forEach(function (x) { x = String(x); if (f.indexOf(x) < 0) f.push(x); });
    if (!f.length) return { error: 'حداقل یک خروجی را برای ساخت لات انتخاب کنید' };
    for (var i = 0; i < f.length; i++) if (!LOT_LABELS[f[i]]) return { error: 'خروجی نامعتبر برای لات: ' + f[i] };
    if (f.indexOf('kernel_kg') >= 0 && f.some(function (x) { return LOT_SUB.indexOf(x) >= 0; })) return { error: '«مغز» و تفکیک آن را هم‌زمان لات نکنید؛ مغز دو بار شمرده می‌شود' };
    var items = [], t = 0, keys = Object.keys(LOT_LABELS);
    for (var k = 0; k < keys.length; k++) {
      if (f.indexOf(keys[k]) < 0) continue;
      var g = wsGramsInt(cols && cols[keys[k]]);
      if (g <= 0) return { error: 'برای «' + LOT_LABELS[keys[k]] + '» وزنی وارد نشده است' };
      items.push([keys[k], g]); t += g;
    }
    return { items: items, total_g: t };
  }
  /** ردیف‌های جدول «لات‌های ساخته‌شده»: [کد، خروجی، کیلو]؛ بدون هیچ فیلد قیمتی. esc برای متن‌های سرور. */
  function lotRows(lots, fmtNum, esc) {
    return (Array.isArray(lots) ? lots : []).map(function (l) {
      return [esc(l.code || '—'), esc(LOT_LABELS[l.field_key] || String(l.field_key || '—')), esc(fmtNum(Number(l.kg) || 0))];
    });
  }
  // ---- CP3: سود و زیان خط شکست (فقط نمایش؛ همهٔ مبلغ‌ها از سرور، ریال صحیح — D10/D11)
  var CP_NOTE = 'سود مدیریتی؛ در اسناد حسابداری بهای این لات‌ها صفر است.';
  var CP_PROV_NOTE = 'سود هر لات تا بستن استخر «موقت» است (هزینه فقط روی لات‌های فروخته‌شده سهم‌بندی می‌شود)؛ کیلوی فروخته‌نشده را کنارش ببینید.';
  var CP_NEED_PRICE = 'برای دیدن سود، دسترسی قیمت لازم است.';
  var CP_CONSUME = { full: 'تبدیل کامل (هزینهٔ افت روی همین گروه)', lots_only: 'فقط وزن لات‌ها' };
  var CP_HEADS = ['تاریخ', 'خط', 'لات مبدأ', 'مصرف / افت (کیلو)', 'مبلغ استخر (ریال)', 'درآمد (ریال)', 'سود / زیان (ریال)', 'حاشیه ٪', 'وضعیت', 'فروخته‌نشده (کیلو)'];
  var CP_LOT_HEADS = ['کد لات', 'خروجی', 'کیلو', 'مانده (کیلو)', 'درآمد (ریال)', 'سهم هزینه (ریال)', 'سود (ریال)'];
  function cpStatus(it) { return it && it.status === 'closed' ? 'قطعی' : 'موقت'; }
  /** سرور برای کارکنِ بدون price.view کلیدهای cost/profit را حذف می‌کند ⇒ مبلغ‌ها نیستند. */
  function cpHasMoney(it) { return !!it && typeof it.profit === 'number' && !!it.pool && typeof it.pool.total === 'number'; }
  function poolInfoRows(pi, fmtNum) {
    if (!pi) return [];
    return [['حالت مصرف', CP_CONSUME[pi.consume] || String(pi.consume || '—')], ['مصرف‌شده از لات مبدأ (کیلو)', fmtNum(Number(pi.consumed_kg) || 0)],
      ['افت (کیلو)', fmtNum(Number(pi.loss_kg) || 0)], ['وضعیت استخر', pi.status === 'closed' ? 'بسته (قطعی)' : 'باز (موقت)']];
  }
  function cpCells(it, fmtNum, fmtDate, esc) {
    return [fmtDate(it.run_date), esc(it.line_code || '—'), esc(it.source_lot_code || '—'), fmtNum(Number(it.consumed_kg) || 0) + ' / ' + fmtNum(Number(it.loss_kg) || 0),
      fmtNum(it.pool.total), fmtNum(it.revenue), fmtNum(it.profit), isNil(it.margin_pct) ? '—' : fmtNum(Number(it.margin_pct)), cpStatus(it), fmtNum(Number(it.unsold_kg) || 0)];
  }
  function cpLotCells(l, fmtNum, esc) {
    return [esc(l.code || '—'), esc(l.label || '—'), fmtNum(Number(l.kg) || 0), fmtNum(Number(l.remaining_kg) || 0), fmtNum(l.revenue), fmtNum(l.cost), fmtNum(l.profit)];
  }
  function cpTotalCells(t, fmtNum) { return ['جمع', '', '', '', fmtNum(t.pool), fmtNum(t.revenue), fmtNum(t.profit), '', '', '']; }
  /** ورودی هزینهٔ اضافه: ارقام فارسی/عربی و جداکننده‌ها پذیرفته؛ فقط عدد صحیح ۰..۱۵ رقم، وگرنه null. */
  function parseRialInput(s) {
    var t = String(s === null || s === undefined ? '' : s).replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
      .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); }).replace(/[,٬،\s]/g, '');
    return /^\d{1,15}$/.test(t) ? Number(t) : null;
  }
  var PURE = { COMPARE_CRACK_NOTE: COMPARE_CRACK_NOTE, realBalanceRows: realBalanceRows, crackWarnings: crackWarnings, csvText: csvText, CARTEX_HEADS: CARTEX_HEADS, CARTEX_OWNER_HEADS: CARTEX_OWNER_HEADS, CARTEX_EVENTS: CARTEX_EVENTS, cartexCells: cartexCells, cartexTotalCells: cartexTotalCells, cartexOwnerCells: cartexOwnerCells, ownerName: ownerName,
    SUMMARY_INTERNAL: SUMMARY_INTERNAL, SUMMARY_CRACK: SUMMARY_CRACK, SUMMARY_CRACK_WARNING: SUMMARY_CRACK_WARNING, summaryRows: summaryRows, COMPARE_HEADS: COMPARE_HEADS, COMPARE_SOURCES: COMPARE_SOURCES, COMPARE_BY: COMPARE_BY, compareCells: compareCells,
    FEED_HEADS: FEED_HEADS, FEED_SOURCES: FEED_SOURCES, feedCells: feedCells, feedTotalCells: feedTotalCells, feedHomeLine: feedHomeLine, OUTSIDE_HEADS: OUTSIDE_HEADS, outsideCells: outsideCells, outsideTotalCells: outsideTotalCells, GROUPS: GROUPS, CALC_KEY: CALC_KEY, OP_LABELS: OP_LABELS, RANGES: RANGES, visibleKey: visibleKey, rangeParams: rangeParams, cellValue: cellValue, isoOf: isoOf,
    CR_PCT_KEYS: CR_PCT_KEYS, crPctOk: crPctOk, crPctToKg: crPctToKg, crKgToPct: crKgToPct,
    LOT_LABELS: LOT_LABELS, crLotsPlan: crLotsPlan, lotRows: lotRows,
    CP_NOTE: CP_NOTE, CP_PROV_NOTE: CP_PROV_NOTE, CP_NEED_PRICE: CP_NEED_PRICE, CP_HEADS: CP_HEADS, CP_LOT_HEADS: CP_LOT_HEADS, cpStatus: cpStatus, cpHasMoney: cpHasMoney, poolInfoRows: poolInfoRows, cpCells: cpCells, cpLotCells: cpLotCells, cpTotalCells: cpTotalCells, parseRialInput: parseRialInput };
  if (typeof window === 'undefined') { if (typeof module !== 'undefined' && module.exports) module.exports = PURE; return; }

  // ------------------------------------------------------------------ بخش UI
  var $ = function (id) { return document.getElementById(id); };
  var E = function (v) { return esc(v); }; // esc سراسری app.js
  function perm(p) { return !window.PBShell || PBShell.hasPerm(p); }
  function opOnly() { return !!(window.PBShell && PBShell.isWorkshopOperatorOnly && PBShell.isWorkshopOperatorOnly()); }
  function num(n) { return (Number(n) || 0).toLocaleString('fa-IR', { maximumFractionDigits: 3 }); }
  function jd(iso) { return iso ? isoToJalaliDisplay(iso) : '—'; }
  function setTitle(t) { var el = $('page-title'); if (el) el.textContent = t; }
  function clearChrome() { document.querySelectorAll('.fab').forEach(function (f) { f.remove(); }); }
  var st = { range: '7' };

  function row2(label, value) { return '<div class="card-row"><span>' + E(label) + '</span><span>' + E(value) + '</span></div>'; }
  function table(headers, rows) {
    if (!rows.length) return '<div class="empty-state">موردی ثبت نشده است</div>';
    return '<div class="table-wrap"><table class="mini-table"><thead><tr>' + headers.map(function (h) { return '<th>' + E(h) + '</th>'; }).join('')
      + '</tr></thead><tbody>' + rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
  }
  function segHtml(active) {
    return '<div class="seg">' + RANGES.map(function (r) { return '<button type="button" class="seg-btn' + (r.v === active ? ' active' : '') + '" data-v="' + r.v + '">' + E(r.label) + '</button>'; }).join('') + '</div>';
  }

  // ---- فهرست خط شکست
  async function showCrackList(c) {
    clearChrome(); setTitle('گزارش خط شکست');
    var r = await api('workshop.crack_list', rangeParams(st.range));
    var items = r.items || [];
    var html = '<div class="card"><div class="muted">ثبت و ویرایش گزارش از اپلیکیشن انجام می‌شود؛ این صفحه فقط نمایش است.</div></div>' + segHtml(st.range)
      + table(['تاریخ', 'خط', 'پسته', 'ورودی', 'مغز', 'ظرفیت', 'مغزِ نشده ٪', 'توقف'], items.map(function (it) {
        var k = it.calc || {};
        return ['<a href="#" class="wk-open" data-id="' + Number(it.id) + '">' + E(jd(it.run_date)) + '</a>', E(it.line_code || '—'), E(it.pistachio_type || '—'), E(num(k.input_kg)),
          E(it.kernel_kg == null ? '—' : num(it.kernel_kg)), E(k.capacity_kgh == null ? '—' : num(k.capacity_kgh)), E(k.loss_pct == null ? '—' : num(k.loss_pct)), E(num(k.stop_count))];
      }));
    c.innerHTML = html;
    c.querySelectorAll('.seg-btn').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showCrackList(c).catch(fail(c)); }); });
    c.querySelectorAll('.wk-open').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); showCrackDetail(c, Number(a.dataset.id)).catch(fail(c)); }); });
  }

  async function showCrackDetail(c, id) {
    clearChrome(); setTitle('جزئیات خط شکست');
    var it = await api('workshop.crack_get', { id: id });
    var html = '<button type="button" class="btn secondary" id="wk-back">‹ بازگشت به فهرست</button>'
      + '<div class="card">' + row2('تاریخ شروع', jd(it.run_date)) + row2('تاریخ پایان', jd(it.end_date)) + row2('تاریخ میلادی', it.run_date || '—') + '</div>';
    var warns = crackWarnings(it.calc, num);
    if (warns.length) html += '<div class="card" style="background:rgba(255,152,0,.10);color:#e65100;font-weight:bold">' + warns.map(function (w) { return '<div>⚠️ ' + E(w) + '</div>'; }).join('') + '</div>';
    GROUPS.forEach(function (g) {
      var rows = g[1].filter(function (s) { return s[0].charAt(0) === '@' || visibleKey(s[0]); }).map(function (s) { return row2(s[1], cellValue(it, s, num)); }).join('');
      html += '<div class="card"><b>' + E(g[0]) + '</b>' + rows + '</div>';
    });
    html += '<div class="card"><b>تراز واقعی</b>' + realBalanceRows(it.calc, num).map(function (r) { return row2(r[0], r[1]); }).join('') + '</div>';
    html += '<div class="card"><b>توقف‌ها</b>' + table(['علت', 'دقیقه'], (it.stops || []).map(function (s) { return [E(s.reason || '—'), E(num(s.minutes))]; })) + '</div>';
    var lr = lotRows(it.lots, num, E);
    if (lr.length) html += '<div class="card"><b>لات‌های ساخته‌شده</b>' + table(['کد لات', 'خروجی', 'کیلو'], lr) + '</div>'; // S6
    if (it.pool_info) html += '<div class="card"><b>مصرف از لات مبدأ</b>' + poolInfoRows(it.pool_info, num).map(function (r) { return row2(r[0], r[1]); }).join('') + '</div>'; // CP3: فقط مدیر؛ بدون مبلغ
    if (lr.length && canProfit()) html += '<div id="cr-profit"></div>'; // CP3: کارت سود (gold + profit.view)
    if (it.note) html += '<div class="card">' + row2('یادداشت', it.note) + '</div>';
    c.innerHTML = html;
    $('wk-back').onclick = function () { showCrackList(c).catch(fail(c)); };
    if ($('cr-profit')) loadProfitCard($('cr-profit'), Number(it.id)); // CP3
  }

  // ---- CP3: کارت سود ردیف + گزارش «سود خط شکست» (gold + profit.view؛ اپراتور هرگز؛ سرور مرجع مجوز است)
  function canProfit() { return !opOnly() && perm('profit.view') && (!window.PBShell || !PBShell.hasFeature || PBShell.hasFeature('gold.reports')); }
  function canManage() { return canProfit() && perm('workshop.manage'); }
  function signCls(n) { return Number(n) < 0 ? 'neg' : 'pos'; }
  function statusBadge(it) { return '<span class="badge ' + (it.provisional ? 'prov' : 'final') + '">' + E(cpStatus(it)) + '</span>'; }
  function lotsTable(lots) { return table(CP_LOT_HEADS, (lots || []).map(function (l) { return cpLotCells(l, num, E); })); }
  function errText(e) {
    var m = String((e && e.message) || '');
    if (m.indexOf('edition_required') >= 0) return 'این گزارش فقط در نسخهٔ طلایی در دسترس است.';
    if (m === 'forbidden' || m.indexOf('forbidden') >= 0) return 'دسترسی لازم را ندارید.';
    return m || 'خطا در انجام کار';
  }
  function profitCardHtml(it) {
    if (!cpHasMoney(it)) return '<div class="card"><b>سود و زیان این ردیف</b><div class="muted">' + E(CP_NEED_PRICE) + '</div></div>';
    var closed = it.status === 'closed';
    var h = '<div class="card"><div class="card-row"><b>سود و زیان این ردیف (ریال)</b>' + statusBadge(it) + '</div>'
      + row2('بهای پستهٔ مبدأ', num(it.pool.source_cost)) + row2('هزینهٔ اضافه (دستی)', num(it.pool.extra)) + row2('جمع استخر هزینه', num(it.pool.total))
      + row2('درآمد خالص فروش', num(it.revenue))
      + '<div class="card-row"><span>سود / زیان گروه</span><b class="' + signCls(it.profit) + '">' + E(num(it.profit)) + '</b></div>'
      + row2('حاشیهٔ سود', isNil(it.margin_pct) ? '—' : num(it.margin_pct) + '٪') + row2('کیلوی فروخته‌شده', num(it.sold_kg)) + row2('کیلوی فروخته‌نشده', num(it.unsold_kg))
      + '<div style="margin-top:8px"><b>سهم هر لات</b></div>' + lotsTable(it.lots)
      + '<div class="muted" style="margin-top:6px">' + E(CP_NOTE) + (it.provisional ? ' ' + E(CP_PROV_NOTE) : '') + '</div>';
    if (canManage()) {
      h += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">'
        + '<button type="button" class="btn btn-secondary" data-act="extra" style="margin-top:0"' + (closed ? ' disabled' : '') + '>ثبت هزینهٔ اضافه</button>'
        + (closed ? '<button type="button" class="btn btn-secondary" data-act="reopen" style="margin-top:0">باز کردن استخر</button>'
          : '<button type="button" class="btn" data-act="close" style="margin-top:0">بستن استخر (قطعی)</button>') + '</div>'
        + (closed ? '<div class="muted" style="margin-top:6px">استخر بسته است؛ برای ثبت هزینهٔ اضافه ابتدا آن را باز کنید.</div>' : '');
    }
    return h + '</div>';
  }
  function renderProfitCard(holder, it) {
    holder.innerHTML = profitCardHtml(it);
    holder.querySelectorAll('[data-act]').forEach(function (b) { b.addEventListener('click', function () { poolAct(holder, it, b.dataset.act); }); });
  }
  async function poolAct(holder, it, act) {
    var action, payload = { run_id: it.run_id }, ok;
    if (act === 'extra') {
      var v = window.prompt('هزینهٔ اضافه (دستمزد، برق، …) به ریال، عدد صحیح:', String(it.pool.extra || 0));
      if (v === null) return;
      var n = parseRialInput(v);
      if (n === null) { toast('هزینهٔ اضافه باید عدد صحیح ریالی و نامنفی باشد'); return; }
      action = 'workshop.crack_pool_extra'; payload.extra_cost = n; ok = 'هزینهٔ اضافه ثبت شد';
    } else if (act === 'close') {
      if (!window.confirm('استخر بسته شود و سود «قطعی» علامت بخورد؟ (فروش همچنان ممکن است و محاسبه زنده می‌ماند)')) return;
      action = 'workshop.crack_pool_close'; ok = 'استخر بسته شد';
    } else {
      if (!window.confirm('استخر دوباره باز شود؟')) return;
      action = 'workshop.crack_pool_reopen'; ok = 'استخر باز شد';
    }
    try { var r = await api(action, payload); renderProfitCard(holder, r); toast(ok); } catch (e) { toast(errText(e)); }
  }
  async function loadProfitCard(holder, runId) {
    try { renderProfitCard(holder, await api('workshop.crack_pool_get', { run_id: runId })); }
    catch (e) { holder.innerHTML = ''; } // بدون استخر/بدون دسترسی/غیر طلایی: کارت نمایش داده نمی‌شود
  }

  var pfst = { status: '' };
  async function showCrackProfit(c) {
    clearChrome(); setTitle('سود خط شکست');
    var q = rangeParams(st.range); if (pfst.status) q.status = pfst.status;
    var r;
    try { r = await api('reports.crack_profit', q); } catch (e) { c.innerHTML = '<div class="empty-state">' + E(errText(e)) + '</div>'; return; }
    var items = r.items || [], t = r.totals || { pool: 0, revenue: 0, profit: 0 };
    var head = '<div class="card"><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><select id="cp-status" class="input">'
      + [['', 'همهٔ وضعیت‌ها'], ['open', 'موقت (باز)'], ['closed', 'قطعی (بسته)']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === pfst.status ? ' selected' : '') + '>' + E(o[1]) + '</option>'; }).join('')
      + '</select>' + CSV_BTN + '</div><div class="muted" style="margin-top:6px">' + E(CP_NOTE) + ' مبلغ‌ها به ریال‌اند. ' + E(CP_PROV_NOTE) + '</div></div>' + segHtml(st.range);
    if (items.length && !cpHasMoney(items[0])) { c.innerHTML = head + '<div class="card"><div class="muted">' + E(CP_NEED_PRICE) + '</div></div>'; bindProfit(c, items, t); return; }
    var body = '';
    if (!items.length) body = '<div class="empty-state">موردی ثبت نشده است</div>';
    else {
      body = '<div class="table-wrap"><table class="mini-table"><thead><tr><th></th>' + CP_HEADS.map(function (h) { return '<th>' + E(h) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + items.map(function (it, i) {
          var cells = cpCells(it, num, jd, E);
          cells[6] = '<span class="' + signCls(it.profit) + '">' + cells[6] + '</span>';
          cells[8] = statusBadge(it);
          return '<tr class="cp-main"><td><button type="button" class="cp-tg" data-i="' + i + '" aria-label="سهم لات‌ها">▸</button></td>'
            + cells.map(function (x, k) { return '<td>' + (k === 0 ? '<a href="#" class="wk-open" data-id="' + Number(it.run_id) + '">' + x + '</a>' : x) + '</td>'; }).join('') + '</tr>'
            + '<tr class="cp-sub hidden" id="cp-sub-' + i + '"><td colspan="' + (CP_HEADS.length + 1) + '">' + lotsTable(it.lots) + '</td></tr>';
        }).join('')
        + '<tr><td></td>' + cpTotalCells(t, num).map(function (x) { return '<td><b>' + E(x) + '</b></td>'; }).join('') + '</tr></tbody></table></div>';
    }
    c.innerHTML = head + body;
    bindProfit(c, items, t);
  }
  function bindProfit(c, items, t) {
    c.querySelectorAll('.seg-btn').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showCrackProfit(c).catch(fail(c)); }); });
    var sel = $('cp-status'); if (sel) sel.addEventListener('change', function () { pfst.status = sel.value; showCrackProfit(c).catch(fail(c)); });
    c.querySelectorAll('.cp-tg').forEach(function (b) { b.addEventListener('click', function () { var row = $('cp-sub-' + b.dataset.i); if (row) { var h = row.classList.toggle('hidden'); b.textContent = h ? '▸' : '▾'; } }); });
    c.querySelectorAll('.wk-open').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); showCrackDetail(c, Number(a.dataset.id)).catch(fail(c)); }); });
    var csv = $('rp-csv');
    if (csv) csv.addEventListener('click', function () {
      var ok = items.length && cpHasMoney(items[0]);
      dlCsv('crack-profit', CP_HEADS, ok ? items.map(function (it) { return cpCells(it, RAWN, RAWD, RAW); }).concat([cpTotalCells(t, RAWN)]) : []);
    });
  }

  // ---- عملیات داخلی (فقط‌خواندنی؛ workshop.list بدون قیمت)
  async function showInternal(c) {
    clearChrome(); setTitle('عملیات کارگاه داخلی');
    var r = await api('workshop.list', rangeParams(st.range));
    var items = r.items || [];
    c.innerHTML = '<div class="card"><div class="muted">ثبت عملیات از اپلیکیشن انجام می‌شود؛ این صفحه فقط نمایش است.</div></div>' + segHtml(st.range)
      + table(['تاریخ', 'نوع عملیات', 'مالک', 'ورودی', 'خروجی', 'تراز'], items.map(function (it) {
        return [E(jd(it.date)), E(OP_LABELS[it.op_type] || it.op_type || '—'), E(it.owner || '—'), E(num(it.input_kg)), E(num(it.output_kg)), E(Number(it.balance_ok) === 1 || it.balance_ok === true ? 'تراز صحیح' : 'نیاز به بررسی')];
      }));
    c.querySelectorAll('.seg-btn').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showInternal(c).catch(fail(c)); }); });
  }

  // ---- فایل خام شکست کارگاه‌ها (workshop.outside_raw؛ فقط‌خواندنی، بدون قیمت؛ مجوز stock.view)
  async function showOutsideRaw(c) {
    clearChrome(); setTitle('فایل خام شکست کارگاه‌ها');
    var r = await api('workshop.outside_raw', rangeParams(st.range));
    var items = r.items || [];
    var rows = items.map(function (it) { return outsideCells(it, num, jd, E); });
    if (items.length) rows.push(outsideTotalCells(r.totals || {}, num).map(function (x) { return '<b>' + E(x) + '</b>'; }));
    c.innerHTML = '<div class="card"><div class="muted">ثبت قبض از اپلیکیشن (برون‌سپاری) انجام می‌شود؛ این صفحه فقط نمایش است. افت = گرم ارسالی − گرم دریافتی. در ردیف جمع، گرم‌ها «میانگین» ردیف‌هایی‌اند که هر دو مقدار را دارند.</div></div>' + segHtml(st.range)
      + table(OUTSIDE_HEADS, rows);
    c.querySelectorAll('.seg-btn').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showOutsideRaw(c).catch(fail(c)); }); });
  }

  // ---- موجودی پستهٔ خرجی (فقط‌خواندنی؛ تعدیل دستی فقط از اپ). فیلتر مالک/کارگاه + بازه + «شامل دریافت‌شده‌ها»
  var fst = { owner: '', workshop: '', done: false };
  async function showFeedStock(c) {
    clearChrome(); setTitle('موجودی پستهٔ خرجی');
    var q = rangeParams(st.range);
    if (fst.owner) q.owner = fst.owner;
    if (fst.workshop) q.workshop = fst.workshop;
    if (fst.done) q.include_done = 1;
    var r = await api('feed.stock', q);
    var items = r.items || [], t = r.totals || {};
    var rows = items.map(function (it) { return feedCells(it, num, jd, E); });
    if (items.length) rows.push(feedTotalCells(t, num).map(function (x) { return '<b>' + E(x) + '</b>'; }));
    c.innerHTML = '<div class="card"><div class="card-row"><b>مانده در کارگاه‌ها</b><b>' + E(num(t.remaining_kg)) + ' کیلو</b></div>'
      + row2('ارسالی', num(t.sent_kg) + ' کیلو') + row2('برگشتی', num(t.returned_kg) + ' کیلو') + row2('ضایعات', num(t.waste_kg) + ' کیلو') + '</div>'
      + '<div class="card"><div style="display:flex;gap:8px;flex-wrap:wrap"><input id="fd-owner" class="input" placeholder="فیلتر مالک" value="' + E(fst.owner) + '">'
      + '<input id="fd-ws" class="input" placeholder="فیلتر کارگاه" value="' + E(fst.workshop) + '">'
      + '<label><input type="checkbox" id="fd-done"' + (fst.done ? ' checked' : '') + '> شامل دریافت‌شده‌ها</label>'
      + '<button type="button" class="btn btn-secondary" id="fd-apply" style="margin-top:0">اعمال</button></div>'
      + '<div class="muted" style="margin-top:6px">جدا از انبار اصلی؛ خودکار از کارگاه بیرونی و خط شکست + تعدیل دستی (ثبت تعدیل از اپلیکیشن). برگشتی = مغز + پوست + خاک؛ مانده = ارسالی − برگشتی − ضایعات.</div></div>'
      + segHtml(st.range) + table(FEED_HEADS, rows);
    c.querySelectorAll('.seg-btn').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showFeedStock(c).catch(fail(c)); }); });
    $('fd-apply').addEventListener('click', function () { fst.owner = $('fd-owner').value.trim(); fst.workshop = $('fd-ws').value.trim(); fst.done = $('fd-done').checked; showFeedStock(c).catch(fail(c)); });
  }

  var RAW = function (x) { return x; }, RAWN = function (n) { return String(n); }, RAWD = function (d) { return d || ''; };
  function dlCsv(name, heads, rows) {
    var blob = new Blob([csvText(heads, rows)], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name + '-' + isoOf(new Date()) + '.csv';
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }
  var CSV_BTN = '<button type="button" class="btn btn-secondary" id="rp-csv" style="margin-top:0">خروجی CSV (اکسل)</button>';
  // ---- ۶-۳: کارتکس مالک (reports.cartex؛ مجوز workshop.feed)
  var cst = { owner: '', party: '' };
  async function showCartex(c) {
    clearChrome(); setTitle('کارتکس مالک');
    var q = rangeParams(st.range);
    if (cst.owner) q.owner = cst.owner;
    if (cst.party) q.party = cst.party;
    var r = await api('reports.cartex', q);
    var rowsRaw = r.rows || [], t = r.totals || {}, owners = r.owners || [];
    var rows = rowsRaw.map(function (it) { return cartexCells(it, num, jd, E); });
    if (rowsRaw.length) rows.push(cartexTotalCells(t, num).map(function (x) { return '<b>' + E(x) + '</b>'; }));
    c.innerHTML = '<div class="card"><div class="card-row"><b>مانده کل (سراسری)</b><b>' + E(dash(t.balance_total_kg, function (v) { return num(v) + ' کیلو'; })) + '</b></div>'
      + row2('جمع مانده مالک‌ها', num(t.balance_kg) + ' کیلو') + row2('مانده افتتاحیه', num(t.opening_kg) + ' کیلو') + row2('ورود', num(t.in_kg) + ' کیلو') + row2('خروج', num(t.out_kg) + ' کیلو') + '</div>'
      + '<div class="card"><div style="display:flex;gap:8px;flex-wrap:wrap"><input id="ct-owner" class="input" placeholder="فیلتر مالک" value="' + E(cst.owner) + '">'
      + '<input id="ct-party" class="input" placeholder="فیلتر کارگاه" value="' + E(cst.party) + '">'
      + '<button type="button" class="btn btn-secondary" id="ct-apply" style="margin-top:0">اعمال</button>' + CSV_BTN + '</div>'
      + '<div class="muted" style="margin-top:6px">فقط کارگاه بیرونی با قبض: ارسال = خروج، دریافت = ورود. «مانده مالک» فقط زنجیرهٔ همان مالک است؛ «مانده کل همهٔ مالک‌ها» تجمعی همهٔ مالک‌ها با همان ترتیب تاریخ (مثل ستون J اکسل)؛ با یک مالک برابرند. مانده منفی = پسته‌ای که هنوز بیرون است. ضایعات برنمی‌گردد و فقط در توضیحات دریافت می‌آید. انس و دهنبست خالی‌اند. بدون قیمت.</div></div>'
      + segHtml(st.range)
      + (owners.length ? '<div class="card"><b>مانده به‌تفکیک مالک</b></div>' + table(CARTEX_OWNER_HEADS, owners.map(function (o) { return cartexOwnerCells(o, num, E); })) : '')
      + table(CARTEX_HEADS, rows);
    c.querySelectorAll('.seg-btn').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showCartex(c).catch(fail(c)); }); });
    $('rp-csv').addEventListener('click', function () { dlCsv('cartex', CARTEX_HEADS, rowsRaw.map(function (it) { return cartexCells(it, RAWN, RAWD, RAW); }).concat(rowsRaw.length ? [cartexTotalCells(t, RAWN)] : [])); });
    $('ct-apply').addEventListener('click', function () { cst.owner = $('ct-owner').value.trim(); cst.party = $('ct-party').value.trim(); showCartex(c).catch(fail(c)); });
  }

  // ---- ۶-۳: خلاصهٔ مدیریتی (reports.workshop_summary؛ مجوز stock.view؛ اپراتور کارگاه ندارد)
  var sst = { owner: '' };
  function kvCard(title, pairs, note) {
    return '<div class="card"><b>' + E(title) + '</b>' + pairs.map(function (p) { return row2(p[0], p[1]); }).join('') + (note ? '<div class="muted" style="margin-top:6px">' + E(note) + '</div>' : '') + '</div>';
  }
  async function showWsSummary(c) {
    clearChrome(); setTitle('خلاصهٔ مدیریتی کارگاه');
    var q = rangeParams(st.range);
    if (sst.owner) q.owner = sst.owner;
    var r = await api('reports.workshop_summary', q);
    c.innerHTML = '<div class="card"><div style="display:flex;gap:8px;flex-wrap:wrap"><input id="sm-owner" class="input" placeholder="فیلتر مالک" value="' + E(sst.owner) + '">'
      + '<button type="button" class="btn btn-secondary" id="sm-apply" style="margin-top:0">اعمال</button>' + CSV_BTN + '</div>'
      + '<div class="muted" style="margin-top:6px">نام ستون‌ها مطابق برگهٔ «کنترل ورود و خروج تولید»؛ «سایر ضایعات» حذف شده است. بدون قیمت.</div></div>'
      + segHtml(st.range)
      + kvCard('کارگاه داخلی (کنترل ورود و خروج تولید)', summaryRows(SUMMARY_INTERNAL, r.internal, num))
      + kvCard('خط شکست', summaryRows(SUMMARY_CRACK, r.crack, num), SUMMARY_CRACK_WARNING);
    c.querySelectorAll('.seg-btn').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showWsSummary(c).catch(fail(c)); }); });
    $('rp-csv').addEventListener('click', function () { dlCsv('workshop-summary', ['شاخص', 'مقدار'], [['کارگاه داخلی', '']].concat(summaryRows(SUMMARY_INTERNAL, r.internal, RAWN), [['خط شکست', '']], summaryRows(SUMMARY_CRACK, r.crack, RAWN), [[SUMMARY_CRACK_WARNING, '']])); });
    $('sm-apply').addEventListener('click', function () { sst.owner = $('sm-owner').value.trim(); showWsSummary(c).catch(fail(c)); });
  }

  // ---- ۶-۳: مقایسهٔ کارگاه‌ها/اپراتورها (reports.workshop_compare؛ مجوز workshop.feed)
  var pst = { by: 'workshop' };
  async function showWsCompare(c) {
    clearChrome(); setTitle('مقایسهٔ کارگاه‌ها');
    var q = rangeParams(st.range); q.by = pst.by;
    var r = await api('reports.workshop_compare', q);
    var rows = (r.rows || []).map(function (it) { return compareCells(it, num, E); });
    c.innerHTML = '<div class="seg">' + COMPARE_BY.map(function (b) { return '<button type="button" class="seg-btn by-btn' + (b[0] === pst.by ? ' active' : '') + '" data-by="' + b[0] + '">' + E(b[1]) + '</button>'; }).join('') + '</div>'
      + segHtml(st.range)
      + '<div class="card"><div class="muted">' + E(pst.by === 'workshop'
        ? 'ردیف‌ها بر اساس بازده (نزولی) مرتب‌اند. خط شکست به‌ازای کد خط، کارگاه داخلی و بیرونی به‌ازای نام کارگاه گروه می‌شوند؛ کارگاه بیرونی فقط سفارش‌های دریافت‌شده را می‌شمارد.'
        : 'کارگاه داخلی و بیرونی نام اپراتور ندارند؛ مقایسهٔ اپراتورها و ثبت‌کنندگان فقط از گزارش‌های خط شکست است (نام خالی = «نامشخص»).')
      + ' بازده = خروجی خوب ÷ ورودی؛ ظرفیت فقط خط شکست، میانگین افت گرم فقط بیرونی. ' + COMPARE_CRACK_NOTE + ' بدون قیمت.</div></div>'
      + '<div style="margin:6px 0">' + CSV_BTN + '</div>' + table(COMPARE_HEADS, rows);
    $('rp-csv').addEventListener('click', function () { dlCsv('workshop-compare', COMPARE_HEADS, (r.rows || []).map(function (it) { return compareCells(it, RAWN, RAW); })); });
    c.querySelectorAll('.by-btn').forEach(function (b) { b.addEventListener('click', function () { pst.by = b.dataset.by; showWsCompare(c).catch(fail(c)); }); });
    c.querySelectorAll('.seg-btn:not(.by-btn)').forEach(function (b) { b.addEventListener('click', function () { st.range = b.dataset.v; showWsCompare(c).catch(fail(c)); }); });
  }

  // ---- کارت صفحهٔ اصلی (از app.js بعد از renderHome صدا زده می‌شود؛ بدون مجوز/خطا/خالی ⇒ چیزی اضافه نمی‌شود)
  async function decorateHome(c) {
    try {
      if (!c || !perm('workshop.feed') || opOnly()) return;
      var r = await api('feed.stock', {});
      var line = feedHomeLine(r && r.summary, num);
      if (!line || !c.isConnected || c.querySelector('#home-feed')) return;
      var card = document.createElement('div');
      card.className = 'card'; card.id = 'home-feed'; card.style.cursor = 'pointer';
      card.innerHTML = '<div class="card-row"><b style="font-size:17px">موجودی پستهٔ خرجی</b><span>‹</span></div><div class="muted">' + E(line) + '</div>';
      card.addEventListener('click', function () { if (window.PBShell) PBShell.openModule('feed.stock'); });
      var dues = c.querySelector('#home-dues');
      if (dues && dues.parentNode) dues.parentNode.insertBefore(card, dues); else c.appendChild(card);
    } catch (e) { /* آفلاین/بدون دسترسی: کارت نمایش داده نمی‌شود */ }
  }

  function fail(c) { return function (err) { c.innerHTML = '<div class="empty-state">' + E((err && err.message) || 'خطا در بارگذاری') + '</div>'; }; }

  // ---- منوی محدود «اپراتور کارگاه»
  var TABS = [['wk-crack', '🏭', 'خط شکست'], ['wk-internal', '⚙️', 'عملیات داخلی'], ['wk-feed', '📦', 'پستهٔ خرجی'], ['wk-cartex', '📒', 'کارتکس'], ['wk-compare', '📊', 'مقایسه']];
  function go(tab) {
    var c = $('content'); if (!c) return;
    document.querySelectorAll('#wk-tabbar .tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab); });
    (tab === 'wk-internal' ? showInternal(c) : tab === 'wk-feed' ? showFeedStock(c) : tab === 'wk-cartex' ? showCartex(c) : tab === 'wk-compare' ? showWsCompare(c) : showCrackList(c)).catch(fail(c));
  }
  function applyRole() {
    var only = opOnly();
    document.body.classList.toggle('wk-only', only);
    var nav = $('wk-tabbar');
    if (!only) { if (nav) nav.remove(); return; }
    if (!nav) {
      nav = document.createElement('nav'); nav.id = 'wk-tabbar'; nav.className = 'tabbar';
      nav.innerHTML = TABS.map(function (t) { return '<button type="button" class="tab" data-tab="' + t[0] + '"><span>' + t[1] + '</span><small>' + t[2] + '</small></button>'; }).join('');
      nav.addEventListener('click', function (e) { var b = e.target.closest('.tab'); if (b) go(b.dataset.tab); });
      $('app-view').appendChild(nav);
      go('wk-crack'); // فقط بار اول؛ bootstrapهای بعدی صفحهٔ باز را ریست نمی‌کنند
    }
  }

  // ---- ثبت ماژول‌ها در منوی کشویی (مالک/دارندگان مجوز)
  var HI = {
    'workshop.crack_list': async function (c) { await showCrackList(c); },
    'workshop.internal_list': async function (c) { await showInternal(c); },
    'reports.crack_profit': async function (c) { if (!canProfit()) { c.innerHTML = '<div class="empty-state">دسترسی این بخش برای کاربر شما فعال نیست</div>'; return; } await showCrackProfit(c); }, // CP3
    'workshop.outside_raw': async function (c) { await showOutsideRaw(c); },
    'feed.stock': async function (c) { await showFeedStock(c); },
    'reports.cartex': async function (c) { await showCartex(c); },
    'reports.workshop_summary': async function (c) { await showWsSummary(c); },
    'reports.workshop_compare': async function (c) { await showWsCompare(c); },
  };
  var prev = window.PBModules;
  window.PBModules = {
    has: function (k) { return typeof HI[k] === 'function' || (prev && prev.has(k)); },
    open: function (k, c) { return typeof HI[k] === 'function' ? HI[k](c) : prev.open(k, c); },
  };

  root.PBCrack = { roleOnly: opOnly, applyRole: applyRole, decorateHome: decorateHome, roleTab: function (tab) { go(['wk-internal', 'wk-feed', 'wk-cartex', 'wk-compare'].indexOf(tab) !== -1 ? tab : 'wk-crack'); }, _pure: PURE };
})(typeof window !== 'undefined' ? window : this);
