/* 投稿型別的共同基底（見 souliong/docs/EXTENDING.md 第三節）
   一張投稿卡片＝一個型別物件 + 一份 state。型別不是「一個外掛」而是「掛在每張卡片上的物件」，
   因為批次佇列本來就可能混型別（同一批丟一張照片、一段錄音、一則文字）——用組合而不是
   把 contribution.js 整個 subclass 出四份。

   contribution.js 只認得這裡定義的介面，完全不知道什麼是 EXIF、什麼是 MediaRecorder；
   反過來，型別檔也不碰佇列、進度、限流重試那些殼的事。
   view.php 依 meta.json 的 contrib.kinds 只載入該地圖啟用的型別檔（純文字地圖不會載到影片程式碼）。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class ContribKind {
    // ---- 身分 ----
    get key() { return ''; }              // data.jsonl 的 kind 值
    get tab() { return null; }            // 對話框分頁代號（要跟 features.php 的 souliong_kinds() 對上）
    get icon() { return 'fa-file'; }

    // ---- 選檔 ----
    // 給 <input type="file"> 的 accept 片段；同一個分頁有多個型別時由殼串起來。
    acceptAttr() { return ''; }
    // 這個檔案歸我管嗎？殼拿一批檔案逐一問過所有已啟用的型別，第一個說是的接手。
    // 不看 file.type 就好，因為有些系統給 HEIC／MOV 的 MIME 是空字串，副檔名才是唯一線索。
    accepts(file) { return false; }
    // 分頁文案的替代 key，只在「這個分頁只剩我一種型別」時才用得到（見 contribution.js 的 tabMeta()）。
    // 回 null＝用分頁自己的通用文案。這張地圖只開放照片的話，媒體分頁不該寫成「選擇照片或影片」。
    tabLabel() { return null; }
    pickLabel() { return null; }
    pickHint() { return null; }

    // ---- 卡片組成（殼依這幾個旗標決定要不要渲染對應區塊）----
    needsFile() { return true; }
    needsSpot() { return true; }          // 關聯地點選單
    needsLocation() { return true; }      // 迷你地圖 + 定位來源
    hasPreview() { return this.needsFile(); }
    placeholderHtml() { return esc(t('processing')); }
    // 插在留言框之前的自訂欄位（建立地點的標題／分類用）
    extraTopHtml() { return ''; }
    // 插在留言框之後的提示（Markdown 語法說明等）
    extraBottomHtml() { return ''; }
    wireExtra(state, card) {}

    // ---- 檔案處理 ----
    // 讀完檔案該填的東西全放進 state：blob（要上傳的主檔）、thumb、duration、time、exif、loc…
    // 丟例外＝這個檔案處理失敗，殼會在預覽區顯示錯誤但仍讓使用者送出（有些型別沒縮圖也能投）。
    async prepare(file, state) { state.blob = file; }
    renderPreview(state, el) { el.textContent = ''; }
    // 型別自己知道座標的話回傳 {lat, lon}（照片的 EXIF GPS）；回 null 就由殼依裝置定位／來源地點補
    initialLoc(state) { return null; }
    timeOf(state) { return (state.file && state.file.lastModified) || Date.now(); }

    // ---- 送出 ----
    // 回傳錯誤字串代表擋下不送；null＝可以送
    validate(state, card) {
      if (this.needsFile() && !state.blob) return t('need_file_for_kind');
      return null;
    }
    // common＝殼算好的通用欄位（item_num／name／comment／lat／lon／loc_source／license…）。
    // 覆寫時要嘛展開它、要嘛完全不要它（建立地點走的是另一支端點，欄位不一樣）。
    fields(state, card, common) { return common; }
    async submit(mapApp, fields, opts) { return mapApp.submitContribution(fields, opts); }

    cleanup(state) {
      (state.urls || []).forEach(u => { try { URL.revokeObjectURL(u); } catch (e) {} });
      state.urls = [];
    }

    // 子類用：建 object URL 並登記，讓 cleanup() 一次回收
    objUrl(state, blob) {
      const u = URL.createObjectURL(blob);
      (state.urls = state.urls || []).push(u);
      return u;
    }
  }

  // 型別註冊表。view.php 只輸出這張地圖開放的型別檔；contribution.js 開機時還會再依
  // APP.contrib.kinds 過濾一次，借用型別檔的其他功能（例如點位聲音編輯載入 kind-audio.js）不會多出投稿分頁。
  const kinds = [];
  window.SLContrib = {
    Kind: ContribKind,
    t, esc,
    kinds,
    register: (inst) => { kinds.push(inst); return inst; },
    byKey: (key) => kinds.find(k => k.key === key) || null,
    byTab: (tab) => kinds.filter(k => k.tab === tab),
    // 這個檔案該由誰接手；沒人認就回 null（殼會提示不支援的檔案型別）。
    // 目前分頁的型別優先，因為單看檔案分不出來的情況真的存在：只有音軌的 .webm／.mp4，
    // 瀏覽器一律給 video/* 的 MIME（副檔名決定的），在音訊分頁選它顯然是要投音訊。
    // 同一個檔案丟到媒體分頁就還是影片——以使用者當下的意圖為準，而不是猜檔案內容。
    match: (file, tab) => {
      const fits = (k) => k.needsFile() && k.accepts(file);
      return (tab && kinds.find(k => k.tab === tab && fits(k))) || kinds.find(fits) || null;
    },
  };
})();
