# 資安檢核與修補（2026-10-03）

基準 main commit：`30da2513f7539f4f9630e8594f1119b0fea2e2b1`，tree：`27b0d7007001365b8e07cd5f6b242ef7b5607263`。
僅使用雲端本機與合成資料；沒有正式服務侵入測試、部署或 main 合併。

## 已確認並修補

| 問題 | 證據與修補 | 回歸 |
|---|---|---|
| 管理免碼投稿缺少 CSRF | contrib_gate 原先 Auth::can(bypass_code) 後直接返回；改用 Auth::require(...,true)，submitContribution 帶 APP.csrf | PIN、primary cookie、account 在 upload 與 contributor newspot 的有效／缺少／錯誤 CSRF；匿名有效碼無 CSRF 可用 |
| 圖層署名儲存型 HTML 注入 | manager layeredit 經 edit_layers（專案）／manage_layers（全站）可寫 attribution；JS 把字串與 suffix 直接輸出成 HTML | 字串、suffix、翻譯內容 escape；前後端僅允許 HTTP(S) 署名連結；惡意 scheme 不產生 anchor |
| 唯讀地圖仍可直接投稿 | features.upload=false 原先只在 newspot 擋；upload 沒有後端關卡 | upload 在消耗代碼前拒絕；匿名、PIN、primary、account 均被擋，newspot 也驗證 |
| 限次碼並行超用 | code_check 原先先讀、後以 LOCK_EX 寫，並未鎖住整段檢查／累加 | 同一檔案鎖內讀、判斷、累加；24 個並行請求對 max_uses=1 僅 1 個成功 |

Cookie 使用 SameSite=Lax，因此不能描述為任意跨站 POST 都能觸發 CSRF。實際攻擊条件包含攻擊者掌控同站不同源（例如同一註冊網域的另一子網域）且管理 cookie 會送往目標；CSRF 修補將免碼寫入綁定到管理憑證。

相容性：內建 layers 皆使用結構化 attribution。歷史字串 HTML 改為純文字顯示，不執行 HTML；要保留連結，改為 `[{"text":"來源","url":"https://example.org","copyright":true,"suffix":"貢獻者"}]`。不保留任意 HTML 白名單。PHP 的 suffix 原本已 escape，現在 JS 與它一致。管理編輯及匯入的正常權限不變。

## 執行證據

雲端執行 PHP 8.3.6、Node；僅建立未版控的 api/config.php 測試秘密、state/ 與 projects/_packdemo/meta.json 最小 fixture。未執行 deploy.sh（它會更新部署）。

`php tools/checkall.php`：75 個 PHP 語法檢查、authlint、authcheck（1630 項）、contentcheck（50 項）、embedcheck、securitycheck、26 個 Node 語法檢查、securitycheck.js 全部通過。

在未修補 main 的獨立 worktree 套用新回歸檢查：authcheck 出現 16 個預期失敗（12 個 CSRF、4 個唯讀關卡）；JS 檢查明確重現未 escape 的 `<img ...>`。這些證明測試能區分修補前後。並行測試驗證修補結果，沒有宣稱每次都能在舊版重現競態。

執行 PHP 時 display_errors=Off：PHP 內建伺服器在 post_max_size 前置警告若顯示於回應會干擾 contentcheck 的狀態解析。正式部署也應關閉錯誤顯示。

## 其他掃描與限制

- layerfile 使用保守路徑字元、拒絕 `..`、realpath 範圍檢查；manager ZIP 匯入拒絕 `..` 並依用途白名單路徑／副檔名，ZIP 有解壓大小限制。這次快速檢核未實證額外路徑穿越，不等同完整 ZIP fuzzing。
- manager layerTarget 經權限與 CSRF gate；點位操作以 edit_spots 把關；view 的內嵌 JSON 使用 JSON_HEX_TAG。仍有眾多 DOM sinks，未完成所有互動的瀏覽器動態驗證。
- trust_forwarded=true 會採用 X-Forwarded-For 第一個 IP；只能在可信反代覆寫該 header 且阻止繞過反代時開啟。否則限流 IP 可以偽造。正式配置未提供，無法確認部署狀況。
- 資料、設定與原稿直接下載防護依賴 Nginx；docs/DEPLOY.md 的設定是否實際使用，不能由 repo 判定。
- 前端存在未鎖版本的 exifr CDN 載入與未設定 SRI 的外部套件；屬供應鏈強化事項，沒有將其列為已確認套件 CVE。本次未查到完整套件通報／未執行依賴漏洞資料庫掃描。
- 限次碼修補保障並行 code_check 消耗；管理端同時編輯整份 codes 的其他 read-modify-write 流程仍需後續整合交易式更新。檔案鎖依賴底層檔案系統支援 flock；無法聲稱支援跨機共享 NFS 的相同保障。
