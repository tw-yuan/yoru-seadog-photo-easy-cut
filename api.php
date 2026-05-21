<?php
declare(strict_types=1);

const REQUIRED_DIRS = ['origin', 'working', 'tmp', 'yoru', 'seadog', 'finish', 'error', 'skipped'];
const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const SUPPORTED_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
const MAX_PIXELS = 12000000;
const LEASE_SECONDS = 1800;

$ROOT = __DIR__;
$MANIFEST = $ROOT . DIRECTORY_SEPARATOR . 'manifest.jsonl';

main();

function main(): void
{
    ensureSession();
    $action = $_GET['action'] ?? '';

    try {
        switch ($action) {
            case 'health':
                jsonResponse(['status' => 'ok', 'health' => healthCheck(), 'stats' => stats()]);
                break;
            case 'get_image':
                handleGetImage();
                break;
            case 'image':
                handleImage();
                break;
            case 'submit':
                handleSubmit();
                break;
            case 'undo':
                handleUndo();
                break;
            case 'skip':
                handleSkip();
                break;
            case 'move_error':
                handleMoveError();
                break;
            default:
                jsonError('BAD_ACTION', '未知的 API action', 404);
        }
    } catch (ApiException $e) {
        jsonError($e->errorCode, $e->getMessage(), $e->httpCode);
    } catch (Throwable $e) {
        logEvent(['event' => 'fail', 'error_code' => 'SERVER_ERROR', 'message' => $e->getMessage()]);
        jsonError('SERVER_ERROR', '伺服器錯誤：' . $e->getMessage(), 500);
    }
}

final class ApiException extends RuntimeException
{
    public string $errorCode;
    public int $httpCode;

    public function __construct(string $errorCode, string $message, int $httpCode = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpCode = $httpCode;
    }
}

