# 社群連結預覽

公開地圖、`?spot=<點位 ID>` 與 `?entry=<投稿 ID>` 提供 OG／Twitter metadata。伺服器產生 1200 × 630 JPEG 名片；社群平台是否顯示及何時更新快取，由平台決定。

點位使用目前名稱、說明及代表照片；照片優先取點位內容，再取目前關聯的照片投稿。投稿使用原作者、授權名稱、最新說明與最新關聯點位，不推測作品名稱。照片完整縮放、不裁切，影片使用縮圖與播放標示，音訊使用識別圖示，文字使用內容摘錄。圖片與名片有柔和圓角，左下保留 Souliong 署名及必要地圖來源。

## 執行環境

```sh
bash tools/setup-social-preview.sh
./deploy.sh --set-check-url https://example.com/souliong/
```

需要 PHP GD／FreeType、Noto CJK 字型。地圖底另需 Node、Chromium、Playwright Core 及 PHP `proc_open`。依賴放入不進版控的 `state/social-preview-runtime/`，部署權限修復會保留此目錄的執行權限。

一般部署會先檢查，缺少時嘗試自動安裝；以 root 執行可一併安裝瀏覽器系統依賴。已就緒時不重新安裝。可用 `DEPLOY_SKIP_SOCIAL_PREVIEW=1 ./deploy.sh` 略過安裝，預覽仍會提供淡底名片。

可在 `api/config.php` 覆寫 `social_preview_font`、`social_preview_node`、`social_preview_chromium`、`social_preview_playwright`、`social_preview_base_url`。網站網址僅取可信設定或 `state/deploy_check_url`，不使用請求 Host 決定瀏覽器連線目的地。

地圖使用同專案實際預設圖磚與既有 `spotMarkerSpecs()`，包括類別顏色、Font Awesome、編號、空白、幾何圖形、自訂圖片與外框。指定點位以既有 MapLibre 投影移到左側，其他地標與投稿標記隱藏；不另建類別配對或圖磚設定。無關聯點位的投稿不顯示地標。

瀏覽器預設只讀本站及既有圖磚供應商、MapLibre CDN。其他公開圖磚主機可由伺服器設定 `social_preview_hosts` 明確加入；不接受 URL 參數變更連線主機。僅允許 GET／HEAD，不帶登入 Cookie。瀏覽器最多執行 15 秒，同時只啟動一個；地圖載入未完成或缺少環境時使用淡色底，不替換成其他圖磚、不畫假位置。缺少 GD／中文字型時，頁面保留既有照片／封面預覽。

## 快取與資料

`?api=socialpreview&project=<slug>&spot=<id>` 或 `entry=<id>` 輸出 JPEG，支援 HEAD／ETag。僅讀取同專案公開紀錄所引用的圖片，拒絕任意檔案路徑及跨專案照片。已刪除的紀錄不可取得舊快取。

快取存於 `state/social-previews/`，每個專案、投稿／點位一份，不修改原照片或任何專案資料。內容、關聯、代表照片及專案設定改變會更新版本；完成的地圖名片快取一天，地圖降級結果一分鐘後重試。對外快取最多五分鐘；更新後可使用平台提供的分享偵錯工具要求重新抓取。
