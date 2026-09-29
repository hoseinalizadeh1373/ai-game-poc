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
// Player identity (cookie) + creator name
// ---------------------------------------------------------------------

define('PLAYER_COOKIE_NAME', 'player_id');

/**
 * Creates the directory and drops an .htaccess that denies direct web
 * access (Apache). For nginx, add a `location /data { deny all; }` rule.
 */
function ensureProtectedDir(string $path): void
{
    ensureDir($path);
    $ht = $path . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
        );
    }
}

function isValidPlayerId(string $id): bool
{
    return (bool) preg_match('/^p_[a-f0-9]{16}$/', $id);
}

/**
 * Returns the unique id of the current visitor. The id lives in a cookie;
 * if the cookie is missing (first visit, or the user deleted it) a fresh
 * id is generated — for now a deleted cookie simply means a new player.
 * The cookie is re-sent on every request so its expiry keeps sliding.
 * MUST be called before any output is sent.
 */
function getOrCreatePlayerId(): string
{
    $id = isset($_COOKIE[PLAYER_COOKIE_NAME]) ? (string) $_COOKIE[PLAYER_COOKIE_NAME] : '';

    if (!isValidPlayerId($id)) {
        $id = 'p_' . bin2hex(random_bytes(8));
    }

    setcookie(PLAYER_COOKIE_NAME, $id, [
        'expires'  => time() + 60 * 60 * 24 * 365 * 5,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[PLAYER_COOKIE_NAME] = $id;

    return $id;
}

/**
 * Cleans the creator's username: single line, no tags, max 40 chars.
 * Also safe to embed inside an HTML comment (no "<", ">" at all).
 */
function sanitizeCreatorName(string $name): string
{
    $name = strip_tags($name);
    $name = str_replace(['<', '>'], '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);
    return function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40);
}

// ---------------------------------------------------------------------
// Game results database (data/results/<gameId>.json — one file per game)
// ---------------------------------------------------------------------

function gameResultsFile(string $dataDir, string $gameId): string
{
    return $dataDir . '/results/' . $gameId . '.json';
}

/** Header/meta part of a game's results file. */
function newResultsDoc(string $gamesDir, string $gameId): array
{
    $html = loadGame($gamesDir, $gameId);
    $file = $gamesDir . '/' . $gameId . '.html';

    return [
        'game_id'      => $gameId,
        'title'        => $html !== null ? extractGameTitle($html, $gameId) : $gameId,
        'creator_name' => $html !== null ? extractCreatorFromHtml($html) : null,
        'created_at'   => date('c', file_exists($file) ? (int) filemtime($file) : time()),
        'results'      => [],
    ];
}

/** Creates the (empty) results file right after a game is generated. */
function initGameResultsFile(string $dataDir, string $gamesDir, string $gameId): void
{
    $file = gameResultsFile($dataDir, $gameId);
    ensureDir(dirname($file));
    if (!file_exists($file)) {
        file_put_contents(
            $file,
            json_encode(newResultsDoc($gamesDir, $gameId), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}

/**
 * Validates/clamps the data coming from the browser. Never trust it:
 * anybody can POST to the endpoint.
 */
function sanitizeResultPayload(array $in, string $playerId): array
{
    $num = function ($v, float $min, float $max) {
        if ($v === null || $v === '' || is_bool($v) || !is_numeric($v)) {
            return null;
        }
        $v = max($min, min($max, (float) $v));
        return $v == (int) $v ? (int) $v : round($v, 1);
    };

    $tracksErrors = !empty($in['tracks_errors']);
    $errors       = $tracksErrors ? $num($in['errors'] ?? null, 0, 100000) : null;

    return [
        'played_at'     => date('c'),
        'user_id'       => $playerId,
        'duration_sec'  => $num($in['duration_sec'] ?? null, 0, 86400),
        'tracks_errors' => $tracksErrors,
        'errors'        => $errors,
        'score'         => $num($in['score'] ?? null, -1000000, 1000000),
        'total'         => $num($in['total'] ?? null, 0, 1000000),
        'won'           => (isset($in['won']) && is_bool($in['won'])) ? $in['won'] : null,
        // true = the game never called GameStats.start(); duration is then
        // measured from page load and is less reliable
        'start_missing' => !empty($in['start_missing']),
    ];
}

/** Appends one play result to the game's own JSON file (locked, race-free). */
function appendGameResult(string $dataDir, string $gamesDir, string $gameId, array $record): bool
{
    $file = gameResultsFile($dataDir, $gameId);
    ensureDir(dirname($file));

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $raw = stream_get_contents($fp);
    $doc = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($doc) || !isset($doc['results']) || !is_array($doc['results'])) {
        $doc = newResultsDoc($gamesDir, $gameId);
    }

    $doc['results'][] = $record;

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

/**
 * JavaScript injected into every generated game (before the AI's own
 * code). The AI only CALLS window.GameStats; timing, error counting and
 * sending the result to the parent page all happen here, so a weak model
 * cannot get them wrong.
 */
function buildGameStatsRuntime(): string
{
    return <<<'JS'
(function () {
  var run = null;

  function nowMs() {
    return (window.performance && typeof performance.now === 'function') ? performance.now() : Date.now();
  }
  function num(v) {
    if (v === null || v === undefined || v === '' || typeof v === 'boolean') { return null; }
    v = Number(v);
    return isFinite(v) ? v : null;
  }
  // startMissing: the game never called start() -> measure from page load
  function newRun(tracksErrors, startMissing) {
    return {
      t0: startMissing ? 0 : nowMs(),
      errors: 0,
      tracksErrors: !!tracksErrors,
      startMissing: !!startMissing,
      finished: false,
      summary: null
    };
  }

  window.GameStats = {
    start: function (opts) {
      run = newRun(opts && opts.tracksErrors, false);
    },
    mistake: function () {
      if (!run) { run = newRun(true, true); }
      if (run.finished) { return; }
      run.tracksErrors = true;
      run.errors++;
    },
    finish: function (result) {
      if (!run) { run = newRun(false, true); }
      if (run.finished) { return run.summary; }
      result = result || {};

      var durationSec = Math.round((nowMs() - run.t0) / 100) / 10;
      run.finished = true;
      run.summary = { durationSec: durationSec, errors: run.tracksErrors ? run.errors : null };

      var payload = {
        duration_sec: durationSec,
        errors: run.summary.errors,
        tracks_errors: run.tracksErrors,
        score: num(result.score),
        total: num(result.total),
        won: (result.won === true || result.won === false) ? result.won : null,
        start_missing: run.startMissing
      };
      try {
        window.parent.postMessage({ source: 'game-stats', type: 'finish', payload: payload }, '*');
      } catch (e) {}

      return run.summary;
    }
  };
})();
JS;
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
        $jsBlock = "<script data-shared-assets>\n" . $js . "\n</script>\n";
        if (preg_match('/<head[^>]*>/i', $html)) {
            $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $jsBlock, $html, 1);
        } elseif (preg_match('/<html[^>]*>/i', $html)) {
            $html = preg_replace('/(<html[^>]*>)/i', '$1' . "\n<head>" . $jsBlock . "</head>\n", $html, 1);
        } else {
            $html = $jsBlock . $html;
        }
    }

    if ($css !== '') {
        $cssBlock = "<style data-shared-assets>\n" . $css . "\n</style>\n";
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

START SCREEN & GAME FLOW — REQUIRED
- Every game must open with a start screen that has one clear "شروع بازی" (Start) button. Real play — and the timer — begins only when the player presses it.
- Every game must end with a clear final result screen (win / lose / completed) that offers a "بازی دوباره" (Play again) button. Pressing it returns to real play and counts as a NEW start.

GAME STATISTICS — REQUIRED (GameStats)
The platform ALREADY injects a global object named GameStats into the page. Do NOT define, redefine, copy, wrap, or import it. You only CALL it. It measures the play time and counts the errors automatically and reports them to the platform when the game ends. Its API:

1. GameStats.start({ tracksErrors: true })   // or { tracksErrors: false }
   - Call it at the exact moment the player presses the Start button, and again every time the player presses Play again (a new call resets the timer and the error counter).
   - tracksErrors = true when the game is a quiz, a learning/educational game, or any game in which the player can give a wrong answer or make a wrong move.
   - tracksErrors = false when a mistake is impossible by design (free drawing, pure exploration, ...).

2. GameStats.mistake()
   - Call it once for EVERY wrong answer / wrong move / wrong attempt, including repeated wrong attempts on the same question.
   - Never call it for correct actions. Not needed when tracksErrors is false.

3. var stats = GameStats.finish({ score: 7, total: 10, won: true })
   - Call it exactly ONCE per play, at the moment the game ends (right when the final result screen is shown).
   - score = final score or number of correct answers. total = maximum possible score or total number of questions/items. won = true or false, or null when the game has no win/lose concept. Use null for anything that is not meaningful.
   - It returns { durationSec, errors }. durationSec = play time in seconds. errors = number of mistakes (null when tracksErrors is false).

4. Show these on the final result screen: the play time formatted as minutes:seconds (built from stats.durationSec) and, ONLY when stats.errors is not null, the number of mistakes labelled "تعداد خطا".

Do not measure time or send data yourself: no fetch, no XMLHttpRequest, no localStorage, no cookies, no postMessage. GameStats handles all of it.

FORBIDDEN
- Writing any <svg> ... </svg>
- Using <img src="assets/icons/...">
- Using {{SVG:...}} placeholders
- Using monotone icons when colored versions exist
- Using any other CDN or external links (except the required Tailwind CDN)
- Using external audio files
- Defining, redefining, or replacing GameStats
- Sending any data to a server yourself (fetch, XMLHttpRequest, localStorage, cookies)
- Calling GameStats.finish() more than once per play
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
13. A start screen with a Start button exists, and GameStats.start() is called when it (and Play again) is pressed
14. GameStats.mistake() is called for every wrong action (when tracksErrors is true)
15. GameStats.finish() is called exactly once when the game ends, and its time (and errors, if not null) are shown on the final screen
16. GameStats is never defined or redefined in the code

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

function saveGame(string $gamesDir, string $gameId, string $html, string $userPrompt = '', string $modelUsed = '', string $creator = ''): void
{
    if ($modelUsed !== '') {
        $safeModel = str_replace(['-->', '<!--'], ['', ''], $modelUsed);
        $html = "<!-- MODEL_USED: " . $safeModel . " -->\n" . $html;
    }

    if ($userPrompt !== '') {
        $safePrompt = str_replace(['-->', '<!--'], ['', ''], $userPrompt);
        $html = "<!-- USER_PROMPT: " . $safePrompt . " -->\n" . $html;
    }

    $creator = sanitizeCreatorName($creator);
    if ($creator !== '') {
        $html = "<!-- CREATOR: " . $creator . " -->\n" . $html;
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
/** The CREATOR comment is always the very first thing in the file. */
function extractCreatorFromHtml(string $html): ?string
{
    if (preg_match('/<!--\s*CREATOR:\s*(.+?)\s*-->/s', substr($html, 0, 512), $m)) {
        return trim($m[1]);
    }
    return null;
}

function getGameCreator(string $gamesDir, string $gameId): ?string
{
    $file = $gamesDir . '/' . $gameId . '.html';
    if (!file_exists($file)) {
        return null;
    }
    return extractCreatorFromHtml(file_get_contents($file));
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
            'creator'    => extractCreatorFromHtml($html),
            'created_at' => filemtime($file),
        ];
    }
    usort($games, function ($a, $b) {
        return $b['created_at'] <=> $a['created_at'];
    });
    return $games;
}

// ---------------------------------------------------------------------
// Generation lock (prevent concurrent AI requests)
// ---------------------------------------------------------------------
//
// Only ONE generation is allowed at a time.
// Method: atomic file creation with mode 'x' (fails if file already exists).
// This works reliably on Windows, Linux, shared hosting, and PHP built-in server.
//
// - Lock file does not exist          → create it and proceed
// - Lock file exists and is fresh     → reject with "server is busy"
// - Lock file exists but is stale     → remove it and take over
//
// Always call releaseGenerationLock() when finished (use try/finally).

define('GENERATION_LOCK_MAX_AGE', 300); // 5 minutes safety for crashed processes

/**
 * Tries to acquire the generation lock.
 *
 * @return array{ok:bool, error:?string}
 */
function acquireGenerationLock(string $lockFile): array
{
    $dir = dirname($lockFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // If a lock already exists, decide whether it is still valid
    if (file_exists($lockFile)) {
        $age = time() - (int) @filemtime($lockFile);

        if ($age <= GENERATION_LOCK_MAX_AGE) {
            // Fresh lock → another generation is running
            return [
                'ok'    => false,
                'error' => 'الان سرور مشغول ساخت بازی دیگری است. لطفاً چند دقیقه دیگر دوباره تلاش کنید.',
            ];
        }

        // Stale lock (process probably crashed) → remove it
        @unlink($lockFile);
    }

    // Atomic create: mode 'x' fails if the file already exists
    // This is the critical race-free step
    $fp = @fopen($lockFile, 'x');
    if ($fp === false) {
        // Someone else just created the lock between our check and now
        return [
            'ok'    => false,
            'error' => 'الان سرور مشغول ساخت بازی دیگری است. لطفاً چند دقیقه دیگر دوباره تلاش کنید.',
        ];
    }

    // Write timestamp and close (we no longer need the handle)
    fwrite($fp, (string) time());
    fclose($fp);

    return [
        'ok'    => true,
        'error' => null,
    ];
}

/**
 * Releases the generation lock.
 * Always call this when the generation finishes (success or error).
 */
function releaseGenerationLock(string $lockFile): void
{
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
}

// ---------------------------------------------------------------------
// Game refinement ("Edit / fix bugs" feature)
//
// Everything below is UI-agnostic: it only knows about game ids, files
// and the AI API. The HTTP endpoint (refine.php) and the front-end
// widget (assets/game-refine.js) are thin layers on top of refineGame().
// ---------------------------------------------------------------------

/**
 * Models the app is allowed to call: [api model id => human label].
 * Used by the refine endpoint. (index.php still keeps its own copy for
 * the "create" form — it can be switched to this function later.)
 */
function allowedModels(): array
{
    return [
        'deepseek-v4.1-flash' => 'DeepSeek v4.1 Flash',
        'claude-fable-5'      => 'Claude Fable 5',
        'claude-sonnet-5'     => 'Claude Sonnet 5',
        'gpt-6-sol'           => 'GPT-6 Sol',
    ];
}

/**
 * Removes the "<!-- USER_PROMPT: ... -->" / "<!-- MODEL_USED: ... -->"
 * header comments written by saveGame(): they are our own metadata and
 * would only confuse the reviewing model.
 */
function stripGameMetaComments(string $html): string
{
    return preg_replace('/<!--\s*(?:CREATOR|USER_PROMPT|MODEL_USED):.*?-->\s*/s', '', $html);
}

/**
 * Removes the shared JS/CSS blocks that injectSharedAssets() put into a
 * saved game, so the AI only sees (and rewrites) the game's own code.
 * They are re-injected after the AI answers.
 *
 * New games carry a data-shared-assets marker; games saved before the
 * marker existed are matched by their exact content instead.
 */
function stripSharedAssets(string $html, string $css, string $js): string
{
    // 1) blocks carrying the marker (games saved after the marker was added)
    $html = preg_replace(
        '/\s*<(script|style)\b[^>]*\bdata-shared-assets\b[^>]*>.*?<\/\1>\s*/is',
        "\n",
        $html
    );

    // 2) exact-content match (older games)
    if ($js !== '') {
        $html = str_replace("\n<script>\n" . $js . "\n</script>\n", "\n", $html);
    }
    if ($css !== '') {
        $html = str_replace("<style>\n" . $css . "\n</style>\n", '', $html);
    }

    // 3) signature fallback: base.js/base.css were edited after the game
    //    was created, so the exact text no longer matches
    $html = preg_replace(
        '/<script\b[^>]*>(?:(?!<\/script>).)*?window\.GameStats\s*=\s*\{.*?<\/script>\s*/is',
        '',
        $html,
        1
    );
    $html = preg_replace(
        '/<style\b[^>]*>\s*\/\*\s*Shared base styles auto-injected.*?<\/style>\s*/is',
        '',
        $html,
        1
    );

    return $html;
}

/**
 * The JS that create.php injects into every game: the GameStats runtime
 * first, then the shared helpers from base.js.
 */
function buildSharedJs(string $baseJs): string
{
    return buildGameStatsRuntime() . "\n" . $baseJs;
}

/**
 * Detects the classic lazy-model failure: answering with a placeholder
 * such as "// ... rest of the code unchanged" instead of the full file.
 * Saving such an answer would destroy the game.
 */
function looksAbbreviated(string $html): bool
{
    return (bool) preg_match(
        '~(?:<!--|//|/\*)[^\n]{0,20}\b(?:rest of (?:the )?(?:code|game|script|file|html|css)|remains? (?:the same|unchanged)|unchanged|same as (?:before|above|original)|previous code)\b~i',
        $html
    );
}

function buildRefineSystemPrompt(string $cssJsManifest): string
{
    $template = <<<'PROMPT'
You are a senior QA engineer and expert HTML5 game developer specializing in children's educational games (Persian, RTL). You are given an EXISTING game — one self-contained HTML document that was generated automatically by a weaker AI model and may contain hidden bugs — together with the original request it was built for.

YOUR JOB
Audit the game thoroughly and return a fully repaired and improved version of the SAME game. Keep its concept, theme, topic, target age and everything that already works. Fix what is broken; improve what is weak.

HOW TO AUDIT
Do not skim. Mentally EXECUTE the game from page load to the final win/lose/completion screen, then restart it and play it a second time. Read every line of JavaScript, every data array, every piece of markup and every CSS rule. Review ALL of the dimensions below.

1. FUNCTIONALITY & JAVASCRIPT
- Syntax errors, unbalanced braces/quotes/backticks, broken template strings, stray text outside tags.
- Undefined variables/functions, typos in ids/classes, getElementById/querySelector calls that return null, wrong scope.
- Script running before the DOM exists (missing DOMContentLoaded, or a script placed before the elements it queries).
- Event listeners that are never attached, attached twice, lost after an innerHTML re-render, or inline handlers that reference missing functions.
- Broken game flow: start -> play -> next question/level -> win/lose -> restart. Restart must fully reset ALL state (score, index, timers, lock flags, DOM).
- Double-click/double-tap bugs, answers accepted after a round is locked, timers/intervals never cleared, off-by-one errors, endless loops, unreachable end screen.
- Score / progress / lives counters that do not update or update wrongly.

2. GAME DATA & CONTENT CORRECTNESS
- Verify EVERY question/item/answer in the data one by one: is the marked correct answer really correct? Look for wrong facts, wrong spellings, wrong counts, wrong icon for the word.
- Exactly one correct option per question; no two options that are both right; no duplicate options; distractors plausible but clearly wrong.
- Content must match the original request (topic, age group, difficulty, number of items).
- CORRECT-ANSWER POSITION: the correct option must NOT sit in the same slot every time (for example always the first of four options). Randomize the option order at render time and identify the correct option by its value/id — never by its original index. Also make sure the data itself does not systematically list the correct answer first.
- Persian text quality: correct spelling, natural phrasing, consistent digits, proper half-space (ZWNJ).
- Every <iconify-icon> name must be a real icon of the given set and must semantically match what it represents.

3. LAYOUT & VISUAL DESIGN
- Correct RTL behaviour (dir="rtl"): alignment, element order, progress bars, arrows/directional icons, mixed LTR/RTL text.
- Responsive from 320px phones to desktop: no horizontal scroll, no overflow or cut-off content, no overlapping elements, sensible grid and text sizes.
- Touch targets of at least 44px, good contrast, readable font sizes, clearly visible pressed / selected / correct / wrong states.
- Overlays and modals: never invisible text (e.g. white on white), never blocking the game after being dismissed.
- A consistent, minimal and chic look (spacing scale, rounded corners, soft shadows, cohesive palette). Nothing decorative without a purpose.
- Resolve conflicts between Tailwind classes and custom CSS.

4. STRUCTURE & CODE QUALITY
- Clear separation: data (constants at the top) / state / rendering / event handling / helpers.
- Remove dead code, duplicate functions and unused variables; use meaningful names; add short comments where helpful.
- Keep ALL content in one easy-to-extend data structure.
- Be robust: guard against null/empty data, wrap Audio code in try/catch, produce no console errors.

5. UX & FEEDBACK
- Clear instructions, immediate feedback for correct and wrong answers, an encouraging (never harsh) tone for children.
- Sound effects work through the Web Audio API and are started only after a user gesture.
- A clear end screen with the result and a working "play again" button.

6. GAME STATISTICS (GameStats)
- The platform injects a global GameStats object; it is NOT part of the code you see and must NEVER be defined, copied, wrapped or replaced by you.
- Verify: GameStats.start({tracksErrors}) is called when the player presses Start AND every time they press Play again; GameStats.mistake() is called for every wrong answer/move (when tracksErrors is true) and never for correct ones; GameStats.finish({score,total,won}) is called exactly ONCE per play, when the final result screen appears; the final screen shows the time (m:ss) and, only when errors is not null, the mistake count.
- The game must open with a start screen with a Start button and end with a result screen with a Play again button. Add or repair them if missing.

7. RULES COMPLIANCE
- The game must still satisfy every rule in the ORIGINAL GENERATION RULES at the end of this message (stack, allowed external resources, font, RTL, icons, sounds, forbidden items).

HOW TO FIX
- Fix every problem you find — do not just report it. If a part is fundamentally broken, rewrite that part properly.
- Preserve everything that already works: same concept, same visual identity, same content unless it is incorrect. Never shrink the game, remove features, or replace content with placeholders.
- If a human reviewer left notes, treat them as top-priority bugs: reproduce each one mentally and fix it, in addition to your own audit.
- The helpers and CSS classes listed below are injected automatically into the final file after your answer, so they exist at runtime and you may use them without defining them. If the game already has its own equivalent function, keep the game's own version.

AUTO-INJECTED SHARED HELPERS
[[HELPERS]]

FINAL SELF-CHECK (MANDATORY)
Before answering, re-read your corrected code once more as a compiler and as a player. Only output it when: there are zero syntax errors, every listener fires, the whole flow works twice in a row, all data is verified, and the layout holds at 320px and at 1280px wide.

OUTPUT FORMAT — STRICT
- Output ONLY the complete corrected HTML document, from <!DOCTYPE html> to </html>.
- Never abbreviate: no "rest of the code unchanged", no "...", no placeholders — the FULL file, every time.
- No explanations, no markdown, no code fences, no text outside the HTML.
- If you find the game already flawless, still return the complete document.

=====================================================================
ORIGINAL GENERATION RULES (the rules the game was built under — they still apply)
=====================================================================
PROMPT;

    $helpers = $cssJsManifest !== '' ? $cssJsManifest : '(none)';

    return str_replace('[[HELPERS]]', $helpers, $template)
        . "\n\n" . buildSystemPrompt('', '');
}

function buildRefineUserMessage(string $gameHtml, string $originalPrompt, string $note): string
{
    $msg = '';

    if ($originalPrompt !== '') {
        $msg .= "ORIGINAL USER REQUEST (what this game was supposed to be):\n\"" . $originalPrompt . "\"\n\n";
    }

    $note = trim($note);
    if ($note !== '') {
        $msg .= "REVIEWER NOTES (known problems reported by a human — fix these first):\n" . $note . "\n\n";
    }

    $msg .= "Audit and fix the following game. Return the complete corrected HTML document only.\n\n"
        . "<game_html>\n" . $gameHtml . "\n</game_html>";

    return $msg;
}

/**
 * Sends a saved game to the AI for a full review + bug-fix pass and
 * saves the result — either as a NEW game (default, original untouched)
 * or over the same id ($replace = true; the previous version is copied
 * to games/.history/ first).
 *
 * The original game is never modified unless the AI returned a complete,
 * plausible document.
 *
 * @return array{ok:bool, error:?string, gameId:?string, replaced:bool, hadExtraText:bool}
 */
function refineGame(
    array $config,
    string $assetsDir,
    string $gamesDir,
    string $gameId,
    ?string $model = null,
    string $note = '',
    bool $replace = false
): array {
    $dataDir = dirname($gamesDir) . '/data';

    $fail = function (string $msg): array {
        return ['ok' => false, 'error' => $msg, 'gameId' => null, 'replaced' => false, 'hadExtraText' => false];
    };

    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $gameId)) {
        return $fail('شناسه بازی نامعتبر است.');
    }

    $original = loadGame($gamesDir, $gameId);
    if ($original === null) {
        return $fail('بازی مورد نظر پیدا نشد.');
    }

    if ($model === null || !isset(allowedModels()[$model])) {
        $model = $config['model'] ?? 'deepseek-v4.1-flash';
    }

    $userPrompt = getGamePrompt($gamesDir, $gameId) ?? '';
    $prevModel  = getGameModel($gamesDir, $gameId) ?? '';
    $creator    = getGameCreator($gamesDir, $gameId) ?? '';

    $baseCss = loadBaseCss($assetsDir);
    $baseJs  = loadBaseJs($assetsDir);
    $sharedJs = buildSharedJs($baseJs);

    // what the AI sees: only the game's own code
    $clean = stripSharedAssets(stripGameMetaComments($original), $baseCss, $sharedJs);

    $apiResult = callAiApi(
        $config,
        buildRefineSystemPrompt(buildCssJsManifest($baseCss, $baseJs)),
        buildRefineUserMessage($clean, $userPrompt, $note),
        $model
    );
    if (!$apiResult['ok']) {
        return $fail($apiResult['error']);
    }

    [$text, $hadExtraText] = extractHtmlDocument(trim($apiResult['text']));

    if ($text === null) {
        return $fail('AI بازی ویرایش‌شده را به صورت HTML معتبر برنگرداند. بازی اصلی دست‌نخورده ماند.');
    }
    if (!preg_match('/<\/html\s*>/i', $text)) {
        return $fail('پاسخ AI ناقص بود (احتمالاً وسط کار قطع شده). بازی اصلی دست‌نخورده ماند.');
    }
    if (strlen($text) < strlen($clean) * 0.4 || looksAbbreviated($text)) {
        return $fail('AI به‌جای بازی کامل، نسخهٔ خلاصه‌شده برگرداند. بازی اصلی دست‌نخورده ماند؛ دوباره تلاش کنید یا مدل قوی‌تری انتخاب کنید.');
    }

    // the platform's GameStats must stay the single source of truth
    if (preg_match('/window\.GameStats\s*=|(?:var|let|const)\s+GameStats\b/', $text)) {
        return $fail('AI تعریف GameStats را دستکاری کرد و نتیجه پذیرفته نشد. بازی اصلی دست‌نخورده ماند؛ دوباره تلاش کنید.');
    }

    // same post-processing pipeline as a freshly created game
    [$text] = applySvgPlaceholders($text, scanIconAssets($assetsDir . '/icons'));
    $text   = normalizeFontPath($text);
    $text   = injectSharedAssets($text, $baseCss, $sharedJs);

    if ($replace) {
        $historyDir = $gamesDir . '/.history';
        ensureDir($historyDir);
        $backup = $historyDir . '/' . $gameId . '_' . date('Ymd_His') . '.html';
        if (!@copy($gamesDir . '/' . $gameId . '.html', $backup)) {
            return $fail('پشتیبان‌گیری از نسخهٔ قبلی ناموفق بود؛ بازی جایگزین نشد.');
        }
        $newId = $gameId;
    } else {
        $newId = newGameId();
    }

    $modelUsed = $prevModel !== '' ? $prevModel . ' → ' . $model : $model;
    saveGame($gamesDir, $newId, $text, $userPrompt, $modelUsed, $creator);
    if (!$replace) {
        initGameResultsFile($dataDir, $gamesDir, $newId);
    }

    return [
        'ok'           => true,
        'error'        => null,
        'gameId'       => $newId,
        'replaced'     => $replace,
        'hadExtraText' => $hadExtraText,
    ];
}
