<?php
/**
 * lib_mp.php — multiplayer room helpers.
 *
 * Rooms live as JSON files under data/rooms/{CODE}.json
 * WebSocket server (Node) is the real-time bus; PHP owns persistence.
 */

require_once __DIR__ . '/lib.php';

define('MP_ROOM_CODE_LEN', 6);
define('MP_MAX_PLAYERS', 2);
define('MP_ROOM_TTL_SEC', 3600); // 1 hour idle expiry

function roomsDir(string $dataDir): string
{
    $dir = rtrim($dataDir, '/') . '/rooms';
    ensureProtectedDir($dir);
    return $dir;
}

function roomFile(string $dataDir, string $code): string
{
    return roomsDir($dataDir) . '/' . strtoupper($code) . '.json';
}

function isValidRoomCode(string $code): bool
{
    return (bool) preg_match('/^[A-Z0-9]{' . MP_ROOM_CODE_LEN . '}$/', strtoupper($code));
}

function generateRoomCode(string $dataDir): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $code = '';
        for ($i = 0; $i < MP_ROOM_CODE_LEN; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!file_exists(roomFile($dataDir, $code))) {
            return $code;
        }
    }
    // fallback: timestamp-based
    return strtoupper(substr(base_convert((string) time(), 10, 36) . bin2hex(random_bytes(2)), 0, MP_ROOM_CODE_LEN));
}

/**
 * @return array{code:string,game_id:string,host_id:string,players:array,status:string,created_at:int,updated_at:int,seed:string}
 */
function createRoom(string $dataDir, string $gameId, string $hostPlayerId, string $hostName = ''): array
{
    $code = generateRoomCode($dataDir);
    $now  = time();
    $room = [
        'code'       => $code,
        'game_id'    => $gameId,
        'host_id'    => $hostPlayerId,
        'players'    => [
            [
                'id'     => $hostPlayerId,
                'name'   => $hostName !== '' ? $hostName : 'بازیکن ۱',
                'role'   => 'host',
                'ready'  => false,
                'joined' => $now,
            ],
        ],
        'status'     => 'waiting', // waiting | ready | playing | finished | closed
        'created_at' => $now,
        'updated_at' => $now,
        'seed'       => bin2hex(random_bytes(8)), // shared RNG seed for deterministic games
    ];
    saveRoom($dataDir, $room);
    return $room;
}

/**
 * @return array|null
 */
function loadRoom(string $dataDir, string $code): ?array
{
    $code = strtoupper($code);
    if (!isValidRoomCode($code)) {
        return null;
    }
    $path = roomFile($dataDir, $code);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['code'])) {
        return null;
    }
    // expire stale rooms
    $updated = (int) ($data['updated_at'] ?? 0);
    if ($updated > 0 && (time() - $updated) > MP_ROOM_TTL_SEC) {
        @unlink($path);
        return null;
    }
    return $data;
}

