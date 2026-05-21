# Yoru / Seadog Photo Easy Cut

這是一個本機使用的照片裁切與標記工具，用來快速製作 Yoru / Seadog 生圖模型訓練資料。

## 專案背景

我們看到 Yoru 跟 Seadog 常常黏在一起，於是開始各種拍照，想拿來換 SITCON Camp 2026 開發組群組頭貼。

拍了一段時間後發現，現有照片裡可能有很多不夠好、不符合預期，或是需要拆分標記的素材。為了之後能訓練一個生圖模型產生更多圖片，我們需要先把現有照片整理成可用的訓練資料。

這個專案就是為了讓大家更快、更一致地完成這件事：

- 從原始合照中用斜線裁出 A / B 兩區。
- 把 A / B 分別標記成 `yoru` 或 `seadog`。
- 遇到單人照或只有一側可用時，可以只輸出單張。
- 保留原圖、記錄操作歷史，降低誤標或檔案污染的風險。

## 功能

- 單頁 PHP + Vanilla JavaScript，適合本機快速使用。
- Canvas 斜線裁切 UI。
- A/B 區域以固定幾何規則判定，不依賴左/右或上/下描述。
- 裁切預覽。
- 成對輸出：
  - `A: YORU / B: SEADOG`
  - `A: SEADOG / B: YORU`
- 單張輸出：
  - 整張標記為 `yoru` 或 `seadog`
  - 只保留 A 區並標記
  - 只保留 B 區並標記
- 復原上一張。
- 跳過照片。
- 問題照片移入 `error/`。
- `manifest.jsonl` append-only 操作紀錄。
- 先寫入 `tmp/`，成功後再移入正式資料夾。

## 環境需求

- PHP 7.4+，建議 PHP 8+
- PHP GD extension
- 建議啟用 PHP EXIF extension，用來處理手機照片 orientation
- Web server 或 PHP built-in server

## 目錄結構

```text
.
├── index.php
├── api.php
├── project.md
├── AGENTS.md
├── MEMORY.md
├── manifest.jsonl
├── origin/
├── working/
├── tmp/
├── yoru/
├── seadog/
├── finish/
├── error/
└── skipped/
```

資料夾用途：

- `origin/`：放入待處理的原始照片。
- `working/`：已領取、正在標記的照片。
- `tmp/`：裁切輸出暫存。
- `yoru/`：輸出後標記為 yoru 的圖片。
- `seadog/`：輸出後標記為 seadog 的圖片。
- `finish/`：處理完成後歸檔的原圖。
- `error/`：無法載入、格式錯誤或人工標記的問題照片。
- `skipped/`：暫時跳過的照片。
- `manifest.jsonl`：所有操作事件紀錄。

## 快速開始

確認資料夾存在並可讀寫：

```bash
chmod 775 origin working tmp yoru seadog finish error skipped
chmod 664 manifest.jsonl
```

放照片到 `origin/`，支援：

- `.jpg`
- `.jpeg`
- `.png`
- `.webp`

啟動本機伺服器：

```bash
php -S 127.0.0.1:8000
```

打開：

```text
http://127.0.0.1:8000/index.php
```

首頁會做環境自檢，包含必要資料夾、權限、GD 與 EXIF 狀態。

## 使用方式

1. 把原始照片放進 `origin/`。
2. 打開網頁後，系統會自動領取一張照片到 `working/`。
3. 拖曳切割線的兩個控制點調整 A/B 區域。
4. 看右側預覽確認裁切結果。
5. 選擇輸出方式：
   - 雙人照：使用 A/B 成對送出。
   - 單人照：使用整張輸出。
   - 只有 A 或 B 可用：使用只留 A 或只留 B。
6. 成功後輸出圖片會進 `yoru/` 或 `seadog/`，原圖會進 `finish/`。

## 操作快捷鍵

- `R`：重設切割線。
- `Z`：復原上一張。
- `Esc`：跳過目前圖片。
- `Y`：送出 `A: YORU / B: SEADOG`。
- `S`：送出 `A: SEADOG / B: YORU`。
- `Space`：按住暫時隱藏遮罩。
- 方向鍵：微調目前選取的控制點或整條線。
- `Shift + 拖曳`：吸附水平、垂直或 45 度方向。

## A/B 定義

A/B 不用「左邊」「右邊」「上面」「下面」定義，因為斜線方向會讓這些描述變得不可靠。

系統固定使用 `p1 -> p2` 的叉積判斷：

```text
cross = (x2 - x1) * (y - y1) - (y2 - y1) * (x - x1)
```

- `cross >= 0` 是 A 區。
- `cross < 0` 是 B 區。

前端預覽和後端輸出都使用同一套規則。

## 輸出結果

成對輸出時會產生兩張透明 PNG：

```text
yoru/photo001__{job_id}_A.png
seadog/photo001__{job_id}_B.png
```

實際路徑會依你選的標籤決定。

單張輸出時會產生一張透明 PNG：

```text
yoru/photo001__{job_id}_FULL.png
yoru/photo001__{job_id}_A.png
seadog/photo001__{job_id}_B.png
```

原始照片處理成功後會移到：

```text
finish/{job_id}__photo001.jpg
```

## 開發與驗證

PHP 語法檢查：

```bash
php -l api.php
php -l index.php
```

前端 script 語法檢查：

```bash
perl -0777 -ne 'print $1 if /<script>(.*)<\/script>/s' index.php | node --check -
```

建議手動驗收：

- 雙人照成對輸出。
- `A: YORU / B: SEADOG` 與 `A: SEADOG / B: YORU` 都測。
- 單人照整張輸出。
- 只留 A。
- 只留 B。
- 復原上一張。
- 跳過。
- 移至問題檔。
- 檢查 `manifest.jsonl` 是否有正確事件。

## 更多規格

完整設計與驗收規格請看 `project.md`。

後續 agent 接手時請先看 `AGENTS.md` 與 `MEMORY.md`。
