/*
 * دستیار پسته بازار — رندرکنندهٔ بلوک‌های پروتکل v1 (کلاینت وب).
 * قواعد:
 *  - هیچ منطق کسب‌وکاری اینجا نیست؛ همه‌چیز از سرور (assistant.php) می‌آید.
 *  - همهٔ متن‌های داده با textContent نشان داده می‌شوند؛ innerHTML برای داده سرور استفاده نمی‌شود.
 *  - بلوک ناشناخته نادیده گرفته نمی‌شود؛ یک نوتیس نشان داده می‌شود.
 * API عمومی:
 *   window.PBAssistant.open({ getAuth: () => ({ phone, token }), onAuthError: () => {} })
 */
(function () {
  'use strict';
  if (window.PBAssistant) return;

  var API = new URL('../backend/api/assistant.php', location.href).href;
  var REQUEST_TIMEOUT_MS = 20000;
  var AI_TIMEOUT_MS = 60000;   // پسته‌کاو (A5): پاسخ مدل کندتر است؛ فقط برای q=ai_ask و step=answer
  var AI_MAX_CHARS = 500;
  var ROOT_ACTION = { q: 'menu', step: 'start' };
  var DEFAULT_THEME = { assistant: '#E8F5E9', user: '#FFFFFF', support: '#FFF3E0', text: '#1b1b1b', accent: '#2e7d32' };

  var st = {
    open: false,
    overlay: null,
    title: null,
    backBtn: null,
    body: null,
    getAuth: function () { return { phone: '', token: '' }; },
    onAuthError: function () {},
    current: null,      // اکشن صفحهٔ فعلی
    stack: [],          // اکشن‌های صفحه‌های قبلی برای دکمهٔ بازگشت
    token: 0,           // شمارندهٔ درخواست؛ پاسخ قدیمی‌تر از این شمارنده دور ریخته می‌شود
    chat: null,         // وضعیت چت پشتیبانی روی صفحهٔ فعلی (یا null)
    onVisibility: null,
    aiPending: null     // حالت انتظار پرسش پسته‌کاو: { token, node, disabled[] }
  };

  /* ------------------------------------------------------------ ابزارها */

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }

  function faDigits(n) {
    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(d)); });
  }

  function safeColor(v, fallback) {
    return (typeof v === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(v)) ? v : fallback;
  }

  function applyTheme(theme) {
    var t = theme || {};
    var r = st.overlay.style;
    r.setProperty('--pbas-assistant', safeColor(t.assistant, DEFAULT_THEME.assistant));
    r.setProperty('--pbas-user', safeColor(t.user, DEFAULT_THEME.user));
    r.setProperty('--pbas-support', safeColor(t.support, DEFAULT_THEME.support));
    r.setProperty('--pbas-accent', safeColor(t.accent, DEFAULT_THEME.accent));
  }

  function newClientId() {
    var s = '';
    if (window.crypto && window.crypto.getRandomValues) {
      var a = new Uint8Array(16);
      window.crypto.getRandomValues(a);
      for (var i = 0; i < a.length; i++) s += ('0' + a[i].toString(16)).slice(-2);
    } else {
      s = String(Date.now()) + String(Math.random()).slice(2);
    }
    return ('c' + s).slice(0, 40).replace(/[^A-Za-z0-9_-]/g, '');
  }

  /* ------------------------------------------------------------ درخواست به سرور */

  /**
   * یک اکشن را به سرور می‌فرستد. همیشه resolve می‌شود با:
   *   { ok, data } | { ok:false, message, retryable, auth }
   */
  function request(action) {
    var auth = st.getAuth() || {};
    var payload = { phone: auth.phone || '', token: auth.token || '', q: action.q, step: action.step || 'start' };
    if (action.params) payload.params = action.params;

    var isAi = isAiAnswer(action);
    var timedOut = false;
    var ctl = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { timedOut = true; ctl.abort(); }, isAi ? AI_TIMEOUT_MS : REQUEST_TIMEOUT_MS) : null;

    return fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      signal: ctl ? ctl.signal : undefined,
      cache: 'no-store'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { status: res.status, data: data || {} };
      });
    }, function () {
      return { status: 0, data: {}, aiTimeout: timedOut && isAi };
    }).then(function (res) {
      if (timer) clearTimeout(timer);
      return interpret(res);
    });
  }

  function interpret(res) {
    if (res.status === 401) {
      st.onAuthError();
      closeAssistant();
      return { ok: false, auth: true, message: '' };
    }
    if (res.status === 0) {
      if (res.aiTimeout) return { ok: false, retryable: true, message: 'پاسخ پسته‌کاو طول کشید؛ دوباره تلاش کنید' };
      return { ok: false, retryable: true, message: 'اتصال به اینترنت برقرار نیست؛ دوباره تلاش کنید' };
    }
    if (res.status >= 500) {
      return { ok: false, retryable: true, message: (res.data && res.data.error) || 'خطای داخلی؛ دوباره تلاش کنید' };
    }
    if (res.status !== 200 || !res.data || !Array.isArray(res.data.blocks)) {
      return { ok: false, message: (res.data && res.data.error) || 'پاسخ نامعتبر از سرور' };
    }
    return { ok: true, data: res.data };
  }

  /* ------------------------------------------------------------ ناوبری */

  /* ------------------------------------------------------------ پسته‌کاو (A5): حالت انتظار */

  function isAiAnswer(a) {
    return !!a && a.q === 'ai_ask' && a.step === 'answer';
  }

  /** فرم/صفحه را نگه می‌دارد، همهٔ دکمه‌ها و فیلدهای صفحه را غیرفعال می‌کند و پیام انتظار را می‌افزاید */
  function showAiPending(token) {
    clearAiPending();
    var disabled = [];
    Array.prototype.forEach.call(st.body.querySelectorAll('button, textarea, input'), function (n) {
      if (!n.disabled) { n.disabled = true; disabled.push(n); }
    });
    var node = el('div', 'pbas-loading pbas-ai-pending', 'پسته‌کاو در حال بررسی است…');
    node.setAttribute('role', 'status');
    st.body.appendChild(node);
    st.aiPending = { token: token, node: node, disabled: disabled };
    st.body.scrollTop = st.body.scrollHeight;
  }

  /** فقط حالت انتظارِ همان درخواست را برمی‌دارد (پاسخ قدیمی نباید انتظار درخواست جدیدتر را پاک کند) */
  function clearAiPending(token) {
    var p = st.aiPending;
    if (!p) return;
    if (token !== undefined && p.token !== token) return;
    st.aiPending = null;
    if (p.node.parentNode) p.node.parentNode.removeChild(p.node);
    p.disabled.forEach(function (n) { n.disabled = false; });
  }

  /** بعد از رندر پاسخ: فوکوس فیلد خالی + اسکرول نرم به ابتدای آخرین حباب دستیار */
  function afterAiAnswer() {
    var inp = st.body.querySelector('.pbas-ai-input');
    if (inp) {
      try { inp.focus({ preventScroll: true }); } catch (e) { inp.focus(); }
    }
    var bubbles = st.body.querySelectorAll('.pbas-role-assistant');
    var last = bubbles.length ? bubbles[bubbles.length - 1] : null;
    if (last && last.scrollIntoView) {
      var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      try { last.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' }); } catch (e) { last.scrollIntoView(); }
    }
  }

  function setLoading() {
    st.body.textContent = '';
    st.body.appendChild(el('div', 'pbas-loading', 'در حال بارگذاری…'));
  }

  function renderError(message, retry) {
    stopChat();
    st.body.textContent = '';
    st.body.appendChild(el('div', 'pbas-notice pbas-tone-error', message || 'خطا رخ داد'));
    if (retry) {
      var b = el('button', 'pbas-btn pbas-btn-primary', 'تلاش دوباره');
      b.type = 'button';
      b.addEventListener('click', retry);
      st.body.appendChild(b);
    }
  }

  /**
   * صفحه‌ای را از سرور می‌گیرد و نشان می‌دهد.
   * opts.push: صفحه فعلی به پشته اضافه شود (برای بازگشت)
   * opts.onError(res): اگر داده شود، به‌جای نشان‌دادن خطای کل صفحه صدا زده می‌شود (مثلاً فرم)
   */
  function go(action, opts) {
    opts = opts || {};
    var my = ++st.token;
    var ai = isAiAnswer(action);
    if (ai) {
      if (!opts.keepScreen) st.body.textContent = '';
      showAiPending(my);
    } else if (!opts.keepScreen) {
      setLoading();
    }
    return request(action).then(function (res) {
      if (ai) clearAiPending(my);
      if (my !== st.token || !st.open) return;
      if (!res.ok) {
        if (res.auth) return;
        if (opts.onError) return opts.onError(res);
        return renderError(res.message, res.retryable ? function () { go(action, opts); } : null);
      }
      // پاسخ پسته‌کاو روی پشتهٔ بازگشت نمی‌رود: «بازگشت» نباید دوباره مدل را صدا بزند (هزینه)
      if (opts.push && st.current && !isAiAnswer(st.current)) st.stack.push(st.current);
      st.current = action;
      render(res.data);
    });
  }

  function back() {
    if (!st.stack.length) return go(ROOT_ACTION, { push: false });
    var prev = st.stack.pop();
    return go(prev, { push: false });
  }

  /* ------------------------------------------------------------ رندر صفحه */

  function render(data) {
    stopChat();
    applyTheme(data.theme);
    st.title.textContent = data.title || 'دستیار پسته بازار';
    st.backBtn.hidden = st.stack.length === 0 && (!st.current || st.current.q === 'menu');
    st.body.textContent = '';
    st.body.scrollTop = 0;

    var blocks = Array.isArray(data.blocks) ? data.blocks : [];
    blocks.forEach(function (b) {
      var node = renderBlock(b);
      if (node) st.body.appendChild(node);
    });
    if (data.data_note) st.body.appendChild(el('p', 'pbas-note', data.data_note));
    if (st.chat) {
      st.chat.log.scrollTop = st.chat.log.scrollHeight;
      startChatTimer();
    }
    if (isAiAnswer(st.current)) afterAiAnswer();
  }

  function renderBlock(b) {
    if (!b || typeof b !== 'object') return null;
    switch (b.type) {
      case 'text': return blockText(b);
      case 'notice': return blockNotice(b);
      case 'buttons': return blockButtons(b);
      case 'choice': return blockChoice(b);
      case 'form': return blockForm(b);
      case 'table': return blockTable(b);
      case 'card': return blockCard(b);
      case 'chat': return blockChat(b);
      case 'confirm': return blockConfirm(b);
      default:
        return el('div', 'pbas-notice pbas-tone-info', 'این بخش در این نسخه پشتیبانی نمی‌شود');
    }
  }

  function blockTitle(text) {
    return text ? el('h3', 'pbas-h', text) : null;
  }

  function wrap(children) {
    var d = el('div', 'pbas-block');
    children.forEach(function (c) { if (c) d.appendChild(c); });
    return d;
  }

  function blockText(b) {
    var role = (b.role === 'user' || b.role === 'support') ? b.role : 'assistant';
    var bubble = el('div', 'pbas-bubble pbas-role-' + role);
    bubble.appendChild(el('div', 'pbas-text', b.text));
    return wrap([bubble]);
  }

  function blockNotice(b) {
    var tone = ['info', 'warning', 'error', 'success'].indexOf(b.tone) >= 0 ? b.tone : 'info';
    return wrap([el('div', 'pbas-notice pbas-tone-' + tone, b.text)]);
  }

  function actionButton(item, extraCls) {
    var btn = el('button', 'pbas-btn' + (extraCls ? ' ' + extraCls : ''));
    btn.type = 'button';
    if (item.icon) btn.appendChild(el('span', 'pbas-btn-icon', item.icon));
    btn.appendChild(el('span', 'pbas-btn-label', item.label || ''));
    if (item.badge !== undefined && item.badge !== null && Number(item.badge) > 0) {
      btn.appendChild(el('span', 'pbas-badge', faDigits(Number(item.badge))));
    }
    btn.addEventListener('click', function () {
      if (item.action) go(item.action, { push: true });
    });
    return btn;
  }

  function blockButtons(b) {
    var list = el('div', 'pbas-buttons');
    (b.items || []).forEach(function (it) { list.appendChild(actionButton(it)); });
    return wrap([list]);
  }

  function blockChoice(b) {
    var items = Array.isArray(b.items) ? b.items : [];
    var list = el('div', 'pbas-choice-list');
    var rows = [];
    items.forEach(function (it) {
      var btn = el('button', 'pbas-choice');
      btn.type = 'button';
      btn.appendChild(el('span', 'pbas-choice-label', it.label || ''));
      if (it.sublabel) btn.appendChild(el('span', 'pbas-choice-sub', it.sublabel));
      btn.addEventListener('click', function () { if (it.action) go(it.action, { push: true }); });
      list.appendChild(btn);
      rows.push({ node: btn, text: ((it.label || '') + ' ' + (it.sublabel || '')).toLowerCase() });
    });
    var kids = [blockTitle(b.title)];
    if (b.search) {
      var input = el('input', 'pbas-search');
      input.type = 'search';
      input.placeholder = 'جستجو…';
      input.setAttribute('aria-label', 'جستجو در فهرست');
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        rows.forEach(function (r) { r.node.hidden = q !== '' && r.text.indexOf(q) < 0; });
      });
      kids.push(input);
    }
    kids.push(list);
    if (!items.length) kids.push(el('div', 'pbas-note', 'موردی برای نمایش نیست'));
    return wrap(kids);
  }

  /* جداکنندهٔ هزارگان هنگام تایپ (سه‌رقم‌سه‌رقم با ویرگول)؛ ارقام فارسی/عربی به لاتین تبدیل می‌شوند و مکان نشانگر حفظ می‌شود.
     سرور مقدار «3,100,000» را درست می‌خواند. */
  function attachThousands(inp) {
    function digitsOnly(str) {
      return String(str)
        .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
        .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
        .replace(/\D/g, '');
    }
    inp.addEventListener('input', function () {
      var v = inp.value;
      var pos = inp.selectionStart;
      if (pos === null || pos === undefined) pos = v.length;
      var digitsBefore = digitsOnly(v.slice(0, pos)).length;
      var out = digitsOnly(v).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      if (out === v) return;
      inp.value = out;
      var p = 0, c = 0;
      while (p < out.length && c < digitsBefore) { if (/\d/.test(out.charAt(p))) c++; p++; }
      try { inp.setSelectionRange(p, p); } catch (e) {}
    });
  }

  /* فرم (مثلاً محاسبه‌گر کوت‌ریزی). مقادیر تایپ‌شده بدون تبدیل فرستاده می‌شوند. */
  function blockForm(b) {
    var fields = Array.isArray(b.fields) ? b.fields : [];
    var rep = b.repeat && typeof b.repeat === 'object' ? b.repeat : null;
    var min = rep ? Math.max(1, Number(rep.min) || 1) : 1;
    var max = rep ? Math.max(min, Number(rep.max) || 50) : 1;
    var rowsBox = el('div', 'pbas-rows');
    var rows = [];
    var errBox = el('div', 'pbas-form-err');
    errBox.hidden = true;
    var addBtn = null;
    var aiForm = !!(b.submit && b.submit.action && b.submit.action.q === 'ai_ask');   // فقط فرم پسته‌کاو
    var submitBtn = el('button', 'pbas-btn pbas-btn-primary', (b.submit && b.submit.label) || 'ثبت');
    submitBtn.type = 'button';

    function refreshButtons() {
      if (addBtn) addBtn.disabled = rows.length >= max;
      rows.forEach(function (r) { if (r.remove) r.remove.disabled = rows.length <= min; });
    }

    function addRow() {
      var idx = rows.length;
      var box = el('div', 'pbas-row');
      var head = el('div', 'pbas-row-head');
      if (rep) head.appendChild(el('span', 'pbas-row-no', 'ردیف ' + faDigits(idx + 1)));
      var rowObj = { inputs: {}, box: box, remove: null };
      if (rep) {
        var rm = el('button', 'pbas-link', rep.remove_label || 'حذف ردیف');
        rm.type = 'button';
        rm.addEventListener('click', function () {
          if (rows.length <= min) return;
          var i = rows.indexOf(rowObj);
          if (i >= 0) rows.splice(i, 1);
          box.remove();
          renumber();
          refreshButtons();
        });
        rowObj.remove = rm;
        head.appendChild(rm);
      }
      box.appendChild(head);
      var grid = el('div', 'pbas-fields');
      fields.forEach(function (f) {
        var lab = el('label', 'pbas-field');
        lab.appendChild(el('span', 'pbas-field-label', f.label || f.name));
        var inp;
        if (aiForm && f.kind === 'text') {
          // پرسش پسته‌کاو: Enter ارسال، Shift+Enter خط جدید، سقف ۵۰۰ نویسه
          inp = el('textarea', 'pbas-input pbas-ai-input');
          inp.rows = 2;
          inp.maxLength = AI_MAX_CHARS;
          inp.placeholder = 'پرسش خود را بنویسید…';
          inp.setAttribute('inputmode', 'text');
          inp.setAttribute('enterkeyhint', 'send');
          inp.setAttribute('autocomplete', 'off');
          inp.setAttribute('dir', 'auto');
          inp.setAttribute('aria-label', f.label || f.name || 'پرسش');
          inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
              e.preventDefault();
              if (!inp.disabled) submit();
            }
          });
        } else {
          inp = el('input', 'pbas-input');
          inp.type = 'text';
          inp.setAttribute('inputmode', 'decimal');
          inp.setAttribute('autocomplete', 'off');
          inp.setAttribute('autocorrect', 'off');
          inp.setAttribute('spellcheck', 'false');
          if (f.format === 'thousands') attachThousands(inp);
        }
        inp.name = String(f.name || '');
        lab.appendChild(inp);
        if (f.hint) lab.appendChild(el('span', 'pbas-field-hint', f.hint));
        rowObj.inputs[f.name] = inp;
        grid.appendChild(lab);
      });
      box.appendChild(grid);
      rowsBox.appendChild(box);
      rows.push(rowObj);
      refreshButtons();
    }

    function renumber() {
      if (!rep) return;
      rows.forEach(function (r, i) {
        var no = r.box.querySelector('.pbas-row-no');
        if (no) no.textContent = 'ردیف ' + faDigits(i + 1);
      });
    }

    function collect() {
      function valuesOf(r) {
        var o = {};
        Object.keys(r.inputs).forEach(function (k) { o[k] = r.inputs[k].value; });
        return o;
      }
      if (rep) return { rows: rows.map(valuesOf) };
      return { values: rows.length ? valuesOf(rows[0]) : {} };
    }

    function submit() {
      if (!b.submit || !b.submit.action) return;
      if (aiForm && submitBtn.disabled) return;              // جلوگیری از ارسال دوباره در حال انتظار
      errBox.hidden = true;
      errBox.textContent = '';
      if (aiForm) {
        var qv = String((collect().values || {}).q_text || '').trim();
        if (!qv) { errBox.textContent = 'لطفاً پرسش خود را بنویسید.'; errBox.hidden = false; return; }
      }
      var action = JSON.parse(JSON.stringify(b.submit.action));
      action.params = Object.assign({}, action.params || {}, collect());
      submitBtn.disabled = true;
      go(action, {
        push: true,
        keepScreen: true,
        onError: function (res) {
          // فرم و مقادیر وارد‌شده دست‌نخورده می‌مانند؛ فقط پیام خطا نشان داده می‌شود
          submitBtn.disabled = false;
          errBox.textContent = res.message || 'خطا رخ داد';
          errBox.hidden = false;
        }
      }).then(function () { submitBtn.disabled = false; });
    }

    for (var i = 0; i < min; i++) addRow();
    submitBtn.addEventListener('click', submit);

    var kids = [blockTitle(b.title), rowsBox];
    if (rep) {
      addBtn = el('button', 'pbas-btn pbas-btn-secondary', rep.add_label || 'افزودن ردیف');
      addBtn.type = 'button';
      addBtn.addEventListener('click', function () {
        if (rows.length >= max) return;
        addRow();
      });
      kids.push(addBtn);
    }
    kids.push(errBox, submitBtn);
    return wrap(kids);
  }

  function blockTable(b) {
    var cols = Array.isArray(b.columns) ? b.columns : [];
    var table = el('table', 'pbas-table');
    var thead = el('thead');
    var htr = el('tr');
    cols.forEach(function (c) {
      var th = el('th', c.align === 'end' ? 'pbas-end' : '', c.label || '');
      th.setAttribute('scope', 'col');
      htr.appendChild(th);
    });
    thead.appendChild(htr);
    table.appendChild(thead);

    var tbody = el('tbody');
    (Array.isArray(b.rows) ? b.rows : []).forEach(function (row) {
      var tr = el('tr');
      var cells = Array.isArray(row) ? row : [];
      cols.forEach(function (c, i) {
        var td = el('td', c.align === 'end' ? 'pbas-end' : '', cells[i] === undefined || cells[i] === null ? '' : cells[i]);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);

    if (Array.isArray(b.footer) && b.footer.length) {
      var tfoot = el('tfoot');
      var ftr = el('tr');
      cols.forEach(function (c, i) {
        var v = b.footer[i];
        ftr.appendChild(el('td', c.align === 'end' ? 'pbas-end' : '', v === undefined || v === null ? '' : v));
      });
      tfoot.appendChild(ftr);
      table.appendChild(tfoot);
    }

    var scroller = el('div', 'pbas-table-wrap');
    scroller.appendChild(table);
    return wrap([blockTitle(b.title), scroller]);
  }

  function lineNode(line) {
    var tone = ['pos', 'neg', 'warn'].indexOf(line.tone) >= 0 ? ' pbas-' + line.tone : '';
    var row = el('div', 'pbas-line');
    row.appendChild(el('span', 'pbas-line-label', line.label || ''));
    row.appendChild(el('span', 'pbas-line-value' + tone, line.value === undefined || line.value === null ? '' : line.value));
    return row;
  }

  function blockCard(b) {
    var box = el('div', 'pbas-card');
    if (b.title) box.appendChild(el('div', 'pbas-card-title', b.title));
    (Array.isArray(b.lines) ? b.lines : []).forEach(function (ln) { box.appendChild(lineNode(ln)); });
    if (b.highlight) box.appendChild(el('div', 'pbas-highlight', b.highlight));
    return wrap([box]);
  }

  function blockConfirm(b) {
    var box = el('div', 'pbas-card pbas-confirm');
    if (b.title) box.appendChild(el('div', 'pbas-card-title', b.title));
    (Array.isArray(b.lines) ? b.lines : []).forEach(function (ln) { box.appendChild(lineNode(ln)); });
    var yes = el('button', 'pbas-btn pbas-btn-primary', (b.confirm && b.confirm.label) || 'تأیید');
    yes.type = 'button';
    yes.addEventListener('click', function () {
      // ثبت فقط یک‌بار ارسال شود (کلیک دوباره تا رسیدن پاسخ بی‌اثر است)
      if (yes.disabled || !(b.confirm && b.confirm.action)) return;
      yes.disabled = true;
      go(b.confirm.action, { push: true }).then(function () { yes.disabled = false; }, function () { yes.disabled = false; });
    });
    var no = el('button', 'pbas-btn pbas-btn-secondary', (b.cancel && b.cancel.label) || 'انصراف');
    no.type = 'button';
    no.addEventListener('click', function () { back(); });
    return wrap([box, yes, no]);
  }

  /* ------------------------------------------------------------ چت پشتیبانی */

  function blockChat(b) {
    var chat = {
      log: el('div', 'pbas-log'),
      status: el('div', 'pbas-chat-status'),
      lastId: 0,
      seen: {},
      welcomeShown: false,
      pollAction: (b.poll && b.poll.action) || null,
      sendAction: (b.send && b.send.action) || null,
      interval: Math.max(2000, Number((b.poll && b.poll.interval_ms) || 5000)),
      timer: null,
      polling: false,
      sending: false,
      errBox: el('div', 'pbas-form-err'),
      input: null,
      sendBtn: null
    };
    chat.errBox.hidden = true;

    var form = el('form', 'pbas-chat-form');
    var ta = el('textarea', 'pbas-input pbas-chat-input');
    ta.rows = 2;
    ta.maxLength = 1000;
    ta.placeholder = 'پیام خود را بنویسید…';
    ta.setAttribute('aria-label', 'متن پیام');
    var sendBtn = el('button', 'pbas-btn pbas-btn-primary pbas-send', 'ارسال');
    sendBtn.type = 'submit';
    chat.input = ta;
    chat.sendBtn = sendBtn;

    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      sendChat(chat);
    });
    form.appendChild(ta);
    form.appendChild(sendBtn);

    applyChatBlocks(chat, b);
    st.chat = chat;
    return wrap([chat.status, chat.log, chat.errBox, form]);
  }

  function applyChatBlocks(chat, block) {
    if (block && block.type === 'chat') {
      appendMessages(chat, block.messages);
    }
  }

  function appendMessages(chat, list) {
    if (!Array.isArray(list)) return;
    var atEnd = isNearEnd(chat.log) || chat.lastId === 0;
    list.forEach(function (m) {
      if (!m || typeof m !== 'object') return;
      var id = Number(m.id) || 0;
      if (id === 0) {
        // پیام خوش‌آمد: فقط یک‌بار، و ذخیره‌نشده
        if (m.sender !== 'assistant' || chat.welcomeShown) return;
        chat.welcomeShown = true;
      } else {
        if (chat.seen[id]) return;
        chat.seen[id] = true;
        if (id > chat.lastId) chat.lastId = id;
      }
      chat.log.appendChild(messageNode(m));
    });
    if (atEnd) chat.log.scrollTop = chat.log.scrollHeight;
  }

  function messageNode(m) {
    var sender = m.sender === 'user' || m.sender === 'support' ? m.sender : 'assistant';
    var row = el('div', 'pbas-msg pbas-from-' + sender);
    var bubble = el('div', 'pbas-bubble pbas-role-' + sender);
    bubble.appendChild(el('div', 'pbas-text', m.body || ''));
    if (m.time) bubble.appendChild(el('div', 'pbas-msg-time', m.time));
    row.appendChild(bubble);
    return row;
  }

  function isNearEnd(box) {
    return box.scrollHeight - box.scrollTop - box.clientHeight < 120;
  }

  function setChatStatus(chat, block) {
    chat.status.textContent = '';
    if (block && block.type === 'notice') chat.status.appendChild(el('div', 'pbas-notice pbas-tone-info', block.text));
  }

  /** بلوک‌های پاسخ poll/send: نوتیس وضعیت گفتگو + پیام‌های تازه */
  function absorb(chat, blocks) {
    (Array.isArray(blocks) ? blocks : []).forEach(function (b) {
      if (b.type === 'notice') setChatStatus(chat, b);
      else if (b.type === 'chat') appendMessages(chat, b.messages);
    });
  }

  function sendChat(chat) {
    var text = chat.input.value.trim();
    if (!text || chat.sending || !chat.sendAction) return;
    chat.sending = true;
    chat.sendBtn.disabled = true;
    chat.errBox.hidden = true;
    var action = JSON.parse(JSON.stringify(chat.sendAction));
    action.params = { body: text, client_id: newClientId() };
    request(action).then(function (res) {
      if (st.chat !== chat) return;
      chat.sending = false;
      chat.sendBtn.disabled = false;
      if (!res.ok) {
        if (res.auth) return;
        chat.errBox.textContent = res.message || 'ارسال انجام نشد';
        chat.errBox.hidden = false;
        return; // متن پیام می‌ماند تا کاربر دوباره بفرستد
      }
      chat.input.value = '';
      absorb(chat, res.data.blocks);
      chat.log.scrollTop = chat.log.scrollHeight;
    });
  }

  function pollChat(chat) {
    if (st.chat !== chat || !chat.pollAction || chat.polling || chat.sending || document.hidden) return;
    chat.polling = true;
    var action = JSON.parse(JSON.stringify(chat.pollAction));
    action.params = { after_id: chat.lastId };
    request(action).then(function (res) {
      chat.polling = false;
      if (st.chat !== chat || !res.ok) return;
      absorb(chat, res.data.blocks);
    });
  }

  function startChatTimer() {
    var chat = st.chat;
    if (!chat) return;
    stopChatTimer();
    chat.timer = setInterval(function () { pollChat(chat); }, chat.interval);
  }

  function stopChatTimer() {
    if (st.chat && st.chat.timer) clearInterval(st.chat.timer);
    if (st.chat) st.chat.timer = null;
  }

  function stopChat() {
    stopChatTimer();
    st.chat = null;
  }

  /* ------------------------------------------------------------ بازشدن و بسته‌شدن */

  function ensureDom() {
    if (st.overlay) return;
    var ov = el('div', 'pbas-overlay');
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'دستیار پسته بازار');
    var sheet = el('div', 'pbas-sheet');

    var head = el('header', 'pbas-head');
    var backBtn = el('button', 'pbas-icon', '→');
    backBtn.type = 'button';
    backBtn.setAttribute('aria-label', 'بازگشت');
    backBtn.addEventListener('click', function () { backFromHeader(); });
    var title = el('div', 'pbas-title', 'دستیار پسته بازار');
    var close = el('button', 'pbas-icon', '✕');
    close.type = 'button';
    close.setAttribute('aria-label', 'بستن دستیار');
    close.addEventListener('click', function () { closeAssistant(); });
    head.appendChild(backBtn);
    head.appendChild(title);
    head.appendChild(close);

    var body = el('main', 'pbas-body');
    sheet.appendChild(head);
    sheet.appendChild(body);
    ov.appendChild(sheet);
    ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAssistant(); });

    st.overlay = ov;
    st.title = title;
    st.backBtn = backBtn;
    st.body = body;
  }

  function backFromHeader() {
    if (st.stack.length === 0 && (!st.current || st.current.q === 'menu')) return;
    back();
  }

  function closeAssistant() {
    if (!st.open) return;
    st.open = false;
    st.token++;
    stopChat();
    if (st.onVisibility) document.removeEventListener('visibilitychange', st.onVisibility);
    st.onVisibility = null;
    if (st.overlay && st.overlay.parentNode) st.overlay.parentNode.removeChild(st.overlay);
    st.stack = [];
    st.current = null;
  }

  function open(opts) {
    opts = opts || {};
    if (typeof opts.getAuth === 'function') st.getAuth = opts.getAuth;
    if (typeof opts.onAuthError === 'function') st.onAuthError = opts.onAuthError;
    if (st.open) return;
    ensureDom();
    st.open = true;
    st.stack = [];
    st.current = null;
    applyTheme(DEFAULT_THEME);
    document.body.appendChild(st.overlay);
    st.onVisibility = function () {
      if (!st.open || !st.chat) return;
      if (document.hidden) stopChatTimer();
      else { pollChat(st.chat); startChatTimer(); }
    };
    document.addEventListener('visibilitychange', st.onVisibility);
    go(ROOT_ACTION, { push: false });
  }

  window.PBAssistant = { open: open, close: closeAssistant };
})();
