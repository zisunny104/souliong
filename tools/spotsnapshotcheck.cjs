// Execute the actual viewer data/update functions with isolated render hooks.
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/js/viewer.core.js'), 'utf8');
const sandbox = { module: { exports: {} } };
vm.runInNewContext(fs.readFileSync(path.join(root, 'assets/js/marker-colors.js'), 'utf8'), sandbox);
let scans = 0;
function traced(rows) {
  rows.forEach = function (callback) { scans++; return Array.prototype.forEach.call(this, callback); };
  return rows;
}
const initial = [{ id:'s2', kind:'spot', num:2, cat:'park', color:'#010203', lat:24, lon:120, content:[] }, { id:'s1', kind:'spot', num:1, cat:'park', lat:25, lon:121, content:[] }];
const context = { APP:{ spotFields:['lat','lon','content','links','markerColor'] }, META:{categoryColors:{park:'#223344'}}, CONTRIB:traced(initial), SouliongMarkerColors:sandbox.module.exports };
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('  const SPOT_FIELDS ='), source.indexOf('  /* ---------- map ---------- */')), context);
const observed = [];
for (const name of ['rebuildCats','buildLegend','recount','renderSpots','renderContribLayer','rebuildPersonFilter']) {
  context[name] = () => {
    const points = context.effectiveSpots();
    observed.push(points[0]?.num);
    points.sort((a,b)=>a.num-b.num);
    context.effectiveSpots();
  };
}
context.emitHook = () => {};
context.renderEntries = () => {};
vm.runInContext(source.slice(source.indexOf('  function refreshMapAndFilters()'), source.indexOf('  // 「投稿」鈕顯示投稿總則數')), context);
context.refreshAll();
assert.equal(scans, 1, 'one update parses contribution history once despite repeated consumers');
assert.ok(observed.every(n=>n===2), 'sorting one caller’s list does not reorder the shared snapshot');
context.CONTRIB.push({id:'edit', kind:'spot', edit_of:'s2', markerColor:'#aabbcc', lat:26, created_at:'2026-10-26'});
context.refreshAll();
assert.equal(scans,2,'a subsequent update rebuilds after edits');
assert.equal(context.effectiveSpots()[0].color,'#aabbcc');
assert.equal(context.effectiveSpots()[0].origLat,24);
assert.equal(context.effectiveSpots()[0].lat,26);
context.META.categoryColors.park='#556677';
assert.equal(context.effectiveSpots()[1].color,'#556677','category changes between updates remain visible');
context.CONTRIB=traced(context.CONTRIB.filter(x=>x.id!=='s1'));
assert.equal(context.effectiveSpots().length,1,'deletion/reload does not reuse the prior array');
const before=scans;
assert.throws(()=>context.withSpotSnapshot(()=>{context.effectiveSpots();throw new Error('render failed');}),/render failed/);
context.effectiveSpots();
assert.equal(scans,before+2,'an exceptional render also releases the snapshot');
context.emitHook=()=>context.CONTRIB.push({id:'s3',kind:'spot',num:3,lat:24,lon:120});
context.renderEntries=()=>assert.equal(context.effectiveSpots().length,2,'plugin mutations are observed after stateChange');
context.refreshAll();
console.log('PASS: single-update spot snapshot, caller sorting, edits, category changes, deletion, exception cleanup and plugin changes');
