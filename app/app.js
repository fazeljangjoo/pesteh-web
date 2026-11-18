// ===================================================================
// تنظیمات: آدرس بک‌اند (پوشهٔ backend/api/ روی هاست)
// پیش‌فرض: اگر پوشهٔ web/ را در public_html/app/ و بک‌اند را در public_html/backend/api/ گذاشته‌اید،
// آدرس خودکار پیدا می‌شود. اگر ساختار هاست شما فرق دارد، آدرس کامل را (با / در انتها) این‌جا بنویسید:
//   const API_BASE_OVERRIDE = 'https://pestehbazar.ir/backend/api/';
// ===================================================================
const API_BASE_OVERRIDE = '';
const API_BASE = API_BASE_OVERRIDE || new URL('../backend/api/', location.href).href;

const state = {
  phone: localStorage.getItem('pb_phone') || '',
  token: localStorage.getItem('pb_token') || '',
  categories: [], types: [], customer: null,
  tab: 'home', blocked: false,
  moneyUnit: 'toman', // S29.T29.4: واحد پول سرور (toman|rial)؛ از bootstrap؛ در هر درخواست به‌صورت unit اعلام می‌شود
  isFx: false, // پلن ارزی (قیمت دلار، سود دلاری، فاکتور ارزی)؛ مرجع تصمیم سرور است (bootstrap)
};

// ---------------------------------------------------------------- امنیت: escape خروجی HTML
function esc(v) {
  return String(v == null ? '' : v).replace(/[&<>"'`]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[c]));
}
/** فقط data-URL تصویر (base64) را می‌پذیرد؛ هر چیز دیگر رد می‌شود */
function safeImg(src) {
  return (typeof src === 'string' && /^data:image\/(jpeg|png|webp|gif);base64,[A-Za-z0-9+/=]+$/.test(src)) ? src : '';
}

// ---------------------------------------------------------------- کار آفلاین (صف ارسال + کش خواندن)
// اکشن‌هایی که فقط خواندن اطلاعات هستند: در صورت آفلاین‌بودن، آخرین پاسخ کش‌شده نمایش داده می‌شود.
const READ_ACTIONS = new Set([
  'bootstrap', 'purchases.list', 'purchases.get', 'sales.list', 'sales.get',
  'parties.list', 'dues.list', 'reports.tx_all', 'account.status', 'sale_returns.profit_lines',
]);
// اکشن‌هایی که تغییر داده می‌دهند و در صورت آفلاین‌بودن به‌جای ارسال فوری، در صف ذخیره می‌شوند.
// (categories.create/types.create عمداً اینجا نیستند چون بلافاصله به id واقعی نیاز دارند)
const QUEUEABLE_ACTIONS = new Set([
  'purchases.create', 'purchases.delete', 'mixes.create', 'mixes.delete',
  'payments.save', 'receipts.save', 'sales.create', 'sales.update', 'sales.delete',
  'dues.mark_paid', 'parties.create', 'loans.create', 'loans.delete',
  'entity.save', 'entity.delete', 'intake.submit', // S8: پذیرش اتاق انس (پیش‌نویس/درصدها/ثبت نهایی) آفلاین‌-اول
]);
// همهٔ اکشن‌های نوشتنی؛ برای هرکدام یک کلید یکتا (idem) به سرور فرستاده می‌شود تا ارسال تکراری، دوبار ثبت نشود
const IDEM_ACTIONS = new Set([...QUEUEABLE_ACTIONS, 'categories.create', 'types.create', 'mixes.update', 'backup.import', 'intake.confirm', 'intake.reject', 'intake.mark_sample']); // S8: قطعی‌سازی/رد/نمونه فقط آنلاین (در صف نمی‌روند) ولی idem دارند
const QUEUE_KEY = 'pb_outbox';
const FAILED_KEY = 'pb_failed';
const IDMAP_KEY = 'pb_idmap';
const CACHE_PREFIX = 'pb_cache_';
const FETCH_TIMEOUT = 30000;

function newId() {
  try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID(); } catch {}
  return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 8);
}
function lsJson(key, fallback) { try { const v = JSON.parse(localStorage.getItem(key) || 'null'); return v == null ? fallback : v; } catch { return fallback; } }

function readQueue() { const q = lsJson(QUEUE_KEY, []); return Array.isArray(q) ? q : []; }
/** false برمی‌گرداند اگر حافظهٔ مرورگر پر باشد (تا داده بی‌صدا از دست نرود) */
function writeQueue(q) {
  try { localStorage.setItem(QUEUE_KEY, JSON.stringify(q)); } catch (e) { return false; }
  updateSyncBadge();
  return true;
}
function readFailed() { const q = lsJson(FAILED_KEY, []); return Array.isArray(q) ? q : []; }
function writeFailed(q) { try { localStorage.setItem(FAILED_KEY, JSON.stringify(q)); } catch (e) { return false; } updateSyncBadge(); return true; }
function queueLength() { return readQueue().length; }
function readIdMap() { return new Map(Object.entries(lsJson(IDMAP_KEY, {})).map(([k, v]) => [Number(k), v])); }
function saveIdMap(map) { try { localStorage.setItem(IDMAP_KEY, JSON.stringify(Object.fromEntries(map))); } catch {} }

function cacheKey(action, payload) {
  // برای اکشن‌های وابسته به شناسه (get/detail) کلید کش شامل شناسه هم می‌شود
  const id = payload && (payload.id ?? payload.party ?? '');
  return CACHE_PREFIX + action + (id !== '' ? ':' + id : '');
}
function getCache(action, payload) {
  try { return JSON.parse(localStorage.getItem(cacheKey(action, payload)) || 'null'); } catch { return null; }
}
function clearCaches(prefixOnly) {
  const p = CACHE_PREFIX + (prefixOnly || '');
  const del = [];
  for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (k && k.startsWith(p)) del.push(k); }
  del.forEach((k) => localStorage.removeItem(k));
}
function setCache(action, payload, data) {
  // عکس رسید (base64 سنگین) کش نمی‌شود تا localStorage پر نشود
  if (data && data.item && data.item.photo) data = { ...data, item: { ...data.item, photo: null } };
  const key = cacheKey(action, payload);
  let str;
  try { str = JSON.stringify(data); } catch { return; }
  try { localStorage.setItem(key, str); return; } catch {}
  // حافظه پر است: اول کش جزئیات، بعد همهٔ کش‌ها را پاک می‌کنیم و یک بار دیگر تلاش می‌کنیم
  clearCaches('purchases.get'); clearCaches('sales.get');
  try { localStorage.setItem(key, str); return; } catch {}
  clearCaches();
  try { localStorage.setItem(key, str); } catch { /* کش اختیاری است */ }
}

// یک شناسهٔ موقت منفی برای رکوردهایی که هنوز در صف هستند و id واقعی از سرور نگرفته‌اند
function tempId() { return -(Date.now() * 1000 + Math.floor(Math.random() * 1000)); }

/** در payload یک آیتم صف، هر شناسهٔ موقتی که قبلاً real شده را جایگزین می‌کند */
function remapIds(obj, map) {
  if (Array.isArray(obj)) { obj.forEach(v => remapIds(v, map)); return; }
  if (obj && typeof obj === 'object') {
    for (const k of Object.keys(obj)) {
      const v = obj[k];
      if (typeof v === 'number' && v < 0 && map.has(v)) obj[k] = map.get(v);
      else if (v && typeof v === 'object') remapIds(v, map);
    }
  }
}

function isOffline() { return !navigator.onLine; }

function fetchWithTimeout(url, opts, ms) {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), ms || FETCH_TIMEOUT);
  return fetch(url, { ...opts, signal: ctl.signal }).finally(() => clearTimeout(t));
}

function updateSyncBadge() {
  const el = document.getElementById('sync-badge');
  if (!el) return;
  const n = readQueue().length, f = readFailed().length;
  const parts = [];
  if (n > 0) parts.push(`⏳ ${n.toLocaleString('fa-IR')} در صف`);
  if (f > 0) parts.push(`⚠️ ${f.toLocaleString('fa-IR')} خطا`);
  if (parts.length) { el.textContent = parts.join(' · '); el.classList.remove('hidden'); }
  else el.classList.add('hidden');
}

const ACTION_NAMES = {
  'purchases.create': 'ثبت/ویرایش خرید', 'purchases.delete': 'حذف خرید', 'mixes.create': 'ثبت مخلوط/جداسازی', 'mixes.delete': 'حذف مخلوط/جداسازی',
  'payments.save': 'ثبت پرداخت', 'receipts.save': 'ثبت دریافت', 'sales.create': 'ثبت فروش', 'sales.update': 'ویرایش فروش',
  'sales.delete': 'حذف فروش', 'dues.mark_paid': 'تسویهٔ سررسید',
  'parties.create': 'افزودن طرف‌حساب', 'loans.create': 'ثبت قرض/بازپرداخت', 'loans.delete': 'حذف قرض/بازپرداخت',
  'entity.save': 'ذخیرهٔ پذیرش اتاق انس', 'entity.delete': 'حذف ردیف پذیرش', 'intake.submit': 'ثبت نهایی پذیرش اتاق انس',
};
function describeEntry(e) {
  const p = e.payload || {};
  const extra = p.type_name || p.buyer || p.seller || (p.data && p.data.type_name) || '';
  return (ACTION_NAMES[e.action] || e.action) + (extra ? ' — ' + extra : '');
}

/** آیتم‌های صفِ کاربر دیگر (اگر روی همین دستگاه کسی دیگر وارد شده) هرگز ارسال نمی‌شوند؛ حذف می‌شوند */
function purgeForeignOutbox() {
  const mine = (e) => !e.owner || e.owner === state.phone;
  const q = readQueue(), f = readFailed();
  if (q.some((e) => !mine(e))) writeQueue(q.filter(mine));
  if (f.some((e) => !mine(e))) writeFailed(f.filter(mine));
}

let _flushing = false;
async function flushQueue(silent) {
  if (_flushing || isOffline() || !state.token) return;
  purgeForeignOutbox();
  if (!readQueue().length) return;
  if (navigator.locks && navigator.locks.request) {
    // جلوگیری از ارسال هم‌زمان از چند تب
    await navigator.locks.request('pb_flush', { ifAvailable: true }, async (lock) => { if (lock) await flushQueueLocked(silent); });
  } else {
    await flushQueueLocked(silent);
  }
}
async function flushQueueLocked(silent) {
  if (_flushing) return;
  _flushing = true;
  const map = readIdMap(); // tempId(purchaseId ساخته‌شده در صف) -> id واقعی؛ بین دفعات ارسال حفظ می‌شود
  let synced = 0, failedNow = 0;
  try {
    while (true) {
      const cur = readQueue();
      if (!cur.length) break;
      const item = cur[0];
      remapIds(item.payload, map);
      let res, data;
      try {
        res = await fetchWithTimeout(API_BASE + 'app.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ phone: state.phone, token: state.token, action: item.action, payload: item.payload, idem: item.idem, unit: item.unit || 'toman' }),
        });
        try { data = await res.json(); } catch { data = {}; }
      } catch (netErr) {
        break; // آفلاین یا timeout؛ بعداً با همان کلید یکتا دوباره تلاش می‌شود (ثبت تکراری نمی‌شود)
      }
      if (res.ok) {
        if (window.PBRealtime) PBRealtime.noteOwn(item.action, item.payload); // S8
        if (item.tempId != null && data && typeof data.id === 'number') { map.set(item.tempId, data.id); saveIdMap(map); }
        writeQueue(readQueue().filter((e) => e.qid !== item.qid));
        synced++;
        continue;
      }
      if (res.status === 401) { toast('نشست شما منقضی شده؛ دوباره وارد شوید (موارد ارسال‌نشده حفظ می‌شوند)'); doLogout({ keepOutbox: true }); break; }
      // STAGE11.2: ۴۰۳ با error='forbidden' یعنی مجوز همین عملیات برای کارکن نیست (نه اشتراک مسدود)؛ مورد به «خطاها» می‌رود تا کل صف قفل نشود
      const forbiddenOp = res.status === 403 && data && data.error === 'forbidden';
      if (res.status === 403 && !forbiddenOp) { state.blocked = true; break; }
      if (data && (data.error === 'upgrade_required' || data.error === 'unit_mismatch')) { toast('واحد پول سرور تغییر کرده؛ صفحه را تازه کنید (موارد صف حفظ می‌شوند)'); break; } // S29.T29.4
      // STAGE11.1: ۴۰۹ فقط وقتی موقتی است که سرور بگوید «درخواست قبلی هنوز در حال پردازش است»؛ ۴۰۹ قطعی (مثلاً تعارض/موجودی) به «خطاها» می‌رود
      const busy409 = res.status === 409 && String((data && data.error) || '').includes('در حال پردازش');
      const transient = busy409 || res.status === 408 || res.status === 429 || res.status >= 500;
      if (transient) {
        item.fails = (item.fails || 0) + (res.status >= 500 || busy409 ? 1 : 0);
        if (item.fails < 5) {
          writeQueue(readQueue().map((e) => (e.qid === item.qid ? { ...e, fails: item.fails } : e)));
          break; // خطای موقت سرور: بعداً دوباره
        }
      }
      // خطای قطعی (اعتبارسنجی، یافت‌نشد، حجم زیاد، یا ۵ بار خطای سرور): مورد از صف خارج و در «خطاها» نگه‌داری می‌شود
      // تا بقیهٔ صف قفل نشود
      const failed = readFailed();
      failed.push({ ...item, error: (data && data.error) || `خطا (${res.status})`, failedAt: Date.now() });
      writeFailed(failed);
      writeQueue(readQueue().filter((e) => e.qid !== item.qid));
      failedNow++;
    }
  } finally {
    _flushing = false;
  }
  if (!readQueue().length) { try { localStorage.removeItem(IDMAP_KEY); } catch {} }
  updateSyncBadge();
  if (failedNow > 0) toast(`${failedNow.toLocaleString('fa-IR')} مورد صف به‌خاطر خطا ثبت نشد؛ روی نشانگر صف بزنید`);
  else if (synced > 0 && !silent) toast(`${synced.toLocaleString('fa-IR')} مورد از صف ارسال، ثبت شد`);
  if ((synced > 0 || failedNow > 0) && ['home', 'inventory', 'sales', 'parties', 'dues'].includes(state.tab) && !document.querySelector('.modal-overlay') && !document.getElementById('ii-form') && !document.getElementById('ic-form') && !(window.PBIntake && PBIntake.roomOnly()) && !(window.PBCartex && PBCartex.roleOnly())) setTab(state.tab); // S8: فرم پذیرش/قیمت‌گذاری هرگز با ارسال صف پاک نشود
}
window.addEventListener('online', () => flushQueue());
setInterval(() => { if (!isOffline()) flushQueue(true); }, 25000);
document.addEventListener('visibilitychange', () => { if (!document.hidden && !isOffline()) flushQueue(true); });

/** پنجرهٔ مدیریت صف: مشاهده، ارسال مجدد و حذف موارد (هم در صف، هم خطادار) */
function openQueueModal() {
  let close = null;
  const build = () => {
    const q = readQueue(), f = readFailed();
    const row = (e, kind) => `<div class="card"><div style="display:flex;gap:8px;align-items:flex-start">
      <div style="flex:1"><div><b>${esc(describeEntry(e))}</b></div>
      <div class="meta">${esc(new Date(e.failedAt || e.ts || Date.now()).toLocaleString('fa-IR'))}</div>
      ${kind === 'f' ? `<div class="error" style="margin-top:4px">${esc(e.error || '')}</div>` : ''}</div>
      <button class="btn btn-danger q-del" data-k="${kind}" data-qid="${esc(e.qid)}" style="width:auto;padding:6px 12px;margin:0">حذف</button>
    </div></div>`;
    return `<h2>صف ارسال</h2>
      ${q.length ? `<div class="section-title">در انتظار ارسال (${q.length.toLocaleString('fa-IR')})</div>${q.map((e) => row(e, 'q')).join('')}
        <button class="btn btn-secondary" id="q-retry">🔄 ارسال دوباره</button>` : '<div class="muted" style="margin:8px 0">موردی در انتظار ارسال نیست</div>'}
      ${f.length ? `<div class="section-title">ناموفق (${f.length.toLocaleString('fa-IR')})</div>${f.map((e) => row(e, 'f')).join('')}
        <button class="btn btn-danger" id="q-clear-failed">حذف همهٔ موارد ناموفق</button>` : ''}
      <button class="btn btn-secondary" id="q-close">بستن</button>`;
  };
  const show = () => {
    if (close) close();
    close = openModal(build());
    document.getElementById('q-close').onclick = () => close();
    const retry = document.getElementById('q-retry');
    if (retry) retry.onclick = async () => { await flushQueue(); show(); };
    const clr = document.getElementById('q-clear-failed');
    if (clr) clr.onclick = () => { if (confirm('همهٔ موارد ناموفق حذف شوند؟')) { writeFailed([]); show(); } };
    document.querySelectorAll('.q-del').forEach((b) => b.addEventListener('click', () => {
      if (!confirm('این مورد حذف شود؟ (دیگر ارسال نخواهد شد)')) return;
      const qid = b.dataset.qid;
      if (b.dataset.k === 'q') writeQueue(readQueue().filter((e) => e.qid !== qid));
      else writeFailed(readFailed().filter((e) => e.qid !== qid));
      show();
      if (['home', 'inventory', 'sales', 'parties', 'dues'].includes(state.tab)) setTab(state.tab);
    }));
  };
  show();
}

// ---------------------------------------------------------------- کمک‌کننده‌ها

function toman(n) {
  // S35.T35.12: واحد ذخیره/نمایش از PBFmt (تنظیمات شرکت)؛ برچسب واحد همیشه نمایش داده می‌شود
  return PBFmt.money(Math.round(Number(n) || 0));
}
/** فیلد قفل‌شدهٔ ویژهٔ پلن ارزی؛ برای کاربر پلن ریالی نمایش داده می‌شود ولی غیرفعال است */
function fxLockedHtml(label) {
  return `<div class="field"><label>🔒 ${label} (ویژهٔ پلن ارزی)</label><input type="text" disabled placeholder="برای فعال‌سازی با پشتیبانی تماس بگیرید"></div>`;
}
/** مبلغ دلاری (پلن ارزی) */
function usd(n, dec = 2) {
  return (Number(n) || 0).toLocaleString('fa-IR', { minimumFractionDigits: 0, maximumFractionDigits: dec }) + ' دلار';
}
function kg(n) {
  return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('fa-IR') + ' کیلو';
}
/** تاریخ امروز به وقت ایران (نه UTC و نه ساعت دستگاه) → [سال، ماه، روز] میلادی */
const _tehranFmt = (() => { try { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit' }); } catch { return null; } })();
function tehranYMD() {
  if (_tehranFmt) {
    const p = {};
    _tehranFmt.formatToParts(new Date()).forEach((x) => { p[x.type] = x.value; });
    if (p.year && p.month && p.day) return [Number(p.year), Number(p.month), Number(p.day)];
  }
  const d = new Date();
  return [d.getFullYear(), d.getMonth() + 1, d.getDate()];
}
function todayStr() {
  const [y, m, d] = tehranYMD();
  return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.remove('hidden');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => t.classList.add('hidden'), 2600);
}

// ---------------------------------------------------------------- تقویم شمسی (دقیقاً همان الگوریتم اپ اندروید)

function toFaDigits(s) {
  const fa = '۰۱۲۳۴۵۶۷۸۹';
  return String(s).replace(/\d/g, (d) => fa[+d]);
}
function toEnDigitsStr(s) {
  const fa = '۰۱۲۳۴۵۶۷۸۹', ar = '٠١٢٣٤٥٦٧٨٩';
  let out = String(s == null ? '' : s);
  for (let i = 0; i < 10; i++) out = out.split(fa[i]).join(String(i)).split(ar[i]).join(String(i));
  return out.replace(/[٫،]/g, '.').replace(/٬/g, '');
}
function _divp(a, b) { return Math.trunc(a / b); }
function _g2jdn(y, m, d) {
  const a = Math.trunc((14 - m) / 12);
  const y2 = y + 4800 - a;
  const m2 = m + 12 * a - 3;
  return d + Math.trunc((153 * m2 + 2) / 5) + 365 * y2 + Math.trunc(y2 / 4) - Math.trunc(y2 / 100) + Math.trunc(y2 / 400) - 32045;
}
function _jdn2g(jdn) {
  const a = jdn + 32044;
  const b = Math.trunc((4 * a + 3) / 146097);
  const c = a - Math.trunc((146097 * b) / 4);
  const d = Math.trunc((4 * c + 3) / 1461);
  const e = c - Math.trunc((1461 * d) / 4);
  const m = Math.trunc((5 * e + 2) / 153);
  const day = e - Math.trunc((153 * m + 2) / 5) + 1;
  const month = m + 3 - 12 * Math.trunc(m / 10);
  const year = 100 * b + d - 4800 + Math.trunc(m / 10);
  return [year, month, day];
}
const _kJalaliBreaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
function _jalCal(jy) {
  const bl = _kJalaliBreaks.length;
  const gy = jy + 621;
  let leapJ = -14;
  let jp = _kJalaliBreaks[0];
  let jump = 0;
  for (let i = 1; i < bl; i++) {
    const jm = _kJalaliBreaks[i];
    jump = jm - jp;
    if (jy < jm) break;
    leapJ = leapJ + _divp(jump, 33) * 8 + _divp(jump % 33, 4);
    jp = jm;
  }
  let n = jy - jp;
  leapJ = leapJ + _divp(n, 33) * 8 + _divp((n % 33) + 3, 4);
  if (jump % 33 === 4 && jump - n === 4) leapJ += 1;
  const leapG = _divp(gy, 4) - _divp((_divp(gy, 100) + 1) * 3, 4) - 150;
  const march = 20 + leapJ - leapG;
  if (jump - n < 6) n = n - jump + _divp(jump + 4, 33) * 33;
  let leap = ((n + 1) % 33 - 1) % 4;
  if (leap < 0) leap += 4;
  return { leap, gy, march };
}
function isLeapJalaliYear(jy) { return _jalCal(jy).leap === 0; }
function daysInJalaliMonth(jy, jm) {
  if (jm <= 6) return 31;
  if (jm <= 11) return 30;
  return isLeapJalaliYear(jy) ? 30 : 29;
}
function _j2jdn(jy, jm, jd) {
  const c = _jalCal(jy);
  return _g2jdn(c.gy, 3, c.march) + 31 * (jm - 1) - _divp(jm, 7) * (jm - 7) + jd - 1;
}
function _jdn2j(jdn) {
  const gy0 = _jdn2g(jdn)[0];
  let jy = gy0 - 621;
  let c = _jalCal(jy);
  let jdn1f = _g2jdn(c.gy, 3, c.march);
  if (jdn < jdn1f) {
    jy -= 1;
    c = _jalCal(jy);
    jdn1f = _g2jdn(c.gy, 3, c.march);
  }
  let k = jdn - jdn1f;
  let jm, jd;
  if (k <= 185) {
    jm = 1 + Math.trunc(k / 31);
    jd = (k % 31) + 1;
  } else {
    k -= 186;
    jm = 7 + Math.trunc(k / 30);
    jd = (k % 30) + 1;
  }
  return [jy, jm, jd];
}
function gregorianToJalali(gy, gm, gd) { return _jdn2j(_g2jdn(gy, gm, gd)); }
function jalaliToGregorianYMD(jy, jm, jd) { return _jdn2g(_j2jdn(jy, jm, jd)); }
function pad2(n) { return String(n).padStart(2, '0'); }
function todayJalaliYMD() {
  const [y, m, d] = tehranYMD();
  return gregorianToJalali(y, m, d);
}
function isoToJalaliYMD(iso) {
  const [gy, gm, gd] = String(iso).split('-').map(Number);
  return gregorianToJalali(gy, gm, gd);
}
function jalaliYMDToIso(jy, jm, jd) {
  const g = jalaliToGregorianYMD(jy, jm, jd);
  return `${g[0]}-${pad2(g[1])}-${pad2(g[2])}`;
}
function isoToJalaliDisplay(iso) {
  if (!iso) return '';
  const j = isoToJalaliYMD(iso);
  return toFaDigits(`${j[0]}/${pad2(j[1])}/${pad2(j[2])}`);
}
const kJalaliMonthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
const kJalaliWeekdayShort = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
function jalaliWeekdayIndex(iso) {
  const [gy, gm, gd] = String(iso).split('-').map(Number);
  const d = new Date(gy, gm - 1, gd);
  return (d.getDay() + 1) % 7; // شنبه=۰
}

// ---------------------------------------------------------------- ابزارِ فیلد تاریخ شمسی (جایگزین <input type=date>)

const _datePickers = {};
/** یک فیلد تاریخ شمسی (فقط خواندنی، با تقویم بازشو) را داخل containerId می‌سازد */
function mountJalaliDate(containerId, initialIso, onChange) {
  const box = document.getElementById(containerId);
  if (!box) return;
  let iso = initialIso || todayStr();
  box.innerHTML = `<div class="jdate-wrap">
    <input type="text" class="jdate-input" readonly placeholder="انتخاب تاریخ">
    <span class="jdate-icon">📅</span>
    <div class="jdate-panel hidden"></div>
  </div>`;
  const input = box.querySelector('.jdate-input');
  const panel = box.querySelector('.jdate-panel');
  let viewY, viewM;

  function setIso(newIso) {
    iso = newIso;
    input.value = isoToJalaliDisplay(iso);
  }
  function renderPanel() {
    const days = daysInJalaliMonth(viewY, viewM);
    const first = jalaliYMDToIso(viewY, viewM, 1);
    const startIdx = jalaliWeekdayIndex(first);
    const selJ = iso ? isoToJalaliYMD(iso) : null;
    const todayJ = todayJalaliYMD();
    const yearOpts = [];
    for (let y = viewY - 8; y <= viewY + 8; y++) yearOpts.push(`<option value="${y}" ${y === viewY ? 'selected' : ''}>${toFaDigits(y)}</option>`);
    const monthOpts = kJalaliMonthNames.map((mn, i) => `<option value="${i + 1}" ${i + 1 === viewM ? 'selected' : ''}>${mn}</option>`).join('');
    let cells = '';
    for (const w of kJalaliWeekdayShort) cells += `<div class="jdate-wd">${w}</div>`;
    for (let i = 0; i < startIdx; i++) cells += `<div></div>`;
    for (let d = 1; d <= days; d++) {
      const isSel = selJ && selJ[0] === viewY && selJ[1] === viewM && selJ[2] === d;
      const isToday = todayJ[0] === viewY && todayJ[1] === viewM && todayJ[2] === d;
      cells += `<button type="button" class="jdate-day ${isSel ? 'sel' : ''} ${isToday ? 'today' : ''}" data-d="${d}">${toFaDigits(d)}</button>`;
    }
    panel.innerHTML = `
      <div class="jdate-nav">
        <button type="button" class="jdate-nav-btn" id="${containerId}-prev">‹</button>
        <select id="${containerId}-msel">${monthOpts}</select>
        <select id="${containerId}-ysel">${yearOpts}</select>
        <button type="button" class="jdate-nav-btn" id="${containerId}-next">›</button>
      </div>
      <div class="jdate-grid">${cells}</div>
      <button type="button" class="jdate-today-btn" id="${containerId}-today">امروز</button>
    `;
    panel.querySelector(`#${containerId}-prev`).onclick = () => { viewM--; if (viewM < 1) { viewM = 12; viewY--; } renderPanel(); };
    panel.querySelector(`#${containerId}-next`).onclick = () => { viewM++; if (viewM > 12) { viewM = 1; viewY++; } renderPanel(); };
    panel.querySelector(`#${containerId}-msel`).onchange = (e) => { viewM = Number(e.target.value); renderPanel(); };
    panel.querySelector(`#${containerId}-ysel`).onchange = (e) => { viewY = Number(e.target.value); renderPanel(); };
    panel.querySelector(`#${containerId}-today`).onclick = () => {
      const t = todayJalaliYMD();
      setIso(jalaliYMDToIso(t[0], t[1], t[2]));
      panel.classList.add('hidden');
      if (onChange) onChange(iso);
    };
    panel.querySelectorAll('.jdate-day').forEach((btn) => {
      btn.onclick = () => {
        setIso(jalaliYMDToIso(viewY, viewM, Number(btn.dataset.d)));
        panel.classList.add('hidden');
        if (onChange) onChange(iso);
      };
    });
  }
  function openPanel() {
    const j = iso ? isoToJalaliYMD(iso) : todayJalaliYMD();
    viewY = j[0]; viewM = j[1];
    renderPanel();
    document.querySelectorAll('.jdate-panel').forEach((p) => { if (p !== panel) p.classList.add('hidden'); });
    panel.classList.toggle('hidden');
  }
  input.addEventListener('click', openPanel);
  box.querySelector('.jdate-icon').addEventListener('click', openPanel);
  document.addEventListener('click', (e) => { if (!box.contains(e.target)) panel.classList.add('hidden'); });
  setIso(iso);
  _datePickers[containerId] = { get: () => iso, set: setIso };
  return _datePickers[containerId];
}
/** مقدار ISO فعلی یک فیلد تاریخ شمسی را برمی‌گرداند */
function dateVal(containerId) {
  return (_datePickers[containerId] && _datePickers[containerId].get()) || '';
}

// ---------------------------------------------------------------- فیلتر لیست خرید/فروش (ترتیب + بازهٔ شمسی؛ پیش‌فرض: سال پسته‌ای)
// سال پسته‌ای = ۱ مهر تا ۳۱ شهریورِ سال بعد. حالت فیلتر: { newest: bool, from: 'yyyy-mm-dd'|'', to: 'yyyy-mm-dd'|'' }

function pistachioYearOf(jy, jm) { return jm >= 7 ? jy : jy - 1; }
function pistachioRange(y) { return { from: jalaliYMDToIso(y, 7, 1), to: jalaliYMDToIso(y + 1, 6, 31) }; }
function defaultDocFilter() {
  const [jy, jm] = todayJalaliYMD();
  const r = pistachioRange(pistachioYearOf(jy, jm));
  return { newest: true, from: r.from, to: r.to };
}
/** اگر بازه دقیقاً یک سال پسته‌ای کامل باشد سالِ شروعش، وگرنه null */
function docFilterYear(f) {
  if (!f.from || !f.to) return null;
  const [jy, jm] = isoToJalaliYMD(f.from);
  const y = pistachioYearOf(jy, jm);
  const r = pistachioRange(y);
  return (f.from === r.from && f.to === r.to) ? y : null;
}
function docFilterLabel(f) {
  const y = docFilterYear(f);
  if (y !== null) return `سال پسته‌ای ${toFaDigits(y)}–${toFaDigits(y + 1)}`;
  if (!f.from && !f.to) return 'همهٔ تاریخ‌ها';
  return `از ${f.from ? isoToJalaliDisplay(f.from) : '...'} تا ${f.to ? isoToJalaliDisplay(f.to) : '...'}`;
}
/** لیست فیلترشده و مرتب‌شده (آرایهٔ ورودی تغییر نمی‌کند). ردیف بدون تاریخ در بازه حساب می‌شود. */
function applyDocFilter(items, f) {
  const day = (it) => String(it.date || '').slice(0, 10);
  const out = items.filter((it) => { const d = day(it); return !d || ((!f.from || d >= f.from) && (!f.to || d <= f.to)); });
  const key = (it) => day(it) || '9999-99-99';
  out.sort((a, b) => {
    let c = key(a).localeCompare(key(b));
    if (c === 0) c = (Number(a.id) || 0) - (Number(b.id) || 0);
    return f.newest ? -c : c;
  });
  return out;
}
/**
 * نوار فیلتر را داخل boxId می‌سازد: پیکان‌های سال پسته‌ای قبل/بعد + برچسب بازه (با کلیک، پنل ترتیب و بازهٔ دلخواه).
 * f (حالت فیلتر) درجا تغییر می‌کند و بعد از هر تغییر onChange() صدا زده می‌شود.
 * خروجی: { setCount(shown, total) } برای نمایش «n مورد از m».
 */
function mountDocFilter(boxId, f, onChange) {
  const box = document.getElementById(boxId);
  if (!box) return { setCount() {} };
  const fromId = `${boxId}-from`, toId = `${boxId}-to`;
  box.classList.add('docf');
  box.innerHTML = `
    <div class="docf-bar">
      <button type="button" class="docf-nav" data-nav="-1" title="سال پسته‌ای قبل">‹</button>
      <button type="button" class="docf-label">📅 <span class="docf-label-t"></span></button>
      <button type="button" class="docf-nav" data-nav="1" title="سال پسته‌ای بعد">›</button>
    </div>
    <div class="docf-panel hidden">
      <div class="docf-lbl">ترتیب نمایش</div>
      <div class="seg-tabs" style="margin-bottom:6px">
        <button type="button" class="seg-tab" data-ord="new">جدیدترین اول</button>
        <button type="button" class="seg-tab" data-ord="old">قدیمی‌ترین اول</button>
      </div>
      <label class="docf-check"><input type="checkbox" class="docf-all"> همهٔ تاریخ‌ها (بدون محدودیت)</label>
      <div class="docf-range">
        <div class="docf-lbl">از تاریخ</div><div id="${fromId}"></div>
        <div class="docf-lbl">تا تاریخ</div><div id="${toId}"></div>
        <div class="docf-err hidden">«از تاریخ» نباید بعد از «تا تاریخ» باشد</div>
      </div>
      <button type="button" class="btn btn-secondary docf-cur" style="margin-top:10px">سال پسته‌ای جاری</button>
    </div>
    <div class="docf-count"></div>`;
  const q = (sel) => box.querySelector(sel);
  const dflt = defaultDocFilter();
  mountJalaliDate(fromId, f.from || dflt.from, () => commitRange());
  mountJalaliDate(toId, f.to || dflt.to, () => commitRange());

  function sync() {
    const y = docFilterYear(f);
    q('.docf-label-t').textContent = docFilterLabel(f);
    q('.docf-label').classList.toggle('custom', y === null);
    box.querySelectorAll('.docf-nav').forEach((b) => { b.disabled = y === null; });
    box.querySelectorAll('.seg-tab[data-ord]').forEach((b) => b.classList.toggle('active', (b.dataset.ord === 'new') === !!f.newest));
    const all = !f.from && !f.to;
    q('.docf-all').checked = all;
    q('.docf-range').classList.toggle('hidden', all);
    if (!all) {
      const dp = (id) => _datePickers[id];
      if (f.from && dp(fromId)) dp(fromId).set(f.from);
      if (f.to && dp(toId)) dp(toId).set(f.to);
    }
    q('.docf-err').classList.add('hidden');
  }
  function commitRange() {
    const from = dateVal(fromId), to = dateVal(toId);
    if (from && to && from > to) { q('.docf-err').classList.remove('hidden'); return; }
    q('.docf-err').classList.add('hidden');
    f.from = from; f.to = to;
    q('.docf-label-t').textContent = docFilterLabel(f);
    q('.docf-label').classList.toggle('custom', docFilterYear(f) === null);
    box.querySelectorAll('.docf-nav').forEach((b) => { b.disabled = docFilterYear(f) === null; });
    onChange();
  }
  function setYear(y) { const r = pistachioRange(y); f.from = r.from; f.to = r.to; sync(); onChange(); }

  box.querySelectorAll('.docf-nav').forEach((b) => {
    b.onclick = () => { const y = docFilterYear(f); if (y !== null) setYear(y + Number(b.dataset.nav)); };
  });
  q('.docf-label').onclick = () => q('.docf-panel').classList.toggle('hidden');
  box.querySelectorAll('.seg-tab[data-ord]').forEach((b) => {
    b.onclick = () => { f.newest = b.dataset.ord === 'new'; sync(); onChange(); };
  });
  q('.docf-all').onchange = (e) => {
    if (e.target.checked) { f.from = ''; f.to = ''; sync(); onChange(); }
    else { const d = defaultDocFilter(); f.from = d.from; f.to = d.to; sync(); onChange(); }
  };
  q('.docf-cur').onclick = () => { const d = defaultDocFilter(); f.from = d.from; f.to = d.to; sync(); onChange(); };
  sync();
  return { setCount(shown, total) { q('.docf-count').textContent = `${toFaDigits(shown)} مورد از ${toFaDigits(total)} • ${f.newest ? 'جدیدترین اول' : 'قدیمی‌ترین اول'}`; } };
}

// ---------------------------------------------------------------- فیلدهای مبلغ با جداکنندهٔ سه‌رقمی (مثل اپ اندروید)

function formatThousandsLive(value) {
  let digits = toEnDigitsStr(value).replace(/[^0-9.]/g, '');
  if (!digits) return '';
  const dotIdx = digits.indexOf('.');
  let intPart = dotIdx === -1 ? digits : digits.slice(0, dotIdx);
  let decPart = dotIdx === -1 ? '' : '.' + digits.slice(dotIdx + 1).replace(/\./g, '');
  intPart = intPart.replace(/^0+(?=\d)/, '');
  let out = '';
  for (let i = 0; i < intPart.length; i++) {
    if (i > 0 && (intPart.length - i) % 3 === 0) out += ',';
    out += intPart[i];
  }
  return out + decPart;
}
/** یک فیلد قیمت/مبلغ را به فرمت زندهٔ سه‌رقم‌جدا مجهز می‌کند (فیلد باید از قبل type=text باشد) */
function attachThousands(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.setAttribute('inputmode', 'decimal');
  el.value = formatThousandsLive(el.value);
  el.addEventListener('input', () => {
    const pos = el.selectionStart;
    const before = el.value.length;
    el.value = formatThousandsLive(el.value);
    const after = el.value.length;
    const np = Math.max(0, (pos || 0) + (after - before));
    try { el.setSelectionRange(np, np); } catch {}
  });
}
/** مقدار عددی خام یک فیلد سه‌رقم‌جدا (یا هر فیلد عددی معمولی) را برمی‌گرداند */
function numVal(id) {
  const el = document.getElementById(id);
  if (!el) return 0;
  return Number(toEnDigitsStr(el.value).replace(/,/g, '')) || 0;
}

async function api(action, payload = {}, opts = {}) {
  // آفلاین واقعی (مرورگر گزارش می‌دهد اینترنت نیست)
  if (isOffline()) return apiOffline(action, payload);

  const idem = IDEM_ACTIONS.has(action) ? newId() : undefined;
  const long = action === 'backup.import' || action === 'backup.export';
  let res;
  try {
    res = await fetchWithTimeout(API_BASE + 'app.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone: state.phone, token: state.token, action, payload, idem, unit: state.moneyUnit }),
    }, long ? 180000 : FETCH_TIMEOUT);
  } catch (err) {
    // خطای شبکه یا timeout (نه پاسخ سرور): حالت آفلاین. کلید یکتای همین درخواست به صف منتقل می‌شود؛
    // اگر سرور درخواست را ثبت کرده ولی پاسخ گم شده باشد، ارسال دوباره ثبت تکراری نمی‌سازد.
    if (err instanceof TypeError || (err && err.name === 'AbortError')) return apiOffline(action, payload, idem);
    throw err;
  }
  let data;
  try { data = await res.json(); } catch { data = {}; }
  if (!res.ok) {
    const permDenied = data.error === 'forbidden' || data.error === 'edition_required'; // دسترسی/نسخه؛ نه انقضای اشتراک
    const e = new Error(data.error === 'edition_required' ? (data.message || 'این بخش در نسخهٔ فعلی شما فعال نیست') : (data.error === 'forbidden' ? (data.message || 'دسترسی لازم را ندارید') : (data.error || (res.status === 413 ? 'حجم داده از حد مجاز سرور بیشتر است' : 'خطای ارتباط با سرور'))));
    e.status = res.status;
    if (res.status === 401) doLogout({ keepOutbox: true });
    else if (res.status === 403 && action !== 'account.status' && !permDenied) showBlocked();
    throw e;
  }
  if (window.PBRealtime) PBRealtime.noteOwn(action, payload); // S8: تغییر خودِ این تب در poll لحظه‌ای دوباره دریافت نمی‌شود
  if (READ_ACTIONS.has(action)) setCache(action, payload, data);
  if (!isOffline()) flushQueue(true); // فرصتی برای خالی‌کردن صف قدیمی اگر مانده باشد
  return data;
}

/** رفتار api() وقتی اینترنت در دسترس نیست */
function apiOffline(action, payload, idem) {
  if (READ_ACTIONS.has(action)) {
    const cached = getCache(action, payload);
    if (cached) return Promise.resolve(applyQueueToRead(action, payload, cached));
    return Promise.reject(new Error('اینترنت وصل نیست و دادهٔ ذخیره‌شدهٔ قبلی برای این صفحه موجود نیست'));
  }
  if (QUEUEABLE_ACTIONS.has(action)) {
    const q = readQueue();
    const entry = { qid: newId(), action, payload, ts: Date.now(), idem: idem || newId(), owner: state.phone, fails: 0, unit: state.moneyUnit };
    let fakeResult = { ok: true, queued: true };
    if (action === 'purchases.create' || action === 'mixes.create' || action === 'sales.create') {
      const tid = tempId();
      entry.tempId = tid;
      fakeResult = { id: tid, queued: true };
    }
    q.push(entry);
    if (!writeQueue(q)) {
      return Promise.reject(new Error('حافظهٔ مرورگر پر است و ذخیره در صف ممکن نشد. اینترنت را وصل کنید یا عکس رسید را حذف کنید'));
    }
    toast('ارتباط با سرور برقرار نیست؛ در صف ذخیره شد و با وصل‌شدن اینترنت خودکار ارسال می‌شود');
    return Promise.resolve(fakeResult);
  }
  return Promise.reject(new Error('این عملیات به اینترنت نیاز دارد (مثلاً افزودن دستهٔ جدید)'));
}

/** لیست‌های کش‌شده را با موارد در صف (هنوز ارسال‌نشده) ترکیب می‌کند تا صفحه هنگام آفلاین‌بودن به‌روزتر دیده شود */
function applyQueueToRead(action, payload, data) {
  const q = readQueue();
  if (!q.length) return data;
  if (action === 'purchases.list') {
    const out = JSON.parse(JSON.stringify(data));
    for (const it of q) {
      if (it.action === 'purchases.create' && it.tempId != null) {
        const p = it.payload;
        const tare = (Number(p.small_sack_count) || 0) * 0.1 + (Number(p.large_sack_count) || 0) * 0.2;
        const net = (Number(p.total_weight) || 0) - tare;
        out.items.unshift({
          id: it.tempId, code: '⏳ در صف', category_id: p.category_id, type_name: p.type_name || '',
          category_name: (state.categories.find(c => c.id === p.category_id) || {}).name || '',
          date: p.date, price: Number(p.price) || 0, net_weight: net, remaining: net,
          usd_rate: Number(p.usd_rate) > 0 ? Number(p.usd_rate) : null,
          price_usd: Number(p.usd_rate) > 0 ? (Number(p.price) || 0) / Number(p.usd_rate) : null,
          total_weight: p.total_weight, small_sack_count: p.small_sack_count, large_sack_count: p.large_sack_count,
          is_mix: 0, seller: p.seller || '', note: p.note || '', _pending: true,
        });
      } else if (it.action === 'purchases.delete') {
        out.items = out.items.filter(x => x.id !== it.payload.id);
      }
    }
    return out;
  }
  if (action === 'sales.list') {
    const out = JSON.parse(JSON.stringify(data));
    for (const it of q) {
      if (it.action === 'sales.create' && it.tempId != null) {
        const p = it.payload;
        // فرمت جدید: purchase_id/weight در خود payload است؛ فرمت قدیمی: items
        const qItems = p.items || (p.purchase_id ? [{ purchase_id: p.purchase_id, weight: p.weight, item_price: p.price, small_sacks: p.small_sacks, large_sacks: p.large_sacks }] : []);
        const qNet = p.weight != null ? Number(p.weight) : Number(p.total_weight) || 0;
        const qLot = _purchasesCache.find(x => x.id === (qItems[0] && qItems[0].purchase_id));
        out.items.unshift({
          id: it.tempId, date: p.date, price: Number(p.price) || 0, net_weight: qNet, total_weight: qNet,
          usd_rate: Number(p.usd_rate) > 0 ? Number(p.usd_rate) : null,
          small_sack_count: Number(p.small_sacks) || 0, large_sack_count: Number(p.large_sacks) || 0,
          buyer: p.buyer || '', note: p.note || '',
          items: qItems.map(x => ({ ...x, type_name: qLot ? qLot.type_name : '', code: qLot ? qLot.code : '', cost_price_usd: qLot && qLot.price_usd != null ? qLot.price_usd : null })), _pending: true,
        });
      } else if (it.action === 'sales.delete') {
        out.items = out.items.filter(x => x.id !== it.payload.id);
      }
    }
    return out;
  }
  if (action === 'parties.list') {
    const out = JSON.parse(JSON.stringify(data));
    out.items.forEach(partyDefaults);
    return applyLoanQueue(out, q);
  }
  return data;
}

// ---------------------------------------------------------------- ورود/خروج

// ورود فقط با شماره موبایل + کد پیامکی (دو مرحله: ۱) دریافت کد ۲) تأیید کد)
const loginUI = {
  step: 'phone', phone: '', code: '', timer: null, staff: false,
  el: (id) => document.getElementById(id),
};
const LOGIN_BTN_LABEL = { phone: 'دریافت کد ورود', pass: 'ورود', code: 'ورود', name: 'ادامه', newpass: 'ثبت‌نام و ورود' };
function loginSetStep(step) {
  loginUI.step = step;
  const isCode = step === 'code', isName = step === 'name', isPass = step === 'pass', isNewPass = step === 'newpass';
  loginUI.el('login-step-phone').classList.toggle('hidden', isCode || isName || isNewPass);
  loginUI.el('login-step-newpass').classList.toggle('hidden', !isNewPass);
  loginUI.el('login-step-pass').classList.toggle('hidden', !isPass);
  loginUI.el('login-step-code').classList.toggle('hidden', !isCode);
  loginUI.el('login-step-name').classList.toggle('hidden', !isName);
  // لینک جابه‌جایی «پیامک ⇄ رمز عبور» فقط در دو مرحلهٔ اول دیده می‌شود
  const modeBtn = loginUI.el('login-mode');
  modeBtn.parentElement.classList.toggle('hidden', !(step === 'phone' || isPass));
  modeBtn.textContent = isPass ? 'ورود با کد پیامکی' : 'ورود با رمز عبور';
  loginUI.el('login-sub').textContent = isPass ? (loginUI.staff ? 'ورود پرسنل: شمارهٔ مالک، نام کاربری و رمز' : 'ورود با شماره موبایل و رمز عبور') : 'ورود با شماره موبایل و کد پیامکی';
  if (!isPass) loginUI.staff = false; // S21.T21.1
  loginUI.el('login-staff-wrap').classList.toggle('hidden', !(isPass && loginUI.staff));
  loginUI.el('login-phone-label').textContent = (isPass && loginUI.staff) ? 'شمارهٔ موبایل مالک (صاحب حساب)' : 'شماره موبایل';
  const staffBtn = loginUI.el('login-staff-mode');
  staffBtn.classList.toggle('hidden', !(step === 'phone' || isPass)); // دکمهٔ بزرگ «ورود پرسنل» از همان صفحهٔ اول دیده می‌شود
  staffBtn.textContent = loginUI.staff ? '↩️ بازگشت به ورود مالک' : '👤 ورود پرسنل';
  modeBtn.classList.toggle('hidden', isPass && loginUI.staff);
  loginUI.el('login-btn').textContent = LOGIN_BTN_LABEL[step];
  loginUI.el('login-error').textContent = '';
  if (isPass) { clearInterval(loginUI.timer); loginUI.el('login-pass').value = ''; }
  if (step === 'phone' || isPass) { loginUI.el('login-newpass').value = ''; loginUI.el('login-newpass2').value = ''; }
  if (isNewPass) { clearInterval(loginUI.timer); setTimeout(() => loginUI.el('login-newpass').focus(), 50); }
  else if (isName) { clearInterval(loginUI.timer); setTimeout(() => loginUI.el('login-name').focus(), 50); }
  else if (isCode) { loginUI.el('login-code').value = ''; setTimeout(() => loginUI.el('login-code').focus(), 50); }
  else { clearInterval(loginUI.timer); }
}
function loginStartCooldown(sec) {
  const btn = loginUI.el('login-resend');
  clearInterval(loginUI.timer);
  let left = Math.max(0, Math.floor(sec));
  const tick = () => {
    if (left <= 0) { btn.disabled = false; btn.textContent = 'ارسال دوباره کد'; clearInterval(loginUI.timer); return; }
    btn.disabled = true; btn.textContent = `ارسال دوباره (${left.toLocaleString('fa-IR')} ثانیه)`;
    left--;
  };
  tick();
  loginUI.timer = setInterval(tick, 1000);
}
async function loginPost(path, body) {
  let res;
  try {
    res = await fetchWithTimeout(API_BASE + path, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    }, 20000);
  } catch (err) {
    throw new Error('ارتباط با سرور برقرار نشد. اینترنت خود را بررسی کنید.');
  }
  let data;
  try { data = await res.json(); } catch { data = {}; }
  if (!res.ok) { const e = new Error(data.error || 'خطای ارتباط با سرور'); e.data = data; throw e; }
  return data;
}
async function loginSendCode() {
  const phone = loginUI.el('login-phone').value.trim();
  if (!phone) throw new Error('شماره موبایل را وارد کنید');
  try {
    const data = await loginPost('otp_send.php', { phone });
    loginUI.phone = phone;
    loginUI.el('login-phone-show').textContent = toFaDigits(toEnDigitsStr(phone));
    loginSetStep('code');
    loginStartCooldown(data.resend_in || 60);
  } catch (err) {
    // کد قبلاً فرستاده شده و هنوز مهلت دوباره‌گرفتن تمام نشده: کاربر را به مرحلهٔ وارد کردن کد ببریم
    if (err.data && err.data.resend_in) {
      loginUI.phone = phone;
      loginUI.el('login-phone-show').textContent = toFaDigits(toEnDigitsStr(phone));
      loginSetStep('code');
      loginStartCooldown(err.data.resend_in);
      loginUI.el('login-error').textContent = err.message;
      return;
    }
    throw err;
  }
}
async function loginVerifyCode() {
  // مرحلهٔ نام (کاربر جدید): فقط نام را می‌گیریم و به مرحلهٔ تعیین رمز می‌رویم
  if (loginUI.step === 'name') {
    if (loginUI.el('login-name').value.trim().length < 2) throw new Error('نام خود را وارد کنید');
    loginSetStep('newpass');
    return;
  }
  let code, name, password;
  if (loginUI.step === 'newpass') {
    code = loginUI.code;
    name = loginUI.el('login-name').value.trim();
    password = loginUI.el('login-newpass').value;
    if (password.length < 6) throw new Error('رمز عبور باید حداقل ۶ کاراکتر باشد');
    if (password !== loginUI.el('login-newpass2').value) throw new Error('تکرار رمز با رمز عبور یکسان نیست');
  } else {
    code = loginUI.el('login-code').value.trim();
    if (!code) throw new Error('کد پیامکی را وارد کنید');
  }
  const body = { phone: loginUI.phone, code };
  if (name) body.name = name;
  if (password) body.password = password; // فقط برای ثبت‌نام کاربر جدید
  const data = await loginPost('otp_verify.php', body);
  if (data.need_name) {
    // کاربر جدید: کد درست است ولی هنوز مصرف نشده؛ نام و بعد رمز را می‌پرسیم
    loginUI.code = code;
    loginSetStep('name');
    return;
  }
  if (data.need_password) {
    loginUI.code = code;
    loginSetStep('newpass');
    return;
  }
  loginUI.code = '';
  await loginFinish(data);
}
async function loginPasswordSignIn() {
  const phone = loginUI.el('login-phone').value.trim();
  const password = loginUI.el('login-pass').value;
  if (!phone) throw new Error('شماره موبایل را وارد کنید');
  if (!password) throw new Error('رمز عبور را وارد کنید');
  if (loginUI.staff) { // S21.T21.1: ورود کارکن (شماره مالک + نام کاربری + رمز)
    const username = loginUI.el('login-staff-user').value.trim();
    if (!username) throw new Error('نام کاربری کارکن را وارد کنید');
    const sdata = await loginPost('staff_login.php', { phone, username, password });
    await loginFinish(sdata);
    return;
  }
  const data = await loginPost('login.php', { phone, password });
  await loginFinish(data);
}
async function loginFinish(data) {
  // اگر کاربر قبلی روی این دستگاه فرق دارد، کش و صف او کاملاً پاک می‌شود
  const lastPhone = localStorage.getItem('pb_last_phone');
  if (lastPhone && lastPhone !== data.phone) {
    clearCaches();
    [QUEUE_KEY, FAILED_KEY, IDMAP_KEY].forEach((k) => localStorage.removeItem(k));
  }
  state.phone = data.phone; state.token = data.token; state.blocked = false;
  localStorage.setItem('pb_last_phone', state.phone);
  localStorage.setItem('pb_phone', state.phone);
  localStorage.setItem('pb_token', state.token);
  clearInterval(loginUI.timer);
  loginSetStep('phone');
  loginUI.el('login-phone').value = '';
  loginUI.el('login-name').value = '';
  loginUI.el('login-pass').value = '';
  await boot();
  if (data.is_new) toast(`خوش آمدید! ${(data.days_left || 10).toLocaleString('fa-IR')} روز اشتراک آزمایشی رایگان برای شما فعال شد`);
}

