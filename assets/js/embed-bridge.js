/* 嵌入橋接（?embed=1&ui=bare 才載入）：父頁以 postMessage 控制鏡頭與標記，地圖頁回報事件。
   協定 { v:1, ns:'souliong', id, type, ... }；只接受 APP.embedOrigins 內來源的訊息（空清單＝全部拒絕），
   回覆一律指定來源，不用 "*"。鏡頭指令只支援 MapLibre 引擎，Leaflet 回 error:unsupported。
   viewer.core.js 只提供掛勾：engineReady／bootDone／spotClick 與 MapApp.embedOpts／applyTheme。 */
(function () {
  'use strict';

  const APP = window.APP || {};
  const PROTO = 1, NS = 'souliong';
  const MAX_DURATION = 10000, MAX_IDS = 500, MAX_QUEUE = 50, MAX_SNAP = 4096;

  class BridgeError extends Error {
    constructor(code, message) { super(message); this.code = code; }
  }
  const bad = (msg) => new BridgeError('bad_request', msg);
  const NOREPLY = Symbol('noreply');   // 指令自行負責回覆（場景這類等待型指令）

  const isNum = (v) => typeof v === 'number' && Number.isFinite(v);
  const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
  const EASINGS = {
    linear: (t) => t,
    ease: (t) => t * (2 - t),
    easeInOut: (t) => (t < .5 ? 2 * t * t : -1 + (4 - 2 * t) * t),
  };
  const COLOR_RE = /^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,%\s]{1,40}\)|[a-zA-Z]{3,20})$/;

  class EmbedBridge {
    constructor(app) {
      this.app = app;
      this.opts = app.embedOpts() || {};
      this.allowed = Array.isArray(APP.embedOrigins) ? APP.embedOrigins.filter((o) => typeof o === 'string' && o) : [];
      this.engine = null;
      this.map = null;
      this.bootDone = false;
      this.readySent = false;
      this.cameraTouched = false;
      this.home = null;
      this.cur = null;                 // 進行中的鏡頭動畫 { id }
      this.learned = '';               // 已驗證過的父頁來源
      this.queue = [];
      this.state = { hl: null, dim: null, markers: { mode: 'all', ids: null } };
      this.commands = new Map(Object.entries({
        getState: this.getState, getSpots: this.getSpots, resetView: this.resetView, setView: this.setView,
        flyTo: this.flyTo, zoomAbout: this.zoomAbout, fitSpots: this.fitSpots, highlight: this.highlight,
        dimOthers: this.dimOthers, setMarkers: this.setMarkers, setTheme: this.setTheme, snapshot: this.snapshot,
        scene: this.scene_, skip: this.skip, cancel: this.cancel, setLabels: this.setLabels, setLayer: this.setLayer,
      }));
      this.motion = new Set(['resetView', 'setView', 'flyTo', 'zoomAbout', 'fitSpots', 'scene', 'skip', 'cancel']);
      this.scene = null;               // 目前場景（進行中，或已抵達並維持終點）
      document.addEventListener('visibilitychange', () => this.onVisibility());

      window.addEventListener('message', (e) => this.onMessage(e));
      app.onHook('engineReady', (engine) => this.attach(engine));
      app.onHook('bootDone', () => { this.bootDone = true; this.maybeReady(); });
      app.onHook('spotClick', (spot) => this.onSpotClick(spot));
      const eng = app.getEngine();
      if (eng) this.attach(eng);
      setTimeout(() => { this.bootDone = true; this.maybeReady(); }, 10000);   // 保險：boot 卡住時仍回報 ready
    }

    /* ---------- 引擎就緒 ---------- */
    attach(engine) {
      if (this.engine) return;
      this.engine = engine;
      this.supported = engine.type === 'maplibre';
      this.map = this.supported ? engine.getRawMap() : null;
      this.watchMarkers();
      this.markCreditLinks();
      if (this.map) {
        const m = this.map;
        if (!this.opts.interactive) {
          ['scrollZoom', 'boxZoom', 'dragRotate', 'dragPan', 'keyboard', 'doubleClickZoom', 'touchZoomRotate', 'touchPitch']
            .forEach((h) => { if (m[h] && m[h].disable) m[h].disable(); });
        }
        this.labels = this.opts.labels !== false;
        if (!this.labels) this.applyLabels();
        m.on('style.load', () => this.applyLabels());   // setTheme 換樣式後重套
        if (this.opts.bg === 'transparent') {
          const hideBg = () => {
            const st = m.getStyle();
            ((st && st.layers) || []).forEach((l) => { if (l.type === 'background') m.setLayoutProperty(l.id, 'visibility', 'none'); });
          };
          if (m.isStyleLoaded()) hideBg();
          m.on('style.load', hideBg);
        }
        m.on('resize', () => this.onResize());
        m.on('idle', () => { this.maybeReady(); this.post('idle', this.cameraInfo()); });
        m.on('error', (ev) => {
          const err = ev && ev.error;
          if (ev && (ev.tile || ev.sourceId)) this.post('tile-error', { source: ev.sourceId || null, message: String((err && err.message) || 'tile error').slice(0, 200) });
        });
      }
      this.drain();
    }

    maybeReady() {
      if (this.readySent || !this.engine || !this.bootDone) return;
      if (this.map && !this.map.loaded()) return;
      this.readySent = true;
      if (!this.cameraTouched) this.home = this.cameraInfo();
      this.markCreditLinks();
      this.post('ready', {
        project: APP.project, view: this.cameraInfo(), spotCount: this.spots().length, engine: this.engine.type,
      });
    }

    /* ---------- 訊息收發 ---------- */
    originOk(origin) { return this.allowed.length > 0 && this.allowed.includes(origin); }

    onMessage(e) {
      if (!this.originOk(e.origin) || e.source !== window.parent) return;
      const d = e.data;
      if (!d || typeof d !== 'object' || d.ns !== NS || d.v !== PROTO || typeof d.type !== 'string') return;
      const id = (typeof d.id === 'string' || isNum(d.id)) && String(d.id).length <= 64 ? d.id : null;
      this.learned = e.origin;
      const job = { id, msg: d, origin: e.origin };
      if (!this.engine) {
        if (this.queue.length < MAX_QUEUE) this.queue.push(job);
        else this.reply(job, 'error', { code: 'busy', message: 'queue full' });
        return;
      }
      this.run(job);
    }

    drain() { const q = this.queue; this.queue = []; q.forEach((j) => this.run(j)); }

    async run(job) {
      const { msg } = job;
      const fn = this.commands.get(msg.type);
      if (!fn) return this.reply(job, 'error', { code: 'unknown_command', message: 'unknown command: ' + String(msg.type).slice(0, 40) });
      try {
        if (this.motion.has(msg.type) || msg.type === 'snapshot') this.needMapLibre();
        else if (!this.supported && !['getState', 'getSpots', 'setTheme'].includes(msg.type)) this.needMapLibre();
        const result = await fn.call(this, job, msg);
        if (result !== NOREPLY) this.reply(job, 'done', Object.assign({ state: job.state || 'completed' }, this.cameraInfo(), result === undefined ? {} : { result }));
      } catch (err) {
        if (err instanceof BridgeError) this.reply(job, 'error', { code: err.code, message: err.message });
        else {
          console.error('[embed-bridge]', err);
          this.reply(job, 'error', { code: 'internal', message: 'internal error' });
        }
      }
    }

    needMapLibre() {
      if (!this.supported) throw new BridgeError('unsupported', 'camera commands require the MapLibre engine');
    }

    send(target, payload) {
      try { window.parent.postMessage(Object.assign({ v: PROTO, ns: NS }, payload), target); } catch (err) { /* 來源不符時瀏覽器自行丟棄 */ }
    }
    reply(job, type, extra) {
      if (window.parent === window) return;
      this.send(job.origin, Object.assign({ id: job.id, type }, extra));
    }
    // 事件的目標來源：已驗證過的父頁 > ancestorOrigins > referrer > 逐一送往允許清單
    eventTargets() {
      if (this.learned) return [this.learned];
      const hit = [];
      try { const a = location.ancestorOrigins; if (a && a.length && this.originOk(a[0])) hit.push(a[0]); } catch (err) { /* ignore */ }
      if (!hit.length && document.referrer) {
        try { const o = new URL(document.referrer).origin; if (this.originOk(o)) hit.push(o); } catch (err) { /* ignore */ }
      }
      return hit.length ? hit : this.allowed;
    }
    post(type, extra) {
      if (window.parent === window || !this.allowed.length) return;
      this.eventTargets().forEach((o) => this.send(o, Object.assign({ type }, extra)));
    }

    onSpotClick(spot) {
      if (!this.opts.interactive || !spot) return;
      this.post('spot-click', { spotId: spot.id, num: spot.num });
    }

    /* ---------- 資料 ---------- */
    spots() { return this.app.effectiveSpots(); }
    findSpot(spotId) {
      if (typeof spotId !== 'string' || !spotId || spotId.length > 64) throw bad('spotId must be a string');
      const s = this.spots().find((p) => p.id === spotId);
      if (!s) throw new BridgeError('spot_not_found', 'spot not found');
      return s;
    }
    cameraInfo() {
      const m = this.map;
      if (m) { const c = m.getCenter(); return { center: [c.lat, c.lng], zoom: m.getZoom() }; }
      const c = this.engine.getCenter();
      return { center: [c.lat, c.lon], zoom: this.engine.getZoom() };
    }
    wh() { const c = this.map.getContainer(); return [c.clientWidth, c.clientHeight]; }

    /* ---------- 參數檢查 ---------- */
    latlon(v, name) {
      if (!Array.isArray(v) || v.length !== 2 || !isNum(v[0]) || !isNum(v[1]) || Math.abs(v[0]) > 90 || Math.abs(v[1]) > 180) {
        throw bad(name + ' must be [lat, lon]');
      }
      return [v[0], v[1]];
    }
    zoomOf(v, name) {
      if (!isNum(v)) throw bad(name + ' must be a number');
      return clamp(v, this.map.getMinZoom(), this.map.getMaxZoom());
    }
    duration(msg, fallback) {
      if (msg.duration == null) return this.reducedMotion(msg) ? 0 : fallback;
      if (!isNum(msg.duration)) throw bad('duration must be a number');
      return this.reducedMotion(msg) ? 0 : clamp(msg.duration, 0, MAX_DURATION);
    }
    reducedMotion(msg) {
      return msg.reducedMotion === true || !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    easing(msg) {
      if (msg.easing == null) return undefined;
      if (typeof msg.easing !== 'string' || !Object.prototype.hasOwnProperty.call(EASINGS, msg.easing)) throw bad('easing must be linear|ease|easeInOut');
      return EASINGS[msg.easing];
    }
    offsetPx(msg) {
      if (msg.offset == null) return undefined;
      const o = msg.offset;
      if (typeof o !== 'object' || !isNum(o.x == null ? 0 : o.x) || !isNum(o.y == null ? 0 : o.y)) throw bad('offset must be {x,y}');
      const [w, h] = this.wh();
      return [clamp(o.x || 0, -.5, .5) * w, clamp(o.y || 0, -.5, .5) * h];
    }
    ids(v) {
      if (!Array.isArray(v) || v.length > MAX_IDS || v.some((s) => typeof s !== 'string' || s.length > 64)) throw bad('spotIds must be an array of strings');
      return v;
    }

    // 中斷目前所有鏡頭動作（低階動畫與場景）；被中斷者回 done{state:'interrupted'}
    abort() {
      const prev = this.cur;
      this.cur = null;
      if (prev) this.replyDone(prev.job, 'interrupted', { interrupted: true });
      if (this.scene && !this.scene.finished) this.finishScene(this.scene, 'interrupted');
      this.scene = null;
      this.map.stop();
    }

    replyDone(job, state, extra) {
      this.reply(job, 'done', Object.assign({ state }, this.cameraInfo(), extra));
    }

    animate(job, duration, start) {
      const m = this.map;
      this.cameraTouched = true;
      this.abort();
      return new Promise((resolve) => {
        const tok = { job };
        let timer = 0;
        const finish = () => {
          m.off('moveend', finish);
          clearTimeout(timer);
          if (this.cur !== tok) return;
          this.cur = null;
          resolve(undefined);
        };
        this.cur = tok;
        m.on('moveend', finish);
        timer = setTimeout(finish, duration + 1500);
        start();
        if (duration === 0) finish();
      });
    }

    /* ---------- 場景：停留(hold) → 飛行(fly) → 發光＋淡化其他點；新場景取代舊場景 ---------- */
    sceneParams(msg) {
      const num = (v, name, lo, hi, dflt) => {
        if (v == null) return dflt;
        if (!isNum(v)) throw bad(name + ' must be a number');
        return clamp(v, lo, hi);
      };
      const p = { spotId: null };
      if (msg.spotId != null) p.spotId = this.findSpot(msg.spotId).id;
      p.hold = num(msg.hold, 'hold', 0, MAX_DURATION, 0);
      p.fly = this.reducedMotion(msg) ? 0 : num(msg.fly, 'fly', 0, MAX_DURATION, 1500);
      p.zoom = msg.zoom == null ? null : this.zoomOf(msg.zoom, 'zoom');
      const o = msg.offset;
      if (o != null && (typeof o !== 'object' || !isNum(o.x == null ? 0 : o.x) || !isNum(o.y == null ? 0 : o.y))) throw bad('offset must be {x,y}');
      p.offset = o ? { x: clamp(o.x || 0, -.5, .5), y: clamp(o.y || 0, -.5, .5) } : null;
      const g = msg.glow == null ? true : msg.glow;
      if (typeof g !== 'boolean' && typeof g !== 'string') throw bad('glow must be boolean or color');
      if (typeof g === 'string' && !COLOR_RE.test(g)) throw bad('invalid glow color');
      p.glow = p.spotId ? g : false;
      const d = msg.dim == null ? false : msg.dim;
      if (typeof d !== 'boolean' && !isNum(d)) throw bad('dim must be boolean or opacity');
      p.dim = p.spotId ? (d === true ? 0.3 : (isNum(d) ? clamp(d, 0, 1) : false)) : false;
      return p;
    }

    sceneTarget(sc) {
      const o = { zoom: sc.zoom, essential: true };
      if (sc.spot) {
        o.center = [sc.spot.lon, sc.spot.lat];
        if (sc.p.offset) { const [w, h] = this.wh(); o.offset = [sc.p.offset.x * w, sc.p.offset.y * h]; }
      }
      return o;
    }

    scene_(job, msg) {
      const p = this.sceneParams(msg);
      const key = JSON.stringify(p);
      const cur = this.scene;
      if (cur && cur.key === key) {
        if (!cur.finished) { cur.jobs.push(job); return NOREPLY; }
        if (cur.state === 'completed' || cur.state === 'skipped') { this.replyDone(job, 'completed'); return NOREPLY; }
      }
      this.cameraTouched = true;
      this.abort();
      this.state.hl = null; this.state.dim = null; this.applyState();
      const m = this.map;
      const spot = p.spotId ? this.findSpot(p.spotId) : null;
      const zoom = p.zoom != null ? p.zoom : (spot ? m.getZoom() : clamp(m.getZoom() + 1, m.getMinZoom(), m.getMaxZoom()));
      const sc = { key, p, spot, zoom, jobs: [job], finished: false, state: '', timer: 0, onEnd: null, t0: 0, dur: 0, paused: false };
      this.scene = sc;
      if (p.hold > 0) sc.timer = setTimeout(() => this.sceneFly(sc, sc.p.fly), p.hold);
      else this.sceneFly(sc, p.fly);
      return NOREPLY;
    }

    sceneDetach(sc) {
      clearTimeout(sc.timer);
      if (sc.onEnd) { this.map.off('moveend', sc.onEnd); sc.onEnd = null; }
    }

    sceneFly(sc, dur) {
      if (sc.finished) return;
      const m = this.map;
      this.sceneDetach(sc);
      sc.t0 = performance.now();
      sc.dur = dur;
      sc.onEnd = () => this.sceneArrive(sc, 'completed');
      m.on('moveend', sc.onEnd);
      sc.timer = setTimeout(() => this.sceneArrive(sc, 'completed'), dur + 1500);
      const o = Object.assign(this.sceneTarget(sc), { duration: dur });
      if (sc.spot && dur > 0) m.flyTo(o); else m.easeTo(o);
    }

    sceneArrive(sc, state) {
      if (sc.finished || sc.paused) return;
      this.sceneDetach(sc);
      const { p, spot } = sc;
      if (spot && p.glow) this.state.hl = { id: spot.id, pulse: true, color: typeof p.glow === 'string' ? p.glow : '' };
      if (spot && p.dim !== false) this.state.dim = { opacity: p.dim, scale: 1 };
      this.applyState();
      this.finishScene(sc, state);
    }

    finishScene(sc, state) {
      if (sc.finished) return;
      sc.finished = true;
      sc.state = state;
      this.sceneDetach(sc);
      const extra = state === 'interrupted' ? { interrupted: true } : {};
      sc.jobs.forEach((j) => this.replyDone(j, state, extra));
    }

    skip(job) {
      const sc = this.scene;
      if (sc && !sc.finished) {
        this.sceneDetach(sc);
        sc.paused = false;
        this.map.stop();
        this.map.easeTo(Object.assign(this.sceneTarget(sc), { duration: 0 }));
        this.sceneArrive(sc, 'skipped');
      }
      job.state = 'skipped';
    }

    cancel(job, msg) {
      const instant = msg.instant === true || this.reducedMotion(msg);
      this.abort();
      this.state.hl = null; this.state.dim = null; this.applyState();
      const h = this.home || this.cameraInfo();
      job.state = 'cancelled';
      const dur = instant ? 0 : 800;
      return this.animate(job, dur, () => this.map.easeTo({ center: [h.center[1], h.center[0]], zoom: h.zoom, bearing: 0, pitch: 0, duration: dur, essential: true }));
    }

    onVisibility() {
      const sc = this.scene;
      if (!this.map || !sc || sc.finished) return;
      if (document.visibilityState === 'hidden') {
        sc.paused = true;
        this.sceneDetach(sc);
        this.map.stop();
      } else if (sc.paused) {
        sc.paused = false;
        this.map.easeTo(Object.assign(this.sceneTarget(sc), { duration: 0 }));
        this.sceneArrive(sc, 'completed');
      }
    }

    onResize() {
      const sc = this.scene;
      if (!sc || !sc.spot || !sc.p.offset || sc.paused) return;
      if (sc.finished) {
        if (sc.state === 'completed' || sc.state === 'skipped') this.map.easeTo(Object.assign(this.sceneTarget(sc), { duration: 0 }));
      } else if (sc.onEnd) {
        const left = Math.max(0, sc.dur - (performance.now() - sc.t0));
        this.sceneDetach(sc);
        this.map.stop();
        this.sceneFly(sc, left);
      }
    }

    getState() {
      const m = this.map;
      const base = Object.assign({
        project: APP.project, engine: this.engine.type, theme: document.documentElement.dataset.theme || 'auto',
        spotCount: this.spots().length, highlight: this.state.hl && this.state.hl.id, markers: this.state.markers.mode,
        dimmed: !!this.state.dim,
      }, this.cameraInfo());
      if (m) {
        const b = m.getBounds();
        Object.assign(base, {
          bearing: m.getBearing(), pitch: m.getPitch(), minZoom: m.getMinZoom(), maxZoom: m.getMaxZoom(),
          bounds: [[b.getSouth(), b.getWest()], [b.getNorth(), b.getEast()]], moving: !!this.cur,
        });
      }
      return base;
    }

    async getSpots() {
      try {
        const url = APP.base + '?api=spots&project=' + encodeURIComponent(APP.project);
        const res = await fetch(url, { credentials: 'omit' });
        if (res.ok) return await res.json();
      } catch (err) { /* 退回本地資料 */ }
      return {
        v: 1, project: APP.project,
        spots: this.spots().map((p) => ({ spotId: p.id, num: p.num, title: this.app.spotTitle(p), area: p.area || '', cat: p.cat, lat: p.lat, lon: p.lon })),
      };
    }

    resetView(job, msg) {
      const dur = this.duration(msg, 800);
      const h = this.home || this.cameraInfo();
      return this.animate(job, dur, () => this.map.easeTo({ center: [h.center[1], h.center[0]], zoom: h.zoom, bearing: 0, pitch: 0, duration: dur, easing: this.easing(msg), essential: true }));
    }

    setView(job, msg) {
      if (msg.center == null && msg.zoom == null && msg.bearing == null && msg.pitch == null) throw bad('center or zoom required');
      const o = { essential: true };
      if (msg.center != null) { const c = this.latlon(msg.center, 'center'); o.center = [c[1], c[0]]; }
      if (msg.zoom != null) o.zoom = this.zoomOf(msg.zoom, 'zoom');
      if (msg.bearing != null) { if (!isNum(msg.bearing)) throw bad('bearing must be a number'); o.bearing = clamp(msg.bearing, -180, 180); }
      if (msg.pitch != null) { if (!isNum(msg.pitch)) throw bad('pitch must be a number'); o.pitch = clamp(msg.pitch, 0, 60); }
      o.duration = this.duration(msg, 800);
      o.easing = this.easing(msg);
      return this.animate(job, o.duration, () => this.map.easeTo(o));
    }

    flyTo(job, msg) {
      let center;
      if (msg.spotId != null) { const s = this.findSpot(msg.spotId); center = [s.lat, s.lon]; }
      else center = this.latlon(msg.center, 'center');
      const o = { center: [center[1], center[0]], essential: true, duration: this.duration(msg, 1500) };
      if (msg.zoom != null) o.zoom = this.zoomOf(msg.zoom, 'zoom');
      const off = this.offsetPx(msg);
      if (off) o.offset = off;
      if (msg.curve != null) { if (!isNum(msg.curve)) throw bad('curve must be a number'); o.curve = clamp(msg.curve, .1, 3); }
      if (o.duration === 0) { o.easing = EASINGS.linear; return this.animate(job, 0, () => this.map.easeTo(o)); }
      return this.animate(job, o.duration, () => this.map.flyTo(o));
    }

    zoomAbout(job, msg) {
      const m = this.map;
      let zoom;
      if (msg.to != null) zoom = this.zoomOf(msg.to, 'to');
      else if (msg.by != null) { if (!isNum(msg.by)) throw bad('by must be a number'); zoom = this.zoomOf(m.getZoom() + msg.by, 'by'); }
      else throw bad('by or to required');
      const o = { zoom, essential: true, duration: this.duration(msg, 600), easing: this.easing(msg) };
      const a = msg.anchor;
      if (a != null && a !== 'view-center') {
        if (typeof a !== 'object' || !isNum(a.x) || !isNum(a.y)) throw bad('anchor must be "view-center" or {x,y}');
        const [w, h] = this.wh();
        o.around = m.unproject([w / 2 + clamp(a.x, -.5, .5) * w, h / 2 + clamp(a.y, -.5, .5) * h]);
      }
      return this.animate(job, o.duration, () => m.easeTo(o));
    }

    fitSpots(job, msg) {
      const all = this.spots();
      const list = msg.spotIds != null ? this.ids(msg.spotIds).map((id) => this.findSpot(id)) : all;
      if (!list.length) throw new BridgeError('spot_not_found', 'no spots to fit');
      let w = Infinity, s = Infinity, e = -Infinity, n = -Infinity;
      list.forEach((p) => { w = Math.min(w, p.lon); e = Math.max(e, p.lon); s = Math.min(s, p.lat); n = Math.max(n, p.lat); });
      const [cw, ch] = this.wh();
      const pad = msg.padding == null ? 40 : (isNum(msg.padding) ? clamp(msg.padding, 0, Math.min(cw, ch) / 2 - 1) : (() => { throw bad('padding must be a number'); })());
      const dur = this.duration(msg, 1000);
      return this.animate(job, dur, () => this.map.fitBounds([[w, s], [e, n]], { padding: pad, duration: dur, essential: true, maxZoom: Math.min(this.map.getMaxZoom(), 18) }));
    }

    /* ---------- 標記狀態（以 data-sid 操作 DOM，re-render 後自動重套） ---------- */
    pins() { return document.querySelectorAll('#map .dot-pin[data-sid]'); }
    watchMarkers() {
      const host = document.getElementById('map');
      if (!host || typeof MutationObserver === 'undefined') return;
      let pending = false;
      new MutationObserver(() => {
        if (pending) return;
        pending = true;
        requestAnimationFrame(() => { pending = false; this.applyState(); });
      }).observe(host, { childList: true, subtree: true });
    }
    applyState() {
      const { hl, dim, markers } = this.state;
      const keep = hl ? hl.id : null;
      const only = markers.ids ? new Set(markers.ids) : null;
      this.pins().forEach((el) => {
        const sid = el.dataset.sid;
        const isHl = !!hl && sid === hl.id;
        el.classList.toggle('is-highlight', isHl);
        el.classList.toggle('eb-still', isHl && !hl.pulse);
        if (isHl && hl.color) el.style.setProperty('--pulse-color', hl.color); else el.style.removeProperty('--pulse-color');
        const dimmed = !!dim && sid !== keep;
        el.classList.toggle('eb-dim', dimmed);
        if (dimmed) { el.style.setProperty('--eb-dim-o', String(dim.opacity)); el.style.setProperty('--eb-dim-s', String(dim.scale)); }
        else { el.style.removeProperty('--eb-dim-o'); el.style.removeProperty('--eb-dim-s'); }
        const hidden = markers.mode === 'none' || (markers.mode === 'only' && !only.has(sid));
        el.classList.toggle('eb-hide', hidden);
        el.classList.toggle('eb-dot', markers.mode === 'dots');
      });
    }

    highlight(job, msg) {
      if (msg.spotId == null) { this.state.hl = null; this.applyState(); return; }
      const s = this.findSpot(msg.spotId);
      if (msg.style != null && msg.style !== 'glow') throw bad('style must be "glow"');
      let color = '';
      if (msg.color != null) { if (typeof msg.color !== 'string' || !COLOR_RE.test(msg.color)) throw bad('invalid color'); color = msg.color; }
      this.state.hl = { id: s.id, pulse: msg.pulse !== false, color };
      this.applyState();
    }

    dimOthers(job, msg) {
      if (!isNum(msg.opacity)) throw bad('opacity must be a number');
      const opacity = clamp(msg.opacity, 0, 1);
      let scale = 1;
      if (msg.scale != null) { if (!isNum(msg.scale)) throw bad('scale must be a number'); scale = clamp(msg.scale, .2, 2); }
      this.state.dim = (opacity === 1 && scale === 1) ? null : { opacity, scale };
      this.applyState();
    }

    setMarkers(job, msg) {
      if (!['all', 'dots', 'none', 'only'].includes(msg.mode)) throw bad('mode must be all|dots|none|only');
      let ids = null;
      if (msg.mode === 'only') { ids = this.ids(msg.spotIds); }
      this.state.markers = { mode: msg.mode, ids };
      this.applyState();
    }

    // 底圖樣式自帶的 symbol 圖層（引擎 hideBaseLabels 已排除 sl-／m3d- 疊圖）；點位標記是 DOM，不受影響
    applyLabels() {
      if (this.labels || !this.map) return;
      const m = this.map;
      ((m.getStyle() || {}).layers || []).forEach((l) => {
        if (l.type === 'symbol' && !/^(sl-|m3d-)/.test(l.id)) m.setLayoutProperty(l.id, 'visibility', 'none');
      });
    }

    setLabels(job, msg) {
      if (typeof msg.visible !== 'boolean') throw bad('visible must be boolean');
      this.labels = msg.visible;
      const m = this.map;
      if (msg.visible) {
        ((m.getStyle() || {}).layers || []).forEach((l) => {
          if (l.type === 'symbol' && !/^(sl-|m3d-)/.test(l.id)) m.setLayoutProperty(l.id, 'visibility', 'visible');
        });
      } else this.applyLabels();
    }

    // 換底圖：只能在專案已啟用的底圖之間切換（頁面載入後引擎不重建，因此以重新載入該樣式實現）
    setLayer(job, msg) {
      const all = Array.isArray(APP.layers) ? APP.layers : [];
      if (typeof msg.layer !== 'string') throw bad('layer must be a string');
      const want = all.find((l) => l.id === msg.layer && (l.pane || 'art') === 'base' && l.type === 'vector');
      if (!want) throw new BridgeError('layer_not_found', 'layer not enabled for this project');
      const dark = document.documentElement.dataset.theme === 'dark' ||
        (!document.documentElement.dataset.theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      const url = (dark && want.urlDark) || want.url;
      if (!url) throw new BridgeError('layer_not_found', 'layer has no style');
      this.engine._baseManifest = want;
      this.map.once('style.load', () => this.engine._mountOverlays());
      this.map.setStyle(url);
    }

    setTheme(job, msg) {
      if (!['light', 'dark', 'auto'].includes(msg.theme)) throw bad('theme must be light|dark|auto');
      this.app.applyTheme(msg.theme === 'auto' ? 'system' : msg.theme);
    }

    async snapshot(job, msg) {
      const mime = msg.mime == null ? 'image/png' : msg.mime;
      if (!['image/png', 'image/jpeg', 'image/webp'].includes(mime)) throw bad('mime must be image/png|image/jpeg|image/webp');
      const dim = (v, name) => { if (v == null) return 0; if (!isNum(v) || v < 1) throw bad(name + ' must be a positive number'); return Math.min(MAX_SNAP, Math.round(v)); };
      const w = dim(msg.width, 'width'), h = dim(msg.height, 'height');
      const url = this.engine.getCanvasDataURL(mime, 0.92);
      if (!url) throw new BridgeError('snapshot_failed', 'canvas unavailable (tainted or not ready)');
      if (!w && !h) {
        const c = this.map.getCanvas();
        return { dataUrl: url, width: c.width, height: c.height, mime };
      }
      const img = await new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => no(new Error('decode')); i.src = url; });
      const ow = w || Math.round(img.width * h / img.height), oh = h || Math.round(img.height * w / img.width);
      const cv = document.createElement('canvas');
      cv.width = ow; cv.height = oh;
      cv.getContext('2d').drawImage(img, 0, 0, ow, oh);
      return { dataUrl: cv.toDataURL(mime, 0.92), width: ow, height: oh, mime };
    }

    /* ---------- 版權連結一律開新分頁（沒有離站確認框） ---------- */
    markCreditLinks() {
      document.querySelectorAll('.cr-bar a, .leaflet-control-attribution a').forEach((a) => { a.target = '_blank'; a.rel = 'noopener'; });
    }
  }

  const app = window.MapApp;
  if (!app || !app.embedOpts || !app.embedOpts()) return;
  new EmbedBridge(app);
})();
