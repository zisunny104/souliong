# Souliong 擴充架構（趁早預留，之後只加不打掉）

## 一、資料模型為什麼「加不打掉」

投稿存的是 **JSON-Lines（每行一個 JSON 物件）**，是 **schemaless** 的——
新增欄位/新型別**不需要遷移舊資料**。搭配每筆都有的 `kind` 欄位，任何新內容型別都是「加一個分支」，不是重寫。

一筆記錄目前的欄位：
```
id, project, item_num, kind, name, comment,
photo, thumb, photo_time, lat, lon, loc_source,
media, media_mime, duration,                      （影片／音訊；照片不會有這幾個）
exif{make,model,lens,f,exp,iso,focal,sw},
license, owner_hash, src_hash, contrib_id, contrib_hash, edit_of, created_at
```
`kind` 目前有：`photo`（照片投稿）、`video`／`audio`（影音投稿）、`text`（純文字的一則紀錄）、
`spot`（點位本身：建立／搬移／寫入原生內容，見 3.6 節）。
完整定義在 `api/features.php` 的 `souliong_kinds()`，見第三節。
`edit_of` 指向被編輯的原始投稿 id（版本化：不覆寫，新增一筆版本紀錄，前端取最新版本蓋過原始值）。
`contrib_id`/`contrib_hash` 是可選的投稿者身分（自選 PIN 才有）：前者對外可見（分組顯示用），後者僅供伺服器驗證「本人編輯/刪除」，不外流。
`license` 是投稿時決定的授權：預設 `cc0`，已建立身分的投稿者可在投稿視窗勾選改成 `cc-by`（`api/upload.php` 會再驗一次有沒有 `ctoken`——沒有穩定身分就沒有名字可標示，一律回落 `cc0`）。目前只入庫、還沒有顯示端。

## 二、新增一張地圖（完全不用改程式）

1. 建 `projects/<新id>/meta.json` 與點位 JSON（參考 `projects/100chairs/`）。
2. 要開放投稿就到後台建一組投稿代碼（碼即開關，見 `api/security.php` 的 `contrib_open()`）。
3. 進入方式：`/koilisu/souliong/<新id>`；`souliong/` 首頁會自動列出它。

點位 JSON 每筆：`num, theme, area, chair, material, lat, lon, cat, catLabel, color`
（非椅子主題可自訂欄位；`spotSub()` 會退回 `sub` 欄位。）

## 三、投稿型別（kind）：一個殼 ＋ 一組型別檔

這一節原本是「之後要加聲音」的預測清單。實際做的時候一次做完照片／影片／音訊／文字四種投稿加上「建立地點」，
預測大致命中（儲存 schemaless、渲染依 `kind` 分流，所以全是新增），但有幾件事是動手才想清楚的，記在這裡。

### 3.1 中央註冊表：`api/features.php` 的 `souliong_kinds()`

這份表原本只有 `label` 跟一個全專案沒人讀的 `has_photo` 旗標——`kind` 只是「記錄下來的標籤」。
現在它是真正被消費的定義，每個 kind 帶：

| 欄位 | 意義 |
|---|---|
| `label` | 後台投稿列表與設定介面的種類名稱 |
| `tab` | 投稿對話框的分頁代號；`null` ＝不出現在對話框（由專屬流程產生） |
| `postable` | `upload.php` 是否接受前端直接 POST 這個 kind |
| `file` | 要收的 `$_FILES` 欄位名；`null` ＝純文字投稿 |
| `thumb` | 是否伴隨一張顯示用縮圖 |
| `mimes` | 允許的 MIME ⇒ 副檔名（取代原本只服務照片的 `allowed_mime`） |
| `max_bytes` | 該種類的大小上限（沒寫就用 config 的預設） |

**`postable` 是安全邊界，不是分類。** `spot` 是 `false`：它一旦可以直接 POST 到
`upload.php`，任何人都能偽造一筆座標覆蓋紀錄，繞過 `editspot.php` 的 `Auth::require()` 與 `newspot.php`
自己的權限判斷。`upload.php` 的白名單因此改成檢查這個旗標，而不是「有沒有出現在註冊表裡」。
之後新增 kind 時，這一格要先想清楚再填。

### 3.2 每張地圖自己決定開放哪幾種：`meta.json` 的 `contrib` 區塊

```json
"contrib": { "kinds": ["text", "photo", "video", "audio"], "default": "media", "newPoint": "contributor" }
```

`souliong_contrib_cfg($meta)` 解析它，精神比照 `souliong_module_on()`：**PHP 端算一次，前端直接讀
`$APP.contrib`**，不在兩邊各自重算預設值。回傳 `kinds`（依註冊表順序，不依 meta.json 的書寫順序，
分頁排列才會每張地圖一致）、`tabs`（由 kinds 推導）、`default`（保證在 tabs 內）、`newPoint`。

**沒有 `contrib` 區塊的舊地圖一律解析成 `kinds:["photo"]`、`newPoint:"off"`**，也就是跟加這個功能之前
完全一樣——既有地圖不改設定檔就零變化。後台「編輯專案描述」對話框可以勾選型別、選預設分頁與建立地點權限；
存檔前會再跑一次 `souliong_contrib_cfg()` 收斂（例如取消勾選所有媒體型別時，`default` 會自動從 `media`
換成第一個還存在的分頁），寫進 `meta.json` 的就是前端實際拿到的東西。

### 3.3 擷取在插件、呈現在核心

投稿的「**擷取**」（選檔、轉檔、錄音、送出）整套受 `upload` 模組旗標控制，關掉就完全不該存在 → 留在插件。
投稿的「**呈現**」（地圖圖層、卡片牆、燈箱）唯讀地圖也要看得到 → 必須在核心 `viewer.core.js`。
所以型別的知識刻意拆兩邊，沒有試圖用同一組 class 兩邊共用。

擷取端：`assets/js/plugins/contribution.js` 是**與型別無關的殼**（批次佇列、進度、429 限流重試、
授權勾選、暱稱同步、`u` 快速鍵、小地圖定位校正），型別檔在 `assets/js/contrib/`：

| 檔案 | 內容 |
|---|---|
| `kind-base.js` | `ContribKind` 基底類別與註冊表 `window.SLContrib` |
| `kind-photo.js` | EXIF（exifr）、HEIC 轉檔（heic2any）、WebP 壓縮 |
| `kind-video.js` | 原檔直傳；`<video>` + canvas 抽第一幀當封面；讀長度 |
| `kind-audio.js` | 選檔 ＋ MediaRecorder 現場錄音；無縮圖 |
| `kind-text.js` | 無檔案、無座標的一則文字 |
| `kind-newspot.js` | 標題／分類／故事／小地圖選點，送 `api/newspot.php` |

三個當時想清楚的決定：

1. **型別是掛在每張卡片上的物件，不是一個插件。** 一個批次本來就可能混型別（同時丟一張照片、一段錄音），
   所以用組合：卡片持有一個 `ContribKind` 實體，殼只認得 `accepts()`／`prepare()`／`fields()`／`submit()`
   這組介面，完全不知道什麼是 EXIF、什麼是 MediaRecorder。
2. **「檔案有被載入」＝「這個型別可用」。** `view.php` 依 `souliong_contrib_cfg()` 只 `readfile` 該地圖啟用的
   型別檔（純文字地圖不會載到影片程式碼，`exifr`／`heic2any` 兩支 CDN script 也只在 `photo` 啟用時輸出），
   前端因此不需要再對 `APP.contrib.kinds` 過濾一次——註冊表裡有的就是能用的。
3. **`SLContrib.match(file, tab)` 讓目前分頁的型別優先認領檔案。** 只有音軌的 `.webm`／`.mp4`，瀏覽器一律
   照副檔名給 `video/*` 的 MIME，光看檔案分不出來是影片還是錄音；使用者當下在哪個分頁才是唯一可靠的線索。
   同一個檔案在音訊分頁是音訊、在媒體分頁是影片，這是刻意的。

分頁文案同理有一層 fallback：分頁裡只剩一種型別時（只開放照片的地圖就是），用型別自己的文案，
不然 100chairs 會看到「媒體／選擇照片或影片」——講的是這張地圖根本沒開放的東西。

### 3.4 儲存與端點

- **照片完全不動**：仍然收 `photo` 欄位、存進 `projects/<id>/photos/`、記錄欄位 `photo`／`thumb`，
  所以 `exiffix.php`／`thumbfix.php`／`editentry.php`／前端的 `photoFullUrl()` 全部照舊。
- **影音**走 `media` 欄位、存進 `projects/<id>/media/`、記錄欄位 `media`／`media_mime`（＋影片的 `thumb`）。
- **`api/media.php`** 服務影音檔，**必須支援 HTTP Range**——`<audio>`／`<video>` 靠它才能拖曳進度條，
  Safari 拿不到 `Accept-Ranges` 甚至直接不播。這是它跟 `photo.php` 分開存在的主要理由。
- **MIME 一律由伺服器判**，瀏覽器宣稱的值只當參考：圖片用 `getimagesize()`、影音用 `finfo`。
  實測 `finfo` 會把只有音軌的 WebM 判成 `video/webm`、把 AAC-in-MP4 判成 `audio/x-m4a`（它只看容器格式），
  而那兩種正是 MediaRecorder 在 Chrome／Firefox 與 Safari 的產物，所以 `audio` 的白名單要一起收下——
  否則現場錄音跟 iPhone 的語音備忘錄都會被自己的白名單擋掉。副檔名照「送進來的 kind」給，顯示端要的是 `<audio>`。

### 3.5 `text` 投稿與點位的 `text` 內容區塊

兩者刻意分開：`text` 投稿是「我留下的一則紀錄」，進 `entries.jsonl`，跟照片一樣平行排在投稿牆上、
可以沒有座標；點位內容區塊是點位自己的原生內容，進 `spots.jsonl`，由 `spotcontent.php` 整體儲存。
兩者的 `comment` 都是 Markdown，算繪只有 `spot_markdown()` 一個入口；`list.php` 與 `upload.php`
回應的 text 投稿、點位內容的 text 區塊都附衍生欄位 `html`（只在輸出時算，不寫入檔案）。

### 3.6 點位本身（`spot`）：建立、搬移、寫入原生內容是同一個 kind

點位識別（這是哪個點、在哪裡、帶什麼原生內容）跟投稿內容（掛在點位底下的一則則貢獻）是兩回事，
物理上也分開存放：`spot` 記錄只進 `spots.jsonl`，其餘所有 kind 都進 `entries.jsonl`
（`store_file()` 依 `kind` 分流，見 `api/store.php`）。原本各專案的 `data.jsonl` 是遷移前的唯讀存底，
之後不會再被任何程式碼讀寫。

`spot` 套用跟內容型別完全相同的 `edit_of` 鏈狀版本化，不是另開一套機制：

- **起點**：`api/newspot.php` 附加一筆 `kind:'spot'`、帶 `title`、無 `edit_of`——這是「建立地點」事件。
- **後續搬移／寫入內容**：`api/editspot.php`（改座標）與 `api/spotcontent.php`（寫入 `content`）各附加一筆
  `kind:'spot'`、帶 `edit_of` 指回起點的 `id` 的記錄。可覆寫欄位只有 `lat`／`lon`／`content`
  （單一清單見 `spot_overridable_fields()`），伺服器端用 `spot_effective()` 算出目前有效狀態，再以
  `spot_append_version()` 寫一筆稀疏版本紀錄：只帶這次改的欄位，沒提到的欄位不寫、也不會被蓋掉。
  寫入前會掃 `spots.jsonl` 找同 `item_num` 的起點記錄來解析 `edit_of`；找不到（點位來自靜態底稿
  `chairs.json`／`points.json`，從沒被建立過 `spot` 記錄）就把 `edit_of` 留空，退回用 `item_num`
  取最新一筆覆蓋——這是唯一沒辦法納入 `edit_of` 鏈的情況，因為靜態底稿的點位天生沒有 jsonl id 可指。
