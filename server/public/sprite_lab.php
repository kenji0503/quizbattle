<?php
// quiz/battle/test/sprite_lab.php で保存想定
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$baseDir = __DIR__;
$imgDir  = $baseDir . '/images';
$webImg  = 'images';

$files = glob($imgDir . '/*_sprite_320x320.png'); // 規格：320x320 / 4x4
usort($files, fn($a, $b) => strcmp(basename($a), basename($b)));

// ?only=human,robot などで絞り込み可能
$only = isset($_GET['only']) ? explode(',', $_GET['only']) : [];

function pick($path)
{
    return basename($path);
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>スプライト画像 ラボ（4×4/320×320 テスト専用）</title>

    <!-- 既存のsprite.cssを使う場合（任意） -->
    <link rel="stylesheet" href="css/sprite.css">

    <style>
        :root {
            --ink: #e6eaf2;
            --dim: #9fb0c7;
            --card: #10131a;
            --line: #2b3240;
            --accent: #69f0ff;
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #060814;
            color: var(--ink);
            font: 14px/1.6 system-ui, -apple-system, Segoe UI, Meiryo, "Noto Sans JP", sans-serif
        }

        header {
            position: sticky;
            top: 0;
            z-index: 3;
            background: #121528;
            border-bottom: 1px solid #1f2533;
            padding: 10px 14px
        }

        header h1 {
            margin: 0;
            font-size: 16px
        }

        .container {
            max-width: 1200px;
            margin: 14px auto;
            padding: 0 12px
        }

        .tools {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0 16px
        }

        .tools .field {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #0b0f18;
            border: 1px solid #1b2130;
            padding: 8px 10px;
            border-radius: 10px
        }

        input[type="range"] {
            width: 160px
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px
        }

        .card {
            background: var(--card);
            border: 1px solid #1b2130;
            border-radius: 14px;
            overflow: hidden
        }

        .card header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #0e1320;
            padding: 8px 10px;
            border-bottom: 1px solid #1b2130
        }

        .card header .name {
            font-weight: 700
        }

        .card .pad {
            padding: 10px
        }

        .stage {
            position: relative;
            width: 160px;
            height: 160px;
            margin: auto;
            border-radius: 12px;
            background: #0a0f18;
            display: grid;
            place-items: center;
            border: 1px solid #1b2130
        }

        .sprite {
            width: 80px;
            height: 80px;
            background-repeat: no-repeat;
            background-position: 0 0;
            background-size: 400% 400%;
            /* 4x4 想定 */
            image-rendering: auto;
            /* ドット絵なら pixelated */
            overflow: hidden;
        }

        .gridlines::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(to right, rgba(255, 255, 255, .08) 1px, transparent 1px) 0 0 / 80px 80px,
                linear-gradient(to bottom, rgba(255, 255, 255, .08) 1px, transparent 1px) 0 0 / 80px 80px;
            pointer-events: none;
            border-radius: 12px
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px
        }

        button,
        .btn {
            appearance: none;
            border: 1px solid #2a3346;
            background: #12192b;
            color: #e6eaf2;
            padding: 6px 10px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700
        }

        button:hover {
            filter: brightness(1.06)
        }

        .badge {
            font: 12px/1.4 ui-monospace, Consolas, monospace;
            color: #b7c2d8;
            background: #0b1220;
            border: 1px solid #1b2130;
            padding: 2px 6px;
            border-radius: 6px
        }

        .small {
            font-size: 12px;
            color: var(--dim)
        }

        .footer {
            margin: 30px 0 20px;
            text-align: center;
            color: var(--dim)
        }
    </style>
</head>

