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

## 等使用者決定

| 項目 | 現況 |
|---|---|
| layerfile 圖層檔 immutable 快取一年 | 舊訪客要強制重新整理才會拿到新底圖樣式；要立即生效需縮短樣式 json 快取 |
| content_migrate --apply 到真實資料 | 等確認 |
| 草稿只存記憶體 | 可改存 localStorage |
| 409 沒有「載入最新並保留草稿」 | 是否做合併 |
| beforeunload 離頁警告 | 未做 |
| 歷史版本只列不還原 | 需還原 op |
| 點位聲音區塊分享連結 | 需先定網址格式 |
| A3 照片區塊 | 是否開始 |

## 低優先

- HEIC（heic2any）實際轉檔未測；歷史還原音訊會留下孤兒檔。
- 沒有整合的測試執行器（authlint、authcheck、contentcheck 需分別跑）。
- viewer.core.js 是否拆分（暫緩）。
- 薄包裝別名 primary_perms／pin_default_perms／_account_default_perms 保留；MapApp 未使用匯出、分散的 .brand／.name-in CSS、跨檔同名 CSS class 未整理。
- view.php 為 $needPhotoKind 多載 kind-base.js（無害，可縮小條件）。
- 錄音進行中重繪麥克風不會停；投稿端與點位聲音錄音卡片樣式可合併；無障礙（鍵盤排序、aria-live）。
- 「被授權的專案管理者」中間身分未實測。
