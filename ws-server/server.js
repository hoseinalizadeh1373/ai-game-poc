/**
 * Mega Game — WebSocket relay (zero dependency, pure Node.js)
 *
 * فقط با Node اجرا می‌شود — نیازی به npm install نیست:
 *   node server.js
 *
 * پورت پیش‌فرض: 8765
 * تغییر پورت:  set MP_WS_PORT=9000 && node server.js   (Windows)
 *              MP_WS_PORT=9000 node server.js           (Linux/Mac)
 *
 * تست سلامت:  مرورگر یا curl → http://127.0.0.1:8765/health
 */

'use strict';

const http = require('http');
const crypto = require('crypto');

const PORT = Number(process.env.MP_WS_PORT || 8765);

// ---------------------------------------------------------------------------
// Minimal WebSocket (RFC6455) — text frames only, enough for our JSON relay
// ---------------------------------------------------------------------------

const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

function acceptKey(secWebSocketKey) {
  return crypto
    .createHash('sha1')
    .update(secWebSocketKey + GUID)
    .digest('base64');
}

function encodeTextFrame(str) {
  const payload = Buffer.from(str, 'utf8');
  const len = payload.length;
  let header;
  if (len < 126) {
    header = Buffer.alloc(2);
    header[0] = 0x81;
    header[1] = len;
  } else if (len < 65536) {
    header = Buffer.alloc(4);
    header[0] = 0x81;
    header[1] = 126;
    header.writeUInt16BE(len, 2);
  } else {
    header = Buffer.alloc(10);
    header[0] = 0x81;
    header[1] = 127;
    header.writeUInt32BE(0, 2);
    header.writeUInt32BE(len, 6);
  }
  return Buffer.concat([header, payload]);
}

function encodeCloseFrame(code) {
  code = code || 1000;
  const buf = Buffer.alloc(4);
  buf[0] = 0x88;
  buf[1] = 2;
  buf.writeUInt16BE(code, 2);
  return buf;
}

function encodePongFrame(payload) {
  const data = payload || Buffer.alloc(0);
  const header = Buffer.alloc(2);
  header[0] = 0x8a;
  header[1] = data.length;
  return Buffer.concat([header, data]);
}

function parseFrames(buffer) {
  const messages = [];
  const pingPayloads = [];
  let closed = false;
  let offset = 0;

  while (offset + 2 <= buffer.length) {
    const b0 = buffer[offset];
    const b1 = buffer[offset + 1];
    const opcode = b0 & 0x0f;
    const masked = (b1 & 0x80) !== 0;
    let payloadLen = b1 & 0x7f;
    let headerLen = 2;

    if (payloadLen === 126) {
      if (offset + 4 > buffer.length) break;
      payloadLen = buffer.readUInt16BE(offset + 2);
      headerLen = 4;
    } else if (payloadLen === 127) {
      if (offset + 10 > buffer.length) break;
      payloadLen = buffer.readUInt32BE(offset + 6);
      headerLen = 10;
    }

    const maskLen = masked ? 4 : 0;
    const total = headerLen + maskLen + payloadLen;
    if (offset + total > buffer.length) break;

    let payload = buffer.slice(offset + headerLen + maskLen, offset + total);
    if (masked) {
      const mask = buffer.slice(offset + headerLen, offset + headerLen + 4);
      const decoded = Buffer.alloc(payloadLen);
      for (let i = 0; i < payloadLen; i++) {
        decoded[i] = payload[i] ^ mask[i % 4];
      }
      payload = decoded;
    }

    offset += total;

    if (opcode === 0x8) {
      closed = true;
      break;
    } else if (opcode === 0x9) {
      pingPayloads.push(payload);
    } else if (opcode === 0xa) {
      // pong
    } else if (opcode === 0x1) {
      messages.push(payload.toString('utf8'));
    }
  }

  return {
    messages: messages,
    pingPayloads: pingPayloads,
    closed: closed,
    rest: buffer.slice(offset),
  };
}

