<?php
/**
 * api_room.php — REST endpoints for multiplayer rooms.
 *
 * POST actions (JSON body):
 *   create  { game_id, name? }
 *   join    { code, name? }
 *   ready   { code, ready?: bool }
 *   leave   { code }
 *   status  { code }   (also accepts GET ?code=)
 */

require __DIR__ . '/lib.php';
require __DIR__ . '/lib_mp.php';

$config  = require __DIR__ . '/config.php';
$baseDir = __DIR__;
$dataDir = $baseDir . '/data';
$gamesDir = $baseDir . '/games';

ensureProtectedDir($dataDir);
roomsDir($dataDir);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$playerId = getOrCreatePlayerId();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = [];
if ($method === 'POST') {
    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST ?: [];
    }
}

$action = (string) ($body['action'] ?? $_GET['action'] ?? '');

function jsonOut(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeName(string $name): string
{
    $name = strip_tags($name);
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    return function_exists('mb_substr') ? mb_substr($name, 0, 24) : substr($name, 0, 24);
}

// GET status shortcut
if ($method === 'GET' && (isset($_GET['code']) || $action === 'status')) {
    $code = strtoupper((string) ($_GET['code'] ?? $body['code'] ?? ''));
    $room = loadRoom($dataDir, $code);
    if (!$room) {
        jsonOut(['ok' => false, 'error' => 'اتاق پیدا نشد.'], 404);
    }
    jsonOut(['ok' => true, 'room' => publicRoomView($room), 'you' => $playerId]);
}

if ($method !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Method not allowed'], 405);
}

switch ($action) {
    case 'create': {
        $gameId = basename((string) ($body['game_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $gameId) || !file_exists($gamesDir . '/' . $gameId . '.html')) {
            jsonOut(['ok' => false, 'error' => 'بازی نامعتبر است.'], 400);
        }
        $name = sanitizeName((string) ($body['name'] ?? ''));
        if ($name === '') {
            $name = 'میزبان';
        }
        $room = createRoom($dataDir, $gameId, $playerId, $name);
        jsonOut(['ok' => true, 'room' => publicRoomView($room), 'you' => $playerId]);
    }

    case 'join': {
        $code = strtoupper((string) ($body['code'] ?? ''));
        $name = sanitizeName((string) ($body['name'] ?? ''));
        if ($name === '') {
            $name = 'مهمان';
        }
        $err = null;
        $room = joinRoom($dataDir, $code, $playerId, $name, $err);
        if (!$room) {
            jsonOut(['ok' => false, 'error' => $err ?? 'خطا در ورود به اتاق.'], 400);
        }
        jsonOut(['ok' => true, 'room' => publicRoomView($room), 'you' => $playerId]);
    }

    case 'ready': {
        $code  = strtoupper((string) ($body['code'] ?? ''));
        $ready = array_key_exists('ready', $body) ? (bool) $body['ready'] : true;
        $room  = setPlayerReady($dataDir, $code, $playerId, $ready);
        if (!$room) {
            jsonOut(['ok' => false, 'error' => 'اتاق پیدا نشد یا شما عضو نیستید.'], 400);
        }
        jsonOut(['ok' => true, 'room' => publicRoomView($room), 'you' => $playerId]);
    }

    case 'leave': {
        $code = strtoupper((string) ($body['code'] ?? ''));
        $room = leaveRoom($dataDir, $code, $playerId);
        jsonOut(['ok' => true, 'room' => $room ? publicRoomView($room) : null, 'you' => $playerId]);
    }

    case 'status': {
        $code = strtoupper((string) ($body['code'] ?? ''));
        $room = loadRoom($dataDir, $code);
        if (!$room) {
            jsonOut(['ok' => false, 'error' => 'اتاق پیدا نشد.'], 404);
        }
        jsonOut(['ok' => true, 'room' => publicRoomView($room), 'you' => $playerId]);
    }

    default:
        jsonOut(['ok' => false, 'error' => 'action نامعتبر. از create|join|ready|leave|status استفاده کن.'], 400);
}
