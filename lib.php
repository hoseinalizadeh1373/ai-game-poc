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
/**
 * Injects the shared JS at the very START of <head> (so functions/vars
 * are defined before anything the AI wrote runs), and the shared CSS at
 * the very END of <head> (right before </head>).
 *
 * The CSS position matters: a weak model sometimes ignores the "do not
 * redefine these classes" rule and declares its own .overlay/.btn/etc.
 * with the same name. In CSS, when two rules target the same selector
 * with equal specificity, whichever rule appears LAST in the document
 * wins, property by property — so if our rule were injected first and
 * the model's own (incomplete/conflicting) rule came later, the model's
 * version would silently win on every property it happens to set, while
 * our properties survive on anything the model's rule left out. That
 * mismatch is exactly what produces invisible white-text-on-white
 * overlays in practice (our .overlay sets color:#fff on a dark
 * background; a model's later .overlay redefinition changes only the
 * background to white, and our still-active white text disappears).
 * Placing our CSS last in <head> instead makes our rules win the
 * cascade automatically, with no !important needed.
 *
 * This runs unconditionally — regardless of whether the AI actually
 * used any of these classes/functions — so the final file is guaranteed
 * consistent and still 100% self-contained (no external <link>/<script
 * src>, everything inlined once, at save time).
 */
function injectSharedAssets(string $html, string $css, string $js): string
{
    if ($js !== '') {
        $jsBlock = "<script>\n" . $js . "\n</script>\n";
        if (preg_match('/<head[^>]*>/i', $html)) {
            $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $jsBlock, $html, 1);
        } elseif (preg_match('/<html[^>]*>/i', $html)) {
            $html = preg_replace('/(<html[^>]*>)/i', '$1' . "\n<head>" . $jsBlock . "</head>\n", $html, 1);
        } else {
            $html = $jsBlock . $html;
        }
    }

    if ($css !== '') {
        $cssBlock = "<style>\n" . $css . "\n</style>\n";
        if (preg_match('/<\/head>/i', $html)) {
            $html = preg_replace('/(<\/head>)/i', $cssBlock . '$1', $html, 1);
        } elseif (preg_match('/<head[^>]*>/i', $html)) {
            // <head> opened but never closed (malformed) — best we can do
            $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $cssBlock, $html, 1);
        } else {
            $html = $cssBlock . $html;
        }
    }

    return $html;
}

/**
 * Detects a weak model redeclaring one of our shared CSS classes with
 * its own conflicting rule instead of reusing it. Runs on the RAW model
 * output, before injection, so it only flags rules the model itself
 * wrote (not our own injected copy).
 *
 * @return string[] shared class names the model redeclared
 */
function detectSharedClassCollisions(string $rawModelOutput, string $baseCss): array
{
    $sharedClasses = [];
    if (preg_match_all('/@class\s+(\.[a-zA-Z0-9_-]+)/', $baseCss, $m)) {
        $sharedClasses = $m[1];
    }

    $collisions = [];
    foreach ($sharedClasses as $class) {
        $pattern = '/' . preg_quote($class, '/') . '\s*(,|\{)/';
        if (preg_match($pattern, $rawModelOutput)) {
            $collisions[] = $class;
        }
    }

    return $collisions;
}

// ---------------------------------------------------------------------
// Extracting the HTML document from the raw model response
// ---------------------------------------------------------------------

/**
 * A weak/non-compliant model sometimes ignores the "output ONLY raw HTML,
 * no explanations, no code fences" rule and wraps the document in prose
 * and/or ```html fences. Simply trimming ``` from the very start/end of
 * the string (the old approach) misses this whenever there's ANY text
 * before the opening fence or after the closing one — that leftover
 * prose then becomes literal, visible text on the saved game page
 * (browsers relocate stray text before <html> into the visible body).
 *
 * This instead locates the first <!doctype html> (or <html>) tag and the
 * LAST </html> tag anywhere in the response and keeps only what's
 * between them — robust to prose or fences appearing anywhere around the
 * document, not just at the very edges of the trimmed string.
 *
 * @return array{0:?string, 1:bool} [extracted HTML, or null if no document was found; whether extra text/fences were stripped]
 */
