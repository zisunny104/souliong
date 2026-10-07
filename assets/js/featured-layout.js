/* 精選預覽的排位：每個點位最多幾張預覽，依目前縮放把它們擺在點位四周。
   重點：一眼看得出預覽屬於哪個點位（比任何其他點位都更靠自己的點位，另外再畫一條細線），
   各點位的角度與距離錯開不整齊，並避開別人的點位與別人的預覽。純函式，不碰 DOM，Node 與瀏覽器共用。 */
(function (root) {
  const STEP = 15;                   // 候選角度間隔（度）
  const RADIUS_STEPS = [0, 9, 18];   // 候選半徑，越遠越少用

  function project(lat, lon, zoom) {
    const world = 512 * Math.pow(2, zoom), s = Math.sin(Math.max(-0.9999, Math.min(0.9999, lat * Math.PI / 180)));
    return [(lon + 180) / 360 * world, (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * world];
  }
  function hash(text) {
    let h = 2166136261;
    String(text).split('').forEach(ch => { h = Math.imul(h ^ ch.charCodeAt(0), 16777619); });
    return (h >>> 0);
  }
  function angleGap(a, b) { const d = Math.abs(a - b) % 360; return d > 180 ? 360 - d : d; }
  function segmentDistance(px, py, ax, ay, bx, by) {
    const dx = bx - ax, dy = by - ay, len = dx * dx + dy * dy;
    const t = len ? Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / len)) : 0;
    return Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
  }

  /* spots：全部要畫的點位 [{id, lat, lon, slots}]，slots 是這個點位要放幾個預覽（含「+N」收合鈕），沒有就 0。
     回傳 Map：id → [{x, y}]（預覽中心相對於點位中心的像素位移），長度等於 slots。 */
  function place(spots, zoom, opts) {
    const pinPx = (opts && opts.pinPx) || 24, size = (opts && opts.size) || 30;
    const r0 = pinPx / 2 + size / 2 + 10;
    const pinMin = pinPx / 2 + size / 2 + 5, prevMin = size + 5;
    const pts = spots.map(s => { const p = project(s.lat, s.lon, zoom); return { id: s.id, slots: s.slots || 0, x: p[0], y: p[1] }; });
    const placed = [];               // 已排好的預覽中心（絕對像素）
    const out = new Map();
    pts.filter(p => p.slots > 0)
      .sort((a, b) => b.slots - a.slots || (String(a.id) < String(b.id) ? -1 : 1))
      .forEach(p => {
        const near = pts.filter(o => o !== p && Math.hypot(o.x - p.x, o.y - p.y) < r0 + 18 + size + pinMin);
        const base = hash(p.id) % 360;
        const list = [];
        for (let i = 0; i < p.slots; i++) {
          const pref = base + i * (360 / Math.max(p.slots, 3));
          let best = null;
          for (let k = 0; k < 360 / STEP; k++) {
            const angle = base + k * STEP, rad = angle * Math.PI / 180;
            RADIUS_STEPS.forEach((extra, ri) => {
              const r = r0 + extra, cx = p.x + Math.cos(rad) * r, cy = p.y + Math.sin(rad) * r;
              let cost = ri * 2 + angleGap(angle, pref) / STEP * 0.8;
              near.forEach(o => {
                const d = Math.hypot(o.x - cx, o.y - cy);
                if (d < pinMin) cost += (pinMin - d) * (pinMin - d) * 0.6 + 50;
                else if (d < pinMin + 14) cost += (pinMin + 14 - d) * 1.2;
                if (d < r + 6) cost += (r + 6 - d) * 2 + 20;                         // 要比任何別的點位都更靠自己的點位
                if (segmentDistance(o.x, o.y, p.x, p.y, cx, cy) < pinPx / 2 + 4) cost += 60;  // 連線不要穿過別人的點位
              });
              placed.forEach(q => {
                const d = Math.hypot(q.x - cx, q.y - cy);
                if (d < prevMin) cost += (prevMin - d) * (prevMin - d) * 0.8 + 80;
                else if (d < prevMin + 10) cost += (prevMin + 10 - d);
              });
              if (!best || cost < best.cost) best = { cost: cost, x: cx, y: cy };
            });
          }
          placed.push({ x: best.x, y: best.y });
          list.push({ x: best.x - p.x, y: best.y - p.y });
        }
        out.set(p.id, list);
      });
    return out;
  }

  const api = { place, project };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SouliongFeaturedLayout = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
