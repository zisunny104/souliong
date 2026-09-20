/* 選用插件：點位內容編輯（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.contentEdit 為 true、且目前身分具備 edit_spots 權限時，view.php 才會載入這個檔案；
   這只是顯示層級的判斷，真正擋寫入的是 api/spotcontent.php 的 Auth::require(edit_spots)。
   點位的說明是 spots.jsonl 裡的 content 區塊陣列，不是投稿；它是「一個整體」：可以組合多段文字、多個聲音，
   但編輯的是整份內容，一次儲存產生一個版本、一個編輯者，沒有「各區塊各自的作者」。
   按「編輯內容」進入編輯模式後，所有改動（改文字、增刪、上下移動、錄好或選好的聲音）都只留在前端草稿，
   按「儲存」才一次呼叫 MapApp.saveSpotContent()；別人在這期間改過內容時伺服器回 409，草稿原樣保留。
   區塊怎麼「顯示」由核心的 registerSpotContent() 算繪器負責，這裡只管「怎麼編輯」：
   每種區塊型別一個 BlockType（繼承 SLContentEditor.BlockType 後 registerBlockType()）。
   聲音借用 kind-audio.js 的 window.SLAudioTools（錄音／選檔／量時長），該檔沒載入時就不出現錄音與上傳鈕；
   它不依賴 upload 模組，也不需要 contrib.kinds 含 audio。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const pauseAll = (root) => root.querySelectorAll('audio').forEach(a => { try { a.pause(); } catch (e) {} });
  let uid = 0;

  /* ---------- 區塊型別 ---------- */

  // 草稿區塊：{ key, kind, id?, comment, item?, ... }。既有區塊帶 id 與原始項目 item，新區塊沒有；型別自己的欄位另外加。
  // ctx = { mapApp, add(block) }：add 把新區塊接到草稿最後並重畫清單。
  class BlockType {
    get kind() { return ''; }
    get icon() { return 'fa-square'; }
    get labelKey() { return ''; }    // 區塊標籤
    get trackKey() { return ''; }    // 儲存後回報的功能使用統計
    available() { return true; }     // 回 false 就不畫這個型別的新增鈕

    fromItem(item) { return { key: ++uid, kind: this.kind, id: item.id, comment: item.comment || '', item }; }
    isEmpty(block) { return false; }
    changed(block) { return !block.id || block.comment.trim() !== String(block.item.comment || '').trim(); }
    buildBody(block, ctx) { return null; }
    // 回傳送給伺服器的區塊；需要上傳的檔案放進 files（media_N → [blob, 檔名]）
    serialize(block, files) { return { id: block.id, kind: this.kind, comment: block.comment.trim() }; }
    addControls(bar, ctx) {}
    dispose(block) {}
  }

  // 文字區塊：textarea 直接編輯 Markdown 原文，不做前端預覽，儲存後畫面用伺服器算好的 html。
  class TextBlockType extends BlockType {
    get kind() { return 'text'; }
    get icon() { return 'fa-align-left'; }
    get labelKey() { return 'content_kind_text'; }
    get trackKey() { return 'story'; }

    isEmpty(block) { return !block.comment.trim(); }

    buildBody(block) {
      const el = document.createElement('div');
      el.innerHTML = '<textarea class="sc-ta" placeholder="' + esc(t('story_textarea_placeholder')) + '">' + esc(block.comment) + '</textarea>' +
        '<div class="sc-hint">' + esc(t('content_md_hint')) + '</div>';
      el.querySelector('textarea').oninput = (ev) => { block.comment = ev.target.value; };
      return el;
    }

    addControls(bar, ctx) {
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'btn small';
      btn.innerHTML = '<i class="fa-solid fa-align-left"></i> ' + esc(t('content_add_text'));
      btn.onclick = () => ctx.add({ key: ++uid, kind: 'text', comment: '', focus: true });
      bar.appendChild(btn);
    }
  }

  // 聲音區塊：既有的只能改說明文字；新的先把檔案留在前端草稿（錄音與選檔兩條路收斂到同一個 onFile()），儲存時一併上傳。
  class AudioBlockType extends BlockType {
    get kind() { return 'audio'; }
    get icon() { return 'fa-microphone'; }
    get labelKey() { return 'content_kind_audio'; }
    get trackKey() { return 'sound'; }
    available() { return !!window.SLAudioTools; }

    buildBody(block, ctx) {
      const isNew = !block.id;
      const url = isNew ? block.playUrl : ctx.mapApp.mediaFullUrl(block.item);
      const dur = isNew ? block.duration : block.item.duration;
      const el = document.createElement('div');
      el.innerHTML = ctx.mapApp.audioPlayerHtml(url, dur) +
        (isNew
          ? '<input type="url" class="sc-src" placeholder="' + esc(t('sound_source_placeholder')) + '" value="' + esc(block.source_url) + '">' +
            '<div class="sc-lic"' + (block.source_url ? '' : ' style="display:none"') + '>' +
            '<p class="sc-hint">' + esc(t('sound_source_license_hint')) + '</p>' +
            '<label><input type="radio" name="scLic' + block.key + '" value="cc0"' + (block.source_license === 'cc0' ? ' checked' : '') + '> CC0</label> ' +
            '<label><input type="radio" name="scLic' + block.key + '" value="cc-by"' + (block.source_license === 'cc-by' ? ' checked' : '') + '> CC BY</label></div>'
          : '') +
        '<textarea class="sc-ta sc-cap" placeholder="' + esc(t('sound_caption_placeholder')) + '">' + esc(block.comment) + '</textarea>';
      ctx.mapApp.wireAudioPlayer(el);
      el.querySelector('.sc-cap').oninput = (ev) => { block.comment = ev.target.value; };
      if (isNew) {
        const lic = el.querySelector('.sc-lic');
        el.querySelector('.sc-src').oninput = (ev) => { block.source_url = ev.target.value; lic.style.display = ev.target.value.trim() ? '' : 'none'; };
        el.querySelectorAll('.sc-lic input').forEach(r => { r.onchange = () => { block.source_license = r.value; }; });
      }
      return el;
    }

    serialize(block, files) {
      if (block.id) return super.serialize(block, files);
      const file = 'media_' + Object.keys(files).length;
      files[file] = [block.blob, block.fileName];
      const out = { kind: 'audio', file, comment: block.comment.trim() };
      if (block.duration) out.duration = block.duration;
      let source = block.source_url.trim();
      if (source) {
        if (!/^https?:\/\//i.test(source)) source = 'https://' + source;
        out.source_url = source;
        out.source_license = block.source_license;
      }
      return out;
    }

    dispose(block) { (block.urls || []).forEach(u => { try { URL.revokeObjectURL(u); } catch (e) {} }); }

    addControls(bar, ctx) {
      const tools = window.SLAudioTools;
      bar.appendChild(tools.buildRecorder(file => this.onFile(file, ctx)));
      const pick = document.createElement('button');
      pick.type = 'button'; pick.className = 'btn small';
      pick.innerHTML = '<i class="fa-solid fa-upload"></i> ' + esc(t('pick_audio_btn'));
      const input = document.createElement('input');
      input.type = 'file'; input.hidden = true; input.accept = tools.acceptAttr();
      input.onchange = () => { if (input.files[0]) this.onFile(input.files[0], ctx); input.value = ''; };
      pick.onclick = () => input.click();
      bar.appendChild(pick);
      bar.appendChild(input);
    }

    async onFile(file, ctx) {
      const st = { urls: [] };
      await window.SLAudioTools.prepare(file, st);
      if (st.mediaError) { this.dispose(st); alert(t('media_read_failed')); return; }
      ctx.add({
        key: ++uid, kind: 'audio', comment: '', blob: file, fileName: file.name || 'audio',
        duration: st.duration || null, playUrl: st.playUrl, urls: st.urls, source_url: '', source_license: 'cc0',
      });
    }
  }

  // 這個編輯器不認得的區塊（之後才有的型別）：原樣保留，只能移動或刪除，送出時只帶 id 與 kind
  class OpaqueBlockType extends BlockType {
    get icon() { return 'fa-cube'; }
    changed(block) { return false; }
    serialize(block) { return { id: block.id, kind: block.kind }; }
  }

  const types = [];
  const registerBlockType = (type) => { types.push(type); };
  registerBlockType(new TextBlockType());
  registerBlockType(new AudioBlockType());
  const opaque = new OpaqueBlockType();
  window.SLContentEditor = { BlockType, registerBlockType };

  /* ---------- 插件本體 ---------- */

  class ContentEditorPlugin extends MapApp.Plugin {
    constructor() { super('contentEdit'); this.drafts = new Map(); }

    mount() {
      this.mapApp.registerEntriesHint(spot => { this.decorate(spot); return null; });
    }

    typeOf(block) { return block.opaque ? opaque : (types.find(k => k.kind === block.kind) || opaque); }

    // #storyActions 是核心 renderEntries() 每次重建 #entries 時一定會重畫的節點；草稿留在這裡（依點位），
    // 重繪後如果這個點位還有進行中的草稿，就把編輯介面接回去，改動不會因為畫面重繪而消失。
    decorate(spot) {
      const mapApp = this.mapApp;
      if (!mapApp.can('edit_spots') || mapApp.isEmbedMode()) return;
      const actions = document.getElementById('storyActions');
      if (!actions) return;
      const btn = document.createElement('button');
      btn.className = 'btn small sc-edit-btn'; btn.type = 'button';
      btn.innerHTML = '<i class="fa-solid fa-pen"></i> ' + esc(t('content_edit_btn'));
      btn.onclick = () => this.open(spot);
      actions.insertBefore(btn, actions.firstChild);
      const draft = this.drafts.get(spot.num);
      if (draft && !draft.done) this.showEditor(draft);
    }

    open(spot) {
      if (!this.drafts.has(spot.num)) {
        const blocks = (spot.content || []).map(item => {
          const type = types.find(k => k.kind === item.kind && (k.kind !== 'audio' || item.media));
          return type ? type.fromItem(item) : { key: ++uid, kind: item.kind, id: item.id, opaque: true, item };
        });
        this.drafts.set(spot.num, { num: spot.num, baseRev: spot.contentRev, blocks, origOrder: blocks.map(b => b.id).join(','), done: false });
      }
      this.showEditor(this.drafts.get(spot.num));
    }

    isDirty(draft) {
      return draft.blocks.map(b => b.id || '').join(',') !== draft.origOrder ||
        draft.blocks.some(b => this.typeOf(b).changed(b));
    }

    discard(draft) {
      draft.blocks.forEach(b => this.typeOf(b).dispose(b));
      this.drafts.delete(draft.num);
    }

    showEditor(draft) {
      const story = document.querySelector('#entries .story');
      const list = story && story.querySelector('.sc-list');
      if (!list) return;
      const mapApp = this.mapApp;
      story.classList.add('sc-editing');
      const host = document.createElement('div');
      host.className = 'sc-editor';
      host.innerHTML = '<div class="sc-items"></div><div class="sc-addbar"></div>' +
        '<div class="sc-row sc-foot"><button class="btn primary small sc-save" type="button">' + esc(t('save')) + '</button>' +
        '<button class="btn small sc-cancel" type="button">' + esc(t('cancel')) + '</button></div>';
      list.insertAdjacentElement('afterend', host);
      const items = host.querySelector('.sc-items');

      const ctx = {
        mapApp,
        add: (block) => { draft.blocks.push(block); render(); if (block.focus) { block.focus = false; const ta = items.querySelector('.sc-item:last-child textarea'); if (ta) ta.focus(); } },
      };
      const move = (i, dir) => { const b = draft.blocks; [b[i], b[i + dir]] = [b[i + dir], b[i]]; render(); };
      const remove = (i) => { const [b] = draft.blocks.splice(i, 1); this.typeOf(b).dispose(b); render(); };

      const render = () => {
        pauseAll(items);
        items.innerHTML = '';
        if (!draft.blocks.length) items.innerHTML = '<div class="story-body"><span class="empty">' + esc(t('story_empty')) + '</span></div>';
        draft.blocks.forEach((block, i) => {
          const type = this.typeOf(block);
          const row = document.createElement('div');
          row.className = 'sc-item';
          const label = block.opaque ? t('content_block_unknown', { kind: block.kind }) : t(type.labelKey);
          row.innerHTML = '<div class="sc-item-head"><span class="sc-item-tag"><i class="fa-solid ' + type.icon + '"></i> ' + esc(label) + '</span><span class="sc-tools"></span></div>';
          const tools = row.querySelector('.sc-tools');
          const mk = (icon, title, fn, disabled) => {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'btn small sc-tool'; b.title = title; b.setAttribute('aria-label', title);
            b.innerHTML = '<i class="fa-solid ' + icon + '"></i>';
            if (disabled) b.disabled = true; else b.onclick = fn;
            tools.appendChild(b);
          };
          mk('fa-arrow-up', t('content_move_up'), () => move(i, -1), i === 0);
          mk('fa-arrow-down', t('content_move_down'), () => move(i, 1), i === draft.blocks.length - 1);
          mk('fa-trash', t('delete'), () => remove(i));
          const body = type.buildBody(block, ctx);
          if (body) row.appendChild(body);
          items.appendChild(row);
        });
      };
      render();

      const bar = host.querySelector('.sc-addbar');
      types.filter(k => k.available()).forEach(k => k.addControls(bar, ctx));

      const saveBtn = host.querySelector('.sc-save'), cancelBtn = host.querySelector('.sc-cancel');
      cancelBtn.onclick = () => {
        if (this.isDirty(draft) && !confirm(t('content_discard_confirm'))) return;
        this.discard(draft);
        mapApp.refreshEntries();
      };
      saveBtn.onclick = async () => {
        const blocks = [], files = {};
        for (const b of draft.blocks) {
          const type = this.typeOf(b);
          if (type.isEmpty(b)) { alert(t('enter_story_content')); return; }
          blocks.push(type.serialize(b, files));
        }
        if (!this.isDirty(draft)) { this.discard(draft); mapApp.refreshEntries(); return; }
        saveBtn.disabled = cancelBtn.disabled = true; saveBtn.textContent = t('submitting');
        // 成功時核心會先重繪說明區才回來，先標記完成，重繪就不會又把編輯介面接回去
        draft.done = true;
        try {
          await mapApp.saveSpotContent(draft.num, { blocks, files, baseRev: draft.baseRev });
        } catch (err) {
          draft.done = false;
          alert(err.status === 409 ? t('content_conflict') : t('save_failed', { err: err.message || err }));
          saveBtn.disabled = cancelBtn.disabled = false; saveBtn.textContent = t('save');
          if (!host.isConnected) mapApp.refreshEntries();
          return;
        }
        const used = new Set();
        draft.blocks.forEach(b => { const type = this.typeOf(b); if (type.trackKey && (!b.id || type.changed(b))) used.add(type.trackKey); });
        used.forEach(k => mapApp.trackFeature(k));
        this.discard(draft);
      };
    }
  }

  new ContentEditorPlugin().init(window.MapApp);
})();