function extractHtmlDocument(string $raw): array
{
    $raw = trim($raw);

    // Fence markers can legitimately appear anywhere (start, or wrapping
    // the whole thing) — strip them first so the doctype/html search
    // below isn't thrown off, and so they never end up inside the saved
    // document.
    $stripped = preg_replace('/```html/i', '', $raw);
    $stripped = preg_replace('/```/', '', $stripped);

    $startPos = null;
    if (preg_match('/<!doctype\s+html/i', $stripped, $m, PREG_OFFSET_CAPTURE)) {
        $startPos = $m[0][1];
    } elseif (preg_match('/<html[\s>]/i', $stripped, $m, PREG_OFFSET_CAPTURE)) {
        $startPos = $m[0][1];
    }

    if ($startPos === null) {
        return [null, false];
    }

    $endPos = null;
    if (preg_match_all('/<\/html\s*>/i', $stripped, $mAll, PREG_OFFSET_CAPTURE)) {
        $last  = end($mAll[0]);
        $endPos = $last[1] + strlen($last[0]);
    }

    $extracted = trim($endPos !== null
        ? substr($stripped, $startPos, $endPos - $startPos)
        : substr($stripped, $startPos));

    $hadExtraText = trim($stripped) !== $extracted;

    return [$extracted, $hadExtraText];
}

// ---------------------------------------------------------------------
// System prompt
// ---------------------------------------------------------------------

