<?php
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>miniLatex</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { min-height: 100vh; }
    #editor { min-height: 72vh; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
    #previewFrame { width: 100%; min-height: 72vh; border: 1px solid #dee2e6; border-radius: .375rem; background: #fff; }
    .file-item { cursor: pointer; }
  </style>
</head>
<body class="bg-light">
  <div class="container-fluid py-3">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
      <h1 class="h4 mb-0 me-auto">miniLatex</h1>
      <button id="newProjectBtn" class="btn btn-outline-secondary btn-sm">Novo</button>
      <input id="importInput" class="d-none" type="file" multiple>
      <button id="importBtn" class="btn btn-outline-primary btn-sm">Importar arquivos</button>
      <button id="saveBrowserBtn" class="btn btn-outline-success btn-sm">Salvar no navegador</button>
      <button id="exportBtn" class="btn btn-outline-success btn-sm">Exportar projeto</button>
      <button id="compileBtn" class="btn btn-primary btn-sm">Compilar PDF</button>
      <button id="downloadPdfBtn" class="btn btn-outline-primary btn-sm" disabled>Baixar PDF</button>
      <button id="detachBtn" class="btn btn-outline-dark btn-sm">Desacoplar preview</button>
      <div class="form-check form-switch ms-2">
        <input class="form-check-input" type="checkbox" role="switch" id="autoCompileSwitch" checked>
        <label class="form-check-label" for="autoCompileSwitch">Pré-visualização em tempo real</label>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-12 col-md-3 col-lg-2">
        <div class="card">
          <div class="card-header py-2">Arquivos</div>
          <ul id="fileList" class="list-group list-group-flush"></ul>
        </div>
      </div>

      <div class="col-12 col-md-4 col-lg-5">
        <div class="card">
          <div class="card-header py-2 d-flex align-items-center gap-2">
            <span>Editor</span>
            <span id="currentFileBadge" class="badge text-bg-secondary">main.tex</span>
          </div>
          <div class="card-body p-2">
            <textarea id="editor" class="form-control" spellcheck="false"></textarea>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-5 col-lg-5">
        <div class="card">
          <div class="card-header py-2">Preview PDF</div>
          <div class="card-body p-2">
            <iframe id="previewFrame" title="Pré-visualização PDF"></iframe>
            <pre id="compileLog" class="small mt-2 mb-0 p-2 bg-dark text-light rounded" style="max-height: 180px; overflow:auto;"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
