<?php
// バトル進行フェーズ（0起点）
define('BATTLE_PHASE_WAIT',      0); // 予約済み／開始待ち（全員カウントダウン）
define('BATTLE_PHASE_QUESTION',  1); // 出題中
define('BATTLE_PHASE_ANSWER',    2); // 正解表示中
define('BATTLE_PHASE_FINISHED',  3); // 終了

// 進行時間（秒）：start.php / get_question.php で共通利用
define('BATTLE_QUESTION_SEC', 10);
define('BATTLE_ANSWER_SEC',    3);

// ===== ユーザー属性（一覧フィルタ用）=====
define('ROLE_TEMP',     1); // 即席参加
define('STATUS_ACTIVE', 1); // 有効