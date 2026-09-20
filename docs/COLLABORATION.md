# 多視窗協作慣例

本專案由多個 Claude 視窗並行作業，以下慣例避免 compact 後遺忘。

## 角色

- 100chairs-53：統一 commit、push、清理與進度彙整；唯一可執行 git 的視窗。
- 100chairs-c9：後端 PHP、語言檔與工具。
- 100chairs-77：前端 assets/js 與 assets/css。

## 回覆格式

- 一律使用台灣繁體中文（台灣用語），不使用 emoji；工具說明欄位也用中文。
- 每次回覆最後一行附自我識別，格式為「—— 視窗名稱（負責範圍）」，例如：
  - —— 100chairs-53（統一 commit、push 與進度彙整）
  - —— 100chairs-c9（後端 PHP、語言檔與工具）
  - —— 100chairs-77（前端 assets/js 與 assets/css）

## 流程

- 只有 53 執行 git；commit 後必須立刻 push；commit 訊息結尾附 Co-Authored-By。
- 其他視窗完成後回報 53，由 53 統一提交。
- 網址一律走 api/routes.php 的 Route::；權限一律走 api/auth.php。
