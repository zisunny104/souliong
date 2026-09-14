# Souliong 部署與上線安全清單

Souliong 是 KoiLiSu 框架下的一個 app（`apps/souliong`）。純 PHP + 檔案儲存，零資料庫、零額外常駐服務。

## 一、上線前必做（安全關鍵）

### 1. ★ 擋掉 `projects/` 與 `state/` 的直接存取
`projects/<id>/` 裡有：投稿內容、**投稿代碼**（`codes.json`）、投稿者名冊、統計——**絕對不能被直接下載**（否則投稿代碼外洩＝門檻失效）；照片也在裡面，一律由 PHP（`?api=photo`）輸出，不應該被直接列目錄。
`state/` 裡有 admin PIN 清單（**明碼**存放，不是雜湊）與限流檔，同樣不能外流。

**這一步是整個部署裡最關鍵的一步。** 程式碼本身完全不會、也不能防這件事——擋人直接下載
`projects/`、`state/` 底下的檔案，是網頁伺服器（Nginx）的責任，不是 PHP 應用程式的責任。換句話說：
少了這段 Nginx 設定，任何人不用登入、不用密碼，直接在網址列打
`https://你的網域/.../projects/100chairs/codes.json`，瀏覽器就會把投稿代碼原原本本顯示出來
（因為 Nginx 預設看到「檔案存在」就會直接送出檔案內容，完全不會經過 `index.php`）。`state/pins.json`
同理，會把每個專案的管理密碼全部曝光。

在你的 Nginx **server 區塊**（跟 `listen`、`server_name`、`root` 同一層，通常是
`/etc/nginx/sites-available/你的網域` 或 `nginx.conf` 裡對應的 `server { ... }` 區塊）裡加入：
```nginx
# 不管框架用哪種路徑掛載（獨立部署 /souliong/、或框架下 /koilisu/apps/souliong/），
# 只要網址路徑裡出現 state/ 或 projects/ 這個資料夾名稱就一律擋掉，不依賴猜對確切路徑。
location ~ /(state|projects)/ { deny all; return 404; }
# 保險：擋掉 .jsonl 這類原始資料檔（正常照片一律走 ?api=photo，不會被這條擋到）
location ~ \.jsonl$ { deny all; return 404; }
```
把這段貼進去之後：
```bash
sudo nginx -t              # 先測設定檔語法有沒有錯，錯了不會重載，安全
sudo systemctl reload nginx   # 沒錯再套用（reload 不會中斷現有連線）
```
> **驗證（一定要實測，不要只看設定檔）**：瀏覽器開
> `https://你的網域/.../projects/100chairs/codes.json`，應該看到 403 Forbidden 或 404，
> 而不是投稿代碼的 JSON 內容。`state/pins.json` 也照樣測一次。

### 2. 改掉預設密鑰（`api/config.php`）
- `primary_pin` → 一組不易猜的 PIN（管理頁登入用；驗證後種 httpOnly cookie，PIN 不進網址）。
- `ip_salt` → 一組隨機字串（鑑識 IP 雜湊用）。

### 3. 反代 IP 設定
- `trust_forwarded => true`（位於 Nginx 反代後**必設**，否則所有訪客共用一個 IP，限流會誤判、統計也會塞住）。

### 4. 可寫目錄
`projects/`、`state/` 需 php-fpm 執行者可寫：
```bash
cd /你的路徑/apps/souliong
chown -R www-data:www-data projects state   # www-data 換成你的 php-fpm 使用者
chmod -R 775 projects state
```
只有在你要從後台匯入**全站**圖層或主題包時，`layers/`、`packs/` 才需要一併可寫（專案專屬的圖層落在 `projects/<id>/layers/`，已被上面涵蓋）。這兩個目錄進版控，不寫也不影響既有功能，所以預設可以維持唯讀。

### 5. 上傳大小
單檔上限由 `api/features.php` 的各投稿型別決定：照片 12 MB（`config` 的 `max_bytes`）、**影片 64 MB**、音訊 24 MB。伺服器端要放得比最大的那個型別寬：

Nginx：`client_max_body_size 70m;`　PHP：`upload_max_filesize=64M`、`post_max_size=68M`。

若不開放影片投稿，可依 `meta.json` 實際開放的型別調小。另外 PHP 的 `max_file_uploads` 請保持在 20 以上（預設值）——切圖磚工具一批送 16 張磚，調低會讓後面的磚被無聲丟掉。

### 6. 穩定後關 debug
`api/config.php` 的 `debug => false`（錯誤不再回傳內部細節）。

### 7. HTTPS
定位、相機、`crypto.subtle`（刪除判斷）都需安全情境。全站 HTTPS。

## 二、投稿代碼（限特定人上傳）

- 開不開放投稿：看這張地圖現在有沒有還有效的投稿代碼——有＝要碼才能投稿，一組都沒有＝除管理者外不能投稿。碼本身就是開關，不必改 `meta.json`。
- 碼存在 `projects/<id>/codes.json`（清單，不在 meta、前端拿不到）；可同時開多組碼，各自可選填到期時間／張數上限（留空即不限），用滿即失效。
- **管理**：管理頁「投稿代碼」區塊一碼一張卡，新增/刪除都在後台操作，刪除立即失效但不影響已投稿內容。
- **給組員**：把邀請連結 `.../<id>?code=XXXX` 傳給他們，點一次即在該裝置解鎖。

