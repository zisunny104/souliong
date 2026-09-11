/* 選用插件：單則投稿的深層連結（見 souliong/docs/EXTENDING.md 第七節）
   跟 share-link.js 用同一個 features.share 開關載入：分享整張地圖既然開放，分享某一則
   投稿（例如省府聲景裡的一段聲音）也合理。

   - 消費端：網址帶 ?entry=<id> 時，開機完成、投稿資料載好後自動開啟該則所屬地點的面板，
     並捲到那張卡片、短暫高亮——不呼叫 openLightbox()，音訊卡片本來就是 <audio controls
     preload="none">（見 viewer.core.js entryPreviewHtml()），永遠要訪客自己按下播放，
     深層連結不該讓它自動出聲。
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
    constructor() { super('entryLink'); this.handled = false; }

    mount() {
      this.registerCopyButton();
      this.tryOpenFromUrl();
      this.mapApp.onHook('stateChange', () => this.tryOpenFromUrl());
    }

    entryUrl(entryId) {
      return location.origin + window.APP.base + this.mapApp.getProjectId() + '?entry=' + encodeURIComponent(entryId);
    }

    registerCopyButton() {
      const App = this.mapApp;
      App.registerEntryAction(e => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn small';
        btn.innerHTML = '<i class="fa-solid fa-link"></i> ' + esc(t('copy_entry_link'));
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

    // 投稿資料是非同步載入的，第一次呼叫時多半還是空的；stateChange 觸發時再試一次，
    // 直到 CONTRIB 載完（不論有沒有找到那則 id）才不再重試。
    tryOpenFromUrl() {
      if (this.handled) return;
      const id = new URLSearchParams(location.search).get('entry');
      if (!id) { this.handled = true; return; }
      const App = this.mapApp;
      const entries = App.effectiveEntries();
      if (!entries.length) return;
      this.handled = true;
      const entry = entries.find(e => e.id === id);
      if (!entry || entry.item_num == null) return;
      const point = App.effectivePoints().find(p => p.num === entry.item_num);
      if (!point) return;
      App.openPanel(point);
      const engine = App.getEngine();
      if (engine) engine.panTo(point.lat, point.lon, { animate: true });
      requestAnimationFrame(() => {
        const card = document.querySelector('.entry[data-entry-id="' + CSS.escape(id) + '"]');
        if (!card) return;
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.classList.add('entry-highlight');
        setTimeout(() => card.classList.remove('entry-highlight'), 2400);
      });
    }
  }

  new EntryLinkPlugin().init(window.MapApp);
})();
