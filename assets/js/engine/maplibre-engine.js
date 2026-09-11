/* MapLibreEngine —— MapEngine（assets/js/engine/map-engine.js）的 MapLibre GL 實作。
   一律用獨立的 new maplibregl.Map(...)，絕不透過 @maplibre/maplibre-gl-leaflet 橋接套件
   （v6.6.0 上會跟 MapLibre 內部非同步投影初始化搶時間、丟一串 TypeError，見規劃文件）。

   MapLibre 沒有 Leaflet 的「pane」分層概念，向量 style 本身就是一張完整的圖（道路／建物／
   標籤都內建在同一份 style 裡），沒辦法像光柵圖層一樣疊好幾張互相獨立的底圖。這裡的處理方式：
   manifests 陣列裡「pane 是 base 且 type 是 vector」的最後一筆整個當作地圖的 style；其餘每一筆
   （不論哪個 pane）在 style 載入完成後依序疊成 source+layer，疊在最上層、依陣列順序疊放。 */
window.MapLibreEngine = (() => {
  const MAPLIBRE_CREDIT = { text: 'MapLibre', url: 'https://maplibre.org' };

  const paneKey = (m) => (m && m.pane) || 'art';

  // 同一條規則跟 LeafletEngine 的 baseManifests() 精神一致（同 pane 後面蓋前面，取最後一筆）；
  // 差別是這裡限定 type 也要是 vector，因為向量 style 才能整顆當地圖的 style。
  function baseVectorManifest(manifests) {
    const cand = manifests.filter(m => paneKey(m) === 'base' && m.type === 'vector');
    return cand.length ? cand[cand.length - 1] : null;
  }

  // Leaflet 圖磚網址樣板裡的 {s}/{r} 這裡的引擎不吃：{r} 直接拿掉（不處理視網膜倍率圖），
  // {s} 展開成每個子網域各一個 URL——MapLibre 的 raster source 本來就支援多個 tiles URL
  // 做平行連線輪詢，跟 Leaflet subdomains 選項的用意相同，不是遺漏。
  function rasterTileUrls(m, dark) {
    const raw = (dark && m.urlDark) ? m.urlDark : m.url;
    if (!raw) return [];
    const noRetina = raw.replace('{r}', '');
    if (m.subdomains && noRetina.indexOf('{s}') >= 0) {
      return m.subdomains.split('').map(s => noRetina.replace('{s}', s));
    }
    return [noRetina];
  }

  function syntheticRasterStyle(m, dark) {
    const tiles = rasterTileUrls(m, dark);
    return {
      version: 8,
      sources: { 'sl-base': { type: 'raster', tiles, tileSize: 256 } },
      layers: [{ id: 'sl-base', type: 'raster', source: 'sl-base' }],
    };
  }

  function cornersFromBounds(bounds) {
    // Leaflet 的 bounds 是 [[南,西],[北,東]]；MapLibre image source 的 coordinates
    // 是四角順時針從左上開始：[[西北],[東北],[東南],[西南]]
    const [[s, w], [n, e]] = bounds;
    return [[w, n], [e, n], [e, s], [w, s]];
  }

  const POS_MAP = { bottomleft: 'bottom-left', bottomright: 'bottom-right', topleft: 'top-left', topright: 'top-right' };
  const mapPos = (p, fallback) => POS_MAP[p] || p || fallback;

  // MapLibre 原生的 AttributionControl 除了吃 customAttribution，還會自己去掃目前載入的
  // style 裡每個 source 自帶的 attribution（向量 style JSON 內建的那份），兩邊用「|」接在一起
  // 顯示、無法去重、也套不到我們自己的版權排版（cr-ext/cr-own/cr-sep，見 map-engine.js
  // buildCredit()）——結果就是「我們的」版權跟「它原生秀出來的」擠在一起、風格對不上。
  // 這裡完全不用原生控制項，自己刻一個只顯示 buildCredit() 產出內容的最小控制項：畫面上
  // 看到的版權永遠是我們自己排版、自己翻譯過的那一份，向量 style 內建的來源標註改成透過
  // buildCredit() 帶入的 manifest attribution 手動列出（見 layers/openfreemap-liberty/layer.json），
  // 不假手 MapLibre 自己掃出來的原始樣式。
  class CreditControl {
    constructor(html) { this._html = html; }
    onAdd() {
      this._container = document.createElement('div');
      this._container.className = 'maplibregl-ctrl cr-bar';
      this._container.innerHTML = this._html;
      return this._container;
    }
    onRemove() {
      if (this._container && this._container.parentNode) this._container.parentNode.removeChild(this._container);
      this._container = null;
    }
  }

  // 把一個現成的 DOM 元素（不是重新刻一顆，例如 pages/view.php 的 #resetBtn）包成 MapLibre
  // 認得的 control，讓它掛進跟縮放鈕（含羅盤，見下面 mountControls()）同一個角落的堆疊，用
  // MapLibre 自己的排版機制自動疊好，不用寫死高度數字去對齊——縮放鈕比 Leaflet 多一顆羅盤鈕、
  // 高度不同，寫死數字對不齊就是這樣來的。不限於重置鈕，任何要掛角落的既有元素都能用這個包。
  class DomControl {
    constructor(el) { this._el = el; }
    onAdd() { this._el.classList.add('maplibregl-ctrl'); return this._el; }
    onRemove() { this._el.classList.remove('maplibregl-ctrl'); }
  }

  // MapLibre CustomLayerInterface（renderingMode:'3d'）＋ three.js 畫單一自訂模型，原本是
  // map3d.js 自己的類別，3D 能力整併進引擎時一起搬過來（見 docs 規劃 Part D）。座標換算沿用官方
  // 文件那套 MercatorCoordinate 作法：模型原點換成麥卡托座標＋公尺→麥卡托單位的縮放係數，
  // render() 收到的 modelViewProjectionMatrix 只描述「地圖現在怎麼看整個世界」，疊上這個平移＋
  // 縮放矩陣才會變成「模型自己這個局部座標系怎麼被畫出來」。
  class Map3DModelLayer {
    constructor(id, region, THREE, GLTFLoaderCtor) {
      this.id = id;
      this.type = 'custom';
      this.renderingMode = '3d';
      this.region = region;
      this.THREE = THREE;
      this.GLTFLoaderCtor = GLTFLoaderCtor;
    }

    onAdd(map, gl) {
      const THREE = this.THREE;
      const m = this.region.model;
      this.map = map;
      this.camera = new THREE.Camera();
      this.scene = new THREE.Scene();
      this.scene.add(new THREE.AmbientLight(0xffffff, 1.2));
      const sun = new THREE.DirectionalLight(0xffffff, 0.8);
      sun.position.set(0, -70, 100);
      this.scene.add(sun);

      this.origin = maplibregl.MercatorCoordinate.fromLngLat(
        { lng: m.anchor[1], lat: m.anchor[0] },
        Number(m.altitudeOffset) || 0
      );
      this.mercatorScale = this.origin.meterInMercatorCoordinateUnits();

      new this.GLTFLoaderCtor().load(
        this.region.modelUrl,
        (gltf) => {
          const s = (Number(m.scale) || 1) * this.mercatorScale;
          gltf.scene.scale.set(s, s, s);
          // glTF 是 Y-up，麥卡托世界是「地面 XY、高度 Z」，先繞 X 轉正，水平朝向（管理員填的角度）
          // 才能單純疊在轉正後的 Z 軸上，不會跟這個座標系轉正操作互相纏在一起
          gltf.scene.rotation.x = Math.PI / 2;
          gltf.scene.rotation.z = -(Number(m.rotationDeg) || 0) * Math.PI / 180;
          this.scene.add(gltf.scene);
          map.triggerRepaint();
        },
        undefined,
        (err) => console.error('[maplibre-engine] glTF 載入失敗', this.region.id, err)
      );

      this.renderer = new THREE.WebGLRenderer({ canvas: map.getCanvas(), context: gl, antialias: true });
      this.renderer.autoClear = false;
    }

    render({ gl, modelViewProjectionMatrix }) {
      const THREE = this.THREE;
      const l = new THREE.Matrix4()
        .makeTranslation(this.origin.x, this.origin.y, this.origin.z)
        .scale(new THREE.Vector3(this.mercatorScale, -this.mercatorScale, this.mercatorScale));
      this.camera.projectionMatrix = new THREE.Matrix4().fromArray(modelViewProjectionMatrix).multiply(l);
      this.renderer.resetState();
      this.renderer.render(this.scene, this.camera);
      this.map.triggerRepaint();
    }
  }

  class MapLibreEngine extends MapEngine {
    constructor(opts) {
      super(opts);
      const o = opts || {};
      this.manifests = (o.manifests && o.manifests.length) ? o.manifests : [MapEngine.FALLBACK_LAYER];
      this._baseManifest = baseVectorManifest(this.manifests);
      this.credit = MapEngine.buildCredit(this.manifests, MAPLIBRE_CREDIT);
      this._dark = !!o.dark;
      this._overlayIds = [];
      this._markerLayers = {};
      this._markerSpecs = {};
      this._zoomThresholds = [];
      this._idSeq = 0;
      // 3D 能力狀態（見 enter3D()/exit3D()）：_3dCfg 是進入 3D 時收到的 {excludedBuildingIds,regions}，
      // _modelLayerIds/_threeLoading/THREE/GLTFLoader 是自訂模型的延遲載入狀態，跟 map3d.js
      // 舊版一致——這顆引擎不論是被當主引擎重用還是另開一顆給 3D 用，都走同一套。
      this._navControlAdded = false;
      this._3dCfg = null;
      this._modelLayerIds = [];
      this._threeLoading = false;
      this.THREE = null;
      this.GLTFLoader = null;

      this.map = new maplibregl.Map({
        container: o.container,
        style: this._styleFor(this._dark),
        center: [o.center ? o.center[1] : 120.7, o.center ? o.center[0] : 23.9],
        zoom: o.zoom || 14,
        attributionControl: false,
        preserveDrawingBuffer: true,   // 讓 getCanvasDataURL() 讀得到畫面（見下方快照擷圖）；WebGL 預設畫完就可能清緩衝區
      });
      this.map.on('load', () => this._mountOverlays());
      this.map.on('zoomend', () => this._checkZoomThresholds());
    }

    get type() { return 'maplibre'; }
    get supports3D() { return true; }
    getRawMap() { return this.map; }

    get supportsSnapshot() { return true; }
    getCanvasDataURL(mime, quality) {
      try {
        const src = this.map.getCanvas();
        const chairs = (this._markerSpecs && this._markerSpecs.chairs) || [];
        if (!chairs.length) return src.toDataURL(mime || 'image/png', quality);
        // 疊繪簡化圓點：chairs 標記是 DOM 覆蓋層，不在 WebGL canvas 的繪圖緩衝區裡，
        // 直接 toDataURL() 擷不到，改成另開一張同尺寸的 2D canvas，先貼底圖再手動畫點。
        const out = document.createElement('canvas');
        out.width = src.width;
        out.height = src.height;
        const ctx = out.getContext('2d');
        ctx.drawImage(src, 0, 0);
        // map.project() 回傳的是 CSS 像素座標，跟 canvas 實際繪圖緩衝區（依裝置畫素比可能
        // 更大）不是同一個座標系，這裡實測容器的 CSS 尺寸換算縮放比，不假設 devicePixelRatio。
        const rect = this.map.getContainer().getBoundingClientRect();
        const sx = rect.width ? src.width / rect.width : 1;
        const sy = rect.height ? src.height / rect.height : 1;
        const r = 5 * Math.min(sx, sy);
        ctx.lineWidth = Math.max(1, 1.5 * Math.min(sx, sy));
        ctx.strokeStyle = '#fff';
        chairs.forEach(spec => {
          const pt = this.map.project([spec.lon, spec.lat]);
          ctx.beginPath();
          ctx.arc(pt.x * sx, pt.y * sy, r, 0, Math.PI * 2);
          ctx.fillStyle = spec.color || '#888';
          ctx.fill();
          ctx.stroke();
        });
        return out.toDataURL(mime || 'image/png', quality);
      } catch (e) {
        return null;   // 跨網域圖磚沒開 CORS 導致 canvas 被污染時 toDataURL() 會丟例外
      }
    }

    _styleFor(dark) {
      return this._baseManifest ? ((dark && this._baseManifest.urlDark) || this._baseManifest.url)
        : syntheticRasterStyle(this.manifests[0], dark);
    }

    _overlayManifests() {
      return this.manifests.filter(m => m !== this._baseManifest);
    }

    _mountOverlays() {
      this._overlayIds = [];
      this._overlayManifests().forEach((m, i) => {
        const id = 'sl-ov-' + i;
        if (m.type === 'image') {
          if (!m.bounds) return;
          const url = (this._dark && m.urlDark) || m.url;
          if (!url) return;
          this.map.addSource(id, { type: 'image', url, coordinates: cornersFromBounds(m.bounds) });
          this.map.addLayer({ id, type: 'raster', source: id, paint: m.opacity != null ? { 'raster-opacity': m.opacity } : {} });
        } else {
          const tiles = rasterTileUrls(m, this._dark);
          if (!tiles.length) return;
          this.map.addSource(id, { type: 'raster', tiles, tileSize: 256 });
          this.map.addLayer({ id, type: 'raster', source: id, paint: m.opacity != null ? { 'raster-opacity': m.opacity } : {} });
        }
        this._overlayIds.push(id);
      });
      // marker 圖層要重新疊在最上層——setStyle()/重建 style 之後 source/layer 全部清空，
      // 但 maplibregl.Marker 是獨立於 style 的 DOM 覆蓋層，不受影響，這裡不用重畫。
    }

    getCenter() { const c = this.map.getCenter(); return { lat: c.lat, lon: c.lng }; }
    getZoom() { return this.map.getZoom(); }
    setView(center, zoom) { this.map.jumpTo({ center: [center[1], center[0]], zoom }); }
    panTo(lat, lon, opts) {
      const o = opts || {};
      this.map.easeTo({ center: [lon, lat], duration: o.animate === false ? 0 : (o.duration != null ? o.duration * 1000 : 250) });
    }
    fitBounds(latlonPairs, opts) {
      if (!latlonPairs || !latlonPairs.length) return;
      const lons = latlonPairs.map(p => p[1]), lats = latlonPairs.map(p => p[0]);
      let w = Math.min(...lons), e = Math.max(...lons), s = Math.min(...lats), n = Math.max(...lats);
      // 比照 Leaflet 的 L.latLngBounds.pad()：依 bounds 自身寬高的比例往外擴，而不是
      // MapLibre fitBounds() 原生的像素 padding——兩者語意不同，這裡刻意換算成前者的行為。
      const pad = (opts && opts.pad != null) ? opts.pad : 0;
      if (pad) { const dw = (e - w) * pad, dh = (n - s) * pad; w -= dw; e += dw; s -= dh; n += dh; }
      this.map.fitBounds([[w, s], [e, n]], { padding: 0, linear: true });
    }
    destroy() { this.map.remove(); }

    mountControls(opts) {
      const o = opts || {};
      const zoomPos = mapPos(o.zoomPosition, 'bottom-left');
      this.map.addControl(new maplibregl.NavigationControl(), zoomPos);
      this.mountOpButtons(o.opButtons, zoomPos);
      this.map.addControl(new CreditControl(this.credit), mapPos(o.attributionPosition, 'bottom-right'));
    }
    _addCornerControl(el, position) {
      this.map.addControl(new DomControl(el), position);
    }
    onBackgroundClick(fn) { this.map.on('click', fn); }

    _layerArr(layerKey) { return this._markerLayers[layerKey] || (this._markerLayers[layerKey] = []); }
    setMarkerLayer(layerKey, specs) {
      this.clearMarkerLayer(layerKey);
      this._markerSpecs[layerKey] = specs || [];
      const arr = this._layerArr(layerKey);
      (specs || []).forEach(spec => {
        const el = document.createElement('div');
        el.style.width = spec.size[0] + 'px';
        el.style.height = spec.size[1] + 'px';
        el.innerHTML = spec.html;
        // maplibregl.Marker 的 DOM 元素是 map 容器的子節點，click 事件預設會冒泡到
        // onBackgroundClick 的 map.on('click', ...)；Leaflet marker 不會，這個差異在這裡吸收掉。
        if (spec.onClick) el.addEventListener('click', (e) => { e.stopPropagation(); spec.onClick(); });
        const marker = new maplibregl.Marker({ element: el, anchor: 'top-left', offset: [-spec.anchor[0], -spec.anchor[1]] })
          .setLngLat([spec.lon, spec.lat]).addTo(this.map);
        arr.push(marker);
      });
    }
    clearMarkerLayer(layerKey) {
      const arr = this._markerLayers[layerKey];
      if (arr) arr.forEach(mk => mk.remove());
      this._markerLayers[layerKey] = [];
      this._markerSpecs[layerKey] = [];
    }
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

    _nextId() { return 'sl-ln-' + (++this._idSeq); }
    _lineData(pts) {
      return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: (pts || []).map(p => [p[1], p[0]]) } };
    }
    drawPolyline(pts, style) {
      const s = style || {};
      const id = this._nextId();
      this.map.addSource(id, { type: 'geojson', data: this._lineData(pts) });
      const paint = {
        'line-color': s.color || '#3388ff',
        'line-width': s.weight != null ? s.weight : 3,
        'line-opacity': s.opacity != null ? s.opacity : 1,
      };
      if (s.dashArray) paint['line-dasharray'] = String(s.dashArray).split(/\s+/).map(Number);
      this.map.addLayer({ id, type: 'line', source: id, layout: { 'line-cap': 'round', 'line-join': 'round' }, paint });
      return { id };
    }
    updatePolylinePoints(handle, pts) {
      if (!handle) return;
      const src = this.map.getSource(handle.id);
      if (src) src.setData(this._lineData(pts));
    }
    removePolyline(handle) {
      if (!handle) return;
      if (this.map.getLayer(handle.id)) this.map.removeLayer(handle.id);
      if (this.map.getSource(handle.id)) this.map.removeSource(handle.id);
    }
    drawPoint(lat, lon, style) {
      const s = style || {};
      const d = (s.radius != null ? s.radius : 6) * 2;
      const el = document.createElement('div');
      el.style.cssText = 'width:' + d + 'px;height:' + d + 'px;border-radius:50%;box-sizing:border-box;' +
        'background:' + (s.fillColor || s.color || '#3388ff') + ';opacity:' + (s.fillOpacity != null ? s.fillOpacity : 1) +
        ';border:' + (s.weight || 0) + 'px solid ' + (s.color || '#fff') + ';';
      return new maplibregl.Marker({ element: el }).setLngLat([lon, lat]).addTo(this.map);
    }
    updatePointPosition(handle, lat, lon) { if (handle) handle.setLngLat([lon, lat]); }
    removePoint(handle) { if (handle) handle.remove(); }

    applyTheme(dark) {
      if (!this._baseManifest || !this._baseManifest.urlDark) return;
      this._dark = !!dark;
      this.map.once('style.load', () => this._mountOverlays());
      this.map.setStyle(this._styleFor(this._dark));
    }
    styleUrl() { return (this._baseManifest && this._baseManifest.url) || ''; }
    get hasDarkStyle() { return !!(this._baseManifest && this._baseManifest.urlDark); }

    createMiniPicker(container, opts) {
      const o = opts || {};
      const mini = new maplibregl.Map({
        container, style: this._styleFor(!!o.dark), center: [o.lon, o.lat], zoom: o.zoom || 16,
        attributionControl: false, interactive: true,
      });
      const el = document.createElement('div');
      el.className = 'sl-ml-pin';
      el.style.cssText = 'width:24px;height:34px;transform:translate(-12px,-32px);cursor:grab;' +
        'background:no-repeat center/contain url(\'data:image/svg+xml;utf8,' +
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 34"><path fill="%23ff5252" ' +
        'd="M12 0C5.4 0 0 5.4 0 12c0 9 12 22 12 22s12-13 12-22C24 5.4 18.6 0 12 0z"/>' +
        '<circle fill="%23fff" cx="12" cy="12" r="5"/></svg>\')';
      const mk = new maplibregl.Marker({ element: el, draggable: true }).setLngLat([o.lon, o.lat]).addTo(mini);
      const listeners = [];
      const emit = (lat, lon) => listeners.forEach(fn => fn({ lat, lon }));
      mk.on('dragend', () => { const p = mk.getLngLat(); emit(p.lat, p.lng); });
      mini.on('click', ev => { mk.setLngLat(ev.lngLat); emit(ev.lngLat.lat, ev.lngLat.lng); });
      const fix = () => { try { mini.resize(); } catch (e) {} };
      requestAnimationFrame(fix);
      const timers = [150, 500, 1200].map(ms => setTimeout(fix, ms));
      let ro = null;
      if (window.ResizeObserver) { ro = new ResizeObserver(fix); ro.observe(container); }
      let draggable = true;
      return {
        getPosition: () => { const p = mk.getLngLat(); return { lat: p.lat, lon: p.lng }; },
        setPosition: (pos, opts2) => {
          mk.setLngLat([pos.lon, pos.lat]);
          if (opts2 && opts2.pan) mini.panTo([pos.lon, pos.lat]);
        },
        onChange: (fn) => { listeners.push(fn); },
        setDraggable: (v) => { draggable = !!v; mk.setDraggable(draggable); el.style.cursor = draggable ? 'grab' : 'default'; },
        resize: fix,
        destroy: () => {
          timers.forEach(clearTimeout);
          if (ro) ro.disconnect();
          mini.remove();
        },
      };
    }

    // ---- 3D（見 assets/js/plugins/map3d.js：這顆引擎不論是被當主引擎原地重用、還是另開
    // 一顆專給 3D 用，都走同一套 enter3D()/exit3D()，呼叫端不用區分）----
    enter3D(cfg) {
      this._3dCfg = cfg || {};
      this.map.easeTo({ pitch: 55, duration: 300 });
      if (!this._navControlAdded) {
        this.map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'bottom-right');
        this._navControlAdded = true;
      }
      const run = () => {
        this._applyBuildingExclusion(this._3dCfg.excludedBuildingIds);
        this._maybeLoadThree();
      };
      if (this.map.loaded()) run(); else this.map.once('load', run);
    }
    exit3D() {
      this.map.easeTo({ pitch: 0, duration: 300 });
    }

    // 排除機制見 api/regions3d.php 開頭的說明：清單是管理員存檔當下算好的靜態 id，這裡只是
    // 原樣套成 filter，不做任何即時查詢或重算——圖層建立當下就生效，訪客怎麼平移都一樣。
    _applyBuildingExclusion(excludedIds) {
      if (!excludedIds || !excludedIds.length) return;
      const style = this.map.getStyle();
      const layer = style && style.layers && style.layers.find(
        (l) => l.type === 'fill-extrusion' && (l['source-layer'] === 'building' || /building/i.test(l.id))
      );
      if (!layer) { console.warn('[maplibre-engine] 找不到公用建物 fill-extrusion 圖層，排除清單未套用'); return; }
      const exclude = ['!', ['in', ['id'], ['literal', excludedIds]]];
      this.map.setFilter(layer.id, layer.filter ? ['all', layer.filter, exclude] : exclude);
    }

    // three.js（~600KB）只有在這張地圖真的存了至少一個自訂模型時才載入，跟 kind-*.js
    // 「沒用到就零痕跡」同一個精神。import map 由 view.php 靜態輸出、不含任何下載成本，
    // 真正的檔案要等這裡的 import() 執行才會抓。
    async _maybeLoadThree() {
      const regions = this._3dCfg && this._3dCfg.regions;
      if (this._threeLoading || this.THREE || !regions || !regions.length) return;
      this._threeLoading = true;
      try {
        const [THREE, addon] = await Promise.all([
          import('three'),
          import('three/addons/loaders/GLTFLoader.js'),
        ]);
        this.THREE = THREE;
        this.GLTFLoader = addon.GLTFLoader;
        if (this.map.loaded()) this._renderCustomModels();
        else this.map.once('load', () => this._renderCustomModels());
      } catch (e) {
        console.error('[maplibre-engine] three.js 載入失敗', e);
      }
    }

    _renderCustomModels() {
      const regions = this._3dCfg && this._3dCfg.regions;
      if (!this.THREE || !regions) return;
      regions.forEach((r) => {
        if (!r.model || !r.model.anchor || !r.modelUrl || this._modelLayerIds.includes(r.id)) return;
        this.map.addLayer(new Map3DModelLayer('m3d-model-' + r.id, r, this.THREE, this.GLTFLoader));
        this._modelLayerIds.push(r.id);
      });
    }
  }

  return MapLibreEngine;
})();
