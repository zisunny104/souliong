# 隱私與資料說明 · Privacy &amp; Data Notice

_最後更新 / Last updated: 2026-09_

> 站內版本：`<base>/privacy`（由 `pages/privacy.php` 直接算繪本檔，含下列所有段落）。
> On-site version at `<base>/privacy` renders this file directly, including everything below.

我們盡量少收資料、以去識別方式處理，且不使用第三方追蹤或廣告。
We collect as little as possible, keep it de-identified, and use no third-party tracking or ads.

---
<!-- site:content -->

## 存在你裝置上的資料 / Stored on your device

以瀏覽器 `localStorage` 儲存（僅在你的裝置，可自行清除）：主題偏好、你輸入的暱稱、投稿權限（解鎖後記住的投稿代碼）、一個**有期限**的「擁有者標記」（用來讓你在原裝置刪除自己的投稿），以及——若你自選了 PIN 建立可跨裝置延續的投稿者身分——一組由該 PIN 衍生的識別權杖（可用來在其他裝置編輯/刪除同一身分投稿過的內容；若此身分是由分享連結建立，管理者可為其設到期時間或使用次數上限，僅影響「新增投稿」，不影響既有內容的編輯/刪除）。

公開地圖頁**不設 Cookie**；只有後台登入（PIN 或帳號）使用功能性的 `httpOnly` Cookie 維持登入，並非追蹤用途。

> Kept in `localStorage` (your device only): theme, nickname, the contribution code once unlocked, a time-limited “owner marker” to let you delete your own posts from this device, and — if you chose a PIN to create a portable, cross-device contributor identity — a derived token for that identity (lets you edit/delete that identity's posts from another device; if the identity was created via a share link, an admin may set an expiry or use-count limit on it, which only affects new posts, never editing/deleting existing ones). The public map sets **no cookies**; only admin login (PIN or account) uses functional httpOnly cookies — not for tracking.

## 伺服器記錄的資料 / Recorded on the server

- **投稿內容 / Posts**：照片、影片、音訊、文字、暱稱、時間、你選擇的座標——公開顯示於地圖。Photo, video, audio, text, nickname, time, chosen location — shown publicly.
- **匿名統計 / Aggregate stats**：瀏覽、工作階段、裝置類別、功能使用、相機型號等彙總數字，**不含個人身分**。Non-identifying counts only.
- **防冒名來源標記 / Abuse-forensics marker**：一段**加鹽雜湊**後的來源標記，經去識別、**無法還原成 IP**，僅供管理者鑑識，不對外顯示。A salted, de-identified source hash; never shown, not reversible to an IP.

上傳時照片會轉存為 WebP，過程**移除原始 EXIF**（僅另存我們讀到的拍攝時間、座標，以及相機廠牌/型號/鏡頭/軟體、光圈、快門、ISO、焦距等有限的拍攝參數欄位；不含機身序號等可唯一識別裝置的資訊）。
On upload, photos are re-encoded to WebP and **original EXIF is stripped** (only shot time, coordinates, and a limited set of shooting parameters are kept — camera make/model/lens/software, aperture, shutter speed, ISO, focal length; no device serial number or other uniquely-identifying fields).

影片與音訊**依原檔保存、不重新編碼**，因此檔案內原有的中繼資料（部分手機會寫入拍攝地點與機型）也會一起留在伺服器上。在網頁上直接錄下的聲音不含這些欄位；若你上傳的是相簿裡的既有檔案且介意這件事，請先自行清除中繼資料再上傳。
Video and audio are kept **exactly as uploaded — we do not re-encode them**, so any metadata already inside the file (some phones embed location and device model) stays on the server too. Audio recorded in the browser contains none of this; if you upload an existing file from your gallery and this matters to you, strip its metadata first.

## 刪除與內容處理 / Deletion &amp; takedown

投稿者可在**當初上傳的裝置**上、於擁有者標記的**有效期限內**自行移除自己的投稿。逾期、或發現不當、疑似侵權的內容，可向網站管理者反映協助移除。
Contributors can remove their own posts from the original device while the owner marker is valid. For expired posts or inappropriate/infringing content, ask the site administrator to remove it.

本服務不針對兒童設計；請勿上傳可識別未成年人或敏感個資的內容。
This service is not directed at children; do not upload content identifying minors or sensitive personal data.

## 第三方 / Third parties

地圖以 MapLibre GL JS 顯示，預設向量圖磚來自 openfreemap.org，圖資 © OpenStreetMap 貢獻者（ODbL）；改用光柵底圖的地圖以 Leaflet 顯示。管理者在切圖工具選取範圍時，預覽底圖會向 tile.openstreetmap.org 取圖磚。封存的 CARTO 圖層若被選用會連線 basemaps.cartocdn.com，圖磚 © CARTO。QR 在你的瀏覽器本機產生。個別地圖可能改用其他圖磚來源或自繪疊圖，實際來源標示在地圖的圖資出處列。皆用於顯示功能，非廣告或追蹤。授權詳見 [LICENSE](https://github.com/zisunny104/souliong/blob/main/LICENSE)。
Maps render via MapLibre GL JS with vector tiles from openfreemap.org by default, data © OpenStreetMap contributors (ODbL); maps using a raster base map render via Leaflet instead. When an admin selects an area in the tile-cutting tool, its preview map fetches tiles from tile.openstreetmap.org. Archived CARTO layers, if selected, connect to basemaps.cartocdn.com (tiles © CARTO). QR generated locally in your browser. Individual maps may use other tile sources or hand-drawn overlays — the actual source is credited in the map's attribution line. For display only. See [LICENSE](https://github.com/zisunny104/souliong/blob/main/LICENSE).

---

## 投稿條款 / Contribution terms

1. 你保證對上傳內容擁有合法權利，且不侵害他人著作權、肖像權或隱私。
   You warrant you have the rights to what you upload and infringe no one's copyright, likeness, or privacy.
2. 你同意上傳內容以**公開、非專屬**方式在本平台展示。授權方式在投稿當下決定：**預設為 CC0**（公眾領域貢獻，等同不再主張著作權）；若你已建立投稿者身分，可在投稿視窗勾選改為 **CC BY**——著作權仍屬你本人，他人引用時須標示你的暱稱。
   You agree your content is shown publicly and non-exclusively. The licence is chosen at upload time: **CC0 by default** (public domain dedication); if you have created a contributor identity, you may tick the box in the upload dialog to use **CC BY** instead — copyright remains yours and reusers must credit your nickname.
3. 禁止上傳違法、仇恨、猥褻、廣告或含他人敏感個資之內容。
   No illegal, hateful, obscene, advertising, or sensitive-personal-data content.
4. 站方得於必要時移除不當內容或更換投稿代碼。
   The site may remove inappropriate content or rotate the contribution code when necessary.
5. 本服務按「現狀」提供，不保證不中斷或無錯誤。
   The service is provided "as is", without warranty of uninterrupted or error-free operation.

---
© 2026 prjToka ・ 程式碼採 MIT 授權 / Code under MIT.
