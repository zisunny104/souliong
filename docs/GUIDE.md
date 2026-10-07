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

## 內容顯示與署名

在專案設定的「內容顯示」，點位與投稿的編輯紀錄、署名各有獨立開關，預設均開啟。開關控制前台顯示，既有版本紀錄仍保留，管理與寫入權限不變。

署名格式支援 `{name}`、`{datetime}`、`{date}`、`{time}`，可換行，內容以純文字顯示。點位預設為 `— {name}・{datetime}`；投稿預設姓名與日期時間分兩行。留空恢復預設，最長 500 字元。格式存於 `meta.bylineFormats.spot`／`entry`，顯示開關沿用 `features.spotHistory`／`entryHistory`／`spotByline`／`entryByline`。

左上控制面板預設寬 280px，最小寬 220px，窄螢幕會限制在畫面寬度扣除兩側 14px。點位清單數量使用膠囊顯示；點位介紹直接呈現內容，不再加上固定標題。

驗證：`php tools/checkall.php`；`node tools/displaycheck.js`（需 Playwright／Chromium，使用隔離資料與模擬地圖引擎，驗證後台儲存與前台顯示）。

## Font Awesome 地標圖示

專案設定 → 地標外觀 → 地標顯示選擇「Font Awesome 圖示」。`pinMark` 的 `number`／`blank`／`shape`／`image`／`icon` 互斥，圖示模式只顯示圖示，標題編號仍由 `numbering` 獨立決定。

同一類別共用 `meta.categoryIcons[類別代號]`，無分類使用 `new`。選單有即時 SVG 預覽，名稱只接受內建的 61 種免費 Solid 圖示（Font Awesome Free 6.5.1），未設定或非法名稱退回 `location-dot`。背景仍使用既有 `categoryColors`，尺寸、外框、投稿徽章及音訊播放光暈不變。切換其他模式會保留圖示設定供日後使用。

圖示來自官方 `@fortawesome/free-solid-svg-icons@6.5.1`，固定 SVG 路徑存於 `assets/icons/fontawesome-solid.json`，授權附於同目錄的 `FONT-AWESOME-LICENSE.txt`。僅啟用 icon 模式的地圖載入圖示目錄與渲染工具，不依賴 Font Awesome 字型下載。

導航按鈕的圖示放大至 20px，按鈕仍維持 32×32px；導航選單與點位面板的關閉／展開圖示也調整清晰度，按鈕尺寸不變。

點位下拉清單的數量顯示在選擇框內；展開清單沿用主題樣式並限制高度。專案設定的「功能模組」可切換「清單顯示地標」（預設開啟），清單標記沿用地圖的模式、尺寸與類別顏色。未開放投稿時不顯示邀請第一則投稿的空白提示；既有投稿仍可瀏覽。

文字與圖片投稿的說明均支援 Markdown，沿用伺服器端的 `spot_markdown()` 解析；原始說明仍儲存在 `comment`，HTML 僅在回傳時產生。圖片的投稿卡片及放大預覽都會呈現 Markdown。CC BY 投稿顯示「CC BY · 作者名稱」，使用投稿的 `name` 欄位，包含匯入作者；授權姓名標示不受投稿署名顯示開關影響。

投稿授權使用 `license` 欄位：`cc0`、`cc-by`、`cc-by-sa`、`cc-by-nd`、`cc-by-nc`、`cc-by-nc-sa`、`cc-by-nc-nd`。有身分的新投稿預設選 CC BY-NC，保留既有記憶選項及歷史授權。`author_url` 為選填作者網址（http／https）；匯入資料可使用相同欄位，授權者使用原始投稿的 `name`。授權標章連到官方說明，複製連結顯示在同一列左側。投稿條款見 PRIVACY.md。點位下拉清單使用浮動彈出層，不改變控制卡高度。

投稿末尾的操作與授權共用一列，空間不足時自然換行。「引用」圖示可複製作者、授權名稱與官方條款網址，以及原始投稿的分享連結。