document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const errEl = loginUI.el('login-error');
  const btn = loginUI.el('login-btn');
  errEl.textContent = '';
  btn.disabled = true;
  btn.textContent = loginUI.step === 'phone' ? 'در حال ارسال کد...' : 'در حال ورود...';
  try {
    if (loginUI.step === 'phone') await loginSendCode();
    else if (loginUI.step === 'pass') await loginPasswordSignIn();
    else await loginVerifyCode();
  } catch (err) {
    errEl.textContent = err.message;
  } finally {
    btn.disabled = false;
    btn.textContent = LOGIN_BTN_LABEL[loginUI.step];
  }
});
loginUI.el('login-resend').addEventListener('click', async () => {
  const errEl = loginUI.el('login-error');
  errEl.textContent = '';
  loginUI.el('login-resend').disabled = true;
  try {
    const data = await loginPost('otp_send.php', { phone: loginUI.phone });
    toast('کد جدید ارسال شد');
    loginStartCooldown(data.resend_in || 60);
  } catch (err) {
    errEl.textContent = err.message;
    loginStartCooldown((err.data && err.data.resend_in) || 5);
  }
});
loginUI.el('login-mode').addEventListener('click', () => loginSetStep(loginUI.step === 'pass' ? 'phone' : 'pass'));
loginUI.el('login-staff-mode').addEventListener('click', () => { loginUI.staff = !loginUI.staff; loginSetStep(loginUI.staff ? 'pass' : 'phone'); }); // S21.T21.1
loginUI.el('login-name-back').addEventListener('click', () => { loginUI.code = ''; loginSetStep('phone'); });
loginUI.el('login-newpass-back').addEventListener('click', () => loginSetStep('name'));
loginUI.el('login-change').addEventListener('click', () => { loginUI.code = ''; loginSetStep('phone'); });

document.getElementById('sync-badge').addEventListener('click', () => openQueueModal());
function updateOfflineBadge() {
  const el = document.getElementById('offline-badge');
  if (!el) return;
  el.classList.toggle('hidden', !isOffline());
}
window.addEventListener('online', updateOfflineBadge);
window.addEventListener('offline', updateOfflineBadge);
updateOfflineBadge();
updateSyncBadge();

/**
 * خروج: نشست، کش و (به‌جز در انقضای نشست) صف ارسال پاک می‌شود تا کاربر بعدی روی همین دستگاه
 * داده‌ای از کاربر قبلی نبیند و صف او با توکن کاربر جدید ارسال نشود.
 * keepOutbox: فقط برای انقضای خودکار نشست (۴۰۱)؛ موارد ارسال‌نشده با برچسب مالک حفظ می‌شوند و فقط
 * اگر همان کاربر دوباره وارد شود ارسال می‌شوند.
 */
function doLogout(opts) {
  const keep = !!(opts && opts.keepOutbox);
  localStorage.removeItem('pb_phone'); localStorage.removeItem('pb_token');
  clearCaches();
  if (!keep) [QUEUE_KEY, FAILED_KEY, IDMAP_KEY, 'pb_last_phone'].forEach((k) => localStorage.removeItem(k));
  state.phone = ''; state.token = ''; state.blocked = false;
  state.categories = []; state.types = []; state.customer = null; state.partyNames = null;
  if (window.PBRealtime) PBRealtime.stop(); // S8
  document.body.classList.remove('room-only', 'wk-only'); { const rt = document.getElementById('room-tabbar'); if (rt) rt.remove(); const wt = document.getElementById('wk-tabbar'); if (wt) wt.remove(); }
  document.getElementById('modal-root').innerHTML = '';
  removeRenewBanner();
  document.querySelectorAll('.fab, .photo-view-overlay').forEach((f) => f.remove());
  document.getElementById('content').innerHTML = '';
  updateSyncBadge();
  document.getElementById('app-view').classList.add('hidden');
  document.getElementById('login-view').classList.remove('hidden');
}
document.getElementById('logout-btn').addEventListener('click', () => {
  const pending = readQueue().length + readFailed().length;
  const msg = pending
    ? `${pending.toLocaleString('fa-IR')} مورد هنوز به سرور ارسال نشده و با خروج برای همیشه پاک می‌شود. با این حال خارج شوید؟`
    : 'از حساب خارج شوید؟';
  if (confirm(msg)) doLogout();
});
document.getElementById('settings-btn').addEventListener('click', openSettingsModal);

/** کاربر منقضی/غیرفعال: صفحهٔ اصلی باز نمی‌شود ولی تنظیمات (تماس و واتس‌اپ برای تمدید) در دسترس است */
function showBlocked() {
  state.blocked = true;
  removeRenewBanner();
  document.getElementById('login-view').classList.add('hidden');
  document.getElementById('app-view').classList.remove('hidden');
  document.querySelectorAll('.fab').forEach((f) => f.remove());
  document.getElementById('page-title').textContent = 'اشتراک';
  const c = document.getElementById('content');
  c.innerHTML = `<div class="empty-state" style="padding:40px 16px">
    <div style="font-size:44px">🔒</div>
    <p><b>اشتراک شما فعال نیست یا منقضی شده است.</b></p>
    <p class="muted">برای تمدید با پشتیبانی تماس بگیرید. اطلاعات شما حفظ شده و بعد از تمدید دوباره در دسترس است.</p>
    <button class="btn" id="blocked-renew" style="max-width:280px;margin:14px auto 0">💳 تمدید اشتراک</button>
    <button class="btn btn-secondary" id="blocked-open" style="max-width:280px;margin:8px auto 0">📞 تماس با پشتیبانی / وضعیت اشتراک</button>
  </div>`;
  document.getElementById('blocked-renew').onclick = openRenewal;
  document.getElementById('blocked-open').onclick = openSettingsModal;
}

// ---------------------------------------------------------------- تنظیمات / اشتراک / پشتیبان‌گیری

async function openSettingsModal() {
  const html = `
    <h2>تنظیمات و اشتراک</h2>
    <div class="section-title">وضعیت اشتراک</div>
    <div class="card" id="st-account"><div class="muted">در حال بارگذاری...</div></div>
    <div class="card">
      <p class="muted" style="font-size:12.5px;margin:0 0 10px;text-align:center">برای تمدید یا تغییر اشتراک با پشتیبانی تماس بگیرید:</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-secondary" id="st-call" href="tel:+989134970483" style="flex:1;text-align:center;text-decoration:none;margin-top:0">📞 تماس: ۰۹۱۳۴۹۷۰۴۸۳</a>
        <a class="btn btn-secondary" id="st-wa" target="_blank" rel="noopener" style="flex:1;text-align:center;text-decoration:none;margin-top:0">💬 واتس‌اپ</a>
      </div>
      <button class="btn btn-secondary" id="st-refresh">🔄 بررسی مجدد وضعیت</button>
      <button class="btn" id="st-renew" style="margin-top:8px">💳 تمدید / خرید اشتراک</button>
    </div>
    <div class="section-title">نام و رمز عبور</div>
    <div class="card">
      <div class="field"><label>نام</label>
        <input type="text" id="st-name" maxlength="60" placeholder="نام و نام خانوادگی">
      </div>
      <button class="btn btn-secondary" id="st-name-save" style="margin-top:0">ذخیرهٔ نام</button>
      <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">
      <p class="muted" style="font-size:12.5px;margin:0 0 10px" id="st-pw-help">با تعیین رمز عبور، وقتی پیامک نمی‌رسد هم می‌توانید با «ورود با رمز عبور» وارد شوید.</p>
      <div class="field hidden" id="st-pw-cur-wrap"><label>رمز فعلی</label>
        <input type="password" id="st-pw-cur" autocomplete="current-password" maxlength="200">
      </div>
      <div class="field"><label>رمز جدید (حداقل ۶ کاراکتر)</label>
        <input type="password" id="st-pw-new" autocomplete="new-password" maxlength="72">
      </div>
      <div class="field"><label>تکرار رمز جدید</label>
        <input type="password" id="st-pw-new2" autocomplete="new-password" maxlength="72">
      </div>
      <div id="st-pw-err" class="error"></div>
      <button class="btn btn-secondary" id="st-pw-save" style="margin-top:0">🔑 ذخیرهٔ رمز عبور</button>
      <button type="button" class="link-btn hidden" id="st-pw-forgot" style="margin-top:8px">رمز عبور را فراموش کرده‌اید؟</button>
      <p class="muted" style="font-size:12px;margin:8px 0 0">با هر تغییر رمز، دستگاه‌های دیگرِ این حساب خارج می‌شوند و باید دوباره وارد شوند.</p>
    </div>
    <div class="section-title">پشتیبان‌گیری</div>
    <div class="card">
      <p class="muted" style="font-size:12.5px;margin:0 0 10px">یک فایل کامل از همهٔ خرید/فروش/پرداخت/انبار شما دانلود می‌شود تا جای امنی نگه دارید.</p>
      <button class="btn btn-secondary" id="st-export" style="margin-top:0">⬇️ دانلود فایل پشتیبان (JSON)</button>
    </div>
    <div class="section-title">بازگردانی از فایل پشتیبان</div>
    <div class="card">
      <p class="muted" style="font-size:12.5px;margin:0 0 10px">⚠️ بازگردانی، همهٔ اطلاعات فعلی حساب شما را با محتوای فایل جایگزین می‌کند و برگشت‌ناپذیر است. قبل از بازگردانی یک پشتیبان تازه از وضعیت فعلی بگیرید.</p>
      <input type="file" id="st-restore-file" accept="application/json">
      <button class="btn btn-danger" id="st-restore" style="margin-top:8px">بازگردانی از این فایل</button>
    </div>
    <button class="btn btn-secondary" id="st-close">بستن</button>
    <div class="credit">قدرت‌گرفته از تیم نرم‌افزاری <b>فاضل جنگجو</b></div>
  `;
  const close = openModal(html);
  document.getElementById('st-close').onclick = close;

  let pwHas = false;
  function loadStatus() {
  api('account.status').then((data) => {
    const a = data.item;
    if (state.blocked && a.active && a.days_left >= 0) { close(); state.blocked = false; boot(); return; }
    const msg = encodeURIComponent('سلام، برای فعال‌سازی/تمدید اشتراک پسته بازار تماس می‌گیرم. شماره من: ' + a.phone);
    document.getElementById('st-wa').href = 'https://wa.me/989134970483?text=' + msg;
    // فرم نام/رمز: نام فعلی، و نمایش «رمز فعلی» فقط اگر کاربر قبلاً رمز گذاشته باشد
    const nameInput = document.getElementById('st-name');
    if (nameInput && document.activeElement !== nameInput) nameInput.value = a.name || '';
    pwHas = !!a.has_password;
    document.getElementById('st-pw-cur-wrap').classList.toggle('hidden', !pwHas);
    document.getElementById('st-pw-forgot').classList.toggle('hidden', !pwHas);
    document.getElementById('st-pw-save').textContent = pwHas ? '🔑 تغییر رمز عبور' : '🔑 تعیین رمز عبور';
    const box = document.getElementById('st-account');
    box.innerHTML = `
      ${a.name ? `<div class="card-row"><span>نام</span><span>${esc(a.name)}</span></div>` : ''}
      <div class="card-row"><span>شماره موبایل</span><span>${toFaDigits(a.phone)}</span></div>
      <div class="card-row"><span>وضعیت</span><span class="${a.active ? 'pos' : 'neg'}">${a.active ? 'فعال' : 'غیرفعال'}</span></div>
      <div class="card-row"><span>تاریخ انقضا</span><span>${isoToJalaliDisplay(a.expiry_date)}</span></div>
      <div class="card-row"><span>روزهای باقی‌مانده</span><span class="${a.days_left < 7 ? 'neg' : ''}">${cnt(a.days_left)} روز</span></div>
    `;
  }).catch((err) => { document.getElementById('st-account').innerHTML = `<div class="muted">${esc(err.message)}</div>`; });
  }
  loadStatus();

  document.getElementById('st-name-save').onclick = async () => {
    const btn = document.getElementById('st-name-save');
    const name = document.getElementById('st-name').value.trim();
    if (name.length < 2) { toast('نام را وارد کنید (حداقل ۲ حرف)'); return; }
    btn.disabled = true;
    try { await api('account.set_name', { name }); toast('نام ذخیره شد'); loadStatus(); }
    catch (err) { toast(err.message); }
    finally { btn.disabled = false; }
  };
  document.getElementById('st-pw-save').onclick = async () => {
    const btn = document.getElementById('st-pw-save');
    const errEl = document.getElementById('st-pw-err');
    const cur = document.getElementById('st-pw-cur').value;
    const n1 = document.getElementById('st-pw-new').value;
    const n2 = document.getElementById('st-pw-new2').value;
    errEl.textContent = '';
    if (pwHas && !cur) { errEl.textContent = 'رمز فعلی را وارد کنید'; return; }
    if (n1.length < 6) { errEl.textContent = 'رمز جدید باید حداقل ۶ کاراکتر باشد'; return; }
    if (n1 !== n2) { errEl.textContent = 'تکرار رمز با رمز جدید یکسان نیست'; return; }
    btn.disabled = true;
    try {
      const res = await api('account.set_password', { current_password: cur, new_password: n1 });
      // توکن عوض شده؛ همین دستگاه با توکن جدید وارد می‌ماند و بقیهٔ دستگاه‌ها خارج می‌شوند
      if (res && res.new_token) { state.token = res.new_token; localStorage.setItem('pb_token', res.new_token); }
      ['st-pw-cur', 'st-pw-new', 'st-pw-new2'].forEach((id) => { document.getElementById(id).value = ''; });
      toast('رمز عبور ذخیره شد؛ دستگاه‌های دیگر خارج شدند');
      loadStatus();
    } catch (err) { errEl.textContent = err.message; }
    finally { btn.disabled = false; }
  };
  document.getElementById('st-pw-forgot').onclick = () => openForgotPassword(loadStatus);
  document.getElementById('st-wa').href = 'https://wa.me/989134970483';
  document.getElementById('st-refresh').onclick = () => { document.getElementById('st-account').innerHTML = '<div class="muted">در حال بارگذاری...</div>'; loadStatus(); };
  document.getElementById('st-renew').onclick = () => { document.querySelectorAll('.modal-overlay').forEach((o) => o.remove()); openRenewal(); };

  async function downloadBackup(prefix) {
    const data = await api('backup.export');
    const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = `${prefix}-${todayStr()}.json`;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
  }
  document.getElementById('st-export').onclick = async () => {
    const btn = document.getElementById('st-export');
    btn.disabled = true; btn.textContent = 'در حال آماده‌سازی...';
    try { await downloadBackup('pesteh-bazar-backup'); toast('فایل پشتیبان دانلود شد'); }
    catch (err) { toast(err.message); }
    finally { btn.disabled = false; btn.textContent = '⬇️ دانلود فایل پشتیبان (JSON)'; }
  };

  const BACKUP_TABLE_NAMES = ['categories', 'types', 'purchases', 'mix_sources', 'payments', 'lump_tx', 'credit_inst', 'cheque_inst',
    'sales', 'sale_items', 'receipts', 'receipt_lump_tx', 'receipt_credit_inst', 'receipt_cheque_inst'];
  // جدول‌های اختیاری: طرف‌حساب‌های دستی و دفتر قرض/وام (در پشتیبان‌های قدیمی نیستند)
  const BACKUP_OPTIONAL_TABLES = ['parties', 'loan_entries', 'loan_dues'];
  /** ساختار فایل را قبل از هر کاری بررسی می‌کند؛ متن خطا یا null */
  function checkBackupFile(p) {
    if (!p || typeof p !== 'object' || !p.tables || typeof p.tables !== 'object' || Array.isArray(p.tables)) return 'این فایل، پشتیبان پسته بازار نیست (کلید tables پیدا نشد)';
    for (const t of BACKUP_TABLE_NAMES) if (!Array.isArray(p.tables[t])) return `فایل ناقص است: جدول «${t}» وجود ندارد`;
    for (const k of Object.keys(p.tables)) if (!BACKUP_TABLE_NAMES.includes(k) && !BACKUP_OPTIONAL_TABLES.includes(k)) return 'فایل معتبر نیست: جدول ناشناخته';
    for (const k of BACKUP_OPTIONAL_TABLES) if (k in p.tables && !Array.isArray(p.tables[k])) return `فایل معتبر نیست: جدول «${k}»`;
    return null;
  }

  document.getElementById('st-restore').onclick = () => {
    const fileInput = document.getElementById('st-restore-file');
    const file = fileInput.files && fileInput.files[0];
    if (!file) { toast('ابتدا یک فایل پشتیبان انتخاب کنید'); return; }
    if (readQueue().length) { toast('ابتدا صف ارسال را خالی کنید (موارد در انتظار ارسال هنوز روی سرور ثبت نشده‌اند)'); return; }
    const reader = new FileReader();
    reader.onload = async () => {
      let parsed;
      try { parsed = JSON.parse(String(reader.result)); }
      catch { toast('فایل انتخابی JSON معتبر نیست'); return; }
      const bad = checkBackupFile(parsed);
      if (bad) { toast(bad); return; }
      const t = parsed.tables;
      const summary = `${t.purchases.length.toLocaleString('fa-IR')} خرید، ${t.sales.length.toLocaleString('fa-IR')} فروش`;
      if (!confirm(`⚠️ این فایل شامل ${summary} است.\nبا ادامه، همهٔ اطلاعات فعلی حساب شما پاک و با محتوای این فایل جایگزین می‌شود و برگشت‌ناپذیر است.\nقبل از شروع، یک پشتیبان از وضعیت فعلی دانلود می‌شود. ادامه می‌دهید؟`)) return;
      const btn = document.getElementById('st-restore');
      btn.disabled = true;
      try {
        try { await downloadBackup('pesteh-bazar-before-restore'); }
        catch (e) { if (!confirm('گرفتن پشتیبان از وضعیت فعلی ناموفق بود. بدون آن ادامه می‌دهید؟')) { btn.disabled = false; return; } }
        await api('backup.import', { tables: t });
        clearCaches(); state.partyNames = null;
        toast('بازگردانی با موفقیت انجام شد');
        close(); await boot();
      } catch (err) { toast(err.message); }
      finally { btn.disabled = false; }
    };
    reader.readAsText(file);
  };
}


// ---------------------------------------------------------------- یادآوری تمدید اشتراک (۳ روز مانده به پایان)
const SUPPORT_PHONE_DISPLAY = '۰۹۱۳۴۹۷۰۴۸۳';
const SUPPORT_PHONE_TEL = '+989134970483';
function daysUntil(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
  if (!m) return null;
  const t = /^(\d{4})-(\d{2})-(\d{2})$/.exec(todayStr());
  const a = Date.UTC(+m[1], +m[2] - 1, +m[3]);
  const b = Date.UTC(+t[1], +t[2] - 1, +t[3]);
  return Math.round((a - b) / 86400000);
}
function removeRenewBanner() {
  const old = document.getElementById('renew-banner');
  if (old) old.remove();
}
function renderRenewBanner() {
  removeRenewBanner();
  if (state.blocked || !state.customer) return;
  const left = daysUntil(state.customer.expiry_date);
  if (left == null || left < 0 || left > 3) return;
  if (localStorage.getItem('pb_renew_dismiss') === todayStr()) return; // امروز بسته شده؛ فردا دوباره
  const when = left === 0 ? 'امروز پایان می‌یابد' : (left === 1 ? 'فردا پایان می‌یابد' : `${left.toLocaleString('fa-IR')} روز دیگر پایان می‌یابد`);
  const div = document.createElement('div');
  div.id = 'renew-banner';
  div.className = 'renew-banner' + (left <= 1 ? ' urgent' : '');
  div.innerHTML = `<span class="rb-text">⏳ اشتراک شما ${when}. برای تمدید اشتراک با پشتیبانی تماس بگیرید.</span>
    <a class="rb-call" href="tel:${SUPPORT_PHONE_TEL}">📞 تماس: ${SUPPORT_PHONE_DISPLAY}</a>
    <button type="button" class="rb-close" aria-label="بستن">✕</button>`;
  div.querySelector('.rb-close').onclick = () => {
    try { localStorage.setItem('pb_renew_dismiss', todayStr()); } catch {}
    div.remove();
  };
  const main = document.getElementById('content');
  main.parentNode.insertBefore(div, main);
}

async function boot() {
  try {
    const data = await api('bootstrap');
    state.categories = data.categories; state.types = data.types; state.customer = data.customer;
    state.moneyUnit = data.money_unit === 'rial' ? 'rial' : 'toman'; // S29.T29.4
    if (window.PBFmt) PBFmt.configure({ storedUnit: state.moneyUnit, displayUnit: data.display_unit || state.moneyUnit, digits: data.digits || 'fa' }); // S35.T35.12
    state.isFx = !!(data.customer && data.customer.plan === 'fx');
    if (window.PBShell) PBShell.apply(data); // S21.T21.1: edition + staff perms → تب‌ها و منو
    state.syncVer = data.sync ? data.sync.ver : null; state.syncPoll = data.sync ? data.sync.poll_interval : null; // S8: مبنای poll لحظه‌ای
    if (window.PBIntake) PBIntake.applyRole(); // S8: منوی محدود نقش «اتاق انس»
    if (window.PBCartex) PBCartex.applyRole(); // نقش «کارتکس»: فقط کارتکس لات (بدون تب/منو)
    if (window.PBCrack) PBCrack.applyRole(); // کارگاه ۳-ب: منوی محدود نقش «اپراتور کارگاه» (بدون قیمت)
    { const ab = document.getElementById('assistant-btn'); if (ab) ab.classList.toggle('hidden', !state.isFx || !!data.staff); } // دستیار فقط پلن ارزی (بدون دکمه در index.html هم اپ نمی‌شکند)
    state.blocked = false;
  } catch (err) {
    if (err && err.status === 403) { showBlocked(); return; }
    if (err && err.status === 401) return; // doLogout قبلاً صفحهٔ ورود را نشان داده
    toast(err.message);
    return;
  }
  if (!(await pbContractGate())) return; // قرارداد فعلی باید پذیرفته شود
  document.getElementById('login-view').classList.add('hidden');
  document.getElementById('app-view').classList.remove('hidden');
  flushQueue(true);
  renderRenewBanner();
  setTab('home');
  startRealtime();
}

/** S8: همگام‌سازی لحظه‌ای (poll سبک + visibilitychange)؛ منطق در sync_rt.js */
function startRealtime() {
  if (!window.PBRealtime) return;
  PBRealtime.start({
    apiBase: API_BASE,
    getAuth: () => ({ phone: state.phone, token: state.token }),
    getUnit: () => state.moneyUnit,
    queueLength: () => readQueue().length,
    flush: () => flushQueue(true),
    onUnauthorized: () => doLogout({ keepOutbox: true }),
    ver: state.syncVer, pollSec: state.syncPoll,
  });
}

