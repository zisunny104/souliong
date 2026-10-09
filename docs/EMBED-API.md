# 嵌入與資料 API

Souliong 對外提供四種整合方式，彼此獨立，可單獨使用：

1. **資料 API**：唯讀 JSON，取得專案設定與點位。
2. **嵌入模式**：把地圖頁放進 iframe（`?embed=1&ui=bare`），由父頁用 `postMessage` 控制鏡頭。
3. **導航小頁**：`?api=navsheet`，只含「用哪個軟體導航」選單，可放進 iframe 或 modal。
4. **嵌入投稿**：`?embed=1&ui=submit`，只放投稿對話框，見第 10 節。

本文的網址都以 `<站台>` 代表部署位置（含路徑前綴）。實際網址由站台的路由設定決定，請以部署的站台為準，不要寫死路徑格式。互動示範與手動測試頁見 `tools/embed-demo.html`。

---

## 1. 識別碼：spotId 與 num

| 欄位 | 性質 | 能否當外部鍵 |
|---|---|---|
| `spotId` | 16 位小寫十六進位字串，建立點位時隨機產生，之後不變，備份匯出入時保留 | 可以 |
| `num` | 顯示編號，會出現在標籤與清單；刪除或重整後可能被重用，也可能被改號 | 不可以 |
| 專案 `slug` | 專案識別，沿用建立時的名稱，不亂數化 | 可以 |

- 凡是接受 `num` 的入口（`?spot=`、統計、`item_num` 等）同時接受 `spotId`；新產生的連結一律使用 `spotId`。
- 外部系統要記住「某一個點位」時，請存 `spotId`，不要存 `num`。
- 目前統計資料仍以 `num` 為鍵（顯示用途），所以統計數字在 `num` 被重用時可能與舊點位混在一起。這是已知限制。

---

## 2. 資料 API

兩支端點皆為 `GET`（也接受 `HEAD`、`OPTIONS`），無狀態：不開 session、不送 Set-Cookie。

### 2.1 專案

```
GET <站台>?api=project&project=<slug>
```

```json
{
  "v": 1,
  "id": "<slug>",
  "title": "…",
  "subtitle": "…",
  "view": { "center": [lat, lon], "zoom": 16, "minZoom": 3, "maxZoom": 19 },
  "layers": [ { "id": "…", "type": "vector-style|raster", "styleUrl": "…", "attribution": "…" } ],
  "cats": [ { "key": "…", "label": "…", "color": "#rrggbb" } ],
  "updatedAt": "…"
}
```

### 2.2 點位

```
GET <站台>?api=spots&project=<slug>
```

```json
{
  "v": 1,
  "project": "<slug>",
  "etag": "…",
  "spots": [
    {
      "spotId": "03f4ca5421f1336c",
      "num": 12,
      "title": "…",
      "area": "…",
      "cat": "…",
      "lat": 24.0473,
      "lon": 120.6872,
      "nav": { "google": "…", "apple": "…", "geo": "…", "osm": "…" }
    }
  ]
}
```

- 只回「目前有效」的狀態（已套用修改紀錄），欄位為白名單；不含任何雜湊（投稿者雜湊、來源雜湊、IP）、後台網址、CSRF 權杖或權限資訊。
- `nav` 的連結已完成名稱的 URL 編碼；不帶起點，不做定位。

### 2.3 快取與錯誤

- 回應帶 `ETag` 與 `Cache-Control: public, max-age=60`；請求帶 `If-None-Match`（含 `W/` 前綴）時命中回 `304`。
- 專案不存在或 slug 格式錯誤：`404`（slug 缺漏或格式錯誤為 `400`）；`POST` 等其他方法：`405` 並附 `Allow`。
- 超過速率限制回 `429`。限制可在站台設定的 `rate_limits` 調整。

### 2.4 CORS

- 預設不送任何 CORS 標頭（空清單）。
- 來源在允許清單內時，`Access-Control-Allow-Origin` 只回該來源本身，不使用 `*`；所有回應都帶 `Vary: Origin`。
- 預檢 `OPTIONS` 回 `204`。
- 不在清單內的來源，瀏覽器會因缺少標頭而擋下跨來源讀取；CORS 只限制瀏覽器，不是存取控制，資料本身是公開的。