function saveRoom(string $dataDir, array $room): bool
{
    $room['updated_at'] = time();
    $path = roomFile($dataDir, $room['code']);
    $json = json_encode($room, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return $json !== false && @file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Join an existing waiting room. Returns updated room or null + error message via reference.
 * @return array|null
 */
function joinRoom(string $dataDir, string $code, string $playerId, string $playerName = '', ?string &$error = null): ?array
{
    $room = loadRoom($dataDir, $code);
    if ($room === null) {
        $error = 'اتاق پیدا نشد یا منقضی شده است.';
        return null;
    }
    if (($room['status'] ?? '') === 'closed') {
        $error = 'این اتاق بسته شده است.';
        return null;
    }

    // already in room?
    foreach ($room['players'] as &$p) {
        if ($p['id'] === $playerId) {
            if ($playerName !== '') {
                $p['name'] = $playerName;
            }
            saveRoom($dataDir, $room);
            return $room;
        }
    }
    unset($p);

    if (count($room['players']) >= MP_MAX_PLAYERS) {
        $error = 'اتاق پر است (حداکثر ۲ نفر).';
        return null;
    }

    if (($room['status'] ?? '') === 'playing' || ($room['status'] ?? '') === 'finished') {
        $error = 'بازی این اتاق شروع شده و دیگر نمی‌توانی وارد شوی.';
        return null;
    }

    $room['players'][] = [
        'id'     => $playerId,
        'name'   => $playerName !== '' ? $playerName : 'بازیکن ۲',
        'role'   => 'guest',
        'ready'  => false,
        'joined' => time(),
    ];

    if (count($room['players']) >= MP_MAX_PLAYERS) {
        $room['status'] = 'ready';
    }

    saveRoom($dataDir, $room);
    return $room;
}

function setPlayerReady(string $dataDir, string $code, string $playerId, bool $ready = true): ?array
{
    $room = loadRoom($dataDir, $code);
    if ($room === null) {
        return null;
    }
    $found = false;
    foreach ($room['players'] as &$p) {
        if ($p['id'] === $playerId) {
            $p['ready'] = $ready;
            $found = true;
            break;
        }
    }
    unset($p);
    if (!$found) {
        return null;
    }

    // if both ready → playing
    $allReady = count($room['players']) >= MP_MAX_PLAYERS;
    if ($allReady) {
        foreach ($room['players'] as $p) {
            if (empty($p['ready'])) {
                $allReady = false;
                break;
            }
        }
    }
    if ($allReady) {
        $room['status'] = 'playing';
    } elseif (count($room['players']) >= MP_MAX_PLAYERS) {
        $room['status'] = 'ready';
    }

    saveRoom($dataDir, $room);
    return $room;
}

function leaveRoom(string $dataDir, string $code, string $playerId): ?array
{
    $room = loadRoom($dataDir, $code);
    if ($room === null) {
        return null;
    }
    $room['players'] = array_values(array_filter(
        $room['players'],
        static fn($p) => ($p['id'] ?? '') !== $playerId
    ));
    if (empty($room['players'])) {
        $room['status'] = 'closed';
        @unlink(roomFile($dataDir, $code));
        return $room;
    }
    // reassign host if needed
    $hostGone = true;
    foreach ($room['players'] as $p) {
        if (($p['id'] ?? '') === ($room['host_id'] ?? '')) {
            $hostGone = false;
            break;
        }
    }
    if ($hostGone) {
        $room['host_id'] = $room['players'][0]['id'];
        $room['players'][0]['role'] = 'host';
    }
    $room['status'] = count($room['players']) < MP_MAX_PLAYERS ? 'waiting' : ($room['status'] ?? 'waiting');
    saveRoom($dataDir, $room);
    return $room;
}

function publicRoomView(array $room): array
{
    return [
        'code'       => $room['code'],
        'game_id'    => $room['game_id'],
        'host_id'    => $room['host_id'],
        'players'    => array_map(static function ($p) {
            return [
                'id'    => $p['id'],
                'name'  => $p['name'] ?? 'بازیکن',
                'role'  => $p['role'] ?? 'guest',
                'ready' => !empty($p['ready']),
            ];
        }, $room['players'] ?? []),
        'status'     => $room['status'],
        'seed'       => $room['seed'] ?? '',
        'player_count' => count($room['players'] ?? []),
        'max_players'  => MP_MAX_PLAYERS,
    ];
}

/**
 * Script injected into game HTML so the game (or parent) can talk multiplayer.
 * Seed is applied to Math.random for deterministic shared content when possible.
 */
function buildMpBridgeScript(string $roomCode, string $playerId, string $role, string $seed, bool $isHost): string
{
    $cfg = json_encode([
        'room'     => $roomCode,
        'playerId' => $playerId,
        'role'     => $role,
        'seed'     => $seed,
        'isHost'   => $isHost,
    ], JSON_UNESCAPED_UNICODE);

    return <<<JS
<script>
(function(){
  if (window.MegaMP) return;
  var CFG = {$cfg};

  // Deterministic PRNG from room seed (mulberry32) — optional override of Math.random
  function mulberry32(a) {
    return function() {
      var t = a += 0x6D2B79F5;
      t = Math.imul(t ^ t >>> 15, t | 1);
      t ^= t + Math.imul(t ^ t >>> 7, t | 61);
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }
  if (CFG.seed) {
    var h = 0;
    for (var i = 0; i < CFG.seed.length; i++) h = Math.imul(31, h) + CFG.seed.charCodeAt(i) | 0;
    Math.random = mulberry32(h >>> 0);
  }

  var handlers = {};
  window.MegaMP = {
    enabled: true,
    room: CFG.room,
    playerId: CFG.playerId,
    role: CFG.role,
    isHost: !!CFG.isHost,
    seed: CFG.seed,
    send: function(type, data) {
      try {
        parent.postMessage({ source: 'megamp', type: type, data: data || {} }, '*');
      } catch (e) {}
    },
    on: function(type, cb) {
      if (!handlers[type]) handlers[type] = [];
      handlers[type].push(cb);
    },
    off: function(type, cb) {
      if (!handlers[type]) return;
      handlers[type] = handlers[type].filter(function(f){ return f !== cb; });
    },
    _emit: function(type, data) {
      (handlers[type] || []).forEach(function(cb){ try { cb(data); } catch(e){} });
    },
    // convenience for quizzes / scores
    reportScore: function(score, extra) {
      this.send('score', Object.assign({ score: score }, extra || {}));
    },
    reportAnswer: function(payload) {
      this.send('answer', payload || {});
    },
    reportFinish: function(payload) {
      this.send('finish', payload || {});
    },
    reportState: function(state) {
      this.send('state', state || {});
    }
  };

  window.addEventListener('message', function(e) {
    var d = e.data;
    if (!d || d.source !== 'megamp-parent') return;
    window.MegaMP._emit(d.type, d.data);
  });

  // announce ready to parent
  try {
    parent.postMessage({ source: 'megamp', type: 'bridge_ready', data: { playerId: CFG.playerId } }, '*');
  } catch (e) {}
})();
</script>
JS;
}

function injectMpBridge(string $html, string $bridgeScript): string
{
    // Prefer inject before </body>, else before </html>, else append
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $bridgeScript . "\n</body>", $html, 1);
    }
    if (stripos($html, '</html>') !== false) {
        return preg_replace('/<\/html>/i', $bridgeScript . "\n</html>", $html, 1);
    }
    return $html . "\n" . $bridgeScript;
}
