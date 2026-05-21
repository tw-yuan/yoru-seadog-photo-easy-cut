# 照片裁切標記工具 — 專案規劃書

## 專案概述

一個單頁 PHP 網頁應用程式，讓使用者對原始合照逐張進行斜線裁切，並將裁切後的兩塊分別標記為 `yoru` 或 `seadog`。系統需支援批次標註、可復原、防重複送出、操作紀錄與錯誤追蹤。

本專案以單人本機使用為優先，但規格需避免多分頁、重新整理或部分寫檔失敗造成資料污染。

---

## 核心設計原則

1. **原始照片不可丟失**：原圖只在成功產生 A/B 輸出後移入 `finish/`。
2. **每次操作必須可追蹤**：所有領圖、送出、復原、跳過、失敗都寫入 append-only log。
3. **檔案寫入必須接近交易式**：先寫入 `tmp/`，驗證成功後再原子性 `rename()` 到正式目錄。
4. **不可只靠資料夾推斷狀態**：資料夾代表目前位置，`manifest.jsonl` 才是操作歷史。
5. **A/B 定義必須由幾何規則決定**：不能只用「左/上」這種會隨角度混淆的描述。
6. **送出前要降低誤標機率**：UI 需明確顯示 A/B 標籤、完整配對按鈕與裁切預覽。

---

## 資料夾結構

```
/project-root/
├── index.php               # 主頁面（前端 UI）
├── api.php                 # 後端 API（領圖、裁切、移動、復原、跳過）
├── manifest.jsonl          # append-only 操作紀錄
├── origin/                 # 尚未領取的原始合照（手動上傳）
├── working/                # 已被領取、等待送出或復原的照片
├── tmp/                    # 裁切輸出暫存區，成功後才 rename 到正式資料夾
├── yoru/                   # 裁切後標記為 yoru 的照片
├── seadog/                 # 裁切後標記為 seadog 的照片
├── finish/                 # 處理完畢的原始照片存檔
├── error/                  # 無法載入、驗證失敗或裁切失敗的問題照片
└── skipped/                # 使用者跳過、稍後處理的照片
```

> 所有資料夾需手動建立並設定 PHP process 可讀寫權限（建議 chmod 775）。首頁需做環境自檢，明確顯示缺少的資料夾、不可寫的目錄、GD 是否啟用。

---

## 工作狀態模型

圖片狀態以檔案位置加上 `manifest.jsonl` 事件記錄管理：

| 狀態 | 位置 | 說明 |
|------|------|------|
| `queued` | `origin/` | 尚未領取 |
| `working` | `working/` | 已由 `get_image` 領取，有 `job_id` |
| `submitted` | `finish/` + `yoru/` / `seadog/` | A/B 成功輸出，原圖歸檔 |
| `skipped` | `skipped/` | 使用者跳過，稍後處理 |
| `failed` | `error/` 或保留 `working/` | 驗證、載入或裁切失敗 |
| `undone` | `origin/` 或 `working/` | 使用者復原上一筆送出 |

`manifest.jsonl` 每行是一筆 JSON，不覆寫舊紀錄：

```json
{"event":"submit","job_id":"20260521-120001-a1b2c3","source_filename":"photo001.jpg","source_checksum":"...","x1":320,"y1":0,"x2":280,"y2":600,"a_label":"yoru","b_label":"seadog","output_a":"yoru/photo001__20260521-120001-a1b2c3_A.png","output_b":"seadog/photo001__20260521-120001-a1b2c3_B.png","created_at":"2026-05-21T12:00:01Z"}
```

最低限度需記錄：

- `event`：`lock`、`submit`、`undo`、`skip`、`fail`
- `job_id`
- `source_filename`
- `source_checksum`
- `x1`, `y1`, `x2`, `y2`
- `a_label`, `b_label`
- `output_a`, `output_b`
- `error_code`, `message`（失敗時）
- `created_at`
- `session_id`（可由 cookie 產生匿名 session）

---

## 功能規格

### 1. 領圖與鎖定

- 進入網頁時，前端呼叫 `api.php?action=get_image`。
- 後端從 `origin/` 選取一張圖片（支援 jpg、jpeg、png、webp），產生 `job_id`。
- 後端用 `rename()` 將檔案從 `origin/{filename}` 原子移動到 `working/{job_id}__{filename}`，視為領圖鎖定。
- API 回傳 `job_id`、原始檔名、圖片尺寸、圖片讀取 URL、目前統計。
- 若 `origin/` 已空且沒有可回收的 `working/`，顯示「所有照片已處理完畢」畫面。
- 若使用者中途關閉頁面，`working/` 中超過租約時間的檔案可由「回收工作」流程移回 `origin/` 或留給人工處理。

