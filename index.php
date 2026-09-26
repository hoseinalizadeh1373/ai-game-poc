<?php

// weak/slow target models can take a while — don't let PHP's own execution
// time limit kill the request before curl's own timeout does. If your
// hosting also sits behind nginx/Apache as a reverse proxy, its
// proxy_read_timeout / Timeout setting may need raising too.
set_time_limit(300);

require __DIR__ . '/lib.php';

$config = require __DIR__ . '/config.php';

$baseDir  = __DIR__;
$iconsDir = $baseDir . '/assets/icons';
$gamesDir = $baseDir . '/games';

ensureDir($iconsDir);
ensureDir($gamesDir);

$view         = 'home';
$error        = null;
$missingSvgs  = [];
$resultHtml   = null;
$resultTitle  = null;

// -----------------------------------------------------------------
// View: play a previously saved game
// -----------------------------------------------------------------
if (isset($_GET['play'])) {

    $view   = 'play';
    $gameId = basename((string) $_GET['play']);

    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $gameId)) {
        $error = 'شناسه بازی نامعتبر است.';
    } else {
        $html = loadGame($gamesDir, $gameId);
        if ($html === null) {
            $error = 'بازی مورد نظر پیدا نشد.';
        } else {
            $resultHtml  = $html;
            $resultTitle = extractGameTitle($html, $gameId);
        }
    }

// -----------------------------------------------------------------
// Action: create a new game
// -----------------------------------------------------------------
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $view = 'create';

    $userPrompt = trim($_POST['prompt'] ?? '');
    $count      = isset($_POST['count']) && $_POST['count'] !== '' ? (int) $_POST['count'] : null;
    if ($count !== null) {
        if ($count < 1)  $count = 1;
        if ($count > 30) $count = 30;
    }

    if ($userPrompt === '') {
        $error = 'لطفاً توضیح بازی را وارد کنید.';
    } else {

        $icons        = scanIconAssets($iconsDir);
        $svgManifest  = buildSvgManifest($icons);
        $systemPrompt = buildSystemPrompt($svgManifest);

        $userMessage = 'درخواست کاربر: "' . $userPrompt . '"';
        if ($count !== null) {
            $userMessage .= "\n" . 'تعداد سوال/آیتم پیشنهادی (در صورتی که این نوع بازی به آن نیاز دارد): ' . $count;
        }

        $apiResult = callAiApi($config, $systemPrompt, $userMessage);

        if (!$apiResult['ok']) {
            $error = $apiResult['error'];
        } else {

            $text = trim($apiResult['text']);
            $text = preg_replace('/^```html\s*/i', '', $text);
            $text = preg_replace('/^```\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim($text);

            [$text, $missingSvgs] = applySvgPlaceholders($text, $icons);

            if (stripos($text, '<!doctype html') === false && stripos($text, '<html') === false) {
                $error = 'AI بازی را به صورت HTML معتبر برنگرداند.';
            } else {
                $resultHtml  = $text;
                $resultTitle = extractGameTitle($text, $userPrompt);

                $gameId = newGameId();
                saveGame($gamesDir, $gameId, $resultHtml);
            }
        }
    }
}