---

## 3. 允許清單

同一份清單同時控制：資料 API 的 CORS、地圖頁與導航小頁的 `frame-ancestors`、以及 `postMessage` 的來源白名單。

- 全站：由 `php tools/embed_allow.php list|add|remove <來源>` 維護，另可在站台設定填 `embed_allowed_origins`，兩者取聯集。
- 單一專案：後台專案設定的「允許嵌入的網域」。
- 實際生效清單為兩者聯集。預設為空，空清單代表全部拒絕。

格式限制，不符者會被拒絕或忽略：

- `https://host[:port]`；開發用途允許 `http://localhost[:port]` 與 `http://127.0.0.1[:port]`。
- 不接受萬用字元、路徑、查詢字串、片段、帳密、IDN（請填 punycode）。
- 預設連接埠會被正規化，比對時不分大小寫。

注意：從備份匯入專案不會還原 `meta.json`，匯入後需重新設定該專案的允許網域。

---

## 4. 嵌入地圖

```html
<iframe src="<站台>?p=<slug>&embed=1&ui=bare" allow="" loading="lazy"></iframe>
```

只有 `embed=1` 時下列參數才有效：

| 參數 | 值 | 預設 | 說明 |
|---|---|---|---|
| `ui` | `bare` | 完整介面 | 隱藏標題、圖例、清單、選單、縮放鈕、重設鈕、投稿與解鎖等浮層，也不載入與地圖無關的功能。圖資版權標示保留（縮小置於右下角，不可移除） |
| `interactive` | `0`、`1` | `0` | `0` 時地圖不接受使用者拖曳與縮放，也不送 `spot-click`；`1` 開放操作與點位點擊 |
| `view` | `meta`、`fit`、`none` | `meta`（bare） | `meta` 用專案設定的中心與縮放；`fit` 縮放到涵蓋所有點位；`none` 不動鏡頭 |
| `bg` | `transparent`、`theme` | `theme` | 見下方說明 |
| `theme` | `light`、`dark`、`auto` | 不指定 | 色彩模式；不指定時沿用預設行為（跟隨系統） |
| `layer` | 圖層 id | 專案預設 | 必須是該專案已啟用的底圖圖層，否則忽略 |
| `spots` | `0`、`1` | `1` | 顯示點位標記；`0` 隱藏點位，仍可用 spotId 控制鏡頭 |
| `contributions` | `0`、`1` | `1` | 顯示投稿縮圖與投稿數量角標；`0` 隱藏這些顯示，不刪除投稿 |
| `labels` | `0`、`1` | `1` | `0` 在執行期隱藏底圖的文字標籤（點位標記除外） |

`bg=transparent` 的限制：它只影響底圖載入前的背景色與背景圖層，土地與水體的填色仍會繪出，**不保證整體透明**。需要與父頁融合時，請搭配 `layer`／`labels` 與父頁的版面處理。

引擎：鏡頭指令只支援 MapLibre 向量引擎。若專案使用其他引擎，頁面仍可顯示地圖，但鏡頭指令會回 `error: unsupported`。

---

## 5. postMessage 協定

### 5.1 訊息格式

所有訊息（雙向）皆為：

```json
{ "v": 1, "ns": "souliong", "id": "任意字串", "type": "…", "…": "指令參數" }
```

- 父頁送指令時自行帶 `id`；子頁的 `done`／`error` 會原樣帶回同一個 `id`。
- 地圖頁只接受 `event.source` 為父視窗、且 `event.origin` 在允許清單內的訊息，其餘一律忽略。回覆只送給該來源，不使用 `"*"`。
- 父頁收訊息時也請同樣檢查 `event.source`（你的 iframe `contentWindow`）與 `event.origin`（站台的 origin），送訊息時 `targetOrigin` 指定站台 origin。
- 未知的 `type` 回 `error: unknown_command`，不丟例外。
- 數值會做型別與範圍檢查：座標、`zoom`（夾在專案的 `minZoom`／`maxZoom`）、時間（上限 10000 ms）、陣列長度（上限 500）。
- 使用者系統設定為減少動態（`prefers-reduced-motion`），或指令帶 `reducedMotion: true` 時，動畫時間視為 0。

### 5.2 子頁送出的事件

