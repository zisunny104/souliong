# 未完成事項追蹤

由 100chairs-53 彙整維護（唯一寫入者）。各 session 完成或新增項目時回報給 53 更新。全部完成後刪除本檔。
最後更新：2026-09-20

## 進行中的設計原則（已由使用者定案）

- 點位說明是點位原生 `content`：有序區塊陣列（text 含 Markdown、audio，之後可加 photo），沒有 story 欄位、沒有 kind:'desc'。
- 內容區是一個整體：編輯即整體編輯，一次儲存 = 一個版本 = 一位編輯者；署名與時間只顯示一次（最新版本）。
- 訪客文字投稿留在底部投稿區，同樣支援 Markdown。
- 既有多來源內容合併成單一內容區，操作者記為「系統合併」（tools/content_migrate.php）。
- 投稿聲音的播放鍵預設不脈衝，只有由分享連結（?entry=）進入時才出現。

## 待提交（尚未 commit）

- 權限 Phase B（c9）：cover、delete、editentry、tilecut 等，其餘見下。
- A2 後端（c9，已通過 php -l 與 authcheck）＋前端整體編輯器（77，已通過 node --check）＋tools/content_migrate.php，需一起提交。
- 等 c9：lang 補齊（見下）與 op=save 冒煙結果，回報後 53 統一 commit + push。

## 待辦清單

| 項目 | 負責 | 狀態 | 下一步 |
|---|---|---|---|
| lang：11 個 content_* 鍵（zh_TW+en）、en 補 8 個舊鍵、刪 edit_story_btn／record_sound_btn | c9 | 已派工 | 補完回報；使用者已回報 content_add_text 顯示原始鍵 |
| spotcontent op=save 冒煙（新增、409、清空、相同不寫版本、html） | c9 | 已派工 | 一行回報結果 |
| 權限 Phase B：region3d、exiffix、layermigrate、spotmigrate、thumbfix、stat、manager（最後含 bypass_code 開關） | c9 | 進行中 | authlint 目標 0 違規（目前 55） |
| spotmigrate_run／newspot 新建區塊移除 name/created_at/actor | c9 | 已派工 | — |
| stat 的 story／sound 鍵改 content | c9 | 已派工 | — |
| docs/EXTENDING.md：3.5 text vs desc、3.6 內容區塊、第 17／29／136／246-253 行舊敘述 | c9 | 已派工 | A2 落地後改寫 |
| oglib OG 文字目前是原始 Markdown | c9 | 遺留 | 改用去除 Markdown 的純文字 |
| 沒有 id 的舊區塊在 op=save 會被視為刪除 | 53 | 遺留 | 部署後先跑 content_migrate --apply（見下） |
| contribution.js 依 APP.contrib.kinds 過濾投稿分頁（kind-audio.js 被 view.php 額外載入後） | 77 | 待確認 | 確認 audio 不會出現在投稿分頁 |
| 草稿只存記憶體，整頁重載會消失 | 77 | 已知限制 | 視需要再做 localStorage 草稿 |
| 點位自己的聲音區塊沒有分享連結格式 | 77 | 未做 | 需要時再定連結格式 |
| 歷史版本鈕只列不還原 | 77 | 未做 | 需要時再做還原 |
| 瀏覽器實測整體編輯器寫入路徑 | 53+77 | 待後端落地 | 提交前後各跑一次 _packdemo |
| tools/content_migrate.php 多人多版本合併測試（本機無 desc 資料） | 53 | 待做 | 用 scratchpad 合成專案測試 |
| content_migrate --apply 到真實資料 | 53 | 等使用者確認 | 先備份；各環境部署後立即跑 |
| viewer.core.js 是否拆分 | 53 | 暫緩 | 之後決定 |
| docs/auth-unification.md 更新（op=save、content_rev、系統合併、投稿 MD）後刪除 | 53 | 待做 | 全部完成時刪 |
| 記憶更新：content 區塊、整體單一編輯者原則、MD、統一 Auth | 53 | 待做 | 完成後寫入 memory |
| tools/soundcontent_migrate.php | 53 | 已刪除（由 content_migrate 取代） | 隨下次提交 |

## 部署注意

1. 部署後每個專案先 `php tools/content_migrate.php projects/<p>` 預覽，確認後加 `--apply`（會先備份）。
2. 未跑 content_migrate 前，沒有 id 的舊區塊在前端整體儲存時會被刪掉。

## 前端（77）回報的未完成項目

後端 A2（op=save、list.php html、newspot description、view.php 載入）已由 c9 落地，77 的 A/D 類項目現在都可實測；lang 仍待 c9。

實測（等 lang 補齊與 c9 冒煙後，53+77 用 _packdemo 做，測完還原 spots.jsonl）：
- 整體儲存：新舊區塊混合、media_N 上傳、409、回應 item 更新畫面。
- 投稿牆文字 Markdown 卡片顯示（.sc-md）。
- kind-newspot 送 description。
- 手機寬度與深色模式下編輯器樣式（Playwright）。

需使用者或 53 決定：
| 項目 | 現況 | 選項 |
|---|---|---|
| 草稿只存記憶體，整頁重載即消失 | 已知限制 | 接受／存 localStorage（不含 Blob） |
| 換點位再回來草稿保留但沿用舊 base_rev，存檔才 409 | 刻意設計 | 加「有未儲存草稿」提示 |
| 409 只提示，無「載入最新並保留草稿」 | 依規格 | 是否要做合併 |
| beforeunload 離頁警告 | 未做 | 可加 |
| 歷史版本還原 | 只列不還原 | 需後端還原 op，或以舊版 blocks 再存一版 |
| 點位自己的聲音區塊分享連結 | 無網址格式 | 先定格式（例：?spot=&block=）再做深連結與脈衝 |

77 自行處理（低優先）：
- 錄音進行中畫面重繪時麥克風不會停（SLAudioTools.buildRecorder 需回傳可 stop 的控制）。
- 新增聲音的大小／長度前端預檢。
- 無障礙（鍵盤排序、aria-live）。
- 文字區塊即時預覽、拖曳排序（目前刻意不做）。
- 投稿端與點位聲音區塊的錄音卡片樣式各一份，可再合併。
- 既有聲音區塊的來源／授權附註不能改（符合規格，接受）。

## 回報紀錄

- 77 已回報（見上）；c9 尚未回報清單。
