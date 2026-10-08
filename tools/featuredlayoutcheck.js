// Regression checks for featured preview placement around crowded spots.
const assert = require('node:assert/strict');
const layout = require('../assets/js/featured-layout.js');
const zoom = 16, size = 30, pinPx = 24;

function makeSpots(stepLat, stepLon) {
  const spots = [];
  for (let i = 0; i < 14; i++) {
    spots.push({ id: 's' + i, lat: 23.9 + (i % 4) * stepLat + ((i * 7) % 5 - 2) * stepLat * 0.05, lon: 120.7 + Math.floor(i / 4) * stepLon + ((i * 3) % 5 - 2) * stepLon * 0.05, slots: i % 2 === 0 ? (i % 4 === 0 ? 3 : 1) : 0 });
  }
  return spots;
}
function measure(spots, offsetsOf) {
  const px = new Map(spots.map(s => [s.id, layout.project(s.lat, s.lon, zoom)]));
  const previews = [];
  spots.forEach(s => (offsetsOf(s) || []).forEach(o => previews.push({ id: s.id, x: px.get(s.id)[0] + o.x, y: px.get(s.id)[1] + o.y, o })));
  let farther = 0, covers = 0, overlap = 0;
  previews.forEach((p, i) => {
    const own = Math.hypot(p.o.x, p.o.y);
    spots.forEach(s => {
      if (s.id === p.id) return;
      const d = Math.hypot(px.get(s.id)[0] - p.x, px.get(s.id)[1] - p.y);
      if (d < own) farther++;               // 比自己的點位更靠近別人的點位：看不出是誰的
      if (d < pinPx / 2 + size / 2) covers++;  // 蓋住別人的點位標記
    });
    previews.slice(i + 1).forEach(q => { if (Math.hypot(q.x - p.x, q.y - p.y) < size) overlap++; });
  });
  return { previews, farther, covers, overlap };
}
// 舊做法：固定四個角度與半徑
const fixed = [-35, 140, -140, 45], oldRadius = (pinPx / 2 + size / 2 + 8) * Math.SQRT2;
const oldOffsets = s => Array.from({ length: s.slots }, (_, i) => ({ x: Math.cos(fixed[i] * Math.PI / 180) * oldRadius, y: Math.sin(fixed[i] * Math.PI / 180) * oldRadius }));

// 一般密度（點位約 90 px）：全部要做到
let spots = makeSpots(0.0009, 0.001);
let out = layout.place(spots, zoom, { pinPx, size });
let result = measure(spots, s => out.get(s.id));
assert.equal(result.farther, 0, 'every preview is closer to its own spot than to any other spot');
assert.equal(result.covers, 0, 'no preview covers another spot marker');
assert.equal(result.overlap, 0, 'previews do not overlap each other');
const angles = new Set(result.previews.map(p => Math.round(Math.atan2(p.o.y, p.o.x) * 180 / Math.PI / 15)));
assert.ok(angles.size >= 4, 'angles are staggered, not one fixed pattern: ' + angles.size);

// 很擠的街區（點位約 46 px）：不保證完美，但一定比固定角度少出問題
spots = makeSpots(0.00045, 0.00055);
out = layout.place(spots, zoom, { pinPx, size });
const crowded = measure(spots, s => out.get(s.id)), old = measure(spots, oldOffsets);
assert.ok(crowded.farther < old.farther && crowded.covers <= old.covers && crowded.overlap <= old.overlap, JSON.stringify({ crowded: [crowded.farther, crowded.covers, crowded.overlap], old: [old.farther, old.covers, old.overlap] }));
console.log(`featuredlayoutcheck: ${result.previews.length} previews, ${angles.size} distinct angles; crowded ${crowded.farther}/${crowded.covers}/${crowded.overlap} vs old ${old.farther}/${old.covers}/${old.overlap}, passed`);