// ---------------------------------------------------------------- دستیار (فقط پلن ارزی)
// منطق کامل در assistant/assistant.js است و یک‌بار، هنگام اولین باز شدن، بارگذاری می‌شود.
let assistantLoading = null;
function loadAssistantScripts() {
  return fetch('assistant/version.json', { cache: 'no-cache' })
    .then((r) => (r.ok ? r.json() : {}))
    .catch(() => ({}))
    .then((v) => new Promise((resolve, reject) => {
      const ver = encodeURIComponent((v && v.v) || '1');
      const css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = 'assistant/assistant.css?v=' + ver;
      document.head.appendChild(css);
      const sc = document.createElement('script');
      sc.src = 'assistant/assistant.js?v=' + ver;
      sc.onload = () => resolve();
      sc.onerror = () => reject(new Error('assistant'));
      document.head.appendChild(sc);
    }));
}
async function openAssistant() {
  if (!state.isFx) return;
  try {
    if (!window.PBAssistant) {
      if (!assistantLoading) assistantLoading = loadAssistantScripts();
      await assistantLoading;
    }
    window.PBAssistant.open({
      getAuth: () => ({ phone: state.phone, token: state.token }),
      onAuthError: () => doLogout({ keepOutbox: true }),
    });
  } catch (e) {
    assistantLoading = null;
    toast('بارگذاری دستیار انجام نشد؛ اتصال اینترنت را بررسی کنید');
  }
}
{ const ab = document.getElementById('assistant-btn'); if (ab) ab.addEventListener('click', openAssistant); }

// ---------------------------------------------------------------- ناوبری

document.querySelectorAll('.tab').forEach(b => b.addEventListener('click', () => setTab(b.dataset.tab)));
const TITLES = { home: 'خانه', inventory: 'انبار', sales: 'فروش', parties: 'طرف‌حساب‌ها', dues: 'سررسیدها' };

function setTab(tab) {
  if (state.blocked) { showBlocked(); return; }
  if (window.PBIntake && PBIntake.roomOnly()) { PBIntake.roomTab(tab); return; } // S8: نقش «اتاق انس» فقط پذیرش + فهرست
  if (window.PBCartex && PBCartex.roleOnly()) { PBCartex.roleTab(); return; } // نقش «کارتکس»: فقط صفحهٔ کارتکس لات
  if (window.PBCrack && PBCrack.roleOnly()) { PBCrack.roleTab(tab); return; } // کارگاه ۳-ب: نقش «اپراتور کارگاه» فقط خط شکست + عملیات داخلی
  state.tab = tab;
  document.getElementById('page-title').textContent = TITLES[tab];
  document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.fab').forEach(f => f.remove());
  const c = document.getElementById('content');
  c.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
  const renderers = { home: renderHome, inventory: renderInventory, sales: renderSales, parties: renderParties, dues: renderDues };
  if (window.PBRealtime) PBRealtime.setView({ refresh: () => setTab(tab), full: true, entities: tab === 'home' ? ['lot_intakes'] : [] }); // S8
  renderers[tab]().catch(err => { c.innerHTML = `<div class="empty-state">${esc(err.message)}</div>`; });
}

function fab(icon, onClick) {
  const b = document.createElement('button');
  b.className = 'fab'; b.textContent = icon; b.onclick = onClick;
  document.getElementById('app-view').appendChild(b);
}
function fabSecondary(icon, onClick) {
  const b = document.createElement('button');
  b.className = 'fab fab-secondary'; b.textContent = icon; b.onclick = onClick;
  document.getElementById('app-view').appendChild(b);
}

// ---------------------------------------------------------------- خانه

function daysUntilIso(iso) {
  const [y, m, d] = String(iso).split('-').map(Number);
  const t = new Date(y, m - 1, d).getTime();
  const [ty, tm, td] = tehranYMD(); const t0 = new Date(ty, tm - 1, td).getTime();
  return Math.round((t - t0) / 86400000);
}
/** سود یک فروش = مبلغ فروش − بهای خرید اقلام (بهای خرید = فیِ همان خریدِ مبدأ)، مثل اپ اندروید */
function saleRevenue(s) { return (Number(s.net_weight) || 0) * (Number(s.price) || 0); }
/**
 * درآمد/بها/سود دلاریِ یک فروش (پلن ارزی): درآمد = مبلغ فروش تومانی ÷ قیمت دلارِ روز فروش،
 * بها = وزن × فی دلاریِ خریدِ همان پسته. اگر قیمت دلار یا فی دلاریِ خرید ثبت نشده باشد null.
 */
function saleUsd(s) {
  const rate = Number(s.usd_rate);
  if (!(rate > 0)) return null;
  let cost = 0, extraToman = saleExtraCosts(s);
  for (const it of (s.items || [])) {
    if (it.cost_price_usd == null || it.cost_price_usd === '') return null;
    const w = Number(it.weight) || 0;
    cost += w * Number(it.cost_price_usd);
    extraToman += w * purchaseExtraPerKg(it);
  }
  cost += extraToman / rate; // هزینه‌های تومانی با قیمت دلار روز فروش به دلار تبدیل می‌شوند
  const revenue = saleRevenue(s) / rate;
  return { revenue, cost, profit: revenue - cost };
}
/** ردیف‌های دلاریِ کارت فروش/جزئیات فروش */
function usdSaleRowsHtml(s) {
  const rate = Number(s.usd_rate);
  if (!(rate > 0)) return '<div class="muted" style="font-size:12px">قیمت دلار این فروش ثبت نشده</div>';
  const u = saleUsd(s);
  return `<div class="card-row"><span>قیمت دلار روز فروش</span><span>${toman(rate)}</span></div>
    <div class="card-row"><span>فی فروش به دلار</span><span>${usd(Number(s.price) / rate, 4)}</span></div>
    <div class="card-row"><span>مبلغ فروش به دلار</span><span>${usd(saleRevenue(s) / rate)}</span></div>
    `;
}
/** هزینهٔ جانبیِ هر کیلو از یک خرید = (کارگر + حمل‌ونقل/باسکول) ÷ وزن خالص همان خرید */
function purchaseExtraPerKg(it) {
  const net = Number(it.p_net_weight) || 0;
  return net > 0 ? ((Number(it.p_labor_cost) || 0) + (Number(it.p_transport_cost) || 0)) / net : 0;
}
/** هزینه‌های جانبیِ ثبت‌شده در خودِ فروش (کارگر + حمل‌ونقل/باسکول) */
function saleExtraCosts(s) { return (Number(s.labor_cost) || 0) + (Number(s.transport_cost) || 0); }
/** بهای تمام‌شدهٔ یک فروش = (فی خرید + هزینهٔ جانبیِ خرید به‌ازای کیلو) × وزن + هزینه‌های جانبیِ فروش */
function saleCost(s) {
  return (s.items || []).reduce((a, it) => a + (Number(it.weight) || 0) * ((Number(it.cost_price) || 0) + purchaseExtraPerKg(it)), 0) + saleExtraCosts(s);
}
/** سهم یک قلم از هزینه‌های جانبیِ خودِ فروش (به نسبت وزن) */
function saleExtraShare(s, it) {
  const tw = (s.items || []).reduce((a, x) => a + (Number(x.weight) || 0), 0);
  return tw > 0 ? saleExtraCosts(s) * (Number(it.weight) || 0) / tw : 0;
}

async function renderHome() {
  const [inv, sales] = await Promise.all([api('purchases.list'), api('sales.list')]);
  let dues = [];
  try { dues = (await api('dues.list')).items.filter(d => !d.paid); } catch { /* بدون کارت سررسید */ }
  let msgs = [];
  try { msgs = (await api('messages.list')).items.filter(m => !m.read); } catch { /* آفلاین یا هنوز پیامی نیست */ }
  const c = document.getElementById('content');

  let invKg = 0;
  for (const l of inv.items) invKg += Number(l.remaining) || 0;

  // حریم خصوصی: در صفحهٔ اصلی فقط مقدارِ فروش (کیلوگرم) و موجودی دیده می‌شود؛ سود و زیان فقط در «گزارش سود و زیان» (قابل قفل)
  const today = todayStr();
  const tj = todayJalaliYMD();
  const monthStart = jalaliYMDToIso(tj[0], tj[1], 1);
  let todayKg = 0, todayCount = 0, monthKg = 0;
  for (const s of sales.items) {
    const w = Number(s.net_weight) || 0;
    if (s.date === today) { todayKg += w; todayCount++; }
    if (s.date >= monthStart) monthKg += w;
  }
  const overdue = dues.filter(d => daysUntilIso(d.date) < 0);
  const soon = dues.filter(d => daysUntilIso(d.date) >= 0 && daysUntilIso(d.date) <= 7);
  const sum = (a) => a.reduce((x, d) => x + Number(d.amount || 0), 0);
  const msgColor = { update: '#1565c0', greeting: '#e65100', info: '#2e7d32' };
  const msgIcon = { update: '🔄', greeting: '🎉', info: '📢' };
  const safeUrl = (u) => /^https?:\/\//i.test(String(u || '')) ? u : '';

  c.innerHTML = `
    ${msgs.map(m => `<div class="card home-msg" data-mid="${m.id}" style="border:1.5px solid ${msgColor[m.kind] || msgColor.info};background:${(msgColor[m.kind] || msgColor.info)}12">
      <div class="card-row"><b style="color:${msgColor[m.kind] || msgColor.info}">${msgIcon[m.kind] || '📢'} ${esc(m.title)}</b><button type="button" class="tag" data-close-msg="${m.id}" title="بستن">✕</button></div>
      ${m.body ? `<div style="white-space:pre-wrap;margin-top:4px">${esc(m.body)}</div>` : ''}
      ${safeUrl(m.link_url) ? `<a class="btn btn-secondary" style="display:inline-block;margin-top:8px;text-decoration:none" href="${esc(safeUrl(m.link_url))}" target="_blank" rel="noopener noreferrer">🔗 ${esc(m.link_label || 'باز کردن لینک')}</a>` : ''}
    </div>`).join('')}
    <div class="card" style="display:flex;gap:8px">
      <button class="btn btn-primary" id="q-purchase" style="margin-top:0">🛒 خرید جدید</button>
      <button class="btn btn-primary" id="q-sale" style="margin-top:0">🏷 فروش جدید</button>
    </div>
    <div class="card" style="background:#e8f3da;text-align:center;padding:18px">
      <div class="muted">موجودی انبار (خالص)</div>
      <div style="font-size:30px;font-weight:800;margin-top:4px">${kg(invKg)}</div>
    </div>
    <div class="section-title">فروش</div>
    <div class="card">
      <div class="card-row"><span>فروش امروز</span><span>${cnt(todayCount)} مورد • ${kg(todayKg)} (خالص)</span></div>
      <div class="card-row"><span>فروش این ماه</span><span>${kg(monthKg)} (خالص)</span></div>
    </div>
    <div class="card" id="home-dues" style="cursor:pointer;${overdue.length ? 'background:#fdecea' : ''}">
      <div class="card-row"><b style="font-size:17px">سررسیدها</b><span>‹</span></div>
      ${!overdue.length && !soon.length ? '<div class="muted">چیزی سررسید نزدیک ندارد</div>' : ''}
      ${overdue.length ? `<div class="card-row"><b>عقب‌افتاده</b><b class="neg">${cnt(overdue.length)} مورد • ${toman(sum(overdue))}</b></div>` : ''}
      ${soon.length ? `<div class="card-row"><b>تا ۷ روز آینده</b><b>${cnt(soon.length)} مورد • ${toman(sum(soon))}</b></div>` : ''}
    </div>
    <div class="card"><button class="btn btn-secondary" id="q-report" style="margin-top:0">📊 گزارش سود و زیان 🔒</button></div>
  `;
  document.getElementById('q-purchase').onclick = () => openPurchaseForm();
  document.getElementById('q-sale').onclick = () => openSaleForm();
  document.getElementById('q-report').onclick = () => openReportForm();
  document.getElementById('home-dues').onclick = () => setTab('dues');
  if (window.PBIntake) PBIntake.decorateHome(c); // S8: کارت «پذیرش‌های در انتظار قطعی‌سازی»
  if (window.PBCrack && PBCrack.decorateHome) PBCrack.decorateHome(c); // مرحلهٔ ۵: کارت «موجودی پستهٔ خرجی»
  c.querySelectorAll('[data-close-msg]').forEach(b => b.addEventListener('click', async () => {
    const id = Number(b.dataset.closeMsg);
    const card = c.querySelector(`.home-msg[data-mid="${id}"]`);
    if (card) card.remove();
    try { await api('messages.read', { id }); } catch { /* بعداً دوباره */ }
  }));
}

// ---- قفل صفحهٔ سود و زیان: رمز عددی (۴ تا ۸ رقم) فقط روی همین مرورگر و به‌صورت هش‌شده نگه‌داشته می‌شود
const PL_KEY = 'pl_hash_v1', PL_SALT = 'pl_salt_v1';
async function plHash(pin, salt) {
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(salt + '|' + pin + '|pesteh'));
  return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
}
function plEnabled() { try { return !!localStorage.getItem(PL_KEY); } catch { return false; } }
async function plSet(pin) { const salt = newId(); localStorage.setItem(PL_SALT, salt); localStorage.setItem(PL_KEY, await plHash(pin, salt)); }
async function plVerify(pin) { try { return (await plHash(pin, localStorage.getItem(PL_SALT) || '')) === localStorage.getItem(PL_KEY); } catch { return false; } }
function plRemove() { try { localStorage.removeItem(PL_KEY); localStorage.removeItem(PL_SALT); } catch { /* نادیده */ } }

function openPinModal(onOk) {
  const close = openModal(`<h2>🔒 صفحهٔ سود و زیان قفل است</h2>
    <div class="field"><label>رمز را وارد کنید</label><input type="password" id="pl-pin" inputmode="numeric" maxlength="8" autocomplete="off" style="text-align:center;font-size:22px;letter-spacing:8px"></div>
    <div class="error" id="pl-err"></div>
    <button class="btn btn-primary" id="pl-ok">باز کردن</button>
    <button class="btn btn-secondary" id="pl-forgot">رمز را فراموش کرده‌ام</button>
    <button class="btn btn-secondary" id="pl-cancel">انصراف</button>`);
  const pin = document.getElementById('pl-pin'); pin.focus();
  const submit = async () => {
    if (await plVerify(toEnDigitsStr(pin.value.trim()))) { close(); onOk(); }
    else { document.getElementById('pl-err').textContent = 'رمز درست نیست'; pin.value = ''; }
  };
  document.getElementById('pl-ok').onclick = submit;
  pin.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
  document.getElementById('pl-cancel').onclick = close;
  document.getElementById('pl-forgot').onclick = () => {
    if (!confirm('برای ریست رمز باید از حساب خارج شوید و دوباره با کد پیامکی وارد شوید (اطلاعات شما پاک نمی‌شود). ادامه می‌دهید؟')) return;
    plRemove(); close(); doLogout();
  };
}

function openPinSettings() {
  const enabled = plEnabled();
  const close = openModal(`<h2>قفل صفحهٔ سود و زیان</h2>
    <div class="muted" style="margin-bottom:10px">${enabled ? 'قفل فعال است؛ برای دیدن سود و زیان باید رمز را وارد کنید.' : 'با فعال‌کردن قفل، هیچ‌کس بدون رمز درآمد و سود شما را نمی‌بیند.'}</div>
    ${enabled ? '<div class="field"><label>رمز فعلی</label><input type="password" id="pls-cur" inputmode="numeric" maxlength="8"></div>' : ''}
    <div class="field"><label>رمز جدید (۴ تا ۸ رقم) — برای حذف قفل خالی بگذارید</label><input type="password" id="pls-new" inputmode="numeric" maxlength="8"></div>
    <div class="field"><label>تکرار رمز جدید</label><input type="password" id="pls-new2" inputmode="numeric" maxlength="8"></div>
    <div class="error" id="pls-err"></div>
    <button class="btn btn-primary" id="pls-ok">ذخیره</button>
    <button class="btn btn-secondary" id="pls-cancel">انصراف</button>`);
  document.getElementById('pls-cancel').onclick = close;
  document.getElementById('pls-ok').onclick = async () => {
    const err = document.getElementById('pls-err'); err.textContent = '';
    const val = (id) => toEnDigitsStr((document.getElementById(id) || { value: '' }).value.trim());
    if (enabled && !(await plVerify(val('pls-cur')))) { err.textContent = 'رمز فعلی درست نیست'; return; }
    const a = val('pls-new'), b = val('pls-new2');
    if (!a) { if (!enabled) { err.textContent = 'رمز جدید را وارد کنید'; return; } plRemove(); toast('قفل برداشته شد'); close(); return; }
    if (!/^\d{4,8}$/.test(a)) { err.textContent = 'رمز باید ۴ تا ۸ رقم (عدد) باشد'; return; }
    if (a !== b) { err.textContent = 'دو رمز یکی نیستند'; return; }
    await plSet(a); toast('قفل صفحهٔ سود و زیان فعال شد'); close();
  };
}

function openReportForm() {
  if (!plEnabled()) { openReportFormInner(); return; }
  openPinModal(() => openReportFormInner());
}

let _rpChartMode = 0; // 0 = میله‌ای | 1 = کروی
let _rpChartUsd = false; // نمودار دلاری (فقط پلن ارزی)
function openReportFormInner() {
  const html = `
    <h2>گزارش سود و زیان</h2>
    <div class="row2">
      <div class="field"><label>از تاریخ (اختیاری)</label><div id="rp-from"></div></div>
      <div class="field"><label>تا تاریخ (اختیاری)</label><div id="rp-to"></div></div>
    </div>
    <button class="btn btn-secondary" id="rp-clear" style="margin-top:0">حذف فیلتر بازه (ماه جاری)</button>
    <div class="field" style="margin-top:8px"><label>جستجو با کد پسته</label><input type="text" id="rp-q" placeholder="مثلاً KH00012"></div>
    <div class="muted" id="rp-period" style="margin-bottom:6px"></div>
    <div id="rp-result"><div class="muted">در حال بارگذاری...</div></div>
    <div class="section-title">خروجی PDF تراکنش‌ها (بازهٔ انتخابی)</div>
    <button class="btn btn-secondary" id="rp-pay" style="margin-top:0">🖨 گزارش کلی تراکنش‌های پرداخت (خرید)</button>
    <button class="btn btn-secondary" id="rp-rec">🖨 گزارش کلی تراکنش‌های دریافت (فروش)</button>
    <button class="btn btn-secondary" id="rp-lock">🔒 قفل صفحهٔ سود و زیان</button>
<button class="btn btn-secondary" id="rp-close">بستن</button>
  `;
  const close = openModal(html);
  let useFrom = false, useTo = false; // تا کاربر تاریخی انتخاب نکند، بازه «همه» است
  const fromBox = document.getElementById('rp-from'), toBox = document.getElementById('rp-to');
  const mark = () => { fromBox.style.opacity = useFrom ? 1 : .55; toBox.style.opacity = useTo ? 1 : .55; };
  mountJalaliDate('rp-from', todayStr(), () => { useFrom = true; mark(); load(); });
  mountJalaliDate('rp-to', todayStr(), () => { useTo = true; mark(); load(); });
  document.getElementById('rp-clear').onclick = () => { useFrom = false; useTo = false; mark(); load(); };
  document.getElementById('rp-close').onclick = close;
document.getElementById('rp-lock').onclick = () => openPinSettings();
  mark();
  const range = () => ({ from: useFrom ? dateVal('rp-from') : '', to: useTo ? dateVal('rp-to') : '' });
  /** بازهٔ گزارش سود: اگر کاربر چیزی انتخاب نکرده باشد، ماه جاری شمسی (نه کل بازه) */
  const profitRange = () => {
    const r = range();
    if (r.from || r.to) return r;
    const [jy, jm] = todayJalaliYMD();
    return { from: jalaliYMDToIso(jy, jm, 1), to: jalaliYMDToIso(jy, jm, daysInJalaliMonth(jy, jm)) };
  };
  const periodLabel = () => { const r = range(); return (!r.from && !r.to) ? 'همهٔ بازه‌ها' : `از ${r.from ? isoToJalaliDisplay(r.from) : '...'} تا ${r.to ? isoToJalaliDisplay(r.to) : '...'}`; };

  let all = [];
  let allRets = null; // ردیف‌های برگشت از فروش (برای کسر از سود)؛ null = هنوز بارگذاری نشده
  let retsFailed = false; // اگر برگشت‌ها بارگذاری نشوند، گزارش بدون کسر برگشت است و باید هشدار نشان بدهد
  async function load() {
    const box = document.getElementById('rp-result');
    if (!box) return;
    if (!all.length) { try { all = (await api('sales.list')).items; } catch (err) { box.innerHTML = `<div class="error">${esc(err.message)}</div>`; return; } }
    if (allRets === null) { try { allRets = (await api('sale_returns.profit_lines')).items || []; retsFailed = false; } catch { allRets = []; retsFailed = true; } }
    if (!all.length) { box.innerHTML = '<div class="empty-state">هنوز فروشی ثبت نشده</div>'; return; }
    const r = profitRange();
    const explicit = useFrom || useTo;
    const pe = document.getElementById('rp-period');
    if (pe) pe.textContent = `${explicit ? 'بازهٔ انتخابی' : 'ماه جاری'}: از ${r.from ? isoToJalaliDisplay(r.from) : '...'} تا ${r.to ? isoToJalaliDisplay(r.to) : '...'}`;
    const q = normSearch((document.getElementById('rp-q') || {}).value);
    const list = all.filter(s => (!r.from || s.date >= r.from) && (!r.to || s.date <= r.to)
      && (!q || s.items.some(it => normSearch(it.code).includes(q))));
    let totalRevenue = 0, totalCost = 0, usdRev = 0, usdCost = 0, usdMissing = 0;
    const byType = {};
    // داده‌ی نمودار: سود و زیانِ هر روز (بازه تا ۳۱ روز) یا هر ماه شمسی
    // برگشت از فروش: به تاریخ «برگشت» حساب می‌شود و از سود کم می‌شود (جمع کل، نوع پسته، نمودار)
    const retList = allRets.filter(x => (!r.from || x.date >= r.from) && (!r.to || x.date <= r.to)
      && (!q || normSearch(x.code).includes(q)));
    let cFrom = r.from, cTo = r.to;
    if (list.length || retList.length) { const ds = list.map(x => x.date).concat(retList.map(x => x.date)).sort(); if (!cFrom) cFrom = ds[0]; if (!cTo) cTo = ds[ds.length - 1]; }
    const buckets = (cFrom && cTo) ? chBuckets(cFrom, cTo) : [];
    const bucketTypes = buckets.map(() => ({}));
    const byTypeUsd = {};
    const bucketTypesUsd = buckets.map(() => ({}));
    const rows = list.map(s => {
      const suE = state.isFx ? saleUsd(s) : null; // null = قیمت دلار یا فی دلاریِ خرید ثبت نشده
      const rateE = Number(s.usd_rate);
      for (const it of s.items) {
        const w = Number(it.weight) || 0;
        const rev = w * (Number(it.item_price) || Number(s.price) || 0);
        const extraC = w * purchaseExtraPerKg(it) + saleExtraShare(s, it);
const cost = w * (Number(it.cost_price) || 0) + extraC;
        // قلم‌های مخلوط/جداسازی بر اساس «نوع زیردسته»شان حساب می‌شوند
        const key = `${it.category_name} - ${(it.base_type && String(it.base_type).trim()) || it.type_name}`;
        byType[key] = (byType[key] || 0) + (rev - cost);
        const bi = chBucketIdx(buckets, s.date);
        if (bi >= 0) bucketTypes[bi][key] = (bucketTypes[bi][key] || 0) + (rev - cost);
        if (suE && rateE > 0) { // سود دلاریِ همین قلم (برای نمودار دلاری)
          const pu = rev / rateE - w * Number(it.cost_price_usd) - extraC / rateE;
          byTypeUsd[key] = (byTypeUsd[key] || 0) + pu;
          if (bi >= 0) bucketTypesUsd[bi][key] = (bucketTypesUsd[bi][key] || 0) + pu;
        }
      }
      const revenue = saleRevenue(s), cost = saleCost(s);
      totalRevenue += revenue; totalCost += cost;
      const su = state.isFx ? saleUsd(s) : null;
      if (state.isFx) { if (su) { usdRev += su.revenue; usdCost += su.cost; } else usdMissing++; }
      const comp = s.items.map(it => `${esc(it.type_name)}${it.code ? ' (کد ' + esc(it.code) + ')' : ''} ${cnt(it.weight)}`).join(' + ');
      return `<div class="card"><div><b>${isoToJalaliDisplay(s.date)}${esc(s.buyer ? ' • ' + s.buyer : '')}</b></div><div class="meta">${comp}</div>
        <div class="card-row"><span>مبلغ فروش</span><span>${toman(revenue)}</span></div>
        <div class="card-row"><span>بهای خرید و هزینه‌ها</span><span>${toman(cost)}</span></div>
        <div class="card-row"><span>سود / زیان</span><span class="${revenue - cost >= 0 ? 'pos' : 'neg'}">${toman(revenue - cost)}</span></div>
        ${state.isFx ? (su ? `<div class="card-row"><span>مبلغ فروش (دلار)</span><span>${usd(su.revenue)}</span></div><div class="card-row"><span>بهای خرید (دلار)</span><span>${usd(su.cost)}</span></div><div class="card-row"><span>سود / زیان دلاری</span><span class="${su.profit >= 0 ? 'pos' : 'neg'}">${usd(su.profit)}</span></div>` : '<div class="muted" style="font-size:12px">سود دلاری: قیمت دلار یا فی دلاریِ خرید ثبت نشده</div>') : ''}</div>`;
    }).join('');
    // ---- کسر برگشت از فروش ----
    let retRev = 0, retCost = 0, retUsdRev = 0, retUsdCost = 0, retUsdMissing = 0;
    const retRows = retList.map(x => {
      const kg = Number(x.kg) || 0;
      const rev = kg * (Number(x.unit_price) || 0);
      const cost = kg * ((Number(x.cost_price) || 0) + (Number(x.extra_per_kg) || 0));
      retRev += rev; retCost += cost;
      const key = `${x.category_name} - ${(x.base_type && String(x.base_type).trim()) || x.type_name}`;
      byType[key] = (byType[key] || 0) - (rev - cost);
      const bi = chBucketIdx(buckets, x.date);
      if (bi >= 0) bucketTypes[bi][key] = (bucketTypes[bi][key] || 0) - (rev - cost);
      const rate = Number(x.usd_rate);
      if (state.isFx) {
        if (rate > 0 && x.cost_price_usd != null && x.cost_price_usd !== '') {
          const uRev = rev / rate, uCost = kg * Number(x.cost_price_usd) + kg * (Number(x.extra_per_kg) || 0) / rate;
          retUsdRev += uRev; retUsdCost += uCost;
          byTypeUsd[key] = (byTypeUsd[key] || 0) - (uRev - uCost);
          if (bi >= 0) bucketTypesUsd[bi][key] = (bucketTypesUsd[bi][key] || 0) - (uRev - uCost);
        } else retUsdMissing++;
      }
      return `<div class="card"><div><b>↩ برگشت از فروش • ${isoToJalaliDisplay(x.date)}</b></div><div class="meta">${esc(x.type_name || '')}${x.code ? ' (کد ' + esc(x.code) + ')' : ''} ${cnt(kg)}</div>
        <div class="card-row"><span>فروش برگشتی (کسر از فروش)</span><span>${toman(rev)}</span></div>
        <div class="card-row"><span>بهای کالای برگشتی (کسر از بها)</span><span>${toman(cost)}</span></div>
        <div class="card-row"><span>سود برگشتی (کسر از سود)</span><span class="${rev - cost >= 0 ? 'neg' : 'pos'}">${toman(rev - cost)}</span></div></div>`;
    }).join('');
    const grossRevenue = totalRevenue, grossCost = totalCost;
    totalRevenue -= retRev; totalCost -= retCost;
    if (state.isFx) { usdRev -= retUsdRev; usdCost -= retUsdCost; usdMissing += retUsdMissing; }
    const totalProfit = totalRevenue - totalCost;
    const totalGain = Object.values(byType).reduce((a, v) => a + (v > 0 ? v : 0), 0);
    const totalLoss = Object.values(byType).reduce((a, v) => a + (v < 0 ? v : 0), 0);
    const types = Object.entries(byType).sort((a, b) => b[1] - a[1]);
    box.innerHTML = `
      <div class="card" style="background:${totalProfit >= 0 ? '#eef6ff' : '#fdecea'};margin-top:10px">
        <div class="card-row"><span>مجموع فروش</span><span>${toman(grossRevenue)}</span></div>
        ${retList.length ? `<div class="card-row"><span>برگشت از فروش (کسر از فروش)</span><span class="neg">${toman(-retRev)}</span></div>` : ''}
        <div class="card-row"><span>مجموع بهای خرید و هزینه‌ها</span><span>${toman(grossCost)}</span></div>
        ${retList.length ? `<div class="card-row"><span>بهای کالای برگشتی (کسر از بها)</span><span class="pos">${toman(-retCost)}</span></div>` : ''}
        ${totalGain > 0 ? `<div class="card-row"><span>مجموع سود اجناس سودده</span><span class="pos">${toman(totalGain)}</span></div>` : ''}
        ${totalLoss < 0 ? `<div class="card-row"><span>مجموع زیان اجناس زیان‌ده</span><span class="neg">${toman(-totalLoss)}</span></div>` : ''}
        <div class="card-row"><span>${totalProfit >= 0 ? 'خالص سود' : 'خالص زیان'}</span><span class="${totalProfit >= 0 ? 'pos' : 'neg'}">${toman(Math.abs(totalProfit))}</span></div>
        ${state.isFx ? `<hr><div class="card-row"><span>مجموع فروش (دلار)</span><span>${usd(usdRev)}</span></div><div class="card-row"><span>مجموع بهای خرید (دلار)</span><span>${usd(usdCost)}</span></div><div class="card-row"><span>مجموع سود / زیان دلاری</span><span class="${usdRev - usdCost >= 0 ? 'pos' : 'neg'}">${usd(usdRev - usdCost)}</span></div>${usdMissing ? `<div class="muted" style="font-size:12px">${cnt(usdMissing)} فروش به‌دلیل نبودنِ قیمت دلار یا فی دلاریِ خرید در جمع دلاری نیامده است</div>` : ''}` : ''}
      </div>
      ${retsFailed ? '<div class="error" style="margin-top:8px">⚠ برگشت از فروش بارگذاری نشد؛ اعداد این گزارش بدون کسر برگشت‌ها هستند. اینترنت را بررسی کنید و صفحه را دوباره باز کنید.</div>' : ''}
      <div id="rp-chart"></div>
      ${types.length ? `<div class="section-title">سود به تفکیک نوع پسته (بازهٔ انتخاب‌شده)</div><div class="card">${types.map(([k, v]) => `<div class="card-row"><span>${esc(k)}</span><span class="${v >= 0 ? 'pos' : 'neg'}">${toman(v)}</span></div>`).join('')}</div>` : ''}
      ${retRows ? `<div class="section-title">برگشت از فروش در این بازه</div>${retRows}<div class="muted" style="font-size:12px;margin-bottom:6px">برگشت‌ها بر اساس تاریخ برگشت حساب می‌شوند؛ هزینهٔ کارگر و حمل ثبت‌شده روی خودِ فروش اصلی تغییر نمی‌کند.</div>` : ''}
      <div class="section-title">فروش‌ها</div>${rows || '<div class="muted">در این بازه فروشی نیست</div>'}`;
    const drawPc = () => {
      const pc = profitChartHtml(_rpChartMode, buckets, bucketTypes, byType, state.isFx ? { bucketTypes: bucketTypesUsd, byType: byTypeUsd } : null, _rpChartUsd);
      const host = document.getElementById('rp-chart');
      if (host) { host.innerHTML = pc.html; pc.bind((mm) => { _rpChartMode = mm; drawPc(); }, (u) => { _rpChartUsd = u; drawPc(); }); }
    };
    drawPc();
  }
  document.getElementById('rp-q').addEventListener('input', () => load());
  load();

  async function exportTx(kind) {
    try {
      const r = range();
      const items = (await api('reports.tx_all', { kind, from: r.from, to: r.to })).items;
      const total = items.reduce((a, x) => a + Number(x.amount), 0);
      const isPay = kind === 'payments';
      openPrintView(isPay ? 'گزارش کلی تراکنش‌های پرداخت' : 'گزارش کلی تراکنش‌های دریافت', `
        <h1>${isPay ? 'گزارش کلی تراکنش‌های پرداخت' : 'گزارش کلی تراکنش‌های دریافت وجه'}</h1>
        <div class="sub">${periodLabel()}</div>
        <table><thead><tr><th>${isPay ? 'فروشنده' : 'خریدار'}</th><th>مبلغ (ریال)</th><th>تاریخ</th><th>کد پسته</th><th>نوع</th><th>وضعیت</th></tr></thead>
        <tbody>${items.map(x => `<tr><td>${esc(x.name || '-')}</td><td>${cnt(x.amount)}</td><td>${isoToJalaliDisplay(x.date)}</td><td>${esc(x.code || '-')}</td><td>${x.kind}</td><td>${Number(x.paid) ? 'تسویه‌شده' : 'باقیمانده'}</td></tr>`).join('')}</tbody></table>
        <div class="row"><span><b>جمع کل</b></span><span><b>${toman(total)}</b></span></div>`);
    } catch (err) { toast(err.message); }
  }
  document.getElementById('rp-pay').onclick = () => exportTx('payments');
  document.getElementById('rp-rec').onclick = () => exportTx('receipts');
}

function cnt(n) { return (Math.round(Number(n) || 0)).toLocaleString('fa-IR'); }

/** یک پنجرهٔ جدید با محتوای HTML قابل‌چاپ باز می‌کند و دیالوگ چاپ مرورگر را نشان می‌دهد (معادل PDF/کارتکس اپ اندروید) */
function openPrintView(title, bodyHtml) {
  const w = window.open('', '_blank');
  if (!w) { toast('اجازهٔ باز کردن پنجرهٔ جدید داده نشد؛ Popup را برای این سایت فعال کنید'); return; }
  w.document.write(`<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">
    <title>${title}</title>
    <style>
      body { font-family: Tahoma, Vazirmatn, sans-serif; padding: 24px; color: #222; }
      h1 { font-size: 18px; margin-bottom: 4px; }
      .sub { color: #666; font-size: 12.5px; margin-bottom: 18px; }
      table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
      td, th { border: 1px solid #ccc; padding: 7px 10px; font-size: 13.5px; text-align: right; }
      th { background: #f2f2f2; }
      .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #eee; font-size: 13.5px; }
      .brand-footer { position: fixed; bottom: 6px; left: 0; right: 0; text-align: center; font-weight: bold; font-size: 12px; color: #333; }
      .brand-footer img { width: 16px; height: 16px; vertical-align: middle; margin-left: 5px; }
      body { padding-bottom: 46px; }
      @media print { .no-print { display: none; } }
    </style></head><body>
    ${bodyHtml}
    <div class="brand-footer"><img src="${new URL('icons/icon-96.png', location.href).href}" alt="">پسته بازار</div>
    <div class="no-print" style="margin-top:20px"><button id="pbtn" style="padding:10px 18px">چاپ / ذخیرهٔ PDF</button></div>
    </body></html>`);
  w.document.close();
  const pb = w.document.getElementById('pbtn');
  if (pb) pb.addEventListener('click', () => w.print());
  w.focus();
  setTimeout(() => { try { w.print(); } catch {} }, 350);
}


// ---------------------------------------------------------------- فاکتور (خرید / فروش)
// دو نوع فاکتور: «تومانی» (برای همهٔ کاربران) و «تومانی/ارزی» (فقط پلن ارزی؛ فی، مبلغ و نرخ دلار هم درج می‌شود).

/** پلن ارزی بین دو نوع فاکتور انتخاب می‌کند؛ پلن ریالی مستقیم «toman». null = انصراف */
function askInvoiceType() {
  if (!state.isFx) return Promise.resolve('toman');
  return new Promise((resolve) => {
    let done = false;
    const close = openModal(`
      <h2>نوع فاکتور</h2>
      <button class="btn btn-secondary" id="inv-toman" style="margin-top:0">🧾 فاکتور ریالی</button>
      <button class="btn btn-primary" id="inv-fx">💱 فاکتور ریالی / ارزی</button>
      <div class="muted" style="margin:6px 0">فاکتور ارزی فی، مبلغ کل و نرخ دلار را هم درج می‌کند.</div>
      <button class="btn btn-secondary" id="inv-cancel">انصراف</button>`);
    const fin = (v) => { if (done) return; done = true; close(); resolve(v); };
    document.getElementById('inv-toman').onclick = () => fin('toman');
    document.getElementById('inv-fx').onclick = () => fin('fx');
    document.getElementById('inv-cancel').onclick = () => fin(null);
  });
}

/** مشخصات کیفی یک خرید برای درج در فاکتور */
function purchaseSpecs(p) {
  const out = [];
  if (!p) return out;
  if (p.ounce != null && p.ounce !== '') out.push(['انس', p.ounce]);
  if (p.gram != null && p.gram !== '') out.push(['گرم', p.gram]);
  [['pct_khandan', 'درصد خندان در دنبست'], ['pct_dombest', 'درصد دنبست در خندان'], ['pct_tuchin', 'درصد توچین'], ['pct_crooked', 'درصد کج/ناخالص']]
    .forEach(([k, label]) => { if (p[k] != null && p[k] !== '') out.push([label, '٪' + p[k]]); });
  parseCustomSpecs(p.custom_specs).forEach(s => out.push([s.label, s.value]));
  return out;
}

/** جمع مبالغ پرداخت‌شده/دریافت‌شده از شیِ payment (خرید) یا receipt (فروش): فقط ردیف‌های paid */
function paidSumOf(pay) {
  if (!pay) return 0;
  const list = pay.lump || pay.installments || [];
  return list.reduce((a, t) => a + ((Number(t.paid) === 1 || t.paid === true) ? (Number(t.amount) || 0) : 0), 0);
}

