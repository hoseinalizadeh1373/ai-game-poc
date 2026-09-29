/**
 * Mega Game — multiplayer client (parent page).
 * Connects to WS relay, mirrors events into the game iframe via postMessage.
 */
(function (global) {
  'use strict';

  function resolveWsUrl(explicit) {
    if (explicit) return explicit;
    var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    // Default: same host, port 8765. Override with window.MEGA_MP_WS_URL
    var host = location.hostname || '127.0.0.1';
    var port = (global.MEGA_MP_WS_PORT || '8765');
    return proto + '//' + host + ':' + port;
  }

  function MegaMpClient(opts) {
    opts = opts || {};
    this.wsUrl = resolveWsUrl(opts.wsUrl);
    this.room = String(opts.room || '').toUpperCase();
    this.playerId = opts.playerId || '';
    this.name = opts.name || 'بازیکن';
    this.ws = null;
    this.connected = false;
    this.peers = [];
    this._handlers = {};
    this._retry = 0;
    this._closed = false;
    this._iframe = opts.iframe || null;
  }

  MegaMpClient.prototype.on = function (type, cb) {
    if (!this._handlers[type]) this._handlers[type] = [];
    this._handlers[type].push(cb);
    return this;
  };

  MegaMpClient.prototype._emit = function (type, data) {
    (this._handlers[type] || []).forEach(function (cb) {
      try {
        cb(data);
      } catch (e) {
        console.warn('[MegaMP]', e);
      }
    });
  };

  MegaMpClient.prototype.connect = function () {
    var self = this;
    if (this._closed) return;
    if (this.ws && (this.ws.readyState === 0 || this.ws.readyState === 1)) return;

    var url = this.wsUrl;
    var ws;
    try {
      ws = new WebSocket(url);
    } catch (e) {
      this._emit('error', { message: 'WebSocket ساخته نشد: ' + e.message });
      this._scheduleReconnect();
      return;
    }
    this.ws = ws;

    ws.onopen = function () {
      self.connected = true;
      self._retry = 0;
      self._emit('open');
      ws.send(
        JSON.stringify({
          type: 'join',
          room: self.room,
          playerId: self.playerId,
          name: self.name,
        })
      );
    };

    ws.onmessage = function (ev) {
      var msg;
      try {
        msg = JSON.parse(ev.data);
      } catch {
        return;
      }
      if (!msg || !msg.type) return;

      switch (msg.type) {
        case 'welcome':
          self.peers = msg.peers || [];
          self._emit('welcome', msg);
          self._emit('peers', self.peers);
          break;
        case 'peer_joined':
        case 'peer_left':
          self.peers = msg.peers || self.peers;
          self._emit(msg.type, msg);
          self._emit('peers', self.peers);
          break;
        case 'event':
          self._emit('event', msg);
          self._forwardToGame(msg);
          break;
        case 'error':
          self._emit('error', msg);
          break;
        case 'pong':
          break;
        default:
          self._emit(msg.type, msg);
      }
    };

    ws.onclose = function () {
      self.connected = false;
      self._emit('close');
      self._scheduleReconnect();
    };

    ws.onerror = function () {
      self._emit('error', { message: 'خطای اتصال WebSocket' });
    };
  };

  MegaMpClient.prototype._scheduleReconnect = function () {
    var self = this;
    if (this._closed) return;
    this._retry = Math.min(this._retry + 1, 8);
    var delay = Math.min(1000 * Math.pow(1.6, this._retry), 15000);
    setTimeout(function () {
      if (!self._closed) self.connect();
    }, delay);
  };

  MegaMpClient.prototype.sendEvent = function (event, data) {
    if (!this.ws || this.ws.readyState !== 1) return false;
    this.ws.send(
      JSON.stringify({
        type: 'event',
        event: event,
        data: data || {},
      })
    );
    return true;
  };

  MegaMpClient.prototype.setReady = function (ready) {
    if (!this.ws || this.ws.readyState !== 1) return false;
    this.ws.send(JSON.stringify({ type: 'ready', ready: !!ready }));
    return true;
  };

  MegaMpClient.prototype._forwardToGame = function (msg) {
    var frame = this._iframe || document.getElementById('gameFrame');
    if (!frame || !frame.contentWindow) return;
    try {
      frame.contentWindow.postMessage(
        {
          source: 'megamp-parent',
          type: msg.event || 'event',
          data: Object.assign({}, msg.data || {}, { from: msg.from }),
        },
        '*'
      );
    } catch (e) {}
  };

  /** Parent listens for messages from the game bridge and relays them */
  MegaMpClient.prototype.attachIframeBridge = function (iframe) {
    var self = this;
    this._iframe = iframe || document.getElementById('gameFrame');
    window.addEventListener('message', function (e) {
      var d = e.data;
      if (!d || d.source !== 'megamp') return;
      if (d.type === 'bridge_ready') {
        self._emit('bridge_ready', d.data);
        return;
      }
      // relay game events to peers
      self.sendEvent(d.type, d.data);
      self._emit('local_event', { event: d.type, data: d.data });
    });
  };

  MegaMpClient.prototype.close = function () {
    this._closed = true;
    try {
      if (this.ws) {
        this.ws.send(JSON.stringify({ type: 'leave' }));
        this.ws.close();
      }
    } catch (e) {}
  };

  global.MegaMpClient = MegaMpClient;
})(typeof window !== 'undefined' ? window : this);
