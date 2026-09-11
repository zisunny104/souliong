/* 通用地圖檢視器 —— 由 ?p=<project> 載入 projects/<project>/meta.json 與點位資料。
   後端：api/list.php、api/upload.php（純 PHP，append-only）。
   地圖繪製透過 assets/js/engine/ 底下的 MapEngine 抽象層（見 map-engine.js）：這個檔案跟所有
   plugin 一律只呼叫 engine.* / MapApp.getEngine().*，不直接認得 Leaflet 或 MapLibre 的 API。 */
window.MapApp = (() => {
  const APP = window.APP || { base: './', project: 'chairs' };
  const I18N = window.I18N || {};
  // 翻譯輔助：key 缺就直接顯示 key 本身（不會整段消失，方便發現漏翻）
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const params = new URLSearchParams(location.search);
  const PROJECT = (APP.project || params.get('p') || 'chairs').replace(/[^a-z0-9_-]/gi, '');
  const EMBED = !!(APP.embed) || params.get('embed') === '1';
  const apiUrl = (action) => APP.base + '?api=' + action;
  // 後台網址由伺服器端的 Route::manager() 算好塞進 APP.manager；前端不自己拼路徑，
  // 後台網址形狀要改時只動 api/routes.php 一個檔案。
  const MANAGER_URL = APP.manager || '';
  const catOrder = ['green', 'pink', 'blue'];

  // 這張地圖的投稿設定（由 view.php 依 souliong_contrib_cfg() 算好塞進 APP.contrib）。
  // 舊部署或獨立部署可能沒有這個欄位，退回「只有照片、不能建點」——跟加入多型別之前一樣。
  const CONTRIB_CFG = Object.assign({ kinds: ['photo'], tabs: ['media'], default: 'media', newPoint: 'off', primaryKind: '' }, APP.contrib || {});

  // 投稿型別在**呈現端**的中繼資料。刻意跟 api/features.php 的註冊表分開：那份管的是
  // 「怎麼收檔案」（受 upload 模組控制、關掉就整套不存在），這份管的是「怎麼顯示」——
  // 唯讀地圖沒有投稿外掛，照樣要畫得出別人投的影片與音訊，所以必須留在核心。
  //   icon    卡片牆與地圖標記用的 Font Awesome 圖示
  //   layer   是否畫進地圖的投稿圖層
  //   box     卡片牆的預覽區長相：image｜video｜audio｜text
  const KINDS = {
    photo: { icon: 'fa-image',            layer: true,  box: 'image' },
    video: { icon: 'fa-film',             layer: true,  box: 'video' },
    audio: { icon: 'fa-microphone-lines', layer: true,  box: 'audio' },
    // 文字投稿不進地圖圖層：它沒有自己的座標（見 contrib/kind-text.js 的 needsLocation()），
    // 只會出現在所屬地點的投稿牆上。
    text:  { icon: 'fa-align-left',       layer: false, box: 'text'  },
  };
  // 主要內容型別（如聲音地圖的錄音）：這個型別不進投稿牆，而是取代故事文字、直接當成
  // 點位本身的主要內容顯示（見 renderEntries() 的 .story 區塊），比照 desc 的角色但換成媒體型別。
  const PRIMARY_KIND = CONTRIB_CFG.primaryKind || '';
  const PRIMARY_BOX = PRIMARY_KIND && KINDS[PRIMARY_KIND] ? KINDS[PRIMARY_KIND].box : null;
  // 沒有 kind 的舊記錄一律當照片（多型別上線前所有投稿都是照片）
  const kindOf = (e) => (e && KINDS[e.kind] ? e.kind : 'photo');
  const kindDef = (e) => KINDS[kindOf(e)];

  // 主題：system / light / dark（手動可覆蓋系統偏好）
  const systemDark = () => !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  const isDark = () => { const t = document.documentElement.dataset.theme; return t === 'dark' ? true : t === 'light' ? false : systemDark(); };

  /* ---------- 地圖圖層（伺服器端見 api/layers.php）----------
     由 view.php 依 meta.json 的 layers（沒寫就用 config 的 default_layers）解析好塞進 APP.layers，
     由下往上排序。相對路徑在 PHP 端就被改寫成絕對網址，所以這裡完全不必分辨圖磚是外部服務
     還是本站 layer 端點輸出的，也不必分辨圖層是全站的還是這張地圖專屬的。
     實際的圖層系統（MapLayer/RasterLayer/ImageLayer/LayerStack）現在活在 assets/js/engine/
     底下每個引擎自己的檔案裡；這裡只負責「該用哪份 manifest」，跟哪個引擎去畫完全無關。 */
  // APP.layers 缺席時的保命底圖定義在 MapEngine.FALLBACK_LAYER（assets/js/engine/map-engine.js）：
  // 每個引擎自己的圖層系統做第二層防禦時也要用同一份，所以放在兩邊都讀得到的地方，不是各自重複一份。
  const layerManifests = () => (APP.layers && APP.layers.length) ? APP.layers : [MapEngine.FALLBACK_LAYER];

  let themeMode = localStorage.getItem('theme') || 'system';
  if (themeMode !== 'system') document.documentElement.dataset.theme = themeMode;

  // 未填暱稱時給一個可愛的隨機匿名名（存在本機，重新整理不會換；長按身分可換新的）
  const ANON_NOUNS = t('anon_nouns').split(',');
  function newAnonName() { return t('anon_prefix') + ANON_NOUNS[Math.floor(Math.random() * ANON_NOUNS.length)]; }
  let SESSION_ANON = newAnonName();
  try { SESSION_ANON = localStorage.getItem('anonName') || SESSION_ANON; localStorage.setItem('anonName', SESSION_ANON); } catch (e) {}
  function rerollAnon() {
    SESSION_ANON = newAnonName();
    try { localStorage.setItem('anonName', SESSION_ANON); localStorage.removeItem('myName'); } catch (e) {}
    const myName = document.getElementById('myName'); if (myName) myName.value = '';
    emitHook('identityChanged');
    emitHook('identityReroll');
    toast(t('toast_new_anon_name', { name: SESSION_ANON }));
  }
  function displayName() {
    const n = (document.getElementById('myName').value || '').trim();
    return n || SESSION_ANON;
  }

  // 擁有者標記（此裝置）：用於「只刪自己的」。90 天後自動更換（過期即無法再刪舊內容）。
  function ownerToken() {
    const KEY = 'ownerToken', TTL = 90 * 24 * 3600 * 1000;
    try { const o = JSON.parse(localStorage.getItem(KEY) || 'null'); if (o && o.t && (Date.now() - o.c) < TTL) return o.t; } catch (e) {}
    const t = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now().toString(36) + Math.random().toString(36).slice(2));
    try { localStorage.setItem(KEY, JSON.stringify({ t: t, c: Date.now() })); } catch (e) {}
    return t;
  }
  let myOwnerHash = '';
  async function computeMyHash() {
    try {
      const d = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(ownerToken()));
      myOwnerHash = [...new Uint8Array(d)].map(x => x.toString(16).padStart(2, '0')).join('');
    } catch (e) { myOwnerHash = ''; }
  }

  // 投稿者身分（可選，設 PIN 才有；匿名投稿者無此身分）：token 存於此裝置，跨裝置管理自己的投稿要靠它。
  function contribInfo() { try { return JSON.parse(localStorage.getItem('contrib_' + PROJECT) || 'null') || null; } catch (e) { return null; } }
  function contribToken() { const c = contribInfo(); return (c && c.token) ? c.token : ''; }
  function setContribInfo(info) { try { localStorage.setItem('contrib_' + PROJECT, JSON.stringify(info)); } catch (e) {} }
  let myContribId = '';
  async function computeContribId() {
    const ct = contribToken();
    if (!ct) { myContribId = ''; return; }
    try {
      const d = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('cid|' + ct));
      myContribId = [...new Uint8Array(d)].map(x => x.toString(16).padStart(2, '0')).join('').slice(0, 12);
    } catch (e) { myContribId = ''; }
  }
  const isMine = (e) => !!((myOwnerHash && e.owner_hash && e.owner_hash === myOwnerHash) || (myContribId && e.contrib_id && e.contrib_id === myContribId));

  // 新增一筆投稿（故事版本、照片…共用）：project/owner/code/ctoken 這些通用欄位統一在這裡補上，呼叫端只要給業務欄位（kind/name/comment/photo…）。
  // 欄位值傳 [blob, filename] 陣列可指定 Blob 的檔名（否則瀏覽器預設存成 "blob"）。
  // opts.maxRetry 搭配 opts.onRetry(waitSeconds, attempt, maxAttempt) 可在遇到伺服器限流（429）時自動倒數重試，不做的話（不傳 opts）就是原本的單次送出行為。
  async function submitContribution(fields, opts) {
    opts = opts || {};
    const maxRetry = opts.maxRetry || 0;
    for (let attempt = 0; attempt <= maxRetry; attempt++) {
      const fd = new FormData();
      fd.append('project', PROJECT);
      fd.append('owner', ownerToken());
      fd.append('code', storedCode());
      const ct = contribToken(); if (ct) fd.append('ctoken', ct);
      for (const k in fields) {
        const v = fields[k];
        if (v === undefined || v === null) continue;
        if (Array.isArray(v)) fd.append(k, v[0], v[1]); else fd.append(k, v);
      }
      const res = await fetch(apiUrl('upload'), { method: 'POST', body: fd });
      if (res.status === 429 && attempt < maxRetry) {
        const wait = parseInt(res.headers.get('Retry-After') || '10', 10) || 10;
        if (opts.onRetry) await opts.onRetry(wait, attempt + 1, maxRetry);
        continue;
      }
      const j = await res.json().catch(() => ({ error: 'HTTP ' + res.status }));
      if (!res.ok || j.error) throw new Error((j.error || ('HTTP ' + res.status)) + (j.detail ? '：' + j.detail : ''));
      CONTRIB.push(j.item);
      return j.item;
    }
    const err = new Error(t('failed_rate_limited_retry_manually')); err.rateLimited = true; throw err;
  }

  // 建立新地點：身分欄位比照 submitContribution 統一在這裡補齊，但打的是 api/newpoint.php——
  // 建點的權限是每張地圖自己設定的（meta.json 的 contrib.newPoint），由那支端點把關，
  // 所以管理者模式下要一併帶上 csrf（見 api/newpoint.php 的 admin 分支）。
  async function submitNewPoint(fields) {
    const fd = new FormData();
    fd.append('project', PROJECT);
    fd.append('owner', ownerToken());
    fd.append('code', storedCode());
    const ct = contribToken(); if (ct) fd.append('ctoken', ct);
    if (APP.isManager) fd.append('csrf', APP.csrf || '');
    for (const k in fields) {
      const v = fields[k];
      if (v === undefined || v === null) continue;
      fd.append(k, v);
    }
    const res = await fetch(apiUrl('newpoint'), { method: 'POST', body: fd });
    const j = await res.json().catch(() => ({ error: 'HTTP ' + res.status }));
    if (!res.ok || j.error) throw new Error((j.error || ('HTTP ' + res.status)) + (j.detail ? '：' + j.detail : ''));
    CONTRIB.push(j.item);
    return j.item;
  }

  async function deleteEntry(id) {
    if (!confirm(t('confirm_delete'))) return;
    try {
      const fd = new FormData();
      fd.append('project', PROJECT); fd.append('id', id); fd.append('owner', ownerToken());
      const ct = contribToken(); if (ct) fd.append('ctoken', ct);
      const res = await fetch(apiUrl('delete'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || j.error) { alert(t('delete_failed', { reason: j.error || ('HTTP ' + res.status) })); return; }
      CONTRIB = CONTRIB.filter(e => String(e.id) !== String(id));
      refreshAll();
    } catch (e) { alert(t('delete_failed', { reason: e.message })); }
  }

  // 匿名統計（只累加計數，不送個資）
  function statSend(type, id, extra) {
    try {
      const fd = new FormData();
      fd.append('project', PROJECT); fd.append('type', type);
      if (id != null) fd.append('id', id);
      if (extra) for (const k in extra) fd.append(k, extra[k]);
      if (navigator.sendBeacon) navigator.sendBeacon(apiUrl('stat'), fd);
      else fetch(apiUrl('stat'), { method: 'POST', body: fd, keepalive: true });
    } catch (e) {}
  }
  const feature = (name) => statSend('feature', name);
  function statVisit() {
    const now = new Date();
    statSend('view', null, { h: now.getHours(), d: now.getDay() });
    try {
      if (!sessionStorage.getItem('sVisited')) {
        sessionStorage.setItem('sVisited', '1');
        statSend('session');
        statSend('device', /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent) ? 'mobile' : 'desktop');
      }
    } catch (e) {}
  }

  // 上傳權限：能不能投稿完全看投稿碼。APP.gated＝這張地圖現在有還有效的碼（見 api/security.php contrib_open）；
  // 一組都沒有＝目前未開放投稿，解鎖鈕也不出現。已用管理 PIN 登入者一律視為已解鎖。EMBED 一律不可上傳。
  function storedCode() { try { return localStorage.getItem('uploadCode_' + PROJECT) || ''; } catch (e) { return ''; } }
  function isUnlocked() { return !!APP.isManager || (!!APP.gated && !!storedCode()); }
  function canPost() { return !EMBED && MOD('upload') && isUnlocked(); }
  function applyPostState() {
    document.body.classList.toggle('noupload', !canPost());
    const fab = document.getElementById('unlockFab');
    if (fab) fab.style.display = (APP.gated && !isUnlocked() && !EMBED) ? '' : 'none';
    emitHook('identityChanged');
    if (current) renderEntries();   // 讓「編輯說明」鈕跟著出現/消失
  }
  // 管理者「檢視模式」：直接覆寫 APP.isManager 本身，讓所有既有讀 APP.isManager 的地方
  // （核心與各插件的管理者專屬 UI／CSRF 附加邏輯）不用個別修改，一次翻轉全部一起生效。
  // 不做持久化，重新整理頁面就回到真實管理者狀態。
  const REAL_IS_MANAGER = !!APP.isManager;
  let PREVIEW_MODE = false;
  function setPreviewMode(on) {
    if (!REAL_IS_MANAGER || on === PREVIEW_MODE) return;
    PREVIEW_MODE = on;
    APP.isManager = on ? false : true;
    applyPostState();
    refreshAll();
    if (current) openPanel(current);
  }
  // 右上身分指示鈕的點擊行為（觸發上傳捷徑或解鎖流程）：留在核心是因為要用到 MOD/canPost 等私有狀態；
  // 指示鈕本身的渲染／長按換名／解鎖對話框裡的建立身分欄位都在 assets/js/plugins/contributor-identity.js。
  function identityChipClick() { if (!MOD('upload')) return; if (canPost()) { emitHook('identityUploadShortcut'); } else if (APP.gated) { openUnlock(); } }
  async function doUnlock(code, cpin, cname) {
    code = (code || '').trim();
    if (!code) return { ok: false, msg: t('enter_code') };
    try {
      const fd = new FormData(); fd.append('project', PROJECT); fd.append('code', code);
      if (cpin) { fd.append('cpin', cpin); if (cname) fd.append('cname', cname); }
      const res = await fetch(apiUrl('unlock'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) {
        try { localStorage.setItem('uploadCode_' + PROJECT, code); } catch (e) {}
        if (j.contrib) { setContribInfo(j.contrib); await computeContribId(); }
        applyPostState(); return { ok: true };
      }
      return { ok: false, msg: j.error || t('code_incorrect') };
    } catch (e) { return { ok: false, msg: t('connection_failed') }; }
  }
  function toast(html) { const t = document.createElement('div'); t.className = 'toast egg'; t.innerHTML = html; document.body.appendChild(t); setTimeout(() => { t.style.opacity = '0'; }, 2200); setTimeout(() => t.remove(), 2800); }

  // 封面快照：管理者檢視時機會性擷圖更新（伺服器端仍會依最小間距與 custom 模式把關，這裡的
  // 檢查只是避免每次開頁都白白擷圖編碼）。force＝後台「強制刷新」按鈕開的分頁（見 ?snapcover=force），
  // 略過間距檢查並在完成後跳提示，方便管理者確認新圖真的存進去了。
  // 封面統一用淺色主題擷圖：管理者當下若開著深色主題，擷圖前先暫時切回淺色、擷完再切回去，
  // 避免同一批專案的封面卡片因為各管理者擷圖當下開的主題不同而深淺不一。
  function trySnapshotCover(force) {
    if (!APP.isManager || !engine || !engine.supportsSnapshot) return;
    const cov = (APP.meta && APP.meta.cover) || null;
    if (!force) {
      if (cov && cov.mode === 'custom') return;
      if (cov && cov.updatedAt && (Date.now() - Date.parse(cov.updatedAt)) < (APP.coverMinInterval || 3600) * 1000) return;
    }
    const needLightSwap = isDark() && engine.hasDarkStyle;
    const capture = () => {
      const dataUrl = engine.getCanvasDataURL('image/jpeg', 0.85);
      if (needLightSwap) engine.applyTheme(true);   // 擷完切回管理者原本開著的深色主題，不留痕跡
      if (!dataUrl) return;
      const fd = new FormData();
      fd.append('project', APP.project);
      fd.append('action', 'auto');
      if (force) fd.append('force', '1');
      fd.append('image', dataUrl);
      fd.append('csrf', APP.csrf || '');
      fetch(APP.coverUrl, { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (d && d.ok) {
          APP.meta = APP.meta || {};
          APP.meta.cover = d.cover || null;
          if (force) toast('<i class="fa-solid fa-check"></i> ' + esc(t('cover_refresh_done')));
        } else if (force) {
          toast('<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(t('cover_refresh_failed')));
        }
      }).catch(() => { if (force) toast('<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(t('cover_refresh_failed'))); });
    };
    if (needLightSwap) {
      engine.applyTheme(false);
      engine.getRawMap().once('idle', capture);   // 等新樣式的圖磚真的畫完，不然擷到切換中的半載入畫面
    } else {
      capture();
    }
  }

  // 管理 PIN 連結兌換：秘密只透過網址 fragment（#redeem=...&rmode=admin）傳遞，不落地在 query string／伺服器紀錄。
  // 讀出後立刻用 history.replaceState 清掉，避免重新整理或分享網址時重複兌換／外流。
  let pendingRedeem = null; // {project, token}，等待使用者在 #adminRedeemDialog 自己輸入 PIN／暱稱後才送出
  async function handleRedeemFragment() {
    if (EMBED || !MOD('delegation')) return;
    const raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
    if (!/(^|&)redeem=/.test(raw)) return;
    const hp = new URLSearchParams(raw);
    const token = hp.get('redeem');
    const rmode = hp.get('rmode');
    hp.delete('redeem'); hp.delete('rmode');
    const rest = hp.toString();
    try { history.replaceState(null, '', location.pathname + location.search + (rest ? '#' + rest : '')); } catch (e) {}
    if (!token || rmode !== 'admin') return;
    pendingRedeem = { project: PROJECT, token };
    openAdminRedeem();
  }

  // 管理 PIN 邀請兌換彈窗：PIN／暱稱由收到連結的人自己輸入，不做自動登入
  function openAdminRedeem() {
    const msg = document.getElementById('adminRedeemMsg'); if (msg) { msg.textContent = ''; msg.style.color = ''; }
    const pinEl = document.getElementById('adminRedeemPinInput'), nameEl = document.getElementById('adminRedeemNameInput');
    if (pinEl) pinEl.value = ''; if (nameEl) nameEl.value = '';
    const dlg = document.getElementById('adminRedeemDialog'); if (dlg) dlg.classList.add('open');
    setTimeout(() => { if (pinEl) pinEl.focus(); }, 60);
  }
  function closeAdminRedeem() { const dlg = document.getElementById('adminRedeemDialog'); if (dlg) dlg.classList.remove('open'); }
  async function trySubmitAdminRedeem() {
    if (!pendingRedeem) { closeAdminRedeem(); return; }
    const msg = document.getElementById('adminRedeemMsg');
    const pinEl = document.getElementById('adminRedeemPinInput'), nameEl = document.getElementById('adminRedeemNameInput');
    const pin = pinEl ? pinEl.value.trim() : '', label = nameEl ? nameEl.value.trim() : '';
    if (msg) { msg.style.color = ''; msg.textContent = t('unlocking_verifying'); }
    try {
      const fd = new FormData();
      fd.append('action', 'admin_redeem'); fd.append('project', pendingRedeem.project);
      fd.append('token', pendingRedeem.token); fd.append('pin', pin); fd.append('label', label);
      const res = await fetch(MANAGER_URL, { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) {
        pendingRedeem = null;
        closeAdminRedeem();
        toast('<i class="fa-solid fa-user-shield"></i> ' + esc(t('redeem_admin_ok')));
        setTimeout(() => location.reload(), 900);
      } else if (msg) {
        msg.style.color = '#c0392b';
        msg.textContent = j.error === 'pin_taken' ? t('redeem_admin_pin_taken') : (j.error || t('redeem_admin_link_invalid'));
      }
    } catch (e) { if (msg) { msg.style.color = '#c0392b'; msg.textContent = t('connection_failed'); } }
  }

  // 解鎖彈窗 + QR 掃描
  let scanStream = null, scanRAF = null;
  function openUnlock() {
    document.getElementById('unlockCodeInput').value = '';
    const msg = document.getElementById('unlockMsg'); msg.textContent = ''; msg.style.color = '';
    document.getElementById('scanBox').style.display = 'none';
    const pinEl = document.getElementById('unlockPinInput'), nameEl = document.getElementById('unlockCnameInput'), idFields = document.getElementById('idFields'), idBtn = document.getElementById('idToggleBtn');
    if (pinEl) pinEl.value = ''; if (nameEl) nameEl.value = '';
    if (idFields) idFields.style.display = 'none'; if (idBtn) idBtn.setAttribute('aria-expanded', 'false');
    document.getElementById('unlockDialog').classList.add('open');
    setTimeout(() => document.getElementById('unlockCodeInput').focus(), 60);
  }
  function closeUnlock() { stopScan(); const dlg = document.getElementById('unlockDialog'); if (dlg) dlg.classList.remove('open'); }
  async function trySubmitUnlock() {
    const msg = document.getElementById('unlockMsg');
    msg.style.color = ''; msg.textContent = t('unlocking_verifying');
    const pinEl = document.getElementById('unlockPinInput'), nameEl = document.getElementById('unlockCnameInput');
    const cpin = pinEl ? pinEl.value.trim() : '', cname = nameEl ? nameEl.value.trim() : '';
    const r = await doUnlock(document.getElementById('unlockCodeInput').value, cpin, cname);
    if (r.ok) { closeUnlock(); toast('<i class="fa-solid fa-check"></i> ' + esc(t('unlock_success'))); }
    else { msg.style.color = '#c0392b'; msg.textContent = r.msg; }
  }
  function extractCode(text) { if (!text) return ''; const m = String(text).match(/[?&]code=([^&\s]+)/i); return (m ? decodeURIComponent(m[1]) : String(text)).trim(); }
  async function startScan() {
    if (typeof jsQR === 'undefined') { document.getElementById('unlockMsg').textContent = t('scanner_not_loaded'); return; }
    const box = document.getElementById('scanBox'), video = document.getElementById('scanVideo');
    box.style.display = 'block';
    try {
      scanStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = scanStream; await video.play();
      const cv = document.createElement('canvas'), ctx = cv.getContext('2d', { willReadFrequently: true });
      const tick = () => {
        if (!scanStream) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
          cv.width = video.videoWidth; cv.height = video.videoHeight;
          ctx.drawImage(video, 0, 0, cv.width, cv.height);
          const img = ctx.getImageData(0, 0, cv.width, cv.height);
          const q = jsQR(img.data, img.width, img.height);
          if (q && q.data) { const c = extractCode(q.data); stopScan(); document.getElementById('unlockCodeInput').value = c; trySubmitUnlock(); return; }
        }
        scanRAF = requestAnimationFrame(tick);
      };
      scanRAF = requestAnimationFrame(tick);
    } catch (e) { document.getElementById('unlockMsg').textContent = t('camera_open_failed', { reason: e.message || e }); box.style.display = 'none'; }
  }
  function stopScan() {
    if (scanRAF) { cancelAnimationFrame(scanRAF); scanRAF = null; }
    if (scanStream) { scanStream.getTracks().forEach(t => t.stop()); scanStream = null; }
    const box = document.getElementById('scanBox'); if (box) box.style.display = 'none';
  }

  let META = null, POINTS = [], CATS = [], active = {}, CONTRIB = [], counts = {}, audioPoints = new Set(), playingPoints = new Set();
  let engine = null, photoLayerOn = false;
  let filterPerson = '';

  function updateThemeIcon() {
    const icon = { system: 'fa-circle-half-stroke', light: 'fa-sun', dark: 'fa-moon' }[themeMode] || 'fa-circle-half-stroke';
    const btn = document.getElementById('themeBtn');
    if (btn) btn.innerHTML = '<i class="fa-solid ' + icon + '"></i>';
  }
  function applyTheme(mode) {
    themeMode = mode;
    if (mode === 'system') delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = mode;
    localStorage.setItem('theme', mode);
    updateThemeIcon();
    if (engine) engine.applyTheme(isDark());
  }

  /* ---------- 選用插件掛勾點（見 souliong/docs/EXTENDING.md）----------
     核心只負責「發生了什麼事」（onHook/emitHook）與「讓插件參與渲染結果」（registerPhotoFilter/registerEntriesHint），
     不認得任何特定插件；插件自己的邏輯、DOM、CSS 都放在 assets/js/plugins/ 底下獨立檔案。 */
  const hookListeners = {};
  function onHook(name, fn) { (hookListeners[name] = hookListeners[name] || []).push(fn); }
  function emitHook(name, ...args) { (hookListeners[name] || []).forEach(fn => { try { fn(...args); } catch (err) { console.error('[hook:' + name + ']', err); } }); }
  const photoFilters = [];     // fn(photoEntry, currentPoint) => bool；renderEntries() 的照片清單要 AND 全部通過
  const entriesHintFns = [];   // fn(currentPoint) => HTMLElement|null；renderEntries() 會把回傳的節點插進卡片內容
  const entryActionFns = [];   // fn(entry) => HTMLElement|null；renderEntries() 會把回傳的節點接在每張投稿卡的操作列（編輯／刪除按鈕）後面
  const scopeParamFns = [];    // fn() => {key: value}|null；插件自己在分享連結／嵌入碼網址上帶的額外參數，讀取時插件自己讀 location.search，不需要核心知道
  const shortcuts = [];        // {key, label}；key 顯示鍵名（例："Esc"／"R"），label 是 i18n 過的說明字串——鍵盤快捷鍵提示彈窗（見 openShortcuts()）照登記順序列出，各檔案自己知道自己註冊了哪個鍵，核心不需要另外維護一份對照表
  function registerPhotoFilter(fn) { photoFilters.push(fn); }
  function registerEntriesHint(fn) { entriesHintFns.push(fn); }
  function registerEntryAction(fn) { entryActionFns.push(fn); }
  function registerScopeParam(fn) { scopeParamFns.push(fn); }
  function registerShortcut(spec) { shortcuts.push(spec); }

  // 插件基底類別：每個插件檔案 class XxxPlugin extends MapApp.Plugin，只需覆寫 mount()——
  // 核心建立實例並呼叫 init(MapApp)，插件自己的 state/DOM/CSS 一律掛在 this 底下，不碰核心內部變數。
  class SouliongPlugin {
    constructor(key) { this.key = key; this.mapApp = null; }
    init(MapApp) { this.mapApp = MapApp; this.mount(); }
    mount() {}
  }

  // 模組開關（見 souliong/api/features.php souliong_modules()）：直接讀 PHP 算好的 APP.moduleState，
  // 不在前端重算一次預設值/相依邏輯，確保跟 view.php 用 $mod() 決定要不要輸出 HTML 時的結果一致。
  function MOD(key) { return !!(APP.moduleState && APP.moduleState[key]); }

  /* ---------- helpers ---------- */
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const pad2 = (n) => String(n).padStart(2, '0');
  function fmtTime(t) {
    if (!t) return '';
    const d = new Date(t); if (isNaN(d)) return '';
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  // 點位的顯示名稱。欄位名稱依資料來源而異（100chairs 是 theme／chair，一般地圖是 title，
  // 訪客建立的地點也是 title），下拉選單與標題都走這一個函式，加新來源時只要改這裡。
  const pointName = (p) => p.theme || p.title || p.chair || '';
  function pointTitle(p) {
    const base = pointName(p);
    if (META.numbering === 'disable') return base;
    if (META.numbering === 'prefix') return (p.num != null ? pad2(p.num) + ' ' : '') + base;
    return base + (p.num != null ? ' ' + pad2(p.num) : '');
  }
  // 點位列表／跳轉選單共用的「編號｜名稱」標籤（回傳值已 HTML 跳脫，可直接接進 innerHTML）
  function pointNumLabel(p) {
    const name = esc(pointName(p));
    return META.numbering === 'disable' ? name : pad2(p.num) + '｜' + name;
  }
  function pointSub(p) {
    const bits = [];
    if (p.area || p.chair) bits.push(esc([p.area, p.chair].filter(Boolean).join(' ・ ')));
    else if (p.sub) bits.push(esc(p.sub));
    if (p.material) bits.push(esc(p.material));
    return bits.join('<br>');
  }
  function chairOptionsHtml(selNum) {
    const none = '<option value=""' + (selNum == null ? ' selected' : '') + '>' + esc(t('point_none_option')) + '</option>';
    // 用 effectivePoints() 而非 POINTS：訪客建立的地點也要能被選成「這則投稿屬於哪個點」
    return none + effectivePoints().sort((a, b) => a.num - b.num).map(p =>
      '<option value="' + p.num + '"' + (p.num === selNum ? ' selected' : '') +
      (p.color ? ' style="color:' + esc(p.color) + '"' : '') + '>' +
      '● ' + pad2(p.num) + '｜' + esc(pointName(p)) + (p.area ? '（' + esc(p.area) + '）' : '') + '</option>').join('');
  }
  function haversine(aLat, aLon, bLat, bLon) {
    const R = 6371000, r = Math.PI / 180;
    const dLat = (bLat - aLat) * r, dLon = (bLon - aLon) * r;
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
  }
  function nearestPoint(lat, lon) {
    let best = null, bd = Infinity;
    for (const p of effectivePoints()) {
      const d = haversine(lat, lon, p.lat, p.lon);
      if (d < bd) { bd = d; best = p; }
    }
    return best;
  }
  function photoFullUrl(item) { return item.photo ? apiUrl('photo') + '&f=' + encodeURIComponent(item.photo) : null; }
  // 標記與照片牆用縮圖、燈箱用原圖；舊投稿沒 thumb 欄位就帶 th=1 讓伺服器自動產縮圖（失敗時回原圖）
  function photoThumbUrl(item) { return item.thumb ? apiUrl('photo') + '&f=' + encodeURIComponent(item.thumb) : (item.photo ? photoFullUrl(item) + '&th=1' : null); }
  // 影音走 api/media.php（支援 Range，<audio>/<video> 才拖得動進度條）
  function mediaFullUrl(item) { return item.media ? apiUrl('media') + '&f=' + encodeURIComponent(item.media) : null; }

  // 任一種投稿的主檔／縮圖網址。**縮圖不能一律組 photo.php 的網址**：影片的封面圖存在
  // projects/<id>/media/ 底下，photo.php 只看 photos/，組錯了會整排 404。
  function entryFullUrl(item) { return item.media ? mediaFullUrl(item) : photoFullUrl(item); }
  function entryThumbUrl(item) {
    if (item.media) return item.thumb ? apiUrl('media') + '&f=' + encodeURIComponent(item.thumb) : null;
    return photoThumbUrl(item);
  }

  // 影音時長：upload.php 收下的是前端量到的秒數（浮點），顯示成 m:ss
  function fmtDur(sec) {
    const s = Math.max(0, Math.round(+sec || 0));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  }

  // 哪些記錄是「排在投稿牆上的一則投稿」。desc（地點故事版本）不算，它顯示在故事區；
  // primaryKind（如聲音地圖的錄音）同理不算，它也顯示在故事區、取代 desc 的角色；
  // point／newpoint 也不算，它們是地點本身而不是掛在地點底下的內容。
  const isEntry = (e) => !!(e && (KINDS[e.kind] || (!e.kind && e.photo)) && e.kind !== 'desc' && (!PRIMARY_KIND || e.kind !== PRIMARY_KIND));

  // 合併「原始投稿」與其 edit_of 編輯紀錄，算出目前應顯示的內容：
  // 留言/關聯地點/定位取最新一筆編輯，但檔案本身與原始拍攝時間永遠沿用原始那筆（編輯不能換照片/影音檔）。
  function effectiveEntries() {
    const originals = {}, edits = {};
    CONTRIB.forEach(e => {
      if (e.kind === 'desc' || e.kind === 'point' || e.kind === 'newpoint') return;
      if (PRIMARY_KIND && e.kind === PRIMARY_KIND) return;
      if (e.edit_of) (edits[e.edit_of] = edits[e.edit_of] || []).push(e);
      // photo 記錄要有圖才算（純留言的照片投稿沿用舊行為不上牆）；其餘型別各有自己的成立條件
      else if (isEntry(e) && (e.photo || e.media || (e.kind === 'text' && e.comment))) originals[e.id] = e;
    });
    return Object.keys(originals).map(id => {
      const orig = originals[id];
      const list = edits[id];
      if (!list || !list.length) return orig;
      list.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
      const latest = list[list.length - 1];
      return {
        ...orig,
        comment: latest.comment,
        item_num: latest.item_num,
        lat: latest.lat,
        lon: latest.lon,
        loc_source: latest.loc_source,
        edited: true,
        editHistory: [orig, ...list],
      };
    });
  }
  // 只要照片的那份。多型別上線前 effectivePhotos() 就是唯一的投稿清單，route-tour／person-explore
  // 兩個插件都在用它畫「某人的觀察路線」與照片時間軸；那些畫面本來就只處理得了 <img>，
  // 所以這裡維持照片語意不變，核心自己的呈現才改吃 effectiveEntries()。
  function effectivePhotos() { return effectiveEntries().filter(e => !!e.photo); }

  // 合併「定位點（椅子）原始座標」與管理者的位置編輯紀錄：同一 item_num 底下只留最新一筆 kind:'point' 覆蓋座標。
  // origLat/origLon 一律保留 chairs.json 的原始座標，供編輯面板「還原初始位置」使用。
  function effectivePoints() {
    const latest = {};
    CONTRIB.forEach(e => {
      if (e.kind !== 'point' || e.item_num == null) return;
      const cur = latest[e.item_num];
      if (!cur || new Date(e.created_at) > new Date(cur.created_at)) latest[e.item_num] = e;
    });
    // 訪客／管理者建立的地點（api/newpoint.php 寫的 kind:'newpoint'）先併進清單，再一起套座標覆蓋——
    // 順序不能反過來：建立出來的點之後也要能被管理者搬位置，那條路徑走的同樣是 kind:'point'。
    const added = CONTRIB.filter(e => e.kind === 'newpoint' && e.num != null).map(e => ({
      num: e.num, title: e.title, cat: e.cat || 'new', catLabel: e.catLabel, color: e.color || '#7a7f87',
      lat: e.lat, lon: e.lon, story: e.story, area: e.area, addedBy: e.name, addedAt: e.created_at, userAdded: true,
      photo: e.photo || null, thumb: e.thumb || null,
    }));
    return POINTS.concat(added).map(p => {
      const ed = latest[p.num];
      return ed
        ? { ...p, lat: ed.lat, lon: ed.lon, posEdited: true, origLat: p.lat, origLon: p.lon }
        : { ...p, origLat: p.lat, origLon: p.lon };
    });
  }

  /* ---------- map ---------- */
  // badgeColor 有給值時（篩選單一投稿者時）覆蓋角標底色，跟該投稿者的路徑同色
  // 回傳的是引擎無關的 marker spec（見 assets/js/engine/map-engine.js 的 setMarkerLayer()），
  // 不是某個引擎的原生 icon 物件——LeafletEngine／MapLibreEngine 各自決定怎麼把它畫出來。
  // 幾何圖形固定由 num 算出（num 是點位建立時分配、之後永不改變的識別碼），
  // 不需要另存欄位：同一個點每次算出來的圖形永遠一樣，效果等同「建立時隨機、之後固定」。
  const PIN_SHAPES = [
    '<polygon points="5,0.5 9.5,9.5 0.5,9.5"/>',                    // 三角形
    '<rect x="1" y="1" width="8" height="8"/>',                     // 正方形
    '<polygon points="5,0 10,5 5,10 0,5"/>',                        // 菱形
    '<polygon points="5,0 9.76,3.42 7.94,9.08 2.06,9.08 0.24,3.42"/>', // 五邊形
    '<polygon points="5,0 6.53,3.53 10,5 6.53,6.47 5,10 3.47,6.47 0,5 3.47,3.53"/>', // 星形
    '<polygon points="2.5,0.5 7.5,0.5 9.5,5 7.5,9.5 2.5,9.5 0.5,5"/>', // 六邊形
  ];
  function pinShapeSvg(num) {
    const n = ((num % PIN_SHAPES.length) + PIN_SHAPES.length) % PIN_SHAPES.length;
    return '<svg viewBox="0 0 10 10" width="10" height="10" fill="currentColor" aria-hidden="true">' + PIN_SHAPES[n] + '</svg>';
  }
  // pinMark='image' 時，所有標記統一換成後台上傳的同一張圖（圓形裁切），取代編號／留空／幾何圖形
  const PIN_MARK_IMAGE_URL = apiUrl('pinmark') + '&project=' + encodeURIComponent(PROJECT);
  // pinSize：圓點直徑——sm/lg 對應 CSS 的 .sl-sz-sm/.sl-sz-lg 修飾類別，沒設或非白名單值就是預設 24px
  const PIN_SIZE_PX = { sm: 18, lg: 32 };
  function chairIcon(c, count, badgeColor) {
    const badge = count ? '<div class="badge"' + (badgeColor ? ' style="background:' + badgeColor + '"' : '') + '>' + count + '</div>' : '';
    const sizeCls = META.pinSize === 'sm' ? ' sl-sz-sm' : META.pinSize === 'lg' ? ' sl-sz-lg' : '';
    // pinBorder：白色外框開關，沒設過（舊專案）預設為 true，跟改版前的固定外框行為一致
    const borderCls = META.pinBorder === false ? ' sl-noborder' : '';
    // has-audio：這個地點掛了聲音；is-playing：其中一則正在播放，脈衝光暈只在播放中顯示（見 map-markers.css）
    const cls = 'dot-pin' + sizeCls + borderCls + (count ? ' has-contrib' : '') + (audioPoints.has(c.num) ? ' has-audio' : '') + (playingPoints.has(c.num) ? ' is-playing' : '');
    // pinMark：地圖上圓點裡要放什麼——number（預設，顯示編號）／blank（留空）／shape（依 num 固定配一個幾何圖形）／image（自訂圖片取代整個標記）
    const isImage = META.pinMark === 'image';
    const pinMark = isImage || META.pinMark === 'blank' ? '' : META.pinMark === 'shape' ? '<span>' + pinShapeSvg(c.num) + '</span>' : '<span>' + c.num + '</span>';
    const bg = isImage ? 'url(' + PIN_MARK_IMAGE_URL + ') center/cover' : (c.color || '#888');
    const px = PIN_SIZE_PX[META.pinSize] || 24, half = px / 2;
    return {
      size: [px, px], anchor: [half, half],
      html: '<div class="' + cls + '" style="background:' + bg + '">' + pinMark + badge + '</div>'
    };
  }
  // 音訊播放狀態切換：只有播放中的地點才顯示標記脈衝，切換時才需要重畫標記層
  function setPointPlaying(num, playing) {
    if (num == null) return;
    const had = playingPoints.has(num);
    if (playing) playingPoints.add(num); else playingPoints.delete(num);
    if (had !== playing) renderChairs();
  }
  // 一則 <audio> 元素綁定播放狀態事件；卸除時（面板重繪／關閉）要先呼叫 pause() 才會確實觸發 pause 事件
  function bindAudioPlayState(audioEl, itemNum) {
    audioEl.addEventListener('play', () => setPointPlaying(itemNum, true));
    audioEl.addEventListener('pause', () => setPointPlaying(itemNum, false));
    audioEl.addEventListener('ended', () => setPointPlaying(itemNum, false));
  }
  // 引擎無關的 chairs marker spec 陣列——2D 主地圖跟 map3d.js 的 3D 模式共用同一份，
  // 不要各刻一份（3D 之前自己重畫過一次簡化圓點，見 Part D 整併紀錄）。
  function chairMarkerSpecs() {
    // 篩選單一投稿者時：角標改顯示「這個人在這個點的張數」，並跟路徑同色（同一個 personColor 快取）
    let personCounts = null, badgeColor = null;
    if (filterPerson) {
      personCounts = {};
      personPoints(filterPerson).forEach(e => { personCounts[e.item_num] = (personCounts[e.item_num] || 0) + 1; });
      badgeColor = personColor(filterPerson);
    }
    const specs = [];
    effectivePoints().forEach(c => {
      if (active[c.cat] === false) return;
      const count = personCounts ? (personCounts[c.num] || 0) : (counts[c.num] || 0);
      const icon = chairIcon(c, count, badgeColor);
      specs.push({
        id: c.num, lat: c.lat, lon: c.lon, html: icon.html, size: icon.size, anchor: icon.anchor,
        onClick: () => { emitHook('panelReset'); openPanel(c); },
      });
    });
    return specs;
  }
  function renderChairs() {
    engine.setMarkerLayer('chairs', chairMarkerSpecs());
  }
  const THUMB_ZOOM = 15;   // ≥ 此縮放顯示縮圖，較遠只顯示小方塊
  // 一則投稿在地圖上的標記。照片與影片有縮圖就鋪成方塊（影片右下角補一個播放角標）；
  // 音訊沒有縮圖、影片也可能因主機沒 GD 而抽不出封面，這時退成同尺寸的圖示方塊。
  function entryIcon(e, thumb) {
    const sz = thumb ? 30 : 14, half = sz / 2;
    const def = kindDef(e);
    const url = entryThumbUrl(e);
    const cls = 'photo-sq' + (thumb ? '' : ' plain');
    const html = url
      ? '<div class="' + cls + '" style="background-image:url(' + esc(url) + ')">' +
        (def.box === 'video' && thumb ? '<span class="sl-mk-play"><i class="fa-solid fa-play"></i></span>' : '') + '</div>'
      : '<div class="' + cls + ' sl-mk-ico"><i class="fa-solid ' + def.icon + '"></i></div>';
    return { size: [sz, sz], anchor: [half, half], html: html };
  }
  function renderContribLayer() {
    if (!photoLayerOn) { engine.clearMarkerLayer('contrib'); return; }
    const thumb = engine.getZoom() >= THUMB_ZOOM;
    const specs = [];
    effectiveEntries().forEach(e => {
      if (!kindDef(e).layer) return;
      if (filterPerson && e.name !== filterPerson) return;
      const url = entryFullUrl(e);
      if (typeof e.lat !== 'number' || typeof e.lon !== 'number' || !url) return;
      const icon = entryIcon(e, thumb);
      specs.push({
        id: e.id, lat: e.lat, lon: e.lon, html: icon.html, size: icon.size, anchor: icon.anchor,
        onClick: () => openLightbox(e, url),
      });
    });
    engine.setMarkerLayer('contrib', specs);
  }

  // 某人的觀察路線（照片依時間串連）
  function personPoints(name) {
    return effectivePhotos().filter(e => e.name === name && typeof e.lat === 'number' && typeof e.lon === 'number')
      .sort((a, b) => tv(a) - tv(b));
  }

  /* ---------- 依序探索（選用功能，見 META.personExplore） ----------
     把某人的所有照片依「有沒有綁地標」分兩種：綁地標的（item_num 有值）同一地標合併成一站，
     用該站最早一張照片的時間排序；沒綁地標的零散照片各自一站，用自己的拍攝時間排序。
     兩種站合併後依時間排成一條時間軸，供左上卡片的兩個箭頭逐站切換。 */
  function personTimeline(name) {
    const groups = {}, loose = [];
    effectivePhotos().filter(e => e.name === name).forEach(e => {
      if (e.item_num != null) (groups[e.item_num] = groups[e.item_num] || []).push(e);
      else loose.push(e);
    });
    const stops = Object.keys(groups).map(numStr => {
      const num = +numStr;
      const photos = groups[numStr].sort((a, b) => tv(a) - tv(b));
      return { type: 'point', num: num, point: effectivePoints().find(p => p.num === num), photos: photos, time: tv(photos[0]) };
    });
    loose.forEach(e => stops.push({ type: 'loose', entry: e, time: tv(e) }));
    return stops.sort((a, b) => a.time - b.time);
  }
  // 隨機配色（不用姓名雜湊固定推算）：同一人第一次出現時抽色，之後這個分頁內都沿用同一色，
  // 讓路徑、篩選時的角標...等所有地方顯示的顏色能保持一致。
  const personColorCache = {};
  function personColor(name) {
    if (!personColorCache[name]) personColorCache[name] = 'hsl(' + Math.floor(Math.random() * 360) + ', 70%, 45%)';
    return personColorCache[name];
  }
  // 依目前「全部／投稿」模式切換下拉選單的用途：投稿模式列投稿者（跟 pointList 模組開關無關，
  // 一定要用下拉選單，因為選了要記住、重新整理不會跑掉）；全部模式列地點標籤，開了 pointList
  // 模組時改交給左上角卡片的點位列表（見 renderPointList()），下拉選單本身隱藏。
  function rebuildPersonFilter() {
    const sel = document.getElementById('personFilter');
    const selRow = document.getElementById('personFilterRow');
    const listBox = document.getElementById('pointList');
    if (!sel && !listBox) return;
    if (photoLayerOn) {
      if (listBox) listBox.style.display = 'none';
      if (selRow) selRow.style.display = '';
      if (!sel) return;
      sel.title = t('filter_person');
      const names = [...new Set(CONTRIB.filter(e => e.name && (e.photo || e.comment) && !e.edit_of).map(e => e.name))].sort();
      sel.innerHTML = '';
      const all = document.createElement('option'); all.value = ''; all.textContent = t('all_contributors_count', { n: names.length });
      sel.appendChild(all);
      names.forEach(n => { const o = document.createElement('option'); o.value = n; o.textContent = n; sel.appendChild(o); });
      sel.value = names.includes(filterPerson) ? filterPerson : '';
    } else if (listBox) {
      if (selRow) selRow.style.display = 'none';
      renderPointList(listBox);
    } else {
      sel.title = t('jump_to_point');
      const pts = effectivePoints().sort((a, b) => a.num - b.num);
      sel.innerHTML = '<option value="">' + esc(t('jump_to_point_option', { n: pts.length })) + '</option>' +
        pts.map(p =>
          '<option value="' + p.num + '">' + pointNumLabel(p) + (p.area ? '（' + esc(p.area) + '）' : '') + '</option>'
        ).join('');
    }
  }
  // pointList 模組：左上角卡片直接列出可點擊的點位，取代「跳到地點」下拉選單（見上方 rebuildPersonFilter()）
  function renderPointList(box) {
    const pts = effectivePoints().sort((a, b) => a.num - b.num);
    box.style.display = '';
    box.innerHTML = '<div class="sl-point-list-heading">' + esc(t('point_list_heading', { n: pts.length })) + '</div>' +
      pts.map(p =>
        '<button type="button" class="sl-point-list-item" data-num="' + p.num + '">' +
          '<span class="sl-point-list-dot" style="background:' + esc(p.color || '#888') + '"></span>' +
          '<span class="sl-point-list-label">' + pointNumLabel(p) + '</span>' +
          (p.area ? '<span class="sl-point-list-area">' + esc(p.area) + '</span>' : '') +
        '</button>'
      ).join('');
    box.querySelectorAll('.sl-point-list-item').forEach(btn => {
      btn.onclick = () => {
        const pt = effectivePoints().find(p => p.num === +btn.dataset.num);
        if (pt) { emitHook('panelReset'); openPanel(pt); engine.panTo(pt.lat, pt.lon, { animate: true }); }
      };
    });
  }

  /* ---------- legend ---------- */
  // 圖例的分類清單。用 effectivePoints() 而非 POINTS：訪客建立的地點可能帶了一個這張地圖
  // 原本沒有的分類（newpoint.php 已把 catLabel／color 存進記錄），不從這裡推導的話，
  // 那些點會畫在地圖上、圖例卻沒有對應的一格可以開關。投稿載入後會再跑一次。
  const urlCats = (params.get('cat') || '').split(',').map(s => s.trim()).filter(Boolean);
  const catDefaulted = {};   // ?cat= 的預設只對「第一次出現」的分類套用，不覆蓋使用者後來按過的開關
  function rebuildCats() {
    const seen = {};
    effectivePoints().forEach(c => { if (c.cat && !seen[c.cat]) seen[c.cat] = { key: c.cat, label: c.catLabel || c.cat, color: c.color }; });
    const order = (META && META.categoryOrder) || catOrder;
    CATS = order.filter(k => seen[k]).map(k => seen[k]);
    Object.keys(seen).forEach(k => { if (!CATS.find(c => c.key === k)) CATS.push(seen[k]); });
    // 分享／嵌入可帶 ?cat=key1,key2 只顯示指定主題／分類，其餘分類預設關閉
    if (urlCats.length) CATS.forEach(c => {
      if (catDefaulted[c.key]) return;
      catDefaulted[c.key] = true;
      if (!urlCats.includes(c.key)) active[c.key] = false;
    });
  }
  function buildLegend() {
    const legend = document.getElementById('legend'); if (!legend) return; legend.innerHTML = '';
    CATS.forEach(c => {
      const el = document.createElement('div'); el.className = 'chip' + (active[c.key] === false ? ' off' : '');
      el.innerHTML = '<span class="dot" style="background:' + esc(c.color) + '"></span>' + esc(c.label);
      el.onclick = () => { active[c.key] = active[c.key] === false ? true : false; el.classList.toggle('off', active[c.key] === false); renderChairs(); };
      legend.appendChild(el);
    });
  }

  /* ---------- panel ---------- */
  let current = null;
  function openPanel(c) {
    current = c;
    const cat = CATS.find(x => x.key === c.cat) || { label: '', color: '' };
    document.getElementById('pCat').textContent = cat.label;
    document.getElementById('pCat').style.color = cat.color;
    document.getElementById('pTitle').textContent = pointTitle(c);
    document.getElementById('pSub').innerHTML = pointSub(c);
    document.getElementById('panel').classList.add('open');
    const peBtn = document.getElementById('pointEditBtn');
    if (peBtn) peBtn.style.display = (!EMBED && APP.isManager) ? '' : 'none';
    resetPointEditor();
    renderEntries();
    statSend('point', c.num);
  }
  function closePanel() { document.getElementById('panel').classList.remove('open'); resetPointEditor(); current = null; emitHook('panelReset'); }
  // 電腦版：地點卡片在「預設寬度」與「接近全螢幕的大卡片」之間切換（狀態保留到下次開啟）
  function togglePanelSize() {
    const p = document.getElementById('panel');
    const wide = p.classList.toggle('wide');
    const btn = p.querySelector('.p-expand');
    if (btn) {
      btn.title = t(wide ? 'collapse_panel' : 'expand_panel');
      btn.setAttribute('aria-label', btn.title);
      btn.innerHTML = '<i class="fa-solid ' + (wide ? 'fa-down-left-and-up-right-to-center' : 'fa-up-right-and-down-left-from-center') + '" aria-hidden="true"></i>';
    }
    // 寬度動畫結束後觸發 resize，讓卡片內的迷你地圖（Leaflet trackResize）重算尺寸
    setTimeout(() => window.dispatchEvent(new Event('resize')), 320);
  }
  function resetPointEditor() {
    const el = document.getElementById('pointEditor');
    if (!el) return;
    const p = el._picker; if (p) p.destroy();
    el._picker = null; el.style.display = 'none'; el.innerHTML = '';
  }
  // 定位點（椅子）位置微調面板：僅管理者可見，比照 buildPhotoEditorPanel 的迷你地圖模式，
  // 但不需要留言/關聯地點欄位，多了「還原初始位置」讓管理者在儲存前能隨時退回 chairs.json 的原始座標。
  function togglePointEditor() {
    const el = document.getElementById('pointEditor');
    if (!el || !current) return;
    if (el.style.display !== 'none') { resetPointEditor(); return; }
    el.style.display = 'block';
    const c = current;
    const lat0 = c.lat, lon0 = c.lon;
    el.innerHTML =
      '<div class="mini pt-mini"></div>' +
      '<div class="row">' +
      '<button class="btn small pt-revert" type="button">' + esc(t('reset_location_btn')) + '</button>' +
      '<button class="btn small pt-cancel" type="button">' + esc(t('cancel')) + '</button>' +
      '<button class="btn primary small pt-save" type="button">' + esc(t('save_location_btn')) + '</button>' +
      '<span class="status pt-status"></span></div>';
    const miniDiv = el.querySelector('.pt-mini');
    const picker = engine.createMiniPicker(miniDiv, { lat: lat0, lon: lon0, zoom: 17 });
    el._picker = picker;
    const state = { lat: lat0, lon: lon0 };
    picker.onChange(pos => { state.lat = pos.lat; state.lon = pos.lon; });

    el.querySelector('.pt-revert').onclick = () => {
      picker.setPosition({ lat: c.origLat, lon: c.origLon }, { pan: true });
      state.lat = c.origLat; state.lon = c.origLon;
    };
    el.querySelector('.pt-cancel').onclick = () => resetPointEditor();
    el.querySelector('.pt-save').onclick = () => submitPointEdit(c, state, el, picker);
  }
  async function submitPointEdit(orig, state, panel, picker) {
    const btn = panel.querySelector('.pt-save'); const status = panel.querySelector('.pt-status');
    btn.disabled = true; status.textContent = t('saving');
    try {
      const fd = new FormData();
      fd.append('project', PROJECT);
      fd.append('item_num', orig.num);
      fd.append('lat', state.lat);
      fd.append('lon', state.lon);
      fd.append('name', displayName());
      fd.append('csrf', APP.csrf || '');
      const res = await fetch(apiUrl('editpoint'), { method: 'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || ('HTTP ' + res.status));
      CONTRIB.push(j.item);
      resetPointEditor();
      renderChairs(); rebuildPersonFilter();
      const updated = effectivePoints().find(p => p.num === orig.num);
      if (updated) {
        current = updated;
        document.getElementById('pTitle').textContent = pointTitle(updated);
        document.getElementById('pSub').innerHTML = pointSub(updated);
        renderEntries();
      }
    } catch (err) {
      status.textContent = t('save_failed', { err: err.message });
      btn.disabled = false;
    }
  }
  function renderEntries() {
    if (!current) return;
    const box = document.getElementById('entries');
    // 重繪前先暫停舊的播放器：光把節點丟掉不保證觸發 pause 事件，標記的播放脈衝會卡住不消失
    box.querySelectorAll('audio').forEach(el => { try { el.pause(); } catch (err) {} });
    box.innerHTML = '';
    // 版本種類：一般地圖是 desc（改寫故事文字）；設了 primaryKind 的地圖（如聲音地圖）
    // 改成那個型別本身（如 audio），取代故事文字變成點位的主要內容，兩者共用同一套版本化機制。
    const versionKind = PRIMARY_KIND || 'desc';
    const descs = CONTRIB.filter(e => e.item_num === current.num && e.kind === versionKind && !e.edit_of && (e.comment || e.media)).sort((a, b) => tv(a) - tv(b));
    const entries = effectiveEntries().filter(e => e.item_num === current.num && photoFilters.every(f => f(e, current))).sort((a, b) => tv(a) - tv(b));
    // 版本序列：原始（資料來源為專案自訂的 META.source，例如 StoryMaps）在最舊，之後接使用者送出的版本。
    // 這個「原始」只對文字故事有意義——設了 primaryKind 後點位內容本身就是媒體，沒有等價的純文字原始版本。
    const versions = [];
    if (!PRIMARY_KIND && current.story) versions.push({ name: t('original_source_tag'), comment: current.story, created_at: null, baseline: true });
    descs.forEach(d => versions.push(d));
    const latest = versions[versions.length - 1];
    const hasPrimary = !!PRIMARY_KIND;
    const isPrimaryAudio = PRIMARY_BOX === 'audio';
    const byLine = (!hasPrimary && latest)
      ? (latest.baseline ? esc(t('source_label', { src: META.source || META.credit || '' })) : '— ' + esc(latest.name || t('anon_fallback')) + '・' + fmtTime(latest.photo_time || latest.created_at))
      : '';
    const sourceLic = latest && latest.source_license === 'cc-by' ? 'CC BY' : (latest && latest.source_license === 'cc0' ? 'CC0' : '');
    let sourceLine = '';
    if (latest && latest.source_url) {
      let sourceHost = latest.source_url;
      try { sourceHost = new URL(latest.source_url).host || sourceHost; } catch (e) {}
      sourceLine = '<div class="story-source">' + esc(t('field_source_label')) + '：<a href="' + esc(latest.source_url) + '" target="_blank" rel="noopener">' + esc(sourceHost) + '</a>' +
        (sourceLic ? '<span class="story-source-lic">' + sourceLic + '</span>' : '') +
        '</div>';
    }

    // 主要內容本身：一般地圖是故事文字；設了 primaryKind 且型別是音訊時，改成大播放鍵＋進度條的
    // 自訂播放器（不用瀏覽器原生介面），文字說明（如果有）放在播放器下方當作附註。
    let bodyHtml;
    if (isPrimaryAudio && latest && latest.media) {
      bodyHtml = audioPlayerHtml(entryFullUrl(latest), latest.duration, { big: true }) +
        (latest.comment ? '<div class="story-caption">' + esc(latest.comment) + '</div>' : '');
    } else if (latest) {
      bodyHtml = esc(latest.comment);
    } else {
      bodyHtml = '<span class="empty">' + esc(t(isPrimaryAudio ? 'sound_story_empty' : 'story_empty')) + '</span>';
    }

    // 故事 / 說明（版本化，預設只顯示最新版）
    const story = document.createElement('div'); story.className = 'story';
    story.innerHTML =
      (hasPrimary ? '' : '<div class="story-head">' + esc(t('location_story_title')) + '</div>') +
      '<div class="story-body">' + bodyHtml + '</div>' +
      (byLine ? '<div class="story-by">' + byLine + '</div>' : '') +
      sourceLine +
      '<div class="story-actions" id="storyActions">' +
      (!EMBED && versions.length > 1 ? '<button class="btn small" id="histBtn">' + esc(t('history_versions', { n: versions.length })) + '</button>' : '') +
      '</div><div id="descHistory" style="display:none"></div>';
    box.appendChild(story);
    if (PRIMARY_BOX === 'audio' && latest && latest.media) wireAudioPlayer(story, current.num);
    const hb = story.querySelector('#histBtn'); if (hb) hb.onclick = () => toggleHistory(versions);

    // 插件掛勾點：讓插件（例如上傳、依序探索）在照片牆前面插入自己的提示區塊或按鈕（如「上傳照片到這個點」「僅顯示 X 的照片・顯示全部」）
    entriesHintFns.forEach(fn => { const el = fn(current); if (el) box.appendChild(el); });

    // 投稿牆：只有這張地圖除了主要內容型別外還能投其他型別時才顯示（比照 contribution.js
    // initTabs() 的判斷方式）——否則投稿牆永遠不會有內容，不該固定顯示「還沒有投稿」空狀態
    const hasExtraKinds = CONTRIB_CFG.kinds.some(k => k !== PRIMARY_KIND);
    if (hasExtraKinds) {
      const gwrap = document.createElement('div');
      gwrap.className = 'gallery';   // 大卡片模式時靠這個 class 排成多欄
      if (!entries.length) gwrap.innerHTML = '<div class="empty" style="margin-top:12px">' + esc(t('photos_empty')) + '</div>';
      entries.forEach(e => {
        const d = document.createElement('div'); d.className = 'entry sl-kind-' + kindOf(e); d.dataset.entryId = e.id;
        const alt = esc(e.comment || (current.chair || current.theme || t('contrib_photo_alt')));
        const canEdit = canPost() && (isMine(e) || APP.isManager);
        // 只有預覽區依型別換掉，底下的 meta／編輯／刪除／歷史四段所有型別完全共用
        d.innerHTML = entryPreviewHtml(e, alt) + '<div class="meta"><div class="who">' + esc(e.name || t('anon_fallback')) +
          (e.edited ? ' <span class="edited-tag">' + esc(t('edited_tag')) + '</span>' : '') + '</div>' +
          '<div class="time">' + fmtTime(e.photo_time || e.created_at) + '</div>' +
          (e.comment ? '<div class="txt">' + esc(e.comment) + '</div>' : '') +
          '<div class="entry-actions">' +
          (canEdit ? '<button class="btn small edit-btn" type="button"><i class="fa-solid fa-pen"></i> ' + esc(t('edit')) + '</button>' : '') +
          (!EMBED && e.editHistory && e.editHistory.length > 1 ? '<button class="btn small hist-btn" type="button">' + esc(t('history_versions', { n: e.editHistory.length })) + '</button>' : '') +
          (!EMBED && isMine(e) ? '<button class="del-btn" type="button"><i class="fa-solid fa-trash"></i> ' + esc(t('delete')) + '</button>' : '') + '</div>' +
          '</div><div class="photo-editor" style="display:none"></div><div class="photo-history" style="display:none"></div>';
        const open = d.querySelector('.sl-open');   // 文字與音訊沒有這個元素：文字不開燈箱，音訊直接在卡片上聽
        if (open) {
          open.onclick = () => openLightbox(e);
          open.onkeydown = (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openLightbox(e); } };
        }
        if (d.querySelector('.sl-aplay')) wireAudioPlayer(d, e.item_num);
        const del = d.querySelector('.del-btn'); if (del) del.onclick = () => deleteEntry(e.id);
        const edbtn = d.querySelector('.edit-btn'); if (edbtn) edbtn.onclick = () => togglePhotoEditor(e, d);
        const hbtn = d.querySelector('.hist-btn'); if (hbtn) hbtn.onclick = () => togglePhotoEditHistory(e, d);
        const actions = d.querySelector('.entry-actions');
        if (actions) entryActionFns.forEach(fn => { const el = fn(e); if (el) actions.appendChild(el); });
        gwrap.appendChild(d);
      });
      box.appendChild(gwrap);
    }
  }
  // 卡片牆的預覽區。`.sl-open` 是「點了會開燈箱」的標記，只有需要放大／播放的型別才給。
  function entryPreviewHtml(e, alt) {
    const def = kindDef(e);
    const dur = e.duration ? '<span class="sl-dur">' + esc(fmtDur(e.duration)) + '</span>' : '';
    if (def.box === 'image') {
      return '<img class="sl-open" src="' + esc(entryThumbUrl(e) || '') + '" loading="lazy" decoding="async" alt="' + alt + '" tabindex="0">';
    }
    if (def.box === 'video') {
      // 只鋪封面圖不放 <video>：一個地點可能有十幾則投稿，全部掛播放器等於同時開十幾條連線
      const poster = entryThumbUrl(e);
      return '<div class="sl-prev sl-prev-video sl-open" role="button" tabindex="0" aria-label="' + alt + '">' +
        (poster
          ? '<img src="' + esc(poster) + '" loading="lazy" decoding="async" alt="' + alt + '">'
          : '<div class="sl-prev-blank"><i class="fa-solid ' + def.icon + '"></i></div>') +
        '<span class="sl-play"><i class="fa-solid fa-play"></i></span>' + dur + '</div>';
    }
    if (def.box === 'audio') {
      // 音訊反過來：沒有東西好放大，直接在卡片上聽最省事
      return '<div class="sl-prev sl-prev-audio">' + audioPlayerHtml(entryFullUrl(e), e.duration) + '</div>';
    }
    return '';   // 文字投稿沒有預覽區，內容就是底下 meta 裡的 .txt
  }
  // 自訂音訊播放器：大顆播放鍵＋進度條，不用瀏覽器原生介面（沒有音量／速度／下載等雜項設定）。
  // 卡片牆與故事區的主要聲音內容共用同一個元件；headless 的 <audio> 仍吃 preload=none，捲過不會下載。
  function audioPlayerHtml(src, dur, opts) {
    opts = opts || {};
    return '<div class="sl-aplay' + (opts.big ? ' sl-aplay-lg' : '') + '">' +
      '<audio preload="none" src="' + esc(src || '') + '"></audio>' +
      '<button class="sl-play-btn sl-aplay-btn" type="button" aria-label="' + esc(t('play_audio_btn')) + '"><i class="fa-solid fa-play"></i></button>' +
      '<div class="sl-aplay-main">' +
        '<div class="sl-aplay-bar"><div class="sl-aplay-fill"></div></div>' +
        '<div class="sl-aplay-time"><span class="sl-aplay-cur">0:00</span><span class="sl-aplay-dur">' + esc(dur ? fmtDur(dur) : '') + '</span></div>' +
      '</div></div>';
  }
  // 幫一顆 .sl-aplay 接上互動：播放鍵切換、進度條點擊／拖曳定位、目前時間更新；
  // itemNum 有給值時順便接上 bindAudioPlayState()，讓地圖標記的播放脈衝對這顆播放器也生效。
  function wireAudioPlayer(root, itemNum) {
    const wrap = root.querySelector('.sl-aplay'); if (!wrap) return;
    const audio = wrap.querySelector('audio');
    const btn = wrap.querySelector('.sl-aplay-btn');
    const icon = btn.querySelector('i');
    const bar = wrap.querySelector('.sl-aplay-bar');
    const fill = wrap.querySelector('.sl-aplay-fill');
    const curEl = wrap.querySelector('.sl-aplay-cur');
    const durEl = wrap.querySelector('.sl-aplay-dur');
    btn.onclick = () => { if (audio.paused) audio.play().catch(() => {}); else audio.pause(); };
    audio.addEventListener('play', () => { icon.className = 'fa-solid fa-pause'; });
    audio.addEventListener('pause', () => { icon.className = 'fa-solid fa-play'; });
    audio.addEventListener('ended', () => { icon.className = 'fa-solid fa-play'; });
    audio.addEventListener('timeupdate', () => {
      if (audio.duration) fill.style.width = (audio.currentTime / audio.duration * 100) + '%';
      curEl.textContent = fmtDur(audio.currentTime);
    });
    audio.addEventListener('loadedmetadata', () => { if (!durEl.textContent && audio.duration) durEl.textContent = fmtDur(audio.duration); });
    let dragging = false;
    const seek = (clientX) => {
      const r = bar.getBoundingClientRect();
      const ratio = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
      if (!audio.duration) return;
      audio.currentTime = ratio * audio.duration;
      fill.style.width = (ratio * 100) + '%';
      curEl.textContent = fmtDur(audio.currentTime);
    };
    bar.addEventListener('pointerdown', ev => { dragging = true; bar.setPointerCapture(ev.pointerId); seek(ev.clientX); });
    bar.addEventListener('pointermove', ev => { if (dragging) seek(ev.clientX); });
    bar.addEventListener('pointerup', () => { dragging = false; });
    if (itemNum != null) bindAudioPlayState(audio, itemNum);
    return audio;
  }
  const tv = (e) => new Date(e.photo_time || e.created_at).getTime() || 0;
  function chairColorOf(num) { const p = POINTS.find(x => x.num === num); return p ? p.color : '#888'; }

  // 編輯照片的留言/關聯地點/定位共用的面板內容建構（原始照片檔案本身不可更換）。
  // opts.onCancel / opts.onSaved 讓呼叫端（照片卡片 vs. 單張檢視）各自決定取消/儲存完成後要做什麼。
  function buildPhotoEditorPanel(e, panel, opts) {
    opts = opts || {};
    panel.innerHTML =
      '<textarea class="pe-cmt" placeholder="' + esc(t('write_something_placeholder')) + '">' + esc(e.comment || '') + '</textarea>' +
      '<label class="c-lab">' + esc(t('related_point_label')) + '</label>' +
      '<select class="pe-chair"></select>' +
      '<div class="mini pe-mini"></div>' +
      '<div class="loc pe-loc"></div>' +
      '<div class="row"><button class="btn small pe-cancel" type="button">' + esc(t('cancel')) + '</button><button class="btn primary small pe-save" type="button">' + esc(t('save')) + '</button><span class="status pe-status"></span></div>';
    const sel = panel.querySelector('.pe-chair');
    sel.innerHTML = chairOptionsHtml(e.item_num);
    const miniDiv = panel.querySelector('.pe-mini');
    const lat0 = typeof e.lat === 'number' ? e.lat : META.center[0];
    const lon0 = typeof e.lon === 'number' ? e.lon : META.center[1];
    const picker = engine.createMiniPicker(miniDiv, { lat: lat0, lon: lon0, zoom: 16 });
    panel._picker = picker;
    const state = { lat: lat0, lon: lon0, source: e.loc_source || 'manual' };
    const locEl = panel.querySelector('.pe-loc');
    locEl.innerHTML = locNote(state.source) + ' <span class="loc-hint">' + esc(t('drag_to_fix_hint')) + '</span>';
    const setBorder = () => { miniDiv.style.borderColor = chairColorOf(sel.value ? +sel.value : null); };
    setBorder();
    sel.onchange = setBorder;
    const updLoc = (pos) => {
      state.lat = pos.lat; state.lon = pos.lon; state.source = 'manual';
      locEl.innerHTML = locNote('manual') + ' <span class="loc-hint">' + esc(t('drag_to_fix_hint')) + '</span>';
    };
    picker.onChange(updLoc);

    panel.querySelector('.pe-cancel').onclick = () => {
      picker.destroy(); panel._picker = null; panel.style.display = 'none'; panel.innerHTML = '';
      if (opts.onCancel) opts.onCancel();
    };
    panel.querySelector('.pe-save').onclick = () => submitPhotoEdit(e, {
      comment: panel.querySelector('.pe-cmt').value,
      item_num: sel.value,
      lat: state.lat, lon: state.lon, loc_source: state.source,
    }, panel, picker, opts.onSaved);
  }
  // 每張卡片一個可收合面板，同時只開一個。
  function togglePhotoEditor(e, container) {
    const panel = container.querySelector('.photo-editor');
    if (panel.style.display !== 'none') { const p2 = panel._picker; if (p2) p2.destroy(); panel.style.display = 'none'; panel.innerHTML = ''; return; }
    document.querySelectorAll('.photo-editor').forEach(p => { if (p !== panel) { const p2 = p._picker; if (p2) p2.destroy(); p.style.display = 'none'; p.innerHTML = ''; } });
    const histPanel = container.querySelector('.photo-history'); if (histPanel) { histPanel.style.display = 'none'; histPanel.innerHTML = ''; }
    panel.style.display = 'block';
    buildPhotoEditorPanel(e, panel);
  }
  // 單張檢視（lightbox）裡的獨立編輯面板：有些照片沒有對應的地點（item_num 為空），
  // 側欄的照片牆是依地點分組顯示，這種照片永遠不會出現在任何照片牆卡片上，
  // 所以這裡不能靠「捲到卡片」，要有一份完全獨立、平行的編輯入口。
  function toggleLightboxEditor(e) {
    const panel = document.getElementById('lbEditor');
    const cap = document.getElementById('lbCap');
    if (panel.style.display !== 'none') { const p2 = panel._picker; if (p2) p2.destroy(); panel.style.display = 'none'; panel.innerHTML = ''; if (cap) cap.style.display = ''; return; }
    document.querySelectorAll('.photo-editor').forEach(p => { if (p !== panel) { const p2 = p._picker; if (p2) p2.destroy(); p.style.display = 'none'; p.innerHTML = ''; } });
    if (cap) cap.style.display = 'none';  // 編輯面板跟 caption 都貼底置中，同時顯示會疊在一起，編輯時先收起 caption
    panel.style.display = 'block';
    buildPhotoEditorPanel(e, panel, {
      onCancel: () => { if (cap) cap.style.display = ''; },
      onSaved: () => {
        const updated = effectiveEntries().find(x => x.id === e.id) || e;
        openLightbox(updated);
      }
    });
  }
  async function submitPhotoEdit(orig, vals, panel, picker, onSaved) {
    const btn = panel.querySelector('.pe-save'); const status = panel.querySelector('.pe-status');
    btn.disabled = true; status.textContent = t('saving');
    try {
      const fd = new FormData();
      fd.append('project', PROJECT);
      fd.append('edit_of', orig.id);
      if (vals.item_num !== '' && vals.item_num != null) fd.append('item_num', vals.item_num);
      fd.append('comment', (vals.comment || '').trim());
      if (vals.lat != null) fd.append('lat', vals.lat);
      if (vals.lon != null) fd.append('lon', vals.lon);
      fd.append('loc_source', vals.loc_source || 'manual');
      fd.append('name', displayName());
      fd.append('owner', ownerToken());
      const ct = contribToken(); if (ct) fd.append('ctoken', ct);
      if (APP.isManager) fd.append('csrf', APP.csrf || '');
      const res = await fetch(apiUrl('editentry'), { method: 'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || ('HTTP ' + res.status));
      CONTRIB.push(j.item);
      picker.destroy(); panel._picker = null;
      panel.style.display = 'none'; panel.innerHTML = '';
      recount(); renderChairs(); renderContribLayer(); rebuildPersonFilter(); emitHook('stateChange');
      if (current) renderEntries();
      if (onSaved) onSaved(j.item);
    } catch (err) {
      status.textContent = t('save_failed', { err: err.message });
      btn.disabled = false;
    }
  }
  // 照片編輯歷史（比照故事的 toggleHistory）：原始投稿與之後每一次編輯都保留，新到舊列出
  function togglePhotoEditHistory(e, container) {
    const panel = container.querySelector('.photo-history');
    if (panel.style.display !== 'none') { panel.style.display = 'none'; panel.innerHTML = ''; return; }
    const editor = container.querySelector('.photo-editor'); if (editor) { const p2 = editor._picker; if (p2) p2.destroy(); editor.style.display = 'none'; editor.innerHTML = ''; }
    panel.style.display = 'block';
    const list = (e.editHistory || []).slice().reverse();
    panel.innerHTML = '<div class="hist-title">' + esc(t('photo_history_title')) + '</div>' +
      list.map((v, i) => {
        const isOrig = !v.edit_of;
        return '<div class="hist-item"><div class="hist-meta">' + (i === 0 ? '<b>' + esc(t('latest_tag')) + '</b>・' : '') +
          esc(v.name || t('anon_fallback')) + '・' + fmtTime(v.photo_time || v.created_at) +
          (isOrig ? esc(t('original_submission_tag')) : '') +
          (!EMBED && isMine(v) && !isOrig ? ' <button class="del-btn" type="button" data-id="' + esc(v.id) + '">' + esc(t('delete')) + '</button>' : '') + '</div>' +
          '<div class="hist-txt">' + (v.comment ? esc(v.comment) : '<span class="empty">' + esc(t('no_comment')) + '</span>') + '</div></div>';
      }).join('');
    panel.querySelectorAll('.del-btn[data-id]').forEach(b => b.onclick = () => deleteEntry(b.dataset.id));
  }

  function toggleHistory(descs) {
    const el = document.getElementById('descHistory');
    if (el.style.display === 'none') {
      el.style.display = 'block';
      el.innerHTML = '<div class="hist-title">' + esc(t('desc_history_title')) + '</div>' +
        descs.slice().reverse().map((d, i) =>
          '<div class="hist-item"><div class="hist-meta">' + (i === 0 ? '<b>' + esc(t('latest_tag')) + '</b>・' : '') +
          (d.baseline ? esc(t('original_source_tag')) : esc(d.name || t('anon_fallback')) + '・' + fmtTime(d.photo_time || d.created_at)) +
          (!EMBED && isMine(d) ? ' <button class="del-btn" type="button" data-id="' + esc(d.id) + '">' + esc(t('delete')) + '</button>' : '') + '</div>' +
          '<div class="hist-txt">' + (d.comment ? esc(d.comment) : '<span class="empty">' + esc(t('no_comment')) + '</span>') + '</div></div>').join('');
      el.querySelectorAll('.del-btn[data-id]').forEach(b => b.onclick = () => deleteEntry(b.dataset.id));
    } else { el.style.display = 'none'; el.innerHTML = ''; }
  }
  /* ---------- lightbox ---------- */
  // 單張的「i」資訊內容：相機 EXIF（機身/鏡頭/光圈/快門/焦段/ISO）、拍攝時間、座標與定位來源
  function photoInfoHtml(e) {
    const x = e.exif || {};
    const rows = [];
    // 影音沒有 EXIF，但有長度與檔案型別，這兩項填在同一張資訊表上（欄位缺就整列不出現）
    if (e.duration) rows.push([t('info_duration'), esc(fmtDur(e.duration))]);
    if (e.media_mime) rows.push([t('info_media_type'), esc(e.media_mime)]);
    const cam = [x.make, x.model].filter(Boolean).join(' ');
    if (cam) rows.push([t('info_camera'), esc(cam)]);
    if (x.lens) rows.push([t('info_lens'), esc(x.lens)]);
    const shot = [];
    if (x.f) shot.push('f/' + (+x.f));
    if (x.exp) shot.push((+x.exp) >= 1 ? (+x.exp) + 's' : '1/' + Math.round(1 / (+x.exp)) + 's');
    if (x.focal) shot.push((+x.focal) + 'mm');
    if (x.iso) shot.push('ISO ' + (+x.iso));
    if (shot.length) rows.push([t('info_params'), shot.join(' · ')]);
    if (x.sw) rows.push([t('info_software'), esc(x.sw)]);
    // 「沒有相機資訊」只對照片說得通；影音本來就不會有 EXIF，不必特地報告一次
    if (!rows.length && kindDef(e).box === 'image') rows.push([t('info_camera'), '<span class="empty">' + esc(t('info_no_camera')) + '</span>']);
    const shotTime = e.photo_time || e.created_at;
    if (shotTime) rows.push([t('info_shot_time'), fmtTime(shotTime)]);
    if (e.lat != null && e.lon != null) rows.push([t('info_coords'), (+e.lat).toFixed(5) + ', ' + (+e.lon).toFixed(5)]);
    rows.push([t('info_loc_source'), locNote(e.loc_source)]);
    return rows.map(r => '<div class="lbi-row"><span class="lbi-k">' + r[0] + '</span><span class="lbi-v">' + r[1] + '</span></div>').join('');
  }
  // 傳整筆投稿資料進來，才能連留言／說明文字一起顯示成卡片（不只圖片+姓名時間）
  function openLightbox(e, url) {
    const def = kindDef(e);
    if (def.box === 'text') return;   // 文字投稿沒有東西好放大，內容本來就整段顯示在卡片上
    const lbImg = document.getElementById('lbImg');
    const lbMedia = document.getElementById('lbMedia');
    const nextUrl = url || entryFullUrl(e);
    // 換一則投稿一定要先卸掉上一個播放器：留著它 src 不變，音軌會在燈箱關掉後繼續在背景播
    clearLightboxMedia();
    if (def.box === 'video' || def.box === 'audio') {
      lbImg.removeAttribute('src');
      lbImg.style.display = 'none';
      const poster = def.box === 'video' ? entryThumbUrl(e) : null;
      lbMedia.innerHTML = def.box === 'video'
        ? '<video controls autoplay playsinline preload="metadata"' + (poster ? ' poster="' + esc(poster) + '"' : '') +
          ' src="' + esc(nextUrl || '') + '"></video>'
        : '<div class="lb-audio"><i class="fa-solid ' + def.icon + '" aria-hidden="true"></i>' +
          '<audio controls preload="metadata" src="' + esc(nextUrl || '') + '"></audio></div>';
      lbMedia.style.display = '';
      if (def.box === 'audio') { const lbAudio = lbMedia.querySelector('audio'); if (lbAudio) bindAudioPlayState(lbAudio, e.item_num); }
    } else {
      lbImg.style.display = '';
      // 換照片時先淡出，避免舊照片在新資訊（姓名/時間/說明）已更新後還被誤認成「已載入完成」
      if (lbImg.src !== new URL(nextUrl, location.href).href) {
        lbImg.classList.add('loading');
        lbImg.onload = lbImg.onerror = () => lbImg.classList.remove('loading');
      }
      lbImg.src = nextUrl;
    }
    // 照片資訊改成「時間後面的 i 小圖示」，不佔一顆獨立按鈕（id 不變，沿用原本的展開邏輯）
    const who = esc(e.name || t('anon_fallback')) + ' ・ ' + fmtTime(e.photo_time || e.created_at) +
      ' <button class="lb-info-i" type="button" id="lbInfoBtn" title="' + esc(t('photo_info_title')) + '" aria-label="' + esc(t('photo_info_title')) + '"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button>';
    const txt = e.comment ? '<div class="lb-txt">' + esc(e.comment) + '</div>' : '';
    const canEdit = canPost() && (isMine(e) || APP.isManager);
    const actions =
      (canEdit ? '<button class="btn small" type="button" id="lbEditBtn"><i class="fa-solid fa-pen"></i> ' + esc(t('edit')) + '</button>' : '') +
      (!EMBED && isMine(e) ? '<button class="btn small danger" type="button" id="lbDelBtn"><i class="fa-solid fa-trash"></i> ' + esc(t('delete')) + '</button>' : '');
    const cap = document.getElementById('lbCap');
    cap.style.display = '';
    cap.innerHTML = '<div class="lb-who">' + who + '</div>' + txt + (actions ? '<div class="lb-actions">' + actions + '</div>' : '') +
      '<div class="lb-info" id="lbInfo" style="display:none"></div>';
    const ib = cap.querySelector('#lbInfoBtn');
    if (ib) ib.onclick = (ev) => {
      ev.stopPropagation();
      const box = cap.querySelector('#lbInfo');
      if (box.style.display === 'none') { box.innerHTML = photoInfoHtml(e); box.style.display = ''; feature('info'); }
      else { box.style.display = 'none'; box.innerHTML = ''; }
    };
    const eb = cap.querySelector('#lbEditBtn');
    if (eb) eb.onclick = (ev) => { ev.stopPropagation(); toggleLightboxEditor(e); };
    const db = cap.querySelector('#lbDelBtn');
    if (db) db.onclick = (ev) => { ev.stopPropagation(); closeLightbox(); deleteEntry(e.id); };
    // 換一張照片時，上一張留在 lbEditor 裡未存檔的編輯面板（含迷你地圖）要先清掉，避免殘留
    const oldPanel = document.getElementById('lbEditor');
    if (oldPanel) { const p2 = oldPanel._picker; if (p2) p2.destroy(); oldPanel._picker = null; oldPanel.style.display = 'none'; oldPanel.innerHTML = ''; }
    document.getElementById('lb').style.display = 'flex';
  }
  // 清掉燈箱裡的播放器。用 pause() + removeAttribute('src') + load() 三步而不只是清 innerHTML：
  // 光把節點拿掉，某些瀏覽器仍會讓已經開始的音訊播完那一段緩衝。
  function clearLightboxMedia() {
    const lbMedia = document.getElementById('lbMedia');
    if (!lbMedia) return;
    lbMedia.querySelectorAll('video, audio').forEach(el => {
      try { el.pause(); el.removeAttribute('src'); el.load(); } catch (err) {}
    });
    lbMedia.innerHTML = '';
    lbMedia.style.display = 'none';
  }
  function closeLightbox() {
    const panel = document.getElementById('lbEditor');
    if (panel) { const p2 = panel._picker; if (p2) p2.destroy(); panel._picker = null; panel.style.display = 'none'; panel.innerHTML = ''; }
    clearLightboxMedia();
    document.getElementById('lb').style.display = 'none';
  }

  // 照片定位來源標示：lightbox 資訊面板（核心）與上傳插件的批次卡片共用，故留在核心並開放給插件呼叫
  const SRC_META = {
    exif:    { key: 'loc_src_exif',    tone: 'ok',    icon: 'fa-location-dot' },
    device:  { key: 'loc_src_device',  tone: 'warn',  icon: 'fa-location-crosshairs' },
    chair:   { key: 'loc_src_chair',   tone: 'muted', icon: 'fa-location-dot' },
    manual:  { key: 'loc_src_manual',  tone: 'info',  icon: 'fa-hand-pointer' },
    default: { key: 'loc_src_default', tone: 'muted', icon: 'fa-location-dot' },
  };
  function srcTone(src) { return (SRC_META[src] || SRC_META.default).tone; }
  function locNote(src) {
    const m = SRC_META[src] || SRC_META.default;
    return '<span class="loc-src ' + m.tone + '"><i class="fa-solid ' + m.icon + '"></i> ' + esc(t(m.key)) + '</span>';
  }

  // 地圖標記角標與「投稿」鈕的數字：所有型別一起算（文字投稿也是一則投稿）
  let contribTotal = 0;
  function recount() {
    counts = {}; contribTotal = 0; audioPoints = new Set();
    effectiveEntries().forEach(e => {
      contribTotal++;
      if (e.item_num != null) {
        counts[e.item_num] = (counts[e.item_num] || 0) + 1;
        if (kindOf(e) === 'audio') audioPoints.add(e.item_num);
      }
    });
    // 主要內容型別若是音訊（如聲音地圖的錄音），不算進投稿數，但地圖標記仍要標示「這個點有聲音」
    if (PRIMARY_BOX === 'audio') {
      CONTRIB.forEach(e => { if (e.kind === PRIMARY_KIND && e.item_num != null) audioPoints.add(e.item_num); });
    }
    updatePhotoBtn();
  }
  // 供插件在自己完成一次會影響地圖/清單顯示的動作（例如上傳、建立地點）後，一次重繪所有受影響的畫面。
  // rebuildCats() 要排在 renderChairs() 之前：新建立的地點可能帶來一個新分類，圖例得先有那一格。
  function refreshAll() { rebuildCats(); buildLegend(); recount(); renderChairs(); renderContribLayer(); rebuildPersonFilter(); emitHook('stateChange'); renderEntries(); }
  // 「投稿」鈕顯示投稿總則數；有投稿的地點數移到 title 提示裡
  function updatePhotoBtn() {
    const btn = document.getElementById('photoLayerBtn');
    if (!btn) return;
    const nPts = Object.keys(counts).length;
    btn.innerHTML = '<i class="fa-solid fa-photo-film"></i> ' + esc(t('contrib')) + (contribTotal ? ' <span class="cnt">' + contribTotal + '</span>' : '');
    btn.title = contribTotal
      ? t('photo_layer_title_active', { n: contribTotal, a: nPts, b: effectivePoints().length })
      : t('photo_layer_title_inactive');
  }

  /* ---------- data loading ---------- */
  async function loadContributions() {
    try {
      const res = await fetch(apiUrl('list') + '&project=' + encodeURIComponent(PROJECT));
      const j = await res.json();
      if (j.error) throw new Error(j.error + (j.detail ? '：' + j.detail : ''));
      CONTRIB = (j.items || []).map(x => ({ ...x, lat: x.lat != null ? +x.lat : null, lon: x.lon != null ? +x.lon : null }));
      // 投稿裡可能含 kind:'newpoint'（訪客建立的地點），分類與圖例得重算一次才看得到那些點
      rebuildCats(); buildLegend();
      recount(); renderChairs(); renderContribLayer(); rebuildPersonFilter(); emitHook('stateChange');
      // 注意：這裡不因為 filterPerson 記得先前篩選就把地圖對焦過去——那樣專案層級的進站縮放
      // 會被投稿者自己散落各地的投稿點拉開，核心點位範圍反而被壓縮成一小塊。進站永遠維持
      // boot() 那份只看 POINTS 的縮放；使用者自己從下拉選單「重新選取」投稿者時（見下方
      // #personFilter 的 onchange）才會對焦到那個人的範圍，這是刻意保留的互動行為。
      document.getElementById('cloudWarn').style.display = 'none';
    } catch (e) {
      document.getElementById('cloudWarn').style.display = 'block';
      document.getElementById('cloudWarn').textContent = t('backend_conn_failed', { detail: e.message ? ('（' + e.message + '）') : '' });
    }
  }

  async function boot() {
    // 資料由 view.php 伺服器端內嵌（框架不供應靜態檔）；獨立部署時退回 fetch。
    META = APP.meta || await fetch(APP.base + 'projects/' + PROJECT + '/meta.json').then(r => r.json());
    POINTS = APP.points || await fetch(APP.base + 'projects/' + PROJECT + '/' + (META.points || 'points.json')).then(r => r.json());
    document.title = (META.title || t('map_title_fallback')) + (META.subtitle ? '・' + META.subtitle : '');
    document.getElementById('titleTxt').textContent = META.title || t('map_title_fallback');
    document.getElementById('titleSub').textContent = META.subtitle ? '・' + META.subtitle : '';
    // 資料來源連結（meta.sources = [{label,url}]，可展開看原始 StoryMaps）與原始單位署名（meta.credit）
    var srcLinks = '';
    if (Array.isArray(META.sources) && META.sources.length) {
      srcLinks = '<details class="src-links"><summary>' + esc(t('source_links_summary', { n: META.sources.length })) + '</summary>' +
        META.sources.map(function (s) {
          var safeUrl = /^https?:\/\//i.test(s.url || '') ? s.url : '#';
          return '<a href="' + esc(safeUrl) + '" target="_blank" rel="noopener">' + esc(s.label || s.url) + '</a>';
        }).join('') +
        '</details>';
    }
    document.getElementById('foot').innerHTML =
      (META.source ? '<div class="foot-src">' + esc(t('source_label', { src: META.source })) + '</div>' : '') +
      srcLinks +
      (META.credit ? '<div class="foot-src">' + esc(META.credit) + '</div>' : '') +
      '<div class="foot-note">' + esc(t('contrib_public_notice')) + '<a href="' + esc(APP.base) + 'privacy" target="_blank" rel="noopener">' + esc(t('privacy_link_text')) + '</a></div>';

    rebuildCats();   // 此時 CONTRIB 還是空的，結果就是 POINTS 的分類；投稿載入後會再算一次

    // 主引擎依 APP.engine（view.php 依 layers 裡有沒有 type:vector 算出來的）選擇。
    // MapLibre 沒有 <script src> 吃得下去的全域版本（v6 只出 ESM），前面 <head> 那段
    // type="module" shim 只保證晚於文件解析完才執行、服務不了這裡同步的 boot()——
    // 所以主引擎是 maplibre 時自己 import() 動態載入，跟 map3d.js 對 three.js 的
    // lazy-load 手法一致（見該檔 maybeLoadThree()）；跟 map3d 開關同時開時載入同一個
    // 網址，瀏覽器 module 快取本來就會共用，不會重複下載。
    if (APP.engine === 'maplibre' && !window.maplibregl) {
      window.maplibregl = await import('https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.mjs');
    }
    const EngineClass = APP.engine === 'maplibre' ? window.MapLibreEngine : window.LeafletEngine;
    engine = new EngineClass({
      container: 'map', center: META.center || [23.9, 120.7], zoom: META.zoom || 14,
      dark: isDark(), manifests: layerManifests(),
    });
    engine.mountControls({ zoomPosition: 'bottomleft', attributionPosition: 'bottomright', opButtons: [{ el: document.getElementById('resetBtn') }] });
    engine.onZoomThresholdCross(THUMB_ZOOM, () => { if (photoLayerOn) renderContribLayer(); });

    buildLegend();
    renderChairs();
    if (POINTS.length) engine.fitBounds(POINTS.map(c => [c.lat, c.lon]), { pad: 0.08 });

    // 封面快照要等圖磚真的畫完才擷圖，不然存到的是半載入的畫面；只有 MapLibre 引擎有 'idle' 事件
    // 可等（見 engine.supportsSnapshot），Leaflet 專案這裡什麼都不會發生。
    if (engine.supportsSnapshot) {
      const forceSnap = new URLSearchParams(location.search).get('snapcover') === 'force';
      engine.getRawMap().once('idle', () => trySnapshotCover(forceSnap));
    }

    // 暱稱隱藏欄位（單一真實來源；與上傳視窗 #modalName 的同步交給 contribution-upload.js 自己處理）
    const myName = document.getElementById('myName');
    myName.value = localStorage.getItem('myName') || '';

    // 收折
    // 品牌互動：連點變形狀（點→線→三→四→五→六角）→ 六角小彩蛋；長按 → 管理入口
    // delegation 關閉時這張地圖不接受新的專案 PIN／邀請登入，連彩蛋入口都不掛，管理者一律走 /manager 直接登入
    if (MOD('delegation')) setupBrandEgg();

    // 主題切換（系統／淺／深）
    updateThemeIcon();
    document.getElementById('themeBtn').onclick = () => {
      const order = ['system', 'light', 'dark'];
      applyTheme(order[(order.indexOf(themeMode) + 1) % 3]);
      feature('theme');
    };
    if (window.matchMedia) window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (themeMode === 'system' && engine) engine.applyTheme(isDark()); });

    // 語言切換（手動覆寫）：客製化下拉（原生 select 選單樣式無法跟主題搭配），選了哪個就切哪個，
    // 帶著目前網址參數整頁重新載入，讓伺服器端重新解析並寫入 lang cookie
    const langMenu = document.getElementById('langMenu');
    const langBtn = document.getElementById('langBtn');
    const langList = document.getElementById('langList');
    if (langMenu && langBtn && langList) {
      langBtn.onclick = () => {
        const open = langMenu.classList.toggle('open');
        langBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      langList.querySelectorAll('li').forEach(li => {
        li.onclick = () => {
          const url = new URL(location.href);
          url.searchParams.set('lang', li.dataset.lang);
          location.href = url.toString();
        };
      });
      document.addEventListener('click', e => {
        if (langMenu.classList.contains('open') && !langMenu.contains(e.target)) {
          langMenu.classList.remove('open');
          langBtn.setAttribute('aria-expanded', 'false');
        }
      });
    }

    const controls = document.getElementById('controls');
    const chevron = (collapsed) => '<i class="fa-solid fa-chevron-' + (collapsed ? 'right' : 'down') + '"></i>';
    // 沒存過使用者偏好時，手機版（同 CSS 斷點 640px）預設收合，桌機維持展開
    const savedCollapsed = localStorage.getItem('ctlCollapsed');
    const startCollapsed = savedCollapsed !== null ? savedCollapsed === '1' : window.matchMedia('(max-width:640px)').matches;
    if (startCollapsed) { controls.classList.add('collapsed'); document.getElementById('collapseBtn').innerHTML = chevron(true); }
    document.getElementById('collapseBtn').onclick = function () {
      const c = controls.classList.toggle('collapsed');
      this.innerHTML = chevron(c);
      localStorage.setItem('ctlCollapsed', c ? '1' : '0');
    };

    // 定位點微調（僅管理者，顯示與否由 openPanel() 依 APP.isManager 控制）
    const peBtn = document.getElementById('pointEditBtn');
    if (peBtn) peBtn.onclick = togglePointEditor;

    // 全部點位／投稿：互斥的顯示模式，預設「全部」；?contrib=1（嵌入用）或曾記住的投稿者篩選可預設改成「投稿」
    // apb/plb 不存在＝這張地圖關了 contribBrowse 模組（見 api/features.php），photoLayerOn 永遠留在
    // 預設值 false，行為等同一直停在「全部」模式；#personFilter 仍會渲染，只是只剩「跳到地點」用途。
    const apb = document.getElementById('allPointsBtn'), plb = document.getElementById('photoLayerBtn');
    if (apb && plb) {
      function setContribMode(on, opts) {
        photoLayerOn = on;
        plb.classList.toggle('on', on);
        apb.classList.toggle('on', !on);
        document.body.classList.toggle('focus-contrib', on);
        if (!on) filterPerson = '';   // 切回「全部」時，下拉選單改用途（跳到地點），投稿者篩選狀態一併清掉
        rebuildPersonFilter();
        renderContribLayer();
        if (on && !(opts && opts.silent)) feature('photos');
        renderChairs(); emitHook('stateChange');
      }
      apb.onclick = function () { if (photoLayerOn) setContribMode(false); };
      plb.onclick = function () { if (!photoLayerOn) setContribMode(true); };
      const personPrefKey = 'filterPerson_' + PROJECT;
      let savedPerson = ''; try { savedPerson = localStorage.getItem(personPrefKey) || ''; } catch (e) {}
      // ?contributor=<name>（分享／嵌入帶入的指定投稿者）優先於本機記住的篩選，但不落地存到 localStorage，
      // 避免別人分享的連結覆蓋掉這台裝置本來記住的篩選對象
      const urlPerson = params.get('contributor') || '';
      if (urlPerson) filterPerson = urlPerson;
      else if (savedPerson) filterPerson = savedPerson;
      setContribMode(params.get('contrib') === '1' || !!filterPerson, { silent: true });
    }

    // 下拉選單依模式切換用途：「投稿」模式＝篩選投稿者（看他的觀察地圖，選擇會記住，重新整理不會跑掉）；
    // 「全部」模式＝跳到指定地點標籤（不是持續篩選，選完會自動重置）
    const pf = document.getElementById('personFilter');
    if (pf) pf.onchange = () => {
      if (photoLayerOn) {
        filterPerson = pf.value;
        try { if (filterPerson) localStorage.setItem(personPrefKey, filterPerson); else localStorage.removeItem(personPrefKey); } catch (e) {}
        if (filterPerson) feature('filter');
        renderContribLayer(); renderChairs(); emitHook('stateChange');
        const pts = filterPerson ? personPoints(filterPerson).map(e => [e.lat, e.lon]) : [];
        if (pts.length) engine.fitBounds(pts, { pad: 0.25 });
      } else {
        const num = pf.value ? +pf.value : null;
        pf.value = '';
        if (num != null) {
          const pt = effectivePoints().find(p => p.num === num);
          if (pt) { emitHook('panelReset'); openPanel(pt); engine.panTo(pt.lat, pt.lon, { animate: true }); }
        }
      }
    };

    // 右上：手機收成漢堡（避免遮住卡片），點外部或 Esc 收合
    const trGroup = document.getElementById('topright');
    const trToggle = document.getElementById('trToggle');
    if (trToggle && trGroup) {
      trToggle.onclick = () => {
        const open = trGroup.classList.toggle('open');
        trToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      document.addEventListener('click', e => {
        if (trGroup.classList.contains('open') && !trGroup.contains(e.target)) {
          trGroup.classList.remove('open');
          trToggle.setAttribute('aria-expanded', 'false');
        }
      });
    }

    // 右上：重置；左下：重置地圖（身分指示鈕的渲染／互動見 assets/js/plugins/contributor-identity.js）
    const resetBtn = document.getElementById('resetBtn'); if (resetBtn) resetBtn.onclick = resetView;

    // 上傳權限：解鎖 FAB（右下）+ 彈窗（含 QR 掃描）+ 邀請連結 ?code=
    const ub = document.getElementById('unlockFab');
    if (ub) ub.onclick = openUnlock;
    if (document.getElementById('unlockDialog')) {
      document.getElementById('unlockSubmit').onclick = trySubmitUnlock;
      document.getElementById('scanBtn').onclick = startScan;
    }
    const arSubmit = document.getElementById('adminRedeemSubmit');
    if (arSubmit) arSubmit.onclick = trySubmitAdminRedeem;
    const arPin = document.getElementById('adminRedeemPinInput');
    if (arPin) arPin.addEventListener('keydown', e => { if (e.key === 'Enter') trySubmitAdminRedeem(); });
    const uci = document.getElementById('unlockCodeInput');
    if (uci) uci.addEventListener('keydown', e => { if (e.key === 'Enter') trySubmitUnlock(); });
    const pinBtn = document.getElementById('pinSubmitBtn');
    if (pinBtn) pinBtn.onclick = pinSubmit;
    const pinInputEl = document.getElementById('pinInput');
    if (pinInputEl) pinInputEl.addEventListener('keydown', e => { if (e.key === 'Enter') pinSubmit(); });
    if (params.get('code') && !EMBED && MOD('upload')) {
      const c = params.get('code');
      params.delete('code');
      const qs = params.toString();
      try { history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (e) {}
      doUnlock(c).then(r => { if (r.ok) toast('<i class="fa-solid fa-check"></i> ' + esc(t('invite_unlock_success'))); });
    }
    await handleRedeemFragment();
    applyPostState();

    // 關閉浮動卡片：×、地圖背景點擊、Esc
    const pc = document.querySelector('.p-close'); if (pc) pc.onclick = closePanel;
    engine.onBackgroundClick(() => closePanel());
    setupTitleMarquee();

    // 鍵盤快速鍵（無障礙）：方向鍵/＋－由 Leaflet 平移縮放；此處補全域鍵
    document.addEventListener('keydown', e => {
      const tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.metaKey || e.ctrlKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'escape') {
        closePanel(); closeUnlock(); closePin(); closeAdminRedeem(); closeShortcuts(); emitHook('closeAll');
        const trG = document.getElementById('topright'), trT = document.getElementById('trToggle');
        if (trG) { trG.classList.remove('open'); if (trT) trT.setAttribute('aria-expanded', 'false'); }
      }
      else if (k === 'r') { resetView(); }
      else if (k === 't') { const b = document.getElementById('themeBtn'); if (b) b.click(); }
    });
    const shortcutsBtn = document.getElementById('shortcutsBtn');
    if (shortcutsBtn) shortcutsBtn.onclick = openShortcuts;

    await computeMyHash();       // 先算出本裝置擁有者雜湊（供「刪自己的」判斷）
    await computeContribId();    // 若已建立投稿者身分，算出對外可見的投稿者ID（供「刪自己的」判斷）
    await loadContributions();
    statVisit();                 // 匿名累加瀏覽 / 工作階段 / 裝置別
    hideSkeleton();
  }
  function hideSkeleton() {
    const sk = document.getElementById('skeleton');
    if (!sk) return;
    sk.classList.add('hide');
    setTimeout(() => { if (sk.parentNode) sk.remove(); }, 500);
  }
  setTimeout(hideSkeleton, 9000);   // 保險：即使載入卡住也移除骨架

  // 目前畫面的篩選狀態（投稿者／分類，再加上插件透過 registerScopeParam() 註冊的額外參數），
  // 供分享連結／嵌入碼帶入範圍限制；沒有特別篩選時回傳空字串
  function currentScopeParams() {
    const sp = new URLSearchParams();
    if (photoLayerOn && filterPerson) sp.set('contributor', filterPerson);
    const visibleCats = CATS.filter(c => active[c.key] !== false).map(c => c.key);
    if (CATS.length && visibleCats.length < CATS.length) sp.set('cat', visibleCats.join(','));
    scopeParamFns.forEach(fn => {
      try {
        const extra = fn();
        if (extra) Object.entries(extra).forEach(([k, v]) => { if (v != null && v !== '') sp.set(k, v); });
      } catch (err) { console.error('[scopeParam]', err); }
    });
    return sp.toString();
  }
  // 重置地圖回初始視角（左下地圖操作）
  function resetView() {
    if (!engine) return;
    if (POINTS && POINTS.length) engine.fitBounds(effectivePoints().map(c => [c.lat, c.lon]), { pad: 0.08 });
    else engine.setView(META.center || [23.9, 120.7], META.zoom || 14);
    feature('reset');
  }
  // 標題單擊 → 若名稱溢出則跑馬燈一次（與形狀彩蛋並存，兩者都綁在同一次點擊）
  function setupTitleMarquee() {
    const el = document.getElementById('title');
    if (!el) return;
    const run = () => {
      if (el.scrollWidth > el.clientWidth + 2) {
        el.classList.remove('marquee'); void el.offsetWidth; el.classList.add('marquee');
        setTimeout(() => el.classList.remove('marquee'), 9000);
      }
    };
    el.addEventListener('click', run);
    el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); run(); } });
  }

  // 圓角形狀（內嵌 SVG，stroke-linejoin:round → 尖角變圓角）；顏色用 currentColor 跟主題
  function polyPoints(sides, R, rot) {
    rot = rot || 0;
    const p = [];
    for (let i = 0; i < sides; i++) { const a = (-90 + rot + i * 360 / sides) * Math.PI / 180; p.push((12 + R * Math.cos(a)).toFixed(1) + ',' + (12 + R * Math.sin(a)).toFixed(1)); }
    return p.join(' ');
  }
  function shapeSVG(kind) {
    // 角度各有變化：方塊微斜、五角略轉、六角平頂；線改厚矩形；stroke-linejoin:round → 圓角
    const open = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="4" stroke-linejoin="round" stroke-linecap="round">';
    const inner = {
      dot: '<circle cx="12" cy="12" r="5.5" stroke="none"/>',
      line: '<rect x="3.5" y="8.5" width="17" height="7" rx="3.5" stroke="none"/>',
      triangle: '<polygon points="' + polyPoints(3, 8.2, 0) + '"/>',
      square: '<rect x="5.5" y="5.5" width="13" height="13" rx="4.5" transform="rotate(7 12 12)"/>',
      pentagon: '<polygon points="' + polyPoints(5, 8, -8) + '"/>',
      hexagon: '<polygon points="' + polyPoints(6, 8, 30) + '"/>',
    }[kind] || '';
    return open + inner + '</svg>';
  }

  // 連點標題 → 圓角形狀在標題旁依序出現（點→線→三→四→五角→六角）→ 六角跳彩蛋並開 PIN 面板
  function setupBrandEgg() {
    const el = document.getElementById('title');
    const slot = document.getElementById('brandShape');
    if (!el || !slot) return;
    const SHAPES = ['dot', 'line', 'triangle', 'square', 'pentagon', 'hexagon'];   // 別叫 KINDS，那是投稿型別表
    let n = 0, timer = null;
    const reset = () => { n = 0; slot.innerHTML = ''; };
    el.addEventListener('click', () => {
      n = Math.min(n + 1, SHAPES.length);
      clearTimeout(timer);
      timer = setTimeout(reset, 1800);
      slot.innerHTML = shapeSVG(SHAPES[n - 1]);
      slot.style.animation = 'none'; void slot.offsetWidth; slot.style.animation = '';
      if (n === SHAPES.length) { eggPop(); openPinPad(); }
    });
  }
  function eggPop() {
    const e = document.createElement('div');
    e.className = 'toast egg';
    e.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> ' + esc(t('easter_egg_msg'));
    document.body.appendChild(e);
    setTimeout(() => { e.style.opacity = '0'; }, 3000);
    setTimeout(() => e.remove(), 3600);
  }

  // ---- 管理 PIN 面板（數字鍵盤／鍵盤切換輸入，見 pin-input.js） ----
  async function pinSubmit() {
    const input = document.getElementById('pinInput');
    const pin = input ? input.value : '';
    if (!pin) return;
    try {
      const fd = new FormData(); fd.append('action', 'login'); fd.append('json', '1'); fd.append('pin', pin); fd.append('project', PROJECT);
      const res = await fetch(MANAGER_URL, { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) {
        if (j.label) { try { localStorage.setItem('myName', j.label); } catch (e) {} }
        closePin(); location.href = MANAGER_URL; return;
      }
    } catch (e) {}
    if (input) { input.value = ''; input.dispatchEvent(new Event('input')); }
    const msg = document.getElementById('pinMsg'); if (msg) msg.textContent = t('admin_pin_incorrect');
    const box = document.querySelector('.pin-box'); if (box) { box.style.animation = 'none'; void box.offsetWidth; box.style.animation = 'pinshake .3s'; }
  }
  function openPinPad() {
    const input = document.getElementById('pinInput'), msg = document.getElementById('pinMsg');
    if (input) { input.value = ''; input.dispatchEvent(new Event('input')); }
    if (msg) msg.textContent = '';
    document.getElementById('pinDialog').classList.add('open');
    if (input) input.focus();
  }
  function closePin() { const dlg = document.getElementById('pinDialog'); if (dlg) dlg.classList.remove('open'); }

  // ── 鍵盤快捷鍵提示彈窗：內容不是寫死的清單，開啟當下才照 shortcuts 註冊表現組——
  //    核心自己的 Esc/R/T 跟各插件各自登記的鍵（見 registerShortcut()），沒有哪個檔案要另外
  //    維護一份別人的鍵位表。按鈕本身只在滑鼠／觸控板環境才會出現（見 page-frame.css 的
  //    any-hover/any-pointer 媒體查詢），因為快捷鍵能不能用跟能不能發現是兩件事，但沒有實體
  //    鍵盤的裝置沒必要看到這顆鈕。
  function openShortcuts() {
    const list = document.getElementById('shortcutsList');
    if (list) list.innerHTML = shortcuts.map(s => '<dt>' + esc(s.key) + '</dt><dd>' + esc(s.label) + '</dd>').join('');
    const dlg = document.getElementById('shortcutsDialog');
    if (dlg) dlg.classList.add('open');
  }
  function closeShortcuts() { const dlg = document.getElementById('shortcutsDialog'); if (dlg) dlg.classList.remove('open'); }

  // ── 外部連結離站確認：任何連到不同網域的連結，先跳確認框才真的開新分頁 ──
  // （同一分頁工作階段內，同網域點過一次後不再重複詢問，避免每次點資料來源都要按兩下；
  //   Ctrl/Cmd/中鍵點擊等瀏覽器原生「開背景分頁」手勢不攔截，尊重使用者原本習慣）
  let extLinkPending = null;
  function extLinkOkHosts() {
    try { return JSON.parse(sessionStorage.getItem('extLinkOk') || '[]'); } catch (e) { return []; }
  }
  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    let url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (url.origin === location.origin || extLinkOkHosts().includes(url.host)) return;
    e.preventDefault();
    extLinkPending = url.href;
    document.getElementById('extLinkHost').textContent = url.host;
    document.getElementById('extLinkDialog').classList.add('open');
  });
  function extLinkProceed() {
    if (!extLinkPending) return;
    try {
      const host = new URL(extLinkPending).host, hosts = extLinkOkHosts();
      if (!hosts.includes(host)) { hosts.push(host); sessionStorage.setItem('extLinkOk', JSON.stringify(hosts)); }
    } catch (e) {}
    window.open(extLinkPending, '_blank', 'noopener');
    closeExtLinkDialog();
  }
  function closeExtLinkDialog() { extLinkPending = null; const dlg = document.getElementById('extLinkDialog'); if (dlg) dlg.classList.remove('open'); }

  // 核心自己的全域鍵位先登記——同步呼叫，保證排在任何插件（各自在自己的 mount() 裡登記）
  // 前面，快捷鍵提示彈窗才會照「核心鍵在前、插件鍵在後」的固定順序列出。
  registerShortcut({ key: 'Esc', label: t('shortcut_esc') });
  registerShortcut({ key: 'R', label: t('shortcut_reset_view') });
  registerShortcut({ key: 'T', label: t('shortcut_toggle_theme') });

  boot();
  return {
    closePanel, togglePanelSize, openLightbox, closeLightbox, closePin, closeUnlock, closeAdminRedeem,
    extLinkProceed, closeExtLinkDialog, openShortcuts, closeShortcuts,
    // ---- 選用插件掛勾點 API（見 souliong/docs/EXTENDING.md）----
    onHook, registerPhotoFilter, registerEntriesHint, registerEntryAction, registerScopeParam, registerShortcut,
    personTimeline, pointTitle, photoFullUrl, openPanel, openUnlock, refreshEntries: renderEntries,
    refreshPersonFilter: rebuildPersonFilter,
    // 正式的引擎存取介面，回傳 MapEngine 抽象基底的實例（見 docs/EXTENDING.md）。
    getEngine: () => engine,
    getFilterPerson: () => filterPerson, isPhotoLayerOn: () => photoLayerOn,
    getCurrentPoint: () => current,
    isUnlocked, isEmbedMode: () => EMBED,
    canTogglePreview: () => REAL_IS_MANAGER, isPreviewMode: () => PREVIEW_MODE, setPreviewMode,
    hasIdentity: () => !!contribToken(),
    trackFeature: feature, currentScopeParams, getProjectId: () => PROJECT,
    // effectivePhotos／photoFullUrl 是「只有照片」的那份，route-tour／person-explore 兩個插件在用
    // （那些畫面只處理得了 <img>）；要拿到全部型別的投稿請用 effectiveEntries + entryFullUrl。
    effectivePhotos, effectiveEntries, entryFullUrl, entryThumbUrl, effectivePoints, chairMarkerSpecs,
    kindOf, contribCfg: () => CONTRIB_CFG, fmtDur,
    // 自訂聲音播放器元件（大播放鍵＋進度條，取代預設 <audio controls>）：故事區、投稿卡片預覽共用，
    // 供插件（如 sound-editor.js 錄音後的即時試聽）也能用同一套外觀，見 renderEntries() 內的用法。
    audioPlayerHtml, wireAudioPlayer,
    personColor, toast,
    displayName, anonName: () => SESSION_ANON, submitContribution, submitNewPoint,
    rerollAnon, identityChipClick,
    chairOptionsHtml, nearestPoint, locNote, srcTone, fmtTime,
    getMeta: () => META, getCats: () => CATS.slice(),
    refreshCounts: recount, refreshAll,
    Plugin: SouliongPlugin,
  };
})();