| type | 內容 | 時機 |
|---|---|---|
| `ready` | `{project, view:{center,zoom}, spotCount, engine}` | 第一次進入閒置（地圖可操作） |
| `idle` | 無 | 每次鏡頭停止且圖磚載入完 |
| `tile-error` | 無 | 圖磚載入失敗；是否改用備援由父頁決定 |
| `spot-click` | `{spotId, num}` | 使用者點擊點位，僅 `interactive=1` |
| `done` | `{id, state, center, zoom, result?}` | 指令完成 |
| `error` | `{id, code, message}` | 指令失敗 |

`done.state` 為 `completed`、`interrupted`、`skipped`、`cancelled` 之一，`center` 為 `[lat, lon]`，`zoom` 為結束時的縮放。`getState`、`getSpots`、`snapshot` 的結果放在 `done.result`。

### 5.3 場景指令（主要路徑）

場景把「停留、飛行、發光、淡化其他點」包成一個指令。

**`scene`**

| 參數 | 預設 | 說明 |
|---|---|---|
| `spotId` | `null` | 目標點位；`null` 只做畫面中心的緩慢放大，不發光、不淡化 |
| `hold` | `0` | 先停留的毫秒數（維持目前視角） |
| `fly` | `1500` | 飛行毫秒數 |
| `zoom` | 專案目前縮放 | 目標縮放 |
| `offset` | 無 | `{x, y}`，範圍 -0.5 至 0.5，以畫面寬高為單位；目標點落在偏離中心的位置，例如 `{x:0, y:0.15}` 表示點落在中心偏下 |
| `glow` | `true` | `true`、`false` 或顏色字串；到達後持續脈動發光 |
| `dim` | `false` | `true`（透明度 0.3）、`false` 或 0 至 1 的透明度；淡化目標以外的點 |

**`skip`**：目前場景立刻到達最終狀態，回 `done{state:"skipped"}`。
**`cancel`**（`instant` 可選）：取消場景，回到預設視角並取消發光，回 `done{state:"cancelled"}`；`instant:true` 不做動畫。

行為保證：

- 新場景取代舊場景（以收到順序為準）；舊場景立刻放棄、不留殘餘動畫，並回 `done{state:"interrupted"}`。
- 冪等：參數相同的 `scene` 連送兩次，效果等於一次。
- 視窗被隱藏（切分頁、縮到背景）時暫停；回到前景時直接跳到目前場景的終點。
- 視窗尺寸改變（旋轉、鍵盤）時重算偏移，點位維持在預期位置（誤差小於 2% 畫面）。
- 連續亂序送出 `scene`／`skip`／`cancel` 時，最後一個指令的終點狀態必定正確。

### 5.4 低階指令

| 指令 | 參數 | 說明 |
|---|---|---|
| `getState` | 無 | `done.result` 含 `project, engine, theme, spotCount, highlight, markers, dimmed`、目前視角（`center, zoom`）、`bearing, pitch, minZoom, maxZoom, bounds, moving` |
| `getSpots` | 無 | `done.result` 為與 `?api=spots` 相同格式的點位 |
| `resetView` | `duration` | 回到專案預設視角 |
| `setView` | `center, zoom, duration, easing` | `easing` 為 `linear`、`ease`、`easeInOut` |
| `flyTo` | `spotId` 或 `center`、`zoom, duration, curve, offset` | 飛到點位或座標 |
| `zoomAbout` | `by` 或 `to`、`duration`、`anchor` | `anchor` 為 `"view-center"` 或 `{x,y}` |
| `fitSpots` | `padding, duration, spotIds?` | 縮放到涵蓋指定（預設全部）點位 |
| `highlight` | `spotId \| null`、`style:"glow"`、`pulse`、`color?` | 持續脈動；`null` 取消 |
| `dimOthers` | `opacity, scale` | 淡化他點；`opacity:1, scale:1` 還原 |
| `setMarkers` | `mode: "all"\|"dots"\|"none"\|"only"`、`spotIds?` | 控制點位標記顯示 |
| `setTheme` | `theme` | `light`、`dark`、`auto` |
| `setLayer` | `layer` | 只能在專案已啟用的向量底圖圖層之間切換 |
| `setDisplay` | `spots`、`contributions`（至少一個布林） | 獨立切換點位與投稿顯示，兩個引擎皆支援；不修改專案設定。`getState` 的 `display` 回報目前值 |
| `setLabels` | `labels` | 布林，顯示或隱藏底圖文字標籤 |
| `snapshot` | `width, height, mime` | `mime` 為 `image/png`、`image/jpeg`、`image/webp`；`done.result` 為 `{dataUrl, width, height, mime}`；邊長上限 4096 |

