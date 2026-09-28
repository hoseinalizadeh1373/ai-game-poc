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
    return buildSystemPromptSimple($svgManifest, $cssJsManifest);
}

/**
 * "ساده" level — unchanged from the original single-file HTML/CSS/JS
 * pipeline. Kept as its own named function (buildSystemPrompt() above
 * just forwards to it) so index.php can pick between this and the new
 * Phaser prompt without touching the generation pipeline itself.
 */
function buildSystemPromptSimple(string $svgManifest, string $cssJsManifest): string
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
- Only allowed external resources: the local Iconify script and the local Vazir font.
- Fully playable game with real logic, score (when relevant), and clear win/lose/completion state.
- Must be responsive (works well on mobile and desktop).
- Prefer relative units (em, rem, %) so icons and layout scale nicely with text.
- Visual style: simple, colorful, friendly for children.
- The game language is Persian (RTL). Always set dir="rtl" on the <html> tag.

FONT — REQUIRED
Always include and use the local Vazir font.

Add this inside a <style> tag in the <head>:

@font-face {
  font-family: 'Vazir';
  src: url('/assets/fonts/vazir.woff') format('woff');
  font-weight: normal;
  font-style: normal;
  font-display: swap;
}

Then apply it to the whole page:

body {
  font-family:'Vazir', Tahoma, sans-serif !important;
}

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

You are free to use any valid icon from these collections. There is no limited list.

4. Size recommendation:
Use font-size with em units (example: style="font-size: 2.2em; vertical-align: -0.15em;")

SOUND EFFECTS — REQUIRED

Every game must have simple and pleasant sound effects using the Web Audio API (no external audio files).

Required sounds (at minimum):
- Correct / Success sound
- Wrong / Error sound
- Click / Tap sound
- Win / Level complete sound (when relevant)

Rules for sound:
- Use Web Audio API only (AudioContext).
- Keep sounds short and soft (suitable for children).
- Do not use external .mp3 / .wav files.
- Do not use base64 audio.
- Create a small reusable sound helper function.

Example structure (you can improve it):

const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

function playTone(freq, duration, type = 'sine', volume = 0.1) {
  const oscillator = audioCtx.createOscillator();
  const gainNode = audioCtx.createGain();
  oscillator.connect(gainNode);
  gainNode.connect(audioCtx.destination);
  oscillator.frequency.value = freq;
  oscillator.type = type;
  gainNode.gain.setValueAtTime(volume, audioCtx.currentTime);
  gainNode.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
  oscillator.start();
  oscillator.stop(audioCtx.currentTime + duration);
}

FORBIDDEN
- Writing any <svg> ... </svg>
- Using <img src="assets/icons/...">
- Using {{SVG:...}} placeholders
- Using monotone icons when colored versions exist
- Using any CDN or external links
- Using external audio files

BEFORE ANSWERING — SELF CHECK
- Vazir font is loaded and applied
- Iconify local script is present
- Every icon uses <iconify-icon>
- Colored icon sets are preferred
- Sound effects with Web Audio API exist
- No <svg> tags
- html has dir="rtl"
- Game is responsive

Return only the final HTML document.
PROMPT;

    return $template;
}

/**
 * "پیشرفته / دو‌بعدی" level — same overall contract (one self-contained
 * HTML document, output-format rules, RTL/Vazir/sound requirements) as
 * buildSystemPromptSimple(), but the game itself is rendered as a real
 * 2D canvas game with Phaser 3 instead of plain DOM/CSS elements. The
 * local assets/phaser.min.js build is injected into every generated
 * document the same way base.css/base.js already are (see
 * injectPhaserAsset() below) — the model only has to assume
 * `window.Phaser` already exists.
 */
