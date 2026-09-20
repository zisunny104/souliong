# 未完成事項追蹤

由 100chairs-53 彙整維護（唯一寫入者）。全部完成後刪除本檔。
最後更新：2026-09-20（已 push 至 e5a7d42）

## 已定案的設計原則

- 點位說明是點位原生 `content`：有序區塊陣列（text 含 Markdown、audio，之後可加 photo），沒有 story 欄位。
- 內容區是一個整體：一次儲存 = 一個版本 = 一位編輯者；base_rev／content_rev 做樂觀並發（不符回 409）。
- 訪客文字投稿留在底部投稿區，同樣支援 Markdown。
- 權限一律走 api/auth.php（Auth::require／Auth::can），authlint 守門；網址一律走 api/routes.php 的 Route::。

## 部署必做

1. 每個環境部署後立即 `php tools/content_migrate.php projects/<p>` 預覽，確認後加 `--apply`（會先備份）；完成後刪除該工具。
2. 未執行前，沒有 id 的舊區塊在前端整體儲存時會被刪掉。
3. api/config.php 不在 git 內，需依 config.example.php 自行補 compress_*、ffmpeg_bin 等鍵。

## 進行中（2026-09-20）

- c9：樣式 json 縮短快取（sprite、圖磚維持 immutable）、tools/checkall.php 整合測試、content_migrate 本機預覽、EXTENDING／README 對齊現況。
- 77：燈箱（全螢幕看圖）異常查證、214a054 編輯器新功能的瀏覽器實測。
- 53：收到回報後統一 commit、push；點位／投稿／權限架構報告。

草稿、離頁警告、409 合併、歷史還原、聲音區塊分享連結、照片區塊已完成（214a054、17d4b49），不再列。

## 新需求：點位導航

點位（spot）要能被導航：
- 是否可導航由資料決定（有座標且設為可導航才顯示；規格待定：預設全開或每點位可關閉）。
- 可導航時點位面板顯示「導航」按鈕，按下跳出選單，選擇導航軟體後開啟。
- 手機要支援多種軟體：Google Maps、Apple 地圖、OpenStreetMap 系（OsmAnd、Organic Maps、OSM 網頁路線）等；桌機以網頁版為主。
- 網址格式集中管理（不在各處硬編碼）；深連結格式需逐一驗證。
- 負責：前端選單（77）、連結產生與設定鍵、lang（c9）；規格定案前先問使用者。

## 等使用者決定

- content_migrate --apply 到正式環境（部署時執行）。
- 點位導航的預設行為與軟體清單。

## 低優先

- HEIC（heic2any）實際轉檔未測；歷史還原音訊會留下孤兒檔。
- 沒有整合的測試執行器（authlint、authcheck、contentcheck 需分別跑）。
- viewer.core.js 是否拆分（暫緩）。
- 薄包裝別名 primary_perms／pin_default_perms／_account_default_perms 保留；MapApp 未使用匯出、分散的 .brand／.name-in CSS、跨檔同名 CSS class 未整理。
- view.php 為 $needPhotoKind 多載 kind-base.js（無害，可縮小條件）。
- 錄音進行中重繪麥克風不會停；投稿端與點位聲音錄音卡片樣式可合併；無障礙（鍵盤排序、aria-live）。
- 「被授權的專案管理者」中間身分未實測。
