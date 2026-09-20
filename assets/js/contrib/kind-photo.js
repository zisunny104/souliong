/* 投稿型別：照片
   EXIF 讀取（exifr）、HEIC 轉檔（heic2any）、WebP 壓縮全部收在這裡——這三件事只有照片用得到，
   view.php 也只在這張地圖啟用 photo 時才輸出那兩支 CDN script。 */
(() => {
  const { Kind, t, register } = window.SLContrib;

  async function readExif(file) {
    const out = { time: null, lat: null, lon: null, source: null, cam: null };
    try { const g = await exifr.gps(file); if (g && typeof g.latitude === 'number') { out.lat = g.latitude; out.lon = g.longitude; out.source = 'exif'; } } catch (e) {}
    try {
      const m = await exifr.parse(file, ['DateTimeOriginal', 'CreateDate', 'Make', 'Model', 'LensModel', 'FNumber', 'ExposureTime', 'ISO', 'FocalLength', 'Software']);
      if (m) {
        const dt = m.DateTimeOriginal || m.CreateDate; if (dt) out.time = new Date(dt).getTime();
        const s = (v, n) => String(v).replace(/\x00/g, '').trim().slice(0, n);
        const cam = {};
        if (m.Make) cam.make = s(m.Make, 40);
        if (m.Model) cam.model = s(m.Model, 60);
        if (m.LensModel) cam.lens = s(m.LensModel, 60);
        if (m.FNumber) cam.f = +(+m.FNumber).toFixed(1);
        if (m.ExposureTime) cam.exp = +(+m.ExposureTime).toFixed(5);
        if (m.ISO) cam.iso = parseInt(m.ISO, 10) || undefined;
        if (m.FocalLength) cam.focal = +(+m.FocalLength).toFixed(1);
        if (m.Software) cam.sw = s(m.Software, 40);
        if (Object.keys(cam).length) out.cam = cam;
      }
    } catch (e) {}
    if (!out.time) out.time = file.lastModified || Date.now();
    return out;
  }

  async function toWebp(file, max = 1600, q = 0.85) {
    let src = file;
    if (/heic|heif/i.test(file.type) || /\.hei[cf]$/i.test(file.name)) {
      const j = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.92 });
      src = Array.isArray(j) ? j[0] : j;
    }
    let bmp;
    try { bmp = await createImageBitmap(src, { imageOrientation: 'from-image' }); }
    catch (e) { bmp = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = URL.createObjectURL(src); }); }
    let w = bmp.width, h = bmp.height;
    if (Math.max(w, h) > max) { const s = max / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
    const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
    cv.getContext('2d').drawImage(bmp, 0, 0, w, h);
    return await new Promise(r => cv.toBlob(r, 'image/webp', q));
  }

  // 點位內容編輯的照片區塊借用（不依賴投稿殼）
  window.SLPhotoTools = {
    acceptAttr: () => 'image/*,.heic,.heif',
    accepts: (file) => /^image\//i.test(file.type) || /\.(jpe?g|png|webp|heic|heif|gif|bmp|tiff?)$/i.test(file.name),
    toWebp,
  };

  class PhotoKind extends Kind {
    get key() { return 'photo'; }
    get tab() { return 'media'; }
    get icon() { return 'fa-image'; }

    acceptAttr() { return 'image/*,.heic,.heif'; }
    accepts(file) { return /^image\//i.test(file.type) || /\.(jpe?g|png|webp|heic|heif|gif|bmp|tiff?)$/i.test(file.name); }
    // 沒開放影片的地圖（例如 100chairs）媒體分頁只剩照片，按鈕就不該寫成「照片或影片」
    tabLabel() { return 'tab_photo'; }
    pickLabel() { return 'pick_photos_btn'; }
    pickHint() { return 'pick_photos_hint'; }

    async prepare(file, state) {
      // 座標優先用照片自己的 EXIF GPS，所以 EXIF 要先讀完（殼在 prepare() 之後才問 initialLoc()）
      state.exif = await readExif(file);
      state.blob = await toWebp(file);
      // 顯示用小縮圖從已轉好的主圖再縮一次，避免重讀 HEIC；失敗不擋上傳，顯示端會 fallback 用原圖
      try { state.thumb = await toWebp(state.blob, 640, 0.78); } catch (e) { state.thumb = null; }
    }

    renderPreview(state, el) {
      el.textContent = '';
      if (state.blob) el.style.backgroundImage = 'url(' + this.objUrl(state, state.blob) + ')';
      else el.textContent = t('image_read_failed');
    }

    initialLoc(state) {
      const x = state.exif;
      return (x && x.source === 'exif') ? { lat: x.lat, lon: x.lon, source: 'exif' } : null;
    }
    timeOf(state) { return (state.exif && state.exif.time) || super.timeOf(state); }

    // 照片是唯一「只留一段話、沒有檔案」也成立的型別（既有行為，upload.php 那邊也是這樣判的）
    validate(state, card) {
      if (!state.blob && !card.querySelector('.c-cmt').value.trim()) return t('need_photo_or_comment');
      return null;
    }

    fields(state, card, common) {
      const f = { ...common, photo_time: new Date(this.timeOf(state)).toISOString() };
      if (state.exif && state.exif.cam) f.exif = JSON.stringify(state.exif.cam);
      if (state.blob) f.photo = [state.blob, 'photo.webp'];
      if (state.blob && state.thumb) f.thumb = [state.thumb, 'thumb.webp'];
      return f;
    }
  }

  register(new PhotoKind());
})();
