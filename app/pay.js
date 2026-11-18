// ===================================================================
// تمدید اشتراک، پرداخت (درگاه / کارت‌به‌کارت / شبا) و قرارداد — وب
// این فایل قبل از app.js بارگذاری می‌شود و از توابع/متغیرهای سراسری app.js
// (state، esc، toman، toast، newId، boot، setTab، doLogout، removeRenewBanner، toEnDigitsStr ...) استفاده می‌کند.
// همهٔ تصمیم‌های مالی روی سرور است (pay.php)؛ این فایل فقط نمایش و ارسال است.
// ===================================================================

const PAY_STATUS_LABEL = {
  pending: 'در انتظار پرداخت', awaiting_review: 'در انتظار تأیید ادمین',
  paid: 'پرداخت‌شده ✅', rejected: 'رد شده', canceled: 'لغوشده',
};
const PAY_PENDING_KEY = 'pb_pay_order';
const PAY_PLANS = ['rial', 'fx'];
const PAY_MONTHS = [3, 6, 12];

const payUI = {
  info: null, history: [], plan: 'rial', months: 3,
  codeText: '', code: '', quote: null, quoteErr: '',
  method: null, order: null, idem: null, notice: '', paidNow: false,
};

// ---------------------------------------------------------------- ارتباط با pay.php