- **`effectiveSpots()`**（`viewer.core.js`，即原本的 `effectivePoints()`）比照 `effectiveEntries()`
  的 origins/edits 分組演算法：有 `title` 的是起點，`edit_of` 指向它的是版本鏈，取最新一筆疊加顯示；
  沒有起點可循的（`item_num` 覆蓋那批）疊到靜態底稿對應 `num` 上。

**`content` 是點位自己的原生內容**：投稿（`entries.jsonl`）與點位（`spots.jsonl`）是兩個平行的域，
點位記錄不指向任何投稿。`content` 是型別標記物件的陣列（目前只有 `audio`，形狀刻意設計成可擴充），
只有具備 `edit_spots` 權限的人能經 `spotcontent.php` 寫入，`renderEntries()` 在故事區放大顯示它。
它不會出現在投稿牆上；投稿牆只放 `entries.jsonl` 的投稿。

權限由該地圖的 `contrib.newPoint` 決定：`off`（預設，端點直接 403）／`admin`（比照 `editspot.php`）／
`contributor`（比照 `upload.php` 的停權與投稿代碼把關）。配號在 `store_append_locked()` 的 `LOCK_EX` 內完成，
避免兩人同時建點撞號。`spot` 記錄不可透過 `upload.php` 直接 POST（`postable:false`，見 3.1 節），
也不能被一般刪除動作清掉：`delete.php` 直接拒絕自刪 `kind==='spot'` 的記錄，`manager.php` 的刪除動作
需要額外具備 `edit_spots` 權限才能刪點位。

> 主機端提醒：影片上傳會先撞到 PHP 自己的 `upload_max_filesize`／`post_max_size`（常見預設 2M／8M），
> 那是 php.ini 的事，程式改不掉。要開放影片投稿前得先確認部署主機放行到多大。

### 3.7 舊機制淘汰與退場

`spot`／`entries.jsonl` 這次重構留下三個已淘汰、只為相容舊資料而存在的機制，不會再有新資料寫入：

- **`data.jsonl`**：各專案目錄下遷移前的唯讀存底，見 3.6 節。`store.php` 從這次重構開始所有函式都不讀寫它。
- **`kind` 值 `point`／`newpoint`**：已併入 `spot`，見 3.6 節。舊資料裡不會再新增這兩個值。
- **`primaryKind`**：已由 `spot` 記錄的 `content` 欄位取代，見 3.6 節。

**退場判準**：一個專案的 `data.jsonl` 可以安全刪除，若且唯若 `spots.jsonl`＋`entries.jsonl`
完整涵蓋 `data.jsonl` 的每一筆記錄（同一個 `id` 都找得到），且除了下列刻意改動之外，其餘欄位逐一相符：
`kind`（`point`／`newpoint` 改寫為 `spot`）、`edit_of`（遷移時對這些記錄新增的欄位，原本沒有）、
`feature`（舊版部分專案遷移時對 `spot` 起點記錄回填的欄位，系統已不再讀寫它，比對時忽略）。

**程序**：執行 `php tools/retirecheck.php <project_dir>`（唯讀、CLI only，不寫入也不刪除任何東西）。
報告 PASS 才手動刪除該專案的 `data.jsonl`——刪除動作永遠由人工執行，這支工具不會替你刪。

**範圍**：這裡列的三項只涵蓋這次 `spot`／`entries` 重構本身淘汰的機制。專案裡其他既有的相容／
遷移代碼（例如帳號系統的 PIN→帳號遷移、管理 PIN 檔案首次使用時改名、投稿代碼檔案格式一次性遷移）
是各自獨立的既有設計，不在這個退場判準的範圍內。

**後續的「Point → Spot 全面改名」計畫已完成**：檔名（`api/newspot.php`／`api/editspot.php`／
`assets/css/spot-panel.css`／`assets/js/contrib/kind-newspot.js`）、投稿 kind registry 的
`newspot` key、`?spot=` URL 參數（不留 `?point=` 相容別名）、`edit_points`→`edit_spots` 權限 key、
JS 函式（`spotTitle()`／`nearestSpot()`／`getCurrentSpot()`／`submitNewSpot()` 等）與對應的 CSS
class／i18n key 都已統一改成 `spot`。目前程式碼裡仍看得到的 `newPoint`（`meta.json` 的
`contrib.newPoint` 欄位、`CONTRIB_CFG.newPoint`）是刻意保留的例外，屬於獨立的資料欄位改名問題，
不在這次改名範圍內。

## 四、多地圖「重疊」呈現（未來）

- 現在檢視器一次載入一張地圖（一個 project）。
- 要「疊圖」＝同時載入多個 project 的點位與投稿、用圖層開關切換。
- 資料本來就是「每個 project 各自的檔案」，所以重疊只是「多讀幾個 + 圖層控制」的**新增功能**，不需改資料結構。

## 五、統計的顯示

統計記在 `projects/<project>/stats.json`（由 `api/stat.php` 累加），後台「總覽」分頁已經在畫。原始 JSON 也可以自己取用：`GET ?api=stat&project=<id>&read=1`（需已用管理 PIN 登入）。

欄位分三種形狀：`views`／`sessions`／`uploads` 是純計數；`spots`（點位熱門度）、`cameras`、`features`、`browser`、`os` 是「key ⇒ 次數」的排行；`by_hour`（0–23）、`by_dow`（0–6）、`device`（`mobile`／`desktop`）是固定格數的分布。

圖表是**伺服器端就把寬高算好的純 CSS**——沒有圖表函式庫、沒有 `<canvas>`、沒有多一支 CDN。後台是一支自足的 PHP 檔，多一個外部相依就多一個「離線或 CDN 掛掉就只剩空白」的理由。

`api/manager.php` 的 `pane-overview` 裡有兩個共用產生器，要加新圖表先看能不能套現成的：

| | 形狀 | 目前用在 |
|---|---|---|
| `$statBars($arr, $label, $limit = 20)` | 橫條排行（`<ol class="statbars">`） | 點位、相機、功能、瀏覽器、系統 |
| `$statCols($cells, $aria)` | 直條分布（`<div class="statcols">` ＋ 軸標列） | 造訪時段（24 格）、星期分布（7 格） |

兩個都吃「呼叫端已經排序、翻譯、取好前幾名的陣列」，不是 `stats.json` 原文——標籤怎麼翻、要不要 `arsort()`、取幾名都留在呼叫端，產生器只管畫。`$statCols` 的 `$cells` 每格是 `['v' => 次數, 'title' => 滑過顯示的字, 'axis' => 軸標（空字串＝這格不標）]`。

**最小可見寬度是刻意的**：橫條最小 2%、直條最小 8%，值為 0 的直條另外畫一條 3% 的淺底。只出現過一次的項目也要看得到一小截，否則使用者會以為那一列是空的；0 值畫淺底則是讓人看得出「這一格存在但沒有資料」，而不是被誤讀成軸上少了一格。

**無障礙**：橫條圖的標籤與數字本來就是真文字，只有色塊軌道 `aria-hidden`；直條圖沒有可讀的文字，所以整塊掛 `role="img"` ＋ `aria-label`，內容就是改版前那行文字摘要（例如「熱門時段（當地時間）：1 點·26、3 點·17、0 點·16」），軸標列 `aria-hidden`。圖表是**加上去的**，不是拿掉文字摘要換來的。

CSS 全部前綴 `.stat-card .col`，因為要蓋過同層的 `.stat-card .col ol`（特異度 0,2,1）；類別名一律 `stat*` 開頭，避免跟 Font Awesome 或其他全域規則撞名。

## 六、功能模組開關（後台可關，每張地圖各自設定）

地圖核心只保留「顯示點位」這個基本功能；路線導覽、地點故事編輯、上傳投稿、嵌入代碼、分享、回平台首頁、投稿者身分、依序探索、管理者邀請登入這九樣都是可關的模組，管理者在後台「編輯專案描述」對話框裡逐一勾選，存在該地圖 `meta.json` 的 `features` 物件（`{"route":true,"upload":false,...}`）。單一事實來源在 `api/features.php` 的 `souliong_modules()`（key／中文說明／預設值）與 `souliong_module_on($meta, $key)`（沒設定就用預設值，舊地圖不受影響）：

- **後台**（`manager.php`）：`souliong_modules()` 逐一畫勾選框，送出後寫回 `meta.json`。
- **樣板**（`view.php`）：`$mod = fn($key) => souliong_module_on($meta, $key);`，模組關閉時直接不輸出對應的按鈕／彈窗 HTML（不是用 CSS 藏起來）。
- **前端邏輯**（`viewer.core.js`）：`MOD(key)` 讀 `window.APP.meta.features[key]`（同樣「沒設定＝開」），`canPost()` 把 `MOD('upload')` 併進解鎖判斷；凡是對應 DOM 可能不存在的地方都要 `if (el)` 再綁事件，全域鍵盤快速鍵／Esc 關閉等會不分模組狀態一律觸發的路徑也要能安全跳過（見各 `close*()` 函式的 null 檢查）。

`delegation`（管理者邀請登入）跟上面那些有專屬插件檔的模組不一樣（`homeLink` 也是，它只是核心模板裡的一顆連結），不是插件檔案，而是核心裡兩段既有 UI 的開關：地圖頁品牌區塊的彩蛋入口（連點六下開啟 `#pinDialog`，見 `setupBrandEgg()`）與邀請連結兌換彈窗 `#adminRedeemDialog`（見 `handleRedeemFragment()`）。關閉後這張地圖不會再讓人透過網址 fragment 兌換出新的專案 PIN，地圖頁上也不再有快速登入入口——但完全不影響 `config['primary_pin']`／`state/pins.json` 的主 PIN：主 PIN 是全域權限，一律能從 `/manager` 直接登入任何專案，這條路由不經過 `view.php`，不受這個旗標影響（見 `api/security.php` 的「主 PIN／專案 PIN」兩層設計）。適合「僅檢視、只有超級管理者能更新內容，不需要專案 PIN 或邀請代理」的部署。**已知缺口**：目前只有 `view.php`／`viewer.core.js`／`api/features.php` 接上這個旗標；`manager.php` 後台的邀請連結建立介面、與 `security.php` 的 `pins_redeem()` 對「這個專案要不要接受專案 PIN」的判斷，尚未跟著收斂，待補。

`personExplore`（依序探索）沿用原本的扁平旗標寫法（`meta.json` 直接存 `personExplore: true/false`），`souliong_module_on()` 對這個 key 特殊處理，行為與既有插件機制（見下一節）相容。

「隨機探索」是首頁層級功能（從所有地圖裡挑一個跳轉，不屬於單一地圖），所以不走 `meta.json`，而是存在跨地圖的 `state/settings.json`（`api/settings.php` 的 `souliong_settings_load()`／`souliong_random_explore_on()`），在後台「工具」分頁（僅主 PIN 可見）開關。

