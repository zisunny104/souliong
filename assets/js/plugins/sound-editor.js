/* 選用插件：點位原生音訊內容編輯（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.soundEdit 為 true、且目前身份具備 edit_spots 權限時，
   view.php 才會載入這個檔案；這只是顯示層級的判斷，真正擋寫入的是 api/spotcontent.php 的
   perm_check(edit_spots)。
   跟 story-editor.js 是同一種角色：故事區「新增一則版本」的動作，只是送出的是錄音而非故事文字，
   但寫入的是點位自己的 content 欄位（見 MapApp.submitSpotContent()／api/spotcontent.php），
   不進 entries.jsonl、不會出現在投稿牆上，錄完就是這個點位當下的音訊內容，不必另外「設精選」。
   錄音機／選檔／量時長仍借用 kind-audio.js 的 AudioKind 類別（buildRecorder/prepare/acceptAttr），
   所以這張地圖仍要開著 upload 模組、contrib.kinds 留著 "audio"，這支檔案才拿得到那個類別。 */
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

    // #storyActions 是核心 renderEntries() 每次重建 #entries 時一定會重畫的容器，藉 registerEntriesHint 的時機掛上錄音鈕。
    // 按鈕要不要出現只看這支檔案有沒有被載入（view.php 的 soundEdit && canEditSpots 條件，見檔頭
    // 註解），這裡不再另外拿 SLContrib 裡有沒有註冊 audio 型別當可見性判斷。
    injectRecordButton(spot) {
      const actions = document.getElementById('storyActions');
      if (!actions) return;
      if (!this.mapApp.isUnlocked() || this.mapApp.isEmbedMode()) return;
      const audioKind = window.SLContrib && window.SLContrib.byKey('audio');
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
          kind: audioKind.key,
          name: this.mapApp.displayName(),
          comment: caption || null, source_url: source || null,
          media: [st.blob, st.blob.name || 'audio'],
        };
        if (st.duration) fields.duration = st.duration;
        if (source) {
          const licEl = document.querySelector('input[name="sndLic"]:checked');
          fields.source_license = licEl ? licEl.value : 'cc0';
        }
        // 寫進點位自己的 content，不是投稿——submitSpotContent() 成功後會自己重繪故事區，
        // 這裡不用再另外呼叫 refreshEntries()（重繪同時也會把這個編輯面板本身清掉）。
        await this.mapApp.submitSpotContent(num, fields);
        this.mapApp.trackFeature('sound');
        this.cleanup();
      } catch (err) {
        alert(t('save_failed', { err: err.message || err }));
        btn.disabled = false; btn.textContent = t('submit_new_version');
      }
    }
  }

  new SoundEditorPlugin().init(window.MapApp);
})();
