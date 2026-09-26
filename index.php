<?php

$config = require __DIR__ . '/config.php';

$game = null;
$error = null;
$rawResponse = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $userPrompt = trim($_POST['prompt'] ?? '');

    if ($userPrompt === '') {
        $error = 'لطفاً توضیح بازی را وارد کنید.';
    } else {

        $systemPrompt = <<<'PROMPT'
You are an expert HTML5 browser game developer.

Your task is to CREATE A REAL PLAYABLE GAME.

The user will describe a game in natural language.

You MUST generate the complete game yourself.

The final output must be a single self-contained HTML document.

Requirements:

- Use HTML5
- Use CSS3
- Use Vanilla JavaScript
- Do not use React
- Do not use Vue
- Do not use Phaser
- Do not use external libraries
- Do not use external JavaScript files
- Do not use external CSS files
- Do not use external images
- Do not use external network requests
- The game must work completely offline
- Use Canvas if it helps
- The game must actually be playable
- Include all game logic
- Include visual design
- Include animations where appropriate
- Include score where appropriate
- Include win/lose/completion state
- Make the game responsive
- Make it suitable for children when the prompt is educational

IMPORTANT:

Do not explain the code.

Do not return Markdown.

Do not return
```html.

Return ONLY the complete HTML document starting with <!DOCTYPE html>.

The result must be directly executable inside a browser iframe.
PROMPT;

$payload = [
'model' => $config['model'],
'messages' => [
[
'role' => 'system',
'content' => $systemPrompt,
],
[
'role' => 'user',
'content' => $userPrompt,
],
],
];

$ch = curl_init($config['api_url']);

curl_setopt_array($ch, [
CURLOPT_RETURNTRANSFER => true,
CURLOPT_POST => true,
CURLOPT_HTTPHEADER => [
'Content-Type: application/json',
'Authorization: Bearer ' . $config['api_key'],
],
CURLOPT_POSTFIELDS => json_encode($payload),
CURLOPT_TIMEOUT => 120,
]);

$response = curl_exec($ch);

if ($response === false) {
$error = 'خطای ارتباط با API: ' . curl_error($ch);
} else {

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$rawResponse = $response;

if ($httpCode < 200 || $httpCode >= 300) {

$error = "API Error ($httpCode): " . $response;

} else {

$data = json_decode($response, true);

if (!$data) {

$error = 'پاسخ API قابل پردازش نیست.';

} else {

$text = null;

// استخراج خروجی طبق فرمت استاندارد Chat Completions
if (isset($data['choices'][0]['message']['content'])) {
$text = $data['choices'][0]['message']['content'];
} elseif (isset($data['output_text'])) {
$text = $data['output_text'];
}

if (!$text) {
$error = 'نتوانستم متن بازی تولیدشده را از پاسخ AI استخراج کنم.';
} else {

/*
* اگر مدل اشتباهاً Markdown برگرداند،
* wrapper را حذف می‌کنیم.
*/

$text = trim($text);

$text = preg_replace('/^
```html\s*/i', '', $text);
                        $text = preg_replace('/^
```\s*/', '', $text);
$text = preg_replace('/\s*
```$/', '', $text);

                        $game = trim($text);

                        /*
                         * یک بررسی بسیار ساده:
                         */

                        if (
                            stripos($game, '<!doctype html') === false &&
                            stripos($game, '<html') === false
                        ) {
                            $error = 'AI بازی را به صورت HTML معتبر برنگرداند.';
                            $game = null;
                        }
                    }
                }
            }
        }

        curl_close($ch);
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>AI Game Builder POC</title>

    <style>
        * {
            box-sizing: border-box;
        }

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

        h1 {
            margin-top: 0;
        }

        .description {
            color: #9ca3af;
            margin-bottom: 25px;
        }

        textarea {
            width: 100%;
            min-height: 150px;
            padding: 18px;
            border-radius: 12px;
            border: 1px solid #374151;
            background: #1f2937;
            color: white;
            font-size: 16px;
            resize: vertical;
            outline: none;
        }

        button {
            margin-top: 15px;
            padding: 14px 28px;
            border: 0;
            border-radius: 10px;
            background: #6366f1;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #4f46e5;
        }

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

        .game-container {
            margin-top: 30px;
        }

        .game-header {
            margin-bottom: 15px;
        }

        .game-frame {
            width: 100%;
            height: 700px;
            border: 2px solid #374151;
            border-radius: 15px;
            background: white;
        }

        .loading {
            display: inline-block;
            margin-right: 10px;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>🎮 AI Game Builder — POC</h1>

    <div class="description">
        توضیح بده چه بازی‌ای می‌خواهی؛ هوش مصنوعی باید بازی واقعی را بسازد.
    </div>

    <form method="POST">

        <textarea
            name="prompt"
            placeholder="مثلاً: یک بازی جمع و تفریق برای کودکان ۸ ساله بساز..."
        ><?= htmlspecialchars($_POST['prompt'] ?? '') ?></textarea>

        <br>

        <button type="submit">
            🚀 ساخت بازی
        </button>

    </form>

    <?php if ($error): ?>

        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($game): ?>

        <div class="game-container">

            <div class="game-header">
                <h2>🎮 بازی تولید شده</h2>
            </div>

            <iframe
                class="game-frame"
                sandbox="allow-scripts"
                srcdoc="<?= htmlspecialchars($game, ENT_QUOTES, 'UTF-8') ?>"
            ></iframe>

        </div>

    <?php endif; ?>

</div>

</body>
</html>