/** همهٔ اکشن‌های pay.* و contract.* از این تابع می‌روند. خطا: e.status، e.code، e.message (فارسی) */
async function payCall(action, payload = {}, idem) {
  if (isOffline()) throw new Error('اینترنت وصل نیست. بعد از اتصال دوباره تلاش کنید.');
  const body = { phone: state.phone, token: state.token, action, payload };
  if (idem) body.idem = idem;
  let res;
  try {
    res = await fetchWithTimeout(API_BASE + 'pay.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    }, 30000);
  } catch (err) {
    throw new Error('ارتباط با سرور برقرار نشد. اینترنت خود را بررسی کنید.');
  }
  let data;
  try { data = await res.json(); } catch { data = {}; }
  if (!res.ok) {
    const e = new Error(data.error || 'خطای ارتباط با سرور');
    e.status = res.status; e.code = data.code; e.data = data;
    if (res.status === 401) { hideContractView(); doLogout({ keepOutbox: true }); }
    throw e;
  }
  return data;
}

// STAGE11.1: مبلغ‌های اشتراک به واحد قیمت سرور (price_unit: rial|toman) هستند، نه واحد ذخیرهٔ شرکت؛ بدون تبدیل و با برچسب همان واحد
function payUnit(src) {
  const u = (src && src.price_unit) || (payUI.info && payUI.info.price_unit);
  return u === 'rial' || u === 'toman' ? u : null;
}
function payMoneyText(n, unit) {
  const v = Math.round(Number(n) || 0);
  const u = unit || payUnit();
  const f = (x) => (u ? PBFmt.num(x, 0) + ' ' + PBFmt.unitLabel(u) : toman(x));
  return v < 0 ? '−' + f(-v) : f(v);
}

// ---------------------------------------------------------------- محافظ قرارداد (بعد از ورود)

function hideContractView() {
  const v = document.getElementById('contract-view');
  v.classList.add('hidden');
  v.innerHTML = '';
}

/**
 * قبل از ورود به برنامه صدا زده می‌شود (boot). کاربر تا قرارداد فعلی را نپذیرد resolve نمی‌شود.
 * محدودیت فعلی: این قفل فقط سمت کلاینت است؛ داده‌های bootstrap قبل از پذیرش گرفته می‌شود.
 */
function pbContractGate() {
  return new Promise((resolve) => {
    payCall('contract.get').then((d) => {
      if (!d.item || d.accepted) { hideContractView(); resolve(true); return; }
      showContractForAccept(d.item, resolve);
    }).catch((e) => showContractError(e.message, resolve));
  });
}

function showContractForAccept(item, resolve) {
  document.getElementById('login-view').classList.add('hidden');
  document.getElementById('app-view').classList.add('hidden');
  const v = document.getElementById('contract-view');
  v.classList.remove('hidden');
  v.innerHTML = `<div class="login-box contract-box">
    <h1>قرارداد استفاده</h1>
    <p class="muted">${esc(item.title || 'قرارداد استفاده از پسته بازار')} — نسخهٔ ${toFaDigits(String(item.version))}</p>
    <div class="contract-text">${esc(item.body || '')}</div>
    <label class="contract-check"><input type="checkbox" id="ct-agree"> متن قرارداد را خوانده‌ام و شرایط آن را می‌پذیرم.</label>
    <div id="ct-err" class="error"></div>
    <button type="button" id="ct-accept" disabled>پذیرش و ادامه</button>
    <div class="login-links" style="justify-content:center"><button type="button" id="ct-logout" class="link-btn">خروج از حساب</button></div>
  </div>`;
  const cb = document.getElementById('ct-agree');
  const btn = document.getElementById('ct-accept');
  const err = document.getElementById('ct-err');
  cb.onchange = () => { btn.disabled = !cb.checked; };
  btn.onclick = () => {
    btn.disabled = true; err.textContent = '';
    payCall('contract.accept', {
      version: item.version, platform: 'web', device: (navigator.userAgent || '').slice(0, 200),
    }).then(() => { hideContractView(); resolve(true); })
      .catch((e) => {
        if (e.code === 'contract_outdated') { resolve(pbContractGate()); return; } // نسخهٔ جدید: دوباره بخوان
        err.textContent = e.message; btn.disabled = !cb.checked;
      });
  };
  document.getElementById('ct-logout').onclick = () => { hideContractView(); doLogout(); };
}

function showContractError(msg, resolve) {
  document.getElementById('login-view').classList.add('hidden');
  document.getElementById('app-view').classList.add('hidden');
  const v = document.getElementById('contract-view');
  v.classList.remove('hidden');
  v.innerHTML = `<div class="login-box contract-box">
    <h1>قرارداد استفاده</h1>
    <p class="error">${esc(msg)}</p>
    <button type="button" id="ct-retry">تلاش دوباره</button>
    <div class="login-links" style="justify-content:center"><button type="button" id="ct-logout" class="link-btn">خروج از حساب</button></div>
  </div>`;
  document.getElementById('ct-retry').onclick = () => { resolve(pbContractGate()); };
  document.getElementById('ct-logout').onclick = () => { hideContractView(); doLogout(); };
}

// ---------------------------------------------------------------- صفحهٔ تمدید

/** از دکمه‌های «تمدید اشتراک» (صفحهٔ قفل و تنظیمات) صدا زده می‌شود؛ حتی برای اشتراک منقضی */
async function openRenewal() {
  document.getElementById('login-view').classList.add('hidden');
  document.getElementById('contract-view').classList.add('hidden');
  document.getElementById('app-view').classList.remove('hidden');
  document.querySelectorAll('.fab').forEach((f) => f.remove());
  removeRenewBanner();
  document.getElementById('page-title').textContent = 'تمدید اشتراک';
  document.querySelectorAll('.tab').forEach((b) => b.classList.remove('active'));
  const c = document.getElementById('content');
  c.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
  try {
    const d = await payCall('pay.info');
    payUI.info = d.item;
    payUI.notice = ''; payUI.paidNow = false; payUI.pendingId = ''; payUI.order = null; payUI.idem = null; payUI.method = null;
    payUI.code = ''; payUI.codeText = ''; payUI.quoteErr = '';
    const info = payUI.info;
    payUI.plan = payDefaultPlan(info);
    payUI.months = payDefaultMonths(payUI.plan, info);
    try { payUI.history = (await payCall('pay.history')).items || []; } catch { payUI.history = []; }
    await payCheckPendingGateway();
    await payRefreshQuote();
    paintRenewal();
  } catch (e) {
    c.innerHTML = `<div class="empty-state"><p>${esc(e.message)}</p>
      <button class="btn" id="pay-retry">تلاش دوباره</button>
      <button class="btn btn-secondary" id="pay-back">بازگشت</button></div>`;
    document.getElementById('pay-retry').onclick = openRenewal;
    document.getElementById('pay-back').onclick = () => boot();
  }
}

function payPriceOf(plan, m) {
  const p = payUI.info && payUI.info.prices && payUI.info.prices[plan];
  return (p && p[String(m)]) || null;
}
function payDefaultPlan(info) {
  if (info.account && info.account.active && info.account.plan === 'fx') return 'fx';
  if (info.upgrade) return 'fx';
  return info.can_buy_rial ? 'rial' : 'fx';
}
function payDefaultMonths(plan, info) {
  if (plan === 'fx' && info.upgrade) return 0;
  return 3;
}
function payPlanEnabled(plan) {
  const info = payUI.info;
  if (plan === 'rial') return !!info.can_buy_rial;
  return !!info.upgrade || PAY_MONTHS.some((m) => payPriceOf('fx', m));
}
/** گزینه‌های مدت برای پلن؛ ۰ = فقط ارتقا (فقط وقتی ریالی فعال است و ارزی می‌خرد) */
function payMonthOptions(plan) {
  const opts = [];
  if (plan === 'fx' && payUI.info.upgrade) opts.push(0);
  PAY_MONTHS.forEach((m) => opts.push(m));
  return opts;
}
function payMonthEnabled(plan, m) {
  if (m === 0) return true;
  return !!payPriceOf(plan, m);
}

async function payRefreshQuote() {
  payUI.quote = null; payUI.quoteErr = '';
  if (!payUI.plan || payUI.months == null) return;
  if (payUI.months === 0 && !payUI.info.upgrade) return;
  if (!payMonthEnabled(payUI.plan, payUI.months)) return;
  const base = { plan: payUI.plan, months: payUI.months };
  try {
    const d = await payCall(payUI.code ? 'pay.code_check' : 'pay.quote', payUI.code ? { ...base, code: payUI.code } : base);
    payUI.quote = d.item;
  } catch (e) {
    if (payUI.code) {
      // کد نامعتبر: پیام را نشان بده و قیمت بدون کد را بگیر
      payUI.quoteErr = e.message;
      payUI.code = '';
      try { payUI.quote = (await payCall('pay.quote', base)).item; } catch (e2) { payUI.quoteErr = e2.message; }
    } else {
      payUI.quoteErr = e.message;
    }
  }
}

function paintRenewal() {
  const c = document.getElementById('content');
  const info = payUI.info;
  const acc = info.account || {};
  const planName = acc.plan ? (PAY_LABEL_SHORT[acc.plan] || acc.plan) : '—';
  let statusTxt;
  if (!acc.active && !acc.expired) statusTxt = 'اشتراک فعال ندارید';
  else if (acc.expired) statusTxt = 'منقضی شده — اطلاعات شما حفظ شده است';
  else statusTxt = `فعال تا ${esc(acc.expiry_jalali || '')} (${toFaDigits(String(acc.days_left ?? ''))} روز مانده)`;

  const q = payUI.quote;
  const total = q ? Number(q.total) || 0 : 0;

  // گزینه‌ها
  const planChips = PAY_PLANS.map((p) => {
    const en = payPlanEnabled(p);
    return `<button type="button" class="pay-chip ${payUI.plan === p ? 'on' : ''}" data-plan="${p}" ${en ? '' : 'disabled'}>
      ${PAY_LABEL_SHORT[p]}${!en ? '<small>(فعلاً در دسترس نیست)</small>' : ''}</button>`;
  }).join('');
  const monthChips = payMonthOptions(payUI.plan).map((m) => {
    const en = payMonthEnabled(payUI.plan, m);
    let price = '';
    if (m === 0) price = payMoneyText(info.upgrade ? info.upgrade.amount : 0);
    else { const pr = payPriceOf(payUI.plan, m); if (pr) price = payMoneyText(pr.final); }
    const label = m === 0 ? 'فقط ارتقا به ارزی' : `${toFaDigits(String(m))} ماهه`;
    return `<button type="button" class="pay-chip pay-chip-wide ${payUI.months === m ? 'on' : ''}" data-months="${m}" ${en ? '' : 'disabled'}>
      <b>${label}</b><small>${en ? esc(price) : 'به‌زودی'}</small></button>`;
  }).join('');
  const discountNote = (() => {
    if (payUI.months === 0) return '';
    const pr = payPriceOf(payUI.plan, payUI.months);
    if (!pr || !(pr.normal > pr.final)) return '';
    return `<div class="pay-save">صرفه‌جویی شما: ${payMoneyText(pr.normal - pr.final)}</div>`;
  })();

  // خلاصهٔ سفارش
  let summary = '';
  if (payUI.quoteErr) summary = `<div class="error">${esc(payUI.quoteErr)}</div>`;
  else if (!q) summary = '<div class="muted" style="font-size:13px">برای دیدن مبلغ، پلن و مدت را انتخاب کنید.</div>';
  else {
    const s = q.summary || {};
    const lines = (s.lines || []).map((l) => `<div class="card-row ${l.kind === 'total' ? 'pay-total' : ''}">
      <span>${esc(l.label)}</span><span>${payMoneyText(l.amount)}</span></div>`).join('');
    const notes = (s.notes || []).map((n) => `<div class="pay-note">• ${esc(n)}</div>`).join('');
    const newExp = s.new_expiry_jalali ? `<div class="pay-note">اعتبار تا: <b>${esc(s.new_expiry_jalali)}</b></div>` : '';
    summary = `<div class="card">${lines || `<div class="card-row"><span>مبلغ قابل پرداخت</span><span>${payMoneyText(total)}</span></div>`}${newExp}${notes}</div>`;
  }

  // روش‌های پرداخت
  const m = info.methods || {};
  let pay = '';
  if (!q || payUI.quoteErr) {
    pay = '';
  } else if (total === 0) {
    pay = `<button type="button" class="btn" data-act="free">✅ ثبت رایگان (با کد تخفیف)</button>`;
  } else {
    const btns = [];
    if (m.gateway) btns.push(`<button type="button" class="pay-method ${payUI.method === 'gateway' ? 'on' : ''}" data-method="gateway">💳 درگاه زرین‌پال<small>فعال‌سازی فوری — سریع‌تر است</small></button>`);
    if (m.card) btns.push(`<button type="button" class="pay-method ${payUI.method === 'card' ? 'on' : ''}" data-method="card">🏦 کارت‌به‌کارت<small>بعد از ثبت، ادمین بررسی می‌کند</small></button>`);
    if (m.sheba) btns.push(`<button type="button" class="pay-method ${payUI.method === 'sheba' ? 'on' : ''}" data-method="sheba">🔁 شبا<small>بعد از ثبت، ادمین بررسی می‌کند</small></button>`);
    pay = btns.length ? btns.join('') : '<div class="error">روش پرداخت فعالی وجود ندارد؛ با پشتیبانی تماس بگیرید.</div>';
  }

  let methodBody = '';
  if (q && !payUI.quoteErr && total > 0 && payUI.method === 'gateway') {
    methodBody = `<div class="pay-box">
      <p>با زدن دکمه، به صفحهٔ امن زرین‌پال می‌روید. بعد از پرداخت، اشتراک خودکار فعال می‌شود.</p>
      <button type="button" class="btn" data-act="gateway">پرداخت با درگاه زرین‌پال — ${payMoneyText(total)}</button>
      <div class="muted" style="font-size:12px;margin-top:8px">اگر بعد از پرداخت به برنامه برگشتید و فعال نشد، همین صفحه «بررسی پرداخت» را نشان می‌دهد.</div>
    </div>`;
  } else if (q && !payUI.quoteErr && total > 0 && (payUI.method === 'card' || payUI.method === 'sheba')) {
    methodBody = payManualHtml(payUI.method, total);
  }

  const hist = (payUI.history || []).slice(0, 10).map((o) => {
    const kind = o.order_type === 'upgrade' ? 'ارتقا به ارزی' : `${PAY_LABEL_SHORT[o.plan] || o.plan} ${o.months ? toFaDigits(String(o.months)) + ' ماهه' : ''}`;
    const st = PAY_STATUS_LABEL[o.status] || o.status;
    return `<div class="card-row"><span>${esc(o.created_jalali || '')}<br><small class="muted">${esc(kind)}</small></span>
      <span style="text-align:left">${payMoneyText(o.total)}<br><small class="muted">${esc(st)}${o.reject_reason ? ' — ' + esc(o.reject_reason) : ''}</small>${o.status === 'paid' ? `<br><button type="button" class="link-btn" data-invoice="${esc(o.id)}">🧾 فاکتور</button>` : ''}</span></div>`;
  }).join('');

  c.innerHTML = `<div class="pay-wrap">
    ${payUI.notice ? `<div class="pay-notice">${esc(payUI.notice)}</div>` : ''}
    ${payUI.paidNow ? '<button type="button" class="btn" id="pay-enter">ورود به برنامه</button>' : ''}
    ${payUI.pendingId ? '<button type="button" class="btn btn-secondary" id="pay-recheck">🔄 بررسی پرداخت</button>' : ''}
    <div class="card">
      <div class="card-row"><span>پلن فعلی</span><span>${esc(planName)}</span></div>
      <div class="card-row"><span>وضعیت</span><span>${statusTxt}</span></div>
    </div>
    <div class="pay-hint">اشتراک تمدید یا خرید می‌شود؛ <b>هیچ استردادی وجود ندارد</b>. اطلاعات شما حفظ می‌شود.</div>

    <div class="section-title">۱. پلن</div>
    <div class="pay-chips">${planChips}</div>
    <div class="section-title">۲. مدت</div>
    <div class="pay-chips pay-chips-col">${monthChips}</div>
    ${discountNote}

    <div class="section-title">۳. کد تخفیف (اختیاری)</div>
    <div class="pay-code">
      <input type="text" id="pay-code" value="${esc(payUI.codeText)}" maxlength="40" placeholder="کد تخفیف را وارد کنید">
      <button type="button" class="btn btn-secondary" id="pay-code-apply">اعمال</button>
    </div>

    <div class="section-title">۴. خلاصهٔ سفارش</div>
    ${summary}

    <div class="section-title">۵. روش پرداخت</div>
    ${pay ? `<div class="pay-methods">${pay}</div>` : ''}
    ${methodBody}
    <div id="pay-err" class="error"></div>

    ${hist ? `<div class="section-title">سفارش‌های من</div><div class="card">${hist}</div>` : ''}
    <button type="button" class="btn btn-secondary" id="pay-back">بازگشت</button>
  </div>`;
  payBind();
}

const PAY_LABEL_SHORT = { rial: 'ریالی', fx: 'ارزی' };

function payHourOpts() {
  const h = new Date().getHours();
  let out = '';
  for (let i = 0; i < 24; i++) out += `<option value="${i}" ${i === h ? 'selected' : ''}>${toFaDigits(pad2(i))}</option>`;
  return out;
}
function payMinOpts() {
  const m = new Date().getMinutes();
  let out = '';
  for (let i = 0; i < 60; i++) out += `<option value="${i}" ${i === m ? 'selected' : ''}>${toFaDigits(pad2(i))}</option>`;
  return out;
}

function payManualHtml(method, total) {
  const banks = (payUI.info.banks || []);
  const list = banks.length ? banks.map((b) => {
    const num = method === 'card' ? b.card_number : b.sheba;
    const label = method === 'card' ? 'شمارهٔ کارت' : 'شماره شبا';
    return `<div class="pay-bank">
      <div><b>${esc(b.bank_name || '')}</b>${b.owner_name ? ` — ${esc(b.owner_name)}` : ''}</div>
      ${num ? `<div class="pay-num">${label}: <span dir="ltr">${esc(num)}</span>
        <button type="button" class="link-btn" data-copy="${esc(num)}">📋 کپی</button></div>` : ''}
      ${b.note ? `<div class="muted" style="font-size:12px">${esc(b.note)}</div>` : ''}
    </div>`;
  }).join('') : '<div class="error">اطلاعات حساب فعلاً ثبت نشده؛ با پشتیبانی تماس بگیرید.</div>';
  return `<div class="pay-box">
    <p>مبلغ <b>${payMoneyText(total)}</b> را به حساب زیر واریز کنید، سپس فرم را پر کنید.</p>
    ${list}
    <form id="pay-manual" class="pay-form" novalidate>
      <label>نام پرداخت‌کننده</label>
      <input type="text" name="payer_name" maxlength="60" required>
      <label>۴ رقم آخر کارتی که با آن پرداخت کرده‌اید</label>
      <input type="tel" name="card_last4" inputmode="numeric" maxlength="4" required>
      <label>تاریخ پرداخت</label>
      <div id="pay-date"></div>
      <label>ساعت و دقیقهٔ پرداخت</label>
      <div class="pay-time">
        <select name="p_hour" aria-label="ساعت">${payHourOpts()}</select>
        <span>:</span>
        <select name="p_min" aria-label="دقیقه">${payMinOpts()}</select>
      </div>
      <label>مبلغ واریزی (${payUnit() ? PBFmt.unitLabel(payUnit()) : 'تومان'})</label>
      <input type="tel" name="amount" inputmode="numeric" value="${total}" required>
      <label>شمارهٔ پیگیری (اختیاری)</label>
      <input type="text" name="tracking_no" maxlength="40">
      <div id="pay-manual-err" class="error"></div>
      <button type="submit" class="btn">✅ پرداخت کردم — ثبت برای بررسی</button>
    </form>
  </div>`;
}

function payBind() {
  const on = (sel, fn) => document.querySelectorAll(sel).forEach((el) => { el.onclick = fn; });
  on('[data-plan]', async (ev) => {
    const p = ev.currentTarget.dataset.plan;
    if (p === payUI.plan) return;
    payUI.plan = p;
    payResetSelection();
    if (!payMonthOptions(p).includes(payUI.months) || !payMonthEnabled(p, payUI.months)) payUI.months = payDefaultMonths(p, payUI.info);
    await payRefreshQuote();
    paintRenewal();
  });
  on('[data-months]', async (ev) => {
    const m = Number(ev.currentTarget.dataset.months);
    if (m === payUI.months) return;
    payUI.months = m;
    payResetSelection();
    await payRefreshQuote();
    paintRenewal();
  });
  on('#pay-code-apply', async () => {
    payUI.codeText = toEnDigitsStr(document.getElementById('pay-code').value).trim();
    payUI.code = payUI.codeText;
    payResetSelection();
    await payRefreshQuote();
    paintRenewal();
  });
  on('[data-method]', (ev) => {
    const mth = ev.currentTarget.dataset.method;
    if (mth !== payUI.method) { payUI.order = null; payUI.idem = null; }
    payUI.method = mth;
    paintRenewal();
  });
  on('[data-act="gateway"]', (ev) => payGateway(ev.currentTarget));
  on('[data-act="free"]', (ev) => payFree(ev.currentTarget));
  on('[data-copy]', (ev) => payCopy(ev.currentTarget.dataset.copy));
  on('#pay-back', () => boot());
  on('[data-invoice]', (ev) => payInvoice(ev.currentTarget.dataset.invoice));
  on('#pay-enter', () => boot());
  on('#pay-recheck', (ev) => payRecheck(ev.currentTarget));
  const form = document.getElementById('pay-manual');
  if (form) { mountJalaliDate('pay-date'); form.onsubmit = payManualSubmit; }
}

/** با تغییر پلن/مدت/کد/روش، سفارش و کلید ارسال قبلی دور ریخته می‌شود */
function payResetSelection() {
  payUI.order = null; payUI.idem = null; payUI.notice = ''; payUI.paidNow = false;
}

/** فاکتور اشتراک: داده از pay.invoice؛ چاپ/ذخیره به‌صورت PDF از پنجرهٔ چاپ مرورگر */
async function payInvoice(id) {
  try {
    const inv = (await payCall('pay.invoice', { order_id: id })).invoice;
    const row = (k, v) => `<div class="row"><span>${esc(k)}</span><span>${v}</span></div>`;
    const lines = (inv.lines || []).filter((l) => l.kind !== 'total').map((l) => row(l.label, payMoneyText(l.amount, inv.price_unit))).join('');
    const periods = (inv.periods || []).map((p) => `<div>• ${esc(p.label || '')}</div>`).join('');
    const party = (t, d, keys) => `<div><b>${t}</b><br>${keys.filter((k) => d && d[k]).map((k) => esc(d[k])).join('<br>')}</div>`;
    openPrintView('فاکتور اشتراک ' + inv.number, `<h1>فاکتور اشتراک</h1>
      <div class="sub">شمارهٔ فاکتور: ${esc(inv.number)} — تاریخ: ${esc(inv.date_jalali || '')}</div>
      <div style="display:flex;gap:24px;margin:12px 0">${party('فروشنده', inv.seller, ['name', 'phone', 'address', 'economic_code'])}${party('خریدار', inv.buyer, ['name', 'phone'])}</div>
      ${lines}
      <div class="row"><span><b>جمع کل (قابل پرداخت)</b></span><span><b>${payMoneyText(inv.total, inv.price_unit)}</b></span></div>
      ${periods ? `<div style="margin-top:10px"><b>دوره‌های خریداری‌شده:</b>${periods}</div>` : ''}
      ${inv.new_expiry_jalali ? `<div>اعتبار اشتراک تا: ${esc(inv.new_expiry_jalali)}</div>` : ''}
      ${inv.ref_id ? `<div>کد پیگیری بانک: ${esc(inv.ref_id)}</div>` : ''}
      <div class="sub" style="margin-top:18px">${esc(inv.footer || '')}</div>`);
  } catch (e) {
    toast(e.message);
  }
}

function payCopy(text) {
  try {
    navigator.clipboard.writeText(text).then(() => toast('کپی شد'), () => toast('کپی انجام نشد؛ دستی کپی کنید'));
  } catch { toast('کپی انجام نشد؛ دستی کپی کنید'); }
}

function payRememberPending(id) { try { localStorage.setItem(PAY_PENDING_KEY, id); } catch {} }
function payForgetPending() { try { localStorage.removeItem(PAY_PENDING_KEY); } catch {} }

/** سفارش ساخته‌شده (با idem یکتا؛ ارسال دوباره همان سفارش را برمی‌گرداند) */
async function payCreateOrder(method) {
  if (!payUI.idem) payUI.idem = newId();
  const p = { plan: payUI.plan, months: payUI.months, method };
  if (payUI.code) p.code = payUI.code;
  const r = await payCall('pay.create_order', p, payUI.idem);
  payUI.order = r.order;
  return r;
}

function payErr(msg) {
  const el = document.getElementById('pay-err');
  if (el) el.textContent = msg;
  else toast(msg);
}

function payDoneAndEnter(msg) {
  payUI.order = null; payUI.idem = null;
  toast(msg);
  boot();
}

async function payGateway(btn) {
  payErr('');
  btn.disabled = true;
  try {
    const r = payUI.order ? { order: payUI.order } : await payCreateOrder('gateway');
    if (r.order && r.order.status === 'paid') { payDoneAndEnter('اشتراک شما فعال شد'); return; }
    let url = r.pay_url;
    if (!url) url = (await payCall('pay.start_gateway', { order_id: r.order.id })).pay_url;
    payRememberPending(r.order.id);
    window.location.href = url;
  } catch (e) {
    payErr(e.message);
    btn.disabled = false;
  }
}

async function payFree(btn) {
  payErr('');
  btn.disabled = true;
  try {
    const r = await payCreateOrder('gateway');
    if (r.order && r.order.status === 'paid') { payDoneAndEnter('اشتراک شما با کد تخفیف فعال شد'); return; }
    payErr('ثبت سفارش انجام نشد؛ دوباره تلاش کنید.');
    btn.disabled = false;
  } catch (e) {
    payErr(e.message);
    btn.disabled = false;
  }
}

async function payManualSubmit(ev) {
  ev.preventDefault();
  const f = ev.currentTarget;
  const btn = f.querySelector('button[type="submit"]');
  const errEl = document.getElementById('pay-manual-err');
  const v = (name) => toEnDigitsStr(f.elements[name].value).trim();
  const payer = f.elements.payer_name.value.trim();
  const last4 = v('card_last4');
  const amount = v('amount');
  const tracking = v('tracking_no');
  errEl.textContent = '';
  if (payer.length < 2) { errEl.textContent = 'نام پرداخت‌کننده را وارد کنید'; return; }
  if (!/^\d{4}$/.test(last4)) { errEl.textContent = 'ارقام آخر کارت باید ۴ رقم باشد'; return; }
  const dateIso = dateVal('pay-date');
  if (!dateIso) { errEl.textContent = 'تاریخ پرداخت را انتخاب کنید'; return; }
  const [gy, gm, gd] = dateIso.split('-').map(Number);
  const hh = Number(f.elements.p_hour.value);
  const mi = Number(f.elements.p_min.value);
  const when = new Date(gy, gm - 1, gd, hh, mi).getTime();
  const nowMs = Date.now();
  if (when > nowMs + 10 * 60 * 1000) { errEl.textContent = 'زمان پرداخت نمی‌تواند در آینده باشد'; return; }
  if (when < nowMs - 30 * 86400 * 1000) { errEl.textContent = 'زمان پرداخت خیلی قدیمی است؛ با پشتیبانی تماس بگیرید'; return; }
  const [jy, jm, jd] = isoToJalaliYMD(dateIso);
  const paidAt = `${jy}/${pad2(jm)}/${pad2(jd)} ${pad2(hh)}:${pad2(mi)}`;
  if (!/^\d+$/.test(amount) || Number(amount) <= 0) { errEl.textContent = 'مبلغ واریزی را درست وارد کنید'; return; }
  btn.disabled = true;
  try {
    if (!payUI.order) await payCreateOrder(payUI.method);
    const r = await payCall('pay.submit_manual', {
      order_id: payUI.order.id, method: payUI.method, payer_name: payer, card_last4: last4,
      paid_at: paidAt, amount: Number(amount), tracking_no: tracking || undefined,
    });
    payUI.order = null; payUI.idem = null;
    if (r.order && r.order.status === 'paid') { payDoneAndEnter('اشتراک شما فعال شد'); return; }
    payUI.notice = r.message || 'اطلاعات پرداخت ثبت شد. بعد از بررسی ادمین اشتراک شما فعال می‌شود.';
    payUI.method = null;
    await openRenewalRefresh();
  } catch (e) {
    errEl.textContent = e.message;
    btn.disabled = false;
  }
}

/** بعد از ثبت، اطلاعات (تاریخچه/حساب) را تازه کن ولی صفحه را دوباره از اول نساز */
async function openRenewalRefresh() {
  try {
    payUI.info = (await payCall('pay.info')).item;
    payUI.history = (await payCall('pay.history')).items || [];
  } catch {}
  paintRenewal();
}

/**
 * برگشت از درگاه: اگر سفارش درگاهی باز مانده، وضعیتش را از سرور بپرس.
 * فعال‌سازی فقط با verify سرور است؛ برگشت صفحه معیار نیست.
 */
async function payCheckPendingGateway() {
  let id = '';
  try { id = localStorage.getItem(PAY_PENDING_KEY) || ''; } catch {}
  payUI.pendingId = '';
  if (!id) return;
  try {
    const r = await payCall('pay.check_payment', { order_id: id });
    if (r.result === 'paid') { payForgetPending(); payUI.notice = 'پرداخت شما تأیید شد و اشتراک فعال است.'; payUI.paidNow = true; }
    else if (r.result === 'review') { payForgetPending(); payUI.notice = 'پول شما دریافت شد ولی فعال‌سازی خودکار انجام نشد. ادمین بررسی و فعال می‌کند.'; }
    else if (r.result === 'pending') { payUI.notice = 'پرداخت هنوز تأیید نشده است. اگر پول کم شده، چند دقیقه بعد دوباره «بررسی پرداخت» را بزنید.'; payUI.pendingId = id; }
    else { payForgetPending(); payUI.notice = 'این پرداخت تأیید نشد. اگر پول از حسابتان کم شده، با پشتیبانی تماس بگیرید.'; }
  } catch (e) {
    payUI.notice = e.message;
    payUI.pendingId = id;
  }
}

async function payRecheck(btn) {
  btn.disabled = true;
  await payCheckPendingGateway();
  await openRenewalRefresh();
}
