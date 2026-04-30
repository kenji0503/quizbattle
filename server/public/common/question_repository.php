<?php

function question_category_api_base(): string
{
    if (defined('QUESTION_CATEGORY_API_BASE')) {
        return QUESTION_CATEGORY_API_BASE;
    }
    return getenv('QB_CATEGORY_API_BASE') ?: 'https://boy-503.ssl-lolipop.jp/quiz/mente/api/category.php';
}

function question_mondai_api_base(): string
{
    if (defined('QUESTION_MONDAI_API_BASE')) {
        return QUESTION_MONDAI_API_BASE;
    }
    return getenv('QB_MONDAI_API_BASE') ?: 'https://boy-503.ssl-lolipop.jp/quiz/mente/api/mondai.php';
}

function question_build_url(string $base, array $params): string
{
    $query = http_build_query($params);
    if ($query === '') {
        return $base;
    }
    return $base . (str_contains($base, '?') ? '&' : '?') . $query;
}

function question_fetch_json(string $url, ?string &$error = null): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $httpCode >= 400) {
            $error = "API fetch failed: code={$httpCode} {$curlErr}";
            return null;
        }
    }

    if ($raw === false) {
        $last = error_get_last();
        $error = 'API fetch failed: ' . ($last['message'] ?? 'unknown error');
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $error = 'API response is not valid JSON.';
        return null;
    }

    return $json;
}

function question_extract_rows(array $json): array
{
    if (isset($json['data']) && is_array($json['data'])) {
        return $json['data'];
    }
    if (isset($json['list']) && is_array($json['list'])) {
        return $json['list'];
    }
    if (function_exists('array_is_list') ? array_is_list($json) : question_is_list_array($json)) {
        return $json;
    }
    return [];
}

