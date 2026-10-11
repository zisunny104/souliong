// 自動像素比（assets/js/engine/maplibre-engine.js 的 createPixelGovernor）：慢才降、暫停不算、有下限、不重複降。
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict');
const sandbox = { window: {}, sessionStorage: { getItem: () => null, setItem() {} }, console };
sandbox.window = sandbox;
vm.createContext(sandbox);
for (const file of ['map-engine.js', 'maplibre-engine.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/engine', file), 'utf8'), sandbox);
const { createPixelGovernor } = sandbox.MapLibreEngine;
assert.equal(typeof createPixelGovernor, 'function');
const feed = (gov, dt, n) => { let out = null; for (let i = 0; i < n; i++) out = gov.sample(dt) ?? out; return out; };

let gov = createPixelGovernor(2.46);
assert.equal(feed(gov, 16, 200), null, '流暢（16 ms）不降級');
assert.equal(feed(gov, 40, 100), null, '剛好在門檻上不降級');

gov = createPixelGovernor(2.46);
assert.equal(feed(gov, 60, 23), null, '樣本不足一個視窗不判斷');
assert.equal(gov.sample(60), 1.97, '慢（60 ms）降到 80%：2.46 → 1.97');
assert.equal(feed(gov, 60, 24), 1.58, '再慢再降一級');
assert.equal(feed(gov, 60, 24), 1.26, '繼續降');
assert.equal(feed(gov, 60, 24), 1.25, '不低於下限 1.25');
assert.equal(feed(gov, 60, 200), null, '到下限後不再降');

gov = createPixelGovernor(2);
assert.equal(feed(gov, 40000, 100), null, '40 秒的暫停間隔不算樣本');
assert.equal(feed(gov, 2001, 100), null, '超過 2 秒的間隔不算樣本');
assert.equal(feed(gov, 0, 100), null, '0 或負值不算樣本');
assert.equal(feed(gov, 500, 24), 1.6, '真的很慢（500 ms 畫格）仍會降級');

gov = createPixelGovernor(1.25);
assert.equal(feed(gov, 100, 100), null, '起點已在下限就不動');
console.log('PASS: 自動像素比（慢才降、暫停不算、下限、單次單級）');