function ensureSession(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (empty($_COOKIE['photo_cut_session']) || !preg_match('/^[a-f0-9]{32}$/', $_COOKIE['photo_cut_session'])) {
        $id = bin2hex(random_bytes(16));
        setcookie('photo_cut_session', $id, [
            'expires' => time() + 86400 * 365,
            'path' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['photo_cut_session'] = $id;
    }
}

function sessionId(): string
{
    return $_COOKIE['photo_cut_session'] ?? 'unknown';
}

function rootPath(string $relative): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . $relative;
}

function dirPath(string $name): string
{
    if (!in_array($name, REQUIRED_DIRS, true)) {
        throw new ApiException('BAD_DIR', '不允許的目錄');
    }
    return rootPath($name);
}

function ensureRuntimeReady(): void
{
    $health = healthCheck();
    if (!empty($health['errors'])) {
        throw new ApiException('ENV_NOT_READY', implode('；', $health['errors']), 500);
    }
}

function healthCheck(): array
{
    $errors = [];
    $warnings = [];
    $dirs = [];

    foreach (REQUIRED_DIRS as $dir) {
        $path = dirPath($dir);
        $exists = is_dir($path);
        $readable = $exists && is_readable($path);
        $writable = $exists && is_writable($path);
        $dirs[$dir] = ['exists' => $exists, 'readable' => $readable, 'writable' => $writable];
        if (!$exists) {
            $errors[] = "缺少資料夾 {$dir}/";
        } elseif (!$readable || !$writable) {
            $errors[] = "{$dir}/ 需要可讀寫權限";
        }
    }

    if (!extension_loaded('gd')) {
        $errors[] = 'PHP GD extension 尚未啟用';
    }
    if (!function_exists('imagewebp')) {
        $warnings[] = '此 PHP GD 可能不支援 WebP';
    }
    if (!extension_loaded('exif')) {
        $warnings[] = 'EXIF extension 尚未啟用，JPEG orientation 不會自動正規化';
    }
    if (!is_file(rootPath('manifest.jsonl'))) {
        $warnings[] = 'manifest.jsonl 尚未建立，首次操作時會自動建立';
    } elseif (!is_writable(rootPath('manifest.jsonl'))) {
        $errors[] = 'manifest.jsonl 不可寫';
    }

    return [
        'errors' => $errors,
        'warnings' => $warnings,
        'dirs' => $dirs,
        'gd' => extension_loaded('gd'),
        'exif' => extension_loaded('exif'),
        'memory_limit' => ini_get('memory_limit'),
        'max_pixels' => MAX_PIXELS,
    ];
}

function handleGetImage(): void
{
    ensureRuntimeReady();
    $origin = dirPath('origin');
    $working = dirPath('working');
    $files = listSupportedFiles($origin);

    if (empty($files)) {
        jsonResponse(array_merge(['status' => 'empty'], stats()));
        return;
    }

    foreach ($files as $filename) {
        if (!isSafeFilename($filename)) {
            continue;
        }
        $jobId = makeId();
        $source = $origin . DIRECTORY_SEPARATOR . $filename;
        $targetName = $jobId . '__' . $filename;
        $target = $working . DIRECTORY_SEPARATOR . $targetName;

        if (!is_file($source) || file_exists($target)) {
            continue;
        }
        if (!@rename($source, $target)) {
            continue;
        }

        try {
            $info = imageInfo($target);
            if ($info['width'] * $info['height'] > MAX_PIXELS) {
                throw new ApiException('IMAGE_TOO_LARGE', '圖片像素超過限制');
            }
            $checksum = checksum($target);
            $leaseExpiresAt = gmdate('c', time() + LEASE_SECONDS);
            logEvent([
                'event' => 'lock',
                'job_id' => $jobId,
                'source_filename' => $filename,
                'source_checksum' => $checksum,
                'lease_expires_at' => $leaseExpiresAt,
            ]);
            jsonResponse(array_merge([
                'status' => 'ok',
                'job_id' => $jobId,
                'filename' => $filename,
                'url' => 'api.php?action=image&job_id=' . rawurlencode($jobId),
                'width' => $info['width'],
                'height' => $info['height'],
                'lease_expires_at' => $leaseExpiresAt,
            ], stats()));
            return;
        } catch (Throwable $e) {
            $errorTarget = uniquePath(dirPath('error'), $targetName);
            @rename($target, $errorTarget);
            logEvent([
                'event' => 'fail',
                'job_id' => $jobId,
                'source_filename' => $filename,
                'error_code' => $e instanceof ApiException ? $e->errorCode : 'IMAGE_INVALID',
                'message' => $e->getMessage(),
            ]);
            continue;
        }
    }

    jsonResponse(array_merge(['status' => 'empty'], stats()));
}

function handleImage(): void
{
    ensureRuntimeReady();
    $jobId = assertJobId((string)($_GET['job_id'] ?? ''));
    $job = findWorkingJob($jobId);
    if ($job === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '找不到圖片或圖片已完成';
        return;
    }
    if (isLeaseExpired($jobId)) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        echo '圖片工作租約已過期';
        return;
    }
    $info = imageInfo($job['path']);
    if ($info['mime'] === 'image/jpeg' && exifOrientation($job['path'], $info['mime']) !== 1) {
        $image = loadNormalizedImage($job['path']);
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        imagepng($image);
        imagedestroy($image);
        return;
    }
    header('Content-Type: ' . $info['mime']);
    header('Content-Length: ' . filesize($job['path']));
    header('Cache-Control: no-store');
    readfile($job['path']);
}

