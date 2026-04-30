<?php
// 結果発表（3問終了後）
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

$log = Logger::getInstance();
$log->debug('** result.php start');

$pdo = dbConnectPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// bid/gid はセッション優先、無ければ GET から
$gid = (int)($_SESSION['gid'] ?? ($_GET['gid'] ?? 0));
$bid = (int)($_SESSION['bid'] ?? ($_GET['bid'] ?? 0));
$uid  = (int)($_SESSION['uid'] ?? 0);
$selfUid = $uid; // ← セッション本人のUIDを退避（以後はこれを使う）

if ($gid <= 0 || $bid <= 0) {
    http_response_code(400);
    die('invalid bid/gid');
}

$st = $pdo->prepare("SELECT bnum, phase, switch_at FROM qb_battle_state WHERE bid=:bid AND gid=:gid");
$st->execute([':bid' => $bid, ':gid' => $gid]);
$row = $st->fetch(PDO::FETCH_ASSOC);

$log->debug('[RESULT_ENTER] uid=' . $uid . ' gid=' . $gid . ' bid=' . $bid .
    ' phase=' . ($row['phase'] ?? 'null') . ' bnum=' . ($row['bnum'] ?? 'null') .
    ' ref=' . ($_SERVER['HTTP_REFERER'] ?? '-') . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '-'));

if (!$row) {
    die('state not found');
}
$nowMs = (int)floor(microtime(true) * 1000);
$maxSt = $pdo->prepare("SELECT MAX(order_no) FROM qb_battle_lineup WHERE bid=?");
$maxSt->execute([$bid]);
$maxOrder = (int)($maxSt->fetchColumn() ?? 0);

$isFinished = ((int)$row['phase'] === BATTLE_PHASE_FINISHED)
    || ((int)$row['bnum'] >= $maxOrder && $nowMs >= (int)$row['switch_at']);
if (!$isFinished) {
    header("Location: start.php?gid={$gid}&bid={$bid}");
    exit;
}

