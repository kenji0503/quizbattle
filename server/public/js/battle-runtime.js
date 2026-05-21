document.addEventListener("DOMContentLoaded", () => {
  const battleId = Number(window.__BATTLE_ID__ || 0);
  const groupId = Number(window.__GROUP_ID__ || 0);
  const userId = Number(window.__USER_ID__ || 0);
  const wsUrl = String(window.__QB_WS_URL__ || "");

  const PHASE_WAIT = 0;
  const PHASE_QUESTION = 1;
  const PHASE_ANSWER = 2;
  const PHASE_FINISHED = 3;
  const REVEAL_PENDING_MS = 1000;

  let currentBid = battleId;
  let currentBnum = null;
  let currentKey = null;
  let currentAnswer = null;
  let lastTs = 0;
  let sentLock = false;
  let serverClockOffsetMs = 0;
  let countdownTargetMs = 0;
  let countdownIv = null;
  let countdownLabelText = "問題を出題します";
  let countdownDoneText = "START!";
  let currentState = null;
  let currentRevealAtMs = 0;
  let redirectedToResult = false;
  let socket = null;
  let reconnectTimer = null;
  let reconnectDelayMs = 1000;
  let heartbeatTimer = null;
  let questionStartTimer = null;
  let answerCloseTimer = null;
  let revealPendingTimer = null;
  let lastCountdownSecond = null;
  let lastRevealSoundKey = "";
  let lastQuestionSoundKey = "";
  let soundEnabled = localStorage.getItem("battle_sound_enabled") !== "0";
  let audioUnlocked = false;
  let unlockAttempted = false;

  const sounds = {
    answerBtn: resolveAudioElement("sound-answerBtn", "sound/answerBtn.mp3"),
    beep: resolveAudioElement("sound-beep", "sound/beep.mp3"),
    kotae: resolveAudioElement("sound-kotae", "sound/kotae.mp3"),
    countdown: resolveAudioElement("sound-countdown", "sound/countdown.mp3"),
    mondai: resolveAudioElement("sound-mondai", "sound/mondai.mp3"),
  };

  Object.values(sounds).forEach((audio) => {
    if (!audio) return;
    audio.preload = "auto";
    audio.playsInline = true;
    audio.setAttribute("playsinline", "");
    try {
      audio.load();
    } catch {}
  });

  function $(id) {
    return document.getElementById(id);
  }

  function resolveAudioElement(elementId, fallbackSrc) {
    const el = $(elementId);
    if (el) return el;
    const audio = new Audio(fallbackSrc);
    return audio;
  }

  function stopAllSounds() {
    Object.values(sounds).forEach((audio) => {
      try {
        audio.pause();
        audio.currentTime = 0;
      } catch {}
    });
  }

  function updateSoundToggle() {
    const button = $("sound-toggle");
    const label = $("sound-toggle-label");
    if (!button || !label) return;
    const muted = !soundEnabled;
    button.dataset.muted = muted ? "1" : "0";
    button.setAttribute("aria-pressed", muted ? "true" : "false");
    button.setAttribute(
      "aria-label",
      muted ? "音声をオンにする" : "音声をオフにする",
    );
    label.textContent = muted ? "音なし" : "音あり";
  }

  function syncServerClock(serverNowMs) {
    const parsed = Number(serverNowMs);
    if (Number.isFinite(parsed) && parsed > 0) {
      serverClockOffsetMs = parsed - Date.now();
    }
  }

  function syncedNow() {
    return Date.now() + serverClockOffsetMs;
  }

  function playSound(name) {
    if (!soundEnabled) return;
    const audio = sounds[name];
    if (!audio) return;
    try {
      audio.pause();
      audio.currentTime = 0;
      const promise = audio.play();
      if (promise && typeof promise.catch === "function") {
        promise.catch(() => {});
      }
    } catch {}
  }

  function primeAudio() {
    if (audioUnlocked || unlockAttempted) return;
    const targets = Object.values(sounds).filter(Boolean);
    if (targets.length === 0) return;
    unlockAttempted = true;
    Promise.all(
      targets.map((audio) => {
        try {
          audio.muted = true;
          audio.volume = 0;
          const promise = audio.play();
          if (promise && typeof promise.then === "function") {
            return promise.then(() => {
              audio.pause();
              audio.currentTime = 0;
              audio.muted = false;
              audio.volume = 1;
            });
          }
          audio.pause();
          audio.currentTime = 0;
          audio.muted = false;
          audio.volume = 1;
          return Promise.resolve();
        } catch {
          return Promise.reject();
        }
      }),
    )
      .then(() => {
        audioUnlocked = true;
        detachPrimeListeners();
      })
      .catch(() => {
        unlockAttempted = false;
      });
  }

  function detachPrimeListeners() {
    document.removeEventListener("pointerdown", primeAudio);
    document.removeEventListener("keydown", primeAudio);
    document.removeEventListener("touchstart", primeAudio);
  }

  function toggleSound() {
    primeAudio();
    soundEnabled = !soundEnabled;
    localStorage.setItem("battle_sound_enabled", soundEnabled ? "1" : "0");
    if (soundEnabled) {
      audioUnlocked = true;
    } else {
      stopAllSounds();
    }
    updateSoundToggle();
  }

  function renderCountdown() {
    const panel = $("countdown-panel");
    const label = $("countdown-label");
    const value = $("countdown-value");
    if (!panel || !label || !value) return;

    if (!countdownTargetMs) {
      panel.style.display = "none";
      label.textContent = countdownLabelText;
      value.textContent = "";
      lastCountdownSecond = null;
      return;
    }

    const remainMs = countdownTargetMs - syncedNow();
    const remainSec = remainMs > 0 ? Math.ceil(remainMs / 1000) : 0;
    panel.style.display = "block";
    label.textContent = countdownLabelText;
    value.textContent = remainSec > 0 ? String(remainSec) : countdownDoneText;

    if (remainSec > 0 && remainSec !== lastCountdownSecond) {
      lastCountdownSecond = remainSec;
    }
  }

  function setCountdownTarget(targetMs, options = {}) {
    const parsed = Number(targetMs);
    if (!Number.isFinite(parsed) || parsed <= 0) return;
    countdownLabelText = String(options.label || "問題を出題します");
    countdownDoneText = String(options.doneText || "START!");
    countdownTargetMs = parsed;
    renderCountdown();
    if (countdownIv) return;
    countdownIv = setInterval(() => {
      renderCountdown();
      if (countdownTargetMs && countdownTargetMs - syncedNow() <= -400) {
        clearCountdownTarget();
      }
    }, 200);
  }

  function clearCountdownTarget() {
    countdownTargetMs = 0;
    countdownLabelText = "問題を出題します";
    countdownDoneText = "START!";
    lastCountdownSecond = null;
    if (countdownIv) {
      clearInterval(countdownIv);
      countdownIv = null;
    }
    renderCountdown();
  }

  function scheduleQuestionStart(targetMs) {
    const parsed = Number(targetMs);
    if (!Number.isFinite(parsed) || parsed <= 0) return;
    if (questionStartTimer) {
      clearTimeout(questionStartTimer);
      questionStartTimer = null;
    }
    questionStartTimer = setTimeout(
      () => {
        questionStartTimer = null;
        hydrateBattleView().catch((error) => console.error(error));
      },
      Math.max(0, parsed - syncedNow() + 50),
    );
  }

  function scheduleRevealDisplay(targetMs) {
    const parsed = Number(targetMs);
    if (!Number.isFinite(parsed) || parsed <= 0) return;
    if (revealPendingTimer) {
      clearTimeout(revealPendingTimer);
      revealPendingTimer = null;
    }
    revealPendingTimer = setTimeout(
      () => {
        revealPendingTimer = null;
        hydrateBattleView().catch((error) => console.error(error));
      },
      Math.max(0, parsed - syncedNow() + 50),
    );
  }

  function answerCloseAtOf(payload) {
    const explicit = Number(payload?.answer_close_at || 0);
    if (Number.isFinite(explicit) && explicit > 0) return explicit;
    const revealAt = Number(payload?.reveal_at || 0);
    if (Number.isFinite(revealAt) && revealAt > REVEAL_PENDING_MS) {
      return revealAt - REVEAL_PENDING_MS;
    }
    return revealAt;
  }

  function scheduleAnswerClosePending(closeAtMs, revealAtMs) {
    const closeAt = Number(closeAtMs);
    const revealAt = Number(revealAtMs);
    if (!Number.isFinite(closeAt) || closeAt <= 0) return;
    if (!Number.isFinite(revealAt) || revealAt <= closeAt) return;
    if (answerCloseTimer) {
      clearTimeout(answerCloseTimer);
      answerCloseTimer = null;
    }
    answerCloseTimer = setTimeout(
      () => {
        answerCloseTimer = null;
        showRevealPendingMessage(revealAt);
      },
      Math.max(0, closeAt - syncedNow() + 50),
    );
  }

  function showMessage(msg) {
    const el = $("message-area");
    if (el) el.textContent = msg;
  }

  function setRevealLayout(enabled) {
    const stage = $("question-stage");
    if (!stage) return;
    if (enabled) {
      stage.classList.remove("is-ready");
    }
    stage.classList.toggle("is-reveal", !!enabled);
  }

  function setReadyLayout(enabled) {
    const stage = $("question-stage");
    if (!stage) return;
    if (enabled) {
      stage.classList.remove("is-reveal");
    }
    stage.classList.toggle("is-ready", !!enabled);
  }

  function setAnswerCount(count) {
    const label = $("answer-count");
    if (!label) return;
    if (!Number.isFinite(Number(count))) {
      label.textContent = "";
      return;
    }
    label.textContent = `現在の解答者数: ${Number(count)}人`;
  }

  function showReadyMessage() {
    setRevealLayout(false);
    setReadyLayout(true);
    clearCountdownTarget();
    clearBattleTitle();
    const headline = $("q-headline");
    if (headline) headline.textContent = "";
    showMessage("");
    $("question").textContent = "問題を出題します";
    ["A", "B", "C", "D"].forEach((label) => {
      const div = $(`sentaku${label}`);
      if (div) div.innerHTML = "";
    });
    setAnswerCount(NaN);
    currentBnum = null;
    currentAnswer = null;
    currentKey = null;
    currentRevealAtMs = 0;
    lastRevealSoundKey = "";
    lastQuestionSoundKey = "";
    sentLock = false;
    setChoicesEnabled(false);
  }

  function showRevealPendingMessage(targetMs) {
    setRevealLayout(false);
    setReadyLayout(true);
    clearCountdownTarget();
    const headline = $("q-headline");
    if (headline) headline.textContent = "";
    showMessage("");
    $("question").textContent = "正解を表示します";
    ["A", "B", "C", "D"].forEach((label) => {
      const div = $(`sentaku${label}`);
      if (div) div.innerHTML = "";
    });
    setAnswerCount(NaN);
    setChoicesEnabled(false);
    scheduleRevealDisplay(targetMs);
  }

  function showQuestionCountdown(targetMs) {
    setCountdownTarget(targetMs, {
      label: "問題を出題します",
      doneText: "START!",
    });
  }

  function showAnswerDeadlineCountdown(targetMs, revealAtMs = targetMs) {
    setCountdownTarget(targetMs, {
      label: "正解表示まで",
      doneText: "締切!",
    });
    scheduleAnswerClosePending(targetMs, revealAtMs);
  }

  function renderChoice(containerId, label, text) {
    const div = $(containerId);
    if (!div) return;
    div.innerHTML = "";

    const btn = document.createElement("button");
    btn.className = "choice-button";
    btn.type = "button";
    btn.disabled = !(
      currentState &&
      Number(currentState.phase) === PHASE_QUESTION &&
      !sentLock
    );
    btn.onclick = () => checkAnswer(label);

    const span = document.createElement("span");
    span.className = `choice-label label-${label}`;
    span.textContent = label;

    btn.appendChild(span);
    btn.appendChild(document.createTextNode(text ?? ""));
    div.appendChild(btn);
  }

  function clearSelection() {
    document
      .querySelectorAll(".choice-button")
      .forEach((button) => button.classList.remove("selected"));
  }

  function markSelected(label) {
    clearSelection();
    const el = document.querySelector(`.label-${label}`);
    if (el && el.parentElement) {
      el.parentElement.classList.add("selected");
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

  function setChoicesEnabled(enabled) {
    document.querySelectorAll(".choice-button").forEach((btn) => {
      btn.disabled = !enabled;
    });
  }

  function restoreStoredAnswer() {
    if (!currentBid || !currentBnum) return;
    const stored = sessionStorage.getItem(
      `answer_${currentBid}_${currentBnum}`,
    );
    if (!stored) return;
    try {
      const parsed = JSON.parse(stored);
      if (parsed?.sel) {
        markSelected(parsed.sel);
      }
    } catch {}
  }

  function renderQuestionPayload(q) {
    currentBid = Number(q.bid || currentBid || battleId);
    currentBnum = Number(q.bnum || 0);
    currentKey = {
      cate1: Number(q.cate1 || 0),
      cate2: Number(q.cate2 || 0),
      id: Number(q.id || 0),
      num: Number(q.num || 0),
    };
    currentAnswer = q.kaito ? String(q.kaito).toUpperCase() : null;
    currentRevealAtMs = answerCloseAtOf(q);
    lastTs = Number(q.ts || 0);
    const revealSoundKey = `${currentBid}:${currentBnum}:${currentAnswer || ""}`;
    const questionSoundKey = `${currentBid}:${currentBnum}:${lastTs}`;

    setReadyLayout(false);
    setBattleTitle(q.theme_title || q.display || "出題中");
    const headline = $("q-headline");
    if (headline) headline.textContent = "";
    $("question").textContent = q.mondai || "";
    showMessage("");

    renderChoice("sentakuA", "A", q.qa);
    renderChoice("sentakuB", "B", q.qb);
    renderChoice("sentakuC", "C", q.qc);
    renderChoice("sentakuD", "D", q.qd);

    if (lastQuestionSoundKey !== questionSoundKey) {
      lastQuestionSoundKey = questionSoundKey;
      playSound("mondai");
    }

    clearSelection();
    restoreStoredAnswer();
    setAnswerCount(Number(q.answered_players || 0));

    if (Number(q.reveal_pending || 0) === 1 && Number(q.show_answer || 0) !== 1) {
      showRevealPendingMessage(Number(q.reveal_at || 0));
      return;
    }

    if (Number(q.show_answer || 0) === 1 && currentAnswer) {
      setRevealLayout(true);
      clearCountdownTarget();
      highlightCorrectAnswer(currentAnswer);
      if (lastRevealSoundKey !== revealSoundKey) {
        lastRevealSoundKey = revealSoundKey;
        playSound("kotae");
      }
      setChoicesEnabled(false);
    } else {
      setRevealLayout(false);
      const answerCloseAt = answerCloseAtOf(q);
      if (Number.isFinite(answerCloseAt)) {
        showAnswerDeadlineCountdown(answerCloseAt, Number(q.reveal_at || 0));
      }
      setChoicesEnabled(
        Number(currentState?.phase) === PHASE_QUESTION && !sentLock,
      );
    }
  }

  function setBattleTitle(text) {
    const title = $("battle-title");
    if (!title) return;
    title.textContent = text || "出題中";
  }

  function clearBattleTitle() {
    const title = $("battle-title");
    if (!title) return;
    title.textContent = "";
  }

  function renderBattleControls(state) {
    const box = $("battleControls");
    if (!box) return;
    box.className = "qb-action-row";
    box.innerHTML = "";

    const phase = Number(state?.phase || 0);
    const isLast = Number(state?.is_last || 0);
    const allAnswered = Number(state?.all_answered || 0);

    if (phase === PHASE_QUESTION && allAnswered === 1) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "qb-round-yellow-btn qb-round-yellow-btn--wide";
      btn.textContent = "正解表示";
      btn.onclick = () => doPhaseJump("reveal");
      box.appendChild(btn);
    }

    if (phase === PHASE_ANSWER) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "qb-round-yellow-btn qb-round-yellow-btn--wide";
      btn.textContent = isLast === 1 ? "結果へ" : "次の問題";
      btn.onclick = () => doPhaseJump("next");
      box.appendChild(btn);
    }
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      cache: "no-store",
      credentials: "same-origin",
      ...options,
    });
    const text = await response.text();
    let payload = {};
    try {
      payload = text ? JSON.parse(text) : {};
    } catch {
      throw new Error(`JSON parse failed: ${url}`);
    }
    if (!response.ok) {
      throw new Error(
        payload?.message || payload?.msg || `HTTP ${response.status}`,
      );
    }
    return payload;
  }

  async function refreshBattleState() {
    const qs = new URLSearchParams({
      bid: String(currentBid || battleId),
      gid: String(groupId),
    });
    const state = await fetchJson(`./get_battle_state.php?${qs.toString()}`);
    currentState = state;
    syncServerClock(state.now);
    renderBattleControls(state);

    if (
      Number(state.phase) === PHASE_WAIT &&
      Number.isFinite(Number(state.q_start_at))
    ) {
      scheduleQuestionStart(Number(state.q_start_at));
      showReadyMessage();
    } else if (
      Number(state.phase) === PHASE_QUESTION &&
      Number.isFinite(Number(state.reveal_at))
    ) {
      const answerCloseAt = answerCloseAtOf(state);
      currentRevealAtMs = answerCloseAt;
      if (Number(state.reveal_pending || 0) === 1) {
        showRevealPendingMessage(Number(state.reveal_at || 0));
      } else {
        showAnswerDeadlineCountdown(answerCloseAt, Number(state.reveal_at || 0));
      }
    } else if (
      Number(state.phase) === PHASE_ANSWER &&
      Number.isFinite(Number(state.switch_at))
    ) {
      currentRevealAtMs = Number(state.reveal_at || 0);
      clearCountdownTarget();
    } else if (Number(state.phase) >= PHASE_QUESTION) {
      currentRevealAtMs = Number(state.reveal_at || 0);
      clearCountdownTarget();
    }

    if (Number(state.phase) >= PHASE_FINISHED) {
      redirectToResult();
    }

    return state;
  }

  async function refreshQuestion() {
    const qs = new URLSearchParams({
      bid: String(currentBid || battleId),
      gid: String(groupId),
    });
    const q = await fetchJson(`./get_question.php?${qs.toString()}`);
    if (q?.error) {
      const message = String(q.message || q.error || "").toLowerCase();
      if (
        q.phase_hint === "countdown" &&
        Number.isFinite(Number(q.q_start_at || 0))
      ) {
        syncServerClock(q.now_ms);
        scheduleQuestionStart(Number(q.q_start_at));
        showReadyMessage();
        return null;
      }
      throw new Error(message || "question error");
    }
    renderQuestionPayload(q);
    return q;
  }

  async function hydrateBattleView() {
    if (redirectedToResult) return;
    const state = await refreshBattleState();
    if (Number(state.phase) >= PHASE_FINISHED) return;
    if (Number(state.phase) === PHASE_WAIT) return;
    await refreshQuestion();
  }

  function redirectToResult() {
    if (redirectedToResult) return;
    redirectedToResult = true;
    const qs = new URLSearchParams({
      bid: String(currentBid || battleId),
      gid: String(groupId),
    });
    window.location.replace(`result.php?${qs.toString()}`);
  }

  async function doPhaseJump(action) {
    const box = $("battleControls");
    box
      ?.querySelectorAll("button")
      .forEach((button) => (button.disabled = true));
    try {
      const form = new URLSearchParams({
        gid: String(groupId),
        bid: String(battleId),
        action,
      });
      const response = await fetchJson("phase_jump.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: form.toString(),
      });

      if (response?.state) {
        syncServerClock(response.state.now);
        currentState = { ...(currentState || {}), ...response.state };
        renderBattleControls(currentState);
        if (Number(currentState.phase) >= PHASE_FINISHED) {
          redirectToResult();
          return;
        }
        if (
          Number(response.state.reveal_pending || 0) === 1 &&
          Number.isFinite(Number(response.state.reveal_at || 0))
        ) {
          showRevealPendingMessage(Number(response.state.reveal_at));
          return;
        }
        if (
          action === "next" &&
          Number(currentState.phase) === PHASE_WAIT &&
          Number.isFinite(Number(response.state.q_start_at || 0))
        ) {
          scheduleQuestionStart(Number(response.state.q_start_at));
          showReadyMessage();
        } else {
          hydrateBattleView().catch((error) => console.error(error));
        }
      }
    } catch (error) {
      alert(`操作に失敗: ${error.message || error}`);
    } finally {
      setTimeout(() => {
        box
          ?.querySelectorAll("button")
          .forEach((button) => (button.disabled = false));
      }, 300);
    }
  }

  async function checkAnswer(selected) {
    primeAudio();
    if (sentLock || !currentKey || !currentBid || redirectedToResult) {
      playSound("beep");
      return;
    }
    if (
      Number(currentState?.phase) !== PHASE_QUESTION ||
      (currentRevealAtMs && syncedNow() >= currentRevealAtMs)
    ) {
      playSound("beep");
      setChoicesEnabled(false);
      hydrateBattleView().catch((error) => console.error(error));
      return;
    }

    sentLock = true;
    markSelected(selected);
    setChoicesEnabled(false);
    playSound("answerBtn");

    const params = new URLSearchParams({
      bid: String(currentBid),
      gid: String(groupId),
      cate1: String(currentKey.cate1),
      cate2: String(currentKey.cate2),
      id: String(currentKey.id),
      num: String(currentKey.num),
      uid: String(userId),
      selected,
    });

    try {
      const data = await fetchJson("./submit_buzz.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: params.toString(),
      });

      if (data?.error === "answer_closed") {
        syncServerClock(data.now_ms);
        showMessage("この問題の解答受付は終了しました。");
        sentLock = false;
        setChoicesEnabled(false);
        hydrateBattleView().catch((error) => console.error(error));
        return;
      }
      if (data?.error) {
        throw new Error(String(data.error));
      }

      const pos = data.position ?? "？";
      const at = data.answered_at || new Date().toLocaleTimeString();
      sessionStorage.setItem(
        `answer_${currentBid}_${currentBnum}`,
        JSON.stringify({ pos, sel: selected, at }),
      );

      setAnswerCount(Number(data.answered_players || 0));
      if (Number(data.all_answered || 0) === 1) {
        refreshBattleState().catch((error) => console.error(error));
      }
      if (data.duplicate) {
        playSound("beep");
        showMessage("この問題は既に解答済みです。");
      }
    } catch (error) {
      showMessage("通信エラーが発生しました。再度お試しください。");
      sentLock = false;
      setChoicesEnabled(Number(currentState?.phase) === PHASE_QUESTION);
    }
  }

  function sendHeartbeat() {
    if (!currentBid || !groupId || redirectedToResult) return;
    const qs = new URLSearchParams({
      bid: String(currentBid || battleId),
      gid: String(groupId),
    });
    fetch(`./ping_battle.php?${qs.toString()}`, {
      cache: "no-store",
      credentials: "same-origin",
    }).catch(() => {});
  }

  function startHeartbeat() {
    sendHeartbeat();
    if (heartbeatTimer) return;
    heartbeatTimer = setInterval(sendHeartbeat, 15000);
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") {
        sendHeartbeat();
      }
    });
  }

  function scheduleReconnect() {
    if (reconnectTimer || !wsUrl) return;
    reconnectTimer = setTimeout(() => {
      reconnectTimer = null;
      connectSocket();
    }, reconnectDelayMs);
    reconnectDelayMs = Math.min(reconnectDelayMs * 2, 10000);
  }

  function handleSocketEvent(message) {
    const event = String(message?.event || "");
    const payload = message?.payload || {};

    if (event === "battle.state" || event === "battle.tick") {
      if (
        event === "battle.state" &&
        Number(payload.reveal_pending || 0) === 1 &&
        Number.isFinite(Number(payload.reveal_at || 0))
      ) {
        syncServerClock(payload.now);
        currentState = { ...(currentState || {}), ...payload };
        renderBattleControls(currentState);
        showRevealPendingMessage(Number(payload.reveal_at));
        return;
      }
      hydrateBattleView().catch((error) => console.error(error));
      return;
    }

    if (event === "battle.buzz") {
      if (
        currentKey &&
        Number(payload.cate1) === currentKey.cate1 &&
        Number(payload.cate2) === currentKey.cate2 &&
        Number(payload.id) === currentKey.id &&
        Number(payload.num) === currentKey.num
      ) {
        setAnswerCount(Number(payload.answered_players || 0));
      }
      if (Number(payload.all_answered || 0) === 1) {
        refreshBattleState().catch((error) => console.error(error));
      }
    }
  }

  function connectSocket() {
    if (!wsUrl || redirectedToResult) return;
    if (
      socket &&
      (socket.readyState === WebSocket.OPEN ||
        socket.readyState === WebSocket.CONNECTING)
    ) {
      return;
    }

    socket = new WebSocket(wsUrl);
    socket.addEventListener("open", () => {
      reconnectDelayMs = 1000;
      socket.send(
        JSON.stringify({
          type: "subscribe",
          rooms: [`battle:${battleId}:${groupId}`],
        }),
      );
    });

    socket.addEventListener("message", (event) => {
      try {
        handleSocketEvent(JSON.parse(event.data));
      } catch (error) {
        console.error("ws message parse error", error);
      }
    });

    socket.addEventListener("close", () => {
      scheduleReconnect();
    });

    socket.addEventListener("error", () => {
      try {
        socket.close();
      } catch {}
    });
  }

  hydrateBattleView().catch((error) => {
    console.error(error);
    showMessage("初期表示の取得に失敗しました。");
  });
  startHeartbeat();
  updateSoundToggle();
  $("sound-toggle")?.addEventListener("click", toggleSound);
  document.addEventListener("pointerdown", primeAudio);
  document.addEventListener("keydown", primeAudio);
  document.addEventListener("touchstart", primeAudio, { passive: true });
  connectSocket();
});