function handleSubmit(): void
{
    ensureRuntimeReady();
    $body = requestJson();
    $jobId = assertJobId((string)($body['job_id'] ?? ''));
    $filename = assertFilename((string)($body['filename'] ?? ''));
    $mode = assertSubmitMode((string)($body['mode'] ?? 'pair'));
    $aLabel = $mode === 'pair' ? assertLabel((string)($body['a_label'] ?? '')) : null;
    $bLabel = $aLabel !== null ? otherLabel($aLabel) : null;
    $singleLabel = $mode !== 'pair' ? assertLabel((string)($body['single_label'] ?? '')) : null;
    $coords = $mode === 'single_full' ? ['x1' => null, 'y1' => null, 'x2' => null, 'y2' => null] : validateCoords($body);
    $annotationId = makeId();
    $tmpFiles = [];
    $finalA = null;
    $finalB = null;
    $finalSingle = null;

    $job = findWorkingJob($jobId);
    if ($job === null || $job['filename'] !== $filename) {
        throw new ApiException('JOB_NOT_FOUND', '找不到對應的 working 圖片，可能已送出或被其他分頁處理');
    }

    $sourcePath = $job['path'];
    $checksum = checksum($sourcePath);

    try {
        $image = loadNormalizedImage($sourcePath);
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width * $height > MAX_PIXELS) {
            throw new ApiException('IMAGE_TOO_LARGE', '圖片像素超過限制');
        }

        $aPoly = [];
        $bPoly = [];
        if ($mode !== 'single_full') {
            $coords = clampCoords($coords, $width, $height);
            if (distanceSquared($coords['x1'], $coords['y1'], $coords['x2'], $coords['y2']) < 4) {
                throw new ApiException('INVALID_LINE', 'p1 與 p2 不可重疊');
            }

            $aPoly = clipRectPolygon($width, $height, $coords, 1);
            $bPoly = clipRectPolygon($width, $height, $coords, -1);
            if (count($aPoly) < 3 || count($bPoly) < 3) {
                throw new ApiException('INVALID_LINE', '切割線未能形成有效 A/B 區域');
            }
        }

        $base = filenameStem($filename) . '__' . $jobId;
        if ($mode === 'pair') {
            $aOutputName = $base . '_A.png';
            $bOutputName = $base . '_B.png';
            $aDir = dirPath((string)$aLabel);
            $bDir = dirPath((string)$bLabel);
            $finalA = $aDir . DIRECTORY_SEPARATOR . $aOutputName;
            $finalB = $bDir . DIRECTORY_SEPARATOR . $bOutputName;
            if (file_exists($finalA) || file_exists($finalB)) {
                throw new ApiException('OUTPUT_EXISTS', '輸出檔已存在，拒絕覆蓋');
            }

            $tmpA = dirPath('tmp') . DIRECTORY_SEPARATOR . $annotationId . '_A.png';
            $tmpB = dirPath('tmp') . DIRECTORY_SEPARATOR . $annotationId . '_B.png';
            $tmpFiles = [$tmpA, $tmpB];

            writeCutPng($image, $bPoly, $tmpA);
            writeCutPng($image, $aPoly, $tmpB);
            imagedestroy($image);

            assertOutputFile($tmpA);
            assertOutputFile($tmpB);

            if (!@rename($tmpA, $finalA)) {
                throw new ApiException('WRITE_FAILED', '無法移動 A 輸出檔');
            }
            if (!@rename($tmpB, $finalB)) {
                throw new ApiException('WRITE_FAILED', '無法移動 B 輸出檔');
            }
        } else {
            $region = singleRegion($mode);
            $singleOutputName = $base . '_' . $region . '.png';
            $finalSingle = dirPath((string)$singleLabel) . DIRECTORY_SEPARATOR . $singleOutputName;
            if (file_exists($finalSingle)) {
                throw new ApiException('OUTPUT_EXISTS', '輸出檔已存在，拒絕覆蓋');
            }

            $tmpSingle = dirPath('tmp') . DIRECTORY_SEPARATOR . $annotationId . '_single.png';
            $tmpFiles = [$tmpSingle];
            if ($mode === 'single_full') {
                writeFullPng($image, $tmpSingle);
            } elseif ($mode === 'single_a') {
                writeCutPng($image, $bPoly, $tmpSingle);
            } else {
                writeCutPng($image, $aPoly, $tmpSingle);
            }
            imagedestroy($image);

            assertOutputFile($tmpSingle);
            if (!@rename($tmpSingle, $finalSingle)) {
                throw new ApiException('WRITE_FAILED', '無法移動單張輸出檔');
            }
        }

        $finishName = $filename;
        $finishPath = uniquePath(dirPath('finish'), $jobId . '__' . $finishName);
        if (!@rename($sourcePath, $finishPath)) {
            throw new ApiException('ARCHIVE_FAILED', '輸出已完成，但原圖無法移入 finish');
        }

        $event = [
            'event' => 'submit',
            'annotation_id' => $annotationId,
            'job_id' => $jobId,
            'source_filename' => $filename,
            'source_checksum' => $checksum,
            'submit_mode' => $mode,
            'x1' => $coords['x1'],
            'y1' => $coords['y1'],
            'x2' => $coords['x2'],
            'y2' => $coords['y2'],
            'a_label' => $aLabel,
            'b_label' => $bLabel,
            'moved_to' => relativePath($finishPath),
        ];
        if ($mode === 'pair') {
            $event['output_a'] = relativePath((string)$finalA);
            $event['output_b'] = relativePath((string)$finalB);
        } else {
            $event['single_label'] = $singleLabel;
            $event['single_region'] = singleRegion($mode);
            $event['output_single'] = relativePath((string)$finalSingle);
        }
        logEvent($event);

        jsonResponse(array_merge(['status' => 'ok'], $event));
    } catch (Throwable $e) {
        foreach ($tmpFiles as $tmpFile) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
        foreach ([$finalA, $finalB, $finalSingle] as $finalFile) {
            if (is_string($finalFile) && is_file($finalFile)) {
                @unlink($finalFile);
            }
        }
        logEvent([
            'event' => 'fail',
            'annotation_id' => $annotationId,
            'job_id' => $jobId,
            'source_filename' => $filename,
            'source_checksum' => $checksum,
            'submit_mode' => $mode,
            'x1' => $coords['x1'] ?? null,
            'y1' => $coords['y1'] ?? null,
            'x2' => $coords['x2'] ?? null,
            'y2' => $coords['y2'] ?? null,
            'a_label' => $aLabel,
            'b_label' => $bLabel,
            'single_label' => $singleLabel,
            'error_code' => $e instanceof ApiException ? $e->errorCode : 'CUT_FAILED',
            'message' => $e->getMessage(),
        ]);
        if ($e instanceof ApiException) {
            throw $e;
        }
        throw new ApiException('CUT_FAILED', '裁切失敗，已保留原圖於 working，可重試');
    }
}

