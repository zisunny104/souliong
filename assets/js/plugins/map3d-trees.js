/* 選用插件：3D 樹木（OSM natural=tree 單株與 natural=tree_row 行道樹）
   資料是 projects/<p>/trees.geojson，網址由 APP.map3d.treesUrl 提供，沒有這個網址就整個不動作。
   進入 3D 時載入，用 three.js 的 InstancedMesh 畫低面數（low-poly）平面著色的樹：樹幹是 6 邊錐柱，
   闊葉冠是二十面體（落葉的用最粗的一級、常綠的細分一級較圓），針葉冠是三層疊錐；每株有隨機轉向、
   各軸微幅縮放與明度色差，避免整齊呆板。退出時移除。渲染底座（自訂圖層、光線、座標）在 MapLibreEngine。

   行道樹沿線每 TREE_SPACING_M 一株；隔株分成「疏」「密」兩組，縮放到 DENSE_ZOOM 以上才畫密的那組，
   低於 MIN_ZOOM 整層不畫。實例總數超過 MAX_TREES 時按編號雜湊固定抽樣，每次進 3D 抽到的樹相同。 */
(() => {
  const LAYER_ID = 'm3d-trees';
  const MIN_ZOOM = 15.5;        // 低於這個縮放不畫樹（遠看只是一團雜點，還白花繪製成本）
  const DENSE_ZOOM = 17;        // 到這個縮放才補畫行道樹的另一半
  const TREE_SPACING_M = 7;     // 行道樹密的間距；疏的一組是每隔一株
  const MAX_TREES = 50000;

  // 沒標尺寸時的預設，依真實樹木比例（[最小, 最大]，在範圍內按編號固定取隨機值）：
  //   闊葉樹高 8–15 公尺、冠幅為樹高的 0.6–0.9 倍；針葉樹高 10–20 公尺、冠幅為樹高的 0.3–0.5 倍；
  //   行道樹（tree_row）較矮，樹高 6–10 公尺；標了 height 但不到 SHRUB_MAX_M 的視為灌木，冠幅約等於樹高。
  const BROAD = { height: [8, 15], crownRatio: [0.6, 0.9], clear: 0.3 };
  const NEEDLE = { height: [10, 20], crownRatio: [0.3, 0.5], clear: 0.12 };
  const ROW_HEIGHT = [6, 10];
  const SHRUB_MAX_M = 3;
  const SHRUB_CLEAR = 0.1;
  // 樹幹直徑沒標時取樹高的 9%（8–10% 的中間），下限 0.4 公尺；上限不超過冠幅的 40%
  const TRUNK_RATIO = 0.09;
  const TRUNK_MIN_M = 0.4;
  const TRUNK_MAX_CROWN = 0.4;
  const TRUNK_FLARE = [1.15, 0.8];   // 樹幹底部與頂部半徑相對於標稱半徑的倍數（底粗上收）

  const COLORS = {
    light: { decid: '#86b352', ever: '#4f8a45', needle: '#3d7a52', trunk: '#7a5a3c' },
    // 夜間色階約為白天的 0.15–0.2 倍（受環境光放大後與旁邊建物同等偏暗）並偏藍綠：樹只受微弱環境光與月光，不可比旁邊的建物亮
    dark: { decid: '#0c1712', ever: '#09140f', needle: '#08131a', trunk: '#120e0b' },
  };

  const range = (r, u) => r[0] + (r[1] - r[0]) * u;
  const num = (v) => {
    const n = parseFloat(v);
    return Number.isFinite(n) && n > 0 ? n : null;
  };
  // 以編號湊出穩定的 0..1 亂數，讓樹高與顏色的變化每次進 3D 都一樣
  const hash01 = (n) => {
    const x = Math.sin(n * 127.1 + 311.7) * 43758.5453;
    return x - Math.floor(x);
  };

  class Map3DTreesPlugin extends MapApp.Plugin {
    constructor() {
      super('map3d-trees');
      this.dataPromise = null;
      this.threePromise = null;
      this.built = null;   // {THREE, origin:[lat,lon], sparse:[], dense:[], geo}
    }

    mount() {
      if (typeof MapLibreEngine === 'undefined' || !MapLibreEngine.register3DExtension) return;
      MapLibreEngine.register3DExtension({
        apply: (engine, cfg) => this.apply(engine, cfg),
        clear: (engine) => this.clear(engine),
      });
    }

    loadData(url) {
      if (!this.dataPromise) {
        this.dataPromise = fetch(url, { credentials: 'same-origin' })
          .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
          .then((j) => (j && Array.isArray(j.features) ? j.features : []))
          .catch((e) => { console.error('[map3d-trees] 樹木資料載入失敗', e); this.dataPromise = null; return null; });
      }
      return this.dataPromise;
    }
    loadThree() {
      if (!this.threePromise) {
        this.threePromise = import('three').catch((e) => { console.error('[map3d-trees] three.js 載入失敗', e); this.threePromise = null; return null; });
      }
      return this.threePromise;
    }

    apply(engine, cfg) {
      if (!cfg.treesUrl) return;
      Promise.all([this.loadData(cfg.treesUrl), this.loadThree()]).then(([features, THREE]) => {
        const map = engine.getRawMap();
        // 樣式重載中（getStyle 為空）或已退出 3D 就放棄；樣式載完後引擎會再呼叫一次 apply
        if (!features || !features.length || !THREE || !engine.in3D || !map.getStyle()) return;
        // 切深淺色重載樣式後圖層可能還在，但場景是舊色系建的，要重建
        if (map.getLayer(LAYER_ID)) {
          if (this.layerDark === engine.dark) return;
          map.removeLayer(LAYER_ID);
        }
        if (!this.built) this.built = this.collect(THREE, features);
        if (!this.built.sparse.length && !this.built.dense.length) return;
        this.layerDark = engine.dark;
        map.addLayer(this.makeLayer(engine.dark));
      });
    }

    clear(engine) {
      const map = engine.getRawMap();
      if (map.getLayer(LAYER_ID)) map.removeLayer(LAYER_ID);
    }

    // 攤成場景公尺座標（東 +x、北 +y）的樹清單：{x,y,h,crown,trunk,needle,evergreen,seed}
    collect(THREE, features) {
      let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
      const seen = (c) => {
        minLon = Math.min(minLon, c[0]); maxLon = Math.max(maxLon, c[0]);
        minLat = Math.min(minLat, c[1]); maxLat = Math.max(maxLat, c[1]);
      };
      features.forEach((f) => {
        const g = f && f.geometry;
        if (!g) return;
        if (g.type === 'Point') seen(g.coordinates);
        else if (g.type === 'LineString') g.coordinates.forEach(seen);
      });
      const lat0 = (minLat + maxLat) / 2, lon0 = (minLon + maxLon) / 2;
      // 場景座標用引擎共用的麥卡托換算（與 SceneLayer 的矩陣同一套），不自己用經緯度乘係數
      const local = MapLibreEngine.localMeters([lat0, lon0]);

      const sparse = [], dense = [];
      let seed = 0;
      const plant = (xy, p, into) => {
        seed++;
        const needle = p.leafType === 'needleleaved';
        const spec = needle ? NEEDLE : BROAD;
        const given = num(p.height);
        const shrub = given !== null && given < SHRUB_MAX_M;
        const h = given || range(p.kind === 'row' && !needle ? ROW_HEIGHT : spec.height, hash01(seed));
        const crown = num(p.crown) || h * (shrub ? 1 : range(spec.crownRatio, hash01(seed + 0.5)));
        const trunk = Math.min(num(p.trunk) || Math.max(TRUNK_MIN_M, h * TRUNK_RATIO), crown * TRUNK_MAX_CROWN);
        into.push({
          x: xy[0], y: xy[1], h, crown, trunk, seed,
          clear: h * (shrub ? SHRUB_CLEAR : spec.clear),
          kind: needle ? 'needle' : p.leafCycle === 'evergreen' ? 'ever' : 'decid',
        });
      };
      features.forEach((f) => {
        const g = f && f.geometry, p = (f && f.properties) || {};
        if (!g) return;
        if (g.type === 'Point') {
          plant(local(g.coordinates), p, sparse);
        } else if (g.type === 'LineString' && g.coordinates.length >= 2) {
          // 沿線等距取點；carry 是上一段用剩的距離，轉角處不會多出或少掉一株
          const pts = g.coordinates.map(local);
          let carry = 0, n = 0;
          for (let i = 0; i + 1 < pts.length; i++) {
            const a = pts[i], b = pts[i + 1];
            const len = Math.hypot(b[0] - a[0], b[1] - a[1]);
            for (let d = carry; d <= len; d += TREE_SPACING_M) {
              const t = len ? d / len : 0;
              plant([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t], p, n++ % 2 === 0 ? sparse : dense);
              carry = d + TREE_SPACING_M - len;
            }
            if (carry < 0) carry = 0;
          }
        }
      });

      // 超過上限就按編號抽樣，比例一致所以疏密兩組的比例不變
      const total = sparse.length + dense.length;
      const share = Math.min(1, MAX_TREES / Math.max(1, total));
      if (share < 1) console.warn('[map3d-trees] 樹木超過上限 ' + MAX_TREES + '，已抽樣');
      const thin = (list) => (share < 1 ? list.filter((t) => hash01(t.seed * 3.7) < share) : list);
      return { THREE, origin: [lat0, lon0], sparse: thin(sparse), dense: thin(dense), geo: null };
    }

    // 單位零件：軸轉成 Z 向上、底面貼在 z=0，實例再用縮放決定粗細與高度
    geometries(THREE) {
      const b = this.built;
      if (b.geo) return b.geo;
      const up = (g, dz) => { g.rotateX(Math.PI / 2); g.translate(0, 0, dz); return g; };
      // 針葉冠：三層疊錐（z 範圍與底半徑），合併成一個網格
      const tiers = [[0, 0.55, 1], [0.25, 0.8, 0.72], [0.5, 1, 0.48]].map(([z0, z1, r]) =>
        up(new THREE.ConeGeometry(r, z1 - z0, 7), (z0 + z1) / 2).toNonIndexed());
      const pos = tiers.flatMap((g) => Array.from(g.getAttribute('position').array));
      const needle = new THREE.BufferGeometry();
      needle.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
      b.geo = {
        trunk: up(new THREE.CylinderGeometry(TRUNK_FLARE[1], TRUNK_FLARE[0], 1, 6), 0.5),
        decid: new THREE.IcosahedronGeometry(1, 0),
        ever: new THREE.IcosahedronGeometry(1, 1),
        needle,
      };
      return b.geo;
    }

    makeLayer(dark) {
      const THREE = this.built.THREE;
      const plugin = this;
      const palette = dark ? COLORS.dark : COLORS.light;
      class TreesLayer extends MapLibreEngine.SceneLayer {
        populate(scene) {
          this.denseGroup = new THREE.Group();
          plugin.fill(scene, this.denseGroup, palette);
          scene.add(this.denseGroup);
        }
        render(gl, args) {
          const z = this.map.getZoom();
          if (z < MIN_ZOOM) return;
          this.denseGroup.visible = z >= DENSE_ZOOM;
          super.render(gl, args);
        }
      }
      return new TreesLayer(LAYER_ID, THREE, this.built.origin, 0, MapLibreEngine.lights3D(dark));
    }

    // 疏的一組放進場景，密的一組放進 denseGroup（由圖層依縮放開關）
    fill(scene, denseGroup, palette) {
      const b = this.built, THREE = b.THREE, geo = this.geometries(THREE);
      const mat = new THREE.MeshLambertMaterial({ flatShading: true });
      const m4 = new THREE.Matrix4(), pos = new THREE.Vector3(), scl = new THREE.Vector3();
      const quat = new THREE.Quaternion(), up = new THREE.Vector3(0, 0, 1);
      const col = new THREE.Color(), hsl = { h: 0, s: 0, l: 0 };
      const jitter = (base, seed) => {
        col.set(base).getHSL(hsl);
        return col.setHSL(hsl.h + (hash01(seed * 9.1) - 0.5) * 0.04, hsl.s, Math.max(0.05, hsl.l + (hash01(seed * 5.3) - 0.5) * 0.12)).clone();
      };
      // place 設定 pos／scl，這裡統一加上隨機轉向與各軸 ±12% 的縮放
      const mesh = (geom, list, parent, place, color) => {
        if (!list.length) return;
        const im = new THREE.InstancedMesh(geom, mat, list.length);
        im.frustumCulled = false;
        list.forEach((t, i) => {
          place(t);
          scl.multiply(new THREE.Vector3(1 + (hash01(t.seed * 2.3) - 0.5) * 0.24, 1 + (hash01(t.seed * 4.1) - 0.5) * 0.24, 1));
          quat.setFromAxisAngle(up, hash01(t.seed * 7.7) * Math.PI * 2);
          im.setMatrixAt(i, m4.compose(pos, quat, scl));
          im.setColorAt(i, color(t));
        });
        parent.add(im);
      };
      [[b.sparse, scene], [b.dense, denseGroup]].forEach(([list, parent]) => {
        mesh(geo.trunk, list, parent, (t) => {
          pos.set(t.x, t.y, 0);
          scl.set(t.trunk / 2, t.trunk / 2, t.clear + 0.5);
        }, (t) => jitter(palette.trunk, t.seed));
        ['decid', 'ever'].forEach((kind) => mesh(geo[kind], list.filter((t) => t.kind === kind), parent, (t) => {
          // 橢球佔樹幹頂稍下到樹頂之間，讓冠底蓋住樹幹頂端
          const low = t.clear * 0.85, zr = (t.h - low) / 2;
          pos.set(t.x, t.y, low + zr);
          scl.set(t.crown / 2, t.crown / 2, zr);
        }, (t) => jitter(palette[kind], t.seed)));
        mesh(geo.needle, list.filter((t) => t.kind === 'needle'), parent, (t) => {
          const low = t.clear * 0.85;
          pos.set(t.x, t.y, low);
          scl.set(t.crown / 2, t.crown / 2, t.h - low);
        }, (t) => jitter(palette.needle, t.seed));
      });
    }
  }

  new Map3DTreesPlugin().init(window.MapApp);
})();
