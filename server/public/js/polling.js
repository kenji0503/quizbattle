document.addEventListener("DOMContentLoaded", () => {
  const battleId = window.__BATTLE_ID__; // bid
  const groupId = window.__GROUP_ID__; // gid
  const userId = window.__USER_ID__; // uid

  // サーバと同じ値（define.php と一致）
  const PHASE_WAIT = 0; // 予約中/待機
  const PHASE_QUESTION = 1; // 出題中
  const PHASE_ANSWER = 2; // 正解表示中
  const PHASE_FINISHED = 3; // 終了

  // 現在の出題状態
  let currentBnum = null; // 1..3
  let lastTs = 0; // 出題の更新判定用 ts
  let currentBid = battleId;
  let currentKey = null; // {cate1,cate2,id,num}
  let currentAnswer = null; // 'A'..'D'
  let retryCount = 0;
  let sentLock = false;

  // 500ミリ秒ポーリングで出題取得
  const questionIntervalId = setInterval(fetchAndRenderQuestion, 500);

  async function fetchAndRenderQuestion() {
    try {
      const qs = new URLSearchParams({
        bid: String(currentBid || battleId),
        gid: String(groupId),
      });
      const res = await fetch(`./get_question.php?${qs.toString()}`, {
        cache: "no-store",
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const text = await res.text();

      let q;
      try {
        q = JSON.parse(text);
      } catch (e) {
        console.error("JSONパースエラー:", e, text);
        document.getElementById("question").textContent =
          "問題の取得に失敗しました。（形式エラー）";
        return;
      }

      // 普段はコメントアウト
      // console.log('[QUESTION] payload', q);
      // console.log('[QUESTION] display=', q.display || '');

      retryCount = 0;

      if (q.error) {
        const msg = String(q.message || q.error || "")
          .trim()
          .toLowerCase();

        // 出題前：案内表示にする（エラー扱いにしない）
        if (msg.includes("no current question") || msg.includes("not ready")) {
          showReadyMessage();
          return;
        }

        // 他端末ログイン（運用に合わせてURL調整）
        if (msg === "他の端末でログインされています") {
          alert("他の端末でログイン中です。");
          clearInterval(questionIntervalId);
          setTimeout(() => {
            location.href = "user/logout.php";
          }, 300);
          return;
        }

        console.error("取得エラー:", msg);
        document.getElementById("question").textContent = "取得エラー: " + msg;
        return;
      }

      if (q.ts !== lastTs) {
        sentLock = false; // 次の問題では送信できるように解除
        setChoicesEnabled(true); // 念のためボタン有効化

        lastTs = q.ts;
        currentBnum = q.bnum;
        currentBid = q.bid;
        currentKey = { cate1: q.cate1, cate2: q.cate2, id: q.id, num: q.num };
        currentAnswer = q.kaito ? String(q.kaito).toUpperCase() : null;

        // 画面反映
        const hl = document.getElementById("q-headline");
        if (hl) hl.textContent = q.display || "";
        document.getElementById("message-area").textContent = "";
        document.getElementById("question").textContent = q.mondai || "";

        clearSelection();
        renderChoice("sentakuA", "A", q.qa);
        renderChoice("sentakuB", "B", q.qb);
        renderChoice("sentakuC", "C", q.qc);
        renderChoice("sentakuD", "D", q.qd);

        if (q.show_answer == 1 && currentAnswer) {
          setTimeout(() => highlightCorrectAnswer(currentAnswer), 100);
        }
      } else {
        // 出題は同じだが、正解表示フラグだけ後追いで立った場合
        if (q.kaito) {
          currentAnswer = String(q.kaito).toUpperCase();
        }
        if (q.show_answer == 1 && currentAnswer) {
          highlightCorrectAnswer(currentAnswer);
        }
      }

      // 自分の回答表示を復元（bid+bnum単位でキー化）
      const key = `answer_${currentBid}_${currentBnum}`;
      const stored = sessionStorage.getItem(key);
      if (stored) {
        const { pos, sel, at } = JSON.parse(stored);
        showRankingButton();
        markSelected(sel);
      }

      // 解答者数の更新
      updateAnswerCount();

      // フェーズボタンはバトル状態ポーリング側で描画する
      // （q には十分なフェーズ情報が載らないため）
    } catch (error) {
      console.warn(`問題取得失敗（リトライ${retryCount}回目）:`, error);
      if (retryCount < 3) {
        retryCount++;
        setTimeout(fetchAndRenderQuestion, 1000);
      } else {
        document.getElementById("question").textContent =
          "再試行にも失敗しました。接続状況をご確認ください。";
      }
    }
  }

  function showReadyMessage() {
    document.getElementById("message-area").textContent = "";
    document.getElementById("question").textContent =
      "これから問題を出題します！";

    sentLock = false; // ★ 念のため解除
    setChoicesEnabled(false); // ★ まだ回答できないので無効化

    ["A", "B", "C", "D"].forEach((label) => {
      const div = document.getElementById(`sentaku${label}`);
      if (div) div.innerHTML = "";
    });
    const btn = document.getElementById("go-to-ranking");
    if (btn) btn.style.display = "none";
    const cnt = document.getElementById("answer-count");
    if (cnt) cnt.textContent = "";

    currentBnum = null;
    currentAnswer = null;
    currentKey = null;
  }

  function renderChoice(containerId, label, text) {
    const div = document.getElementById(containerId);
    div.innerHTML = "";

    const btn = document.createElement("button");
    btn.className = "choice-button";
    btn.onclick = () => checkAnswer(label);

    const span = document.createElement("span");
    span.className = `choice-label label-${label}`;
    span.textContent = label;

    btn.appendChild(span);
    btn.appendChild(document.createTextNode(text ?? ""));
    div.appendChild(btn);
  }

  function checkAnswer(selected) {
    if (sentLock) return;

    // 前提チェック（不足なら何もしない）
    if (!currentKey || !currentBid || !groupId) {
      showMessage("現在の問題情報が不足しています。");
      return;
    }

    // 2重送信防止ロック
    sentLock = true;

    // UI：自分の選択を先に強調
    markSelected(selected);

    // サーバーへ送信（qb_buzzes に INSERT）
    const params = new URLSearchParams({
      bid: String(currentBid),
      gid: String(groupId),
      cate1: String(currentKey.cate1),
      cate2: String(currentKey.cate2),
      id: String(currentKey.id),
      num: String(currentKey.num),
      uid: String(userId), // サーバ側はセッション優先だが同送してもOK
      selected: selected,
    });

    // 送信中は押せないように
    document
      .querySelectorAll(".choice-button")
      .forEach((btn) => (btn.disabled = true));

    fetch("./submit_buzz.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString(),
    })
      .then(async (res) => {
        const text = await res.text();
        let data = null;
        try {
          data = JSON.parse(text);
        } catch (e) {
          console.error("non-JSON from submit_buzz:", text);
          showMessage("サーバ応答の形式エラーです。");
          // リトライできるようにボタンを再有効化
          document
            .querySelectorAll(".choice-button")
            .forEach((btn) => (btn.disabled = false));
          sentLock = false;
          return null;
        }
        return data;
      })
      .then((data) => {
        if (!data) return; // 形式エラー

        if (data.error) {
          // サーバ側定義のエラー（セッション不正など）
          showMessage(data.error);
          // リトライ可能に戻す（同一問題での再送を許す）
          document
            .querySelectorAll(".choice-button")
            .forEach((btn) => (btn.disabled = false));
          sentLock = false;
          return;
        }

        if (data.duplicate) {
          // 既に回答済み：エラー扱いにせず通知のみ
          showMessage("この問題は既に解答済みです。");
        }

        const pos = data.position ?? "？";
        const sel = data.selected ?? selected;
        const at = data.answered_at || new Date().toLocaleTimeString();

        // bid+bnumで保存（出題単位）
        sessionStorage.setItem(
          `answer_${currentBid}_${currentBnum}`,
          JSON.stringify({ pos, sel, at })
        );

        showRankingButton();
        updateAnswerCount(); // ついでに解答者数を更新

        // 成功時はボタンはそのまま無効（再回答不可）
        // ロックも維持しておく
      })
      .catch((err) => {
        console.error("submit_buzz error:", err);
        showMessage(
          "通信エラーが発生しました。ページを更新して再度お試しください。"
        );
        // 通信自体が失敗した場合は再トライできるよう戻す
        document
          .querySelectorAll(".choice-button")
          .forEach((btn) => (btn.disabled = false));
        sentLock = false;
      });
  }

  // 小さなヘルパ
  function $(id) {
    return document.getElementById(id);
  }

  // 置き換え
  function showRankingButton() {
    const btn = $("go-to-ranking");
    if (!btn) return; // ← ボタンが無い環境でも例外にしない
    btn.style.display = "inline-block";
    btn.onclick = () => {
      if (!currentKey) return;
      const q = new URLSearchParams({
        bid: currentBid,
        gid: groupId,
        cate1: currentKey.cate1,
        cate2: currentKey.cate2,
        id: currentKey.id,
        num: currentKey.num,
      });
      window.location.href = `ranking.php?${q.toString()}`;
    };
  }

  function showMessage(msg) {
    const el = $("message-area");
    if (!el) {
      console.warn("message-area が見つかりません:", msg);
      return;
    }
    el.textContent = msg;
  }

  function updateAnswerCount() {
    if (!currentKey) return;
    const qs = new URLSearchParams({
      bid: currentBid,
      gid: groupId,
      cate1: String(currentKey.cate1),
      cate2: String(currentKey.cate2),
      id: String(currentKey.id),
      num: String(currentKey.num),
    });

    fetch(`./get_ranking.php?${qs.toString()}`, { cache: "no-store" })
      .then((res) => {
        if (!res.ok) throw new Error(res.status);
        return res.json();
      })
      .then((payload) => {
        // サーバが count を返す場合にも対応
        const winners = Array.isArray(payload)
          ? payload
          : Array.isArray(payload.winners)
          ? payload.winners
          : [];
        const count = Number.isInteger(payload?.count)
          ? payload.count
          : winners.length;
        const label = $("answer-count");
        if (label) label.textContent = `現在の解答者数: ${count}人`;
      })
      .catch((err) => console.warn("解答者数取得失敗:", err));
  }

  // ここからフェーズジャンプUIの修正（置き換え）
  function renderBattleControls(state) {
    const box = document.getElementById("battleControls");
    if (!box) return;
    box.innerHTML = "";

    // --- フォールバック ---
    // 1) phase_hint が無ければ、数値 phase から導出
    let phaseHint = state?.phase_hint || "";
    if (!phaseHint && Number.isFinite(state?.phase)) {
      const p = Number(state.phase);
      phaseHint =
        p <= 0
          ? "waiting"
          : p === 1
          ? "answering"
          : p === 2
          ? "reveal"
          : "finished";
    }

    // 2) is_last が無ければ、max_order と bnum から導出
    let isLast = Number(state?.is_last || 0);
    if (
      !state?.is_last &&
      Number.isFinite(state?.max_order) &&
      Number.isFinite(state?.bnum)
    ) {
      const maxOrder = Number(state.max_order);
      const bnum = Number(state.bnum);
      if (maxOrder > 0 && bnum >= maxOrder) isLast = 1;
    }

    // 3) all_answered は無ければ 0 扱い（「解答表示」だけに影響）
    const allAnswered = Number(state?.all_answered || 0);

    // answering かつ 全員回答済み → 「解答表示」
    if (phaseHint === "answering" && allAnswered === 1) {
      const btn = document.createElement("button");
      btn.textContent = "解答表示";
      btn.type = "button";
      btn.onclick = () => doPhaseJump("reveal");
      box.appendChild(btn);
    }

    // reveal → 「次の問題」または「結果へ」
    if (phaseHint === "reveal") {
      const btn = document.createElement("button");
      btn.textContent = isLast === 1 ? "結果へ" : "次の問題";
      btn.type = "button";
      btn.onclick = () => doPhaseJump("next");
      box.appendChild(btn);
    }
  }

  let _jumping = false;
  async function doPhaseJump(action) {
    if (_jumping) return; // 連打ガード
    _jumping = true;
    // 仮置きでボタンを無効化
    const box = document.getElementById("battleControls");
    box?.querySelectorAll("button")?.forEach((b) => (b.disabled = true));

    try {
      const form = new URLSearchParams();
      form.set("gid", String(groupId));
      form.set("bid", String(battleId));
      form.set("action", action);

      const res = await fetch("phase_jump.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: form.toString(),
        credentials: "same-origin",
        cache: "no-store",
      });

      // 409 = 条件未達（例: リビール前）などを文言で表示
      if (!res.ok) {
        let msg = `HTTP ${res.status}`;
        try {
          const j = await res.json();
          if (j?.msg) msg = j.msg;
        } catch {}
        alert("操作に失敗: " + msg);
        return;
      }

      // JSONの ok=false も拾う
      const j = await res.json().catch(() => ({}));
      if (j && j.ok === false) {
        alert("操作に失敗: " + (j.msg || ""));
        return;
      }
      // 成功: 以後はポーリングがフェーズ変化を検知して遷移
    } catch (e) {
      alert("通信エラー: " + (e?.message || e));
    } finally {
      // ほんの少し待ってから解除（ダブルポスト防止）
      setTimeout(() => {
        _jumping = false;
        box?.querySelectorAll("button")?.forEach((b) => (b.disabled = false));
      }, 600);
    }
  }

  function highlightCorrectAnswer(kaito) {
    const target = document.querySelector(`.label-${kaito}`);
    if (target && target.parentElement) {
      target.parentElement.style.backgroundColor = "#00C853";
      target.parentElement.style.color = "#fff";
      target.parentElement.style.fontWeight = "bold";
    }
  }

  function markSelected(label) {
    document
      .querySelectorAll(".choice-button")
      .forEach((b) => b.classList.remove("selected"));
    const el = document.querySelector(`.label-${label}`);
    if (el && el.parentElement) el.parentElement.classList.add("selected");
  }

  function clearSelection() {
    document
      .querySelectorAll(".choice-button")
      .forEach((b) => b.classList.remove("selected"));
  }

  function setChoicesEnabled(enabled) {
    document.querySelectorAll(".choice-button").forEach((btn) => {
      btn.disabled = !enabled;
    });
  }

  // 既存の変数の近くに追加
  let _redirectedToResult = false;
  let _finishConfirmCount = 0; // ★ 連続FINISHEDカウント
  let _lastPhaseSeen = null; // ★ 前回フェーズ

  // === デバッグユーティリティ（追加）=============================
  const DEBUG_NAV = true; // trueで詳細ログ
  const DEBUG_NAV_STRICT = true; // trueで result.php を事前にfetchして状態を出す

  function _ts() {
    const d = new Date();
    return (
      d.toLocaleTimeString() +
      "." +
      String(d.getMilliseconds()).padStart(3, "0")
    );
  }
  function _toStr(v) {
    try {
      return typeof v === "string" ? v : JSON.stringify(v);
    } catch {
      return String(v);
    }
  }
  function dlog(...args) {
    if (!DEBUG_NAV) return;
    const line = ["[BATTLE]", _ts(), ...args].map(_toStr).join(" ");
    console.log(line);

    const box = document.getElementById("debug-log");
    if (box) {
      const div = document.createElement("div");
      div.textContent = line;
      div.style.fontFamily = "monospace";
      box.prepend(div);
    }
  }

  // グローバルエラーも拾う
  window.addEventListener(
    "error",
    (e) => dlog("window.error", e.message, `${e.filename}:${e.lineno}`),
    true
  );
  window.addEventListener(
    "unhandledrejection",
    (e) => dlog("unhandledrejection", _toStr(e.reason)),
    true
  );

  // 起動時の基礎情報
  dlog("BOOT", {
    href: location.href,
    base: document.baseURI,
    bid: window.__BATTLE_ID__,
    gid: window.__GROUP_ID__,
    PHASES: {
      WAIT: PHASE_WAIT,
      QUESTION: PHASE_QUESTION,
      ANSWER: PHASE_ANSWER,
      FINISHED: PHASE_FINISHED,
    },
  });

  // === pollBattleState（置き換え）=================================
  function pollBattleState() {
    const qs = new URLSearchParams({
      bid: String(currentBid || battleId),
      gid: String(groupId),
    });
    const url = `./get_battle_state.php?${qs.toString()}`;
    dlog("pollBattleState: GET", url);

    fetch(url, { cache: "no-store" })
      .then((r) => {
        dlog("state.http", r.status);
        return r.json().catch((e) => {
          dlog("state.jsonError", String(e));
          throw e;
        });
      })
      .then((st) => {
        dlog("state.payload", st);
        if (!st) {
          dlog("skip: empty payload");
          return;
        }
        // フェーズボタン描画をここで行う（常に最新状態で上書き）
        try {
          renderBattleControls(st);
        } catch {}
        if (_redirectedToResult) {
          dlog("skip: already redirected");
          return;
        }

        const phase = Number(st.phase);
        dlog("state.phase", {
          raw: st.phase,
          num: phase,
          eqQ: phase === PHASE_QUESTION,
          geFIN: phase >= PHASE_FINISHED,
          finishCount: _finishConfirmCount,
        });

        // 取得できた phase に追従
        setChoicesEnabled(phase === PHASE_QUESTION);

        if (!Number.isFinite(phase)) {
          dlog("phase invalid -> ignore");
          return;
        }

        // 切り替わり直後の追随を早める微調整
        const delta = Number(st.next_at || 0) - Number(st.now || Date.now());
        if (delta > 0 && delta < 1200) {
          setTimeout(pollBattleState, 200); // 直前にもう一度だけ速く叩く
        }

        // === 終了判定 ===
        if (phase >= PHASE_FINISHED) {
          _finishConfirmCount++;
          dlog("FINISH seen", { finishCount: _finishConfirmCount });

          if (_finishConfirmCount < 2) {
            // ★ 初回FINISHEDは0.2秒後に即再ポーリングして確定を早める
            setTimeout(pollBattleState, 200);
            return;
          }

          const rq = new URLSearchParams({
            bid: String(currentBid || battleId),
            gid: String(groupId),
          });
          const targetUrl = new URL("./result.php", location.href);
          targetUrl.search = rq.toString();
          dlog("NAVIGATE ->", targetUrl.toString());

          _redirectedToResult = true;

          try {
            clearInterval(battleStateTimer);
          } catch {}
          try {
            clearInterval(questionIntervalId);
          } catch {}

          const navigate = () => {
            try {
              window.location.replace(targetUrl.toString());
            } catch {}
            setTimeout(() => {
              try {
                location.href = targetUrl.toString();
              } catch {}
            }, 600);
          };

          if (DEBUG_NAV_STRICT) {
            fetch(targetUrl.toString(), {
              cache: "no-store",
              credentials: "same-origin",
            })
              .then((r) =>
                dlog("result.preflight", {
                  status: r.status,
                  ok: r.ok,
                  redirected: r.redirected,
                  finalUrl: r.url,
                })
              )
              .catch((e) => dlog("result.preflight.error", String(e)))
              .finally(navigate);
          } else {
            navigate();
          }
          return;
        }

        // 未終了の場合はリセット
        _finishConfirmCount = 0;
        _lastPhaseSeen = phase;
      })
      .catch((err) => dlog("pollBattleState.error", String(err)));
  }

  // すぐ1回実行（初回の取りこぼし防止）
  pollBattleState();

  // 以降2秒ごとに実行
  const battleStateTimer = setInterval(pollBattleState, 2000);

  // 1時間でポーリング停止
  setTimeout(() => {
    clearInterval(questionIntervalId);
    const msgDiv = document.getElementById("polling-message");
    if (msgDiv) msgDiv.style.display = "block";
    console.log("⏹ 1時間経過：ポーリングを停止しました");
  }, 3600 * 1000);
});
