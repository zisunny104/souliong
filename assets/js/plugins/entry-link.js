/* 選用插件：單則投稿的深層連結（見 souliong/docs/EXTENDING.md 第七節）
   跟 share-link.js 用同一個 features.share 開關載入：分享整張地圖既然開放，分享某一則
   投稿（例如省府聲景裡的一段聲音）也合理。

   - 消費端：網址帶 ?entry=<id> 的處理已移到核心（viewer.core.js 的 openEntryCard()），不依賴這個插件，
     也不開滿版檢視、不自動出聲；這個插件只負責產生端。
   - 產生端：在每張投稿卡的操作列多掛一顆「複製此則連結」，複製的網址只帶 entry 參數，
     不合併目前的篩選範圍（分享單一則內容時，篩選狀態沒有意義）。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class EntryLinkPlugin extends MapApp.Plugin {
    constructor() { super('entryLink'); }

    mount() {
      this.registerCopyButton();
    }

    entryUrl(entryId) {
      return location.origin + window.APP.base + this.mapApp.getProjectId() + '?entry=' + encodeURIComponent(entryId);
    }

    registerCopyButton() {
      const App = this.mapApp;
      App.registerEntryAction(e => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn small entry-icon-action';
        btn.dataset.entryLink = '1';
        btn.title = t('copy_entry_link');
        btn.setAttribute('aria-label', t('copy_entry_link'));
        btn.innerHTML = '<i class="fa-solid fa-link" aria-hidden="true"></i>';
        btn.onclick = async (ev) => {
          ev.stopPropagation();
          try {
            await navigator.clipboard.writeText(this.entryUrl(e.id));
            App.trackFeature('share');
            App.toast('<i class="fa-solid fa-check"></i> ' + esc(t('copied')));
          } catch (err) {}
        };
        return btn;
      });
    }
  }

  new EntryLinkPlugin().init(window.MapApp);
})();