/**
 * o: { isSale, withUsd, number, date(iso), seller, buyer, rate, rows:[{category,type,code,net,price}], paid (تومان یا null),
 *      total, small, large, specs:[[label,value]], note }
 */
function invoiceHtml(o) {
  const fx = o.withUsd && Number(o.rate) > 0;
  let sumT = 0, sumU = 0, net = 0;
  const rows = o.rows.map((r, i) => {
    const amt = r.net * r.price, unit = fx ? r.price / o.rate : null, amtU = fx ? r.net * unit : null;
    sumT += amt; net += r.net; if (fx) sumU += amtU;
    return `<tr><td>${cnt(i + 1)}</td><td>${esc(r.category || '-')}</td><td>${esc(r.type || '-')}</td><td>${esc(r.code || '-')}</td><td>${kg(r.net)}</td><td>${cnt(r.price)}</td><td>${cnt(amt)}</td>${o.withUsd ? `<td>${unit == null ? '-' : usd(unit, 4)}</td><td>${amtU == null ? '-' : usd(amtU)}</td>` : ''}</tr>`;
  }).join('');
  // B2: بارکد کد(های) پسته؛ فقط کدهای قابل‌چاپ و حداکثر ۴ تا (کد N… بارکد ندارد)
  const bcCodes = [...new Set(o.rows.map(r => PBBarcode.normalize(r.code)).filter(c => PBBarcode.isPrintable(c)))].slice(0, 4);
  const bcBlock = bcCodes.length ? `<div style="text-align:center;margin:12px 0">${bcCodes.map(c => `<div style="display:inline-block;margin:0 8px;vertical-align:top">${PBBarcode.svg(c, { height: 44 })}</div>`).join('')}</div>` : '';
  const sign = (label) => `<div style="flex:1;border:1px solid #bbb;border-radius:6px;height:70px;padding:6px;font-size:12px">${label}</div>`;
  return `
    <h1>${o.isSale ? 'فاکتور فروش' : 'فاکتور خرید'} <span style="font-size:12px;color:#666;font-weight:normal">(${o.withUsd ? 'ریالی / ارزی' : 'ریالی'})</span></h1>
    <div class="sub">شمارهٔ فاکتور: ${esc(o.number)} · تاریخ: ${isoToJalaliDisplay(o.date)}</div>
    <table><tbody>
      <tr><th>فروشنده</th><td>${esc(o.seller || '-')}</td><th>خریدار</th><td>${esc(o.buyer || '-')}</td></tr>
      ${o.withUsd ? `<tr><th>نرخ دلار</th><td colspan="3">${fx ? toman(o.rate) : 'ثبت نشده'}</td></tr>` : ''}
    </tbody></table>
    <table><thead><tr><th>ردیف</th><th>دسته</th><th>نوع</th><th>کد پسته</th><th>وزن (خالص)</th><th>فی (ریال)</th><th>مبلغ (ریال)</th>${o.withUsd ? '<th>فی (دلار)</th><th>مبلغ (دلار)</th>' : ''}</tr></thead>
    <tbody>${rows}</tbody></table>
    ${bcBlock}
    <div class="row"><span>وزن کل (ناخالص - با گونی)</span><span>${kg(o.total)}</span></div>
    <div class="row"><span>گونی کوچک</span><span>${cnt(o.small)}</span></div>
    <div class="row"><span>گونی بزرگ</span><span>${cnt(o.large)}</span></div>
    <div class="row"><span>وزن (خالص)</span><span>${kg(net)}</span></div>
    ${o.specs && o.specs.length ? `<h1 style="font-size:14px;margin-top:14px">مشخصات جنس</h1><table><tbody>${o.specs.map(([l, v]) => `<tr><th>${esc(l)}</th><td>${esc(v)}</td></tr>`).join('')}</tbody></table>` : ''}
    <div class="row" style="margin-top:10px"><span><b>جمع کل (ریال)</b></span><span><b>${toman(sumT)}</b></span></div>
    ${o.withUsd ? `<div class="row"><span><b>جمع کل (دلار)</b></span><span><b>${fx ? usd(sumU) : '-'}</b></span></div>` : ''}
    ${o.paid != null ? `<div class="row" style="margin-top:8px"><span>مبلغ پرداخت‌شده (ریال)</span><span>${toman(o.paid)}</span></div><div class="row"><span><b>مانده حساب (ریال)</b></span><span><b>${toman(sumT - o.paid)}</b></span></div>` : ''}
    ${o.note ? `<div class="row"><span>توضیحات</span><span>${esc(o.note)}</span></div>` : ''}
    <div style="display:flex;gap:12px;margin-top:26px">${sign('امضای فروشنده')}${sign('امضای خریدار')}</div>`;
}

async function openPurchaseInvoice(p) {
  const type = await askInvoiceType();
  if (!type) return;
  const withUsd = type === 'fx';
  if (withUsd && !(Number(p.usd_rate) > 0)) { toast('قیمت دلار این خرید ثبت نشده؛ ابتدا خرید را ویرایش کنید و قیمت دلار را وارد کنید'); return; }
  // پرداخت‌ها در purchases.get می‌آیند؛ بار مخلوط پرداخت ندارد. اگر دریافت نشد (مثلاً آفلاین) بخش پرداخت درج نمی‌شود.
  let paid = null;
  if (Number(p.is_mix) !== 1) {
    try { paid = paidSumOf((await api('purchases.get', { id: p.id })).item.payment); } catch { paid = null; }
  }
  openPrintView(`فاکتور خرید ${esc(p.code || '')}`, invoiceHtml({
    isSale: false, withUsd, paid, number: p.code || String(p.id), date: p.date,
    seller: p.seller || '', buyer: (state.customer && state.customer.name) || '',
    rate: Number(p.usd_rate) || 0,
    rows: [{ category: p.category_name, type: p.type_name, code: p.code, net: Number(p.net_weight) || 0, price: Number(p.price) || 0 }],
    total: Number(p.total_weight) || 0, small: Number(p.small_sack_count) || 0, large: Number(p.large_sack_count) || 0,
    specs: purchaseSpecs(p), note: p.note || '',
  }));
}

async function openSaleInvoice(s) {
  const type = await askInvoiceType();
  if (!type) return;
  const withUsd = type === 'fx';
  if (withUsd && !(Number(s.usd_rate) > 0)) { toast('قیمت دلار این فروش ثبت نشده؛ ابتدا فروش را ویرایش کنید و قیمت دلار را وارد کنید'); return; }
  // اطلاعات کامل پسته (دسته و مشخصات)؛ اگر در دسترس نبود (مثلاً آفلاین) با همان اطلاعات خودِ فروش ساخته می‌شود
  const purchases = await Promise.all((s.items || []).map(async (it) => {
    try { return (await api('purchases.get', { id: it.purchase_id })).item; } catch { return null; }
  }));
  const rows = (s.items || []).map((it, i) => ({
    category: (purchases[i] && purchases[i].category_name) || it.category_name || '',
    type: it.type_name, code: it.code,
    net: Number(it.weight) || 0, price: Number(it.item_price) || Number(s.price) || 0,
  }));
  let paid = null;
  if (s.receipt !== undefined) paid = paidSumOf(s.receipt);
  else { try { paid = paidSumOf((await api('sales.get', { id: s.id })).item.receipt); } catch { paid = null; } }
  openPrintView(`فاکتور فروش ${s.id}`, invoiceHtml({
    isSale: true, withUsd, paid, number: 'S' + String(Math.abs(Number(s.id))).padStart(5, '0'), date: s.date,
    seller: (state.customer && state.customer.name) || '', buyer: s.buyer || '',
    rate: Number(s.usd_rate) || 0, rows,
    total: Number(s.total_weight) || Number(s.net_weight) || 0,
    small: Number(s.small_sack_count) || 0, large: Number(s.large_sack_count) || 0,
    specs: rows.length === 1 ? purchaseSpecs(purchases[0]) : [], note: s.note || '',
  }));
}

// ---------------------------------------------------------------- انبار

let _purchasesCache = [];
let _invView = 'available'; // 'available' = موجود در انبار | 'sold' = فروش رفته
let _invKind = 'bought'; // 'bought' = پسته‌های خریداری‌شده | 'mixed' = مخلوط/جداسازی (ساخته‌شده از پسته‌های خودِ انبار)
const INV_EPS = 1e-6;

// B3: پیام‌های اسکن بارکد (اسکنر یا دوربین)
function barcodeScanFail(reason) {
  toast(reason === 'denied'
    ? 'دسترسی به دوربین ممکن نشد؛ از اسکنر یا تایپ استفاده کنید'
    : 'اسکن با دوربین در این مرورگر پشتیبانی نمی‌شود؛ از اسکنر یا تایپ استفاده کنید');
}

let _invSearch = '';
let _invGroup = null; // T3c: کلید گروه مخلوطِ انتخاب‌شده (null = همه)
/** متن جستجو: ارقام فارسی/عربی به انگلیسی، حروف کوچک (تا «kh00012» و «KH۰۰۰۱۲» هر دو پیدا شوند) */
function normSearch(q) { return toEnDigitsStr(String(q || '')).trim().toLowerCase(); }

async function renderInventory() {
  const data = await api('purchases.list');
  _purchasesCache = data.items.filter(p => !p._pending); // موارد در صف را نمی‌توان مبدأ فروش/مخلوط جدید قرار داد
  const c = document.getElementById('content');
  if (!data.items.length) {
    c.innerHTML = '<div class="empty-state">انبار خالی است</div>';
    fab('+', () => openPurchaseForm());
    fabSecondary('🔀', () => openMixForm());
    return;
  }

  // «موجود» = هنوز مقداری از خود این خرید/مخلوط مانده، یا مقداری از آن داخل مخلوطی است که کامل فروش نرفته.
  // فقط وقتی هم خودش و هم همهٔ مخلوط‌های ساخته‌شده از آن کاملاً فروش رفته باشند «فروش رفته» حساب می‌شود.
  const isAvail = (p) => (Number(p.remaining) || 0) > INV_EPS || !!p.in_mix_stock;
  // انبار دو دسته است: پسته‌های خریداری‌شده، و پسته‌های مخلوط/جداسازی‌شده (که از انبار خودمان ساخته شده‌اند)
  const boughtAll = data.items.filter(p => !Number(p.is_mix));
  const mixedAll = data.items.filter(p => Number(p.is_mix));
  const kindItems = _invKind === 'mixed' ? mixedAll : boughtAll;
  const otherKindItems = _invKind === 'mixed' ? boughtAll : mixedAll;
  const available = kindItems.filter(isAvail);
  const soldOut = kindItems.filter(p => !isAvail(p));

  const groupItems = (items) => {
    const groups = {};
    for (const p of items) {
      const catG = groups[p.category_name] || (groups[p.category_name] = {});
      (catG[p.type_name] || (catG[p.type_name] = [])).push(p);
    }
    return groups;
  };

  let html = `${window.PBCartex && PBCartex.canView() ? '<button type="button" id="inv-cartex" class="btn btn-secondary" style="margin:0 0 8px">📋 کارتکس لات</button>' : ''}<div class="seg-tabs">
    <button class="seg-tab ${_invKind === 'bought' ? 'active' : ''}" data-kind="bought">🛒 خریداری‌شده <span class="seg-count">${cnt(boughtAll.filter(isAvail).length)}</span></button>
    <button class="seg-tab ${_invKind === 'mixed' ? 'active' : ''}" data-kind="mixed">🔀 مخلوط/جداسازی <span class="seg-count">${cnt(mixedAll.filter(isAvail).length)}</span></button>
  </div>
  <div class="seg-tabs">
    <button class="seg-tab ${_invView === 'available' ? 'active' : ''}" data-view="available">📦 موجود <span class="seg-count">${cnt(available.length)}</span></button>
    <button class="seg-tab ${_invView === 'sold' ? 'active' : ''}" data-view="sold">✅ فروش رفته <span class="seg-count">${cnt(soldOut.length)}</span></button>
  </div>`;

  // مجموع ضایعات/خاکِ ثبت‌شده در مخلوط/جداسازی‌ها (فقط در دستهٔ مخلوط/جداسازی)
  const totalWaste = mixedAll.reduce((a, p) => a + (Number(p.waste) || 0), 0);
  const wasteRow = (_invKind === 'mixed' && totalWaste > INV_EPS)
    ? `<div class="card-row"><span>مجموع ضایعات ثبت‌شده</span><span>${kg(totalWaste)}</span></div>` : '';

  if (_invView === 'available') {
    let totalKg = 0, totalValue = 0, totalSmall = 0, totalLarge = 0;
    for (const p of available) {
      const rem = Number(p.remaining) || 0;
      totalKg += rem;
      totalValue += rem * (Number(p.price) || 0);
      totalSmall += Number(p.small_left || 0);
      totalLarge += Number(p.large_left || 0);
    }
    html += `<div class="card">
      <div class="card-row"><span>مجموع موجودی (خالص)</span><span>${kg(totalKg)}</span></div>
      <div class="card-row"><span>ارزش خرید موجودی</span><span>${toman(totalValue)}</span></div>
      <div class="card-row"><span>تعداد گونی کل</span><span>${cnt(totalSmall + totalLarge)} عدد</span></div>
      <div class="card-row"><span>گونی کوچک</span><span>${cnt(totalSmall)}</span></div>
<div class="card-row"><span>گونی بزرگ</span><span>${cnt(totalLarge)}</span></div>
      ${wasteRow}
    </div>`;
  } else {
    let soldKg = 0, soldValue = 0;
    for (const p of soldOut) {
      const w = Number(p.net_weight) || 0;
      soldKg += w;
      soldValue += w * (Number(p.price) || 0);
    }
    html += `<div class="card">
      <div class="card-row"><span>تعداد خریدهای فروش‌رفته</span><span>${cnt(soldOut.length)} بار</span></div>
      <div class="card-row"><span>مجموع وزن فروش‌رفته (خالص)</span><span>${kg(soldKg)}</span></div>
      <div class="card-row"><span>ارزش خرید آن‌ها</span><span>${toman(soldValue)}</span></div>
      ${wasteRow}
    </div>`;
  }
  if (_invView === 'available') html += '<div id="inv-chart"></div>';
  html += `<button class="btn btn-secondary" id="inv-history" style="margin-top:0">📋 تاریخچهٔ کامل خرید (جستجو)</button>
    <div style="display:flex;gap:8px;align-items:center"><input type="search" id="inv-search" class="search-input" style="flex:1" placeholder="جستجو با کد پسته (مثلاً KH00012 یا MX00003)، نوع یا دسته..." value="${esc(_invSearch)}"><button class="btn btn-secondary" id="inv-scan" style="width:auto;margin:0">📷 اسکن</button></div>
    <div id="inv-list"></div>`;
  c.innerHTML = html;
  if (_invView === 'available') {
    const sc = stockChartHtml(available, _invKind === 'mixed' ? 'مخلوط / جداسازی' : 'خریداری‌شده');
    const host = document.getElementById('inv-chart');
    if (host) { host.innerHTML = sc.html; sc.bind(); }
  }

  const drawList = () => {
    const q = normSearch(_invSearch);
    const match = (p) => !q || `${p.code || ''} ${p.category_name || ''} ${p.type_name || ''}`.toLowerCase().includes(q);
    const base = _invView === 'available' ? available : soldOut;
    const other = _invView === 'available' ? soldOut : available;
    const matched = base.filter(match);
    // T3c: راهنمای گروه‌های مخلوط (از فهرست جاری پیش از فیلتر گروه). سرور قدیمی (بدون mix_group) ⇒ بی‌رنگ و بدون راهنما.
    const gKeys = {};
    data.items.forEach(p => { if (p.mix_group !== undefined && p.mix_group !== null) gKeys[p.id] = Number(p.mix_group); });
    const gCounts = window.PBCode ? PBCode.mixGroupCounts(matched, gKeys) : [];
    const gf = gCounts.some(g => g.key === _invGroup) ? _invGroup : null;
    const gOf = (p) => (p.mix_group === undefined || p.mix_group === null) ? null : Number(p.mix_group);
    const list = gf === null ? matched
      : matched.filter(p => gOf(p) === gf).sort((a, b) => (Number(b.id) === gf) - (Number(a.id) === gf)); // خودِ مخلوط اول
    const gLabel = (key) => {
      const m = data.items.find(x => Number(x.id) === key);
      return m && m.code ? 'مخلوط ' + m.code : 'مخلوط #' + cnt(key);
    };
    let h = '';
    if (gCounts.length) {
      h += '<div class="g-guide">' + gCounts.map(g => `<button type="button" class="g-guide-chip${gf === g.key ? ' on' : ''}" data-g="${g.key}" style="--g:${PBCode.groupColor(g.key)}" title="${gf === g.key ? 'لغو فیلتر گروه' : 'نمایش فقط این گروه'}"><i class="g-dot"></i>${esc(gLabel(g.key))} · ${cnt(g.count)} لات</button>`).join('') + '</div>';
    }
    if (!list.length) {
      const otherHits = q ? other.filter(match).length : 0;
      const kindName = _invKind === 'mixed' ? 'مخلوط/جداسازی' : 'خریداری‌شده';
      h += `<div class="empty-state">${q ? 'نتیجه‌ای پیدا نشد' : (_invView === 'available'
        ? (_invKind === 'mixed' ? 'هیچ مخلوط/جداسازی‌ای در انبار موجود نیست' : 'هیچ جنسی در انبار موجود نیست')
        : (_invKind === 'mixed' ? 'هنوز هیچ مخلوط/جداسازی‌ای به‌طور کامل فروش نرفته است' : 'هنوز هیچ خریدی به‌طور کامل فروش نرفته است'))}</div>`;
      if (otherHits) h += `<button class="btn btn-secondary" id="inv-switch">${cnt(otherHits)} مورد در بخش «${_invView === 'available' ? 'فروش رفته' : 'موجود'}» (${kindName}) پیدا شد — نمایش</button>`;
      const otherKindHits = q ? otherKindItems.filter(match).length : 0;
      if (otherKindHits) h += `<button class="btn btn-secondary" id="inv-switch-kind">${cnt(otherKindHits)} مورد در دستهٔ «${_invKind === 'mixed' ? 'خریداری‌شده' : 'مخلوط/جداسازی'}» پیدا شد — نمایش</button>`;
    } else {
      const groups = groupItems(list);
      for (const catName of Object.keys(groups)) {
        h += `<div class="section-title">${esc(catName)}</div>`;
        for (const typeName of Object.keys(groups[catName])) {
          const items = groups[catName][typeName];
          const sumRem = items.reduce((a, l) => a + (Number(l.remaining) || 0), 0);
          const sumNet = items.reduce((a, l) => a + (Number(l.net_weight) || 0), 0);
          const headInfo = _invView === 'available'
            ? `${kg(sumRem)} (خالص) · ${cnt(items.length)} بار`
            : `${kg(sumNet)} (خالص) · ${cnt(items.length)} بار`;
          h += `<div class="card${_invView === 'sold' ? ' card-sold' : ''}">
          <div class="card-row" style="align-items:flex-start"><b>${esc(typeName)}</b><span class="muted" style="font-size:12.5px">${headInfo}</span></div>
          ${items.map(l => {
            const net = Number(l.net_weight) || 0;
            const rem = Number(l.remaining) || 0;
            const soldW = Math.max(0, net - rem);
            const mixBadge = Number(l.is_mix) ? ' <span class="badge">مخلوط/جداسازی</span>' : '';
            const lg = (window.PBCode && gOf(l) !== null) ? gOf(l) : null; // T3c: گروه مخلوط این لات
            const gCls = lg === null ? '' : ' lot-g';
            const gStyle = lg === null ? '' : `--g:${PBCode.groupColor(lg)};`;
            const gChip = lg === null ? '' : ` <span class="badge g-chip" style="--g:${PBCode.groupColor(lg)}">مخلوط #${cnt(lg)}</span>`;
            const wasteLine = (Number(l.is_mix) && Number(l.waste) > INV_EPS) ? `<div class="meta">ضایعات/خاک: ${kg(l.waste)}</div>` : '';
            const pendBadge = l._pending ? ' <span class="badge unpaid">⏳ در صف</span>' : '';
            const traceBtn = (!l._pending && window.PBTrace && window.PBShell && PBShell.hasPerm('stock.view') && PBShell.hasPerm('purchases.view')) ? `<button type="button" class="btn secondary trace-link" data-trace="${l.id}" title="مسیر پسته" style="padding:2px 8px;margin-inline-start:6px">🧭 مسیر</button>` : ''; // T6
            const codeBadge = l.code ? (window.PBCode ? ' ' + PBCode.chipHtml(l.code, l.is_mix) : ` <span class="badge code-badge">کد ${esc(l.code)}</span>`) : ''; // T2: چیپ حرف‌دار
            const mixCodes = Array.isArray(l.in_mix_codes) ? l.in_mix_codes : [];
            const inMixLine = mixCodes.length
              ? `<div class="meta">🔀 ${rem <= INV_EPS ? 'کل موجودی آن' : 'بخشی از آن'} داخل مخلوط/جداسازی ${mixCodes.map(m => esc(m)).join('، ')} است (هنوز کامل فروش نرفته)</div>`
              : '';
            if (_invView === 'available') {
              return `
          <div class="card-row${gCls}" data-id="${l.id}" style="${gStyle}cursor:${l._pending ? 'default' : 'pointer'};align-items:flex-start">
            <div>
              <div>${kg(l.remaining)} (خالص) مانده${codeBadge}${mixBadge}${gChip}${pendBadge}${traceBtn}</div>
              <div class="meta">خرید ${isoToJalaliDisplay(l.date)} · فی ${toman(l.price)}</div>
              ${soldW > INV_EPS ? `<div class="meta">از ${kg(net)} (خالص) خریداری‌شده، ${kg(soldW)} (خالص) فروخته/مصرف شده</div>` : ''}
              ${inMixLine}
              ${wasteLine}
              <div class="meta">گونی مانده: کوچک ${cnt(l.small_left || 0)} / بزرگ ${cnt(l.large_left || 0)}</div>
            </div>
          </div>`;
            }
            return `
          <div class="card-row${gCls}" data-id="${l.id}" style="${gStyle}cursor:${l._pending ? 'default' : 'pointer'};align-items:flex-start">
            <div>
              <div>${kg(net)} (خالص) <span class="badge paid">فروش رفت</span>${codeBadge}${mixBadge}${gChip}${pendBadge}${traceBtn}</div>
              <div class="meta">خرید ${isoToJalaliDisplay(l.date)} · فی ${toman(l.price)}</div>
              ${l.seller ? `<div class="meta">${esc('فروشنده: ' + l.seller)}</div>` : ''}
              ${wasteLine}
            </div>
          </div>`;
          }).join('')}
        </div>`;
        }
      }
    }
    const box = document.getElementById('inv-list');
    box.innerHTML = h;
    box.querySelectorAll('.g-guide-chip').forEach(b => b.addEventListener('click', () => {
      const k = Number(b.dataset.g);
      _invGroup = (_invGroup === k) ? null : k;
      drawList();
    }));
    box.querySelectorAll('[data-trace]').forEach(b => b.addEventListener('click', (ev) => { ev.stopPropagation(); PBTrace.openLot(Number(b.dataset.trace)); })); // T6
    box.querySelectorAll('[data-id]').forEach(el => {
      const item = data.items.find(x => String(x.id) === el.dataset.id);
      if (item && item._pending) return;
      el.addEventListener('click', () => openPurchaseDetail(Number(el.dataset.id)));
    });
    const sw = document.getElementById('inv-switch');
    if (sw) sw.onclick = () => { _invView = _invView === 'available' ? 'sold' : 'available'; renderInventory(); };
    const swk = document.getElementById('inv-switch-kind');
    if (swk) swk.onclick = () => { _invKind = _invKind === 'mixed' ? 'bought' : 'mixed'; renderInventory(); };
  };
  drawList();
  document.getElementById('inv-search').addEventListener('input', (e) => { _invSearch = e.target.value; drawList(); });
  // B3: کد خوانده‌شده → اگر در لیست همین کاربر بود، جزئیات آن خرید؛ وگرنه پیام
  const invInput = document.getElementById('inv-search');
  const onInvCode = (code) => {
    _invSearch = '';
    invInput.value = '';
    drawList();
    const hit = data.items.find((x) => (x.code || '') === code);
    if (hit) openPurchaseDetail(hit.id);
    else toast('این کد در حساب شما نیست');
  };
  PBBarcode.attachWedge(invInput, onInvCode);
  document.getElementById('inv-scan').onclick = () => PBBarcode.scan(onInvCode, barcodeScanFail);
  { const cx = document.getElementById('inv-cartex'); if (cx) cx.onclick = () => PBCartex.open(); } // کارتکس لات
  c.querySelectorAll('.seg-tab[data-view]').forEach(btn => {
    btn.onclick = () => { _invView = btn.dataset.view; renderInventory(); };
  });
  c.querySelectorAll('.seg-tab[data-kind]').forEach(btn => {
    btn.onclick = () => { _invKind = btn.dataset.kind; renderInventory(); };
  });
  document.getElementById('inv-history').onclick = () => openPurchaseHistoryModal(data.items);
  fab('+', () => openPurchaseForm());
  fabSecondary('🔀', () => openMixForm());
}

/** تب «خرید» اپ اندروید: لیست تخت و جست‌وجوپذیر همهٔ خریدها (شامل موارد کاملاً فروخته‌شده) */
let _phFilter = defaultDocFilter(); // پیش‌فرض: سال پسته‌ای جاری، جدیدترین اول
function openPurchaseHistoryModal(allItems) {
  const html = `
    <h2>تاریخچهٔ کامل خرید</h2>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px"><input type="text" id="ph-search" placeholder="جستجو با کد، دسته یا نوع..." style="flex:1;padding:10px;border:1px solid var(--border);border-radius:9px"><button class="btn btn-secondary" id="ph-scan" style="width:auto;margin:0">📷 اسکن</button></div>
    <div id="ph-filter"></div>
    <div id="ph-list"></div>
    <button class="btn btn-secondary" id="ph-close">بستن</button>
  `;
  const close = openModal(html);
  document.getElementById('ph-close').onclick = close;
  let phBar = null;
  let phQuery = '';
  function draw(q) {
    if (q !== undefined) phQuery = q;
    q = normSearch(phQuery);
    const byText = !q ? allItems : allItems.filter(p => `${p.code || ''} ${p.category_name} ${p.type_name}`.toLowerCase().includes(q));
    const list = applyDocFilter(byText, _phFilter); // بازهٔ تاریخ + ترتیب
    if (phBar) phBar.setCount(list.length, allItems.length);
    const box = document.getElementById('ph-list');
    box.innerHTML = list.length ? list.map(p => `
      <div class="list-item" data-id="${p.id}" style="cursor:${p._pending ? 'default' : 'pointer'}">
        <div>
          <div class="title">${esc(p.type_name)} <span class="muted" style="font-weight:400">— ${esc(p.category_name)}</span>${Number(p.is_mix) ? ' <span class="badge">مخلوط/جداسازی</span>' : ''}${Number(p.has_photo) ? ' 📷' : ''}${p._pending ? ' <span class="badge unpaid">⏳ در صف</span>' : ''}</div>
          <div class="meta">${isoToJalaliDisplay(p.date)} · کد ${esc(p.code || '-')}${esc(p.seller ? ' · فروشنده: ' + p.seller : '')}</div>
        </div>
        <div style="text-align:left">
          <div>${kg(p.remaining)}</div>
          <div class="meta">از ${kg(p.net_weight)}</div>
        </div>
      </div>`).join('') : '<div class="empty-state">نتیجه‌ای پیدا نشد</div>';
    box.querySelectorAll('.list-item').forEach(el => {
      const item = list.find(x => String(x.id) === el.dataset.id);
      if (item && item._pending) return;
      el.addEventListener('click', () => { close(); openPurchaseDetail(Number(el.dataset.id)); });
    });
  }
  phBar = mountDocFilter('ph-filter', _phFilter, () => draw());
  draw('');
  document.getElementById('ph-search').addEventListener('input', (e) => draw(e.target.value));
  // B3: کد دقیق → همان رخداد انتخاب آیتم (کلیک روی ردیف) در همین لیست
  const phInput = document.getElementById('ph-search');
  const pickCode = (code) => {
    const hit = allItems.find((x) => (x.code || '') === code);
    if (!hit) { toast('این کد در حساب شما نیست'); return; }
    phInput.value = code;
    draw(code);
    const row = document.querySelector(`#ph-list .list-item[data-id="${hit.id}"]`);
    if (row) row.click();
    else toast('این کد در حساب شما نیست');
  };
  PBBarcode.attachWedge(phInput, pickCode);
  document.getElementById('ph-scan').onclick = () => PBBarcode.scan(pickCode, barcodeScanFail);
}

async function openPurchaseDetail(id) {
  const data = await api('purchases.get', { id });
  const p = data.item;
  const sold = Number(p.net_weight) - Number(p.remaining);
  const specs = parseCustomSpecs(p.custom_specs);
  const isMix = Number(p.is_mix) === 1; // مخلوط/جداسازی: از پسته‌های خودِ انبار ساخته شده؛ نه فاکتور دارد نه پرداخت
  // B1: بارکد فقط از کد پسته ساخته می‌شود (بدون هیچ داده‌ای دیگر)؛ کد آفلاین N… چاپ نمی‌شود
  const bcCode = PBBarcode.normalize(p.code);
  const bcBox = PBBarcode.isPrintable(bcCode)
    ? `<div style="text-align:center;margin:8px 0">${PBBarcode.svg(bcCode, { height: 48 })}</div>`
    : `<div class="muted" style="font-size:12px;margin:8px 0">${PBBarcode.note()}</div>`;
  const html = `
    <h2>${esc(p.type_name)} ${isMix ? '(مخلوط/جداسازی)' : ''}</h2>
    ${p.photo ? `<div class="photo-box" id="pd-photo-view" style="cursor:zoom-in"><img src="${safeImg(p.photo)}"></div>` : ''}
    <div class="card">
      <div class="card-row"><span>کد پسته</span><span><b>${esc(p.code || '-')}</b></span></div>
      ${bcBox}
      <div class="card-row"><span>دسته</span><span>${esc(p.category_name)}</span></div>
      <div class="card-row"><span>تاریخ</span><span>${isoToJalaliDisplay(p.date)}</span></div>
      ${p.ounce != null ? `<div class="card-row"><span>انس</span><span>${p.ounce}</span></div>` : ''}
      <div class="card-row"><span>قیمت هر کیلو</span><span>${toman(p.price)}</span></div>
      ${state.isFx && Number(p.usd_rate) > 0 ? `<div class="card-row"><span>قیمت دلار روز خرید</span><span>${toman(p.usd_rate)}</span></div><div class="card-row"><span>فی خرید به دلار (هر کیلو)</span><span>${usd(p.price_usd, 4)}</span></div>` : ''}
      <div class="card-row"><span>وزن (خالص)</span><span>${kg(p.net_weight)}</span></div>
      <div class="card-row"><span>موجودی (خالص)</span><span>${kg(p.remaining)}</span></div>
      <div class="card-row"><span>گونی کوچک (خرید)</span><span>${cnt(p.small_sack_count)}</span></div>
      <div class="card-row"><span>گونی بزرگ (خرید)</span><span>${cnt(p.large_sack_count)}</span></div>
      <div class="card-row"><span>گونی کوچک مانده در انبار</span><span>${cnt(p.small_left || 0)}</span></div>
      <div class="card-row"><span>گونی بزرگ مانده در انبار</span><span>${cnt(p.large_left || 0)}</span></div>
      ${(p.in_mix_codes || []).length ? `<div class="card-row"><span>داخل مخلوط/جداسازی (هنوز فروش نرفته)</span><span>${p.in_mix_codes.map(m => esc(m)).join('، ')}</span></div>` : ''}
      ${p.gram != null ? `<div class="card-row"><span>گرم</span><span>${p.gram}</span></div>` : ''}
      ${p.pct_khandan != null ? `<div class="card-row"><span>درصد خندان در دنبست</span><span>٪${p.pct_khandan}</span></div>` : ''}
      ${p.pct_dombest != null ? `<div class="card-row"><span>درصد دنبست در خندان</span><span>٪${p.pct_dombest}</span></div>` : ''}
      ${p.pct_tuchin != null ? `<div class="card-row"><span>درصد توچین</span><span>٪${p.pct_tuchin}</span></div>` : ''}
      ${p.pct_crooked != null ? `<div class="card-row"><span>درصد کج/ناخالص</span><span>٪${p.pct_crooked}</span></div>` : ''}
      ${specs.map(s => `<div class="card-row"><span>${esc(s.label)}</span><span>${esc(s.value)}</span></div>`).join('')}
      ${isMix && Number(p.waste) > 0 ? `<div class="card-row"><span>ضایعات/خاک</span><span>${kg(p.waste)}</span></div>` : ''}
      ${p.seller ? `<div class="card-row"><span>فروشنده</span><span>${esc(p.seller)}</span></div>` : ''}
      ${p.note ? `<div class="card-row"><span>توضیحات</span><span>${esc(p.note)}</span></div>` : ''}
    </div>
    ${isMix && p.sources ? `<div class="section-title">اجناس مصرف‌شده در این مخلوط/جداسازی</div><div class="card">${p.sources.map(s => `<div class="card-row" style="align-items:flex-start"><div><div>${esc(s.type_name)}${s.code ? ` <span class="badge code-badge">کد ${esc(s.code)}</span>` : ''}</div>${(Number(s.small_sacks) || Number(s.large_sacks)) ? `<div class="meta">گونی مصرفی: کوچک ${cnt(s.small_sacks || 0)} / بزرگ ${cnt(s.large_sacks || 0)}</div>` : ''}</div><span>${kg(s.weight)}</span></div>`).join('')}</div>` : ''}
    <div id="pd-finblk"></div>
    ${isMix ? '' : `<div class="section-title">وضعیت پرداخت</div><div class="card">${p.payment ? renderPaySummary(p.payment, Number(p.net_weight) * Number(p.price)) : '<div class="muted">هنوز اطلاعات پرداختی ثبت نشده</div>'}</div>
    <button class="btn btn-secondary" id="pd-add-pay">💳 مدیریت پرداخت‌ها</button>`}
    <button class="btn btn-secondary" id="pd-print">🖨 چاپ کارتکس</button>
    ${isMix ? '' : '<button class="btn btn-secondary" id="pd-invoice">🧾 فاکتور خرید</button>'}
    ${!p.is_mix ? '<button class="btn btn-secondary" id="pd-edit">ویرایش</button>' : `<button class="btn btn-secondary" id="pd-edit-mix" ${sold > 1e-6 ? 'disabled title="چون بخشی از این مخلوط/جداسازی فروخته شده قابل ویرایش نیست"' : ''}>ویرایش مخلوط/جداسازی</button>`}
    <button class="btn btn-danger" id="pd-delete" ${sold > 1e-6 ? 'disabled title="چون بخشی از این خرید فروخته شده قابل حذف نیست"' : ''}>حذف این خرید</button>
    <button class="btn btn-secondary" id="pd-close">بستن</button>
  `;
  const close = openModal(html);
  if (!isMix) finBlockInto('pd-finblk', 'purchase', p.id);
  document.getElementById('pd-close').onclick = close;
  document.getElementById('pd-print').onclick = () => {
    // معادل buildStockCard اپ اندروید: همان عنوان و همان اقلام
    openPrintView(`کارتکس ${esc(p.code || p.type_name)}`, `
      <h1>کارتکس انبار</h1>
      <div class="row"><span><b>کد پسته</b></span><span><b>${esc(p.code || '-')}</b></span></div>
      <div style="text-align:center;margin:8px 0">${PBBarcode.isPrintable(bcCode) ? PBBarcode.svg(bcCode, { height: 60 }) : `<p class="muted">${PBBarcode.note()}</p>`}</div>
      <div class="row"><span>وزن کل (ناخالص)</span><span>${kg(p.total_weight)}</span></div>
      <div class="row"><span>وزن (خالص)</span><span>${kg(p.net_weight)}</span></div>
      <div class="row"><span>تعداد گونی</span><span>${cnt(Number(p.small_sack_count || 0) + Number(p.large_sack_count || 0))}</span></div>
      <div class="row"><span>تعداد گونی کوچک</span><span>${cnt(p.small_sack_count)}</span></div>
      <div class="row"><span>تعداد گونی بزرگ</span><span>${cnt(p.large_sack_count)}</span></div>
    `);
  };
  const invBtn = document.getElementById('pd-invoice');
  if (invBtn) invBtn.onclick = () => openPurchaseInvoice(p);
  const photoView = document.getElementById('pd-photo-view');
  if (photoView) photoView.addEventListener('click', () => openPhotoView(p.photo));
  const editBtn = document.getElementById('pd-edit');
  if (editBtn) editBtn.onclick = () => { close(); openPurchaseForm(p); };
  const editMixBtn = document.getElementById('pd-edit-mix');
  if (editMixBtn && sold <= 1e-6) editMixBtn.onclick = () => { close(); openMixForm(p); };
  const delBtn = document.getElementById('pd-delete');
  if (sold <= 1e-6) delBtn.onclick = async () => {
    if (!confirm('حذف شود؟')) return;
    try { await api(p.is_mix ? 'mixes.delete' : 'purchases.delete', { id }); toast('حذف شد'); close(); setTab('inventory'); }
    catch (err) { toast(err.message); }
  };
  const addPayBtn = document.getElementById('pd-add-pay');
  if (addPayBtn) addPayBtn.onclick = () => { close(); openPaymentOnlyForm(id); };
}

