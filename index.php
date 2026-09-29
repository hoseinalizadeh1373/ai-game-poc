<?php

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

$games = listGames($gamesDir);

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mega Game — بازی‌های ساخته‌شده</title>
    <style>
        * {
            box-sizing: border-box;
        }

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
            margin: 0 auto 24px;
        }

        .cta-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 12px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            box-shadow: 0 4px 16px rgba(99, 102, 241, 0.35);
            transition: transform 0.15s, box-shadow 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(99, 102, 241, 0.45);
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 24px;
            border-radius: 12px;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.15);
            color: #c7d2fe;
            text-decoration: none;
            font-weight: 500;
            font-size: 15px;
            transition: border-color 0.2s, transform 0.15s;
        }

        .btn-ghost:hover {
            border-color: #818cf8;
            transform: translateY(-1px);
        }

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
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
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

        .empty {
            color: #64748b;
            font-size: 0.95rem;
            padding: 40px 0;
            text-align: center;
        }

        .empty a {
            color: #a5b4fc;
            text-decoration: none;
        }

        .empty a:hover {
            color: #c7d2fe;
        }

        @media (max-width: 640px) {
            .container {
                padding: 24px 16px 60px;
            }
        }
    </style>
</head>

<body>

    <div class="container">

        <div class="page-header">
            <h1>🎮 Mega Game</h1>
            <p class="description">
                بازی‌های ساخته‌شده با هوش مصنوعی — یکی رو انتخاب کن و بازی کن
            </p>
            <div class="cta-row">
                <a class="btn-primary" href="create.php">🚀 ساخت بازی جدید</a>
            </div>
        </div>

        <div class="games-list">
            <h2>بازی‌های ساخته‌شده</h2>

            <?php if (empty($games)): ?>
                <div class="empty">
                    هنوز هیچ بازی‌ای ساخته نشده است.
                    <br><br>
                    <a href="create.php">اولین بازی رو بساز ←</a>
                </div>
            <?php else: ?>
                <div class="games-grid">
                    <?php foreach ($games as $g): ?>
                        <a class="game-card" href="play.php?id=<?= urlencode($g['id']) ?>">
                            <div class="title"><?= htmlspecialchars($g['title']) ?></div>
                            <div class="meta">
                                <?= htmlspecialchars(date('Y-m-d H:i', $g['created_at'])) ?>
                                <?php if (!empty($g['creator'])): ?>
                                    · <?= htmlspecialchars($g['creator']) ?>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

</body>

</html>