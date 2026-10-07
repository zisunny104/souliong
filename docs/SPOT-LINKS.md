# 點位連結

點位面板的編輯功能可維護多筆連結。按「新增連結」填寫 HTTP／HTTPS 網址，預設使用一般連結圖示，也可選 Facebook、Instagram、LINE 或 Threads。每種圖示可重複使用，最多 30 筆。儲存會同時保存座標與連結；取消不會寫入變更。

有網址的連結以 Font Awesome 圖示按鈕顯示於導航旁，並在新分頁開啟。空白輸入列不儲存；移除所有連結後儲存可清空。沒有連結的既有點位維持原樣。

資料使用點位版本既有的 `links` 欄位，格式為 `[{"url":"https://example.com","icon":"link"}]`。可用 icon 為 `link`、`facebook`、`instagram`、`line`、`threads`。`editspot` 請求未提供 `links` 時保留原值，提供 `[]` 時清空。伺服器驗證網址與圖示白名單，不接受帶帳密網址或任意 HTML／CSS。

權限沿用 `edit_spots` 與 CSRF 驗證，不改投稿資料或實際專案設定。驗證：`php tools/spotlinkscheck.php` 及 `node tools/displaycheck.js`（需 Playwright）。
