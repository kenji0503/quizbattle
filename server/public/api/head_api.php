<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../common/battle_common.php';
require_once __DIR__ . '/../common/question_repository.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = dbConnectPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $mode = (string)($_GET['mode'] ?? '');
    $error = null;

    if ($mode === 'c1') {
        $sql = "
            SELECT cate1, title
              FROM qb_question_category
             WHERE cate2 = 0 AND qid = 0 AND del = 0
             ORDER BY cate1
        ";
        $list = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'c2') {
        $c1 = filter_input(INPUT_GET, 'cate1', FILTER_VALIDATE_INT);
        if (!$c1) {
            echo json_encode(['ok' => false, 'msg' => 'bad cate1'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $st = $pdo->prepare("
            SELECT cate2, title
              FROM qb_question_category
             WHERE cate1 = :c1 AND cate2 > 0 AND qid = 0 AND del = 0
             ORDER BY cate2
        ");
        $st->execute([':c1' => $c1]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'ids') {
        $c1 = filter_input(INPUT_GET, 'cate1', FILTER_VALIDATE_INT);
        $c2 = filter_input(INPUT_GET, 'cate2', FILTER_VALIDATE_INT);
        if (!$c1 || !$c2) {
            echo json_encode(['ok' => false, 'msg' => 'bad cate1/cate2'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $st = $pdo->prepare("
            SELECT qid AS id, title
              FROM qb_question_category
             WHERE cate1 = :c1 AND cate2 = :c2 AND qid > 0 AND del = 0
             ORDER BY qid
        ");
        $st->execute([':c1' => $c1, ':c2' => $c2]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'bad mode'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'server error'], JSON_UNESCAPED_UNICODE);
}
