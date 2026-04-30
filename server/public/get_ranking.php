<?php
require_once __DIR__ . '/api_bootstrap.php';  // ★ 追加：一番最初に

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/logs/logger.php';
require_once __DIR__ . '/common/define.php';
require_once __DIR__ . '/common/battle_common.php'; // dbConnectPDO() は上のconfigで繋がる前提

// $log = Logger::getInstance();
// $log->debug('** get_ranking.php start');

try {
        $pdo = dbConnectPDO();
        if (!$pdo) {
                api_json_send(['winners' => [], 'count' => 0]);
        }

        // GET パラメータ
        $bid  = isset($_GET['bid'])   ? (int)$_GET['bid']   : (int)($_SESSION['bid'] ?? 0);
        $gid  = isset($_GET['gid'])   ? (int)$_GET['gid']   : (int)($_SESSION['gid'] ?? 0);
        $c1   = isset($_GET['cate1']) ? (int)$_GET['cate1'] : 0;
        $c2   = isset($_GET['cate2']) ? (int)$_GET['cate2'] : 0;
        $qid  = isset($_GET['id'])    ? (int)$_GET['id']    : 0;
        $qnum = isset($_GET['num'])   ? (int)$_GET['num']   : 0;
        $bnum = isset($_GET['bnum'])  ? (int)$_GET['bnum']  : 0;

        if ($bid <= 0 || $gid <= 0) {
                api_json_send(['winners' => [], 'count' => 0]);
        }

        // bnum が来ている場合は、現在問キーを bnum から解決して上書き
        if ($bnum > 0) {
                $q = $pdo->prepare("
            SELECT cate1, cate2, id, num
              FROM qb_battle_lineup
             WHERE bid = :bid AND order_no = :bnum
             LIMIT 1
        ");
                $q->execute([':bid' => $bid, ':bnum' => $bnum]);
                if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                        $c1   = (int)$row['cate1'];
                        $c2   = (int)$row['cate2'];
                        $qid  = (int)$row['id'];
                        $qnum = (int)$row['num'];
                }
        }

        // 集計キーが揃っていなければ 0 人
        if ($c1 === 0 || $c2 === 0 || $qid === 0 || $qnum === 0) {
                api_json_send(['winners' => [], 'count' => 0]);
        }

        // 該当問題の解答者を時系列で取得（同時刻は seq で安定化できるなら付与）
        $sql = "
        SELECT b.uid, b.sentaku, b.buzzed_at
          FROM qb_buzzes b
         WHERE b.bid = :bid AND b.gid = :gid
           AND b.cate1 = :c1 AND b.cate2 = :c2
           AND b.id = :qid AND b.num = :qnum
         ORDER BY b.buzzed_at ASC
    ";
        $st = $pdo->prepare($sql);
        $st->execute([
                ':bid' => $bid,
                ':gid' => $gid,
                ':c1' => $c1,
                ':c2' => $c2,
                ':qid' => $qid,
                ':qnum' => $qnum
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        api_json_send([
                'winners' => $rows,
                'count'   => count($rows)
        ]);
} catch (Throwable $e) {
        api_json_send(['winners' => [], 'count' => 0]);
}
