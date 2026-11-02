<?php
/** Create the installable NexaPress ZIP without tests, fixtures or development files. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$manifest = json_decode((string)file_get_contents(__DIR__ . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$id = $manifest['id'] ?? ''; $version = $manifest['version'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]+$/D', $id) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) { throw new RuntimeException('Invalid manifest'); }
if (!class_exists(ZipArchive::class)) { throw new RuntimeException('PHP ZipArchive is required'); }
$output = __DIR__ . '/dist'; if (!is_dir($output)) { mkdir($output, 0755, true); }
$destination = $output . '/' . $id . '-' . $version . '.zip';
$zip = new ZipArchive();
if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create ZIP'); }
$files = [];
foreach (['manifest.json', 'bootstrap.php', 'autoload.php', 'README.md', 'CHANGELOG.md', 'LICENSE', 'THIRD-PARTY-NOTICES.md'] as $file) { $files[$file] = __DIR__ . '/' . $file; }
foreach (['src', 'admin', 'views', 'assets', 'config'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) { continue; }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(__DIR__) + 1));
        $files[$relative] = $file->getPathname();
    }
}
ksort($files);
foreach ($files as $relative => $path) {
    if (!is_file($path) || !$zip->addFile($path, $id . '/' . $relative)) { throw new RuntimeException('Cannot add ' . $relative); }
}
$zip->close();
$verify = new ZipArchive();
if ($verify->open($destination, ZipArchive::CHECKCONS) !== true || $verify->numFiles !== count($files)) { throw new RuntimeException('ZIP verification failed'); }
foreach ($files as $relative => $path) {
    if ($verify->getFromName($id . '/' . $relative) !== file_get_contents($path)) { throw new RuntimeException('ZIP content mismatch: ' . $relative); }
}
$verify->close();
echo basename($destination) . ' · ' . count($files) . ' files · ' . filesize($destination) . " bytes\nSHA-256: " . hash_file('sha256', $destination) . "\n";
