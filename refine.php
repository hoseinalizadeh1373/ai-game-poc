<?php

/**
 * refine.php — JSON endpoint behind the "Edit / fix bugs" button.
 *
 *   GET  refine.php?action=models
 *        -> { ok, models: [{id,label}], default }
 *
 *   POST refine.php   game_id=...  [model=...] [note=...] [replace=1]
 *        -> { ok, error, gameId, replaced, playUrl }
 *
 * It knows nothing about index.php's markup; the only host-specific
 * thing is the playUrl format below.
 */

set_time_limit(300);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// the ONE place that knows how to link to a game page
function playUrlFor(string $gameId): string
{
    return 'index.php?play=' . rawurlencode($gameId);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') !== 'models') {
        respond(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);
    }

    $models = [];
    foreach (allowedModels() as $id => $label) {
        $models[] = ['id' => $id, 'label' => $label];
    }
    respond(['ok' => true, 'models' => $models, 'default' => $config['model'] ?? null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'متد نامعتبر است.'], 405);
}

$gameId  = trim((string) ($_POST['game_id'] ?? ''));
$model   = trim((string) ($_POST['model'] ?? ''));
$note    = trim((string) ($_POST['note'] ?? ''));
if (preg_match('/^.{0,2000}/us', $note, $noteMatch)) {
    $note = $noteMatch[0]; // cap length without needing the mbstring extension
}
$replace = ($_POST['replace'] ?? '') === '1';

if ($gameId === '') {
    respond(['ok' => false, 'error' => 'شناسه بازی ارسال نشده است.'], 400);
}

try {
    $result = refineGame(
        $config,
        __DIR__ . '/assets',
        __DIR__ . '/games',
        $gameId,
        $model !== '' ? $model : null,
        $note,
        $replace
    );
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'خطای داخلی: ' . $e->getMessage()], 500);
}

respond([
    'ok'       => $result['ok'],
    'error'    => $result['error'],
    'gameId'   => $result['gameId'],
    'replaced' => $result['replaced'],
    'playUrl'  => $result['ok'] ? playUrlFor($result['gameId']) : null,
]);