(() => {
  const STORAGE_KEY = 'miniLatexProject';
  const TEXT_EXT = ['.tex', '.bib', '.sty', '.cls', '.bst', '.txt', '.md'];
  let project = {
    mainFile: 'main.tex',
    files: {
      'main.tex': {
        isText: true,
        content: btoa(unescape(encodeURIComponent('\\documentclass{article}\n\\begin{document}\nOlá, miniLatex!\n\\end{document}\n')))
      }
    }
  };
  let currentFile = 'main.tex';
  let lastPdfBase64 = '';
  let detachedWindow = null;
  let compileTimer = null;

  const el = {
    fileList: document.getElementById('fileList'),
    editor: document.getElementById('editor'),
    currentFileBadge: document.getElementById('currentFileBadge'),
    importInput: document.getElementById('importInput'),
    importBtn: document.getElementById('importBtn'),
    newProjectBtn: document.getElementById('newProjectBtn'),
    saveBrowserBtn: document.getElementById('saveBrowserBtn'),
    exportBtn: document.getElementById('exportBtn'),
    compileBtn: document.getElementById('compileBtn'),
    downloadPdfBtn: document.getElementById('downloadPdfBtn'),
    autoCompileSwitch: document.getElementById('autoCompileSwitch'),
    previewFrame: document.getElementById('previewFrame'),
    compileLog: document.getElementById('compileLog'),
    detachBtn: document.getElementById('detachBtn')
  };

  function decodeUtf8(base64) {
    try { return decodeURIComponent(escape(atob(base64))); } catch (_) { return atob(base64); }
  }

  function encodeUtf8(text) {
    try { return btoa(unescape(encodeURIComponent(text))); } catch (_) { return btoa(text); }
  }

  function normalizePath(name) {
    return name.replace(/^\/+/, '').replace(/\\/g, '/').replace(/\.{2,}/g, '.');
  }

  function isTextFile(path) {
    const p = path.toLowerCase();
    return TEXT_EXT.some(ext => p.endsWith(ext));
  }

  function renderFiles() {
    const names = Object.keys(project.files).sort();
    el.fileList.innerHTML = '';
    names.forEach((name) => {
      const li = document.createElement('li');
      li.className = 'list-group-item file-item py-2 d-flex justify-content-between align-items-center';
      const title = document.createElement('span');
      title.textContent = name;
      li.appendChild(title);
      if (name === project.mainFile) {
        const badge = document.createElement('span');
        badge.className = 'badge text-bg-primary';
        badge.textContent = 'main';
        li.appendChild(badge);
      }
      li.addEventListener('click', () => selectFile(name));
      el.fileList.appendChild(li);
    });
    if (!project.files[currentFile]) currentFile = project.mainFile;
    el.currentFileBadge.textContent = currentFile;
    const file = project.files[currentFile];
    el.editor.value = (file && file.isText) ? decodeUtf8(file.content) : '';
    el.editor.disabled = !(file && file.isText);
  }

  function selectFile(name) {
    persistEditor();
    currentFile = name;
    renderFiles();
  }

  function persistEditor() {
    const file = project.files[currentFile];
    if (file && file.isText) file.content = encodeUtf8(el.editor.value);
  }

  function saveBrowser() {
    persistEditor();
    localStorage.setItem(STORAGE_KEY, JSON.stringify(project));
  }

  function loadBrowser() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    try {
      const parsed = JSON.parse(raw);
      if (parsed && parsed.files && parsed.mainFile) project = parsed;
    } catch (_) {}
  }

  function updatePdf(base64, logText) {
    lastPdfBase64 = base64 || '';
    el.downloadPdfBtn.disabled = !lastPdfBase64;
    if (base64) {
      el.previewFrame.src = 'data:application/pdf;base64,' + base64;
      el.compileLog.textContent = logText || 'Compilação concluída com sucesso.';
    }
    if (detachedWindow && !detachedWindow.closed) {
      detachedWindow.postMessage({ type: 'preview-update', pdfBase64: lastPdfBase64, log: el.compileLog.textContent }, '*');
    }
  }

  async function compileProject() {
    persistEditor();
    el.compileBtn.disabled = true;
    el.compileLog.textContent = 'Compilando...';
    try {
      const res = await fetch('compile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(project)
      });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error((data && data.error) || 'Falha ao compilar.');
      updatePdf(data.pdfBase64, data.log || 'Compilação concluída com sucesso.');
    } catch (err) {
      el.compileLog.textContent = String(err.message || err);
      updatePdf('', el.compileLog.textContent);
    } finally {
      el.compileBtn.disabled = false;
    }
  }

  function maybeAutoCompile() {
    if (!el.autoCompileSwitch.checked) return;
    clearTimeout(compileTimer);
    compileTimer = setTimeout(compileProject, 1200);
  }

  function exportProject() {
    persistEditor();
    const blob = new Blob([JSON.stringify(project, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'miniLatex-project.json';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  function downloadPdf() {
    if (!lastPdfBase64) return;
    const a = document.createElement('a');
    a.href = 'data:application/pdf;base64,' + lastPdfBase64;
    a.download = 'output.pdf';
    a.click();
  }

  async function importFiles(files) {
    if (!files || !files.length) return;
    if (files.length === 1 && files[0].name.endsWith('.json')) {
      const txt = await files[0].text();
      const parsed = JSON.parse(txt);
      if (parsed && parsed.files && parsed.mainFile) {
        project = parsed;
        currentFile = parsed.mainFile;
        renderFiles();
        maybeAutoCompile();
        return;
      }
    }

    const imported = {};
    for (const file of files) {
      const relative = normalizePath(file.webkitRelativePath || file.name);
      const isText = isTextFile(relative);
      if (isText) {
        imported[relative] = {
          isText: true,
          content: encodeUtf8(await file.text())
        };
      } else {
        const arr = new Uint8Array(await file.arrayBuffer());
        let binary = '';
        const chunk = 8192;
        for (let i = 0; i < arr.length; i += chunk) {
          binary += String.fromCharCode(...arr.slice(i, i + chunk));
        }
        imported[relative] = { isText: false, content: btoa(binary) };
      }
    }

    project.files = { ...project.files, ...imported };
    const texFiles = Object.keys(project.files).filter(f => f.toLowerCase().endsWith('.tex'));
    if (texFiles.length && !project.files[project.mainFile]) project.mainFile = texFiles[0];
    if (texFiles.length && !texFiles.includes(currentFile)) currentFile = project.mainFile;
    renderFiles();
    maybeAutoCompile();
  }

  function openDetachedPreview() {
    if (!detachedWindow || detachedWindow.closed) {
      detachedWindow = window.open('preview.php', 'miniLatexPreview', 'width=900,height=700');
    }
    setTimeout(() => {
      if (detachedWindow && !detachedWindow.closed) {
        detachedWindow.postMessage({ type: 'preview-update', pdfBase64: lastPdfBase64, log: el.compileLog.textContent }, '*');
      }
    }, 300);
  }

  el.importBtn.addEventListener('click', () => el.importInput.click());
  el.importInput.addEventListener('change', async (e) => {
    await importFiles(Array.from(e.target.files || []));
    e.target.value = '';
  });

  el.newProjectBtn.addEventListener('click', () => {
    project = {
      mainFile: 'main.tex',
      files: {
        'main.tex': {
          isText: true,
          content: encodeUtf8('\\documentclass{article}\n\\begin{document}\nNovo projeto\n\\end{document}\n')
        }
      }
    };
    currentFile = 'main.tex';
    renderFiles();
    updatePdf('', '');
  });

  el.saveBrowserBtn.addEventListener('click', saveBrowser);
  el.exportBtn.addEventListener('click', exportProject);
  el.compileBtn.addEventListener('click', compileProject);
  el.downloadPdfBtn.addEventListener('click', downloadPdf);
  el.detachBtn.addEventListener('click', openDetachedPreview);
  el.editor.addEventListener('input', () => {
    persistEditor();
    maybeAutoCompile();
  });

  loadBrowser();
  renderFiles();
  maybeAutoCompile();
})();
</script>
</body>
</html>
