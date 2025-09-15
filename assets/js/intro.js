// assets/js/intro.js
(function(){
  const canvases = Array.from(document.querySelectorAll('.canvas'));
  const lines = Array.from(document.querySelectorAll('.dialog .line'));
  const prevBtn = document.getElementById('prevBtn');
  const nextBtn = document.getElementById('nextBtn');
  const startBtn = document.getElementById('startBtn');
  const skipBtn = document.getElementById('skipBtn');

  let i = 0;
  function show(idx){
    i = Math.max(0, Math.min(canvases.length-1, idx));
    canvases.forEach((c, n)=>c.classList.toggle('active', n===i));
    lines.forEach((p)=>p.classList.remove('active'));
    const activeLine = document.querySelector(`.dialog .line[data-step="${i+1}"]`);
    if (activeLine) activeLine.classList.add('active');

    prevBtn.style.display = i===0 ? 'none':'inline-block';
    nextBtn.style.display = i===canvases.length-1 ? 'none':'inline-block';
    startBtn.style.display = i===canvases.length-1 ? 'inline-grid':'none';
  }

  async function markSeenAndGo(){
    try {
      await fetch('intro_complete.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ module_id: PG_INTRO.moduleId })
      });
    } catch(e){}
    window.location.href = PG_INTRO.go; // simulation page
  }

  prevBtn.addEventListener('click', ()=>show(i-1));
  nextBtn.addEventListener('click', ()=>show(i+1));
  startBtn.addEventListener('click', (e)=>{ e.preventDefault(); markSeenAndGo(); });
  skipBtn.addEventListener('click', (e)=>{ e.preventDefault(); markSeenAndGo(); });

  // Keyboard shortcuts
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'ArrowRight' || e.key === ' ') show(i+1);
    if (e.key === 'ArrowLeft') show(i-1);
  });

  show(0);
})();
