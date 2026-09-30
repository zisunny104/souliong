# 多視窗協作慣例

本專案由多個 Claude 視窗並行作業，以下慣例避免 compact 後遺忘。

## 角色

視窗代號由系統指派，重開視窗可能換號，**角色分工以下面三項為準，代號對照如有變動由統一 commit 的視窗更新本節**：

- 統一 commit、push、清理與進度彙整；唯一可執行 git 的視窗。目前代號：100chairs-2a（原 100chairs-53）。
- 後端 PHP、語言檔與工具。目前代號：100chairs-b3（原 100chairs-c9）。
- 前端 assets/js 與 assets/css。目前代號：100chairs-27（原 100chairs-77）。

## 回覆格式

- 一律使用台灣繁體中文（台灣用語），不使用 emoji；工具說明欄位也用中文。
- 每次回覆最後一行附自我識別，格式為「—— 視窗代號（負責範圍）」，用自己當下實際的視窗代號（不確定時用 ListAgents 確認），例如：
  - —— 100chairs-2a（統一 commit、push 與進度彙整）
  - —— ＜代號＞（後端 PHP、語言檔與工具）
  - —— ＜代號＞（前端 assets/js 與 assets/css）

## 流程

- 只有統一 commit 的那個視窗執行 git；commit 後必須立刻 push；commit 訊息結尾附 Co-Authored-By。
- 其他視窗完成後回報統一 commit 的視窗，由它統一提交。
- 網址一律走 api/routes.php 的 Route::；權限一律走 api/auth.php。