function handleUndo(): void
{
    ensureRuntimeReady();
    $body = requestJson(false);
    $annotationId = isset($body['annotation_id']) ? assertId((string)$body['annotation_id'], 'annotation_id') : null;
    $entry = findUndoTarget($annotationId);

    if ($entry === null) {
        throw new ApiException('UNDO_NOT_FOUND', '找不到可復原的送出紀錄');
    }

    $outputs = [];
    if (!empty($entry['output_single'])) {
        $outputs[] = rootPath((string)$entry['output_single']);
    } else {
        $outputs[] = rootPath($entry['output_a'] ?? '');
        $outputs[] = rootPath($entry['output_b'] ?? '');
    }
    $finish = rootPath($entry['moved_to'] ?? '');
    if (empty($outputs) || !is_file($finish)) {
        throw new ApiException('UNDO_MISSING_FILE', '復原需要的輸出檔或原圖缺失，已停止');
    }
    foreach ($outputs as $output) {
        if (!is_file($output)) {
            throw new ApiException('UNDO_MISSING_FILE', '復原需要的輸出檔或原圖缺失，已停止');
        }
    }

    $originName = uniquePath(dirPath('origin'), (string)$entry['source_filename']);
    if (!@rename($finish, $originName)) {
        throw new ApiException('UNDO_FAILED', '無法將原圖移回 origin');
    }
    foreach ($outputs as $output) {
        if (!@unlink($output)) {
            throw new ApiException('UNDO_FAILED', '原圖已移回 origin，但無法刪除輸出檔');
        }
    }

    logEvent([
        'event' => 'undo',
        'annotation_id' => $entry['annotation_id'] ?? null,
        'job_id' => $entry['job_id'] ?? null,
        'source_filename' => $entry['source_filename'] ?? null,
        'source_checksum' => $entry['source_checksum'] ?? null,
        'output_a' => $entry['output_a'] ?? null,
        'output_b' => $entry['output_b'] ?? null,
        'output_single' => $entry['output_single'] ?? null,
        'moved_to' => relativePath($originName),
    ]);

    jsonResponse(['status' => 'ok', 'undone' => $entry['annotation_id'] ?? null, 'restored_to' => relativePath($originName), 'stats' => stats()]);
}

function handleSkip(): void
{
    ensureRuntimeReady();
    $body = requestJson();
    $jobId = assertJobId((string)($body['job_id'] ?? ''));
    $reason = trim((string)($body['reason'] ?? '稍後處理'));
    $job = findWorkingJob($jobId);
    if ($job === null) {
        throw new ApiException('JOB_NOT_FOUND', '找不到可跳過的 working 圖片');
    }
    $target = uniquePath(dirPath('skipped'), $job['basename']);
    if (!@rename($job['path'], $target)) {
        throw new ApiException('SKIP_FAILED', '無法移至 skipped');
    }
    logEvent([
        'event' => 'skip',
        'job_id' => $jobId,
        'source_filename' => $job['filename'],
        'source_checksum' => checksum($target),
        'message' => $reason,
        'moved_to' => relativePath($target),
    ]);
    jsonResponse(['status' => 'ok', 'moved_to' => relativePath($target), 'stats' => stats()]);
}

