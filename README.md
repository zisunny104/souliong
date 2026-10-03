# Souliong 循跡

> 循著地方留下的痕跡，用地圖探索、記錄一座城市。
> Every place leaves traces. Every trace tells a story.

一個以地圖為媒介的開放**地方探索平台**，作為 [KoiLiSu 開利手](https://github.com/zisunny104/koilisu-framework) 框架下的一個 app。純 PHP + 檔案儲存，**零資料庫、零額外常駐服務**。底下可放多張地圖（第一張示例是中興新村「一百種停下來的理由・椅子地圖」）。

> 本 repo 只含**平台程式碼**，不含各專案內容與使用者資料（`projects/`、`state/` 皆不進版控）。

## 特色

- OSM 底圖 + 分類彩色圓點，深淺主題自動切換、骨架載入
- **可堆疊的地圖圖層**：底圖與自繪插畫疊圖由下往上疊，附對位切圖磚工具（`<base>/tilecut`）
- **3D 模式（可選）**：切換 MapLibre 向量底圖，建物自動立體擠出，並可用自訂 glTF 模型排除特定區域
- 投稿**照片／影片／音訊／文字**，可版本化的**地點內容區**（文字／音訊／照片區塊，可還原歷史，聲音區塊可分享連結）、投稿者觀察路線、參與者自建地點
- **限特定人投稿**：投稿代碼（QR 掃描／邀請連結一點解鎖）
- **只能刪自己的**（裝置匿名標記，append-only）
- 批次上傳（EXIF/GPS、HEIC→WebP、可拖曳定位）
- 匿名聚合統計（後台附純 CSS 圖表，無圖表函式庫）、可嵌入（`?embed=1`）
- **分層管理 PIN／帳號**：專案各自獨立授權，權限逐項開放

## 建立一張地圖（專案）

以主 PIN 登入後開 `<base>/newproject`，填標題、拖曳標記定位即可建立；也可以手動在 `projects/<id>/` 放 `meta.json`，**免改程式**：

- `meta.json`：標題、中心點、分類順序、模組開關（投稿要不要碼由後台的投稿代碼決定，不寫在這裡）

```json
// projects/mymap/meta.json
{ "id":"mymap", "title":"我的地圖", "subtitle":"副標",
  "center":[23.95,120.69], "zoom":14,
  "numbering":"suffix", "categoryOrder":["green","pink","blue"] }
```
地點存在 `projects/<id>/spots.jsonl`，由後台或投稿視窗的「建立地點」新增（可設為管理者限定或開放投稿者建立），沒有另外的地點檔。詳見 [EXTENDING.md](docs/EXTENDING.md)（投稿型別、模組開關、圖層系統）。對外資料 API、iframe 嵌入與 postMessage 控制見 [EMBED-API.md](docs/EMBED-API.md)。

網址：`/koilisu/souliong/<id>`；`/koilisu/souliong/` 首頁自動列出所有地圖；後台在 `/koilisu/souliong/manager`。

## 投稿代碼（給參與者上傳）

- **碼即開關**：後台建立投稿代碼＝開放投稿（要碼才能投）；一組有效碼都沒有＝目前未開放投稿，只有管理者能投。
- 碼存 `projects/<id>/codes.json`（清單），可同時開多組，各自可選填**到期時間**／**張數上限**（留空即不限）；後台一碼一張卡，含邀請連結、QR、用量、刪除。
- 在後台新增/刪除投稿代碼、複製**邀請連結**或給參與者掃 **QR**；連結 `.../<id>?code=XXXX` 一點即解鎖上傳。

## 管理後台

- 進入：直接開 `<base>/manager`，或在地圖頁**連點標題**（點→線→…→六角）叫出 PIN 面板、首頁**連點 logo**；輸入 PIN 或帳號密碼，httpOnly cookie 保持登入，**不進網址**。登入後的落點是全部地圖總覽，單張地圖在 `<base>/manager/<mapid>`。
- 權限分層管理，可逐專案、逐項授權（刪別人投稿、改別人投稿、改地點位置、建立分享連結、編 3D 排除區域…）；PIN 與帳號（userid＋密碼）皆可用，投稿者與協作者身分都能自助建立，後台僅負責顯示與撤銷。權限架構見 [EXTENDING.md](docs/EXTENDING.md)。
- 後台功能：投稿代碼／身分管理、統計摘要與圖表、審閱與刪除投稿、冒名鑑識線索、**個別/全部專案備份（ZIP）**、主題包與**圖層**管理（ZIP 匯出匯入）、資料修復工具。

## 授權

程式碼採 **MIT**（見 [LICENSE](LICENSE)）。地圖圖資 © OpenStreetMap 貢獻者（ODbL）、向量圖磚 © OpenFreeMap；使用者投稿預設以 **CC0** 公開分享，已建立身分的投稿者可改選 **CC BY**（見投稿條款）。隱私說明見 [PRIVACY.md](docs/PRIVACY.md)。

© 2026 prjToka
