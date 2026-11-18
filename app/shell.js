// S21.T21.1 — web navigation shell: edition awareness, staff permissions, module drawer.
// Loaded BEFORE app.js. Uses app.js globals (state, api-free helpers, setTab, esc, toast) only inside functions.
// Server stays the authority (403 edition_required / permission checks); this file only hides or marks UI.
(function () {
  'use strict';

  // Core tabs (always in every edition). perm '' = no permission needed.
  const TAB_RULES = {
    home:      { feature: '',              perm: '' },
    inventory: { feature: 'core.inventory', perm: 'stock.view' },
    sales:     { feature: 'core.sales',     perm: 'sales.view' },
    parties:   { feature: 'core.parties',   perm: 'parties.view' },
    dues:      { feature: 'core.payments',  perm: 'dues.view' },
  };

  // Drawer catalog: feature keys mirror server/backend/api/features.php; perm keys mirror permissions.php.
  // Modules are not yet ported to the web client (S21.T21.2+): they open a placeholder.
  const GROUPS = [
    { title: 'عملیات', items: [
      { key: 'ops.warehouses',  label: 'انبارها',              perm: 'stock.view' },
      { key: 'lot.chain',       feature: '', label: 'مسیر پسته',        perm: 'stock.view' }, // T6: ردیابی (سرور purchases.view را اعمال می‌کند)
      { key: 'lot.cartex',      feature: '', label: 'کارتکس لات',       perm: ['cartex.view', 'stock.view'] }, // کارتکس لات (سرور cartex.view را اعمال می‌کند)
      { key: 'ops.form_settings', label: 'تنظیمات فرم‌ها',     perm: 'settings.manage' }, // S35.T35.1
      { key: 'ops.returns',     label: 'برگشتی‌ها',            perm: 'sales.view' },
      { key: 'ops.proforma',    label: 'پیش‌فاکتور',           perm: 'sales.view' },
      { key: 'ops.brokers',     label: 'کارگزاران',            perm: 'purchases.view' },
      { key: 'ops.cheques',     label: 'چک‌ها',                perm: 'dues.view' },
      { key: 'ops.crm',         label: 'مشتری‌مداری (CRM)',    perm: 'crm.view' },
      { key: 'ops.separation',  label: 'تفکیک',                perm: 'purchases.view' },
      { key: 'ops.consignment', label: 'امانی',                perm: 'stock.view' },
      { key: 'ops.processing',  label: 'فرآوری',               perm: 'stock.view' },
      { key: 'ops.orchard',     label: 'باغ‌ها',               perm: 'orchards.view' },
      { key: 'ops.payroll',     label: 'حقوق و دستمزد',        perm: 'payroll.view' },
      { key: 'ops.pos',         label: 'فروش فوری (POS)',      perm: 'sales.create' },
      { key: 'ops.excel',       label: 'خروجی اکسل',           perm: 'reports.view' },
      { key: 'ops.staff',       label: 'کارکنان و نقش‌ها',     perm: 'users.manage' },
    ] },
    { title: 'اتاق انس', items: [
      { key: 'ounce.intake_new', feature: '', label: 'پذیرش جدید',            perm: 'ounce.intake' },
      { key: 'ounce.intakes',    feature: '', label: 'پذیرش‌های اتاق انس',    perm: ['ounce.view_own', 'ounce.view_all'] },
    ] },
    { title: 'کارگاه', items: [
      { key: 'workshop.crack_list',    feature: '', label: 'گزارش خط شکست',        perm: 'workshop.crack' },
      { key: 'workshop.internal_list', feature: '', label: 'عملیات کارگاه داخلی',  perm: 'workshop.view' },
      { key: 'reports.crack_profit',   feature: 'gold.reports', label: 'سود خط شکست',  perm: 'profit.view' }, // CP3: gold + profit.view
      { key: 'workshop.outside_raw',    feature: '', label: 'فایل خام شکست کارگاه‌ها', perm: 'stock.view' },
      { key: 'feed.stock',              feature: '', label: 'موجودی پستهٔ خرجی',     perm: 'workshop.feed' },
      { key: 'reports.cartex',           feature: '', label: 'کارتکس مالک',           perm: 'workshop.feed' },
      { key: 'reports.workshop_summary', feature: '', label: 'خلاصهٔ مدیریتی کارگاه', perm: 'stock.view' },
      { key: 'reports.workshop_compare', feature: '', label: 'مقایسهٔ کارگاه‌ها',     perm: 'workshop.feed' },
    ] },
    { title: 'گزارش‌ها', items: [
      { key: 'core.reports', label: 'موجودی (گزارش)', perm: 'stock.view' },
    ] },
    { title: 'ارزی', items: [
      { key: 'fx.rates',     label: 'نرخ ارز',     perm: '' },
      { key: 'fx.documents', label: 'اسناد ارزی',  perm: 'sales.view' },
    ] },
    { title: 'حسابداری (طلایی)', items: [
      { key: 'gold.fiscal_years',  label: 'سال مالی',           perm: 'accounting.view' },
      { key: 'gold.coa',           label: 'سرفصل حساب‌ها',      perm: 'accounting.view' },
      { key: 'gold.cost_centers',  label: 'مراکز هزینه',        perm: 'accounting.view' },
      { key: 'gold.treasury',      label: 'خزانه',              perm: 'accounting.view' },
      { key: 'gold.vouchers',      label: 'اسناد حسابداری',     perm: 'accounting.view' },
      { key: 'gold.vat',           label: 'مالیات بر ارزش افزوده', perm: 'tax.view' },
      { key: 'gold.invoices',      label: 'صورتحساب‌ها',        perm: 'accounting.view' },
      { key: 'gold.reports',       label: 'گزارش‌های مالی',     perm: 'accounting.view' },
      { key: 'gold.yearend',       label: 'پایان سال مالی',     perm: 'accounting.close' },
      { key: 'gold.payroll_law',   label: 'حقوق طبق قانون',     perm: 'payroll.view' },
      { key: 'gold.fx_accounting', label: 'حسابداری ارزی',      perm: 'accounting.view' },
    ] },
  ];

  const shellState = { edition: null, staff: null };

  function features() {
    return (shellState.edition && Array.isArray(shellState.edition.features)) ? shellState.edition.features : null;
  }
  function hasFeature(key) {
    if (!key) return true;
    const f = features();
    return f === null ? true : f.indexOf(key) !== -1; // قبل از دریافت edition: محدود نمی‌کنیم (سرور در هر حال کنترل می‌کند)
  }
  function hasPerm(perm) {
    if (!perm) return true;
    if (Array.isArray(perm)) return perm.some(hasPerm); // S8: آرایه = هر کدام (any-of) مثل سرور
    const st = shellState.staff;
    if (!st) return true; // مالک (یا هنوز ناشناخته): همه‌کار
    return Array.isArray(st.perms) && st.perms.indexOf(perm) !== -1;
  }
  // S8: کارکنی که فقط مجوزهای «اتاق انس» را دارد (همان kOunceRoomPerms اپ و نقش سیستمی سرور)؛ مالک و هر مجوز دیگر ⇒ false
  const OUNCE_ROOM_PERMS = ['ounce.intake', 'ounce.view_own', 'ounce.edit_own', 'ounces.view'];
  function isOunceRoomOnly() {
    const st = shellState.staff;
    if (!st || !Array.isArray(st.perms) || !st.perms.length) return false;
    return st.perms.every((p) => OUNCE_ROOM_PERMS.indexOf(p) !== -1);
  }
  // کارگاه ۳-ب: کارکنی که فقط مجوزهای نقش «اپراتور کارگاه» را دارد (workshop.operate الزامی) — همان kWorkshopOperatorPerms اپ
  const WORKSHOP_OPERATOR_PERMS = ['workshop.operate', 'workshop.view', 'workshop.crack', 'workshop.feed'];
  function isWorkshopOperatorOnly() {
    const st = shellState.staff;
    if (!st || !Array.isArray(st.perms) || !st.perms.length) return false;
    if (st.perms.indexOf('workshop.operate') === -1) return false;
    return st.perms.every((p) => WORKSHOP_OPERATOR_PERMS.indexOf(p) !== -1);
  }
  // مجوزهای کارکن (آرایه) یا null برای مالک/ناشناخته — برای نقش «کارتکس» (modules_cartex.js)
  function staffPerms() { const st = shellState.staff; return st && Array.isArray(st.perms) ? st.perms : null; }
  // 'ok' | 'locked' (نسخهٔ بالاتر لازم است) | 'hidden' (مجوز کارکن ندارد)
  function access(feature, perm) {
    if (!hasPerm(perm)) return 'hidden';
    if (!hasFeature(feature)) return 'locked';
    return 'ok';
  }
  function needLabel(key) {
    if (key === 'gold.fx_accounting') return 'نسخهٔ طلایی ارزی';
    if (key.indexOf('gold.') === 0) return 'نسخهٔ طلایی';
    if (key.indexOf('fx.') === 0) return 'نسخهٔ ارزی';
    return 'نسخهٔ بالاتر';
  }

  function applyTabs() {
    document.querySelectorAll('.tab').forEach((b) => {
      const rule = TAB_RULES[b.dataset.tab];
      if (!rule) return;
      b.classList.toggle('hidden', access(rule.feature, rule.perm) !== 'ok');
    });
  }

  function staffTitle() {
    const st = shellState.staff;
    if (!st) return 'مالک';
    return (st.name || st.username || 'کارکن') + (st.role ? ' — ' + st.role : '');
  }

  function renderDrawerBody() {
    const body = document.getElementById('drawer-body');
    if (!body) return;
    const ed = shellState.edition;
    let html = '<div class="drawer-head">'
      + '<div><b>' + esc(ed && ed.name ? ed.name : 'پسته بازار') + '</b></div>'
      + '<div class="muted">کاربر: ' + esc(staffTitle()) + '</div>'
      + '</div>';
    GROUPS.forEach((g) => {
      const items = g.items.filter((it) => access(featOf(it), it.perm) !== 'hidden');
      if (!items.length) return;
      html += '<h3>' + esc(g.title) + '</h3>';
      items.forEach((it) => {
        const locked = access(featOf(it), it.perm) === 'locked';
        html += '<button type="button" class="drawer-item' + (locked ? ' locked' : '') + '" data-key="' + it.key + '">'
          + '<span>' + esc(it.label) + '</span>'
          + '<span class="drawer-tag">' + (locked ? '🔒 ' + needLabel(it.key) : '') + '</span>'
          + '</button>';
      });
    });
    body.innerHTML = html;
    body.querySelectorAll('.drawer-item').forEach((btn) => {
      btn.addEventListener('click', () => openModule(btn.dataset.key));
    });
  }

  // S8: آیتم می‌تواند feature جدا داشته باشد (خالی = بدون قفل نسخه)؛ پیش‌فرض همان key
  function featOf(it) { return it.feature !== undefined ? it.feature : it.key; }

  function findItem(key) {
    for (const g of GROUPS) {
      for (const it of g.items) if (it.key === key) return { group: g, item: it };
    }
    return null;
  }

  // صفحهٔ جای‌گزین برای ماژول‌هایی که هنوز در وب نیستند یا در نسخهٔ فعلی قفل‌اند
  function openModule(key) {
    closeDrawer();
    const found = findItem(key);
    if (!found) return;
    const acc = access(featOf(found.item), found.item.perm);
    if (acc === 'hidden') { toast('دسترسی این بخش برای کاربر شما فعال نیست'); return; }
    document.querySelectorAll('.tab').forEach((b) => b.classList.remove('active'));
    document.querySelectorAll('.fab').forEach((f) => f.remove());
    document.getElementById('page-title').textContent = found.item.label;
    const msg = acc === 'locked'
      ? 'این بخش در نسخهٔ فعلی شما فعال نیست (' + needLabel(found.item.key) + ' لازم است).'
      : 'این بخش فعلاً فقط در اپلیکیشن موبایل در دسترس است.';
    const c = document.getElementById('content');
    if (acc === 'ok' && window.PBModules && PBModules.has(key)) {
      c.innerHTML = '<div class="spinner">در حال بارگذاری...</div>';
      if (window.PBRealtime) PBRealtime.setView({ refresh: () => PBModules.open(key, c), full: true, entities: [] }); // S8: تازه‌سازی لحظه‌ای نمای ماژول
      PBModules.open(key, c).catch((err) => {
        c.innerHTML = '<div class="empty-state">' + esc((err && err.message) || 'خطا در بارگذاری') + '</div>';
      });
      return;
    }
    c.innerHTML = '<div class="empty-state">' + esc(msg) + '</div>';
  }

  function openDrawer() {
    renderDrawerBody();
    document.getElementById('drawer').classList.remove('hidden');
    document.getElementById('drawer-overlay').classList.remove('hidden');
  }
  function closeDrawer() {
    const d = document.getElementById('drawer');
    const o = document.getElementById('drawer-overlay');
    if (d) d.classList.add('hidden');
    if (o) o.classList.add('hidden');
  }

  function init() {
    const menu = document.getElementById('menu-btn');
    if (menu) menu.addEventListener('click', openDrawer);
    const ov = document.getElementById('drawer-overlay');
    if (ov) ov.addEventListener('click', closeDrawer);
    const close = document.getElementById('drawer-close');
    if (close) close.addEventListener('click', closeDrawer);
  }

  // app.js بعد از bootstrap موفق صدا می‌زند
  function apply(data) {
    shellState.edition = (data && data.customer && data.customer.edition) || null;
    shellState.staff = (data && data.staff) || null;
    applyTabs();
  }

  window.PBShell = { apply, openModule, closeDrawer, access, hasFeature, hasPerm, isOunceRoomOnly, isWorkshopOperatorOnly, staffPerms };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
