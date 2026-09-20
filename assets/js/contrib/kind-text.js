/* 投稿型別：文字紀錄
   沒有檔案，也沒有自己的座標——一則文字是「掛在某個地點上的一筆紀錄」，不是地圖上的一個座標點，
   所以 needsLocation() 回 false，viewer.core.js 的 KINDS 表也把它標成 layer:false（不進圖層）。

   要跟點位內容裡的 text 區塊分清楚：那是地點自己的說明（寫在 spots.jsonl，由 content-editor.js
   編輯，不出現在這個對話框）；這裡的 text 是「我留下的一則紀錄」，跟照片平行地排在投稿牆上。 */
(() => {
  const { Kind, t, esc, register } = window.SLContrib;

  class TextKind extends Kind {
    get key() { return 'text'; }
    get tab() { return 'text'; }
    get icon() { return 'fa-align-left'; }

    needsFile() { return false; }
    needsLocation() { return false; }
    // 關聯地點留著：文字紀錄一定是留給某個地點的（upload.php 允許不指定，但這裡預設會選最近的）
    needsSpot() { return true; }

    // 文字投稿支援 Markdown，伺服器輸出時算出 html（見 viewer.core.js 投稿牆的 .txt）
    extraBottomHtml() { return '<div class="sc-hint">' + esc(t('content_md_hint')) + '</div>'; }

    validate(state, card) {
      return card.querySelector('.c-cmt').value.trim() ? null : t('need_text_content');
    }
  }

  register(new TextKind());
})();
