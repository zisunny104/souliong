# 無障礙檢核

訪客地圖提供主內容與專案標題語意。收起的點位面板使用 inert 與 aria-hidden，避免鍵盤進入不可見內容；關閉面板時將焦點移回觸發位置。分類可使用 Enter／空白鍵切換，並提供選取狀態。

照片預覽具有按鈕語意；精選星章具有圖片語意與輔助名稱。投稿 Markdown 標題以 aria-level 整理點位面板與燈箱中的閱讀層級。頁面允許瀏覽器縮放。

## 驗證

使用 Chromium、axe-core 與獨立暫存專案，在 390／1280 像素檢查地圖與投稿面板。分類鍵盤操作、收起面板、關閉焦點、精選星章及 Markdown 標題語意均已納入 tools/displaycheck.js。

```bash
AXE_PATH=/path/to/axe-core/axe.min.js node tools/displaycheck.js
```

尚待實機使用 NVDA、VoiceOver／TalkBack 確認宣告，以及正式嵌入視窗內外的焦點操作。自訂圖磚、類別配色、投稿內容及照片替代文字仍需依實際素材檢查；自動通過不代表全面符合 WCAG。

## 全站頁面與後台檢核

`tools/accessibilitycheck.js` 使用隔離專案，在 390／1280 像素檢查首頁、隱私頁、PIN／帳號登入、訪客地圖、點位面板、投稿、建立點位、Markdown 說明、投稿嵌入及純地圖嵌入。後台涵蓋總覽、專案、投稿權限、紀錄、工具頁，以及展開的設定內容和專案設定／圖磚／封面／地標圖片／樣式包視窗。

登入方式切換可使用鍵盤 Enter。投稿與建立點位另以 `tools/contributionuicheck.js` 驗證焦點、表單順序及送出流程。

```bash
AXE_PATH=/path/to/axe-core/axe.min.js node tools/accessibilitycheck.js
node tools/contributionuicheck.js
```

測試以模擬地圖引擎隔離外部網路，因此未驗證正式圖磚、外部字型、真實素材及各瀏覽器的地圖操作；輔助科技與正式嵌入仍須依上述實機項目確認。結果不是全站 WCAG 認證。

登入流程會在同一分頁以 sessionStorage 暫存 PIN／帳號選擇，適用於全站與專案後台。暫存只有登入方式，不包含帳號、密碼或 PIN；登入失敗回應保留該次帳號欄位，密碼不回填。另一種登入方式的欄位停用，不會混入送出的憑證。切換會更新提示並將焦點移至有效欄位，錯誤訊息提供 alert 語意及淺色背景的足夠對比。

檢核包含 Enter 切換、帳號與 PIN 的實際錯誤回應、錯誤後重整、切換至專案登入及手機橫向溢出檢查。