function buildSystemPromptPhaser(
    string $svgManifest,
    string $cssJsManifest
): string {
    return <<<'PROMPT'
You are an expert educational browser game designer.

You are generating a game for the Vizura Game Engine.

IMPORTANT ARCHITECTURE RULE:

You DO NOT write raw Phaser application code.

You DO NOT create Phaser.Game.

You DO NOT create Phaser.Scene.

You DO NOT extend Phaser.Scene.

You DO NOT use preload().

You DO NOT use update() directly as a Phaser Scene method.

You DO NOT use Phaser physics.

You DO NOT load images.

You DO NOT load sprites.

You DO NOT load external assets.

The Vizura Runtime already owns Phaser.

Your job is ONLY to describe the game using the VizuraGame API.

==================================================
OUTPUT FORMAT
==================================================

Return ONLY a complete HTML document.

Start with:

<!DOCTYPE html>

End with:

</html>

No markdown.

No code fences.

No explanation.

==================================================
HTML STRUCTURE
==================================================

Your HTML MUST contain:

<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>...</title>
</head>

<body>

<div id="game-container"></div>

<script>
    // VizuraGame.define(...)
</script>

</body>
</html>

Do NOT include Phaser.
Do NOT include vizura-runtime.js.

They are injected automatically by the server.

==================================================
VIZURA GAME DEFINITION
==================================================

Your game MUST use this structure:

<script>

VizuraGame.define({

    width: 800,
    height: 600,

    start() {

        // game initialization

    },

    update(time, delta) {

        // optional animation/update logic

    },

    onCorrect(score) {

        // optional

    },

    onWrong(score) {

        // optional

    },

    onFinish(result) {

        // optional

    }

});

VizuraRuntimeStart();

</script>

The game MUST call:

VizuraRuntimeStart();

after VizuraGame.define(...).

==================================================
AVAILABLE API
==================================================

Inside start(), update(time, delta), and every callback, `this` IS the Vizura API.
start() may also be written as start(api). `api` and `this` are the same object.

RULES FOR `this`:
- Call this.text(), this.button(), this.correct() directly inside start().
- Put EVERY helper function INSIDE start(), and close over `const api = this`.
- Do NOT add startLevel/checkAnswer/render as siblings of start(). The runtime never calls those, and `this` inside them is not the API.
- Store changing data on this.state (a stable object): this.state.level, this.state.index, this.state.items.
- Store points with this.score. The runtime updates this.score when you call this.correct().
- this.clear() removes objects you created. It does NOT remove the gradient background.
- After this.clear(), draw the whole scene again from inside start().

WRONG:
VizuraGame.define({
    start() { this.render(); },
    render() { this.text({ text: 'سلام' }); }
});

RIGHT:
VizuraGame.define({
    start() {
        const api = this;
        function render() {
            api.text({ text: 'سلام' });
        }
        render();
    }
});

--------------------------------------------------
TEXT
--------------------------------------------------

this.text({
    x: 400,
    y: 80,
    text: 'سلام',
    size: 32,
    color: '#ffffff',
    bold: true
});

--------------------------------------------------
BUTTON
--------------------------------------------------

this.button({
    x: 400,
    y: 300,
    width: 220,
    height: 70,
    text: 'پاسخ درست',
    fontSize: 26,
    color: 0x4f46e5,

    onClick: () => {
        this.correct();
    }
});

--------------------------------------------------
RECTANGLE
--------------------------------------------------

this.rect({
    x: 400,
    y: 300,
    width: 200,
    height: 100,
    color: 0x4f46e5,
    radius: 20
});

--------------------------------------------------
CIRCLE
--------------------------------------------------

this.circle({
    x: 400,
    y: 300,
    radius: 50,
    color: 0x22c55e
});

--------------------------------------------------
EMOJI
--------------------------------------------------

this.emoji({
    x: 400,
    y: 250,
    text: '🍎',
    size: 80
});

--------------------------------------------------
LINE
--------------------------------------------------

this.line({
    x1: 100,
    y1: 100,
    x2: 700,
    y2: 100,
    width: 4,
    color: 0xffffff
});

--------------------------------------------------
ANIMATION
--------------------------------------------------

const item = this.emoji({
    x: 400,
    y: 300,
    text: '⭐',
    size: 70
});

this.animate(item, {
    y: 250,
    duration: 700,
    yoyo: true,
    repeat: -1
});

--------------------------------------------------
DELAY
--------------------------------------------------

this.delay(1000, () => {
    // do something
});

--------------------------------------------------
CORRECT ANSWER
--------------------------------------------------

this.correct();

Optional:

this.correct({
    points: 2
});

--------------------------------------------------
WRONG ANSWER
--------------------------------------------------

this.wrong();

--------------------------------------------------
FINISH
--------------------------------------------------

this.finish({
    success: true,
    message: 'آفرین! 🎉'
});

--------------------------------------------------
SOUND
--------------------------------------------------

this.playSound('click');

this.playSound('correct');

this.playSound('wrong');

this.playSound('win');

--------------------------------------------------
RANDOM
--------------------------------------------------

this.random(1, 10);

this.randomItem(['🍎', '🍌', '🍊']);

--------------------------------------------------
STATE
--------------------------------------------------

this.state.level = 1;
this.state.index = 0;
this.score = 0;

--------------------------------------------------
PANEL
--------------------------------------------------

A raised card. Use it behind questions and instructions.

this.panel({
    x: 480,
    y: 160,
    width: 680,
    height: 130,
    color: 0xffffff,
    radius: 28
});

--------------------------------------------------
STAR
--------------------------------------------------

this.star({
    x: 80,
    y: 70,
    radius: 22,
    color: 0xfbbf24
});

--------------------------------------------------
BADGE
--------------------------------------------------

this.badge({
    x: 140,
    y: 48,
    text: 'امتیاز ' + this.score,
    color: 0xfbbf24
});

--------------------------------------------------
BACKGROUND
--------------------------------------------------

The runtime already paints a gradient and a few decorations.
Set colors on the definition, or call this.background().

background: { top: '#312e81', bottom: '#f472b6' }

this.background({
    top: '#1d4ed8',
    bottom: '#f59e0b'
});

--------------------------------------------------
RICHER SHAPES
--------------------------------------------------

Text color may be '#312e81' or 0x312e81.
Shape color may be 0x4f46e5 or '#4f46e5'.

this.circle({
    x: 480,
    y: 300,
    radius: 64,
    color: 0xffffff,
    gloss: true,
    shadow: true,
    stroke: 0x7c3aed,
    strokeWidth: 6
});

this.emoji({
    x: 480,
    y: 280,
    text: '🍎',
    size: 78,
    bubble: true
});

this.rect({
    x: 480,
    y: 540,
    width: 360,
    height: 22,
    radius: 11,
    color: 0x22c55e,
    gradient: true
});

this.text({
    x: 480,
    y: 160,
    text: 'عدد بزرگ‌تر کدام است؟',
    size: 40,
    color: '#312e81',
    bold: true,
    shadow: true,
    wordWrapWidth: 620
});

Buttons are already gradient, shadowed, and rounded.
Do not draw a second rectangle behind a button.

==================================================
VISUAL QUALITY
==================================================

This is the advanced 2D level. It must look richer than a flat HTML page.

The scene must include ALL of these:

1. A gradient background via `background: { top, bottom }`. Pick two different, saturated colors. Do not leave the default unchanged unless it fits the theme.
2. At least one white or colored panel behind the question.
3. A score badge.
4. Large gradient buttons (width >= 180, height >= 72, fontSize >= 26).
5. At least one emoji, preferably with bubble: true.
6. At least two decorative stars or glossy circles.
7. At least one looping tween (yoyo: true, repeat: -1) on an emoji or star.
8. Clear empty space. Do not cover the canvas with one flat rectangle.

Suggested size: width 960, height 600.

Color ideas:
- sky: top '#38bdf8', bottom '#fde68a'
- candy: top '#7c3aed', bottom '#fb7185'
- garden: top '#166534', bottom '#86efac'
- night: top '#1e1b4b', bottom '#7c3aed'

Text on a light panel must be dark ('#1e1b4b').
Text on a dark panel must be light ('#ffffff').

==================================================
COMPLETE PATTERN
==================================================

Follow this structure. Replace the subject, but keep the nesting,
the background, the panel, the badge, and the buttons.

VizuraGame.define({
    width: 960,
    height: 600,
    background: { top: '#312e81', bottom: '#f472b6' },

    start() {
        const api = this;
        api.state.questions = [
            { prompt: '۲ + ۳', answer: '۵', options: ['۴', '۵', '۶'] },
            { prompt: '۷ - ۲', answer: '۵', options: ['۳', '۵', '۹'] }
        ];
        api.state.index = 0;

        function showQuestion() {
            const q = api.state.questions[api.state.index];

            const hero = api.emoji({
                x: 480,
                y: 300,
                text: '🌟',
                size: 70,
                bubble: true
            });

            api.animate(hero, {
                y: 270,
                duration: 900,
                yoyo: true,
                repeat: -1
            });

            api.panel({
                x: 480,
                y: 150,
                width: 700,
                height: 120,
                color: 0xffffff
            });

            api.text({
                x: 480,
                y: 150,
                text: q.prompt,
                size: 42,
                color: '#312e81',
                bold: true
            });

            api.badge({
                x: 150,
                y: 46,
                text: 'امتیاز ' + api.score,
                color: 0xfbbf24
            });

            q.options.forEach(function (option, i) {
                api.button({
                    x: 250 + i * 230,
                    y: 430,
                    width: 190,
                    height: 82,
                    text: option,
                    fontSize: 32,
                    color: 0x4f46e5,
                    onClick: function () {
                        if (option === q.answer) {
                            api.correct();
                            api.state.index += 1;
                            if (api.state.index >= api.state.questions.length) {
                                api.delay(700, function () {
                                    api.finish({ message: 'آفرین! همه را درست گفتی' });
                                });
                            } else {
                                api.delay(700, function () {
                                    api.clear();
                                    showQuestion();
                                });
                            }
                        } else {
                            api.wrong();
                        }
                    }
                });
            });
        }

        showQuestion();
    }
});

VizuraRuntimeStart();

If you call api.clear() before the next question, draw the panel,
badge, and buttons again. Draw the floating hero AFTER clear, or
it will be removed too. The gradient background stays.

==================================================
GAME DESIGN RULES
==================================================

The game must be a REAL educational game.

Do NOT create a fake demo.

Do NOT create a static screen.

The player must have something meaningful to do.

Examples:

- choose the correct answer
- sort objects
- count objects
- match items
- identify colors
- identify shapes
- solve simple mathematics
- recognize letters
- recognize numbers
- memory game
- classification
- sequencing

The educational objective must be clear.

==================================================
CHILDREN
==================================================

Games are designed for children.

Use:

- large buttons
- large text
- colorful shapes
- emoji when useful
- simple instructions
- immediate feedback
- positive feedback
- simple animations

Avoid:

- tiny text
- complicated menus
- long instructions
- excessive visual complexity

==================================================
PERSIAN
==================================================

All visible text must be Persian unless the user specifically requests another language.

Use:

fontFamily:
'Vazir, Tahoma, Arial, sans-serif'

for Phaser text.

==================================================
NO EXTERNAL ASSETS
==================================================

Do NOT use:

this.load.image(...)
this.load.spritesheet(...)
this.load.atlas(...)
<img>
SVG
external images
external audio
CDN
fetch()
XMLHttpRequest()
external URLs

Everything must be generated with:

- text
- rectangles
- circles
- lines
- emoji
- tweens

==================================================
NO RAW PHASER
==================================================

NEVER write:

new Phaser.Game(...)

class MyScene extends Phaser.Scene

class GameScene extends Phaser.Scene

extends Phaser.Scene

new Phaser.Scene(...)

preload()

this.physics

this.load

this.add.particles

Phaser.Game

The Vizura Runtime handles Phaser.

==================================================
GAME FLOW
==================================================

Every game should have:

1. Clear title/instruction
2. Main gameplay
3. Correct/wrong feedback
4. Score when appropriate
5. Completion condition
6. Finish screen

For quiz games:

- show one question
- show several answers
- exactly one correct answer
- wrong answers should not immediately finish unless appropriate
- after correct answer move to the next question
- finish after all questions

==================================================
IMPORTANT
==================================================

Keep the logic simple and the picture rich.

Do not over-engineer the rules.

Do not use physics unless the user explicitly asks for physics-like gameplay.

Do not create unnecessary systems.

Do not create hundreds of objects.

Do not create class hierarchies.

Do not invent API methods. Only call methods documented above.

The generated game must prioritize:

A FINISHED, VISIBLE, PLAYABLE SCENE > COMPLEXITY

==================================================
SELF CHECK
==================================================

Before returning the HTML verify:

- VizuraGame.define exists
- VizuraRuntimeStart() exists
- #game-container exists
- start() uses this.text / this.button directly
- helper functions are nested inside start()
- no sibling methods that expect this to be the API
- background top and bottom are set
- a panel, a badge, large buttons, and an emoji exist
- at least one yoyo tween exists
- no new Phaser.Game
- no Phaser.Scene subclass
- no preload
- no physics
- no this.load
- no external images
- no SVG
- no external URL
- Persian RTL HTML
- Vazir font
- actual playable logic
- correct/wrong feedback
- completion state

Return ONLY the HTML document.
PROMPT;
}

