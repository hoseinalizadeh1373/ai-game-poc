<?php

// weak/slow target models can take a while — don't let PHP's own execution
// time limit kill the request before curl's own timeout does. If your
// hosting also sits behind nginx/Apache as a reverse proxy, its
// proxy_read_timeout / Timeout setting may need raising too.
set_time_limit(300);

require __DIR__ . '/lib.php';

$config = require __DIR__ . '/config.php';

$baseDir   = __DIR__;
$assetsDir = $baseDir . '/assets';
$iconsDir  = $assetsDir . '/icons';
$gamesDir  = $baseDir . '/games';

ensureDir($iconsDir);
ensureDir($gamesDir);

$view            = 'home';
$error           = null;
$missingSvgs     = [];
$handDrawnCount  = 0;
$brokenSvgCount  = 0;
$classCollisions = [];
$hadExtraText    = false;
$resultHtml      = null;
$resultTitle     = null;
$gameId          = null;

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
            $gamePrompt  = getGamePrompt($gamesDir, $gameId);
            $gameModel   = getGameModel($gamesDir, $gameId);

            // carried over from the AJAX create redirect (see below) so
            // warnings from the generation that just happened aren't lost
            if (!empty($_GET['missing'])) {
                $missingSvgs = array_filter(explode(',', (string) $_GET['missing']));
            }
            $handDrawnCount  = isset($_GET['handdrawn']) ? (int) $_GET['handdrawn'] : 0;
            $brokenSvgCount  = isset($_GET['broken']) ? (int) $_GET['broken'] : 0;
            if (!empty($_GET['collide'])) {
                $classCollisions = array_filter(explode(',', (string) $_GET['collide']));
            }
            $hadExtraText = !empty($_GET['extratext']);
        }
    }

    // -----------------------------------------------------------------
    // Action: create a new game
    // -----------------------------------------------------------------
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $view = 'create';

    $userPrompt = trim($_POST['prompt'] ?? '');
    $count      = isset($_POST['count']) && $_POST['count'] !== '' ? (int) $_POST['count'] : null;
    $selectedModel = $_POST['model'] ?? 'deepseek-v4.1-flash'; // مقدار پیش‌فرض

    // اعتبارسنجی مدل
    $allowedModels = ['deepseek-v4.1-flash', 'claude-fable-5', 'claude-sonnet-5', 'gpt-6-sol'];
    if (!in_array($selectedModel, $allowedModels, true)) {
        $selectedModel = 'deepseek-v4.1-flash';
    }
    if ($count !== null) {
        if ($count < 1)  $count = 1;
        if ($count > 30) $count = 30;
    }

    if ($userPrompt === '') {
        $error = 'لطفاً توضیح بازی را وارد کنید.';
    } else {

        $icons          = scanIconAssets($iconsDir);
        $svgManifest    = buildSvgManifest($icons);
        $baseCss        = loadBaseCss($assetsDir);
        $baseJs         = loadBaseJs($assetsDir);
        $cssJsManifest  = buildCssJsManifest($baseCss, $baseJs);
        $systemPrompt   = buildSystemPrompt($svgManifest, $cssJsManifest);

        $userMessage = 'درخواست کاربر: "' . $userPrompt . '"';
        if ($count !== null) {
            $userMessage .= "\n" . 'تعداد سوال/آیتم پیشنهادی (در صورتی که این نوع بازی به آن نیاز دارد): ' . $count;
        }

        $apiResult = callAiApi($config, $systemPrompt, $userMessage, $selectedModel);
        if (!$apiResult['ok']) {
            $error = $apiResult['error'];
        } else {

            $rawText = trim($apiResult['text']);
            [$text, $hadExtraText] = extractHtmlDocument($rawText);

            if ($text === null) {
                $error = 'AI بازی را به صورت HTML معتبر برنگرداند.';
            } else {

                // must run BEFORE substitution: a compliant model's raw output
                // never contains a literal "<svg" tag (only {{SVG:id}} placeholders)
                $handDrawnCount = countHandDrawnSvg($text);

                [$text, $missingSvgs] = applySvgPlaceholders($text, $icons);

                // must run AFTER substitution: anything still matching "{{SVG"
                // at this point means the placeholder was built dynamically
                // (e.g. with a JS variable) and could never be resolved
                $brokenSvgCount = countBrokenSvgPlaceholders($text);

                // must run on the RAW model output, before we inject our own
                // <style> — otherwise we'd also match our own shared rules
                $classCollisions = detectSharedClassCollisions($text, $baseCss);

                // fix up whatever path the model wrote for the Vazir font
                // BEFORE injecting/saving, so the fix actually lands in the
                // saved file (previously this ran too late to have any effect)
                $text = normalizeFontPath($text);
                $text = injectSharedAssets($text, $baseCss, $baseJs);

                $resultHtml  = $text;
                $resultTitle = extractGameTitle($text, $userPrompt);

                $gameId = newGameId();
                saveGame($gamesDir, $gameId, $resultHtml, $userPrompt, $selectedModel);
            }
        }
    }

    // AJAX requests (see the fetch in the page's own <script> below) get a
    // small JSON response instead of the full page — the previous version
    // returned full HTML that the frontend ignored, which is why results
    // and errors from a submission never actually reached the user.
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'              => $error === null,
            'error'           => $error,
            'gameId'          => $gameId,
            'missingSvgs'     => $missingSvgs,
            'handDrawnCount'  => $handDrawnCount,
            'brokenSvgCount'  => $brokenSvgCount,
            'classCollisions' => $classCollisions,
            'hadExtraText'    => $hadExtraText,
        ], JSON_UNESCAPED_UNICODE);
        exit;
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
            padding: 40px 24px 80px;
        }

        /* Header */
        .page-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .page-header h1 {
            margin: 0 0 12px;
            font-size: clamp(1.8rem, 5vw, 2.4rem);
            font-weight: 700;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd, #f0abfc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .description {
            color: #94a3b8;
            font-size: 1.05rem;
            line-height: 1.7;
            max-width: 560px;
            margin: 0 auto;
        }

        /* Form Card */
        .form-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 48px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
        }

        .form-card label {
            display: block;
            color: #94a3b8;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .form-card select,
        .form-card textarea,
        .form-card input[type="number"] {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.15);
            background: rgba(15, 23, 42, 0.6);
            color: #f1f5f9;
            font-size: 15px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-card select:focus,
        .form-card textarea:focus,
        .form-card input[type="number"]:focus {
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2);
        }

        .form-card textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.6;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 16px;
            align-items: flex-end;
        }

        .form-row .field {
            flex: 1;
            min-width: 140px;
        }

        .form-row input[type="number"] {
            max-width: 120px;
        }

        button[type="submit"] {
            margin-top: 20px;
            padding: 14px 32px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            font-size: 16px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 16px rgba(99, 102, 241, 0.35);
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(99, 102, 241, 0.45);
        }

        button[type="submit"]:active {
            transform: translateY(0);
        }

        button[type="submit"]:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        #loading {
            margin-top: 16px;
            color: #a5b4fc;
            font-size: 0.95rem;
        }

        .error {
            margin-top: 16px;
            padding: 14px 18px;
            border-radius: 12px;
            background: rgba(127, 29, 29, 0.5);
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: #fecaca;
            direction: ltr;
            text-align: left;
            white-space: pre-wrap;
            font-size: 0.9rem;
        }

        /* Game play view */
        .game-container {
            margin-top: 8px;
        }

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

        .prompt-box {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            color: #e2e8f0;
            line-height: 1.7;
            font-size: 0.95rem;
        }

        .prompt-box strong {
            color: #a5b4fc;
        }

        .game-frame-wrap {
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.15);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            background: #fff;
        }

        .game-frame {
            width: 100%;
            height: min(78vh, 820px);
            min-height: 480px;
            border: 0;
            display: block;
            background: white;
        }

        /* Games list */
        .games-list {
            margin-top: 16px;
        }

        .games-list h2 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0 0 20px;
            color: #e2e8f0;
        }

        .games-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 14px;
        }

        .game-card {
            background: rgba(30, 41, 59, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            padding: 18px;
            text-decoration: none;
            color: #f1f5f9;
            display: block;
            transition: border-color 0.2s, transform 0.15s, box-shadow 0.2s;
        }

        .game-card:hover {
            border-color: #818cf8;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.15);
        }

        .game-card .title {
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.98rem;
            line-height: 1.4;
        }

        .game-card .meta {
            color: #64748b;
            font-size: 0.8rem;
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

        .back-link:hover {
            color: #c7d2fe;
        }

        .empty {
            color: #64748b;
            font-size: 0.95rem;
            padding: 24px 0;
        }

        @media (max-width: 640px) {
            .container { padding: 24px 16px 60px; }
            .form-card { padding: 20px; }
            .game-frame { min-height: 420px; height: 70vh; }
        }
    </style>
</head>

<body>

    <div class="container">

        <?php if ($view === 'play'): ?>
            <a class="back-link" href="index.php">← بازگشت به خانه</a>
        <?php else: ?>

            <div class="page-header">
                <h1>🎮 Mega Game</h1>
                <p class="description">
                    با استفاده از هوش مصنوعی ، بازی بساز
                </p>
            </div>

            <div class="form-card">
                <form id="createGameForm">

                    <div style="margin-bottom: 18px;">
                        <label for="model">مدل هوش مصنوعی</label>
                        <select name="model" id="model">
                            <option value="deepseek-v4.1-flash">DeepSeek v4.1 Flash (پیشنهادی)</option>
                            <option value="claude-fable-5">Claude Fable 5</option>
                            <option value="claude-sonnet-5">Claude Sonnet 5</option>
                            <option value="gpt-6-sol">GPT-6 Sol</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 4px;">
                        <label for="prompt">توضیح بازی</label>
                        <textarea
                            name="prompt"
                            id="prompt"
                            placeholder="مثلاً: کوییز میوه‌ها برای کودکان ۵ ساله که هنوز خواندن و نوشتن بلد نیستند..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="count">تعداد سوال / آیتم (اختیاری)</label>
                            <input
                                type="number"
                                id="count"
                                name="count"
                                min="1"
                                max="30"
                                placeholder="مثلاً ۵">
                        </div>
                    </div>

                    <button type="submit" id="submitBtn">🚀 ساخت بازی</button>

                    <div id="loading" style="display:none;">
                        ⏳ در حال ساخت بازی... (ممکن است تا چند دقیقه طول بکشد)
                    </div>
                    <div id="ajaxError" class="error" style="display:none;"></div>
                </form>
            </div>

        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($resultHtml): ?>

            <div class="game-container">

                <div class="game-header">
                    <h2>🎮 <?= htmlspecialchars($resultTitle ?? 'بازی') ?></h2>
                </div>

                <?php if (!empty($gamePrompt)): ?>
                    <div class="prompt-box">
                        <strong>پرامپت اصلی:</strong><br>
                        <?= nl2br(htmlspecialchars($gamePrompt)) ?>
                        <?php if (!empty($gameModel)): ?>
                            <br><strong>مدل استفاده‌شده:</strong> <?= htmlspecialchars($gameModel) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="game-frame-wrap">
                    <iframe
                        class="game-frame"
                        sandbox="allow-scripts allow-same-origin"
                        srcdoc="<?= htmlspecialchars($resultHtml, ENT_QUOTES, 'UTF-8') ?>"></iframe>
                </div>
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
        const createGameForm = document.getElementById('createGameForm');
        if (createGameForm) {
            createGameForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const promptInput = document.getElementById('prompt');
                const countInput = document.getElementById('count');
                const modelSelect = document.getElementById('model');
                const btn = document.getElementById('submitBtn');
                const loading = document.getElementById('loading');
                const errorBox = document.getElementById('ajaxError');

                const prompt = promptInput.value.trim();
                const count = countInput.value;
                const model = modelSelect.value;

                // پاک کردن خطای قبلی
                errorBox.style.display = 'none';
                errorBox.textContent = '';

                if (!prompt) {
                    errorBox.style.display = 'block';
                    errorBox.textContent = 'لطفاً توضیح بازی را وارد کنید.';
                    return;
                }

                // حالت لودینگ
                btn.disabled = true;
                btn.textContent = 'در حال ارسال...';
                loading.style.display = 'block';

                try {
                    const formData = new FormData();
                    formData.append('prompt', prompt);
                    formData.append('model', model);
                    if (count) {
                        formData.append('count', count);
                    }

                    const response = await axios.post('index.php', formData, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const data = response.data;

                    if (data.ok && data.gameId) {
                        let url = 'index.php?play=' + encodeURIComponent(data.gameId);
                        if (data.missingSvgs && data.missingSvgs.length) {
                            url += '&missing=' + encodeURIComponent(data.missingSvgs.join(','));
                        }
                        if (data.handDrawnCount) url += '&handdrawn=' + encodeURIComponent(data.handDrawnCount);
                        if (data.brokenSvgCount) url += '&broken=' + encodeURIComponent(data.brokenSvgCount);
                        if (data.classCollisions && data.classCollisions.length) {
                            url += '&collide=' + encodeURIComponent(data.classCollisions.join(','));
                        }
                        if (data.hadExtraText) url += '&extratext=1';
                        window.location.href = url;
                        return;
                    }

                    errorBox.style.display = 'block';
                    errorBox.textContent = data.error || 'خطای نامشخص از سرور.';

                } catch (err) {
                    console.error(err);
                    errorBox.style.display = 'block';
                    errorBox.textContent = 'خطا در ارتباط با سرور. دوباره تلاش کنید.';
                } finally {
                    btn.disabled = false;
                    btn.textContent = '🚀 ساخت بازی';
                    loading.style.display = 'none';
                }
            });
        }
    </script>

</body>

</html>