### 2. 斜線裁切 UI

前端以 HTML5 Canvas 實作。

**切割線互動設計：**

- 進入圖片後，預設在照片正中央顯示一條垂直切割線。
- 切割線有兩個控制點：`p1` 與 `p2`，不要使用「上端點 / 下端點」作為邏輯名稱。
- UI 可將 `p1` 顯示為實心圓、`p2` 顯示為空心圓，方便辨識方向。
- 使用者可拖移任一控制點改變角度與位置。
- 使用者可拖曳線段本體平移整條切割線。
- 支援 `R` 重設為中央垂直線。
- 支援方向鍵微調目前選取的 handle 或整條線。
- 支援 `Shift + 拖曳` 吸附水平、垂直或 45 度方向。
- 控制點視覺半徑建議 8-12px，觸控命中半徑不得小於 24px。

**A/B 區域定義：**

- 後端與前端都以向量 `p1 -> p2` 判斷區域。
- 使用叉積：

```text
cross = (x2 - x1) * (y - y1) - (y2 - y1) * (x - x1)
```

- 規格固定 `cross >= 0` 為 A 區，`cross < 0` 為 B 區。
- 前端必須直接在遮罩區塊上標示大字 `A`、`B`。
- 文件與 UI 不再以「左/上」或「右/下」描述 A/B，避免斜線方向造成誤判。

**視覺呈現：**

- 切割線以紅色顯示。
- A/B 區以低透明度遮罩標示，建議透明度 10-18%。
- 提供顯示/隱藏遮罩按鈕，或按住 Space 暫時隱藏遮罩。
- 顯示目前檔名、已處理數、剩餘數、跳過數、失敗數。
- 工具列應固定在視窗可見位置，長圖或直圖不得把送出按鈕推到不可見區域。

### 3. 裁切預覽、標記與送出

切割線確認後，使用者需看到 A/B 裁切預覽縮圖。預覽可由前端 Canvas 低解析產生，不必等後端裁切。

送出按鈕需顯示完整配對結果：

```
[A: YORU / B: SEADOG]   [A: SEADOG / B: YORU]
```

點擊任一按鈕後：

1. 前端立即鎖定送出按鈕，顯示處理中，避免重複送出。
2. 前端將 `job_id`、原始檔名、切割線座標與 `a_label` 透過 AJAX POST 送至 `api.php?action=submit`。
3. 後端執行驗證、裁切、暫存輸出、正式移動、原圖歸檔與 manifest 記錄。
4. 前端收到成功回應後，顯示簡短成功回饋，然後載入下一張圖片。
5. 前端收到失敗回應時，保留目前圖片與切割線，恢復送出按鈕並顯示可重試錯誤。

### 4. 復原上一張

「復原上一張」是 MVP 必備功能。

- 前端提供 Undo 按鈕與快捷鍵 `Z`。
- 後端根據最近一筆成功 `submit` 紀錄：
  - 刪除或移除該次產生的 A/B 輸出檔。
  - 將原圖從 `finish/` 移回 `origin/` 或 `working/`。
  - 寫入 `undo` 事件到 `manifest.jsonl`。
- 若輸出檔或原圖缺失，API 必須回傳明確錯誤，不可靜默成功。

### 5. 跳過與問題檔

- 前端提供「跳過」按鈕與快捷鍵 `Esc`。
- 跳過時將 `working/{job_id}__{filename}` 移至 `skipped/`，寫入 `skip` 事件。
- 圖片載入失敗不得自動跳過。需顯示「重試」「移至問題檔」「跳過」三個操作。
- 驗證失敗、格式不支援、裁切失敗可移至 `error/`，並在 manifest 中記錄 `error_code` 與 `message`。

---

## 後端 API 規格（api.php）

### GET `api.php?action=get_image`

成功回傳 JSON：

```json
{
  "status": "ok",
  "job_id": "20260521-120001-a1b2c3",
  "filename": "photo001.jpg",
  "url": "api.php?action=image&job_id=20260521-120001-a1b2c3",
  "width": 1200,
  "height": 800,
  "remaining": 23,
  "processed": 12,
  "skipped": 1,
  "failed": 0,
  "lease_expires_at": "2026-05-21T12:30:01Z"
}
```

