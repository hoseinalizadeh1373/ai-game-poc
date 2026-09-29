<?php

require __DIR__ . '/lib.php';

$config = require __DIR__ . '/config.php';

$baseDir   = __DIR__;
$assetsDir = $baseDir . '/assets';
$iconsDir  = $assetsDir . '/icons';
$gamesDir  = $baseDir . '/games';
$dataDir   = $baseDir . '/data';

ensureDir($iconsDir);
ensureDir($gamesDir);
ensureProtectedDir($dataDir);
ensureDir($dataDir . '/results');

// unique player id (cookie) — must run before any output
$playerId = getOrCreatePlayerId();

$error           = null;
$missingSvgs     = [];
$handDrawnCount  = 0;
$brokenSvgCount  = 0;
$classCollisions = [];
$hadExtraText    = false;
$resultHtml      = null;
$resultTitle     = null;
$gameId          = null;
$gamePrompt      = null;
$gameModel       = null;
$gameCreator     = null;

// -----------------------------------------------------------------
// Endpoint: save a play result (called by the parent page when the
// game inside the iframe reports that it has finished)
// -----------------------------------------------------------------
if (($_GET['action'] ?? '') === 'save_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json; charset=utf-8');

    $body = json_decode((string) file_get_contents('php://input'), true);
    $gid  = is_array($body) ? (string) ($body['game_id'] ?? '') : '';

    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $gid) || !file_exists($gamesDir . '/' . $gid . '.html')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid game'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $record = sanitizeResultPayload($body, $playerId);
    $saved  = appendGameResult($dataDir, $gamesDir, $gid, $record);

    echo json_encode(['ok' => $saved]);
    exit;
}