## 七、插件標準（plugin standard）

有些功能不是每個專案都需要，做成「插件」比寫死在核心程式裡更乾淨：核心不認識任何特定插件，只提供一組掛勾點與一個基底類別；插件是 `assets/js/plugins/` 底下**獨立的檔案**，自己管理自己的 state、DOM、CSS，只在對應模組旗標開啟時才由 `view.php` 用 `<?php if ($mod('xxx')): ?>` 條件載入（讀完核心 `viewer.core.js` 之後）。關閉時整個檔案不會被讀進頁面，不留任何 HTML／`<script>` 痕跡——這是插件形式比原本散落在核心裡的 `if (MOD('xxx'))` 分支更乾淨的地方。

一個合格的插件必須符合：

1. **位置與命名**：`assets/js/plugins/<kebab-case-檔名>.js`，檔名中性、避免品牌名稱（例如 `share-link.js` 而非帶特定服務商名稱）；模組旗標的 key 維持 camelCase，跟 `api/features.php` 的 `souliong_modules()` 一致。
2. **繼承共用基底類別**：核心提供 `MapApp.Plugin`（即 `SouliongPlugin`），插件寫 `class XxxPlugin extends MapApp.Plugin`，只需覆寫 `mount()`——插件自己的 DOM／事件綁定都在這裡建立。載入時由核心（或插件檔案自己，見參考實作）`new XxxPlugin().init(window.MapApp)`；`init()` 是基底類別定義的生命週期，會存好 `this.mapApp` 再呼叫 `mount()`，插件不需要重複寫這段。
3. **自我管理**：state 放在 `this` 底下（不用模組層級的全域變數）、DOM 由 `mount()` 自己建立插入、CSS 用 `document.createElement('style')` 自己注入（不加進 `assets/css/*.css`——那些檔案是核心一定會載入的層疊樣式，插件的樣式必須跟著插件一起關掉）。
4. **只透過 `window.MapApp` 溝通**：插件不可讀寫核心內部的 closure 變數，只能呼叫下面公開的 API。需要掛進既有版位時，可以直接用文件裡列出的容器 id（例如 `#ctlBody`）當掛勾點，不需要核心另外開一個「註冊按鈕」的 API——這就是 `person-explore.js` 已經在用的作法。
5. **核心原生功能 vs. 模組專屬工具**：判斷「訪客能不能寫入」這類跟裝置權限有關的能力（`isUnlocked()`、`owner_hash`）是**核心原生功能**，任何模組都可以依賴；但某個模組自己專屬的工具函式（例如上傳模組的 `canPost()`、`resetQueue()`）**不算**核心原生功能，其他模組不可以呼叫它——即使當下剛好在同一個檔案裡摸得到。這條規則是為了避免「地點故事編輯」曾經誤呼叫上傳模組專屬的 `canPost()` 這種耦合。

`window.MapApp`（核心）目前對插件公開：

- **基底類別**：`MapApp.Plugin`（`class SouliongPlugin { constructor(key); init(MapApp); mount(); }`，`mount()` 留給子類別覆寫）。
- **事件**：`MapApp.onHook(name, fn)` 訂閱、核心內部用 `emitHook(name, ...)` 發送。目前有 `'stateChange'`（投稿新增／刪除／編輯、投稿者篩選、全部-投稿模式切換、資料重新整理等任何「顯示內容可能變了」的時機都會發送一次，插件不需要知道確切原因，收到就重新算自己要畫什麼）、`'panelReset'`（有其他管道直接開/關地點面板時發送，插件若有自己的「聚焦」狀態應在這裡清掉）、`'closeAll'`（全域 Esc 鍵或其他「全部關閉」時機發送；插件若有自己的浮層／對話框應在這裡關閉——核心不需要知道插件的對話框 id）、`'identityUploadShortcut'`（訪客點擊身分小標籤且已有投稿權限時發送；核心自己不認得「打開上傳批次視窗」這件事，改由上傳模組訂閱這個事件自己決定要做什麼——模組關閉時核心呼叫 `emitHook` 也只是發到空氣中，不會出錯）、`'identityChanged'`（顯示用的身分狀態可能變了——長按換匿名名、或解鎖狀態改變時發送；核心自己不畫身分小標籤，改由身分插件訂閱重繪）、`'identityReroll'`（專門給「換了一個新匿名名」這個更窄的時機，跟 `'identityChanged'` 分開是因為上傳模組批次視窗的暱稱欄位只需要在真的換名時重設 placeholder，不需要每次身分狀態變動就重設，否則會把使用者已經打的字清掉）。
- **延伸點（有回傳值）**：`MapApp.registerPhotoFilter(fn)`（`fn(photoEntry, currentSpot) => bool`，篩掉不想顯示的照片，AND 疊加）、`MapApp.registerEntriesHint(fn)`（`fn(currentSpot) => HTMLElement|null`，插進地點卡片內容裡的提示區塊，插件自己建節點、自己綁事件）、`MapApp.registerScopeParam(fn)`（`fn() => {key: value}|null`，插件自己想在分享連結／嵌入代碼網址上多帶的參數，會併進 `currentScopeParams()` 的輸出；讀回來則不用核心幫忙——插件自己在 `mount()` 裡 `new URLSearchParams(location.search)` 讀自己定義的 key 即可，核心不需要知道有這個參數存在）。
- **資料／動作**：`MapApp.personTimeline(name)`、`MapApp.spotTitle(p)`、`MapApp.photoFullUrl(item)`、`MapApp.openPanel(spot)`、`MapApp.openLightbox(entry, url)`、`MapApp.openUnlock()`（跳出投稿代碼／解鎖視窗）、`MapApp.refreshEntries()`（＝目前地點卡片重繪一次，通常在插件自己改了篩選狀態之後呼叫）、`MapApp.trackFeature(name)`（記一筆功能使用統計，寫進該地圖的 `stats.json`）、`MapApp.currentScopeParams()`（目前的投稿者／分類篩選狀態，序列化成 querystring 片段，分享連結／嵌入代碼都靠這個帶入範圍限制）、`MapApp.effectiveEntries()`（合併「原始投稿」與其編輯紀錄後的目前有效清單，**所有型別**都在裡面，是投稿資料的單一事實來源）、`MapApp.effectivePhotos()`（同一份清單只留有照片的那些；`route-tour`／`person-explore` 這種畫面只處理得了 `<img>` 的插件用這個，不要為了「支援新型別」把它們改成吃 `effectiveEntries()`）、`MapApp.entryFullUrl(entry)` / `MapApp.entryThumbUrl(entry)`（依型別給出主檔／縮圖網址，照片走 `?api=photo`、影音走 `?api=media`，插件不需要自己判斷 kind）、`MapApp.kindOf(entry)`（一筆投稿的 kind，舊記錄沒有這個欄位時退回 `photo`）、`MapApp.contribCfg()`（這張地圖的投稿設定，見第三節的 `souliong_contrib_cfg()`）、`MapApp.fmtDur(sec)`（影音長度的顯示格式化）、`MapApp.effectiveSpots()`（併入新建立點位並套上搬移／內容記錄後的目前點位清單，見 3.6 節的 `spot` 版本鏈）、`MapApp.getCats()`（目前地圖的分類清單）、`MapApp.submitNewSpot(fields)`（送出一筆新地點，走 `api/newspot.php` 而非投稿端點）、`MapApp.personColor(name)`（某投稿者的固定配色，跟篩選角標同一份快取，同一頁內顏色不會兜不起來）、`MapApp.toast(html)`（畫面上方跳出的短暫提示訊息）、`MapApp.displayName()`（目前裝置設定的暱稱，沒設定就退回本次隨機匿名名）、`MapApp.anonName()`（純粹讀本次隨機匿名名，不管暱稱欄位有沒有填——輸入框 placeholder 要用這個而非 `displayName()`）、`MapApp.submitContribution(fields)`（送出一筆新投稿的共用管道；`project`/`owner`/`code`/`ctoken` 這些每筆投稿都要帶的欄位由核心統一補上，插件只要給業務欄位，例如 `{kind:'text', item_num, name, comment, photo_time}`）、`MapApp.refreshPersonFilter()`（投稿者篩選下拉重新計算一次，新增投稿可能帶入新名字時要呼叫）、`MapApp.refreshCounts()`（只重算統計數字，不重繪地圖圖層／卡片——批次上傳中途每張都呼叫這個即可，比全套 `refreshAll()` 省事）、`MapApp.refreshAll()`（資料異動後的完整重繪：統計、地圖圖層、投稿者篩選、`'stateChange'` 事件、目前地點卡片，一次呼叫涵蓋所有連動，插件不需要自己記得哪些要重繪）、`MapApp.fmtTime(date)`（統一的時間顯示格式化）、`MapApp.getMeta()`（目前地圖的 `meta.json` 內容；用函式而非直接暴露屬性，是因為部分欄位可能是非同步取得，插件不該假設它在 `mount()` 當下就是最終值）、`MapApp.getCurrentSpot()`（目前面板開著的地點物件，沒開面板則為 `null`——例如上傳快速鍵要「以目前地點為預設脈絡」開啟批次視窗時要用這個，而不是自己記一份）、`MapApp.nearestSpot(lat, lon)`（找離某座標最近的地點，EXIF GPS 定位配對用）、`MapApp.spotOptionsHtml(selectedNum)`（地點下拉選單的 `<option>` HTML，批次卡片讓使用者手動指定/修正地點用）、`MapApp.srcTone(src)` / `MapApp.locNote(src)`（照片定位來源的顯示文字與樣式，核心的照片編輯面板與上傳模組的批次卡片共用同一份判斷邏輯，避免兩處各自維護一份、日後兜不起來）、`MapApp.rerollAnon()`（換一個新的本次匿名名；會依序發送 `'identityChanged'` 與 `'identityReroll'`，實際換算 `SESSION_ANON` 這個私有狀態的邏輯留在核心，插件只管觸發時機，例如長按身分小標籤）、`MapApp.identityChipClick()`（身分小標籤被點擊時該做什麼——已有投稿權限就發 `'identityUploadShortcut'`、被鎖住就開解鎖視窗、上傳模組整個關閉則什麼都不做；這個判斷要用到 `MOD('upload')`/`canPost()` 等核心私有狀態，所以決策邏輯留在核心，身分插件只負責把點擊事件轉呼叫過來）。
- **唯讀狀態**：`MapApp.getEngine()`（**新插件的正式地圖介面**，回傳 `MapEngine` 抽象基底的實例——`LeafletEngine` 或 `MapLibreEngine`，依這張地圖的主引擎而定，見第八節 8.4。插件需要碰地圖（畫 marker、畫路線、鏡頭移動、開一顆小地圖選點器…）一律呼叫這個拿到的物件上的方法，不分辨底下是哪個引擎——`route-tour.js`／`person-explore.js`／`contribution.js`／`map3d.js` 都已經是這個寫法，可以直接參考）、`MapApp.getFilterPerson()`、`MapApp.isPhotoLayerOn()`、`MapApp.isUnlocked()`（裝置是否已解鎖投稿權限——核心原生的權限判斷，見上面第 5 點）、`MapApp.hasIdentity()`（這台裝置有沒有建立跨裝置的投稿者身分；跟 `isUnlocked()` 是兩件事——能投稿不代表具名，CC BY 選項就是靠這個決定顯不顯示）、`MapApp.isEmbedMode()`（這個頁面是不是以 `?embed=1` 嵌入模式載入）、`MapApp.getProjectId()`（目前地圖的 project id，已做過安全字元過濾）。