// ---------------------------------------------------------------------
// Shared Phaser build (assets/phaser.min.js) — "پیشرفته" level only
// ---------------------------------------------------------------------
// ---------------------------------------------------------------------
// Vizura Advanced 2D Runtime
// ---------------------------------------------------------------------

/**
 * Loads the local Phaser build.
 */
function loadPhaserJs(string $assetsDir): string
{
    $file = $assetsDir . '/phaser.min.js';

    if (!file_exists($file)) {
        return '';
    }

    return trim((string) file_get_contents($file));
}

/**
 * Loads the fixed Vizura runtime.
 *
 * The runtime is NOT generated by AI.
 * It owns the Phaser lifecycle and exposes a very small API
 * to the generated game.
 */
function loadVizuraRuntime(string $assetsDir): string
{
    $file = $assetsDir . '/vizura-runtime.js';

    if (!file_exists($file)) {
        return '';
    }

    return trim((string) file_get_contents($file));
}

/**
 * Inject Phaser + Vizura Runtime together.
 *
 * IMPORTANT:
 * Phaser must execute first.
 * Vizura Runtime must execute second.
 * AI-generated game code executes after them.
 */
function injectAdvanced2dRuntime(
    string $html,
    string $phaserJs,
    string $runtimeJs
): string {
    if (trim($phaserJs) === '') {
        throw new RuntimeException(
            'فایل assets/phaser.min.js خالی است یا بارگذاری نشده.'
        );
    }

    if (trim($runtimeJs) === '') {
        throw new RuntimeException(
            'فایل assets/vizura-runtime.js خالی است یا بارگذاری نشده.'
        );
    }

    // A literal "</script>" inside an inlined library would close the
    // HTML script element early and leave the page blank.
    $escapeScript = static function (string $js): string {
        return preg_replace('/<\/script/i', '<\\/script', $js) ?? $js;
    };

    $block = "\n<!-- VIZURA PHASER RUNTIME START -->\n";

    $block .= "<script>\n";
    $block .= $escapeScript($phaserJs);
    $block .= "\n</script>\n";

    $block .= "<script>\n";
    $block .= $escapeScript($runtimeJs);
    $block .= "\n</script>\n";

    $block .= "<!-- VIZURA PHASER RUNTIME END -->\n";

    // از callback استفاده می‌کنیم تا محتوای JS به‌عنوان
    // replacement expression تفسیر نشود.
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback(
            '/<head[^>]*>/i',
            function ($matches) use ($block) {
                return $matches[0] . $block;
            },
            $html,
            1
        );
    }

    if (preg_match('/<html[^>]*>/i', $html)) {
        return preg_replace_callback(
            '/<html[^>]*>/i',
            function ($matches) use ($block) {
                return $matches[0] .
                    "\n<head>\n" .
                    $block .
                    "\n</head>\n";
            },
            $html,
            1
        );
    }

    return "<head>\n" . $block . "\n</head>\n" . $html;
}

