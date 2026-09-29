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
