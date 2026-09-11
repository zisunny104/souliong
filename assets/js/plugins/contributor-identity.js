/* 選用插件：投稿者身分（見 souliong/docs/EXTENDING.md 第七節）
   由 api/features.php 的 identity 旗標決定要不要載入（關閉時整個檔案不會載入，#identity 自然不存在；
   #idToggleBtn／#idFields 仍是 view.php 在 #unlockDialog 裡輸出的既有 DOM，見下方 idBtn 段落；
   personExplore 透過 dependsOn 一併隨之關閉，見 souliong_module_on()）。
   #identity 小標籤改由這裡自己建立、插入 #trItems 的最前面，view.php 不再輸出這段 HTML。
   管右上角的身分指示鈕（#identity：顯示暱稱/管理者/匿名預覽名、點按觸發上傳捷徑或解鎖、長按換一個匿名名），
   以及解鎖對話框裡「建立身分」的展開/收合（#idToggleBtn/#idFields，PIN／暱稱欄位的讀取與重置仍留在核心，
   因為它們跟純代碼解鎖共用同一個對話框與送出按鈕，拆不乾淨）。
   contribToken/contribInfo 等「有無設定過 PIN」的實際存取與運算留在核心：submitContribution／deleteEntry／
   photo 編輯等核心自身的送出流程都要用到，且沒設 PIN 時它們本來就是無害的空字串，行為與這個模組開／關無關。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class ContributorIdentityPlugin extends MapApp.Plugin {
    constructor() { super('identity'); }

    mount() {
      const trItems = document.getElementById('trItems');
      const idEl = document.createElement('span');
      idEl.id = 'identity';
      idEl.className = 'idchip';
      idEl.title = t('contributor_identity');
      idEl.setAttribute('role', 'button');
      idEl.tabIndex = 0;

      let host = idEl;
      // 只有真正的管理者才看得到「檢視模式」切換鈕：包一層 .sl-idmenu 容器，把身分晶片跟
      // 新的下拉鈕放在一起，晶片本身的點擊/長按邏輯不受影響。
      if (this.mapApp.canTogglePreview()) host = this.buildPreviewMenu(idEl);

      // 排在這組按鈕的最前面。原本是「插在首頁鈕之前」，但首頁鈕是可關閉的模組（homeLink），
      // 關掉時 insertBefore(…, null) 會變成 append，身分標籤就跑到語言選單後面去了。
      if (trItems) trItems.insertBefore(host, trItems.firstChild);

      let idLpTimer = null, idLpFired = false;
      idEl.addEventListener('pointerdown', () => { idLpFired = false; idLpTimer = setTimeout(() => { idLpFired = true; this.mapApp.rerollAnon(); }, 600); });
      const idLpCancel = () => { if (idLpTimer) { clearTimeout(idLpTimer); idLpTimer = null; } };
      idEl.addEventListener('pointerup', idLpCancel);
      idEl.addEventListener('pointerleave', idLpCancel);
      idEl.addEventListener('pointercancel', idLpCancel);
      idEl.onclick = () => { if (idLpFired) { idLpFired = false; return; } this.mapApp.identityChipClick(); };
      idEl.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.mapApp.identityChipClick(); } };

      const idBtn = document.getElementById('idToggleBtn'), idFields = document.getElementById('idFields');
      if (idBtn && idFields) idBtn.onclick = () => {
        const open = idFields.style.display === 'none';
        idFields.style.display = open ? '' : 'none';
        idBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      };

      this.mapApp.onHook('identityChanged', () => this.render());
      this.render();
    }

    // 比照語言選單（.lang-menu／.lang-btn／.lang-list）同一套定位／開合模式：
    // position:relative 容器＋absolute 選單＋.open class，點外部關閉
    buildPreviewMenu(idEl) {
      const wrap = document.createElement('span');
      wrap.className = 'sl-idmenu';
      wrap.id = 'idMenu';
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'sl-idmenu-btn'; btn.id = 'idMenuBtn';
      btn.title = t('preview_mode_switch');
      btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = '<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
      const list = document.createElement('ul');
      list.className = 'sl-idmenu-list'; list.id = 'idMenuList';
      list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', t('preview_mode_switch'));
      list.innerHTML =
        '<li role="option" data-preview="0">' + esc(t('preview_mode_off')) + '</li>' +
        '<li role="option" data-preview="1">' + esc(t('preview_mode_on')) + '</li>';
      wrap.appendChild(idEl); wrap.appendChild(btn); wrap.appendChild(list);

      btn.onclick = () => {
        const open = wrap.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      list.querySelectorAll('li').forEach(li => {
        li.onclick = () => {
          this.mapApp.setPreviewMode(li.dataset.preview === '1');   // 會觸發 identityChanged hook，render() 自動跟著更新
          wrap.classList.remove('open');
          btn.setAttribute('aria-expanded', 'false');
        };
      });
      document.addEventListener('click', e => {
        if (wrap.classList.contains('open') && !wrap.contains(e.target)) {
          wrap.classList.remove('open');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
      return wrap;
    }

    // 顯示目前暱稱（未輸入則管理者帶入「管理者」、否則顯示本次匿名預覽名）；解鎖狀態附鎖圖示；
    // 管理者切成檢視模式時改用眼睛圖示，提醒自己還在「假裝訪客」而不是真的登出
    render() {
      const el = document.getElementById('identity');
      if (!el) return;
      if (this.mapApp.isEmbedMode()) { el.style.display = 'none'; return; }
      const previewing = this.mapApp.isPreviewMode && this.mapApp.isPreviewMode();
      let name = '';
      try { name = (localStorage.getItem('myName') || '').trim(); } catch (e) {}
      const shown = name || (window.APP.isManager ? t('identity_manager') : this.mapApp.anonName());
      const unlocked = this.mapApp.isUnlocked();
      const icon = previewing ? 'fa-eye' : (unlocked ? 'fa-user-check' : 'fa-user');
      el.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + esc(shown);
      el.title = t('identity_title_named', { name: shown }) +
        (previewing ? t('identity_title_preview_suffix') : (name ? '' : (window.APP.isManager ? t('identity_title_manager_suffix') : t('identity_title_anon_suffix'))));

      const list = document.getElementById('idMenuList');
      if (list) list.querySelectorAll('li').forEach(li => {
        li.setAttribute('aria-selected', (li.dataset.preview === '1') === previewing ? 'true' : 'false');
      });
    }
  }

  new ContributorIdentityPlugin().init(window.MapApp);
})();
