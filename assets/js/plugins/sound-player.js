/* 選用插件：聲音地圖的播放器／點位卡片（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 contrib.kinds 有開放 audio 種類時，view.php 才會載入這個檔案——
   任何一個點位都可能帶有 audio 原生內容，不看是不是「聲音地圖」，看的是有沒有 audio 內容。
   不碰 viewer.core.js 一行程式碼——全靠 registerEntriesHint()（每次 renderEntries() 都會呼叫，
   可以動任何 DOM，不限於 #entries）跟 panelReset 這個既有 hook 來擴充既有的 #panel，
   .p-close/.p-expand 的 onclick 完全沿用核心預設，這裡不重新綁定。
   手機版在既有的單一底部抽屜狀態之外，多插「中卡」「迷你列」兩個狀態，靠 .sl-mp-handle 拖曳
   切換（中卡/全卡/退回迷你列），不用另外的圖示按鈕；電腦版完全不動既有的 .wide 展開機制，
   只在 .p-head 多畫一張封面圖。播放器本身也不重做：所有新按鈕／進度條都直接
   操作核心 audioPlayerHtml() 產生的同一顆 <audio>，不另外建立第二顆播放器（見 onAudioChanged()）。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class SoundPlayerPlugin extends MapApp.Plugin {
    constructor() {
      super('soundPlayer');
      this.audioEl = null;
      this.hasAudio = false;
      this.lastNum = null;
      this.miniSpot = null;
      this.miniCatText = '';
      this.miniCatColor = '';
      this.miniTitleText = '';
      this.miniSubText = '';
      this.miniCoverUrl = null;
    }

    mount() {
      const panel = document.getElementById('panel');
      if (!panel) return;
      panel.classList.add('sl-audio');
      this.buildHandle(panel);
      this.buildHeadCover(panel);
      this.buildMedium(panel);
      this.attachMediumGesture(this.medium);
      this.attachCollapseGesture(this.head);
      this.buildMini();
      this.mapApp.registerEntriesHint(spot => { this.onRender(spot); return null; });
      this.mapApp.onHook('panelReset', () => this.onPanelReset());
    }

    isMobile() { return window.matchMedia('(max-width:640px)').matches; }

    /* ---------- 建立一次性的新 DOM ---------- */

    buildHandle(panel) {
      const handle = document.createElement('div');
      handle.className = 'sl-mp-handle';
      handle.setAttribute('role', 'button');
      handle.tabIndex = 0;
      handle.setAttribute('aria-label', t('expand_panel'));
      panel.insertBefore(handle, panel.firstChild);
      this.handle = handle;
      let startY = null, moved = false;
      handle.addEventListener('pointerdown', ev => {
        startY = ev.clientY; moved = false;
        try { handle.setPointerCapture(ev.pointerId); } catch (err) {}
      });
      handle.addEventListener('pointermove', ev => {
        if (startY == null) return;
        const dy = ev.clientY - startY;
        if (Math.abs(dy) < 24) return;
        moved = true; startY = null;
        if (dy < 0) { this.setSize('full'); return; }
        if (panel.classList.contains('sl-full')) this.setSize('medium');
        else this.mapApp.closePanel();
      });
      handle.addEventListener('pointerup', () => { startY = null; });
      handle.addEventListener('click', () => { if (!moved) this.toggleSize(); });
      // 鍵盤等效：上下滑動手勢分別對應方向鍵，Enter/Space 對應點擊（切換全卡／中卡）
      handle.addEventListener('keydown', ev => {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); this.toggleSize(); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); this.setSize('full'); }
        else if (ev.key === 'ArrowDown') {
          ev.preventDefault();
          if (panel.classList.contains('sl-full')) this.setSize('medium');
          else this.mapApp.closePanel();
        }
      });
    }

    buildHeadCover(panel) {
      const cover = document.createElement('div');
      cover.className = 'sl-mp-cover sl-mp-head-cover';
      const head = panel.querySelector('.p-head');
      head.insertBefore(cover, head.firstChild);
      this.headCover = cover;
      this.head = head;
    }

    buildMedium(panel) {
      const el = document.createElement('div');
      el.className = 'sl-mp-medium';
      el.innerHTML =
        '<div class="sl-mp-medium-top">' +
          '<div class="sl-mp-cover sl-mp-medium-cover"></div>' +
          '<div class="sl-mp-medium-text">' +
            '<div class="sl-mp-cat"></div>' +
            '<div class="sl-mp-title"></div>' +
            '<div class="sl-mp-sub"></div>' +
          '</div>' +
        '</div>' +
        '<div class="sl-mp-medium-controls">' +
          '<button class="sl-mp-side-btn sl-mp-medium-loop" type="button"><i class="fa-solid fa-repeat" aria-hidden="true"></i></button>' +
          '<button class="sl-play-btn sl-mp-medium-play" type="button" aria-label="' + esc(t('play_audio_btn')) + '"><i class="fa-solid fa-play" aria-hidden="true"></i></button>' +
          '<button class="sl-mp-side-btn sl-mp-medium-mute" type="button"><i class="fa-solid fa-volume-high" aria-hidden="true"></i></button>' +
        '</div>' +
        '<div class="sl-mp-medium-bar"><div class="sl-mp-medium-fill"></div></div>' +
        '<div class="sl-mp-medium-time"><span class="sl-mp-medium-cur">0:00</span><span class="sl-mp-medium-dur"></span></div>';
      const body = panel.querySelector('.p-body');
      panel.insertBefore(el, body);
      this.medium = el;
      this.mediumCat = el.querySelector('.sl-mp-cat');
      this.mediumTitle = el.querySelector('.sl-mp-title');
      this.mediumSub = el.querySelector('.sl-mp-sub');
      this.mediumCover = el.querySelector('.sl-mp-medium-cover');
      this.mediumBtn = el.querySelector('.sl-mp-medium-play');
      this.mediumLoopBtn = el.querySelector('.sl-mp-medium-loop');
      this.mediumMuteBtn = el.querySelector('.sl-mp-medium-mute');
      this.mediumBar = el.querySelector('.sl-mp-medium-bar');
      this.mediumFill = el.querySelector('.sl-mp-medium-fill');
      this.mediumCur = el.querySelector('.sl-mp-medium-cur');
      this.mediumDur = el.querySelector('.sl-mp-medium-dur');
      this.mediumBtn.onclick = () => this.togglePlay();
      this.mediumLoopBtn.onclick = () => this.toggleLoop();
      this.mediumMuteBtn.onclick = () => this.toggleMute();
      this.initSeekAria(this.mediumBar);
      this.attachSeek(this.mediumBar);
    }

    /* 整張中卡都能滑／點展開收合，不侷限 .sl-mp-handle 窄把手（播放鍵／進度條自行處理
       手勢，此處排除）。上滑或點擊展開大卡；下滑收成迷你列，效果同把手下滑。 */
    attachMediumGesture(el) {
      const IGNORE = '.sl-mp-medium-play, .sl-mp-medium-bar';
      let startY = null, startT = 0, moved = false;
      el.addEventListener('pointerdown', ev => {
        if (ev.target.closest(IGNORE)) return;
        startY = ev.clientY; startT = performance.now(); moved = false;
        try { el.setPointerCapture(ev.pointerId); } catch (err) {}
      });
      el.addEventListener('pointermove', ev => {
        if (startY == null) return;
        if (Math.abs(ev.clientY - startY) > 6) moved = true;
      });
      el.addEventListener('pointerup', ev => {
        if (startY == null) return;
        const dy = ev.clientY - startY;
        const dt = Math.max(1, performance.now() - startT);
        startY = null;
        if (dy < -30 || dy / dt < -0.5 || !moved) { this.setSize('full'); return; }
        if (dy > 30 || dy / dt > 0.5) this.mapApp.closePanel();
      });
    }

    /* .p-head 下滑收合成中卡，只做下滑、不做點擊（標題文字／編輯鈕本來就要點擊閱讀，
       點擊收合易誤觸）。只在手機＋有聲音投稿時生效：.p-head 電腦版也一直顯示，若不擋住
       會跟核心 togglePanelSize() 的 .wide／.p-expand 圖示互相覆寫，顯示狀態對不上。 */
    attachCollapseGesture(el) {
      const IGNORE = 'button, a, input, select, textarea, .spot-editor';
      const panel = document.getElementById('panel');
      let startY = null, startT = 0;
      el.addEventListener('pointerdown', ev => {
        if (!this.isMobile() || !panel.classList.contains('sl-has-audio')) return;
        if (ev.target.closest(IGNORE)) return;
        startY = ev.clientY; startT = performance.now();
        try { el.setPointerCapture(ev.pointerId); } catch (err) {}
      });
      el.addEventListener('pointerup', ev => {
        if (startY == null) return;
        const dy = ev.clientY - startY;
        const dt = Math.max(1, performance.now() - startT);
        startY = null;
        if (dy > 30 || dy / dt > 0.5) this.setSize('medium');
      });
    }

    /* 迷你列點擊會重開，這裡加上滑手勢（門檻同 attachMediumGesture）。手勢達標時標記
       swiped，讓隨後補發的 click 略過，避免重複觸發 reopen()。 */
    attachMiniGesture(el) {
      const IGNORE = '.sl-mp-mini-play, .sl-mp-mini-bar';
      let startY = null, startT = 0, swiped = false;
      el.addEventListener('pointerdown', ev => {
        swiped = false;
        if (ev.target.closest(IGNORE)) return;
        startY = ev.clientY; startT = performance.now();
        try { el.setPointerCapture(ev.pointerId); } catch (err) {}
      });
      el.addEventListener('pointerup', ev => {
        if (startY == null) return;
        const dy = ev.clientY - startY;
        const dt = Math.max(1, performance.now() - startT);
        startY = null;
        if (dy < -30 || dy / dt < -0.5) { swiped = true; this.reopen(); }
      });
      el.addEventListener('click', ev => {
        if (ev.target.closest(IGNORE)) return;
        if (swiped) return;
        this.reopen();
      });
    }

    buildMini() {
      const el = document.createElement('div');
      el.className = 'sl-mp-mini';
      el.hidden = true;
      el.setAttribute('role', 'button');
      el.tabIndex = 0;
      el.setAttribute('aria-label', t('reopen_player_aria'));
      el.innerHTML =
        '<div class="sl-mp-cover sl-mp-mini-cover"></div>' +
        '<div class="sl-mp-mini-info">' +
          '<div class="sl-mp-cat"></div>' +
          '<div class="sl-mp-title"></div>' +
          '<div class="sl-mp-sub"></div>' +
        '</div>' +
        '<button class="sl-play-btn sl-mp-mini-play" type="button" aria-label="' + esc(t('play_audio_btn')) + '"><i class="fa-solid fa-play" aria-hidden="true"></i></button>' +
        '<div class="sl-mp-mini-bar"><div class="sl-mp-mini-fill"></div></div>';
      document.body.appendChild(el);
      this.mini = el;
      if (window.ResizeObserver) {
        new ResizeObserver(() => { if (!el.hidden) this.updateMiniGap(); }).observe(el);
      }
      this.miniCover = el.querySelector('.sl-mp-mini-cover');
      this.miniCatEl = el.querySelector('.sl-mp-cat');
      this.miniTitleEl = el.querySelector('.sl-mp-title');
      this.miniSubEl = el.querySelector('.sl-mp-sub');
      this.miniBtn = el.querySelector('.sl-mp-mini-play');
      this.miniBar = el.querySelector('.sl-mp-mini-bar');
      this.miniFill = el.querySelector('.sl-mp-mini-fill');
      this.miniBtn.onclick = (ev) => { ev.stopPropagation(); this.togglePlay(); };
      this.initSeekAria(this.miniBar);
      this.attachSeek(this.miniBar);
      this.attachMiniGesture(el);
      el.addEventListener('keydown', ev => {
        if (ev.target.closest('.sl-mp-mini-play, .sl-mp-mini-bar')) return;
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); this.reopen(); }
      });
    }

    /* ---------- 每次 renderEntries() 都會重跑一次 ---------- */

    onRender(spot) {
      const panel = document.getElementById('panel');
      const audio = document.querySelector('#entries .story .sl-aplay audio');
      this.hasAudio = !!(audio && audio.getAttribute('src'));
      this.audioEl = this.hasAudio ? audio : null;
      // <audio> 吃 preload="none"（見 viewer.core.js audioPlayerHtml() 註解，避免捲過卡片牆就整批下載），
      // duration 要等按下播放才會有值；.sl-aplay-dur 是同一顆音訊的「故事」版播放器，用投稿時存的
      // 秒數欄位直接填字，不用等瀏覽器讀檔，借來當作播放前的預顯示值，播放後才換成 audio.duration 現測值。
      const durEl = this.hasAudio ? document.querySelector('#entries .story .sl-aplay .sl-aplay-dur') : null;
      this.knownDurText = durEl ? durEl.textContent : '';

      // 故事區的大播放器（.sl-aplay-lg）是電腦版跟手機全卡共用的同一顆，核心 audioPlayerHtml()
      // 沒有循環／靜音鍵，時間跟播放鍵各自佔一列太鬆。這裡把時間搬進按鈕列兩端（時間仍左右
      // 分居），按鈕居中一組，進度條獨立一列在下面；.sl-aplay-time 淨空後隱藏。只搬動既有節點＋
      // 插入新按鈕，核心 wireAudioPlayer() 靠 class 選取＋事件監聽都不受影響。
      const bigWrap = this.hasAudio ? document.querySelector('#entries .story .sl-aplay-lg') : null;
      const bigPlayBtn = bigWrap ? bigWrap.querySelector('.sl-aplay-btn') : null;
      const bigCurEl = bigWrap ? bigWrap.querySelector('.sl-aplay-cur') : null;
      const bigDurEl = bigWrap ? bigWrap.querySelector('.sl-aplay-dur') : null;
      if (bigWrap && bigPlayBtn) {
        const controls = document.createElement('div');
        controls.className = 'sl-mp-big-controls';
        bigWrap.insertBefore(controls, bigPlayBtn);

        const btns = document.createElement('div');
        btns.className = 'sl-mp-big-btns';
        btns.insertAdjacentHTML('beforeend', '<button class="sl-mp-side-btn sl-mp-big-loop" type="button"><i class="fa-solid fa-repeat" aria-hidden="true"></i></button>');
        btns.appendChild(bigPlayBtn);
        btns.insertAdjacentHTML('beforeend', '<button class="sl-mp-side-btn sl-mp-big-mute" type="button"><i class="fa-solid fa-volume-high" aria-hidden="true"></i></button>');

        if (bigCurEl) controls.appendChild(bigCurEl);
        controls.appendChild(btns);
        if (bigDurEl) controls.appendChild(bigDurEl);
      }
      this.bigLoopBtn = bigWrap ? bigWrap.querySelector('.sl-mp-big-loop') : null;
      this.bigMuteBtn = bigWrap ? bigWrap.querySelector('.sl-mp-big-mute') : null;
      if (this.bigLoopBtn) this.bigLoopBtn.onclick = () => this.toggleLoop();
      if (this.bigMuteBtn) this.bigMuteBtn.onclick = () => this.toggleMute();

      const isNewSpot = spot.num !== this.lastNum;
      this.lastNum = spot.num;
      if (isNewSpot) panel.classList.remove('sl-full');
      panel.classList.toggle('sl-has-audio', this.hasAudio);

      const catEl = document.getElementById('pCat');
      const catText = catEl ? catEl.textContent : '';
      const catColor = catEl ? catEl.style.color : '';
      const titleText = document.getElementById('pTitle').textContent;
      const subRaw = document.getElementById('pSub').innerHTML || '';
      const subText = subRaw.replace(/<br\s*\/?>/gi, ' ・ ').trim();

      this.mediumCat.textContent = catText;
      this.mediumCat.style.color = catColor;
      this.mediumTitle.textContent = titleText;
      this.mediumSub.textContent = subText;

      const url = this.coverUrlFor(spot);
      panel.classList.toggle('sl-has-cover', !!url);
      this.paintCover(this.headCover, url);
      this.paintCover(this.mediumCover, url);

      this.syncExpandIcon(panel.classList.contains('wide'));

      this.miniSpot = spot;
      this.miniCatText = catText;
      this.miniCatColor = catColor;
      this.miniTitleText = titleText;
      this.miniSubText = subText;
      this.miniCoverUrl = url;

      this.onAudioChanged();
      this.hideMini();
    }

    onPanelReset() {
      if (!this.isMobile() || !this.hasAudio || !this.audioEl) return;
      this.showMini();
    }

    /* ---------- 封面圖：點位自己的 photo/thumb 欄位，沒有就用分類色＋唱片圖示頂替 ---------- */

    coverUrlFor(spot) {
      return this.mapApp.entryThumbUrl(spot) || null;
    }

    paintCover(el, url) {
      if (!el) return;
      el.hidden = !url;
      if (url) el.innerHTML = '<img src="' + esc(url) + '" alt="" loading="lazy">';
    }

    /* ---------- 手機中卡／全卡尺寸 ---------- */

    setSize(state) {
      const panel = document.getElementById('panel');
      const full = state === 'full';
      panel.classList.toggle('sl-full', full);
      this.syncExpandIcon(full);
    }

    toggleSize() {
      const panel = document.getElementById('panel');
      this.setSize(panel.classList.contains('sl-full') ? 'medium' : 'full');
    }

    syncExpandIcon(expanded) {
      const btn = document.querySelector('#panel .p-expand');
      if (btn) {
        btn.title = t(expanded ? 'collapse_panel' : 'expand_panel');
        btn.setAttribute('aria-label', btn.title);
        btn.innerHTML = '<i class="fa-solid ' + (expanded ? 'fa-down-left-and-up-right-to-center' : 'fa-up-right-and-down-left-from-center') + '" aria-hidden="true"></i>';
      }
      if (this.handle) this.handle.setAttribute('aria-label', t(expanded ? 'collapse_panel' : 'expand_panel'));
    }

    /* ---------- 迷你列：退到探索地圖但音訊繼續播 ---------- */

    showMini() {
      if (!this.miniSpot) return;
      this.miniCatEl.textContent = this.miniCatText;
      this.miniCatEl.style.color = this.miniCatColor;
      this.miniTitleEl.textContent = this.miniTitleText;
      this.miniSubEl.textContent = this.miniSubText;
      this.paintCover(this.miniCover, this.miniCoverUrl);
      this.mini.hidden = false;
      document.body.classList.add('sl-mini-open');
      this.updateMiniGap();
    }

    hideMini() {
      this.mini.hidden = true;
      document.body.classList.remove('sl-mini-open');
      document.body.style.removeProperty('--sl-mini-gap');
    }

    // 左下地圖操作區／版權列往上推的距離：迷你列自身高度＋它的 bottom:10px 留白＋
    // 再留 10px 間距，跟現有的 10px 視覺節奏一致（見 sound-player.css 的 .sl-mini-open 規則）
    updateMiniGap() {
      const h = this.mini.getBoundingClientRect().height;
      document.body.style.setProperty('--sl-mini-gap', h ? (h + 20) + 'px' : '0px');
    }

    reopen() {
      if (!this.miniSpot) return;
      const panel = document.getElementById('panel');
      const cur = this.mapApp.getCurrentSpot();
      if (cur && cur.num === this.miniSpot.num) panel.classList.add('open');
      else this.mapApp.openPanel(this.miniSpot);
      this.hideMini();
    }

    /* ---------- 播放器：中卡／迷你列的按鈕與進度條都操作同一顆 <audio> ---------- */

    initSeekAria(bar) {
      bar.setAttribute('role', 'slider');
      bar.tabIndex = 0;
      bar.setAttribute('aria-label', t('seek_audio_aria'));
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '0');
      bar.setAttribute('aria-valuenow', '0');
    }

    attachSeek(bar) {
      let dragging = false;
      let rect = null;
      const seek = (clientX) => {
        const a = this.audioEl;
        if (!a || !a.duration || !rect) return;
        const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
        a.currentTime = ratio * a.duration;
        this.syncProgress();
      };
      bar.addEventListener('pointerdown', ev => {
        if (!this.audioEl) return;
        dragging = true;
        rect = bar.getBoundingClientRect();
        try { bar.setPointerCapture(ev.pointerId); } catch (err) {}
        seek(ev.clientX);
        ev.stopPropagation();
      });
      // rect 在拖曳開始時量一次就好：拖曳中每次 pointermove 都重量會跟 syncProgress()
      // 寫入的 style.width 輪流觸發強制重排（layout thrashing），拖曳期間位置不會變不需要重量。
      bar.addEventListener('pointermove', ev => { if (dragging) seek(ev.clientX); });
      bar.addEventListener('pointerup', () => { dragging = false; rect = null; });
      // 鍵盤等效：左右／上下鍵各跳 5 秒，Home/End 跳到頭尾，取代僅能用滑鼠拖曳的操作
      bar.addEventListener('keydown', ev => {
        const a = this.audioEl;
        if (!a || !a.duration) return;
        let handled = true;
        if (ev.key === 'ArrowRight' || ev.key === 'ArrowUp') a.currentTime = Math.min(a.duration, a.currentTime + 5);
        else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowDown') a.currentTime = Math.max(0, a.currentTime - 5);
        else if (ev.key === 'Home') a.currentTime = 0;
        else if (ev.key === 'End') a.currentTime = a.duration;
        else handled = false;
        if (!handled) return;
        ev.preventDefault();
        ev.stopPropagation();
        this.syncProgress();
      });
    }

    togglePlay() {
      const a = this.audioEl;
      if (!a) return;
      if (a.paused) a.play().catch(() => {}); else a.pause();
    }

    // 循環／靜音只作用在中卡（迷你列本來就沒有這兩顆鍵），狀態直接讀寫同一顆 <audio>，
    // 不另外存一份旗標——renderEntries() 每次都重建 <audio>，換點位時自然重置回預設值。
    toggleLoop() {
      const a = this.audioEl;
      if (!a) return;
      a.loop = !a.loop;
      this.syncLoopIcon(a.loop);
    }

    toggleMute() {
      const a = this.audioEl;
      if (!a) return;
      a.muted = !a.muted;
      this.syncMuteIcon(a.muted);
    }

    onAudioChanged() {
      const a = this.audioEl;
      this.syncPlayIcon(!!a && !a.paused);
      this.syncLoopIcon(!!a && a.loop);
      this.syncMuteIcon(!!a && a.muted);
      this.syncInvite(!!a);
      this.syncProgress();
      if (!a) return;
      a.addEventListener('play', () => { this.syncPlayIcon(true); this.syncInvite(false); });
      a.addEventListener('pause', () => this.syncPlayIcon(false));
      a.addEventListener('ended', () => this.syncPlayIcon(false));
      a.addEventListener('timeupdate', () => this.syncProgress());
      a.addEventListener('loadedmetadata', () => this.syncProgress());
    }

    syncPlayIcon(playing) {
      const cls = 'fa-solid fa-' + (playing ? 'pause' : 'play');
      this.mediumBtn.querySelector('i').className = cls;
      this.miniBtn.querySelector('i').className = cls;
    }

    // 邀請點擊脈衝（spot-panel.css .sl-invite）：每次換點位／renderEntries() 都是全新的
    // <audio>，一律先當作沒播過；一按下播放（見上面 play 監聽）就整個頁面生命週期內不再回來。
    syncInvite(on) {
      this.mediumBtn.classList.toggle('sl-invite', on);
      this.miniBtn.classList.toggle('sl-invite', on);
    }

    syncLoopIcon(on) {
      const label = t(on ? 'loop_off_btn' : 'loop_on_btn');
      [this.mediumLoopBtn, this.bigLoopBtn].forEach(btn => {
        if (!btn) return;
        btn.classList.toggle('on', on);
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.setAttribute('aria-pressed', String(on));
      });
    }

    syncMuteIcon(on) {
      const label = t(on ? 'mute_off_btn' : 'mute_on_btn');
      const iconCls = 'fa-solid ' + (on ? 'fa-volume-xmark' : 'fa-volume-high');
      [this.mediumMuteBtn, this.bigMuteBtn].forEach(btn => {
        if (!btn) return;
        btn.classList.toggle('on', on);
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.setAttribute('aria-pressed', String(on));
        btn.querySelector('i').className = iconCls;
      });
    }

    syncProgress() {
      const a = this.audioEl;
      const pct = (a && a.duration) ? (a.currentTime / a.duration * 100) : 0;
      this.mediumFill.style.width = pct + '%';
      this.miniFill.style.width = pct + '%';
      this.mediumCur.textContent = a ? this.mapApp.fmtDur(a.currentTime) : '0:00';
      this.mediumDur.textContent = (a && a.duration) ? this.mapApp.fmtDur(a.duration) : this.knownDurText;
      const dur = (a && a.duration) ? a.duration : 0;
      const cur = a ? a.currentTime : 0;
      const valuetext = (a && a.duration)
        ? this.mapApp.fmtDur(cur) + ' / ' + this.mapApp.fmtDur(dur)
        : '0:00';
      this.setSeekAria(this.mediumBar, dur, cur, valuetext);
      this.setSeekAria(this.miniBar, dur, cur, valuetext);
    }

    setSeekAria(bar, dur, cur, valuetext) {
      bar.setAttribute('aria-valuemax', Math.round(dur));
      bar.setAttribute('aria-valuenow', Math.round(cur));
      bar.setAttribute('aria-valuetext', valuetext);
    }
  }

  new SoundPlayerPlugin().init(window.MapApp);
})();
