<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../manager/common.php';

if (manager_is_logged_in()) {
    header('Location: ' . manager_base_url('index.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!manager_login_configured()) {
        $error = '管理者ログイン情報が未設定です。.env を確認してください。';
    } elseif (!manager_verify_credentials($loginId, $password)) {
        $error = 'ログインIDまたはパスワードが違います。';
    } else {
        manager_login($loginId);
        header('Location: ' . manager_base_url('index.php'));
        exit;
    }
}
?>
<!doctype html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>カテゴリ同期 管理ログイン</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: linear-gradient(160deg, #0c1630, #1e2348 45%, #15203a);
            color: #eef2ff;
            font-family: "Segoe UI", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif;
        }

        .card {
            width: min(420px, calc(100vw - 32px));
            padding: 28px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        p {
            color: #c7d2fe;
        }

        label {
            display: block;
            margin-top: 16px;
            margin-bottom: 8px;
            font-weight: 700;
        }

        input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
        }

        button {
            width: 100%;
            margin-top: 20px;
            padding: 12px 14px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, #60a5fa, #22d3ee);
            color: #08111f;
            font-weight: 800;
            cursor: pointer;
        }

        .error {
            color: #fecaca;
            font-weight: 700;
        }
    </style>
</head>

<body>
    <main class="card">
        <h1>管理者ログイン</h1>
        <p>`qb_question_category` 同期用の管理画面です。</p>
        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post">
            <label for="login_id">ログインID</label>
            <input id="login_id" name="login_id" type="text" required autocomplete="username">

            <label for="password">パスワード</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">

            <button type="submit">ログイン</button>
        </form>
    </main>
</body>

</html>