$games = listGames($gamesDir);

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Game Builder</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Tahoma, Arial, sans-serif;
            background: #111827;
            color: white;
        }

        .container {
            max-width: 1400px;
            margin: auto;
            padding: 30px;
        }

        h1 { margin-top: 0; }

        .description {
            color: #9ca3af;
            margin-bottom: 25px;
        }

        textarea {
            width: 100%;
            min-height: 130px;
            padding: 18px;
            border-radius: 12px;
            border: 1px solid #374151;
            background: #1f2937;
            color: white;
            font-size: 16px;
            resize: vertical;
            outline: none;
        }

        .count-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
        }

        .count-row label { color: #9ca3af; }

        .count-row input {
            width: 90px;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #374151;
            background: #1f2937;
            color: white;
            font-size: 16px;
        }

        button {
            margin-top: 15px;
            margin-inline-end: 10px;
            padding: 14px 28px;
            border: 0;
            border-radius: 10px;
            background: #6366f1;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover { background: #4f46e5; }

        button.secondary { background: #10b981; }
        button.secondary:hover { background: #059669; }

        .error {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            background: #7f1d1d;
            color: #fecaca;
            direction: ltr;
            text-align: left;
            white-space: pre-wrap;
        }

        .warning {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            background: #78350f;
            color: #fde68a;
        }

        .game-container { margin-top: 30px; }

        .game-header {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .game-frame {
            width: 100%;
            height: 700px;
            border: 2px solid #374151;
            border-radius: 15px;
            background: white;
        }

        .games-list { margin-top: 50px; }

        .games-list h2 {
            border-bottom: 1px solid #374151;
            padding-bottom: 10px;
        }

        .games-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .game-card {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 12px;
            padding: 16px;
            text-decoration: none;
            color: white;
            display: block;
        }

        .game-card:hover { border-color: #6366f1; }

        .game-card .title { font-weight: bold; margin-bottom: 6px; }

        .game-card .meta {
            color: #9ca3af;
            font-size: 13px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 15px;
            color: #a5b4fc;
            text-decoration: none;
        }

        .empty { color: #6b7280; margin-top: 20px; }
    </style>
</head>

<body>

<div class="container">

    <h1>🎮 AI Game Builder</h1>

    <?php if ($view === 'play'): ?>
        <a class="back-link" href="index.php">← بازگشت به خانه</a>
    <?php else: ?>

        <div class="description">
            توضیح بده چه بازی‌ای می‌خواهی؛ هوش مصنوعی کل بازی (HTML، CSS، JS) را مستقیماً می‌سازد و فقط از آیکون‌های آماده‌ی پروژه استفاده می‌کند.
        </div>

        <form method="POST">

            <textarea
                name="prompt"
                placeholder="مثلاً: کوییز میوه‌ها برای کودکان ۵ ساله که هنوز خواندن و نوشتن بلد نیستند..."
            ><?= htmlspecialchars($_POST['prompt'] ?? '') ?></textarea>

            <div class="count-row">
                <label for="count">تعداد سوال / آیتم (اختیاری):</label>
                <input
                    type="number"
                    id="count"
                    name="count"
                    min="1"
                    max="30"
                    value="<?= htmlspecialchars($_POST['count'] ?? '') ?>"
                    placeholder="مثلاً 5"
                >
            </div>

            <button type="submit">🚀 ساخت بازی</button>

        </form>

    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($missingSvgs)): ?>
        <div class="warning">
            ⚠️ هوش مصنوعی به این آیکون‌ها اشاره کرده که در پوشه‌ی assets/icons وجود ندارند (نادیده گرفته شدند):
            <?= htmlspecialchars(implode('، ', $missingSvgs)) ?>
        </div>
    <?php endif; ?>

    <?php if ($resultHtml): ?>

        <div class="game-container">

            <div class="game-header">
                <h2>🎮 <?= htmlspecialchars($resultTitle ?? 'بازی') ?></h2>
                <button type="button" class="secondary" onclick="downloadGame()">
                    ⬇️ دریافت خروجی (HTML)
                </button>
            </div>

            <iframe
                class="game-frame"
                sandbox="allow-scripts"
                srcdoc="<?= htmlspecialchars($resultHtml, ENT_QUOTES, 'UTF-8') ?>"
            ></iframe>

            <input type="hidden" id="gameData" value="<?= base64_encode($resultHtml) ?>">

        </div>

    <?php endif; ?>

    <?php if ($view !== 'play'): ?>

        <div class="games-list">
            <h2>بازی‌های ساخته‌شده</h2>

            <?php if (empty($games)): ?>
                <div class="empty">هنوز هیچ بازی‌ای ساخته نشده است.</div>
            <?php else: ?>
                <div class="games-grid">
                    <?php foreach ($games as $g): ?>
                        <a class="game-card" href="index.php?play=<?= urlencode($g['id']) ?>">
                            <div class="title"><?= htmlspecialchars($g['title']) ?></div>
                            <div class="meta"><?= htmlspecialchars(date('Y-m-d H:i', $g['created_at'])) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<script>
function downloadGame() {
    var field = document.getElementById('gameData');
    if (!field) return;

    var bytes = Uint8Array.from(atob(field.value), function (c) {
        return c.charCodeAt(0);
    });
    var html = new TextDecoder('utf-8').decode(bytes);

    var blob = new Blob([html], { type: 'text/html' });
    var url = URL.createObjectURL(blob);

    var a = document.createElement('a');
    a.href = url;
    a.download = 'game.html';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    URL.revokeObjectURL(url);
}
</script>

</body>
</html>
