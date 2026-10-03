# 未完成事項追蹤

由統一 commit 的維護者彙整更新（唯一寫入者）。全部完成後刪除本檔。
最後更新：2026-10-01（tilecut 擴增選區浮點誤差修正；導航選單改版完成；EXTENDING.md 補齊權限系統架構章節）

## 已定案的設計原則

- 地點說明是地點原生 `content`：有序區塊陣列（text 含 Markdown、audio、photo），沒有 story 欄位。
- 內容區是一個整體：一次儲存 = 一個版本 = 一位編輯者；base_rev／content_rev 做樂觀並發（不符回 409）。
- 訪客文字投稿留在底部投稿區，同樣支援 Markdown。
- 權限一律走 api/auth.php（Auth::require／Auth::can），authlint 守門；網址一律走 api/routes.php 的 Route::；導航外部網址集中在 api/navlinks.php。
- 預設底圖為 paper-ink；3D 模式沿用專案原本的向量底圖，3D 專用圖層以樣式 metadata `souliong:3d`（show／hide）標註，引擎不認圖層 id。
- 地圖地名語言由 meta.json `mapLabelLang`（auto 或語言鍵）決定，引擎在每次 style.load 後覆寫 symbol 圖層的 text-field。
- 路徑功能必須先選投稿者。

## 資料相容性

舊版資料遷移前先備份，確認預覽結果後才套用。

## 已完成（近期）

伺服器端壓縮、Route:: 遷移、樣式 json 縮短快取、tools/checkall.php、地點導航（Google、Apple、geo、OSM 選單）、封面快照隱藏底圖地名並重拍三個專案、地名跟隨語言、路徑需先選投稿者、光柵底圖 minZoom／maxNativeZoom／tms／bounds、預設底圖對齊 paper-ink、3D 建物沿用原底圖（紙墨）、燈箱說明置中。
導航選單改版：觸發鈕改 icon-only（圖示外露文字進 title/aria-label）、選單 portal 到 document.body 用 position:fixed 真浮動，視覺質感比照 Tocas UI dropdown（圓角、陰影、縮放淡入）但不引入該框架；#panel 的 overflow:clip 維持不動。過程中發現並修正一個定位 bug：選單原本右對齊觸發鈕右緣，但鈕實際貼在面板左側，疊加選單無 max-width 撐寬，導致蓋到左側圖層篩選面板；改為預設左對齊（視窗右緣放不下才翻右）＋ max-width: min(280px, 100vw-16px)。已用 Playwright 在桌面寬度、.wide 展開寬度、手機寬度、深色主題下截圖驗證，選單皆貼齊觸發鈕且不越界。
OSM 驅動 3D：屋頂造型（Simple 3D Buildings）、顏色、窗戶、low-poly 樹、電塔與電線，資料由 tools/osm_fetch.php 預抓成 projects/<p>/{roofs,trees,power}.geojson（git 忽略），經 Route::osm 供應；日夜光照、3D 重新整理後還原、3D 控制鈕與深色羅盤修正。
tilecut.php 擴增選區「不拉伸原圖」功能（程式碼、lang 檔、docs/TILECUT.md 先前已完成）：修正 isPureGrow() 沒容忍 toFixed(6) 浮點誤差、導致按鈕誤判為不可用的 bug；已用 _packdemo 實測按鈕啟用/停用、平移貼上＋透明留白、檔名帶新座標轉 webp、單張 SVG 整列隱藏。
文件更新：EXTENDING.md 補齊第十三節，完整記錄 Auth/Actor/auth_registry() 架構與現有 16 個具名權限鍵（10 專案層級＋6 全站層級）及其作用域，修正第 529 行過時的 $canProject/$primary 權限描述。


## 等使用者決定

- soundspace 預設縮放 19.6 超過向量圖磚 z14，建議降到 17 至 18，或以 meta.json 限制最大縮放（需確認 PHP 端是否輸出該欄位）。
- _packdemo 是否移除 `pack: demo-loud` 以免蓋掉 paper-ink。
- 播放鍵脈動：77 無法重現，需使用者提供裝置、瀏覽器、當時聲音是否在播、完整網址。

## 尚未驗證

- OSM 3D：新資料需在每個專案跑 tools/osm_fetch.php（`--contact` 請用專案網址，不用個人信箱）；針葉樹實景、電塔切日夜、type=building 關係式成員、multipolygon 主體分件歸屬、「地點深連結加 3D 還原」組合、玻璃圓頂 way/1172959614 的新比例，皆未實測。
- 3D 高傾角：最大傾角降為 70，文字貼地（text-pitch-alignment map）與 sky／霧已實測淺色；深色底圖畫面、觸控傾斜未測。Leaflet 光柵備援（OSM 標準圖磚）未實機開圖。
- 管理端 mapLabelLang 下拉（未做登入後的 POST 測試）。
- 導航選單在無座標地點時的顯示；真機的 geo:／Apple 連結（.wide 展開面板與手機寬度已於導航選單改版時用 Playwright 驗證過不越界）。
- 3D：管理者畫區域、存檔、排除的完整流程；自訂模型（three.js）在地圖內的顯示；Leaflet 光柵主引擎走獨立引擎的路徑。
- 光柵圖磚只驗到請求層，沒有視覺確認。
- paper-ink 深色模式的地名證據；Leaflet 2D 模糊；Windows 顯示縮放造成的預覽模糊（看 devicePixelRatio）。
- 燈箱異常的實際症狀報告（77 尚未回報）；214a054 編輯器新功能的瀏覽器實測回報。

## 待辦

- 部署後確認圖層設定與現行版本一致。

## 低優先

- HEIC（heic2any）實際轉檔未測；歷史還原音訊會留下孤兒檔。
- viewer.core.js 是否拆分（暫緩）。
- 薄包裝別名 primary_perms／pin_default_perms／_account_default_perms 保留；MapApp 未使用匯出、分散的 .brand／.name-in CSS、跨檔同名 CSS class 未整理。
- view.php 為 $needPhotoKind 多載 kind-base.js（無害，可縮小條件）。
- 錄音進行中重繪麥克風不會停；投稿端與地點聲音錄音卡片樣式可合併；無障礙（鍵盤排序、aria-live）。
- 「被授權的專案管理者」中間身分未實測。
