/* 地圖引擎抽象層 —— 見 souliong/docs/EXTENDING.md。
   MapEngine 是所有具體引擎（LeafletEngine／MapLibreEngine）的共同介面：viewer.core.js 與所有
   plugin 一律只透過 MapApp.getEngine() 拿到的這個介面操作地圖，不直接認得 Leaflet 或 MapLibre
   的 API。方法預設丟錯，逼具體引擎覆寫；3D 兩個方法預設 no-op，因為並非每個引擎都支援 3D
   （目前只有 MapLibreEngine 會覆寫，LeafletEngine 維持 no-op）。

   這裡也擺了幾個「兩個引擎都要用、但沒有第三個更適合的家」的共用小工具（版權標註組字、
   沒有 layers 設定時的保命底圖），放在這裡而不是各引擎重複一份，也不是塞進 viewer.core.js
   讓引擎檔反過來依賴它。 */
window.MapEngine = (() => {
  const APP = window.APP || { base: './' };
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  // layer.json 的 attribution 裡可以寫 {key} 引用翻譯字串（例：{osm_contributors}）
  const i18nSub = (s) => String(s == null ? '' : s).replace(/\{([a-z0-9_]+)\}/gi, (_, k) => t(k));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  // 版權標註（依 OSM 慣例含連結）：來源標註跟著圖層走（各層 layer.json 自己的 attribution，
  // 由 buildCredit() 去重串接），框架與自家連結則固定，依重要性排最後：引擎 → GitHub → Souliong → prjToka。
  // 分隔符號只在這裡定義一次，凡是要把好幾則署名接成一行的地方（下面的 creditListHtml()／
  // buildCredit()／這裡的自家連結）都套用同一個變數，不各自重複同一段字面字串。
  const SEP = ' &middot; ';
  const REPO_URL = 'https://github.com/zisunny104/souliong';
  const SITE_URL = (APP.base || '/');
  const ORG_URL = 'https://toka.dev';
  const CREDIT_OWN =
    '<span class="cr-sep" aria-hidden="true"></span>' +
    '<span class="cr-own">' +
      '<a href="' + REPO_URL + '" target="_blank" rel="noopener" aria-label="' + t('github_source_aria') + '"><i class="fa-brands fa-github"></i></a>' + SEP +
      '<a href="' + SITE_URL + '">Souliong</a>' + SEP + '<a href="' + ORG_URL + '" target="_blank" rel="noopener">prjToka</a>' +
    '</span>';

  // 版權小工具：一則署名一律是 {text, url, copyright, suffix} 這個固定形狀——manifest 的
  // attribution、引擎自己的署名連結（Leaflet／MapLibre）都套同一份渲染邏輯，圖磚來源或引擎不同
  // 只是換這幾個欄位的值，標籤(<a>)怎麼組、要不要加 &copy;、要不要接 i18n 字尾（如
  // {osm_contributors}）永遠由這裡決定，不會因為換平台就在別的地方另刻一份 HTML。
  function creditHtml(part) {
    if (!part) return '';
    const text = esc(i18nSub(part.text));
    const body = part.url ? '<a href="' + esc(part.url) + '" target="_blank" rel="noopener">' + text + '</a>' : text;
    // &copy; 跟後面的名稱之間用不斷行空格——換行時「©」絕不能跟它所屬的名稱拆到兩行，
    // 變成一個孤伶伶的符號吊在行尾
    return (part.copyright ? '&copy;&nbsp;' : '') + body + (part.suffix ? ' ' + i18nSub(part.suffix) : '');
  }
  // attribution 欄位可以是上面那種物件排成的陣列（新格式，多方署名各自標 text/url）；也相容
  // 純字串（manager.php／region3d.php／tilecut.php 讓管理員手打圖磚署名時存的就是字串，直接沿用）。
  function creditListHtml(attribution) {
    if (Array.isArray(attribution)) return attribution.map(creditHtml).filter(Boolean).join(SEP);
    return i18nSub(attribution);
  }

  // engineCredit：這份 manifests 實際掛在哪個引擎上的署名連結（Leaflet／MapLibre），由呼叫端
  // 傳入——同一份 manifests 理論上可能被不同引擎掛載，署名連結不該寫死在這個共用函式裡。
  function buildCredit(manifests, engineCredit) {
    const src = [];
    (manifests || []).forEach(m => {
      const a = creditListHtml(m && m.attribution);
      if (a && src.indexOf(a) < 0) src.push(a);   // 同一個來源疊兩層（例如底圖＋同來源道路層）只列一次
    });
    const eng = creditHtml(engineCredit);
    if (eng) src.push(eng);
    return '<span class="cr-ext">' + src.join(SEP) + '</span>' + CREDIT_OWN;
  }

  // APP.layers 缺席時的保命底圖（獨立部署，或 view.php 還沒更新到有 layers 的版本）。內容與
  // layers/carto-voyager/layer.json 一致——寧可重複一份設定，也不要因為少一個設定就整張地圖開天窗。
  // 放在這裡（而非某個引擎檔或 viewer.core.js）是因為兩邊都要用同一份：viewer.core.js 的
  // layerManifests() 解析 APP.layers 時要用它兜底，各引擎自己的圖層系統做第二層防禦時也要用它。
  const FALLBACK_LAYER = {
    id: 'carto-voyager', type: 'raster', pane: 'base',
    url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    urlDark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    subdomains: 'abcd', detectRetina: true, maxZoom: 20,
    attribution: [
      { text: 'OpenStreetMap', url: 'https://www.openstreetmap.org/copyright', copyright: true, suffix: '{osm_contributors}' },
      { text: 'CARTO', url: 'https://carto.com/attributions' },
    ],
  };

  class MapEngine {
    constructor(opts) { this.opts = opts || {}; }

    // ---- 生命週期 ----
    getCenter() { throw new Error('MapEngine.getCenter() not implemented'); }
    getZoom() { throw new Error('MapEngine.getZoom() not implemented'); }
    setView(center, zoom) { throw new Error('MapEngine.setView() not implemented'); }
    panTo(lat, lon, opts) { throw new Error('MapEngine.panTo() not implemented'); }
    fitBounds(latlonPairs, opts) { throw new Error('MapEngine.fitBounds() not implemented'); }
    destroy() {}

    // ---- 圖磚控制 ----
    mountControls(opts) {}
    onBackgroundClick(fn) { throw new Error('MapEngine.onBackgroundClick() not implemented'); }

    // 「左下地圖操作」：一串固定在縮放鈕同一個角落、跟著一起排版的按鈕（例如 #resetBtn，見
    // pages/view.php／viewer.core.js 的 mountControls() 呼叫）。哪顆鈕該出現、掛在哪個角落，
    // 統一由這裡的清單跟 require 決定（require 對應引擎子類上的能力旗標，例如 get supports3D()，
    // 沒填就是兩個引擎都掛）；引擎子類只需要各自實作 _addCornerControl() 這一個轉接器（把一個
    // 現成的 DOM 元素包成自家控制項系統認得的格式），不用各自重寫「哪顆鈕該不該出現」這段判斷。
    mountOpButtons(opButtons, position) {
      (opButtons || []).forEach(b => {
        if (!b || !b.el) return;
        if (b.require && !this[b.require]) return;
        this._addCornerControl(b.el, position);
      });
    }
    _addCornerControl(el, position) { throw new Error('MapEngine._addCornerControl() not implemented'); }

    // ---- marker（spec 形狀：{id, lat, lon, html, size:[w,h], anchor:[ax,ay], onClick}）----
    setMarkerLayer(layerKey, specs) { throw new Error('MapEngine.setMarkerLayer() not implemented'); }
    clearMarkerLayer(layerKey) { throw new Error('MapEngine.clearMarkerLayer() not implemented'); }
    onZoomThresholdCross(zoom, fn) { throw new Error('MapEngine.onZoomThresholdCross() not implemented'); }

    // ---- 路線／動畫點 ----
    drawPolyline(pts, style) { throw new Error('MapEngine.drawPolyline() not implemented'); }
    updatePolylinePoints(handle, pts) { throw new Error('MapEngine.updatePolylinePoints() not implemented'); }
    removePolyline(handle) { throw new Error('MapEngine.removePolyline() not implemented'); }
    drawPoint(lat, lon, style) { throw new Error('MapEngine.drawPoint() not implemented'); }
    updatePointPosition(handle, lat, lon) { throw new Error('MapEngine.updatePointPosition() not implemented'); }
    removePoint(handle) { throw new Error('MapEngine.removePoint() not implemented'); }

    // ---- 圖層堆疊 ----
    applyTheme(dark) {}
    styleUrl() { return ''; }

    // ---- 小地圖選點器 ----
    createMiniPicker(container, opts) { throw new Error('MapEngine.createMiniPicker() not implemented'); }

    // ---- 3D（並非每個引擎都支援；預設 no-op，見 assets/js/plugins/map3d.js）----
    get supports3D() { return false; }
    enter3D(cfg) {}
    exit3D() {}

    // ---- 快照擷圖（供封面圖片自動產生用，見 api/cover.php）----
    // 並非每個引擎都能擷出單張畫面：Leaflet 是多張 <img> 拼貼的 DOM/圖磚渲染，沒有單一 canvas
    // 可讀；MapLibre 是 WebGL 單一 canvas，才覆寫成真的能擷圖。不支援的引擎回傳 null，呼叫端
    // （viewer.core.js）據此完全跳過自動快照，該專案改為僅能由管理者自訂上傳封面。
    get supportsSnapshot() { return false; }
    getCanvasDataURL() { return null; }
  }

  MapEngine.buildCredit = buildCredit;
  MapEngine.FALLBACK_LAYER = FALLBACK_LAYER;

  return MapEngine;
})();
