# 管理指南

建立地圖、開放投稿與後台操作。部署見 `deploy.sh --help`，擴充見 [EXTENDING.md](EXTENDING.md)，嵌入見 [EMBED-API.md](EMBED-API.md)。

## 建立一張地圖

以主要 PIN 登入後開 `<base>/newproject`，填標題、拖曳標記定位即可。也可手動在 `projects/<id>/` 放 `meta.json`，不必改程式：

```json
{ "id": "mymap", "title": "我的地圖", "subtitle": "副標",
  "center": [23.95, 120.69], "zoom": 14,
  "numbering": "suffix", "categoryOrder": ["green", "pink", "blue"] }
```

- `meta.json` 放標題、中心點、分類順序與模組開關（`features`）；投稿要不要碼由後台的投稿代碼決定。
- 點位存在 `projects/<id>/spots.jsonl`，由後台或「建立」視窗新增（唯讀地圖不提供）。
- 網址：地圖 `<base>/<id>`、首頁列出所有地圖、後台 `<base>/manager`。

## 開放投稿

- **碼即開關**：建立有效的投稿代碼就是開放投稿（要碼才能投）；沒有有效碼＝未開放，只有管理者能投。
- 碼存於 `projects/<id>/codes.json`，可同時多組，各自可設到期時間與使用上限。
- 後台一碼一張卡：邀請連結（`<base>/<id>?code=XXXXXX`，一點即解鎖）、QR、用量、刪除。
- 投稿對話框可單獨嵌入，見 [EMBED-API.md](EMBED-API.md)。
- 猜錯代碼有獨立限流，可在 `rate_limits.codefail` 調整。

## 管理後台

- 進入：`<base>/manager`，或在地圖頁連點標題、首頁連點 logo 叫出 PIN 面板；登入狀態用 httpOnly cookie 保持。單張地圖在 `<base>/manager/<mapid>`。
- 權限可逐專案、逐項授權，PIN 與帳號皆可使用；架構見 [EXTENDING.md](EXTENDING.md) 第十三章。
- 功能：投稿代碼與身分管理、統計、審閱與刪除投稿、備份與還原（ZIP）、主題包與圖層管理、資料修復工具。
