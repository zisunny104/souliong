/* 選用插件：專案說明頁（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.pages 為 true 時，view.php 才會載入這個檔案。
   左上角地圖名稱旁一顆按鈕，開啟多分頁的說明面板，每頁一份 Markdown。
   具 edit_pages 權限的身分可以在面板內切換「編輯／對照／檢視」：編輯是純文字輸入，對照是左寫右看，檢視是看草稿算繪的結果。
   草稿只留在前端，按「儲存」才一次寫入（api/pages.php，base_rev 不符回 409，草稿原樣保留）。
   圖片：貼上剪貼簿圖片、拖放或按工具列選檔都會先上傳到專案，文字裡寫 souliong-photo:<專案>/<檔名>；外部圖片直接貼網址即可。
   算繪一律由伺服器做（預覽也是），畫面與正式顯示不會有兩套規則。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const MAX_PAGES = 12;

  class InfoPagesPlugin extends MapApp.Plugin {
    constructor() { super('pages'); }

    async mount() {
      this.pages = [];          // 草稿：{id?, title, md, html}
      this.rev = '';
      this.sel = 0;
      this.mode = 'view';
      this.dirty = false;
      this.canEdit = false;
      this.returnFocus = null;
      this.previewTimer = null;
      this.previewSeq = 0;
      this.mapApp.onHook('closeAll', () => this.close(true));
      try { await this.load(); } catch (e) { return; }
      if (!this.pages.length && !this.canEdit) return;     // 沒內容又不能編輯：連入口都不放
      this.injectStyle();
      this.injectDialog();
      this.injectButton();
    }

    url() { return window.APP.base + '?api=pages'; }
    project() { return this.mapApp.getProjectId(); }

    async load() {
      const can = this.mapApp.can('edit_pages');
      const res = await fetch(this.url() + '&project=' + encodeURIComponent(this.project()) + (can ? '&edit=1' : ''), { cache: 'no-store' });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || 'load failed');
      this.canEdit = !!j.can_edit;
      this.rev = j.rev || '';
      this.pages = (j.pages || []).map(p => ({ id: p.id, title: p.title, md: p.md != null ? p.md : '', html: p.html }));
      this.sel = Math.min(this.sel, Math.max(0, this.pages.length - 1));
      this.dirty = false;
    }

    injectStyle() {
      const style = document.createElement('style');
      style.textContent = `
        .pages-box { width: min(960px, 96vw); max-width: none; height: min(86vh, 780px); display: flex; flex-direction: column; padding: 0; text-align: left; overflow: hidden }
        .pages-box [hidden] { display: none !important }
        .pages-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--line); flex-shrink: 0 }
        .pages-tabs { display: flex; gap: 4px; flex: 1; min-width: 0; overflow-x: auto; scrollbar-width: thin }
        .pages-tab { flex: none; padding: 6px 12px; border: 1px solid var(--line); border-radius: var(--r-sm); background: transparent; color: var(--fg); font: inherit; font-size: .875rem; cursor: pointer; white-space: nowrap }
        .pages-tab:hover { background: var(--hover) }
        .pages-tab[aria-selected="true"] { background: var(--accent); color: var(--accent-fg); border-color: var(--accent) }
        .pages-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 8px 12px; border-bottom: 1px solid var(--line); flex-shrink: 0 }
        .pages-bar .spacer { flex: 1 }
        .pages-bar .btn, .pages-seg button { font-size: .8125rem }
        .pages-seg { display: inline-flex; border: 1px solid var(--line); border-radius: var(--r-sm); overflow: hidden }
        .pages-seg button { border: 0; background: transparent; color: var(--fg); padding: 5px 12px; font: inherit; cursor: pointer }
        .pages-seg button[aria-pressed="true"] { background: var(--accent); color: var(--accent-fg) }
        .pages-title-in { min-width: 0; width: 11em; max-width: 100%; padding: 5px 8px; border: 1px solid var(--line); border-radius: var(--r-sm); background: transparent; color: var(--fg); font: inherit }
        .pages-main { flex: 1; min-height: 0; display: flex }
        .pages-pane { flex: 1; min-width: 0; min-height: 0; overflow: auto; padding: 14px 16px }
        .pages-main.is-compare .pages-pane + .pages-pane { border-left: 1px solid var(--line) }
        .pages-ta { border-radius: 0; box-shadow: none; display: block; width: 100%; height: 100%; min-height: 12em; box-sizing: border-box; resize: none; border: 0; padding: 14px 16px; background: transparent; color: var(--fg); font: .875rem/1.6 ui-monospace, Menlo, Consolas, monospace }
        .pages-ta:focus { outline: none }
        .pages-ta.is-drop { background: var(--hover) }
        .pages-read img { max-width: 100%; height: auto; border-radius: var(--r-sm) }
        .pages-empty { color: var(--muted); text-align: center; padding: 32px 0 }
        .pages-msg { font-size: .8125rem; color: var(--muted) }
        @media (max-width: 720px) {
          .pages-box { width: 100vw; height: 100dvh; max-height: none; border-radius: 0 }
          .pages-main.is-compare { flex-direction: column }
          .pages-main.is-compare .pages-pane + .pages-pane { border-left: 0; border-top: 1px solid var(--line) }
        }
      `;
      document.head.appendChild(style);
    }

    injectDialog() {
      const scr = document.createElement('div');
      scr.id = 'pagesScreen';
      scr.className = 'dialog';
      scr.innerHTML = `
        <div class="dialog-box pages-box" role="dialog" aria-modal="true" aria-label="${esc(t('pages_open'))}">
          <div class="pages-head">
            <div class="pages-tabs" role="tablist" id="pagesTabs"></div>
            <button class="icon-btn" id="pagesClose" aria-label="${esc(t('close'))}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          </div>
          <div class="pages-bar" id="pagesBar" hidden></div>
          <div class="pages-main" id="pagesMain"></div>
        </div>`;
      document.body.appendChild(scr);
      scr.querySelector('#pagesClose').onclick = () => this.close();
      scr.addEventListener('mousedown', e => { if (e.target === scr) this.close(); });
      scr.addEventListener('keydown', e => { if (e.key === 'Escape') { e.stopPropagation(); this.close(); } });
      this.scr = scr;
      this.render();
    }

    injectButton() {
      const head = document.querySelector('#controls .ctl-head');
      if (!head) return;
      const btn = document.createElement('button');
      btn.id = 'pagesBtn';
      btn.className = 'icon-btn';
      btn.title = t('pages_open');
      btn.setAttribute('aria-label', t('pages_open'));
      btn.innerHTML = '<i class="fa-solid fa-book-open" aria-hidden="true"></i>';
      btn.onclick = () => this.open();
      const spacer = head.querySelector('.spacer');
      if (spacer) head.insertBefore(btn, spacer); else head.appendChild(btn);
    }

    open() {
      this.returnFocus = document.activeElement;
      this.mode = 'view';
      this.render();
      this.scr.classList.add('open');
      const first = this.scr.querySelector('.pages-tab[aria-selected="true"]') || this.scr.querySelector('#pagesClose');
      if (first) first.focus();
    }

    close(force) {
      if (!this.scr || !this.scr.classList.contains('open')) return;
      if (!force && this.dirty && !confirm(t('pages_dirty_close'))) return;
      this.scr.classList.remove('open');
      const restore = (this.returnFocus && document.body.contains(this.returnFocus)) ? this.returnFocus : document.getElementById('pagesBtn');
      if (restore && typeof restore.focus === 'function') restore.focus();
      this.returnFocus = null;
    }

    current() { return this.pages[this.sel] || null; }

    // ---------- 算繪 ----------

    render() {
      this.renderTabs();
      this.renderBar();
      this.renderMain();
    }

    renderTabs() {
      const box = this.scr.querySelector('#pagesTabs');
      box.innerHTML = '';
      this.pages.forEach((p, i) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'pages-tab'; b.setAttribute('role', 'tab');
        b.setAttribute('aria-selected', i === this.sel ? 'true' : 'false');
        b.textContent = p.title;
        b.onclick = () => { this.sel = i; this.render(); };
        box.appendChild(b);
      });
      box.hidden = this.pages.length < 2 && !(this.canEdit && this.mode !== 'view');   // 只有一頁又在檢視：不顯示分頁列
    }

    renderBar() {
      const bar = this.scr.querySelector('#pagesBar');
      if (!this.canEdit) { bar.hidden = true; return; }
      bar.hidden = false;
      bar.innerHTML = '';
      const seg = document.createElement('div');
      seg.className = 'pages-seg';
      [['edit', 'pages_edit'], ['compare', 'pages_compare'], ['view', 'pages_view']].forEach(([m, key]) => {
        const b = document.createElement('button');
        b.type = 'button'; b.textContent = t(key); b.setAttribute('aria-pressed', this.mode === m ? 'true' : 'false');
        b.onclick = () => { this.syncDraft(); this.mode = m; this.render(); };
        seg.appendChild(b);
      });
      bar.appendChild(seg);
      const editing = this.mode !== 'view';
      const cur = this.current();
      if (editing) {
        if (cur) {
          const title = document.createElement('input');
          title.className = 'pages-title-in'; title.value = cur.title; title.maxLength = 40;
          title.setAttribute('aria-label', t('pages_title_label')); title.placeholder = t('pages_title_label');
          title.oninput = () => { cur.title = title.value; this.markDirty(); const tab = this.scr.querySelectorAll('.pages-tab')[this.sel]; if (tab) tab.textContent = title.value || t('pages_new_title'); };
          bar.appendChild(title);
          bar.appendChild(this.iconBtn('fa-arrow-left', t('pages_move_left'), () => this.move(-1), this.sel === 0));
          bar.appendChild(this.iconBtn('fa-arrow-right', t('pages_move_right'), () => this.move(1), this.sel >= this.pages.length - 1));
          bar.appendChild(this.iconBtn('fa-trash', t('pages_delete'), () => this.remove()));
          const tools = document.createElement('span');
          tools.className = 'pages-seg';
          [['fa-bold', 'pages_tb_bold', () => this.wrap('**', '**', t('pages_tb_bold'))],
           ['fa-heading', 'pages_tb_heading', () => this.linePrefix('## ')],
           ['fa-list', 'pages_tb_list', () => this.linePrefix('- ')],
           ['fa-link', 'pages_tb_link', () => this.insertLink()],
           ['fa-image', 'pages_tb_image', () => this.pickImage()]].forEach(([icon, key, fn]) => {
            const b = document.createElement('button');
            b.type = 'button'; b.title = t(key); b.setAttribute('aria-label', t(key)); b.setAttribute('aria-pressed', 'false');
            b.innerHTML = '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>'; b.onclick = fn;
            tools.appendChild(b);
          });
          bar.appendChild(tools);
        }
        const add = document.createElement('button');
        add.type = 'button'; add.className = 'btn small'; add.textContent = t('pages_add'); add.onclick = () => this.add();
        bar.appendChild(add);
      }
      const sp = document.createElement('span'); sp.className = 'spacer'; bar.appendChild(sp);
      const msg = document.createElement('span'); msg.className = 'pages-msg'; msg.id = 'pagesMsg'; msg.setAttribute('role', 'status'); bar.appendChild(msg);
      const discard = document.createElement('button');
      discard.type = 'button'; discard.className = 'btn small'; discard.id = 'pagesDiscard'; discard.hidden = !this.dirty;
      discard.textContent = t('pages_discard'); discard.onclick = () => this.discard();
      bar.appendChild(discard);
      const save = document.createElement('button');
      save.type = 'button'; save.className = 'btn primary small'; save.id = 'pagesSave'; save.textContent = t('pages_save'); save.disabled = !this.dirty; save.onclick = () => this.save();
      bar.appendChild(save);
    }

    iconBtn(icon, label, fn, disabled) {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'icon-btn'; b.title = label; b.setAttribute('aria-label', label); b.disabled = !!disabled;
      b.innerHTML = '<i class="fa-solid ' + icon + '" aria-hidden="true"></i>'; b.onclick = fn;
      return b;
    }

    renderMain() {
      const main = this.scr.querySelector('#pagesMain');
      main.innerHTML = '';
      main.className = 'pages-main' + (this.mode === 'compare' ? ' is-compare' : '');
      const cur = this.current();
      if (!cur) {
        const e = document.createElement('div'); e.className = 'pages-pane pages-empty'; e.textContent = t(this.canEdit ? 'pages_no_pages' : 'pages_empty');
        main.appendChild(e); return;
      }
      if (this.mode === 'edit' || this.mode === 'compare') {
        const ta = document.createElement('textarea');
        ta.className = 'pages-ta'; ta.id = 'pagesTa'; ta.value = cur.md; ta.spellcheck = false; ta.placeholder = t('pages_placeholder');
        ta.setAttribute('aria-label', cur.title);
        ta.oninput = () => { cur.md = ta.value; this.markDirty(); if (this.mode === 'compare') this.schedulePreview(); };
        ta.addEventListener('paste', e => this.onPaste(e));
        ta.addEventListener('dragover', e => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); ta.classList.add('is-drop'); } });
        ta.addEventListener('dragleave', () => ta.classList.remove('is-drop'));
        ta.addEventListener('drop', e => {
          ta.classList.remove('is-drop');
          const files = [...((e.dataTransfer && e.dataTransfer.files) || [])].filter(f => f.type.startsWith('image/'));
          if (files.length) { e.preventDefault(); files.forEach(f => this.uploadImage(f)); }
        });
        if (this.mode === 'edit') main.appendChild(ta);
        else { const left = document.createElement('div'); left.className = 'pages-pane'; left.style.padding = '0'; left.appendChild(ta); main.appendChild(left); }
      }
      if (this.mode === 'view' || this.mode === 'compare') {
        const pane = document.createElement('div');
        pane.className = 'pages-pane pages-read sc-md'; pane.id = 'pagesRead';
        pane.innerHTML = cur.html || '';
        if (!cur.html && !cur.md) pane.innerHTML = '<p class="pages-empty">' + esc(t('pages_empty')) + '</p>';
        main.appendChild(pane);
        if (this.canEdit && (cur.html == null || cur.stale)) this.schedulePreview(0);
      }
    }

    syncDraft() { const ta = this.scr.querySelector('#pagesTa'); const cur = this.current(); if (ta && cur) cur.md = ta.value; }

    markDirty() {
      const cur = this.current(); if (cur) { cur.stale = true; }
      this.dirty = true;     // 不重畫工具列：使用者可能正在打分頁名稱，重畫會讓輸入框失去焦點
      const save = this.scr.querySelector('#pagesSave'); if (save) save.disabled = false;
      const discard = this.scr.querySelector('#pagesDiscard'); if (discard) discard.hidden = false;
    }

    // ---------- 預覽（伺服器算繪） ----------

    schedulePreview(delay) {
      clearTimeout(this.previewTimer);
      const cur = this.current(); if (!cur) return;
      const seq = ++this.previewSeq;
      const run = async () => {
        const fd = new FormData();
        fd.append('project', this.project()); fd.append('op', 'preview'); fd.append('md', cur.md);
        if (window.APP.csrf) fd.append('csrf', window.APP.csrf);
        try {
          const res = await fetch(this.url(), { method: 'POST', body: fd });
          const j = await res.json();
          if (seq !== this.previewSeq || !res.ok || j.error) return;
          cur.html = j.html; cur.stale = false;
          const pane = this.scr.querySelector('#pagesRead');
          if (pane) pane.innerHTML = j.html || '<p class="pages-empty">' + esc(t('pages_empty')) + '</p>';
        } catch (e) { /* 預覽失敗就維持上一次的畫面 */ }
      };
      this.previewTimer = setTimeout(run, delay == null ? 450 : delay);
    }

    // ---------- 編輯動作 ----------

    add() {
      if (this.pages.length >= MAX_PAGES) { this.say(t('pages_too_many')); return; }
      this.syncDraft();
      this.pages.push({ title: t('pages_new_title'), md: '', html: '' });
      this.sel = this.pages.length - 1;
      this.dirty = true; this.render();
      const ta = this.scr.querySelector('#pagesTa'); if (ta) ta.focus();
    }

    remove() {
      if (!this.current() || !confirm(t('pages_delete_confirm'))) return;
      this.syncDraft();
      this.pages.splice(this.sel, 1);
      this.sel = Math.min(this.sel, Math.max(0, this.pages.length - 1));
      this.dirty = true; this.render();
    }

    move(delta) {
      this.syncDraft();
      const j = this.sel + delta;
      if (j < 0 || j >= this.pages.length) return;
      [this.pages[this.sel], this.pages[j]] = [this.pages[j], this.pages[this.sel]];
      this.sel = j; this.dirty = true; this.render();
    }

    async discard() {
      this.dirty = false;
      try { await this.load(); } catch (e) { /* 載入失敗就保留目前畫面 */ }
      this.render();
    }

    say(text) { const m = this.scr && this.scr.querySelector('#pagesMsg'); if (m) m.textContent = text || ''; }

    async save() {
      this.syncDraft();
      const fd = new FormData();
      fd.append('project', this.project()); fd.append('op', 'save'); fd.append('base_rev', this.rev);
      fd.append('name', this.mapApp.displayName());
      fd.append('pages', JSON.stringify(this.pages.map(p => ({ id: p.id, title: p.title, md: p.md }))));
      if (window.APP.csrf) fd.append('csrf', window.APP.csrf);
      const btn = this.scr.querySelector('#pagesSave'); if (btn) btn.disabled = true;
      try {
        const res = await fetch(this.url(), { method: 'POST', body: fd });
        const j = await res.json().catch(() => ({}));
        if (res.status === 409) { this.say(t('pages_conflict')); if (btn) btn.disabled = false; return; }
        if (!res.ok || j.error) throw new Error(j.error || 'save failed');
        this.rev = j.rev;
        this.pages = j.pages.map(p => ({ id: p.id, title: p.title, md: p.md, html: p.html }));
        this.sel = Math.min(this.sel, Math.max(0, this.pages.length - 1));
        this.dirty = false; this.render(); this.say(t('pages_saved'));
      } catch (e) {
        this.say(t('pages_save_failed') + '：' + (e.message || ''));
        if (btn) btn.disabled = false;
      }
    }

    // ---------- 文字工具 ----------

    ta() { return this.scr.querySelector('#pagesTa'); }

    // 在游標處套前後綴；有選取就包住選取，沒有就放佔位字並選取它
    wrap(before, after, placeholder) {
      const ta = this.ta(); if (!ta) return;
      const s = ta.selectionStart, e = ta.selectionEnd, picked = ta.value.slice(s, e) || placeholder;
      ta.setRangeText(before + picked + after, s, e, 'end');
      ta.setSelectionRange(s + before.length, s + before.length + picked.length);
      ta.dispatchEvent(new Event('input')); ta.focus();
    }

    linePrefix(prefix) {
      const ta = this.ta(); if (!ta) return;
      const start = ta.value.lastIndexOf('\n', ta.selectionStart - 1) + 1;
      ta.setRangeText(prefix, start, start, 'end');
      ta.dispatchEvent(new Event('input')); ta.focus();
    }

    insertLink() {
      const ta = this.ta(); if (!ta) return;
      const s = ta.selectionStart, e = ta.selectionEnd, label = ta.value.slice(s, e) || t('pages_tb_link');
      ta.setRangeText('[' + label + '](https://)', s, e, 'end');
      const urlStart = s + label.length + 3;
      ta.setSelectionRange(urlStart, urlStart + 8);
      ta.dispatchEvent(new Event('input')); ta.focus();
    }

    // ---------- 圖片 ----------

    pickImage() {
      const input = document.createElement('input');
      input.type = 'file'; input.accept = 'image/*'; input.multiple = true;
      input.onchange = () => [...input.files].forEach(f => this.uploadImage(f));
      input.click();
    }

    onPaste(e) {
      const items = [...((e.clipboardData && e.clipboardData.items) || [])];
      const files = items.filter(i => i.kind === 'file' && i.type.startsWith('image/')).map(i => i.getAsFile()).filter(Boolean);
      if (!files.length) return;          // 一般文字照常貼上，外部圖片網址本來就是文字
      e.preventDefault();
      files.forEach(f => this.uploadImage(f));
    }

    async uploadImage(file) {
      const ta = this.ta(); if (!ta) return;
      this.say(t('pages_uploading'));
      const fd = new FormData();
      fd.append('project', this.project()); fd.append('op', 'upload');
      fd.append('image', file, file.name || 'pasted.png');
      if (window.APP.csrf) fd.append('csrf', window.APP.csrf);
      try {
        const res = await fetch(this.url(), { method: 'POST', body: fd });
        const j = await res.json().catch(() => ({}));
        if (!res.ok || j.error) throw new Error(j.error || 'upload failed');
        const alt = (file.name || '').replace(/\.[^.]+$/, '').replace(/[\[\]()]/g, '') || 'image';
        const s = ta.selectionStart;
        ta.setRangeText('\n![' + alt + '](' + j.src + ')\n', s, ta.selectionEnd, 'end');
        ta.dispatchEvent(new Event('input')); this.say('');
      } catch (err) {
        this.say(t('pages_upload_failed') + '：' + (err.message || ''));
      }
    }
  }

  new InfoPagesPlugin().init(window.MapApp);
})();