參考實作：
- `assets/js/plugins/embed-code.js`（`embed` 旗標——產生 `<iframe>` 嵌入代碼；`class EmbedCodePlugin extends MapApp.Plugin` 寫法，是目前符合完整標準的範例）。
- `assets/js/plugins/share-link.js`（`share` 旗標——全螢幕分享卡片＋QR code；`class ShareLinkPlugin extends MapApp.Plugin` 寫法。連帶的 vendor 函式庫 `qrcode-generator.js` 也一併只在 `share` 開啟時由 `view.php` 條件載入，避免關閉時仍多載一支用不到的腳本）。
- `assets/js/plugins/route-tour.js`（`route` 旗標——多投稿者路徑／單人路徑／連點彩蛋動畫；`class RouteTourPlugin extends MapApp.Plugin` 寫法。這個模組原本跟核心主渲染流程（新增／刪除／編輯／篩選）交纏最深，改法是把核心那些散落各處的 `drawRoute()`/`drawPersonRoute()` 呼叫全部收斂成統一的 `'stateChange'` 事件——資料或篩選狀態一變就發送一次，插件訂閱這個事件自己決定要不要重繪，核心不用再認得「路徑」這個概念。`#routeBtn` 也已改為插件自己在 `mount()` 建立並插入 `#ctlBody` 第一個 `.ctl-row`，`view.php` 不再輸出這顆按鈕）。
- `assets/js/plugins/content-editor.js`（`contentEdit` 旗標——點位內容區塊的編輯：新增／修改／刪除／排序 text 與 audio 區塊，整體送 `spotcontent.php` 的 `op=save`；寫入權限看 `edit_spots`，旗標只決定前端顯不顯示入口）。
- `assets/js/plugins/contribution.js`（`upload` 旗標——目前最大的一個插件：投稿按鈕、分頁式批次投稿視窗、每張卡片的小地圖／定位來源／地點下拉、送出（含 429 限流自動重試）。型別專屬的知識（EXIF、WebP 轉檔、抽影格、錄音、建立地點表單）全都不在這個檔案裡，而在 `assets/js/contrib/kind-*.js`，見第三節。`#uploadBtn`/`#unlockFab`/`#pickImages`（插在 `#resetBtn` 之後）與批次投稿彈窗 `#contribModal`（插在 `#panel` 之後）都已改為插件自己在 `mount()` 的 `injectDom()` 建立並插入固定位置，`view.php` 不再輸出這幾段 HTML。解鎖對話框（掃碼／投稿代碼／PIN）與 `?code=` 網址參數自動解鎖仍留在核心，完全不受此旗標影響——「能不能寫入」是核心原生功能，「怎麼上傳」才是這個模組的範圍，見上面第 5 點規則。身分小標籤點擊快速開啟批次視窗改用 `'identityUploadShortcut'` 事件（核心不再直接呼叫插件內部函式），`u` 鍵快速鍵與地點卡片裡的「投稿到這個點」按鈕都用 `MapApp.getCurrentSpot()`／`MapApp.isUnlocked()` 等核心原生功能拿到需要的狀態，不假設自己知道核心內部變數）。
- `assets/js/plugins/contributor-identity.js`（`identity` 旗標——右上角身分小標籤的渲染（暱稱／管理者／匿名預覽名、解鎖狀態圖示）、點擊與長按換名的事件綁定、解鎖對話框裡「建立身分」PIN／暱稱欄位的展開收合按鈕。`#identity` 小標籤已改為插件自己在 `mount()` 建立並插入 `#trItems` 的最前面，`view.php` 不再輸出這段 HTML；旗標關閉時整個檔案不會被載入，`#identity` 自然不存在。`#idToggleBtn`/`#idFields` 仍是 `view.php` 在 `#unlockDialog` 裡輸出的既有 HTML（原因見下段），解鎖對話框其餘部分（投稿代碼輸入、QR 掃描）不受影響，純代碼解鎖照常可用。PIN／暱稱欄位本身的讀取（送出解鎖時）與重置（對話框重開時）仍留在核心——它們跟純代碼解鎖共用同一個對話框與送出按鈕，沒有乾淨的切點，且已對 DOM 是否存在做防呆（`if (el)`），旗標關閉時安全略過；`contribToken`/`contribInfo`/`myContribId` 這些「有無設定過 PIN」的實際存取也留在核心，因為 `submitContribution`／刪除／照片編輯等核心自身送出流程都要用到，且沒設 PIN 時它們本來就是無害的空字串，不受這個模組開關影響。`personExplore` 透過 `dependsOn` 相依這個模組，見下一節）。
- `assets/js/plugins/person-explore.js`（`personExplore` 旗標——選了投稿者後可依序探索他的地標／零散照片時間軸；這個檔案早於 `MapApp.Plugin` 基底類別存在，尚未改寫成 `extends` 寫法，但其餘規則——旗標自我檢查、`<style>` 自己注入、DOM 自己插入 `#ctlBody`——仍是有效範例）。

### 模組相依

當一個模組的存在前提是另一個模組開著，`souliong_modules()` 的模組定義可以加一個 `'dependsOn' => 'otherKey'`；`souliong_module_on()` 判斷時，父模組關閉就一併視為關閉，不管自己的旗標是什麼。因為 `view.php`（PHP 端 `$mod()`）與 `viewer.core.js`（JS 端 `MOD()`）都要算出一致的結果，`$APP` 會多帶一份 `moduleState`（每個模組 key 對應解析後的布林值，PHP 端用 `$mod()` 算好），JS 的 `MOD(key)` 直接讀這份資料，不在前端重算一次預設值／相依邏輯——避免兩邊各自判斷、日後兜不起來。

目前接上這個機制的有兩組：`personExplore`（依序探索）相依 `identity`（投稿者身分）——只有訪客能設定具名身分時，「選了某人、依序探索他的地標」才有意義；`identity` 相依 `upload`（上傳投稿）——身分小標籤點擊後的「快速上傳」捷徑、與解鎖對話框裡「建立身分」的 PIN／暱稱欄位（`#idToggleBtn`/`#idFields`）都只在 `#unlockDialog` 存在（即 `upload` 開啟）時才有意義，核心 `identityChipClick()` 本來就已經用 `MOD('upload')` 判斷過一次（見上一節），這裡只是把既有的耦合明文化。兩段相依會串連：`upload` 關閉 → `identity` 一併關閉 → `personExplore` 也跟著關閉，即使該地圖的 `personExplore`／`identity` 旗標本身仍是開著的（後台勾選框仍會顯示、但不生效）。

## 八、地圖圖層（底圖／疊圖）

底圖與插畫疊圖歸「資源」那一類，**不是插件**。判準就是上一節那條：插件是「可以整包關掉、關掉後核心不認得它」的功能，而底圖關掉地圖就沒了。它的形狀跟主題包一樣是「用哪一包」，不是布林開關——差別只在**數量與順序**：一張地圖只套一個 pack，卻可以由下往上疊好幾層圖層。

### 8.1 兩層作用域

| 作用域 | 位置 | 進版控？ | 用途 |
|---|---|---|---|
| `site` | `layers/<id>/` | 是 | 平台內建、所有地圖共用的底圖 |
| `project` | `projects/<proj>/layers/<id>/` | 否 | 這張地圖專屬（自繪插畫多半屬於這類） |

專案層放在 `projects/` 底下不是隨便選的：那整棵目錄本來就在 `.gitignore`，所以自繪插畫、切好的圖磚金字塔這種「內容而非程式」的檔案天然不進版控，不必為了體積另立規則。同名 id 時**專案層覆蓋全站層**，讓單一地圖能在不影響其他地圖的前提下改掉內建圖層。

反過來說，`url` 直接指向外部圖磚服務的圖層（CARTO、國土測繪中心…）一個檔案都不落地，連數量問題都不存在。

### 8.2 註冊表與選用

比照 `api/packs.php`：註冊表就是目錄底下的資料夾本身，沒有中央 index 檔，新增一層只要新增一個資料夾（內含 `layer.json`）。解析在 `api/layers.php`：

- `souliong_layer_list($cfg, $proj)` — 掃兩層作用域，回傳 `[id => manifest]`，manifest 會被補上 `id`（資料夾名稱才算數，`layer.json` 內容不可覆寫）與 `scope`。
- `souliong_layers_for($cfg, $meta, $proj)` — 這張地圖生效的**有序**陣列。`meta.json` 的 `"layers": ["carto-voyager", "chungshing-art"]` 由下往上；沒有這個欄位就退回 `config` 的 `default_layers`（預設 `['carto-voyager']`，也就是圖層化之前寫死在檢視器裡的那張底圖，所以舊地圖零影響）。選到不存在的 id 會被靜靜略過，不讓整張地圖開天窗。
- `souliong_layers_public($cfg, $meta, $proj, $base)` — 前端版本，額外把相對 `url` 改寫成絕對網址。

「特定專案才有插畫疊圖」不需要額外的開關：`meta.json` 沒寫就是沒有。

**內建的全站層**（`layers/`，都是外部圖磚服務，一個檔案都不落地）：

| id | 用途 |
| --- | --- |
| `carto-voyager` | 通用底圖，道路較寬、有淡彩。`default_layers` 的預設值。深色模式換 Dark Matter。 |
| `carto-positron` | 配色極淡，幾乎只剩路網輪廓與地名。要讓自繪插畫當主角時選這張。深色模式換 Dark Matter。 |
| `carto-positron-nolabels` | Positron 拿掉所有文字。手繪稿自己寫了地名時，底圖不必再標一次。 |
| `demo-overlay` | 透明 SVG 疊圖的參考範例，不是給正式地圖用的。 |

三張 CARTO 底圖都在 `sl-base` pane，同時勾兩張只有上面那張看得到——它們是彼此的替代品，不是可以疊加的東西。

要再加一個外部來源（國土測繪中心的電子地圖與正射航照、Esri 的衛星影像、中研院的歷史地圖…）就是「多一個資料夾放 `layer.json`」，不必動任何程式碼。注意 `attribution` 必須跟著來源走：CARTO 圖磚的資料是 OpenStreetMap，換一家就要換一份標註。

### 8.3 `layer.json`

```json
{
  "label": "中興新村手繪",
  "type": "image",
  "pane": "art",
  "url": "overlay.svg",
  "bounds": [[23.94, 120.66], [23.97, 120.70]],
  "opacity": 0.9,
  "attribution": [{ "text": "繪圖：某某" }]
}
```

`type` 目前兩種：`raster`（`L.tileLayer`，吃 `subdomains`／`detectRetina`／`maxZoom`／`maxNativeZoom`／`minZoom`／`tms`／`bounds`）與 `image`（`L.imageOverlay`，`bounds` 是必要的，可用透明 PNG 或 SVG）。`urlDark` 有值的圖層會在深淺色切換時重建，沒有的原地不動。

**這些欄位必須整組跟著 manifest，不能只抽 URL**：`subdomains`／`detectRetina`／`maxNativeZoom` 都是跟著來源走的屬性，少一個就破圖。

