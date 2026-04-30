<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$log = Logger::getInstance();
$log->debug('** ranking.php (battle) start');

$pdo = dbConnectPDO();
if (!$pdo) die('データベース接続エラー');

// GET パラメータ（gid はセッション既定でも可）
$bid  = isset($_GET['bid'])  ? (int)$_GET['bid']  : (int)($_SESSION['bid'] ?? 0);
$gid  = isset($_GET['gid'])  ? (int)$_GET['gid']  : (int)($_SESSION['gid'] ?? 0);
$c1   = isset($_GET['cate1']) ? (int)$_GET['cate1'] : 0;
$c2   = isset($_GET['cate2']) ? (int)$_GET['cate2'] : 0;
$qid  = isset($_GET['id'])   ? (int)$_GET['id']   : 0;
$qnum = isset($_GET['num'])  ? (int)$_GET['num']  : 0;

if (!$bid || !$gid || !$qid) {
    die('パラメータ不足です。');
}

// 名前取得（qb_user想定）
function qbUserName(PDO $pdo, int $uid): string
{
    $s = $pdo->prepare("
        SELECT name
          FROM qb_battle_participants
         WHERE bid = :bid AND gid = :gid AND uid = :uid
         ORDER BY last_ping DESC, joined_at DESC
         LIMIT 1
    ");
    $s->execute([':bid' => (int)($_GET['bid'] ?? ($_SESSION['bid'] ?? 0)), ':gid' => (int)($_GET['gid'] ?? ($_SESSION['gid'] ?? 0)), ':uid' => $uid]);
    return (string)($s->fetchColumn() ?: ('Player#' . $uid));
}

// 解答（押下）順
$stmt = $pdo->prepare("
    SELECT b.uid, b.sentaku, b.buzzed_at
  FROM qb_buzzes b
 WHERE b.bid=:bid AND b.gid=:gid
   AND b.cate1=:c1 AND b.cate2=:c2
   AND b.id=:qid AND b.num=:qnum
  ORDER BY b.buzzed_at ASC, b.uid ASC
");
$stmt->execute([
    ':bid' => $bid,
    ':gid' => $gid,
    ':c1' => $c1,
    ':c2' => $c2,
    ':qid' => $qid,
    ':qnum' => $qnum
]);
$answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 自分ID
$myuid = (int)($_SESSION['uid'] ?? 0);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta http-equiv="Content-Language" content="ja" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ランキング - クイズバトル</title>
    <link rel="stylesheet" href="css/battle.css">
    <style>
        body {
            background-color: #1b1b2f;
            color: #fff;
            font-family: 'Segoe UI', 'Meiryo', sans-serif;
            margin: 0;
            padding: 0;
        }

        #topBar {
            background: linear-gradient(90deg, #FFD700, #FFA500);
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #000;
        }

        #ranking {
            width: 90%;
            max-width: 700px;
            margin: 40px auto;
            border-collapse: collapse;
        }

        #ranking th {
            background: #FFD700;
            color: #000;
            padding: 8px;
            border: 1px solid #ccc;
        }

        #ranking td {
            padding: 8px;
            border: 1px solid #ccc;
            text-align: center;
        }

        .me {
            background: #ffe082;
            color: #000;
            font-weight: bold;
        }

        .back {
            margin: 1em auto;
            padding: .5em 1em;
            font-size: 1em;
            cursor: pointer;
            display: block;
        }
    </style>
</head>

<body>
    <div id="topBar">
        <div class="title-wrapper">ランキング</div>
        <div>
            <?php if (!empty($_SESSION['uid'])): ?>
                <?= htmlspecialchars(qbUserName($pdo, (int)$_SESSION['uid']), ENT_QUOTES, 'UTF-8') ?> さん
            <?php endif; ?>
        </div>
    </div>

    <div id="maincontents">
        <button type="button" class="back" onclick="window.location.href='start.php?bid=<?= (int)$bid ?>&gid=<?= (int)$gid ?>'">解答画面へ</button>

        <table id="ranking">
            <thead>
                <tr>
                    <th>順位</th>
                    <th>ユーザー名</th>
                    <th>選択肢</th>
                    <th>解答時間</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($answers): ?>
                    <?php foreach ($answers as $i => $ans): ?>
                        <?php $uname = qbUserName($pdo, (int)$ans['uid']); ?>
                        <tr class="<?= ((int)$ans['uid'] === $myuid) ? 'me' : '' ?>">
                            <td><?= $i + 1 ?>位</td>
                            <td><?= htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($ans['sentaku'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(date('H:i:s', strtotime($ans['buzzed_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">まだ誰も解答していません</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>

</html>
