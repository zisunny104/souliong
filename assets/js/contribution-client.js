/* Shared contribution identity and multipart transport for maps and embedded clients. */
(() => {
  'use strict';
  function ownerToken() {
    const key = 'ownerToken', ttl = 90 * 24 * 3600 * 1000;
    try { const old = JSON.parse(localStorage.getItem(key) || 'null'); if (old && old.t && Date.now() - old.c < ttl) return old.t; } catch (e) {}
    const token = window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
    try { localStorage.setItem(key, JSON.stringify({ t: token, c: Date.now() })); } catch (e) {}
    return token;
  }
  function request(url, fields, common, options = {}) {
    const form = new FormData();
    for (const [key, value] of Object.entries(Object.assign({}, fields, common))) {
      if (value == null) continue;
      if (Array.isArray(value)) form.append(key, value[0], value[1]); else form.append(key, value);
    }
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest(); xhr.open('POST', url); xhr.timeout = 120000;
      xhr.upload.onprogress = event => { if (event.lengthComputable && options.onProgress) options.onProgress(event.loaded / event.total); };
      xhr.onload = () => resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status,
        headers: { get: key => xhr.getResponseHeader(key) }, json: async () => JSON.parse(xhr.responseText) });
      xhr.onerror = xhr.ontimeout = () => reject(Error('連線中斷，無法確認投稿結果；請先確認是否已送出，避免重複投稿。'));
      xhr.send(form);
    });
  }
  async function submit(url, fields, common, options) {
    const response = await request(url, fields, common, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok || !data.item || !data.item.id) throw Error(data.error || '無法確認投稿結果，請先確認是否已送出。');
    return data.item;
  }
  window.SouliongContribution = { ownerToken, request, submit };
})();