`attribution` 也一樣跟著來源走——CARTO 的圖磚是 OSM 資料，國土測繪中心的不是，寫死在檢視器裡一定會錯。格式是一個物件陣列，每則署名 `{text, url, copyright, suffix}`：`url` 有值才會包成連結，`copyright` 是 `true` 時前面加 `&copy;`，`suffix` 接在連結後面（例 `{osm_contributors}`，跟 `text` 一樣可以用 `{key}` 引用翻譯字串，不必為每種語言各寫一份）。標籤怎麼組（要不要連結、要不要 `&copy;`）固定由 `assets/js/engine/map-engine.js` 的 `buildCredit()`/`creditHtml()` 決定，manifest 只描述資料，不寫 HTML——這樣同一份框架換圖磚來源只是換這幾個欄位的值，不會因為換供應商就要在別的地方另刻一份標註邏輯。透過「建立圖層」／去背裁切工具（`api/manager.php`／`region3d.php`／`tilecut.php`）產生的圖層目前仍存純字串（管理員手打的單行署名），`creditListHtml()` 相容這個舊格式，原樣沿用不轉換。各層的 attribution 由 `buildCredit()` 去重串接，再接上固定的引擎（Leaflet／MapLibre）＋自家連結。

`pane` 決定疊放層級。Leaflet 預設只有 `tilePane`(200)／`overlayPane`(400)／`markerPane`(600)，圖層之間沒有可指定的層級，所以檢視器替四種角色各開一個 pane：

| `pane` | z-index | 角色 |
|---|---|---|
| `base` | 200 | 底圖 |
| `paper` | 220 | 紙張底色 |
| `road` | 240 | 道路 |
| `art` | 260 | 插畫疊圖（認不得的值一律歸這裡） |

全部落在 400 以下，所以路徑線與點位標記照舊蓋在所有圖層上面。

### 8.4 前端：兩個引擎各自的圖層掛載

檢視器現在是「引擎抽象層＋兩個可替換的引擎實作」，圖層系統不再是核心共用的一份程式碼，而是每個引擎各自負責——`LeafletEngine` 跟 `MapLibreEngine` 之間**不共用類別繼承**，`MapLayer` 這個類別族只活在 `LeafletEngine` 裡，`MapLibreEngine` 完全是另一套掛法。哪個引擎接手，由 `pages/view.php` 依這張地圖生效的 layers 裡有沒有 `type:"vector"` 決定（見下方 8.4.2），跟主引擎無關的那些「真正通用」的邏輯（marker、路線、面板、篩選）則收斂在 `assets/js/engine/map-engine.js` 的 `MapEngine` 抽象基底裡，兩個引擎都要實作同一組方法（`setMarkerLayer()`／`drawPolyline()`／`createMiniPicker()`…），呼叫端（`viewer.core.js`、各 plugin）一律只認 `MapApp.getEngine()` 給的這組介面，不分辨底下是哪個引擎。

#### 8.4.1 `LeafletEngine`（`assets/js/engine/leaflet-engine.js`）

`MapLayer` 是抽象基底，子類只需要回答「怎麼變成一個 `L.Layer`」（`build(dark, opts)`），其餘（pane、換主題重建、掛上／移除）都在基底；`MapLayer.from(manifest)` 依 `type` 挑子類（`RasterLayer`／`ImageLayer`）。`LayerStack` 持有一整疊圖層與它們共同的版權標註，`LeafletEngine` 只跟它打交道（`addTo(map, dark)` / `applyTheme(dark)`）。版權整組掛在最底層那一個 `L.Layer` 上，上層換主題重建時版權列才不會閃一下。8.3 的 `pane` 分層（`base`／`paper`／`road`／`art`）就是這裡的機制，只在 `LeafletEngine` 內有意義。

新增一種**光柵或單張疊圖**的圖層型別＝多一個 `MapLayer` 子類，`LeafletEngine` 其餘部分不用動。

#### 8.4.2 `MapLibreEngine`（`assets/js/engine/maplibre-engine.js`）

MapLibre 沒有 Leaflet 的「pane／可疊多張獨立底圖」概念，向量 style 本身就是一張完整、自成一體的地圖（道路、建物、標籤全包在裡面）。掛載演算法因此跟 `LeafletEngine` 完全不同形狀：在 `manifests` 陣列裡找 `pane==='base'` 且 `type==='vector'` 的最後一筆，它的 `url`/`urlDark` 直接當整顆地圖的 `style:`；陣列裡其餘每一筆（不論哪個 pane）在 style 載入完成後依序 `addSource()`+`addLayer()` 疊上去，一律疊在整個 style 最上層（沒有 `beforeId`）——這是刻意的取捨，跟 8.3 `art` pane（260）一律蓋在最上面的既有語意一致，只是延伸到向量 style 內部圖層；代價是向量底圖自己的路名標籤會被蓋在疊圖層下面。`applyTheme(dark)` 有 `urlDark` 時整個 `setStyle()` 重換一份 style 並在 `style.load` 重跑一次疊圖（`setStyle()` 會清空所有動態加的 source/layer，這是 MapLibre 本身的限制）；沒有 `urlDark` 就整個跳過。

新增一種向量底圖來源＝新增一個 `layers/<id>/layer.json`（`type:"vector"`，見 8.2 的 `openfreemap-liberty`），不用動任何程式碼；新增一種**要疊在向量底圖上**的圖層型別，才需要在 `MapLibreEngine` 裡多一段轉換邏輯（現在只認 `raster`→`{type:'raster', tiles:[...]}` 與 `image`→`{type:'image', url, coordinates}` 兩種）。

`MapLibreEngine` 也是 3D 地圖模式（`assets/js/plugins/map3d.js`）的地基：`enter3D()`/`exit3D()`、建物排除、自訂模型（three.js glTF）延遲載入都在這個檔案裡，不論是被當主引擎原地重用、還是 `map3d.js` 另開一顆專給 3D 用，都走同一套，呼叫端不用區分。

分享卡片與定位用的小地圖一律走 `MapApp.getEngine().createMiniPicker(container, {lat,lon,zoom})`——兩個引擎各自實作，只掛 `base` 那一層，那些畫面不需要插畫疊圖，也不該被它擋住地標。

### 8.5 圖檔端點 `<base>/layer/<project>/<id>/<路徑>`

`layer.json` 的 `url` 若是相對路徑，代表圖檔就放在該層自己的資料夾裡。框架不供應靜態檔（理由同 `api/photo.php`），所以由 `api/layerfile.php` 輸出：

```
圖磚   <base>/layer/100chairs/chungshing-art/tiles/16/54738/28275.png
單張   <base>/layer/100chairs/demo-overlay/overlay.svg
```

一支端點同時吃圖磚與單張：自繪插畫可能切成金字塔、也可能就是一張大透明 PNG／SVG，兩者只差在資料夾裡的路徑長相，`layer.json` 想換形式時網址結構不用跟著改。`<project>` 只決定解析範圍（專案層優先於全站層），全站層也走同一條網址——前端因此永遠拿到同一種網址形狀，不必知道圖層是誰的。

把關方式：

- **副檔名白名單**（`png`／`webp`／`jpg`／`jpeg`／`avif`／`svg`）。這道關卡同時保證 `layer.json` 本身拿不到——註冊表內容不該從公開端點外流。
- **路徑**每一段只允許保守字元、整串不得出現 `..`，最後再用 `realpath()` 確認實體位置落在該圖層資料夾之內（符號連結一併攤平）。
- **SVG 回應加 `Content-Security-Policy: default-src 'none'; sandbox`**：SVG 可以內嵌 `<script>`，放在 `<img>` 裡不會執行，但有人直接開這個網址就會——同源之下那等於「能放圖層檔的人＝能在本站執行腳本」。在回應層面關掉，不倚賴呼叫端怎麼用。
- **找不到檔案時分兩種**：稀疏疊圖的常態是「這一格根本沒畫」，所以圖磚形狀（`…/<z>/<x>/<y>.<ext>`）的請求回一張 68 bytes 的全透明 PNG（帶 `X-Souliong-Tile: miss`，分得出「空白」與「真的有一張全透明的磚」），Leaflet 就不會為每個空格印一行紅字；其餘（單張疊圖路徑打錯）照實回 404。

`layers/demo-overlay/` 是可以照抄的參考範例：一張透明 SVG 蓋在底圖上，沒畫到的地方完全透出底圖。要用在自己的地圖上，複製整個資料夾、換掉 `overlay.svg`、把 `bounds` 改成插畫實際對應的西南／東北兩角，再把 id 加進 `meta.json` 的 `layers`。

### 8.6 後台

**每張地圖疊哪幾層**：「編輯專案描述」對話框裡的圖層清單，勾選＋上下箭頭排序，存進 `meta.json` 的 `layers`。

清單**由上到下＝由頂層到底層**（跟繪圖軟體的圖層面板一致），`meta.json` 存的是相反方向（由下往上疊），所以 `api/manager.php` 的表單與存檔各反轉一次。送出的 `layers[]` 就是 DOM 由上到下的順序，因此排序不需要任何隱藏的序號欄位——搬動整列就是排序。

**全部不勾＝移除欄位**（跟隨 `default_layers`），不是存成空陣列：空陣列在 `souliong_layers_for()` 裡本來就等同「沒指定」，存下去只會讓人以為自己關掉了所有圖層，實際上照樣拿到預設底圖。想要「只有插畫、沒有底圖」就只勾插畫那一層。

**匯出／匯入**（形狀比照主題包，ZIP 內路徑含 `<id>/` 前綴，id 只認資料夾名稱以防 `layer.json` 內容偽造）：

| | 位置 | 權限 | 匯入落點 |
|---|---|---|---|
| 全站層 | 「工具」分頁 → 地圖圖層 | 主要管理者 | `layers/<id>/` |
| 專案層 | 專案卡片 → 地圖圖層 | 該專案的管理者 | `projects/<proj>/layers/<id>/` |

切好的圖磚金字塔請匯進**專案**——`projects/` 本來就不進版控。

跟主題包的兩點差異：

- **檔案數量不固定**。單張疊圖只有兩個檔，圖磚金字塔可能上萬個，所以 `souliong_layer_files()` 要遞迴走訪，並且設上限（預設 5000 檔／300 MB）；超過就整包放棄並回 413，請對方直接從伺服器取檔，而不是讓請求跑到逾時、留下半截 ZIP。符號連結一律跳過。
- **副檔名白名單**（`souliong_layer_mimes()`）匯出、匯入、圖檔端點三邊共用同一份：匯得出去的東西就該匯得回來。`layer.json` 之外只收圖檔，所以 ZIP 裡夾帶 `.php`、`.txt` 或 `../` 路徑都不會落地。匯進來的 SVG 可能內嵌 `<script>`，那是靠圖檔端點回應的 CSP sandbox 擋掉的（見 8.5），不是靠匯入時過濾。

匯入是**覆蓋不是取代**：ZIP 裡沒有的舊檔不會被刪掉（同主題包）。

**匯入前的撞名確認**：`souliong_layer_files()` 的檔案清單是遞迴走訪出來的，覆蓋哪些 id 得先把 ZIP 打開才知道——不像 `tilecut.php` 的 `begin` 動作能在使用者送出前就先問一次。所以 `layerimport` 先用 `zip_unpack()` 把整包收進記憶體、抓出裡面出現的所有 id，逐一比對 `$destRoot/<id>/` 是否已存在；有撞名又沒勾表單上的「覆蓋同名圖層」，整包擋下回 409（不寫任何檔案），訊息列出撞到的 id。勾了才照舊寫檔——這一步只是把「靜默覆蓋」變成「確認過的覆蓋」，覆蓋本身的語意沒有變。

