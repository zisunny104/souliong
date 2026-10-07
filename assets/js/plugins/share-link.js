/* 選用插件：分享（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.share 為 true 時，view.php 才會載入這個檔案。
   全螢幕分享卡片（含 QR code）＋複製公開網址，網址會帶入目前的篩選範圍
   （投稿者／分類，以及其他插件透過 MapApp.registerScopeParam() 註冊的參數）。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class ShareLinkPlugin extends MapApp.Plugin {
    constructor() { super('share'); }

    mount() {
      this.returnFocus = null;
      this.injectStyle();
      this.injectDialog();
      this.injectButton();
      this.mapApp.onHook('closeAll', () => this.close());
      // 快速鍵 s：與核心鍵盤處理分開自己監聽，嵌入模式或表單輸入中不觸發
      document.addEventListener('keydown', e => {
        const tag = (e.target && e.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key.toLowerCase() === 's' && !this.mapApp.isEmbedMode()) this.open();
      });
      if (!this.mapApp.isEmbedMode()) this.mapApp.registerShortcut({ key: 'S', label: t('shortcut_share') });
    }

    // 比照 embed-code.js：只補「分享卡片獨有」的樣式，遮罩／卡片本體／關閉鈕／按鈕
    // 都直接沿用核心 .dialog／.dialog-box／.icon-btn／.btn，才能一併吃到主題包材質換色
    injectStyle() {
      const style = document.createElement('style');
      style.textContent = `
        .share-box { text-align: center }
        .share-close { position: absolute; top: 10px; right: 10px }
        .share-qr { width: 180px; height: 180px; margin: 8px auto 14px; background: #fff; border-radius: var(--r-md); padding: 12px; box-sizing: border-box; overflow: hidden }
        .share-qr svg { width: 100%; height: 100%; display: block }
        .share-title { font-size: 1.0625rem; font-weight: 800; margin-bottom: 2px; color: var(--fg) }
        .share-url { font-family: ui-monospace, Menlo, Consolas, monospace; word-break: break-all }
      `;
      document.head.appendChild(style);
    }

    injectDialog() {
      const scr = document.createElement('div');
      scr.id = 'shareScreen';
      scr.className = 'dialog';
      scr.innerHTML = `
        <div class="dialog-box share-box">
          <button class="icon-btn share-close" aria-label="${esc(t('close'))}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          <div class="share-qr" id="shareQr" aria-hidden="true"></div>
          <div class="share-title" id="shareTitle"></div>
          <div class="hint" id="shareSub"></div>
          <div class="hint share-url" id="shareUrl"></div>
          <div class="dialog-actions" style="justify-content:center">
            <button class="btn primary" id="shareCopyBtn" title="${esc(t('copy_link'))}" aria-label="${esc(t('copy_link'))}"><i class="fa-solid fa-link" aria-hidden="true"></i></button>
            <span id="shareCopyMsg" class="hint"></span>
          </div>
        </div>
      `;
      document.body.appendChild(scr);

      scr.querySelector('.share-close').onclick = () => this.close();
      scr.querySelector('#shareCopyBtn').onclick = () => this.copyLink();
    }

    injectButton() {
      const btn = document.createElement('button');
      btn.id = 'shareBtn';
      btn.className = 'icon-btn hide-in-embed';
      btn.title = t('share_map');
      btn.setAttribute('aria-label', t('share_map'));
      btn.innerHTML = '<i class="fa-solid fa-share-nodes" aria-hidden="true"></i>';
      btn.onclick = () => this.open();
      // 掛在首頁鈕後面，但那顆是可關閉的模組（homeLink）；它不在時往後找下一個固定版位，
      // 免得少了一顆鈕就讓分享鈕整個不見。依序退到嵌入鈕（embed，也可能被關掉）與主題鈕之前，
      // 這樣不論首頁鈕在不在，分享鈕跟嵌入鈕的先後順序都一樣。
      const homeBtn = document.getElementById('homeBtn');
      const anchor = document.getElementById('embedBtn') || document.getElementById('themeBtn');
      if (homeBtn) homeBtn.insertAdjacentElement('afterend', btn);
      else if (anchor) anchor.insertAdjacentElement('beforebegin', btn);
    }

    // 公開地圖網址（不含 embed/code；帶目前篩選狀態，讓分享連結只給對方看你指定的投稿者或主題）
    publicUrl() {
      const App = this.mapApp;
      const qs = App.currentScopeParams();
      return location.origin + window.APP.base + App.getProjectId() + (qs ? '?' + qs : '');
    }

    open() {
      this.mapApp.trackFeature('share');
      const url = this.publicUrl();
      const meta = window.APP.meta || {};
      document.getElementById('shareTitle').textContent = meta.title || t('app_title');
      document.getElementById('shareSub').textContent = meta.subtitle || t('app_tagline');
      document.getElementById('shareUrl').textContent = url;
      const box = document.getElementById('shareQr'); box.innerHTML = '';
      try { const qr = qrcode(0, 'M'); qr.addData(url); qr.make(); box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true }); }
      catch (e) { box.textContent = t('qr_generate_failed'); }
      this.returnFocus = document.activeElement;
      const scr = document.getElementById('shareScreen');
      scr.classList.add('open');
      const closeBtn = scr.querySelector('.share-close'); if (closeBtn) closeBtn.focus();
    }

    close() {
      const scr = document.getElementById('shareScreen');
      if (!scr) return;
      const restore = (this.returnFocus && document.body.contains(this.returnFocus)) ? this.returnFocus : document.getElementById('shareBtn');
      if (restore && typeof restore.focus === 'function') restore.focus();
      scr.classList.remove('open');
      this.returnFocus = null;
    }

    async copyLink() {
      try { await navigator.clipboard.writeText(this.publicUrl()); } catch (e) { }
      const m = document.getElementById('shareCopyMsg');
      if (m) { m.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('copied')); setTimeout(() => m.textContent = '', 2000); }
    }
  }

  new ShareLinkPlugin().init(window.MapApp);
})();