function renderPaySummary(pay, total) {
  const type = pay.pay_type || pay.recv_type;
  const typeLabel = type === 'lump' ? 'یکجا' : (pay.staged_type === 'credit' ? 'قسطی نقدی' : 'چک');
  const list = pay.lump || pay.installments || [];
  const settled = list.filter(t => Number(t.paid)).reduce((a, t) => a + Number(t.amount || 0), 0);
  const rem = (Number(total) || 0) - settled;
  const rows = list.map(t => `<div class="card-row"><span>${isoToJalaliDisplay(t.due_date || t.date)}</span><span>${toman(t.amount)} <span class="badge ${Number(t.paid) ? 'paid' : 'unpaid'}">${Number(t.paid) ? 'تسویه‌شده' : 'مانده'}</span></span></div>`).join('');
  const sums = total != null ? `<div class="card-row"><span>تسویه‌شده</span><span>${toman(settled)}</span></div><div class="card-row"><span>${rem >= 0 ? 'باقیمانده' : 'مبلغ اضافی'}</span><span class="${rem > 0 ? 'neg' : 'pos'}">${toman(Math.abs(rem))}</span></div>` : '';
  return `<div class="card-row"><span>نوع</span><span>${typeLabel}</span></div>${rows}${sums}`;
}

function openMixForm(existing = null) {
  // برای ویرایش، موجودیِ مبدأهای قبلیِ همین مخلوط را به آن‌ها برمی‌گردانیم تا بشود دوباره تنظیم کرد
  // (دقیقاً معادل AppDb.I.lotsForMixEdit در اپ اندروید)
  const usedByThis = {};
  if (existing && existing.sources) {
    for (const s of existing.sources) {
      usedByThis[s.source_purchase_id] = {
        weight: Number(s.weight) || 0,
        small: Number(s.small_sacks) || 0,
        large: Number(s.large_sacks) || 0,
      };
    }
  }
  const lots = _purchasesCache
    .filter(p => !existing || p.id !== existing.id)
    .map(p => {
      const u = usedByThis[p.id];
      if (!u) return p;
      return { ...p, remaining: Number(p.remaining) + u.weight, small_left: Number(p.small_left || 0) + u.small, large_left: Number(p.large_left || 0) + u.large };
    })
    .filter(p => Number(p.remaining) > 0.0005);
  if (!lots.length) { toast('هیچ باری با موجودی مثبت در انبار نیست'); return; }

  const selCat0 = existing ? existing.category_id : state.categories[0]?.id;
  // ضایعاتِ مخلوط‌های قدیمی (ثبت‌نشده) = اختلاف وزن برداشته‌شده و وزن خالص، اگر مثبت باشد
  let existingWaste = '';
  if (existing) {
    if (existing.waste != null && existing.waste !== '') existingWaste = Number(existing.waste);
    else {
      const takenOld = (existing.sources || []).reduce((a, x) => a + (Number(x.weight) || 0), 0);
      const d = Math.round((takenOld - Number(existing.net_weight || 0)) * 1000) / 1000;
      existingWaste = d > 0 ? d : '';
    }
  }
  const html = `
    <h2>${existing ? 'ویرایش مخلوط/جداسازی' : 'ساخت مخلوط / جداسازی'}</h2>
    <div class="locked-note" style="background:#eef6ff;color:#0a5aa8">هزینه ضایعات به صورت میانگین از سود همه اجناس جدا/مخلوط شده کم می‌شود.</div>
    <div class="section-title">۱) انتخاب اجناس مبدأ</div>
    <input type="text" id="m-search" placeholder="جستجو..." style="width:100%;padding:10px;border:1px solid var(--border);border-radius:9px;margin-bottom:8px">
    <button type="button" class="tag" id="m-select-all">✓ انتخاب همهٔ اجناس</button>
    <div id="m-lots" style="margin-top:8px"></div>
    <div id="m-rest"></div>
    <div class="error" id="m-error"></div>
  `;
  const close = openModal(html);

  const sel = {}; // pid -> true
  if (existing) for (const pid of Object.keys(usedByThis)) sel[pid] = true;
  let showAvg = false; // «مشاهده میانگین کوت‌ریزی» (فقط نمایش)

  function drawLots(q) {
    q = (q || '').trim().toLowerCase();
    const box = document.getElementById('m-lots');
    const visible = !q ? lots : lots.filter(l => sel[l.id] || `${l.code || ''} ${l.category_name} ${l.type_name}`.toLowerCase().includes(q));
    box.innerHTML = visible.map(l => `
      <div class="check-row" data-pid="${l.id}">
        <input type="checkbox" class="m-chk" ${sel[l.id] ? 'checked' : ''}>
        <div style="flex:1"><div>${esc(l.category_name)} - ${esc(l.type_name)}</div><div class="meta">کد ${esc(l.code || '-')} · موجودی (خالص) ${kg(l.remaining)} · فی ${toman(l.price)}${state.isFx && l.price_usd != null ? ` (${usd(l.price_usd, 3)})` : ''}</div></div>
      </div>`).join('');
    box.querySelectorAll('.m-chk').forEach(chk => chk.addEventListener('change', (e) => {
      const pid = Number(e.target.closest('.check-row').dataset.pid);
      sel[pid] = e.target.checked;
      drawRest();
    }));
  }
  document.getElementById('m-search').addEventListener('input', (e) => drawLots(e.target.value));
  document.getElementById('m-select-all').addEventListener('click', () => {
    lots.forEach(l => { sel[l.id] = true; });
    drawLots(document.getElementById('m-search').value);
    drawRest();
  });

  function selectedLots() { return lots.filter(l => sel[l.id]); }
  function sourceRowValues(pid) {
    return {
      weight: Number(document.getElementById('m-w-' + pid) ? document.getElementById('m-w-' + pid).value || 0 : 0),
      small: document.getElementById('m-sm-' + pid) ? document.getElementById('m-sm-' + pid).value : '',
      large: document.getElementById('m-lg-' + pid) ? document.getElementById('m-lg-' + pid).value : '',
    };
  }
  function takenSum() { return selectedLots().reduce((a, l) => a + sourceRowValues(l.id).weight, 0); }

  function drawRest() {
    const s = selectedLots();
    const box = document.getElementById('m-rest');
    if (!s.length) { box.innerHTML = ''; return; }
    const prevVals = {};
    s.forEach(l => { prevVals[l.id] = sourceRowValues(l.id); });
    // مقدارهای قسمت ۳ (با تغییر انتخاب اجناس) از بین نروند
    const keep = {};
    ['m-type', 'm-net', 'm-waste', 'm-small', 'm-large', 'm-ounce', 'm-gram', 'm-note'].forEach(id => {
      const el = document.getElementById(id); if (el) keep[id] = el.value;
    });
    const kv = (id, dflt) => (id in keep ? keep[id] : dflt);
    box.innerHTML = `
      <div class="section-title">۲) چقدر از هرکدام برمی‌دارید</div>
      ${s.map(l => {
        const u = usedByThis[l.id];
        const wv = prevVals[l.id].weight || (u ? u.weight : '');
        const smv = prevVals[l.id].small !== '' ? prevVals[l.id].small : (u ? u.small : '');
        const lgv = prevVals[l.id].large !== '' ? prevVals[l.id].large : (u ? u.large : '');
        return `
        <div class="card" data-pid="${l.id}">
          <b>${esc(l.category_name)} - ${esc(l.type_name)}</b>${l.code ? ` <span class="badge code-badge">کد ${esc(l.code)}</span>` : ''}
          <div class="meta">موجودی (خالص) ${kg(l.remaining)}</div>
          <button type="button" class="tag" id="m-fill-${l.id}" style="margin:6px 0">∞ همهٔ موجودی</button>
          <div class="field"><label>وزنی که از این جنس برمی‌دارید (خالص) به کیلو *</label><input type="number" inputmode="decimal" id="m-w-${l.id}" value="${wv}"></div>
          <div class="row2">
            <div class="field"><label>گونی کوچک مصرف‌شده (اختیاری) — حداکثر ${Number(l.small_left || 0)}</label><input type="number" inputmode="decimal" id="m-sm-${l.id}" placeholder="0" min="0" max="${Number(l.small_left || 0)}" value="${smv}"></div>
            <div class="field"><label>گونی بزرگ مصرف‌شده (اختیاری) — حداکثر ${Number(l.large_left || 0)}</label><input type="number" inputmode="decimal" id="m-lg-${l.id}" placeholder="0" min="0" max="${Number(l.large_left || 0)}" value="${lgv}"></div>
          </div>
        </div>`;
      }).join('')}
      <div class="card"><div class="card-row"><span>جمع وزن برداشته‌شده (خالص)</span><span id="m-sum">${kg(takenSum())}</span></div></div>
      <label class="card check-row" style="display:flex;align-items:center;gap:8px;cursor:pointer;border-top:0">
        <input type="checkbox" id="m-avg-chk" style="width:auto" ${showAvg ? 'checked' : ''}>
        <span><b>مشاهده میانگین کوت‌ریزی</b><br><span class="meta">میانگین گرم، انس و قیمت اجناس انتخاب‌شده، بر اساس وزن هرکدام</span></span>
      </label>
      <div id="m-avg"></div>
      <div class="section-title">۳) مشخصات قلم تازه (مخلوط/جداسازی)</div>
      <div class="field"><label>دسته</label><div class="tag-row" id="m-cats">${categoryTags(selCatVal)}</div>
        <button type="button" class="tag" id="m-cat-add">+ دستهٔ جدید</button>
      </div>
<div class="field"><label>نوع پسته (زیردسته) * <span class="muted" style="font-size:11px">— مشخص می‌کند این قلم در نمودارها جزو کدام نوع پسته حساب شود</span></label><div class="tag-row" id="m-bases"></div></div>
      <div class="field"><label>نام قلم تازه (مثلاً «کوت‌ریز» یا «دنبست جداشده») *</label><input type="text" id="m-type" value="${esc(kv('m-type', existing ? existing.type_name : ''))}"></div>
      <div class="field"><label>وزن نهایی (خالص) به کیلو — طبق ترازو *</label><input type="number" inputmode="decimal" id="m-net" value="${kv('m-net', existing ? existing.net_weight : '')}"></div>
      <div class="field"><label>ضایعات / خاک (کیلو) — وزنِ کم‌شده هنگام مخلوط/جداسازی</label><input type="number" inputmode="decimal" id="m-waste" placeholder="0" min="0" value="${kv('m-waste', existingWaste)}">
        <button type="button" class="tag" id="m-waste-fill" style="margin-top:6px">⚖️ ثبت اختلاف به‌عنوان ضایعات</button>
      </div>
      <div id="m-balance" class="card" style="font-size:13.5px"></div>
      <div class="row2">
        <div class="field"><label>تعداد گونی کوچک (۱۰۰ گرمی)</label><input type="number" inputmode="decimal" id="m-small" value="${kv('m-small', existing ? existing.small_sack_count : 0)}"></div>
        <div class="field"><label>تعداد گونی بزرگ (۲۰۰ گرمی)</label><input type="number" inputmode="decimal" id="m-large" value="${kv('m-large', existing ? existing.large_sack_count : 0)}"></div>
      </div>
      <div class="row2">
        <div class="field"><label>انس (اختیاری)</label><input type="number" inputmode="decimal" id="m-ounce" value="${kv('m-ounce', existing && existing.ounce != null ? existing.ounce : '')}"></div>
        <div class="field"><label>گرم (اختیاری)</label><input type="number" inputmode="decimal" id="m-gram" value="${kv('m-gram', existing && existing.gram != null ? existing.gram : '')}"></div>
      </div>
      <div class="field"><label>توضیحات (اختیاری)</label><textarea id="m-note" rows="2">${esc(kv('m-note', existing ? (existing.note || '') : ''))}</textarea></div>
      <button class="btn btn-primary" id="m-submit">${existing ? 'ذخیرهٔ تغییرات' : 'ثبت در انبار'}</button>
      <button class="btn btn-secondary" id="m-cancel">انصراف</button>
    `;
    s.forEach(l => {
      document.getElementById('m-w-' + l.id).addEventListener('input', updateSumDisplay);
      document.getElementById('m-fill-' + l.id).addEventListener('click', () => {
        document.getElementById('m-w-' + l.id).value = Math.round(Number(l.remaining) * 1000) / 1000;
        document.getElementById('m-sm-' + l.id).value = l.small_left || 0;
        document.getElementById('m-lg-' + l.id).value = l.large_left || 0;
        updateSumDisplay();
      });
    });
    function updateSumDisplay() {
      const el = document.getElementById('m-sum');
      if (el) el.textContent = kg(takenSum());
      updateBalance();
      updateAvg();
    }
    // میانگین وزنیِ گرم و انس (همان فرمول «محاسبه کوت‌ریزی» دستیار): Σ(مقدار·وزن) ÷ Σوزن
    // جنسی که گرم/انس ندارد، میانگینِ همان مقدار را غیرقابل‌محاسبه می‌کند.
    function updateAvg() {
      const box = document.getElementById('m-avg'); if (!box) return;
      if (!showAvg) { box.innerHTML = ''; return; }
      let sumW = 0, sumGW = 0, sumOW = 0, sumPW = 0;
      const noGram = [], noOunce = [], noPrice = [];
      selectedLots().forEach(l => {
        const w = sourceRowValues(l.id).weight;
        if (!(w > 0)) return;
        const name = `${l.category_name} - ${l.type_name}`;
        const g = (l.gram == null || l.gram === '') ? null : Number(l.gram);
        const o = (l.ounce == null || l.ounce === '') ? null : Number(l.ounce);
        const pr = (l.price == null || l.price === '') ? null : Number(l.price);
        sumW += w;
        if (pr == null || !isFinite(pr) || pr <= 0) noPrice.push(name); else sumPW += pr * w;
        if (g == null || !isFinite(g)) noGram.push(name); else sumGW += g * w;
        if (o == null || !isFinite(o) || o <= 0) noOunce.push(name); else sumOW += o * w;
      });
      if (!(sumW > 0)) { box.innerHTML = '<div class="card meta">وزنِ برداشته‌شده از هر جنس را وارد کنید تا میانگین محاسبه شود.</div>'; return; }
      const f2 = (x) => (Math.round(x * 100) / 100).toLocaleString('fa-IR');
      const val = (ok, x) => ok ? `<b>${f2(x)}</b>` : '<b class="neg">قابل محاسبه نیست</b>';
      box.innerHTML = `<div class="card">
        <div class="card-row"><span>میانگین گرم</span>${val(!noGram.length, sumGW / sumW)}</div>
        <div class="card-row"><span>میانگین انس</span>${val(!noOunce.length, sumOW / sumW)}</div>
        <div class="card-row"><span>میانگین قیمت (فی)</span>${noPrice.length ? '<b class="neg">قابل محاسبه نیست</b>' : `<b>${toman(sumPW / sumW)}</b>`}</div>
        ${noGram.length ? `<div class="neg" style="margin-top:6px">⚠ میانگین گرم قابل محاسبه نیست؛ این اجناس گرم ندارند: ${noGram.map(esc).join('، ')}</div>` : ''}
        ${noOunce.length ? `<div class="neg" style="margin-top:6px">⚠ میانگین انس قابل محاسبه نیست؛ این اجناس انس ندارند: ${noOunce.map(esc).join('، ')}</div>` : ''}
        ${noPrice.length ? `<div class="neg" style="margin-top:6px">⚠ میانگین قیمت قابل محاسبه نیست؛ این اجناس قیمت ندارند: ${noPrice.map(esc).join('، ')}</div>` : ''}
        <div class="meta" style="margin-top:6px">میانگین وزنی بر اساس وزنِ برداشته‌شده از هر جنس (${kg(sumW)}).</div>
      </div>`;
    }
    document.getElementById('m-avg-chk').addEventListener('change', (e) => { showAvg = e.target.checked; updateAvg(); });
    const numVal = (id) => Number((document.getElementById(id) || {}).value || 0);
    const r3 = (x) => Math.round(x * 1000) / 1000;
    // وزن برداشته‌شده = وزن خالص + ضایعات
    function updateBalance() {
      const el = document.getElementById('m-balance'); if (!el) return;
      const taken = r3(takenSum()), net = numVal('m-net'), waste = numVal('m-waste');
      const diff = r3(taken - net - waste);
      let msg;
      if (Math.abs(diff) <= 0.0015) msg = '<span class="pos">✓ وزن‌ها برابرند</span>';
      else if (diff > 0) msg = `<span class="neg">${kg(diff)} اختلاف ثبت‌نشده دارید؛ اگر خاک یا ضایعات بوده در «ضایعات» وارد کنید</span>`;
      else msg = `<span class="neg">وزن (خالص) + ضایعات به اندازهٔ ${kg(-diff)} از وزن برداشته‌شده بیشتر است</span>`;
      el.innerHTML = `<div class="card-row"><span>برداشته‌شده (خالص)</span><span>${kg(taken)}</span></div>
        <div class="card-row"><span>وزن (خالص) + ضایعات</span><span>${kg(r3(net + waste))}</span></div>
        <div>${msg}</div>`;
    }
    ['m-net', 'm-waste'].forEach(id => document.getElementById(id).addEventListener('input', updateBalance));
    document.getElementById('m-waste-fill').addEventListener('click', () => {
      const d = r3(takenSum() - numVal('m-net'));
      document.getElementById('m-waste').value = d > 0 ? d : 0;
      updateBalance();
    });
    updateBalance();
    updateAvg();

    document.getElementById('m-cats').addEventListener('click', (e) => {
      const t = e.target.closest('[data-cid]'); if (!t) return;
      selCatVal = Number(t.dataset.cid);
      document.querySelectorAll('#m-cats .tag').forEach(x => x.classList.toggle('active', Number(x.dataset.cid) === selCatVal));
selBase = ''; drawBases();
    });
    document.getElementById('m-cat-add').onclick = async (e) => {
      e.preventDefault();
      const name = prompt('نام دستهٔ جدید:');
      if (!name || !name.trim()) return;
      try {
        const res = await api('categories.create', { name: name.trim() });
        state.categories.push({ id: res.id, name: name.trim(), preset_key: null });
        selCatVal = res.id;
        document.getElementById('m-cats').innerHTML = categoryTags(selCatVal);
selBase = ''; drawBases();
      } catch (err) { toast(err.message); }
    };
drawBases();
    document.getElementById('m-cancel').onclick = close;
    document.getElementById('m-submit').onclick = submit;
  }

  let selCatVal = selCat0;
let selBase = existing ? (existing.base_type || '') : ''; // نوع زیردسته (برای نمودارهای دقیق‌تر)
function drawBases() {
  const box = document.getElementById('m-bases'); if (!box) return;
  const list = state.types.filter(t => t.category_id === selCatVal);
  box.innerHTML = list.map(t => `<span class="tag ${t.name === selBase ? 'active' : ''}" data-name="${esc(t.name)}">${esc(t.name)}</span>`).join('')
    + '<button type="button" class="tag" id="m-base-add">+ نوع جدید</button>';
  box.querySelectorAll('[data-name]').forEach(el => el.addEventListener('click', () => { selBase = el.dataset.name; drawBases(); }));
  document.getElementById('m-base-add').onclick = async (e) => {
    e.preventDefault();
    const name = prompt('نام نوع جدید (مثلاً دنبست):');
    if (!name || !name.trim()) return;
    try {
      const res = await api('types.create', { category_id: selCatVal, name: name.trim() });
      state.types.push({ id: res.id, category_id: selCatVal, name: name.trim() });
      selBase = name.trim(); drawBases();
    } catch (err) { toast(err.message); }
  };
}
drawLots('');
  drawRest();

  async function submit() {
    const errEl = document.getElementById('m-error'); errEl.textContent = '';
    const s = selectedLots();
    if (!s.length) { errEl.textContent = 'حداقل یک جنس مبدأ را انتخاب کنید'; return; }
    const items = [];
    for (const l of s) {
      const v = sourceRowValues(l.id);
      if (v.weight <= 0) { errEl.textContent = `وزن (خالص) «${l.type_name}» را وارد کنید`; return; }
      if (v.weight > l.remaining + 1e-6) { errEl.textContent = `وزن (خالص) «${l.type_name}» از موجودی آن بیشتر است`; return; }
      if (v.small !== '' && Number(v.small) > Number(l.small_left || 0) + 1e-6) { errEl.textContent = `گونی کوچک «${l.type_name}» از موجودی آن (${Number(l.small_left || 0)}) بیشتر است`; return; }
      if (v.large !== '' && Number(v.large) > Number(l.large_left || 0) + 1e-6) { errEl.textContent = `گونی بزرگ «${l.type_name}» از موجودی آن (${Number(l.large_left || 0)}) بیشتر است`; return; }
      items.push({ purchase_id: l.id, weight: v.weight, small_sacks: v.small === '' ? null : Number(v.small), large_sacks: v.large === '' ? null : Number(v.large) });
    }
    if (!selCatVal) { errEl.textContent = 'دسته را انتخاب کنید'; return; }
    const typeName = document.getElementById('m-type').value.trim();
    if (!selBase) { errEl.textContent = 'نوع پسته (زیردسته) را انتخاب کنید'; return; }
if (!typeName) { errEl.textContent = 'نام قلم تازه را وارد کنید'; return; }
    const net = Number(document.getElementById('m-net').value || 0);
    if (net <= 0) { errEl.textContent = 'وزن نهایی (خالص، بعد از توزین) را وارد کنید'; return; }
    const waste = Number(document.getElementById('m-waste').value || 0);
    if (waste < 0) { errEl.textContent = 'وزن ضایعات نمی‌تواند منفی باشد'; return; }
    const takenW = Math.round(takenSum() * 1000) / 1000;
    const diffW = Math.round((takenW - net - waste) * 1000) / 1000;
    if (Math.abs(diffW) > 0.0015) {
      errEl.textContent = diffW > 0
        ? `وزن برداشته‌شده ${takenW} کیلو است ولی وزن (خالص) + ضایعات فقط ${Math.round((net + waste) * 1000) / 1000} کیلو می‌شود؛ ${diffW} کیلو اختلاف دارید. اگر خاک یا ضایعات بوده در «ضایعات» وارد کنید`
        : `وزن (خالص) + ضایعات ${Math.round((net + waste) * 1000) / 1000} کیلو از وزن برداشته‌شده (${takenW} کیلو) بیشتر است`;
      return;
    }
    const payload = {
      category_id: selCatVal, type_name: typeName, base_type: selBase, sources: items, net_weight: net, waste,
      small_sack_count: Number(document.getElementById('m-small').value || 0),
      large_sack_count: Number(document.getElementById('m-large').value || 0),
      ounce: document.getElementById('m-ounce').value === '' ? null : Number(document.getElementById('m-ounce').value),
      gram: document.getElementById('m-gram').value === '' ? null : Number(document.getElementById('m-gram').value),
      note: document.getElementById('m-note').value.trim(),
    };
    if (existing) payload.id = existing.id;
    try {
      await api(existing ? 'mixes.update' : 'mixes.create', payload);
      toast(existing ? 'مخلوط/جداسازی ویرایش شد' : 'با موفقیت به انبار اضافه شد');
      close(); setTab('inventory');
    } catch (err) { errEl.textContent = err.message; }
  }
}

function categoryTags(selectedId, onSelect) {
  return state.categories.map(c => `<span class="tag ${c.id === selectedId ? 'active' : ''}" data-cid="${c.id}">${esc(c.name)}</span>`).join('');
}

function parseCustomSpecs(json) {
  if (!json) return [];
  try { const v = JSON.parse(json); return Array.isArray(v) ? v : []; } catch { return []; }
}

// ---------------------------------------------------------------- S35.T35.6a: بلوک مالی فرم‌ها (فقط وقتی تنظیم form.fin_block روشن است)
async function finBlockOn() {
  try {
    const d = await api('form_settings.get');
    const f = (d.features || []).find(x => x.key === 'fin_block');
    return !!(f && f.on);
  } catch (e) { return false; }
}
function finFormHtml(prefix, existing) {
  const disc = existing ? (existing.discount_amount ?? existing.discount) : null;
  const wb = existing ? existing.weighbridge_cost : null;
  const payer = existing && existing.side_cost_payer === 'party' ? 'party' : 'us';
  const tx = existing && existing.taxable != null ? String(Number(existing.taxable)) : '';
  return `<div class="section-title">بلوک مالی</div>
    <div class="field"><label>تخفیف (ریال) — اختیاری</label><input type="text" id="${prefix}-disc" inputmode="decimal" value="${disc ? esc(String(disc)) : ''}"></div>
    <div class="field"><label>هزینهٔ باسکول (ریال) — اختیاری</label><input type="text" id="${prefix}-wb" inputmode="decimal" value="${wb ? esc(String(wb)) : ''}"></div>
    <div class="field"><label>هزینه‌های جانبی بر عهدهٔ</label>
      <select id="${prefix}-payer"><option value="us" ${payer === 'us' ? 'selected' : ''}>ما</option><option value="party" ${payer === 'party' ? 'selected' : ''}>طرف حساب</option></select></div>
    <div class="field"><label>وضعیت مالیاتی</label>
      <select id="${prefix}-taxable"><option value="" ${tx === '' ? 'selected' : ''}>تعیین نشده</option><option value="1" ${tx === '1' ? 'selected' : ''}>مشمول مالیات</option><option value="0" ${tx === '0' ? 'selected' : ''}>معاف</option></select></div>`;
}
function finMountForm(prefix, existing) {
  finBlockOn().then(on => {
    const box = document.getElementById(prefix + '-fin');
    if (!on || !box) return;
    box.innerHTML = finFormHtml(prefix, existing);
    attachThousands(prefix + '-disc');
    attachThousands(prefix + '-wb');
  });
}
function finPayload(prefix) {
  const payer = document.getElementById(prefix + '-payer');
  if (!payer) return {};
  const t = document.getElementById(prefix + '-taxable').value;
  return {
    discount_amount: numVal(prefix + '-disc'),
    weighbridge_cost: numVal(prefix + '-wb'),
    side_cost_payer: payer.value,
    taxable: t === '' ? null : t === '1',
  };
}
async function finBlockInto(boxId, docType, id) {
  const box = document.getElementById(boxId);
  if (!box || !(await finBlockOn())) return;
  try {
    const b = await api('fin.block', { doc_type: docType, id });
    const row = (k, v) => `<div class="card-row"><span>${k}</span><span>${toman(v)}</span></div>`;
    box.innerHTML = `<div class="section-title">بلوک مالی</div><div class="card">
      ${row('ارزش کالا', b.goods)}${row('تخفیف', b.discount)}${row('مبلغ قبل از مالیات', b.before_tax)}${row('مالیات بر ارزش افزوده', b.tax)}
      ${row('هزینه کارگر', b.side_costs.labor)}${row('حمل‌ونقل', b.side_costs.transport)}${row('باسکول', b.side_costs.weighbridge)}
      <div class="card-row"><span>هزینه‌های جانبی بر عهدهٔ</span><span>${b.side_cost_payer === 'party' ? 'طرف حساب' : 'ما'}</span></div>
      <div class="card-row"><span><b>مبلغ کل</b></span><span><b>${toman(b.grand_total)}</b></span></div>
      <div class="muted" style="margin-top:6px">${esc(b.words)}</div></div>`;
  } catch (err) { box.innerHTML = ''; }
}

function openPurchaseForm(existing = null) {
  const catId0 = existing ? existing.category_id : state.categories[0]?.id;
  let selCat = catId0;
  // اگر بخشی از این خرید قبلاً فروخته شده باشد، دسته/نوع/وزن/مشخصات کیفی قابل ویرایش نیست
  const locked = !!(existing && (Number(existing.net_weight) - Number(existing.remaining)) > 1e-6);
  const existingSpecs = existing ? parseCustomSpecs(existing.custom_specs) : [];

  const html = `
    <h2>${existing ? 'ویرایش خرید' : 'ثبت خرید جدید'}</h2>
    ${locked ? `<div class="locked-note">چون بخشی از این خرید فروخته شده، دسته/نوع/وزن و مشخصات کیفی قابل تغییر نیست. فقط فی، فروشنده، عکس و توضیحات قابل ویرایش‌اند.</div>` : ''}
    <div class="${locked ? 'locked-section' : ''}">
      <div class="field"><label>دسته</label><div class="tag-row" id="p-cats">${categoryTags(selCat)}</div>
        <button type="button" class="tag" id="p-cat-add">+ دسته جدید</button>
      </div>
      <div class="field"><label>نوع</label><div class="tag-row" id="p-types"></div>
        <input type="text" id="p-type-custom" placeholder="یا نام نوع دلخواه را بنویسید" value="${esc(existing ? existing.type_name : '')}">
      </div>
    </div>
    <div class="field"><label>تاریخ</label><div id="p-date"></div></div>
    <div class="section-title">عکس رسید (اختیاری)</div>
    <div class="photo-box" id="p-photo"></div>
    <div class="${locked ? 'locked-section' : ''}">
      <div class="row2">
        <div class="field"><label>انس پسته (اختیاری)</label><input type="number" id="p-ounce" inputmode="decimal" value="${existing && existing.ounce != null ? existing.ounce : ''}"></div>
        <div class="field"><label>گرم (اختیاری)</label><input type="number" id="p-gram" inputmode="decimal" value="${existing && existing.gram != null ? existing.gram : ''}"></div>
      </div>
      <div class="field"><label>وزن کل (ناخالص) به کیلو</label><input type="number" id="p-total" inputmode="decimal" value="${existing ? existing.total_weight : ''}"></div>
      <div class="row2">
        <div class="field"><label>تعداد گونی کوچک (۱۰۰ گرمی)</label><input type="number" id="p-small" inputmode="decimal" value="${existing ? existing.small_sack_count : 0}"></div>
        <div class="field"><label>تعداد گونی بزرگ (۲۰۰ گرمی)</label><input type="number" id="p-large" inputmode="decimal" value="${existing ? existing.large_sack_count : 0}"></div>
      </div>
    </div>
    <div class="field"><label>قیمت هر کیلو (ریال) *</label><input type="text" id="p-price" value="${existing ? existing.price : ''}"></div>
    ${state.isFx ? `<div class="field"><label>قیمت دلار در زمان خرید (ریال) ${locked ? '' : '*'}</label><input type="text" id="p-rate" inputmode="decimal" value="${existing && Number(existing.usd_rate) > 0 ? formatThousandsLive(String(Number(existing.usd_rate))) : ''}"></div>` : fxLockedHtml('قیمت دلار در زمان خرید')}
    <div class="card" id="p-calc" style="margin:6px 0 12px"></div>
    <div class="${locked ? 'locked-section' : ''}" id="p-quality"></div>
    <div class="field"><label>فروشنده</label><input type="text" id="p-seller" placeholder="نام فروشنده" value="${esc(existing ? (existing.seller||'') : '')}"></div>
    <div class="field"><label>توضیحات</label><textarea id="p-note" rows="2">${esc(existing ? (existing.note||'') : '')}</textarea></div>
<div class="field"><label>هزینه کارگر (ریال) — اختیاری</label><input type="text" id="p-labor" inputmode="decimal" value="${existing && existing.labor_cost != null ? esc(String(existing.labor_cost)) : ''}"><div class="muted" style="font-size:12px">اگر وارد شود از سود این پسته کم می‌شود</div></div>
<div class="field"><label>هزینه حمل‌ونقل / باسکول (ریال) — اختیاری</label><input type="text" id="p-transport" inputmode="decimal" value="${existing && existing.transport_cost != null ? esc(String(existing.transport_cost)) : ''}"><div class="muted" style="font-size:12px">اگر وارد شود از سود این پسته کم می‌شود</div></div>
    <div id="p-fin"></div>
    ${existing ? '' : `
    <div class="field"><label>نحوهٔ پرداخت</label>
      <div class="tag-row" id="p-pay-mode">
        <span class="tag active" data-v="none">پرداخت نشده</span>
        <span class="tag" data-v="lump">یکجا</span>
        <span class="tag" data-v="credit">قسطی نقدی</span>
        <span class="tag" data-v="cheque">چک</span>
      </div>
    </div>
    <div id="p-pay-fields"></div>`}

    <div class="error" id="p-error"></div>
    <button class="btn btn-primary" id="p-submit">${existing ? 'ذخیرهٔ تغییرات' : 'ثبت خرید'}</button>
    <button class="btn btn-secondary" id="p-cancel">انصراف</button>
  `;
  const close = openModal(html);
  mountJalaliDate('p-date', existing ? existing.date : todayStr());
  attachThousands('p-price');
  attachThousands('p-labor');
  attachThousands('p-transport');
  finMountForm('p', existing);
  if (state.isFx) attachThousands('p-rate');
  attachPartyDatalist('p-seller');
  const photoPicker = mountPhotoPicker('p-photo', existing ? existing.photo : null);

  document.getElementById('p-cat-add').onclick = async (e) => {
    e.preventDefault();
    if (locked) return;
    const name = prompt('نام دستهٔ جدید:');
    if (!name || !name.trim()) return;
    try {
      const res = await api('categories.create', { name: name.trim() });
      state.categories.push({ id: res.id, name: name.trim(), preset_key: null });
      selCat = res.id;
      document.getElementById('p-cats').innerHTML = categoryTags(selCat);
      typeTagsFor(selCat);
      renderQualityFields();
    } catch (err) { toast(err.message); }
  };

  function typeTagsFor(catId) {
    const list = state.types.filter(t => t.category_id === catId);
    document.getElementById('p-types').innerHTML = list.map(t => `<span class="tag ${esc(existing && t.name === existing.type_name ? 'active' : '')}" data-tid="${t.id}" data-name="${esc(t.name)}">${esc(t.name)}</span>`).join('')
      + `<button type="button" class="tag" id="p-type-add">+ نوع جدید</button>`;
    document.getElementById('p-type-add').onclick = async (e) => {
      e.preventDefault();
      if (locked) return;
      const name = prompt('نام نوع جدید:');
      if (!name || !name.trim()) return;
      try {
        const res = await api('types.create', { category_id: catId, name: name.trim() });
        state.types.push({ id: res.id, category_id: catId, name: name.trim() });
        typeTagsFor(catId);
      } catch (err) { toast(err.message); }
    };
  }
  typeTagsFor(selCat);
  document.getElementById('p-cats').addEventListener('click', (e) => {
    if (locked) return;
    const t = e.target.closest('[data-cid]'); if (!t) return;
    selCat = Number(t.dataset.cid);
    document.querySelectorAll('#p-cats .tag[data-cid]').forEach(x => x.classList.toggle('active', Number(x.dataset.cid) === selCat));
    typeTagsFor(selCat);
    renderQualityFields();
  });
  let selTypeName = existing ? existing.type_name : '';
  document.getElementById('p-types').addEventListener('click', (e) => {
    if (locked) return;
    const t = e.target.closest('[data-tid]'); if (!t) return;
    selTypeName = t.dataset.name;
    document.getElementById('p-type-custom').value = '';
    document.querySelectorAll('#p-types .tag[data-tid]').forEach(x => x.classList.toggle('active', x === t));
  });

  // ---------------- مشخصات کیفی بر اساس نوع دسته (دنبست / خندان / دلخواه) ----------------
  const customSpecRows = existingSpecs.length ? existingSpecs.map(s => ({ ...s })) : [];
  function presetKeyOf(catId) { return (state.categories.find(c => c.id === catId) || {}).preset_key || null; }
  function renderQualityFields() {
    const preset = presetKeyOf(selCat);
    const box = document.getElementById('p-quality');
    if (preset === 'dombest') {
      box.innerHTML = `
        <div class="section-title">مشخصات پسته دنبست</div>
        <div class="row2">
          <div class="field"><label>درصد خندان در دنبست</label><input type="number" id="p-pct-khandan" max="100" value="${existing && existing.pct_khandan != null ? existing.pct_khandan : ''}"></div>
          <div class="field"><label>درصد پسته کج / ناخالص</label><input type="number" id="p-pct-crooked" max="100" value="${existing && existing.pct_crooked != null ? existing.pct_crooked : ''}"></div>
        </div>`;
    } else if (preset === 'khandan') {
      box.innerHTML = `
        <div class="section-title">مشخصات پسته خندان</div>
        <div class="row2">
          <div class="field"><label>درصد دنبست در خندان</label><input type="number" id="p-pct-dombest" max="100" value="${existing && existing.pct_dombest != null ? existing.pct_dombest : ''}"></div>
          <div class="field"><label>درصد توچین</label><input type="number" id="p-pct-tuchin" max="100" value="${existing && existing.pct_tuchin != null ? existing.pct_tuchin : ''}"></div>
        </div>
        <div class="field"><label>درصد پسته کج / ناخالص</label><input type="number" id="p-pct-crooked" max="100" value="${existing && existing.pct_crooked != null ? existing.pct_crooked : ''}"></div>`;
    } else {
      box.innerHTML = `
        <div class="section-title">مشخصات (حداکثر ۴ مورد دلخواه)</div>
        <div id="p-specs"></div>
        <button type="button" class="tag" id="p-spec-add">+ افزودن مشخصه</button>`;
      renderSpecRows();
      document.getElementById('p-spec-add').onclick = (e) => {
        e.preventDefault();
        if (customSpecRows.length >= 4) { toast('حداکثر ۴ مشخصهٔ دلخواه'); return; }
        customSpecRows.push({ label: '', value: '' });
        renderSpecRows();
      };
    }
  }
  function renderSpecRows() {
    const wrap = document.getElementById('p-specs');
    if (!wrap) return;
    wrap.innerHTML = customSpecRows.map((s, i) => `
      <div class="spec-row" data-i="${i}">
        <input type="text" class="spec-label" placeholder="عنوان" value="${esc(s.label || '')}">
        <input type="text" class="spec-value" placeholder="مقدار" value="${esc(s.value || '')}">
        <button type="button" class="spec-remove">✕</button>
      </div>`).join('');
    wrap.querySelectorAll('.spec-row').forEach(row => {
      const i = Number(row.dataset.i);
      row.querySelector('.spec-label').addEventListener('input', (e) => customSpecRows[i].label = e.target.value);
      row.querySelector('.spec-value').addEventListener('input', (e) => customSpecRows[i].value = e.target.value);
      row.querySelector('.spec-remove').addEventListener('click', (e) => { e.preventDefault(); customSpecRows.splice(i, 1); renderSpecRows(); });
    });
  }
  renderQualityFields();

  let payMode = 'none';
  function renderPayFields() {
    const box = document.getElementById('p-pay-fields');
    if (payMode === 'none') { box.innerHTML = ''; return; }
    if (payMode === 'lump') {
      box.innerHTML = `
        <div class="row2">
          <div class="field"><label>مبلغ</label><input type="text" id="pf-amount"></div>
          <div class="field"><label>تاریخ پرداخت</label><div id="pf-date"></div></div>
        </div>
        <div class="field"><label>پرداخت شده؟</label>
          <div class="tag-row"><span class="tag active" id="pf-paid-yes">بله</span><span class="tag" id="pf-paid-no">خیر</span></div>
        </div>`;
      mountJalaliDate('pf-date', todayStr()); attachThousands('pf-amount');
      let paidYes = true;
      document.getElementById('pf-paid-yes').onclick = () => { paidYes = true; document.getElementById('pf-paid-yes').classList.add('active'); document.getElementById('pf-paid-no').classList.remove('active'); };
      document.getElementById('pf-paid-no').onclick = () => { paidYes = false; document.getElementById('pf-paid-no').classList.add('active'); document.getElementById('pf-paid-yes').classList.remove('active'); };
      box._collect = () => ({ pay_type: 'lump', lump: [{ amount: numVal('pf-amount'), date: dateVal('pf-date'), paid: paidYes }] });
    } else {
      box.innerHTML = `
        <div class="row2">
          <div class="field"><label>مبلغ قسط</label><input type="text" id="pf-amount"></div>
          <div class="field"><label>تاریخ سررسید</label><div id="pf-date"></div></div>
        </div>
        ${payMode === 'cheque' ? `<div class="row2"><div class="field"><label>شماره چک</label><input type="text" id="pf-cheque"></div><div class="field"><label>نام بانک</label><input type="text" id="pf-bank"></div></div>` : ''}
        <div class="muted" style="font-size:12px">می‌توانید بعداً از صفحهٔ سررسیدها اقساط بیشتری مدیریت کنید.</div>`;
      mountJalaliDate('pf-date', todayStr()); attachThousands('pf-amount');
      box._collect = () => ({
        pay_type: 'staged', staged_type: payMode,
        installments: [{
          amount: numVal('pf-amount'),
          due_date: dateVal('pf-date'),
          cheque_no: payMode === 'cheque' ? document.getElementById('pf-cheque').value : undefined,
          bank_name: payMode === 'cheque' ? document.getElementById('pf-bank').value : undefined,
          paid: false,
        }],
      });
    }
  }
  if (document.getElementById('p-pay-mode')) {
    document.getElementById('p-pay-mode').addEventListener('click', (e) => {
      const t = e.target.closest('[data-v]'); if (!t) return;
      payMode = t.dataset.v;
      document.querySelectorAll('#p-pay-mode .tag').forEach(x => x.classList.toggle('active', x === t));
      renderPayFields();
    });
  }

  function calc() {
    const total = Number(document.getElementById('p-total').value || 0);
    const small = Number(document.getElementById('p-small').value || 0);
    const large = Number(document.getElementById('p-large').value || 0);
    const price = numVal('p-price');
    const rate = state.isFx ? numVal('p-rate') : 0;
    const tare = small * 0.1 + large * 0.2;
    const net = total - tare;
    document.getElementById('p-calc').innerHTML = `
      <div class="card-row"><span>وزن گونی‌ها</span><span>${kg(tare)}</span></div>
      <div class="card-row"><span>وزن (خالص)</span><span>${kg(net)}</span></div>
      <div class="card-row"><span>ارزش کل</span><span>${toman(net * price)}</span></div>
      ${rate > 0 && price > 0 ? `<div class="card-row"><span>فی خرید به دلار (هر کیلو)</span><span>${usd(price / rate, 4)}</span></div>
      <div class="card-row"><span>ارزش کل به دلار</span><span>${usd(net * price / rate)}</span></div>` : ''}`;
    return net;
  }
  ['p-total', 'p-small', 'p-large', 'p-price', ...(state.isFx ? ['p-rate'] : [])].forEach(id => document.getElementById(id).addEventListener('input', calc));
  calc();

  document.getElementById('p-cancel').onclick = close;
  document.getElementById('p-submit').onclick = async () => {
    const errEl = document.getElementById('p-error');
    errEl.textContent = '';
    const typeName = document.getElementById('p-type-custom').value.trim() || selTypeName;
    if (!typeName) { errEl.textContent = 'نوع پسته را انتخاب یا وارد کنید'; return; }
    if (numVal('p-price') <= 0) { errEl.textContent = 'قیمت خرید را وارد کنید'; return; }
    if (state.isFx && !locked && !(numVal('p-rate') > 0)) { errEl.textContent = 'قیمت دلار روز خرید را وارد کنید'; return; }
    for (const el of document.querySelectorAll('#p-total, #p-small, #p-large, #p-ounce, #p-gram, [id^="p-pct-"]')) {
      if (el.value !== '' && Number(el.value) < 0) { errEl.textContent = 'عدد معتبر وارد کنید (منفی مجاز نیست)'; return; }
      if (el.id.startsWith('p-pct-') && el.value !== '' && Number(el.value) > 100) { errEl.textContent = 'درصد نمی‌تواند بیشتر از ۱۰۰ باشد'; return; }
    }
    const net = calc();
    if (net <= 0) { errEl.textContent = 'وزن (خالص) باید بزرگ‌تر از صفر باشد'; return; }
    const preset = presetKeyOf(selCat);
    const numOrNull = (id) => { const v = document.getElementById(id); if (!v || v.value === '') return null; return Number(v.value); };
    const payload = {
      category_id: selCat, type_name: typeName, date: dateVal('p-date'),
      ounce: numOrNull('p-ounce'),
      gram: numOrNull('p-gram'),
      price: numVal('p-price'),
      ...(state.isFx ? { usd_rate: numVal('p-rate') || null } : {}),
      total_weight: Number(document.getElementById('p-total').value || 0),
      small_sack_count: Number(document.getElementById('p-small').value || 0),
      large_sack_count: Number(document.getElementById('p-large').value || 0),
      pct_khandan: preset === 'dombest' ? numOrNull('p-pct-khandan') : null,
      pct_dombest: preset === 'khandan' ? numOrNull('p-pct-dombest') : null,
      pct_tuchin: preset === 'khandan' ? numOrNull('p-pct-tuchin') : null,
      pct_crooked: (preset === 'dombest' || preset === 'khandan') ? numOrNull('p-pct-crooked') : null,
      custom_specs: (!preset) ? customSpecRows.filter(s => (s.label || '').trim() !== '') : null,
      photo: photoPicker.get(),
      seller: document.getElementById('p-seller').value.trim(),
      note: document.getElementById('p-note').value.trim(),
        labor_cost: numVal('p-labor') || null,
        transport_cost: numVal('p-transport') || null,
        ...finPayload('p'),
    };
    if (existing) payload.id = existing.id;
    const box = document.getElementById('p-pay-fields');
    if (!existing && payMode !== 'none' && box && box._collect) payload.payment = box._collect();
    if (!existing && !(payload.small_sack_count > 0) && !(payload.large_sack_count > 0)) {
      if (!confirm('شما تعداد را وارد نکردید؛ گونیِ انبار برای این خرید اضافه نخواهد شد و فقط وزن (خالص) به انبار اضافه میشود. مطمئنید؟')) return;
    }
    try {
      await api('purchases.create', payload);
      toast(existing ? 'ذخیره شد' : 'خرید ثبت شد');
      close(); setTab('inventory');
    } catch (err) { errEl.textContent = err.message; }
  };
}

