/* 選用插件：嵌入代碼（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.embed 為 true 時，view.php 才會載入這個檔案。
   讓訪客產生一段 <iframe> 嵌入代碼，可調整寬高與單位，複製貼到自己的網站。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class EmbedCodePlugin extends MapApp.Plugin {
    constructor() { super('embed'); }

    mount() {
      this.injectStyle();
      this.injectDialog();
      this.injectButton();
      this.mapApp.onHook('closeAll', () => this.close());
    }

    injectStyle() {
      const style = document.createElement('style');
      style.textContent = `
        .embed-size-row { display: flex; align-items: center; gap: 8px; margin-top: 8px }
        .embed-size-row .embed-size-label { font-size: 0.75rem; color: var(--muted); width: 32px; flex-shrink: 0 }
        .embed-size-row .name-in { width: 80px }
        .embed-size-row select {
          appearance: none;
          -webkit-appearance: none;
          padding-right: 26px;
          background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%238a857c' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
          background-repeat: no-repeat;
          background-position: right 9px center;
          background-size: 10px;
          cursor: pointer
        }
        .embed-size-row select.embed-size-unit { width: auto }
        .embed-size-row select.embed-size-preset { margin-left: auto; width: auto; max-width: 150px }
      `;
      document.head.appendChild(style);
    }

    injectDialog() {
      const dlg = document.createElement('div');
      dlg.id = 'embedDialog';
      dlg.className = 'dialog';
      dlg.innerHTML = `
        <div class="dialog-box">
          <div class="dialog-head"><b>${esc(t('embed_this_map_title'))}</b><button class="icon-btn" id="embedCloseBtn" aria-label="${esc(t('close'))}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
          <div class="hint">${esc(t('embed_hint'))}</div>
          <div class="embed-size-row">
            <span class="embed-size-label">${esc(t('embed_size_label'))}</span>
            <input type="number" id="embedWidth" class="name-in" min="200" step="10" value="800">
            <span class="hint">×</span>
            <input type="number" id="embedHeight" class="name-in" min="200" step="10" value="600">
            <select id="embedSizeUnit" class="name-in embed-size-unit" aria-label="${esc(t('embed_size_unit'))}">
              <option value="px">px</option>
              <option value="pct">%</option>
            </select>
            <select id="embedSizePreset" class="name-in embed-size-preset">
              <option value="custom">${esc(t('embed_size_custom'))}</option>
              <option value="fill_w">${esc(t('embed_fill_container_w'))}</option>
              <option value="fill_h">${esc(t('embed_fill_container_h'))}</option>
              <option value="fill_wh">${esc(t('embed_fill_container_wh'))}</option>
            </select>
          </div>
          <textarea id="embedCode" readonly rows="4"></textarea>
          <div class="dialog-actions"><button class="btn primary" id="copyEmbedBtn">${esc(t('copy'))}</button><span id="copyMsg" class="hint"></span></div>
        </div>
      `;
      document.body.appendChild(dlg);

      dlg.querySelector('#embedCloseBtn').onclick = () => this.close();
      dlg.querySelector('#copyEmbedBtn').onclick = async () => {
        const ta = dlg.querySelector('#embedCode');
        try { await navigator.clipboard.writeText(ta.value); } catch (e) { ta.select(); document.execCommand('copy'); }
        dlg.querySelector('#copyMsg').innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('copied'));
        setTimeout(() => { dlg.querySelector('#copyMsg').textContent = ''; }, 2000);
      };
      dlg.querySelector('#embedWidth').oninput = () => { this.syncPresetFromFields(); this.buildCode(); };
      dlg.querySelector('#embedHeight').oninput = () => { this.syncPresetFromFields(); this.buildCode(); };
      // 選項是欄位狀態的「捷徑」：選了滿版類選項＝直接把單位切成 %、對應欄位設成 100，
      // 讓欄位不會跟選項顯示的內容對不上；選「自訂」則什麼都不動，維持欄位原本的值。
      // 選完仍要反推一次——選單本身沒有獨立記憶，欄位數值才是唯一事實來源：
      // 就算剛選了「自訂」，只要欄位仍剛好等於某個預設值，就該改顯示那個預設，而不是自訂
      dlg.querySelector('#embedSizePreset').onchange = () => { this.applyPreset(); this.syncPresetFromFields(); this.buildCode(); };
      // 切單位時把寬高換成該單位下合理的值域（% 是 10–100、px 維持原本 200 起跳），再重組嵌入代碼
      dlg.querySelector('#embedSizeUnit').onchange = () => {
        const pct = dlg.querySelector('#embedSizeUnit').value === 'pct';
        ['embedWidth', 'embedHeight'].forEach((id, i) => {
          const el = dlg.querySelector('#' + id);
          el.min = pct ? 10 : 200;
          el.step = pct ? 5 : 10;
          if (pct && (!el.value || +el.value > 100)) el.value = 100;
          if (!pct && (!el.value || +el.value < 200)) el.value = i === 0 ? 800 : 600;
        });
        this.syncPresetFromFields();
        this.buildCode();
      };
    }

    // 依目前寬高／單位反推對應的尺寸選項：% 模式下寬高都剛好是 100 → 寬高滿版；只有寬是 100 → 寬滿版；
    // 只有高是 100 → 高滿版；其餘情況 → 自訂。讓選單永遠如實反映欄位目前代表的狀態，不用手動先選「自訂」才能編輯欄位
    syncPresetFromFields() {
      const dlg = document.getElementById('embedDialog');
      const pct = dlg.querySelector('#embedSizeUnit').value === 'pct';
      const w = +dlg.querySelector('#embedWidth').value;
      const h = +dlg.querySelector('#embedHeight').value;
      const wFull = pct && w === 100, hFull = pct && h === 100;
      dlg.querySelector('#embedSizePreset').value = wFull && hFull ? 'fill_wh' : wFull ? 'fill_w' : hFull ? 'fill_h' : 'custom';
    }

    // 手動選了滿版類選項時，把欄位同步過去（單位切成 %，對應寬／高設成 100），選「自訂」則不動欄位
    applyPreset() {
      const dlg = document.getElementById('embedDialog');
      const preset = dlg.querySelector('#embedSizePreset').value;
      if (preset === 'custom') return;
      const unitSel = dlg.querySelector('#embedSizeUnit');
      const wEl = dlg.querySelector('#embedWidth'), hEl = dlg.querySelector('#embedHeight');
      if (unitSel.value !== 'pct') {
        unitSel.value = 'pct';
        [wEl, hEl].forEach(el => { el.min = 10; el.step = 5; if (!el.value || +el.value > 100) el.value = 100; });
      }
      if (preset === 'fill_w' || preset === 'fill_wh') wEl.value = 100;
      if (preset === 'fill_h' || preset === 'fill_wh') hEl.value = 100;
    }

    injectButton() {
      const btn = document.createElement('button');
      btn.id = 'embedBtn';
      btn.className = 'icon-btn hide-in-embed';
      btn.title = t('get_embed_code');
      btn.setAttribute('aria-label', t('embed_this_map'));
      btn.innerHTML = '<i class="fa-solid fa-code" aria-hidden="true"></i>';
      btn.onclick = () => this.open();
      const themeBtn = document.getElementById('themeBtn');
      if (themeBtn) themeBtn.insertAdjacentElement('beforebegin', btn);
    }

    buildCode() {
      const App = this.mapApp;
      const sp = new URLSearchParams(App.currentScopeParams());
      sp.set('embed', '1');
      if (App.isPhotoLayerOn()) sp.set('contrib', '1');
      const url = location.origin + window.APP.base + App.getProjectId() + '?' + sp.toString();
      const preset = document.getElementById('embedSizePreset').value;
      const unit = document.getElementById('embedSizeUnit').value === 'pct' ? '%' : 'px';
      const w = document.getElementById('embedWidth').value || (unit === '%' ? 100 : 800);
      const h = document.getElementById('embedHeight').value || (unit === '%' ? 100 : 600);
      const wFill = preset === 'fill_w' || preset === 'fill_wh';
      const hFill = preset === 'fill_h' || preset === 'fill_wh';
      const sizeStyle = 'width:' + (wFill ? '100%' : w + unit) + ';height:' + (hFill ? '100%' : h + unit);
      const title = (window.APP && window.APP.meta && window.APP.meta.title) || 'Souliong';
      document.getElementById('embedCode').value =
        '<iframe src="' + url + '" title="' + esc(title) + '" ' +
        'style="' + sizeStyle + ';border:0;border-radius:12px" loading="lazy"></iframe>';
    }

    open() {
      this.mapApp.trackFeature('embed');
      this.buildCode();
      document.getElementById('embedDialog').classList.add('open');
    }

    close() {
      const dlg = document.getElementById('embedDialog');
      if (dlg) dlg.classList.remove('open');
    }
  }

  new EmbedCodePlugin().init(window.MapApp);
})();
