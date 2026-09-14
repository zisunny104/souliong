/* 數字鍵盤／一般鍵盤切換輸入元件。
   用法：在 <input> 加上 data-pin-toggle 屬性即可，載入本檔會自動掃描套用。
   預設呼出手機原生數字鍵盤；按切換鈕可換成一般鍵盤直接輸入英數字。
   無外部依賴，view.php（有 window.I18N）與 manager.php（無 I18N，純中文頁面）都能共用。 */
(function () {
  const I18N = window.I18N || {};
  const t = (key, fallback) => (I18N[key] != null ? I18N[key] : fallback);
  // 不依賴 Font Awesome：manager.php 未登入前的頁面刻意不載入外部資源，用行內 SVG 確保一定看得到圖示
  const ICON_KEYBOARD = '<svg viewBox="0 0 20 14" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="1" width="18" height="12" rx="2"/><path d="M4 5h.01M8 5h.01M12 5h.01M16 5h.01M4 9h.01M8 9h8"/></svg>';
  const ICON_NUMERIC = '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 2v12M10 2v12M1.5 6h13M1.5 11h13"/></svg>';

  // 遮罩用的幾何圖案（取代瀏覽器原生密碼圓點）；沿用專案品牌圖形語彙：圓角多邊形，顏色跟隨 currentColor
  const MASK_KINDS = ['dot', 'triangle', 'square', 'pentagon', 'hexagon', 'line'];
  const MASK_MAX = 20;
  function polyPoints(sides, R, rot) {
    rot = rot || 0;
    const p = [];
    for (let i = 0; i < sides; i++) { const a = (-90 + rot + i * 360 / sides) * Math.PI / 180; p.push((12 + R * Math.cos(a)).toFixed(1) + ',' + (12 + R * Math.sin(a)).toFixed(1)); }
    return p.join(' ');
  }
  // 還沒填的格子：空心圓點，只用來預告「這個欄位共幾位」，填進去之後才換成上面那組實心幾何圖案
  const EMPTY_SLOT_SVG = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="4.5"/></svg>';
  function maskShapeSVG(kind) {
    const open = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="4" stroke-linejoin="round" stroke-linecap="round">';
    const inner = {
      dot: '<circle cx="12" cy="12" r="5.5" stroke="none"/>',
      line: '<rect x="3.5" y="8.5" width="17" height="7" rx="3.5" stroke="none"/>',
      triangle: '<polygon points="' + polyPoints(3, 8.2, 0) + '"/>',
      square: '<rect x="5.5" y="5.5" width="13" height="13" rx="4.5" transform="rotate(7 12 12)"/>',
      pentagon: '<polygon points="' + polyPoints(5, 8, -8) + '"/>',
      hexagon: '<polygon points="' + polyPoints(6, 8, 30) + '"/>',
    }[kind] || '';
    return open + inner + '</svg>';
  }

  // 真正輸入仍走原生欄位（含數字鍵盤/切換），只是把看得見的字換成幾何圖案疊層。
  // 掛了 data-pin-toggle 的欄位一律套用——這些欄位就是 PIN／投稿代碼，本來就該遮起來，
  // 不再只認 type="password"：view.php 裡除了管理者 PIN 之外的幾個欄位都是 type="text"，
  // 只認 password 的話那幾個欄位會直接把碼明碼顯示，遮罩形同沒做。
  // 個別欄位若真的需要看見原文，加 data-pin-mask="off" 就好。
  function buildMaskOverlay(input, wrap) {
    if (input.dataset.pinMask === 'off') return;
    // data-pin-slots="6"：長度固定的欄位（例如投稿代碼）先用空心圓點把 6 格位置預告出來，
    // 每輸入一位就把該格換成實心幾何圖案，看得出「還差幾位」。長度不固定的欄位（管理者 PIN）
    // 不宣告這個屬性，就維持「打幾位長幾個圖案」，不會謊報一個其實不存在的位數。
    const slots = Math.min(parseInt(input.dataset.pinSlots || '', 10) || 0, MASK_MAX);
    input.style.color = 'transparent';
    // 有預告格子時關掉原生游標：文字是透明的，游標會落在透明字的位置，跟置中排列的圖案對不齊，
    // 看起來像多出一條跟任何一格都無關的線；改用把「下一格」畫得比其他空格明顯來指示進度。
    input.style.caretColor = slots ? 'transparent' : 'currentColor';
    // 格子本身已經說明了位數，再留一行「6 位數字」的預設填充字會跟圖案疊在一起
    if (slots) input.placeholder = '';
    const overlay = document.createElement('span');
    overlay.className = 'pin-mask-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    wrap.appendChild(overlay);
    function render() {
      const n = Math.min(input.value.length, MASK_MAX);
      const total = Math.max(n, slots);
      let html = '';
      for (let i = 0; i < total; i++) {
        if (i < n) {
          html += '<span class="pin-mask-dot' + (i === n - 1 ? ' pop' : '') + '">' + maskShapeSVG(MASK_KINDS[i % MASK_KINDS.length]) + '</span>';
        } else {
          html += '<span class="pin-mask-dot pin-blank' + (i === n ? ' pin-next' : '') + '">' + EMPTY_SLOT_SVG + '</span>';
        }
      }
      overlay.innerHTML = html;
    }
    input.addEventListener('input', render);
    render();
  }

  const ICON_DEL = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><path d="M18 9l-6 6M12 9l6 6"/></svg>';
  const ICON_OK = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>';
  const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'del', '0', 'ok'];

  // 欄位是空白密碼框，實體鍵盤不一定在手邊（平板/桌機測試、或單純想用滑鼠點）——
  // 掛 data-pin-keypad 的欄位才長這個，目前只用在 PIN 登入那兩個欄位。
  function buildKeypad(input, wrap) {
    if (input.dataset.pinKeypad == null) return null;
    const pad = document.createElement('div');
    pad.className = 'pin-keypad';
    KEYS.forEach(function (k) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'pin-key' + (k === 'ok' ? ' ok' : '') + (k === 'del' ? ' del' : '');
      b.setAttribute('aria-label', k === 'del' ? t('delete', '刪除') : k === 'ok' ? t('confirm_ok', '確認') : k);
      b.innerHTML = k === 'del' ? ICON_DEL : k === 'ok' ? ICON_OK : k;
      b.addEventListener('click', function () {
        if (k === 'del') {
          input.value = input.value.slice(0, -1);
        } else if (k === 'ok') {
          submitField(input);
          return;
        } else {
          input.value += k;
        }
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
      });
      pad.appendChild(b);
    });
    wrap.parentNode.insertBefore(pad, wrap.nextSibling);
    return pad;
  }

  // 有原生 <form> 就走原生送出（manager.php 的後台登入）；沒有的欄位（view.php 是 fetch
  // 呼叫 API，不是表單）改派發一個假的 Enter keydown——那邊本來就掛了實體 Enter 的監聽，
  // 借用同一條路徑送出，不用讓這個共用元件認得個別頁面的送出函式。
  function submitField(input) {
    if (input.form) {
      if (input.form.requestSubmit) input.form.requestSubmit();
      else input.form.submit();
      return;
    }
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
  }

  function upgrade(input) {
    if (input.dataset.pinToggled) return;
    input.dataset.pinToggled = '1';

    const wrap = document.createElement('span');
    wrap.className = 'pin-toggle-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    buildMaskOverlay(input, wrap);
    const pad = buildKeypad(input, wrap);
    // 有畫面鍵盤（pad）的欄位改用 inputmode="none" 擋掉手機原生鍵盤，改用畫面鍵盤輸入；
    // 沒有畫面鍵盤的欄位（投稿代碼／專案 PIN 等）沒有別的輸入方式，還是要呼叫原生數字鍵盤。
    input.setAttribute('inputmode', pad ? 'none' : 'numeric');
    input.setAttribute('pattern', '[0-9]*');

    // data-pin-digits-only：後端會把非數字字元濾掉的欄位（投稿代碼），打英數字沒有意義，
    // 不出「切換成一般鍵盤」鈕，讓它固定呼叫系統數字鍵盤就好。PIN 欄位後端沒有格式限制
    // （可能設英數混合），保留切換鈕。
    if (input.dataset.pinDigitsOnly != null) return;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pin-toggle-btn';
    wrap.appendChild(btn);

    let keyboardMode = false;
    function render() {
      const label = keyboardMode
        ? t('pin_toggle_numeric', '切換成數字鍵盤')
        : t('pin_toggle_keyboard', '切換成鍵盤輸入');
      btn.title = label;
      btn.setAttribute('aria-label', label);
      btn.innerHTML = keyboardMode ? ICON_NUMERIC : ICON_KEYBOARD;
      // 切成一般鍵盤代表要打英數字，畫面數字鍵盤沒意義，收起來；切回數字模式才重新顯示。
      if (pad) pad.style.display = keyboardMode ? 'none' : '';
    }
    btn.addEventListener('click', function () {
      keyboardMode = !keyboardMode;
      if (keyboardMode) {
        input.setAttribute('inputmode', 'text');
        input.removeAttribute('pattern');
      } else {
        input.setAttribute('inputmode', pad ? 'none' : 'numeric');
        input.setAttribute('pattern', '[0-9]*');
      }
      render();
      input.focus();
    });
    render();
  }

  function scan(root) {
    (root || document).querySelectorAll('input[data-pin-toggle]').forEach(upgrade);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else {
    scan();
  }

  // 供動態插入的欄位（例如對話框開啟時才建立的 input）事後呼叫
  window.PinInput = { scan: scan };
})();