// ---------------------------------------------------------------- فروش

let _saleSearch = '';
let _saleFilter = defaultDocFilter(); // پیش‌فرض: سال پسته‌ای جاری، جدیدترین اول

async function renderSales() {
  const data = await api('sales.list');
  const c = document.getElementById('content');
  if (!data.items.length) {
    c.innerHTML = '<div class="empty-state">هنوز فروشی ثبت نشده</div>';
    fab('+', () => openSaleForm());
    return;
  }
  c.innerHTML = `<input type="search" id="sale-search" class="search-input" placeholder="جستجو با کد پسته (مثلاً KH00012)، نوع یا خریدار..." value="${esc(_saleSearch)}"><div id="sale-filter"></div><div id="sale-list"></div>`;
  let saleBar = null;
  const draw = () => {
    const q = normSearch(_saleSearch);
    const byText = !q ? data.items : data.items.filter(s =>
      `${s.buyer || ''} ${(s.items || []).map(it => `${it.code || ''} ${it.type_name || ''} ${it.category_name || ''}`).join(' ')}`.toLowerCase().includes(q));
    const list = applyDocFilter(byText, _saleFilter); // بازهٔ تاریخ + ترتیب
    if (saleBar) saleBar.setCount(list.length, data.items.length);
    const box = document.getElementById('sale-list');
    if (!list.length) { box.innerHTML = '<div class="empty-state">فروشی با این مشخصات پیدا نشد</div>'; return; }
    box.innerHTML = list.map(s => {
      const net = Number(s.net_weight) || 0, total = Number(s.total_weight) || 0;
      const tare = Math.max(0, total - net);
      const small = Number(s.small_sack_count) || 0, large = Number(s.large_sack_count) || 0;
      const sacks = '';
      const comp = (s.items || []).map(it => `${esc(it.type_name || '')}${it.code ? ` <span class="badge code-badge">کد ${esc(it.code)}</span>` : ''}`).join(' + ');
      return `
      <div class="card" data-id="${s.id}" style="cursor:${s._pending ? 'default' : 'pointer'}">
        <div><b>${isoToJalaliDisplay(s.date)}${esc(s.buyer ? ' • ' + s.buyer : '')}</b> ${s._pending ? '<span class="badge unpaid">⏳ در صف</span>' : ''}</div>
        <div class="meta">${comp}</div>
        <div class="card-row"><span>وزن پسته (خالص)</span><span>${kg(net)}</span></div>
        ${(small + large) > 0 ? `<div class="card-row"><span>گونی کوچک کم‌شده از انبار</span><span>${cnt(small)}</span></div><div class="card-row"><span>گونی بزرگ کم‌شده از انبار</span><span>${cnt(large)}</span></div>` : '<div class="card-row"><span>گونی کم‌شده از انبار</span><span>ثبت نشده</span></div>'}
        <div class="card-row"><span>وزن کل (ناخالص - با گونی)</span><span>${kg(total)}${tare > 0 ? sacks : ''}</span></div>
        <div class="card-row"><span>فی فروش</span><span>${toman(s.price)}</span></div>
        <div class="card-row"><span>مبلغ فروش</span><span>${toman(net * Number(s.price))}</span></div>
        ${state.isFx ? usdSaleRowsHtml(s) : ''}
      </div>`;
    }).join('');
    box.querySelectorAll('[data-id]').forEach(el => {
      const item = data.items.find(x => String(x.id) === el.dataset.id);
      if (item && item._pending) return;
      el.addEventListener('click', () => openSaleDetail(Number(el.dataset.id)));
    });
  };
  saleBar = mountDocFilter('sale-filter', _saleFilter, draw);
  draw();
  document.getElementById('sale-search').addEventListener('input', (e) => { _saleSearch = e.target.value; draw(); });
  fab('+', () => openSaleForm());
}

async function openSaleDetail(id) {
  const data = await api('sales.get', { id });
  const s = data.item;
  // B2: بارکد هر کد پسته‌ی این فروش (کدهای N… بارکد ندارند)
  const saleBcCodes = [...new Set((s.items || []).map(it => PBBarcode.normalize(it.code)).filter(c => PBBarcode.isPrintable(c)))];
  const saleBcHtml = saleBcCodes.length ? `<div style="text-align:center;margin:8px 0">${saleBcCodes.map(c => `<div style="display:inline-block;margin:0 8px;vertical-align:top">${PBBarcode.svg(c, { height: 44 })}</div>`).join('')}</div>` : '';
  const html = `
    <h2>فروش به ${esc(s.buyer || 'بدون نام')}</h2>
    <div class="card">
      <div class="card-row"><span>تاریخ</span><span>${isoToJalaliDisplay(s.date)}</span></div>
      <div class="card-row"><span>وزن (خالص)</span><span>${kg(s.net_weight)}</span></div>
      ${(Number(s.small_sack_count) + Number(s.large_sack_count)) > 0 ? `<div class="card-row"><span>گونی کوچک کم‌شده از انبار</span><span>${cnt(s.small_sack_count)}</span></div><div class="card-row"><span>گونی بزرگ کم‌شده از انبار</span><span>${cnt(s.large_sack_count)}</span></div>` : '<div class="card-row"><span>گونی کم‌شده از انبار</span><span>ثبت نشده</span></div>'}
      <div class="card-row"><span>قیمت میانگین</span><span>${toman(s.price)}</span></div>
      <div class="card-row"><span>ارزش کل</span><span>${toman(s.price * s.net_weight)}</span></div>
      ${state.isFx ? usdSaleRowsHtml(s) : ''}
      ${s.note ? `<div class="card-row"><span>توضیحات</span><span>${esc(s.note)}</span></div>` : ''}
    </div>
    <div class="section-title">اقلام فروخته‌شده</div>
    <div class="card">${s.items.map(it => `<div class="card-row"><span>${esc(it.type_name)} (${esc(it.code||'')})</span><span>${kg(it.weight)}${it.item_price ? ' · فی ' + toman(it.item_price) : ''}</span></div>`).join('')}</div>
    ${saleBcHtml}
    <div id="sd-finblk"></div>
    <div class="section-title">وضعیت دریافت وجه</div><div class="card">${s.receipt ? renderPaySummary(s.receipt, Number(s.net_weight) * Number(s.price)) : '<div class="muted">هنوز اطلاعات دریافتی ثبت نشده</div>'}</div>
    <button class="btn btn-secondary" id="sd-add-recv">💰 وصول مطالبات (مدیریت دریافت‌ها)</button>
    <button class="btn btn-secondary" id="sd-print">🖨 چاپ فاکتور فروش</button>
    ${s.items.length > 1 ? '<div class="pending-note">این فروش قدیمیِ چندقلمی است و قابل ویرایش نیست؛ فقط می‌توانید حذفش کنید و برای هر جنس یک فروش جدا ثبت کنید.</div>' : '<button class="btn btn-secondary" id="sd-edit">ویرایش وزن/گونی این فروش</button>'}
    <button class="btn btn-danger" id="sd-delete">حذف این فروش (بازگشت به انبار)</button>
    <button class="btn btn-secondary" id="sd-close">بستن</button>
  `;
  const close = openModal(html);
  finBlockInto('sd-finblk', 'sale', s.id);
  document.getElementById('sd-close').onclick = close;
  document.getElementById('sd-print').onclick = () => openSaleInvoice(s);
  const editSaleBtn = document.getElementById('sd-edit');
  if (editSaleBtn) editSaleBtn.onclick = () => { close(); openSaleForm(s); };
  document.getElementById('sd-delete').onclick = async () => {
    if (!confirm('این فروش حذف و وزن آن به انبار برگردانده شود؟')) return;
    try { await api('sales.delete', { id }); toast('حذف شد'); close(); setTab('sales'); }
    catch (err) { toast(err.message); }
  };
  const addRecvBtn = document.getElementById('sd-add-recv');
  if (addRecvBtn) addRecvBtn.onclick = () => { close(); openReceiptOnlyForm(id); };
}

async function openSaleForm(existing = null) {
  const invData = await api('purchases.list');
  const byId = new Map(invData.items.filter(p => !p._pending).map(p => [p.id, p]));
  // هر فروش فقط یک قلم دارد. فروش‌های قدیمیِ چندقلمی قابل ویرایش نیستند.
  const exItem = existing && existing.items && existing.items.length === 1 ? existing.items[0] : null;
  if (existing && !exItem) { toast('این فروش قدیمیِ چندقلمی است و قابل ویرایش نیست؛ آن را حذف و برای هر جنس فروش جدا ثبت کنید'); return; }
  // لیست قابل‌انتخاب = اقلامِ دارای موجودی + قلمی که همین فروش از آن برداشته (موجودیِ آن با وزن/گونی همین فروش جمع می‌شود)
  const lotIds = new Set();
  byId.forEach((p, id) => { if (Number(p.remaining) > 0.001) lotIds.add(id); });
  if (exItem) lotIds.add(exItem.purchase_id);
  const lots = [...lotIds].map(pid => {
    const base = byId.get(pid);
    const isEx = !!exItem && exItem.purchase_id === pid;
    return {
      id: pid,
      type_name: base ? base.type_name : (isEx ? exItem.type_name : `#${pid}`),
      category_name: base ? (base.category_name || '') : '',
      code: (base && base.code) || (isEx && exItem.code) || '',
      priceUsd: base && base.price_usd != null && base.price_usd !== '' ? Number(base.price_usd) : null, // فی دلاریِ خرید (پلن ارزی)
      remaining: (base ? Number(base.remaining) : 0) + (isEx ? Number(exItem.weight) || 0 : 0),
      smallRem: (base ? Number(base.small_sacks_remaining) || 0 : 0) + (isEx && exItem.small_sacks != null ? Number(exItem.small_sacks) : 0),
      largeRem: (base ? Number(base.large_sacks_remaining) || 0 : 0) + (isEx && exItem.large_sacks != null ? Number(exItem.large_sacks) : 0),
    };
  });
  if (!lots.length) { toast('موجودی انبار برای فروش وجود ندارد'); return; }
  let selPid = exItem ? exItem.purchase_id : null;

  const html = `
    <h2>${existing ? 'ویرایش فروش' : 'ثبت فروش جدید'}</h2>
    <div class="locked-note" style="background:#eef6ff;color:#0a5aa8">هر فروش فقط از یک قلم انبار است. اگر خریدار چند جنس می‌خرد، برای هر جنس یک فروش (فاکتور) جدا ثبت کنید.</div>
    <div class="field"><label>خریدار</label><input type="text" id="s-buyer" value="${esc(existing ? (existing.buyer||'') : '')}"></div>
    <div class="field"><label>تاریخ</label><div id="s-date"></div></div>
    <div class="section-title">۱) قلم فروخته‌شده را از انبار انتخاب کنید</div>
    ${existing ? '' : '<input type="search" id="s-search" class="search-input" placeholder="جستجو با کد پسته (مثلاً KH00012)، نوع یا دسته...">'}
    <div id="s-lots"></div>
    <div class="section-title">۲) وزن و گونی</div>
    <div class="field"><label>وزن فروش (خالص) — وزن واقعی طبق ترازو (کیلو) *</label>
      <input type="number" inputmode="decimal" step="any" id="s-weight" value="${exItem ? exItem.weight : ''}">
      <button type="button" class="tag" id="s-fill" style="margin-top:6px">∞ همهٔ موجودی این قلم</button>
    </div>
    <div class="row2">
      <div class="field"><label>گونی کوچک برداشته‌شده (اختیاری)</label><input type="number" inputmode="decimal" step="any" min="0" id="s-small" placeholder="اختیاری" value="${exItem && exItem.small_sacks != null ? exItem.small_sacks : ''}"><div class="meta" id="s-small-hint"></div></div>
      <div class="field"><label>گونی بزرگ برداشته‌شده (اختیاری)</label><input type="number" inputmode="decimal" step="any" min="0" id="s-large" placeholder="اختیاری" value="${exItem && exItem.large_sacks != null ? exItem.large_sacks : ''}"><div class="meta" id="s-large-hint"></div></div>
    </div>
    <div class="card" id="s-calc"></div>
    <div class="section-title">۳) فی فروش</div>
    <div class="field"><label>فی فروش (ریال به ازای هر کیلو) *</label><input type="text" id="s-price" value="${exItem ? formatThousandsLive(String(exItem.item_price || existing.price)) : ''}"></div>
    ${state.isFx ? `<div class="field"><label>قیمت دلار در زمان فروش (ریال) *</label><input type="text" id="s-rate" inputmode="decimal" value="${existing && Number(existing.usd_rate) > 0 ? formatThousandsLive(String(Number(existing.usd_rate))) : ''}"></div>` : fxLockedHtml('قیمت دلار در زمان فروش')}
    <div class="field"><label>توضیحات</label><textarea id="s-note" rows="2">${esc(existing ? (existing.note||'') : '')}</textarea></div>
<div class="field"><label>هزینه کارگر (ریال) — اختیاری</label><input type="text" id="s-labor" inputmode="decimal" value="${existing && existing.labor_cost != null ? esc(String(existing.labor_cost)) : ''}"><div class="muted" style="font-size:12px">اگر وارد شود از سود این فروش کم می‌شود</div></div>
<div class="field"><label>هزینه حمل‌ونقل / باسکول (ریال) — اختیاری</label><input type="text" id="s-transport" inputmode="decimal" value="${existing && existing.transport_cost != null ? esc(String(existing.transport_cost)) : ''}"><div class="muted" style="font-size:12px">اگر وارد شود از سود این فروش کم می‌شود</div></div>

    <div id="s-fin"></div>
    ${existing ? '<div class="pending-note">برای تغییر وضعیت دریافت وجه از دکمهٔ «وصول مطالبات» در صفحهٔ جزئیات فروش استفاده کنید.</div>' : `
    <div class="field"><label>نحوهٔ دریافت وجه</label>
      <div class="tag-row" id="s-recv-mode">
        <span class="tag active" data-v="none">دریافت نشده</span>
        <span class="tag" data-v="lump">یکجا</span>
        <span class="tag" data-v="credit">قسطی نقدی</span>
        <span class="tag" data-v="cheque">چک</span>
      </div>
    </div>
    <div id="s-recv-fields"></div>`}

    <div class="error" id="s-error"></div>
    <button class="btn btn-primary" id="s-submit">${existing ? 'ذخیرهٔ تغییرات' : 'ثبت فروش'}</button>
    <button class="btn btn-secondary" id="s-cancel">انصراف</button>
  `;
  const close = openModal(html);
  mountJalaliDate('s-date', existing ? existing.date : todayStr());
  attachPartyDatalist('s-buyer');
  attachThousands('s-price');
  attachThousands('s-labor');
  attachThousands('s-transport');
  if (state.isFx) attachThousands('s-rate');
  finMountForm('s', existing);

  const selLot = () => lots.find(l => l.id === selPid) || null;
  const fmtN = (n) => String(Math.round(n * 100) / 100);

  function drawLots() {
    const q = normSearch(document.getElementById('s-search') ? document.getElementById('s-search').value : '');
    const visible = lots.filter(l => existing ? l.id === selPid : (l.id === selPid || !q || `${l.code} ${l.category_name} ${l.type_name}`.toLowerCase().includes(q)));
    const box = document.getElementById('s-lots');
    box.innerHTML = visible.length ? visible.map(l => `
      <div class="check-row" data-pid="${l.id}" style="cursor:${existing ? 'default' : 'pointer'}">
        <input type="radio" name="s-lot" class="s-radio" ${l.id === selPid ? 'checked' : ''} ${existing ? 'disabled' : ''}>
        <div style="flex:1">
          <div>${esc(l.category_name ? l.category_name + ' - ' : '')}${esc(l.type_name)}${l.code ? ` <span class="badge code-badge">کد ${esc(l.code)}</span>` : ''}</div>
          <div class="meta">${kg(l.remaining)} موجود · گونی مانده: کوچک ${cnt(l.smallRem)} / بزرگ ${cnt(l.largeRem)}${state.isFx && l.priceUsd != null ? ` · فی خرید ${usd(l.priceUsd, 3)}` : ''}</div>
        </div>
      </div>`).join('') : '<div class="empty-state">قلمی با این مشخصات پیدا نشد</div>';
    if (!existing) box.querySelectorAll('.check-row').forEach(row => row.addEventListener('click', () => {
      selPid = Number(row.dataset.pid);
      drawLots(); applyLotLimits(); calcSale();
    }));
  }
  const searchEl = document.getElementById('s-search');
  if (searchEl) searchEl.addEventListener('input', drawLots);

  /** سقف گونی = دقیقاً گونی باقی‌ماندهٔ همین خرید؛ بیشتر از آن قابل‌تایپ نیست */
  function applyLotLimits() {
    const lot = selLot();
    for (const [id, hintId, max, label] of [['s-small', 's-small-hint', lot ? lot.smallRem : 0, 'کوچک'], ['s-large', 's-large-hint', lot ? lot.largeRem : 0, 'بزرگ']]) {
      const el = document.getElementById(id), hint = document.getElementById(hintId);
      if (!lot) { el.disabled = true; el.value = ''; hint.textContent = 'ابتدا قلم را انتخاب کنید'; continue; }
      el.max = String(max);
      if (max <= 0) { el.value = ''; el.disabled = true; hint.textContent = `گونی ${label} باقی‌مانده‌ای ندارد`; }
      else {
        el.disabled = false;
        hint.textContent = `حداکثر ${cnt(max)} (باقی‌ماندهٔ این خرید)`;
        if (el.value !== '' && Number(el.value) > max) el.value = fmtN(max);
      }
    }
  }
  function clampSack(id, label) {
    const lot = selLot(); const el = document.getElementById(id);
    if (!lot || el.value === '') return;
    const max = id === 's-small' ? lot.smallRem : lot.largeRem;
    let v = Number(el.value);
    if (v < 0) { el.value = ''; return; }
    if (v > max + 1e-9) { el.value = fmtN(max); toast(`گونی ${label} نمی‌تواند از ${cnt(max)} (باقی‌ماندهٔ این خرید) بیشتر باشد`); }
  }
  function calcSale() {
    const w = Number(document.getElementById('s-weight').value || 0);
    const small = Number(document.getElementById('s-small').value || 0);
    const large = Number(document.getElementById('s-large').value || 0);
    const tare = small * 0.1 + large * 0.2;
    const price = numVal('s-price');
    const revenue = w * price;
    const rate = state.isFx ? numVal('s-rate') : 0;
    const lot = selLot();
    let usdRows = '';
    if (state.isFx && rate > 0 && price > 0) {
      usdRows = `<div class="card-row"><span>فی فروش به دلار (هر کیلو)</span><span>${usd(price / rate, 4)}</span></div>
        <div class="card-row"><span>مبلغ فروش به دلار</span><span>${usd(revenue / rate)}</span></div>`;
    }
    document.getElementById('s-calc').innerHTML = `
      <div class="card-row"><span>وزن فروش (خالص)</span><span>${kg(w)}</span></div>
      <div class="card-row"><span>وزن کل (ناخالص - با گونی)</span><span>${kg(w + tare)}</span></div>
      <div class="card-row"><span>مبلغ فروش تقریبی</span><span>${toman(revenue)}</span></div>${usdRows}`;
  }
  document.getElementById('s-weight').addEventListener('input', calcSale);
  document.getElementById('s-price').addEventListener('input', calcSale);
  if (state.isFx) document.getElementById('s-rate').addEventListener('input', calcSale);
  document.getElementById('s-small').addEventListener('input', () => { clampSack('s-small', 'کوچک'); calcSale(); });
  document.getElementById('s-large').addEventListener('input', () => { clampSack('s-large', 'بزرگ'); calcSale(); });
  document.getElementById('s-fill').addEventListener('click', () => {
    const lot = selLot(); if (!lot) { toast('ابتدا قلم را انتخاب کنید'); return; }
    document.getElementById('s-weight').value = Math.round(lot.remaining * 1000) / 1000;
    calcSale();
  });
  drawLots(); applyLotLimits(); calcSale();

  let recvMode = 'none';
  function renderRecvFields() {
    const box = document.getElementById('s-recv-fields');
    if (!box) return;
    if (recvMode === 'none') { box.innerHTML = ''; return; }
    if (recvMode === 'lump') {
      box.innerHTML = `
        <div class="row2">
          <div class="field"><label>مبلغ</label><input type="text" id="rf-amount"></div>
          <div class="field"><label>تاریخ دریافت</label><div id="rf-date"></div></div>
        </div>`;
      mountJalaliDate('rf-date', todayStr()); attachThousands('rf-amount');
      box._collect = () => ({ recv_type: 'lump', lump: [{ amount: numVal('rf-amount'), date: dateVal('rf-date'), paid: true }] });
    } else {
      box.innerHTML = `
        <div class="row2">
          <div class="field"><label>مبلغ قسط</label><input type="text" id="rf-amount"></div>
          <div class="field"><label>تاریخ سررسید</label><div id="rf-date"></div></div>
        </div>
        ${recvMode === 'cheque' ? `<div class="row2"><div class="field"><label>شماره چک</label><input type="text" id="rf-cheque"></div><div class="field"><label>نام بانک</label><input type="text" id="rf-bank"></div></div>` : ''}`;
      mountJalaliDate('rf-date', todayStr()); attachThousands('rf-amount');
      box._collect = () => ({
        recv_type: 'staged', staged_type: recvMode,
        installments: [{
          amount: numVal('rf-amount'),
          due_date: dateVal('rf-date'),
          cheque_no: recvMode === 'cheque' ? document.getElementById('rf-cheque').value : undefined,
          bank_name: recvMode === 'cheque' ? document.getElementById('rf-bank').value : undefined,
          paid: false,
        }],
      });
    }
  }
  if (document.getElementById('s-recv-mode')) {
    document.getElementById('s-recv-mode').addEventListener('click', (e) => {
      const t = e.target.closest('[data-v]'); if (!t) return;
      recvMode = t.dataset.v;
      document.querySelectorAll('#s-recv-mode .tag').forEach(x => x.classList.toggle('active', x === t));
      renderRecvFields();
    });
  }

  document.getElementById('s-cancel').onclick = close;
  document.getElementById('s-submit').onclick = async () => {
    const errEl = document.getElementById('s-error');
    errEl.textContent = '';
    const lot = selLot();
    if (!lot) { errEl.textContent = 'قلم فروخته‌شده را از انبار انتخاب کنید'; return; }
    const w = Number(document.getElementById('s-weight').value || 0);
    if (!(w > 0)) { errEl.textContent = 'وزن فروش (خالص) باید بزرگ‌تر از صفر باشد'; return; }
    if (w > lot.remaining + 1e-6) { errEl.textContent = 'وزن فروش (خالص) از موجودی این قلم بیشتر است'; return; }
    const price = numVal('s-price');
    if (!(price > 0)) { errEl.textContent = 'فی فروش را وارد کنید'; return; }
    if (state.isFx && !(numVal('s-rate') > 0)) { errEl.textContent = 'قیمت دلار روز فروش را وارد کنید'; return; }
    const smallRaw = document.getElementById('s-small').value, largeRaw = document.getElementById('s-large').value;
    const small = smallRaw === '' ? null : Number(smallRaw);
    const large = largeRaw === '' ? null : Number(largeRaw);
    if ((small !== null && (small < 0 || small > lot.smallRem + 1e-6)) || (large !== null && (large < 0 || large > lot.largeRem + 1e-6))) {
      errEl.textContent = 'تعداد گونی نمی‌تواند از گونی باقی‌ماندهٔ این خرید بیشتر باشد'; return;
    }
    if (small === null && large === null) {
      if (!confirm('شما تعداد گونی خالی‌شده را وارد نکردید؛ گونیِ انبار برای این خرید کم نخواهد شد. مطمئنید؟')) return;
    }
    const payload = {
      date: dateVal('s-date'),
      price,
      ...(state.isFx ? { usd_rate: numVal('s-rate') } : {}),
      buyer: document.getElementById('s-buyer').value.trim(),
      note: document.getElementById('s-note').value.trim(),
        labor_cost: numVal('s-labor') || null,
        transport_cost: numVal('s-transport') || null,
        ...finPayload('s'),
      purchase_id: lot.id,
      weight: w,
      item_price: price,
      small_sacks: small,
      large_sacks: large,
    };
    if (existing) payload.id = existing.id;
    const box = document.getElementById('s-recv-fields');
    if (!existing && recvMode !== 'none' && box && box._collect) payload.receipt = box._collect();
    try {
      await api(existing ? 'sales.update' : 'sales.create', payload);
      toast(existing ? 'ذخیره شد' : 'فروش ثبت شد');
      close(); setTab('sales');
    } catch (err) { errEl.textContent = err.message; }
  };
}

// ---------------------------------------------------------------- طرف‌حساب‌ها

async function renderParties() {
  const data = await api('parties.list');
  data.items.forEach(partyDefaults);
  const c = document.getElementById('content');
  const totalOwe = data.items.reduce((a, p) => a + (p.weOwe > 0 ? p.weOwe : 0), 0);
  const totalOwed = data.items.reduce((a, p) => a + (p.theyOwe > 0 ? p.theyOwe : 0), 0);
  const loanRecv = data.items.reduce((a, p) => a + (p.loanReceivable > 0 ? p.loanReceivable : 0), 0);
  const loanPay = data.items.reduce((a, p) => a + (p.loanPayable > 0 ? p.loanPayable : 0), 0);
  const anyLoans = data.items.some(p => p.loans.length);
  c.innerHTML = `
    <div class="card">
      <div class="card-row"><span>جمع طلب‌ها (از خریدارها)</span><span class="pos">${toman(totalOwed)}</span></div>
      <div class="card-row"><span>جمع بدهی‌ها (به فروشنده‌ها)</span><span class="neg">${toman(totalOwe)}</span></div>
      ${loanRecv > 0.5 || loanPay > 0.5 ? `<div class="card-row"><span>مانده طلب قرضی</span><span class="pos">${toman(loanRecv)}</span></div>
      <div class="card-row"><span>مانده بدهی قرضی</span><span class="neg">${toman(loanPay)}</span></div>` : ''}
      ${anyLoans ? '<button class="btn btn-secondary" id="loan-pdf-all" style="margin-top:8px">🖨 PDF دفتر قرض همهٔ طرف‌حساب‌ها</button>' : ''}
    </div>
    ${cashFlowCardHtml('cf-main')}
    <input type="text" id="party-search" placeholder="جستجوی نام..." style="width:100%;padding:10px;border:1px solid var(--border);border-radius:9px;margin-bottom:10px">
    <div id="party-list"></div>
  `;
  mountCashFlow('cf-main', { party: null, accounts: () => data.items });
  document.querySelectorAll('.fab').forEach(f => f.remove());
  fab('+', () => openNewPartyForm(data.items.map(x => x.key), () => renderParties()));
  const pdfAll = document.getElementById('loan-pdf-all');
  if (pdfAll) pdfAll.onclick = () => openLoanPrint(data.items);
  function draw(filter) {
    const list = data.items.filter(p => !filter || (p.key || 'بدون نام').includes(filter));
    document.getElementById('party-list').innerHTML = list.length ? list.map(p => {
      const total = p.totalNet;
      const settled = Math.abs(total) < 1;
      const color = settled ? 'var(--muted)' : (total > 0 ? 'var(--green)' : 'var(--red)');
      const lines = [p.bought > 0 ? 'خرید ' + cnt(p.bought) : '', p.sold > 0 ? 'فروش ' + cnt(p.sold) : '',
        Math.abs(p.loanReceivable) > 0.5 ? 'طلب قرضی ' + cnt(p.loanReceivable) : '',
        Math.abs(p.loanPayable) > 0.5 ? 'بدهی قرضی ' + cnt(p.loanPayable) : ''].filter(Boolean).join('  •  ');
      return `<div class="list-item" data-party="${esc(p.key)}" style="cursor:pointer">
        <div><div class="title">${esc(p.key || 'بدون نام')}</div><div class="meta">${lines || 'بدون تراکنش'}</div></div>
        <div style="text-align:left;color:${color};font-weight:700">${settled ? 'تسویه' : cnt(Math.abs(total))}<div style="font-size:11px;font-weight:400">${settled ? '' : (total > 0 ? 'طلب ما' : 'بدهی ما')}</div></div>
      </div>`;
    }).join('') : '<div class="empty-state">هنوز طرف‌حسابی نیست.<br>با دکمهٔ «+» یکی تعریف کنید یا خرید/فروش ثبت کنید.</div>';
    document.querySelectorAll('#party-list .list-item').forEach(el => el.addEventListener('click', () => openPartyDetail(el.dataset.party, data.items)));
  }
  draw('');
  document.getElementById('party-search').addEventListener('input', (e) => draw(e.target.value.trim()));
}

