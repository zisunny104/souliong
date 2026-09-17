/* 選用插件：故事編輯（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.story 為 true 時，view.php 才會載入這個檔案。
   「編輯說明」鈕只看 isUnlocked()（是否已解鎖投稿權限），不像舊版還多要求 upload 模組也要開——
   這是本次拆分順便修掉的既有 bug：以前故事編輯鈕誤用了 upload 專用的 canPost()。
   故事的顯示（標題／內文／署名）與「歷史版本」鈕仍留在核心，這裡只管「新增一則版本」這件事。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class StoryEditorPlugin extends MapApp.Plugin {
    constructor() { super('story'); }

    mount() {
      this.mapApp.registerEntriesHint(spot => { this.injectEditButton(spot); return null; });
    }

    // #storyActions 是核心 renderEntries() 每次重建 #entries 時一定會重畫的容器，藉 registerEntriesHint 的時機掛上編輯鈕
    injectEditButton(spot) {
      const actions = document.getElementById('storyActions');
      if (!actions) return;
      if (!this.mapApp.isUnlocked() || this.mapApp.isEmbedMode()) return;
      const btn = document.createElement('button');
      btn.className = 'btn small'; btn.id = 'editDescBtn';
      btn.innerHTML = '<i class="fa-solid fa-pen"></i> ' + esc(t('edit_story_btn'));
      btn.onclick = () => this.toggleEditor(spot.num);
      actions.insertBefore(btn, actions.firstChild);
    }

    toggleEditor(num) {
      const existing = document.getElementById('descEditor');
      if (existing) { existing.remove(); return; }
      const actions = document.getElementById('storyActions');
      if (!actions) return;
      const el = document.createElement('div');
      el.id = 'descEditor';
      el.innerHTML =
        '<input type="text" id="descName" class="name-in" placeholder="' + esc(this.mapApp.anonName()) + '" style="width:100%;margin-top:8px">' +
        '<textarea id="descText" placeholder="' + esc(t('story_textarea_placeholder')) + '"></textarea>' +
        '<button class="btn primary small" id="descSave">' + esc(t('submit_new_version')) + '</button>';
      actions.insertAdjacentElement('afterend', el);
      const myName = document.getElementById('myName');
      document.getElementById('descName').value = (myName && myName.value) || '';
      document.getElementById('descSave').onclick = () => this.submit(num);
      document.getElementById('descText').focus();
    }

    async submit(num) {
      const text = (document.getElementById('descText').value || '').trim();
      if (!text) { alert(t('enter_story_content')); return; }
      const nick = (document.getElementById('descName').value || '').trim();
      if (nick) {
        const myName = document.getElementById('myName'); if (myName) myName.value = nick;
        try { localStorage.setItem('myName', nick); } catch (e) {}
        const modalName = document.getElementById('modalName'); if (modalName) modalName.value = nick;
      }
      const btn = document.getElementById('descSave'); btn.disabled = true; btn.textContent = t('submitting');
      try {
        await this.mapApp.submitContribution({
          kind: 'desc', item_num: num, name: nick || this.mapApp.displayName(),
          comment: text, photo_time: new Date().toISOString(),
        });
        this.mapApp.trackFeature('story');
        this.mapApp.refreshPersonFilter();
        this.mapApp.refreshEntries();
      } catch (err) {
        alert(t('save_failed', { err: err.message || err }));
        btn.disabled = false; btn.textContent = t('submit_new_version');
      }
    }
  }

  new StoryEditorPlugin().init(window.MapApp);
})();