function question_is_list_array(array $array): bool
{
    $expected = 0;
    foreach ($array as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function question_upsert_category_row(PDO $pdo, array $row): void
{
    $stmt = $pdo->prepare("
        INSERT INTO qb_question_category
            (cate1, cate2, qid, title, kaisetu, word, keyword, imageword, cnt, check_cnt, del, fetched_at)
        VALUES
            (:cate1, :cate2, :qid, :title, :kaisetu, :word, :keyword, :imageword, :cnt, :check_cnt, :del, NOW())
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            kaisetu = VALUES(kaisetu),
            word = VALUES(word),
            keyword = VALUES(keyword),
            imageword = VALUES(imageword),
            cnt = VALUES(cnt),
            check_cnt = VALUES(check_cnt),
            del = VALUES(del),
            fetched_at = VALUES(fetched_at)
    ");

    $stmt->execute([
        ':cate1' => (int)($row['cate1'] ?? 0),
        ':cate2' => (int)($row['cate2'] ?? 0),
        ':qid' => (int)($row['qid'] ?? $row['id'] ?? 0),
        ':title' => (string)($row['title'] ?? ''),
        ':kaisetu' => (string)($row['kaisetu'] ?? ''),
        ':word' => (string)($row['word'] ?? ''),
        ':keyword' => (string)($row['keyword'] ?? ''),
        ':imageword' => (string)($row['imageword'] ?? ''),
        ':cnt' => (int)($row['cnt'] ?? 0),
        ':check_cnt' => (int)($row['checkCnt'] ?? $row['check_cnt'] ?? 0),
        ':del' => (int)($row['del'] ?? 0),
    ]);
}

function question_sync_c1(PDO $pdo, ?string &$error = null): array
{
    $url = question_build_url(question_category_api_base(), ['mode' => 'c1']);
    $json = question_fetch_json($url, $error);
    if ($json === null) {
        return [];
    }

    $rows = [];
    foreach (question_extract_rows($json) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mapped = [
            'cate1' => (int)($row['cate1'] ?? 0),
            'cate2' => 0,
            'qid' => 0,
            'title' => (string)($row['title'] ?? ''),
            'kaisetu' => (string)($row['kaisetu'] ?? ''),
            'word' => (string)($row['word'] ?? ''),
            'keyword' => '',
            'imageword' => '',
            'cnt' => 0,
            'checkCnt' => 0,
            'del' => 0,
        ];
        if ($mapped['cate1'] <= 0) {
            continue;
        }
        question_upsert_category_row($pdo, $mapped);
        $rows[] = $mapped;
    }
    return $rows;
}

function question_sync_c2(PDO $pdo, int $cate1, ?string &$error = null): array
{
    $url = question_build_url(question_category_api_base(), ['mode' => 'c2', 'cate1' => $cate1]);
    $json = question_fetch_json($url, $error);
    if ($json === null) {
        return [];
    }

    $rows = [];
    foreach (question_extract_rows($json) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mapped = [
            'cate1' => $cate1,
            'cate2' => (int)($row['cate2'] ?? 0),
            'qid' => 0,
            'title' => (string)($row['title'] ?? ''),
            'kaisetu' => (string)($row['kaisetu'] ?? ''),
            'word' => (string)($row['word'] ?? ''),
            'keyword' => '',
            'imageword' => '',
            'cnt' => 0,
            'checkCnt' => 0,
            'del' => 0,
        ];
        if ($mapped['cate2'] <= 0) {
            continue;
        }
        question_upsert_category_row($pdo, $mapped);
        $rows[] = $mapped;
    }
    return $rows;
}

function question_sync_ids(PDO $pdo, int $cate1, int $cate2, ?string &$error = null): array
{
    $url = question_build_url(question_category_api_base(), [
        'mode' => 'ids',
        'cate1' => $cate1,
        'cate2' => $cate2,
    ]);
    $json = question_fetch_json($url, $error);
    if ($json === null) {
        return [];
    }

    $rows = [];
    foreach (question_extract_rows($json) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $mapped = [
            'cate1' => $cate1,
            'cate2' => $cate2,
            'qid' => (int)($row['id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'kaisetu' => (string)($row['kaisetu'] ?? ''),
            'word' => (string)($row['word'] ?? ''),
            'keyword' => '',
            'imageword' => '',
            'cnt' => 0,
            'checkCnt' => 0,
            'del' => 0,
        ];
        if ($mapped['qid'] <= 0) {
            continue;
        }
        question_upsert_category_row($pdo, $mapped);
        $rows[] = $mapped;
    }
    return $rows;
}

function question_sync_catalog(PDO $pdo, ?string &$error = null): array
{
    $summary = [
        'cate1_count' => 0,
        'cate2_count' => 0,
        'theme_count' => 0,
    ];

    $c1Rows = question_sync_c1($pdo, $error);
    if ($error !== null && $error !== '') {
        return $summary;
    }
    $summary['cate1_count'] = count($c1Rows);

    foreach ($c1Rows as $c1Row) {
        $cate1 = (int)($c1Row['cate1'] ?? 0);
        if ($cate1 <= 0) {
            continue;
        }

        $c2Rows = question_sync_c2($pdo, $cate1, $error);
        if ($error !== null && $error !== '') {
            return $summary;
        }
        $summary['cate2_count'] += count($c2Rows);

        foreach ($c2Rows as $c2Row) {
            $cate2 = (int)($c2Row['cate2'] ?? 0);
            if ($cate2 <= 0) {
                continue;
            }

            $idRows = question_sync_ids($pdo, $cate1, $cate2, $error);
            if ($error !== null && $error !== '') {
                return $summary;
            }
            $summary['theme_count'] += count($idRows);
        }
    }

    return $summary;
}

function question_catalog_stats(PDO $pdo): array
{
    $sql = "
        SELECT
            SUM(CASE WHEN cate2 = 0 AND qid = 0 AND del = 0 THEN 1 ELSE 0 END) AS cate1_count,
            SUM(CASE WHEN cate2 > 0 AND qid = 0 AND del = 0 THEN 1 ELSE 0 END) AS cate2_count,
            SUM(CASE WHEN cate2 > 0 AND qid > 0 AND del = 0 THEN 1 ELSE 0 END) AS theme_count,
            MAX(fetched_at) AS last_fetched_at
        FROM qb_question_category
    ";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'cate1_count' => (int)($row['cate1_count'] ?? 0),
        'cate2_count' => (int)($row['cate2_count'] ?? 0),
        'theme_count' => (int)($row['theme_count'] ?? 0),
        'last_fetched_at' => (string)($row['last_fetched_at'] ?? ''),
    ];
}

function question_ensure_category_path(PDO $pdo, int $cate1, int $cate2, int $qid, ?string &$error = null): bool
{
    if ($cate1 <= 0) {
        $error = 'cate1 is required.';
        return false;
    }

    $exists = $pdo->prepare("
        SELECT 1
          FROM qb_question_category
         WHERE cate1 = ? AND cate2 = ? AND qid = ?
         LIMIT 1
    ");
    $exists->execute([$cate1, $cate2, $qid]);
    if ($exists->fetchColumn()) {
        return true;
    }

    question_sync_c1($pdo, $error);
    if ($error !== null && $error !== '') {
        return false;
    }
    if ($cate2 > 0) {
        question_sync_c2($pdo, $cate1, $error);
        if ($error !== null && $error !== '') {
            return false;
        }
    }
    if ($cate2 > 0 && $qid > 0) {
        question_sync_ids($pdo, $cate1, $cate2, $error);
        if ($error !== null && $error !== '') {
            return false;
        }
    }

    $exists->execute([$cate1, $cate2, $qid]);
    return (bool)$exists->fetchColumn();
}

function question_sync_set(PDO $pdo, int $cate1, int $cate2, int $qid, ?string &$error = null): int
{
    $url = question_build_url(question_mondai_api_base(), [
        'mode' => 'list',
        'cate1' => $cate1,
        'cate2' => $cate2,
        'id' => $qid,
    ]);
    $json = question_fetch_json($url, $error);
    if ($json === null) {
        return 0;
    }

    $rows = question_extract_rows($json);
    if (empty($rows)) {
        $error = 'Question API returned no rows.';
        return 0;
    }

    $stmt = $pdo->prepare("
        INSERT INTO qb_question_bank
            (cate1, cate2, qid, qnum, mondai, qa, qb, qc, qd, kaito, kaisetu, source_url, level, note, goodcnt, trycnt, del, source_created_at, source_updated_at, fetched_at)
        VALUES
            (:cate1, :cate2, :qid, :qnum, :mondai, :qa, :qb, :qc, :qd, :kaito, :kaisetu, :source_url, :level, :note, :goodcnt, :trycnt, :del, :source_created_at, :source_updated_at, NOW())
        ON DUPLICATE KEY UPDATE
            mondai = VALUES(mondai),
            qa = VALUES(qa),
            qb = VALUES(qb),
            qc = VALUES(qc),
            qd = VALUES(qd),
            kaito = VALUES(kaito),
            kaisetu = VALUES(kaisetu),
            source_url = VALUES(source_url),
            level = VALUES(level),
            note = VALUES(note),
            goodcnt = VALUES(goodcnt),
            trycnt = VALUES(trycnt),
            del = VALUES(del),
            source_created_at = VALUES(source_created_at),
            source_updated_at = VALUES(source_updated_at),
            fetched_at = VALUES(fetched_at)
    ");

    $saved = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $stmt->execute([
            ':cate1' => (int)($row['cate1'] ?? $cate1),
            ':cate2' => (int)($row['cate2'] ?? $cate2),
            ':qid' => (int)($row['id'] ?? $qid),
            ':qnum' => (int)($row['num'] ?? 0),
            ':mondai' => (string)($row['mondai'] ?? ''),
            ':qa' => (string)($row['qa'] ?? ''),
            ':qb' => (string)($row['qb'] ?? ''),
            ':qc' => (string)($row['qc'] ?? ''),
            ':qd' => (string)($row['qd'] ?? ''),
            ':kaito' => (string)($row['kaito'] ?? ''),
            ':kaisetu' => (string)($row['kaisetu'] ?? ''),
            ':source_url' => (string)($row['url'] ?? ''),
            ':level' => (int)($row['level'] ?? 1),
            ':note' => (string)($row['note'] ?? ''),
            ':goodcnt' => (int)($row['goodcnt'] ?? 0),
            ':trycnt' => (int)($row['trycnt'] ?? 0),
            ':del' => (int)($row['del'] ?? 0),
            ':source_created_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            ':source_updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
        ]);
        $saved++;
    }

    return $saved;
}

function question_ensure_set_cached(PDO $pdo, int $cate1, int $cate2, int $qid, ?string &$error = null): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
          FROM qb_question_bank
         WHERE cate1 = ? AND cate2 = ? AND qid = ? AND del = 0
    ");
    $stmt->execute([$cate1, $cate2, $qid]);
    $count = (int)$stmt->fetchColumn();
    if ($count > 0) {
        return $count;
    }

    return question_sync_set($pdo, $cate1, $cate2, $qid, $error);
}

function question_get_title_info(PDO $pdo, int $cate1, int $cate2, int $qid): array
{
    $info = ['genre' => '', 'title' => ''];

    $stmt = $pdo->prepare("
        SELECT cate2, qid, title
          FROM qb_question_category
         WHERE cate1 = ?
           AND ((cate2 = 0 AND qid = 0) OR (cate2 = ? AND qid = ?))
    ");
    $stmt->execute([$cate1, $cate2, $qid]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ((int)$row['cate2'] === 0 && (int)$row['qid'] === 0) {
            $info['genre'] = (string)$row['title'];
        }
        if ((int)$row['cate2'] === $cate2 && (int)$row['qid'] === $qid) {
            $info['title'] = (string)$row['title'];
        }
    }

    return $info;
}
