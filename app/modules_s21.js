// S21.T21.2 — web views: warehouses + ounces, stock balance report, sale/purchase returns, proformas.
// Read-only, uses existing server actions only (entity.list, reports.stock_balance, *_returns.list, proformas.list).
// Registered handlers are called by shell.js openModule(key, container). Server enforces perms/edition.
(function () {
  'use strict';

  const H = {}; // key -> async function(container)

  function table(headers, rows) {
    if (!rows.length) return '<div class="empty-state">موردی ثبت نشده است</div>';
    return '<div class="table-wrap"><table class="mini-table"><thead><tr>'
      + headers.map((h) => '<th>' + esc(h) + '</th>').join('')
      + '</tr></thead><tbody>'
      + rows.map((r) => '<tr>' + r.map((c) => '<td>' + c + '</td>').join('') + '</tr>').join('')
      + '</tbody></table></div>';
  }
  function seg(items, active) {
    return '<div class="seg">' + items.map((it) =>
      '<button type="button" class="seg-btn' + (it.v === active ? ' active' : '') + '" data-v="' + it.v + '">' + esc(it.label) + '</button>'
    ).join('') + '</div>';
  }
  function bindSeg(container, onPick) {
    container.querySelectorAll('.seg-btn').forEach((b) => b.addEventListener('click', () => onPick(b.dataset.v)));
  }
  function jd(iso) { return iso ? isoToJalaliDisplay(iso) : '—'; }
  function num(n) { return (Number(n) || 0).toLocaleString('fa-IR', { maximumFractionDigits: 3 }); }

  // ---------------------------------------------------------------- انبار و اونس (ops.warehouses)
  const WH_TYPES = { main: 'اصلی', consignment: 'امانی', processing: 'فرآوری', virtual: 'مجازی' };
  H['ops.warehouses'] = async function (c) {
    let view = 'warehouses';
    async function draw() {
      c.innerHTML = seg([{ v: 'warehouses', label: 'انبارها' }, { v: 'ounces', label: 'درجه‌بندی اونس' }], view) + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { view = v; draw().catch(showErr); });
      const body = c.querySelector('#s21-body');
      if (view === 'warehouses') {
        const r = await api('entity.list', { entity: 'warehouses', limit: 200 });
        body.innerHTML = table(['نام', 'نوع', 'فعال', 'پیش‌فرض', 'نشانی'], (r.items || []).map((w) => [
          esc(w.name), esc(WH_TYPES[w.type] || w.type || ''), w.active ? '✔' : '—', w.is_default ? '★' : '—', esc(w.address || ''),
        ]));
      } else {
        const r = await api('entity.list', { entity: 'ounces', limit: 200 });
        body.innerHTML = table(['کد', 'از', 'تا', 'توضیح'], (r.items || []).map((o) => [
          esc(o.code), num(o.from_val), num(o.to_val), esc(o.note || ''),
        ]));
      }
    }
    await draw();
  };

  // ---------------------------------------------------------------- گزارش موجودی (core.reports)
  H['core.reports'] = async function (c) {
    let by = 'warehouse';
    const LBL = { warehouse: 'انبار', ounce: 'اونس', type: 'نوع' };
    async function draw() {
      c.innerHTML = seg([{ v: 'warehouse', label: 'انبار' }, { v: 'ounce', label: 'اونس' }, { v: 'type', label: 'نوع' }], by)
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { by = v; draw().catch(showErr); });
      const r = await api('reports.stock_balance', { by });
      const t = r.total || { kg: 0, small_sacks: 0, large_sacks: 0 };
      c.querySelector('#s21-body').innerHTML = table(['گروه ' + LBL[by], 'کیلو', 'کیسه کوچک', 'کیسه بزرگ', 'تعداد لات'],
        (r.items || []).map((x) => [esc(x.label), num(x.kg), num(x.small_sacks), num(x.large_sacks), num(x.lots)]))
        + '<div class="report-total">جمع کل: ' + num(t.kg) + ' کیلو</div>';
    }
    await draw();
  };

  // ---------------------------------------------------------------- برگشتی‌ها (ops.returns)
  H['ops.returns'] = async function (c) {
    let kind = 'sale';
    async function draw() {
      c.innerHTML = seg([{ v: 'sale', label: 'برگشت از فروش' }, { v: 'purchase', label: 'برگشت به خرید' }], kind)
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { kind = v; draw().catch(showErr); });
      const action = kind === 'sale' ? 'sale_returns.list' : 'purchase_returns.list';
      const r = await api(action, {});
      c.querySelector('#s21-body').innerHTML = table(['شماره', 'تاریخ', 'طرف حساب', 'مبلغ کل', 'تسویه'],
        (r.items || []).map((x) => [num(x.number), jd(x.date), esc(x.party || ''), toman(x.total), esc(x.refund_mode || '—')]));
    }
    await draw();
  };

  // ---------------------------------------------------------------- پیش‌فاکتور (ops.proforma)
  const PF_STATUS = { draft: 'پیش‌نویس', sent: 'ارسال‌شده', accepted: 'تأیید‌شده', rejected: 'رد‌شده', expired: 'منقضی', converted: 'تبدیل‌شده' };
  H['ops.proforma'] = async function (c) {
    const r = await api('proformas.list', {});
    c.innerHTML = table(['شماره', 'تاریخ', 'طرف حساب', 'اعتبار تا', 'وضعیت', 'مبلغ کل'],
      (r.items || []).map((x) => [num(x.number), jd(x.date), esc(x.party || ''), jd(x.valid_until), esc(PF_STATUS[x.status] || x.status || ''), toman(x.total)]));
  };

  // ---------------------------------------------------------------- چک‌ها (cheques.list) — S06, فقط خواندنی
  const CHQ_STATUS = { in_hand: 'نزد ما', deposited: 'در جریان', cleared: 'پاس‌شده', bounced: 'برگشتی', endorsed: 'خرج‌شده',
    returned_to_payer: 'عودت‌شده', pending: 'در انتظار', cancelled: 'ابطال‌شده' };
  H['ops.cheques'] = async function (c) {
    let dir = 'received';
    async function draw() {
      c.innerHTML = seg([{ v: 'received', label: 'دریافتنی' }, { v: 'issued', label: 'پرداختنی' }], dir)
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { dir = v; draw().catch(showErr); });
      const r = await api('cheques.list', { direction: dir });
      const body = c.querySelector('#s21-body');
      const sum = (r.summary || []).filter((s) => s.direction === dir);
      body.innerHTML = '<div class="section-title">خلاصه وضعیت</div>'
        + table(['وضعیت', 'تعداد', 'مبلغ'], sum.map((s) => [esc(CHQ_STATUS[s.status] || s.status), num(s.count), toman(s.amount)]))
        + '<div class="section-title">فهرست چک‌ها</div>'
        + table(['شماره', 'بانک', 'مبلغ', 'سررسید', 'طرف حساب', 'وضعیت'], (r.items || []).map((x) => [
          esc(x.number || ''), esc(x.bank || ''), toman(x.amount), jd(x.due_date), esc(x.party || ''), esc(x.status_label || CHQ_STATUS[x.status] || x.status),
        ]));
    }
    await draw();
  };

  // ---------------------------------------------------------------- کارگزاران و کمیسیون (broker_rules / broker_payments) — S05, فقط خواندنی
  const BASIS = { percent: 'درصد', per_kg: 'ریال هر کیلو', fixed: 'مبلغ ثابت' };
  H['ops.brokers'] = async function (c) {
    let tab = 'brokers';
    async function draw() {
      c.innerHTML = seg([{ v: 'brokers', label: 'کارگزاران' }, { v: 'rules', label: 'قوانین کمیسیون' }, { v: 'payments', label: 'پرداخت‌ها' }], tab)
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { tab = v; draw().catch(showErr); });
      const body = c.querySelector('#s21-body');
      if (tab === 'rules') {
        const r = await api('broker_rules.list', {});
        body.innerHTML = table(['کارگزار', 'مبنا', 'مقدار', 'پرداخت‌کننده', 'فعال'], (r.rules || []).map((x) => [
          esc(x.broker_name || ''), esc(BASIS[x.basis] || x.basis || ''), num(x.value), esc(x.payer === 'us' ? 'ما' : 'طرف مقابل'), x.active ? '✔' : '—',
        ]));
      } else if (tab === 'payments') {
        const r = await api('broker_payments.list', {});
        body.innerHTML = table(['کارگزار', 'تاریخ', 'مبلغ', 'یادداشت'], (r.items || []).map((x) => [
          esc(x.broker_name || ''), jd(x.date), toman(x.amount), esc(x.note || ''),
        ]));
      } else {
        // کارگزار = طرف‌حساب دارای قانون کمیسیون (نوع parties برای فهرست کامل در اپ است)
        const r = await api('broker_rules.list', {});
        const seen = {};
        (r.rules || []).forEach((x) => { if (!seen[x.broker_id]) seen[x.broker_id] = { name: x.broker_name, n: 0, active: 0 }; seen[x.broker_id].n++; if (x.active) seen[x.broker_id].active++; });
        body.innerHTML = table(['نام', 'تعداد قانون', 'قوانین فعال'], Object.keys(seen).map((k) => [esc(seen[k].name || ''), num(seen[k].n), num(seen[k].active)]));
      }
    }
    await draw();
  };

  // ---------------------------------------------------------------- CRM (crm.dashboard) — T03.3, فقط خواندنی
  const CRM_STAGE = { lead: 'سرنخ', active: 'فعال', inactive: 'غیرفعال', lost: 'از دست رفته' };
  H['ops.crm'] = async function (c) {
    const d = await api('crm.dashboard', {});
    const st = d.stages || {};
    const taskRows = (list) => (list || []).map((t) => [esc(t.party || ''), jd(t.due_at), esc(t.text || ''), esc(t.assignee_staff || '—')]);
    const head = ['طرف حساب', 'موعد', 'شرح', 'مسئول'];
    c.innerHTML = '<div class="card"><div class="card-row"><span>' + Object.keys(CRM_STAGE).map((k) => CRM_STAGE[k] + ': ' + num(st[k] || 0)).join(' · ') + '</span></div></div>'
      + '<div class="section-title">پیگیری عقب‌افتاده</div>' + table(head, taskRows(d.overdue_tasks))
      + '<div class="section-title">پیگیری امروز</div>' + table(head, taskRows(d.today_tasks))
      + '<div class="section-title">پیگیری هفته آینده</div>' + table(head, taskRows(d.upcoming_tasks))
      + '<div class="section-title">مشتریان غیرفعال (بدون خرید)</div>'
      + table(['طرف حساب', 'آخرین خرید'], (d.inactive_customers || []).map((x) => [esc(x.party || ''), jd(x.last_sale)]))
      + '<div class="section-title">برترین مشتریان</div>'
      + table(['طرف حساب', 'فروش', 'کیلو', 'تعداد فاکتور'], (d.top_customers || []).map((x) => [esc(x.party || ''), toman(x.revenue), num(x.kg), num(x.sales_count)]));
  };

  // ---------------------------------------------------------------- تفکیک (separations.list) — S07, فقط خواندنی
  H['ops.separation'] = async function (c) {
    const r = await api('separations.list', {});
    c.innerHTML = table(['تاریخ', 'ورودی (کیلو)', 'خروجی (کیلو)', 'ضایعات (کیلو)', 'ضایعات غیرعادی', 'هزینه کارگر', 'حمل', 'یادداشت'],
      (r.items || []).map((x) => [jd(x.date), num(x.input_kg), num(x.output_kg), num(x.waste_kg), num(x.abnormal_kg),
        toman(x.labor_cost), toman(x.transport_cost), esc(x.note || '')]));
  };

  // ---------------------------------------------------------------- امانی (consignments.list) — S08, فقط خواندنی
  const CONS_MODE = { commission: 'کارمزدی', purchase_on_sale: 'خرید پس از فروش' };
  H['ops.consignment'] = async function (c) {
    let dir = 'in';
    async function draw() {
      c.innerHTML = seg([{ v: 'in', label: 'امانی دریافتی' }, { v: 'out', label: 'امانی ارسالی' }], dir)
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { dir = v; draw().catch(showErr); });
      const r = await api('consignments.list', {});
      const rows = (r.items || []).filter((x) => x.direction === dir);
      c.querySelector('#s21-body').innerHTML = table(['تاریخ', 'طرف حساب', 'روش تسویه', 'کارمزد', 'قیمت توافقی', 'وضعیت'], rows.map((x) => [
        jd(x.date), esc(x.party || ''), esc(CONS_MODE[x.settlement_mode] || x.settlement_mode || '—'),
        x.commission_type === 'percent' ? num(x.commission_value) + '٪' : toman(x.commission_value),
        x.agreed_price == null ? '—' : toman(x.agreed_price), esc(x.status || ''),
      ]));
    }
    await draw();
  };

  // ---------------------------------------------------------------- فرآوری (processing.list) — S09, فقط خواندنی
  const PROC_DIR = { received: 'دریافتی (مزدی)', paid: 'ارسالی (ما)', internal: 'داخلی (کارگاه)' };
  // کارگاه داخلی (مرحلهٔ ۱): تراز = R = H − (I+J+K+L+M+N+O)، هشدار «تراز صحیح» وقتی |R| < 0٫01 کیلو. محاسبه در سرور (pb_ws_balance)؛ وب فقط نمایش می‌دهد.
  const WS_OPS = { dehull: 'پوست‌گیری', desoil: 'خاک‌گیری', split: 'جداسازی پوست و مغز', fines: 'ریزبرداری و بدشکل', crack: 'شکست' };
  H['ops.processing'] = async function (c) {
    let dir = 'received';
    async function draw() {
      c.innerHTML = seg([{ v: 'received', label: 'دریافتی' }, { v: 'paid', label: 'ارسالی' }, { v: 'internal', label: 'داخلی' }], dir)
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { dir = v; draw().catch(showErr); });
      const r = await api('processing.list', { direction: dir });
      if (dir === 'internal') {
        // مرحلهٔ ۲: ستون بها فقط اگر سرور فیلد را فرستاده باشد (price.view + workshop.cost)؛ محاسبه در سرور (pb_ws_cost)
        const showCost = (r.items || []).some((x) => 'cost_per_kg_sound' in x);
        const head = ['تاریخ', 'عملیات', 'مالک', 'ورودی (کیلو)', 'خروجی (کیلو)', 'افت (کیلو)', 'اختلاف تراز', 'سالم٪', 'تراز'];
        if (showCost) head.push('بهای هر کیلو سالم', 'بهای کل سالم');
        c.querySelector('#s21-body').innerHTML = table(head,
          (r.items || []).map((x) => [jd(x.date), esc(WS_OPS[x.op_type] || x.op_type || ''), esc(x.owner || ''), num(x.input_kg), num(x.output_kg), num(x.loss_kg),
            x.balance_diff_kg == null ? '—' : num(x.balance_diff_kg), x.sound_pct == null ? '—' : num(x.sound_pct),
            x.balance_ok == null ? '—' : (x.balance_ok ? 'تراز صحیح' : '<b>نیاز به بررسی</b>')]
            .concat(showCost ? [x.cost_per_kg_sound == null ? '—' : num(x.cost_per_kg_sound) + (x.cost_warning ? ' ⚠' : ''), x.cost_sound == null ? '—' : num(x.cost_sound)] : [])));
        return;
      }
      // مرحلهٔ ۴: برای «ارسالی» (کارگاه بیرونی) شمارهٔ قبض و نام مالک هم نمایش داده می‌شود (فقط‌خواندنی؛ جزئیات کامل در «فایل خام شکست کارگاه‌ها»)
      const paid = dir === 'paid';
      c.querySelector('#s21-body').innerHTML = table(['تاریخ', 'طرف حساب'].concat(paid ? ['شمارهٔ قبض', 'نام مالک'] : [], ['خدمت', 'ورودی (کیلو)', 'خروجی (کیلو)', 'ضایعات (کیلو)', 'بازده٪', 'وضعیت']),
        (r.items || []).map((x) => [jd(x.date), esc(x.party || '')].concat(paid ? [esc(x.receipt_no || '—'), esc(x.owner || '—')] : [],
          [esc(x.service || ''), num(x.input_kg), num(x.output_kg), num(x.waste_kg), x.yield_pct == null ? '—' : num(x.yield_pct), esc(x.status || '')])));
    }
    await draw();
  };

  // ---------------------------------------------------------------- باغ‌ها (orchards.list) — S10, فقط خواندنی
  H['ops.orchard'] = async function (c) {
    const r = await api('orchards.list', {});
    c.innerHTML = table(['نام باغ', 'محل', 'مساحت (هکتار)', 'تعداد درخت', 'رقم', 'مالکیت', 'فصل‌ها', 'برداشت خشک (کیلو)'],
      (r.items || []).map((x) => [esc(x.name || ''), esc(x.location || ''), num(x.area_ha), num(x.tree_count), esc(x.variety || ''),
        esc(x.ownership || ''), num(x.seasons), num(x.dry_kg)]));
  };

  // ---------------------------------------------------------------- خروجی CSV (کلاینت، بدون کتابخانه) — T21.5
  function csvCell(v) {
    const s = v == null ? '' : String(v);
    return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }
  function downloadCsv(filename, headers, rows) {
    try {
      const text = '﻿' + [headers].concat(rows).map((r) => r.map(csvCell).join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
      const a = document.createElement('a');
      a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (err) { showErr(err); }
  }

  // ---------------------------------------------------------------- حقوق و دستمزد (payroll.employees / payroll.runs) — S11, فقط خواندنی
  H['ops.payroll'] = async function (c) {
    let tab = 'employees';
    let cache = [];
    async function draw() {
      c.innerHTML = seg([{ v: 'employees', label: 'کارکنان' }, { v: 'runs', label: 'دوره‌های حقوق' }], tab)
        + '<div class="row-actions"><button type="button" class="btn-sm" id="s21-csv">خروجی CSV</button></div>'
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { tab = v; draw().catch(showErr); });
      if (tab === 'runs') {
        const r = await api('payroll.runs', {});
        cache = (r.items || []).map((x) => [x.year + '/' + String(x.month).padStart(2, '0'), esc(x.ccy || ''), esc(x.status || ''), x.employees]);
        c.querySelector('#s21-body').innerHTML = table(['دوره (سال/ماه)', 'ارز', 'وضعیت', 'تعداد کارکنان'], cache.map((r) => r.map((v) => (typeof v === 'number' ? num(v) : v))));
        c.querySelector('#s21-csv').onclick = () => downloadCsv('payroll-runs.csv', ['دوره', 'ارز', 'وضعیت', 'تعداد کارکنان'], cache);
      } else {
        const r = await api('payroll.employees', {});
        cache = (r.items || []).map((x) => [x.name, x.position || '', x.base_salary, x.ccy || '', x.pay_schedule || '', x.active ? 'فعال' : 'غیرفعال', x.hire_date || '']);
        c.querySelector('#s21-body').innerHTML = table(['نام', 'سمت', 'حقوق پایه', 'ارز', 'دوره پرداخت', 'وضعیت', 'تاریخ استخدام'],
          cache.map((r) => [esc(r[0]), esc(r[1]), num(r[2]), esc(r[3]), esc(r[4]), esc(r[5]), esc(r[6])]));
        c.querySelector('#s21-csv').onclick = () => downloadCsv('employees.csv', ['نام', 'سمت', 'حقوق پایه', 'ارز', 'دوره پرداخت', 'وضعیت', 'تاریخ استخدام'], cache);
      }
    }
    await draw();
  };

  // ---------------------------------------------------------------- خروجی اکسل/CSV (صفحه انتخاب داده) — T21.5
  const EXPORTS = [
    { key: 'employees', label: 'کارکنان', action: 'payroll.employees', perm: 'payroll.view', list: (r) => (r.items || []).map((x) => [x.name, x.position || '', x.base_salary, x.active ? 'فعال' : 'غیرفعال']), head: ['نام', 'سمت', 'حقوق پایه', 'وضعیت'] },
    { key: 'cheques', label: 'چک‌ها', action: 'cheques.list', perm: 'dues.view', list: (r) => (r.items || []).map((x) => [x.number, x.bank, x.amount, x.due_date, x.party, x.status]), head: ['شماره', 'بانک', 'مبلغ', 'سررسید', 'طرف حساب', 'وضعیت'] },
    { key: 'proformas', label: 'پیش‌فاکتورها', action: 'proformas.list', perm: 'sales.view', list: (r) => (r.items || []).map((x) => [x.number, x.date, x.party, x.status, x.total]), head: ['شماره', 'تاریخ', 'طرف حساب', 'وضعیت', 'مبلغ کل'] },
    { key: 'sale_returns', label: 'برگشتی‌های فروش', action: 'sale_returns.list', perm: 'sales.view', list: (r) => (r.items || []).map((x) => [x.number, x.date, x.party, x.total, x.refund_mode]), head: ['شماره', 'تاریخ', 'طرف حساب', 'مبلغ کل', 'تسویه'] },
  ];
  H['ops.excel'] = async function (c) {
    c.innerHTML = '<div class="section-title">انتخاب داده برای خروجی CSV (فایل با BOM، قابل باز شدن در اکسل)</div>'
      + '<div class="export-grid">' + EXPORTS.map((e) => '<button type="button" class="btn-sm" data-k="' + e.key + '">' + esc(e.label) + '</button>').join('') + '</div>'
      + '<div id="s21-status" class="empty-state"></div>';
    c.querySelectorAll('[data-k]').forEach((b) => b.addEventListener('click', async () => {
      const e = EXPORTS.find((x) => x.key === b.dataset.k);
      const st = c.querySelector('#s21-status');
      st.textContent = 'در حال تهیه ' + e.label + '...';
      try {
        const r = await api(e.action, {});
        const rows = e.list(r);
        downloadCsv(e.key + '.csv', e.head, rows);
        st.textContent = rows.length + ' ردیف ' + e.label + ' خروجی گرفته شد.';
      } catch (err) { st.textContent = ''; showErr(err); }
    }));
  };

  // ---------------------------------------------------------------- اسناد حسابداری (vouchers.list / vouchers.get) — S16, فقط خواندنی، گلد
  const VCH_STATUS = { draft: 'پیش‌نویس', temporary: 'موقت', final: 'قطعی' };
  H['gold.vouchers'] = async function (c) {
    const r = await api('vouchers.list', { limit: 100 });
    c.innerHTML = table(['شماره', 'تاریخ', 'شرح', 'وضعیت', 'جمع', 'منبع'], (r.items || []).map((v) => [
      '<button type="button" class="link-btn" data-id="' + v.id + '">' + (v.number == null ? '—' : num(v.number)) + '</button>',
      jd(v.date), esc(v.description || ''), esc(VCH_STATUS[v.status] || v.status || ''), toman(v.total), esc(v.source_type || 'دستی'),
    ]))
      + '<div id="s21-voucher"></div>';
    c.querySelectorAll('[data-id]').forEach((b) => b.addEventListener('click', async () => {
      const box = c.querySelector('#s21-voucher');
      box.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
      try {
        const d = await api('vouchers.get', { id: Number(b.dataset.id) });
        const lines = d.lines || [];
        box.innerHTML = '<div class="section-title">سند ' + esc(d.voucher && d.voucher.number != null ? num(d.voucher.number) : '') + ' — ' + esc(d.voucher ? d.voucher.description || '' : '') + '</div>'
          + table(['حساب', 'معین', 'بدهکار', 'بستانکار', 'شرح'], lines.map((l) => [
            esc((l.account_code || '') + ' ' + (l.account_name || '')), esc(l.tafsili_name || ''), toman(l.debit), toman(l.credit), esc(l.description || ''),
          ]));
      } catch (err) { box.innerHTML = ''; showErr(err); }
    }));
  };

  // ---------------------------------------------------------------- گزارش‌های مالی (reports.journal / reports.trial / reports.ledger) — S18, فقط خواندنی، گلد
  H['gold.reports'] = async function (c) {
    let kind = 'trial';
    let accounts = null;
    async function draw() {
      c.innerHTML = seg([{ v: 'trial', label: 'تراز آزمایشی' }, { v: 'journal', label: 'روزنامه' }, { v: 'ledger', label: 'دفتر کل' }], kind)
        + '<div id="s21-body" class="spinner">در حال بارگذاری...</div>';
      bindSeg(c, (v) => { kind = v; draw().catch(showErr); });
      const body = c.querySelector('#s21-body');
      if (kind === 'trial') {
        const r = await api('reports.trial', { level: '2' });
        const t = r.totals || {};
        body.innerHTML = table(['کد', 'نام حساب', 'مانده بدهکار', 'مانده بستانکار'], (r.rows || []).map((x) => [
          esc(x.code), esc(x.name), toman(x.close_debit), toman(x.close_credit),
        ])) + '<div class="report-total">جمع بدهکار: ' + toman(t.close_debit) + ' · جمع بستانکار: ' + toman(t.close_credit)
          + (r.balanced ? ' · تراز است ✔' : ' · تراز نیست ✖') + '</div>';
      } else if (kind === 'journal') {
        const r = await api('reports.journal', {});
        const t = r.totals || {};
        body.innerHTML = table(['شماره', 'تاریخ', 'شرح', 'وضعیت', 'جمع'], (r.items || []).map((v) => [
          v.number == null ? '—' : num(v.number), jd(v.date), esc(v.description || ''), esc(VCH_STATUS[v.status] || v.status || ''), toman(v.total),
        ])) + '<div class="report-total">جمع بدهکار: ' + toman(t.debit) + ' · جمع بستانکار: ' + toman(t.credit) + '</div>';
      } else {
        if (!accounts) { const a = await api('accounts.list', {}); accounts = a.items || []; }
        body.innerHTML = '<div class="row-actions"><select id="s21-acc">' + accounts.map((a) => '<option value="' + a.id + '">' + esc(a.code + ' ' + a.name) + '</option>').join('')
          + '</select><button type="button" class="btn-sm" id="s21-led">نمایش</button></div><div id="s21-led-out"></div>';
        body.querySelector('#s21-led').addEventListener('click', async () => {
          const out = body.querySelector('#s21-led-out');
          out.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
          try {
            const r = await api('reports.ledger', { account_id: Number(body.querySelector('#s21-acc').value) });
            const bal = (o) => o ? toman(o.balance) + ' ' + esc(o.side || '') : '—';
            out.innerHTML = '<div class="report-total">مانده اول دوره: ' + bal(r.opening) + ' · مانده پایان: ' + bal(r.closing) + '</div>'
              + table(['شماره', 'تاریخ', 'شرح', 'بدهکار', 'بستانکار', 'مانده'], (r.rows || []).map((x) => [
                x.number == null ? '—' : num(x.number), esc(x.jdate || ''), esc(x.description || x.voucher_description || ''), toman(x.debit), toman(x.credit), toman(x.balance),
              ]));
          } catch (err) { out.innerHTML = ''; showErr(err); }
        });
      }
    }
    await draw();
  };

  // ---------------------------------------------------------------- مالیات بر ارزش افزوده (reports.vat) — S17, فقط خواندنی، گلد
  H['gold.vat'] = async function (c) {
    c.innerHTML = '<div class="row-actions"><div id="s21-vf" aria-label="از"></div><div id="s21-vt" aria-label="تا"></div><button type="button" class="btn-sm" id="s21-vgo">نمایش</button></div><div id="s21-body"></div>';
    // تقویم شمسی؛ پیش‌فرض: از اول سال شمسی جاری تا امروز
    var tj = todayJalaliYMD();
    mountJalaliDate('s21-vf', jalaliYMDToIso(tj[0], 1, 1));
    mountJalaliDate('s21-vt', todayStr());
    async function run() {
      const body = c.querySelector('#s21-body');
      body.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
      const from = dateVal('s21-vf'); const to = dateVal('s21-vt');
      try {
        const payload = {};
        if (from) payload.from = from;
        if (to) payload.to = to;
        const r = await api('reports.vat', payload);
        const s = r.summary || {};
        body.innerHTML = table(['شرح', 'مبلغ (ریال)'], [
          ['مالیات خروجی (فروش)', num(s.output_vat)], ['مالیات ورودی (خرید)', num(s.input_vat)],
          ['خالص پرداختنی', num(s.payable)], ['خالص قابل استرداد', num(s.net_refundable)],
          ['فروش مشمول', num(s.taxable_sales)], ['فروش معاف', num(s.exempt_sales)], ['فروش صفر', num(s.zero_rated_sales)],
          ['خرید مشمول', num(s.taxable_purchases)], ['خرید معاف', num(s.exempt_purchases)], ['خرید صفر', num(s.zero_rated_purchases)],
        ]) + ((r.warnings || []).length ? '<div class="empty-state">' + (r.warnings || []).map((w) => esc(w.detail || w.code || '')).join('<br>') + '</div>' : '');
      } catch (err) { body.innerHTML = ''; showErr(err); }
    }
    c.querySelector('#s21-vgo').addEventListener('click', () => run());
    await run();
  };

  // ---------------------------------------------------------------- تنظیمات فرم‌ها (S35.T35.1: form_settings.get|save)
  H['ops.form_settings'] = async function (c) {
    c.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
    async function draw() {
      const r = await api('form_settings.get', {});
      c.innerHTML = '<p class="muted">همهٔ امکانات پیش‌فرض خاموش‌اند؛ تا روشن نکنید فرم‌ها و چاپ‌ها دقیقاً مثل قبل کار می‌کنند.</p>'
        + (r.features || []).map((f) => '<label class="form-switch" style="display:flex;gap:8px;align-items:center;padding:8px 0">'
          + '<input type="checkbox" data-k="' + esc(f.key) + '"' + (f.on ? ' checked' : '') + '> <span>' + esc(f.help) + '</span></label>').join('');
      c.querySelectorAll('input[data-k]').forEach((cb) => cb.addEventListener('change', async () => {
        const s = {}; s[cb.dataset.k] = cb.checked;
        try { await api('form_settings.save', { settings: s }); toast('ذخیره شد'); } catch (e) { cb.checked = !cb.checked; showErr(e); }
      }));
    }
    await draw();
  };

  function showErr(err) { toast((err && err.message) || 'خطا'); }

  window.PBModules = {
    has: (key) => typeof H[key] === 'function',
    open: (key, container) => H[key](container),
  };
})();
