# 社群連結預覽

公開地圖、`?spot=<點位 ID>` 與 `?entry=<投稿 ID>` 提供 OG／Twitter metadata。伺服器產生 1200 × 630 JPEG 名片；社群平台是否顯示及何時更新快取，由平台決定。`?spot=` 同時接受 16 位十六進位的點位 ID 與舊的純編號，新產生的連結一律用點位 ID。

## 內容規則

- 點位：目前名稱、說明及代表照片；照片優先取點位內容，再取目前關聯的照片投稿。
- 投稿：原作者、授權名稱、最新說明與最新關聯點位，不推測作品名稱。
- 照片完整縮放、不裁切；影片使用縮圖與播放標示；文字使用內容摘錄。
- 名片上的平台名稱與「投稿」小字依頁面語系；網頁輸出名片網址時帶 `lang`，只接受已支援的語系，其他值視為繁體中文。專案、點位、投稿的內容文字維持原文。

## 名片版面

- 左側是地圖，不加任何覆蓋，只在文字欄前做漸層；右側是文字欄（名稱、聲音長度、說明、照片）。
- 上緣一條地標色粗線：點位目前的地標色（含單點自訂色），專案預覽用主色。整條切齊上緣，不倒圓角。
- 下緣置中一個膠囊：平台名稱與來源標示，平台名稱與繁中首頁相同。
- 專案名稱用近白膠囊（風格同網頁）置中放在上緣粗線下方；寬度上限同斷句寬度加兩側內距，太長時單行結尾加「…」。專案預覽的文字欄標題已經是專案名稱，不再放這個膠囊。
- 名稱（專案、點位、投稿所屬點位、平台）用粗體。字型沿用 Noto CJK 的 Bold，可用 `social_preview_font_bold` 覆寫；找不到時以重疊繪製模擬。
- 換行以單字、標點為單位：英數字詞不從中間斷開，標點不落在行首，開括號不留在行尾；超過欄寬的長字詞才逐字拆開，超過行數加「…」。
- 聲音長度後面接示意波形（固定圖樣，前段用主色，不代表實際音訊或進度），外側延伸到畫面右緣之外；長條刻意加粗，縮成小縮圖時才不會被看成分隔線。聲音投稿在知道長度時用同一個播放列，不知道長度才畫左右對稱的波形圖樣。
- 投稿卡片文字欄最上方的小字一律寫「投稿」，種類由畫面本身表達。所屬點位名稱帶編號，與網頁標題一致。
- 沒有地圖（載入失敗或環境缺少）時，地圖的位置用灰底，維持同樣的漸層與版面。
- LINE 等平台最壞會把圖裁成正方形，只有上緣粗線與置中的膠囊一定看得到；右側文字欄在被裁時可能不完整。

地圖使用同專案實際預設圖磚與既有 `spotMarkerSpecs()`，包括類別顏色、Font Awesome、編號、空白、幾何圖形、自訂圖片與外框。指定點位以既有 MapLibre 投影移到左側地圖區中央，其他地標與投稿標記隱藏。無關聯點位的投稿不顯示地標。

專案有勾選 ID 以 `-nolabels` 結尾的無標註底圖時，渲染網址以 `?layer=` 指定它，取得沒有文字的圖磚；沒有勾選時才在瀏覽器裡隱藏樣式的文字圖層。

## 網頁標題與分享標題

`<title>`、`og:title`、`twitter:title` 與前端分頁標題是同一串，由內而外以「 | 」連接：投稿（有留言取前 20 字，沒有就用「誰分享的什麼」）、點位名稱與編號、專案名稱、平台名稱。沒有的層級略過，名稱重複時去重，整串最多 90 字；專案關閉「回平台首頁」模組時不帶平台名稱。另輸出 `og:site_name`。伺服器把算好的標題交給前端（`APP.docTitle`），前端不再另外組一份。嵌入頁不輸出分享預覽。

## 執行環境

```sh
bash tools/setup-social-preview.sh
./deploy.sh --set-check-url https://example.com/souliong/
```

