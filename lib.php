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

/**
 * The raw model output (before placeholder substitution) should NEVER
 * contain a literal "<svg" tag if the model followed the rules — a
 * compliant model only ever writes {{SVG:id}} placeholders, and real
 * <svg> tags only appear after our own substitution runs. So any "<svg"
 * found in the raw text is proof the model hand-drew an icon instead of
 * reusing the library — a 100% reliable check, not a guess.
 *
 * @return int number of hand-drawn <svg> tags found in the raw output
 */
function countHandDrawnSvg(string $rawModelOutput): int
{
    return preg_match_all('/<svg\b/i', $rawModelOutput);
}

/**
 * A successful applySvgPlaceholders() run consumes every {{SVG:id}} it
 * finds — valid ones become real markup, invalid ids become an HTML
 * comment. So after substitution, the literal text "{{SVG" should never
 * remain anywhere in the output. If it does, something didn't match the
 * expected static pattern at all — most commonly the model built the
 * placeholder dynamically at runtime, e.g. `{{SVG:${item.id}}}`, which
 * still contains the unresolved variable and can never be matched. This
 * catches that whole bug class (and any future variant) without needing
 * to know its exact shape in advance.
 *
 * @return int number of leftover, unresolved "{{SVG" fragments
 */
function countBrokenSvgPlaceholders(string $htmlAfterSubstitution): int
{
    return preg_match_all('/\{\{\s*SVG\b/i', $htmlAfterSubstitution);
}

// ---------------------------------------------------------------------
// Shared base CSS/JS (assets/base.css, assets/base.js)
//
// Unlike icons (one file = one id), these are two single files with
// several utilities each. To keep the same "just document it in a
// comment and it's picked up automatically" philosophy, every reusable
// class/function is announced with a "@class" / "@helper" comment right
// above it — the manifest builder below extracts those comments with a
// regex, so adding a new shared utility never requires touching lib.php.
// ---------------------------------------------------------------------

function loadBaseCss(string $assetsDir): string
{
    $file = $assetsDir . '/base.css';
    return file_exists($file) ? trim(file_get_contents($file)) : '';
}

function loadBaseJs(string $assetsDir): string
{
    $file = $assetsDir . '/base.js';
    return file_exists($file) ? trim(file_get_contents($file)) : '';
}

/**
 * Extracts "/* @class .name — description *\/" lines from base.css and
 * "// @helper name(args) — description" lines from base.js into one
 * compact manifest for the prompt.
 */
function buildCssJsManifest(string $css, string $js): string
{
    $lines = [];

    if (preg_match_all('/@class\s+(\S+)\s*—\s*(.+?)\*\//u', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $lines[] = '- ' . trim($match[1]) . ' (CSS class): ' . trim($match[2]);
        }
    }

    if (preg_match_all('/@helper\s+([a-zA-Z0-9_]+\([^)]*\))\s*—\s*(.+)/u', $js, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $lines[] = '- ' . trim($match[1]) . ' (JS function): ' . trim($match[2]);
        }
    }

    return implode("\n", $lines);
}

/**
 * Injects the shared CSS and JS into the very start of <head>, so they
 * are defined before anything the AI wrote (wherever it placed its own
 * <style>/<script>). This runs unconditionally — regardless of whether
 * the AI actually used any of these classes/functions — so the final
 * file is guaranteed consistent and still 100% self-contained (no
 * external <link>/<script src>, everything inlined once, at save time).
 */
function injectSharedAssets(string $html, string $css, string $js): string
{
    $inject = '';
    if ($css !== '') {
        $inject .= "<style>\n" . $css . "\n</style>\n";
    }
    if ($js !== '') {
        $inject .= "<script>\n" . $js . "\n</script>\n";
    }
    if ($inject === '') {
        return $html;
    }

    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $inject, $html, 1);
    }

    // no <head> found (model skipped it) — fall back to right after <html ...> or the very top
    if (preg_match('/<html[^>]*>/i', $html)) {
        return preg_replace('/(<html[^>]*>)/i', '$1' . "\n<head>" . $inject . "</head>\n", $html, 1);
    }

    return $inject . $html;
}

// ---------------------------------------------------------------------
// System prompt
// ---------------------------------------------------------------------

function buildSystemPrompt(string $svgManifest, string $cssJsManifest): string
{
    $template = <<<'PROMPT'
You are an expert HTML5 browser game developer for children's educational games.

TASK
Generate one complete, self-contained, playable HTML5 game based on the user's request.

OUTPUT FORMAT — STRICT
- Output ONLY raw HTML, starting with <!DOCTYPE html> and ending with </html>.
- No explanations, no markdown, no code fences.
- All CSS and JavaScript must be inline.
- Include a meaningful <title> in the same language as the user's request.

TECHNICAL RULES
- HTML5 + CSS3 + vanilla JavaScript only.
- Only allowed external resource: the local Iconify script.
- Fully playable game with real logic, score (when relevant), and clear win/lose/completion state.
- Must be responsive (works well on mobile and desktop).
- Prefer relative units (em, rem, %) so icons and layout scale nicely with text.
- Visual style: simple, colorful, friendly for children.

ICON SYSTEM — ICONIFY (COLORED ICONS)

You must use Iconify for every icon.

1. Always include this exact script in the <head>:
<script src="assets/iconify-icon.min.js"></script>

2. Use icons with the web component:
<iconify-icon icon="collection:icon-name" style="font-size: 2.5em;"></iconify-icon>

3. Strongly prefer colored icon sets:
- twemoji
- emojione
- noto
- fluent-emoji
- openmoji
- fluent-emoji-flat

You are free to use any valid icon from these collections. There is no limited list. Choose whatever icon fits the game best.

4. Size recommendation for responsive + text-heavy games:
Use font-size with em units (example: style="font-size: 2.2em; vertical-align: -0.15em;")

5. Strictly forbidden:
- Writing any <svg> ... </svg>
- Using <img src="assets/icons/...">
- Using {{SVG:...}} placeholders
- Using monotone/icon-font style icons when a colored version exists
- Using CDN links

BEFORE ANSWERING — SELF CHECK
- Iconify local script is present
- Every icon is written as <iconify-icon icon="...">
- Colored sets are preferred
- No <svg> tags exist
- Game is responsive

Return only the final HTML document.
PROMPT;

    return $template;
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