// -----------------------------------------------------------------
// Load the game by ?id=...
// -----------------------------------------------------------------
if (isset($_GET['id'])) {

    $gameId = basename((string) $_GET['id']);

    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $gameId)) {
        $error = 'شناسه بازی نامعتبر است.';
    } else {
        $html = loadGame($gamesDir, $gameId);
        if ($html === null) {
            $error = 'بازی مورد نظر پیدا نشد.';
        } else {
            $resultHtml  = $html;
            $resultTitle = extractGameTitle($html, $gameId);
            $gamePrompt  = getGamePrompt($gamesDir, $gameId);
            $gameModel   = getGameModel($gamesDir, $gameId);
            $gameCreator = getGameCreator($gamesDir, $gameId);

            if (!empty($_GET['missing'])) {
                $missingSvgs = array_filter(explode(',', (string) $_GET['missing']));
            }
            $handDrawnCount = isset($_GET['handdrawn']) ? (int) $_GET['handdrawn'] : 0;
            $brokenSvgCount = isset($_GET['broken']) ? (int) $_GET['broken'] : 0;
            if (!empty($_GET['collide'])) {
                $classCollisions = array_filter(explode(',', (string) $_GET['collide']));
            }
            $hadExtraText = !empty($_GET['extratext']);
        }
    }
} else {
    $error = 'شناسه بازی مشخص نشده است.';
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($resultTitle ?? 'بازی') ?> | Mega Game</title>
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
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 24px 60px;
            transition: max-width 0.3s, padding 0.3s;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 20px;
            color: #a5b4fc;
            text-decoration: none;
            font-size: 0.95rem;
            transition: color 0.15s;
        }

        .back-link:hover { color: #c7d2fe; }

        .game-header {
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .game-header h2 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 600;
        }

        .game-header .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .icon-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(30, 41, 59, 0.7);
            color: #e2e8f0;
            font-family: inherit;
            font-size: 0.9rem;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s, transform 0.15s;
        }

        .icon-btn:hover {
            border-color: #818cf8;
            background: rgba(30, 41, 59, 0.9);
            transform: translateY(-1px);
        }

        .icon-btn:active {
            transform: translateY(0);
        }

        .icon-btn[disabled] {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .prompt-box {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 16px;
            color: #e2e8f0;
            line-height: 1.7;
            font-size: 0.95rem;
        }

        .prompt-box strong { color: #a5b4fc; }

        .game-frame-wrap {
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.15);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            background: #fff;
            position: relative;
            transition: border-radius 0.3s;
        }

        .game-frame {
            width: 100%;
            height: min(78vh, 820px);
            min-height: 480px;
            border: 0;
            display: block;
            background: white;
        }

        .error {
            padding: 14px 18px;
            border-radius: 12px;
            background: rgba(127, 29, 29, 0.5);
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: #fecaca;
            font-size: 0.9rem;
        }

        /* ============================================================
           Fullscreen / focus mode
           ------------------------------------------------------------
           When body has .is-fullscreen, we hide the header, prompt
           boxes and back link, and let the iframe take the whole
           viewport. This works both when the browser's real Fullscreen
           API is used (target: the wrapper) and when it isn't
           available — in that case we just expand visually.
           ============================================================ */
        body.is-fullscreen {
            background: #000;
        }

        body.is-fullscreen .container {
            max-width: 100%;
            padding: 0;
        }

        body.is-fullscreen .back-link,
        body.is-fullscreen .game-header,
        body.is-fullscreen .prompt-box,
        body.is-fullscreen .error {
            display: none !important;
        }

        body.is-fullscreen .game-frame-wrap {
            border-radius: 0;
            border: 0;
            box-shadow: none;
            height: 100vh;
        }

        body.is-fullscreen .game-frame {
            height: 100vh;
            min-height: 0;
        }

        /* Exit hint that shows on hover in fullscreen */
        .exit-hint {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 9999;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(148, 163, 184, 0.25);
            color: #e2e8f0;
            font-size: 0.85rem;
            font-family: inherit;
            cursor: pointer;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        body.is-fullscreen .exit-hint {
            opacity: 0.35;
            pointer-events: auto;
        }

        body.is-fullscreen .exit-hint:hover {
            opacity: 1;
        }

        @media (max-width: 640px) {
            .container { padding: 20px 16px 40px; }
            .game-frame { min-height: 420px; height: 70vh; }
            .icon-btn span { display: none; }
            .icon-btn { padding: 10px 12px; }
        }
    </style>
</head>

<body>

    <div class="container">

        <a class="back-link" href="index.php">← بازگشت به لیست بازی‌ها</a>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($resultHtml): ?>

            <div class="game-header">
                <h2>🎮 <?= htmlspecialchars($resultTitle ?? 'بازی') ?></h2>
                <div class="actions">
                    <button type="button" class="icon-btn" id="reloadBtn" title="شروع دوباره بازی">
                        🔄 <span>شروع دوباره</span>
                    </button>
                    <button type="button" class="icon-btn" id="fullscreenBtn" title="تمام صفحه">
                        ⛶ <span id="fullscreenLabel">تمام صفحه</span>
                    </button>
                </div>
            </div>

            <?php if (!empty($gameCreator)): ?>
                <div class="prompt-box">
                    <strong>سازنده:</strong> <?= htmlspecialchars($gameCreator) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($gamePrompt)): ?>
                <div class="prompt-box">
                    <strong>پرامپت اصلی:</strong><br>
                    <?= nl2br(htmlspecialchars($gamePrompt)) ?>
                    <?php if (!empty($gameModel)): ?>
                        <br><strong>مدل استفاده‌شده:</strong> <?= htmlspecialchars($gameModel) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="game-frame-wrap" id="gameFrameWrap">
                <iframe
                    class="game-frame"
                    id="gameFrame"
                    sandbox="allow-scripts allow-same-origin"
                    srcdoc="<?= htmlspecialchars($resultHtml, ENT_QUOTES, 'UTF-8') ?>"></iframe>
            </div>

        <?php endif; ?>

    </div>

    <!-- Exit hint shown only in fullscreen -->
    <button type="button" class="exit-hint" id="exitHint">
        ✕ خروج از تمام صفحه (Esc)
    </button>

    <?php if ($resultHtml && $gameId): ?>
        <script>
            (function() {
                const GAME_ID = <?= json_encode($gameId) ?>;
                const frame = document.getElementById('gameFrame');
                const wrap = document.getElementById('gameFrameWrap');
                const btn = document.getElementById('fullscreenBtn');
                const btnLabel = document.getElementById('fullscreenLabel');
                const reloadBtn = document.getElementById('reloadBtn');
                const exitHint = document.getElementById('exitHint');

                // ---- report game result to the server ----
                window.addEventListener('message', function(e) {
                    if (!frame || e.source !== frame.contentWindow) return;
                    const d = e.data;
                    if (!d || d.source !== 'game-stats' || d.type !== 'finish' || !d.payload) return;

                    axios.post('play.php?action=save_result', Object.assign({
                        game_id: GAME_ID
                    }, d.payload)).catch(function(err) {
                        console.error('save_result failed', err);
                    });
                });

                // ---- fullscreen state helpers ----
                const canFullscreenApi =
                    !!(wrap.requestFullscreen || wrap.webkitRequestFullscreen || wrap.msRequestFullscreen);

                function isFullscreen() {
                    return document.fullscreenElement
                        || document.webkitFullscreenElement
                        || document.msFullscreenElement
                        || document.body.classList.contains('is-fullscreen');
                }

                function updateButton() {
                    const on = isFullscreen();
                    btnLabel.textContent = on ? 'خروج از تمام صفحه' : 'تمام صفحه';
                    btn.title = on ? 'خروج از تمام صفحه' : 'تمام صفحه';
                    btn.firstChild.nodeValue = on ? '⛶ ' : '⛶ ';
                }

                function enterFallback() {
                    document.body.classList.add('is-fullscreen');
                }

                function exitFallback() {
                    document.body.classList.remove('is-fullscreen');
                }

                function enterFullscreen() {
                    if (wrap.requestFullscreen) {
                        wrap.requestFullscreen().catch(function() {
                            // API blocked (iframe permissions, etc.) -> visual fallback
                            enterFallback();
                        });
                    } else if (wrap.webkitRequestFullscreen) {
                        try { wrap.webkitRequestFullscreen(); } catch (e) { enterFallback(); }
                    } else if (wrap.msRequestFullscreen) {
                        try { wrap.msRequestFullscreen(); } catch (e) { enterFallback(); }
                    } else {
                        enterFallback();
                    }
                }

                function exitFullscreen() {
                    if (document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement) {
                        if (document.exitFullscreen) {
                            document.exitFullscreen().catch(function() {});
                        } else if (document.webkitExitFullscreen) {
                            document.webkitExitFullscreen();
                        } else if (document.msExitFullscreen) {
                            document.msExitFullscreen();
                        }
                    }
                    // always clear the visual fallback too
                    exitFallback();
                }

                // ---- button ----
                btn.addEventListener('click', function() {
                    if (isFullscreen()) {
                        exitFullscreen();
                    } else {
                        enterFullscreen();
                    }
                });

                // ---- exit hint ----
                exitHint.addEventListener('click', exitFullscreen);

                // ---- sync state when browser toggles fullscreen (Esc, F11, ...) ----
                ['fullscreenchange', 'webkitfullscreenchange', 'msfullscreenchange'].forEach(function(ev) {
                    document.addEventListener(ev, function() {
                        // if we're leaving the real fullscreen, make sure
                        // the visual fallback is off too
                        if (!document.fullscreenElement
                            && !document.webkitFullscreenElement
                            && !document.msFullscreenElement) {
                            // don't remove is-fullscreen if user toggled it via fallback
                            // (only the browser-driven case reaches here)
                        }
                        updateButton();
                    });
                });

                // ---- Esc also closes the fallback mode ----
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && document.body.classList.contains('is-fullscreen')
                        && !document.fullscreenElement) {
                        exitFullscreen();
                    }
                });

                // ---- reload the game (fresh iframe => fresh start screen) ----
                reloadBtn.addEventListener('click', function() {
                    // re-assign srcdoc to fully reset the game state
                    const srcdoc = frame.getAttribute('srcdoc');
                    frame.setAttribute('srcdoc', '');
                    // give the browser a tick to release the old document
                    setTimeout(function() {
                        frame.setAttribute('srcdoc', srcdoc);
                    }, 30);
                });

                // initial button state
                updateButton();

                // ---- expose for other scripts if needed ----
                window.MegaGame = {
                    enterFullscreen: enterFullscreen,
                    exitFullscreen: exitFullscreen,
                    isFullscreen: isFullscreen
                };
            })();
        </script>
    <?php endif; ?>

</body>

</html>