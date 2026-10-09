# 未完成事項追蹤

由統一 commit 的維護者彙整更新（唯一寫入者）。全部完成後刪除本檔。
最後更新：2026-10-08（恢復圖資版權條置中；補記驗證範圍與母專案整合）

## 已定案的設計原則

- 點位說明是點位原生 `content`：有序區塊陣列（text 含 Markdown、audio、photo），沒有 story 欄位。
- 內容區是一個整體：一次儲存 = 一個版本 = 一位編輯者；base_rev／content_rev 做樂觀並發（不符回 409）。
- 訪客文字投稿留在底部投稿區，同樣支援 Markdown。
- 權限一律走 api/auth.php（Auth::require／Auth::can），authlint 守門；網址一律走 api/routes.php 的 Route::；導航外部網址集中在 api/navlinks.php。
- 預設底圖為 paper-ink；3D 模式沿用專案原本的向量底圖，3D 專用圖層以樣式 metadata `souliong:3d`（show／hide）標註，引擎不認圖層 id。
- 地圖地名語言由 meta.json `mapLabelLang`（auto 或語言鍵）決定，引擎在每次 style.load 後覆寫 symbol 圖層的 text-field。
- 路徑功能必須先選投稿者。

## 資料相容性

舊版資料遷移前先備份，確認預覽結果後才套用。

## 已完成（近期）

### 2026-10-08：圖資版權條置中修正

- 修正提交：`d05e2d7`。先前 CSS 清理提交 `0d7cc8f` 刪除了 `.cr-bar` 的定位規則，導致版權條回到 MapLibre 預設右下角。
- 將 `position: fixed`、`left: 50%`、`bottom: 14px`、`translateX(-50%)` 及零外距合併回原有 `.cr-bar` 規則，恢復一般地圖頁面的底部置中。純地圖嵌入模式仍由 `embed-bare.css` 覆寫為右下角。
- Chromium／Playwright 以實際 CSS 與控制項 DOM 驗證：1280 與 390 像素寬度皆置中；手機音訊迷你列出現時仍置中並上移；兩種寬度的純地圖嵌入版權條皆位於右下角。此次未載入完整地圖或驗證 VPS 上的圖磚請求。
- Souliong 已用 `https://github.com/zisunny104/souliong.git` 登記為開利手子模組；母專案 `3f62be8` 已記錄這次修正。一般部署由母專案同步記錄的提交；需單獨重跑部署時可用 `./deploy.sh --deploy-apps souliong`。

### 精選投稿地圖預覽（2026-10-08）

- 沿用原始投稿的 `featured` 狀態，精選立即連動地圖，使用所屬點位及像素偏移呈現，不更動投稿 GPS；最多三則，其餘收合至點位面板。預覽位置由 `assets/js/featured-layout.js` 依目前縮放排：比任何其他點位都更靠自己的點位、各點位角度錯開、避開別人的點位與預覽，並以細線連回所屬點位；縮放結束時重排。
- 有精選預覽時取代數字角標；一般 GPS 投稿仍沿用投稿模式。星章圓底 26px、星星 20px，點擊範圍 32px。
- 使用隔離資料與模擬地圖引擎的 Chromium／Playwright 驗證無 GPS、即時精選、數量收合、縮放、顯示開關及手機／桌面星章；尚未驗證 VPS 真實圖磚。

### 先前完成事項

伺服器端壓縮、Route:: 遷移、樣式 json 縮短快取、tools/checkall.php、點位導航（Google、Apple、geo、OSM 選單）、封面快照隱藏底圖地名並重拍三個專案、地名跟隨語言、路徑需先選投稿者、光柵底圖 minZoom／maxNativeZoom／tms／bounds、預設底圖對齊 paper-ink、3D 建物沿用原底圖（紙墨）、燈箱說明置中。
導航選單改版：觸發鈕改 icon-only（圖示外露文字進 title/aria-label）、選單 portal 到 document.body 用 position:fixed 真浮動，視覺質感比照 Tocas UI dropdown（圓角、陰影、縮放淡入）但不引入該框架；#panel 的 overflow:clip 維持不動。過程中發現並修正一個定位 bug：選單原本右對齊觸發鈕右緣，但鈕實際貼在面板左側，疊加選單無 max-width 撐寬，導致蓋到左側圖層篩選面板；改為預設左對齊（視窗右緣放不下才翻右）＋ max-width: min(280px, 100vw-16px)。已用 Playwright 在桌面寬度、.wide 展開寬度、手機寬度、深色主題下截圖驗證，選單皆貼齊觸發鈕且不越界。
OSM 驅動 3D：屋頂造型（Simple 3D Buildings）、顏色、窗戶、low-poly 樹、電塔與電線，資料由 tools/osm_fetch.php 預抓成 projects/<p>/{roofs,trees,power}.geojson（git 忽略），經 Route::osm 供應；日夜光照、3D 重新整理後還原、3D 控制鈕與深色羅盤修正。
tilecut.php 擴增選區「不拉伸原圖」功能（程式碼、lang 檔、docs/TILECUT.md 先前已完成）：修正 isPureGrow() 沒容忍 toFixed(6) 浮點誤差、導致按鈕誤判為不可用的 bug；已用 _packdemo 實測按鈕啟用/停用、平移貼上＋透明留白、檔名帶新座標轉 webp、單張 SVG 整列隱藏。
文件更新：EXTENDING.md 補齊第十三節，完整記錄 Auth/Actor/auth_registry() 架構與現有 16 個具名權限鍵（10 專案層級＋6 全站層級）及其作用域，修正第 529 行過時的 $canProject/$primary 權限描述。


## 等使用者決定