function handleMoveError(): void
{
    ensureRuntimeReady();
    $body = requestJson();
    $jobId = assertJobId((string)($body['job_id'] ?? ''));
    $message = trim((string)($body['message'] ?? '使用者標記為問題檔'));
    $job = findWorkingJob($jobId);
    if ($job === null) {
        throw new ApiException('JOB_NOT_FOUND', '找不到可移動的 working 圖片');
    }
    $target = uniquePath(dirPath('error'), $job['basename']);
    if (!@rename($job['path'], $target)) {
        throw new ApiException('MOVE_ERROR_FAILED', '無法移至 error');
    }
    logEvent([
        'event' => 'fail',
        'job_id' => $jobId,
        'source_filename' => $job['filename'],
        'source_checksum' => checksum($target),
        'error_code' => 'USER_MARKED_ERROR',
        'message' => $message,
        'moved_to' => relativePath($target),
    ]);
    jsonResponse(['status' => 'ok', 'moved_to' => relativePath($target), 'stats' => stats()]);
}

function requestJson(bool $required = true): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        if ($required) {
            throw new ApiException('BAD_JSON', '缺少 JSON body');
        }
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new ApiException('BAD_JSON', 'JSON body 格式錯誤');
    }
    return $decoded;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function jsonError(string $code, string $message, int $status = 400): void
{
    jsonResponse(['status' => 'error', 'error_code' => $code, 'message' => $message], $status);
}

function logEvent(array $event): void
{
    $event['created_at'] = gmdate('c');
    $event['session_id'] = sessionId();
    $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $path = rootPath('manifest.jsonl');
    $fp = fopen($path, 'ab');
    if ($fp === false) {
        throw new ApiException('LOG_FAILED', '無法寫入 manifest.jsonl', 500);
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new ApiException('LOG_FAILED', '無法鎖定 manifest.jsonl', 500);
        }
        fwrite($fp, $line);
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function manifestEntries(): array
{
    $path = rootPath('manifest.jsonl');
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $entries = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }
    return $entries;
}

function findUndoTarget(?string $annotationId): ?array
{
    $entries = manifestEntries();
    $undone = [];
    foreach ($entries as $entry) {
        if (($entry['event'] ?? '') === 'undo' && isset($entry['annotation_id'])) {
            $undone[$entry['annotation_id']] = true;
        }
    }
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        $entry = $entries[$i];
        if (($entry['event'] ?? '') !== 'submit') {
            continue;
        }
        $id = $entry['annotation_id'] ?? null;
        if (!$id || isset($undone[$id])) {
            continue;
        }
        if ($annotationId !== null && $id !== $annotationId) {
            continue;
        }
        if ($annotationId === null && ($entry['session_id'] ?? '') !== sessionId()) {
            continue;
        }
        return $entry;
    }
    return null;
}

function isLeaseExpired(string $jobId): bool
{
    $entries = manifestEntries();
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        $entry = $entries[$i];
        if (($entry['event'] ?? '') === 'lock' && ($entry['job_id'] ?? '') === $jobId) {
            $expires = strtotime((string)($entry['lease_expires_at'] ?? ''));
            return $expires !== false && $expires < time();
        }
    }
    return false;
}

function stats(): array
{
    return [
        'remaining' => count(listSupportedFiles(dirPath('origin'))),
        'working' => count(listSupportedFiles(dirPath('working'))),
        'processed' => count(listSupportedFiles(dirPath('finish'))),
        'skipped' => count(listSupportedFiles(dirPath('skipped'))),
        'failed' => count(listSupportedFiles(dirPath('error'))),
    ];
}

function listSupportedFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..' || !is_file($dir . DIRECTORY_SEPARATOR . $file)) {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, SUPPORTED_EXTS, true)) {
            $files[] = $file;
        }
    }
    sort($files, SORT_NATURAL);
    return $files;
}

function assertJobId(string $jobId): string
{
    return assertId($jobId, 'job_id');
}

function assertId(string $id, string $field): string
{
    if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id)) {
        throw new ApiException('BAD_ID', "{$field} 格式錯誤");
    }
    return $id;
}

function assertFilename(string $filename): string
{
    if (!isSafeFilename($filename)) {
        throw new ApiException('BAD_FILENAME', '檔名不安全');
    }
    return $filename;
}

function isSafeFilename(string $filename): bool
{
    return $filename !== ''
        && strlen($filename) <= 180
        && $filename[0] !== '.'
        && basename($filename) === $filename
        && strpos($filename, '..') === false
        && preg_match('/^[a-zA-Z0-9_.-]+$/', $filename) === 1;
}

