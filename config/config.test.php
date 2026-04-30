<?php
define('BASE_URL', function_exists('envValueAny')
    ? envValueAny(['QB_BASE_URL', 'BASE_URL'], 'https://battle-test.quizbattle.jp/')
    : ((getenv('QB_BASE_URL') ?: getenv('BASE_URL')) ?: 'https://battle-test.quizbattle.jp/'));
define('APP_ENV', 'test');
define('DB_DSN', function_exists('envValueAny')
    ? envValueAny(['QB_DB_DSN', 'DB_DSN'], buildDbDsnFromParts() ?: 'mysql:host=127.0.0.1;dbname=battle_test;charset=utf8mb4')
    : ((getenv('QB_DB_DSN') ?: getenv('DB_DSN')) ?: 'mysql:host=127.0.0.1;dbname=battle_test;charset=utf8mb4'));
define('DB_USER', function_exists('envValueAny')
    ? envValueAny(['QB_DB_USER', 'DB_USER'], 'root')
    : ((getenv('QB_DB_USER') ?: getenv('DB_USER')) ?: 'root'));
define('DB_PASS', function_exists('envValueAny')
    ? envValueAny(['QB_DB_PASS', 'DB_PASS'], '')
    : ((getenv('QB_DB_PASS') ?: getenv('DB_PASS')) ?: ''));
define('QUESTION_CATEGORY_API_BASE', function_exists('envValueAny')
    ? envValueAny(['QB_CATEGORY_API_BASE', 'QUESTION_CATEGORY_API_BASE'], 'https://boy-503.ssl-lolipop.jp/quiz/mente/api/category.php')
    : ((getenv('QB_CATEGORY_API_BASE') ?: getenv('QUESTION_CATEGORY_API_BASE')) ?: 'https://boy-503.ssl-lolipop.jp/quiz/mente/api/category.php'));
define('QUESTION_MONDAI_API_BASE', function_exists('envValueAny')
    ? envValueAny(['QB_MONDAI_API_BASE', 'QUESTION_MONDAI_API_BASE'], 'https://boy-503.ssl-lolipop.jp/quiz/mente/api/mondai.php')
    : ((getenv('QB_MONDAI_API_BASE') ?: getenv('QUESTION_MONDAI_API_BASE')) ?: 'https://boy-503.ssl-lolipop.jp/quiz/mente/api/mondai.php'));