- soundspace 預設縮放 19.6 超過向量圖磚 z14，建議降到 17 至 18，或以 meta.json 限制最大縮放（需確認 PHP 端是否輸出該欄位）。
- _packdemo 是否移除 `pack: demo-loud` 以免蓋掉 paper-ink。
- 播放鍵脈動：77 無法重現，需使用者提供裝置、瀏覽器、當時聲音是否在播、完整網址。

## 尚未驗證

- OSM 3D：新資料需在每個專案跑 tools/osm_fetch.php（`--contact` 請用專案網址，不用個人信箱）；針葉樹實景、電塔切日夜、type=building 關係式成員、multipolygon 主體分件歸屬、「點位深連結加 3D 還原」組合、玻璃圓頂 way/1172959614 的新比例，皆未實測。
- 3D 高傾角：最大傾角降為 70，文字貼地（text-pitch-alignment map）與 sky／霧已實測淺色；深色底圖畫面、觸控傾斜未測。
- 管理端 mapLabelLang 下拉（未做登入後的 POST 測試）。
- 導航選單在無座標點位時的顯示；真機的 geo:／Apple 連結（.wide 展開面板與手機寬度已於導航選單改版時用 Playwright 驗證過不越界）。
- 3D：管理者畫區域、存檔、排除的完整流程；自訂模型（three.js）在地圖內的顯示；光柵底圖專案走獨立引擎的路徑。
- 光柵圖磚只驗到請求層，沒有視覺確認。
- paper-ink 深色模式的地名證據；Windows 顯示縮放造成的預覽模糊（看 devicePixelRatio）。
- 燈箱異常的實際症狀報告（77 尚未回報）；214a054 編輯器新功能的瀏覽器實測回報。

## 待辦

- 部署後確認圖層設定與現行版本一致。

## 低優先

- HEIC（heic2any）實際轉檔未測；歷史還原音訊會留下孤兒檔。
- viewer.core.js 是否拆分（暫緩）。
- 薄包裝別名 primary_perms／pin_default_perms／_account_default_perms 保留；MapApp 未使用匯出、分散的 .brand／.name-in CSS、跨檔同名 CSS class 未整理。
- view.php 為 $needPhotoKind 多載 kind-base.js（無害，可縮小條件）。
- 錄音進行中重繪麥克風不會停；投稿端與點位聲音錄音卡片樣式可合併；無障礙（鍵盤排序、aria-live）。
- 「被授權的專案管理者」中間身分未實測。

### 投稿縮圖尺寸分級

- 縮放小於 14、14 至未滿 16、16 以上分別使用 14／22／32px，精選預覽的環繞距離同步調整，沿用避讓排列與既有緩動效果。
- 一次跨過多個級距只重繪一次；同級距維持既有標記。Chromium 隔離資料測試通過三段尺寸、環繞間距與跨級距重繪檢查，尚未驗證 VPS 實際地圖。

### 實際引擎驗證補充

- `tools/realmapcheck.cjs` 使用實際 MapLibre 6.6.0、隔離的 50 地點／300 照片，驗證三段尺寸、跨級距過渡、燈箱與減少動態效果，390／1280px 皆通過功能檢查。
- 已修復密集預覽重疊：依可用空間收合，並限制畫面與緩衝範圍的預覽；軟體 WebGL 平移測量仍不保證所有情境穩定 60fps。完整方法、數據與限制見 `RELIABILITY-REVIEW.md`。
- 正式站被環境代理拒絕（CONNECT 403），VPS 真實圖磚、真實手機與跨瀏覽器驗收仍未完成。

### 密集預覽修復完成

- 矩形安全位置檢查，空間不足減少預覽／恢復地點數量入口；篩選投稿者時也包含無 GPS 投稿。
- 畫面加 96px 緩衝範圍，移出卸除、移回恢復；拖曳中維持 DOM，結束才重排，相同視角不重算。
- 原本 200 預覽降為 17／16／31，三種縮放的實際引擎測量皆無預覽矩形重疊；收合入口仍可查看完整投稿。結果及效能限制見 RELIABILITY-REVIEW.md。

### 目前位置按鈕

- 新增 `features.locate`，預設啟用；導航箭頭按鈕位於回到初始位置上方。點擊才請求定位，成功顯示位置標記，不寫入投稿。
- 真實 Chromium／MapLibre 驗證手機及桌面的按鈕順序、無自動定位、授權後定位、關閉模組，以及模擬拒絕／無法定位／逾時的提示及按鈕恢復。VPS 真實裝置授權視窗尚未驗證。

### 1.2.3：AAC 上傳與部署安裝引導

- 接受純 AAC 的實際 MIME，優先以 FFmpeg 無損換容器為 M4A；缺少工具或失敗則保留原檔，AAC 媒體端點使用 audio/aac。
- 互動部署缺少 FFmpeg 時直接引導安裝，確認後 apt 安裝及複查；不重複安裝，非互動／其他系統保留手動指引。
- `tools/aaccheck.php` 以實際 ADTS AAC 驗證 MIME、換容器、解碼內容一致與缺少工具回退；隔離上傳端點測試 82／82 通過。部署安裝引導以隔離的 apt／PHP 模擬驗證，沒有修改測試主機套件。

### 1.2.4：沿用 Google Chrome 產生社群預覽

- 部署環境檢查與 PHP 執行路徑皆納入系統 Google Chrome／Google Chrome Stable，避免已有 Chrome 卻重試 Playwright 不支援的 Ubuntu 20.04 Chromium 安裝。優先順序為系統瀏覽器，再查找下載瀏覽器；自訂 social_preview_chromium 仍優先。
