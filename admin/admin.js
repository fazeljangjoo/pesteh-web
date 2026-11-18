/* پنل مدیریت پسته بازار */
(function () {
  'use strict';
  var PB = window.PB, A = window.PBAnn;
  var cfg = null, offset = 0, dirty = false, tab = 'prices', prevDur = 12;
  var $view = document.getElementById('view'), $save = document.getElementById('save'), $dirty = document.getElementById('dirty');
  var DUR = [3, 6, 12];
  var PLANS = [['rial', 'پلن ریالی', 'حساب‌وکتاب به تومان'], ['fx', 'پلن ارزی / ریالی', 'همهٔ امکانات ریالی + حساب دلاری']];
  var TITLES = {
    prices: ['قیمت پلن‌ها', 'قیمت پایه را بدهید؛ قیمت عادی ۳، ۶ و ۱۲ ماهه خودکار حساب می‌شود.'],
    camps: ['تخفیف مناسبتی', 'تخفیف دوم که روی قیمت‌های فعلی اعمال می‌شود؛ مشتری هر دو تخفیف را می‌بیند.'],
    msgs: ['پیام‌های سایت', 'تبریک مناسبت‌ها، خبر آپدیت و اطلاعیه؛ بالای سایت و در بخش «تازه‌ها» نمایش داده می‌شود.'],
    set: ['تنظیمات', 'رمز پنل و خروج']
  };

  function now() { return Math.floor(Date.now() / 1000) + offset; }
  function uid() { return Math.random().toString(36).slice(2, 10); }

  /* ---------- ساخت عناصر ---------- */
  function h(tag, attrs) {
    var e = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'class') e.className = v;
      else if (k === 'text') e.textContent = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
      else if (k === 'value') e.value = v;
      else e.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c == null || c === false) continue;
      if (Array.isArray(c)) c.forEach(function (x) { if (x) e.appendChild(x); });
      else e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return e;
  }

  var toastT;
  function toast(msg, err) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.className = 'show' + (err ? ' err' : '');
    clearTimeout(toastT); toastT = setTimeout(function () { t.className = ''; }, 3200);
  }

  function setDirty(v) {
    dirty = v; $dirty.hidden = !v; $save.disabled = !v;
  }
  function touch() { setDirty(true); }

  /* ---------- ورودی پول با جداکنندهٔ هزارگان ---------- */
  function group(n) { return n ? String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : ''; }
  function moneyField(get, set, opts) {
    opts = opts || {};
    var read = h('div', { class: 'read' });
    var inp = h('input', { type: 'text', inputmode: 'numeric', autocomplete: 'off', placeholder: opts.placeholder || '', value: group(get()) });
    function upd() { var n = get(); read.textContent = n ? PB.money(n) + ' تومان' : ''; }
    inp.addEventListener('input', function () {
      var pos = inp.selectionStart, before = inp.value.slice(0, pos).replace(/[^\d۰-۹٠-٩]/g, '').length;
      var n = PB.parse(inp.value);
      set(n); touch();
      var s = group(n), p = 0, cnt = 0;
      inp.value = s;
      while (p < s.length && cnt < before) { if (/\d/.test(s[p])) cnt++; p++; }
      try { inp.setSelectionRange(p, p); } catch (e) {}
      upd(); if (opts.onChange) opts.onChange();
    });
    upd();
    var box = h('div', { class: 'money' + (opts.small ? ' sm' : '') }, inp);
    var wrap = h('div', null, box, read);
    wrap.refresh = function () { inp.value = group(get()); upd(); };
    return wrap;
  }

  function toggle(label, checked, on) {
    var i = h('input', { type: 'checkbox' }); i.checked = !!checked;
    i.addEventListener('change', function () { on(i.checked); });
    return h('label', { class: 'tog' }, i, h('span', { class: 'sw' }), h('span', { text: label }));
  }
  function chip(label, on, click) { return h('button', { type: 'button', class: 'chip' + (on ? ' on' : ''), 'aria-pressed': on ? 'true' : 'false', onclick: click, text: label }); }

  function fmtDate(sec) {
    try { return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(sec * 1000)); } catch (e) { return ''; }
  }

  /* ---------- زمان پایان (برای تخفیف و پیام) ---------- */
  function endPicker(obj, afterChange) {
    var info = h('div', { class: 'endinfo' });
    function upd() {
      if (obj.end) {
        var left = obj.end <= now() ? 'تمام شده' : PB.leftText(obj.end, now());
        info.textContent = 'پایان: ' + fmtDate(obj.end) + ' (' + left + ')';
      } else info.textContent = 'بدون محدودیت زمانی؛ تا وقتی خاموشش نکنید می‌ماند.';
    }
    var row = h('div', { class: 'endrow' });
    [[1, '۱ روز'], [3, '۳ روز'], [7, '۷ روز'], [10, '۱۰ روز'], [15, '۱۵ روز'], [30, '۳۰ روز']].forEach(function (d) {
      row.appendChild(chip(d[1], false, function () { obj.end = now() + d[0] * 86400; touch(); afterChange(); }));
    });
    row.appendChild(chip('بدون پایان', !obj.end, function () { obj.end = null; touch(); afterChange(); }));
    upd();
    return h('div', { class: 'fld' }, h('span', { text: 'تا کی نمایش داده شود؟ (از همین الان)' }), row, info);
  }

  /* =========================================================
     تب ۱: قیمت‌ها
     ========================================================= */
  var priceRefs;
  function viewPrices() {
    priceRefs = { rows: {}, base: {} };
    var left = h('div', { class: 'stack' });
    left.appendChild(h('div', { class: 'intro' }, h('b', { text: 'چطور کار می‌کند؟ ' }),
      'قیمت یک ماه را بنویسید. سیستم قیمت عادی ۳، ۶ و ۱۲ ماهه را خودش حساب می‌کند. اگر برای یک مدت قیمت کمتری گذاشتید، درصد تخفیف خودکار حساب و به مشتری نشان داده می‌شود. خالی بگذارید یعنی بدون تخفیف.'));

    PLANS.forEach(function (pl) {
      var key = pl[0], P = cfg.plans[key];
      var baseF = moneyField(function () { return P.base; }, function (n) { P.base = n; }, { placeholder: 'مثلاً 2,890,000', onChange: refreshPrices });
      var card = h('section', { class: 'card plan ' + key },
        h('div', { class: 'plan-h' }, h('h2', { text: pl[1] }), h('span', { class: 'pill', text: pl[2] })),
        h('label', { class: 'fld' }, h('span', { text: 'قیمت پایه یک ماه' }), baseF)
      );
      var rows = h('div', { class: 'rows' });
      DUR.forEach(function (m) {
        var ref = {};
        ref.normal = h('strong'); ref.badge = h('span', { class: 'dbadge off', text: 'بدون تخفیف' }); ref.warn = h('div', { class: 'warn' });
        var sp = moneyField(function () { return P.special[m]; }, function (n) { P.special[m] = n; }, { small: true, placeholder: 'قیمت با تخفیف (اختیاری)', onChange: refreshPrices });
        ref.sp = sp;
        var clear = h('button', { type: 'button', class: 'clear', text: 'حذف تخفیف', onclick: function () { P.special[m] = null; sp.refresh(); touch(); refreshPrices(); } });
        priceRefs.rows[key + m] = ref;
        rows.appendChild(h('div', { class: 'rowp' },
          h('div', { class: 'rowp-top' }, h('b', { text: PB.fa(m) + ' ماهه' }), ref.badge),
          h('div', { class: 'normal' }, 'قیمت عادی: ', ref.normal, ' تومان'),
          h('div', { class: 'fld' }, h('span', { text: 'قیمت شما با تخفیف' }), sp),
          h('div', null, clear, ref.warn)
        ));
      });
      card.appendChild(rows);
      left.appendChild(card);
    });

    // S22.T22.3c: قیمت‌های طلایی (cfg.tiers.gold). base خالی = آن SKU فروخته نمی‌شود؛ ارتقای سبز→طلایی از همین قیمت‌ها حساب می‌شود.
    if (!cfg.tiers) cfg.tiers = {};
    if (!cfg.tiers.gold) cfg.tiers.gold = {};
    left.appendChild(h('div', { class: 'intro' }, h('b', { text: 'نسخهٔ طلایی: ' }),
      'قیمت پایهٔ یک‌ماههٔ طلایی را بدهید؛ قیمت ۳، ۶ و ۱۲ ماهه خودکار حساب می‌شود. خالی بگذارید یعنی این نسخه فروخته نشود. هزینهٔ «ارتقا به طلایی» = اختلاف قیمت طلایی و سبز برای روزهای باقی‌ماندهٔ اشتراک.'));
    PLANS.forEach(function (pl) {
      var key = pl[0];
      if (!cfg.tiers.gold[key]) cfg.tiers.gold[key] = { base: null, special: { '3': null, '6': null, '12': null } };
      var G = cfg.tiers.gold[key];
      if (!G.special) G.special = { '3': null, '6': null, '12': null };
      var gBase = moneyField(function () { return G.base; }, function (n) { G.base = n; }, { placeholder: 'مثلاً 4,890,000' });
      var gCard = h('section', { class: 'card plan ' + key },
        h('div', { class: 'plan-h' }, h('h2', { text: 'طلایی — ' + pl[1] }), h('span', { class: 'pill', text: 'طلایی' })),
        h('label', { class: 'fld' }, h('span', { text: 'قیمت پایه یک ماه (طلایی)' }), gBase)
      );
      DUR.forEach(function (m) {
        var gsp = moneyField(function () { return G.special[m]; }, function (n) { G.special[m] = n; }, { small: true, placeholder: 'قیمت ' + PB.fa(m) + ' ماهه (اختیاری)' });
        gCard.appendChild(h('div', { class: 'fld' }, h('span', { text: PB.fa(m) + ' ماهه با تخفیف' }), gsp));
      });
      left.appendChild(gCard);
    });

    var prev = h('aside', { class: 'prev card' });
    var bar = h('div', { class: 'prev-bar' }, h('h2', { text: 'همان چیزی که مشتری می‌بیند' }));
    var seg = h('div', { class: 'seg' });
    DUR.forEach(function (m) {
      seg.appendChild(h('button', { type: 'button', 'aria-pressed': m === prevDur ? 'true' : 'false', text: PB.fa(m) + ' ماهه', onclick: function () {
        prevDur = m; [].forEach.call(seg.children, function (b, i) { b.setAttribute('aria-pressed', DUR[i] === m ? 'true' : 'false'); }); refreshPrices();
      } }));
    });
    bar.appendChild(seg);
    priceRefs.cards = h('div', { class: 'pcards' });
    prev.appendChild(bar);
    prev.appendChild(h('p', { class: 'hint', text: 'تخفیف‌های مناسبتی فعال هم در این پیش‌نمایش اعمال می‌شوند.' }));
    prev.appendChild(priceRefs.cards);
    $view.appendChild(h('div', { class: 'grid2' }, left, prev));
    refreshPrices();
  }

  function refreshPrices() {
    if (!priceRefs) return;
    var bad = false;
    PLANS.forEach(function (pl) {
      var key = pl[0], P = cfg.plans[key], base = PB.parse(P.base);
      DUR.forEach(function (m) {
        var r = priceRefs.rows[key + m]; if (!r) return;
        var normal = base ? base * m : null, sp = PB.parse(P.special[m]);
        r.normal.textContent = normal ? PB.money(normal) : '—';
        r.warn.textContent = '';
        if (sp && normal && sp >= normal) {
          r.badge.className = 'dbadge bad'; r.badge.textContent = 'نامعتبر';
          r.warn.textContent = 'قیمت با تخفیف باید کمتر از قیمت عادی باشد.'; bad = true;
        } else if (sp && normal) {
          var pct = Math.max(1, Math.round((1 - sp / normal) * 100));
          r.badge.className = 'dbadge on'; r.badge.textContent = PB.fa(pct) + '٪ تخفیف';
        } else if (sp && !normal) {
          r.badge.className = 'dbadge bad'; r.badge.textContent = 'قیمت پایه خالی است';
        } else { r.badge.className = 'dbadge off'; r.badge.textContent = 'بدون تخفیف'; }
      });
    });
    priceRefs.invalid = bad;
    var box = priceRefs.cards; box.textContent = '';
    PLANS.forEach(function (pl) {
      var pc = h('article', { class: 'pc' + (pl[0] === 'fx' ? ' fx' : '') }, h('h3', { text: pl[1] }));
      var price = h('div');
      PB.render(price, PB.calc(cfg, pl[0], prevDur, now()), now());
      pc.appendChild(price); box.appendChild(pc);
    });
  }

  /* =========================================================
     تب ۲: تخفیف‌های مناسبتی
     ========================================================= */
  var CAMP_TPL = [['تخفیف نوروزی', 10], ['تخفیف یلدا', 10], ['تخفیف عید فطر', 8], ['تخفیف عید قربان', 8], ['جشنوارهٔ ویژه', 15], ['تخفیف ۲۴ ساعته', 5]];

  function campStatus(c) {
    if (!c.active) return ['off', 'خاموش'];
    if (c.end && c.end <= now()) return ['exp', 'منقضی شده'];
    return ['live', 'در سایت فعال است'];
  }
  function campExample(c) {
    var tmp = { plans: cfg.plans, campaigns: [{ title: c.title, percent: c.percent, plans: c.plans, durations: c.durations, active: true }] };
    for (var k = 0; k < c.plans.length; k++) for (var d = DUR.length - 1; d >= 0; d--) {
      if (c.durations.indexOf(DUR[d]) < 0) continue;
      var r = PB.calc(tmp, c.plans[k], DUR[d], 0);
      if (r) {
        var name = c.plans[k] === 'rial' ? 'ریالی' : 'ارزی';
        return h('div', { class: 'eg' }, 'مثال، ', h('b', { text: name + ' ' + PB.fa(DUR[d]) + ' ماهه' }), ': ',
          h('s', { text: PB.money(r.afterSpecial) }), ' ← ', h('b', { text: PB.money(r.final) + ' تومان' }),
          r.special ? ' (جمعاً ' + PB.fa(r.total) + '٪ ارزان‌تر از قیمت عادی)' : ' (' + PB.fa(r.total) + '٪ ارزان‌تر)');
      }
    }
    return h('div', { class: 'eg', text: 'برای دیدن مثال، قیمت پایهٔ پلن‌های انتخاب‌شده را در تب «قیمت پلن‌ها» وارد کنید.' });
  }

  function viewCamps() {
    $view.appendChild(h('div', { class: 'intro' }, h('b', { text: 'تخفیف روی تخفیف: ' }),
      'مثلاً پلن ۱۲ ماهه با قیمت ویژهٔ شما ۲۲٪ ارزان است. با روشن کردن «تخفیف نوروزی ۱۰٪» همان قیمت ۱۰٪ دیگر کم می‌شود و مشتری هر دو تخفیف و مجموع صرفه‌جویی را می‌بیند. اگر چند تخفیف هم‌زمان به یک پلن بخورد، فقط بیشترینش اعمال می‌شود.'));
    var add = function (t, p) {
      cfg.campaigns.unshift({ id: uid(), title: t || '', percent: p || 10, plans: ['rial', 'fx'], durations: [3, 6, 12], active: true, end: null });
      touch(); render();
    };
    var tpl = h('div', { class: 'tpl' }, h('span', { class: 'hint', text: 'افزودن سریع:' }));
    CAMP_TPL.forEach(function (t) { tpl.appendChild(h('button', { type: 'button', class: 'btn-soft', text: '+ ' + t[0], onclick: function () { add(t[0], t[1]); } })); });
    tpl.appendChild(h('button', { type: 'button', class: 'btn-main', text: 'تخفیف دلخواه', onclick: function () { add('', 10); } }));
    $view.appendChild(h('div', { style: 'margin-top:16px' }, tpl));

    if (!cfg.campaigns.length) {
      $view.appendChild(h('div', { class: 'empty' }, h('big', { text: '🎁' }), 'هنوز تخفیف مناسبتی نساخته‌اید. از دکمه‌های بالا یکی را اضافه کنید.'));
      return;
    }
    var list = h('div', { class: 'list' });
    cfg.campaigns.forEach(function (c, idx) { list.appendChild(campItem(c, idx)); });
    $view.appendChild(list);
  }

  function campItem(c, idx) {
    var st = campStatus(c);
    var stEl = h('span', { class: 'status ' + st[0], text: st[1] });
    var ttl = h('h3', { text: c.title || 'تخفیف جدید' });
    var egBox = h('div'); function egUpd() { egBox.textContent = ''; egBox.appendChild(campExample(c)); }
    function head() { var s = campStatus(c); stEl.className = 'status ' + s[0]; stEl.textContent = s[1]; }

    var titleI = h('input', { type: 'text', maxlength: 40, placeholder: 'مثلاً تخفیف نوروزی', value: c.title, oninput: function (e) { c.title = e.target.value; ttl.textContent = c.title || 'تخفیف جدید'; touch(); egUpd(); } });
    var range = h('input', { type: 'range', min: 1, max: 60, value: c.percent });
    var num = h('input', { type: 'number', class: 'num', min: 1, max: 95, value: c.percent });
    function setPct(v) { v = Math.max(1, Math.min(95, Math.round(+v) || 1)); c.percent = v; range.value = Math.min(v, 60); num.value = v; touch(); egUpd(); }
    range.addEventListener('input', function () { setPct(range.value); });
    num.addEventListener('input', function () { if (num.value !== '') setPct(num.value); });

    function tg(arr, val) { var i = arr.indexOf(val); if (i < 0) arr.push(val); else arr.splice(i, 1); touch(); render(); }
    var plansRow = h('div', { class: 'chips' }, PLANS.map(function (p) { return chip(p[1], c.plans.indexOf(p[0]) >= 0, function () { tg(c.plans, p[0]); }); }));
    var dursRow = h('div', { class: 'chips' }, DUR.map(function (m) { return chip(PB.fa(m) + ' ماهه', c.durations.indexOf(m) >= 0, function () { tg(c.durations, m); }); }));

    egUpd();
    var item = h('article', { class: 'item' + (c.active ? '' : ' off') },
      h('div', { class: 'item-h' }, h('span', { style: 'font-size:26px', text: '🎉' }), h('div', { class: 'grow' }, ttl), stEl,
        toggle('فعال', c.active, function (v) { c.active = v; touch(); render(); })),
      h('div', { class: 'frow' },
        h('label', { class: 'fld' }, h('span', { text: 'نام تخفیف (به مشتری نشان داده می‌شود)' }), titleI),
        h('div', { class: 'fld' }, h('span', { text: 'درصد تخفیف' }), h('div', { class: 'pctbox' }, range, num, h('b', { text: '٪' })))),
      h('div', { class: 'frow' },
        h('div', { class: 'fld' }, h('span', { text: 'روی کدام پلن‌ها؟' }), plansRow),
        h('div', { class: 'fld' }, h('span', { text: 'روی کدام مدت‌ها؟' }), dursRow)),
      endPicker(c, function () { render(); }),
      egBox,
      h('div', { class: 'actions' }, h('span', { class: 'hint', style: 'margin:0', text: (c.plans.length && c.durations.length) ? '' : '⚠️ دست‌کم یک پلن و یک مدت انتخاب کنید.' }),
        h('button', { type: 'button', class: 'btn-soft btn-del', text: 'حذف این تخفیف', onclick: function () { if (confirm('این تخفیف حذف شود؟')) { cfg.campaigns.splice(idx, 1); touch(); render(); } } }))
    );
    return item;
  }

  /* =========================================================
     تب ۳: پیام‌ها
     ========================================================= */
  var MSG_TPL = [
    ['greeting', 'عید نوروز', 'نوروزتان پیروز! 🌱', 'سال نو بر شما مبارک؛ آرزوی سالی پر برکت و پر سود برایتان داریم.'],
    ['greeting', 'یلدا', 'شب یلدا مبارک 🍉', 'شبی دل‌انگیز و پر از گرمای خانواده برایتان آرزومندیم.'],
    ['greeting', 'عید فطر', 'عید سعید فطر مبارک', 'قبولی طاعات و شادی در کنار عزیزانتان.'],
    ['greeting', 'عید قربان', 'عید سعید قربان مبارک', 'ایام به کام و دل‌هایتان شاد.'],
    ['update', 'نسخهٔ جدید', 'نسخهٔ جدید پسته بازار آمد', 'برنامه را به‌روز کنید تا از امکانات و بهبودهای تازه استفاده کنید.'],
    ['notice', 'اطلاعیه', 'اطلاعیه', '']
  ];

  function msgStatus(m) {
    if (!m.active) return ['off', 'پنهان'];
    if (m.end && m.end <= now()) return ['exp', 'منقضی شده'];
    return ['live', 'روی سایت نمایش داده می‌شود'];
  }

  function viewMsgs() {
    $view.appendChild(h('div', { class: 'intro' }, h('b', { text: 'پیام روی سایت کجا دیده می‌شود؟ ' }),
      'پیام‌های فعال به‌صورت نوار رنگی بالای صفحهٔ اصلی (که بازدیدکننده می‌تواند ببندد) و همچنین در بخش «تازه‌های پسته بازار» نمایش داده می‌شوند. اگر چند پیام فعال باشد نوار بالا به‌نوبت آن‌ها را نشان می‌دهد.'));
    var tpl = h('div', { class: 'tpl', style: 'margin-top:16px' }, h('span', { class: 'hint', text: 'قالب آماده:' }));
    MSG_TPL.forEach(function (t) {
      tpl.appendChild(h('button', { type: 'button', class: 'btn-soft', text: '+ ' + t[1], onclick: function () {
        cfg.messages.unshift({ id: uid(), type: t[0], title: t[2], body: t[3], link: '', linkLabel: '', active: true, pinned: false, end: now() + 7 * 86400, created: now() });
        touch(); render();
      } }));
    });
    tpl.appendChild(h('button', { type: 'button', class: 'btn-main', text: 'پیام دلخواه', onclick: function () {
      cfg.messages.unshift({ id: uid(), type: 'notice', title: '', body: '', link: '', linkLabel: '', active: true, pinned: false, end: null, created: now() });
      touch(); render();
    } }));
    $view.appendChild(tpl);
    if (!cfg.messages.length) {
      $view.appendChild(h('div', { class: 'empty' }, h('big', { text: '📣' }), 'هنوز پیامی نگذاشته‌اید. یکی از قالب‌ها را بزنید یا پیام دلخواه بنویسید.'));
      return;
    }
    var list = h('div', { class: 'list' });
    cfg.messages.forEach(function (m, i) { list.appendChild(msgItem(m, i)); });
    $view.appendChild(list);
  }

  function msgItem(m, idx) {
    var prev = h('div', { class: 'ann-prev' });
    function pUpd() { prev.textContent = ''; prev.appendChild(A.strip({ type: m.type, title: m.title || 'عنوان پیام', body: m.body, link: m.link, linkLabel: m.linkLabel }, function () {})); }
    pUpd();
    var typeRow = h('div', { class: 'chips' }, Object.keys(A.types).map(function (k) {
      return chip(A.types[k].i + ' ' + A.types[k].n, m.type === k, function () { m.type = k; touch(); render(); });
    }));
    function field(label, input) { return h('label', { class: 'fld' }, h('span', { text: label }), input); }
    var st = msgStatus(m);
    return h('article', { class: 'item' + (m.active ? '' : ' off') },
      h('div', { class: 'item-h' }, h('span', { class: 'status ' + st[0], text: st[1] }), h('div', { class: 'grow' }),
        toggle('نمایش بده', m.active, function (v) { m.active = v; touch(); render(); }),
        toggle('📌 ثابت (اول بیاید)', m.pinned, function (v) { m.pinned = v; touch(); })),
      h('div', { class: 'fld' }, h('span', { text: 'نوع پیام' }), typeRow),
      field('عنوان', h('input', { type: 'text', maxlength: 80, value: m.title, placeholder: 'مثلاً عید نوروز مبارک', oninput: function (e) { m.title = e.target.value; touch(); pUpd(); } })),
      field('متن پیام (اختیاری)', h('textarea', { maxlength: 600, value: m.body, oninput: function (e) { m.body = e.target.value; touch(); pUpd(); } })),
      h('div', { class: 'frow' },
        field('لینک دکمه (اختیاری)', h('input', { type: 'text', dir: 'ltr', value: m.link, placeholder: 'https://…  یا  download.html', oninput: function (e) { m.link = e.target.value; touch(); pUpd(); } })),
        field('متن دکمه', h('input', { type: 'text', maxlength: 30, value: m.linkLabel, placeholder: 'مثلاً دانلود نسخهٔ جدید', oninput: function (e) { m.linkLabel = e.target.value; touch(); pUpd(); } }))),
      endPicker(m, function () { render(); }),
      h('div', { class: 'fld' }, h('span', { text: 'پیش‌نمایش نوار بالای سایت' }), prev),
      h('div', { class: 'actions' }, h('span', { class: 'hint', style: 'margin:0', text: 'ساخته‌شده: ' + fmtDate(m.created || now()) }),
        h('button', { type: 'button', class: 'btn-soft btn-del', text: 'حذف پیام', onclick: function () { if (confirm('این پیام حذف شود؟')) { cfg.messages.splice(idx, 1); touch(); render(); } } }))
    );
  }

  /* =========================================================
     تب ۴: تنظیمات
     ========================================================= */
  function viewSet() {
    var o = h('input', { type: 'password', dir: 'ltr', autocomplete: 'current-password' });
    var n1 = h('input', { type: 'password', dir: 'ltr', autocomplete: 'new-password', minlength: 8 });
    var n2 = h('input', { type: 'password', dir: 'ltr', autocomplete: 'new-password', minlength: 8 });
    var btn = h('button', { type: 'button', class: 'btn-main', text: 'تغییر رمز', onclick: function () {
      if (n1.value.length < 8) return toast('رمز جدید دست‌کم ۸ نویسه باشد.', true);
      if (n1.value !== n2.value) return toast('رمز جدید و تکرارش یکسان نیست.', true);
      post('password', { old: o.value, 'new': n1.value }).then(function (r) {
        if (r.ok) { toast('رمز تغییر کرد ✓'); o.value = n1.value = n2.value = ''; }
        else toast(r.error === 'old' ? 'رمز فعلی اشتباه است.' : 'خطا در تغییر رمز.', true);
      });
    } });
    $view.appendChild(h('div', { class: 'grid2' },
      h('section', { class: 'card' }, h('h2', { text: 'تغییر رمز پنل' }), h('p', { class: 'hint', text: 'رمز قوی بگذارید؛ این پنل قیمت‌های سایت را عوض می‌کند.' }),
        h('div', { class: 'stack' }, h('label', { class: 'fld' }, h('span', { text: 'رمز فعلی' }), o), h('label', { class: 'fld' }, h('span', { text: 'رمز جدید' }), n1), h('label', { class: 'fld' }, h('span', { text: 'تکرار رمز جدید' }), n2), btn)),
      h('section', { class: 'card' }, h('h2', { text: 'خروج و اطلاعات' }),
        h('p', { class: 'hint', text: cfg.updated ? 'آخرین انتشار: ' + fmtDate(cfg.updated) : 'هنوز چیزی منتشر نشده.' }),
        h('div', { class: 'stack' },
          h('a', { class: 'btn-soft', href: '../', target: '_blank', rel: 'noopener', style: 'text-decoration:none;text-align:center', text: 'مشاهدهٔ سایت ↗' }),
          h('a', { class: 'btn-soft btn-del', href: './?out=1', style: 'text-decoration:none;text-align:center', text: 'خروج از پنل' })))
    ));
  }

  /* ---------- شبکه ---------- */
  function post(a, body) {
    return fetch('api.php?a=' + a, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': window.PB_CSRF }, body: JSON.stringify(body) })
      .then(function (r) { if (r.status === 401) { location.reload(); return { error: 'auth' }; } return r.json().catch(function () { return { error: 'bad' }; }); })
      .catch(function () { return { error: 'net' }; });
  }

  function save() {
    if (priceRefs && priceRefs.invalid) return toast('یکی از قیمت‌های با تخفیف از قیمت عادی بیشتر است. اصلاحش کنید.', true);
    for (var i = 0; i < cfg.campaigns.length; i++) {
      var c = cfg.campaigns[i];
      if (!c.title.trim()) { tab = 'camps'; renderAll(); return toast('یکی از تخفیف‌ها نام ندارد.', true); }
      if (!c.plans.length || !c.durations.length) { tab = 'camps'; renderAll(); return toast('برای هر تخفیف دست‌کم یک پلن و یک مدت انتخاب کنید.', true); }
    }
    for (var j = 0; j < cfg.messages.length; j++) {
      if (!cfg.messages[j].title.trim()) { tab = 'msgs'; renderAll(); return toast('یکی از پیام‌ها عنوان ندارد.', true); }
    }
    $save.disabled = true; $save.textContent = 'در حال ذخیره…';
    post('save', cfg).then(function (r) {
      $save.textContent = 'ذخیره و انتشار در سایت';
      if (r.ok) { cfg = r.config; offset = r.now - Math.floor(Date.now() / 1000); setDirty(false); render(); toast('در سایت منتشر شد ✓'); }
      else { $save.disabled = false; toast('ذخیره نشد. اینترنت یا دسترسی نوشتن پوشهٔ data را بررسی کنید.', true); }
    });
  }

  /* ---------- نمایش ---------- */
  function render() {
    var y = window.scrollY;
    $view.textContent = ''; priceRefs = null;
    ({ prices: viewPrices, camps: viewCamps, msgs: viewMsgs, set: viewSet })[tab]();
    window.scrollTo(0, y);
  }
  function renderAll() {
    document.getElementById('ttl').textContent = TITLES[tab][0];
    document.getElementById('sub').textContent = TITLES[tab][1];
    [].forEach.call(document.querySelectorAll('#tabs button'), function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === tab); });
    render();
  }

  document.getElementById('tabs').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-tab]'); if (!b) return;
    tab = b.getAttribute('data-tab'); renderAll(); window.scrollTo(0, 0);
  });
  $save.addEventListener('click', save);
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); if (dirty) save(); } });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  fetch('api.php?a=load', { credentials: 'same-origin' }).then(function (r) {
    if (r.status === 401) { location.reload(); throw 0; } return r.json();
  }).then(function (d) {
    cfg = d.config; window.PB_CSRF = d.csrf || window.PB_CSRF;
    offset = d.now - Math.floor(Date.now() / 1000);
    cfg.campaigns = cfg.campaigns || []; cfg.messages = cfg.messages || [];
    document.getElementById('loading').remove();
    renderAll();
  }).catch(function () {
    var l = document.getElementById('loading'); if (l) l.textContent = 'بارگذاری نشد. صفحه را دوباره باز کنید.';
  });
})();
