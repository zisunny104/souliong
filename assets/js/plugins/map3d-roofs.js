/* 選用插件：3D 建物屋頂造型（OSM Simple 3D Buildings 的 roof:shape）
   資料是 projects/<p>/roofs.geojson（多邊形，屬性見 api 端整理後的欄位），網址由 APP.map3d.roofsUrl 提供，
   沒有這個網址就整個不動作。進入 3D 時載入、依建物輪廓用 three.js 生出牆身＋屋頂，退出時移除；
   有資料的建物會從公用建物擠出圖層排除，避免疊兩份。渲染底座（自訂圖層、光線、座標）在
   MapLibreEngine，這裡只負責幾何與配色。

   屋頂高度用「位置→高度」函式描述，先把輪廓三角形沿函式的折線切開，再逐頂點取值，
   折線兩側就各是一片平面，不需要為每種造型寫專用的網格生成。未支援的 roof:shape 退回平頂。 */
(() => {
  const LAYER_ID = 'm3d-roofs';
  const EPS = 1e-6;
  const LEVEL_M = 3;            // 每層樓高（公尺），OSM 慣例
  const SAMPLES = 24;
  const PART_COVER = 0.9;
  const EXCLUDE_COVER = 0.5;
  // 窗格（日夜共用）：每格一層樓高、約 WIN_CELL_M 寬；窗寬、窗高、窗台高都是格內比例（窗台約離樓板 0.9 公尺）
  const WIN_CELL_M = 4;
  const WIN_NARROW_M = 6;       // 牆面窄於此只放 1～2 格
  const WIN_W = 0.5;
  const WIN_H = 0.45;
  const WIN_SILL = 0.26;
  const DEFAULT_EAVE_M = 6;     // 沒有任何高度資料時的牆身高度

  // 沒有屋頂高度、層數、坡度時，屋頂高度取「垂直屋脊方向半寬」的倍數（再夾在 1.5–8 公尺，尖塔可到 20 公尺）
  const ROOF_RISE = {
    gabled: 0.6, hipped: 0.6, 'half-hipped': 0.6, gambrel: 0.7, mansard: 0.5, pyramidal: 0.8,
    skillion: 0.3, round: 0.7, dome: 0.25, onion: 1.6, cone: 1.2, spire: 3,
  };
  // 圓頂預設弧高：半徑 3 公尺以內是半球，較大的取四分之一半徑但至少 3 公尺（淺圓丘）
  const DOME_MIN_RISE_M = 3;
  const ROOF_ALIAS = { pyramid: 'pyramidal' };

  const ROOF_MATERIAL_COLOR = {
    tiles: '#a4553a', roof_tiles: '#a4553a', tile: '#a4553a', metal: '#8e979e', metal_sheet: '#8e979e', tin: '#8e979e',
    concrete: '#b8b5ae', slate: '#5d666e', thatch: '#b09a68', copper: '#6f9f89', glass: '#9cc4d4',
    tar_paper: '#4b4b4b', bitumen: '#4b4b4b', wood: '#9a7549', wood_shingle: '#9a7549', stone: '#9d978a',
    eternit: '#9b9b95', asbestos: '#9b9b95', grass: '#78a062', plants: '#78a062', gravel: '#a9a59c',
  };
  const WALL_MATERIAL_COLOR = {
    brick: '#b3644a', concrete: '#bfbcb5', cement_block: '#b5b2ab', plaster: '#ece3d2', stucco: '#ece3d2',
    stone: '#a59f92', wood: '#a9825a', metal: '#98a2a9', glass: '#a9c9d8', sandstone: '#cdb48a', mud: '#a58966',
  };

  const num = (v) => {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : null;
  };
  const pointInRing = (pt, ring) => {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const a = ring[i], b = ring[j];
      if ((a[1] > pt[1]) !== (b[1] > pt[1]) && pt[0] < (b[0] - a[0]) * (pt[1] - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
    }
    return inside;
  };
  // ring 的面積有多少比例被 others（一組外環）蓋住：以外框內的格點取樣估算
  const coverage = (ring, others) => {
    const xs = ring.map((q) => q[0]), ys = ring.map((q) => q[1]);
    const x0 = Math.min(...xs), y0 = Math.min(...ys), dx = (Math.max(...xs) - x0) / SAMPLES, dy = (Math.max(...ys) - y0) / SAMPLES;
    let total = 0, hit = 0;
    for (let i = 0; i < SAMPLES; i++) for (let j = 0; j < SAMPLES; j++) {
      const pt = [x0 + dx * (i + 0.5), y0 + dy * (j + 0.5)];
      if (!pointInRing(pt, ring)) continue;
      total++;
      if (others.some((r) => pointInRing(pt, r))) hit++;
    }
    return total ? hit / total : 0;
  };
  // 以建物編號湊出穩定的 0..1 亂數，讓夜間亮窗的分布每次進 3D 都一樣
  const hash01 = (n) => {
    const x = Math.sin(n * 127.1 + 311.7) * 43758.5453;
    return x - Math.floor(x);
  };

  // 讓 line = a*s + b*t + c（s 沿屋脊、t 垂直屋脊，原點在輪廓外接矩形中心）換成世界 xy 上的直線
  class Frame {
    constructor(cx, cy, phi) {
      this.cx = cx; this.cy = cy;
      this.cos = Math.cos(phi); this.sin = Math.sin(phi);
    }
    s(x, y) { return (x - this.cx) * this.cos + (y - this.cy) * this.sin; }
    t(x, y) { return -(x - this.cx) * this.sin + (y - this.cy) * this.cos; }
    line(a, b, c) {
      const nx = a * this.cos - b * this.sin;
      const ny = a * this.sin + b * this.cos;
      return [nx, ny, c - nx * this.cx - ny * this.cy];
    }
  }

  // 用直線把多邊形切成兩半（Sutherland–Hodgman），回傳 [正側, 負側]
  function clipSide(poly, ln, sign) {
    const out = [];
    const d = poly.map((p) => sign * (ln[0] * p[0] + ln[1] * p[1] + ln[2]));
    for (let i = 0; i < poly.length; i++) {
      const j = (i + 1) % poly.length;
      const a = poly[i], b = poly[j], da = d[i], db = d[j];
      if (da >= -EPS) out.push(a);
      if ((da > EPS && db < -EPS) || (da < -EPS && db > EPS)) {
        const k = da / (da - db);
        out.push([a[0] + (b[0] - a[0]) * k, a[1] + (b[1] - a[1]) * k]);
      }
    }
    return out;
  }
  function splitTriangles(tris, lines) {
    let cur = tris;
    lines.forEach((ln) => {
      const next = [];
      cur.forEach((tri) => {
        const d = tri.map((p) => ln[0] * p[0] + ln[1] * p[1] + ln[2]);
        if (d.every((v) => v >= -EPS) || d.every((v) => v <= EPS)) { next.push(tri); return; }
        [1, -1].forEach((sign) => {
          const piece = clipSide(tri, ln, sign);
          for (let i = 1; i + 1 < piece.length; i++) next.push([piece[0], piece[i], piece[i + 1]]);
        });
      });
      cur = next;
    });
    return cur;
  }

  // 屋頂造型：回傳 {h(s,t)→0..1 的高度比例, lines→折線／細分線}；hs、ht 是外接矩形沿屋脊／垂直屋脊的半長
  const grid = (n, half, axis) => {
    const out = [];
    for (let i = 1; i < n; i++) out.push(axis === 's' ? [1, 0, -(-half + 2 * half * i / n)] : [0, 1, -(-half + 2 * half * i / n)]);
    return out;
  };
  // 球面弧的取樣線：依角度等分（近邊緣較密），弧線接近垂直的地方才不會被切成斗笠狀的折面
  const arcGrid = (n, half, axis) => {
    const out = [];
    for (let i = 0; i < n; i++) {
      const pos = half * Math.sin(i * Math.PI / (2 * n));
      (i ? [pos, -pos] : [0]).forEach((v) => out.push(axis === 's' ? [1, 0, -v] : [0, 1, -v]));
    }
    return out;
  };
  const SHAPES = {
    gabled: (hs, ht) => ({
      h: (s, t) => 1 - Math.abs(t) / ht,
      lines: [[0, 1, 0]],
    }),
    hipped: (hs, ht) => {
      const k = hs - ht;
      return {
        h: (s, t) => Math.max(0, Math.min(1 - Math.abs(t) / ht, (hs - Math.abs(s)) / ht)),
        lines: [[0, 1, 0], [1, 0, 0], [-1, 1, k], [1, 1, k], [-1, 1, -k], [1, 1, -k]],
      };
    },
    'half-hipped': (hs, ht) => {
      const run = 0.6 * ht, slope = 0.5 * ht / run, c0 = 0.5 * ht - slope * hs;
      return {
        h: (s, t) => Math.max(0, Math.min(1 - Math.abs(t) / ht, 0.5 + 0.5 * Math.min(1, (hs - Math.abs(s)) / run))),
        lines: [[0, 1, 0], [1, 0, -(hs - run)], [1, 0, hs - run],
          [-slope, 1, -c0], [slope, 1, -c0], [slope, 1, c0], [-slope, 1, c0]],
      };
    },
    gambrel: (hs, ht) => ({
      h: (s, t) => { const x = Math.abs(t) / ht; return x >= 0.55 ? 0.7 * (1 - x) / 0.45 : 1 - 0.3 * x / 0.55; },
      lines: [[0, 1, 0], [0, 1, -0.55 * ht], [0, 1, 0.55 * ht]],
    }),
    mansard: (hs, ht) => {
      const w = 0.3 * Math.min(ht, hs), k = hs - ht;
      return {
        h: (s, t) => {
          const d = Math.min(ht - Math.abs(t), hs - Math.abs(s));
          return d < w ? 0.8 * d / w : 0.8 + 0.2 * Math.min(1, (d - w) / Math.max(EPS, Math.min(ht, hs) - w));
        },
        lines: [[1, 0, -(hs - w)], [1, 0, hs - w], [0, 1, -(ht - w)], [0, 1, ht - w],
          [-1, 1, k], [1, 1, k], [-1, 1, -k], [1, 1, -k], [0, 1, 0], [1, 0, 0]],
      };
    },
    pyramidal: (hs, ht) => ({
      h: (s, t) => 1 - Math.max(Math.abs(s) / hs, Math.abs(t) / ht),
      lines: [[-ht, hs, 0], [ht, hs, 0], [1, 0, 0], [0, 1, 0]],
    }),
    // 單坡：往 +t 方向下降，方向由 roof:direction 轉進外接矩形座標系時另外處理，這裡不需要折線
    skillion: (hs, ht) => ({ h: (s, t) => (ht - t) / (2 * ht), lines: [] }),
    round: (hs, ht) => ({ h: (s, t) => Math.sqrt(Math.max(0, 1 - (t / ht) ** 2)), lines: arcGrid(12, ht, 't') }),
    dome: (hs, ht) => ({
      h: (s, t) => Math.sqrt(Math.max(0, 1 - Math.min(1, Math.hypot(s / hs, t / ht)) ** 2)),
      lines: arcGrid(14, hs, 's').concat(arcGrid(14, ht, 't')),
    }),
    onion: (hs, ht) => ({
      h: (s, t) => Math.pow(Math.max(0, 1 - Math.min(1, Math.hypot(s / hs, t / ht) / 1.15)), 0.55),
      lines: grid(14, hs, 's').concat(grid(14, ht, 't')),
    }),
    // 非標準但常見的尖塔：輪廓比圓錐更瘦（凹面），塔頂收成一點
    spire: (hs, ht) => ({
      h: (s, t) => Math.pow(Math.max(0, 1 - Math.min(1, Math.hypot(s / hs, t / ht) / 1.2)), 1.6),
      lines: grid(8, hs, 's').concat(grid(8, ht, 't')),
    }),
    cone: (hs, ht) => ({
      h: (s, t) => Math.max(0, 1 - Math.min(1, Math.hypot(s / hs, t / ht) / 1.2)),
      lines: grid(12, hs, 's').concat(grid(12, ht, 't')),
    }),
  };

  class Map3DRoofsPlugin extends MapApp.Plugin {
    constructor() {
      super('map3d-roofs');
      this.dataPromise = null;
      this.threePromise = null;
      this.excluded = 0;
      this.onIdle = null;
      this.layerDark = null;
      this.built = null;   // {THREE, origin:[lat,lon], footprints, geom(by dark)}
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
          .catch((e) => { console.error('[map3d-roofs] 屋頂資料載入失敗', e); this.dataPromise = null; return null; });
      }
      return this.dataPromise;
    }
    loadThree() {
      if (!this.threePromise) {
        this.threePromise = import('three').catch((e) => { console.error('[map3d-roofs] three.js 載入失敗', e); this.threePromise = null; return null; });
      }
      return this.threePromise;
    }

    apply(engine, cfg) {
      if (!cfg.roofsUrl) return;
      Promise.all([this.loadData(cfg.roofsUrl), this.loadThree()]).then(([features, THREE]) => {
        const map = engine.getRawMap();
        // 樣式重載中（getStyle 為空；isStyleLoaded 在載圖磚時也會是 false，不能用）或已退出 3D 就放棄；樣式載完後引擎會再呼叫一次 apply
        if (!features || !features.length || !THREE || !engine.in3D || !map.getStyle()) return;
        // 切深淺色重載樣式後圖層可能還在，但場景是舊色系建的，要重建
        if (map.getLayer(LAYER_ID)) {
          if (this.layerDark === engine.dark) return;
          map.removeLayer(LAYER_ID);
        }
        if (!this.built) this.built = this.collect(THREE, features);
        this.layerDark = engine.dark;
        map.addLayer(this.makeLayer(engine, THREE));
        this.syncExclusion(engine);
        if (!this.onIdle) {
          this.onIdle = () => this.syncExclusion(engine);
          map.on('idle', this.onIdle);
        }
      });
    }

    clear(engine) {
      const map = engine.getRawMap();
      if (this.onIdle) { map.off('idle', this.onIdle); this.onIdle = null; }
      if (map.getLayer(LAYER_ID)) map.removeLayer(LAYER_ID);
      this.excluded = 0;
      engine.setBuildingExclusion('roofs', null);
    }

    // 把多數面積被屋頂輪廓蓋住的公用圖磚建物整棟排除（MapLibre 的 within 不支援多邊形圖徵，只能用圖徵 id）。
    // 圖磚建物常比 OSM 分件大，資料要涵蓋該棟所有分件才不會留空洞。圖磚是漸進載入的，
    // 每次地圖閒置時重掃，id 數量有變才重設 filter。
    syncExclusion(engine) {
      const map = engine.getRawMap();
      const layer = (map.getStyle().layers || []).find((l) => l.type === 'fill-extrusion' && (l['source-layer'] === 'building' || /building/i.test(l.id)));
      if (!layer) return;
      const fps = this.built.footprints;
      // 只排除多數面積（≥50%）被屋頂輪廓蓋住的圖磚建物；僅相鄰或稍微重疊的鄰棟建物要保留
      const outers = fps.map((fp) => fp[0]);
      const covered = (ring) => coverage(ring, outers) >= EXCLUDE_COVER;
      const ids = new Set();
      map.querySourceFeatures(layer.source, { sourceLayer: layer['source-layer'] }).forEach((f) => {
        const g = f.geometry;
        const polys = g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : [];
        if (f.id != null && polys.some((rings) => rings[0] && covered(rings[0]))) ids.add(f.id);
      });
      if (ids.size === this.excluded) return;
      this.excluded = ids.size;
      engine.setBuildingExclusion('roofs', ['in', ['id'], ['literal', [...ids]]]);
    }

    // 把 GeoJSON 攤成 [{rings, props}]，並算出整批資料的中心當場景原點。
    // kind=part 是建物分件，常與外框（kind=building）重疊：分件幾乎蓋滿外框（≥90%）時外框自己不畫，
    // 否則分件只蓋住一角，外框仍要畫，不然剩下的部分會整塊消失。
    collect(THREE, features) {
      const items = [];
      const outerOf = (f) => {
        const g = f && f.geometry;
        const polys = !g ? [] : g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : [];
        return polys.filter((rings) => rings && rings[0] && rings[0].length >= 4);
      };
      const isPart = (f) => f && f.properties && f.properties.kind === 'part';
      const partRings = [];
      features.filter(isPart).forEach((f) => outerOf(f).forEach((rings) => partRings.push(rings[0])));
      const partCovered = (ring) => coverage(ring, partRings) >= PART_COVER;
      const footprints = [];
      let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
      features.forEach((f) => {
        outerOf(f).forEach((rings) => {
          footprints.push(rings);
          if (!isPart(f) && partCovered(rings[0])) return;
          items.push({ rings, props: f.properties || {}, seed: items.length + 1 });
          rings[0].forEach((p) => {
            minLon = Math.min(minLon, p[0]); maxLon = Math.max(maxLon, p[0]);
            minLat = Math.min(minLat, p[1]); maxLat = Math.max(maxLat, p[1]);
          });
        });
      });
      return { THREE, items, footprints, origin: [(minLat + maxLat) / 2, (minLon + maxLon) / 2], cache: {} };
    }

    makeLayer(engine, THREE) {
      const dark = engine.dark;
      const wallBase = new THREE.Color(engine.buildingExtrusionColor() || (dark ? '#4a4a4a' : '#e9dfd1'));
      const b = this.built;
      const key = dark ? 'dark' : 'light';
      if (!b.cache[key]) b.cache[key] = this.buildGeometry(b, dark, wallBase);
      const g = b.cache[key];
      const plugin = this;
      class RoofsLayer extends MapLibreEngine.SceneLayer {
        populate(scene) {
          plugin.fill(scene, g, dark);
        }
      }
      return new RoofsLayer(LAYER_ID, THREE, b.origin, 0, MapLibreEngine.lights3D(dark));
    }

    fill(scene, g, dark) {
      const THREE = this.built.THREE;
      const mk = (geom, mat) => {
        const mesh = new THREE.Mesh(geom, mat);
        mesh.frustumCulled = false;
        scene.add(mesh);
      };
      const wallMat = new THREE.MeshLambertMaterial({ vertexColors: true, side: THREE.DoubleSide });
      const tex = this.windowTexture(THREE, dark);
      if (dark) {
        wallMat.emissive = new THREE.Color(0xffb45a);
        wallMat.emissiveMap = tex;
      } else {
        wallMat.map = tex;
      }
      mk(g.walls, wallMat);
      mk(g.roofs, new THREE.MeshLambertMaterial({ vertexColors: true, side: THREE.DoubleSide }));
    }

    // 4x4 格的窗貼圖，一格＝一層樓×一個窗欄，牆面 uv 再加上每棟不同的位移。日夜共用同一組窗格位置：
    // 夜間是發光遮罩（亮窗約三成，其餘全黑），日間是乘在牆色上的玻璃色（每格都有窗，比外牆略深）
    windowTexture(THREE, dark) {
      const N = 4, PX = 64;
      const c = document.createElement('canvas');
      c.width = c.height = N * PX;
      const ctx = c.getContext('2d');
      ctx.fillStyle = dark ? '#000' : '#fff';
      ctx.fillRect(0, 0, c.width, c.height);
      for (let i = 0; i < N; i++) for (let j = 0; j < N; j++) {
        if (dark && hash01(i * 7 + j * 13 + 3) >= 0.3) continue;
        ctx.fillStyle = dark ? '#fff' : '#a9b7c2';
        ctx.fillRect(i * PX + PX * (1 - WIN_W) / 2, j * PX + PX * (1 - WIN_SILL - WIN_H), PX * WIN_W, PX * WIN_H);
      }
      const tex = new THREE.CanvasTexture(c);
      tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
      tex.colorSpace = THREE.SRGBColorSpace;
      tex.anisotropy = 4;
      return tex;
    }

    // 顏色：明確標的顏色優先，其次材質對照表，最後才是底圖建物色；夜間把明確顏色壓暗但保留色相與最低亮度
    resolveColors(THREE, p, dark, wallBase) {
      const parse = (v) => {
        if (v == null || v === '') return null;
        try { const c = new THREE.Color(String(v)); return c; } catch (e) { return null; }
      };
      const mat = (table, v) => parse(table[String(v || '').toLowerCase()]);
      const hsl = { h: 0, s: 0, l: 0 };
      let wall = parse(p.buildingColour || p.colour) || mat(WALL_MATERIAL_COLOR, p.buildingMaterial);
      let roof = parse(p.roofColour) || mat(ROOF_MATERIAL_COLOR, p.roofMaterial);
      const wallExplicit = !!wall, roofExplicit = !!roof;
      if (!wall) wall = wallBase.clone();
      if (!roof) {
        roof = wall.clone();
        roof.getHSL(hsl);
        roof.setHSL(hsl.h, hsl.s, dark ? Math.min(1, hsl.l + 0.08) : hsl.l * 0.88);
      }
      if (dark) {
        if (wallExplicit) { wall.getHSL(hsl); wall.setHSL(hsl.h, hsl.s * 0.9, Math.min(0.36, Math.max(0.2, hsl.l * 0.5))); }
        if (roofExplicit) { roof.getHSL(hsl); roof.setHSL(hsl.h, hsl.s * 0.9, Math.min(0.44, Math.max(0.26, hsl.l * 0.6 + 0.04))); }
      }
      return { wall, roof };
    }

    // 輪廓外接矩形：取面積最小的旋轉角，回傳世界座標中心、屋脊方向角與沿脊／垂直脊的半長
    orientedBox(pts) {
      let best = null;
      for (let i = 0; i < pts.length; i++) {
        const a = pts[i], b = pts[(i + 1) % pts.length];
        const phi = Math.atan2(b[1] - a[1], b[0] - a[0]);
        const c = Math.cos(phi), s = Math.sin(phi);
        let u0 = Infinity, u1 = -Infinity, v0 = Infinity, v1 = -Infinity;
        pts.forEach((p) => {
          const u = p[0] * c + p[1] * s, v = -p[0] * s + p[1] * c;
          u0 = Math.min(u0, u); u1 = Math.max(u1, u); v0 = Math.min(v0, v); v1 = Math.max(v1, v);
        });
        const area = (u1 - u0) * (v1 - v0);
        if (!best || area < best.area - EPS) best = { area, phi, c, s, u0, u1, v0, v1 };
      }
      const cu = (best.u0 + best.u1) / 2, cv = (best.v0 + best.v1) / 2;
      return {
        cx: cu * best.c - cv * best.s, cy: cu * best.s + cv * best.c,
        phi: best.phi, hu: (best.u1 - best.u0) / 2, hv: (best.v1 - best.v0) / 2,
      };
    }

    buildGeometry(b, dark, wallBase) {
      const THREE = b.THREE;
      const local = MapLibreEngine.localMeters(b.origin);
      const W = { pos: [], col: [], uv: [] };
      const R = { pos: [], col: [] };

      b.items.forEach((it) => {
        const rings = it.rings.map((r) => {
          const pts = r.map(local);
          const first = pts[0], last = pts[pts.length - 1];
          if (first[0] === last[0] && first[1] === last[1]) pts.pop();
          return pts;
        });
        if (rings[0].length < 3) return;
        this.addBuilding(THREE, it, rings, dark, wallBase, W, R);
      });

      const geom = (a, uv) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.Float32BufferAttribute(a.pos, 3));
        g.setAttribute('color', new THREE.Float32BufferAttribute(a.col, 3));
        if (uv) g.setAttribute('uv', new THREE.Float32BufferAttribute(a.uv, 2));
        g.computeVertexNormals();
        return g;
      };
      return { walls: geom(W, true), roofs: geom(R, false) };
    }

    addBuilding(THREE, it, rings, dark, wallBase, W, R) {
      const p = it.props;
      const outer = rings[0];
      const rawShape = String(p.shape || 'flat').toLowerCase();
      const shape = ROOF_ALIAS[rawShape] || rawShape;
      const make = SHAPES[shape];

      const box = this.orientedBox(outer);
      let hs = box.hu, ht = box.hv, phi = box.phi;
      // 屋脊預設順著長邊；roof:orientation=across 改成垂直長邊。長短軸互換後再讓 hs 對應屋脊方向
      const across = p.roofOrientation === 'across';
      if (hs < ht) { [hs, ht] = [ht, hs]; phi += Math.PI / 2; }
      if (across) { [hs, ht] = [ht, hs]; phi += Math.PI / 2; }
      // 單坡的 t 軸要對準傾斜方向：roof:direction 是朝下坡的羅盤方位（北為 0、順時針），
      // 對應 t 軸方向 (sin a, cos a)，所以 phi = -a；沒填就沿用垂直屋脊的 +t
      const dir = shape === 'skillion' ? num(p.roofDirection) : null;
      if (dir != null) phi = -dir * Math.PI / 180;
      const frame = new Frame(box.cx, box.cy, phi);
      if (shape === 'skillion') {
        let tmin = Infinity, tmax = -Infinity;
        outer.forEach((q) => { const t = frame.t(q[0], q[1]); tmin = Math.min(tmin, t); tmax = Math.max(tmax, t); });
        ht = (tmax - tmin) / 2 || 1;
        const tc = (tmax + tmin) / 2;
        frame.cx -= frame.sin * tc; frame.cy += frame.cos * tc;
      }

      const minLevel = num(p.minLevel);
      const minH = num(p.minHeight) != null ? num(p.minHeight) : (minLevel != null ? minLevel * LEVEL_M : 0);
      const levels = num(p.levels);
      const rlevels = num(p.roofLevels), angle = num(p.roofAngle);
      let roofH = 0;
      if (make) {
        const explicitH = num(p.roofHeight);
        roofH = explicitH;
        if (roofH == null && rlevels != null) roofH = rlevels * LEVEL_M;
        if (roofH == null && angle != null) roofH = Math.tan(Math.min(80, Math.max(5, angle)) * Math.PI / 180) * ht;
        const radius = Math.min(hs, ht);
        if (roofH == null) roofH = shape === 'dome' ? Math.min(radius, Math.max(ROOF_RISE.dome * radius, DOME_MIN_RISE_M)) : ht * (ROOF_RISE[shape] || 0.6);
        // 明確填寫的 roof:height 不設上限（尖塔可能很高）；推算出來的才夾範圍
        roofH = explicitH == null ? Math.min(shape === 'spire' ? 20 : 8, Math.max(shape === 'dome' ? 0 : 1.5, roofH)) : Math.max(1.5, roofH);
      }
      const total = num(p.height) != null ? num(p.height) : (levels != null ? levels * LEVEL_M + roofH : null);
      if (total != null && roofH > (total - minH) / 2) roofH = Math.max(0, (total - minH) / 3);
      let eave = total != null ? total - roofH : DEFAULT_EAVE_M;
      if (eave < minH + 0.5) eave = minH + 0.5;
      const { wall, roof } = this.resolveColors(THREE, p, dark, wallBase);

      // 牆身：每條輪廓邊一個矩形，外環逆時針、內環順時針，法向都朝向「非建物」那側
      const orient = (pts, ccw) => {
        let a = 0;
        for (let i = 0; i < pts.length; i++) { const q = pts[i], r = pts[(i + 1) % pts.length]; a += q[0] * r[1] - r[0] * q[1]; }
        return (a > 0) === ccw ? pts : pts.slice().reverse();
      };
      const wallRings = rings.map((r, i) => orient(r, i === 0));
      // 窗貼圖一格就是一層樓一欄：層數用 building:levels（扣掉 min_level），沒填才用牆高除以 3 公尺；
      // 每層列高 = 牆高 / 層數，所以窗列數必等於層數。水平方向每面牆取整數格，窗才不會在牆角被切半
      const floors = Math.max(1, levels != null && levels - (minLevel || 0) >= 1 ? Math.round(levels - (minLevel || 0)) : Math.round((eave - minH) / LEVEL_M));
      const floorH = (eave - minH) / floors;
      const uvOff = [Math.floor(hash01(it.seed) * 4) / 4, Math.floor(hash01(it.seed + 0.5) * 4) / 4];
      const quad = (a, b, z0a, z0b, z1a, z1b) => {
        if (z1a - z0a < 0.01 && z1b - z0b < 0.01) return;
        const len = Math.hypot(b[0] - a[0], b[1] - a[1]);
        const cells = len < WIN_NARROW_M ? (len < WIN_CELL_M ? 1 : 2) : Math.round(len / WIN_CELL_M);
        const u0 = uvOff[0], u1 = uvOff[0] + cells / 4;
        const v = (z) => uvOff[1] + (z - minH) / floorH / 4;
        const P = [[a[0], a[1], z0a, u0, v(z0a)], [b[0], b[1], z0b, u1, v(z0b)],
          [b[0], b[1], z1b, u1, v(z1b)], [a[0], a[1], z1a, u0, v(z1a)]];
        [0, 1, 2, 0, 2, 3].forEach((k) => {
          const q = P[k];
          W.pos.push(q[0], q[1], q[2]);
          W.col.push(wall.r, wall.g, wall.b);
          W.uv.push(q[3], q[4]);
        });
      };
      wallRings.forEach((pts) => {
        for (let i = 0; i < pts.length; i++) {
          const a = pts[i], c = pts[(i + 1) % pts.length];
          quad(a, c, minH, minH, eave, eave);
        }
      });

      // 屋頂：先把輪廓（含內環）三角化，再沿造型的折線細分並逐頂點取高度
      const V = THREE.Vector2;
      const contour = outer.map((q) => new V(q[0], q[1]));
      const holes = rings.slice(1).map((r) => r.map((q) => new V(q[0], q[1])));
      const all = outer.concat(...rings.slice(1));
      let tris = THREE.ShapeUtils.triangulateShape(contour, holes).map((t) => t.map((i) => all[i]));
      const shp = make ? make(hs, ht) : null;
      if (shp) tris = splitTriangles(tris, shp.lines.map((l) => frame.line(l[0], l[1], l[2])));
      const heightAt = shp ? (q) => roofH * Math.max(0, Math.min(1, shp.h(frame.s(q[0], q[1]), frame.t(q[0], q[1])))) : () => 0;

      const segs = [];
      wallRings.forEach((pts) => { for (let i = 0; i < pts.length; i++) segs.push([pts[i], pts[(i + 1) % pts.length]]); });
      const onSeg = (q, s) => {
        const dx = s[1][0] - s[0][0], dy = s[1][1] - s[0][1], l2 = dx * dx + dy * dy || 1;
        const t = ((q[0] - s[0][0]) * dx + (q[1] - s[0][1]) * dy) / l2;
        return t > -1e-6 && t < 1 + 1e-6 && Math.abs((q[0] - s[0][0]) * dy - (q[1] - s[0][1]) * dx) / Math.sqrt(l2) < 0.02;
      };
      tris.forEach((tri) => {
        // 頂面朝上（逆時針），DoubleSide 雖不怕繞向，但法向要對才會被光照亮
        const area = (tri[1][0] - tri[0][0]) * (tri[2][1] - tri[0][1]) - (tri[2][0] - tri[0][0]) * (tri[1][1] - tri[0][1]);
        const t3 = area < 0 ? [tri[0], tri[2], tri[1]] : tri;
        t3.forEach((q) => { R.pos.push(q[0], q[1], eave + heightAt(q)); R.col.push(roof.r, roof.g, roof.b); });
        // 貼在輪廓上的三角形邊，補一片牆到屋頂高度（山牆、圓頂裙邊）
        for (let i = 0; i < 3; i++) {
          const a = t3[i], c = t3[(i + 1) % 3];
          if (segs.some((s) => onSeg(a, s) && onSeg(c, s))) {
            // 三角形逆時針，建物在邊的左側，a→c 的右側法向就朝外
            quad(a, c, eave, eave, eave + heightAt(a), eave + heightAt(c));
          }
        }
      });
    }
  }

  new Map3DRoofsPlugin().init(window.MapApp);
})();