function assertLabel(string $label): string
{
    if (!in_array($label, ['yoru', 'seadog'], true)) {
        throw new ApiException('BAD_LABEL', 'a_label 只能是 yoru 或 seadog');
    }
    return $label;
}

function assertSubmitMode(string $mode): string
{
    if (!in_array($mode, ['pair', 'single_full', 'single_a', 'single_b'], true)) {
        throw new ApiException('BAD_MODE', 'mode 只能是 pair、single_full、single_a 或 single_b');
    }
    return $mode;
}

function singleRegion(string $mode): string
{
    if ($mode === 'single_full') {
        return 'FULL';
    }
    if ($mode === 'single_a') {
        return 'A';
    }
    if ($mode === 'single_b') {
        return 'B';
    }
    throw new ApiException('BAD_MODE', '不是單張輸出模式');
}

function otherLabel(string $label): string
{
    return $label === 'yoru' ? 'seadog' : 'yoru';
}

function validateCoords(array $body): array
{
    foreach (['x1', 'y1', 'x2', 'y2'] as $key) {
        if (!isset($body[$key]) || !is_numeric($body[$key])) {
            throw new ApiException('BAD_COORDS', "{$key} 必須是數字");
        }
    }
    return [
        'x1' => (float)$body['x1'],
        'y1' => (float)$body['y1'],
        'x2' => (float)$body['x2'],
        'y2' => (float)$body['y2'],
    ];
}

function clampCoords(array $coords, int $width, int $height): array
{
    return [
        'x1' => max(0, min($width, $coords['x1'])),
        'y1' => max(0, min($height, $coords['y1'])),
        'x2' => max(0, min($width, $coords['x2'])),
        'y2' => max(0, min($height, $coords['y2'])),
    ];
}

function distanceSquared(float $x1, float $y1, float $x2, float $y2): float
{
    return (($x2 - $x1) ** 2) + (($y2 - $y1) ** 2);
}

function findWorkingJob(string $jobId): ?array
{
    $prefix = $jobId . '__';
    foreach (listSupportedFiles(dirPath('working')) as $file) {
        if (strpos($file, $prefix) === 0) {
            return [
                'basename' => $file,
                'filename' => substr($file, strlen($prefix)),
                'path' => dirPath('working') . DIRECTORY_SEPARATOR . $file,
            ];
        }
    }
    return null;
}

function makeId(): string
{
    return gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
}

function checksum(string $path): string
{
    return 'sha256:' . hash_file('sha256', $path);
}

function imageInfo(string $path): array
{
    $info = @getimagesize($path);
    if (!$info || empty($info['mime']) || !in_array($info['mime'], SUPPORTED_MIMES, true)) {
        throw new ApiException('UNSUPPORTED_IMAGE', '不支援或無法辨識的圖片格式');
    }
    [$width, $height] = [$info[0], $info[1]];
    $orientation = exifOrientation($path, $info['mime']);
    if (in_array($orientation, [5, 6, 7, 8], true)) {
        [$width, $height] = [$height, $width];
    }
    return ['width' => (int)$width, 'height' => (int)$height, 'mime' => $info['mime']];
}

function exifOrientation(string $path, string $mime): int
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return 1;
    }
    $exif = @exif_read_data($path);
    return (int)($exif['Orientation'] ?? 1);
}

function loadNormalizedImage(string $path)
{
    $info = imageInfo($path);
    switch ($info['mime']) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($path);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($path);
            break;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) {
                throw new ApiException('WEBP_UNSUPPORTED', '此 PHP GD 不支援 WebP');
            }
            $image = @imagecreatefromwebp($path);
            break;
        default:
            $image = false;
    }
    if (!$image) {
        throw new ApiException('LOAD_FAILED', '圖片載入失敗');
    }

    if ($info['mime'] === 'image/jpeg') {
        $orientation = exifOrientation($path, $info['mime']);
        $image = applyOrientation($image, $orientation);
    }
    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);
    return $image;
}

function applyOrientation($image, int $orientation)
{
    switch ($orientation) {
        case 2:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return $image;
        case 3:
            return imagerotate($image, 180, 0);
        case 4:
            imageflip($image, IMG_FLIP_VERTICAL);
            return $image;
        case 5:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return imagerotate($image, -90, 0);
        case 6:
            return imagerotate($image, -90, 0);
        case 7:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return imagerotate($image, 90, 0);
        case 8:
            return imagerotate($image, 90, 0);
        default:
            return $image;
    }
}