**含原稿的匯出**：`Route::backupLayer($id, $project, $withSrc)` 第三個參數為真時網址多一個 `?src=1`（`index.php` 用 `parse_url(..., PHP_URL_PATH)` 先拆掉 query string 才做路徑比對，不影響既有的路徑式路由）。`backup=layer` 處理端收到 `src=1` 且該層是專案層（全站層沒有原稿）時，把 `souliong_layersrc_files()` 併進既有的 `$files` 陣列一起 `zip_pack()`——這支函式跟 `souliong_layer_files()` 回傳同一種形狀（`["<id>/相對路徑" => 磁碟絕對路徑]`），放在 **`<id>/_src/` 底下**：圖磚路徑一律是數字組成的 `<z>/<x>/<y>.ext`，`_src` 不會撞名，两邊陣列可以直接用 `+` 合併不必額外處理衝突。

原稿的匯入路由是這個功能最容易出錯、也最需要小心的部分：`_src/edit.json`、`_src/p<idx>.ext` 這種路徑**單獨用一條 `$reSrc` 規則**辨認、寫回 `souliong_layersrc_dir()`，而不是讓它跟著圖層本體規則（`$reMain`）落地到 `layers/<id>/` 底下——原稿一旦混進圖層資料夾，就繼承了圖檔端點的存取模型（副檔名白名單即可讀取，見 8.5），任何猜到網址的人都拿得到，等於繞過原稿原本的管理權限把關。**這裡有一個容易踩的陷阱**：`_src/p0.png` 這種路徑，光看檔名形狀（子目錄／檔名／副檔名）跟一般圖磚路徑（例如 `0/0/0.png`）沒有分別，如果 `$reMain` 沒有特別排除 `_src/` 開頭的子目錄，兩條規則會同時比對成功，寫檔迴圈裡先判到的那條規則就會贏——如果順序不對或沒排除，原稿會被錯誤地判給 `$reMain`、寫進沒有存取管制的 `layers/<id>/_src/`。修法是讓 `$reMain` 的子目錄那段用負向前瞻排除 `_src/`（`(?:(?!_src/)[A-Za-z0-9_.-]+/)*`），確保它結構上就比對不到，不是靠先後順序僥倖答對。全站層匯入（`$lp === ''`）一律不接受 `_src/` 路徑——全站層本來就沒有 `layersrc/` 可以寫。

**原稿佔用空間**：後台圖層清單的每一列（`$layerAdminRow`，同一份程式碼組專案卡片與工具分頁兩處清單）用 `souliong_layersrc_bytes()` 算出這一層原稿目前佔多少空間，>0 時才多顯示一顆「含原稿」匯出按鈕與一個顯示大小的小標籤——沒有原稿的話那顆按鈕匯出的內容會跟旁邊那顆一模一樣，不如不顯示；顯示與否本身就是「這一層還留不留得到原稿」的答案，不必點下去才知道（同 8.6 既有的「重新編輯」按鈕的設計邏輯）。這只是把既有的 `souliong_layersrc_bytes()`（原本只用來擋上傳超過單層上限）拿來多顯示一次，沒有新增任何計算邏輯或跨圖層加總——**要看整個網站原稿總共佔多少空間，還是得自己加總每一層的數字，沒有一個總覽頁面**，這是目前刻意留下的範圍界線，不是遺漏。

**就地改設定**：同兩處清單的「設定」按鈕（`action=layeredit`）。開出來的對話框只有跟位置與外觀有關的那幾欄——顯示名稱、疊放層級、不透明度、版權標註、顯示範圍、縮放範圍。

會這樣切的理由是成本不對稱：切一次圖磚可能產生上萬個檔，只為了改個名字或把疊圖往東挪幾公尺就要重切整包太浪費。所以**表單沒有的欄位一律原樣寫回去**（`url`、`urlDark`、`subdomains`、`detectRetina`、`type`、`desc`、`maxNativeZoom`、`generated`…）——「表單沒送」不等於「使用者想清掉」。

幾個刻意的取捨：

- `maxNativeZoom` **不開放編輯**。那是「圖磚實際切到第幾級」，由切圖工具寫入；改它只會讓 Leaflet 去要不存在的磚。
- 邊界四格**要嘛全填、要嘛全空**，只填一兩格當成錯誤。全空＝移除 `bounds`（外部圖磚服務本來就沒有範圍），不是「保持原樣」。
- 不透明度設回 `1` 就把整個 key 拿掉——1 是 Leaflet 的預設，寫進去只是雜訊。
- 版權標註上限 500 字：內建那三張 CARTO 光是 `attribution` 就 215 字（兩個帶 `target`／`rel` 的 `<a>`），砍在 200 會把使用者從沒碰過的欄位默默截斷。

留著原稿的圖層（見 8.7）會在「設定」旁邊多一顆**「重新編輯」**，連到 `tilecut` 的 `?load=<id>`。沒留原稿的就沒有這顆按鈕——按鈕在不在，本身就是「這一層還能不能改」的答案，不必按下去才知道。

**刪除**（`action=layerdelete`）擺在設定對話框最底下，用分隔線跟「儲存」隔開，送出前有 `confirm()`。整個資料夾連同圖磚一起消失，不可逆；留著原稿的話 `layersrc/<id>/` 也一併刪掉，`confirm()` 的文字會多一句提醒。

兩道護欄：

- **全站預設層刪不掉**。`default_layers` 裡的 id 回 409——刪掉它等於讓所有沒自訂圖層的地圖同時沒有底圖，這種事不該只靠一個 `confirm()` 擋。要換預設請先改設定檔。
- 路徑解析用**作用域對應的那個 root**，不是 `souliong_layer_dir()`。後者同名時偏好專案層，用在刪除上的話，想刪全站層卻剛好有同名專案層時就會刪錯一邊。

原稿刪不掉**不會擋下整個刪除**：圖磚都沒了，這時中止只會留下一個半死的圖層。照樣完成，並把 `(layersrc left)` 記進 audit log——那是給管理者事後清理用的線索，不是需要當場處理的錯誤。

刪掉之後仍指著它的 `meta.json` **不必清理**：`souliong_layers_for()` 對找不到的 id 本來就靜靜略過（同主題包），那張地圖只會少一層，不會開天窗。

### 8.7 切圖磚工具 `<base>/tilecut`

把一張或多張自繪插畫壓平成一組圖磚金字塔，落地成一個**專案層**（`projects/<proj>/layers/<id>/`）。入口在專案卡片的「地圖圖層」對話框，以及後台「工具」分頁。權限跟著專案走（比照匯入），不是主要管理者專屬。

**為什麼切磚而不是直接掛一張 `ImageOverlay`**：單張圖放大時整張都要下載而且會糊，A1 尺寸的手繪稿動輒數十 MB；切成金字塔後瀏覽器只抓看得到的那幾格。代價是檔案數量多，而這正是「專案層不進版控」要解決的問題。小張的插畫仍然直接用 `type: "image"` 單張疊圖就好，不必切。

**切割在瀏覽器做，不在 PHP**。理由同 `api/thumbfix.php`：PHP 要吃下一張上億像素的來源圖，`memory_limit` 與 `max_execution_time` 會先倒下，而且主機不一定編了 WebP。canvas 沒有這些限制，還天然吃得下 SVG。PHP 端只負責收下切好的 256×256 小圖，且每一張都重新驗過座標形狀、該 zoom 的合法範圍、檔案大小、真實 MIME 與像素尺寸——「這些檔是客戶端剛剛才產生的」不構成信任的理由。

四個 POST 動作，全部檢查 CSRF、全部回 JSON；另外一個 GET 把原稿讀回來：

| action | 做什麼 |
|---|---|
| `begin` | 建立圖層資料夾；同 id 已存在時要勾「覆蓋」才會繼續，並先清掉舊的 `tiles/`（沒勾「保留原稿」時連 `layersrc/` 一起清） |
| `srcput` | 收原稿。分段上傳，每段帶明確 offset |
| `tile` | 收一批磚。欄位名就是座標：`tiles[<z>_<x>_<y>]`，不必另外傳一份對照表 |
| `finish` | 寫 `layer.json` 與 `edit.json`，並記一筆 audit log |
| `srcfile`（GET） | 把某一張原稿讀回來，只有該專案的管理者拿得到 |

**重切一定要先清舊磚**：金字塔是稀疏的，上一版畫到、這一版沒畫到的格子若留著，就成了擦不掉的殘影。刪除不可逆，所以 `souliong_layer_rmtree()`（在 `api/layers.php`，後台刪整層也用同一支）用 `realpath()` 攤平後再確認目標確實落在該圖層資料夾之內、而且不等於 root 本身，並且一律不跟隨符號連結。

**一批 16 張**：PHP 的 `max_file_uploads` 常見上限是 20，留些餘裕。上傳沿用 `admin` 限流 bucket（120 次／分），撞到 429 就照 `Retry-After` 等待續傳，不放棄整批。

**幾何**：影像四角在 **Web Mercator 投影空間**線性對應，與 Leaflet 的 `L.imageOverlay` 一致。這是刻意的——同一張圖「切磚前用 ImageOverlay 預覽」與「切磚後用 tileLayer 顯示」必須長得一模一樣，否則對位工具就白做了。對位介面的「鎖定長寬比」因此也算在投影空間裡：用經緯度算，同一張圖在台灣的緯度會被壓扁約 8%。

**多張圖壓平成一層**。清單一列一張來源圖，由上而下＝由頂層到底層（同 8.6 的圖層挑選器）。每一張各自對位、各自有可見與不透明度，切磚時整疊在同一塊 canvas 上壓平：上層蓋住下層、上層透明的地方透出下層、全都沒畫到的地方透出底圖。

會壓平而不是各切一層，是因為分幅的掃描稿、或繪圖軟體匯出的線稿／上色／註記，在使用者眼中本來就是同一張圖；切成好幾個圖層只會讓「編輯專案描述」的清單長出一堆必須一起勾選、順序還不能弄錯的項目。真的要各自獨立控制的東西，本來就該分開切。

壓平的關鍵是一句 `drawImage`：來源矩形是「這塊磚落在該張圖的哪一段影像座標」，**即使超出影像範圍也照傳**——HTML 規格規定這時目的地會按比例一起裁切，於是每張圖自然落在磚內正確的子矩形裡，不必自己算交集。整疊畫完再掃一次 32-bit 全零判斷是不是空磚。

`bounds` 與 zoom 範圍的預設取**可見圖片的聯集**，原生 zoom 則取各張所需的**最大值**——最細的那張決定要切多深，否則它會比其他張先糊掉。

地圖上的把手只服務「清單裡選取的那一張」：中央圓鈕整張平移、兩個角落方塊縮放、虛線框標出範圍。平移的位移**算在投影空間**（理由同上一段的長寬比），中央把手也放在投影中心而不是經緯度中心——後者在南北向會偏，拖起來手感不對。

新加入的圖片若尺寸跟清單裡某一張完全相同，直接沿用那張的位置：十之八九是同一塊畫布匯出的不同圖層，省下重對一次。尺寸不同才退回「鋪滿目前視野」。

**全透明的磚根本不上傳**。缺磚由圖檔端點回 68 bytes 的透明 PNG（見 8.5），`layer.json` 再寫入 `bounds`，Leaflet 連範圍外的請求都不發。一張只畫了幾條街廓的插畫，實際落地的檔案往往只有理論張數的一小部分。

