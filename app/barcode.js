/*
 * پسته‌بازار — بارکد Code128 (زیرمجموعهٔ B) با JS خالص؛ بدون کتابخانه و بدون شبکه.
 * قرارداد: محتوای بارکد فقط خود «کد پسته» است (KH… / MX…)؛ هیچ قیمت، وزن یا داده‌ای از مشتری داخلش نیست.
 * کد موقت آفلاین (N…) هرگز چاپ نمی‌شود؛ به‌جای آن note() نمایش داده می‌شود.
 */
(function (root) {
  'use strict';

  // الگوهای Code128: اندیس 0..102 داده، 103/104/105 شروع A/B/C، 106 پایان
  const PAT = [
    '212222','222122','222221','121223','121322','131222','122213','122312',
    '132212','221213','221312','231212','112232','122132','122231','113222',
    '123122','123221','223211','221132','221231','213212','223112','312131',
    '311222','321122','321221','312212','322112','322211','212123','212321',
    '232121','111323','131123','131321','112313','132113','132311','211313',
    '231113','231311','112133','112331','132131','113123','113321','133121',
    '313121','211331','231131','213113','213311','213131','311123','311321',
    '331121','312113','312311','332111','314111','221411','431111','111224',
    '111422','121124','121421','141122','141221','112214','112412','122114',
    '122411','142112','142211','241211','221114','413111','241112','134111',
    '111242','121142','121241','114212','124112','124211','411212','421112',
    '421211','212141','214121','412121','111143','111341','131141','114113',
    '114311','411113','411311','113141','114131','311141','411131','211412',
    '211214','211232','2331112'
  ];
  const START_B = 104;
  const STOP = 106;

  /** true فقط برای کد واقعی انبار: KH یا MX و حداقل ۳ رقم */
  function isPrintable(code) {
    return typeof code === 'string' && /^(KH|MX)\d{3,}$/.test(code);
  }

  /** پاک‌سازی ورودی اسکن/تایپ: trim، حروف بزرگ، ارقام فارسی/عربی→لاتین، حذف هر چیزی غیر A-Z0-9 */
  function normalize(raw) {
    if (raw == null) return '';
    let s = String(raw).trim().toUpperCase();
    s = s.replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0));
    s = s.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660));
    return s.replace(/[^A-Z0-9]/g, '');
  }

  /** رشتهٔ الگو (عرض میله/فاصله‌ها به‌ترتیب) برای کد؛ کد باید ASCII چاپی باشد */
  function encode(code) {
    const vals = [];
    for (const ch of String(code)) {
      const v = ch.charCodeAt(0) - 32;
      if (v < 0 || v > 95) throw new Error('نویسهٔ غیرمجاز در بارکد');
      vals.push(v);
    }
    let sum = START_B;
    vals.forEach((v, i) => { sum += (i + 1) * v; });
    const check = sum % 103;
    let out = PAT[START_B];
    for (const v of vals) out += PAT[v];
    out += PAT[check] + PAT[STOP];
    return out;
  }

  /** SVG بارکد. کد غیرقابل‌چاپ = رشتهٔ خالی. */
  function svg(code, opts) {
    if (!isPrintable(code)) return '';
    const o = Object.assign({ height: 48, module: 2, quiet: 10, showText: true }, opts || {});
    const pattern = encode(code);
    let modules = 0;
    for (const ch of pattern) modules += Number(ch);
    const width = (modules + 2 * o.quiet) * o.module;
    const textH = o.showText ? 18 : 0;
    const height = o.height + textH + 4;
    let x = o.quiet * o.module;
    let bars = '';
    let isBar = true;
    for (const ch of pattern) {
      const w = Number(ch) * o.module;
      if (isBar) bars += `<rect x="${x}" y="0" width="${w}" height="${o.height}" fill="#000"/>`;
      x += w;
      isBar = !isBar;
    }
    const text = o.showText
      ? `<text x="${width / 2}" y="${o.height + 14}" text-anchor="middle" font-family="Tahoma, sans-serif" font-size="13" fill="#000" style="direction:ltr">${code}</text>`
      : '';
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" role="img" aria-label="بارکد ${code}" style="max-width:100%;height:auto"><rect width="100%" height="100%" fill="#fff"/>${bars}${text}</svg>`;
  }

  /** متن جایگزین برای کدهای آفلاین (N…) یا نامعتبر */
  function note() {
    return 'بارکد بعد از همگام‌سازی ساخته می‌شود';
  }

  /**
   * اسکنر USB/بلوتوث مثل کیبورد تایپ می‌کند و با Enter تمام می‌شود.
   * اگر مقدار ورودی کد قابل‌چاپ باشد: onCode(code) و ورودی خالی می‌شود؛ وگرنه رفتار جستجوی عادی می‌ماند.
   */
  function attachWedge(inputEl, onCode) {
    if (!inputEl) return;
    inputEl.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      const code = normalize(inputEl.value);
      if (!isPrintable(code)) return;
      e.preventDefault();
      inputEl.value = '';
      onCode(code);
    });
  }

  /**
   * اسکن با دوربین عقب (BarcodeDetector). بعد از اولین کد معتبر دوربین بسته می‌شود و onCode صدا زده می‌شود.
   * onUnsupported(reason): reason = 'unsupported' (مرورگر پشتیبانی ندارد) | 'denied' (دسترسی/دوربین خطا داد).
   * هیچ فراخوانی شبکه‌ای ندارد؛ هنگام بستن یا خطا همهٔ track ها متوقف می‌شوند.
   */
  function scan(onCode, onUnsupported) {
    const Det = typeof window !== 'undefined' ? window.BarcodeDetector : undefined;
    const hasCam = typeof navigator !== 'undefined' && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
    if (!Det || !hasCam || typeof document === 'undefined') {
      if (onUnsupported) onUnsupported('unsupported');
      return;
    }
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px';
    const video = document.createElement('video');
    video.setAttribute('playsinline', '');
    video.muted = true;
    video.autoplay = true;
    video.style.cssText = 'max-width:100%;max-height:75vh';
    const cancel = document.createElement('button');
    cancel.textContent = 'بستن';
    cancel.style.cssText = 'padding:10px 24px;font-size:16px';
    overlay.appendChild(video);
    overlay.appendChild(cancel);
    document.body.appendChild(overlay);

    let stream = null, stopped = false, timer = 0;
    const stop = () => {
      if (stopped) return;
      stopped = true;
      clearTimeout(timer);
      if (stream) stream.getTracks().forEach((t) => t.stop());
      video.srcObject = null;
      overlay.remove();
    };
    const fail = (reason) => { stop(); if (onUnsupported) onUnsupported(reason); };
    cancel.onclick = stop;

    const detector = new Det({ formats: ['code_128'] });
    const loop = () => {
      if (stopped) return;
      detector.detect(video).then((codes) => {
        if (stopped) return;
        for (const c of codes || []) {
          const code = normalize(c.rawValue);
          if (isPrintable(code)) { stop(); onCode(code); return; }
        }
        timer = setTimeout(loop, 150);
      }).catch(() => { if (!stopped) timer = setTimeout(loop, 300); });
    };
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
      .then((s) => {
        if (stopped) { s.getTracks().forEach((t) => t.stop()); return null; }
        stream = s;
        video.srcObject = s;
        return video.play();
      })
      .then(() => { if (!stopped) loop(); })
      .catch(() => fail('denied'));
  }

  const api = { PAT, isPrintable, normalize, encode, svg, note, attachWedge, scan };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.PBBarcode = api;
})(typeof window !== 'undefined' ? window : globalThis);
