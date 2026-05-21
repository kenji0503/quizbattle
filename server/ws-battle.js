"use strict";

const http = require("http");
const crypto = require("crypto");

const PORT = Number(process.env.QB_WS_PORT || process.env.WS_PORT || 8081);
const HOST = process.env.QB_WS_HOST || process.env.WS_HOST || "0.0.0.0";
const SECRET = process.env.QB_WS_SECRET || process.env.WS_SECRET || "";
const BASE_URL = (
  process.env.QB_WS_SYNC_BASE_URL ||
  process.env.WS_SYNC_BASE_URL ||
  process.env.BASE_URL ||
  "http://127.0.0.1"
).replace(/\/+$/, "");

const clients = new Set();
const rooms = new Map();
const battleSchedules = new Map();

function log(...args) {
  console.log(new Date().toISOString(), ...args);
}

function roomSet(room) {
  if (!rooms.has(room)) {
    rooms.set(room, new Set());
  }
  return rooms.get(room);
}

function sendFrame(socket, opcode, payloadBuffer = Buffer.alloc(0)) {
  if (socket.destroyed) return;
  const length = payloadBuffer.length;
  let header;

  if (length < 126) {
    header = Buffer.from([0x80 | opcode, length]);
  } else if (length < 65536) {
    header = Buffer.alloc(4);
    header[0] = 0x80 | opcode;
    header[1] = 126;
    header.writeUInt16BE(length, 2);
  } else {
    header = Buffer.alloc(10);
    header[0] = 0x80 | opcode;
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(length), 2);
  }

  socket.write(Buffer.concat([header, payloadBuffer]));
}

function sendJson(socket, payload) {
  try {
    sendFrame(socket, 0x1, Buffer.from(JSON.stringify(payload), "utf8"));
  } catch (error) {
    log("sendJson error", error.message);
  }
}

function broadcast(roomsToSend, event, payload = {}) {
  const message = { event, payload, ts: Date.now() };
  const sent = new Set();
  for (const room of roomsToSend) {
    const sockets = rooms.get(room);
    if (!sockets) continue;
    for (const socket of sockets) {
      if (sent.has(socket)) continue;
      sent.add(socket);
      sendJson(socket, message);
    }
  }
}

function unsubscribeAll(socket) {
  if (!socket._rooms) return;
  for (const room of socket._rooms) {
    const set = rooms.get(room);
    if (!set) continue;
    set.delete(socket);
    if (set.size === 0) {
      rooms.delete(room);
    }
  }
  socket._rooms.clear();
}

async function syncBattleRoom(room) {
  const match = /^battle:(\d+):(\d+)$/.exec(room);
  if (!match) return;
  const bid = Number(match[1]);
  const gid = Number(match[2]);
  const url = `${BASE_URL}/get_battle_state.php?bid=${bid}&gid=${gid}`;

  try {
    const response = await fetch(url, { cache: "no-store" });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const state = await response.json();
    if (!state || typeof state !== "object") {
      throw new Error("invalid state payload");
    }
    scheduleBattleState({
      bid,
      gid,
      bnum: Number(state.bnum || 0),
      phase: Number(state.phase || 0),
      q_start_at: Number(state.q_start_at || 0),
      reveal_at: Number(state.reveal_at || 0),
      switch_at: Number(state.switch_at || 0),
      now: Number(state.now || Date.now()),
    });
    broadcast([room], "battle.state", state);
  } catch (error) {
    log("syncBattleRoom failed", room, error.message);
  }
}

function clearBattleSchedule(room) {
  const existing = battleSchedules.get(room);
  if (!existing) return;
  for (const timer of Object.values(existing.timers)) {
    if (timer) clearTimeout(timer);
  }
  battleSchedules.delete(room);
}

function scheduleBattleState(state) {
  const bid = Number(state.bid || 0);
  const gid = Number(state.gid || 0);
  if (bid <= 0 || gid <= 0) return;

  const room = `battle:${bid}:${gid}`;
  const qStartAt = Number(state.q_start_at || 0);
  const revealAt = Number(state.reveal_at || 0);
  const switchAt = Number(state.switch_at || 0);
  const phase = Number(state.phase || 0);
  const signature = [state.bnum, phase, qStartAt, revealAt, switchAt].join(":");
  const current = battleSchedules.get(room);
  if (current && current.signature === signature) {
    return;
  }

  clearBattleSchedule(room);
  if (phase >= 3) {
    return;
  }

  const timers = { qStart: null, reveal: null, switch: null };
  const now = Date.now();

  if (qStartAt > now) {
    timers.qStart = setTimeout(() => {
      broadcast([room], "battle.tick", { bid, gid, kind: "q_start" });
    }, Math.max(0, qStartAt - now));
  }

  if (revealAt > now) {
    timers.reveal = setTimeout(() => {
      broadcast([room], "battle.tick", { bid, gid, kind: "reveal" });
    }, Math.max(0, revealAt - now));
  }

  if (switchAt > now) {
    timers.switch = setTimeout(() => {
      syncBattleRoom(room).catch((error) => {
        log("switch sync error", room, error.message);
      });
    }, Math.max(0, switchAt - now));
  }

  battleSchedules.set(room, { signature, timers });
}

function normalizeRooms(raw) {
  if (Array.isArray(raw)) {
    return raw.map((room) => String(room || "").trim()).filter(Boolean);
  }
  if (typeof raw === "string" && raw.trim() !== "") {
    return [raw.trim()];
  }
  return [];
}