若沒有可處理圖片：

```json
{
  "status": "empty",
  "processed": 35,
  "skipped": 1,
  "failed": 0
}
```

### GET `api.php?action=image&job_id=...`

- 從 `working/` 串流目前工作圖片。
- 不直接暴露 `origin/` 或 `working/` 的實體路徑。
- 回傳正確 `Content-Type`。
- 拒絕不存在、已完成或已過期的 `job_id`。

### POST `api.php?action=submit`

接收 JSON body：

```json
{
  "job_id": "20260521-120001-a1b2c3",
  "filename": "photo001.jpg",
  "x1": 320,
  "y1": 0,
  "x2": 280,
  "y2": 600,
  "a_label": "yoru"
}
```

欄位說明：

- `job_id`：由 `get_image` 回傳，後端只接受 `working/` 中對應 job。
- `filename`：原始檔名，需與 `job_id` 對應的工作檔相符。
- `x1`, `y1`：`p1` 座標，相對於後端回傳的正規化圖片像素。
- `x2`, `y2`：`p2` 座標，相對於後端回傳的正規化圖片像素。
- `a_label`：`"yoru"` 或 `"seadog"`，B 區自動為另一個。

成功回傳：

```json
{
  "status": "ok",
  "annotation_id": "20260521-120030-d4e5f6",
  "job_id": "20260521-120001-a1b2c3",
  "source_checksum": "sha256...",
  "output_a": "yoru/photo001__20260521-120001-a1b2c3_A.png",
  "output_b": "seadog/photo001__20260521-120001-a1b2c3_B.png",
  "moved_to": "finish/photo001__20260521-120001-a1b2c3.jpg"
}
```

失敗回傳：

```json
{
  "status": "error",
  "error_code": "CUT_FAILED",
  "message": "裁切失敗，已保留原圖於 working，可重試"
}
```

### POST `api.php?action=undo`

接收 JSON body：

```json
{
  "annotation_id": "20260521-120030-d4e5f6"
}
```

若未指定 `annotation_id`，可復原目前 session 最近一筆 submit。

### POST `api.php?action=skip`

接收 JSON body：

```json
{
  "job_id": "20260521-120001-a1b2c3",
  "reason": "稍後處理"
}
```

後端將工作檔移到 `skipped/` 並記錄事件。

---

## 後端處理流程

### get_image 流程

1. 確認必要資料夾存在且可讀寫。
2. 掃描 `origin/` 可支援圖片。
3. 選取一張圖片，產生 `job_id`。
4. 用 `rename(origin/file, working/job_id__file)` 原子移入 `working/`。
5. 使用 `getimagesize()` 驗證格式與取得尺寸。
6. 若是 JPEG，需處理 EXIF orientation 或建立正規化版本，確保前後端座標一致。
7. 寫入 `lock` 事件到 `manifest.jsonl`。
8. 回傳 `job_id`、圖片 URL、尺寸與統計。

### submit 流程

1. 驗證 `job_id`、`filename`、`a_label`、座標格式與範圍。
2. 確認來源檔存在於 `working/`。
3. 用 `getimagesize()` 驗證 MIME，只接受 `image/jpeg`、`image/png`、`image/webp`。
4. 載入 GD image resource，套用 EXIF orientation 正規化。
5. 驗證 `p1` 與 `p2` 不得重疊。
6. 用 GD 多邊形遮罩演算法產生 A/B 輸出。
7. 先寫到 `tmp/`，確認兩張輸出都存在且 `filesize > 0`。
8. 用 `rename()` 將 A/B 輸出移到 `yoru/` 或 `seadog/`。
9. 將原圖從 `working/` 移到 `finish/`。
10. 寫入 `submit` 事件到 `manifest.jsonl`。
11. 回傳 `annotation_id` 與輸出檔資訊。

任何步驟失敗時：

- 清理尚未正式移動的 `tmp/` 檔。
- 保留原圖在 `working/`，讓使用者可重試。
- 寫入 `fail` 事件。
- 回傳明確 `error_code`。

---

## 斜線裁切演算法（PHP GD）

不建議以 PHP 逐像素 `imagecolorat()` / `imagesetpixel()` 處理大圖，效能容易不足。建議使用 GD 的 `imagefilledpolygon()` 多邊形填色能力，讓大量像素操作在 GD C 層執行。

