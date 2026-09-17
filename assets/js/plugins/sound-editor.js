/* 選用插件：聲音主要內容編輯（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.soundEdit 為 true 時，view.php 才會載入這個檔案（依賴 upload 模組，
   因為要用到 isUnlocked()/openUnlock() 的解鎖流程與 kind-audio.js 的錄音機／選檔邏輯）。
   跟 story-editor.js 是同一種角色：故事區「新增一則版本」的動作，只是送出的是錄音而非故事文字。
   送出時走跟一般投稿相同的 api/upload.php，產生一筆普通 audio 投稿（照樣出現在投稿牆上）；
   要不要把這筆設成點位的精選內容（feature），是另一件事，由具備 edit_spots 權限的人透過
   點位編輯 UI 決定，兩者不綁在同一次送出。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class SoundEditorPlugin extends MapApp.Plugin {
    constructor() { super('soundEdit'); this.state = null; }

    mount() {
      this.mapApp.registerEntriesHint(spot => { this.injectRecordButton(spot); return null; });
    }

    // #storyActions 是核心 renderEntries() 每次重建 #entries 時一定會重畫的容器，藉 registerEntriesHint 的時機掛上錄音鈕
    injectRecordButton(spot) {
      const actions = document.getElementById('storyActions');
      if (!actions) return;
      if (!this.mapApp.isUnlocked() || this.mapApp.isEmbedMode()) return;
      const audioKind = window.SLContrib && window.SLContrib.byKey('audio');
      if (!audioKind) return;
      const btn = document.createElement('button');
      btn.className = 'btn small'; btn.id = 'editSoundBtn';
      btn.innerHTML = '<i class="fa-solid fa-microphone"></i> ' + esc(t('record_sound_btn'));
      btn.onclick = () => this.toggleEditor(spot.num, audioKind);
      actions.insertBefore(btn, actions.firstChild);
    }

    toggleEditor(num, audioKind) {
      const existing = document.getElementById('soundEditor');
      if (existing) { this.cleanup(); existing.remove(); return; }
      const actions = document.getElementById('storyActions');
      if (!actions) return;
      this.state = { blob: null, duration: null, playUrl: null, urls: [] };
      const el = document.createElement('div');
      el.id = 'soundEditor';
      el.innerHTML =
        '<div class="sl-sndrec" id="sndrecSlot"></div>' +
        '<input type="url" id="sndSource" placeholder="' + esc(t('sound_source_placeholder')) + '" style="width:100%;margin-top:8px">' +
        '<div class="sl-sndlic" id="sndLicRow" style="display:none">' +
        '<p class="sl-sndlic-hint">' + esc(t('sound_source_license_hint')) + '</p>' +
        '<label><input type="radio" name="sndLic" value="cc0" checked> CC0</label>' +
        '<label><input type="radio" name="sndLic" value="cc-by"> CC BY</label>' +
        '</div>' +
        '<textarea id="sndCaption" placeholder="' + esc(t('sound_caption_placeholder')) + '"></textarea>' +
        '<button class="btn primary small" id="sndSave" disabled>' + esc(t('submit_new_version')) + '</button>';
      actions.insertAdjacentElement('afterend', el);
      const sourceInput = document.getElementById('sndSource');
      const licRow = document.getElementById('sndLicRow');
      sourceInput.addEventListener('input', () => { licRow.style.display = sourceInput.value.trim() ? '' : 'none'; });
      document.getElementById('sndSave').onclick = () => this.submit(num, audioKind);
      this.renderPicker(audioKind);
    }

    // 錄音鈕（kind-audio.js 的 buildRecorder）＋選檔備援，兩條路都收斂到同一個 onFile()
    renderPicker(audioKind) {
      const slot = document.getElementById('sndrecSlot');
      if (!slot) return;
      slot.innerHTML = '';
      slot.appendChild(audioKind.buildRecorder(file => this.onFile(file, audioKind)));
      const pickBtn = document.createElement('button');
      pickBtn.type = 'button'; pickBtn.className = 'btn';
      pickBtn.innerHTML = '<i class="fa-solid fa-upload"></i> ' + esc(t('pick_audio_btn'));
      const input = document.createElement('input');
      input.type = 'file'; input.hidden = true; input.accept = audioKind.acceptAttr();
      input.onchange = () => { if (input.files[0]) this.onFile(input.files[0], audioKind); input.value = ''; };
      pickBtn.onclick = () => input.click();
      slot.appendChild(pickBtn);
      slot.appendChild(input);
    }

    // 拿到檔案（不管是錄的還是選的）：借 AudioKind.prepare() 算時長、開預覽網址，
    // 換成跟正式故事區同一套自訂播放器試聽（見 audioPlayerHtml／wireAudioPlayer）
    async onFile(file, audioKind) {
      const st = this.state;
      (st.urls || []).forEach(u => { try { URL.revokeObjectURL(u); } catch (e) {} });
      st.urls = [];
      await audioKind.prepare(file, st);
      const slot = document.getElementById('sndrecSlot');
      if (!slot) return;
      slot.innerHTML = this.mapApp.audioPlayerHtml(st.playUrl, st.duration);
      this.mapApp.wireAudioPlayer(slot);
      const save = document.getElementById('sndSave'); if (save) save.disabled = false;
    }

    cleanup() {
      if (this.state) (this.state.urls || []).forEach(u => { try { URL.revokeObjectURL(u); } catch (e) {} });
      this.state = null;
    }

    async submit(num, audioKind) {
      const st = this.state;
      if (!st || !st.blob) { alert(t('need_file_for_kind')); return; }
      let source = (document.getElementById('sndSource').value || '').trim();
      if (source && !/^https?:\/\//i.test(source)) source = 'https://' + source;
      const caption = (document.getElementById('sndCaption').value || '').trim();
      const btn = document.getElementById('sndSave'); btn.disabled = true; btn.textContent = t('submitting');
      try {
        const fields = {
          kind: audioKind.key, item_num: num,
          name: this.mapApp.displayName(),
          comment: caption || null, source_url: source || null,
          media: [st.blob, st.blob.name || 'audio'],
          photo_time: new Date().toISOString(),
        };
        if (st.duration) fields.duration = st.duration;
        if (source) {
          const licEl = document.querySelector('input[name="sndLic"]:checked');
          fields.source_license = licEl ? licEl.value : 'cc0';
        }
        await this.mapApp.submitContribution(fields);
        this.mapApp.trackFeature('sound');
        this.cleanup();
        this.mapApp.refreshEntries();
      } catch (err) {
        alert(t('save_failed', { err: err.message || err }));
        btn.disabled = false; btn.textContent = t('submit_new_version');
      }
    }
  }

  new SoundEditorPlugin().init(window.MapApp);
})();
