/* 投稿型別：建立地點
   嚴格說它不是「投稿內容」而是「新增一個可以被投稿的地點」，所以走的是另一支端點
   （api/newspot.php，權限由 meta.json 的 contrib.newPoint 決定），送出的欄位也跟其他型別不一樣：
   沒有 item_num、沒有授權勾選，留言框在這裡的身分是這個地點的「故事」。

   view.php 只在 contrib.newPoint 不是 off（且 admin 模式下確實是管理者）時才載入這個檔案。 */
(() => {
  const { Kind, t, esc, register } = window.SLContrib;

  // 新分類要有一個 key（?cat= 篩選、圖例順序都靠它）。中文標籤 slug 化後會是空字串，
  // 那就給一個短亂碼，至少每個新分類彼此分得開——伺服器收到空值會一律併成 'new'。
  function slugify(label) {
    const s = String(label || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
    return s || ('new-' + Math.random().toString(36).slice(2, 6));
  }

  class NewSpotKind extends Kind {
    get key() { return 'newspot'; }
    get tab() { return 'newspot'; }
    get icon() { return 'fa-map-pin'; }

    needsFile() { return false; }
    needsSpot() { return false; }   // 它自己就是一個點，不掛在別的點底下
    needsLocation() { return true; }

    extraTopHtml() {
      const cats = (window.MapApp.getCats() || []);
      const opts = cats.map(c => '<option value="' + esc(c.key) + '">' + esc(c.label) + '</option>').join('') +
        '<option value="">' + esc(t('newspot_cat_new')) + '</option>';
      return '<input type="text" class="c-title" maxlength="80" placeholder="' + esc(t('newspot_title_placeholder')) + '">' +
        '<label class="c-lab">' + esc(t('newspot_cat_label')) + '</label>' +
        '<div class="row"><select class="c-cat">' + opts + '</select>' +
        '<input type="text" class="c-catlabel" maxlength="30" placeholder="' + esc(t('newspot_cat_name_placeholder')) + '" style="display:none">' +
        '<input type="color" class="c-catcolor" value="#7A7F87" style="display:none" aria-label="' + esc(t('newspot_cat_color')) + '"></div>';
    }

    wireExtra(state, card) {
      const sel = card.querySelector('.c-cat');
      const lab = card.querySelector('.c-catlabel');
      const col = card.querySelector('.c-catcolor');
      const sync = () => {
        // 選了既有分類就沿用它的標籤與顏色（伺服器也會這樣做），只有新分類才要自己填
        const isNew = sel.value === '';
        lab.style.display = isNew ? '' : 'none';
        col.style.display = isNew ? '' : 'none';
      };
      sel.onchange = sync;
      sync();
    }

    validate(state, card) {
      return card.querySelector('.c-title').value.trim() ? null : t('newspot_need_title');
    }

    fields(state, card, common) {
      const cat = card.querySelector('.c-cat').value;
      const f = {
        title: card.querySelector('.c-title').value.trim(),
        story: common.comment,
        name: common.name,
        lat: common.lat,
        lon: common.lon,
      };
      if (cat) f.cat = cat;
      else {
        const label = card.querySelector('.c-catlabel').value.trim();
        if (label) { f.cat = slugify(label); f.catLabel = label; f.color = card.querySelector('.c-catcolor').value; }
      }
      return f;
    }

    async submit(mapApp, fields) { return mapApp.submitNewSpot(fields); }
  }

  register(new NewSpotKind());
})();