### 輸出語意

預設輸出 full-size PNG：

- A 圖保留 A 區像素，B 區透明。
- B 圖保留 B 區像素，A 區透明。
- 輸出尺寸與正規化後原圖一致。
- 檔名固定使用 `.png`，避免 JPEG 不支援透明造成語意混亂。

若業務未來確定只需要白底照片，可改為輸出 JPEG，並明確將非保留區填白。

### 多邊形遮罩做法

1. 根據 `p1(x1,y1)` 與 `p2(x2,y2)` 定義無限切割線。
2. 計算該無限線與圖片矩形邊界的交點。
3. 用交點與圖片角落組成 A 區與 B 區多邊形。
4. 複製原圖成 A canvas 與 B canvas。
5. 對 A canvas，將 B 區多邊形填成透明。
6. 對 B canvas，將 A 區多邊形填成透明。
7. 使用 `imagesavealpha($image, true)` 保留 PNG alpha。

### 效能與資源限制

- 限制最大像素數，例如 `width * height <= 12_000_000`。
- PHP `memory_limit` 建議至少 512M；若部署環境只能 256M，需降低最大像素數。
- 設定合理 `max_execution_time`，裁切失敗需回傳可重試錯誤。
- 避免同時保留不必要的 GD resource，輸出後即釋放。

### 輸出檔名規則

```
原始檔名去副檔名 + __ + job_id + _A.png
原始檔名去副檔名 + __ + job_id + _B.png
```

例：

```
photo001__20260521-120001-a1b2c3_A.png
photo001__20260521-120001-a1b2c3_B.png
```

寫入正式目錄前必須確認目標不存在。若存在，直接拒絕或產生新的版本號，不可覆蓋。

---

## 前端技術規格（index.php）

- 純 HTML + CSS + Vanilla JavaScript，不依賴任何框架。
- HTML5 Canvas 處理圖片顯示、切割線、遮罩、A/B 標籤與預覽。
- 使用 Pointer Events：`pointerdown`、`pointermove`、`pointerup`、`pointercancel`，統一滑鼠、觸控、觸控筆。
- Canvas 設定 `touch-action: none`，避免拖曳 handle 時頁面滾動。
- AJAX 使用 `fetch()` API。
- 送出中所有送出、跳過、復原按鈕需依狀態鎖定，避免重複請求。

### Canvas 尺寸與座標換算

Canvas 必須區分 CSS pixel 與 bitmap pixel：

1. 以 CSS 尺寸決定畫面排版。
2. 以 `window.devicePixelRatio` 設定實際 canvas bitmap 尺寸。
3. 繪圖前使用 `ctx.scale(dpr, dpr)`。
4. 指標座標使用 `getBoundingClientRect()` 取得 CSS pixel。
5. 圖片在 canvas 內以 contain 模式顯示，並記錄：
   - `drawX`
   - `drawY`
   - `renderedWidth`
   - `renderedHeight`
6. 座標換算公式：

```text
imageX = (canvasX - drawX) * (naturalWidth / renderedWidth)
imageY = (canvasY - drawY) * (naturalHeight / renderedHeight)
```

7. 送出前將座標 clamp 到：

```text
0 <= imageX <= naturalWidth
0 <= imageY <= naturalHeight
```

### 互動快捷鍵

- `Y`：送出 `A: YORU / B: SEADOG`
- `S`：送出 `A: SEADOG / B: YORU`
- `Z`：復原上一張
- `R`：重設切割線
- `Esc`：跳過目前圖片
- `Space`：按住暫時隱藏遮罩
- 方向鍵：微調目前選取的 handle 或整條線

快捷鍵觸發前需確認目前沒有文字輸入框聚焦。

---

## 安全性規格

1. **路徑遍歷防護**：檔名只允許 `[a-zA-Z0-9_\-.]`，不可包含 `..`。
2. **檔名邊界限制**：不可為空、不可用 `.` 開頭、長度限制 1-180 字元，且 `basename($filename) === $filename`。
3. **檔案類型驗證**：後端用 `getimagesize()` 驗證確為圖片，不信任副檔名。
4. **MIME 檢查**：只處理 `image/jpeg`、`image/png`、`image/webp`。
5. **輸出路徑限制**：只對目標目錄使用 `realpath()`，不要對尚未存在的輸出檔使用 `realpath()`。
6. **目錄限制**：以 `$target = $realDir . DIRECTORY_SEPARATOR . $safeFilename` 組合路徑。
7. **圖片存取**：圖片透過 `api.php?action=image&job_id=...` 串流，不直接暴露 `origin/` 路徑。
8. **目錄列表**：Web server 必須關閉目錄列表，並禁止上傳圖片被當成 PHP 執行。
9. **覆蓋防護**：輸出檔正式寫入前必須確認不存在，不可覆蓋既有檔案。

