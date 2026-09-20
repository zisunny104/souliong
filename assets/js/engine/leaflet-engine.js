/* LeafletEngine —— MapEngine（assets/js/engine/map-engine.js）的 Leaflet 實作。
   MapLayer/RasterLayer/ImageLayer/LayerStack 是這個引擎的圖層系統：
   新增圖層型別＝多一個 MapLayer 子類，核心（跟這個引擎）都不用再動，見 docs/EXTENDING.md §8.4。 */
window.LeafletEngine = (() => {
  const LEAFLET_CREDIT = { text: 'Leaflet', url: 'https://leafletjs.com' };

  // Leaflet 用「pane」分層決定圖層疊放順序；MapLibre 沒有這個概念（見 maplibre-engine.js），
  // 所以這組常數只活在這個檔案裡，不搬進 MapEngine 抽象介面。
  const LAYER_PANES = { base: 200, paper: 220, road: 240, art: 260 };
  const paneKey = (m) => (m && LAYER_PANES[m.pane] != null) ? m.pane : 'art';

  class MapLayer {
    static from(m) { return (m && m.type === 'image') ? new ImageLayer(m) : new RasterLayer(m); }
    constructor(m) { this.m = m || {}; this.leaflet = null; }
    get themeSensitive() { return !!this.m.urlDark; }
    srcUrl(dark) { return (dark && this.m.urlDark) ? this.m.urlDark : this.m.url; }
    build(dark, opts) { throw new Error('MapLayer.build() is abstract'); }
    addTo(map, dark, opts) {
      const built = this.build(dark, opts || {});
      if (!built) return this;
      this.leaflet = built.addTo(map);
      return this;
    }
    remove(map) { if (this.leaflet) { map.removeLayer(this.leaflet); this.leaflet = null; } }
  }

  class RasterLayer extends MapLayer {
    build(dark, opts) {
      const m = this.m;
      const url = this.srcUrl(dark);
      if (!url) return null;
      const o = { pane: opts.pane, maxZoom: m.maxZoom != null ? m.maxZoom : 20 };
      if (m.subdomains) o.subdomains = m.subdomains;
      if (m.detectRetina) o.detectRetina = true;
      if (m.maxNativeZoom != null) o.maxNativeZoom = m.maxNativeZoom;
      if (m.minZoom != null) o.minZoom = m.minZoom;
      if (m.opacity != null) o.opacity = m.opacity;
      if (m.tms) o.tms = true;
      if (m.bounds) o.bounds = L.latLngBounds(m.bounds);
      if (m.className) o.className = m.className;
      if (opts.attribution) o.attribution = opts.attribution;
      return L.tileLayer(url, o);
    }
  }

  class ImageLayer extends MapLayer {
    build(dark, opts) {
      const m = this.m;
      const url = this.srcUrl(dark);
      if (!url || !m.bounds) return null;
      const o = { pane: opts.pane, interactive: false };
      if (m.opacity != null) o.opacity = m.opacity;
      if (m.className) o.className = m.className;
      if (opts.attribution) o.attribution = opts.attribution;
      return L.imageOverlay(url, L.latLngBounds(m.bounds), o);
    }
  }

  class LayerStack {
    constructor(manifests) {
      this.manifests = (manifests && manifests.length) ? manifests : [MapEngine.FALLBACK_LAYER];
      this.layers = this.manifests.map(m => MapLayer.from(m));
      this.credit = MapEngine.buildCredit(this.manifests, LEAFLET_CREDIT);
      this.map = null;
    }
    ensurePanes(map) {
      Object.keys(LAYER_PANES).forEach(k => {
        const name = 'sl-' + k;
        if (!map.getPane(name)) { map.createPane(name); map.getPane(name).style.zIndex = LAYER_PANES[k]; }
      });
    }
    _opts(i, layer) {
      return { pane: 'sl-' + paneKey(layer.m), attribution: i === 0 ? this.credit : undefined };
    }
    addTo(map, dark) {
      this.map = map;
      this.ensurePanes(map);
      this.layers.forEach((l, i) => l.addTo(map, dark, this._opts(i, l)));
      return this;
    }
    applyTheme(dark) {
      if (!this.map) return;
      this.layers.forEach((l, i) => {
        if (!l.themeSensitive) return;
        l.remove(this.map);
        l.addTo(this.map, dark, this._opts(i, l));
      });
    }
  }

  // 只取「pane 是 base」的第一筆——mini picker（mountBaseLayer）與 styleUrl() 只需要一張
  // 單純的底圖，不需要疊圖用的插畫圖層，也不該被它們擋住地標。跟 MapLibreEngine 選整顆地圖
  // style 用的「同 pane 取最後一筆、後面蓋前面」規則是兩回事，不要搞混。
  function baseManifests(manifests) {
    const base = manifests.filter(m => paneKey(m) === 'base');
    return (base.length ? base : manifests).slice(0, 1);
  }

  class LeafletEngine extends MapEngine {
    constructor(opts) {
      super(opts);
      const o = opts || {};
      this.manifests = (o.manifests && o.manifests.length) ? o.manifests : [MapEngine.FALLBACK_LAYER];
      this.map = L.map(o.container, { zoomControl: false }).setView(o.center || [23.9, 120.7], o.zoom || 14);
      this.baseStack = new LayerStack(this.manifests).addTo(this.map, !!o.dark);
      this.markerLayers = {};        // layerKey -> L.LayerGroup
      this._zoomThresholds = [];     // [{zoom, wasAbove, fn}]
      this.map.on('zoomend', () => this._checkZoomThresholds());
    }

    get type() { return 'leaflet'; }
    getRawMap() { return this.map; }

    getCenter() { const c = this.map.getCenter(); return { lat: c.lat, lon: c.lng }; }
    getZoom() { return this.map.getZoom(); }
    setView(center, zoom) { this.map.setView(center, zoom); }
    panTo(lat, lon, opts) { this.map.panTo([lat, lon], opts || {}); }
    fitBounds(latlonPairs, opts) {
      if (!latlonPairs || !latlonPairs.length) return;
      const b = L.latLngBounds(latlonPairs);
      this.map.fitBounds((opts && opts.pad != null) ? b.pad(opts.pad) : b);
    }
    destroy() { this.map.remove(); }

    mountControls(opts) {
      const o = opts || {};
      const zoomPos = o.zoomPosition || 'bottomleft';
      L.control.zoom({ position: zoomPos }).addTo(this.map);
      this.map.attributionControl.setPosition(o.attributionPosition || 'bottomright');
      this.map.attributionControl.setPrefix(false);
      this.mountOpButtons(o.opButtons, zoomPos);
    }
    // 把一個現成的 DOM 元素（不是重新刻一顆）包成 Leaflet 認得的 control，讓它掛進跟縮放鈕同一個
    // 角落的堆疊——角落容器自己會排版，不用算高度數字去對齊（手算高度會讓 resetBtn 蓋住縮放鈕）。
    _addCornerControl(el, position) {
      const ElControl = L.Control.extend({ onAdd: () => el });
      new ElControl({ position }).addTo(this.map);
    }
    onBackgroundClick(fn) { this.map.on('click', fn); }

    _layerGroup(layerKey) {
      if (!this.markerLayers[layerKey]) this.markerLayers[layerKey] = L.layerGroup().addTo(this.map);
      return this.markerLayers[layerKey];
    }
    setMarkerLayer(layerKey, specs) {
      const g = this._layerGroup(layerKey);
      g.clearLayers();
      (specs || []).forEach(spec => {
        const icon = L.divIcon({ className: '', iconSize: spec.size, iconAnchor: spec.anchor, html: spec.html });
        const mk = L.marker([spec.lat, spec.lon], { icon });
        if (spec.onClick) mk.on('click', spec.onClick);
        g.addLayer(mk);
      });
    }
    clearMarkerLayer(layerKey) { const g = this.markerLayers[layerKey]; if (g) g.clearLayers(); }
    onZoomThresholdCross(zoom, fn) {
      this._zoomThresholds.push({ zoom, wasAbove: this.map.getZoom() >= zoom, fn });
    }
    _checkZoomThresholds() {
      const z = this.map.getZoom();
      this._zoomThresholds.forEach(entry => {
        const above = z >= entry.zoom;
        if (above !== entry.wasAbove) { entry.wasAbove = above; entry.fn(); }
      });
    }

    drawPolyline(pts, style) { return L.polyline(pts, style || {}).addTo(this.map); }
    updatePolylinePoints(handle, pts) { handle.setLatLngs(pts); }
    removePolyline(handle) { if (handle) this.map.removeLayer(handle); }
    drawPoint(lat, lon, style) { return L.circleMarker([lat, lon], style || {}).addTo(this.map); }
    updatePointPosition(handle, lat, lon) { handle.setLatLng([lat, lon]); }
    removePoint(handle) { if (handle) this.map.removeLayer(handle); }

    applyTheme(dark) { this.baseStack.applyTheme(dark); }
    styleUrl() {
      const base = baseManifests(this.manifests)[0];
      return (base && base.url) || '';
    }

    // createMiniPicker() 內部用：把這份底圖疊到小地圖自己的 Leaflet 實體上，不對外公開。
    mountBaseLayer(map, dark) { return new LayerStack(baseManifests(this.manifests)).addTo(map, dark); }

    createMiniPicker(container, opts) {
      const o = opts || {};
      const mini = L.map(container, { attributionControl: false, zoomControl: false, dragging: true })
        .setView([o.lat, o.lon], o.zoom || 16);
      this.mountBaseLayer(mini, !!o.dark);
      const mk = L.marker([o.lat, o.lon], { draggable: true }).addTo(mini);
      const listeners = [];
      const emit = (lat, lon) => listeners.forEach(fn => fn({ lat, lon }));
      mk.on('dragend', ev => { const ll = ev.target.getLatLng(); emit(ll.lat, ll.lng); });
      mini.on('click', ev => { mk.setLatLng(ev.latlng); emit(ev.latlng.lat, ev.latlng.lng); });
      // 小地圖大多裝在剛展開、還在跑進場動畫的面板裡：容器當下的量測尺寸不可靠，
      // 所以先 rAF、再分段 timeout 重算一次尺寸。
      const fix = () => { try { mini.invalidateSize(false); } catch (e) {} };
      requestAnimationFrame(fix);
      const timers = [150, 500, 1200].map(ms => setTimeout(fix, ms));
      let ro = null;
      if (window.ResizeObserver) { ro = new ResizeObserver(fix); ro.observe(container); }
      return {
        getPosition: () => { const ll = mk.getLatLng(); return { lat: ll.lat, lon: ll.lng }; },
        setPosition: (pos, opts2) => {
          mk.setLatLng([pos.lat, pos.lon]);
          if (opts2 && opts2.pan) mini.panTo([pos.lat, pos.lon]);
        },
        onChange: (fn) => { listeners.push(fn); },
        setDraggable: (v) => { if (v) mk.dragging.enable(); else mk.dragging.disable(); },
        resize: fix,
        destroy: () => {
          timers.forEach(clearTimeout);
          if (ro) ro.disconnect();
          mini.remove();
        },
      };
    }
  }

  return LeafletEngine;
})();
