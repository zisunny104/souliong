/* 選用插件：3D 地圖模式（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.map3d 為 true 時，view.php 才會載入這個檔案。

   3D 渲染管線（建物排除、自訂模型、pitch 切換）本身在 MapLibreEngine（見
   assets/js/engine/maplibre-engine.js 的 enter3D()/exit3D()），這裡只剩下判斷「要不要另開
   一顆地圖」的膠水邏輯：
   - 如果這張地圖的主引擎是 MapLibre（任何向量底圖），就直接在同一顆地圖上呼叫
     enter3D()/exit3D()，3D 沿用專案原本的底圖樣式，不開第二個 WebGL context。
   - 否則（主引擎是 Leaflet、底圖是光柵圖磚），另開一顆獨立的 MapLibreEngine 載入 3D 專用 style，
     蓋在 #map 上面、切換時互相隱藏顯示，兩顆地圖互不知情，2D 地圖的任何狀態（圖層、投稿、主題）
     都不會被這裡碰到、也不會反過來被 3D 影響。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };

  class Map3DPlugin extends MapApp.Plugin {
    constructor() {
      super('map3d');
      this.container = null;
      this.standaloneEngine = null;
      this.activeEngine = null;
      this.active = false;
    }

    mount() {
      const cfg = window.APP && window.APP.map3d;
      // 沒有設定（模組沒開，或 provider 沒填 styleUrl）就整個不掛，2D 完全不受影響
      if (!cfg || !cfg.styleUrl) return;
      // WebGL 容錯：壞掉的 webview（Instagram/Line 內建瀏覽器常見）直接不出現切換鈕，
      // 不留一顆按下去只會看到黑畫面的按鈕。maplibregl.supported() 在 v6 被拿掉了（WebGL1
      // 支援整個移除、改成一律要求 WebGL2），改成不依賴 MapLibre API 表面的手動探測。
      if (typeof maplibregl === 'undefined' || !document.createElement('canvas').getContext('webgl2')) return;
      this.cfg = cfg;
      this.injectStyle();
      this.injectButton();
      // 「原地重用」時 activeEngine 就是主引擎，stateChange 已經會驅動它自己的 renderSpots()，
      // 這裡不用重畫；只有「另開一顆」的 standaloneEngine 是獨立於主流程的第二顆地圖，才需要
      // 在這裡手動把它的 spots 圖層跟著刷新。
      this.mapApp.onHook('stateChange', () => {
        if (this.active && this.activeEngine && this.activeEngine === this.standaloneEngine) {
          this.activeEngine.setMarkerLayer('spots', this.mapApp.spotMarkerSpecs());
        }
      });
    }

    injectStyle() {
      const style = document.createElement('style');
      style.textContent = `
        #map3d { position: fixed; inset: 0; z-index: 0; display: none }
        #map3dBtn.on { background: var(--accent); color: var(--accent-fg); border-color: var(--accent) }
      `;
      document.head.appendChild(style);
    }

    injectContainer() {
      const div = document.createElement('div');
      div.id = 'map3d';
      const mapEl = document.getElementById('map');
      if (mapEl && mapEl.parentNode) mapEl.parentNode.insertBefore(div, mapEl.nextSibling);
      else document.body.appendChild(div);
      this.container = div;
    }

    injectButton() {
      const btn = document.createElement('button');
      btn.id = 'map3dBtn';
      btn.className = 'icon-btn';
      btn.title = t('toggle_3d');
      btn.setAttribute('aria-label', t('toggle_3d_aria'));
      btn.innerHTML = '<i class="fa-solid fa-cube" aria-hidden="true"></i>';
      btn.onclick = () => this.toggle();
      const themeBtn = document.getElementById('themeBtn');
      if (themeBtn) themeBtn.insertAdjacentElement('beforebegin', btn);
      this.btn = btn;
    }

    styleUrlWithKey() {
      const { styleUrl, key } = this.cfg;
      if (!key) return styleUrl;
      return styleUrl + (styleUrl.includes('?') ? '&' : '?') + 'key=' + encodeURIComponent(key);
    }

    toggle() { this.active ? this.exit() : this.enter(); }

    enter() {
      this.mapApp.trackFeature('map3d');
      const primary = this.mapApp.getEngine();
      if (primary.type === 'maplibre') {
        this.activeEngine = primary;
      } else {
        document.getElementById('map').style.display = 'none';
        if (!this.container) this.injectContainer();
        this.container.style.display = 'block';
        if (!this.standaloneEngine) {
          this.standaloneEngine = new MapLibreEngine({
            container: this.container,
            center: [primary.getCenter().lat, primary.getCenter().lon],
            zoom: primary.getZoom(),
            manifests: [{ id: 'map3d-style', type: 'vector', pane: 'base', url: this.styleUrlWithKey() }],
          });
        } else {
          this.standaloneEngine.getRawMap().resize();   // 容器隱藏期間視窗尺寸可能變了（例如手機轉向）
        }
        this.activeEngine = this.standaloneEngine;
      }
      this.activeEngine.enter3D(this.cfg);
      this.activeEngine.setMarkerLayer('spots', this.mapApp.spotMarkerSpecs());
      this.active = true;
      this.btn.classList.add('on');
    }

    exit() {
      if (this.activeEngine) this.activeEngine.exit3D();
      if (this.activeEngine === this.standaloneEngine) {
        this.container.style.display = 'none';
        document.getElementById('map').style.display = '';
      }
      this.activeEngine = null;
      this.active = false;
      this.btn.classList.remove('on');
    }
  }

  new Map3DPlugin().init(window.MapApp);
})();