async function openPartyDetail(party, cachedList) {
  let a = (cachedList || []).find(x => x.key === party);
  if (!a) { try { const d = await api('parties.list'); d.items.forEach(partyDefaults); a = d.items.find(x => x.key === party); } catch (err) { toast(err.message); return; } }
  if (!a) { toast('یافت نشد'); return; }
  partyDefaults(a);
  const settled = Math.abs(a.net) < 1;
  const entry = (e) => {
    const rem = e.total - e.settled;
    const open = rem > 0.5;
    return `<div class="card" data-kind="${e.is_purchase ? 'p' : 's'}" data-id="${e.id}" style="cursor:pointer">
      <div><b>${e.is_purchase ? '🛒 خرید' : '🏷 فروش'} ${esc(e.label)} • ${cnt(e.weight)} کیلو (خالص)</b></div>
      <div class="meta">${isoToJalaliDisplay(e.date)}</div>
      <div class="meta" style="${open ? 'color:var(--red)' : ''}">مبلغ ${cnt(e.total)} • تسویه‌شده ${cnt(e.settled)}${open ? ' • مانده ' + cnt(rem) : ' • تسویه کامل'}</div>
    </div>`;
  };
  const hasLoans = a.loans.length > 0;
  const loanNet = a.loanNet, loanSettled = Math.abs(loanNet) < 1;
  const loanItem = (l) => `<div class="list-item" style="margin-bottom:6px"><div>
      <div class="title" style="color:${l.isIn || loanIsIn(l.kind) ? 'var(--green)' : '#d9822b'}">${loanIsIn(l.kind) ? '↙' : '↗'} ${LOAN_LABEL[l.kind] || esc(l.kind)} • ${cnt(l.amount)} ریال</div>
      <div class="meta">${isoToJalaliDisplay(l.date)}${l.note ? ' • ' + esc(l.note) : ''}${l._pending ? ' • ⏳ در صف' : ''}</div></div>
      ${l._pending ? '' : `<button type="button" class="tag" data-del-loan="${esc(l.uid)}">حذف</button>`}</div>`;
  const dueItem = (d) => {
    const given = d.kind === 'given';
    return `<div class="list-item" style="margin-bottom:6px"><div>
      <div class="title">${given ? 'وصول' : 'بازپرداخت'} ${cnt(d.amount)} ریال${d.note ? ' • ' + esc(d.note) : ''}</div>
      <div class="meta" style="${!d.paid && daysUntilIso(d.due_date) < 0 ? 'color:var(--red)' : ''}">${isoToJalaliDisplay(d.due_date)} • ${d.paid ? (given ? 'دریافت شد ✓' : 'پرداخت شد ✓') : dueWhen(d.due_date)}</div></div>
      ${d.paid || d._pending || d.id == null ? '' : `<button type="button" class="tag" data-due-paid="${d.id}">${given ? 'دریافت شد' : 'پرداخت شد'}</button>`}</div>`;
  };
  const loanCard = party ? `<div class="card">
      <b>🤝 دفتر قرض و وام</b>
      <div class="muted" style="font-size:12px;margin:2px 0 8px">این مبالغ در سود و زیان حساب نمی‌شوند.</div>
      ${hasLoans ? `${a.loanGiven > 0 ? `<div class="card-row"><span>قرض اعطایی (به او داده‌ایم)</span><span>${toman(a.loanGiven)}</span></div>` : ''}
        ${a.loanTaken > 0 ? `<div class="card-row"><span>قرض دریافتی (از او گرفته‌ایم)</span><span>${toman(a.loanTaken)}</span></div>` : ''}
        ${a.repaidToUs > 0 ? `<div class="card-row"><span>بازپرداخت دریافتی</span><span>${toman(a.repaidToUs)}</span></div>` : ''}
        ${a.repaidByUs > 0 ? `<div class="card-row"><span>بازپرداخت پرداختی</span><span>${toman(a.repaidByUs)}</span></div>` : ''}
        <div class="card-row"><b>${loanSettled ? 'مانده قرض' : (loanNet > 0 ? 'مانده طلب قرضی (او به ما بدهکار است)' : 'مانده بدهی قرضی (ما به او بدهکاریم)')}</b>
          <b class="${loanSettled ? '' : (loanNet > 0 ? 'pos' : 'neg')}">${loanSettled ? 'تسویه' : toman(Math.abs(loanNet))}</b></div>`
        : '<div class="muted">هنوز قرض یا وامی ثبت نشده.</div>'}
      <div class="tag-row" style="margin-top:10px">
        <button type="button" class="tag" id="ln-given">↗ اعطای قرض</button>
        <button type="button" class="tag" id="ln-taken">↙ دریافت قرض</button>
        <button type="button" class="tag" id="ln-repay">⇄ ثبت بازپرداخت</button>
        ${hasLoans ? '<button type="button" class="tag" id="ln-pdf">🖨 PDF دفتر قرض</button>' : ''}
      </div>
      ${hasLoans ? `<div class="section-title">تراکنش‌های قرض</div>${a.loans.map(loanItem).join('')}` : ''}
      ${a.dues.length ? `<div class="section-title">سررسیدهای قرض</div>${a.dues.map(dueItem).join('')}` : ''}
    </div>` : '';
  const html = `
    <h2>${esc(party || 'بدون نام')}</h2>
    ${a.bought > 0 || a.sold > 0 ? `<div class="card" style="background:#eef6ff">
      ${a.bought > 0 ? `<div class="card-row"><span>جمع خرید از او</span><span>${toman(a.bought)}</span></div>
      <div class="card-row"><span>پرداخت‌شده به او</span><span>${toman(a.paid)}</span></div>
      <div class="card-row"><span>بدهی ما به او</span><span class="${a.weOwe > 0.5 ? 'neg' : ''}">${toman(a.weOwe)}</span></div>` : ''}
      ${a.bought > 0 && a.sold > 0 ? '<hr style="border:none;border-top:1px solid var(--border)">' : ''}
      ${a.sold > 0 ? `<div class="card-row"><span>جمع فروش به او</span><span>${toman(a.sold)}</span></div>
      <div class="card-row"><span>دریافت‌شده از او</span><span>${toman(a.received)}</span></div>
      <div class="card-row"><span>طلب ما از او</span><span class="${a.theyOwe > 0.5 ? 'pos' : ''}">${toman(a.theyOwe)}</span></div>` : ''}
      ${a.bought > 0 && a.sold > 0 ? `<hr style="border:none;border-top:1px solid var(--border)">
      <div class="card-row"><span>${settled ? 'تراز نهایی' : (a.net > 0 ? 'تراز نهایی: او به ما بدهکار است' : 'تراز نهایی: ما به او بدهکاریم')}</span><span>${settled ? 'تسویه' : toman(Math.abs(a.net))}</span></div>` : ''}
    </div>` : ''}
    ${loanCard}
    ${cashFlowCardHtml('cf-party')}
    ${a.entries.length ? `<div class="section-title">خرید و فروش‌ها</div>${a.entries.map(entry).join('')}` : ''}
    <button class="btn btn-secondary" id="pty-close">بستن</button>
  `;
  const close = openModal(html);
  const closeAll = () => { close(); if (state.tab === 'parties') renderParties().catch(() => {}); };
  const reopen = async () => {
    close();
    try { const d = await api('parties.list'); d.items.forEach(partyDefaults); openPartyDetail(party, d.items); } catch (err) { toast(err.message); }
    if (state.tab === 'parties') renderParties().catch(() => {});
  };
  document.getElementById('pty-close').onclick = closeAll;
  mountCashFlow('cf-party', { party: party, accounts: () => [a] });
  document.querySelectorAll('.card[data-kind]').forEach(el => el.addEventListener('click', () => {
    const id = Number(el.dataset.id);
    close();
    if (el.dataset.kind === 'p') openPayEditor('payment', id); else openPayEditor('receipt', id);
  }));
  if (party) {
    document.getElementById('ln-given').onclick = () => openLoanForm(a, 'given', reopen);
    document.getElementById('ln-taken').onclick = () => openLoanForm(a, 'taken', reopen);
    document.getElementById('ln-repay').onclick = () => {
      if (a.loanReceivable <= 0.5 && a.loanPayable <= 0.5) { toast('بدهی برای بازپرداخت وجود ندارد. برای قرض تازه از «اعطای قرض» یا «دریافت قرض» استفاده کنید.'); return; }
      openLoanForm(a, a.loanReceivable > 0.5 ? 'repay_in' : 'repay_out', reopen);
    };
    const pdf = document.getElementById('ln-pdf'); if (pdf) pdf.onclick = () => openLoanPrint([a]);
    document.querySelectorAll('[data-del-loan]').forEach(b => b.addEventListener('click', async () => {
      if (!confirm('این مورد حذف شود؟')) return;
      try { const res = await api('loans.delete', { uid: b.dataset.delLoan }); confirmToast(res, 'حذف شد'); reopen(); } catch (err) { toast(err.message); }
    }));
    document.querySelectorAll('[data-due-paid]').forEach(b => b.addEventListener('click', async () => {
      const d = a.dues.find(x => String(x.id) === b.dataset.duePaid); if (!d) return;
      const verb = d.kind === 'given' ? 'دریافت' : 'پرداخت';
      if (!confirm(`قرض به مبلغ ${cnt(d.amount)} ریال «${verb} شد» ثبت شود؟`)) return;
      try { const res = await api('dues.mark_paid', { table: 'loan_dues', id: d.id, paid: true }); confirmToast(res, `${verb} با موفقیت ثبت شد`); reopen(); } catch (err) { toast(err.message); }
    }));
  }
}

// ---------------------------------------------------------------- سررسیدها

let _duesFilter = 'all';
function dueWhen(iso) {
  const n = daysUntilIso(iso);
  if (n === 0) return 'امروز';
  return n > 0 ? `${cnt(n)} روز دیگر` : `${cnt(-n)} روز گذشته`;
}
async function renderDues() {
  const data = await api('dues.list');
  const c = document.getElementById('content');
  const all = data.items.filter(d => !d.paid);
  const list = all.filter(d => _duesFilter === 'payable' ? !d.receivable : (_duesFilter === 'receivable' ? d.receivable : true));
  let pay = 0, rec = 0, overdueAmt = 0;
  for (const d of all) {
    if (d.receivable) rec += Number(d.amount); else pay += Number(d.amount);
    if (daysUntilIso(d.date) < 0) overdueAmt += Number(d.amount);
  }
  const late = list.filter(d => daysUntilIso(d.date) < 0);
  const soon = list.filter(d => { const n = daysUntilIso(d.date); return n >= 0 && n <= 7; });
  const later = list.filter(d => daysUntilIso(d.date) > 7);
  const tile = (d) => {
    const isLate = daysUntilIso(d.date) < 0;
    const extra = [d.table === 'loan_dues' ? d.label : d.kind, d.bank, d.ref ? 'شماره ' + d.ref : ''].filter(Boolean).join(' • ');
    return `<div class="list-item due-tile" data-table="${d.table}" data-id="${d.id}" style="cursor:pointer">
      <div>
        <div class="title">${d.receivable ? '↙ دریافت از' : '↗ پرداخت به'} ${esc(d.party || 'بدون نام')}</div>
        <div class="meta">${esc(extra)}</div>
        <div class="meta" style="${isLate ? 'color:var(--red)' : ''}">${isoToJalaliDisplay(d.date)} • ${dueWhen(d.date)}</div>
      </div>
      <div style="text-align:left;font-weight:700;color:${d.receivable ? 'var(--green)' : 'var(--red)'}">${cnt(d.amount)}<div class="meta">ریال</div></div>
    </div>`;
  };
  c.innerHTML = `
    <div class="card" style="background:#eef6ff">
      <div class="card-row"><span>جمع پرداختی‌های باقی‌مانده</span><span>${toman(pay)}</span></div>
      <div class="card-row"><span>جمع دریافتی‌های باقی‌مانده</span><span>${toman(rec)}</span></div>
      ${overdueAmt > 0 ? `<div class="card-row"><span>سررسید گذشته</span><span class="neg">${toman(overdueAmt)}</span></div>` : ''}
    </div>
    <div class="tag-row" id="dues-filter" style="margin:8px 0">
      <span class="tag ${_duesFilter === 'all' ? 'active' : ''}" data-f="all">همه</span>
      <span class="tag ${_duesFilter === 'payable' ? 'active' : ''}" data-f="payable">پرداختی</span>
      <span class="tag ${_duesFilter === 'receivable' ? 'active' : ''}" data-f="receivable">دریافتی</span>
    </div>
    ${!list.length ? '<div class="empty-state">سررسید بازی وجود ندارد 🎉</div>' : ''}
    ${late.length ? `<div class="section-title">سررسید گذشته</div>${late.map(tile).join('')}` : ''}
    ${soon.length ? `<div class="section-title">تا ۷ روز آینده</div>${soon.map(tile).join('')}` : ''}
    ${later.length ? `<div class="section-title">بعداً</div>${later.map(tile).join('')}` : ''}
  `;
  document.getElementById('dues-filter').onclick = (e) => {
    const t = e.target.closest('[data-f]'); if (!t) return;
    _duesFilter = t.dataset.f; renderDues();
  };
  c.querySelectorAll('.due-tile').forEach(el => el.addEventListener('click', async () => {
    const d = all.find(x => x.table === el.dataset.table && String(x.id) === el.dataset.id);
    if (!d) return;
    const verb = d.receivable ? 'دریافت' : 'پرداخت';
    const who = d.party ? ` ${d.receivable ? 'از' : 'به'} ${d.party}` : '';
    if (!confirm(`${d.kind} به مبلغ ${cnt(d.amount)} ریال${who} «${verb} شد» ثبت شود؟`)) return;
    try {
      await api('dues.mark_paid', { table: d.table, id: d.id, paid: true });
      toast(`${verb} ثبت شد`);
      renderDues();
    } catch (err) { toast(err.message); }
  }));
}

// ---------------------------------------------------------------- مودال عمومی

// ---------------------------------------------------------------- ویرایشگر کامل پرداخت/دریافت (معادل payment_screen.dart و receipt_screen.dart)

const K_MAX_LUMP = 10;
const K_MAX_STAGED = 50;
function openPaymentOnlyForm(purchaseId) { return openPayEditor('payment', purchaseId); }
function openReceiptOnlyForm(saleId) { return openPayEditor('receipt', saleId); }

async function openPayEditor(kind, id) {
  const isPay = kind === 'payment';
  const L = isPay ? {
    get: 'purchases.get', save: 'payments.save', idKey: 'purchase_id', field: 'payment', typeKey: 'pay_type', nameKey: 'seller_name',
    title: 'پرداخت', totalLabel: 'مبلغ کل خرید', settledLabel: 'تسویه شده', overLabel: 'پرداخت اضافی',
    typeTitle: 'نوع پرداخت', lumpType: 'پرداخت یکجا', stagedType: 'پرداخت مرحله‌ای', creditType: 'تسویه اعتباری (قسطی)', chequeType: 'چک',
    lumpTitle: 'تراکنش‌های پرداخت یکجا', creditTitle: 'اقساط اعتباری', chequeTitle: 'چک‌ها',
    from: 'از کدام حساب', payer: 'به نام چه کسی (واریزکننده)', to: 'به کدام حساب فروشنده', name: 'به نام چه کسی (فروشنده)',
    lumpDate: 'تاریخ واریز', paidLump: 'تسویه شده', paidInst: 'پرداخت شده',
  } : {
    get: 'sales.get', save: 'receipts.save', idKey: 'sale_id', field: 'receipt', typeKey: 'recv_type', nameKey: 'receiver_name',
    title: 'دریافت وجه', totalLabel: 'مبلغ کل فروش', settledLabel: 'وصول شده', overLabel: 'دریافت اضافی',
    typeTitle: 'نحوهٔ دریافت', lumpType: 'دریافت یکجا', stagedType: 'دریافت مرحله‌ای', creditType: 'وصول اعتباری (قسطی)', chequeType: 'چک دریافتی',
    lumpTitle: 'تراکنش‌های دریافت یکجا', creditTitle: 'اقساط وصولی', chequeTitle: 'چک‌های دریافتی',
    from: 'از کدام حساب (خریدار)', payer: 'به نام چه کسی (پرداخت‌کننده)', to: 'به کدام حساب ما', name: 'به نام چه کسی (دریافت‌کننده)',
    lumpDate: 'تاریخ دریافت', paidLump: 'وصول شده', paidInst: 'وصول شده',
  };
  let item;
  try { item = (await api(L.get, { id })).item; } catch (err) { toast(err.message); return; }
  const total = (Number(item.net_weight) || 0) * (Number(item.price) || 0);
  const cur = item[L.field];
  const st = { type: cur ? cur[L.typeKey] : null, staged: cur ? cur.staged_type : null, lump: [], credit: [], cheque: [] };
  if (cur) {
    if (cur[L.typeKey] === 'lump') st.lump = (cur.lump || []).map(r => ({ ...r }));
    else if (cur.staged_type === 'credit') st.credit = (cur.installments || []).map(r => ({ ...r }));
    else if (cur.staged_type === 'cheque') st.cheque = (cur.installments || []).map(r => ({ ...r }));
  }
  const close = openModal('<div id="pe-root"></div>');
  const root = document.getElementById('pe-root');

  function settled() {
    const sum = (rows) => rows.filter(r => Number(r.paid)).reduce((a, r) => a + Number(r.amount || 0), 0);
    if (st.type === 'lump') return sum(st.lump);
    if (st.type === 'staged' && st.staged === 'credit') return sum(st.credit);
    if (st.type === 'staged' && st.staged === 'cheque') return sum(st.cheque);
    return 0;
  }
  async function persist() {
    const p = {};
    if (st.type) {
      p[L.typeKey] = st.type;
      p.staged_type = st.type === 'staged' ? st.staged : null;
      if (st.type === 'lump') p.lump = st.lump;
      else p.installments = st.staged === 'credit' ? st.credit : st.cheque;
    }
    try { await api(L.save, { [L.idKey]: id, [L.field]: p }); }
    catch (err) { toast(err.message); }
    draw();
  }
  function setType(type, staged) {
    st.type = type; st.staged = staged;
    persist();
  }
  const verb = isPay ? 'پرداخت' : 'دریافت';
  const typeLbl = (t, s2) => t === 'lump' ? L.lumpType : (L.stagedType + ' (' + (s2 === 'cheque' ? L.chequeType : L.creditType) + ')');
  /** انتخاب/تغییر نوع همیشه با تأیید کاربر؛ با تغییر نوع، اطلاعات قبلی حذف می‌شود */
  function requestType(type, staged) {
    if (type === st.type && staged === st.staged) return;
    const msg = st.type
      ? `با تغییر نوع ${verb} از «${typeLbl(st.type, st.staged)}» به «${typeLbl(type, staged)}»، همهٔ اطلاعات ${verb} قبلی (تراکنش‌ها، اقساط و چک‌ها) حذف می‌شوند و باید اطلاعات جدید را دوباره وارد کنید. ادامه می‌دهید؟`
      : `نوع «${typeLbl(type, staged)}» انتخاب شود؟`;
    if (!confirm(msg)) return;
    st.lump = []; st.credit = []; st.cheque = [];
    setType(type, staged);
  }
  function printLedger() {
    const set = settled(), rem = total - set;
    const seller = isPay ? String(item.seller || '').trim() : '';
    const buyer = !isPay ? String(item.buyer || '').trim() : '';
    const code = isPay ? (item.code || '-') : ([...new Set((item.items || []).map(x => x.code).filter(Boolean))].join('، ') || '-');
    const title = isPay ? 'رسید پرداخت' + (seller ? ' — ' + seller : '') : 'رسید وصول مطالبات' + (buyer ? ' — ' + buyer : '');
    const fromN = isPay ? 'واریزکننده' : 'پرداخت‌کننده (خریدار)', toN = isPay ? 'مقصد' : 'دریافت‌کننده';
    const nm = (r) => esc(r[L.nameKey] || '-');
    const stl = (r) => Number(r.paid) === 1 ? 'تسویه شده' : (st.type === 'lump' ? 'ثبت‌شده' : 'باقیمانده');
    let head = '', body = '';
    if (st.type === 'lump') {
      head = `<th>مبلغ (ریال)</th><th>${fromN}</th><th>${toN}</th><th>تاریخ</th><th>وضعیت</th>`;
      body = st.lump.map(r => `<tr><td>${cnt(r.amount)}</td><td>${esc(r.payer_name || '-')}</td><td>${nm(r)}</td><td>${isoToJalaliDisplay(r.date)}</td><td>${stl(r)}</td></tr>`).join('');
    } else if (st.type === 'staged' && st.staged === 'credit') {
      head = `<th>مبلغ (ریال)</th><th>${fromN}</th><th>${toN}</th><th>سررسید</th><th>وضعیت</th>`;
      body = st.credit.map(r => `<tr><td>${cnt(r.amount)}</td><td>${esc(r.payer_name || '-')}</td><td>${nm(r)}</td><td>${isoToJalaliDisplay(r.due_date)}</td><td>${stl(r)}</td></tr>`).join('');
    } else if (st.type === 'staged' && st.staged === 'cheque') {
      head = `<th>مبلغ (ریال)</th><th>سررسید</th><th>شماره چک</th><th>بانک</th><th>وضعیت</th>`;
      body = st.cheque.map(r => `<tr><td>${cnt(r.amount)}</td><td>${isoToJalaliDisplay(r.due_date)}</td><td>${esc(r.cheque_no || '-')}</td><td>${esc(r.bank_name || '-')}</td><td>${stl(r)}</td></tr>`).join('');
    }
    openPrintView(title, `
      <h1>${esc(title)}</h1>
      <div class="sub">تاریخ تهیه گزارش: ${isoToJalaliDisplay(todayStr())}</div>
      <div style="border:1px solid #999;border-radius:6px;padding:8px 12px;margin-bottom:14px">
        <div class="row"><span><b>کد پسته</b></span><span><b>${esc(code)}</b></span></div>
        <div class="row"><span><b>${L.totalLabel}</b></span><span><b>${toman(total)}</b></span></div>
        <div class="row"><span>تسویه شده</span><span>${toman(set)}</span></div>
        <div class="row"><span>${rem >= 0 ? 'باقیمانده' : 'اضافه‌پرداخت'}</span><span>${toman(Math.abs(rem))}</span></div>
      </div>
      ${body ? `<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>` : '<div>تراکنشی ثبت نشده است</div>'}`);
  }
  function rowHtml(r, i, list) {
    const paid = Number(r.paid) === 1;
    const isLump = list === 'lump';
    const dateTxt = isLump ? isoToJalaliDisplay(r.date) : 'سررسید ' + isoToJalaliDisplay(r.due_date);
    const chq = list === 'cheque' ? `چک ${esc(r.cheque_no || '-')} — بانک ${esc(r.bank_name || '-')}<br>` : '';
    return `<div class="card" data-list="${list}" data-i="${i}" style="display:flex;gap:8px;align-items:flex-start">
      <input type="checkbox" class="pe-paid" ${paid ? 'checked' : ''} style="margin-top:4px">
      <div class="pe-edit" style="flex:1;cursor:pointer">
        <div><b>${toman(r.amount)}</b> — ${dateTxt}</div>
        <div class="meta">${chq}از ${esc(r.from_account || '-')} (${esc(r.payer_name || '-')})<br>به ${esc(r.to_account || '-')} (${esc(r[L.nameKey] || '-')})${paid ? '<br><span class="pos">' + (isLump ? L.paidLump : L.paidInst) + '</span>' : ''}</div>
      </div>
      <button class="pe-del icon-btn" style="color:var(--red);font-size:17px">🗑</button>
    </div>`;
  }
  function draw() {
    const set = settled();
    const rem = total - set;
    const listKey = st.type === 'lump' ? 'lump' : (st.type === 'staged' ? st.staged : null);
    const rows = listKey ? st[listKey] : [];
    const max = listKey === 'lump' ? K_MAX_LUMP : K_MAX_STAGED;
    const secTitle = listKey === 'lump' ? L.lumpTitle : (listKey === 'credit' ? L.creditTitle : L.chequeTitle);
    root.innerHTML = `
      <h2>${L.title}</h2>
      <div class="card" style="background:#eef6ff">
        <div class="card-row"><span>${L.totalLabel}</span><span>${toman(total)}</span></div>
        <div class="card-row"><span>${L.settledLabel}</span><span>${toman(set)}</span></div>
        <div class="card-row"><span>${rem >= 0 ? 'باقیمانده' : L.overLabel}</span><span class="${rem > 0 ? 'neg' : 'pos'}">${toman(Math.abs(rem))}</span></div>
      </div>
      <div class="section-title">${L.typeTitle}</div>
      <div class="tag-row" id="pe-type">
        <span class="tag ${st.type === 'lump' ? 'active' : ''}" data-t="lump">${L.lumpType}</span>
        <span class="tag ${st.type === 'staged' ? 'active' : ''}" data-t="staged">${L.stagedType}</span>
      </div>
      ${st.type === 'staged' ? `<div class="tag-row" id="pe-staged" style="margin-top:6px">
        <span class="tag ${st.staged === 'credit' ? 'active' : ''}" data-s="credit">${L.creditType}</span>
        <span class="tag ${st.staged === 'cheque' ? 'active' : ''}" data-s="cheque">${L.chequeType}</span>
      </div>` : ''}
      ${listKey ? `
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div class="section-title">${secTitle} (${cnt(rows.length)}/${cnt(max)})</div>
          <button class="btn btn-secondary" id="pe-add" style="width:auto;padding:6px 14px;margin:0">＋ افزودن</button>
        </div>
        ${rows.length ? rows.map((r, i) => rowHtml(r, i, listKey)).join('') : '<div class="muted" style="padding:8px">هنوز موردی ثبت نشده</div>'}` : '<div class="muted" style="padding:8px">یک نوع را انتخاب کنید</div>'}
      ${st.type ? '<button class="btn btn-secondary" id="pe-pdf">🖨 خروجی PDF رسید</button>' : ''}
      <button class="btn btn-secondary" id="pe-close">بستن</button>`;
    const pdfBtn = document.getElementById('pe-pdf');
    if (pdfBtn) pdfBtn.onclick = printLedger;
    document.getElementById('pe-close').onclick = () => { close(); refreshAfter(); };
    document.getElementById('pe-type').onclick = (e) => {
      const t = e.target.closest('[data-t]'); if (!t) return;
      const v = t.dataset.t;
      if (v === st.type) return;
      requestType(v, v === 'staged' ? (st.staged || 'credit') : null);
    };
    const stg = document.getElementById('pe-staged');
    if (stg) stg.onclick = (e) => { const t = e.target.closest('[data-s]'); if (!t || t.dataset.s === st.staged) return; requestType('staged', t.dataset.s); };
    if (!listKey) return;
    document.getElementById('pe-add').onclick = () => {
      if (rows.length >= max) { toast(`حداکثر ${cnt(max)} مورد مجاز است`); return; }
      openRowForm(listKey, null);
    };
    root.querySelectorAll('[data-list]').forEach(card => {
      const i = Number(card.dataset.i);
      card.querySelector('.pe-paid').onchange = (e) => {
        const r = rows[i], on = e.target.checked;
        // v71: un-paying a row that consumed return credit restores its pre-credit amount (same as dues.mark_paid paid=false)
        if (!on && Number(r.credit_used) > 0) { r.amount = Math.round((Number(r.amount) + Number(r.credit_used)) * 100) / 100; delete r.credit_used; }
        r.paid = on ? 1 : 0; persist();
      };
      card.querySelector('.pe-edit').onclick = () => openRowForm(listKey, i);
      card.querySelector('.pe-del').onclick = () => {
        if (!confirm(listKey === 'lump' ? 'این تراکنش حذف شود؟' : (listKey === 'credit' ? 'این قسط حذف شود؟' : 'این چک حذف شود؟'))) return;
        rows.splice(i, 1); persist();
      };
    });
  }
  function openRowForm(listKey, idx) {
    const rows = st[listKey];
    const e = idx == null ? null : rows[idx];
    const isLump = listKey === 'lump', isChq = listKey === 'cheque';
    const titleNew = isLump ? 'تراکنش جدید' : (isChq ? 'چک جدید' : 'قسط جدید');
    const titleEdit = isLump ? 'ویرایش تراکنش' : (isChq ? 'ویرایش چک' : 'ویرایش قسط');
    const amtL = isLump ? 'مبلغ (ریال)' : (isChq ? 'مبلغ چک (ریال)' : 'مبلغ قسط (ریال)');
    const dateL = isLump ? L.lumpDate : (isChq ? 'تاریخ سررسید چک' : 'تاریخ سررسید');
    const paidL = isLump ? L.paidLump : L.paidInst;
    const paid0 = e ? Number(e.paid) === 1 : isLump;
    const close2 = openModal(`
      <h2>${e ? titleEdit : titleNew}</h2>
      <div class="field"><label>${amtL} *</label><input type="text" id="rw-amount" value="${e ? e.amount : ''}"></div>
      <div class="field"><label>${dateL}</label><div id="rw-date"></div></div>
      ${isChq ? `<div class="row2"><div class="field"><label>شماره چک</label><input type="text" id="rw-chq" value="${esc(e && e.cheque_no ? e.cheque_no : '')}"></div><div class="field"><label>نام بانک</label><input type="text" id="rw-bank" value="${esc(e && e.bank_name ? e.bank_name : '')}"></div></div>` : ''}
      <div class="field"><label>${L.from}</label><input type="text" id="rw-from" value="${esc(e && e.from_account ? e.from_account : '')}"></div>
      <div class="field"><label>${L.payer}</label><input type="text" id="rw-payer" value="${esc(e && e.payer_name ? e.payer_name : '')}"></div>
      <div class="field"><label>${L.to}</label><input type="text" id="rw-to" value="${esc(e && e.to_account ? e.to_account : '')}"></div>
      <div class="field"><label>${esc(L.name)}</label><input type="text" id="rw-name" value="${esc(e && e[L.nameKey] ? e[L.nameKey] : '')}"></div>
      <div class="field"><label>${paidL}</label>
        <div class="tag-row"><span class="tag ${paid0 ? 'active' : ''}" id="rw-paid-y">بله</span><span class="tag ${paid0 ? '' : 'active'}" id="rw-paid-n">خیر</span></div>
      </div>
      <div id="rw-warn" style="display:none;margin:6px 0;padding:8px 10px;border:1px solid #e0a030;background:#fff6e5;color:#8a5a00;border-radius:8px;font-size:12.5px"></div>
      <div class="error" id="rw-error"></div>
      <button class="btn btn-primary" id="rw-save">ذخیره</button>
      <button class="btn btn-secondary" id="rw-cancel">انصراف</button>`);
    mountJalaliDate('rw-date', e ? (isLump ? e.date : e.due_date) : todayStr());
    attachThousands('rw-amount');
    // هشدار (غیرمسدودکننده): جمع همهٔ تراکنش‌های ثبت‌شده + مبلغ فعلی از مبلغ کل بیشتر شود
    const othersSum = rows.reduce((a, r, i) => a + (i === idx ? 0 : Number(r.amount || 0)), 0);
    const overMsg = () => {
      const sum = othersSum + numVal('rw-amount');
      return total > 0 && sum > total + 0.5
        ? `هشدار: با این مبلغ، جمع همهٔ تراکنش‌های ثبت‌شده ${toman(sum - total)} بیشتر از مبلغ کل شده است؛ شما دارید بیشتر از مبلغ کل ${verb} می‌کنید. ثبت آن همچنان ممکن است.` : '';
    };
    const warnEl = document.getElementById('rw-warn');
    const updWarn = () => { const m = overMsg(); warnEl.textContent = m; warnEl.style.display = m ? 'block' : 'none'; };
    document.getElementById('rw-amount').addEventListener('input', updWarn);
    updWarn();
    let paid = paid0;
    document.getElementById('rw-paid-y').onclick = () => { paid = true; document.getElementById('rw-paid-y').classList.add('active'); document.getElementById('rw-paid-n').classList.remove('active'); };
    document.getElementById('rw-paid-n').onclick = () => { paid = false; document.getElementById('rw-paid-n').classList.add('active'); document.getElementById('rw-paid-y').classList.remove('active'); };
    document.getElementById('rw-cancel').onclick = close2;
    document.getElementById('rw-save').onclick = () => {
      const amount = numVal('rw-amount');
      if (amount <= 0) { document.getElementById('rw-error').textContent = 'مبلغ را وارد کنید'; return; }
      const row = {
        amount, from_account: document.getElementById('rw-from').value.trim(), payer_name: document.getElementById('rw-payer').value.trim(),
        to_account: document.getElementById('rw-to').value.trim(), [L.nameKey]: document.getElementById('rw-name').value.trim(), paid: paid ? 1 : 0,
      };
      if (isLump) row.date = dateVal('rw-date'); else row.due_date = dateVal('rw-date');
      if (isChq) { row.cheque_no = document.getElementById('rw-chq').value.trim(); row.bank_name = document.getElementById('rw-bank').value.trim(); }
      // v71: a paid row keeps the return credit it consumed (credit_used comes from purchases.get / sales.get: SELECT *);
      // un-paid through this form with the amount untouched ⇒ the pre-credit amount comes back.
      const prev = idx != null ? rows[idx] : null;
      if (prev && Number(prev.credit_used) > 0) {
        if (paid) row.credit_used = Number(prev.credit_used);
        else if (Math.abs(amount - Number(prev.amount)) < 0.005) row.amount = Math.round((amount + Number(prev.credit_used)) * 100) / 100;
      }
      const over = overMsg();
      if (idx == null) rows.push(row); else rows[idx] = row;
      close2(); persist();
      if (over) toast(over);
    };
  }
  function refreshAfter() {
    const tab = state.tab;
    if (tab) setTab(tab);
  }
  draw();
}


/** پیشنهاد خودکار نام فروشنده/خریدار از نام‌های قبلی (معادل party_field.dart) تا یک نفر با چند املا ثبت نشود */
function attachPartyDatalist(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.setAttribute('list', 'party-names-dl');
  let dl = document.getElementById('party-names-dl');
  if (!dl) { dl = document.createElement('datalist'); dl.id = 'party-names-dl'; document.body.appendChild(dl); }
  const fill = () => { dl.innerHTML = (state.partyNames || []).map(n => `<option value="${esc(n)}">`).join(''); };
  fill();
  if (!state.partyNames) {
    api('parties.list').then(d => { state.partyNames = d.items.map(x => x.key).filter(Boolean); fill(); }).catch(() => {});
  }
}

/**
 * «فراموشی رمز»: کد پیامکی به شمارهٔ خودِ حساب می‌آید و با آن رمز جدید گذاشته می‌شود (رمز فعلی لازم نیست).
 * با تغییر رمز توکن عوض می‌شود: همین دستگاه وارد می‌ماند و دستگاه‌های دیگر خارج می‌شوند.
 */
function openForgotPassword(onDone) {
  let sent = false;
  const close = openModal(`
    <h2>فراموشی رمز عبور</h2>
    <p class="muted" id="fp-msg" style="font-size:13px">یک کد پیامکی به شمارهٔ همین حساب ارسال می‌شود و با آن می‌توانید رمز جدید تعیین کنید.</p>
    <div id="fp-step2" class="hidden">
      <div class="field"><label>کد پیامکی</label><input type="text" id="fp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="12"></div>
      <div class="field"><label>رمز جدید (حداقل ۶ کاراکتر)</label><input type="password" id="fp-new" autocomplete="new-password" maxlength="72"></div>
      <div class="field"><label>تکرار رمز جدید</label><input type="password" id="fp-new2" autocomplete="new-password" maxlength="72"></div>
    </div>
    <div id="fp-err" class="error"></div>
    <button class="btn btn-primary" id="fp-go">ارسال کد</button>
    <button class="btn btn-secondary" id="fp-cancel">انصراف</button>`);
  const $ = (id) => document.getElementById(id);
  const showStep2 = () => {
    sent = true;
    $('fp-step2').classList.remove('hidden');
    $('fp-msg').textContent = 'کد پیامکی ارسال‌شده و رمز جدید را وارد کنید.';
    $('fp-go').textContent = 'تغییر رمز';
    setTimeout(() => $('fp-code').focus(), 50);
  };
  $('fp-cancel').onclick = close;
  $('fp-go').onclick = async () => {
    const btn = $('fp-go'), errEl = $('fp-err');
    errEl.textContent = '';
    btn.disabled = true;
    try {
      if (!sent) {
        try { await loginPost('otp_send.php', { phone: state.phone }); showStep2(); }
        catch (err) {
          // کد همین چند لحظه پیش فرستاده شده (مهلت ارسال دوباره): همان کد معتبر است
          if (err.data && err.data.resend_in) showStep2(); else throw err;
        }
        return;
      }
      const code = $('fp-code').value.trim(), n1 = $('fp-new').value, n2 = $('fp-new2').value;
      if (!code) throw new Error('کد پیامکی را وارد کنید');
      if (n1.length < 6) throw new Error('رمز جدید باید حداقل ۶ کاراکتر باشد');
      if (n1 !== n2) throw new Error('تکرار رمز با رمز جدید یکسان نیست');
      const res = await api('account.reset_password', { code, new_password: n1 });
      if (res && res.new_token) { state.token = res.new_token; localStorage.setItem('pb_token', res.new_token); }
      close();
      toast('رمز عبور تغییر کرد؛ دستگاه‌های دیگر خارج شدند');
      if (onDone) onDone();
    } catch (err) { errEl.textContent = err.message; }
    finally { btn.disabled = false; }
  };
}

function openModal(innerHtml) {
  const root = document.getElementById('modal-root');
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `<div class="modal-sheet">${innerHtml}</div>`;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  root.appendChild(overlay);
  function close() { overlay.remove(); }
  return close;
}

function openPhotoView(src) {
  const overlay = document.createElement('div');
  overlay.className = 'photo-view-overlay';
  overlay.innerHTML = `<img src="${safeImg(src)}">`;
  overlay.addEventListener('click', () => overlay.remove());
  document.body.appendChild(overlay);
}

