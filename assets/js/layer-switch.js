/* 訪客端圖層切換（後台勾選的圖層合計至少兩張時，view.php 才載入這個檔案）
   勾選的圖層就是可挑的範圍：多張底圖擇一（單選）、多張疊圖各自開關；一張就沒得挑。
   預設是後台指定的底圖加全部疊圖，訪客的選擇只存在自己的瀏覽器。
   外觀只用核心的主題變數，深淺色與響應式都跟著核心走。 */
(() => {
  const APP = window.APP || {};
  const I18N = window.I18N || {};
  const t = (key) => (I18N[key] != null ? I18N[key] : key);
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const isBase = (m) => (m.pane || 'art') === 'base';

  class LayerSwitchPlugin extends MapApp.Plugin {
    constructor() { super('layerSwitch'); }

    mount() {
      this.all = [].concat(APP.layers || [], APP.layerExtra || []);
      this.bases = this.all.filter(isBase);
      this.overlays = this.all.filter(m => !isBase(m));
      // 只有一張就沒得挑
      if (this.bases.length < 2) this.bases = [];
      if (this.overlays.length < 2) this.overlays = [];
      if (!this.bases.length && !this.overlays.length) return;
      this.key = 'souliong:layers:' + (APP.project || '');
      this.state = this.defaults();
      this.load();
      this.injectStyle();
      const attach = (engine) => {
        if (this.engine || !engine || !engine.supportsLayerSwitch) return;
        this.engine = engine;
        this.injectButton();
        if (this.differsFromDefault()) this.apply();
      };
      attach(this.mapApp.getEngine());
      this.mapApp.onHook('engineReady', attach);
      this.mapApp.onHook('closeAll', () => this.close());
    }

    defaults() {
      // 預設底圖就是伺服器端挑出、放在 APP.layers 裡的那一張；疊圖預設全開
      const cur = (APP.layers || []).filter(isBase).pop();
      return { base: cur ? cur.id : null, on: new Set(this.overlays.map(m => m.id)) };
    }

    differsFromDefault() {
      const d = this.defaults();
      if (d.base !== this.state.base || d.on.size !== this.state.on.size) return true;
      return [...d.on].some(id => !this.state.on.has(id));
    }

    load() {
      try {
        const s = JSON.parse(localStorage.getItem(this.key) || 'null');
        if (!s || typeof s !== 'object') return;
        if (this.bases.some(m => m.id === s.base)) this.state.base = s.base;
        if (Array.isArray(s.on)) this.state.on = new Set(s.on.filter(id => this.overlays.some(m => m.id === id)));
      } catch (e) { }
    }

    save() {
      try {
        if (this.differsFromDefault()) localStorage.setItem(this.key, JSON.stringify({ base: this.state.base, on: [...this.state.on] }));
        else localStorage.removeItem(this.key);
      } catch (e) { }
    }

    // 目前生效的圖層：底圖由單選決定，疊圖看勾選；只有一張的那類不可切換、固定保留
    resolve() {
      const tog = new Set(this.overlays.map(m => m.id));
      const out = this.all.filter(m => {
        if (isBase(m)) return this.bases.length ? m.id === this.state.base : true;
        return !tog.has(m.id) || this.state.on.has(m.id);
      });
      // 底圖一律墊在最下面，其餘維持原本由下往上的順序
      return out.filter(isBase).concat(out.filter(m => !isBase(m)));
    }

    apply() {
      if (this.engine) this.engine.setLayers(this.resolve());
    }

    injectStyle() {
      const style = document.createElement('style');
      style.textContent = `
        .lsw-panel { position: fixed; z-index: 1200; left: 60px; bottom: 14px; width: 300px; max-width: calc(100vw - 28px); max-height: min(70vh, 520px); overflow-y: auto; -webkit-overflow-scrolling: touch;
          background: var(--panel); color: var(--fg); border: 1px solid var(--line); border-radius: var(--r-md); box-shadow: var(--sh-2); padding: 12px;
          --fg: var(--fg-base); --muted: var(--muted-base); display: none }
        .lsw-panel.open { display: block }
        .lsw-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; font-weight: 700; font-size: .875rem }
        .lsw-sec { margin: 10px 0 6px; font-size: .6875rem; font-weight: 700; letter-spacing: .04em; color: var(--muted) }
        .lsw-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(124px, 1fr)); gap: 8px }
        .lsw-item { position: relative; display: flex; flex-direction: column; gap: 4px; cursor: pointer; font-size: .75rem; line-height: 1.3; min-width: 0 }
        .lsw-item input { position: absolute; opacity: 0; inset: 0; margin: 0; cursor: pointer }
        .lsw-thumb { display: flex; align-items: center; justify-content: center; width: 100%; aspect-ratio: 8 / 5; object-fit: cover; border: 2px solid var(--line); border-radius: var(--r-sm); background: var(--bg); color: var(--muted); box-sizing: border-box; transition: border-color var(--t) }
        .lsw-item:hover .lsw-thumb { border-color: var(--muted) }
        .lsw-item input:checked + .lsw-thumb { border-color: var(--accent) }
        .lsw-item input:focus-visible + .lsw-thumb { outline: 2px solid var(--accent); outline-offset: 2px }
        .lsw-name { display: flex; align-items: center; gap: 4px; overflow-wrap: anywhere }
        .lsw-name i { font-size: .6875rem; color: var(--muted) }
        .lsw-on, .lsw-item input:checked ~ .lsw-name .lsw-off { display: none }
        .lsw-item input:checked ~ .lsw-name .lsw-on { display: inline-block; color: var(--accent) }
        .lsw-reset { font-size: .75rem }
        @media (max-width: 560px) {
          .lsw-panel { left: 10px; right: 10px; bottom: 10px; width: auto; max-width: none; max-height: 62vh }
        }
      `;
      document.head.appendChild(style);
    }

    injectButton() {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'layerSwitchBtn';
      btn.className = 'icon-btn mapop-btn';
      btn.title = t('map_layers');
      btn.setAttribute('aria-label', t('map_layers'));
      btn.setAttribute('aria-haspopup', 'true');
      btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = '<i class="fa-solid fa-layer-group" aria-hidden="true"></i>';
      btn.onclick = (e) => { e.stopPropagation(); this.isOpen() ? this.close() : this.open(); };
      this.btn = btn;
      this.engine.mountOpButtons([{ el: btn }], 'bottom-left');
      document.addEventListener('click', (e) => {
        if (this.isOpen() && !this.panel.contains(e.target) && !btn.contains(e.target)) this.close();
      });
    }

    isOpen() { return !!(this.panel && this.panel.classList.contains('open')); }

    item(m, type) {
      const checked = type === 'radio' ? this.state.base === m.id : this.state.on.has(m.id);
      const thumb = m.preview
        ? `<img class="lsw-thumb" loading="lazy" alt="" src="${esc(m.preview)}">`
        : '<span class="lsw-thumb"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>';
      const off = type === 'radio' ? 'fa-circle' : 'fa-square';
      const on = type === 'radio' ? 'fa-circle-dot' : 'fa-square-check';
      return `<label class="lsw-item"><input type="${type}" name="lsw-${type}" value="${esc(m.id)}"${checked ? ' checked' : ''}>${thumb}`
        + `<span class="lsw-name"><i class="fa-regular ${off} lsw-off" aria-hidden="true"></i><i class="fa-solid ${on} lsw-on" aria-hidden="true"></i>${esc(m.label || m.id)}</span></label>`;
    }

    render() {
      const sec = (title, inner) => `<div class="lsw-sec">${esc(title)}</div><div class="lsw-grid">${inner}</div>`;
      this.panel.innerHTML = `<div class="lsw-head"><span>${esc(t('map_layers'))}</span>`
        + `<button type="button" class="btn lsw-reset" data-lsw-reset>${esc(t('map_layers_reset'))}</button></div>`
        + (this.bases.length ? sec(t('map_layers_base'), this.bases.map(m => this.item(m, 'radio')).join('')) : '')
        + (this.overlays.length ? sec(t('map_layers_overlay'), this.overlays.map(m => this.item(m, 'checkbox')).join('')) : '');
      this.panel.querySelectorAll('input[type=radio]').forEach(el => {
        el.onchange = () => { this.state.base = el.value; this.changed(); };
      });
      this.panel.querySelectorAll('input[type=checkbox]').forEach(el => {
        el.onchange = () => { el.checked ? this.state.on.add(el.value) : this.state.on.delete(el.value); this.changed(); };
      });
      this.panel.querySelector('[data-lsw-reset]').onclick = () => {
        this.state = this.defaults();
        this.changed();
        this.render();
      };
    }

    changed() { this.save(); this.apply(); }

    open() {
      if (!this.panel) {
        this.panel = document.createElement('div');
        this.panel.className = 'lsw-panel';
        this.panel.setAttribute('role', 'group');
        this.panel.setAttribute('aria-label', t('map_layers'));
        document.body.appendChild(this.panel);
      }
      this.render();
      this.panel.classList.add('open');
      this.btn.setAttribute('aria-expanded', 'true');
    }

    close() {
      if (!this.isOpen()) return;
      this.panel.classList.remove('open');
      this.btn.setAttribute('aria-expanded', 'false');
    }
  }

  new LayerSwitchPlugin().init(window.MapApp);
})();