- 需要 PHP GD／FreeType、Noto CJK 字型（一般與 Bold）。地圖底另需 Node、Chromium、Playwright Core 及 PHP `proc_open`。依賴放入不進版控的 `state/social-preview-runtime/`，部署權限修復會保留此目錄的執行權限。
- 一般部署會先檢查，缺少時嘗試自動安裝；以 root 執行可一併安裝瀏覽器系統依賴。已就緒時不重新安裝。可用 `DEPLOY_SKIP_SOCIAL_PREVIEW=1 ./deploy.sh` 略過安裝，預覽仍會提供灰底名片。
- 可在 `api/config.php` 覆寫 `social_preview_font`、`social_preview_font_bold`、`social_preview_node`、`social_preview_chromium`、`social_preview_playwright`、`social_preview_base_url`、`social_preview_no_sandbox`。網站網址僅取可信設定或 `state/deploy_check_url`，不使用請求 Host 決定瀏覽器連線目的地。
- 部署與 PHP 預覽程序皆會自動偵測 `/usr/bin/chromium`、`/usr/bin/chromium-browser`、`/usr/bin/google-chrome`、`/usr/bin/google-chrome-stable`，優先沿用系統瀏覽器，之後才尋找 Playwright 下載的 Chromium。已有 Google Chrome 的舊版 Ubuntu 不必為此重新下載 Chromium；仍須確認 Chrome 可由網站 PHP 執行身分啟動。
- 渲染腳本是 `tools/social-preview-map.cjs`：母專案的 `package.json` 若宣告 `"type": "module"`，`.js` 會被當成 ES module 而無法使用 `require`，所以用 `.cjs`。
- 網站執行身分（如 www-data）的家目錄常常不可寫，Chromium 的 crashpad 會因此崩潰。渲染時以 `state/social-preview-home/` 當 `HOME` 與 XDG 目錄並關閉 crash 回報；這個目錄放在 `state/`（網站可寫），不放進依賴目錄，因為依賴目錄的擁有者可能是安裝時的 root。瀏覽器只拿到 `PATH`、`HOME`、語系等少數環境變數。
- 每次渲染失敗的原因（逾時、瀏覽器無法啟動、圖磚載入失敗等）與使用的網址寫入 `state/social-preview-error.log`，只留最近一次、壓成單行，不需要開 debug。

## 安全與資源限制

- 瀏覽器預設啟用 Chromium 沙箱（不使用 Playwright 預設的 `--no-sandbox`）。主機不允許沙箱（如容器內以 root 執行）導致啟動失敗時，才在 `api/config.php` 設 `social_preview_no_sandbox => true`，並確認只渲染本站頁面。
- 瀏覽器預設只讀本站及既有圖磚供應商、MapLibre CDN。其他公開圖磚主機可由伺服器設定 `social_preview_hosts` 明確加入；不接受 URL 參數變更連線主機。僅允許 GET／HEAD，不帶登入 Cookie。
- 瀏覽器最多執行 20 秒，逾時先請它結束、兩秒後仍在就強制終止；同時只啟動一個。其他請求正在渲染且沒有可用的舊圖時，回 503 與 `Retry-After`，不把降級卡當成結果交出去。
- 沒有地圖或缺少環境時使用灰底，不替換成其他圖磚、不畫假位置。缺少 GD／中文字型時，頁面保留既有照片／封面預覽。
- 未命中快取的請求有速率限制；繪圖前會跳脫 `&`，避免字型函式把 `&#數字;` 解碼成不同的字。

## 快取與資料

`?api=socialpreview&project=<slug>&spot=<id>` 或 `entry=<id>` 輸出 JPEG，支援 HEAD／ETag。僅讀取同專案公開紀錄所引用的圖片，拒絕任意檔案路徑及跨專案照片。已刪除的紀錄不可取得舊快取。

- 快取存於 `state/social-previews/`，每個專案、投稿／點位一份，不修改原照片或任何專案資料。
- 內容、關聯、代表照片及專案設定改變會更新版本；有地圖的卡片七天內只在版本改變時重畫，降級卡一分鐘後重試。
- 快取檔每天最多清理一次：超過 30 天沒重畫的名片（含已刪除紀錄留下的舊圖）與遺留暫存檔會刪除。不同語系各存一份。
- ETag 存在快取紀錄裡，命中 `If-None-Match` 時不讀圖檔。
- 對外快取最多五分鐘；更新後可使用平台提供的分享偵錯工具要求重新抓取。