// 3問（bnum=1..3）を取得
$qStmt = $pdo->prepare("
   SELECT
     l.order_no AS bnum, l.cate1, l.cate2, l.id, l.num,
     m.mondai, m.kaito, m.qa, m.qb, m.qc, m.qd
   FROM qb_battle_lineup l
   JOIN qb_question_bank m
     ON m.cate1=l.cate1 AND m.cate2=l.cate2 AND m.qid=l.id AND m.qnum=l.num AND m.del=0
   WHERE l.bid=:bid
   ORDER BY l.order_no ASC
 ");
$qStmt->execute([':bid' => $bid]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$questions) {
    die('このバトルには問題がありません。');
}

// 参加者の解答（全問分）
$bStmt = $pdo->prepare("
  SELECT b.uid, b.cate1, b.cate2, b.id, b.num, b.sentaku, b.buzzed_at,
         COALESCE(p.name, CONCAT('Player#', b.uid)) AS name
  FROM qb_buzzes b
  LEFT JOIN qb_battle_participants p
    ON p.bid = b.bid AND p.gid = b.gid AND p.uid = b.uid
  WHERE b.bid=:bid AND b.gid=:gid
  ORDER BY b.cate1, b.cate2, b.id, b.num, b.buzzed_at ASC
");
$bStmt->execute([':bid' => $bid, ':gid' => $gid]);
$buzzes = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// --- 事実確認用の最小ログ（キー不一致の有無を確認） ---
// ① 出題キー集合
$qKeys = array_map(function ($q) {
    return "{$q['cate1']}-{$q['cate2']}-{$q['id']}-{$q['num']}";
}, $questions);
$qKeys = array_values(array_unique($qKeys));

// ② 回答側キー集合
$bKeys = [];
foreach ($buzzes as $b) {
    $bKeys[] = "{$b['cate1']}-{$b['cate2']}-{$b['id']}-{$b['num']}";
}
$bKeys = array_values(array_unique($bKeys));

// ③ 差分をログ（先頭5件だけ）
$missingInQ = array_values(array_diff($bKeys, $qKeys)); // buzzにはあるが出題に無い
$missingInB = array_values(array_diff($qKeys, $bKeys)); // 出題にはあるがbuzzに無い

$log->debug('[RESULT_DIAG] qKeys=' . count($qKeys) . ' bKeys=' . count($bKeys)
    . ' diff:buzz->q=' . json_encode(array_slice($missingInQ, 0, 5), JSON_UNESCAPED_UNICODE)
    . ' diff:q->buzz=' . json_encode(array_slice($missingInB, 0, 5), JSON_UNESCAPED_UNICODE));

// 参考：差分が出た場合、最初の1キーに紐づくbuzz行を少しだけ出す（任意）
if (!empty($missingInQ)) {
    $probe = $missingInQ[0];
    $cnt = 0;
    foreach ($buzzes as $b) {
        $k = "{$b['cate1']}-{$b['cate2']}-{$b['id']}-{$b['num']}";
        if ($k === $probe && $cnt < 3) {
            $log->debug('[RESULT_DIAG_SAMPLE] key=' . $probe . ' uid=' . $b['uid'] . ' sentaku=' . $b['sentaku'] . ' at=' . $b['buzzed_at']);
            $cnt++;
        }
    }
}
// --- /最小ログ ここまで ---

// インデックス化
$qByKey = [];    // "c1-c2-id-num" => ['bnum'=>..,'kaito'=>.., ...]
$qOrder = [];    // bnum => key
foreach ($questions as $q) {
    $key = "{$q['cate1']}-{$q['cate2']}-{$q['id']}-{$q['num']}";
    $qByKey[$key] = $q;
    $qOrder[(int)$q['bnum']] = $key;
}

// 集計用
$users = [];        // uid => name
$perUser = [];      // uid => ['correct'=>0,'first'=>0,'pos_sum'=>0]
$matrix  = [];      // uid => [bnum => ['ok'=>bool, 'pos'=>int] ]

// 問題ごとに並び順をつけて採点
$grouped = []; // key => [rows...]
foreach ($buzzes as $b) {
    $key = "{$b['cate1']}-{$b['cate2']}-{$b['id']}-{$b['num']}";
    $grouped[$key][] = $b;
    $users[(int)$b['uid']] = $b['name'];
}

// 各問題で pos を振って正誤判定（1問につき1人1回に集約）
$USE_FINAL_ANSWER = true; // 採点は「最終選択」。最初で採点したい場合は false に。

foreach ($grouped as $key => $rows) {
    if (!isset($qByKey[$key])) continue;

    $bnum  = (int)$qByKey[$key]['bnum'];
    $kaito = (string)$qByKey[$key]['kaito']; // 仕様どおり 'A'|'B'|'C'|'D' が入る前提

    // uidごとに「最初の押し（順位用）」と「最後の選択（採点用）」を抽出
    $firstByUid = []; // uid => 最初に押した行
    $lastByUid  = []; // uid => 最後に押した行
    foreach ($rows as $r) {
        $uid = (int)$r['uid'];
        $users[$uid] = $r['name'];            // 表示用に保持
        if (!isset($firstByUid[$uid])) {
            $firstByUid[$uid] = $r;           // $rowsはbuzzed_at昇順で渡ってくる前提
        }
        $lastByUid[$uid] = $r;                // 最後の行で更新
    }

    // 順位は「最初の押し」の時刻昇順
    $uniq = array_values($firstByUid);
    usort($uniq, function ($a, $b) {
        $t = strcmp($a['buzzed_at'], $b['buzzed_at']);
        return ($t !== 0) ? $t : ($a['uid'] <=> $b['uid']); // 同時刻時の安定化
    });

    $pos = 0;
    $firstCorrectAwarded = false; // その問題の最初の正解者だけ +1

    foreach ($uniq as $rFirst) {
        $uid = (int)$rFirst['uid'];
        $pos++;

        // 採点は「最終選択」か「最初の選択」をスイッチ
        $ans = $USE_FINAL_ANSWER ? (string)$lastByUid[$uid]['sentaku'] : (string)$rFirst['sentaku'];
        $ok  = ($ans === $kaito); // A/B/C/D 同士の厳密比較のみ

        // マトリクス（この問題について1回だけ記録）
        $matrix[$uid][$bnum] = ['ok' => $ok, 'pos' => $pos];

        // 個人集計（この問題について1回だけ）
        if (!isset($perUser[$uid])) $perUser[$uid] = ['correct' => 0, 'first' => 0, 'pos_sum' => 0];
        if ($ok) {
            $perUser[$uid]['correct']++;
            if (!$firstCorrectAwarded) {
                $perUser[$uid]['first']++;
                $firstCorrectAwarded = true;
            }
        }
        $perUser[$uid]['pos_sum'] += $pos;
    }
}

// “解答なし”の参加者も載せたい場合は qb_user から拾って追加してもOK（任意）
// ここでは「回答した人だけ」を表示対象にします。

// ランキング作成：正解 desc, pos_sum asc, name asc
$rank = [];
foreach ($users as $uid => $name) {
    $c = $perUser[$uid]['correct'] ?? 0;
    $f = $perUser[$uid]['first']   ?? 0;
    $s = $perUser[$uid]['pos_sum'] ?? 0;
    $rank[] = ['uid' => $uid, 'name' => $name, 'correct' => $c, 'first' => $f, 'pos_sum' => $s];
}
usort($rank, function ($a, $b) {
    if ($a['correct'] !== $b['correct']) return ($b['correct'] - $a['correct']); // desc
    if ($a['pos_sum'] !== $b['pos_sum']) return ($a['pos_sum'] - $b['pos_sum']); // asc
    return strcmp($a['name'], $b['name']);
});

// 自分の順位・スコア
$myRow = null;
$myRank = null;
foreach ($rank as $i => $row) {
    if ($row['uid'] === $selfUid) {
        $myRow = $row;
        $myRank = $i + 1;
        break;
    }
}

$log->debug('[RESULT_SUMMARY] ' . json_encode($rank, JSON_UNESCAPED_UNICODE));

// 再戦用のリンク
$__noticeMessage = (isset($_GET['notice']) && $_GET['notice'] === 'ended')
    ? '既にバトルは終了しています。結果をご確認ください。'
    : null;

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Language" content="ja" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>結果発表</title>
    <link rel="stylesheet" href="css/battle.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .container {
            width: min(1000px, 95%);
            margin: 24px auto;
        }

        .card {
            background: #2c2c4a;
            border: 3px solid #FFD700;
            border-radius: 12px;
            padding: 16px;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        h2 {
            margin: 0 0 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #555;
            padding: 8px;
            text-align: center;
        }

        th {
            background: #FFD700;
            color: #000;
        }

        tr.uid {
            background: #4c4c81ff;
            font-weight: bold;
        }

        .ok {
            color: #7CFC00;
            font-weight: bold;
        }

        /* 〇 */
        .ng {
            color: #FF6B6B;
            font-weight: bold;
        }

        /* × */
        .muted {
            color: #bbb;
        }

        .big {
            font-size: 1.6rem;
            font-weight: 800;
        }

        .btn {
            display: inline-block;
            margin: .5rem .25rem;
            padding: .5rem 1rem;
            background: #FFD700;
            color: #000;
            border-radius: 6px;
            text-decoration: none;
        }

        /* 問題の評価用 */
        .rate-row {
            display: grid;
            grid-template-columns: 3rem 1fr auto;
            gap: 8px;
            align-items: center;
            border: 1px solid #555;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 8px;
            background: #232345;
        }

        .rate-idx {
            text-align: center;
            font-weight: 700;
        }

        .rate-q {
            text-align: left;
        }

        .rate-actions .vote-btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .7rem;
            margin-left: .3rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
        }

        .vote-good {
            background: #27ae60;
            color: #fff;
        }

        .vote-bad {
            background: #e74c3c;
            color: #fff;
        }

        .vote-btn[disabled] {
            opacity: .6;
            cursor: default;
        }


        /* 狭い画面ではボタンを縦積み＋幅いっぱいに */
        @media (max-width: 640px) {

            /* 行レイアウト：アクションを下段に落とす */
            .rate-row {
                grid-template-columns: 3rem 1fr;
                /* 右端の 'auto' 列を無くす */
                align-items: start;
            }

            .rate-actions {
                grid-column: 1 / -1;
                /* 下段へ（左右全体） */
                flex-direction: column;
                /* 縦積み */
                align-items: stretch;
                /* 幅いっぱい */
                gap: 8px;
                margin-top: 2px;
            }

            .rate-actions .vote-btn {
                width: 100%;
                margin-left: 0;
                /* 既存の margin-left を打ち消し */
            }
        }


        .rate-note {
            font-size: .9rem;
            color: #bbb;
            margin: 6px 0 0;
        }

        /* 正解表示 */
        .rate-ans {
            font-size: .95rem;
            color: #ddd;
            margin-top: 4px;
        }

        .pill {
            display: inline-block;
            min-width: 1.4em;
            text-align: center;
            border: 1px solid #888;
            border-radius: 999px;
            padding: 0 .45em;
            margin-right: .35em;
            background: #111;
            font-weight: 700;
        }

        /* ヘッダバーを中央寄せ＆余白調整 */
        #topBar {
            position: sticky;
            /* 既存でstickyならそのまま */
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: center;
            /* 中央寄せ */
            align-items: center;
            padding: 14px 16px;
            background: linear-gradient(90deg, #181a3a, #2a1a47 40%, #0b2846);
            border-bottom: 1px solid rgba(255, 255, 255, .15);
        }

        /* 見出しをドーンと */
        .score-head {
            margin: 0;
            text-align: center;
            font-weight: 900;
            letter-spacing: .02em;
            font-size: clamp(24px, 6vw, 48px);
            /* 画面に応じて可変 */
            line-height: 1.15;
            color: #69f0ff;
            /* 目立つネオン系 */
            text-shadow:
                0 0 8px rgba(105, 240, 255, .6),
                0 0 18px rgba(105, 240, 255, .35);
        }

        /* 成績のバッジをさらに強調（色はゴールド系に） */
        /* ベース（共通） */
        .rank-badge {
            display: inline-block;
            margin-left: .4rem;
            padding: .14em .5em;
            border-radius: .7em;
            background: linear-gradient(180deg, #ffe070, #ff9d3c);
            color: #231a00;
            border: 1px solid rgba(255, 224, 112, .8);
            box-shadow:
                0 2px 10px rgba(255, 200, 80, .35),
                inset 0 1px 0 rgba(255, 255, 255, .6);
            font-size: .92em;
            /* 見出しのサイズに対する相対 */
            font-weight: 900;
        }

        /* 1) NEON（暗所でも最強の可読性） */
        .rank-badge.-neon {
            background: #00f0ff;
            color: #06141a;
            box-shadow:
                0 0 12px rgba(0, 240, 255, .55),
                0 0 28px rgba(0, 240, 255, .35);
            border: 1px solid rgba(180, 255, 255, .9);
            text-shadow: none;
        }

        /* 2) FLAT HIGH-CONTRAST（軽量・くっきり） */
        .rank-badge.-flat {
            background: #ff4d6d;
            /* お好みで #ffb703 などに変更可 */
            color: #120207;
            border: 1px solid rgba(255, 255, 255, .55);
            box-shadow: 0 2px 8px rgba(0, 0, 0, .18);
            text-shadow: none;
        }

        /* 3) GLASS PILL（ガラス感：背景に馴染みつつ目立つ） */
        .rank-badge.-glass {
            background: linear-gradient(180deg, rgba(255, 255, 255, .28), rgba(255, 255, 255, .08));
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .45);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .55),
                0 6px 18px rgba(0, 0, 0, .25);
            backdrop-filter: blur(8px);
            text-shadow: 0 1px 2px rgba(0, 0, 0, .55);
        }

        /* 4) OUTLINE（背景が派手な見出しでも潰れない） */
        .rank-badge.-outline {
            background: transparent;
            color: #fff;
            border: 2px solid currentColor;
            text-shadow:
                -1px -1px 0 rgba(0, 0, 0, .35),
                1px -1px 0 rgba(0, 0, 0, .35),
                -1px 1px 0 rgba(0, 0, 0, .35),
                1px 1px 0 rgba(0, 0, 0, .35);
            box-shadow: none;
        }

        /* 5) RIBBON（リボン風。横長見出しに合う） */
        .rank-badge.-ribbon {
            position: relative;
            background: linear-gradient(180deg, #ffb703, #ff8f00);
            color: #2a1400;
            border: 1px solid rgba(255, 230, 160, .9);
            box-shadow: 0 3px 10px rgba(0, 0, 0, .25);
        }

        .rank-badge.-ribbon::after {
            content: "";
            position: absolute;
            right: -.55em;
            top: 50%;
            transform: translateY(-50%);
            border: .5em solid transparent;
            border-left-color: #cc7300;
            /* 折返し色 */
        }

        /* 6) METAL（メタルだが暗部を抑え、明部多め） */
        .rank-badge.-metal {
            background:
                linear-gradient(180deg, #fff7c8 0%, #ffe37a 42%, #ffc44a 60%, #ffdd8a 100%);
            color: #3b2900;
            border: 1px solid #fff1b0;
            box-shadow:
                0 2px 12px rgba(255, 200, 80, .35),
                inset 0 1px 0 rgba(255, 255, 255, .85);
            text-shadow: 0 1px 0 rgba(255, 255, 255, .4);
        }

        /* 小さめ画面でのタップしやすさ */
        @media (max-width:640px) {
            .rank-badge {
                padding: .24em .72em;
                font-size: 1em
            }
        }

        /* 任意：ダークモードで自動差し替え（flat→neonへ） */
        @media (prefers-color-scheme: dark) {
            .rank-badge.-flat {
                background: #00e5ff;
                color: #04161b;
                border-color: rgba(200, 255, 255, .8);
                box-shadow: 0 0 14px rgba(0, 229, 255, .35);
            }
        }


        @keyframes popIn {
            0% {
                transform: scale(.92);
                opacity: 0
            }

            60% {
                transform: scale(1.04);
                opacity: 1
            }

            100% {
                transform: scale(1)
            }
        }

        .score-head {
            animation: popIn .5s ease-out
        }
    </style>

</head>

<body id="blackBody">
    <div id="topBar">
        <h1 class="score-head">
            結果発表！
            <?php if ($myRow): ?>
                <span class="rank-badge -glass">成績：<?= (int)$myRank ?>位</span>
            <?php endif; ?>
        </h1>
    </div>

    <div id="maincontents">
        <?php if ($__noticeMessage): ?>
            <style>
                .notice-banner {
                    margin: 12px 0;
                    padding: 10px 12px;
                    font-size: 1.8rem;
                    font-weight: 600;
                    line-height: 1.6;
                    color: #fc693cff;
                }

                .notice-banner .fa,
                .notice-banner .fas,
                .notice-banner .far {
                    margin-right: 8px;
                }
            </style>
            <div class="notice-banner">
                <i class="fas fa-flag-checkered" aria-hidden="true"></i>
                <?= htmlspecialchars($__noticeMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <!-- リーダーボード -->
        <div class="card">
            <h2>リーダーボード</h2>
            <table>
                <thead>
                    <tr>
                        <th>順位</th>
                        <th>名前</th>
                        <th>正解</th>
                        <th>早押</th>
                        <th>早押合計</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rank)): ?>
                        <tr>
                            <td colspan="5" class="muted">まだ解答がありません</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rank as $i => $row): ?>
                            <tr class="<?= $row['uid'] === $selfUid ? 'uid' : '' ?>"> <!-- ← $uid → $selfUid -->
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$row['correct'] ?></td>
                                <td><?= (int)$row['first'] ?></td>
                                <td><?= (int)$row['pos_sum'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 回答マトリクス -->
        <div class="card">
            <h2>回答マトリクス</h2>
            <table>
                <thead>
                    <tr>
                        <th>参加者</th>
                        <?php for ($b = 1; $b <= count($questions); $b++): ?>
                            <th>Q<?= $b ?></th>
                        <?php endfor; ?>
                        <th>合計</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rank as $row): $rowUid = (int)$row['uid']; ?>
                        <tr class="<?= ($rowUid === $selfUid) ? 'uid' : '' ?>"> <!-- ← $uid → $selfUid -->
                            <td style="text-align:left;"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <?php for ($b = 1; $b <= count($questions); $b++): ?>
                                <?php
                                $cell = $matrix[$rowUid][$b] ?? null;
                                if (!$cell) {
                                    $txt = '–';
                                    $cls = 'muted';
                                } else {
                                    $txt = ($cell['ok'] ? '<i class="far fa-circle" aria-label="正解"></i>' : '<i class="fas fa-times" aria-label="不正解"></i>') . " （{$cell['pos']}）";
                                    $cls = $cell['ok'] ? 'ok' : 'ng';
                                }
                                ?>
                                <td class="<?= $cls ?>"><?= $txt ?></td>
                            <?php endfor; ?>
                            <td><?= (int)$row['correct'] ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($rank)): ?>
                        <tr>
                            <td colspan="<?= 2 + count($questions) ?>" class="muted">参加者の解答がありません</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($__noticeMessage === null): ?>
            <div style="text-align:center; margin-bottom: 40px;">
                <a class="btn" href="rematch.php?gid=<?= (int)$gid ?>&prev=<?= (int)$bid ?>">
                    もう1回！
                </a>
            </div>
        <?php endif; ?>

        <!-- ★ 問題の評価一覧（正解付き） -->
        <div class="card">
            <h2>問題の評価一覧（このバトルで出題された3問）</h2>
            <div id="rateList">
                <?php foreach ($questions as $idx => $q): ?>
                    <?php
                    // 正解テキストを決定
                    $ansLabel = (string)($q['kaito'] ?? '');
                    switch ($ansLabel) {
                        case 'A':
                            $ansText = $q['qa'] ?? '';
                            break;
                        case 'B':
                            $ansText = $q['qb'] ?? '';
                            break;
                        case 'C':
                            $ansText = $q['qc'] ?? '';
                            break;
                        case 'D':
                            $ansText = $q['qd'] ?? '';
                            break;
                        default:
                            $ansText = '';
                            break;
                    }
                    ?>
                    <div class="rate-row"
                        data-cate1="<?= (int)$q['cate1'] ?>"
                        data-cate2="<?= (int)$q['cate2'] ?>"
                        data-id="<?= (int)$q['id'] ?>"
                        data-num="<?= (int)$q['num'] ?>">
                        <div class="rate-idx">Q<?= (int)$q['bnum'] ?></div>
                        <div class="rate-q">
                            <?= htmlspecialchars($q['mondai'], ENT_QUOTES, 'UTF-8') ?>
                            <div class="rate-ans">
                                正解：
                                <span class="pill"><?= htmlspecialchars($ansLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <?= htmlspecialchars($ansText, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <div class="rate-actions">
                            <button class="vote-btn vote-good" type="button" aria-label="Good">
                                <i class="fas fa-thumbs-up"></i> Good
                            </button>
                            <button class="vote-btn vote-bad" type="button" aria-label="Bad">
                                <i class="fas fa-thumbs-down"></i> Bad
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="rate-note">
                ※ 評価を押して頂けると問題の調査を行います。
            </p>
        </div>
    </div>

    <script>
        /* ★ 追加: Good/Bad 投票 */
        (function() {
            const container = document.getElementById('rateList');
            if (!container) return;

            container.addEventListener('click', async (ev) => {
                const btn = ev.target.closest('.vote-btn');
                if (!btn) return;

                const row = ev.target.closest('.rate-row');
                if (!row) return;

                const action = btn.classList.contains('vote-good') ? 'good' : 'bad';
                const cate1 = parseInt(row.dataset.cate1, 10);
                const cate2 = parseInt(row.dataset.cate2, 10);
                const id = parseInt(row.dataset.id, 10);
                const num = parseInt(row.dataset.num, 10);

                // 二度押し防止：同一行のボタンを暫定で無効化
                const allBtns = row.querySelectorAll('.vote-btn');
                allBtns.forEach(b => b.disabled = true);

                try {
                    const res = await fetch('api/minaosi_vote.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            cate1,
                            cate2,
                            id,
                            num,
                            action
                        })
                    });

                    const js = await res.json().catch(() => ({}));
                    if (!res.ok || !js.ok) {
                        throw new Error(js.error || '投票に失敗しました');
                    }

                    // 成功UI：押した側を強調、反対側は薄く
                    btn.textContent = (action === 'good') ? '記録：いいね' : '記録：よくない';
                } catch (e) {
                    alert('送信に失敗しました。回線状況をご確認ください。');
                    // 失敗時は再度押せるように戻す
                    allBtns.forEach(b => b.disabled = false);
                }
            });
        })();
    </script>

</body>

</html>