## 三、管理 / 審閱 / 分析

- 管理頁：`<base>/manager`（全部地圖總覽）、`<base>/manager/<mapid>`（單張地圖），輸入 PIN 或帳號密碼登入（POST，httpOnly cookie 保持登入，PIN／密碼皆不進網址）。分頁、備份與匯出都是這底下的路徑（`/manager/<mapid>/records`、`/manager/backup.zip`…），完整清單見 [EXTENDING.md](EXTENDING.md) 第九節。舊網址 `?api=admin`、`/<mapid>/manager` 仍然有效，開啟時會自動導向上面的形式。
  - **主 PIN**（`config.primary_pin`）：開所有專案。**專案 PIN**：只開該專案，由主 PIN 在後台新增/移除，可個別授權下列權限旗標（`perm_check`，預設關閉）：`delete_others`（刪別人的投稿）、`edit_others`（改別人的投稿）、`edit_points`（改定位點）、`grant_access`（可建立「管理PIN」型分享連結）、`edit_3d_regions`（編 3D 模型排除區域）。
  - 投稿者身分是**純自助**的：參與者用投稿代碼進地圖後，在解鎖視窗自行設 PIN 建立身分；後台「身分管理」只顯示/撤銷，不能代為建立，身分本身也不帶到期／次數（那是投稿代碼的事）。
  - 「管理 PIN」同樣是**純自助**的：後台只建立**邀請連結**（可設到期時間／兌換次數上限），收到連結的人自行輸入 PIN／暱稱兌換；兌換出來的身分預設無任何權限，需由主 PIN 事後逐項授權（`perm_check`）。連結的秘密只透過網址 fragment（`#redeem=...`）傳遞，不進伺服器紀錄。
  - 帳號（userid＋密碼）是 PIN 之外的另一種登入方式，自助註冊預設**關閉**（「工具」分頁的「開放自助註冊」開關），關閉時只能靠上述邀請連結建立；不論哪種方式建立，新帳號預設無任何專案權限，一樣要主 PIN 逐項授權。平時建議維持關閉，避免被灌帳號。
  - 投稿代碼／身分管理、看統計摘要、瀏覽與刪除投稿。
  - 每筆顯示 `owner`(同源可群組) 與 `src`(加鹽 IP 雜湊)：**同一 owner 出現多個不同 src ＝ 可能投稿代碼外流/冒名**，可據此界定污染範圍後刪除。
- 統計原始 JSON：`GET ?api=stat&project=<id>&read=1`（需已用管理 PIN 登入）

## 四、CSP（若你日後強制執行）
本站目前依賴的外部來源（report-only 下可用；強制執行需放行）：
```
script-src  'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;
style-src   'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com;
img-src     'self' data: blob: https://*.basemaps.cartocdn.com;
media-src   'self' blob:;
connect-src 'self' https://tiles.openfreemap.org;  # 後者只有啟用 3D／向量底圖模式才需要，來源看 map3d_style_url
font-src    https://cdnjs.cloudflare.com;
worker-src  blob:;
```

## 五、備份
只要備份 `apps/souliong/projects/`（每個專案的全部資料、照片、影音與專案專屬圖層）與 `apps/souliong/state/`（PIN 清單）兩個資料夾即為全部使用者資料；後台的「全部專案備份（ZIP）」打包的也是這兩個（`state/` 在 ZIP 裡的路徑沿用舊名 `data/`，匯入端認得）。

> 例外：從後台匯入的**全站**圖層與主題包落在 `layers/`、`packs/`，不在上面兩個資料夾裡。這兩個目錄進版控，所以正常做法是把匯入的成果一併 commit，而不是靠備份。

> 若你是從舊版（`data/` + `photos/` + `projects/` 三資料夾並存）升級：這是有資料位置破壞性的變動，新舊程式碼與新舊資料夾結構不能混用。升級時需把舊的 `data/<id>.jsonl`／`<id>.code.txt`／`<id>.contrib.json`／`<id>.stats.json` 與 `photos/<id>/` 併入 `projects/<id>/`（分別改名為 `data.jsonl`／`code.txt`／`contrib.json`／`stats.json`／`photos/`），再把只剩 `admin_pins.json`、`.rate/` 的舊 `data/` 改名為 `state/`。放進去的 `code.txt` 不用手動轉檔——後端第一次讀取投稿代碼時會自動把它併入 `codes.json`（不限期不限次數那筆）並刪除 `code.txt`，舊碼不會失效。

## 六、韌性說明
- 無狀態：每個請求獨立，php-fpm worker 崩潰會自動被取代，不需手動「重新上線」。
- 檔案儲存以 `flock` 加鎖、逐行解析且跳過壞行：單一壞行不會弄垮整個資料。
- 限流：檔案式滑動視窗（`rate_max`/`rate_window`），攻擊時擋下寫入；限流自身失敗時「放行」而非拒服務。
- 進階：可在 Nginx 加 `limit_req`、或用 fail2ban 針對 429/404 掃描來源封鎖。