/**
 * Basic static validation for Advanced 2D generated games.
 *
 * This does NOT attempt to prove that the game is perfect.
 * It only catches the most common architecture-breaking mistakes.
 *
 * @return array{ok:bool, errors:string[]}
 */
function validateAdvanced2dGame(string $html): array
{
    $errors = [];

    if (stripos($html, 'VizuraGame.define') === false) {
        $errors[] = 'VizuraGame.define(...) در خروجی بازی پیدا نشد.';
    }

    if (stripos($html, 'VizuraRuntimeStart') === false) {
        $errors[] = 'VizuraRuntimeStart() در خروجی بازی پیدا نشد.';
    }

    $forbidden = [
        'new Phaser.Game',
        'class extends Phaser.Scene',
        'extends Phaser.Scene',
        'new Phaser.Scene',
        'this.load.image(',
        'this.load.spritesheet(',
        'this.load.atlas(',
        'this.physics.add.',
        'this.add.particles(',
        'Phaser.Game(',
    ];

    foreach ($forbidden as $pattern) {
        if (stripos($html, $pattern) !== false) {
            $errors[] = 'کد غیرمجاز در خروجی پیدا شد: ' . $pattern;
        }
    }

    if (!preg_match(
        '/<div[^>]+id\s*=\s*["\']game-container["\']/i',
        $html
    )) {
        $errors[] = 'کانتینر #game-container وجود ندارد.';
    }

    if (!preg_match('/<html[^>]+dir\s*=\s*["\']rtl["\']/i', $html)) {
        $errors[] = 'html باید dir="rtl" داشته باشد.';
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
    ];
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

function saveGame(string $gamesDir, string $gameId, string $html, string $userPrompt = '', string $modelUsed = '', string $levelUsed = ''): void
{
    if ($levelUsed !== '') {
        $safeLevel = str_replace(['-->', '<!--'], ['', ''], $levelUsed);
        $html = "<!-- LEVEL_USED: " . $safeLevel . " -->\n" . $html;
    }

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
function getGameLevel(string $gamesDir, string $gameId): ?string
{
    $file = $gamesDir . '/' . $gameId . '.html';
    if (!file_exists($file)) {
        return null;
    }

    $content = file_get_contents($file);

    if (preg_match('/<!--\s*LEVEL_USED:\s*(.+?)\s*-->/s', $content, $m)) {
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