function buildSystemPrompt(string $svgManifest, string $cssJsManifest): string
{
    $template = <<<'PROMPT'
You are an expert HTML5 browser game developer specializing in children's educational games. You write clean, bug-free, production-quality code.

TASK
Generate one complete, self-contained, fully playable HTML5 game based on the user's request.

OUTPUT FORMAT — STRICT
- Output ONLY raw HTML, starting with <!DOCTYPE html> and ending with </html>.
- No explanations, no markdown, no code fences, no comments outside the HTML.
- Include a meaningful <title> in Persian.

TECHNICAL STACK
- HTML5 + Tailwind CSS (via CDN) + vanilla JavaScript only.
- Allowed external resources ONLY:
  1. Tailwind CSS CDN: <script src="https://cdn.tailwindcss.com"></script>
  2. Local Iconify: <script src="assets/iconify-icon.min.js"></script>
  3. Local Vazir font (via @font-face)
- Fully playable game with real logic, score (when relevant), and clear win/lose/completion state.
- The game language is Persian (RTL). Always set dir="rtl" and lang="fa" on the <html> tag.

TAILWIND CSS — REQUIRED
1. Always include this exact script in <head> BEFORE any other scripts:
<script src="https://cdn.tailwindcss.com"></script>

2. Use Tailwind utility classes for ALL layout, spacing, colors, typography, and responsiveness.
3. Prefer Tailwind over writing custom CSS. Only write minimal custom CSS when absolutely necessary (animations, complex keyframes).
4. Mobile-first responsive design is mandatory:
   - Base styles for mobile
   - Use sm:, md:, lg: breakpoints for larger screens
   - Game must look excellent on phones (320px+), tablets, and desktops
5. Use flex, grid, gap, padding, rounded-*, shadow-*, and modern Tailwind patterns.

VISUAL DESIGN — MINIMAL & CHIC
- Clean, modern, minimal aesthetic with generous whitespace.
- Soft, friendly color palette suitable for children but elegant (not childish clutter).
- Large touch targets (min 44px).
- Rounded corners (rounded-xl, rounded-2xl).
- Subtle shadows and soft transitions.
- Clear visual hierarchy.
- Avoid visual clutter. Every element must have purpose.
- Center the main game area nicely on larger screens.
- Use consistent spacing scale (p-4, p-6, gap-4, gap-6...).

FONT — REQUIRED
Always include and use the local Vazir font.

Add this inside a <style> tag in the <head>:

@font-face {
  font-family: 'Vazir';
  src: url('assets/fonts/vazir.woff') format('woff');
  font-weight: normal;
  font-style: normal;
  font-display: swap;
}

body {
  font-family: 'Vazir', Tahoma, sans-serif !important;
}

ICON SYSTEM — ICONIFY (COLORED ICONS)
You must use Iconify for every icon.

1. Always include this exact script in the <head>:
<script src="assets/iconify-icon.min.js"></script>

2. Use icons with the web component:
<iconify-icon icon="collection:icon-name" class="text-4xl"></iconify-icon>

3. Strongly prefer colored icon sets:
- twemoji
- emojione
- noto
- fluent-emoji
- openmoji
- fluent-emoji-flat

You are free to use any valid icon from these collections.

SOUND EFFECTS — REQUIRED
Every game must have simple and pleasant sound effects using the Web Audio API (no external audio files).

Required sounds (at minimum):
- Correct / Success sound
- Wrong / Error sound
- Click / Tap sound
- Win / Level complete sound (when relevant)

Rules:
- Use Web Audio API only (AudioContext).
- Keep sounds short and soft (suitable for children).
- Do not use external .mp3 / .wav files or base64 audio.
- Create a small reusable sound helper function.

FORBIDDEN
- Writing any <svg> ... </svg>
- Using <img src="assets/icons/...">
- Using {{SVG:...}} placeholders
- Using monotone icons when colored versions exist
- Using any other CDN or external links (except the required Tailwind CDN)
- Using external audio files
- Broken or incomplete JavaScript
- Syntax errors of any kind

CRITICAL — SELF-CHECK BEFORE OUTPUT (MANDATORY)
You MUST mentally verify the entire code before returning it. Only output the final HTML if ALL of the following are true:

1. JavaScript has zero syntax errors
2. All event listeners are correctly attached and will fire
3. Game logic is complete and works end-to-end (start → play → win/lose)
4. No undefined variables or missing function references
5. Tailwind script is present and all classes are valid
6. Iconify script is present and every icon uses <iconify-icon>
7. Vazir font is loaded and applied
8. html has dir="rtl" and lang="fa"
9. Layout is fully responsive (mobile + desktop)
10. Sound effects exist and are called correctly
11. No <svg> tags exist
12. The game is actually playable and fun

If anything is broken or incomplete, fix it completely before outputting. Never return half-working code.

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
function callAiApi(array $config, string $systemPrompt, string $userMessage, ?string $modelOverride = null): array
{
    $model = $modelOverride ?: ($config['model'] ?? 'gapgpt-qwen-3.6');

    $payload = [
        'model' => $model,
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

function saveGame(string $gamesDir, string $gameId, string $html, string $userPrompt = '', string $modelUsed = ''): void
{
    if ($modelUsed !== '') {
        $safeModel = str_replace(['-->', '<!--'], ['', ''], $modelUsed);
        $html = "<!-- MODEL_USED: " . $safeModel . " -->\n" . $html;
    }

    if ($userPrompt !== '') {
        $safePrompt = str_replace(['-->', '<!--'], ['', ''], $userPrompt);
        $html = "<!-- USER_PROMPT: " . $safePrompt . " -->\n" . $html;
    }

    file_put_contents($gamesDir . '/' . $gameId . '.html', $html);
}
function getGamePrompt(string $gamesDir, string $gameId): ?string
{
    $file = $gamesDir . '/' . $gameId . '.html';
    if (!file_exists($file)) {
        return null;
    }

    $content = file_get_contents($file);

    if (preg_match('/<!--\s*USER_PROMPT:\s*(.+?)\s*-->/s', $content, $m)) {
        return trim($m[1]);
    }

    return null;
}

function getGameModel(string $gamesDir, string $gameId): ?string
{
    $file = $gamesDir . '/' . $gameId . '.html';
    if (!file_exists($file)) {
        return null;
    }

    $content = file_get_contents($file);

    if (preg_match('/<!--\s*MODEL_USED:\s*(.+?)\s*-->/s', $content, $m)) {
        return trim($m[1]);
    }

    return null;
}
function loadGame(string $gamesDir, string $gameId): ?string
{
    $file = $gamesDir . '/' . $gameId . '.html';
    return file_exists($file) ? file_get_contents($file) : null;
}

/**
 * Force every reference to the Vazir font asset onto the single correct
 * RELATIVE path, regardless of what the model wrote (root-absolute
 * '/assets/...', '../assets/...', etc.).
 *
 * Why this matters: the generated game is shown inside an <iframe
 * srcdoc="...">, and a srcdoc document resolves relative URLs against the
 * URL of the PARENT page (index.php), not against the domain root. So a
 * relative path like 'assets/fonts/vazir.woff' works correctly, but a
 * root-absolute path like '/assets/fonts/vazir.woff' only works if this
 * whole app happens to be hosted at the domain root — in any subfolder
 * deployment it 404s and the font silently falls back to Tahoma. A
 * previous version of this function did the opposite (rewrote to an
 * absolute path), which is exactly what caused that failure.
 */
function normalizeFontPath(string $html): string
{
    return preg_replace(
        '/url\(\s*[\'"]?(?:https?:\/\/[^\'")]*)?(?:\.\.\/)*\/?assets\/fonts\/vazir\.woff[\'"]?\s*\)/i',
        "url('assets/fonts/vazir.woff')",
        $html
    );
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