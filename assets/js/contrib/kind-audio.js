/* 音訊：選檔 + 現場錄音
   影片只支援選檔、音訊多一條現場錄音，是刻意的取捨：錄音只要一顆麥克風權限、產出的檔案小，
   在現場「講一段這裡發生過什麼」是這個工具最想要的東西；錄影則要相機權限、檔案動輒數十 MB，
   多數手機主機的 php.ini 上限根本收不下，不如讓使用者用系統相機拍完再選檔。

   錄音用 MediaRecorder。它產出的容器由瀏覽器決定（Chrome/Firefox 是 webm/opus、Safari 是 mp4/aac），
   這裡不強求統一——伺服器端本來就是用 finfo 認實際內容，不看副檔名也不看瀏覽器說的 MIME。

   檔案分兩層：window.SLAudioTools 是跟投稿無關的錄音／選檔／量時長工具（點位內容編輯的
   content-editor.js 也直接用，不需要 upload 模組與 kind-base.js）；「audio 投稿型別」則只在這張地圖
   確實開了 upload 模組、且 contrib.kinds 含 audio 時才登記進投稿型別註冊表。 */
(() => {
  if (window.SLAudioTools) return;
  const SL = window.SLContrib;
  const I18N = window.I18N || {};
  const t = SL ? SL.t : (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = SL ? SL.esc : (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  const clock = (sec) => Math.floor(sec / 60) + ':' + String(Math.floor(sec % 60)).padStart(2, '0');

  function loadAudio(url) {
    return new Promise((resolve, reject) => {
      const a = document.createElement('audio');
      a.preload = 'metadata';
      a.onloadedmetadata = () => resolve(a);
      a.onerror = () => reject(new Error('audio decode failed'));
      a.src = url;
    });
  }

  const tools = {
    // .webm／.mp4 也收：只有音軌的檔案，瀏覽器仍然照副檔名給 video/* 的 MIME（錄音存下來
    // 再重新選檔就是這種）。搶不到影片的檔案——SLContrib.match() 讓「目前分頁」的型別優先，
    // 在音訊分頁選它才輪得到這裡，同一個檔案在媒體分頁仍然是影片。
    acceptAttr() { return 'audio/*,.weba,.webm,.opus,.m4a'; },
    accepts(file) { return /^audio\//i.test(file.type) || /\.(mp3|m4a|aac|ogg|oga|opus|wav|weba|webm|mp4)$/i.test(file.name); },

    // 讀完檔案把主檔、試聽網址、時長放進 state；建立的 object URL 記在 state.urls，由呼叫端回收
    async prepare(file, state) {
      state.blob = file;
      const url = URL.createObjectURL(file);
      (state.urls = state.urls || []).push(url);
      state.playUrl = url;
      try {
        const a = await loadAudio(url);
        // 串流式 webm（MediaRecorder 的產物）常常回 Infinity，那就存 null，顯示端自己 fallback
        if (isFinite(a.duration) && a.duration > 0) state.duration = a.duration;
      } catch (e) { state.mediaError = true; }
    },

    // 現場錄音：回傳一顆按鈕，錄完把 File 丟回 onFile()，之後跟「選了一個音訊檔」走完全一樣的路。
    // 按鈕帶 abort()：放棄目前這段錄音並關掉麥克風（畫面被重繪或編輯被取消時用），不會呼叫 onFile()；
    // 瀏覽器不支援錄音時沒有 abort。
    buildRecorder(onFile) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn sl-rec-btn';
      const idle = () => { btn.innerHTML = '<i class="fa-solid fa-microphone"></i> ' + esc(t('record_start')); btn.classList.remove('recording'); };
      idle();

      if (!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
        btn.disabled = true;
        btn.title = t('record_unsupported');
        return btn;
      }

      let rec = null, stream = null, timer = null, started = 0;
      const stopAll = () => {
        if (timer) { clearInterval(timer); timer = null; }
        if (stream) { stream.getTracks().forEach(tr => tr.stop()); stream = null; }
        rec = null;
        idle();
      };

      btn.onclick = async () => {
        if (rec) { try { rec.stop(); } catch (e) { stopAll(); } return; }
        try {
          stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
          alert(t('record_denied'));
          stream = null;
          return;
        }
        // 挑一個這個瀏覽器真的錄得出來的容器；都不支援就交給預設值
        const want = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
        const mime = want.find(m => MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) || '';
        const chunks = [];
        try { rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined); }
        catch (e) { alert(t('record_denied')); stopAll(); return; }
        rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onstop = () => {
          const type = (rec && rec.mimeType) || mime || 'audio/webm';
          const ext = /mp4|aac/.test(type) ? 'm4a' : (/ogg/.test(type) ? 'ogg' : 'weba');
          const blob = new Blob(chunks, { type: type.split(';')[0] });
          stopAll();
          if (blob.size) onFile(new File([blob], 'recording_' + Date.now() + '.' + ext, { type: blob.type }));
        };
        rec.start();
        started = Date.now();
        btn.classList.add('recording');
        const tick = () => { btn.innerHTML = '<i class="fa-solid fa-stop"></i> ' + esc(t('record_stop', { time: clock((Date.now() - started) / 1000) })); };
        tick();
        timer = setInterval(tick, 500);
      };

      btn.abort = () => {
        if (rec) { rec.onstop = null; try { rec.stop(); } catch (e) {} }
        stopAll();
      };
      return btn;
    },
  };

  window.SLAudioTools = tools;
  const asContribKind = !!(SL && window.APP && APP.moduleState && APP.moduleState.upload && ((APP.contrib || {}).kinds || []).includes('audio'));
  if (!asContribKind) return;

  class AudioKind extends SL.Kind {
    get key() { return 'audio'; }
    get tab() { return 'audio'; }
    get icon() { return 'fa-microphone-lines'; }

    acceptAttr() { return tools.acceptAttr(); }
    accepts(file) { return tools.accepts(file); }
    prepare(file, state) { return tools.prepare(file, state); }
    // 錄音鈕交給 contribution.js 掛進音訊分頁，錄完的檔案走跟選檔一樣的卡片流程（試聽、定位、留言）
    buildRecorder(onFile) { return tools.buildRecorder(onFile); }

    renderPreview(state, el) {
      if (state.mediaError && !state.playUrl) { el.textContent = t('media_read_failed'); return; }
      el.innerHTML = '<i class="fa-solid ' + this.icon + '" aria-hidden="true"></i>' +
        '<audio class="sl-c-audio" src="' + esc(state.playUrl) + '" controls preload="metadata"></audio>' +
        (state.duration ? '<span class="sl-c-dur">' + esc(clock(state.duration)) + '</span>' : '');
    }

    fields(state, card, common) {
      const f = { ...common, media: [state.blob, state.blob.name || 'audio'] };
      if (state.duration) f.duration = state.duration;
      return f;
    }
  }

  SL.register(new AudioKind());
})();