class MiniWebSocket {
  constructor(socket) {
    this.socket = socket;
    this.readyState = 1;
    this._buf = Buffer.alloc(0);
    this.onmessage = null;
    this.onclose = null;
    this.onerror = null;
    this.isAlive = true;

    const self = this;
    socket.on('data', function (chunk) {
      self._onData(chunk);
    });
    socket.on('close', function () {
      self.readyState = 3;
      if (self.onclose) self.onclose();
    });
    socket.on('error', function (err) {
      if (self.onerror) self.onerror(err);
      self.close();
    });
  }

  _onData(chunk) {
    this._buf = Buffer.concat([this._buf, chunk]);
    const result = parseFrames(this._buf);
    this._buf = result.rest;

    for (let i = 0; i < result.pingPayloads.length; i++) {
      try {
        this.socket.write(encodePongFrame(result.pingPayloads[i]));
      } catch (e) {}
      this.isAlive = true;
    }

    for (let i = 0; i < result.messages.length; i++) {
      if (this.onmessage) {
        try {
          this.onmessage({ data: result.messages[i] });
        } catch (e) {}
      }
    }

    if (result.closed) {
      this.close();
    }
  }

  send(data) {
    if (this.readyState !== 1) return;
    try {
      this.socket.write(encodeTextFrame(String(data)));
    } catch (e) {
      this.close();
    }
  }

  ping() {
    if (this.readyState !== 1) return;
    try {
      this.socket.write(Buffer.from([0x89, 0x00]));
    } catch (e) {}
  }

  close() {
    if (this.readyState === 3) return;
    this.readyState = 3;
    try {
      this.socket.write(encodeCloseFrame());
      this.socket.end();
    } catch (e) {
      try {
        this.socket.destroy();
      } catch (e2) {}
    }
    if (this.onclose) {
      const cb = this.onclose;
      this.onclose = null;
      try {
        cb();
      } catch (e) {}
    }
  }

  terminate() {
    this.readyState = 3;
    try {
      this.socket.destroy();
    } catch (e) {}
    if (this.onclose) {
      const cb = this.onclose;
      this.onclose = null;
      try {
        cb();
      } catch (e) {}
    }
  }
}

// ---------------------------------------------------------------------------
// Room relay
// ---------------------------------------------------------------------------

const rooms = new Map();
const meta = new WeakMap();

function roomPeers(roomCode) {
  const map = rooms.get(roomCode);
  if (!map) return [];
  const list = [];
  for (const entry of map) {
    const id = entry[0];
    const sock = entry[1];
    const m = meta.get(sock);
    list.push({
      id: id,
      name: (m && m.name) || 'بازیکن',
      connected: sock.readyState === 1,
    });
  }
  return list;
}

function broadcast(roomCode, payload, exceptPlayerId) {
  const map = rooms.get(roomCode);
  if (!map) return;
  const raw = JSON.stringify(payload);
  for (const entry of map) {
    const id = entry[0];
    const sock = entry[1];
    if (exceptPlayerId && id === exceptPlayerId) continue;
    if (sock.readyState === 1) {
      try {
        sock.send(raw);
      } catch (e) {}
    }
  }
}

function send(ws, payload) {
  if (ws.readyState === 1) {
    try {
      ws.send(JSON.stringify(payload));
    } catch (e) {}
  }
}

function leave(ws) {
  const m = meta.get(ws);
  if (!m) return;
  const room = m.room;
  const playerId = m.playerId;
  const map = rooms.get(room);
  if (map) {
    map.delete(playerId);
    if (map.size === 0) rooms.delete(room);
    else {
      broadcast(room, {
        type: 'peer_left',
        playerId: playerId,
        peers: roomPeers(room),
      });
    }
  }
  meta.delete(ws);
}