產生的 `layer.json` 把 `maxNativeZoom` 設在切到的最後一級、`maxZoom` 再放寬四級：再放大時 Leaflet 會拉伸最後一級，總比整層消失好（手繪稿放大本來就是糊的，使用者預期得到）。`generated.pieces` 記下壓平了幾張，日後只剩圖磚時還看得出這一層的來歷。

切完**還要回「編輯專案描述」把這一層勾起來**——工具只負責產生圖層，不會自作主張改動任何一張地圖的疊法。

**保留原稿**（第三步的核取方塊，預設勾著）把這次用的原圖一起存起來，之後可以載回來挪一挪重切；不勾就只留圖磚，壓平之後沒有任何辦法把其中一張拆回來。

原稿放在 `projects/<proj>/layersrc/<id>/`，**跟圖磚是兄弟目錄，不是塞在圖層資料夾裡**。這是刻意的：8.5 的圖檔端點靠副檔名白名單把關，而原稿本來就是合法的圖片，只要放進 `layers/` 底下，任何猜到網址的人都拿得到。`souliong_layer_roots()` 走不到 `layersrc/`，所以「結構上拿不到」，比「規則上不准拿」可靠。要讀原稿只有 `srcfile` 一條路：先驗管理權限，再用 `^p\d{1,2}\.(png|webp|jpg|jpeg|svg)$` 卡死檔名，回應一律 `Cache-Control: private, no-store`。全站層沒有這一層——它進版控，數十 MB 的手稿本來就不該塞進 repo。

`edit.json` 記下每張的檔名、原始尺寸、四角座標、不透明度與可見狀態，外加圖層本身那幾欄。它是**伺服器重建的**，不是把前端送來的 JSON 原樣寫下去：對不上檔案的、座標不合法的，整筆丟掉。壞掉的 `edit.json` 比沒有更麻煩——載回來的東西看起來像對的，其實已經歪了。

**分段上傳**是為了 `upload_max_filesize`：它常見的預設是 2M，而要保留的原稿動輒數十 MB。頁面載入時就把 `upload_max_filesize` 與 `post_max_size` 取小的那個乘 0.8 當分段大小（夾在 256 KB 到 4 MB 之間），前端照這個切。每段都帶**明確的 offset**，伺服器用 `filesize()` 比對，對不上就回 409 並附上自己手上的 `have`，前端從那裡續傳。這比「照順序 append 就好」多一次比對，換到的是：回應在路上掉了、前端重送同一段，也不會把檔案接成兩倍長。

型別驗證在**全部收齊之後**才做（`getimagesize()`，SVG 另外看開頭有沒有 `<svg>`），因為半截的檔案本來就驗不過。收的期間檔名是 `.p<idx>`，驗過才改名成 `p<idx>.<ext>`——沒有任何一刻會有「副檔名說是圖片、內容還沒驗過」的檔案躺在那裡。上限預設單檔 64 MB、整層 256 MB（`layersrc_max_file`／`layersrc_max_total`）。

原稿在 `begin` 之後、切磚之前上傳：空間不夠要當場知道，切了十分鐘才發現存不下，那十分鐘就白費了。

**載回**是 `?load=<id>`：頁面只內嵌 `edit.json`，圖片本身由前端逐張走 `srcfile` 抓回來重建，順便把「覆蓋」預先勾起來——會走這條路的人十之八九就是要重切同一層。原稿被刪掉或從沒留過時，`?load=` 靜靜退回空白頁面，不是錯誤。

**重切時沒勾「保留原稿」，等於把上一次留的原稿刪掉**：`begin` 一律先清 `layersrc/<id>/`。理由跟清舊磚一樣——留著跟這一版對不起來的原稿，比沒有更危險。

### 8.8 尚未完成（原「向量圖層」規劃已被取代）

這一節原本規劃向量圖磚（`.pbf`/`.mvt` 那種、樣式在瀏覽器端即時渲染的真正向量圖磚——**注意這跟 8.10 的「保持向量」不是同一件事**，8.10 是單張 SVG 原封不動當 `type:"image"` 疊圖用，本質還是點陣模型裡的一張圖）走 `protomaps-leaflet`（`L.GridLayer`，留在 `LeafletEngine` 內接一個新子類）。這個規劃已經放棄——實際做法是引進整套 `MapLibreEngine`（見 8.4.2）當**替代主引擎**，而不是在 Leaflet 裡另開一種圖層。`type:"vector"` 的 `layer.json`＋`MapLibreEngine` 現在就是向量底圖的正式機制，逐專案透過「編輯專案描述」勾選即可 opt-in（見 `layers/openfreemap-liberty/layer.json` 這個現成範例）；`protomaps-leaflet`／`L.GridLayer` 那條路不會再做。

道路／水域線寬這類「向量底圖細部樣式想再調」的需求目前刻意擱置，不在這次範圍內。

### 8.9 主題包的兩層作用域

主題包（`api/packs.php`）原本只有全站一層；現在跟圖層共用同一套模型：

| 作用域 | 位置 | 進版控？ | 用途 |
|---|---|---|---|
| `site` | `packs/<id>/` | 是 | 平台內建、所有地圖共用的主題包 |
| `project` | `projects/<proj>/packs/<id>/` | 否 | 這張地圖專屬的客製材質，不想污染全站清單時用 |

跟圖層完全同一套函式形狀：`souliong_pack_roots($cfg, $proj)`／`souliong_pack_list($cfg, $proj)`（manifest 補 `id`／`scope`）／`souliong_pack_dir($cfg, $id, $proj)`（專案層優先）／`souliong_pack_for($cfg, $meta, $proj)`（解析這張地圖生效的包，見函式內註解的三態邏輯）。一張地圖只套一個包，所以沒有圖層那種「有序陣列」，其餘（同名覆蓋、`projects/` 天然免版控）道理一致。

包的內容目前只有 `pack.json`＋`pack.css`，素材一律走 CSS 內嵌的 `data:` URI（見 `packs/demo-loud/`），不像圖層有實體圖檔要落地，所以**沒有對應 `api/layerfile.php` 的檔案端點**——`pages/view.php` 直接 `readfile()` 整份 `pack.css`。

後台的三個入口跟圖層一一對應：

| | 位置 | 權限 | 落點 |
|---|---|---|---|
| 全站包 | 「工具」分頁 → 主題包 | 主要管理者 | `packs/<id>/` |
| 專案包 | 專案卡片 → 主題包 | 該專案的管理者 | `projects/<proj>/packs/<id>/` |

匯出（`backup=pack`）、匯入（`action=packimport`）都比照 `backup=layer`／`layerimport`：沒帶 `project`＝全站、帶了＝該地圖自己的，權限跟著包住哪裡走（`$isProj ? !$canProject($pp) : !$primary`）。刪除（`action=packdelete`）也是新加的——比照 `action=layerdelete`：路徑解析用**作用域對應的那個 root**，不是 `souliong_pack_dir()`（後者同名時偏好專案層，刪除時可能刪錯邊）；全站預設包（`state/settings.json` 的 `pack`）刪不掉，回 409，要刪請先去「工具」分頁換掉全站預設。包沒有圖層那種「檔案數量不固定」問題（固定兩個檔），所以匯出不需要遞迴走訪或大小上限。

「編輯專案描述」對話框的包下拉選單現在也是 `souliong_pack_list($cfg, $proj)`——專案自己的包會出現在清單裡，並標注「本地圖專屬」（沿用圖層清單同一顆翻譯字串 `layer_scope_project`，文字本來就是通用的，沒有另外開一顆 `pack_scope_project`）。全站預設下拉（「工具」分頁的 `site_pack`）刻意維持 `souliong_pack_list($cfg)`（不帶 `$proj`）——全站預設本來就只該從全站包裡選，不然某張地圖刪掉自己的專案包後，其他地圖的全站預設會突然解析不到。

`Route::backupPack($packId, $project = '')` 現在也有第二個參數，網址形狀比照 `Route::backupLayer()`：`<base>/manager/packs/<id>.zip`（全站）／`<base>/manager/<project>/packs/<id>.zip`（專案），`Route::parseManager()` 對應加了一條專案層的 `PACKS` 分支。

### 8.10 保持向量輸出

`layer.json` 的 `type:"image"`（`imageOverlay`）從一開始就跟 `type:"raster"`（`tileLayer`）一樣是一等公民（見 8.3）——`layerfile.php`、`manager.php`、前端 viewer 全都同時支援兩種。8.7 一直沒有的，只是**產生**一份 `type:"image"` manifest 的路：`tilecut.php` 原本永遠切磚、永遠輸出 `type:"raster"`。這裡補的是產生端，不是消費端。

**適用條件**：清單裡剛好一張、而且是 SVG。前端 `vectorEligible()`／`vectorActive()`（`api/tilecut.php` 內嵌 script）判斷是否顯示「保持向量」核取方塊；伺服器端在 `finish` 動作裡獨立再驗一次——`$_POST['vector']` 非空時要求 `edit.pieces` 剛好一筆、且檔名符合 `/^p\d{1,2}\.svg$/`，兩者有一個不成立就回 `tilecut_vector_bad_source_msg`（400）。前端的判斷只是省一次來回，真正擋壞資料的是後者。

**輸出的差異**：勾起來之後，`finish` 不進切磚迴圈，也不呼叫 `estimate()` 算圖磚數；直接把已經上傳好的原稿（`layersrc/<id>/p0.svg`）**複製**（不是搬移）成 `layers/<id>/vector.svg`，manifest 寫 `type:"image"`、`url:"vector.svg"`，不含 `minZoom`／`maxNativeZoom`／`maxZoom`（`type:"image"` 本來就不看這幾欄）。`opacity` 是圖層不透明度乘上這張圖自己的不透明度（清單裡每張圖都能個別調，即使只有一張）。

**複製而不是搬移**是刻意的：`layersrc/<id>/` 本來就是「保留原稿」機制落地的地方，複製一份出來當圖層本體，原稿還留在原處，8.7 的「重新編輯」（`?load=<id>` → `loadEdit()`）完全不用另外處理就自動能用。也因為原稿本身就是圖層本體，**保持向量模式下「保留原稿」核取方塊會被強制隱藏**（`applyVectorUI()` 連帶隱藏 `#keepsrcrow`／`#keepsrchint`）——這不是使用者可以選的事，`uploadSources()` 在向量模式下一律帶 `force=true` 送出原稿，不看 `#keepsrc` 的勾選狀態。

**`edit.json` 的差異**：向量模式寫入的 `layer` 子物件不含 `ext`／`minZoom`／`maxZoom`（沒有切磚，這幾欄沒有意義）。`loadEdit()` 就是靠**這幾欄不存在**反推「上一版是保持向量輸出」，回填時把 `#vecmode` 核取方塊勾回去（見 script 內對應註解）——`edit.json` 本身沒有存一個明確的 `mode` 欄位，是靠欄位的有無倒推的，改動這幾欄的寫入邏輯時要留意這個隱性耦合。

**在 `raster` 與 `vector` 之間切換**：同一個圖層 id 從向量模式改回一般切磚模式（或反過來）時，`begin` 動作在 `overwrite=1` 時會清掉舊有的 `layers/<id>/vector.svg`，避免它變成孤兒檔案——新版 `layer.json` 已經改指向 `tiles/`，但沒人會再去讀 `vector.svg`，它就只是佔空間又容易讓人誤會目前是哪個模式。

