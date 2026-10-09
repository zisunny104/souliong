# 未完成事項追蹤

由統一 commit 的維護者彙整更新（唯一寫入者）。全部完成後刪除本檔。

## 已定案的設計原則

- 點位說明是點位原生 `content`：有序區塊陣列（text 含 Markdown、audio、photo），沒有 story 欄位。
- 內容區是一個整體：一次儲存 = 一個版本 = 一位編輯者；base_rev／content_rev 做樂觀並發（不符回 409）。
- 訪客文字投稿留在底部投稿區，同樣支援 Markdown。
- 權限一律走 api/auth.php（Auth::require／Auth::can），authlint 守門；網址一律走 api/routes.php 的 Route::；導航外部網址集中在 api/navlinks.php。
- 預設底圖為 paper-ink；3D 模式沿用專案原本的向量底圖，3D 專用圖層以樣式 metadata `souliong:3d`（show／hide）標註，引擎不認圖層 id。
- 地圖地名語言由 meta.json `mapLabelLang`（auto 或語言鍵）決定，引擎在每次 style.load 後覆寫 symbol 圖層的 text-field。
- 路徑功能必須先選投稿者。

## 等使用者決定

- soundspace 預設縮放 19.6 超過向量圖磚 z14，建議降到 17 至 18，或以 meta.json 限制最大縮放（需確認 PHP 端是否輸出該欄位）。
- _packdemo 是否移除 `pack: demo-loud` 以免蓋掉 paper-ink。
- 播放鍵脈動：77 無法重現，需使用者提供裝置、瀏覽器、當時聲音是否在播、完整網址。

## 尚未驗證

- OSM 3D：新資料需在每個專案跑 tools/osm_fetch.php（`--contact` 請用專案網址，不用個人信箱）；針葉樹實景、電塔切日夜、type=building 關係式成員、multipolygon 主體分件歸屬、「點位深連結加 3D 還原」組合、玻璃圓頂 way/1172959614 的新比例，皆未實測。
- 3D 高傾角：最大傾角 70，文字貼地（text-pitch-alignment map）與 sky／霧已實測淺色；深色底圖畫面、觸控傾斜未測。
- 管理端 mapLabelLang 下拉（未做登入後的 POST 測試）。
- 導航選單在無座標點位時的顯示；真機的 geo:／Apple 連結。
- 3D：管理者畫區域、存檔、排除的完整流程；自訂模型（three.js）在地圖內的顯示；光柵底圖專案走獨立引擎的路徑。
- 光柵圖磚只驗到請求層，沒有視覺確認。
- paper-ink 深色模式的地名證據；Windows 顯示縮放造成的預覽模糊（看 devicePixelRatio）。
- 燈箱異常的實際症狀報告（77 尚未回報）；214a054 編輯器新功能的瀏覽器實測回報。

## 待辦

- 部署後確認圖層設定與現行版本一致。

## 低優先

- HEIC（heic2any）實際轉檔未測；歷史還原音訊會留下孤兒檔。
- viewer.core.js 是否拆分（暫緩）。
- MapApp 未使用匯出、分散的 .brand／.name-in CSS、跨檔同名 CSS class 未整理。
- view.php 為 $needPhotoKind 多載 kind-base.js（無害，可縮小條件）。
- 錄音進行中重繪麥克風不會停；投稿端與點位聲音錄音卡片樣式可合併；無障礙（鍵盤排序、aria-live）。
- 「被授權的專案管理者」中間身分未實測。
