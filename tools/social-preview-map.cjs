#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const { createRequire } = require('node:module');

async function main() {
  const config = JSON.parse(fs.readFileSync(0, 'utf8'));
  const trusted = new URL(config.url);
  const allowed = new Set([
    'unpkg.com', 'tiles.openfreemap.org', 'wmts.nlsc.gov.tw',
    'a.basemaps.cartocdn.com', 'b.basemaps.cartocdn.com',
    'c.basemaps.cartocdn.com', 'd.basemaps.cartocdn.com',
    ...(config.allowedHosts || []),
  ]);
  const requireRuntime = createRequire(config.playwright + '/package.json');
  const { chromium } = requireRuntime(config.playwright);
  let proxy;
  const proxyUrl = process.env.HTTPS_PROXY || process.env.HTTP_PROXY;
  if (proxyUrl) {
    const parsed = new URL(proxyUrl);
    proxy = { server: parsed.origin, bypass: 'localhost,127.0.0.1,[::1]' };
    if (parsed.username) proxy.username = decodeURIComponent(parsed.username);
    if (parsed.password) proxy.password = decodeURIComponent(parsed.password);
  }
  const browser = await chromium.launch({
    executablePath: config.chromium,
    headless: true,
    args: ['--disable-dev-shm-usage', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
    proxy,
  });
  process.once('SIGTERM', async () => { await browser.close(); process.exit(1); });
  try {
    const context = await browser.newContext({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1, colorScheme: 'light', serviceWorkers: 'block' });
    await context.route('**/*', async route => {
      const request = route.request();
      let url;
      try { url = new URL(request.url()); } catch (_) { return route.abort(); }
      if (!['http:', 'https:'].includes(url.protocol) || !['GET', 'HEAD'].includes(request.method())) return route.abort();
      if (url.username || url.password || (url.origin !== trusted.origin && (!allowed.has(url.hostname) || url.protocol !== 'https:' || (url.port && url.port !== '443')))) return route.abort();
      if (request.resourceType() === 'font') return route.abort();
      return route.continue();
    });
    const page = await context.newPage();
    if (config.debug) {
      page.on('pageerror', error => process.stderr.write(error.message + '\n'));
      page.on('requestfailed', request => { if (request.resourceType() !== 'font') process.stderr.write('Resource failed: ' + new URL(request.url()).hostname + ' ' + request.failure()?.errorText + '\n'); });
    }
    page.setDefaultTimeout(9000);
    await page.goto(config.url, { waitUntil: 'domcontentloaded', timeout: 9000 });
    await page.waitForFunction(() => window.MapApp?.getEngine()?.getRawMap());
    if (config.spot) await page.waitForFunction(id => MapApp.effectiveSpots().some(spot => String(spot.id) === id), config.spot);
    await page.addStyleTag({ content: '.maplibregl-control-container,.ctrl-card,#panel,.page-overlay { display:none!important; } * { animation:none!important; transition:none!important; }' });
    await page.evaluate(async config => {
      const engine = MapApp.getEngine(), map = engine.getRawMap();
      const errors = [];
      map.on('error', event => errors.push(event.error?.message || 'Map source error'));
      const spot = config.spot ? MapApp.effectiveSpots().find(s => String(s.id) === config.spot) : null;
      if (config.spot && !spot) throw new Error('Linked point is unavailable');
      const center = spot ? [spot.lon, spot.lat] : map.getCenter();
      map.jumpTo({ center, zoom: config.zoom, bearing: 0, pitch: 0 });
      const point = map.project(center);
      map.panBy([point.x - 300, point.y - 315], { duration: 0 });
      MapApp.setDisplay({ spots: true, contributions: false });
      const specs = spot ? MapApp.spotMarkerSpecs().filter(s => s.id === spot.num) : [];
      engine.setMarkerLayer('spots', specs);
      engine.clearMarkerLayer('contrib');
      await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Map tiles did not finish loading')), 8000);
        const done = () => { clearTimeout(timer); resolve(); };
        if (map.isStyleLoaded() && map.areTilesLoaded()) done(); else map.once('idle', done);
      });
      if (!map.isStyleLoaded() || !map.areTilesLoaded() || errors.length) throw new Error('Map tiles are incomplete');
      if (spot) {
        const location = map.project([spot.lon, spot.lat]);
        if (Math.abs(location.x - 300) > 2 || Math.abs(location.y - 315) > 2) throw new Error('Point projection mismatch');
      }
      await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    }, config);
    const png = await page.locator('#map').screenshot({ type: 'png', timeout: 1000 });
    process.stdout.write(png);
  } finally { await browser.close(); }
}
main().catch(error => { process.stderr.write('Social preview map unavailable: ' + error.message + '\n'); process.exitCode = 1; });
