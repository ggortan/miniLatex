<?php
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>miniLatex Preview</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { margin: 0; padding: .75rem; background: #f8f9fa; }
    #pdf { width: 100%; height: 80vh; border: 1px solid #dee2e6; border-radius: .375rem; background: #fff; }
  </style>
</head>
<body>
  <h1 class="h6">Pré-visualização desacoplada</h1>
  <iframe id="pdf" title="Preview PDF"></iframe>
  <pre id="log" class="small mt-2 mb-0 p-2 bg-dark text-light rounded" style="max-height: 18vh; overflow:auto;"></pre>
<script>
  const pdf = document.getElementById('pdf');
  const log = document.getElementById('log');
  window.addEventListener('message', (event) => {
    if (!event.data || event.data.type !== 'preview-update') return;
    if (event.data.pdfBase64) pdf.src = 'data:application/pdf;base64,' + event.data.pdfBase64;
    log.textContent = event.data.log || '';
  });
</script>
</body>
</html>