function applySubscription(socket, payload) {
  const nextRooms = normalizeRooms(payload.rooms || payload.room);
  unsubscribeAll(socket);
  socket._rooms = new Set();

  for (const room of nextRooms) {
    roomSet(room).add(socket);
    socket._rooms.add(room);
    if (room.startsWith("battle:")) {
      syncBattleRoom(room).catch((error) => {
        log("subscribe sync error", room, error.message);
      });
    }
  }

  sendJson(socket, { event: "subscribed", payload: { rooms: nextRooms }, ts: Date.now() });
}

function handleClientMessage(socket, text) {
  let payload;
  try {
    payload = JSON.parse(text);
  } catch {
    return;
  }

  if (!payload || typeof payload !== "object") return;
  if (payload.type === "subscribe") {
    applySubscription(socket, payload);
  } else if (payload.type === "ping") {
    sendJson(socket, { event: "pong", payload: {}, ts: Date.now() });
  }
}

function attachWebSocket(socket) {
  socket._rooms = new Set();
  socket._buffer = Buffer.alloc(0);
  clients.add(socket);

  socket.on("data", (chunk) => {
    socket._buffer = Buffer.concat([socket._buffer, chunk]);

    while (socket._buffer.length >= 2) {
      const first = socket._buffer[0];
      const second = socket._buffer[1];
      const opcode = first & 0x0f;
      const masked = (second & 0x80) === 0x80;
      let payloadLength = second & 0x7f;
      let offset = 2;

      if (payloadLength === 126) {
        if (socket._buffer.length < offset + 2) return;
        payloadLength = socket._buffer.readUInt16BE(offset);
        offset += 2;
      } else if (payloadLength === 127) {
        if (socket._buffer.length < offset + 8) return;
        payloadLength = Number(socket._buffer.readBigUInt64BE(offset));
        offset += 8;
      }

      const maskLength = masked ? 4 : 0;
      const frameLength = offset + maskLength + payloadLength;
      if (socket._buffer.length < frameLength) return;

      let payload = socket._buffer.subarray(offset + maskLength, frameLength);
      if (masked) {
        const mask = socket._buffer.subarray(offset, offset + 4);
        const decoded = Buffer.alloc(payload.length);
        for (let i = 0; i < payload.length; i += 1) {
          decoded[i] = payload[i] ^ mask[i % 4];
        }
        payload = decoded;
      }

      socket._buffer = socket._buffer.subarray(frameLength);

      if (opcode === 0x8) {
        sendFrame(socket, 0x8);
        socket.end();
        return;
      }
      if (opcode === 0x9) {
        sendFrame(socket, 0xA, payload);
        continue;
      }
      if (opcode === 0x1) {
        handleClientMessage(socket, payload.toString("utf8"));
      }
    }
  });

  socket.on("close", () => {
    unsubscribeAll(socket);
    clients.delete(socket);
  });

  socket.on("error", () => {
    unsubscribeAll(socket);
    clients.delete(socket);
  });
}

async function readJson(req) {
  const chunks = [];
  for await (const chunk of req) {
    chunks.push(chunk);
  }
  if (chunks.length === 0) return {};
  return JSON.parse(Buffer.concat(chunks).toString("utf8"));
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);

  if (req.method === "GET" && url.pathname === "/health") {
    res.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
    res.end(JSON.stringify({ ok: true, clients: clients.size, rooms: rooms.size }));
    return;
  }

  if (req.method === "POST" && url.pathname === "/publish") {
    try {
      const body = await readJson(req);
      const suppliedSecret =
        req.headers["x-qb-ws-secret"] || body.secret || "";
      if (SECRET !== "" && suppliedSecret !== SECRET) {
        res.writeHead(403, { "Content-Type": "application/json; charset=utf-8" });
        res.end(JSON.stringify({ ok: false, message: "forbidden" }));
        return;
      }

      const targetRooms = normalizeRooms(body.rooms);
      const event = String(body.event || "").trim();
      const payload = body.payload && typeof body.payload === "object" ? body.payload : {};

      if (targetRooms.length === 0 || event === "") {
        res.writeHead(400, { "Content-Type": "application/json; charset=utf-8" });
        res.end(JSON.stringify({ ok: false, message: "invalid payload" }));
        return;
      }

      if (event === "battle.state") {
        scheduleBattleState(payload);
      }

      broadcast(targetRooms, event, payload);
      res.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
      res.end(JSON.stringify({ ok: true }));
      return;
    } catch (error) {
      res.writeHead(500, { "Content-Type": "application/json; charset=utf-8" });
      res.end(JSON.stringify({ ok: false, message: error.message }));
      return;
    }
  }

  res.writeHead(404, { "Content-Type": "application/json; charset=utf-8" });
  res.end(JSON.stringify({ ok: false, message: "not found" }));
});

server.on("upgrade", (req, socket) => {
  const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
  if (url.pathname !== "/ws") {
    socket.destroy();
    return;
  }

  const key = req.headers["sec-websocket-key"];
  if (!key) {
    socket.destroy();
    return;
  }

  const accept = crypto
    .createHash("sha1")
    .update(`${key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`, "binary")
    .digest("base64");

  socket.write(
    [
      "HTTP/1.1 101 Switching Protocols",
      "Upgrade: websocket",
      "Connection: Upgrade",
      `Sec-WebSocket-Accept: ${accept}`,
      "\r\n",
    ].join("\r\n")
  );

  attachWebSocket(socket);
});

server.listen(PORT, HOST, () => {
  log(`battle ws daemon listening on ${HOST}:${PORT} base=${BASE_URL}`);
});
