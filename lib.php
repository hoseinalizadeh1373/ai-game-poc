<?php
/**
 * lib.php — shared helpers.
 *
 * Architecture: only assets/icons/*.svg are a separate, structured part
 * of the project. Everything else (HTML, CSS, JS of each game) comes
 * straight from the AI in one response — no JSON, no base64, no split
 * template/data step. This keeps each request simple and fast for a
 * weak/slow target model.
 */

// ---------------------------------------------------------------------
// Filesystem helpers
// ---------------------------------------------------------------------

function ensureDir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function newGameId(): string
{
    return 'game_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
}

// ---------------------------------------------------------------------
// Icon assets (assets/icons/*.svg)
// ---------------------------------------------------------------------

/**
 * Scans assets/icons/*.svg and returns [id => raw svg markup].
 * id = file name without extension, lowercased. This is the ONLY
 * source of truth for available icons — add a file, it shows up
 * everywhere automatically; nothing to edit in code.
 */
function scanIconAssets(string $iconsDir): array
{
    $icons = [];

    if (!is_dir($iconsDir)) {
        return $icons;
    }

    foreach (glob($iconsDir . '/*.svg') as $file) {
        $id = strtolower(pathinfo($file, PATHINFO_FILENAME));
        // first file wins if two names collide only by case — keeps ids unique
        if (!isset($icons[$id])) {
            $icons[$id] = trim(file_get_contents($file));
        }
    }

    ksort($icons);
    return $icons;
}

/**
 * Builds the compact manifest text sent to the AI: just the ids,
 * one per line. File names are expected to be self-descriptive
 * (apple, star, num_3, game-controller, ...).
 */
function buildSvgManifest(array $icons): string
{
    if (empty($icons)) {
        return '(no icons available yet)';
    }
    return '- ' . implode("\n- ", array_keys($icons));
}

/**
 * Replaces every {{SVG:id}} placeholder in the AI-generated HTML with
 * the real icon markup. Tolerant of case/spacing mistakes. Unknown ids
 * become a harmless HTML comment instead of breaking the page, and are
 * collected for a soft warning.
 *
 * Context-aware: a weak model sometimes drops the placeholder INSIDE a
 * quoted JS string, e.g. icons: ["{{SVG:cat}}", ...]. The raw SVG markup
 * itself contains double quotes (viewBox="0 0 100 100"), which would
 * terminate that JS string early and produce a syntax error ("Unexpected
 * number"), so nothing on the page would render. When the placeholder is
 * directly wrapped by a matching quote character (", ' or `), we escape
 * that same quote character (and backslashes) inside the SVG before
 * inserting it, keeping the surrounding JS string valid. Plain HTML
 * placement (the normal case) is untouched.
 *
 * @return array{0:string,1:string[]} [finalHtml, missingIds]
 */
function applySvgPlaceholders(string $html, array $icons): array
{
    $missing = [];

    $result = preg_replace_callback(
        '/(["\'`])?\{\{\s*SVG\s*:\s*([a-zA-Z0-9_\-]+)\s*\}\}(["\'`])?/i',
        function (array $m) use ($icons, &$missing) {
            $quoteBefore = $m[1] ?? '';
            $id          = strtolower($m[2]);
            $quoteAfter  = $m[3] ?? '';

            if (!isset($icons[$id])) {
                $missing[] = $id;
                return $quoteBefore . '<!-- svg not found: ' . htmlspecialchars($id) . ' -->' . $quoteAfter;
            }

            $svg = $icons[$id];

            // wrapped in a matching quote char -> we're inside a JS/attribute string
            if ($quoteBefore !== '' && $quoteBefore === $quoteAfter) {
                $escaped = str_replace('\\', '\\\\', $svg);
                if ($quoteBefore === '`') {
                    $escaped = str_replace(['`', '${'], ['\\`', '\\${'], $escaped);
                } else {
                    $escaped = str_replace($quoteBefore, '\\' . $quoteBefore, $escaped);
                }
                return $quoteBefore . $escaped . $quoteAfter;
            }

            // plain HTML context — insert raw markup, put back any stray
            // single-sided character that wasn't really a matching pair
            return $quoteBefore . $svg . $quoteAfter;
        },
        $html
    );

    return [$result, array_values(array_unique($missing))];
}

// ---------------------------------------------------------------------
// System prompt
// ---------------------------------------------------------------------

