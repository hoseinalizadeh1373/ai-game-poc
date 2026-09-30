<?php

set_time_limit(300);

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
$gameId          = null;

// -----------------------------------------------------------------
// Model metadata — descriptive UI info for each model.
// Keys must match allowedModels() ids. Unknown ids get a generic card.
// speed / quality are 1..5 (shown as mini meters).
// -----------------------------------------------------------------
$modelMeta = [
    'deepseek-v4-flash' => [
        'icon'    => '⚡',
        'desc'    => 'سریع‌ترین گزینه؛ برای بازی‌های ساده و متوسط عالی است و در کمترین زمان آماده می‌شود.',
        'speed'   => 5,
        'quality' => 2,
        'tags'    => ['کوییز', 'حافظه', 'واکنش سریع'],
        'badge'   => 'پیشنهادی',
    ],
    'claude-fable-5' => [
        'icon'    => '🎨',
        'desc'    => 'خلاق و داستان‌محور؛ برای ماجراجویی، روایت تعاملی و بازی‌های سناریومحور.',
        'speed'   => 3,
        'quality' => 4,
        'tags'    => ['ماجراجویی', 'داستانی', 'نقش‌آفرینی'],
        'badge'   => null,
    ],
    'claude-sonnet-5' => [
        'icon'    => '🧠',
        'desc'    => 'متعادل و دقیق؛ برای بازی‌های آموزشی با منطق چندلایه و سوالات پیچیده.',
        'speed'   => 3,
        'quality' => 5,
        'tags'    => ['آموزشی', 'پازل', 'استراتژی'],
        'badge'   => null,
    ],
    'gpt-6-sol' => [
        'icon'    => '✨',
        'desc'    => 'پیشرفته و همه‌کاره؛ برای پیچیده‌ترین بازی‌ها با مکانیک‌های متعدد و گرافیک غنی.',
        'speed'   => 2,
        'quality' => 5,
        'tags'    => ['همه‌کاره', 'حرفه‌ای', 'گرافیکی'],
        'badge'   => null,
    ],
];

$modelFallback = [
    'icon'    => '🎮',
    'desc'    => 'اطلاعات این مدل به‌زودی اضافه می‌شود.',
    'speed'   => 3,
    'quality' => 3,
    'tags'    => [],
    'badge'   => null,
];

$speedLabels   = [1 => 'خیلی کند', 2 => 'کند', 3 => 'متوسط', 4 => 'خوب', 5 => 'عالی'];
$qualityLabels = [1 => 'ضعیف',     2 => 'پایه', 3 => 'خوب',   4 => 'خیلی خوب', 5 => 'عالی'];

