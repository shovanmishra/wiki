<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WikiExporter — Export MediaWiki Pages to HTML</title>
  <meta name="description"
    content="Export MediaWiki pages to standalone HTML files. Upload a CSV of URLs or provide a single URL.">
  <link rel="stylesheet" href="assets/style.css">
</head>

<body>
  <div class="container">

    <!-- Header -->
    <div class="header">
      <div class="logo">📦</div>
      <h1>WikiExporter</h1>
      <p>Export MediaWiki pages into portable HTML files</p>
    </div>

    <!-- Mode Toggle -->
    <div class="mode-toggle" id="modeToggle">
      <button class="mode-toggle-btn active" data-mode="csv" id="btnModeCsv">
        <span class="mode-icon">📄</span> CSV Batch
      </button>
      <button class="mode-toggle-btn" data-mode="single" id="btnModeSingle">
        <span class="mode-icon">🔗</span> Single URL
      </button>
    </div>

    <!-- =================== CSV MODE =================== -->
    <div class="mode-panel active" id="panelCsv">
      <div class="card" id="csvFormCard">
        <div class="card-title"><span class="icon">📁</span> Upload CSV</div>

        <div class="alert error" id="csvAlertError"></div>

        <!-- Upload Zone -->
        <div class="upload-zone" id="uploadZone">
          <input type="file" accept=".csv,.txt" id="csvFileInput">
          <div class="upload-zone-content">
            <span class="upload-icon">☁️</span>
            <div class="upload-title">Drop your CSV file here</div>
            <div class="upload-subtitle">or <span class="accent">click to browse</span> · Accepts .csv files</div>
          </div>
          <div class="file-loaded-info">
            <div class="file-loaded-icon">✅</div>
            <div>
              <div class="file-loaded-name" id="loadedFileName">—</div>
              <div class="file-loaded-meta" id="loadedFileMeta">—</div>
            </div>
            <div class="file-loaded-change">Change file</div>
          </div>
        </div>

        <!-- CSV Preview -->
        <div class="csv-preview-section" id="csvPreviewSection">
          <div class="csv-preview-header">
            <span class="csv-preview-title">Preview</span>
            <span class="csv-preview-count" id="csvPreviewCount">0 URLs</span>
          </div>
          <div class="csv-preview-table-wrap">
            <table class="csv-preview-table" id="csvPreviewTable">
              <thead id="csvPreviewHead"></thead>
              <tbody id="csvPreviewBody"></tbody>
            </table>
          </div>
          <div class="csv-preview-more" id="csvPreviewMore" style="display:none;"></div>
        </div>

        <!-- Authentication (Optional) -->
        <details class="auth-section" id="authSectionCsv">
          <summary class="auth-toggle">🔐 Authentication (Optional)</summary>
          <div class="auth-fields">
            <div class="form-group">
              <label for="authUserCsv">SSO ID / Username</label>
              <input type="text" id="authUserCsv" placeholder="e.g. 503309389" autocomplete="username">
            </div>
            <div class="form-group">
              <label for="authPassCsv">Password</label>
              <input type="password" id="authPassCsv" placeholder="Enter password" autocomplete="current-password">
            </div>
            <p class="auth-hint">Required for internal/corporate wikis that need SSO login.</p>
          </div>
        </details>

        <!-- Start Button -->
        <div class="btn-group">
          <button class="btn btn-primary" id="btnCsvExport" disabled>
            <span id="btnCsvLabel">🚀 Start Batch Export</span>
          </button>
        </div>
      </div>
    </div>

    <!-- =================== SINGLE URL MODE =================== -->
    <div class="mode-panel" id="panelSingle">
      <div class="card" id="formCard">
        <div class="card-title"><span class="icon">🔗</span> Export Settings</div>

        <div class="alert error" id="alertError"></div>

        <form id="exportForm" autocomplete="off">
          <div class="form-group">
            <label for="wikiUrl">MediaWiki Root URL</label>
            <input type="url" id="wikiUrl" name="url" placeholder="https://en.wikipedia.org/wiki/Main_Page" required>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="pageCount">Number of Pages</label>
              <select id="pageCount" name="count">
                <option value="5">5 pages</option>
                <option value="10">10 pages</option>
                <option value="20">20 pages</option>
                <option value="50">50 pages</option>
              </select>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
              <button type="submit" class="btn btn-primary" id="btnExport">
                <span id="btnLabel">🚀 Start Export</span>
              </button>
            </div>
          </div>

          <!-- Authentication (Optional) -->
          <details class="auth-section" id="authSectionSingle">
            <summary class="auth-toggle">🔐 Authentication (Optional)</summary>
            <div class="auth-fields">
              <div class="form-group">
                <label for="authUser">SSO ID / Username</label>
                <input type="text" id="authUser" placeholder="e.g. 503309389" autocomplete="username">
              </div>
              <div class="form-group">
                <label for="authPass">Password</label>
                <input type="password" id="authPass" placeholder="Enter password" autocomplete="current-password">
              </div>
              <p class="auth-hint">Required for internal/corporate wikis that need SSO login.</p>
            </div>
          </details>
        </form>
      </div>
    </div>

    <!-- =================== SHARED: Progress =================== -->
    <div class="card progress-section" id="progressSection">
      <div class="card-title"><span class="icon">⏳</span> Export Progress</div>
      <div class="progress-status" id="progressStatus">Initializing…</div>
      <div class="progress-bar-container">
        <div class="progress-bar" id="progressBar"></div>
      </div>
      <div class="progress-count" id="progressCount">0 / 0</div>
      <div class="log-area" id="logArea"></div>
    </div>

    <!-- =================== CSV BATCH RESULTS =================== -->
    <div class="card results-section" id="batchResultsSection">
      <div class="card-title"><span class="icon">📊</span> Batch Results</div>

      <div class="batch-stats">
        <div class="batch-stat-card">
          <div class="batch-stat-value total-val" id="statTotal">0</div>
          <div class="batch-stat-label">Total</div>
        </div>
        <div class="batch-stat-card">
          <div class="batch-stat-value success-val" id="statSuccess">0</div>
          <div class="batch-stat-label">Success</div>
        </div>
        <div class="batch-stat-card">
          <div class="batch-stat-value failed-val" id="statFailed">0</div>
          <div class="batch-stat-label">Failed</div>
        </div>
      </div>

      <div class="batch-results-table-wrap">
        <table class="batch-results-table" id="batchResultsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>URL</th>
              <th>Title</th>
              <th>Status</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody id="batchResultsBody"></tbody>
        </table>
      </div>

      <div class="download-all-bar">
        <span style="font-size:0.85rem;color:var(--text-muted);">Files saved in <code>exports/</code></span>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button class="btn btn-sm btn-csv-download" id="btnDownloadCsv" onclick="downloadUpdatedCsv()">📥 Download
            Updated CSV</button>
          <button class="btn btn-sm btn-download" id="btnDownloadAllBatch" onclick="downloadAllFiles()">⬇ Download All
            HTML (ZIP)</button>
        </div>
      </div>
    </div>

    <!-- =================== SINGLE URL RESULTS =================== -->
    <div class="card results-section" id="resultsSection">
      <div class="card-title"><span class="icon">✅</span> Exported Files</div>
      <div class="results-summary" id="resultsSummary">
        <span class="count" id="totalExported">0</span>
        <span>pages exported successfully</span>
      </div>
      <ul class="file-list" id="fileList"></ul>
      <div class="download-all-bar">
        <span style="font-size:0.85rem;color:var(--text-muted);">Files saved in <code>exports/</code></span>
        <button class="btn btn-sm btn-download" id="btnDownloadAll" onclick="downloadAllFiles()">⬇ Download All
          (ZIP)</button>
      </div>
    </div>

    <!-- =================== DEBUG =================== -->
    <div class="card debug-section" id="debugSection">
      <div class="card-title" style="cursor:pointer;" onclick="toggleDebugBody()">
        <span class="icon">🛠</span> Debug Panel
        <span style="margin-left:auto;font-size:0.75rem;color:var(--text-muted);" id="debugToggle">▼ show</span>
      </div>
      <div id="debugBody" style="display:none;">
        <div class="debug-sub-title">Server Diagnostics</div>
        <div class="debug-diag" id="diagArea">Run an export to see diagnostics…</div>
        <div class="debug-sub-title" style="margin-top:16px;">Debug Messages</div>
        <div class="debug-log" id="debugLogArea">No debug messages yet.</div>
        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
          <a href="exports/debug.log" target="_blank" class="btn btn-sm btn-outline">📄 View Full Log</a>
          <button class="btn btn-sm btn-outline" onclick="checkAllFiles()">🔍 Verify Files Exist</button>
        </div>
      </div>
    </div>

  </div>

  <script>
    // ===================== SHARED STATE =====================
    let currentMode = 'csv'; // 'csv' | 'single'
    let exportedFiles = [];
    let debugMessages = [];
    let debugVisible = false;

    // CSV-specific state
    let csvData = null;      // { headers: [...], rows: [[...], ...] }
    let csvUrlColIndex = -1;
    let batchResults = [];   // [{ url, status, reason, title, filename, size }]

    // DOM refs — shared
    const progressSection = document.getElementById('progressSection');
    const progressBar = document.getElementById('progressBar');
    const progressStatus = document.getElementById('progressStatus');
    const progressCount = document.getElementById('progressCount');
    const logArea = document.getElementById('logArea');
    const debugSection = document.getElementById('debugSection');
    const debugBody = document.getElementById('debugBody');
    const debugToggle = document.getElementById('debugToggle');
    const diagArea = document.getElementById('diagArea');
    const debugLogArea = document.getElementById('debugLogArea');

    // DOM refs — single mode
    const form = document.getElementById('exportForm');
    const btnExport = document.getElementById('btnExport');
    const btnLabel = document.getElementById('btnLabel');
    const alertError = document.getElementById('alertError');
    const resultsSection = document.getElementById('resultsSection');
    const totalExported = document.getElementById('totalExported');
    const fileList = document.getElementById('fileList');

    // DOM refs — CSV mode
    const csvAlertError = document.getElementById('csvAlertError');
    const csvFileInput = document.getElementById('csvFileInput');
    const uploadZone = document.getElementById('uploadZone');
    const csvPreviewSection = document.getElementById('csvPreviewSection');
    const csvPreviewCount = document.getElementById('csvPreviewCount');
    const csvPreviewHead = document.getElementById('csvPreviewHead');
    const csvPreviewBody = document.getElementById('csvPreviewBody');
    const csvPreviewMore = document.getElementById('csvPreviewMore');
    const btnCsvExport = document.getElementById('btnCsvExport');
    const btnCsvLabel = document.getElementById('btnCsvLabel');
    const batchResultsSection = document.getElementById('batchResultsSection');
    const batchResultsBody = document.getElementById('batchResultsBody');

    // ===================== MODE TOGGLE =====================
    document.querySelectorAll('.mode-toggle-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const mode = btn.dataset.mode;
        currentMode = mode;

        document.querySelectorAll('.mode-toggle-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        document.getElementById('panelCsv').classList.toggle('active', mode === 'csv');
        document.getElementById('panelSingle').classList.toggle('active', mode === 'single');

        // Hide results when switching
        progressSection.classList.remove('active');
        resultsSection.classList.remove('active');
        batchResultsSection.classList.remove('active');
        debugSection.classList.remove('active');
      });
    });

    // ===================== CSV PARSER =====================
    function parseCSV(text) {
      const lines = [];
      let current = '';
      let inQuotes = false;
      
      for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (ch === '"') {
          if (inQuotes && text[i + 1] === '"') {
            current += '"';
            i++;
          } else {
            inQuotes = !inQuotes;
          }
        } else if ((ch === ',' || ch === '\n' || ch === '\r') && !inQuotes) {
          if (ch === '\n' || ch === '\r') {
            if (ch === '\r' && text[i + 1] === '\n') i++;
            if (current.length > 0 || lines.length > 0) {
              if (!lines.length) {
                lines.push([current]);
              } else {
                lines[lines.length - 1].push(current);
                lines.push([]);
              }
            }
            current = '';
          } else {
            if (!lines.length) lines.push([]);
            lines[lines.length - 1].push(current);
            current = '';
          }
        } else {
          current += ch;
        }
      }
      // last field
      if (current.length > 0 || (lines.length > 0 && lines[lines.length - 1].length > 0)) {
        if (!lines.length) lines.push([]);
        lines[lines.length - 1].push(current);
      }
      // Remove trailing empty rows
      while (lines.length && lines[lines.length - 1].every(c => c.trim() === '')) {
        lines.pop();
      }
      return lines;
    }

    function detectUrlColumn(headers) {
      // Try to find a column named 'url' (case insensitive)
      for (let i = 0; i < headers.length; i++) {
        const h = headers[i].trim().toLowerCase();
        if (h === 'url' || h === 'urls' || h === 'link' || h === 'page_url' || h === 'page url' || h === 'wiki_url') {
          return i;
        }
      }
      // If single column, assume it's URLs
      if (headers.length === 1) return 0;
      // Check first non-header row to find a column that looks like a URL
      return -1;
    }

    // ===================== CSV FILE HANDLING =====================
    csvFileInput.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (file) handleCsvFile(file);
    });

    // Drag & drop
    uploadZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      uploadZone.classList.add('drag-over');
    });
    uploadZone.addEventListener('dragleave', () => {
      uploadZone.classList.remove('drag-over');
    });
    uploadZone.addEventListener('drop', (e) => {
      e.preventDefault();
      uploadZone.classList.remove('drag-over');
      const file = e.dataTransfer.files[0];
      if (file) handleCsvFile(file);
    });

    function handleCsvFile(file) {
      if (!file.name.match(/\.(csv|txt)$/i)) {
        showCsvError('Please upload a .csv file.');
        return;
      }

      csvAlertError.classList.remove('active');

      const reader = new FileReader();
      reader.onload = (e) => {
        const text = e.target.result;
        const parsed = parseCSV(text);

        if (parsed.length < 2) {
          showCsvError('CSV file appears to be empty or has no data rows.');
          return;
        }

        const headers = parsed[0].map(h => h.trim());
        const rows = parsed.slice(1).filter(r => r.some(c => c.trim() !== ''));

        csvUrlColIndex = detectUrlColumn(headers);
        if (csvUrlColIndex === -1) {
          // Try first column that has URL-like content
          for (let c = 0; c < headers.length; c++) {
            if (rows.length > 0 && rows[0][c] && rows[0][c].trim().match(/^https?:\/\//i)) {
              csvUrlColIndex = c;
              break;
            }
          }
        }
        if (csvUrlColIndex === -1) {
          showCsvError('Could not find a URL column. Ensure your CSV has a header named "url" or contains URLs starting with http/https.');
          return;
        }

        csvData = { headers, rows, originalHeaders: [...headers] };

        // Update UI
        uploadZone.classList.add('has-file');
        document.getElementById('loadedFileName').textContent = file.name;
        document.getElementById('loadedFileMeta').textContent = `${rows.length} URLs · ${(file.size / 1024).toFixed(1)} KB · URL column: "${headers[csvUrlColIndex]}"`;

        renderCsvPreview();
        btnCsvExport.disabled = false;
      };
      reader.readAsText(file);
    }

    function renderCsvPreview() {
      if (!csvData) return;
      const { headers, rows } = csvData;
      const maxPreview = 10;

      csvPreviewSection.classList.add('active');
      csvPreviewCount.textContent = `${rows.length} URLs found`;

      // Header row
      let headHtml = '<tr><th>#</th>';
      headers.forEach((h, i) => {
        const isUrl = (i === csvUrlColIndex);
        headHtml += `<th${isUrl ? ' style="color:var(--success);"' : ''}>${escapeHtml(h)}${isUrl ? ' 🔗' : ''}</th>`;
      });
      headHtml += '</tr>';
      csvPreviewHead.innerHTML = headHtml;

      // Body rows
      let bodyHtml = '';
      const showRows = rows.slice(0, maxPreview);
      showRows.forEach((row, idx) => {
        bodyHtml += `<tr><td class="row-num">${idx + 1}</td>`;
        headers.forEach((_, ci) => {
          bodyHtml += `<td>${escapeHtml(row[ci] || '')}</td>`;
        });
        bodyHtml += '</tr>';
      });
      csvPreviewBody.innerHTML = bodyHtml;

      if (rows.length > maxPreview) {
        csvPreviewMore.style.display = 'block';
        csvPreviewMore.textContent = `… and ${rows.length - maxPreview} more rows`;
      } else {
        csvPreviewMore.style.display = 'none';
      }
    }

    function showCsvError(msg) {
      csvAlertError.textContent = '⚠ ' + msg;
      csvAlertError.classList.add('active');
    }

    // ===================== CSV BATCH EXPORT =====================
    btnCsvExport.addEventListener('click', async () => {
      if (!csvData || csvData.rows.length === 0) return;

      // Collect URLs
      const urls = csvData.rows.map(row => (row[csvUrlColIndex] || '').trim()).filter(u => u.length > 0);

      if (urls.length === 0) {
        showCsvError('No valid URLs found in the selected column.');
        return;
      }

      // Reset UI
      csvAlertError.classList.remove('active');
      batchResultsSection.classList.remove('active');
      logArea.innerHTML = '';
      exportedFiles = [];
      batchResults = [];
      debugMessages = [];
      debugLogArea.innerHTML = '';
      diagArea.innerHTML = 'Waiting for server response…';

      // Initialize batch results (pending state for all)
      csvData.rows.forEach((row, idx) => {
        const url = (row[csvUrlColIndex] || '').trim();
        batchResults.push({
          url: url,
          status: 'pending',
          reason: '',
          title: '',
          filename: '',
          size: '',
          rowIndex: idx,
        });
      });

      // Show progress & debug
      progressSection.classList.add('active');
      debugSection.classList.add('active');
      progressBar.style.width = '0%';
      progressStatus.textContent = 'Connecting to server…';
      progressCount.textContent = `0 / ${urls.length}`;

      // Disable button
      btnCsvExport.disabled = true;
      btnCsvLabel.innerHTML = '<span class="spinner"></span> Exporting…';

      try {
        const authUserCsv = document.getElementById('authUserCsv').value.trim();
        const authPassCsv = document.getElementById('authPassCsv').value;
        const payload = { mode: 'batch', urls };
        if (authUserCsv) { payload.auth_user = authUserCsv; payload.auth_pass = authPassCsv; }
        const response = await fetch('export.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        if (!response.ok) {
          showCsvError(`Server returned HTTP ${response.status} ${response.statusText}`);
          addDebugMsg(`HTTP Error: ${response.status} ${response.statusText}`);
          btnCsvExport.disabled = false;
          btnCsvLabel.innerHTML = '🚀 Start Batch Export';
          return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();

          for (const line of lines) {
            if (!line.trim()) continue;
            try {
              const msg = JSON.parse(line);
              handleBatchMessage(msg, urls.length);
            } catch (parseErr) {
              addDebugMsg(`Non-JSON response: ${line.substring(0, 200)}`);
            }
          }
        }

        // Process remaining buffer
        if (buffer.trim()) {
          try {
            const msg = JSON.parse(buffer);
            handleBatchMessage(msg, urls.length);
          } catch (e) {
            addDebugMsg(`Final buffer non-JSON: ${buffer.substring(0, 200)}`);
          }
        }

      } catch (err) {
        showCsvError('Connection failed: ' + err.message);
        addDebugMsg(`Fetch exception: ${err.message}`);
      }

      btnCsvExport.disabled = false;
      btnCsvLabel.innerHTML = '🚀 Start Batch Export';
    });

    function handleBatchMessage(msg, totalUrls) {
      switch (msg.type) {
        case 'log':
          addLog(msg.message, msg.level || 'info');
          break;

        case 'progress':
          const pct = Math.round((msg.current / msg.total) * 100);
          progressBar.style.width = pct + '%';
          progressStatus.textContent = msg.message || 'Exporting…';
          progressCount.textContent = `${msg.current} / ${msg.total}`;
          break;

        case 'url_status': {
          // Find matching batchResult by url
          const idx = batchResults.findIndex(r => r.url === msg.url);
          if (idx !== -1) {
            batchResults[idx].status = msg.status;
            batchResults[idx].reason = msg.reason || '';
            batchResults[idx].title = msg.title || '';
            batchResults[idx].filename = msg.filename || '';
            batchResults[idx].size = msg.size || '';
          }
          if (msg.status === 'success') {
            exportedFiles.push({
              title: msg.title || '',
              filename: msg.filename || '',
              size: msg.size || '',
              url: msg.url,
            });
          }
          break;
        }

        case 'batch_complete':
          showBatchResults();
          break;

        case 'error':
          showCsvError(msg.message);
          addDebugMsg(`ERROR: ${msg.message}`);
          break;

        case 'diagnostics':
          showDiagnostics(msg.data);
          break;

        case 'debug':
          addDebugMsg(msg.message);
          break;
      }
    }

    function showBatchResults() {
      const successCount = batchResults.filter(r => r.status === 'success').length;
      const failedCount = batchResults.filter(r => r.status === 'failed').length;

      document.getElementById('statTotal').textContent = batchResults.length;
      document.getElementById('statSuccess').textContent = successCount;
      document.getElementById('statFailed').textContent = failedCount;

      // Build results table
      let html = '';
      batchResults.forEach((r, idx) => {
        const statusClass = r.status === 'success' ? 'success' : (r.status === 'failed' ? 'failed' : 'pending');
        const statusIcon = r.status === 'success' ? '✅' : (r.status === 'failed' ? '❌' : '⏳');
        const detail = r.status === 'success'
          ? `<a href="exports/${encodeURIComponent(r.filename)}" target="_blank" style="color:var(--accent-hover);text-decoration:none;">${escapeHtml(r.filename)}</a>`
          : escapeHtml(r.reason || '—');

        html += `<tr>
          <td class="row-num">${idx + 1}</td>
          <td title="${escapeHtml(r.url)}">${escapeHtml(r.url)}</td>
          <td>${escapeHtml(r.title || '—')}</td>
          <td><span class="status-badge ${statusClass}">${statusIcon} ${r.status}</span></td>
          <td>${detail}</td>
        </tr>`;
      });
      batchResultsBody.innerHTML = html;

      batchResultsSection.classList.add('active');
      progressStatus.textContent = 'Batch export complete!';
      progressBar.style.width = '100%';
    }

    function downloadUpdatedCsv() {
      if (!csvData) return;
      const { originalHeaders, rows } = csvData;

      // Build CSV with status column appended
      const outHeaders = [...originalHeaders, 'status', 'status_detail'];
      let csv = outHeaders.map(h => csvEscapeField(h)).join(',') + '\n';

      rows.forEach((row, idx) => {
        const result = batchResults[idx] || { status: 'unknown', reason: '' };
        const outRow = [...row];
        // Pad row to match original headers length
        while (outRow.length < originalHeaders.length) outRow.push('');
        outRow.push(result.status);
        outRow.push(result.status === 'failed' ? result.reason : (result.filename || ''));
        csv += outRow.map(f => csvEscapeField(f)).join(',') + '\n';
      });

      // Download
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'wikiexporter_results.csv';
      a.click();
      URL.revokeObjectURL(url);
    }

    function csvEscapeField(val) {
      val = String(val ?? '');
      if (val.includes(',') || val.includes('"') || val.includes('\n')) {
        return '"' + val.replace(/"/g, '""') + '"';
      }
      return val;
    }


    // ===================== SINGLE URL MODE =====================
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const url = document.getElementById('wikiUrl').value.trim();
      const count = document.getElementById('pageCount').value;

      if (!url) return;

      // Reset UI
      alertError.classList.remove('active');
      resultsSection.classList.remove('active');
      fileList.innerHTML = '';
      logArea.innerHTML = '';
      exportedFiles = [];
      debugMessages = [];
      debugLogArea.innerHTML = '';
      diagArea.innerHTML = 'Waiting for server response…';

      // Show progress & debug
      progressSection.classList.add('active');
      debugSection.classList.add('active');
      progressBar.style.width = '0%';
      progressStatus.textContent = 'Connecting to wiki…';
      progressCount.textContent = `0 / ${count}`;

      // Disable button
      btnExport.disabled = true;
      btnLabel.innerHTML = '<span class="spinner"></span> Exporting…';

      try {
        const authUser = document.getElementById('authUser').value.trim();
        const authPass = document.getElementById('authPass').value;
        const payload = { url, count: parseInt(count) };
        if (authUser) { payload.auth_user = authUser; payload.auth_pass = authPass; }
        const response = await fetch('export.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        if (!response.ok) {
          showError(`Server returned HTTP ${response.status} ${response.statusText}`);
          addDebugMsg(`HTTP Error: ${response.status} ${response.statusText}`);
          btnExport.disabled = false;
          btnLabel.innerHTML = '🚀 Start Export';
          return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();

          for (const line of lines) {
            if (!line.trim()) continue;
            try {
              const msg = JSON.parse(line);
              handleMessage(msg, count);
            } catch (parseErr) {
              addDebugMsg(`Non-JSON response line: ${line.substring(0, 200)}`);
            }
          }
        }

        if (buffer.trim()) {
          try {
            const msg = JSON.parse(buffer);
            handleMessage(msg, count);
          } catch (e) {
            addDebugMsg(`Final buffer non-JSON: ${buffer.substring(0, 200)}`);
          }
        }

      } catch (err) {
        showError('Connection failed: ' + err.message);
        addDebugMsg(`Fetch exception: ${err.message}`);
      }

      btnExport.disabled = false;
      btnLabel.innerHTML = '🚀 Start Export';
    });

    function handleMessage(msg, totalCount) {
      switch (msg.type) {
        case 'log':
          addLog(msg.message, msg.level || 'info');
          break;
        case 'progress':
          const pct = Math.round((msg.current / msg.total) * 100);
          progressBar.style.width = pct + '%';
          progressStatus.textContent = msg.message || 'Exporting…';
          progressCount.textContent = `${msg.current} / ${msg.total}`;
          break;
        case 'file':
          exportedFiles.push(msg);
          break;
        case 'complete':
          showResults(msg);
          break;
        case 'error':
          showError(msg.message);
          addDebugMsg(`ERROR: ${msg.message}`);
          break;
        case 'diagnostics':
          showDiagnostics(msg.data);
          break;
        case 'debug':
          addDebugMsg(msg.message);
          break;
      }
    }


    // ===================== SHARED HELPERS =====================
    function addLog(text, level) {
      const div = document.createElement('div');
      div.className = 'log-entry ' + level;
      div.textContent = '› ' + text;
      logArea.appendChild(div);
      logArea.scrollTop = logArea.scrollHeight;
    }

    function addDebugMsg(text) {
      debugMessages.push(text);
      const div = document.createElement('div');
      div.className = 'debug-entry';
      div.textContent = `[${new Date().toLocaleTimeString()}] ${text}`;
      debugLogArea.appendChild(div);
      debugLogArea.scrollTop = debugLogArea.scrollHeight;
      if (text.startsWith('ERROR') || text.includes('failed') || text.includes('Failed')) {
        if (!debugVisible) toggleDebugBody();
      }
    }

    function toggleDebugBody() {
      debugVisible = !debugVisible;
      debugBody.style.display = debugVisible ? 'block' : 'none';
      debugToggle.textContent = debugVisible ? '▲ hide' : '▼ show';
    }

    function showDiagnostics(data) {
      let html = '<table class="diag-table">';
      const labels = {
        php_version: 'PHP Version',
        server_software: 'Server',
        document_root: 'Doc Root',
        script_path: 'Script Path',
        exports_dir: 'Exports Dir',
        exports_exists: 'Dir Exists',
        exports_writable: 'Dir Writable',
        exports_perms: 'Dir Permissions',
        process_user: 'Process User',
        curl_available: 'cURL Available',
        allow_url_fopen: 'allow_url_fopen',
        open_basedir: 'open_basedir',
        memory_limit: 'Memory Limit',
        max_execution_time: 'Max Exec Time',
      };
      for (const [key, label] of Object.entries(labels)) {
        const val = data[key] || 'N/A';
        const isWarning = val === 'NO' || val === 'FAILED';
        html += `<tr><td>${label}</td><td class="${isWarning ? 'diag-warn' : ''}">${escapeHtml(val)}</td></tr>`;
      }
      html += '</table>';
      diagArea.innerHTML = html;

      if (data.exports_writable === 'NO' || data.curl_available === 'NO' || data.allow_url_fopen === 'NO') {
        if (!debugVisible) toggleDebugBody();
      }
    }

    function showError(message) {
      alertError.textContent = '⚠ ' + message;
      alertError.classList.add('active');
      progressSection.classList.remove('active');
    }

    function showResults(data) {
      const files = data.files || exportedFiles;
      totalExported.textContent = files.length;
      fileList.innerHTML = '';

      files.forEach((f, i) => {
        const li = document.createElement('li');
        li.className = 'file-item';
        li.style.animationDelay = (i * 0.05) + 's';
        li.innerHTML = `
          <div class="file-info">
            <div class="file-icon">📄</div>
            <div>
              <div class="file-name">${escapeHtml(f.title)}</div>
              <div class="file-meta">${escapeHtml(f.filename)} · ${escapeHtml(f.size)}</div>
            </div>
          </div>
          <div class="file-actions">
            <a href="exports/${encodeURIComponent(f.filename)}" target="_blank" class="btn btn-sm btn-outline">👁 Preview</a>
            <a href="exports/${encodeURIComponent(f.filename)}" download class="btn btn-sm btn-download">⬇ Download</a>
          </div>
        `;
        fileList.appendChild(li);
      });

      resultsSection.classList.add('active');
      progressStatus.textContent = 'Export complete!';
      progressBar.style.width = '100%';
    }

    function escapeHtml(str) {
      const d = document.createElement('div');
      d.textContent = str;
      return d.innerHTML;
    }

    function downloadAllFiles() {
      exportedFiles.forEach((f, i) => {
        setTimeout(() => {
          const a = document.createElement('a');
          a.href = 'exports/' + encodeURIComponent(f.filename);
          a.download = f.filename;
          a.click();
        }, i * 200);
      });
    }

    async function checkAllFiles() {
      if (exportedFiles.length === 0) {
        addDebugMsg('No files to check — run an export first.');
        return;
      }
      addDebugMsg(`Checking ${exportedFiles.length} files...`);
      for (const f of exportedFiles) {
        const fileUrl = 'exports/' + encodeURIComponent(f.filename);
        try {
          const resp = await fetch(fileUrl, { method: 'HEAD' });
          if (resp.ok) {
            addDebugMsg(`✅ ${f.filename} — exists (HTTP ${resp.status})`);
          } else {
            addDebugMsg(`❌ ${f.filename} — NOT FOUND (HTTP ${resp.status})`);
          }
        } catch (e) {
          addDebugMsg(`❌ ${f.filename} — fetch error: ${e.message}`);
        }
      }
      addDebugMsg('File check complete.');
    }
  </script>
</body>

</html>