---

## 錯誤處理

| 狀況 | 前端行為 | 後端行為 |
|------|----------|----------|
| origin 已空 | 顯示完成畫面，附上統計 | 回傳 `status: empty` |
| 裁切失敗 | 顯示錯誤，保留目前圖片讓使用者重試 | 清理 tmp，保留 working，寫 fail log |
| 網路錯誤 | 顯示重試按鈕，送出按鈕恢復 | 不改變檔案狀態 |
| 圖片載入失敗 | 顯示重試、跳過、移至問題檔 | 不自動完成，依使用者操作移動 |
| A/B 面積小於 5% | 要求二次確認 | 可回傳 warning |
| 重複送出 | 前端鎖定按鈕 | 後端以 job 狀態拒絕第二次 submit |
| 多分頁同圖 | 不應發生 | get_image 已把檔案移入 working 鎖定 |

---

## 環境需求

- PHP 7.4+（建議 8.0+）
- GD Library 啟用（`extension=gd`）
- JPEG EXIF orientation 若要自動正規化，需啟用 EXIF extension 或提供替代處理方式
- 所有資料夾需要 PHP process 有讀寫權限
- 不強制使用資料庫；MVP 可用 `manifest.jsonl`
- 建議 `memory_limit` 至少 512M，並設定最大圖片像素限制

---

## 測試與驗收規格

### 幾何與輸出測試

- 垂直線、水平線、45 度斜線、極端斜線。
- `p1` 與 `p2` 重疊時應拒絕。
- 端點超出圖片範圍時應 clamp 或拒絕，規格需一致。
- `a_label=yoru` 與 `a_label=seadog` 都要驗證 A/B 輸出位置正確。
- 使用 golden image 測試固定輸入圖與座標，輸出遮罩需符合預期。

### 檔案與狀態測試

- `get_image` 後原圖應從 `origin/` 移到 `working/`。
- `submit` 成功後 A/B 應進入正確資料夾，原圖進 `finish/`。
- `submit` 中途失敗時不得產生半完成正式輸出。
- `undo` 後輸出檔應移除，原圖應可再次處理。
- 同名檔不得覆蓋。
- 多分頁同時 `get_image` 不得領到同一張。
- 重複 submit 同一 `job_id` 第二次應被拒絕。

### 圖片格式測試

- JPEG、PNG、WebP。
- 透明 PNG。
- 大圖。
- 手機照片 EXIF orientation。
- 格式不符但副檔名正確的假圖片。

---

## 建議開發順序

1. 建立資料夾、自檢頁與權限檢查。
2. 實作 `manifest.jsonl` 寫入工具與 `session_id`。
3. 實作 `get_image`：從 `origin/` 原子移到 `working/`，回傳 `job_id` 與圖片尺寸。
4. 實作 `image` 串流 API，不直接暴露實體圖片路徑。
5. 實作 `index.php` 基本 UI：圖片顯示、Canvas contain 顯示、DPR 處理。
6. 實作切割線互動：`p1/p2`、拖 handle、拖整條線、A/B 遮罩。
7. 實作正確座標換算與 A/B 前端預覽。
8. 實作 `submit` 的驗證與交易式檔案流程。
9. 實作 GD 多邊形遮罩裁切與 PNG 輸出。
10. 實作 undo、skip、error 流程。
11. 加入快捷鍵、送出鎖定與錯誤重試。
12. 補 golden image、併發、重複送出、復原與大圖測試。

---

## 可選擴充功能

- 檢查模式：完成後瀏覽最近輸出的 A/B 縮圖。
- 抽樣複核：每批隨機抽 5-10% 進 review。
- 面積異常自動進 review，例如 A 或 B 小於 5%。
- 標註者登入或簡易使用者名稱。
- SQLite 取代 `manifest.jsonl`，支援查詢、review 與重跑。
- 批次匯出處理報告。
