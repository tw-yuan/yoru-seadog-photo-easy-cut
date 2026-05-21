# AGENTS.md

## 專案概況

這個 repo 是「照片裁切標記工具」的本機單頁 PHP 應用。主要規格來源是 `project.md`。

目標使用情境：

- 使用者把原始照片放進 `origin/`。
- 前端 `index.php` 逐張領圖，使用 Canvas 畫斜線裁切。
- 後端 `api.php` 根據同一套 A/B 幾何規則用 GD 輸出 PNG。
- 輸出依標籤進 `yoru/` 或 `seadog/`，原圖進 `finish/`。
- 所有操作追加寫入 `manifest.jsonl`。

## 目前檔案與目錄

- `project.md`：完整規格與後續補充。
- `index.php`：純 HTML/CSS/Vanilla JS 前端。
- `api.php`：PHP API 與 GD 裁切邏輯。
- `manifest.jsonl`：append-only 操作紀錄。
- `origin/`：待處理圖片。
- `working/`：已鎖定、待送出圖片。
- `tmp/`：裁切暫存輸出。
- `yoru/`、`seadog/`：正式輸出。
- `finish/`：處理完成後的原圖。
- `error/`：問題圖片。
- `skipped/`：使用者跳過圖片。

## 關鍵設計約束

- 原圖不可丟失：只有在輸出成功後才把 working 原圖移到 `finish/`。
- 所有狀態變更都要寫 `manifest.jsonl`，不要只依賴資料夾推斷歷史。
- 檔案輸出要接近交易式：先寫 `tmp/`，驗證後再 `rename()` 到正式目錄。
- 不可覆蓋既有輸出檔。
- 不直接暴露 `origin/` 或 `working/` 實體路徑；圖片由 `api.php?action=image&job_id=...` 串流。
- 檔名必須維持安全檢查：只允許 `[a-zA-Z0-9_\-.]`，不可含 `..`，不可為 dotfile。
- 支援圖片格式：JPEG、PNG、WebP。後端用 `getimagesize()` 和 MIME 驗證，不信任副檔名。
- 若有 EXIF orientation，後端會正規化 JPEG；圖片串流時也要避免前端看到的座標與後端裁切座標不一致。

## A/B 幾何規則

前後端都必須使用向量 `p1 -> p2` 與叉積定義 A/B：

```text
cross = (x2 - x1) * (y - y1) - (y2 - y1) * (x - x1)
```

- `cross >= 0` 是 A 區。
- `cross < 0` 是 B 區。
- UI、文件和程式不要用「左/右」或「上/下」作為 A/B 邏輯描述。

## 送出模式

`api.php?action=submit` 目前支援：

- `mode=pair`：預設模式，輸出 A/B 兩張，透過 `a_label` 決定 A 是 `yoru` 或 `seadog`，B 自動是另一個。
- `mode=single_full`：整張輸出一張，透過 `single_label` 決定標籤。
- `mode=single_a`：只輸出 A 區一張，透過 `single_label` 決定標籤。
- `mode=single_b`：只輸出 B 區一張，透過 `single_label` 決定標籤。

單張模式是為了處理「原圖只有一個人」或「裁完只有一側可用」。

Manifest 中成對送出會記錄 `output_a` / `output_b`；單張送出會記錄 `submit_mode`、`single_label`、`single_region`、`output_single`。

## 前端注意事項

- 前端座標一律以原圖像素座標保存，Canvas 只負責顯示與換算。
- Canvas 要處理 CSS pixel 與 DPR，不要直接把 client 座標當成圖片座標。
- 支援 Pointer Events、拖曳 p1/p2、拖曳線段平移、Shift 吸附、方向鍵微調、R 重設、Z 復原、Esc 跳過、Y/S 成對送出、Space 暫時隱藏遮罩。
- 工具列固定在視窗可見區域，避免長圖把操作按鈕推走。
- 送出、跳過、復原期間要鎖定按鈕，避免重複請求。

## 後端注意事項

- `get_image` 要用 `rename(origin/file, working/job_id__file)` 作為領圖鎖定。
- `image` 要拒絕不存在、已完成或 lease 過期的 `job_id`。
- `submit` 失敗時要清理 `tmp/` 和已移入正式目錄的半成品，並保留 working 原圖供重試。
- `undo` 要支援成對輸出與單張輸出。復原不可靜默成功；缺檔要報錯。
- `skip` 將 working 檔移至 `skipped/` 並寫 `skip`。
- `move_error` 將 working 檔移至 `error/` 並寫 `fail`。

## 驗證方式

此工作環境目前沒有 `php` 指令，無法在容器內跑 PHP lint 或 GD 實測。後續在 dev env 請跑：

```bash
php -l api.php
php -l index.php
php -S 127.0.0.1:8000
```

然後放測試圖片到 `origin/`，打開：

```text
http://127.0.0.1:8000/index.php
```

建議至少實測：

- JPEG、PNG、WebP。
- 一張普通雙人照，測 `A: YORU / B: SEADOG`。
- 同一流程測 `A: SEADOG / B: YORU`。
- 一張單人照，測 `single_full`。
- 一張只保留 A，一張只保留 B。
- `undo` 是否刪除輸出並把原圖放回 `origin/`。
- `skip` 與 `move_error` 是否移到正確資料夾並寫 manifest。

## 編輯偏好

- 保持純 PHP + Vanilla JS，不引入框架。
- 優先延續現有單檔 `index.php` / `api.php` 結構，除非重構能明顯降低複雜度。
- 修改幾何邏輯時必須同步前後端。
- 修改 API payload 時要保持舊格式相容，尤其是未帶 `mode` 的 submit 應繼續視為 `pair`。
