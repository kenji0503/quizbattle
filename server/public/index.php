<?php
require_once __DIR__ . '/../../config/config.php';
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Language" content="ja" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" href="images/icon/icon.png">
    <link rel="icon" href="images/icon/favicon.ico" type="image/x-icon">
    <title>推し問バトル</title>
    <style>
        :root {
            --bg-1: #04111f;
            --bg-2: #0b2540;
            --line: rgba(255, 255, 255, 0.14);
            --text: #eef4ff;
            --muted: #b8c4db;
            --accent: #7cf6d3;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(124, 246, 211, 0.18), transparent 30%),
                radial-gradient(circle at right 20%, rgba(255, 209, 102, 0.18), transparent 28%),
                linear-gradient(160deg, var(--bg-1), var(--bg-2) 55%, #102f4f);
        }

        .shell {
            width: min(720px, calc(100% - 24px));
            margin: 0 auto;
            padding: 32px 0;
        }

        .hero {
            padding: 28px 24px;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.04));
            backdrop-filter: blur(10px);
            box-shadow: 0 18px 60px rgba(0, 0, 0, 0.28);
        }

        .eyebrow {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(124, 246, 211, 0.14);
            color: var(--accent);
            font-weight: 700;
            letter-spacing: 0.08em;
            font-size: 12px;
        }

        h1 {
            margin: 18px 0 12px;
            font-size: clamp(34px, 6vw, 68px);
            line-height: 1.04;
        }

        .lead {
            margin: 0;
            color: var(--muted);
            font-size: clamp(16px, 2.2vw, 20px);
            line-height: 1.75;
        }

        .actions {
            display: block;
            margin-top: 28px;
        }

        .button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 44px;
            padding: 0 18px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 800;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .button-primary {
            color: #062235;
            background: linear-gradient(135deg, var(--accent), #bfffea);
            box-shadow: 0 12px 28px rgba(124, 246, 211, 0.22);
        }

        .button:hover {
            transform: translateY(-1px);
        }

        @media (max-width: 840px) {
            .hero {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <main class="shell">
        <section class="hero">
            <span class="eyebrow">QUIZ BATTLE TEST</span>
            <h1>推し問バトル</h1>
            <p class="lead">
                テーマを選んで対戦部屋を作成し、参加者とリアルタイムでクイズバトルを進める画面です。
                部屋の作成、参加 URL の発行、ロビー更新、出題進行までを一つのフローで確認できます。
            </p>
            <div class="actions">
                <a class="button button-primary" href="battle.php">バトルを開始する</a>
            </div>
        </section>
    </main>
</body>

</html>
