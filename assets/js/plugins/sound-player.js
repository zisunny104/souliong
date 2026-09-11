/* 選用插件：聲音地圖的播放器／點位卡片（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 contrib.primaryKind 是 audio 時，view.php 才會載入這個檔案。
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
      this.miniPoint = null;
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
      this.buildMini();
      this.mapApp.registerEntriesHint(point => { this.onRender(point); return null; });
      this.mapApp.onHook('panelReset', () => this.onPanelReset());
    }

    isMobile() { return window.matchMedia('(max-width:640px)').matches; }

    /* ---------- 建立一次性的新 DOM ---------- */

    buildHandle(panel) {
      const handle = document.createElement('div');
      handle.className = 'sl-mp-handle';
      panel.insertBefore(handle, panel.firstChild);
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
    }

    buildHeadCover(panel) {
      const cover = document.createElement('div');
      cover.className = 'sl-mp-cover sl-mp-head-cover';
      const head = panel.querySelector('.p-head');
      head.insertBefore(cover, head.firstChild);
      this.headCover = cover;
    }

    buildMedium(panel) {
      const el = document.createElement('div');
      el.className = 'sl-mp-medium';
      el.innerHTML =
        '<div class="sl-mp-medium-top">' +
          '<div class="sl-mp-cover sl-mp-medium-cover"></div>' +
          '<div class="sl-mp-medium-text">' +
            '<div class="sl-mp-medium-title"></div>' +
            '<div class="sl-mp-medium-desc"></div>' +
          '</div>' +
        '</div>' +
        '<button class="sl-play-btn sl-mp-medium-play" type="button" aria-label="' + esc(t('play_audio_btn')) + '"><i class="fa-solid fa-play" aria-hidden="true"></i></button>' +
        '<div class="sl-mp-medium-bar"><div class="sl-mp-medium-fill"></div></div>' +
        '<div class="sl-mp-medium-time"><span class="sl-mp-medium-cur">0:00</span><span class="sl-mp-medium-dur"></span></div>';
      const body = panel.querySelector('.p-body');
      panel.insertBefore(el, body);
      this.medium = el;
      this.mediumTitle = el.querySelector('.sl-mp-medium-title');
      this.mediumDesc = el.querySelector('.sl-mp-medium-desc');
      this.mediumCover = el.querySelector('.sl-mp-medium-cover');
      this.mediumBtn = el.querySelector('.sl-mp-medium-play');
      this.mediumBar = el.querySelector('.sl-mp-medium-bar');
      this.mediumFill = el.querySelector('.sl-mp-medium-fill');
      this.mediumCur = el.querySelector('.sl-mp-medium-cur');
      this.mediumDur = el.querySelector('.sl-mp-medium-dur');
      this.mediumBtn.onclick = () => this.togglePlay();
      this.attachSeek(this.mediumBar);
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
          '<div class="sl-mp-mini-cat"></div>' +
          '<div class="sl-mp-mini-title"></div>' +
          '<div class="sl-mp-mini-sub"></div>' +
        '</div>' +
        '<button class="sl-play-btn sl-mp-mini-play" type="button" aria-label="' + esc(t('play_audio_btn')) + '"><i class="fa-solid fa-play" aria-hidden="true"></i></button>' +
        '<div class="sl-mp-mini-bar"><div class="sl-mp-mini-fill"></div></div>';
      document.body.appendChild(el);
      this.mini = el;
      this.miniCover = el.querySelector('.sl-mp-mini-cover');
      this.miniCatEl = el.querySelector('.sl-mp-mini-cat');
      this.miniTitleEl = el.querySelector('.sl-mp-mini-title');
      this.miniSubEl = el.querySelector('.sl-mp-mini-sub');
      this.miniBtn = el.querySelector('.sl-mp-mini-play');
      this.miniBar = el.querySelector('.sl-mp-mini-bar');
      this.miniFill = el.querySelector('.sl-mp-mini-fill');
      this.miniBtn.onclick = (ev) => { ev.stopPropagation(); this.togglePlay(); };
      this.attachSeek(this.miniBar);
      el.addEventListener('click', (ev) => {
        if (ev.target.closest('.sl-mp-mini-play') || ev.target.closest('.sl-mp-mini-bar')) return;
        this.reopen();
      });
      el.addEventListener('keydown', ev => {
        if ((ev.key === 'Enter' || ev.key === ' ') && !ev.target.closest('.sl-mp-mini-play')) { ev.preventDefault(); this.reopen(); }
      });
    }

    /* ---------- 每次 renderEntries() 都會重跑一次 ---------- */

    onRender(point) {
      const panel = document.getElementById('panel');
      const audio = document.querySelector('#entries .story .sl-aplay audio');
      this.hasAudio = !!(audio && audio.getAttribute('src'));
      this.audioEl = this.hasAudio ? audio : null;

      const isNewPoint = point.num !== this.lastNum;
      this.lastNum = point.num;
      if (isNewPoint) panel.classList.remove('sl-full');
      panel.classList.toggle('sl-has-audio', this.hasAudio);

      const catEl = document.getElementById('pCat');
      const titleText = document.getElementById('pTitle').textContent;
      const captionEl = document.querySelector('#entries .story .story-caption');
      const caption = captionEl ? captionEl.textContent.trim() : '';
      const subRaw = document.getElementById('pSub').innerHTML || '';
      const subText = subRaw.replace(/<br\s*\/?>/gi, ' ・ ').trim();
      const blurb = caption || subText;

      this.mediumTitle.textContent = titleText;
      this.mediumDesc.textContent = blurb;

      const url = this.coverUrlFor(point);
      panel.classList.toggle('sl-has-cover', !!url);
      this.paintCover(this.headCover, url);
      this.paintCover(this.mediumCover, url);

      this.syncExpandIcon(panel.classList.contains('wide'));

      this.miniPoint = point;
      this.miniCatText = catEl ? catEl.textContent : '';
      this.miniCatColor = catEl ? catEl.style.color : '';
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

    /* ---------- 封面圖：優先取這個點最新的照片投稿，沒有就用分類色＋唱片圖示頂替 ---------- */

    coverUrlFor(point) {
      const App = this.mapApp;
      const photos = App.effectiveEntries().filter(e => e.item_num === point.num && App.kindOf(e) === 'photo');
      if (!photos.length) return null;
      photos.sort((a, b) => new Date(b.photo_time || b.created_at) - new Date(a.photo_time || a.created_at));
      return App.entryThumbUrl(photos[0]) || null;
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
      if (!btn) return;
      btn.title = t(expanded ? 'collapse_panel' : 'expand_panel');
      btn.setAttribute('aria-label', btn.title);
      btn.innerHTML = '<i class="fa-solid ' + (expanded ? 'fa-down-left-and-up-right-to-center' : 'fa-up-right-and-down-left-from-center') + '" aria-hidden="true"></i>';
    }

    /* ---------- 迷你列：退到探索地圖但音訊繼續播 ---------- */

    showMini() {
      if (!this.miniPoint) return;
      this.miniCatEl.textContent = this.miniCatText;
      this.miniCatEl.style.color = this.miniCatColor;
      this.miniTitleEl.textContent = this.miniTitleText;
      this.miniSubEl.textContent = this.miniSubText;
      this.paintCover(this.miniCover, this.miniCoverUrl);
      this.mini.hidden = false;
    }

    hideMini() { this.mini.hidden = true; }

    reopen() {
      if (!this.miniPoint) return;
      const panel = document.getElementById('panel');
      const cur = this.mapApp.getCurrentPoint();
      if (cur && cur.num === this.miniPoint.num) panel.classList.add('open');
      else this.mapApp.openPanel(this.miniPoint);
      this.hideMini();
    }

    /* ---------- 播放器：中卡／迷你列的按鈕與進度條都操作同一顆 <audio> ---------- */

    attachSeek(bar) {
      let dragging = false;
      const seek = (clientX) => {
        const a = this.audioEl;
        if (!a || !a.duration) return;
        const r = bar.getBoundingClientRect();
        const ratio = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
        a.currentTime = ratio * a.duration;
        this.syncProgress();
      };
      bar.addEventListener('pointerdown', ev => {
        if (!this.audioEl) return;
        dragging = true;
        try { bar.setPointerCapture(ev.pointerId); } catch (err) {}
        seek(ev.clientX);
        ev.stopPropagation();
      });
      bar.addEventListener('pointermove', ev => { if (dragging) seek(ev.clientX); });
      bar.addEventListener('pointerup', () => { dragging = false; });
    }

    togglePlay() {
      const a = this.audioEl;
      if (!a) return;
      if (a.paused) a.play().catch(() => {}); else a.pause();
    }

    onAudioChanged() {
      const a = this.audioEl;
      this.syncPlayIcon(!!a && !a.paused);
      this.syncProgress();
      if (!a) return;
      a.addEventListener('play', () => this.syncPlayIcon(true));
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

    syncProgress() {
      const a = this.audioEl;
      const pct = (a && a.duration) ? (a.currentTime / a.duration * 100) : 0;
      this.mediumFill.style.width = pct + '%';
      this.miniFill.style.width = pct + '%';
      this.mediumCur.textContent = a ? this.mapApp.fmtDur(a.currentTime) : '0:00';
      this.mediumDur.textContent = (a && a.duration) ? this.mapApp.fmtDur(a.duration) : '';
    }
  }

  new SoundPlayerPlugin().init(window.MapApp);
})();
