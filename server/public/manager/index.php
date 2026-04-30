<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../common/battle_common.php';
require_once __DIR__ . '/../common/question_repository.php';
require_once __DIR__ . '/../manager/common.php';

manager_require_login();

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error = '';
$message = '';
$syncSummary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!manager_verify_csrf($csrfToken)) {
        $error = '不正なリクエストです。';
    } else {
        $syncError = null;
        $syncSummary = question_sync_catalog($pdo, $syncError);
        if ($syncError !== null && $syncError !== '') {
            $error = $syncError;
        } else {
            $message = 'カテゴリ情報を同期しました。';
        }
    }
}

$stats = question_catalog_stats($pdo);
$csrfToken = manager_csrf_token();
$envLoaded = defined('ENV_FILE_LOADED') ? ENV_FILE_LOADED : '';
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>カテゴリ同期管理</title>
    <style>
        :root {
            --bg1: #091224;
            --bg2: #15223f;
            --panel: rgba(255, 255, 255, 0.08);
            --line: rgba(255, 255, 255, 0.12);
            --text: #f8fafc;
            --muted: #cbd5e1;
            --ok: #86efac;
            --bad: #fecaca;
            --accent: #38bdf8;
        }

        body {
            margin: 0;
            background: radial-gradient(circle at top left, #1d2b52, transparent 30%), linear-gradient(160deg, var(--bg1), var(--bg2));
            color: var(--text);
            font-family: "Segoe UI", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif;
        }

        .wrap {
            max-width: 980px;
            margin: 0 auto;
            padding: 32px 16px 64px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .logout {
            color: var(--text);
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.06);
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.22);
            backdrop-filter: blur(8px);
        }

        h1, h2 {
            margin: 0 0 10px;
        }

        p, li, dt, dd {
            color: var(--muted);
        }

        .status {
            margin: 14px 0;
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 700;
        }

        .status.ok {
            background: rgba(34, 197, 94, 0.14);
            color: var(--ok);
        }

        .status.error {
            background: rgba(239, 68, 68, 0.14);
            color: var(--bad);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 18px 0 24px;
        }

        .card {
            padding: 16px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .num {
            display: block;
            margin-top: 6px;
            font-size: 28px;
            font-weight: 800;
            color: var(--text);
        }

        button {
            padding: 14px 18px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, #38bdf8, #22c55e);
            color: #08111f;
            font-weight: 800;
            cursor: pointer;
        }

        dl {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 10px 16px;
            margin: 18px 0 0;
        }

        dd {
            margin: 0;
            word-break: break-all;
        }

        code {
            color: #e2e8f0;
        }

        @media (max-width: 640px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            dl {
                grid-template-columns: 1fr;
                gap: 4px;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <h1>カテゴリ同期管理</h1>
                <p>問題サーバから最新版のカテゴリ情報を取得し、`qb_question_category` を更新します。</p>
            </div>
            <a class="logout" href="<?= htmlspecialchars(manager_base_url('logout.php'), ENT_QUOTES, 'UTF-8') ?>">ログアウト</a>
        </div>

        <section class="panel">
            <?php if ($message !== ''): ?>
                <div class="status ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="status error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="grid">
                <div class="card">
                    <div>大カテゴリ数</div>
                    <span class="num"><?= (int)$stats['cate1_count'] ?></span>
                </div>
                <div class="card">
                    <div>サブカテゴリ数</div>
                    <span class="num"><?= (int)$stats['cate2_count'] ?></span>
                </div>
                <div class="card">
                    <div>テーマ数</div>
                    <span class="num"><?= (int)$stats['theme_count'] ?></span>
                </div>
                <div class="card">
                    <div>最終取得日時</div>
                    <span class="num" style="font-size:18px"><?= htmlspecialchars($stats['last_fetched_at'] !== '' ? $stats['last_fetched_at'] : '未取得', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit">最新版カテゴリを取得する</button>
            </form>

            <?php if (is_array($syncSummary)): ?>
                <dl>
                    <dt>今回の大カテゴリ件数</dt>
                    <dd><?= (int)$syncSummary['cate1_count'] ?></dd>
                    <dt>今回のサブカテゴリ件数</dt>
                    <dd><?= (int)$syncSummary['cate2_count'] ?></dd>
                    <dt>今回のテーマ件数</dt>
                    <dd><?= (int)$syncSummary['theme_count'] ?></dd>
                </dl>
            <?php endif; ?>

            <dl>
                <dt>カテゴリAPI</dt>
                <dd><code><?= htmlspecialchars(question_category_api_base(), ENT_QUOTES, 'UTF-8') ?></code></dd>
                <dt>.env 読み込み元</dt>
                <dd><code><?= htmlspecialchars($envLoaded !== '' ? $envLoaded : '未検出', ENT_QUOTES, 'UTF-8') ?></code></dd>
            </dl>
        </section>
    </div>
</body>

</html>
