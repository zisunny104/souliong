'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ctx = { window: { APP: {base: '/'}, I18N: {evil: '<img src=x onerror=alert(1)>'} } };
vm.runInNewContext(fs.readFileSync('assets/js/engine/map-engine.js', 'utf8'), ctx);
const credit = attribution => ctx.window.MapEngine.buildCredit([{attribution}], null);
for (const attribution of ['<img src=x onerror=alert(1)>', '{evil}', [{text:'ok',suffix:'{evil}'}]]) {
  const html = credit(attribution);
  assert(!html.includes('<img'), html);
  assert(html.includes('&lt;img'), html);
}
for (const url of ['javascript:alert(1)', 'data:text/html,x', '//evil.test', ' https://example.test', 'https://example.test\n']) {
  assert(!credit([{text:'url',url}]).includes('href="'+url+'"'));
  assert(!credit([{text:'url',url}]).includes('>url</a>'));
}
assert(credit([{text:'OSM',url:'https://example.test/copyright?a=1&b=2',copyright:true,suffix:'contributors'}]).includes('<a href="https://example.test/copyright?a=1&amp;b=2"'));
assert(credit('© OSM').includes('© OSM'));
console.log('securitycheck.js：署名 HTML／翻譯／URL／正常連結全部通過');
