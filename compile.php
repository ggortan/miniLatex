<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $message, string $log = ''): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message, 'log' => $log], JSON_UNESCAPED_UNICODE);
    exit;
}

function safePath(string $path): bool {
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
        return false;
    }
    return (bool) preg_match('/^[a-zA-Z0-9_\.\/-]+$/', $path);
}

function runCommand(string $cmd, string $cwd, int $timeout = 20): array {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return [127, '', 'Falha ao iniciar comando.'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $start = time();

    while (true) {
        $status = proc_get_status($process);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }

        if ((time() - $start) > $timeout) {
            proc_terminate($process);
            break;
        }

        usleep(100000);
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $code = proc_close($process);
    return [$code, $out, $err];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Método não permitido.');
}

if (!trim((string) shell_exec('command -v pdflatex'))) {
    fail(500, 'pdflatex não está disponível no servidor.');
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data) || !isset($data['files']) || !is_array($data['files']) || !isset($data['mainFile'])) {
    fail(400, 'Payload inválido.');
}

$mainFile = (string) $data['mainFile'];
if (!safePath($mainFile)) {
    fail(400, 'Arquivo principal inválido.');
}

$files = $data['files'];
$totalBytes = 0;
$tmpRoot = sys_get_temp_dir() . '/miniLatex_' . bin2hex(random_bytes(8));
if (!mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
    fail(500, 'Não foi possível criar diretório temporário.');
}

$cleanup = static function () use ($tmpRoot): void {
    if (!is_dir($tmpRoot)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    @rmdir($tmpRoot);
};

register_shutdown_function($cleanup);

foreach ($files as $path => $entry) {
    if (!is_string($path) || !safePath($path) || !is_array($entry) || !isset($entry['content'])) {
        fail(400, 'Arquivo inválido no payload.');
    }
    $decoded = base64_decode((string) $entry['content'], true);
    if ($decoded === false) {
        fail(400, 'Falha ao decodificar conteúdo de arquivo.');
    }

    $totalBytes += strlen($decoded);
    if ($totalBytes > 15 * 1024 * 1024) {
        fail(400, 'Projeto excede limite de 15MB.');
    }

    $dest = $tmpRoot . '/' . $path;
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        fail(500, 'Falha ao criar estrutura de diretórios.');
    }
    if (file_put_contents($dest, $decoded) === false) {
        fail(500, 'Falha ao escrever arquivo temporário.');
    }
}

if (!is_file($tmpRoot . '/' . $mainFile)) {
    fail(400, 'Arquivo principal não existe no projeto.');
}

$log = '';
$mainBase = pathinfo($mainFile, PATHINFO_FILENAME);
$mainArg = escapeshellarg($mainFile);
$mainBaseArg = escapeshellarg($mainBase);

[$code1, $out1, $err1] = runCommand("pdflatex -interaction=nonstopmode -halt-on-error $mainArg", $tmpRoot, 30);
$log .= $out1 . "\n" . $err1;

$hasBib = false;
foreach (array_keys($files) as $f) {
    if (str_ends_with(strtolower((string) $f), '.bib')) {
        $hasBib = true;
        break;
    }
}

if ($code1 === 0 && $hasBib && trim((string) shell_exec('command -v bibtex'))) {
    [$codeBib, $outBib, $errBib] = runCommand("bibtex $mainBaseArg", $tmpRoot, 30);
    $log .= "\n" . $outBib . "\n" . $errBib;
    if ($codeBib === 0) {
        [$code2, $out2, $err2] = runCommand("pdflatex -interaction=nonstopmode -halt-on-error $mainArg", $tmpRoot, 30);
        $log .= "\n" . $out2 . "\n" . $err2;
        [$code3, $out3, $err3] = runCommand("pdflatex -interaction=nonstopmode -halt-on-error $mainArg", $tmpRoot, 30);
        $log .= "\n" . $out3 . "\n" . $err3;
        $code1 = $code3;
    }
}

$pdfPath = $tmpRoot . '/' . pathinfo($mainFile, PATHINFO_FILENAME) . '.pdf';
if ($code1 !== 0 || !is_file($pdfPath)) {
    fail(422, 'Erro na compilação LaTeX.', mb_substr($log, 0, 12000));
}

$pdf = file_get_contents($pdfPath);
if ($pdf === false) {
    fail(500, 'Falha ao ler PDF gerado.', mb_substr($log, 0, 12000));
}

echo json_encode([
    'ok' => true,
    'pdfBase64' => base64_encode($pdf),
    'log' => mb_substr($log, 0, 12000),
], JSON_UNESCAPED_UNICODE);