/** یک فایل عکس را می‌خواند، تغییر اندازه و فشرده می‌کند و data-URL برمی‌گرداند */
function readAndCompressImage(file, maxDim = 1100, quality = 0.72) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('خواندن فایل عکس ناموفق بود'));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('فایل انتخاب‌شده یک عکس معتبر نیست'));
      img.onload = () => {
        let { width, height } = img;
        if (width > maxDim || height > maxDim) {
          if (width >= height) { height = Math.round(height * maxDim / width); width = maxDim; }
          else { width = Math.round(width * maxDim / height); height = maxDim; }
        }
        const canvas = document.createElement('canvas');
        canvas.width = width; canvas.height = height;
        canvas.getContext('2d').drawImage(img, 0, 0, width, height);
        resolve(canvas.toDataURL('image/jpeg', quality));
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

/** جعبهٔ انتخاب/نمایش عکس رسید خرید؛ روی containerId رندر می‌شود و {get, set} برمی‌گرداند */
function mountPhotoPicker(containerId, initialSrc) {
  const box = document.getElementById(containerId);
  let current = initialSrc || null;
  box.innerHTML = '';
  const inner = document.createElement('div');
  inner.style.cssText = 'width:100%;height:100%;display:flex;align-items:center;justify-content:center;position:relative';
  // دو ورودی جدا: یکی مستقیم دوربین را باز می‌کند، دیگری گالری/حافظه
  const cameraInput = document.createElement('input');
  cameraInput.type = 'file'; cameraInput.accept = 'image/*'; cameraInput.capture = 'environment';
  cameraInput.className = 'hidden';
  const galleryInput = document.createElement('input');
  galleryInput.type = 'file'; galleryInput.accept = 'image/*';
  galleryInput.className = 'hidden';
  box.appendChild(inner);
  box.appendChild(cameraInput);
  box.appendChild(galleryInput);
  function draw() {
    inner.innerHTML = current
      ? `<img src="${safeImg(current)}"><button type="button" class="ph-remove">✕</button>`
      : `<div class="ph-placeholder">📷<br>افزودن عکس رسید (اختیاری)</div>`;
    if (current) {
      inner.querySelector('img').addEventListener('click', (e) => { e.stopPropagation(); openPhotoView(current); });
      inner.querySelector('.ph-remove').addEventListener('click', (e) => { e.stopPropagation(); current = null; draw(); });
    }
  }
  box.addEventListener('click', () => {
    const close = openModal(`
      <h2>افزودن عکس رسید</h2>
      <button type="button" class="btn btn-primary" id="ph-camera">📷 گرفتن عکس با دوربین</button>
      <button type="button" class="btn btn-secondary" id="ph-gallery">🖼️ انتخاب از گالری / حافظه</button>
      <button type="button" class="btn btn-secondary" id="ph-cancel">انصراف</button>`);
    document.getElementById('ph-camera').addEventListener('click', () => { close(); cameraInput.click(); });
    document.getElementById('ph-gallery').addEventListener('click', () => { close(); galleryInput.click(); });
    document.getElementById('ph-cancel').addEventListener('click', close);
  });
  async function onPicked(input) {
    const f = input.files && input.files[0];
    input.value = '';
    if (!f) return;
    try { current = await readAndCompressImage(f); draw(); }
    catch (err) { toast(err.message); }
  }
  cameraInput.addEventListener('change', () => onPicked(cameraInput));
  galleryInput.addEventListener('change', () => onPicked(galleryInput));
  draw();
  return { get: () => current };
}

// ================================================================ نمودارها (SVG ساده، بدون هیچ کتابخانه‌ای)
const CH = {
  gain: '#2e7d32', loss: '#c62828', orange: '#d9822b', other: '#9e9e9e',
  palette: ['#5b7f2a', '#d9a441', '#3e8e8a', '#c1673e', '#7a6bb0', '#8fa64b'],
  greens: ['#2e7d32', '#43a047', '#66bb6a', '#81c784', '#a5d6a7'],
  reds: ['#c62828', '#e53935', '#ef5350', '#e57373', '#ef9a9a'],
};
let _chSeq = 0;

/** عدد کوتاه‌شده: ۱٫۲ میلیارد / ۴۵۰ میلیون / ۱۲ هزار */
function chCompact(v) {
  const a = Math.abs(Number(v) || 0), s = v < 0 ? '-' : '';
  const f = (x, d) => Number(x.toFixed(d)).toLocaleString('fa-IR');
  if (a >= 1e12) return s + f(a / 1e12, 1) + ' هزار میلیارد';
  if (a >= 1e9) return s + f(a / 1e9, 1) + ' میلیارد';
  if (a >= 1e6) return s + f(a / 1e6, 1) + ' میلیون';
  if (a >= 1e3) return s + f(a / 1e3, 0) + ' هزار';
  return s + f(a, 0);
}

/** عدد کوتاه‌شدهٔ دلاری: ۱٫۲ میلیون / ۴٫۵ هزار / ۱۲۰ / ۵٫۲۵ */
/** S35.T35.12: مبلغ ذخیره‌شده → واحد نمایش شرکت + کوتاه‌سازی + برچسب واحد */
function chCompactMoney(stored) {
  const c = PBFmt.config();
  const d = PBFmt.toDisplay(Number(stored) || 0, c.storedUnit, c.displayUnit);
  return chCompact(d) + ' ' + (c.displayUnit === 'rial' ? 'ریال' : 'تومان');
}

function chCompactUsd(v) {
  const a = Math.abs(Number(v) || 0), sg = v < 0 ? '-' : '';
  const f = (x, d) => Number(x.toFixed(d)).toLocaleString('fa-IR');
  if (a >= 1e6) return sg + f(a / 1e6, 2) + ' میلیون';
  if (a >= 1e3) return sg + f(a / 1e3, 1) + ' هزار';
  return sg + f(a, a >= 100 ? 0 : 2);
}

/** بیشترین max تکه + «سایر» از یک map {برچسب: مقدار} */
function chTopSlices(map, max = 5) {
  const entries = Object.entries(map).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
  const out = []; let other = 0;
  entries.forEach(([label, value], i) => {
    if (i < max) out.push({ label, value, color: CH.palette[i % CH.palette.length] });
    else other += value;
  });
  if (other > 0) out.push({ label: 'سایر', value: other, color: CH.other });
  return out;
}

/** نمودار کروی: slices = [{label, value>0, color, negative?}] → {html, bind()} */
function chDonut(slices, opts) {
  const list = slices.filter(s => s.value > 0);
  const total = list.reduce((a, s) => a + s.value, 0);
  if (!total) return { html: '', bind() {} };
  const id = 'chd' + (++_chSeq);
  const R = 70, C = 2 * Math.PI * R;
  let cum = 0;
  const arcs = list.map((s, i) => {
    const frac = s.value / total;
    const len = Math.max(0, frac * C - (list.length > 1 ? 3 : 0));
    const off = -cum * C; cum += frac;
    return `<circle class="chd-arc" data-i="${i}" cx="100" cy="100" r="${R}" fill="none" stroke="${s.color}" stroke-width="28" stroke-dasharray="${len} ${C}" stroke-dashoffset="${off}" transform="rotate(-90 100 100)"></circle>`;
  }).join('');
  const centerVal0 = opts.centerValue != null ? opts.centerValue : total;
  const html = `<div class="chd" id="${id}">
    <svg viewBox="0 0 200 200" width="190" height="190" style="display:block;margin:0 auto">${arcs}
      <text x="100" y="92" text-anchor="middle" font-size="11" fill="#777" class="chd-t1">${esc(opts.centerTitle)}</text>
      <text x="100" y="114" text-anchor="middle" font-size="15" font-weight="700" class="chd-t2">${esc(opts.fmt(centerVal0))}</text>
    </svg>
    <div class="chd-legend">${list.map((s, i) => `<div class="chd-row" data-i="${i}">
      <span class="chd-dot" style="background:${s.color}"></span><span class="chd-lbl">${esc(s.label)}</span>
      <span class="chd-val${s.negative ? ' neg' : ''}">${s.negative ? '-' : ''}${esc(opts.fmt(s.value))}</span>
      <span class="chd-pct">${toFaDigits(String(Math.round(s.value / total * 100)))}٪</span></div>`).join('')}</div></div>`;
  const bind = () => {
    const root = document.getElementById(id); if (!root) return;
    const t1 = root.querySelector('.chd-t1'), t2 = root.querySelector('.chd-t2');
    let sel = null;
    const paint = () => {
      root.querySelectorAll('.chd-arc').forEach(a => a.setAttribute('stroke-width', Number(a.dataset.i) === sel ? '38' : '28'));
      root.querySelectorAll('.chd-row').forEach(r => r.classList.toggle('sel', Number(r.dataset.i) === sel));
      if (sel == null) { t1.textContent = opts.centerTitle; t2.textContent = opts.fmt(centerVal0); }
      else { t1.textContent = list[sel].label; t2.textContent = (list[sel].negative ? '-' : '') + opts.fmt(list[sel].value); }
    };
    root.querySelectorAll('[data-i]').forEach(el => el.addEventListener('click', () => {
      const i = Number(el.dataset.i); sel = sel === i ? null : i; paint();
    }));
  };
  return { html, bind };
}

/** نمودار میله‌ای (یک یا چند سری)؛ زمان از راست به چپ. groups = [{label, full, values[]}], series = [{name, color}] */
function chBars(groups, series, opts) {
  const n = groups.length;
  if (!n) return { html: '', bind() {} };
  const id = 'chb' + (++_chSeq);
  const W = 340, H = 210, top = 18, bot = 24, chartH = H - top - bot;
  let maxV = 0, minV = 0;
  groups.forEach(g => g.values.forEach(v => { if (v > maxV) maxV = v; if (v < minV) minV = v; }));
  if (maxV === 0 && minV === 0) maxV = 1;
  const range = maxV - minV;
  const yOf = (v) => top + chartH * (maxV - v) / range;
  const zeroY = yOf(0);
  const slot = W / n, groupW = Math.min(slot * 0.78, 64 * series.length), barW = groupW / series.length;
  const showVals = n <= 6, every = n <= 8 ? 1 : Math.ceil(n / 7);
  let svg = '';
  for (let i = 0; i <= 3; i++) { const y = top + chartH * i / 3; svg += `<line x1="0" y1="${y}" x2="${W}" y2="${y}" stroke="rgba(0,0,0,.08)"></line>`; }
  svg += `<line x1="0" y1="${zeroY}" x2="${W}" y2="${zeroY}" stroke="rgba(0,0,0,.25)" stroke-width="1.2"></line>`;
  groups.forEach((g, i) => {
    const vis = n - 1 - i, cx = vis * slot + slot / 2, left0 = cx - groupW / 2;
    svg += `<rect class="chb-bg" data-i="${i}" x="${vis * slot + 2}" y="${top - 6}" width="${slot - 4}" height="${chartH + 12}" rx="8" fill="rgba(0,0,0,.06)" opacity="0"></rect>`;
    series.forEach((s, k) => {
      const raw = Number(g.values[k]) || 0, y = yOf(raw);
      const t = Math.min(zeroY, y), h = Math.abs(zeroY - y);
      const l = left0 + k * barW + barW * 0.1, w = barW * 0.8;
      if (h > 0.5) svg += `<rect x="${l}" y="${t}" width="${w}" height="${h}" rx="3" fill="${s.color}"></rect>`;
      if (showVals && raw !== 0) svg += `<text x="${l + w / 2}" y="${raw >= 0 ? t - 4 : t + h + 11}" text-anchor="middle" font-size="9" font-weight="700" fill="${s.color}">${esc((opts.compact || chCompact)(raw))}</text>`;
    });
    if (i % every === 0) svg += `<text x="${cx}" y="${H - 8}" text-anchor="middle" font-size="10" fill="#666">${esc(g.label)}</text>`;
    svg += `<rect class="chb-hit" data-i="${i}" x="${vis * slot}" y="0" width="${slot}" height="${H}" fill="transparent" style="cursor:pointer"></rect>`;
  });
  const legend = series.length > 1
    ? `<div class="chb-legend">${series.map(s => `<span><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join('')}</div>` : '';
  const html = `<div class="chb" id="${id}"><svg viewBox="0 0 ${W} ${H}" width="100%" style="display:block">${svg}</svg>${legend}
    <div class="chb-info muted">برای دیدن مبلغ دقیق، روی هر میله بزنید</div></div>`;
  const bind = () => {
    const root = document.getElementById(id); if (!root) return;
    const info = root.querySelector('.chb-info');
    let sel = null;
    root.querySelectorAll('.chb-hit').forEach(el => el.addEventListener('click', () => {
      const i = Number(el.dataset.i); sel = sel === i ? null : i;
      root.querySelectorAll('.chb-bg').forEach(b => b.setAttribute('opacity', Number(b.dataset.i) === sel ? '1' : '0'));
      if (sel == null) { info.className = 'chb-info muted'; info.textContent = 'برای دیدن مبلغ دقیق، روی هر میله بزنید'; return; }
      const g = groups[sel];
      info.className = 'chb-info chb-info-sel';
      info.innerHTML = `<b>${esc(g.full)}</b> ` + series.map((s, k) => {
        const v = Number(g.values[k]) || 0;
        return `<span style="color:${s.color};font-weight:600">${esc(s.name)}: ${esc(opts.fmt(v))}</span>`;
      }).join(' · ');
    }));
  };
  return { html, bind };
}

// ---- بازه‌های زمانی نمودار: روزانه (تا ۳۱ روز) یا ماهانه شمسی (حداکثر ۱۲ ماه آخر)
function chIsoAddDays(iso, n) {
  const [y, m, d] = String(iso).split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + n));
  return `${dt.getUTCFullYear()}-${pad2(dt.getUTCMonth() + 1)}-${pad2(dt.getUTCDate())}`;
}
function chDaysBetween(a, b) {
  const pa = String(a).split('-').map(Number), pb = String(b).split('-').map(Number);
  return Math.round((Date.UTC(pb[0], pb[1] - 1, pb[2]) - Date.UTC(pa[0], pa[1] - 1, pa[2])) / 86400000);
}
function chBuckets(fromIso, toIso) {
  if (toIso < fromIso) { const t = fromIso; fromIso = toIso; toIso = t; }
  const days = chDaysBetween(fromIso, toIso) + 1, out = [];
  if (days <= 31) {
    for (let i = 0; i < days; i++) {
      const s = chIsoAddDays(fromIso, i), j = isoToJalaliYMD(s);
      out.push({ start: s, endEx: chIsoAddDays(s, 1), label: toFaDigits(String(j[2])), full: isoToJalaliDisplay(s) });
    }
    return out;
  }
  let [y, m] = isoToJalaliYMD(fromIso);
  const [ey, em] = isoToJalaliYMD(toIso);
  while (y < ey || (y === ey && m <= em)) {
    const s = jalaliYMDToIso(y, m, 1), ny = m === 12 ? y + 1 : y, nm = m === 12 ? 1 : m + 1;
    out.push({ start: s, endEx: jalaliYMDToIso(ny, nm, 1), label: kJalaliMonthNames[m - 1], full: kJalaliMonthNames[m - 1] + ' ' + toFaDigits(String(y)) });
    y = ny; m = nm;
    if (out.length > 60) break;
  }
  return out.length > 12 ? out.slice(out.length - 12) : out;
}
function chBucketIdx(buckets, iso) {
  for (let i = 0; i < buckets.length; i++) if (iso >= buckets[i].start && iso < buckets[i].endEx) return i;
  return -1;
}

/** نمودار سود و زیان: سود (سبز) و زیان (قرمز) جدا از هم. byType = {برچسب: سود/زیان}، bucketTypes[i] = {برچسب: سود/زیان} */
function profitChartHtml(mode, buckets, bucketTypesT, byTypeT, usdData, useUsd) {
  // پلن ارزی: usdData = { bucketTypes, byType } (سود/زیان دلاریِ فروش‌هایی که قیمت دلار و فی دلاریِ خرید دارند)
  const usd = !!(usdData && useUsd);
  const bucketTypes = usd ? usdData.bucketTypes : bucketTypesT;
  const byType = usd ? usdData.byType : byTypeT;
  const fShort = usd ? chCompactUsd : chCompact;
  const fFull = (v) => fShort(v) + (usd ? ' دلار' : ' ریال');
  const emptyMsg = usd ? 'برای فروش‌های این بازه قیمت دلار یا فی دلاریِ خرید ثبت نشده' : 'در این بازه سود یا زیانی ثبت نشده';
  const gains = bucketTypes.map(m => Object.values(m).reduce((a, v) => a + (v > 0 ? v : 0), 0));
  const losses = bucketTypes.map(m => Object.values(m).reduce((a, v) => a + (v < 0 ? v : 0), 0));
  const curToggle = usdData ? `<div class="seg-tabs" id="pc-cur" style="margin-bottom:8px">
    <button class="seg-tab ${usd ? '' : 'active'}" data-c="0">ریال</button>
    <button class="seg-tab ${usd ? 'active' : ''}" data-c="1">دلار</button></div>` : '';
  const toggle = curToggle + `<div class="seg-tabs" id="pc-toggle" style="margin-bottom:10px">
    <button class="seg-tab ${mode === 0 ? 'active' : ''}" data-m="0">📊 میله‌ای</button>
    <button class="seg-tab ${mode === 1 ? 'active' : ''}" data-m="1">🍩 کروی</button></div>`;
  let chart;
  if (mode === 0) {
    const has = gains.some(v => v !== 0) || losses.some(v => v !== 0);
    chart = has ? chBars(buckets.map((b, i) => ({ label: b.label, full: b.full, values: [gains[i], losses[i]] })),
      [{ name: 'سود', color: CH.gain }, { name: 'زیان', color: CH.loss }], { fmt: fFull, compact: fShort }) : null;
  } else {
    const pos = Object.entries(byType).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
    const neg = Object.entries(byType).filter(([, v]) => v < 0).sort((a, b) => a[1] - b[1]);
    const slices = [
      ...pos.map(([label, value], i) => ({ label, value, color: CH.greens[Math.min(i, CH.greens.length - 1)] })),
      ...neg.map(([label, value], i) => ({ label, value: -value, color: CH.reds[Math.min(i, CH.reds.length - 1)], negative: true })),
    ];
    const net = Object.values(byType).reduce((a, v) => a + v, 0);
    chart = slices.length ? chDonut(slices, { centerTitle: net >= 0 ? 'خالص سود' : 'خالص زیان', centerValue: net, fmt: fShort }) : null;
  }
  const id = 'pcard' + (++_chSeq);
  return {
    html: `<div class="card" id="${id}"><b>${usd ? 'نمودار سود و زیان (دلاری)' : 'نمودار سود و زیان'}</b>${toggle}${chart ? chart.html : `<div class="muted" style="text-align:center;padding:24px 0">${emptyMsg}</div>`}</div>`,
    bind(onMode, onCur) {
      if (chart) chart.bind();
      const cu = document.getElementById('pc-cur');
      if (cu && onCur) cu.addEventListener('click', (e) => { const b = e.target.closest('[data-c]'); if (b) onCur(b.dataset.c === '1'); });
      const t = document.getElementById('pc-toggle');
      if (t) t.addEventListener('click', (e) => { const b = e.target.closest('[data-m]'); if (b) onMode(Number(b.dataset.m)); });
    },
  };
}

/** نمودار کروی موجودی بر اساس نوع پسته (وزن خالص) */
function stockChartHtml(items, kindLabel) {
  const data = {};
  for (const p of items) {
    const rem = Number(p.remaining) || 0;
    if (rem <= 1e-6) continue;
    const key = (p.base_type && String(p.base_type).trim()) || p.type_name;
    data[key] = (data[key] || 0) + rem;
  }
  const slices = chTopSlices(data, 5);
  const d = slices.length ? chDonut(slices, { centerTitle: 'جمع موجودی (خالص)', fmt: (v) => kg(v) }) : null;
  return {
    html: `<div class="card"><b>تفکیک موجودی بر اساس نوع پسته</b><div class="muted" style="font-size:12px;margin:2px 0 10px">${kindLabel} • وزن (خالص)</div>${d ? d.html : '<div class="muted" style="text-align:center;padding:20px 0">موجودی‌ای برای نمایش نیست</div>'}</div>`,
    bind() { if (d) d.bind(); },
  };
}

// ================================================================ طرف‌حساب‌ها: گردش واریزی/برداشت + دفتر قرض و وام
const LOAN_LABEL = { given: 'قرض اعطایی', taken: 'قرض دریافتی', repay_in: 'بازپرداخت دریافتی', repay_out: 'بازپرداخت پرداختی' };
function loanIsIn(kind) { return kind === 'taken' || kind === 'repay_in'; }
function normParty(n) { return String(n == null ? '' : n).replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, ' ').trim(); }
function numFrom(s) { return Number(toEnDigitsStr(String(s == null ? '' : s)).replace(/,/g, '')) || 0; }

/** پیام موفقیت فقط وقتی نشان داده می‌شود که سرور واقعاً ثبت کرده باشد (در حالت صف آفلاین، پیام صف خودش نشان داده شده است) */
function confirmToast(res, msg) { if (res && res.queued) return; toast('✓ ' + msg); }

function recalcParty(a) {
  a.loanReceivable = a.loanGiven - a.repaidToUs;
  a.loanPayable = a.loanTaken - a.repaidByUs;
  a.loanNet = a.loanReceivable - a.loanPayable;
  a.totalNet = (Number(a.net) || 0) + a.loanNet;
}
function partyDefaults(a) {
  a.loans = a.loans || []; a.dues = a.dues || [];
  for (const k of ['loanGiven', 'loanTaken', 'repaidToUs', 'repaidByUs']) a[k] = Number(a[k]) || 0;
  recalcParty(a);
  return a;
}

/** ثبت‌های صف‌شدهٔ قرض/طرف‌حساب را روی فهرست کش‌شده اعمال می‌کند (حالت آفلاین) */
function applyLoanQueue(out, q) {
  const find = (name) => out.items.find(x => x.key === normParty(name));
  const ensure = (name) => {
    let a = find(name);
    if (!a) { a = partyDefaults({ key: normParty(name), bought: 0, paid: 0, sold: 0, received: 0, entries: [], weOwe: 0, theyOwe: 0, net: 0 }); out.items.push(a); }
    return a;
  };
  for (const it of q) {
    const p = it.payload || {};
    if (it.action === 'parties.create') ensure(p.name);
    else if (it.action === 'loans.create') {
      const a = ensure(p.party), amt = Number(p.amount) || 0;
      if (a.loans.some(l => l.uid === p.uid)) continue;
      if (p.kind === 'given') a.loanGiven += amt; else if (p.kind === 'taken') a.loanTaken += amt;
      else if (p.kind === 'repay_in') a.repaidToUs += amt; else if (p.kind === 'repay_out') a.repaidByUs += amt;
      a.loans.unshift({ id: null, uid: p.uid, kind: p.kind, amount: amt, date: p.date, note: p.note || '', _pending: true });
      (p.dues || []).forEach((d, i) => a.dues.push({ id: null, loan_uid: p.uid, seq: i + 1, due_date: d.due_date, amount: Number(d.amount) || 0, note: d.note || '', paid: false, kind: p.kind, _pending: true }));
      recalcParty(a);
    } else if (it.action === 'loans.delete') {
      for (const a of out.items) {
        const l = a.loans.find(x => x.uid === p.uid);
        if (!l) continue;
        if (l.kind === 'given') a.loanGiven -= l.amount; else if (l.kind === 'taken') a.loanTaken -= l.amount;
        else if (l.kind === 'repay_in') a.repaidToUs -= l.amount; else if (l.kind === 'repay_out') a.repaidByUs -= l.amount;
        a.loans = a.loans.filter(x => x.uid !== p.uid); a.dues = a.dues.filter(x => x.loan_uid !== p.uid);
        recalcParty(a);
      }
    }
  }
  return out;
}

// ---- کارت «گردش واریزی و برداشت»
function cashFlowCardHtml(id) {
  return `<div class="card" id="${id}"><b>گردش واریزی و برداشت</b>
    <div class="muted" style="font-size:12px;margin:2px 0 8px">فقط تراکنش‌های انجام‌شده (تسویه‌شده)</div>
    <div class="row2"><div class="field"><label>از تاریخ</label><div id="${id}-from"></div></div>
    <div class="field"><label>تا تاریخ</label><div id="${id}-to"></div></div></div>
    <label class="cf-check"><input type="checkbox" id="${id}-loans"> با احتساب قرض و وام</label>
    <div id="${id}-body"><div class="muted">در حال بارگذاری...</div></div></div>`;
}
/** opts: { party: کلید طرف‌حساب یا null (همه), accounts: () => فهرست طرف‌حساب‌ها (برای قرض‌ها) } */
function mountCashFlow(id, opts) {
  const [jy, jm] = todayJalaliYMD();
  const from0 = jalaliYMDToIso(jy, jm, 1), to0 = jalaliYMDToIso(jy, jm, daysInJalaliMonth(jy, jm));
  mountJalaliDate(id + '-from', from0, () => draw());
  mountJalaliDate(id + '-to', to0, () => draw());
  const chk = document.getElementById(id + '-loans');
  if (chk) chk.addEventListener('change', () => draw());
  async function draw() {
    const body = document.getElementById(id + '-body'); if (!body) return;
    const from = dateVal(id + '-from'), to = dateVal(id + '-to');
    if (!from || !to) return;
    if (to < from) { body.innerHTML = '<div class="muted" style="padding:10px 0">تاریخ «تا» باید بعد از «از» باشد</div>'; return; }
    try {
      const [pay, rec] = await Promise.all([
        api('reports.tx_all', { kind: 'payments', from, to }),
        api('reports.tx_all', { kind: 'receipts', from, to }),
      ]);
      const matches = (name) => opts.party == null || normParty(name === '-' ? '' : name) === opts.party;
      const flows = [];
      for (const x of rec.items) if (Number(x.paid) === 1 && matches(x.name)) flows.push({ date: x.date, amount: Number(x.amount) || 0, isIn: true });
      for (const x of pay.items) if (Number(x.paid) === 1 && matches(x.name)) flows.push({ date: x.date, amount: Number(x.amount) || 0, isIn: false });
      if (chk && chk.checked) {
        for (const a of (opts.accounts ? opts.accounts() : [])) {
          if (opts.party != null && a.key !== opts.party) continue;
          for (const l of (a.loans || [])) if (l.date >= from && l.date <= to) flows.push({ date: l.date, amount: Number(l.amount) || 0, isIn: loanIsIn(l.kind) });
        }
      }
      if (!flows.length) { body.innerHTML = '<div class="muted" style="text-align:center;padding:22px 0">در این بازه تراکنشی انجام نشده</div>'; return; }
      const buckets = chBuckets(from, to);
      const ins = buckets.map(() => 0), outs = buckets.map(() => 0);
      let tIn = 0, tOut = 0;
      for (const f of flows) {
        if (f.isIn) tIn += f.amount; else tOut += f.amount;
        const i = chBucketIdx(buckets, f.date);
        if (i >= 0) { if (f.isIn) ins[i] += f.amount; else outs[i] += f.amount; }
      }
      const net = tIn - tOut;
      const ch = chBars(buckets.map((b, i) => ({ label: b.label, full: b.full, values: [ins[i], outs[i]] })),
        [{ name: 'واریزی', color: CH.gain }, { name: 'برداشت', color: CH.orange }], { fmt: (v) => chCompactMoney(v) });
      body.innerHTML = ch.html + `<div class="cf-totals"><div><span>جمع واریزی</span><b style="color:${CH.gain}">${esc(chCompactMoney(tIn))}</b></div>
        <div><span>جمع برداشت</span><b style="color:${CH.orange}">${esc(chCompactMoney(tOut))}</b></div>
        <div><span>خالص</span><b style="color:${net >= 0 ? CH.gain : CH.loss}">${esc(chCompactMoney(net))}</b></div></div>`;
      ch.bind();
    } catch (err) { body.innerHTML = `<div class="error">${esc(err.message)}</div>`; }
  }
  draw();
}

// ---- طرف‌حساب جدید
function openNewPartyForm(existingKeys, onDone) {
  const close = openModal(`<h2>طرف‌حساب جدید</h2>
    <div class="field"><label>نام طرف‌حساب *</label><input type="text" id="np-name" maxlength="100"></div>
    <div class="error" id="np-err"></div>
    <button class="btn btn-primary" id="np-ok">تعریف</button>
    <button class="btn btn-secondary" id="np-cancel">انصراف</button>`);
  document.getElementById('np-cancel').onclick = close;
  document.getElementById('np-ok').onclick = async (e) => {
    const err = document.getElementById('np-err'); err.textContent = '';
    const name = normParty(document.getElementById('np-name').value);
    if (!name) { err.textContent = 'نام طرف‌حساب را وارد کنید'; return; }
    if (name.length > 100) { err.textContent = 'نام طرف‌حساب حداکثر ۱۰۰ حرف باشد'; return; }
    if (existingKeys.includes(name)) { err.textContent = 'طرف‌حسابی با این نام از قبل وجود دارد'; return; }
    e.target.disabled = true;
    try {
      const res = await api('parties.create', { name });
      confirmToast(res, `طرف‌حساب «${name}» با موفقیت تعریف شد`);
      close(); if (onDone) onDone();
    } catch (ex) { err.textContent = ex.message; e.target.disabled = false; }
  };
}

// ---- ثبت قرض / بازپرداخت (تا ۲۰ سررسید برای اعطا یا دریافت قرض)
function openLoanForm(a, kind, onDone) {
  const isRepay = kind === 'repay_in' || kind === 'repay_out';
  const maxIn = a.loanReceivable > 0.5 ? a.loanReceivable : 0, maxOut = a.loanPayable > 0.5 ? a.loanPayable : 0;
  let k = kind;
  const dueRows = isRepay ? [] : [{ amount: '', date: chIsoAddDays(todayStr(), 30), note: '' }];
  const title = isRepay ? 'ثبت بازپرداخت' : (kind === 'given' ? 'اعطای قرض' : 'دریافت قرض');
  const dateLabel = () => isRepay ? 'تاریخ بازپرداخت' : (k === 'given' ? 'تاریخ اعطای قرض' : 'تاریخ دریافت قرض');
  const close = openModal(`<h2>${title}</h2><div class="muted" style="margin-bottom:8px">${esc(a.key)}</div>
    ${isRepay ? `<div class="tag-row" id="ln-dir">
      <span class="tag ${k === 'repay_in' ? 'active' : ''} ${maxIn ? '' : 'tag-disabled'}" data-k="repay_in">او به ما پس داد</span>
      <span class="tag ${k === 'repay_out' ? 'active' : ''} ${maxOut ? '' : 'tag-disabled'}" data-k="repay_out">ما به او پس دادیم</span></div>
      <div class="muted" id="ln-max" style="margin-bottom:8px"></div>` : ''}
    <div class="field"><label>مبلغ (ریال) *</label><input type="text" id="ln-amount" inputmode="decimal"></div>
    <div class="field"><label id="ln-date-label">${dateLabel()}</label><div id="ln-date"></div></div>
    <div class="field"><label>توضیحات (اختیاری)</label><input type="text" id="ln-note" maxlength="500"></div>
    ${isRepay ? '' : `<div class="section-title">سررسیدها (حداکثر ۲۰ مورد)</div>
      <div class="muted" style="font-size:12px;margin-bottom:6px">در هر سررسید یادآوری می‌شود و از صفحهٔ «سررسیدها» می‌توانید «انجام شد» را بزنید. مبلغ و تاریخ سررسید الزامی است.</div>
      <div id="ln-dues"></div>
      <div class="card-row"><span id="ln-sum"></span><button type="button" class="tag" id="ln-add">+ افزودن سررسید</button></div>`}
    <div class="muted" style="font-size:12px">این مبلغ در سود و زیان حساب نمی‌شود.</div>
    <div class="error" id="ln-err"></div>
    <button class="btn btn-primary" id="ln-ok">ثبت</button>
    <button class="btn btn-secondary" id="ln-cancel">انصراف</button>`);
  attachThousands('ln-amount');
  mountJalaliDate('ln-date', todayStr());
  document.getElementById('ln-cancel').onclick = close;
  const maxOfKind = () => (k === 'repay_in' ? maxIn : maxOut);
  const paintDir = () => {
    if (!isRepay) return;
    document.querySelectorAll('#ln-dir .tag').forEach(t => t.classList.toggle('active', t.dataset.k === k));
    document.getElementById('ln-max').textContent = maxOfKind() > 0.5 ? `مانده قابل بازپرداخت: ${toman(maxOfKind())}` : 'بدهی برای بازپرداخت وجود ندارد';
    document.getElementById('ln-date-label').textContent = dateLabel();
  };
  if (isRepay) {
    document.getElementById('ln-dir').addEventListener('click', (e) => {
      const t = e.target.closest('[data-k]'); if (!t) return;
      const mx = t.dataset.k === 'repay_in' ? maxIn : maxOut;
      if (mx <= 0.5) { toast('بدهی برای بازپرداخت وجود ندارد'); return; }
      k = t.dataset.k; paintDir();
    });
    if (maxOfKind() <= 0.5) k = maxIn > 0.5 ? 'repay_in' : 'repay_out';
    paintDir();
  }
  function syncRows() {
    dueRows.forEach((r, i) => {
      const am = document.getElementById('ln-da-' + i); if (am) r.amount = am.value;
      const nt = document.getElementById('ln-dn-' + i); if (nt) r.note = nt.value;
      r.date = dateVal('ln-dd-' + i) || r.date;
    });
  }
  function updateSum() {
    const el = document.getElementById('ln-sum'); if (!el) return;
    syncRows();
    const sum = dueRows.reduce((s, r) => s + numFrom(r.amount), 0), amt = numVal('ln-amount');
    el.textContent = `جمع سررسیدها: ${toman(sum)} از ${toman(amt)}`;
    el.className = Math.abs(sum - amt) <= 0.5 ? 'pos' : 'neg';
  }
  function drawDues() {
    const box = document.getElementById('ln-dues'); if (!box) return;
    box.innerHTML = dueRows.map((r, i) => `<div class="card" style="margin-bottom:8px">
      <div class="card-row"><b>سررسید ${toFaDigits(String(i + 1))}</b>${dueRows.length > 1 ? `<button type="button" class="tag" data-del="${i}">حذف</button>` : ''}</div>
      <div class="field"><label>مبلغ سررسید (ریال) *</label><input type="text" id="ln-da-${i}" value="${esc(r.amount)}"></div>
      <div class="field"><label>تاریخ سررسید *</label><div id="ln-dd-${i}"></div></div>
      <div class="field"><label>جزئیات (اختیاری)</label><input type="text" id="ln-dn-${i}" maxlength="200" value="${esc(r.note)}"></div></div>`).join('');
    dueRows.forEach((r, i) => {
      attachThousands('ln-da-' + i);
      mountJalaliDate('ln-dd-' + i, r.date, () => updateSum());
      document.getElementById('ln-da-' + i).addEventListener('input', updateSum);
    });
    box.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { syncRows(); dueRows.splice(Number(b.dataset.del), 1); drawDues(); }));
    updateSum();
  }
  if (!isRepay) {
    drawDues();
    document.getElementById('ln-amount').addEventListener('input', updateSum);
    document.getElementById('ln-add').onclick = () => {
      if (dueRows.length >= 20) { toast('حداکثر ۲۰ سررسید مجاز است'); return; }
      syncRows();
      dueRows.push({ amount: '', date: chIsoAddDays(dueRows[dueRows.length - 1].date, 30), note: '' });
      drawDues();
    };
  }
  document.getElementById('ln-ok').onclick = async (e) => {
    const err = document.getElementById('ln-err'); err.textContent = '';
    const amt = numVal('ln-amount');
    if (!(amt > 0)) { err.textContent = 'مبلغ را وارد کنید'; return; }
    if (isRepay) {
      const mx = maxOfKind();
      if (mx <= 0.5) { err.textContent = 'بدهی برای بازپرداخت وجود ندارد'; return; }
      if (amt > mx + 0.5) { err.textContent = `مبلغ بازپرداخت از مانده (${toman(mx)}) بیشتر است`; return; }
    }
    const dues = [];
    if (!isRepay) {
      syncRows();
      if (dueRows.length === 1 && numFrom(dueRows[0].amount) <= 0) dueRows[0].amount = String(amt); // یک سررسید بدون مبلغ = کل مبلغ قرض
      let sum = 0;
      for (let i = 0; i < dueRows.length; i++) {
        const v = numFrom(dueRows[i].amount);
        if (!(v > 0)) { err.textContent = `مبلغ سررسید ${toFaDigits(String(i + 1))} را وارد کنید`; return; }
        if (!dueRows[i].date) { err.textContent = `تاریخ سررسید ${toFaDigits(String(i + 1))} را وارد کنید`; return; }
        sum += v;
        dues.push({ amount: v, due_date: dueRows[i].date, note: String(dueRows[i].note || '').trim() || null });
      }
      if (Math.abs(sum - amt) > 0.5) { err.textContent = `جمع مبلغ سررسیدها (${toman(sum)}) باید برابر مبلغ قرض (${toman(amt)}) باشد`; return; }
    }
    const note = document.getElementById('ln-note').value.trim();
    const payload = { uid: newId(), party: a.key, kind: k, amount: amt, date: dateVal('ln-date') || todayStr(), note: note || null };
    if (dues.length) payload.dues = dues;
    e.target.disabled = true;
    try {
      const res = await api('loans.create', payload);
      confirmToast(res, `${LOAN_LABEL[k]} با موفقیت ثبت شد`);
      close(); if (onDone) onDone();
    } catch (ex) { err.textContent = ex.message; e.target.disabled = false; }
  };
}

/** چاپ/PDF دفتر قرض و وام (یک طرف‌حساب یا همه) */
function openLoanPrint(accounts) {
  const withLoans = accounts.filter(a => (a.loans || []).length);
  if (!withLoans.length) { toast('هنوز قرض یا وامی ثبت نشده است'); return; }
  const single = withLoans.length === 1;
  let recv = 0, pay = 0;
  for (const a of withLoans) { if (a.loanReceivable > 0) recv += a.loanReceivable; if (a.loanPayable > 0) pay += a.loanPayable; }
  const entryRows = withLoans.flatMap(a => a.loans.map(l => `<tr>${single ? '' : `<td>${esc(a.key)}</td>`}<td>${LOAN_LABEL[l.kind] || esc(l.kind)}</td><td>${cnt(l.amount)}</td><td>${isoToJalaliDisplay(l.date)}</td><td>${esc(l.note || '')}</td></tr>`)).join('');
  const dueRows = withLoans.flatMap(a => (a.dues || []).map(d => `<tr>${single ? '' : `<td>${esc(a.key)}</td>`}<td>${d.kind === 'given' ? 'قرض اعطایی' : 'قرض دریافتی'}</td><td>${cnt(d.amount)}</td><td>${isoToJalaliDisplay(d.due_date)}</td><td>${d.paid ? (d.kind === 'given' ? 'دریافت شد' : 'پرداخت شد') : 'در انتظار'}</td><td>${esc(d.note || '')}</td></tr>`)).join('');
  const title = single ? `دفتر قرض و وام — ${withLoans[0].key}` : 'دفتر قرض و وام — همهٔ طرف‌حساب‌ها';
  openPrintView(title, `<h1>${esc(title)}</h1><div class="sub">تاریخ تهیه گزارش: ${isoToJalaliDisplay(todayStr())} — این مبالغ در سود و زیان حساب نمی‌شوند</div>
    <div class="row"><span>مانده طلب قرضی</span><span><b>${toman(recv)}</b></span></div>
    <div class="row"><span>مانده بدهی قرضی</span><span><b>${toman(pay)}</b></span></div>
    <h1 style="margin-top:18px;font-size:15px">تراکنش‌های قرض و بازپرداخت</h1>
    <table><thead><tr>${single ? '' : '<th>طرف‌حساب</th>'}<th>نوع</th><th>مبلغ (ریال)</th><th>تاریخ</th><th>توضیحات</th></tr></thead><tbody>${entryRows}</tbody></table>
    <h1 style="font-size:15px">سررسیدها</h1>
    ${dueRows ? `<table><thead><tr>${single ? '' : '<th>طرف‌حساب</th>'}<th>نوع</th><th>مبلغ (ریال)</th><th>سررسید</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>${dueRows}</tbody></table>` : '<div class="sub">موردی ثبت نشده است</div>'}`);
}

// ---------------------------------------------------------------- شروع

if (state.phone && state.token) { boot(); }

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}