function handleMessage(ws, buf) {
  let msg;
  try {
    msg = JSON.parse(String(buf));
  } catch (e) {
    send(ws, { type: 'error', message: 'invalid json' });
    return;
  }
  if (!msg || typeof msg.type !== 'string') return;

  switch (msg.type) {
    case 'ping':
      send(ws, { type: 'pong' });
      break;

    case 'join': {
      const room = String(msg.room || '')
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 8);
      const playerId = String(msg.playerId || '').slice(0, 32);
      const name = String(msg.name || 'بازیکن').slice(0, 24);
      if (!room || !playerId) {
        send(ws, { type: 'error', message: 'room و playerId لازم است' });
        return;
      }

      leave(ws);

      if (!rooms.has(room)) rooms.set(room, new Map());
      const map = rooms.get(room);

      if (map.has(playerId)) {
        try {
          map.get(playerId).close();
        } catch (e) {}
      }
      map.set(playerId, ws);
      meta.set(ws, { room: room, playerId: playerId, name: name });

      const peers = roomPeers(room);
      send(ws, { type: 'welcome', playerId: playerId, room: room, peers: peers });
      broadcast(
        room,
        {
          type: 'peer_joined',
          player: { id: playerId, name: name, connected: true },
          peers: peers,
        },
        playerId
      );
      break;
    }

    case 'leave':
      leave(ws);
      send(ws, { type: 'left' });
      break;

    case 'ready':
    case 'event': {
      const m = meta.get(ws);
      if (!m) {
        send(ws, { type: 'error', message: 'اول join کن' });
        return;
      }
      if (msg.type === 'ready') {
        broadcast(
          m.room,
          {
            type: 'event',
            from: m.playerId,
            event: 'ready',
            data: { ready: !!msg.ready },
          },
          m.playerId
        );
      } else {
        const event = String(msg.event || 'message').slice(0, 40);
        const data = msg.data && typeof msg.data === 'object' ? msg.data : {};
        broadcast(
          m.room,
          {
            type: 'event',
            from: m.playerId,
            event: event,
            data: data,
          },
          m.playerId
        );
      }
      break;
    }

    default:
      send(ws, { type: 'error', message: 'unknown type: ' + msg.type });
  }
}

// ---------------------------------------------------------------------------
// HTTP + upgrade
// ---------------------------------------------------------------------------

const server = http.createServer(function (req, res) {
  if (req.url === '/health' || req.url === '/') {
    res.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': '*',
    });
    res.end(
      JSON.stringify({
        ok: true,
        service: 'Mega Game WebSocket',
        port: PORT,
        rooms: rooms.size,
        hint: 'WebSocket: ws://127.0.0.1:' + PORT,
      })
    );
    return;
  }
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not found');
});

server.on('upgrade', function (req, socket, head) {
  const key = req.headers['sec-websocket-key'];
  if (!key) {
    socket.destroy();
    return;
  }

  const accept = acceptKey(key);
  const responseHeaders = [
    'HTTP/1.1 101 Switching Protocols',
    'Upgrade: websocket',
    'Connection: Upgrade',
    'Sec-WebSocket-Accept: ' + accept,
    '',
    '',
  ].join('\r\n');

  socket.write(responseHeaders);

  const ws = new MiniWebSocket(socket);
  if (head && head.length) {
    ws._onData(head);
  }

  ws.onmessage = function (ev) {
    handleMessage(ws, ev.data);
  };
  ws.onclose = function () {
    leave(ws);
  };
  ws.onerror = function () {
    leave(ws);
  };
});

const interval = setInterval(function () {
  rooms.forEach(function (map) {
    map.forEach(function (ws) {
      if (ws.isAlive === false) {
        leave(ws);
        ws.terminate();
        return;
      }
      ws.isAlive = false;
      ws.ping();
    });
  });
}, 25000);

server.listen(PORT, '0.0.0.0', function () {
  console.log('');
  console.log('  ==========================================');
  console.log('   Mega Game — WebSocket Server (local)');
  console.log('  ==========================================');
  console.log('');
  console.log('  HTTP health : http://127.0.0.1:' + PORT + '/health');
  console.log('  WebSocket   : ws://127.0.0.1:' + PORT);
  console.log('');
  console.log('  مرورگر بازی باید به همین آدرس وصل شود.');
  console.log('  برای توقف: Ctrl+C');
  console.log('');
});

process.on('SIGINT', function () {
  clearInterval(interval);
  server.close();
  process.exit(0);
});
