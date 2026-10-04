// Regression checks for neutral uncategorized markers, overrides and CSS input validation.
const assert = require('node:assert/strict');
const colors = require('../assets/js/marker-colors.js');
const cases = [
  ['missing category stays neutral despite legacy blue', () => colors.spot({}, { color: '#0000ff' }), '#7a7f87'],
  ['default new category stays neutral', () => colors.spot({}, { cat: 'new', color: '#0000ff' }), '#7a7f87'],
  ['existing classified color is preserved', () => colors.spot({}, { cat: 'park', color: '#0000ff' }), '#0000ff'],
  ['project category color replaces stored display color', () => colors.spot({ categoryColors: { park: '#AABBCC' } }, { cat: 'park', color: '#0000ff' }), '#aabbcc'],
  ['invalid override cannot inject CSS', () => colors.spot({ categoryColors: { park: 'red;display:none' } }, { cat: 'park', color: '#0000ff' }), '#0000ff'],
  ['invalid stored color falls back to gray', () => colors.spot({}, { cat: 'park', color: '<script>' }), '#7a7f87'],
  ['badge defaults to red', () => colors.badge({}), '#c0392b'],
  ['project badge override applies', () => colors.badge({ badgeColor: '#123456' }), '#123456'],
  ['contributor filtering retains its color', () => colors.badge({ badgeColor: '#123456' }, '#abcdef'), '#abcdef'],
  ['invalid badge setting falls back safely', () => colors.badge({ badgeColor: 'url(evil)' }), '#c0392b'],
];
for (const [name, run, expected] of cases) {
  assert.equal(run(), expected, name);
}
console.log(`markercolorcheck: ${cases.length} passed`);