`snapshot` 的已知限制：快照上的點位圓點疊圖不理會 `setMarkers`。

### 5.5 錯誤碼

| code | 說明 |
|---|---|
| `unknown_command` | 未知的 `type` |
| `bad_request` | 參數型別或範圍錯誤 |
| `unsupported` | 目前引擎不支援該指令 |
| `spot_not_found` | `spotId` 不存在 |
| `layer_not_found` | 圖層不存在或未啟用 |
| `snapshot_failed` | 畫布不可用（尚未就緒或被污染） |
| `busy` | 指令佇列已滿（上限 50） |
| `internal` | 內部錯誤 |

---

## 6. 導航小頁

```
GET <站台>?api=navsheet&project=<slug>&spot=<spotId|num>&embed=1[&theme=light|dark|auto][&lang=]
```

只輸出導航選單（Google 地圖、Apple 地圖僅 iOS、系統地圖 `geo:`、OpenStreetMap；選項清單與地圖頁相同），不帶起點、不做定位、無 session。外部連結以 `target="_blank" rel="noopener noreferrer"` 開啟。

- 專案或點位不存在：`404`（頁面內顯示找不到的訊息）；`POST` 等其他方法：`405`。成功時 `Cache-Control: public, max-age=60`。
- 使用者按關閉鈕或向下滑動收起時，頁面對父視窗送 `{v:1, ns:"souliong", type:"navsheet-close"}`，目標 origin 只取自允許清單。父頁據此關閉 modal 或 iframe。
- `embed=1` 且清單非空時送 `frame-ancestors`，規則與地圖頁相同。

---

## 7. 專案封面圖 `?api=cover`

`?api=cover` 提供專案封面圖，只作為靜態備援，不是即時地圖快照。

- 內容是管理者在後台擷取的 3D 視角圖，專案層級只有一張。
- 格式 webp，最長邊上限 960 px（站台設定 `cover_max_dim`）；**不能指定寬高**，需要其他尺寸請用 `snapshot` 指令。
- `Cache-Control: max-age=300`，無 ETag。
- 沒有點位層級或視角層級的備援圖。

---

## 8. 安全注意事項

- 資料 API 回傳的都是公開資料；允許清單不是授權機制，不要把它當成存取控制。
- 嵌入的允許清單預設為空；沒有設定的來源無法被嵌入、也收不到 `postMessage` 回覆。
- 父頁請驗證 `event.source` 與 `event.origin`，並指定 `targetOrigin`；不要使用 `"*"`。
- `snapshot` 的 `dataUrl` 是圖片資料，請當作圖片處理，不要插入為 HTML。
- 導航連結的外部網址一律 `noopener noreferrer`。
- 所有端點受速率限制；父頁輪詢資料 API 時請利用 `ETag`／`If-None-Match`，並尊重 `max-age=60`。

### 8.1 伺服器層標頭

嵌入頁會送 `frame-ancestors`，但網頁伺服器、代理或 CDN 可能另外加上 `X-Frame-Options`，可能使嵌入被擋。部署後請用 `curl -I` 檢查嵌入頁與導航小頁的回應，若有衝突，請在伺服器層調整嵌入用的路徑。

---

## 9. 檢查清單

- [ ] 在站台設定或後台專案設定填入父頁 origin。
- [ ] `curl -I` 確認沒有伺服器層的衝突標頭。
- [ ] 父頁用 `spotId` 而非 `num` 識別點位。
- [ ] 父頁訊息處理檢查 `event.source` 與 `event.origin`。
- [ ] 用 `tools/embed-demo.html` 手動驗證所有指令；`php tools/checkall.php` 含 `embedcheck` 自動檢查。

## 10. 投稿開放條件與嵌入投稿