function crossValue(array $coords, float $x, float $y): float
{
    return ($coords['x2'] - $coords['x1']) * ($y - $coords['y1']) - ($coords['y2'] - $coords['y1']) * ($x - $coords['x1']);
}

function clipRectPolygon(int $width, int $height, array $coords, int $side): array
{
    $poly = [
        ['x' => 0.0, 'y' => 0.0],
        ['x' => (float)$width, 'y' => 0.0],
        ['x' => (float)$width, 'y' => (float)$height],
        ['x' => 0.0, 'y' => (float)$height],
    ];
    $output = [];
    $count = count($poly);
    for ($i = 0; $i < $count; $i++) {
        $current = $poly[$i];
        $previous = $poly[($i + $count - 1) % $count];
        $currentInside = isInsideHalfPlane($coords, $current, $side);
        $previousInside = isInsideHalfPlane($coords, $previous, $side);

        if ($currentInside) {
            if (!$previousInside) {
                $output[] = lineSegmentIntersection($coords, $previous, $current);
            }
            $output[] = $current;
        } elseif ($previousInside) {
            $output[] = lineSegmentIntersection($coords, $previous, $current);
        }
    }
    return array_values(array_filter($output));
}

function isInsideHalfPlane(array $coords, array $point, int $side): bool
{
    $cross = crossValue($coords, $point['x'], $point['y']);
    return $side > 0 ? $cross >= -0.00001 : $cross <= 0.00001;
}

function lineSegmentIntersection(array $coords, array $a, array $b): ?array
{
    $ca = crossValue($coords, $a['x'], $a['y']);
    $cb = crossValue($coords, $b['x'], $b['y']);
    $denom = $cb - $ca;
    if (abs($denom) < 0.0000001) {
        return null;
    }
    $t = -$ca / $denom;
    return [
        'x' => $a['x'] + ($b['x'] - $a['x']) * $t,
        'y' => $a['y'] + ($b['y'] - $a['y']) * $t,
    ];
}

function writeCutPng($source, array $transparentPolygon, string $target): void
{
    $width = imagesx($source);
    $height = imagesy($source);
    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
        throw new ApiException('CUT_FAILED', '無法建立輸出畫布');
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $clear);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
    imagealphablending($canvas, false);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    $points = polygonPoints($transparentPolygon);
    if (count($points) >= 6) {
        imagefilledpolygon($canvas, $points, (int)(count($points) / 2), $transparent);
    }
    if (!imagepng($canvas, $target)) {
        imagedestroy($canvas);
        throw new ApiException('WRITE_FAILED', 'PNG 寫入失敗');
    }
    imagedestroy($canvas);
}

function writeFullPng($source, string $target): void
{
    $width = imagesx($source);
    $height = imagesy($source);
    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
        throw new ApiException('CUT_FAILED', '無法建立輸出畫布');
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $clear);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
    if (!imagepng($canvas, $target)) {
        imagedestroy($canvas);
        throw new ApiException('WRITE_FAILED', 'PNG 寫入失敗');
    }
    imagedestroy($canvas);
}

function polygonPoints(array $polygon): array
{
    $points = [];
    foreach ($polygon as $point) {
        if ($point === null) {
            continue;
        }
        $points[] = (int)round($point['x']);
        $points[] = (int)round($point['y']);
    }
    return $points;
}

function assertOutputFile(string $path): void
{
    if (!is_file($path) || filesize($path) <= 0) {
        throw new ApiException('WRITE_FAILED', '輸出檔驗證失敗');
    }
}

function filenameStem(string $filename): string
{
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $stem) ?: 'image';
}

function uniquePath(string $dir, string $filename): string
{
    $filename = assertFilename($filename);
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path)) {
        return $path;
    }
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    for ($i = 1; $i < 1000; $i++) {
        $candidate = $stem . '__' . $i . ($ext !== '' ? '.' . $ext : '');
        $path = $dir . DIRECTORY_SEPARATOR . $candidate;
        if (!file_exists($path)) {
            return $path;
        }
    }
    throw new ApiException('NAME_CONFLICT', '無法產生不覆蓋的檔名');
}

function relativePath(string $path): string
{
    $root = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($path, $root) === 0) {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
    }
    return $path;
}
