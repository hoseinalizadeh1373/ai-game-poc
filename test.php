<?php

$phaserPath = __DIR__ . '/assets/phaser.min.js';

if (!file_exists($phaserPath)) {
    die('Phaser file does not exist.');
}

$phaserJs = file_get_contents($phaserPath);

echo '<pre>';
echo 'File size: ' . strlen($phaserJs) . PHP_EOL;
echo 'First 200 chars:' . PHP_EOL;
echo htmlspecialchars(substr($phaserJs, 0, 200));
echo '</pre>';

exit;