所有投稿共用既有 `upload` 接口（建立點位走 `newspot`）、資料儲存與專案內容設定。投稿碼和免碼開放是兩種授權條件，可以並存：有效投稿碼可投稿，或免碼開放時不必輸入碼。免碼期間不扣投稿碼次數。照片、文字、音訊、影片由 `meta.contrib.kinds` 決定；建立點位仍由 `meta.contrib.newSpot` 決定，停權與唯讀設定照常生效。

後台「存取與權限」可設定開放投稿的啟用、開始與結束（台北時間）。開始留空代表立即，結束留空代表長期；取消啟用可隨時關閉。每一組投稿碼也可停用或重新啟用，保留原碼及使用次數。

資料欄位統一使用 `enabled`、`starts_at`、`expires_at`；時間為含時區 ISO 字串或 null，期間是 `[開始, 結束)`。需碼條件仍保存在 `codes.json`（另有 `code`、`max_uses`、`used_count` 等），專案免碼條件保存在 `meta.contributionAccess`，由同一個日期與啟用檢查函式判定。既有投稿碼缺少 `enabled` 時視為啟用，不另存鏡像資料。預設不開放免碼：

```json
{"contributionAccess":{"enabled":false,"starts_at":null,"expires_at":null}}
```

`GET ?api=contribstatus&project=demo` 提供免碼 `open`、`state`（disabled/scheduled/ended/open）、`starts_at`、`expires_at`、`serverTime`、`next_change_at`、`codesAvailable`、`kinds`、`newSpot`。POST 同一接口可帶 `project`、`code`、`owner`、`ctoken`，回傳 `codeValid`、`blocked`，不扣碼次數。`open` 專指免碼開放，需碼投稿另看 `codeValid`。回應不快取，不暴露投稿碼或身分秘密；跨網站依允許來源送 CORS。

### 嵌入投稿對話框

`ui=submit` 把地圖頁縮成只剩投稿對話框，等同網站上的投稿視窗，可投的型別（照片、影片、音訊、文字）由 `meta.contrib.kinds` 決定，不含建立點位。要只開放部分型別就加 `type`（逗號分隔），只能收窄專案已開放的型別：

```html
<iframe title="投稿" src="https://example.com/souliong/?p=demo&embed=1&ui=submit&type=photo"></iframe>
```

- 需要投稿碼：先跳出解鎖視窗；可加 `&code=123456` 帶入投稿碼自動解鎖。
- 免碼開放：直接顯示投稿對話框。
- 未開放（上傳模組關閉或沒有有效條件）：顯示「目前未開放投稿」。
- 父頁需先把來源 origin 加入允許嵌入來源。起始與完成時會對該 origin 送出 `{ v:1, ns:'souliong', type:'contribReady' }` 與 `{ …, type:'contribSubmitted', ok, fail }`。


### 後台投稿開放設定與期間歷史

後台將免碼開放與投稿碼整合為同一區塊。免碼可選長期、指定期間或預約時段，仍儲存原有 `contributionAccess.enabled / starts_at / expires_at`，不增加另一種投稿權限；指定期間須填結束時間，預約時段須填開始時間。免碼有效時投稿碼預設收合，仍可展開建立，重疊期間會提示不需要投稿碼。建立點位勾選沿用 `contrib.newSpot` 的 `contributor / off`；原有 `admin` 在未勾選時保留。此設定也適用投稿碼使用者。

「開放期間與歷史」分目前、預約及已結束／已取消。修改或關閉免碼設定時，將原期間保存於 `contributionAccessHistory`；停用或移除投稿碼時，將不含碼值的期間摘要保存於 `contributionCodeHistory`，各保留最近 100 筆。投稿碼摘要只保存時間、標籤及雜湊參照，不保存碼值。這些歷史欄位不參與授權判斷。新增立即開放的設定以展示用 `contributionAccessOpenedAt` 記下開始時間；舊設定未記錄的開始時間不推測。尚未開始即取消的預約顯示「已取消」，不視為曾開放。歷史為期間資料，不是操作稽核紀錄。

嵌入投稿視窗保持開啟，Escape 不會把投稿畫面關成空白。Markdown 說明是獨立的原生對話框，關閉後回到原投稿欄位；完整介面嵌入維持既有唯讀限制，bare 不載入投稿／建立點位外掛。
