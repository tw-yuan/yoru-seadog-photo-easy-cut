# MEMORY.md

## 本次聊天摘要

使用者要求「開始照 `project.md` 做事」。當時 repo 只有 `project.md`。已從零建立 MVP：

- `api.php`
- `index.php`
- `manifest.jsonl`
- `origin/ working/ tmp/ yoru/ seadog/ finish/ error/ skipped/`

後續使用者提醒：「如果圖片內只有一個人，或裁完之後發現只有一個人能用」。因此新增單張輸出流程，並更新 `project.md`。

最後使用者要求整理聊天內容成 `AGENTS.md` 和 `MEMORY.md`。

## 已完成的實作

### 後端 `api.php`

已實作 action：

- `health`
- `get_image`
- `image`
- `submit`
- `undo`
- `skip`
- `move_error`

重要能力：

- 匿名 session cookie。
- `manifest.jsonl` append-only log。
- 目錄 / GD / EXIF / 權限健康檢查。
- 從 `origin/` 原子 rename 到 `working/` 領圖。
- 透過 `api.php?action=image&job_id=...` 串流圖片。
- JPEG EXIF orientation 正規化，避免前後端座標不一致。
- PHP GD 多邊形遮罩裁切，輸出透明 PNG。
- `tmp/` 暫存後 rename 到 `yoru/` 或 `seadog/`。
- 成功送出後原圖移到 `finish/`。
- 失敗時清理暫存和半完成輸出，保留 working 原圖。
- `undo` 支援成對輸出與單張輸出。
- `skip` 和 `move_error`。

### 前端 `index.php`

已實作：

- Canvas 顯示圖片。
- DPR / CSS pixel / 圖片像素座標換算。
- p1/p2 控制點。
- 拖曳控制點改變切割線。
- 拖曳線段平移。
- Shift 拖曳吸附水平、垂直或 45 度。
- A/B 遮罩與大字標籤。
- A/B 裁切預覽。
- 成對送出按鈕：
  - `A: YORU / B: SEADOG`
  - `A: SEADOG / B: YORU`
- 單張輸出按鈕：
  - `整張: YORU`
  - `整張: SEADOG`
  - `只留 A: YORU`
  - `只留 A: SEADOG`
  - `只留 B: YORU`
  - `只留 B: SEADOG`
- 復原上一張。
- 跳過。
- 圖片載入失敗時提供重試、跳過、移至問題檔。
- 快捷鍵：R、Z、Esc、Y、S、Space、方向鍵。
- 送出中鎖定按鈕，避免重複請求。

### `project.md`

已新增「3.1 單人照與單邊可用」：

- `single_full`
- `single_a`
- `single_b`
- manifest 記錄 `submit_mode`、`single_label`、`single_region`、`output_single`
- undo 要支援單張輸出

## 目前工作區狀態

已新增或修改：

- `api.php`
- `index.php`
- `manifest.jsonl`
- `project.md`
- `AGENTS.md`
- `MEMORY.md`

已建立資料夾並設為 `775`：

- `origin/`
- `working/`
- `tmp/`
- `yoru/`
- `seadog/`
- `finish/`
- `error/`
- `skipped/`

`manifest.jsonl` 已建立並設為 `664`。

## 已驗證

此容器有 Node.js，可以檢查前端 script。已用以下方式通過 JS 語法檢查：

```bash
perl -0777 -ne 'print $1 if /<script>(.*)<\/script>/s' index.php | node --check -
```

## 尚未驗證

此容器沒有 `php` 指令，因此沒有跑過：

```bash
php -l api.php
php -l index.php
```

也沒有實際啟動 PHP server 或跑 GD 裁切。使用者表示可在 dev env 驗證。

## 建議下一步

在 dev env 執行：

```bash
php -l api.php
php -l index.php
php -S 127.0.0.1:8000
```

再做手動驗收：

- 放圖進 `origin/`。
- 開 `http://127.0.0.1:8000/index.php`。
- 測雙人 A/B 成對輸出。
- 測單人整張輸出。
- 測只留 A / 只留 B。
- 測 undo。
- 測 skip。
- 測 move_error。
- 檢查 `manifest.jsonl` 事件內容。

若 PHP lint 或實測出錯，優先修 `api.php`，尤其是 GD 函式簽名、EXIF orientation、`imagefilledpolygon()` 參數與檔案 rename 失敗路徑。
