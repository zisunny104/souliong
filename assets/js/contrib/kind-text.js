/* 投稿型別：文字紀錄
   沒有檔案，也沒有自己的座標——一則文字是「掛在某個地點上的一筆紀錄」，不是地圖上的一個座標點，
   所以 needsLocation() 回 false，viewer.core.js 的 KINDS 表也把它標成 layer:false（不進圖層）。

   要跟 kind:'desc' 分清楚：desc 是「改寫這個地點的故事」（取最新一筆覆蓋顯示在故事區），
   由 story-editor.js 送出，不出現在這個對話框；text 是「我留下的一則紀錄」，
   跟照片平行地排在投稿牆上，兩者都是投稿但語意完全不同。 */
(() => {
  const { Kind, t, register } = window.SLContrib;

  class TextKind extends Kind {
    get key() { return 'text'; }
    get tab() { return 'text'; }
    get icon() { return 'fa-align-left'; }

    needsFile() { return false; }
    needsLocation() { return false; }
    // 關聯地點留著：文字紀錄一定是留給某個地點的（upload.php 允許不指定，但這裡預設會選最近的）
    needsSpot() { return true; }

    validate(state, card) {
      return card.querySelector('.c-cmt').value.trim() ? null : t('need_text_content');
    }
  }

  register(new TextKind());
})();