function buildSystemPrompt(string $svgManifest): string
{
    $template = <<<'PROMPT'
You are an expert HTML5 browser game developer for children's educational games.

TASK
Generate one complete, self-contained, playable HTML5 game based on the user's request (given in the next message, along with a suggested item/question count if relevant).

OUTPUT FORMAT — STRICT
- Output ONLY raw HTML, starting with <!DOCTYPE html> and ending with </html>.
- No explanations, no markdown, no ``` fences, no text before or after the code.
- Everything (CSS and JavaScript) must be inline in that one HTML file.
- Include a short, clear <title>...</title> that names this specific game (in the same language as the user's request) — it is used as the game's display name, so make it good.

TECHNICAL RULES
- HTML5 + CSS3 + vanilla JavaScript only.
- Do NOT use React, Vue, Phaser, jQuery, or any external library.
- Do NOT link external files, external images, external fonts, or make any network request.
- Canvas is allowed when it helps the game.
- The game must be fully playable: real game logic, a score when relevant, and a clear win / lose / completion state.
- Responsive layout (works on both desktop and mobile).
- Visual style must be simple, colorful, and friendly for children.

GRAPHICS RULE — IMPORTANT
You do NOT draw most graphics yourself. A fixed set of ready-made icons already exists on the server. Whenever you need one of them, do not write <svg> code — write this exact placeholder text at that spot instead:

{{SVG:ID}}

"ID" must be exactly one of the ids below (lowercase, same spelling/hyphens, no changes). The server replaces each placeholder with the real icon before the game is shown, so the final game still works 100% offline.

AVAILABLE ICON IDS
___SVG_MANIFEST___

HOW TO USE THE PLACEHOLDER
1. Use it like a small inline image, e.g.: <div class="apple-box">{{SVG:apple}}</div>
2. Control its size with CSS on its parent element (width/height) — it scales automatically (viewBox-based).
3. Control its color with CSS "color: ..." on its parent element — it uses currentColor.
4. NEVER invent an id that is not in the list above.
5. NEVER hand-write <svg>...</svg> markup for anything that already exists in the list above — always use the placeholder, to keep your output short.
6. Do not request the same icon id under a different spelling — reuse the existing id exactly as listed; never create a near-duplicate.
7. Only if the game truly needs a visual that is NOT in the list, you may draw one very small, simple custom SVG or CSS shape yourself. Keep it minimal.
8. NEVER write the placeholder as a quoted JavaScript string, e.g. NEVER do `icons: ["{{SVG:cat}}", "{{SVG:dog}}"]`. The real icon markup contains double quotes and WILL break a JS string like that. Instead, either (a) put the placeholder directly as HTML content, e.g. `<div class="icon-slot" data-animal="cat">{{SVG:cat}}</div>`, and read/compare `data-animal` in JavaScript instead of the icon markup itself, or (b) if you truly need it in a JS array, wrap each entry in backticks, e.g. `icons: [\`{{SVG:cat}}\`, \`{{SVG:dog}}\`]`, never plain double or single quotes.

CODE SIZE RULES — IMPORTANT
- Write compact code. No comments. No unnecessary blank lines or unused CSS/JS.
- Reuse CSS classes instead of repeating inline styles.
- If the game needs many icons of the same kind, write one small JavaScript function/array to render them, instead of repeating similar HTML blocks by hand.

CORRECT EXAMPLE
User asked: "a game where the child taps the apple among 3 fruits"
Correct: <div class="fruit" data-correct="true">{{SVG:apple}}</div><div class="fruit">{{SVG:banana}}</div><div class="fruit">{{SVG:grape}}</div>
Wrong (never do this): drawing a full custom <svg><path d="..."/></svg> for the apple yourself.

FINAL REMINDER
Return ONLY the final HTML document. Start directly with <!DOCTYPE html>. Nothing before it, nothing after it.
PROMPT;

    return str_replace('___SVG_MANIFEST___', $svgManifest, $template);
}

// ---------------------------------------------------------------------
// API call
// ---------------------------------------------------------------------

/**
 * @return array{ok:bool, text:?string, error:?string}
 */
function callAiApi(array $config, string $systemPrompt, string $userMessage): array
{
    $payload = [
        'model' => $config['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
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
        // a single raw-HTML response is far smaller/faster to generate than
        // the earlier JSON+Base64 shape, but a slow target model can still
        // take a while — keep this generous.
        CURLOPT_TIMEOUT => 280,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $err     = curl_error($ch);
        $errno   = curl_errno($ch);
        curl_close($ch);
        $isTimeout = in_array($errno, [CURLE_OPERATION_TIMEDOUT], true);
        return [
            'ok' => false,
            'text' => null,
            'error' => ($isTimeout ? 'مهلت زمانی درخواست به پایان رسید (timeout). ' : 'خطای ارتباط با API: ') . $err,
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'text' => null, 'error' => "API Error ($httpCode): " . $response];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return ['ok' => false, 'text' => null, 'error' => 'پاسخ API قابل پردازش نیست.'];
    }

    $text = null;
    if (isset($data['choices'][0]['message']['content'])) {
        $text = $data['choices'][0]['message']['content'];
    } elseif (isset($data['output_text'])) {
        $text = $data['output_text'];
    }

    if (!$text) {
        return ['ok' => false, 'text' => null, 'error' => 'نتوانستم متن بازی تولیدشده را از پاسخ AI استخراج کنم.'];
    }

    return ['ok' => true, 'text' => $text, 'error' => null];
}

// ---------------------------------------------------------------------
// Title extraction
// ---------------------------------------------------------------------

function extractGameTitle(string $html, string $fallback): string
{
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
        if ($title !== '') {
            return $title;
        }
    }
    return $fallback;
}

// ---------------------------------------------------------------------
// Saved games (games/*.html)
// ---------------------------------------------------------------------

function saveGame(string $gamesDir, string $gameId, string $html): void
{
    file_put_contents($gamesDir . '/' . $gameId . '.html', $html);
}

function loadGame(string $gamesDir, string $gameId): ?string
{
    $file = $gamesDir . '/' . $gameId . '.html';
    return file_exists($file) ? file_get_contents($file) : null;
}

function listGames(string $gamesDir): array
{
    $games = [];
    foreach (glob($gamesDir . '/*.html') as $file) {
        $html = file_get_contents($file);
        $id   = basename($file, '.html');
        $games[] = [
            'id'         => $id,
            'title'      => extractGameTitle($html, $id),
            'created_at' => filemtime($file),
        ];
    }
    usort($games, function ($a, $b) {
        return $b['created_at'] <=> $a['created_at'];
    });
    return $games;
}