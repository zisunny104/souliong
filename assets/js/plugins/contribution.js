/* 選用插件：投稿與建立點位（見 souliong/docs/EXTENDING.md 第三、七節）
   view.php 在有任何投稿型別、或管理者可建立點位時才載入這個檔案。
   投稿（#contribModal，入口 #uploadBtn）與建立點位（#spotModal，入口 #createBtn）是兩個各自獨立的
   對話框，由同一個殼的兩個實例負責；入口鈕收在 #fabStack（插在 #resetBtn 之後），
   對話框插在 #panel 之後，都由 injectDom() 自己建立。

   這個檔案是**與型別無關的殼**：批次佇列、進度、429 倒數重試、授權勾選、暱稱同步、u 快速鍵、
   小地圖定位校正都在這裡；「這個檔案是什麼、要怎麼轉檔、要送哪些欄位」全部問 assets/js/contrib/
   底下的型別物件（見 kind-base.js）。要加第五種投稿，只要多一個 kind-*.js，這裡一行都不用改。

   解鎖對話框（QR 掃描、PIN 建立身分）仍留在核心，因為 isUnlocked()/openUnlock() 是任何模組都可能用到的核心原語。 */
(() => {
  const SL = window.SLContrib;
  const t = SL.t, esc = SL.esc;

  const MAX_RATE_RETRY = 6;

  // 分頁只是「選型別的入口」，不是型別本身——媒體頁同時服務照片與影片（丟進來才依 MIME 分流）。
  // 這裡只放圖示與標籤，有哪些分頁由已載入的型別檔決定（view.php 依 meta.json 的 contrib.kinds 輸出）。
  const TABS = {
    media:    { icon: 'fa-photo-film',       label: 'tab_media',    pick: 'pick_media_btn',  hint: 'pick_media_hint' },
    audio:    { icon: 'fa-microphone-lines', label: 'tab_audio',    pick: 'pick_audio_btn',  hint: 'pick_audio_hint' },
    text:     { icon: 'fa-align-left',       label: 'tab_text',     add:  'write_text_btn',  hint: 'write_text_hint' },
    newspot:  { icon: 'fa-map-pin',          label: 'tab_newspot',  add:  'new_spot_btn',    hint: 'new_spot_hint' },
  };

  // 遇到伺服器限流（429）時倒數等待再自動重試，而不是直接判定失敗、丟掉這一則
  function waitCountdown(statusEl, seconds, attempt, maxAttempt) {
    return new Promise(resolve => {
      let left = seconds;
      const tick = () => { statusEl.textContent = t('rate_limited_retry', { left: left, attempt: attempt, maxAttempt: maxAttempt }); statusEl.className = 'status warn'; };
      tick();
      const iv = setInterval(() => { left--; if (left <= 0) { clearInterval(iv); resolve(); } else tick(); }, 1000);
    });
  }

  class ContributionPlugin extends MapApp.Plugin {
    // scope：'contrib' 是投稿對話框（照片／影片／聲音／文字），'spot' 是建立點位對話框；
    // 兩者共用批次佇列的殼，但各有各的入口鈕與對話框，DOM id 由 ids 對應。
    constructor(scope) {
      super(scope === 'spot' ? 'newspot' : 'upload');
      this.scope = scope;
      this.ids = scope === 'spot'
        ? { modal: 'spotModal', queue: 'spotQueue', name: 'spotModalName', submit: 'spotSubmitAll', add: 'spotAddMore', prog: 'spotBatchProgress', fab: 'createBtn' }
        : { modal: 'contribModal', queue: 'queue', name: 'modalName', submit: 'submitAllBtn', add: 'addMoreBtn', prog: 'batchProgress', fab: 'uploadBtn' };
      this.cards = {};
      this.queueSeq = 0;
      this.modalContext = null;
      this.batchRunning = false;
      this.deviceLocCache = null;
      this.tabs = [];
      this.tab = null;
    }

    $(key) { return document.getElementById(this.ids[key]); }

    // ---- 分頁 ----
    // 有哪些分頁＝已載入的型別檔涵蓋到哪些分頁。順序依 TABS 的宣告順序，不依載入順序，
    // 這樣不同地圖的分頁排列才一致（跟 souliong_contrib_cfg() 依註冊表排序是同一個道理）。
    initTabs() {
      const cfg = this.mapApp.contribCfg() || {};
      // 載入了型別檔不代表這張地圖開放它（例如點位聲音編輯借用了 kind-audio.js）：註冊表只留 contrib.kinds 列出的，建立點位不在其中
      const allowed = cfg.kinds || [];
      for (let i = SL.kinds.length - 1; i >= 0; i--) {
        if (SL.kinds[i].key !== 'newspot' && !allowed.includes(SL.kinds[i].key)) SL.kinds.splice(i, 1);
      }
      const have = SL.kinds.map(k => k.tab);
      this.tabs = Object.keys(TABS).filter(tb => have.includes(tb) && (tb === 'newspot') === (this.scope === 'spot'));
      this.tab = this.tabs.includes(cfg.default) ? cfg.default : this.tabs[0];
    }
    tabKinds(tab) { return SL.byTab(tab); }
    // 目前分頁能接受的檔案型別；擋在檔案選擇器上，而不是等使用者選完才跳警告
    tabAccept(tab) { return this.tabKinds(tab).map(k => k.acceptAttr()).filter(Boolean).join(','); }

    // 分頁的文案。TABS 那份是「一個分頁涵蓋多種型別」時的通用說法，但分頁裡只剩一種型別
    // 的地圖也不少（只開照片的舊地圖就是），這時要用型別自己的文案——否則 100chairs 會看到
    // 「媒體／選擇照片或影片」，講的是這張地圖根本沒開放的東西。
    tabMeta(tab) {
      const meta = TABS[tab] || {};
      const ks = this.tabKinds(tab);
      if (ks.length !== 1) return meta;
      const k = ks[0], out = { ...meta, icon: k.icon };
      if (k.tabLabel()) out.label = k.tabLabel();
      if (k.pickLabel()) { if (out.pick) out.pick = k.pickLabel(); else out.add = k.pickLabel(); }
      if (k.pickHint()) out.hint = k.pickHint();
      return out;
    }

    // 右下角的入口鈕都放進同一個直排容器（#fabStack，插在 #resetBtn 之後），建立在上、投稿與解鎖在下，
    // 才不會疊在一起。#pickImages 跟著容器；對話框緊接在 #panel 之後、#unlockDialog 之前。
    fabStack() {
      let st = document.getElementById('fabStack');
      if (!st) {
        const resetBtn = document.getElementById('resetBtn');
        if (!resetBtn) return null;
        resetBtn.insertAdjacentHTML('afterend', '<div id="fabStack" class="fab-stack"></div>');
        st = document.getElementById('fabStack');
      }
      return st;
    }

    injectDom() {
      // 沒有分頁可用（見 initTabs()）就什麼都不放
      if (!this.tabs.length) return;
      const stack = this.fabStack();
      const spot = this.scope === 'spot';
      if (stack && spot) {
        stack.insertAdjacentHTML('afterbegin',
          '<button class="fabtn create-only" id="createBtn"><i class="fa-solid fa-map-pin"></i> ' + esc(t('contrib_fab_create')) + '</button>');
      } else if (stack) {
        stack.insertAdjacentHTML('beforeend',
          '<button class="fabtn upload-only" id="uploadBtn"><i class="fa-solid fa-plus"></i> ' + esc(t('contrib_fab')) + '</button>' +
          '<button class="fabtn fab-unlock" id="unlockFab" style="display:none"><i class="fa-solid fa-lock"></i> ' + esc(t('unlock_contrib')) + '</button>' +
          '<input type="file" id="pickImages" multiple hidden>');
      }

      const panel = document.getElementById('panel');
      if (!panel) return;
      // 只有一個分頁的對話框（建立點位、沒設定 contrib 的地圖）不渲染分頁列
      const tabsHtml = this.tabs.length < 2 ? '' :
        '<div class="sl-tabs" role="tablist">' + this.tabs.map(tb => {
          const m = this.tabMeta(tb);
          return '<button class="sl-tab" type="button" role="tab" data-tab="' + esc(tb) + '">' +
            '<i class="fa-solid ' + m.icon + '"></i>' + esc(t(m.label)) + '</button>';
        }).join('') +
        '</div>';
      const closeFn = spot ? 'closeSpotModal' : 'closeModal';
      const nameField = '<label class="modal-field"><span>' + esc(t('attribution_name')) + '</span><input class="name-in" id="' + this.ids.name + '" placeholder="' + esc(t('attribution_placeholder')) + '" autocomplete="nickname"></label>';
      const consent = spot ? '<div class="modal-consent">' + nameField + '</div>' :
        '<div class="modal-consent" id="modalConsent">' +
          '<div id="ccByRow" class="license-fields"><label class="modal-field"><span>' + esc(t('license_select_label')) + '</span><select id="ccByChk" aria-describedby="licenseSummary">' + Object.entries(APP.licenses).map(([key, value]) => '<option value="' + key + '">' + esc(value.label) + '</option>').join('') + '</select></label>' + nameField + '</div>' +
          '<div class="license-summary"><span id="licenseSummary" aria-live="polite"></span><a id="licenseHelp" target="_blank" rel="noopener noreferrer">' + esc(t('license_help')) + '</a></div>' +
          '<details class="author-link"><summary><i class="fa-solid fa-angle-right" aria-hidden="true"></i><i class="fa-solid fa-link" aria-hidden="true"></i>' + esc(t('author_url_label')) + '</summary><label class="modal-field"><span>' + esc(t('author_url_hint')) + '</span><input type="url" id="authorUrl" placeholder="https://" maxlength="500" autocomplete="url"></label></details>' +
          '<label><input type="checkbox" id="wikidataChk"> ' + esc(t('wikidata_consent_label')) + '</label>' +
        '</div>';
      const anchor = document.getElementById('contribModal') || panel;
      anchor.insertAdjacentHTML('afterend',
        '<div id="' + this.ids.modal + '" class="contrib-modal" role="dialog" aria-modal="true" aria-labelledby="' + this.ids.modal + '-title">' +
          '<div class="modal-box">' +
            '<div class="modal-head">' +
              '<h3 id="' + this.ids.modal + '-title">' + esc(t(spot ? 'create_dialog_title' : 'contrib_dialog_title')) + '</h3>' +
              '<button class="btn" onclick="MapApp.' + closeFn + '()">' + esc(t('close')) + '</button>' +
            '</div>' +
            tabsHtml +
            '<div class="modal-content"><div class="modal-body" id="' + this.ids.queue + '"></div>' +
            consent + '</div>' +
            '<div class="modal-foot">' +
              '<button class="btn" id="' + this.ids.add + '"><i class="fa-solid fa-plus"></i> ' + esc(t('add_more')) + '</button>' +
              '<span class="spacer"></span>' +
              '<span class="hint" id="' + this.ids.prog + '"></span>' +
              '<button class="btn primary" id="' + this.ids.submit + '">' + esc(t('submit_all')) + '</button>' +
            '</div>' +
          '</div>' +
        '</div>');
    }

    mount() {
      this.initTabs();
      this.injectDom();
      this.mapApp.onHook('closeAll', () => this.closeModal());
      if (this.scope === 'contrib') {
        this.mapApp.registerEntriesHint(spot => this.entriesUploadButton(spot));
        this.mapApp.onHook('identityUploadShortcut', () => {
          this.resetQueue();
          this.openModal(null);
          setTimeout(() => { const n = this.$('name'); if (n) n.focus(); }, 60);
        });
      }
      // 暱稱：#modalName 是這個模組自己的欄位，開機時從核心的 #myName（單一真實來源）帶入初始值，
      // 之後雙向同步；長按身分鈕換一個匿名名時（identityReroll）把預覽 placeholder 換過來。
      const modalName = this.$('name');
      if (modalName) {
        modalName.value = document.getElementById('myName').value;
        modalName.setAttribute('placeholder', t('attribution_placeholder'));
        modalName.oninput = e => { const previous = document.getElementById('myName').value; document.getElementById('myName').value = e.target.value; localStorage.setItem('myName', e.target.value); this.$('queue').querySelectorAll('.c-name').forEach(input => { if (!input.value || input.value === previous) input.value = e.target.value; }); this.refreshLicense(); };
        this.mapApp.onHook('identityReroll', () => {
          modalName.value = '';
          modalName.setAttribute('placeholder', t('attribution_placeholder'));
          this.refreshLicense();
        });
      }
      // 授權／Wikidata 捐贈選擇：記住上次選擇（跟暱稱同一層，不分專案），但整個批次共用同一份、
      // 每次開彈窗皆可確認授權；具名授權可使用身分或自行填寫署名。
      // 顯示與否在 openModal() 依當下身分狀態即時判斷。
      const ccByChk = document.getElementById('ccByChk');
      const wikidataChk = document.getElementById('wikidataChk');
      if (ccByChk && this.scope === 'contrib') ccByChk.onchange = () => { this.preferredLicense = ccByChk.value; localStorage.setItem('prefLicense', ccByChk.value); this.refreshLicense(); };
      if (wikidataChk) wikidataChk.onchange = () => localStorage.setItem('prefWikidata', wikidataChk.checked ? '1' : '0');
      // 供核心 view.php 內嵌的 onclick="MapApp.closeModal()" 呼叫（HTML 屬性只能呼叫掛在 MapApp 上的方法，無法用 hook）
      this.mapApp[this.scope === 'spot' ? 'closeSpotModal' : 'closeModal'] = () => this.closeModal();

      const modalEl = this.$('modal');
      if (modalEl) modalEl.querySelectorAll('.sl-tab').forEach(btn => {
        btn.onclick = () => this.switchTab(btn.dataset.tab);
      });

      if (!this.mapApp.isEmbedMode() || this.submitEmbed()) {
        const pick = document.getElementById('pickImages');   // 只有投稿對話框有
        const uploadBtn = this.$('fab');
        if (uploadBtn) uploadBtn.onclick = () => { this.modalContext = null; this.resetQueue(); this.openModal(null); };
        const addMoreBtn = this.$('add');
        if (addMoreBtn) addMoreBtn.onclick = () => this.primaryAdd();
        if (pick) pick.onchange = e => { this.addFiles(Array.from(e.target.files)); };
        const submitAllBtn = this.$('submit');
        if (submitAllBtn) submitAllBtn.onclick = () => this.submitAll();
        if (this.tabs.length && this.scope === 'contrib' && !this.submitEmbed()) this.mapApp.registerShortcut({ key: 'U', label: t('shortcut_upload') });
      }

      if (this.scope === 'contrib' && this.submitEmbed()) this.mountSubmitEmbed();

      if (this.scope === 'contrib') document.addEventListener('keydown', e => {
        const tag = (e.target && e.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key.toLowerCase() !== 'u' || this.mapApp.isEmbedMode()) return;
        if (this.mapApp.isUnlocked()) { this.resetQueue(); this.openModal(this.mapApp.getCurrentSpot()); }
        else if (window.APP && window.APP.gated) this.mapApp.openUnlock();
      });
    }

    submitEmbed() { return this.scope === 'contrib' && !!this.mapApp.isSubmitEmbed && this.mapApp.isSubmitEmbed(); }

    // 嵌入投稿：需碼先開解鎖視窗，開放直接開對話框，否則顯示未開放；身分變動時重判
    mountSubmitEmbed() {
      const note = document.createElement('p');
      note.id = 'submitClosed';
      note.textContent = t('embed_submit_closed');
      note.hidden = true;
      document.body.appendChild(note);
      const origins = (window.APP && APP.embedOrigins) || [];
      const ref = (() => { try { return new URL(document.referrer).origin; } catch (e) { return ''; } })();
      const notify = (type, extra) => {
        if (window.parent === window || !origins.includes(ref)) return;
        window.parent.postMessage(Object.assign({ v: 1, ns: 'souliong', type }, extra), ref);
      };
      this.notifyParent = notify;
      const sync = () => {
        const dlg = document.getElementById('unlockDialog');
        if (!this.tabs.length) {
          note.hidden = false;
        } else if (this.mapApp.isUnlocked()) {
          note.hidden = true;
          if (dlg) dlg.classList.remove('open');
          if (!this.$('modal').classList.contains('open')) { this.resetQueue(); this.openModal(null); }
        } else if (window.APP && APP.gated) {
          note.hidden = true;
          if (dlg && !dlg.classList.contains('open')) this.mapApp.openUnlock();
        } else {
          note.hidden = false;
        }
      };
      this.mapApp.onHook('identityChanged', sync);
      sync();
      notify('contribReady');
    }

    // 「投稿到這個點」鈕：放在說明區底下、第一則投稿之前（核心 renderEntries() 在說明區後、投稿牆前呼叫這裡）。
    // 沒有任何分頁可投（例如地圖 meta.json 的 contrib.kinds 只設了 spot 以外零種型別，理論上不會發生）
    // 時，通用投稿對話框沒有東西好顯示，不出現這顆鈕。
    entriesUploadButton(spot) {
      if (!this.tabs.length) return null;
      const upBtn = document.createElement('button');
      upBtn.className = 'btn primary upload-only entry-upload-button';
      upBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + esc(t('upload_to_spot'));
      upBtn.onclick = () => { this.resetQueue(); this.openModal(spot); };
      return upBtn;
    }

    openModal(contextSpot) {
      // 沒有分頁可投時整個對話框沒有內容（見 initTabs()），所有入口（FAB、快捷鍵 U、身分鈕）共用這道保險
      if (!this.tabs.length) return;
      this.$('name').value = document.getElementById('myName').value || localStorage.getItem('myName') || '';
      this.preferredLicense = localStorage.getItem('prefLicense') || (localStorage.getItem('prefCcBy') === '1' ? 'cc-by' : localStorage.getItem('prefCcBy') === '0' ? 'cc0' : 'cc-by-sa');
      if (!APP.licenses[this.preferredLicense]) this.preferredLicense = 'cc-by-sa';
      this.refreshLicense();
      const wikidataChk = document.getElementById('wikidataChk');
      const authorUrl = document.getElementById('authorUrl'); if (authorUrl) authorUrl.value = '';
      if (wikidataChk) wikidataChk.checked = localStorage.getItem('prefWikidata') === '1';
      this.$('modal').classList.add('open');
      this.modalContext = contextSpot || null;
    }
    refreshLicense() {
      if (this.scope !== 'contrib') return;
      const select = document.getElementById('ccByChk');
      if (!select) return;
      const named = this.mapApp.hasIdentity() || !!this.$('name').value.trim() || Array.from(this.$('queue').querySelectorAll('.c-name')).some(input => input.value.trim());
      Array.from(select.options).forEach(option => { option.disabled = !named && option.value !== 'cc0'; });
      select.value = named ? (this.preferredLicense || 'cc-by-sa') : 'cc0';
      document.getElementById('licenseHelp').href = APP.licenses[select.value].url;
      const summary = document.getElementById('licenseSummary');
      const hint = t('license_hint_' + select.value.replaceAll('-', '_'));
      if (summary.textContent !== hint) summary.textContent = hint;
    }
    closeModal() {
      if (this.submitEmbed() && this.mapApp.isUnlocked()) return;
      const m = this.$('modal');
      if (!m) return;
      m.classList.remove('open');
      // 批次送出仍在背景進行時不可清空佇列，否則尚未送出的內容會直接遺失；重開視窗會看到原本的佇列與進度
      if (!this.batchRunning) this.resetQueue();
    }

    // ---- 佇列 ----
    switchTab(tab) {
      if (!this.tabs.includes(tab) || tab === this.tab) return;
      this.tab = tab;
      this.syncTabUi();
      // 只有空狀態要換（佇列裡已經有的卡片保留——一個批次本來就可以混型別）
      if (!Object.keys(this.cards).length) this.renderEmpty();
      this.refreshLicense();
    }
    syncTabUi() {
      const modalEl = this.$('modal');
      if (modalEl) modalEl.querySelectorAll('.sl-tab').forEach(b => {
        const on = b.dataset.tab === this.tab;
        b.classList.toggle('on', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      const pick = document.getElementById('pickImages');
      if (pick) pick.setAttribute('accept', this.tabAccept(this.tab));
      const addMore = this.$('add');
      const meta = this.tabMeta(this.tab);
      if (addMore) addMore.innerHTML = '<i class="fa-solid fa-plus"></i> ' + esc(t(meta.pick ? 'add_more' : meta.add));
    }

    // 目前分頁的主要動作：選檔的分頁開檔案選擇器，其餘直接開一張空白卡片
    primaryAdd() {
      const meta = this.tabMeta(this.tab);
      if (meta.pick) { const p = document.getElementById('pickImages'); p.value = ''; p.click(); return; }
      const kind = this.tabKinds(this.tab)[0];
      if (kind) this.addCard(kind, null);
    }

    renderEmpty() {
      const q = this.$('queue');
      if (!q) return;   // 這個對話框沒有分頁可用，沒有 DOM
      if (!this.tabs.length) { q.innerHTML = ''; return; }   // 沒有分頁可投（見 initTabs()），對話框本來就不會被打開
      const meta = this.tabMeta(this.tab);
      q.innerHTML = '<div class="queue-empty"><div class="sl-actions">' +
        '<button class="btn primary" id="pickBtn"><i class="fa-solid ' + meta.icon + '"></i> ' + esc(t(meta.pick || meta.add)) + '</button>' +
        '</div><div class="hint">' + esc(t(meta.hint)) + '</div></div>';
      q.querySelector('#pickBtn').onclick = () => this.primaryAdd();
      // 音訊分頁多一顆現場錄音（錄完就當成一個選好的檔案走同一條路，見 kind-audio.js）
      const audio = SL.byKey('audio');
      if (this.tab === 'audio' && audio && audio.buildRecorder) {
        q.querySelector('.sl-actions').appendChild(audio.buildRecorder(file => this.addFiles([file])));
      }
    }

    resetQueue() {
      Object.values(this.cards).forEach(st => {
        try { if (st.picker) st.picker.destroy(); } catch (e) {}
        try { st.kind.cleanup(st); } catch (e) {}
      });
      Object.keys(this.cards).forEach(k => delete this.cards[k]);
      this.renderEmpty();
      this.refreshLicense();
    }
    cancelCard(id) {
      const st = this.cards[id];
      if (!st || st.done) return;   // 已送出的不可取消（不可更改），只能取消尚未送出的
      try { if (st.picker) st.picker.destroy(); } catch (e) {}
      try { st.kind.cleanup(st); } catch (e) {}
      delete this.cards[id];
      const card = document.getElementById(id);
      if (card) card.remove();
      if (!Object.keys(this.cards).length) this.renderEmpty();
      this.refreshLicense();
    }

    getDeviceLoc() {
      if (this.deviceLocCache !== null) return Promise.resolve(this.deviceLocCache);
      return new Promise(res => {
        if (!navigator.geolocation) { this.deviceLocCache = false; return res(false); }
        navigator.geolocation.getCurrentPosition(
          p => { this.deviceLocCache = { lat: p.coords.latitude, lon: p.coords.longitude }; res(this.deviceLocCache); },
          () => { this.deviceLocCache = false; res(false); }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
      });
    }

    // 一筆投稿的座標怎麼來：型別自己知道的最優先（照片的 EXIF GPS），再來是裝置定位，
    // 然後是開啟來源的點位，最後退回目前地圖中心。不需要座標的型別（文字紀錄）不問裝置定位，
    // 免得為了一則純文字跳出定位權限詢問——但仍會算出一個參考點，讓「關聯點位」有合理的預設值。
    async resolveLoc(kind, state) {
      const own = kind.initialLoc(state);
      if (own) return { lat: own.lat, lon: own.lon, source: own.source || 'exif' };
      if (kind.needsLocation()) {
        const dev = await this.getDeviceLoc();
        if (dev) return { lat: dev.lat, lon: dev.lon, source: 'device' };
      }
      if (kind.needsSpot() && this.modalContext) return { lat: this.modalContext.lat, lon: this.modalContext.lon, source: 'chair' };
      const c = this.mapApp.getEngine().getCenter();
      return { lat: c.lat, lon: c.lon, source: 'default' };
    }

    // 媒體型別（照片／影片／聲音）可由後台關閉說明欄；文字與建立點位本身就是文字，一律保留
    captionAllowed(kind) {
      if (!['photo', 'video', 'audio'].includes(kind.key)) return true;
      const caps = (this.mapApp.contribCfg() || {}).captions;
      return !Array.isArray(caps) || caps.includes(kind.key);
    }

    cardHtml(kind) {
      const anon = esc(this.mapApp.anonName());
      return '<button class="btn small c-cancel" type="button" title="' + esc(t('cancel_remove_from_queue')) + '"><i class="fa-solid fa-xmark"></i></button>' +
        (kind.hasPreview() ? '<div class="thumb">' + kind.placeholderHtml() + '</div>' : '') +
        '<div class="fields">' +
          (kind.needsFile() ? '<div class="time">' + esc(t('loading')) + '</div>' : '') +
          '<details class="card-attribution"><summary><i class="fa-solid fa-angle-right" aria-hidden="true"></i>' + esc(t('individual_attribution')) + '</summary><input type="text" class="c-name" placeholder="' + anon + '"></details>' +
          kind.extraTopHtml() +
          (this.captionAllowed(kind) ? '<div class="markdown-field"><textarea class="c-cmt" placeholder="' + esc(t(kind.key === 'newspot' ? 'newspot_story_placeholder' : 'write_something_placeholder')) + '"></textarea><button class="markdown-help-trigger" type="button" data-markdown-help aria-haspopup="dialog" aria-label="' + esc(t('markdown_guide_title')) + '" title="' + esc(t('markdown_guide_title')) + '"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></button></div>' : '') +
          kind.extraBottomHtml() +
          (kind.needsSpot()
            ? '<label class="c-lab">' + esc(t('related_spot_label_multi')) + '</label>' +
              '<div class="row"><select class="c-spot"></select><button class="btn small c-nearest" type="button">' + esc(t('nearest_btn')) + '</button></div>'
            : '') +
          (kind.needsLocation()
            ? '<div class="mini"></div><div class="location-tools"><div class="loc"></div><button class="btn small c-reset-loc" type="button">' + esc(t('reset_location_btn')) + '</button></div>'
            : '') +
          '<div class="row"><button class="btn primary c-send">' + esc(t('submit_this_one')) + '</button><span class="status"></span></div>' +
        '</div>';
    }

    // file 為 null＝這個型別本來就沒有檔案（文字紀錄、建立點位）
    async addCard(kind, file) {
      const qe = this.$('queue').querySelector('.queue-empty'); if (qe) qe.remove();
      const id = (this.scope === 'spot' ? 'sq' : 'q') + (++this.queueSeq);
      const card = document.createElement('div');
      card.className = 'card sl-kind-' + kind.key + (kind.hasPreview() ? '' : ' sl-noprev');
      card.id = id;
      card.innerHTML = this.cardHtml(kind);
      this.$('queue').appendChild(card);
      card.querySelector('.c-name').value = this.$('name').value || '';
      card.querySelector('.c-name').setAttribute('aria-label', t('attribution_name'));
      card.querySelector('.c-name').oninput = () => this.refreshLicense();
      this.refreshLicense();

      const state = { id, kind, file, blob: null, thumb: null, duration: null, urls: [], loc: null, origLoc: null, source: null, done: false, picker: null };
      this.cards[id] = state;
      card.querySelector('.c-cancel').onclick = () => this.cancelCard(id);
      kind.wireExtra(state, card);
      card.querySelector('.c-send').onclick = () => this.submitCard(state, card);

      // 檔案處理（轉檔／抽幀／讀長度）。失敗只讓預覽區顯示錯誤，不把整張卡片丟掉——
      // 使用者至少看得到是哪一個檔案出問題，也還能取消它。
      if (file) {
        const thumbEl = card.querySelector('.thumb');
        try { await kind.prepare(file, state); }
        catch (e) { console.warn('投稿檔案處理失敗：', e); state.prepError = e; }
        try { kind.renderPreview(state, thumbEl); } catch (e) { thumbEl.textContent = t('media_read_failed'); }
      }
      const timeEl = card.querySelector('.time');
      if (timeEl) timeEl.innerHTML = '<i class="fa-solid fa-clock"></i> ' + this.mapApp.fmtTime(kind.timeOf(state));

      const ref = await this.resolveLoc(kind, state);
      if (kind.needsLocation()) {
        state.loc = { lat: ref.lat, lon: ref.lon };
        state.source = ref.source;
        state.origLoc = { lat: ref.lat, lon: ref.lon, source: ref.source };
      } else {
        state.ref = ref;   // 只用來算「最近的點位」，不會被送出
      }

      if (kind.needsSpot()) {
        const at = state.loc || state.ref;
        const defSpot = (kind.key !== 'newspot' && this.modalContext) ? this.modalContext.num : (this.mapApp.nearestSpot(at.lat, at.lon) || {}).num;
        const sel = card.querySelector('.c-spot');
        sel.innerHTML = this.mapApp.spotOptionsHtml(defSpot);
        card.querySelector('.c-nearest').onclick = () => {
          const p = state.loc || state.ref;
          const np = this.mapApp.nearestSpot(p.lat, p.lon);
          if (np) sel.value = String(np.num);
        };
      }
      if (kind.needsLocation()) this.setupMiniMap(state, card);
    }

    // 迷你地圖（可拖曳；只調整這一筆投稿自己的座標，不會改動點位座標）
    setupMiniMap(state, card) {
      const miniDiv = card.querySelector('.mini');
      const picker = this.mapApp.getEngine().createMiniPicker(miniDiv, { lat: state.loc.lat, lon: state.loc.lon, zoom: 16 });
      state.picker = picker;
      const updLoc = (pos) => {
        state.loc = { lat: pos.lat, lon: pos.lon };
        // 用外框顏色標示定位來源
        miniDiv.classList.remove('src-ok', 'src-warn', 'src-info', 'src-muted');
        miniDiv.classList.add('src-' + this.mapApp.srcTone(state.source));
        card.querySelector('.loc').innerHTML = this.mapApp.locNote(state.source) + ' <span class="loc-hint">' + esc(t('drag_to_fix_hint')) + '</span>';
      };
      picker.onChange(pos => { state.source = 'manual'; updLoc(pos); });
      updLoc({ lat: state.loc.lat, lon: state.loc.lon });
      card.querySelector('.c-reset-loc').onclick = () => {
        const o = state.origLoc; if (!o) return;
        picker.setPosition({ lat: o.lat, lon: o.lon }, { pan: true });
        state.source = o.source; updLoc({ lat: o.lat, lon: o.lon });
      };
    }

    async addFiles(files) {
      if (!files || !files.length) return;
      // 型別自己認領檔案（見 kind-base.js 的 match()），目前分頁的型別優先——只有音軌的 .webm
      // 在音訊分頁是音訊、在媒體分頁是影片，光看檔案分不出來，以使用者當下的分頁為準。
      // 認不出來的就整批擋下並說清楚，而不是靜靜地丟掉——現在 <input accept> 已經先擋一層，
      // 會走到這裡多半是拖檔或系統忽略 accept。
      const jobs = [];
      const rejected = [];
      files.forEach(f => { const k = SL.match(f, this.tab); if (k) jobs.push([k, f]); else rejected.push(f.name); });
      if (rejected.length) alert(t('unsupported_files_alert', { files: rejected.join('、') }));
      for (const [kind, file] of jobs) {
        try { await this.addCard(kind, file); }
        catch (err) { console.warn('這個檔案處理失敗，略過：', err); }
      }
    }

    // opts.bulk：批次送出時，成功後只更新輕量的投稿計數，地圖/清單重繪留給 submitAll 結束後一次做，避免逐筆重繪卡頓
    async submitCard(state, card, opts) {
      opts = opts || {};
      if (state.done) return true;
      const kind = state.kind;
      const statusEl = card.querySelector('.status');
      const btn = card.querySelector('.c-send');
      const cancelBtn = card.querySelector('.c-cancel');
      const bad = kind.validate(state, card);
      if (bad) { statusEl.textContent = bad; statusEl.className = 'status err'; return false; }
      btn.disabled = true; if (cancelBtn) cancelBtn.disabled = true;
      statusEl.textContent = t('uploading'); statusEl.className = 'status';
      try {
        const spotSel = card.querySelector('.c-spot');
        const spotNum = spotSel ? parseInt(spotSel.value, 10) : NaN;
        const common = {
          kind: kind.key,
          item_num: isNaN(spotNum) ? undefined : spotNum,
          name: card.querySelector('.c-name').value.trim() || this.$('name').value.trim() || (this.mapApp.hasIdentity() ? this.mapApp.displayName() : ''),
          comment: (card.querySelector('.c-cmt') || { value: '' }).value.trim(),
          lat: state.loc ? state.loc.lat : undefined,
          lon: state.loc ? state.loc.lon : undefined,
          loc_source: state.loc ? state.source : undefined,
        };
        // 授權與連結共用，沒有身分及署名的個別項目使用 CC0。
        // 伺服器另行驗證授權白名單與署名條件。
        const ccByChk = document.getElementById('ccByChk');
        const wikidataChk = document.getElementById('wikidataChk');
        common.license = ccByChk && (this.mapApp.hasIdentity() || common.name) ? ccByChk.value : 'cc0';
        common.author_url = document.getElementById('authorUrl')?.value.trim() || '';
        if (common.author_url && !/^https?:\/\//i.test(common.author_url)) throw Error(t('author_url_invalid'));
        common.wikidata_ok = (wikidataChk && wikidataChk.checked) ? 1 : 0;

        await kind.submit(this.mapApp, kind.fields(state, card, common), {
          maxRetry: MAX_RATE_RETRY,
          onRetry: (wait, attempt, maxAttempt) => waitCountdown(statusEl, wait, attempt, maxAttempt),
        });
        this.mapApp.trackFeature(kind.key === 'newspot' ? 'newspot' : 'upload');
        // 成功：鎖定卡片
        state.done = true;
        // 建立點位會改變點位清單與圖例，批次模式那套「只更新計數」不夠用，一律整個重繪
        if (opts.bulk && kind.key !== 'newspot') { this.mapApp.refreshCounts(); } else { this.mapApp.refreshAll(); }
        card.classList.add('done');
        card.querySelectorAll('input,textarea,button,select').forEach(el => el.disabled = true);
        if (state.picker) state.picker.setDraggable(false);
        statusEl.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('submitted_locked')); statusEl.className = 'status ok';
        return true;
      } catch (err) {
        // 重試次數用盡仍被限流：留在佇列裡，讓使用者可按「送出這則」手動再試，不會憑空消失
        statusEl.textContent = err.rateLimited ? t('failed_rate_limited_retry_manually') : t('save_failed', { err: err.message || err });
        statusEl.className = 'status err';
        btn.disabled = false; if (cancelBtn) cancelBtn.disabled = false;
        return false;
      }
    }

    async submitAll() {
      const ids = Object.keys(this.cards).filter(id => !this.cards[id].done);
      if (!ids.length) return;
      this.batchRunning = true;
      const submitBtn = this.$('submit');
      const prog = this.$('prog');
      const total = ids.length;
      let ok = 0, fail = 0;
      if (submitBtn) submitBtn.disabled = true;
      const showProg = () => { if (prog) { prog.removeAttribute('data-done'); prog.textContent = t('upload_progress', { done: ok + fail, total: total, failSuffix: fail ? t('upload_fail_suffix', { fail: fail }) : '' }); } };
      showProg();
      for (const id of ids) {
        const st = this.cards[id]; const card = document.getElementById(id);
        if (!st || st.done || !card) continue;
        const success = await this.submitCard(st, card, { bulk: true });
        if (success) ok++; else fail++;
        showProg();
      }
      // 批次跑完後才一次重繪地圖／清單，避免每送出一筆就整層重繪造成卡頓
      this.mapApp.refreshAll();
      this.batchRunning = false;
      if (this.notifyParent) this.notifyParent('contribSubmitted', { ok, fail });
      if (submitBtn) submitBtn.disabled = false;
      if (prog) {
        if (!fail) { prog.dataset.done = '1'; prog.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('upload_all_done', { ok: ok })); setTimeout(() => { if (prog.dataset.done === '1') { prog.textContent = ''; delete prog.dataset.done; } }, 5000); }
        else prog.textContent = t('upload_partial_done', { ok: ok, total: total, fail: fail });
      }
    }
  }

  // 建立點位對話框只在載入了 kind-newspot.js 時才有分頁，沒有分頁的實例什麼都不放
  ['contrib', 'spot'].forEach(scope => {
    const plugin = new ContributionPlugin(scope);
    plugin.init(window.MapApp);
    // 分頁列與空狀態要等 DOM 都插好才畫得出來（init() 內部會呼叫 mount()）
    plugin.syncTabUi();
    plugin.resetQueue();
  });
})();
