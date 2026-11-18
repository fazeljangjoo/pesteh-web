/* S8.T3 — همگام‌سازی لحظه‌ای وب (poll سبک). معادل lib/sync_plan.dart + lib/sync_service.dart (S7).
 * بخش ۱ (PBSyncPlan): منطق خالص و بدون DOM؛ با backend/tests/sync_plan_cases.json در Node تست می‌شود.
 * بخش ۲ (PBRealtime): فقط مرورگر. هر poll_interval ثانیه (پیش‌فرض ۴، از پاسخ سرور؛ ۲..۶۰) با sync_ping.php
 *   (اگر ۴۰۴/۴۰۵: sync.version از app.php) فقط وقتی تب دیده می‌شود؛ بازگشت به تب = poll فوری؛ خطای شبکه ⇒ backoff تا ۶۰ث.
 *   صف ارسال همیشه اول؛ وسط ویرایش (مودال/فیلد فعال/فرم پذیرش) تغییر منتظر می‌ماند (مبنا جلو نمی‌رود) و نوار «به‌روزرسانی جدید»
 *   نشان داده می‌شود؛ به‌محض بسته‌شدن فرم (تایمر ۱ث) اعمال می‌شود. «اعمال» = تازه‌سازی نمای فعلی (وب کش محلیِ جدا ندارد). */