<body>
    <header>
        <h1>スプライト画像ラボ（テスト専用 / 4×4=16フレーム / 320×320）</h1>
    </header>

    <div class="container">

        <div class="tools">
            <div class="field">
                <label>再生FPS</label>
                <input id="fps" type="range" min="4" max="30" step="1" value="12">
                <span id="fpsVal" class="badge">12</span>
            </div>
            <div class="field">
                <button id="playAll">▶ すべて再生</button>
                <button id="pauseAll">⏸ すべて停止</button>
                <button id="stepAll">⏭ 全カード同じコマへ</button>
                <select id="stepSel">
                    <?php for ($i = 0; $i < 16; $i++): ?>
                        <option value="<?= $i ?>"><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field">
                <label><input id="toggleGrid" type="checkbox"> 80pxグリッドを重ねる</label>
            </div>
            <div class="field">
                <form method="get" onsubmit="/*keep*/">
                    <label>絞り込み（カンマ区切り）：</label>
                    <input type="text" name="only" placeholder="human,robot,ufo" value="<?= htmlspecialchars($_GET['only'] ?? '', ENT_QUOTES) ?>">
                    <button type="submit">適用</button>
                </form>
            </div>
        </div>

        <div id="cards" class="grid">
            <?php
            if (!$files) {
                echo '<p>images フォルダに *_sprite_320x320.png を置いてください。</p>';
            } else {
                foreach ($files as $abs) {
                    $bn = basename($abs);
                    $name = preg_replace('/_sprite_320x320\.png$/', '', $bn);
                    if ($only && !in_array($name, $only, true)) continue;
                    $url = $webImg . '/' . $bn;
            ?>
                    <section class="card" data-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>" data-url="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                        <header>
                            <span class="name"><?= htmlspecialchars($name, ENT_QUOTES) ?></span>
                            <span class="small"><?= htmlspecialchars($bn, ENT_QUOTES) ?></span>
                        </header>
                        <div class="pad">
                            <div class="stage">
                                <div class="sprite" aria-label="<?= htmlspecialchars($name, ENT_QUOTES) ?>"></div>
                                <!-- グリッドラインのON/OFFはJSでstageに .gridlines を付与 -->
                            </div>
                            <div class="controls">
                                <button class="play">▶</button>
                                <button class="pause">⏸</button>
                                <button class="prev">⏮</button> <!-- 追加 -->
                                <button class="next">⏭</button> <!-- 追加 -->
                                <label class="small">コマ：
                                    <select class="frameSel">
                                        <?php for ($i = 0; $i < 16; $i++): ?>
                                            <option value="<?= $i ?>"><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                                <span class="badge info">0/15</span>
                            </div>
                        </div>
                    </section>
            <?php
                }
            }
            ?>
        </div>

        <p class="footer">※ 形式は 4×4=16フレーム（各80×80）・画像全体 320×320 固定。既存ランタイムの <code>background-size: 400% 400%</code> 前提です。</p>

    </div>

    <script src="js/sprite_runtime.js"></script>
    <script>
        (function() {
            // 全体FPS
            const fps = document.getElementById('fps');
            const fpsVal = document.getElementById('fpsVal');

            function applyFps() {
                const v = +fps.value || 12;
                fpsVal.textContent = v;
                if (window.SpriteEngine) SpriteEngine.setGlobalFps(v);
            }
            fps.addEventListener('input', applyFps);
            applyFps();

            // カード初期化
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => {
                const url = card.dataset.url;
                const stage = card.querySelector('.stage');
                const sp = card.querySelector('.sprite');
                const badge = card.querySelector('.badge.info');
                const playBtn = card.querySelector('.play');
                const pauseBtn = card.querySelector('.pause');
                const sel = card.querySelector('.frameSel');

                const prevBtn = card.querySelector('.prev');
                const nextBtn = card.querySelector('.next');


                // 初期フレームインデックス

                let currentFrame = 0;
                // 前へ
                prevBtn.addEventListener('click', () => {
                    currentFrame = (currentFrame + 15) % 16;
                    SpriteEngine.setFrame(sp, currentFrame);
                    sel.value = currentFrame;
                    badge.textContent = `${currentFrame}/15`;
                });

                // 次へ
                nextBtn.addEventListener('click', () => {
                    currentFrame = (currentFrame + 1) % 16;
                    SpriteEngine.setFrame(sp, currentFrame);
                    sel.value = currentFrame;
                    badge.textContent = `${currentFrame}/15`;
                });

                // 画像を当てて登録（規格：4×4）
                SpriteEngine.attach(sp, url);

                // 再生・停止
                playBtn.addEventListener('click', () => SpriteEngine.resume());
                pauseBtn.addEventListener('click', () => SpriteEngine.pause());

                // コマ送り（0-15）
                sel.addEventListener('change', () => {
                    const i = +sel.value | 0;
                    currentFrame = i; // 追加
                    SpriteEngine.setFrame(sp, i);
                    badge.textContent = i + '/15';
                });

                // 初期値
                badge.textContent = '0/15';
            });

            // すべて操作
            document.getElementById('playAll').addEventListener('click', () => SpriteEngine.resume());
            document.getElementById('pauseAll').addEventListener('click', () => SpriteEngine.pause());
            document.getElementById('stepAll').addEventListener('click', () => {
                const i = +document.getElementById('stepSel').value | 0;
                document.querySelectorAll('.card .sprite').forEach(sp => {
                    SpriteEngine.setFrame(sp, i);
                });
                document.querySelectorAll('.card .badge.info').forEach(b => b.textContent = i + '/15');
            });

            // 80pxグリッドの重ね合わせ
            const gridChk = document.getElementById('toggleGrid');
            gridChk.addEventListener('change', () => {
                document.querySelectorAll('.stage').forEach(st => {
                    if (gridChk.checked) st.classList.add('gridlines');
                    else st.classList.remove('gridlines');
                });
            });

        })();
    </script>
</body>

</html>