// -----------------------------------------------------------------
// Action: create a new game
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $userPrompt = trim($_POST['prompt'] ?? '');
    $creator    = sanitizeCreatorName((string) ($_POST['creator'] ?? ''));
    $count      = isset($_POST['count']) && $_POST['count'] !== '' ? (int) $_POST['count'] : null;
    $selectedModel = $_POST['model'] ?? 'deepseek-v4-flash';

    $allowedModels = array_keys(allowedModels());
    if (!in_array($selectedModel, $allowedModels, true)) {
        $selectedModel = 'deepseek-v4-flash';
    }
    if ($count !== null) {
        if ($count < 1)  $count = 1;
        if ($count > 30) $count = 30;
    }

    if ($userPrompt === '') {
        $error = 'لطفاً توضیح بازی را وارد کنید.';
    } elseif ($creator === '') {
        $error = 'لطفاً نام کاربری خود را وارد کنید.';
    } else {

        $lockFile = $dataDir . '/.generation.lock';
        $lock = acquireGenerationLock($lockFile);

        if (!$lock['ok']) {
            $error = $lock['error'];
        } else {
            try {
                $icons          = scanIconAssets($iconsDir);
                $svgManifest    = buildSvgManifest($icons);
                $baseCss        = loadBaseCss($assetsDir);
                $baseJs         = loadBaseJs($assetsDir);
                $cssJsManifest  = buildCssJsManifest($baseCss, $baseJs);
                $systemPrompt   = buildSystemPrompt($svgManifest, $cssJsManifest);

                $userMessage = 'USER REQUEST: "' . $userPrompt . '"';
                if ($count !== null) {
                    $userMessage .= "\n" . 'Suggested number of questions/items (only if this game type needs it): ' . $count;
                }

                $apiResult = callAiApi($config, $systemPrompt, $userMessage, $selectedModel);
                logApiUsage($dataDir, 'create', (string) ($selectedModel ?: ($config['model'] ?? '')), $apiResult['usage'] ?? null, $apiResult['opt'] ?? null);
                if (!$apiResult['ok']) {
                    $error = $apiResult['error'];
                } else {

                    $rawText = trim($apiResult['text']);
                    [$text, $hadExtraText] = extractHtmlDocument($rawText);

                    if ($text === null) {
                        $error = 'AI بازی را به صورت HTML معتبر برنگرداند.';
                    } else {

                        $handDrawnCount = countHandDrawnSvg($text);
                        [$text, $missingSvgs] = applySvgPlaceholders($text, $icons);
                        $brokenSvgCount = countBrokenSvgPlaceholders($text);
                        $classCollisions = detectSharedClassCollisions($text, $baseCss);

                        $text = normalizeFontPath($text);
                        $text = injectSharedAssets($text, $baseCss, buildGameStatsRuntime() . "\n" . $baseJs);

                        $title = extractGameTitle($text, $userPrompt);

                        $gameId = newGameId();
                        saveGame($gamesDir, $gameId, $text, $userPrompt, $selectedModel, $creator);
                        initGameResultsFile($dataDir, $gamesDir, $gameId);
                    }
                }
            } finally {
                releaseGenerationLock($lockFile);
            }
        }
    }

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

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ساخت بازی | Mega Game</title>
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
            max-width: 860px;
            margin: 0 auto;
            padding: 40px 24px 80px;
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

        .page-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .page-header h1 {
            margin: 0 0 12px;
            font-size: clamp(1.6rem, 5vw, 2.1rem);
            font-weight: 700;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd, #f0abfc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .description {
            color: #94a3b8;
            font-size: 1rem;
            line-height: 1.7;
            margin: 0 auto;
        }

        .form-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
        }

        .form-card label.field-label {
            display: block;
            color: #94a3b8;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .form-card select,
        .form-card textarea,
        .form-card input[type="text"],
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
        .form-card input[type="text"]:focus,
        .form-card input[type="number"]:focus {
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2);
        }

        .form-card textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.6;
        }

        .field { margin-bottom: 22px; }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }

        .form-row .field { flex: 1; min-width: 140px; margin-bottom: 0; }

        .form-row input[type="number"] { max-width: 120px; }

        /* ============================================================
           Compact horizontal model rows — full width, one per line
           ============================================================ */
        .model-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .model-row {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid rgba(148, 163, 184, 0.14);
            background: rgba(15, 23, 42, 0.5);
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s, transform 0.15s;
            user-select: none;
        }

        .model-row input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .model-row:hover {
            border-color: rgba(129, 140, 248, 0.4);
            background: rgba(15, 23, 42, 0.75);
        }

        .model-row.is-selected,
        .model-row:has(input:checked) {
            border-color: #818cf8;
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.14), rgba(139, 92, 246, 0.04));
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.12), 0 4px 16px rgba(99, 102, 241, 0.15);
        }

        .model-row:has(input:focus-visible) {
            outline: 2px solid #a5b4fc;
            outline-offset: 2px;
        }

        /* --- radio dot --- */
        .model-dot {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid rgba(148, 163, 184, 0.45);
            flex-shrink: 0;
            position: relative;
            transition: border-color 0.2s;
        }

        .model-dot::after {
            content: '';
            position: absolute;
            inset: 3px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            opacity: 0;
            transform: scale(0);
            transition: opacity 0.2s, transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .model-row.is-selected .model-dot,
        .model-row:has(input:checked) .model-dot {
            border-color: #a5b4fc;
        }

        .model-row.is-selected .model-dot::after,
        .model-row:has(input:checked) .model-dot::after {
            opacity: 1;
            transform: scale(1);
        }

        /* --- icon --- */
        .model-icon {
            font-size: 1.3rem;
            line-height: 1;
            flex-shrink: 0;
            width: 32px;
            text-align: center;
        }

        /* --- info block (name + tags) --- */
        .model-info {
            flex: 1 1 auto;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .model-info-top {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .model-name {
            font-weight: 600;
            font-size: 0.92rem;
            color: #f1f5f9;
            white-space: nowrap;
        }

        .model-badge {
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 0.65rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.4);
            white-space: nowrap;
        }

        .model-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .model-tags .tag {
            padding: 1px 7px;
            border-radius: 99px;
            font-size: 0.65rem;
            color: #a5b4fc;
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(129, 140, 248, 0.2);
            white-space: nowrap;
        }

        /* --- mini meters on the left --- */
        .model-meters {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            width: 110px;
        }

        .mini-meter {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.65rem;
            color: #94a3b8;
        }

        .mini-meter .mini-icon {
            width: 12px;
            text-align: center;
            flex-shrink: 0;
        }

        .mini-track {
            flex: 1;
            height: 4px;
            border-radius: 99px;
            background: rgba(148, 163, 184, 0.15);
            overflow: hidden;
        }

        .mini-fill {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #22c55e, #84cc16);
            transition: width 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .mini-fill.quality {
            background: linear-gradient(90deg, #6366f1, #a855f7);
        }

        /* On mobile the mini-meters drop below the info */
        @media (max-width: 560px) {
            .model-row {
                flex-wrap: wrap;
            }
            .model-meters {
                width: 100%;
                margin-top: 2px;
                padding-right: 56px; /* align under info */
            }
        }

        /* ------------------------------------------------------------ */

        button[type="submit"] {
            margin-top: 24px;
            width: 100%;
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

        button[type="submit"]:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        #loading {
            margin-top: 16px;
            color: #a5b4fc;
            font-size: 0.95rem;
            text-align: center;
        }

        .error {
            margin-top: 16px;
            padding: 14px 18px;
            border-radius: 12px;
            background: rgba(127, 29, 29, 0.5);
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: #fecaca;
            font-size: 0.9rem;
            line-height: 1.7;
            white-space: pre-wrap;
        }

        @media (max-width: 640px) {
            .container { padding: 24px 16px 60px; }
            .form-card { padding: 20px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition-duration: 0.001ms !important;
                animation-duration: 0.001ms !important;
            }
        }
    </style>
</head>

<body>

    <div class="container">

        <a class="back-link" href="index.php">← بازگشت به لیست بازی‌ها</a>

        <div class="page-header">
            <h1>🚀 ساخت بازی جدید</h1>
            <p class="description">
                توضیح بده چه بازی‌ای می‌خوای، هوش مصنوعی برات می‌سازدش
            </p>
        </div>

        <div class="form-card">
            <form id="createGameForm">

                <div class="field">
                    <label class="field-label" for="creator">نام کاربری شما (سازنده بازی)</label>
                    <input
                        type="text"
                        name="creator"
                        id="creator"
                        maxlength="40"
                        autocomplete="username"
                        placeholder="مثلاً: parsa">
                </div>

                <div class="field">
                    <label class="field-label">مدل هوش مصنوعی</label>

                    <div class="model-list" id="modelList">
                        <?php foreach (allowedModels() as $mid => $mlabel): ?>
                            <?php
                                $meta = $modelMeta[$mid] ?? $modelFallback;
                                $isDefault = ($mid === 'deepseek-v4-flash');
                                $speed = max(1, min(5, (int) $meta['speed']));
                                $quality = max(1, min(5, (int) $meta['quality']));
                                $speedPct = $speed * 20;
                                $qualityPct = $quality * 20;
                                $speedText = $speedLabels[$speed] ?? '—';
                                $qualityText = $qualityLabels[$quality] ?? '—';

                                $tooltip = $mlabel . ' — ' . $meta['desc']
                                    . "\n\nسرعت: " . $speedText
                                    . "\nکیفیت: " . $qualityText;
                            ?>
                            <label
                                class="model-row<?= $isDefault ? ' is-selected' : '' ?>"
                                data-model="<?= htmlspecialchars($mid) ?>"
                                title="<?= htmlspecialchars($tooltip, ENT_QUOTES) ?>">

                                <input
                                    type="radio"
                                    name="model"
                                    value="<?= htmlspecialchars($mid) ?>"
                                    <?= $isDefault ? 'checked' : '' ?>>

                                <span class="model-dot" aria-hidden="true"></span>
                                <span class="model-icon" aria-hidden="true"><?= htmlspecialchars($meta['icon']) ?></span>

                                <div class="model-info">
                                    <div class="model-info-top">
                                        <span class="model-name"><?= htmlspecialchars($mlabel) ?></span>
                                        <?php if (!empty($meta['badge'])): ?>
                                            <span class="model-badge"><?= htmlspecialchars($meta['badge']) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($meta['tags'])): ?>
                                        <div class="model-tags">
                                            <?php foreach ($meta['tags'] as $tag): ?>
                                                <span class="tag"><?= htmlspecialchars($tag) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="model-meters" aria-hidden="true">
                                    <div class="mini-meter">
                                        <span class="mini-icon">⚡</span>
                                        <div class="mini-track">
                                            <div class="mini-fill" style="width: <?= $speedPct ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="mini-meter">
                                        <span class="mini-icon">✨</span>
                                        <div class="mini-track">
                                            <div class="mini-fill quality" style="width: <?= $qualityPct ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="field">
                    <label class="field-label" for="prompt">توضیح بازی</label>
                    <textarea
                        name="prompt"
                        id="prompt"
                        placeholder="مثلاً: کوییز میوه‌ها برای کودکان ۵ ساله که هنوز خواندن و نوشتن بلد نیستند..."></textarea>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label class="field-label" for="count">تعداد سوال / آیتم (اختیاری)</label>
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

                <?php if ($error): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div id="ajaxError" class="error" style="display:none;"></div>
            </form>
        </div>

    </div>

    <script>
        const createGameForm = document.getElementById('createGameForm');
        if (createGameForm) {

            // ---------- model rows: sync .is-selected (JS fallback for :has) ----------
            const modelRows = document.querySelectorAll('.model-row');
            const modelRadios = document.querySelectorAll('input[name="model"]');

            function syncSelected() {
                modelRows.forEach(function(row) {
                    const radio = row.querySelector('input[name="model"]');
                    if (radio && radio.checked) {
                        row.classList.add('is-selected');
                    } else {
                        row.classList.remove('is-selected');
                    }
                });
            }
            modelRadios.forEach(function(r) {
                r.addEventListener('change', syncSelected);
            });
            syncSelected();

            // ---------- remember the creator name on this browser ----------
            try {
                const savedName = localStorage.getItem('creatorName');
                const ci = document.getElementById('creator');
                if (savedName && ci && !ci.value) ci.value = savedName;
            } catch (e) {}

            // ---------- submit ----------
            createGameForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const promptInput = document.getElementById('prompt');
                const countInput = document.getElementById('count');
                const btn = document.getElementById('submitBtn');
                const loading = document.getElementById('loading');
                const errorBox = document.getElementById('ajaxError');

                const prompt = promptInput.value.trim();
                const creator = document.getElementById('creator').value.trim();
                const count = countInput.value;

                const modelRadio = document.querySelector('input[name="model"]:checked');
                const model = modelRadio ? modelRadio.value : 'deepseek-v4-flash';

                errorBox.style.display = 'none';
                errorBox.textContent = '';

                if (!prompt) {
                    errorBox.style.display = 'block';
                    errorBox.textContent = 'لطفاً توضیح بازی را وارد کنید.';
                    return;
                }

                if (!creator) {
                    errorBox.style.display = 'block';
                    errorBox.textContent = 'لطفاً نام کاربری خود را وارد کنید.';
                    return;
                }
                try { localStorage.setItem('creatorName', creator); } catch (e) {}

                btn.disabled = true;
                btn.textContent = 'در حال ارسال...';
                loading.style.display = 'block';

                try {
                    const formData = new FormData();
                    formData.append('prompt', prompt);
                    formData.append('model', model);
                    formData.append('creator', creator);
                    if (count) formData.append('count', count);

                    const response = await axios.post('create.php', formData, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        timeout: 300000
                    });

                    const data = response.data;

                    if (data.ok && data.gameId) {
                        let url = 'play.php?id=' + encodeURIComponent(data.gameId);
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