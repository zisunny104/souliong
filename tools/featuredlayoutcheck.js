// Regression checks for featured previews: crowded maps collapse instead of covering markers.
const assert = require('node:assert/strict');
// Evaluate the browser UMD module directly so a parent package's type:module does not change this test.
const source = require('node:fs').readFileSync(require('node:path').join(__dirname, '../assets/js/featured-layout.js'), 'utf8');
const sandbox = { module: { exports: {} } };
require('node:vm').runInNewContext(source, sandbox);
const layout = sandbox.module.exports;
const pinPx = 24;

function makeSpots(step) {
  return Array.from({ length: 16 }, (_, i) => ({
    id: 's' + i,
    lat: 23.9 + (i % 4) * step,
    lon: 120.7 + Math.floor(i / 4) * step,
    slots: 3
  }));
}
function intersects(a, aSize, b, bSize) {
  const half = (aSize + bSize) / 2;
  return Math.abs(a.x - b.x) < half - 0.000001 && Math.abs(a.y - b.y) < half - 0.000001;
}
function check(spots, zoom, size, label) {
  const out = layout.place(spots, zoom, { pinPx, size });
  const points = spots.map(s => {
    const p = layout.project(s.lat, s.lon, zoom);
    return { ...s, x: p[0], y: p[1] };
  });
  const previews = [];
  points.forEach(p => {
    const offsets = out.get(p.id) || [];
    assert.ok(offsets.length <= p.slots, label + ': preview count respects requested slots');
    offsets.forEach(o => {
      assert.ok(Number.isFinite(o.x) && Number.isFinite(o.y), label + ': finite offsets');
      previews.push({ id: p.id, x: p.x + o.x, y: p.y + o.y });
    });
  });
  previews.forEach((p, i) => {
    points.forEach(q => assert.ok(!intersects(p, size, q, pinPx), label + ': preview does not cover marker ' + q.id));
    previews.slice(i + 1).forEach(q => assert.ok(!intersects(p, size, q, size), label + ': previews do not overlap'));
  });
  assert.deepEqual(Array.from(layout.place(spots, zoom, { pinPx, size })), Array.from(out), label + ': identical input gives stable positions');
  return previews.length;
}

const results = [];
for (const size of [14, 22, 32]) {
  const ordinary = makeSpots(0.0015);
  const normal = check(ordinary, 16, size, size + 'px ordinary');
  assert.ok(normal > 0, 'ordinary map displays previews');
  const dense = makeSpots(0.0002);
  const crowded = check(dense, 16, size, size + 'px dense');
  assert.ok(crowded < dense.reduce((n, s) => n + s.slots, 0), 'dense map collapses previews instead of forcing all slots');
  const expanded = check(dense, 19, size, size + 'px zoomed dense');
  assert.ok(expanded > crowded, 'zooming in makes room for more previews');
  const samePosition = ordinary.map(s => ({ ...s, lat: 23.9, lon: 120.7 }));
  const coincident = check(samePosition, 16, size, size + 'px coincident');
  assert.ok(coincident < samePosition.length * 3, 'coincident spots do not force every preview onto the same point');
  check([{ id: 'marker-only', lat: 23.9, lon: 120.7, slots: 0 }], 16, size, size + 'px marker only');
  results.push(size + 'px ordinary/dense/zoomed/coincident: ' + [normal, crowded, expanded, coincident].join('/'));
}
console.log('featuredlayoutcheck: ' + results.join('; ') + '; rectangle collision and deterministic placement passed');
