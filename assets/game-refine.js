/**
 * game-refine.js — self-contained "Edit / fix bugs" widget.
 *
 * Depends on NOTHING in the host page (no axios, no host CSS classes,
 * no host markup). To add the feature anywhere:
 *
 *   1. include this script once:   <script src="assets/game-refine.js"></script>
 *   2. put a trigger anywhere:     <button data-game-refine="GAME_ID">ویرایش</button>
 *      (optionally add class="gr-trigger" for the built-in look)
 *
 * Programmatic use:
 *   GameRefine.open(gameId)                       -> opens the dialog
 *   GameRefine.refine(gameId, {model,note,replace}) -> Promise (no UI at all)
 *
 * When a refinement succeeds a cancelable "gamerefine:done" event is fired
 * on document (detail: {originalId, gameId, replaced, playUrl}). If nobody
 * calls preventDefault(), the page navigates to detail.playUrl.
 *
 * Endpoint: refine.php next to the page, or set
 *   <script src="..." data-endpoint="/path/refine.php"></script>
 */
(function () {
    'use strict';

    if (window.GameRefine) return;

    var script = document.currentScript;
    var config = {
        endpoint: (script && script.getAttribute('data-endpoint')) || 'refine.php',
        timeoutMs: 300000
    };

    // ------------------------------------------------------------------
    // Network layer (no DOM)
    // ------------------------------------------------------------------
    var modelsPromise = null;

    function loadModels() {
        if (!modelsPromise) {
            modelsPromise = fetch(config.endpoint + '?action=models', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .catch(function () { modelsPromise = null; return { ok: false, models: [] }; });
        }
        return modelsPromise;
    }

    function refine(gameId, options, signal) {
        options = options || {};
        var body = new FormData();
        body.append('game_id', gameId);
        if (options.model) body.append('model', options.model);
        if (options.note) body.append('note', options.note);
        if (options.replace) body.append('replace', '1');

        return fetch(config.endpoint, { method: 'POST', body: body, signal: signal })
            .then(function (r) {
                return r.json().catch(function () {
                    throw new Error('پاسخ نامعتبر از سرور (کد ' + r.status + ').');
                });
            })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'خطای نامشخص از سرور.');
                return data;
            });
    }

    // ------------------------------------------------------------------
    // Styles (scoped with the gr- prefix, injected once)
    // ------------------------------------------------------------------
    function injectStyles() {
        if (document.getElementById('gr-styles')) return;
        var css = [
            '.gr-trigger{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;border:1px solid rgba(165,180,252,.35);background:rgba(99,102,241,.15);color:#c7d2fe;font:inherit;font-size:.9rem;cursor:pointer;transition:background .15s,border-color .15s}',
            '.gr-trigger:hover{background:rgba(99,102,241,.3);border-color:#818cf8}',
            '.gr-backdrop{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(2,6,23,.7);backdrop-filter:blur(4px);direction:rtl;font-family:Vazir,Tahoma,Arial,sans-serif}',
            '.gr-backdrop.gr-open{display:flex}',
            '.gr-dialog{width:100%;max-width:520px;max-height:100%;overflow:auto;background:#1e293b;color:#f1f5f9;border:1px solid rgba(148,163,184,.2);border-radius:18px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.5)}',
            '.gr-dialog h3{margin:0 0 6px;font-size:1.15rem}',
            '.gr-dialog p.gr-hint{margin:0 0 18px;color:#94a3b8;font-size:.85rem;line-height:1.7}',
            '.gr-dialog label{display:block;margin:14px 0 6px;color:#94a3b8;font-size:.85rem}',
            '.gr-dialog select,.gr-dialog textarea{width:100%;box-sizing:border-box;padding:11px 13px;border-radius:10px;border:1px solid rgba(148,163,184,.2);background:rgba(15,23,42,.7);color:#f1f5f9;font:inherit;font-size:.92rem;outline:none}',
            '.gr-dialog select:focus,.gr-dialog textarea:focus{border-color:#818cf8}',
            '.gr-dialog textarea{min-height:90px;resize:vertical;line-height:1.7}',
            '.gr-check{display:flex!important;align-items:flex-start;gap:8px;color:#cbd5e1!important;cursor:pointer}',
            '.gr-check input{margin-top:4px}',
            '.gr-actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}',
            '.gr-btn{padding:11px 22px;border-radius:10px;border:0;font:inherit;font-size:.95rem;cursor:pointer}',
            '.gr-btn-primary{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:600}',
            '.gr-btn-primary:disabled{opacity:.6;cursor:not-allowed}',
            '.gr-btn-ghost{background:transparent;color:#94a3b8;border:1px solid rgba(148,163,184,.25)}',
            '.gr-status{margin-top:16px;font-size:.9rem;color:#a5b4fc;display:none}',
            '.gr-status.gr-error{color:#fecaca;background:rgba(127,29,29,.45);border:1px solid rgba(248,113,113,.3);border-radius:10px;padding:10px 14px;direction:rtl;white-space:pre-wrap}',
            '.gr-spin{display:inline-block;width:14px;height:14px;margin-left:8px;border:2px solid rgba(165,180,252,.35);border-top-color:#a5b4fc;border-radius:50%;animation:gr-rot .8s linear infinite;vertical-align:-2px}',
            '@keyframes gr-rot{to{transform:rotate(360deg)}}'
        ].join('\n');
        var style = document.createElement('style');
        style.id = 'gr-styles';
        style.textContent = css;
        document.head.appendChild(style);
    }

    // Inject right away (not on first click), so a trigger button is styled
    // from the very first paint. Needs only document.head, which exists even
    // when this script is loaded from <head>.
    injectStyles();

    // ------------------------------------------------------------------
    // Dialog (built lazily, once)
    // ------------------------------------------------------------------
    var ui = null;
    var currentGameId = null;
    var abortController = null;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function buildDialog() {
        var backdrop = el('div', 'gr-backdrop');
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');

        var dialog = el('div', 'gr-dialog');
        dialog.appendChild(el('h3', '', '🛠️ ویرایش و رفع باگ بازی'));
        dialog.appendChild(el('p', 'gr-hint',
            'کد بازی برای هوش مصنوعی ارسال می‌شود تا آن را از نظر عملکرد، دیزاین، ساختار و محتوا بررسی کرده و مشکلاتش را رفع کند.'));

        var modelLabel = el('label', '', 'مدل هوش مصنوعی');
        var select = el('select');
        modelLabel.appendChild(select);

        var noteLabel = el('label', '', 'مشکلی که دیدید (اختیاری)');
        var note = el('textarea');
        note.placeholder = 'مثلاً: گزینهٔ درست همیشه اولین گزینه است / دکمهٔ شروع کار نمی‌کند';
        noteLabel.appendChild(note);

        var checkLabel = el('label', 'gr-check');
        var replace = el('input');
        replace.type = 'checkbox';
        checkLabel.appendChild(replace);
        checkLabel.appendChild(el('span', '', 'به‌جای بازی فعلی ذخیره شود (نسخهٔ قبلی پشتیبان‌گیری می‌شود). در غیر این صورت، بازی جدیدی ساخته می‌شود.'));

        var status = el('div', 'gr-status');

        var actions = el('div', 'gr-actions');
        var submit = el('button', 'gr-btn gr-btn-primary', '🚀 شروع بررسی و ویرایش');
        submit.type = 'button';
        var cancel = el('button', 'gr-btn gr-btn-ghost', 'انصراف');
        cancel.type = 'button';
        actions.appendChild(submit);
        actions.appendChild(cancel);

        dialog.appendChild(modelLabel);
        dialog.appendChild(noteLabel);
        dialog.appendChild(checkLabel);
        dialog.appendChild(actions);
        dialog.appendChild(status);
        backdrop.appendChild(dialog);
        document.body.appendChild(backdrop);

        backdrop.addEventListener('mousedown', function (e) {
            if (e.target === backdrop && !abortController) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && backdrop.classList.contains('gr-open') && !abortController) close();
        });
        cancel.addEventListener('click', function () {
            if (abortController) abortController.abort();
            close();
        });
        submit.addEventListener('click', run);

        ui = { backdrop: backdrop, select: select, note: note, replace: replace, status: status, submit: submit };
    }

    function setStatus(message, isError, busy) {
        ui.status.className = 'gr-status' + (isError ? ' gr-error' : '');
        ui.status.textContent = '';
        if (busy) ui.status.appendChild(el('span', 'gr-spin'));
        ui.status.appendChild(document.createTextNode(message));
        ui.status.style.display = message ? 'block' : 'none';
    }

    function close() {
        if (ui) ui.backdrop.classList.remove('gr-open');
    }

    function fillModels() {
        return loadModels().then(function (data) {
            if (ui.select.options.length) return;
            (data.models || []).forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = m.label;
                ui.select.appendChild(opt);
            });
            if (data.default) ui.select.value = data.default;
            ui.select.parentNode.style.display = ui.select.options.length ? '' : 'none';
        });
    }

    function open(gameId) {
        if (!gameId) return;
        if (!ui) buildDialog();
        currentGameId = gameId;
        setStatus('', false, false);
        ui.submit.disabled = false;
        ui.backdrop.classList.add('gr-open');
        fillModels();
        ui.note.focus();
    }

    function run() {
        var gameId = currentGameId;
        var options = {
            model: ui.select.value || '',
            note: ui.note.value.trim(),
            replace: ui.replace.checked
        };

        abortController = new AbortController();
        var timer = setTimeout(function () { abortController.abort(); }, config.timeoutMs);

        ui.submit.disabled = true;
        setStatus('در حال بررسی و ویرایش بازی... (ممکن است چند دقیقه طول بکشد)', false, true);

        refine(gameId, options, abortController.signal)
            .then(function (data) {
                var detail = { originalId: gameId, gameId: data.gameId, replaced: !!data.replaced, playUrl: data.playUrl };
                var proceed = document.dispatchEvent(new CustomEvent('gamerefine:done', { detail: detail, cancelable: true }));
                if (proceed && data.playUrl) window.location.assign(data.playUrl);
                else close();
            })
            .catch(function (err) {
                var aborted = err && err.name === 'AbortError';
                setStatus(aborted ? 'درخواست لغو شد یا مهلت زمانی تمام شد.' : (err.message || 'خطا در ارتباط با سرور.'), true, false);
                ui.submit.disabled = false;
            })
            .then(function () {
                clearTimeout(timer);
                abortController = null;
            });
    }

    // ------------------------------------------------------------------
    // Declarative triggers: any [data-game-refine="ID"] element, anywhere,
    // including ones added to the page later (event delegation).
    // ------------------------------------------------------------------
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-game-refine]') : null;
        if (!trigger) return;
        e.preventDefault();
        open(trigger.getAttribute('data-game-refine'));
    });

    window.GameRefine = { open: open, refine: refine, configure: function (o) { for (var k in o) config[k] = o[k]; } };
})();