**沒有動到的部分**：`layerfile.php`、`manager.php`、`pages/view.php`、viewer 端的 `MapLayer` 相關程式碼全部不用改——`type:"image"` 的讀取、後台圖層清單顯示、匯出 ZIP，這些機制在這次改動之前就已經對兩種 `type` 一視同仁。

### 8.11 從圖磚重建（沒留原稿的降級路徑）

`?load=<id>` 原本只看 `layersrc/<id>/edit.json` 在不在——不在就當全新圖層處理，`#lid` 退回預設值 `artwork`，使用者完全看不出來這個 id 其實已經有東西。現在多一層退而求其次的偵測：`edit.json` 不在時，改讀 `tilecut_dir($cfg, $project, $id)`（8.1 提過的專案作用域限定版路徑解析，不是 8.1 通用的 `souliong_layer_dir()`）底下的 `layer.json`——如果 `type` 是 `raster` 且欄位齊全（`bounds`／`maxNativeZoom`／`url` 副檔名都在），代表圖磚金字塔還落在磁碟上，只是沒有可回填編輯狀態的原稿，於是組出一個 `$RECON` 陣列（`id`／`ext`／`z`／`bounds`／`label`／`pane`／`opacity`／`attribution`）交給前端。`type:"image"`（8.10 的保持向量輸出）刻意不進這條路——本體是單一 SVG，沒有圖磚金字塔可拼，也不需要。

**前端**：`$RECON !== null` 時，「已載入」banner 的位置換成一個帶按鈕的提示（`tilecut_recon_hint` + `#reconbtn`），是否要花時間重建交給使用者按下去才做，不像 `$EDIT !== null` 那樣自動載入——重建要逐張抓圖磚，比讀一份 JSON 重得多。按下去之後 `reconstructFromTiles()`：

1. 用既有的 `tileRange()`（8.7 對位/切磚共用的同一個函式）算出 `RECON.z` 這一級覆蓋 `RECON.bounds` 的 tile x/y 範圍，張數超過 `MAX_TILES`（跟正常切磚共用同一個上限）就直接回絕，不開始抓。
2. 逐批（沿用上傳時的 `BATCH` 併發數）用 `<img>` 直接打 `layerfile.php` 的公開端點（`<base>/layer/<project>/<id>/tiles/{z}/{x}/{y}.<ext>`，不需要另外認證，本來就是給地圖前台用的），畫進一張 `cols*TILE × rows*TILE` 的 canvas。**目的地尺寸固定給 `TILE`**（不是來源圖片的自然尺寸）——因為缺磚時 `layerfile.php` 回的是 68 bytes 的 1×1 透明佔位圖（成功回應，不是 404，見 8.5／`layerfile.php` 內註解），照自然尺寸畫只會在該格畫出一個小點。
3. 拼好的 canvas 邊界**不是**沿用 `layer.json` 原本存的 `bounds`，是重新用 `lngOf`/`latOf` 算 tile 格子四個角落對應的經緯度——因為原始 bounds 不見得剛好落在 tile 邊界上（切磚時本來就是「涵蓋 bounds 的最小 tile 範圍」），拼回來的圖是照 tile 網格對齊的，用原 bounds 會讓拼出來的像素跟宣告的地理範圍對不上，重新對位時會歪掉。
4. 拼好轉成 PNG blob，包成一個 `Piece` 塞進 `pieces[]`（跟使用者手動加圖片走的是同一套物件），`applyNativeZoom()` 抓一次合理 zoom 範圍，`select(0)` 選取並 `map.fitBounds()` 帶過去——後續使用者能重新對位、加減圖層、再按一次「開始切」，跟正常流程完全一樣接軌。`#keepsrc`（保留原稿）預設就是勾選的，這次重切之後這個 id 就會重新有原稿可留，往後不會再掉進同一個降級路徑。

**跟正常上傳流程共用、沒有另開一套的部分**：`Piece` 類別、`tileRange()`、`applyNativeZoom()`、`select()`/`refresh()`、`MAX_TILES` 上限、切磚／上傳的 `finish` 端點。這條路徑刻意不支援中途停止（`#stop`）——張數上限已經跟正常切磚共用，最壞情況耗時跟切一次磚相當，加一套獨立的中止/續傳語意不划算。

## 九、網址表：`api/routes.php`

網址長什麼樣，全站只寫在一個檔案裡。`Route` 同時負責**拆**（`index.php` 收到請求時）與**組**（其他檔案要產生連結時），兩邊共用同一組常數，所以不會出現「拆得開卻組不回去」的歪斜。

```
<base>/manager                          全部地圖總覽（登入後的落點）
<base>/manager/<pane>                   總覽的分頁
<base>/manager/<mapid>                  單張地圖
<base>/manager/<mapid>/<pane>           分頁：overview｜records｜access｜tools
<base>/manager/logout                   登出
<base>/manager/backup.zip               全站備份
<base>/manager/<mapid>/backup.zip       單張地圖備份
<base>/manager/packs/<id>.zip           全站主題包匯出
<base>/manager/<mapid>/packs/<id>.zip   專案主題包匯出
<base>/manager/layers/<id>.zip          全站圖層匯出
<base>/manager/<mapid>/layers/<id>.zip  專案圖層匯出
```

要產生網址就呼叫 `Route::manager()`／`Route::logout()`／`Route::backupAll()`／`Route::backupProject()`／`Route::backupPack()`／`Route::backupLayer()`／`Route::tool()`／`Route::map()`／`Route::api()`，**不要自己黏字串**。前端也一樣：`view.php` 把 `Route::manager($proj)` 放進 `APP.manager`，`viewer.core.js` 讀 `MANAGER_URL` 就好。

之所以要這一層，是因為原本沒有：`?api=admin` 光一支 `manager.php` 就出現 47 次，「還原掛載根目錄」那段計算被複製了七份（其中兩份的邊界情況還算得不一樣）。改一次網址形狀就得全域搜尋改一輪，漏改的地方不會報錯，只會在某些部署下靜靜連到錯的地方。

幾個刻意的決定：

- **後台自成一區**，單張地圖掛在總覽底下（`/manager/<mapid>`），而不是掛在地圖底下（`/<mapid>/manager`）。「管全部」才是登入後的落點，它需要一個自己的根；地圖代號是它的下一層。
- **分頁寫在路徑裡**，不是 fragment，這樣才貼得出「你看這頁」的連結、重新整理也停在原地。切換分頁本身不重新載入（各分頁早就一起渲染好了），只用 `history.replaceState()` 換網址，而那些網址一樣是 `Route::manager()` 產生後塞進前端的 `PANE_URL`。舊的 `#tools` 書籤仍然認得。
- **`backup.zip` 用副檔名而不是 `?backup=1`**：地圖代號只允許 `[a-z0-9_-]`，含點的字串永遠不可能跟它撞名，而且瀏覽器與使用者一看就知道那是下載。
- **地圖代號撞到保留字時，真實資料優先**。`Route::parseManager()` 收一個 `$isProject` 回呼（由 `index.php` 提供，實作是「`projects/<id>/` 在不在」），所以一張真的叫 `tools` 的地圖仍然打得開（`/manager/tools`），代價是總覽的工具分頁得寫成 `/manager/tools/tools`。反過來用保留字黑名單的話，那張地圖會永遠打不開——那才是真的壞掉。
- **`?api=photo` 這類資料出口維持 query 形式**（`Route::api()`）。它們不是「頁面」，參數本身含斜線（`f=<project>/<file>`），改成路徑只是多一層轉義。
- **舊網址不打斷**：`?api=admin`、`/admin`、`/<mapid>/manager|admin|edit` 都還在，`manager.php` 對 **GET** 回 302 導向正規形式。只導 GET——POST 帶著表單內容，302 會把 body 丟掉；下載類請求也不導，那不是「頁面」，導了只是讓瀏覽器多跑一趟。
- **掛載根目錄 `Route::base()` 只算一次**：不能用「目前網址去掉 query」代替，後台可能是從 `/manager/<mapid>/tools` 這種深路徑進來的，那樣算出來的 base 會多黏幾段，組出來的公開網址與分享連結會整個是壞的。

## 十、命名與品牌

- 平台：**Souliong｜循跡**（原創詞，靈感取自客語拼音音韻與 Soul＝地方精神；非客語單字）。
- Slogan：Every place leaves traces. Every trace tells a story.（每個地方都留下痕跡，每一道痕跡都有故事。）
- 署名：© 2026 prjToka。

## 十一、全站容量統計：快取，不是即時計算

主要管理者在「工具」分頁能看到全站容量總覽（投稿檔案／圖層／主題包），每個專案的總覽卡片也有一顆「空間佔用」磚——這些數字全部來自 `state/storage.json`，不是頁面載入當下算出來的。

**為什麼不即時算**：`manager.php` 的後台頁面是一次把 `pane-overview`／`pane-access`／`pane-tools` 全部 render 出來，前端只用 JS 切換哪個 `.pane` 顯示（`display:none`），不是分頁各自發請求。圖層目錄是圖磚金字塔，一層可能就有幾百到幾千個檔案；如果容量計算寫在任何一個 pane 的渲染迴圈裡，等於**每次載入後台頁面都會遞迴掃過全部專案的圖磚**，不管當下看的是哪一分頁，而且是所有管理者（含專案管理者）共同承受的成本。

**函式分工**（`api/store.php`）：

- `souliong_dir_bytes(string $dir): int`：遞迴加總一個目錄底下所有檔案大小，跟 `manager.php` 的 `backup=all` 用的 `$addDir` closure 同一套 `RecursiveIteratorIterator`／`RecursiveDirectoryIterator` 寫法，只加總不收集檔案清單；目錄不存在回 0。
- `souliong_storage_compute(array $cfg): array`：實際做全站遞迴掃描，只回傳陣列，**不寫檔**。分三塊：`layers`／`packs` 各自用 `'' => [...]` 存全站層、`'<proj>' => [...]` 存各專案自己的（用 `$info['scope'] === 'project'` 過濾，避免跟合併進來的全站層重複計）；`uploads` 存各專案 `photos/`／`media/` 的大小。圖層要先過 `souliong_layer_is_local()`（layers.php）濾掉外部圖磚服務（本來就是 0 bytes，目錄可能根本不存在）。
- `souliong_storage_cache(array $cfg): ?array`：讀 `state/storage.json`，檔案不存在或格式壞掉回 `null`。**呼叫端要能分辨「還沒算過」（`null`）跟「算出來是 0」**——前端據此決定顯示「—」／「還沒計算過」還是真的顯示 0 KB，不能把兩者混為一談。

**寫入時機**：只有主要管理者手動 POST `action=storagerecalc`（`manager.php`）才會呼叫 `souliong_storage_compute()` 並覆寫快取，寫入時連帶記一筆 `computed_at` 時間戳，頁面上據此顯示「計算於 X」，讓數字的新舊誠實揭露，不假裝即時。這個動作本身可能跑上幾秒，跟既有的「備份全站」（同樣是單一請求裡遞迴打包全部 `projects_dir`／`state_dir`）是同一等級的操作，不需要額外的背景工作機制。

**這階段刻意沒做的事**：清理／壓縮功能。使用者原始需求裡有提到，但清理範圍（刪什麼、怎麼判斷能刪）與壓縮定義（是重新壓縮圖片、還是別的意思）都還沒決定，留到之後單獨規劃再做——目前只有「看得到用了多少」，沒有任何會刪檔案或改檔案內容的動作。
