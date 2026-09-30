// Shared helper functions auto-injected into every generated game.
// Each documented function below is announced to the target AI in the
// prompt via the "@helper" comment convention — add a new helper here
// with the same comment style and it appears automatically next time.

// @helper shuffleArray(arr) — returns a NEW shuffled copy of the array, does not mutate the original
function shuffleArray(arr){const a=arr.slice();for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]];}return a;}

// @helper pickRandom(arr) — returns one random item from the array
function pickRandom(arr){return arr[Math.floor(Math.random()*arr.length)];}

// @helper playTone(kind) — plays a short offline beep; kind is "correct" or "wrong", no audio files needed
function playTone(kind){try{const ctx=new (window.AudioContext||window.webkitAudioContext)();const o=ctx.createOscillator();const g=ctx.createGain();o.connect(g);g.connect(ctx.destination);o.frequency.value=kind==='correct'?880:220;o.type='sine';g.gain.setValueAtTime(.2,ctx.currentTime);g.gain.exponentialRampToValueAtTime(.001,ctx.currentTime+.3);o.start();o.stop(ctx.currentTime+.3);}catch(e){}}

// @helper launchConfetti(count) — spawns a short colorful confetti burst across the screen (pairs with the .confetti-piece CSS class)
function launchConfetti(count){count=count||30;const colors=['#f87171','#fbbf24','#34d399','#60a5fa','#a78bfa'];for(let i=0;i<count;i++){const el=document.createElement('div');el.className='confetti-piece';el.style.left=Math.random()*100+'vw';el.style.background=colors[i%colors.length];el.style.animationDuration=(2+Math.random()*1.5)+'s';document.body.appendChild(el);setTimeout(()=>el.remove(),4000);}}

// @helper vibrateDevice(ms) — safely triggers device vibration feedback if supported, does nothing otherwise
function vibrateDevice(ms){if(navigator.vibrate)navigator.vibrate(ms||100);}

// @helper clamp(v,min,max) — limits a number to the range min..max
function clamp(v,min,max){return Math.max(min,Math.min(max,v));}

// @helper rectsOverlap(a,b) — true when two boxes {x,y,w,h} overlap (screen coordinates: x right, y DOWN)
function rectsOverlap(a,b){return a.x<b.x+b.w&&a.x+a.w>b.x&&a.y<b.y+b.h&&a.y+a.h>b.y;}

// @helper stepBody(b,dt,groundY) — one physics step (dt in seconds) for a body {x,y,w,h,vx,vy}: y grows DOWN, adds gravity to vy, moves x/y, lands on the floor line groundY and sets b.onGround
function stepBody(b,dt,groundY){b.vy=(b.vy||0)+2000*dt;b.x+=(b.vx||0)*dt;b.y+=b.vy*dt;b.onGround=false;if(b.y+b.h>=groundY){b.y=groundY-b.h;b.vy=0;b.onGround=true;}}

// @helper jumpBody(b,speed) — makes a body jump upwards (speed about 700-800) only if b.onGround
function jumpBody(b,speed){if(b.onGround){b.vy=-speed;b.onGround=false;}}