<?php
/**
 * multiplayer.php — لابی چندنفره برای یک بازی مشخص
 * ?game=GAME_ID
 */

require __DIR__ . '/lib.php';
require __DIR__ . '/lib_mp.php';

$config   = require __DIR__ . '/config.php';
$baseDir  = __DIR__;
$gamesDir = $baseDir . '/games';
$dataDir  = $baseDir . '/data';

ensureDir($gamesDir);
ensureProtectedDir($dataDir);

$playerId = getOrCreatePlayerId();

$gameId = isset($_GET['game']) ? basename((string) $_GET['game']) : '';
$error  = null;
$gameTitle = null;

if ($gameId === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $gameId)) {
    $error = 'شناسه بازی نامعتبر است.';
} else {
    $html = loadGame($gamesDir, $gameId);
    if ($html === null) {
        $error = 'بازی مورد نظر پیدا نشد.';
    } else {
        $gameTitle = extractGameTitle($html, $gameId);
    }
}

// optional pre-filled room from URL
$prefillCode = isset($_GET['code']) ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_GET['code'])) : '';

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بازی چندنفره — <?= htmlspecialchars($gameTitle ?? 'Mega Game') ?></title>
    <script src="assets/axios.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        @font-face {
            font-family: 'Vazir';
            src: url('assets/fonts/vazir.woff') format('woff');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        body {
            margin: 0;
            font-family: 'Vazir', Tahoma, Arial, sans-serif !important;
            background: linear-gradient(160deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            background-attachment: fixed;
            color: #f1f5f9;
            min-height: 100vh;
        }
        .container {
            max-width: 520px;
            margin: 0 auto;
            padding: 40px 20px 80px;
        }
        .back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 24px;
        }
        .back:hover { color: #c7d2fe; }
        h1 {
            margin: 0 0 8px;
            font-size: 1.6rem;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd, #f0abfc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .subtitle {
            color: #94a3b8;
            margin-bottom: 28px;
            line-height: 1.6;
            font-size: 0.95rem;
        }
        .card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(99, 102, 241, 0.25);
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 16px;
        }
        .card h2 {
            margin: 0 0 14px;
            font-size: 1.05rem;
            color: #e2e8f0;
        }
        label {
            display: block;
            font-size: 0.85rem;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #334155;
            background: #0f172a;
            color: #f1f5f9;
            font-family: inherit;
            font-size: 1rem;
            margin-bottom: 12px;
            outline: none;
        }
        input[type="text"]:focus {
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2);
        }
        input.code-input {
            letter-spacing: 0.35em;
            text-align: center;
            font-size: 1.35rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 13px 18px;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.12s, box-shadow 0.2s, opacity 0.15s;
        }
        .btn:active { transform: scale(0.98); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            box-shadow: 0 4px 16px rgba(99, 102, 241, 0.35);
        }
        .btn-primary:hover:not(:disabled) {
            box-shadow: 0 6px 22px rgba(99, 102, 241, 0.5);
        }
        .btn-secondary {
            background: #1e293b;
            color: #e2e8f0;
            border: 1px solid #475569;
        }
        .btn-secondary:hover:not(:disabled) {
            border-color: #818cf8;
            color: #fff;
        }
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
        }
        .divider {
            text-align: center;
            color: #64748b;
            font-size: 0.85rem;
            margin: 8px 0 16px;
            position: relative;
        }
        .error-box {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
        .room-panel { display: none; }
        .room-panel.active { display: block; }
        .room-code-display {
            text-align: center;
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: 0.4em;
            color: #a5b4fc;
            padding: 16px;
            background: #0f172a;
            border-radius: 12px;
            border: 1px dashed #6366f1;
            margin: 12px 0 16px;
            user-select: all;
        }
        .players {
            list-style: none;
            padding: 0;
            margin: 0 0 16px;
        }
        .players li {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: #0f172a;
            border-radius: 10px;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        .badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 999px;
            background: #312e81;
            color: #c7d2fe;
        }
        .badge.ready { background: #064e3b; color: #6ee7b7; }
        .badge.you { background: #1e3a5f; color: #93c5fd; }
        .status-line {
            text-align: center;
            color: #94a3b8;
            font-size: 0.9rem;
            margin-bottom: 14px;
            min-height: 1.4em;
        }
        .status-line.pulse {
            animation: pulse 1.4s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 1; }
        }
        .hint {
            font-size: 0.8rem;
            color: #64748b;
            text-align: center;
            margin-top: 10px;
            line-height: 1.5;
        }
        .copy-btn {
            width: auto;
            margin-top: 0;
            padding: 8px 14px;
            font-size: 0.85rem;
        }
        .row-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .row-actions .btn { width: auto; flex: 1; }
    </style>
</head>
<body>
<div class="container">
    <a class="back" href="<?= $gameId ? 'play.php?id=' . urlencode($gameId) : 'index.php' ?>">← بازگشت</a>

    <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <a class="btn btn-secondary" href="index.php">برو به لیست بازی‌ها</a>
    <?php else: ?>

    <h1>🌐 بازی چندنفره</h1>
    <p class="subtitle">
        <strong><?= htmlspecialchars($gameTitle) ?></strong><br>
        یه اتاق بساز و کد رو برای دوستت بفرست، یا با کد وارد شو. وقتی هر دو نفر آماده بودن، بازی شروع می‌شه.
    </p>

    <div id="errBox" class="error-box" style="display:none;"></div>

    <!-- Lobby: create / join -->
    <div id="lobbyPanel">
        <div class="card">
            <h2>اسم نمایشی تو</h2>
            <label for="playerName">نام (اختیاری)</label>
            <input type="text" id="playerName" maxlength="24" placeholder="مثلاً علی" autocomplete="nickname">
        </div>

        <div class="card">
            <h2>ساخت اتاق جدید</h2>
            <button type="button" class="btn btn-primary" id="btnCreate">🎮 ساخت اتاق و دریافت کد</button>
            <p class="hint">کد ۶ حرفی می‌گیری؛ برای دوستت بفرست تا بیاد تو اتاق.</p>
        </div>

        <div class="divider">یا</div>

        <div class="card">
            <h2>ورود با کد اتاق</h2>
            <label for="joinCode">کد اتاق</label>
            <input type="text" id="joinCode" class="code-input" maxlength="6" placeholder="مثلاً K7M2XP" value="<?= htmlspecialchars($prefillCode) ?>" autocomplete="off">
            <button type="button" class="btn btn-secondary" id="btnJoin">ورود به اتاق</button>
        </div>
    </div>

    <!-- Waiting room -->
    <div id="roomPanel" class="room-panel">
        <div class="card">
            <h2>کد اتاق</h2>
            <div class="room-code-display" id="roomCodeDisplay">------</div>
            <div class="row-actions" style="margin-bottom:12px;">
                <button type="button" class="btn btn-secondary copy-btn" id="btnCopy">📋 کپی کد</button>
                <button type="button" class="btn btn-secondary copy-btn" id="btnShare">🔗 لینک دعوت</button>
            </div>
            <div class="status-line pulse" id="statusLine">در انتظار حریف...</div>
            <h2 style="margin-top:8px;">بازیکن‌ها</h2>
            <ul class="players" id="playersList"></ul>
            <div class="row-actions">
                <button type="button" class="btn btn-success" id="btnReady">✅ آماده‌ام</button>
                <button type="button" class="btn btn-secondary" id="btnLeave">خروج از اتاق</button>
            </div>
            <p class="hint">وقتی هر دو نفر «آماده‌ام» رو بزنن، خودکار وارد بازی می‌شید.</p>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php if (!$error): ?>
<script>
(function () {
    const GAME_ID = <?= json_encode($gameId) ?>;
    const YOU = <?= json_encode($playerId) ?>;

    const lobbyPanel = document.getElementById('lobbyPanel');
    const roomPanel = document.getElementById('roomPanel');
    const errBox = document.getElementById('errBox');
    const statusLine = document.getElementById('statusLine');
    const playersList = document.getElementById('playersList');
    const roomCodeDisplay = document.getElementById('roomCodeDisplay');
    const btnReady = document.getElementById('btnReady');

    let currentRoom = null;
    let pollTimer = null;
    let iAmReady = false;

    function showErr(msg) {
        if (!msg) {
            errBox.style.display = 'none';
            errBox.textContent = '';
            return;
        }
        errBox.style.display = 'block';
        errBox.textContent = msg;
    }

    function name() {
        const n = (document.getElementById('playerName').value || '').trim();
        return n || '';
    }

    async function api(action, extra) {
        const body = Object.assign({ action: action }, extra || {});
        const res = await axios.post('api_room.php', body, {
            headers: { 'Content-Type': 'application/json' },
        });
        return res.data;
    }

    function renderRoom(room) {
        currentRoom = room;
        roomCodeDisplay.textContent = room.code;
        playersList.innerHTML = '';
        (room.players || []).forEach(function (p) {
            const li = document.createElement('li');
            const left = document.createElement('span');
            left.textContent = (p.name || 'بازیکن') + (p.id === YOU ? ' (تو)' : '');
            const badges = document.createElement('span');
            if (p.role === 'host') {
                const b = document.createElement('span');
                b.className = 'badge';
                b.textContent = 'میزبان';
                badges.appendChild(b);
            }
            if (p.ready) {
                const b = document.createElement('span');
                b.className = 'badge ready';
                b.textContent = 'آماده';
                b.style.marginRight = '6px';
                badges.appendChild(b);
            }
            if (p.id === YOU) {
                const b = document.createElement('span');
                b.className = 'badge you';
                b.textContent = 'تو';
                b.style.marginRight = '6px';
                badges.appendChild(b);
            }
            li.appendChild(left);
            li.appendChild(badges);
            playersList.appendChild(li);
        });

        const count = (room.players || []).length;
        const max = room.max_players || 2;
        if (room.status === 'playing') {
            statusLine.classList.remove('pulse');
            statusLine.textContent = 'هر دو آماده‌اید! در حال ورود به بازی...';
            goToGame(room);
            return;
        }
        if (count < max) {
            statusLine.classList.add('pulse');
            statusLine.textContent = 'در انتظار حریف... (' + count + '/' + max + ')';
        } else {
            statusLine.classList.remove('pulse');
            const allReady = (room.players || []).every(function (p) { return p.ready; });
            if (allReady) {
                statusLine.textContent = 'شروع بازی...';
            } else {
                statusLine.textContent = 'حریف وصل شد — هر دو «آماده‌ام» رو بزنید';
            }
        }

        // update ready button label
        const me = (room.players || []).find(function (p) { return p.id === YOU; });
        iAmReady = !!(me && me.ready);
        btnReady.textContent = iAmReady ? '⏳ منتظر حریف...' : '✅ آماده‌ام';
        btnReady.disabled = iAmReady && count < max;
    }

    function showRoom() {
        lobbyPanel.style.display = 'none';
        roomPanel.classList.add('active');
    }

    function showLobby() {
        roomPanel.classList.remove('active');
        lobbyPanel.style.display = 'block';
        currentRoom = null;
        stopPoll();
    }

    function startPoll() {
        stopPoll();
        pollTimer = setInterval(async function () {
            if (!currentRoom) return;
            try {
                const data = await api('status', { code: currentRoom.code });
                if (data.ok && data.room) renderRoom(data.room);
                else if (!data.ok) {
                    showErr(data.error || 'اتاق از بین رفت.');
                    showLobby();
                }
            } catch (e) {
                /* ignore transient */
            }
        }, 1500);
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function goToGame(room) {
        stopPoll();
        const url = 'play.php?id=' + encodeURIComponent(GAME_ID)
            + '&room=' + encodeURIComponent(room.code)
            + '&mp=1';
        // small delay so user sees the message
        setTimeout(function () {
            location.href = url;
        }, 600);
    }

    document.getElementById('btnCreate').addEventListener('click', async function () {
        showErr('');
        this.disabled = true;
        try {
            const data = await api('create', { game_id: GAME_ID, name: name() });
            if (!data.ok) {
                showErr(data.error || 'ساخت اتاق ناموفق بود.');
                return;
            }
            renderRoom(data.room);
            showRoom();
            startPoll();
        } catch (e) {
            showErr('خطای شبکه: ' + (e.message || e));
        } finally {
            this.disabled = false;
        }
    });

    document.getElementById('btnJoin').addEventListener('click', async function () {
        showErr('');
        const code = (document.getElementById('joinCode').value || '').trim().toUpperCase();
        if (code.length < 4) {
            showErr('کد اتاق را وارد کن.');
            return;
        }
        this.disabled = true;
        try {
            const data = await api('join', { code: code, name: name() });
            if (!data.ok) {
                showErr(data.error || 'ورود ناموفق بود.');
                return;
            }
            renderRoom(data.room);
            showRoom();
            startPoll();
        } catch (e) {
            showErr('خطای شبکه: ' + (e.message || e));
        } finally {
            this.disabled = false;
        }
    });

    btnReady.addEventListener('click', async function () {
        if (!currentRoom) return;
        showErr('');
        this.disabled = true;
        try {
            const data = await api('ready', { code: currentRoom.code, ready: !iAmReady });
            if (!data.ok) {
                showErr(data.error || 'خطا');
                return;
            }
            renderRoom(data.room);
        } catch (e) {
            showErr('خطای شبکه');
        } finally {
            this.disabled = false;
        }
    });

    document.getElementById('btnLeave').addEventListener('click', async function () {
        if (!currentRoom) return;
        try {
            await api('leave', { code: currentRoom.code });
        } catch (e) {}
        showLobby();
        showErr('');
    });

    document.getElementById('btnCopy').addEventListener('click', function () {
        if (!currentRoom) return;
        const t = currentRoom.code;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t).then(function () {
                statusLine.textContent = 'کد کپی شد ✓';
            });
        } else {
            prompt('کد اتاق را کپی کن:', t);
        }
    });

    document.getElementById('btnShare').addEventListener('click', function () {
        if (!currentRoom) return;
        const link = location.origin + location.pathname
            + '?game=' + encodeURIComponent(GAME_ID)
            + '&code=' + encodeURIComponent(currentRoom.code);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(function () {
                statusLine.textContent = 'لینک دعوت کپی شد ✓';
            });
        } else {
            prompt('لینک دعوت را کپی کن:', link);
        }
    });

    // auto-join if code in URL
    <?php if ($prefillCode !== ''): ?>
    setTimeout(function () {
        document.getElementById('btnJoin').click();
    }, 400);
    <?php endif; ?>
})();
</script>
<?php endif; ?>
</body>
</html>
