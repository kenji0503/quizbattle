/* public/js/sprite_runtime.js
   UMD: <script> でも ESModule でも使えるようにしています。
*/
(function (root, factory) {
  if (typeof module === "object" && typeof module.exports === "object") {
    module.exports = factory();
  } else {
    root.SpriteEngine = factory();
  }
})(typeof self !== "undefined" ? self : this, function () {
  ("use strict");

  // ==== 16コマ統一メタ ====
  const SPRITE_META = Object.freeze({
    cols: 4,
    rows: 4,
    frames: 16,
    fps: 12, // 体感の良い既定
  });

  // 実効フレーム時間（全体既定）
  let FRAME_MS = 1000 / SPRITE_META.fps;

  // コマ計算（%ではなくpxで位置合わせ）
  function setFrame(el, i) {
    const cols = SPRITE_META.cols;
    const col = i % cols;
    const row = Math.floor(i / cols);

    // 要素の見た目サイズ = 1コマの表示サイズ
    const fw = el.clientWidth; // 例: 80
    const fh = el.clientHeight; // 例: 80

    // pxでピタッと切り出す
    const bx = -(col * fw);
    const by = -(row * fh);
    el.style.backgroundPosition = `${bx}px ${by}px`;
  }

  // ===== 中央ループ（rAF） =====
  const Runtime = (() => {
    // 登録要素は {el, phase, frames?, frameMs?} を持てる
    const items = new Set();
    let rafId = null;
    let startT = performance.now();
    let paused = false;

    function loop(now) {
      if (paused) return;

      // ベースのフレーム（グローバル既定fpsで進行）
      const elapsed = now - startT;
      const base = Math.floor(elapsed / FRAME_MS);

      items.forEach((it) => {
        const frames = it.frames ?? SPRITE_META.frames;
        const phase = it.phase ?? 0;

        // 要素毎にfpsを変えたい場合（任意）
        const fm = it.frameMs ?? FRAME_MS;
        const f = Math.floor(elapsed / fm);
        const idx = (f + phase) % frames;

        setFrame(it.el, idx);
      });

      rafId = requestAnimationFrame(loop);
    }

    function ensure() {
      if (rafId == null) rafId = requestAnimationFrame(loop);
    }

    return {
      add(it) {
        items.add(it);
        ensure();
      },
      remove(el) {
        for (const it of items) {
          if (it.el === el) {
            items.delete(it);
            break;
          }
        }
        if (items.size === 0 && rafId != null) {
          cancelAnimationFrame(rafId);
          rafId = null;
        }
      },
      pause() {
        if (rafId != null) cancelAnimationFrame(rafId);
        rafId = null;
        paused = true;
      },
      resume() {
        if (!paused) return;
        paused = false;
        ensure();
      },
    };
  })();

  // ==== 外部API ====

  /**
   * 画像URLを適用してスプライト再生に参加させる
   * @param {HTMLElement} el - 背景を当てる要素(80x80推奨)
   * @param {string} url - スプライト画像 4x4=16コマ (320x320など)
   * @param {object} [opt] - {phase, fps, frames}
   */
  // 画像を当てる時に、背景サイズをpxで固定（cols*fw, rows*fh）
  async function attach(el, url, opt = {}) {
    const { phase, fps, frames } = opt;

    // 先に要素サイズを確定させてから背景を当てる
    const fw = el.clientWidth || 80;
    const fh = el.clientHeight || 80;

    el.style.backgroundImage = `url("${url}")`;
    el.style.backgroundRepeat = "no-repeat";
    // ここが重要：pxで総サイズを固定（例: 4*80=320）
    el.style.backgroundSize = `${SPRITE_META.cols * fw}px ${
      SPRITE_META.rows * fh
    }px`;

    try {
      const img = new Image();
      img.decoding = "async";
      img.src = url;
      await img.decode();
    } catch (_) {
      /* ignore */
    }

    const item = {
      el,
      phase:
        typeof phase === "number"
          ? phase
          : Math.floor(Math.random() * SPRITE_META.frames),
      frames: typeof frames === "number" ? frames : SPRITE_META.frames,
    };
    if (typeof fps === "number" && fps > 0) item.frameMs = 1000 / fps;

    el._spriteStop = () => detach(el);
    Runtime.add(item);
  }

  function detach(el) {
    Runtime.remove(el);
    el.style.backgroundImage = "";
    if (el._spriteStop) el._spriteStop = null;
  }

  // グローバルfpsを変えたいとき（全員に一括で効く）
  function setGlobalFps(fps) {
    if (typeof fps === "number" && fps > 0) {
      FRAME_MS = 1000 / fps;
    }
  }

  return {
    meta: SPRITE_META, // cols/rows/framesの定義
    attach, // 要素に画像を当てて再生へ登録
    detach, // 再生から外す
    pause: Runtime.pause, // 全体一時停止
    resume: Runtime.resume,
    setGlobalFps, // 全体fps変更（任意）
    setFrame, // （必要なら）手動で特定コマを当てる
  };
});
