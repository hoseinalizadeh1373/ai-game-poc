# بازی چندنفره (Multiplayer) — راهنمای راه‌اندازی

## جریان کاربر

1. از لیست بازی‌ها روی **🌐 چندنفره** بزن (یا داخل صفحه بازی دکمه «بازی چندنفره»).
2. **ساخت اتاق** → کد ۶ حرفی بگیر و برای دوستت بفرست (یا لینک دعوت را کپی کن).
3. دوستت با **ورود با کد** وارد همان اتاق می‌شود.
4. هر دو **آماده‌ام** را می‌زنند → هر دو وارد همان بازی می‌شوند.
5. نوار بالای بازی وضعیت اتصال WebSocket و امتیاز حریف را نشان می‌دهد.

## معماری

| بخش | نقش |
|-----|-----|
| `api_room.php` + `lib_mp.php` | ساخت/ورود/آمادگی/خروج اتاق (فایل JSON در `data/rooms/`) |
| `multiplayer.php` | لابی UI |
| `ws-server/` (Node + `ws`) | رلهٔ بلادرنگ رویدادها بین دو کلاینت |
| `assets/mp-client.js` | کلاینت WebSocket در صفحهٔ والد (`play.php`) |
| Bridge داخل بازی (`window.MegaMP`) | بازی می‌تواند `reportScore` / `reportFinish` / `send` بفرستد |

- seed مشترک اتاق → `Math.random` در هر دو کلاینت یکسان می‌شود (محتوای تصادفی مشترک).
- بازی‌های فعلی بدون تغییر هم کار می‌کنند؛ امتیاز پایان از `game-stats` به حریف رله می‌شود.
- بازی‌های جدید می‌توانند از `window.MegaMP` استفاده کنند.

## راه‌اندازی سرور WebSocket

```bash
cd ws-server
npm install
npm start
# گوش می‌دهد روی پورت 8765
```

متغیر محیطی اختیاری:

```bash
MP_WS_PORT=8765 npm start
```

### اگر فرانت و WS روی دامنه/پورت متفاوت‌اند

قبل از لود شدن `mp-client.js` در صفحه:

```html
<script>
  window.MEGA_MP_WS_URL = 'wss://127.0.0.1/mp'; // یا ws://host:8765
  // یا فقط پورت:
  // window.MEGA_MP_WS_PORT = '8765';
</script>
```

برای پروداکشن معمولاً پشت nginx با path مثل `/mp` پروکسی می‌کنی:

```nginx
location /mp {
    proxy_pass http://127.0.0.1:8765;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
}
```

و در کلاینت: `window.MEGA_MP_WS_URL = (location.protocol === 'https:' ? 'wss:' : 'ws:') + '//' + location.host + '/mp';`

## API اتاق (خلاصه)

`POST api_room.php` با JSON:

- `{ "action": "create", "game_id": "...", "name": "علی" }`
- `{ "action": "join", "code": "K7M2XP", "name": "سارا" }`
- `{ "action": "ready", "code": "K7M2XP", "ready": true }`
- `{ "action": "leave", "code": "K7M2XP" }`
- `{ "action": "status", "code": "K7M2XP" }` — یا `GET ?action=status&code=...`

## استفاده داخل بازی (برای بازی‌سازان AI)

```js
if (window.MegaMP && MegaMP.enabled) {
  MegaMP.reportScore(42);
  MegaMP.reportFinish({ score: 42, stars: 3 });
  MegaMP.send('answer', { q: 1, correct: true });
  MegaMP.on('score', function (data) {
    console.log('حریف امتیاز داد', data);
  });
}
```

## نکات

- حداکثر ۲ بازیکن در هر اتاق (قابل تغییر در `lib_mp.php` → `MP_MAX_PLAYERS`).
- اتاق‌های بلااستفاده بعد از ۱ ساعت منقضی می‌شوند.
- بدون اجرای `ws-server`، لابی و اتاق‌ها کار می‌کنند ولی همگام‌سازی بلادرنگ امتیاز/رویداد قطع است (نوار وضعیت «قطع» می‌ماند).
