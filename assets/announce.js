/* پیام‌های مدیر سایت: نوار بالای صفحه + بخش «تازه‌ها» */
(function (root) {
  var T = {
    greeting: { i: '🎉', n: 'تبریک' },
    update: { i: '🔄', n: 'آپدیت' },
    notice: { i: '📢', n: 'اطلاعیه' },
    warning: { i: '⚠️', n: 'هشدار' }
  };
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function safeHref(u) { return /^(https?:\/\/|tel:|mailto:|\/|#)/i.test(u || '') ? u : ''; }
  function link(cls, m) {
    var href = safeHref(m.link); if (!href) return null;
    var a = el('a', cls, m.linkLabel || 'بیشتر بدانید');
    a.href = href;
    if (/^https?:/i.test(href)) a.rel = 'noopener';
    return a;
  }
  function dateText(sec) {
    try { return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(sec * 1000)); } catch (e) { return ''; }
  }

  var A = { types: T, dateText: dateText };

  A.strip = function (m, onClose) {
    var t = T[m.type] || T.notice;
    var d = el('div', 'ann-item t-' + (m.type || 'notice'));
    d.appendChild(el('span', 'ic', t.i));
    var tx = el('div', 'tx'); tx.appendChild(el('b', null, m.title));
    if (m.body) tx.appendChild(el('span', null, m.body.replace(/\s+/g, ' ')));
    d.appendChild(tx);
    var a = link('go', m); if (a) d.appendChild(a);
    if (onClose) { var x = el('button', 'x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'بستن'); x.onclick = onClose; d.appendChild(x); }
    return d;
  };

  A.card = function (m) {
    var t = T[m.type] || T.notice;
    var c = el('article', 'nw t-' + (m.type || 'notice'));
    var h = el('div', 'ph2'); h.appendChild(el('span', 'ic', t.i)); h.appendChild(el('span', 'kind', t.n));
    if (m.pinned) h.appendChild(el('span', 'pin', '📌 ثابت'));
    c.appendChild(h);
    c.appendChild(el('h3', null, m.title));
    if (m.body) c.appendChild(el('p', null, m.body));
    var a = link('go', m); if (a) c.appendChild(a);
    if (m.created) c.appendChild(el('span', 'dt', dateText(m.created)));
    return c;
  };
  root.PBAnn = A;

  if (root.PB_ADMIN) return;

  function dismissed() { try { return JSON.parse(localStorage.getItem('pbAnnX') || '[]'); } catch (e) { return []; } }
  function dismiss(id) { try { var l = dismissed(); l.push(id); localStorage.setItem('pbAnnX', JSON.stringify(l.slice(-40))); } catch (e) {} }

  function start() {
    if (!root.PB || !PB.load) return;
    PB.load().then(function (cfg) {
      var msgs = cfg.messages || [];
      // بخش تازه‌ها (فقط صفحهٔ اصلی)
      var news = document.getElementById('news'), box = document.getElementById('nws');
      if (news && box && msgs.length) {
        msgs.forEach(function (m) { box.appendChild(A.card(m)); });
        news.hidden = false;
      }
      // نوار بالا
      var bar = document.getElementById('ann'); if (!bar) return;
      var gone = dismissed(), list = msgs.filter(function (m) { return gone.indexOf(m.id) < 0; });
      if (!list.length) return;
      var i = 0, timer;
      function show() {
        bar.textContent = '';
        if (!list.length) { bar.hidden = true; return; }
        if (i >= list.length) i = 0;
        var m = list[i];
        bar.appendChild(A.strip(m, function () {
          dismiss(m.id); list.splice(i, 1); show();
        }));
        if (list.length > 1) {
          var dots = el('div', 'ann-dots');
          list.forEach(function (_, k) { dots.appendChild(el('i', k === i ? 'on' : '')); });
          bar.appendChild(dots);
        }
        bar.hidden = false;
      }
      show();
      if (list.length > 1) timer = setInterval(function () { if (list.length > 1) { i++; show(); } }, 7000);
    }).catch(function () {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})(window);
