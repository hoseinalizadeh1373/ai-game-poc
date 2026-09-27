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
        }
    }

// -----------------------------------------------------------------
// Action: create a new game
// -----------------------------------------------------------------
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $view = 'create';

$userPrompt = trim($_POST['prompt'] ?? '');
$count      = isset($_POST['count']) && $_POST['count'] !== '' ? (int) $_POST['count'] : null;
$selectedModel = $_POST['model'] ?? 'gapgpt-qwen-3.6'; // مقدار پیش‌فرض

// اعتبارسنجی مدل
$allowedModels = ['gapgpt-qwen-3.6', 'claude-fable-5', 'claude-sonnet-5', 'gpt-6-sol', 'deepseek-v4.1-flash'];
if (!in_array($selectedModel, $allowedModels, true)) {
    $selectedModel = 'gapgpt-qwen-3.6';
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

            $text = trim($apiResult['text']);
            $text = preg_replace('/^```html\s*/i', '', $text);
            $text = preg_replace('/^```\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim($text);

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

            if (stripos($text, '<!doctype html') === false && stripos($text, '<html') === false) {
                $error = 'AI بازی را به صورت HTML معتبر برنگرداند.';
            } else {
                $text = injectSharedAssets($text, $baseCss, $baseJs);

                $resultHtml  = $text;
                $resultTitle = extractGameTitle($text, $userPrompt);

                $gameId = newGameId();

                saveGame($gamesDir, $gameId, $resultHtml, $userPrompt);
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

<form id="createGameForm">

    <div style="margin-bottom: 15px;">
        <label for="model" style="color:#9ca3af; display:block; margin-bottom:6px;">انتخاب مدل هوش مصنوعی:</label>
        <select name="model" id="model" style="width:100%; padding:12px; border-radius:8px; border:1px solid #374151; background:#1f2937; color:white; font-size:16px;">
            <option value="gapgpt-qwen-3.6">Qwen 3.6 (پیشنهادی)</option>
            <option value="claude-fable-5">Claude Fable 5</option>
            <option value="claude-sonnet-5">Claude Sonnet 5</option>
            <option value="gpt-6-sol">GPT-6 Sol</option>
            <option value="deepseek-v4.1-flash">DeepSeek v4.1 Flash</option>
        </select>
    </div>

    <textarea
        name="prompt"
        id="prompt"
        placeholder="مثلاً: کوییز میوه‌ها برای کودکان ۵ ساله که هنوز خواندن و نوشتن بلد نیستند..."
    ></textarea>

    <div class="count-row">
        <label for="count">تعداد سوال / آیتم (اختیاری):</label>
        <input
            type="number"
            id="count"
            name="count"
            min="1"
            max="30"
            placeholder="مثلاً 5"
        >
    </div>

    <button type="submit" id="submitBtn">🚀 ساخت بازی</button>

    <div id="loading" style="display:none; margin-top:12px; color:#9ca3af;">
        ⏳ در حال ساخت بازی... (ممکن است تا چند دقیقه طول بکشد)
    </div>
    <div id="ajaxError" class="error" style="display:none;"></div>
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

    <?php if ($handDrawnCount > 0): ?>
        <div class="warning">
            ⚠️ هوش مصنوعی <?= (int) $handDrawnCount ?> مورد SVG را خودش دستی کشیده، به‌جای استفاده از آیکون‌های آماده‌ی <code>assets/icons</code>. بازی همچنان کار می‌کند، ولی این یعنی مدل قانون استفاده‌ی مجدد از آیکون را رعایت نکرده.
        </div>
    <?php endif; ?>

    <?php if ($brokenSvgCount > 0): ?>
        <div class="error">
            ⚠️ <?= (int) $brokenSvgCount ?> مورد placeholder آیکون به‌درستی جایگزین نشده (احتمالاً مدل آن را به‌صورت پویا در جاوااسکریپت ساخته، مثلاً <code>{{SVG:$&#123;item.id&#125;}}</code> به‌جای نوشتن مستقیم <code>{{SVG:cat}}</code>). این یعنی به‌جای آیکون، متن خام <code>{{SVG:...}}</code> روی صفحه‌ی بازی دیده می‌شود.
        </div>
    <?php endif; ?>

    <?php if (!empty($classCollisions)): ?>
        <div class="warning">
            ⚠️ هوش مصنوعی این کلاس‌های مشترک را با تعریف خودش بازنویسی کرده (که می‌تونه ظاهر بازی رو خراب کنه، مثلاً متن نامرئی):
            <?= htmlspecialchars(implode('، ', $classCollisions)) ?>
        </div>
    <?php endif; ?>

<?php if ($resultHtml): ?>

    <div class="game-container">

        <div class="game-header">
            <h2>🎮 <?= htmlspecialchars($resultTitle ?? 'بازی') ?></h2>
        </div>

        <?php if (!empty($gamePrompt)): ?>
            <div class="prompt-box" style="background:#1f2937; border:1px solid #374151; border-radius:12px; padding:16px; margin-bottom:20px; color:#e5e7eb; line-height:1.7;">
                <strong style="color:#a5b4fc;">پرامپت اصلی:</strong><br>
                <?= nl2br(htmlspecialchars($gamePrompt)) ?>
            </div>
        <?php endif; ?>

        <iframe
            class="game-frame"
            sandbox="allow-scripts"
            srcdoc="<?= htmlspecialchars($resultHtml, ENT_QUOTES, 'UTF-8') ?>"
        ></iframe>

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
createGameForm.addEventListener('submit', async function (e) {
    e.preventDefault();

    const promptInput = document.getElementById('prompt');
    const countInput  = document.getElementById('count');
    const modelSelect = document.getElementById('model');
    const btn         = document.getElementById('submitBtn');
    const loading     = document.getElementById('loading');
    const errorBox    = document.getElementById('ajaxError');

    const prompt = promptInput.value.trim();
    const count  = countInput.value;
    const model  = modelSelect.value;

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
