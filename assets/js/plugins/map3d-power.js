/* 選用插件：3D 電塔、電桿與電線（OSM power=tower／pole 與 power=line／minor_line）
   資料是 projects/<p>/power.geojson，網址由 APP.map3d.powerUrl 提供，沒有這個網址就整個不動作。
   進入 3D 時載入，用 three.js 生出塔桿網格與下垂的導線，退出時移除。渲染底座（自訂圖層、光線、座標）
   在 MapLibreEngine，這裡只負責幾何與配色。

   電線的頂點與塔桿以座標相等對上；線上沒有塔桿的頂點當作「虛擬支撐」，在同樣的高度畫導線但不畫塔身，
   這樣沒標到塔的線路也連得起來。塔桿缺 height 時依相連電線的最大電壓推定；缺 design 時依迴路數。
   導線每跨一條下垂拋物線，弛度取跨距的 SAG_RATIO。 */
(() => {
  const LAYER_ID = 'm3d-power';
  const MIN_ZOOM = 14.5;        // 低於這個縮放不畫
  const SAG_RATIO = 0.01;
  const WIRE_SEGMENTS = 8;      // 每跨導線的折線段數
  const MAX_WIRE_VERTS = 400000;
  const MAX_SUPPORTS = 6000;

  const COLORS = {
    light: { metal: '#8a9199', wire: '#5b6168' },
    dark: { metal: '#4c5560', wire: '#3b434c' },
  };
  const POLE_STRUCTURES = ['tubular', 'pole', 'monopole', 'concrete', 'wood', 'steel_pole'];
  const LEVELS = { 'one-level': 1, 'two-level': 2, 'three-level': 3, delta: 1, donau: 2 };

  const num = (v) => {
    const n = parseFloat(v);
    return Number.isFinite(n) && n > 0 ? n : null;
  };
  const key = (c) => c[0].toFixed(7) + ',' + c[1].toFixed(7);

  // 沒標高度時依電壓推定：高壓鐵塔 30–60 公尺，低壓電桿 8–12 公尺
  const defaultHeight = (kind, volt) => {
    if (kind === 'pole' || kind === 'minor_line') return volt >= 20000 ? 12 : volt ? 9 : 10;
    return volt >= 220000 ? 60 : volt >= 110000 ? 45 : 30;
  };

  // 網格累積器：柱體（兩端可不同半徑的多邊稜柱）、兩點之間的桿件
  class Mesh {
    constructor() { this.pos = []; }
    prism(a, b, r0, r1, sides) {
      const ax = [b[0] - a[0], b[1] - a[1], b[2] - a[2]];
      const len = Math.hypot(...ax) || 1;
      const d = ax.map((v) => v / len);
      // 任取一個不與軸平行的向量做外積，湊出垂直於軸的兩個基底
      const ref = Math.abs(d[2]) < 0.9 ? [0, 0, 1] : [1, 0, 0];
      let u = [d[1] * ref[2] - d[2] * ref[1], d[2] * ref[0] - d[0] * ref[2], d[0] * ref[1] - d[1] * ref[0]];
      const ul = Math.hypot(...u) || 1;
      u = u.map((v) => v / ul);
      const w = [d[1] * u[2] - d[2] * u[1], d[2] * u[0] - d[0] * u[2], d[0] * u[1] - d[1] * u[0]];
      const ring = (p, r, k) => {
        const t = (k / sides) * Math.PI * 2;
        const c = Math.cos(t) * r, s = Math.sin(t) * r;
        return [p[0] + u[0] * c + w[0] * s, p[1] + u[1] * c + w[1] * s, p[2] + u[2] * c + w[2] * s];
      };
      for (let k = 0; k < sides; k++) {
        const a0 = ring(a, r0, k), a1 = ring(a, r0, k + 1), b0 = ring(b, r1, k), b1 = ring(b, r1, k + 1);
        this.pos.push(...a0, ...a1, ...b1, ...a0, ...b1, ...b0);
      }
    }
    geometry(THREE) {
      const g = new THREE.BufferGeometry();
      g.setAttribute('position', new THREE.Float32BufferAttribute(this.pos, 3));
      g.computeVertexNormals();
      return g;
    }
  }

  class Map3DPowerPlugin extends MapApp.Plugin {
    constructor() {
      super('map3d-power');
      this.dataPromise = null;
      this.threePromise = null;
      this.built = null;   // {THREE, origin, supports, spans, towers:Geometry, wires:Float32Array}
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
          .catch((e) => { console.error('[map3d-power] 電力資料載入失敗', e); this.dataPromise = null; return null; });
      }
      return this.dataPromise;
    }
    loadThree() {
      if (!this.threePromise) {
        this.threePromise = import('three').catch((e) => { console.error('[map3d-power] three.js 載入失敗', e); this.threePromise = null; return null; });
      }
      return this.threePromise;
    }

    apply(engine, cfg) {
      if (!cfg.powerUrl) return;
      Promise.all([this.loadData(cfg.powerUrl), this.loadThree()]).then(([features, THREE]) => {
        const map = engine.getRawMap();
        // 樣式重載中（getStyle 為空）或已退出 3D 就放棄；樣式載完後引擎會再呼叫一次 apply
        if (!features || !features.length || !THREE || !engine.in3D || !map.getStyle()) return;
        // 切深淺色重載樣式後圖層可能還在，但場景是舊色系建的，要重建
        if (map.getLayer(LAYER_ID)) {
          if (this.layerDark === engine.dark) return;
          map.removeLayer(LAYER_ID);
        }
        if (!this.built) this.built = this.collect(THREE, features);
        if (!this.built.towers && !this.built.wires.length) return;
        this.layerDark = engine.dark;
        map.addLayer(this.makeLayer(engine.dark));
      });
    }

    clear(engine) {
      const map = engine.getRawMap();
      if (map.getLayer(LAYER_ID)) map.removeLayer(LAYER_ID);
    }

    // 攤成場景公尺座標（東 +x、北 +y）：塔桿網格（真的塔桿）與導線折線（所有跨距）
    collect(THREE, features) {
      const points = [], lines = [];
      let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
      features.forEach((f) => {
        const g = f && f.geometry, p = (f && f.properties) || {};
        if (!g) return;
        const coords = g.type === 'Point' ? [g.coordinates] : g.type === 'LineString' ? g.coordinates : [];
        if (g.type === 'Point') points.push({ c: g.coordinates, p });
        else if (g.type === 'LineString' && coords.length >= 2) lines.push({ coords, p });
        coords.forEach((c) => {
          minLon = Math.min(minLon, c[0]); maxLon = Math.max(maxLon, c[0]);
          minLat = Math.min(minLat, c[1]); maxLat = Math.max(maxLat, c[1]);
        });
      });
      const lat0 = (minLat + maxLat) / 2, lon0 = (minLon + maxLon) / 2;
      // 場景座標用引擎共用的麥卡托換算（與 SceneLayer 的矩陣同一套），不自己用經緯度乘係數
      const local = MapLibreEngine.localMeters([lat0, lon0]);

      // 每個座標一個支撐：真的塔桿帶屬性，線頂點補上電壓、迴路與線路走向
      const sup = new Map();
      const at = (c) => {
        const k = key(c);
        if (!sup.has(k)) sup.set(k, { xy: local(c), real: null, volt: 0, circuits: 0, kind: null, dir: null });
        return sup.get(k);
      };
      points.forEach(({ c, p }) => { at(c).real = p; });
      lines.forEach(({ coords, p }) => {
        coords.forEach((c, i) => {
          const s = at(c);
          s.volt = Math.max(s.volt, num(p.voltage) || 0);
          s.circuits = Math.max(s.circuits, num(p.circuits) || 0);
          s.kind = s.kind || p.kind;
          if (!s.dir) {
            const a = local(coords[Math.max(0, i - 1)]), b = local(coords[Math.min(coords.length - 1, i + 1)]);
            const l = Math.hypot(b[0] - a[0], b[1] - a[1]);
            if (l) s.dir = [(b[0] - a[0]) / l, (b[1] - a[1]) / l];
          }
        });
      });
      sup.forEach((s) => {
        const p = s.real || {};
        const volt = num(p.voltage) || s.volt || 0;
        s.H = num(p.height) || defaultHeight(p.kind || s.kind, volt);
        const circuits = num(p.circuits) || s.circuits || 1;
        s.circuits = circuits;
        s.levels = LEVELS[p.design] || (circuits >= 2 ? 2 : 1);
        s.style = p.design === 'portal' ? 'portal'
          : (p.kind === 'pole' || POLE_STRUCTURES.includes(p.structure) || (!s.real && s.kind === 'minor_line')) ? 'pole' : 'lattice';
      });

      const towers = new Mesh();
      let drawn = 0;
      sup.forEach((s) => {
        if (!s.real || drawn >= MAX_SUPPORTS) return;
        drawn++;
        this.addSupport(towers, s);
      });

      const wires = [];
      lines.forEach(({ coords }) => {
        for (let i = 0; i + 1 < coords.length && wires.length < MAX_WIRE_VERTS * 3; i++) {
          this.addSpan(wires, sup.get(key(coords[i])), sup.get(key(coords[i + 1])));
        }
      });
      if (wires.length >= MAX_WIRE_VERTS * 3) console.warn('[map3d-power] 電線超過上限，已截斷');
      return {
        THREE, origin: [lat0, lon0], wires: new Float32Array(wires),
        towers: towers.pos.length ? towers.geometry(THREE) : null,
      };
    }

    // 導線掛點（側向位移 a、高度 z）：每層橫臂左右各一，最後是塔頂中央；門型是橫樑上左中右三點
    attachments(s) {
      const H = s.H, pts = [];
      if (s.style === 'portal') {
        const hw = H * 0.22, z = H * 0.85 - 0.6;
        pts.push([-hw, z], [hw, z], [0, z]);
      } else {
        for (let i = 0; i < s.levels; i++) {
          const a = H * (0.14 + 0.03 * i), z = H * (0.92 - 0.14 * i);
          pts.push([-a, z], [a, z]);
        }
        pts.push([0, H]);
      }
      return pts.slice(0, Math.max(3, Math.min(pts.length, 3 * s.circuits)));
    }

    // 兩個支撐之間的導線：同序號的掛點相連，側向用這一跨自己的垂直方向，兩端塔身朝向不同也接得起來
    addSpan(out, a, b) {
      if (!a || !b) return;
      const dx = b.xy[0] - a.xy[0], dy = b.xy[1] - a.xy[1], span = Math.hypot(dx, dy);
      if (span < 1) return;
      const px = -dy / span, py = dx / span;
      const pa = this.attachments(a), pb = this.attachments(b);
      for (let k = 0; k < Math.min(pa.length, pb.length); k++) {
        let prev = null;
        for (let n = 0; n <= WIRE_SEGMENTS; n++) {
          const t = n / WIRE_SEGMENTS;
          const lat = pa[k][0] * (1 - t) + pb[k][0] * t;
          const z = pa[k][1] * (1 - t) + pb[k][1] * t - 4 * SAG_RATIO * span * t * (1 - t);
          const v = [a.xy[0] + dx * t + px * lat, a.xy[1] + dy * t + py * lat, Math.max(1, z)];
          if (prev) out.push(...prev, ...v);
          prev = v;
        }
      }
    }

    // 塔身：局部座標（側向 u、高度 z）以線路走向的垂直方向為側向；沒有線路的塔桿側向朝東
    addSupport(m, s) {
      const [x, y] = s.xy, H = s.H;
      const d = s.dir || [0, 1];
      const px = -d[1], py = d[0];
      const P = (u, v, z) => [x + px * u + d[0] * v, y + py * u + d[1] * v, z];
      const rr = (f) => Math.max(0.05, H * f);
      const bar = (u0, v0, z0, u1, v1, z1, r) => m.prism(P(u0, v0, z0), P(u1, v1, z1), r, r, 4);

      if (s.style === 'portal') {
        const hw = H * 0.22, zb = H * 0.85;
        [-hw, hw].forEach((u) => m.prism(P(u, 0, 0), P(u, 0, zb), rr(0.014), rr(0.01), 6));
        bar(-hw, 0, zb, hw, 0, zb, rr(0.01));
        return;
      }
      if (s.style === 'pole') {
        m.prism(P(0, 0, 0), P(0, 0, H), rr(0.022), rr(0.012), 8);
        for (let i = 0; i < s.levels; i++) {
          const a = H * (0.14 + 0.03 * i), z = H * (0.92 - 0.14 * i);
          bar(-a, 0, z, a, 0, z, rr(0.008));
        }
        return;
      }
      // 桁架：四根腿由底寬收到頂窄，每隔 1/4 高一圈橫桿加斜撐，橫臂在腿的外側
      const bw = Math.max(1.5, H * 0.14), tw = H * 0.03;
      const half = (z) => bw + (tw - bw) * (z / H);
      const corner = (i, z) => [((i & 1) ? 1 : -1) * half(z), ((i & 2) ? 1 : -1) * half(z)];
      const cs = [0, 1, 3, 2];
      for (let i = 0; i < 4; i++) {
        const c0 = corner(i, 0), c1 = corner(i, H);
        m.prism(P(c0[0], c0[1], 0), P(c1[0], c1[1], H), rr(0.008), rr(0.005), 4);
      }
      for (let q = 0; q < 4; q++) {
        const z0 = H * q / 4, z1 = H * (q + 1) / 4;
        for (let e = 0; e < 4; e++) {
          const a0 = corner(cs[e], z0), b0 = corner(cs[(e + 1) % 4], z0);
          const a1 = corner(cs[e], z1), b1 = corner(cs[(e + 1) % 4], z1);
          if (q > 0) bar(a0[0], a0[1], z0, b0[0], b0[1], z0, rr(0.004));
          bar(a0[0], a0[1], z0, b1[0], b1[1], z1, rr(0.003));
        }
      }
      for (let i = 0; i < s.levels; i++) {
        const a = H * (0.14 + 0.03 * i), z = H * (0.92 - 0.14 * i);
        bar(-a, 0, z, a, 0, z, rr(0.006));
      }
    }

    makeLayer(dark) {
      const THREE = this.built.THREE;
      const b = this.built;
      const palette = dark ? COLORS.dark : COLORS.light;
      class PowerLayer extends MapLibreEngine.SceneLayer {
        populate(scene) {
          if (b.towers) {
            const mesh = new THREE.Mesh(b.towers, new THREE.MeshLambertMaterial({ color: palette.metal, side: THREE.DoubleSide }));
            mesh.frustumCulled = false;
            scene.add(mesh);
          }
          if (b.wires.length) {
            const g = new THREE.BufferGeometry();
            g.setAttribute('position', new THREE.BufferAttribute(b.wires, 3));
            const lines = new THREE.LineSegments(g, new THREE.LineBasicMaterial({ color: palette.wire }));
            lines.frustumCulled = false;
            scene.add(lines);
          }
        }
        render(gl, args) {
          if (this.map.getZoom() < MIN_ZOOM) return;
          super.render(gl, args);
        }
      }
      return new PowerLayer(LAYER_ID, THREE, b.origin, 0, MapLibreEngine.lights3D(dark));
    }
  }

  new Map3DPowerPlugin().init(window.MapApp);
})();
