<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// This script never boots FOSSBilling and writes only its result below the extension.
$module = dirname(__DIR__);
$root = dirname($module, 2);
$baselinePath = $module . '/.local/core-baseline.json';
if (!is_file($baselinePath)) {
    exit("No pre-development SHA-256 baseline exists. A retrospective comparison cannot prove unchanged files.\n");
}
$raw = file_get_contents($baselinePath);
$baseline = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
$known = $unauthorized = $runtime = [];
foreach ($baseline as $file) {
    $name = str_replace('\\', '/', $file['Path']);
    $known[$name] = true;
    $path = $root . '/' . $name;
    $current = is_file($path) ? strtoupper(hash_file('sha256', $path)) : null;
    if ($current !== $file['Hash']) {
        $entry = ['path' => $name, 'change' => $current === null ? 'removed' : 'modified'];
        if (preg_match('~\Adata/(cache|log)/~', $name)) {
            $runtime[] = $entry;
        } else {
            $unauthorized[] = $entry;
        }
    }
}
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $file) use ($module): bool {
    return strcasecmp($file->getPathname(), $module) !== 0;
});
foreach (new RecursiveIteratorIterator($filter) as $file) {
    if (!$file->isFile()) continue;
    $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (isset($known[$name])) continue;
    $entry = ['path' => $name, 'change' => 'created'];
    if (preg_match('~\Adata/(cache|log)/~', $name)) {
        $runtime[] = $entry;
    } else {
        $unauthorized[] = $entry;
    }
}
$result = ['existing_files_checked' => count($baseline), 'unauthorized_changes' => $unauthorized, 'approved_runtime_changes' => $runtime];
file_put_contents($module . '/.local/integrity-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo 'Existing files checked: ' . count($baseline) . PHP_EOL;
echo 'Unauthorized changes: ' . count($unauthorized) . PHP_EOL;
echo 'Approved runtime cache/log changes: ' . count($runtime) . PHP_EOL;
foreach ($unauthorized as $entry) echo $entry['change'] . ': ' . $entry['path'] . PHP_EOL;
exit($unauthorized === [] ? 0 : 1);
