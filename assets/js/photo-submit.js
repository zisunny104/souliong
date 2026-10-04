(function () {
  'use strict';
  const cfg = window.PHOTO_SUBMIT;
  const $ = id => document.getElementById(id);
  let status = cfg.status, blob = null, previewUrl = '', selection = 0, busy = false, submitted = false;
  let name = '', spot = '', parentOrigin = '', boundaryTimer = null;
  let serverOffset = Date.parse(status.serverTime) - Date.now();
  const owner = SouliongContribution.ownerToken();
  function post(type, extra) {
    if (!parentOrigin || window.parent === window) return;
    window.parent.postMessage(Object.assign({ v: 1, ns: 'souliong-photo', type, project: cfg.project }, extra), parentOrigin);
  }
  window.addEventListener('message', event => {
    const data = event.data;
    if (event.source !== window.parent || !cfg.origins.includes(event.origin) || !data || data.v !== 1
        || data.ns !== 'souliong-photo' || data.type !== 'init' || data.project !== cfg.project) return;
    parentOrigin = event.origin;
    if (!busy && !submitted) {
      name = typeof data.name === 'string' ? data.name.slice(0, 60) : '';
      spot = typeof data.spotId === 'string' && /^[0-9a-f]{16}$/.test(data.spotId) ? data.spotId : '';
    }
    post('ready', { status }); post('status', { status }); resize();
  });
  function resize() { post('resize', { height: Math.ceil(document.querySelector('main').getBoundingClientRect().height) }); }
  new ResizeObserver(resize).observe(document.querySelector('main'));
  function update() {
    const allowed = status.kinds && status.kinds.includes('photo') && (status.open || status.codesAvailable);
    $('photo-code-label').hidden = !!status.open || !status.codesAvailable;
    $('photo-code').required = !status.open && !!status.codesAvailable;
    $('photo-code').disabled = busy || submitted;
    $('photo-submit').disabled = busy || submitted || !allowed || !blob;
    ['pick-photo', 'take-photo', 'photo-comment', 'photo-consent'].forEach(id => { $(id).disabled = busy || submitted || !allowed; });
    const messages = { disabled: '目前未開放照片投稿。', scheduled: '照片投稿將於 ' + localTime(status.starts_at) + ' 開放。',
      ended: '本次照片投稿時段已結束。', open: '現在開放投稿，可拍攝或選擇照片。' };
    $('availability').textContent = status.blocked ? '此身分已被停權，無法投稿。' : !status.kinds || !status.kinds.includes('photo') ? '這個專案未開放照片投稿。' : !status.open && status.codesAvailable ? '請輸入投稿碼後送出照片。' : messages[status.state] || messages.disabled;
    clearTimeout(boundaryTimer);
    const boundary = submitted ? null : status.next_change_at;
    if (boundary) {
      const delay = Date.parse(boundary) - (Date.now() + serverOffset);
      boundaryTimer = setTimeout(refreshStatus, Math.max(100, Math.min(delay + 100, 2147483647)));
    }
  }
  function localTime(value) { return value ? new Intl.DateTimeFormat('zh-TW', { timeZone: 'Asia/Taipei', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : ''; }
  async function refreshStatus() {
    try {
      const query = new FormData(); query.set('project', cfg.project); query.set('owner', owner); query.set('code', $('photo-code').value);
      const response = await fetch(cfg.statusUrl, { method: 'POST', body: query, cache: 'no-store' });
      if (!response.ok) throw Error('status');
      status = await response.json(); serverOffset = Date.parse(status.serverTime) - Date.now(); update(); post('status', { status });
    } catch (e) {
      status = { open: false, state: 'disabled' }; update(); post('status', { status });
      $('availability').textContent = '暫時無法確認投稿時段，請稍後再試。';
    }
  }
  async function select(file) {
    if (!file || busy || submitted) return;
    const run = ++selection;
    blob = null; update(); $('photo-message').textContent = '正在準備照片…';
    try {
      if (!SLPhotoTools.accepts(file)) throw Error('請選擇照片檔案。');
      // HEIC converter is needed only for HEIC/HEIF, so normal photos make no CDN request.
      if (/heic|heif/i.test(file.type) || /\.hei[cf]$/i.test(file.name)) {
        if (!window.heic2any) await new Promise((resolve, reject) => {
          const script = document.createElement('script'); script.src = 'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js';
          script.onload = resolve; script.onerror = reject; document.head.appendChild(script);
        });
      }
      const converted = await SLPhotoTools.toWebp(file, 1600, .85);
      if (run !== selection) return;
      if (!converted || converted.size > cfg.maxBytes) throw Error('照片太大，請換一張較小的照片。');
      blob = converted;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = URL.createObjectURL(blob); $('preview').src = previewUrl; $('preview').hidden = false;
      $('photo-message').textContent = '照片已準備好，可填寫說明後送出。'; update();
    } catch (e) { if (run === selection) $('photo-message').textContent = e.message || '無法讀取照片，請改用 JPEG、PNG 或 WebP。'; }
  }
  $('pick-photo').onclick = () => $('photo-file').click();
  $('take-photo').onclick = () => $('photo-camera').click();
  ['photo-file', 'photo-camera'].forEach(id => { $(id).onchange = () => select($(id).files[0]); });
  $('photo-form').onsubmit = async event => {
    event.preventDefault();
    if (busy || submitted || !blob || !$('photo-consent').checked) return;
    busy = true; update(); post('uploading'); $('photo-message').textContent = '正在確認投稿時段…';
    try {
      await refreshStatus();
      if (!status.kinds || !status.kinds.includes('photo') || (!status.open && !status.codeValid)) throw Error(status.codesAvailable ? '需要有效的投稿碼。' : '目前未開放投稿。');
      const fields = { kind: 'photo', name: $('photo-name') ? $('photo-name').value : name,
        comment: $('photo-comment').value, photo: [blob, 'photo.webp'] };
      if (spot) fields.item_num = spot;
      $('photo-progress').hidden = false; $('photo-progress').value = 0;
      $('photo-message').textContent = '正在送出照片，請稍候…';
      const result = await SouliongContribution.submit(cfg.uploadUrl, fields,
        { project: cfg.project, owner, code: $('photo-code').value, csrf: cfg.csrf },
        { onProgress: value => { $('photo-progress').value = Math.round(value * 100); } });
      submitted = true; $('photo-message').textContent = '照片與說明已送出，謝謝你的分享。';
      post('submitted', { entryId: result.id });
    } catch (e) { $('photo-message').textContent = e.message; post('error', { message: e.message }); }
    finally { busy = false; $('photo-progress').hidden = true; update(); }
  };
  update();
  setInterval(() => { if (!submitted && !document.hidden) refreshStatus(); }, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshStatus(); });
})();
