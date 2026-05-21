<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>照片裁切標記工具</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f6f7f9;
      --panel: #ffffff;
      --ink: #1f2933;
      --muted: #667085;
      --line: #e11d48;
      --border: #d8dee8;
      --blue: #1b68d1;
      --blue-weak: #e8f1ff;
      --green: #257a4f;
      --green-weak: #e8f7ef;
      --red: #b42318;
      --red-weak: #fff1f0;
      --amber: #9a6700;
      --amber-weak: #fff7d6;
      font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      color: var(--ink);
      background: var(--bg);
    }

    button {
      border: 1px solid var(--border);
      border-radius: 6px;
      background: #fff;
      color: var(--ink);
      min-height: 36px;
      padding: 7px 11px;
      font: inherit;
      cursor: pointer;
    }

    button:hover:not(:disabled) {
      border-color: #9aa8ba;
      background: #f8fafc;
    }

    button:disabled {
      cursor: not-allowed;
      opacity: .55;
    }

    button.primary {
      border-color: var(--blue);
      background: var(--blue);
      color: #fff;
      font-weight: 700;
    }

    button.success {
      border-color: var(--green);
      background: var(--green);
      color: #fff;
      font-weight: 700;
    }

    button.danger {
      border-color: var(--red);
      color: var(--red);
      background: #fff;
    }

    .app {
      min-height: 100vh;
      display: grid;
      grid-template-rows: auto 1fr;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 20;
      border-bottom: 1px solid var(--border);
      background: rgba(255, 255, 255, .96);
      backdrop-filter: blur(8px);
    }

    .topbar-inner {
      max-width: 1480px;
      margin: 0 auto;
      padding: 10px 14px;
      display: grid;
      grid-template-columns: minmax(220px, 1fr) auto;
      gap: 12px;
      align-items: center;
    }

    .titleline {
      min-width: 0;
    }

    .filename {
      font-weight: 800;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .meta {
      margin-top: 3px;
      color: var(--muted);
      font-size: 13px;
      display: flex;
      flex-wrap: wrap;
      gap: 9px;
    }

    .toolbar {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 8px;
    }

    .status {
      max-width: 1480px;
      margin: 10px auto 0;
      padding: 0 14px;
    }

    .notice {
      border: 1px solid var(--border);
      border-radius: 6px;
      background: var(--panel);
      padding: 10px 12px;
      margin-bottom: 10px;
      line-height: 1.45;
    }

    .notice.error {
      border-color: #f0a7a0;
      color: var(--red);
      background: var(--red-weak);
    }

    .notice.warn {
      border-color: #e9ce73;
      color: var(--amber);
      background: var(--amber-weak);
    }

    .notice.ok {
      border-color: #a8dbc0;
      color: var(--green);
      background: var(--green-weak);
    }

    .main {
      max-width: 1480px;
      width: 100%;
      margin: 0 auto;
      padding: 14px;
      display: grid;
      grid-template-columns: minmax(0, 1fr) 320px;
      gap: 14px;
      align-items: start;
    }

    .workspace {
      min-width: 0;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: #15181d;
      overflow: hidden;
    }

    #mainCanvas {
      display: block;
      width: 100%;
      height: min(74vh, 820px);
      min-height: 420px;
      touch-action: none;
      cursor: crosshair;
    }

    .side {
      display: grid;
      gap: 12px;
    }

    .panel {
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--panel);
      padding: 12px;
    }

    .panel h2 {
      margin: 0 0 9px;
      font-size: 15px;
    }

    .preview-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .preview-item {
      min-width: 0;
    }

    .preview-label {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      color: var(--muted);
      font-size: 13px;
      margin-bottom: 5px;
    }

    .preview-label strong {
      color: var(--ink);
      font-size: 14px;
    }

    .preview {
      display: block;
      width: 100%;
      aspect-ratio: 1 / 1;
      border: 1px solid var(--border);
      border-radius: 6px;
      background-color: #fff;
      background-image:
        linear-gradient(45deg, #d9dee8 25%, transparent 25%),
        linear-gradient(-45deg, #d9dee8 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #d9dee8 75%),
        linear-gradient(-45deg, transparent 75%, #d9dee8 75%);
      background-size: 18px 18px;
      background-position: 0 0, 0 9px, 9px -9px, -9px 0;
    }

    .submit-grid {
      display: grid;
      gap: 8px;
      margin-top: 11px;
    }

    .empty {
      max-width: 720px;
      margin: 70px auto;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--panel);
      padding: 24px;
      text-align: center;
    }

    .empty h1 {
      margin: 0 0 8px;
      font-size: 24px;
    }

    .hidden {
      display: none !important;
    }

    @media (max-width: 980px) {
      .topbar-inner {
        grid-template-columns: 1fr;
      }

      .toolbar {
        justify-content: flex-start;
      }

      .main {
        grid-template-columns: 1fr;
      }

      #mainCanvas {
        height: 62vh;
        min-height: 320px;
      }
    }
  </style>
</head>
<body>
  <div class="app">
    <header class="topbar">
      <div class="topbar-inner">
        <div class="titleline">
          <div class="filename" id="filename">讀取中</div>
          <div class="meta" id="meta"></div>
        </div>
        <div class="toolbar">
          <button id="toggleMaskBtn" type="button">隱藏遮罩</button>
          <button id="resetBtn" type="button">重設</button>
          <button id="undoBtn" type="button">復原上一張</button>
          <button id="skipBtn" type="button">跳過</button>
          <button id="nextBtn" type="button">重新檢查</button>
        </div>
      </div>
    </header>

    <div class="status" id="status"></div>

    <main class="main" id="workView">
      <section class="workspace">
        <canvas id="mainCanvas"></canvas>
      </section>

      <aside class="side">
        <section class="panel">
          <h2>裁切預覽</h2>
          <div class="preview-grid">
            <div class="preview-item">
              <div class="preview-label"><strong>A</strong><span id="areaA">0%</span></div>
              <canvas class="preview" id="previewA"></canvas>
            </div>
            <div class="preview-item">
              <div class="preview-label"><strong>B</strong><span id="areaB">0%</span></div>
              <canvas class="preview" id="previewB"></canvas>
            </div>
          </div>
          <div class="submit-grid">
            <button class="primary" id="submitYoruBtn" type="button">A: YORU / B: SEADOG</button>
            <button class="success" id="submitSeadogBtn" type="button">A: SEADOG / B: YORU</button>
          </div>
        </section>

        <section class="panel">
          <h2>單人 / 單邊可用</h2>
          <div class="submit-grid">
            <button id="fullYoruBtn" type="button">整張: YORU</button>
            <button id="fullSeadogBtn" type="button">整張: SEADOG</button>
            <button id="singleAYoruBtn" type="button">只留 A: YORU</button>
            <button id="singleASeadogBtn" type="button">只留 A: SEADOG</button>
            <button id="singleBYoruBtn" type="button">只留 B: YORU</button>
            <button id="singleBSeadogBtn" type="button">只留 B: SEADOG</button>
          </div>
        </section>
      </aside>
    </main>

    <section class="empty hidden" id="emptyView">
      <h1>所有照片已處理完畢</h1>
      <p id="emptyStats"></p>
      <button id="emptyReloadBtn" type="button">重新檢查</button>
    </section>
  </div>

  <script>
    const state = {
      job: null,
      img: null,
      line: null,
      draw: null,
      dragging: null,
      selected: 'line',
      showMask: true,
      spaceHide: false,
      busy: false,
      healthOk: false
    };

    const el = {
      filename: document.getElementById('filename'),
      meta: document.getElementById('meta'),
      status: document.getElementById('status'),
      workView: document.getElementById('workView'),
      emptyView: document.getElementById('emptyView'),
      emptyStats: document.getElementById('emptyStats'),
      canvas: document.getElementById('mainCanvas'),
      previewA: document.getElementById('previewA'),
      previewB: document.getElementById('previewB'),
      areaA: document.getElementById('areaA'),
      areaB: document.getElementById('areaB'),
      toggleMaskBtn: document.getElementById('toggleMaskBtn'),
      resetBtn: document.getElementById('resetBtn'),
      undoBtn: document.getElementById('undoBtn'),
      skipBtn: document.getElementById('skipBtn'),
      nextBtn: document.getElementById('nextBtn'),
      submitYoruBtn: document.getElementById('submitYoruBtn'),
      submitSeadogBtn: document.getElementById('submitSeadogBtn'),
      fullYoruBtn: document.getElementById('fullYoruBtn'),
      fullSeadogBtn: document.getElementById('fullSeadogBtn'),
      singleAYoruBtn: document.getElementById('singleAYoruBtn'),
      singleASeadogBtn: document.getElementById('singleASeadogBtn'),
      singleBYoruBtn: document.getElementById('singleBYoruBtn'),
      singleBSeadogBtn: document.getElementById('singleBSeadogBtn'),
      emptyReloadBtn: document.getElementById('emptyReloadBtn')
    };

    const ctx = el.canvas.getContext('2d');
    const previewCtxA = el.previewA.getContext('2d');
    const previewCtxB = el.previewB.getContext('2d');

    init();

    function init() {
      bindEvents();
      resizeCanvas();
      loadHealth().then(loadNext);
    }

    function bindEvents() {
      window.addEventListener('resize', () => {
        resizeCanvas();
        drawAll();
      });
      el.canvas.addEventListener('pointerdown', onPointerDown);
      el.canvas.addEventListener('pointermove', onPointerMove);
      el.canvas.addEventListener('pointerup', endPointer);
      el.canvas.addEventListener('pointercancel', endPointer);

      el.toggleMaskBtn.addEventListener('click', () => {
        state.showMask = !state.showMask;
        el.toggleMaskBtn.textContent = state.showMask ? '隱藏遮罩' : '顯示遮罩';
        drawAll();
      });
      el.resetBtn.addEventListener('click', resetLine);
      el.undoBtn.addEventListener('click', undoLast);
      el.skipBtn.addEventListener('click', skipCurrent);
      el.nextBtn.addEventListener('click', loadNext);
      el.emptyReloadBtn.addEventListener('click', loadNext);
      el.submitYoruBtn.addEventListener('click', () => submitCurrent('yoru'));
      el.submitSeadogBtn.addEventListener('click', () => submitCurrent('seadog'));
      el.fullYoruBtn.addEventListener('click', () => submitSingle('single_full', 'yoru'));
      el.fullSeadogBtn.addEventListener('click', () => submitSingle('single_full', 'seadog'));
      el.singleAYoruBtn.addEventListener('click', () => submitSingle('single_a', 'yoru'));
      el.singleASeadogBtn.addEventListener('click', () => submitSingle('single_a', 'seadog'));
      el.singleBYoruBtn.addEventListener('click', () => submitSingle('single_b', 'yoru'));
      el.singleBSeadogBtn.addEventListener('click', () => submitSingle('single_b', 'seadog'));

      document.addEventListener('keydown', onKeyDown);
      document.addEventListener('keyup', event => {
        if (event.code === 'Space') {
          state.spaceHide = false;
          drawAll();
        }
      });
    }

    async function loadHealth() {
      try {
        const data = await apiGet('health');
        const health = data.health || {};
        state.healthOk = !health.errors || health.errors.length === 0;
        renderHealth(health);
      } catch (error) {
        showNotice(error.message, 'error');
      }
    }

    function renderHealth(health) {
      const messages = [];
      if (health.errors && health.errors.length) {
        messages.push(`<div class="notice error">${escapeHtml(health.errors.join('；'))}</div>`);
      }
      if (health.warnings && health.warnings.length) {
        messages.push(`<div class="notice warn">${escapeHtml(health.warnings.join('；'))}</div>`);
      }
      el.status.innerHTML = messages.join('');
    }

    async function loadNext(force = false) {
      if (state.busy && !force) return;
      setBusy(true);
      showWork();
      el.filename.textContent = '讀取中';
      try {
        const data = await apiGet('get_image');
        if (data.status === 'empty') {
          state.job = null;
          state.img = null;
          renderStats(data);
          showEmpty(data);
          return;
        }
        state.job = data;
        await loadImage(data.url);
        resetLine(false);
        renderJob();
        drawAll();
      } catch (error) {
        showNotice(error.message, 'error');
      } finally {
        setBusy(false);
      }
    }

    function loadImage(url) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
          state.img = img;
          resolve();
        };
        img.onerror = () => {
          state.img = null;
          showImageLoadActions();
          reject(new Error('圖片載入失敗'));
        };
        img.src = `${url}&_=${Date.now()}`;
      });
    }

    function showImageLoadActions() {
      const retry = `<button type="button" onclick="window.__retryImage()">重試</button>`;
      const skip = `<button type="button" onclick="window.__skipImage()">跳過</button>`;
      const move = `<button type="button" class="danger" onclick="window.__moveError()">移至問題檔</button>`;
      showNotice(`圖片載入失敗。${retry} ${skip} ${move}`, 'error', true);
    }

    window.__retryImage = () => {
      if (state.job) {
        loadImage(state.job.url).then(() => {
          resetLine(false);
          renderJob();
          drawAll();
        }).catch(showImageLoadActions);
      }
    };
    window.__skipImage = () => skipCurrent();
    window.__moveError = () => moveCurrentToError('圖片載入失敗');

    function renderJob() {
      if (!state.job) return;
      el.filename.textContent = state.job.filename;
      renderStats(state.job);
      showWork();
    }

    function renderStats(data) {
      el.meta.innerHTML = [
        `已處理 ${Number(data.processed || 0)}`,
        `剩餘 ${Number(data.remaining || 0)}`,
        `working ${Number(data.working || 0)}`,
        `跳過 ${Number(data.skipped || 0)}`,
        `失敗 ${Number(data.failed || 0)}`
      ].map(escapeHtml).join('<span>|</span>');
    }

    function showWork() {
      el.workView.classList.remove('hidden');
      el.emptyView.classList.add('hidden');
    }

    function showEmpty(data) {
      el.workView.classList.add('hidden');
      el.emptyView.classList.remove('hidden');
      el.filename.textContent = '沒有可處理圖片';
      el.emptyStats.textContent = `已處理 ${Number(data.processed || 0)}，跳過 ${Number(data.skipped || 0)}，失敗 ${Number(data.failed || 0)}`;
      clearCanvas();
    }

    function showNotice(message, type = 'ok', raw = false) {
      const cls = type === 'error' ? 'error' : type === 'warn' ? 'warn' : 'ok';
      el.status.innerHTML = `<div class="notice ${cls}">${raw ? message : escapeHtml(message)}</div>`;
    }

    function clearNotice() {
      el.status.innerHTML = '';
    }

    function resizeCanvas() {
      const rect = el.canvas.getBoundingClientRect();
      const dpr = window.devicePixelRatio || 1;
      el.canvas.width = Math.max(1, Math.round(rect.width * dpr));
      el.canvas.height = Math.max(1, Math.round(rect.height * dpr));
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      [el.previewA, el.previewB].forEach(canvas => {
        const size = Math.max(1, Math.round(canvas.getBoundingClientRect().width * dpr));
        canvas.width = size;
        canvas.height = size;
        canvas.getContext('2d').setTransform(dpr, 0, 0, dpr, 0, 0);
      });
    }

    function resetLine(shouldDraw = true) {
      if (!state.job) return;
      const w = state.job.width;
      const h = state.job.height;
      state.line = {
        p1: { x: w / 2, y: 0 },
        p2: { x: w / 2, y: h }
      };
      state.selected = 'line';
      if (shouldDraw) drawAll();
    }

    function drawAll() {
      resizeCanvas();
      drawMain();
      drawPreviews();
      setButtons();
    }

    function clearCanvas() {
      const rect = el.canvas.getBoundingClientRect();
      ctx.clearRect(0, 0, rect.width, rect.height);
    }

    function drawMain() {
      const rect = el.canvas.getBoundingClientRect();
      ctx.clearRect(0, 0, rect.width, rect.height);
      ctx.fillStyle = '#15181d';
      ctx.fillRect(0, 0, rect.width, rect.height);

      if (!state.img || !state.job || !state.line) return;

      const draw = containRect(state.job.width, state.job.height, rect.width, rect.height);
      state.draw = draw;
      ctx.drawImage(state.img, draw.x, draw.y, draw.w, draw.h);

      if (state.showMask && !state.spaceHide) {
        drawMask(draw);
      }
      drawCutLine(draw);
    }

    function drawMask(draw) {
      const aPoly = clipRectPolygon(state.job.width, state.job.height, state.line, 1);
      const bPoly = clipRectPolygon(state.job.width, state.job.height, state.line, -1);
      fillPolygon(ctx, aPoly, draw, 'rgba(27, 104, 209, .16)');
      fillPolygon(ctx, bPoly, draw, 'rgba(37, 122, 79, .16)');
      drawRegionLabel(ctx, aPoly, draw, 'A', '#1b68d1');
      drawRegionLabel(ctx, bPoly, draw, 'B', '#257a4f');
    }

    function drawCutLine(draw) {
      const p1 = imageToCanvas(state.line.p1, draw);
      const p2 = imageToCanvas(state.line.p2, draw);
      ctx.save();
      ctx.lineWidth = 3;
      ctx.strokeStyle = '#e11d48';
      ctx.beginPath();
      ctx.moveTo(p1.x, p1.y);
      ctx.lineTo(p2.x, p2.y);
      ctx.stroke();

      drawHandle(p1, true, state.selected === 'p1');
      drawHandle(p2, false, state.selected === 'p2');
      ctx.restore();
    }

    function drawHandle(point, filled, active) {
      ctx.save();
      ctx.lineWidth = active ? 4 : 3;
      ctx.strokeStyle = '#ffffff';
      ctx.fillStyle = filled ? '#e11d48' : '#15181d';
      ctx.beginPath();
      ctx.arc(point.x, point.y, 9, 0, Math.PI * 2);
      ctx.fill();
      ctx.stroke();
      if (!filled) {
        ctx.strokeStyle = '#e11d48';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(point.x, point.y, 5, 0, Math.PI * 2);
        ctx.stroke();
      }
      ctx.restore();
    }

    function drawPreviews() {
      if (!state.img || !state.job || !state.line) return;
      const aPoly = clipRectPolygon(state.job.width, state.job.height, state.line, 1);
      const bPoly = clipRectPolygon(state.job.width, state.job.height, state.line, -1);
      drawPreview(previewCtxA, el.previewA, aPoly);
      drawPreview(previewCtxB, el.previewB, bPoly);

      const totalArea = state.job.width * state.job.height;
      const aRatio = Math.max(0, Math.min(1, polygonArea(aPoly) / totalArea));
      const bRatio = Math.max(0, Math.min(1, polygonArea(bPoly) / totalArea));
      el.areaA.textContent = `${Math.round(aRatio * 100)}%`;
      el.areaB.textContent = `${Math.round(bRatio * 100)}%`;
    }

    function drawPreview(context, canvas, keepPoly) {
      const rect = canvas.getBoundingClientRect();
      context.clearRect(0, 0, rect.width, rect.height);
      const draw = containRect(state.job.width, state.job.height, rect.width, rect.height);
      context.save();
      context.beginPath();
      keepPoly.forEach((point, index) => {
        const mapped = imageToCanvas(point, draw);
        if (index === 0) context.moveTo(mapped.x, mapped.y);
        else context.lineTo(mapped.x, mapped.y);
      });
      context.closePath();
      context.clip();
      context.drawImage(state.img, draw.x, draw.y, draw.w, draw.h);
      context.restore();
    }

    function onPointerDown(event) {
      if (!state.job || !state.line || state.busy) return;
      event.preventDefault();
      el.canvas.setPointerCapture(event.pointerId);
      const pos = pointerPos(event);
      const hit = hitTest(pos);
      state.selected = hit.type;
      state.dragging = {
        type: hit.type,
        start: pos,
        line: JSON.parse(JSON.stringify(state.line))
      };
      drawAll();
    }

    function onPointerMove(event) {
      if (!state.dragging || !state.job || !state.draw) return;
      event.preventDefault();
      const pos = pointerPos(event);
      const startImg = canvasToImage(state.dragging.start, state.draw);
      const currentImg = canvasToImage(pos, state.draw);
      const dx = currentImg.x - startImg.x;
      const dy = currentImg.y - startImg.y;

      if (state.dragging.type === 'p1' || state.dragging.type === 'p2') {
        const key = state.dragging.type;
        const other = key === 'p1' ? state.dragging.line.p2 : state.dragging.line.p1;
        let next = clampPoint({
          x: state.dragging.line[key].x + dx,
          y: state.dragging.line[key].y + dy
        });
        if (event.shiftKey) {
          next = snapPoint(other, next);
        }
        state.line[key] = next;
      } else {
        state.line.p1 = clampPoint({
          x: state.dragging.line.p1.x + dx,
          y: state.dragging.line.p1.y + dy
        });
        state.line.p2 = clampPoint({
          x: state.dragging.line.p2.x + dx,
          y: state.dragging.line.p2.y + dy
        });
      }
      drawAll();
    }

    function endPointer(event) {
      if (state.dragging) {
        event.preventDefault();
      }
      state.dragging = null;
    }

    function hitTest(pos) {
      const p1 = imageToCanvas(state.line.p1, state.draw);
      const p2 = imageToCanvas(state.line.p2, state.draw);
      if (distance(pos, p1) <= 24) return { type: 'p1' };
      if (distance(pos, p2) <= 24) return { type: 'p2' };
      if (pointSegmentDistance(pos, p1, p2) <= 14) return { type: 'line' };
      return { type: 'line' };
    }

    function onKeyDown(event) {
      if (isTypingTarget(event.target)) return;
      if (event.code === 'Space') {
        event.preventDefault();
        state.spaceHide = true;
        drawAll();
        return;
      }
      if (state.busy && !['KeyZ'].includes(event.code)) return;

      switch (event.code) {
        case 'KeyR':
          event.preventDefault();
          resetLine();
          break;
        case 'KeyZ':
          event.preventDefault();
          undoLast();
          break;
        case 'Escape':
          event.preventDefault();
          skipCurrent();
          break;
        case 'KeyY':
          event.preventDefault();
          submitCurrent('yoru');
          break;
        case 'KeyS':
          event.preventDefault();
          submitCurrent('seadog');
          break;
        case 'ArrowUp':
        case 'ArrowDown':
        case 'ArrowLeft':
        case 'ArrowRight':
          event.preventDefault();
          nudgeLine(event.code, event.shiftKey ? 10 : 1);
          break;
      }
    }

    function nudgeLine(code, amount) {
      if (!state.line || !state.job) return;
      const delta = { x: 0, y: 0 };
      if (code === 'ArrowUp') delta.y = -amount;
      if (code === 'ArrowDown') delta.y = amount;
      if (code === 'ArrowLeft') delta.x = -amount;
      if (code === 'ArrowRight') delta.x = amount;

      if (state.selected === 'p1' || state.selected === 'p2') {
        state.line[state.selected] = clampPoint({
          x: state.line[state.selected].x + delta.x,
          y: state.line[state.selected].y + delta.y
        });
      } else {
        state.line.p1 = clampPoint({ x: state.line.p1.x + delta.x, y: state.line.p1.y + delta.y });
        state.line.p2 = clampPoint({ x: state.line.p2.x + delta.x, y: state.line.p2.y + delta.y });
      }
      drawAll();
    }

    async function submitCurrent(aLabel) {
      if (!state.job || !state.line || state.busy) return;
      const ratios = currentAreaRatios();
      if ((ratios.a < .05 || ratios.b < .05) && !confirm('A 或 B 的面積小於 5%，仍要送出嗎？')) {
        return;
      }
      setBusy(true);
      try {
        const payload = {
          job_id: state.job.job_id,
          filename: state.job.filename,
          x1: state.line.p1.x,
          y1: state.line.p1.y,
          x2: state.line.p2.x,
          y2: state.line.p2.y,
          a_label: aLabel
        };
        await apiPost('submit', payload);
        showNotice('已送出，載入下一張', 'ok');
        await loadNext(true);
      } catch (error) {
        showNotice(error.message, 'error');
      } finally {
        setBusy(false);
      }
    }

    async function submitSingle(mode, singleLabel) {
      if (!state.job || !state.line || state.busy) return;
      if (mode !== 'single_full') {
        const ratios = currentAreaRatios();
        const ratio = mode === 'single_a' ? ratios.a : ratios.b;
        if (ratio < .05 && !confirm('保留區域面積小於 5%，仍要送出嗎？')) {
          return;
        }
      }
      setBusy(true);
      try {
        const payload = {
          mode,
          single_label: singleLabel,
          job_id: state.job.job_id,
          filename: state.job.filename,
          x1: state.line.p1.x,
          y1: state.line.p1.y,
          x2: state.line.p2.x,
          y2: state.line.p2.y
        };
        await apiPost('submit', payload);
        showNotice('已送出單張，載入下一張', 'ok');
        await loadNext(true);
      } catch (error) {
        showNotice(error.message, 'error');
      } finally {
        setBusy(false);
      }
    }

    async function undoLast() {
      if (state.busy) return;
      setBusy(true);
      try {
        const data = await apiPost('undo', {});
        showNotice('上一張已復原', 'ok');
        if (data.stats) {
          renderStats(data.stats);
        }
        if (!state.job) {
          await loadNext(true);
        }
      } catch (error) {
        showNotice(error.message, 'error');
      } finally {
        setBusy(false);
      }
    }

    async function skipCurrent() {
      if (!state.job || state.busy) return;
      setBusy(true);
      try {
        await apiPost('skip', { job_id: state.job.job_id, reason: '使用者跳過' });
        showNotice('已跳過，載入下一張', 'ok');
        await loadNext(true);
      } catch (error) {
        showNotice(error.message, 'error');
      } finally {
        setBusy(false);
      }
    }

    async function moveCurrentToError(message) {
      if (!state.job || state.busy) return;
      setBusy(true);
      try {
        await apiPost('move_error', { job_id: state.job.job_id, message });
        showNotice('已移至問題檔，載入下一張', 'ok');
        await loadNext(true);
      } catch (error) {
        showNotice(error.message, 'error');
      } finally {
        setBusy(false);
      }
    }

    function setBusy(busy) {
      state.busy = busy;
      setButtons();
    }

    function setButtons() {
      const noJob = !state.job;
      [
        el.toggleMaskBtn,
        el.resetBtn,
        el.skipBtn,
        el.submitYoruBtn,
        el.submitSeadogBtn,
        el.fullYoruBtn,
        el.fullSeadogBtn,
        el.singleAYoruBtn,
        el.singleASeadogBtn,
        el.singleBYoruBtn,
        el.singleBSeadogBtn
      ].forEach(button => {
        button.disabled = state.busy || noJob;
      });
      el.undoBtn.disabled = state.busy;
      el.nextBtn.disabled = state.busy || Boolean(state.job);
    }

    async function apiGet(action) {
      const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, { cache: 'no-store' });
      return parseResponse(response);
    }

    async function apiPost(action, payload) {
      const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      return parseResponse(response);
    }

    async function parseResponse(response) {
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || data.status === 'error') {
        throw new Error((data && data.message) || `HTTP ${response.status}`);
      }
      return data;
    }

    function pointerPos(event) {
      const rect = el.canvas.getBoundingClientRect();
      return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    function containRect(srcW, srcH, dstW, dstH) {
      const scale = Math.min(dstW / srcW, dstH / srcH);
      const w = srcW * scale;
      const h = srcH * scale;
      return { x: (dstW - w) / 2, y: (dstH - h) / 2, w, h };
    }

    function imageToCanvas(point, draw) {
      return {
        x: draw.x + point.x * draw.w / state.job.width,
        y: draw.y + point.y * draw.h / state.job.height
      };
    }

    function canvasToImage(point, draw) {
      return clampPoint({
        x: (point.x - draw.x) * state.job.width / draw.w,
        y: (point.y - draw.y) * state.job.height / draw.h
      });
    }

    function clampPoint(point) {
      return {
        x: Math.max(0, Math.min(state.job.width, point.x)),
        y: Math.max(0, Math.min(state.job.height, point.y))
      };
    }

    function snapPoint(origin, point) {
      const dx = point.x - origin.x;
      const dy = point.y - origin.y;
      const length = Math.hypot(dx, dy) || 1;
      const angle = Math.atan2(dy, dx);
      const step = Math.PI / 4;
      const snapped = Math.round(angle / step) * step;
      return clampPoint({
        x: origin.x + Math.cos(snapped) * length,
        y: origin.y + Math.sin(snapped) * length
      });
    }

    function cross(line, x, y) {
      return (line.p2.x - line.p1.x) * (y - line.p1.y) - (line.p2.y - line.p1.y) * (x - line.p1.x);
    }

    function clipRectPolygon(width, height, line, side) {
      const poly = [
        { x: 0, y: 0 },
        { x: width, y: 0 },
        { x: width, y: height },
        { x: 0, y: height }
      ];
      const output = [];
      for (let i = 0; i < poly.length; i++) {
        const current = poly[i];
        const previous = poly[(i + poly.length - 1) % poly.length];
        const currentInside = inside(current, line, side);
        const previousInside = inside(previous, line, side);
        if (currentInside) {
          if (!previousInside) output.push(intersection(previous, current, line));
          output.push(current);
        } else if (previousInside) {
          output.push(intersection(previous, current, line));
        }
      }
      return output.filter(Boolean);
    }

    function inside(point, line, side) {
      const value = cross(line, point.x, point.y);
      return side > 0 ? value >= -0.00001 : value <= 0.00001;
    }

    function intersection(a, b, line) {
      const ca = cross(line, a.x, a.y);
      const cb = cross(line, b.x, b.y);
      const denom = cb - ca;
      if (Math.abs(denom) < 0.0000001) return null;
      const t = -ca / denom;
      return {
        x: a.x + (b.x - a.x) * t,
        y: a.y + (b.y - a.y) * t
      };
    }

    function fillPolygon(context, polygon, draw, color) {
      if (polygon.length < 3) return;
      context.save();
      context.fillStyle = color;
      context.beginPath();
      polygon.forEach((point, index) => {
        const mapped = imageToCanvas(point, draw);
        if (index === 0) context.moveTo(mapped.x, mapped.y);
        else context.lineTo(mapped.x, mapped.y);
      });
      context.closePath();
      context.fill();
      context.restore();
    }

    function drawRegionLabel(context, polygon, draw, label, color) {
      if (polygon.length < 3) return;
      const center = polygonCentroid(polygon);
      const mapped = imageToCanvas(center, draw);
      context.save();
      context.font = '800 42px ui-sans-serif, system-ui';
      context.textAlign = 'center';
      context.textBaseline = 'middle';
      context.lineWidth = 6;
      context.strokeStyle = 'rgba(255,255,255,.88)';
      context.fillStyle = color;
      context.strokeText(label, mapped.x, mapped.y);
      context.fillText(label, mapped.x, mapped.y);
      context.restore();
    }

    function polygonArea(polygon) {
      if (polygon.length < 3) return 0;
      let sum = 0;
      for (let i = 0; i < polygon.length; i++) {
        const a = polygon[i];
        const b = polygon[(i + 1) % polygon.length];
        sum += a.x * b.y - b.x * a.y;
      }
      return Math.abs(sum) / 2;
    }

    function polygonCentroid(polygon) {
      let x = 0;
      let y = 0;
      polygon.forEach(point => {
        x += point.x;
        y += point.y;
      });
      return { x: x / polygon.length, y: y / polygon.length };
    }

    function currentAreaRatios() {
      const a = clipRectPolygon(state.job.width, state.job.height, state.line, 1);
      const b = clipRectPolygon(state.job.width, state.job.height, state.line, -1);
      const total = state.job.width * state.job.height;
      return { a: polygonArea(a) / total, b: polygonArea(b) / total };
    }

    function distance(a, b) {
      return Math.hypot(a.x - b.x, a.y - b.y);
    }

    function pointSegmentDistance(point, a, b) {
      const dx = b.x - a.x;
      const dy = b.y - a.y;
      const len = dx * dx + dy * dy;
      if (len === 0) return distance(point, a);
      const t = Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / len));
      return distance(point, { x: a.x + t * dx, y: a.y + t * dy });
    }

    function isTypingTarget(target) {
      return target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
    }

    function escapeHtml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }
  </script>
</body>
</html>