(function (root) {
  'use strict';

  // ------------------------------------------------------------------ بخش ۱: منطق خالص
  var INTAKE_ENTITIES = ['lot_intakes', 'lot_intake_pcts', 'pct_defs'];
  var MIN_FULL_GAP_MS = 8000;      // حداقل فاصلهٔ دو تازه‌سازی کامل خودکار
  var OWN_TTL_MS = 120000;         // عمر «تغییر خودِ این تب»
  // همان PB_SYNC_WRITE_VERB_RE سرور (backend/api/sync_version.php)
  var WRITE_VERB_RE = /\.(save|create|update|delete|transition|approve|apply|pay|receive|post|unpost|settle|rebuild|adjust|add|set_status|convert|return|transfer|transfer_cancel|cancel|mark_paid|attach|confirm|reject|mark_sample|submit|seed|import|allocate|deliver|sold|link_sales|settings_save|line_update|run_create|run_delete|pay_line_add|pay_line_delete|params_save|upgrade_apply|price_settings)$/;

  /** آیا اکشن (احتمالاً) در سرور ver را بالا می‌برد؟ (تقریب سمت کلاینت برای ثبت «تغییر خودی») */
  function isWriteAction(action) {
    action = String(action || '');
    if (!action || action.indexOf('sync.') === 0 || action.indexOf('reports.') === 0) return false;
    return WRITE_VERB_RE.test(action);
  }
  function keyOf(action, entity) { return action + '|' + (entity == null ? '' : entity); }
  /** همان pb_sync_entity_of سرور */
  function entityOfOwn(action, payload) {
    if (action.indexOf('entity.') === 0) {
      var e = payload && payload.entity;
      return typeof e === 'string' ? e : null;
    }
    if (action.indexOf('intake.') === 0) return 'lot_intakes';
    var i = action.indexOf('.');
    return i > 0 ? action.substring(0, i) : action;
  }

  /** changes: [{ver,action,entity}]؛ ownKeys: فهرست کلیدهای تغییر خودی (چندمجموعه)؛ isKnownEntity: fn(name)→bool */
  function plan(o) {
    if (o.reset) return { entities: [], fullPull: true, consumedOwn: [], foreignCount: 0, hasWork: true };
    var own = {}, k, i;
    (o.ownKeys || []).forEach(function (x) { own[x] = (own[x] || 0) + 1; });
    var consumed = [], ents = {}, full = false, foreign = 0;
    (o.changes || []).forEach(function (c) {
      k = keyOf(c.action, c.entity);
      if ((own[k] || 0) > 0) { own[k]--; consumed.push(k); return; }
      foreign++;
      var a = c.action;
      if (a.indexOf('entity.') === 0) {
        if (c.entity != null && o.isKnownEntity(c.entity)) ents[c.entity] = true; // ناشناخته/بی‌دسترسی ⇒ نادیده
        return;
      }
      if (a.indexOf('intake.') === 0) {
        for (i = 0; i < INTAKE_ENTITIES.length; i++) if (o.isKnownEntity(INTAKE_ENTITIES[i])) ents[INTAKE_ENTITIES[i]] = true;
        if (a === 'intake.confirm') full = true; // قطعی‌سازی خرید/موجودی هم می‌سازد
        return;
      }
      full = true; // هر ناحیهٔ غیر-entity
    });
    var list = Object.keys(ents).sort();
    return { entities: list, fullPull: full, consumedOwn: consumed, foreignCount: foreign, hasWork: full || list.length > 0 };
  }

  /** تصمیم یک poll: {kind: baseline|idle|advance|wait_queue|wait_edit|wait_gap|apply, ver?, plan?} */
  function decide(o) {
    var res = o.res || {};
    if (o.since == null) return { kind: 'baseline', ver: res.ver };
    if (res.ver === o.since && !res.reset) return { kind: 'idle' };
    var p = plan({ changes: res.changes || [], reset: !!res.reset, ownKeys: o.ownKeys, isKnownEntity: o.isKnownEntity });
    if (!p.hasWork) return { kind: 'advance', ver: res.ver, plan: p };
    if (o.queueLen > 0) return { kind: 'wait_queue', plan: p };   // صف ارسال همیشه اول
    if (o.editing) return { kind: 'wait_edit', plan: p };          // فرم در حال ویرایش دست‌نخورده می‌ماند
    if (p.fullPull && (o.now - (o.lastFull || 0)) < MIN_FULL_GAP_MS) return { kind: 'wait_gap', plan: p };
    return { kind: 'apply', ver: res.ver, plan: p };
  }

  /** فاصلهٔ poll بعدی (ثانیه): موفق ⇒ pollSec؛ خطا ⇒ دو برابر شونده تا ۶۰ */
  function nextDelaySec(pollSec, failStreak) {
    var base = pollSec < 2 ? 2 : (pollSec > 60 ? 60 : pollSec);
    if (failStreak <= 0) return base;
    var d = base;
    for (var i = 0; i < failStreak && d < 60; i++) d *= 2;
    return d > 60 ? 60 : d;
  }

  var PBSyncPlan = { INTAKE_ENTITIES: INTAKE_ENTITIES, MIN_FULL_GAP_MS: MIN_FULL_GAP_MS, isWriteAction: isWriteAction, keyOf: keyOf, entityOfOwn: entityOfOwn, plan: plan, decide: decide, nextDelaySec: nextDelaySec };

  if (typeof module !== 'undefined' && module.exports) { module.exports = PBSyncPlan; return; }
  root.PBSyncPlan = PBSyncPlan;

  // ------------------------------------------------------------------ بخش ۲: اجرا در مرورگر
  var KNOWN = { lot_intakes: 1, lot_intake_pcts: 1, pct_defs: 1, warehouses: 1, ounces: 1 }; // entityهایی که وب می‌شناسد
  var SINCE_KEY = 'pb_sync_ver';
  var SAFETY_MS = 600000; // شبکهٔ ایمنی: اگر ۱۰ دقیقه چیزی اعمال نشد یک تازه‌سازی (وقتی ویرایشی نیست)
  var st = {
    cfg: null, running: false, timer: null, editTimer: null, since: null, pollSec: 4, failStreak: 0, offline: false, busy: false,
    useApp: false, pending: false, lastFull: 0, lastApply: 0, own: [], view: null, probes: [], applying: false,
  };

  function now() { return Date.now(); }
  function clamp(n) { n = Number(n); if (!isFinite(n)) return 4; return Math.max(2, Math.min(60, Math.round(n))); }
  function ownKeys() { var t = now(); st.own = st.own.filter(function (x) { return t - x.ts < OWN_TTL_MS; }); return st.own.map(function (x) { return x.key; }); }
  function consumeOwn(keys) {
    keys.forEach(function (k) { var i = st.own.findIndex(function (x) { return x.key === k; }); if (i >= 0) st.own.splice(i, 1); });
  }
  function setSince(v) {
    v = Number(v);
    if (!isFinite(v)) return;
    st.since = v;
    try { localStorage.setItem(SINCE_KEY, (st.cfg && st.cfg.getAuth().phone) + ':' + v); } catch (e) { /* اختیاری */ }
  }
  function storedSince() {
    try {
      var s = String(localStorage.getItem(SINCE_KEY) || ''), i = s.lastIndexOf(':');
      if (i > 0 && s.slice(0, i) === st.cfg.getAuth().phone) { var v = Number(s.slice(i + 1)); return isFinite(v) ? v : null; }
    } catch (e) { /* نادیده */ }
    return null;
  }

  function isEditing() {
    var i;
    for (i = 0; i < st.probes.length; i++) { try { if (st.probes[i]()) return true; } catch (e) { /* نادیده */ } }
    if (document.querySelector('#modal-root .modal-overlay')) return true;
    var a = document.activeElement;
    if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && !/^(checkbox|radio|button|submit)$/.test(a.type || '')) {
      if (a.closest && (a.closest('#content') || a.closest('#modal-root'))) return true;
    }
    return false;
  }

  // ---- نشانگر وضعیت + نوار «به‌روزرسانی جدید»
  function chip() {
    var el = document.getElementById('rt-chip');
    if (!el) {
      var host = document.querySelector('.topbar-right');
      if (!host) return null;
      el = document.createElement('span');
      el.id = 'rt-chip'; el.className = 'rt-chip rt-off hidden'; el.setAttribute('role', 'status');
      host.insertBefore(el, host.firstChild);
    }
    return el;
  }
  function renderChip() {
    var el = chip(); if (!el) return;
    if (!st.running) { el.classList.add('hidden'); return; }
    var q = st.cfg && st.cfg.queueLength ? st.cfg.queueLength() : 0;
    var kind = st.offline ? 'off' : ((q > 0 || st.pending || st.applying) ? 'wait' : 'ok');
    el.className = 'rt-chip rt-' + kind;
    el.textContent = kind === 'off' ? 'آفلاین' : (kind === 'wait' ? 'در حال همگام‌سازی' : 'همگام');
    el.title = kind === 'ok' ? 'داده‌ها به‌روزند' : '';
  }
  function renderBanner() {
    var el = document.getElementById('rt-banner');
    if (!st.pending) { if (el) el.remove(); return; }
    if (!el) {
      el = document.createElement('div');
      el.id = 'rt-banner'; el.className = 'rt-banner'; el.setAttribute('role', 'status');
      el.textContent = 'به‌روزرسانی جدید — بعد از بستن فرم اعمال می‌شود';
      document.body.appendChild(el);
    }
  }
  function setPending(on) {
    if (st.pending === on) return;
    st.pending = on;
    renderBanner(); renderChip();
    clearInterval(st.editTimer); st.editTimer = null;
    if (on) st.editTimer = setInterval(function () { if (!isEditing()) { setPending(false); pollNow(); } }, 1000);
  }
  function setOffline(v) { if (st.offline !== v) { st.offline = v; } renderChip(); }

  // ---- شبکه
  function fetchTO(url, body) {
    var ctl = new AbortController(), t = setTimeout(function () { ctl.abort(); }, 15000);
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), signal: ctl.signal, cache: 'no-store' })
      .finally(function () { clearTimeout(t); });
  }
  function httpErr(r, data) { var e = new Error((data && data.error) || ('HTTP ' + r.status)); e.status = r.status; return e; }
  async function ping() {
    var a = st.cfg.getAuth(), body = { phone: a.phone, token: a.token };
    if (st.since != null) body.since_ver = st.since;
    var r, data;
    if (!st.useApp) {
      r = await fetchTO(st.cfg.apiBase + 'sync_ping.php', body);
      if (r.status === 404 || r.status === 405) { st.useApp = true; }
      else {
        try { data = await r.json(); } catch (e) { data = {}; }
        if (!r.ok) throw httpErr(r, data);
        return data;
      }
    }
    // سرور بدون sync_ping.php: همان پاسخ از app.php (اکشن sync.version)
    r = await fetchTO(st.cfg.apiBase + 'app.php', { phone: a.phone, token: a.token, action: 'sync.version', payload: st.since != null ? { since_ver: st.since } : {}, unit: st.cfg.getUnit ? st.cfg.getUnit() : undefined });
    try { data = await r.json(); } catch (e) { data = {}; }
    if (r.status === 404 || r.status === 400 || (r.ok && data && data.ver === undefined)) { var u = new Error('unsupported'); u.unsupported = true; throw u; }
    if (!r.ok) throw httpErr(r, data);
    return data;
  }

  function applyPlan(p) {
    var v = st.view;
    if (!v || typeof v.refresh !== 'function') return;
    var need = (p.fullPull && v.full) || (v.entities && p.entities.some(function (e) { return v.entities.indexOf(e) !== -1; }));
    if (!need) return;
    st.applying = true; renderChip();
    Promise.resolve().then(function () { return v.refresh(); }).catch(function () { /* خطای نمایش را خود نما نشان می‌دهد */ })
      .finally(function () { st.applying = false; renderChip(); });
  }

  async function tick() {
    if (!st.running || st.busy || document.hidden) return;
    st.busy = true;
    try {
      if (st.cfg.queueLength() > 0 && navigator.onLine) { try { await st.cfg.flush(); } catch (e) { /* ادامه */ } }
      var res = await ping();
      st.failStreak = 0; setOffline(false);
      if (res && res.poll_interval != null) st.pollSec = clamp(res.poll_interval);
      var d = decide({
        since: st.since, res: res, ownKeys: ownKeys(), queueLen: st.cfg.queueLength(), editing: isEditing(), now: now(), lastFull: st.lastFull,
        isKnownEntity: function (e) { return !!KNOWN[e]; },
      });
      if (d.kind === 'baseline') { setSince(d.ver); st.lastApply = now(); }
      else if (d.kind === 'advance') { consumeOwn(d.plan.consumedOwn); setSince(d.ver); }
      else if (d.kind === 'apply') {
        consumeOwn(d.plan.consumedOwn); setSince(d.ver); setPending(false); // ver قبل از اعمال ثبت می‌شود؛ تغییرِ حین اعمال در poll بعد دیده می‌شود
        st.lastApply = now(); if (d.plan.fullPull) st.lastFull = now();
        applyPlan(d.plan);
      }
      else if (d.kind === 'wait_edit') { setPending(true); }
      else if (d.kind === 'idle') {
        if (!st.pending && now() - st.lastApply > SAFETY_MS && !isEditing() && st.cfg.queueLength() === 0) { st.lastApply = now(); applyPlan({ fullPull: true, entities: [] }); }
      }
      // wait_queue / wait_gap: مبنا جلو نمی‌رود؛ poll بعد دوباره می‌سنجد
    } catch (e) {
      if (e && e.status === 401) { stop(); if (st.cfg.onUnauthorized) st.cfg.onUnauthorized(); return; }
      if (e && (e.status === 403 || e.unsupported)) { stop(); return; } // اشتراک غیرفعال / سرور قدیمی ⇒ رفتار قبلی (فقط flush صف)
      st.failStreak++; setOffline(true);
    } finally {
      st.busy = false; renderChip(); schedule();
    }
  }
  function schedule() {
    clearTimeout(st.timer); st.timer = null;
    if (!st.running || document.hidden) return;
    st.timer = setTimeout(tick, nextDelaySec(st.pollSec, st.failStreak) * 1000);
  }
  function pollNow() { clearTimeout(st.timer); st.timer = null; return tick(); }

  function onVisibility() { if (document.hidden) { clearTimeout(st.timer); st.timer = null; } else pollNow(); }
  function onOnline() { st.failStreak = 0; pollNow(); }
  function onOffline() { setOffline(true); }

  /** cfg: {apiBase, getAuth()→{phone,token}, getUnit?(), queueLength(), flush(), onUnauthorized?(), ver?, pollSec?} */
  function start(cfg) {
    stop();
    st.cfg = cfg; st.running = true; st.failStreak = 0; st.offline = false; st.useApp = false; st.pending = false;
    st.pollSec = clamp(cfg.pollSec == null ? 4 : cfg.pollSec);
    var v = cfg.ver != null ? Number(cfg.ver) : NaN;
    if (isFinite(v)) setSince(v); else st.since = storedSince(); // بوت‌استرپ تازه ⇒ مبنا همان؛ وگرنه آخرین مبنای ذخیره‌شده/ping اول
    st.lastApply = now();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('online', onOnline); window.addEventListener('offline', onOffline);
    renderChip();
    schedule();
  }
  function stop() {
    st.running = false;
    clearTimeout(st.timer); clearInterval(st.editTimer); st.timer = null; st.editTimer = null; st.pending = false;
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('online', onOnline); window.removeEventListener('offline', onOffline);
    renderBanner(); renderChip();
  }

  root.PBRealtime = {
    start: start, stop: stop, pollNow: pollNow, isEditing: isEditing,
    /** هر نوشتنِ موفق این تب (بعد از پاسخ سرور) ⇒ برای نادیده‌گرفتن در poll بعد */
    noteOwn: function (action, payload) {
      if (!st.running || !isWriteAction(action)) return;
      st.own.push({ key: keyOf(action, entityOfOwn(action, payload || {})), ts: now() });
    },
    /** نمای فعلی: {refresh(), full:bool (تغییر غیر-entity هم تازه‌اش کند), entities:[..]} */
    setView: function (v) { st.view = v || null; },
    /** probe() → true یعنی کاربر وسط ویرایش است (مثل isEditing فلاتر) */
    addEditingProbe: function (fn) { st.probes.push(fn); },
    status: function () { return { running: st.running, since: st.since, pollSec: st.pollSec, offline: st.offline, pending: st.pending, useApp: st.useApp }; },
  };
})(typeof window !== 'undefined' ? window : this);
