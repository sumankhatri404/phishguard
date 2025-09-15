/* HackerOS (simulated) — improved UX + full commands */
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[c]));

  // ===== State =====
  const state = {
    rep: 0, credits: 0,
    missionIndex: 0,
    missions: [
      { id:"m1", title:"Wi-Fi (Simulated) — Capture & Connect",
        steps:["scan","capture","crack","connect"],
        rewards:{rep:180, credits:120}
      }
    ],
    progress:{},
    fs:null, home:"/home/guest",
    radio:{scanned:false, networks:[], selected:null, connected:false},
    secrets:{},
    log:[],
    started:false
  };

  // ===== Virtual FS =====
  const DIR="dir", FILE="file";
  const dir = (children={}) => ({type:DIR, children});
  const file = (content="") => ({type:FILE, content});
  function buildFS(){
    return dir({
      home: dir({ guest: dir({
        "notes.txt": file("This is a safe simulation.\nTry 'help' in Terminal.\n"),
        found: dir({}), logs: dir({})
      }) }),
      captures: dir({}),
      etc: dir({ motd: file("Ethics first. Always get permission.\n") })
    });
  }
  function ensureFS(){ if(!state.fs) state.fs=buildFS(); }
  function normalize(parts){ const st=[]; for(const p of parts){ if(!p||p===".") continue; if(p==="..") st.pop(); else st.push(p);} return "/"+st.join("/"); }
  function resolvePath(input,cwd){
    if(!input||input===".") return cwd;
    if(input==="~") return state.home;
    if(input.startsWith("~/")) return state.home+input.slice(1);
    const raw=input.startsWith("/")?input:(cwd.replace(/\/+$/,"")+"/"+input);
    return normalize(raw.split("/"));
  }
  function getNode(abs){
    if(abs==="/") return {node:state.fs,parent:null,name:""};
    const parts=abs.split("/").filter(Boolean);
    let cur=state.fs,parent=null,name="";
    for(const p of parts){ if(!cur||cur.type!==DIR) return {node:null,parent:null,name:p}; parent=cur; name=p; cur=cur.children[p]; }
    return {node:cur||null,parent,name};
  }
  function mkdirP(abs){ const parts=abs.split("/").filter(Boolean); let cur=state.fs; for(const p of parts){ if(!cur.children[p]) cur.children[p]=dir({}); cur=cur.children[p]; if(cur.type!==DIR) return false; } return true; }
  function writeFile(abs, content, append=false){
    const {node,parent,name}=getNode(abs);
    if(!parent||parent.type!==DIR) return false;
    if(!node) parent.children[name]=file(content);
    else if(node.type===FILE) node.content = append ? (node.content+content) : content;
    else return false; return true;
  }
  function rmRecursive(abs){
    const {node,parent,name}=getNode(abs); if(!node||!parent) return false;
    if(node.type===FILE){ delete parent.children[name]; return true; }
    for(const k of Object.keys(node.children))
      rmRecursive(abs === "/" ? "/" + k : abs + "/" + k);
    delete parent.children[name]; return true;
  }
  function listNames(node){ return node&&node.type===DIR ? Object.keys(node.children).sort() : []; }

  // ===== HUD =====
  /** Makes the script more resilient by checking for elements before updating them. */
  function setMission(idx){
    state.missionIndex=idx;
    const m=state.missions[idx];
    state.progress=Object.fromEntries(m.steps.map(s=>[s,false]));
    const missionEl = $("#hudMission");
    if (missionEl) missionEl.textContent=m.title;
    renderSteps();
  }
  function renderSteps(){
    const m=state.missions[state.missionIndex]; if(!m) return;
    const stepsEl = $("#hudSteps");
    if (stepsEl) {
      stepsEl.innerHTML = m.steps.map(s =>
        `<span class="step ${state.progress[s]?'done':''}"><span class="dot"></span><span class="label">${s}</span></span>`
      ).join("");
    }
  }
  function markStep(s){
    const was = !!state.progress[s];
    if (!was && s in state.progress) {
      state.progress[s] = true;
      renderSteps();
      saveAll();
      if (s === "connect") celebrateObjective(1);
    }
  }
  function updateHUD(){
    // This function is made extra defensive. If elements are missing, it will not throw an error.
    const hudMapping = {
      "#hudRep": state.rep,
      "#hudCreds": state.credits
    };

    for (const selector in hudMapping) {
      const el = $(selector);
      if (el) {
        el.textContent = hudMapping[selector];
      }
    }
  }

  // ===== Window + Toast =====
  function win(title, size=""){
    const w=document.createElement("div");
    w.className="win "+size;
    w.style.left=(18+Math.random()*40)+"px";
    w.style.top=(86+Math.random()*60)+"px";
    w.innerHTML = `
      <div class="bar">
        <div class="title">${title}</div>
        <div class="actions">
          <button class="xbtn" data-act="min">—</button>
          <button class="xbtn" data-act="close">✕</button>
        </div>
      </div>
      <div class="body"></div>`;
    $("#workspace").appendChild(w);

    const bar=w.querySelector(".bar"); let sx=0,sy=0,ox=0,oy=0,drag=false;
    const onMove=(e)=>{ if(!drag) return; w.style.left=(ox + (e.clientX-sx))+"px"; w.style.top=(oy + (e.clientY-sy))+"px"; };
    const onUp=()=>{ drag=false; window.removeEventListener("pointermove",onMove); window.removeEventListener("pointerup",onUp); };
    bar.addEventListener("pointerdown",(e)=>{ if(e.button!==0) return; if(e.target.closest(".actions")) return; drag=true; const r=w.getBoundingClientRect(); ox=r.left; oy=r.top; sx=e.clientX; sy=e.clientY; window.addEventListener("pointermove",onMove); window.addEventListener("pointerup",onUp); });
    const actions=w.querySelector(".actions"); ["pointerdown","mousedown","click"].forEach(ev=>actions.addEventListener(ev, (e)=>e.stopPropagation()));
    w.querySelector("[data-act='close']").addEventListener("click",(e)=>{ e.stopPropagation(); w.remove(); });
    w.querySelector("[data-act='min']").addEventListener("click",(e)=>{ e.stopPropagation(); w.style.display="none"; });
    return w;
  }
  function toast(msg, icon="ℹ️"){
    const t=$("#toast"); t.innerHTML = `${icon} ${msg}`;
    t.classList.add("show"); t.style.opacity=1;
    setTimeout(()=>{ t.style.opacity=0; t.classList.remove("show"); }, 1800);
  }

  // ===== Modals / Confetti =====
  function ensureModalCSS(){
    if (document.getElementById("modalCSS")) return;
    const css = `
      .modalOverlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:1300;animation:modalFade .15s ease;backdrop-filter:blur(2px)}
      @keyframes modalFade{from{opacity:0}to{opacity:1}}
      .modalCard{width:min(560px,calc(100% - 40px));background:linear-gradient(180deg,#0f172a,#0b1220);border:1px solid rgba(255,255,255,.12);box-shadow:0 20px 60px rgba(0,0,0,.6);border-radius:16px;color:#e5e7eb;transform:scale(.98);animation:modalPop .16s ease-out forwards;font-size:15px}
      @keyframes modalPop{to{transform:scale(1)}}
      .modalHead{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.10);font-weight:800}
      .modalBody{padding:14px 16px}
      .modalFoot{padding:12px 16px;border-top:1px solid rgba(255,255,255,.10);display:flex;gap:10px;justify-content:flex-end}
      .modalFoot .btn{padding:.6rem 1rem;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#0f172a;color:#e5e7eb;font-weight:700}
      .modalFoot .btn.primary{background:#2563eb;border-color:#1d4ed8;color:#fff}
      .confettiPiece{position:fixed;top:-12px;width:8px;height:12px;opacity:.9;z-index:1290;will-change:transform,opacity}
      @keyframes confettiFall{to{transform:translateY(110vh) rotate(720deg);opacity:.95}}
    `;
    const tag = document.createElement("style");
    tag.id = "modalCSS"; tag.textContent = css;
    document.head.appendChild(tag);
  }
  function showModal({title, html, buttons}){
    ensureModalCSS();
    const ov = document.createElement("div");
    ov.className = "modalOverlay";
    ov.innerHTML = `
      <div class="modalCard">
        <div class="modalHead">${esc(title)}</div>
        <div class="modalBody">${html}</div>
        <div class="modalFoot"></div>
      </div>`;
    const foot = ov.querySelector(".modalFoot");
    (buttons||[{label:"OK"}]).forEach(b=>{
      const btn=document.createElement("button");
      btn.className="btn"+(b.primary?" primary":"");
      btn.textContent=b.label||"OK";
      btn.addEventListener("click",()=>{ b.onClick?.(); document.body.removeChild(ov); });
      foot.appendChild(btn);
    });
    document.body.appendChild(ov);
  }
  function confettiBurst(count=70){
    ensureModalCSS();
    const colors = ["#60a5fa","#a78bfa","#34d399","#fbbf24","#f472b6","#f87171"];
    for(let i=0;i<count;i++){
      const d=document.createElement("div");
      d.className="confettiPiece";
      const left = Math.random()*100;
      const dur  = 800 + Math.random()*900;
      d.style.left = left+"vw";
      d.style.background = colors[i % colors.length];
      d.style.animation = `confettiFall ${dur}ms linear forwards`;
      document.body.appendChild(d);
      setTimeout(()=>d.remove(), dur+60);
    }
  }
  function startBriefing(){
    showModal({
      title: "Mission Briefing — Wi-Fi (Simulated)",
      html: `
        <div class="hint">Objective 1</div>
        <div style="margin:.35rem 0 .6rem 0"><b>Find a network to target.</b></div>
        <ul style="margin-left:1rem;line-height:1.5">
          <li>Open <b>Scanner</b> and press <b>Scan</b>.</li>
          <li>Click a network to <b>Select</b> it.</li>
        </ul>
        <div class="hint" style="margin-top:.6rem">Everything here is 100% simulation.</div>
      `,
      buttons: [
        {label:"Open Scanner", primary:true, onClick:()=>openScanner()},
        {label:"Let’s go"}
      ]
    });
  }
  function celebrateObjective(n){
    confettiBurst(70);
    showModal({
      title: `Objective ${n} Complete!`,
      html: `<div style="margin:.2rem 0 .6rem 0">Nice work — you finished Objective ${n}.</div>
             <div class="hint">Next: Capture ➜ Crack ➜ Connect (simulated).</div>`,
      buttons: [{label:"Continue", primary:true}]
    });
  }

  // ===== Simulated Data =====
  /** Centralized function to get simulated network data, avoiding duplication. */
  function getSimulatedNetworks() {
    const networks = [
      { ssid:"YourCo-Guest",  bssid:"62:AA:11:22:33:01", ch:6,  sec:"WPA2", rssi:-48, pw:"guest123!" },
      { ssid:"CoffeeBar-WiFi", bssid:"62:AA:11:22:33:02", ch:11, sec:"WPA2", rssi:-67, pw:"coffeebar123!" },
      { ssid:"RickRolled",    bssid:"62:AA:11:22:33:03", ch:1,  sec:"WEP",  rssi:-72, pw:"never-gonna-give-you-up" }
    ];
    state.radio.networks = networks;
    state.secrets = Object.fromEntries(networks.map(n=>[n.ssid,n.pw]));
    state.radio.scanned = true;
    window.dispatchEvent(new CustomEvent("radio:scan",{detail:{networks}}));
    addLog("scan","Scan completed");
    return networks;
  }

  // ===== Save / Log =====
  const STORAGE_KEY="hackeros.save.v1";
  function saveAll(){ try{ localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); toast("Saved.","💾"); }catch{} }
  function ensureLog(){ if(!state.log) state.log = []; }
  function formatTs(ts){ const d=new Date(ts); const hh=String(d.getHours()).padStart(2,'0'); const mm=String(d.getMinutes()).padStart(2,'0'); const ss=String(d.getSeconds()).padStart(2,'0'); return `${hh}:${mm}:${ss}`; }
  function addLog(type,msg){ ensureLog(); state.log.push({ts:Date.now(), type, msg}); renderLog(); try{ saveAll(); }catch{} }
  function renderLog(){
    ensureLog();
    const list=document.getElementById("logList");
    if(!list) return;
    list.innerHTML = state.log.map(e => `<div class="logitem"><div class="ts">${formatTs(e.ts)}<div class="type">[${esc(e.type)}]</div></div><div class="msg">${esc(e.msg)}</div></div>`).join("");
    list.scrollTop=list.scrollHeight;
  }
  function toggleLog(force){
    const p = document.getElementById("logPanel");
    if (!p) return;
    const want = (typeof force === "boolean") ? force : !p.classList.contains("open");
    p.classList.toggle("open", want);
    if (want) renderLog();
  }
  function loadAll(){ try{ const raw=localStorage.getItem(STORAGE_KEY); if(!raw) return false; const s=JSON.parse(raw); Object.assign(state,s); updateHUD(); renderSteps(); renderLog(); return true; }catch{ return false; } }
  function resetAll(){ localStorage.removeItem(STORAGE_KEY); location.reload(); }

  // ===== Capture (sim) =====
  function openCaptureSession(ssid){
    if(!ssid){ toast("Select a network first.","⚠️"); return; }
    const w = win("Capture Session — simulated","tall"); w.dataset.app="capterm";
    const body = w.querySelector(".body");
    body.innerHTML = `
      <div class="term" id="capOut" style="height:100%"></div>
      <div style="margin-top:8px;display:flex;gap:8px">
        <button class="btn" id="capCopy">Copy path</button>
        <button class="btn" id="capShow">Show in Files</button>
      </div>`;
    toast("Capture started (simulation).","📡");

    const out = body.querySelector("#capOut");
    const log = s => { const d=document.createElement("div"); d.textContent=s; out.appendChild(d); out.scrollTop = out.scrollHeight; };

    const found = (state.radio.networks||[]).find(n=>n.ssid===ssid) || {};
    const bssid = found.bssid || "xx:xx:xx:xx:xx:xx";
    const ch = found.ch || "?";
    const filePath = `/captures/${ssid.replace(/\s+/g,'_')}.handshake`;

    log(`sim@lab:~$ demo capture --ssid ${ssid}`);
    log(`# simulation only — no real packets sent`);
    log(`Listening on channel ${ch} for ${ssid} (${bssid})...`);

    let bursts = 0;
    const deauthTimer = setInterval(()=>{
      bursts++;
      log(`[SIM] DeAuth burst -> BSSID [${bssid}] (broadcast)`);
      if(bursts >= 6){
        clearInterval(deauthTimer);
        log(`[SIM] EAPOL frames observed — handshake detected`);
        log(`[SIM] Writing handshake to ${filePath}`);
        writeFile(filePath, `FAKE_HANDSHAKE for ${ssid}\n`);
        markStep("capture");
        addLog("capture", "Handshake saved -> " + filePath);
        toast("Handshake saved (simulation).","🧪");
      }
    }, 450);
  }

  // ===== Crack (sim) =====
  function openCrackSession(inFile, outFile, ssid){
    const w = win("Crack Session — simulated","tall"); w.dataset.app="crackterm";
    const body = w.querySelector(".body");
    body.innerHTML = `
      <div class="term" id="crOut" style="height:100%"></div>
      <div style="margin-top:8px;display:flex;gap:8px">
        <button class="btn" id="crCopy">Copy password</button>
        <button class="btn" id="crShow">Show saved file</button>
      </div>`;
    toast("Cracking (simulation)…","🔐");

    const out = body.querySelector("#crOut");
    const log = s => { const d=document.createElement("div"); d.textContent=s; out.appendChild(d); out.scrollTop = out.scrollHeight; };

    log(`sim@lab:~$ crack --in ${inFile||"<handshake>"} --out ${outFile||"<out>"}  (simulation)`);
    log("Aircrack (simulation)"); log("");

    const pwd = ssid ? (state.secrets[ssid] || "unknown") : "unknown";
    let tested = 0, step = 0;
    const rate = 550 + Math.floor(Math.random()*120);
    const fakeHex = () => Array.from({length:16},()=>Math.floor(Math.random()*256).toString(16).padStart(2,"0").toUpperCase()).join(" ");
    const hexBlock = () => [fakeHex(), fakeHex(), fakeHex(), fakeHex()].join("\n");

    const timer = setInterval(()=>{
      step++; tested += 300 + Math.floor(Math.random()*900);
      log(` ${tested.toLocaleString()} keys tested (${rate} k/s)`);
      if(step===3){ log(""); log("Master Key   :  " + fakeHex()); }
      if(step===4){ log("Transient Key:"); log(" " + hexBlock()); }
      if(step===5){ log(""); log("EAPOL HMAC   :  " + fakeHex()); }
      if(step>=8){
        clearInterval(timer);
        log(""); log(`[SIM] KEY FOUND! Passphrase: "${pwd}"`);
        if(outFile){
          const abs = resolvePath(outFile, state.home);
          writeFile(abs, pwd + "\n");
          markStep("crack");
          addLog("crack","Saved password to " + outFile);
          toast("Password saved (simulation).","🔐");
        }
      }
    }, 500);
  }

  // ===== Router =====
  function openApp(name){
    if(name==="terminal") openTerminal();
    else if(name==="scanner") openScanner();
    else if(name==="wifi") openWifi();
    else if(name==="files") openFiles();
    else if(name==="labguide") openLabGuide();
    else if(name==="log") toggleLog();
    else if(name==="help") openHelp();
  }

  // ===== Terminal (with full basic commands) =====
  function openTerminal(){
    const existing=$(`.win[data-app="terminal"]`); if(existing){ existing.style.display=""; return; }
    ensureFS();
    const w=win("Terminal — simulated shell","tall"); w.dataset.app="terminal";
    const body=w.querySelector(".body");
    body.innerHTML = `
      <div class="term" id="termOut"></div>
      <div class="input"><span class="badge">guest@hackeros</span><input id="termIn" placeholder="type 'help'…" autocomplete="off"></div>`;
    const out=$("#termOut",body), input=$("#termIn",body);
    const printText=s=>{ const d=document.createElement('div'); d.textContent=s; out.appendChild(d); out.scrollTop=out.scrollHeight; };
    const printHTML=s=>{ const d=document.createElement('div'); d.innerHTML=s; out.appendChild(d); out.scrollTop=out.scrollHeight; };
    const ok=s=>printHTML(`<span style="color:#86efac">${s}</span>`);
    const err=s=>printHTML(`<span style="color:#fecaca">${s}</span>`);

    const ctx={ cwd: state.home, history: [] };
    printText("Welcome to the simulated shell. Nothing real is executed.");
    printText("Try: help");

    function splitPipes(line){ const parts=[]; let cur="",q=null; for(const ch of line){ if(q){ if(ch===q) q=null; cur+=ch; continue; } if(ch==="\""||ch=="'"){ q=ch; cur+=ch; continue; } if(ch==="|"){ parts.push(cur.trim()); cur=""; continue; } cur+=ch; } if(cur.trim()) parts.push(cur.trim()); return parts; }
    function tokenise(s){ const t=[]; let cur="",q=null; for(const ch of s){ if(q){ if(ch===q) q=null; else cur+=ch; continue; } if(ch==="\""||ch=="'"){ q=ch; continue; } if(/\s/.test(ch)){ if(cur){ t.push(cur); cur=""; } continue; } cur+=ch; } if(cur) t.push(cur); return t; }
    function pathDirBase(abs){ const ps=abs.split("/"); const base=ps.pop()||""; const d=ps.join("/")||"/"; return {dir:d, base}; }

    function runCommand(cmd,args,stdin=""){
      switch(cmd){
        /* Story / radio */
        case "demo": {
          const sub=(args[0]||"").toLowerCase();
          if(sub!=="capture") return "Try: demo capture [--ssid <name>] (simulation only)";
          let ssid=null; const i=args.indexOf("--ssid"); if(i>=0 && args[i+1]) ssid=args[i+1];
          if(!ssid) ssid=state.radio.selected;
          if(!ssid) return "Select a network first (scan → nets → select <idx|ssid>), or pass --ssid <name>.";
          openCaptureSession(ssid); return `Starting simulated capture for ${ssid}. Watch the Capture Session window.`;
        }
        case "learn": {
          const t=(args[0]||"").toLowerCase();
          if(!t) return "Try: learn airodump | learn deauth | learn aircrack";
          const blurbs={
            airodump:"Passive 802.11 monitoring to observe BSSIDs, channels, and handshakes (simulated).",
            deauth:"Deauth can trigger reconnection to produce EAPOL frames (simulated).",
            aircrack:"Key recovery from captured handshakes using wordlists (simulated)."
          };
          return blurbs[t] || "Unknown topic.";
        }
        case "help":
          return [
            "pwd ls cd cat echo touch mkdir rm rmdir mv cp head tail wc grep find tree clear history !!",
            "scan nets select capture crack",
            "learn airodump | learn deauth | learn aircrack",
            "demo capture [--ssid <name>]"
          ].join("\n");

        /* Sim flow */
        case "scan": {
          const networks = getSimulatedNetworks();
          if(!$(`.win[data-app="scanner"]`)) openScanner();
          ok("Discovered networks: "+networks.map(n=>n.ssid).join(", "));
          markStep("scan");
          saveAll();
          return "";
        }
        case "nets":{
          if(!state.radio.scanned) return "No scan yet. Run 'scan' first.";
          return state.radio.networks.map((n,i)=>`${i+1}. ${n.ssid}  (BSSID ${n.bssid})  ch ${n.ch}  ${n.sec}  ${n.rssi} dBm`).join("\n");
        }
        case "select":{
          if(!state.radio.scanned) return "Run 'scan' first.";
          const target=args.join(" "); if(!target) return "Usage: select <index|ssid>";
          let sel=null;
          if(/^\d+$/.test(target)) sel=state.radio.networks[parseInt(target)-1];
          else sel=state.radio.networks.find(n=>n.ssid.toLowerCase()===target.toLowerCase());
          if(!sel) return "Target not found.";
          state.radio.selected=sel.ssid;
          addLog("select","Target -> "+sel.ssid);
          window.dispatchEvent(new CustomEvent("radio:select",{detail:{ssid:sel.ssid}}));
          saveAll(); return "Target set -> "+sel.ssid;
        }
        case "capture":{
          if(!state.radio.selected) return "Select a network first (use 'select').";
          addLog("capture","Started capture for "+state.radio.selected);
          openCaptureSession(state.radio.selected);
          return "Capturing (simulation)... see Capture Session window";
        }
        case "crack":{
          let inFile=null, outFile=null;
          for(let i=0;i<args.length;i++){ if(args[i]==="--in") inFile=args[i+1]; if(args[i]==="--out") outFile=args[i+1]; }
          if(!inFile||!outFile) return "Usage: crack --in <file> --out <file>";
          const ssid=state.radio.selected; if(!ssid) return "Select a network first.";
          openCrackSession(inFile, outFile, ssid);
          return "Cracking (simulation)... see Crack Session window";
        }

        /* Basic FS (simulated) */
        case "pwd": return ctx.cwd;
        case "clear": out.textContent=""; return "";
        case "history": return ctx.history.map((c,i)=>String(i+1).padStart(3," ")+"  "+c).join("\n");
        case "cd": {
          const target=args[0]||state.home;
          const p=resolvePath(target,ctx.cwd);
          const {node}=getNode(p); if(!node) return "cd: no such file or directory";
          if(node.type!==DIR) return "cd: not a directory";
          ctx.cwd=p; return "";
        }
        case "ls": {
          let detailed=false,target=null; for(const a of args){ if(a=="-l") detailed=true; else target=a; }
          const p=resolvePath(target||".",ctx.cwd);
          const {node}=getNode(p); if(!node) return "ls: path not found";
          if(node.type===FILE) return (target||".")+"\n";
          const names=listNames(node);
          if(!detailed) return (names.join("  ") + (names.length?"\n":""));
          return names.map(n=>{
            const c=node.children[n];
            const flag=(c.type===DIR?"d":"-")+"rwxr-xr-x";
            const size=c.type===DIR?"-":(c.content||"").length;
            return `${flag} 1 guest users ${String(size).padStart(4)} ${n}`;
          }).join("\n");
        }
        case "cat": {
          if(!args.length) return stdin;
          const p=resolvePath(args[0],ctx.cwd); const {node}=getNode(p);
          if(!node) return "cat: no such file"; if(node.type!==FILE) return "cat: is a directory";
          return node.content;
        }
        case "echo": return args.join(" ");
        case "touch": {
          if(!args.length) return "touch: missing file";
          const p=resolvePath(args[0],ctx.cwd); writeFile(p,"",false); return "";
        }
        case "mkdir": {
          let pflag=false,target=null; for(const a of args){ if(a=="-p") pflag=true; else target=a; }
          if(!target) return "mkdir: missing operand";
          const p=resolvePath(target,ctx.cwd);
          if(pflag){ mkdirP(p); return ""; }
          const dirName=p.split("/").slice(0,-1).join("/")||"/";
          const par=getNode(dirName).node; if(!par) return "mkdir: parent missing";
          mkdirP(p); return "";
        }
        case "rmdir": {
          if(!args.length) return "rmdir: missing operand";
          const p=resolvePath(args[0],ctx.cwd); const {node,parent,name}=getNode(p);
          if(!node||!parent) return "rmdir: failed";
          if(node.type!==DIR||Object.keys(node.children).length) return "rmdir: not empty";
          delete parent.children[name]; return "";
        }
        case "rm": {
          let rec=false,target=null; for(const a of args){ if(a=="-r"||a=="-rf"||a=="-fr") rec=true; else target=a; }
          if(!target) return "rm: missing operand";
          const p=resolvePath(target,ctx.cwd); const {node}=getNode(p);
          if(!node) return "rm: no such file";
          if(node.type===DIR&&!rec) return "rm: is a directory";
          rmRecursive(p); return "";
        }
        case "mv": {
          if(args.length<2) return "mv: missing operands";
          const src=resolvePath(args[0],ctx.cwd);
          const dst0=resolvePath(args[1],ctx.cwd);
          const {node:srcN,parent:srcP,name:srcName}=getNode(src);
          if(!srcN||!srcP) return "mv: cannot stat";
          let dst=dst0;
          let {node:dstN}=getNode(dst0);
          if(dstN&&dstN.type===DIR) dst = (dst0 === "/" ? "/" + srcName : dst0 + "/" + srcName);
          const {node:dstPar}=getNode(dst.split("/").slice(0,-1).join("/")||"/");
          if(!dstPar||dstPar.type!==DIR) return "mv: bad dest";
          dstPar.children[dst.split("/").pop()] = srcN; delete srcP.children[srcName]; return "";
        }
        case "cp": {
          let rec=false; const rest=[]; for(const a of args){ if(a=="-r"||a=="-R") rec=true; else rest.push(a); }
          if(rest.length<2) return "cp: missing operands";
          const src=resolvePath(rest[0],ctx.cwd); const dst0=resolvePath(rest[1],ctx.cwd);
          const {node:srcN}=getNode(src); if(!srcN) return "cp: cannot stat";
          let dst=dst0; let {node:dstN}=getNode(dst0);
          if(dstN&&dstN.type===DIR) dst = (dst0 === "/" ? "/" + src.split("/").pop() : dst0 + "/" + src.split("/").pop());
          const {node:dstPar}=getNode(dst.split("/").slice(0,-1).join("/")||"/"); if(!dstPar||dstPar.type!==DIR) return "cp: bad dest";
          function clone(n){ return n.type===FILE?{type:FILE,content:String(n.content||"")}:{type:DIR,children:Object.fromEntries(Object.entries(n.children).map(([k,v])=>[k,clone(v)]))}; }
          if(srcN.type===DIR&&!rec) return "cp: -r required for directories";
          dstPar.children[dst.split('/').pop()] = clone(srcN); return "";
        }
        case "head": {
          let n=10, file=null; for(let i=0;i<args.length;i++){ if(args[i]=="-n"){ n=parseInt(args[i+1]||"10",10); i++; } else file=args[i]; }
          let text=""; if(!file) text=stdin; else { const p=resolvePath(file,ctx.cwd); const {node}=getNode(p); if(!node||node.type!==FILE) return "head: invalid"; text=node.content; }
          return text.split(/\r?\n/).slice(0,n).join("\n");
        }
        case "tail": {
          let n=10, file=null; for(let i=0;i<args.length;i++){ if(args[i]=="-n"){ n=parseInt(args[i+1]||"10",10); i++; } else file=args[i]; }
          let text=""; if(!file) text=stdin; else { const p=resolvePath(file,ctx.cwd); const {node}=getNode(p); if(!node||node.type!==FILE) return "tail: invalid"; text=node.content; }
          const lines=text.split(/\r?\n/); return lines.slice(Math.max(0,lines.length-n)).join("\n");
        }
        case "wc": {
          const flags={l:false,w:false,c:false}; const files=[];
          for(const a of args){ if(a=="-l") flags.l=true; else if(a=="-w") flags.w=true; else if(a=="-c") flags.c=true; else files.push(a); }
          function counts(s){ const bytes=(new TextEncoder().encode(s)).length; const lines=s? s.split(/\r?\n/).length-1:0; const words=s? s.trim()? s.trim().split(/\s+/).length:0:0; return {lines,words,bytes}; }
          if(!files.length){ const c=counts(stdin); const parts=[]; if(flags.l||flags.w||flags.c){ if(flags.l) parts.push(c.lines); if(flags.w) parts.push(c.words); if(flags.c) parts.push(c.bytes); } else { parts.push(c.lines,c.words,c.bytes); } return parts.join(" "); }
          let res=""; files.forEach(fn=>{ const p=resolvePath(fn,ctx.cwd); const {node}=getNode(p); if(!node||node.type!==FILE){ res+=(res?"\n":"")+`wc: ${fn}: invalid`; } else { const c=counts(node.content); const parts=[]; if(flags.l) parts.push(c.lines); if(flags.w) parts.push(c.words); if(flags.c) parts.push(c.bytes); if(!flags.l&&!flags.w&&!flags.c) parts.push(c.lines,c.words,c.bytes); res+=(res?"\n":"")+parts.join(" ")+" "+fn; } }); return res;
        }
        case "grep": {
          let withN=false; const files=[]; const pat=args[0]; if(!pat) return "grep: missing pattern";
          for(let i=1;i<args.length;i++){ if(args[i]=="-n") withN=true; else files.push(args[i]); }
          const re=new RegExp(pat); function runText(text){ const lines=text.split(/\r?\n/); return lines.map((L,i)=> re.test(L)? (withN? (i+1)+":"+L: L): null).filter(Boolean).join("\n"); }
          if(!files.length) return runText(stdin);
          let outStr=""; files.forEach(fn=>{ const p=resolvePath(fn,ctx.cwd); const {node}=getNode(p); if(node&&node.type===FILE){ const r=runText(node.content); outStr += (outStr? "\n":"") + (files.length>1? `==> ${fn} <==\n`:"") + r; } });
          return outStr;
        }
        case "find": {
          let start=".", type=null, name=null;
          for(let i=0;i<args.length;i++){ const a=args[i]; if(a==="."||a.startsWith("/")) start=a; else if(a=="-type") { type=args[i+1]; i++; } else if(a=="-name"){ name=args[i+1]; i++; } }
          const p=resolvePath(start,ctx.cwd); const {node}=getNode(p); if(!node||node.type!==DIR) return "find: invalid";
          const results=[]; function walk(path,node){ Object.keys(node.children).forEach(k=>{ const child=node.children[k]; const childPath=(path==="/" ? "/"+k : path+"/"+k); const okType=!type||(type==="f"&&child.type===FILE)||(type==="d"&&child.type===DIR); const okName=!name||new RegExp("^"+name.replace(/\*/g,".*").replace(/\./g,"\\.")+"$").test(k); if(okType&&okName) results.push(childPath); if(child.type===DIR) walk(childPath,child); }); } walk(p,node); return results.join("\n");
        }
        case "tree": {
          const target=args[0]||"."; const p=resolvePath(target,ctx.cwd); const {node}=getNode(p); if(!node) return "tree: path not found"; if(node.type!==DIR) return "tree: not a directory";
          function treeStr(node,prefix=""){ const entries=Object.keys(node.children).sort(); let o=""; entries.forEach((name,i)=>{ const last=i===entries.length-1; const branch=last?"└── ":"├── "; o+=prefix+branch+name+"\n"; const child=node.children[name]; if(child.type===DIR) o+=treeStr(child,prefix+(last?"    ":"│   ")); }); return o; }
          return ".\n"+treeStr(node);
        }
      }
      return `Unknown command: ${cmd}`;
    }

    function execLine(line){
      if(line==="!!"){ const last=ctx.history[ctx.history.length-1]; if(last) line=last; else { printText("No previous command."); return; } }
      ctx.history.push(line);
      let redirect=null, append=false;
      const m = line.match(/(.*)\s(>>|>)\s([^>]+)$/);
      let work=line;
      if(m){ work=m[1].trim(); append=(m[2]===">>"); redirect=m[3].trim(); }
      const segments = splitPipes(work);
      let stdin="";
      for(const seg of segments){
        const toks=tokenise(seg); const c=toks[0]; const args=toks.slice(1); if(!c) continue;
        const outStr = runCommand(c,args,stdin); stdin = outStr;
      }
      if(redirect){ const p=resolvePath(redirect,ctx.cwd); writeFile(p, stdin + (stdin.endsWith("\n")?"":"\n"), append); ok(`wrote ${redirect}`); return; }
      if(stdin) printText(stdin);
    }

    input.addEventListener("keydown",(e)=>{ if(e.key!=="Enter") return; const cmd=input.value; input.value=""; printText(`$ ${cmd}`); execLine(cmd); });
  }

  // ===== Scanner / Wi-Fi / Files / Help / Guide =====
  function openScanner(){ /* unchanged from earlier but kept complete */ 
    const existing=$(`.win[data-app="scanner"]`); if(existing){ existing.style.display=""; return; }
    const w=win("Network Scanner — simulated",""); w.dataset.app="scanner";
    const body=w.querySelector(".body");
    body.innerHTML = `
      <div class="grid2">
        <div class="panel">
          <div class="hint">Nearby networks (simulated). Click one to select.</div>
          <div class="list" id="netList"></div>
        </div>
        <div class="panel">
          <div class="hint">Actions</div>
          <div class="progress"><span id="scanProg"></span></div>
          <div style="margin-top:8px">
            <button class="btn" id="btnScan">Scan</button>
            <button class="btn primary" id="btnCapture">Capture Handshake</button>
          </div>
        </div>
      </div>`;
    const list=$("#netList",body), scanBar=$("#scanProg",body);
    const lvl=(rssi)=> (rssi>=-50?4:rssi>=-60?3:rssi>=-70?2:1);

    function renderList(){
      list.innerHTML="";
      const nets=state.radio.networks||[];
      if(!nets.length){ list.innerHTML = `<div class="panel">No results yet.</div>`; return; }
      nets.forEach(n=>{
        const row=document.createElement("div"); row.className="panel";
        row.style.display="grid"; row.style.gridTemplateColumns="1fr auto auto"; row.style.gap="12px"; row.style.alignItems="center";
        row.innerHTML=`<div>${n.ssid}</div><div>ch ${n.ch} · ${n.sec} · ${n.rssi} dBm <span class="sig"><i class="b1 ${lvl(n.rssi)>=1?'on':''}"></i><i class="b2 ${lvl(n.rssi)>=2?'on':''}"></i><i class="b3 ${lvl(n.rssi)>=3?'on':''}"></i><i class="b4 ${lvl(n.rssi)>=4?'on':''}"></i></span></div><div>${state.radio.selected===n.ssid?'✅ Selected':''}</div>`;
        row.addEventListener("click", ()=>{ state.radio.selected=n.ssid; addLog("select","Target -> "+n.ssid); renderList(); window.dispatchEvent(new CustomEvent("radio:select",{detail:{ssid:n.ssid}})); });
        list.appendChild(row);
      });
    }
    renderList();

    function performScanGUI(){
      scanBar.style.width="0%"; let pct=0;
      const t=setInterval(()=>{ pct+=10+Math.random()*15; scanBar.style.width=Math.min(100,pct)+"%"; if(pct>=100){ clearInterval(t);
          getSimulatedNetworks();
          toast("Scan complete — airodump-style sweep (simulated).","📡");
          saveAll();
          renderList();
      } }, 140);
    }

    $("#btnScan",body).onclick = performScanGUI;
    $("#btnCapture",body).onclick = ()=>{ if(!state.radio.selected){ toast("Select a network first.","⚠️"); return; } openCaptureSession(state.radio.selected); };

    window.addEventListener("radio:scan", renderList);
    window.addEventListener("radio:select", renderList);
  }

  function openWifi(){
    const existing=$(`.win[data-app="wifi"]`); if(existing){ existing.style.display=""; return; }
    const w=win("Wi-Fi — simulated connect",""); w.dataset.app="wifi";
    const body=w.querySelector(".body");
    body.innerHTML=`
      <div class="panel">
        <div class="hint">Pick a network and enter the password (from your cracked file).</div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:6px">
          <select id="wifiSsid"></select>
          <input id="wifiPass" placeholder="password">
          <button class="btn primary" id="wifiConnect">Connect</button>
        </div>
        <div id="wifiStatus" class="hint" style="margin-top:8px">Status: <b>disconnected</b></div>
      </div>`;
    const sel=$("#wifiSsid",body), inp=$("#wifiPass",body), status=$("#wifiStatus",body);
    function fill(){ const nets=state.radio.networks||[]; if(!nets.length){ sel.innerHTML=`<option>(no scan yet)</option>`; return; } sel.innerHTML = nets.map(n=>`<option value="${n.ssid}" ${state.radio.selected===n.ssid?"selected":""}>${n.ssid}</option>`).join(""); }
    fill();
    const onScan=()=>fill(), onSelect=()=>fill();
    window.addEventListener("radio:scan", onScan);
    window.addEventListener("radio:select", onSelect);

    $("#wifiConnect",body).onclick=()=>{
      const ssid = sel.value;
      const pw = inp.value.trim();
      if(!state.secrets[ssid]) { toast("No info for this SSID — run scan/crack first.","⚠️"); return; }
      if(pw && pw === state.secrets[ssid]){
        state.radio.connected = true; status.innerHTML="Status: <b>connected</b> ✅"; toast(`Connected to ${ssid} (simulated).`,"📶"); markStep("connect"); addLog("wifi","Connected to "+ssid);
        const m=state.missions[state.missionIndex];
        const done=m.steps.every(s=>state.progress[s]);
        if(done){
          state.rep+=m.rewards.rep; state.credits+=m.rewards.credits; updateHUD();
          toast(`Mission complete! +${m.rewards.rep} Rep • +${m.rewards.credits} Credits`,"✅");
          addLog("mission", "Completed: "+m.title); saveAll();
        }
      } else { toast("Password incorrect (simulated).","❌"); addLog("wifi","Password incorrect for "+ssid); }
    };
    w.querySelector("[data-act='close']").addEventListener("click",()=>{ window.removeEventListener("radio:scan",onScan); window.removeEventListener("radio:select",onSelect); });
  }

  function openFiles(startPath){
    ensureFS();
    const w=win("Files — virtual storage","wide");
    const body=w.querySelector(".body");
    body.innerHTML=`
      <div class="files-layout">
        <aside class="files-sidebar">
          <div class="hint">Folders</div>
          <div id="fsTree" class="tree"></div>
        </aside>
        <main class="files-main">
          <div class="toolbar"><div id="fsBreadcrumbs" class="breadcrumbs"></div></div>
          <div class="list head"><div>Name</div><div>Type/Size</div><div>Action</div></div>
          <div id="fsList" class="list"></div>
          <div id="fsPreview" class="panel" style="margin-top:10px;display:none"></div>
        </main>
      </div>`;
    let cwd = startPath || "/";
    const treeEl=$("#fsTree",body), listEl=$("#fsList",body), prevEl=$("#fsPreview",body), crumbEl=$("#fsBreadcrumbs",body);
    function parts(p){ return p.split("/").filter(Boolean); }
    function renderBreadcrumbs(){ const ps=parts(cwd); crumbEl.innerHTML=`<a href="#" data-path="/">/</a>`+ps.map((seg,i)=>{ const p="/"+ps.slice(0,i+1).join("/"); return ` / <a href="#" data-path="${esc(p)}">${esc(seg)}</a>`; }).join(""); }
    crumbEl.addEventListener("click",(e)=>{ const a=e.target.closest("a[data-path]"); if(!a) return; e.preventDefault(); const p=a.getAttribute("data-path"); const {node}=getNode(p); if(node&&node.type===DIR){ cwd=p; renderAll(); } else if(node&&node.type===FILE){ openEditor(p); } });
    function nodeHTML(node,base){ const names=Object.keys(node.children).sort(); return `<ul>`+names.map(name=>{ const child=node.children[name]; const p=base==="/"?`/${name}`:`${base}/${name}`; if(child.type===DIR) return `<li class="t-dir"><a href="#" data-path="${esc(p)}">📁 ${esc(name)}/</a>${nodeHTML(child,p)}</li>`; return `<li class="t-file"><a href="#" data-path="${esc(p)}">📄 ${esc(name)}</a></li>`; }).join("")+`</ul>`; }
    function renderTree(){ treeEl.innerHTML = nodeHTML(state.fs,"/"); }
    treeEl.addEventListener("click",(e)=>{ const a=e.target.closest("a[data-path]"); if(!a) return; e.preventDefault(); const p=a.getAttribute("data-path"); const {node}=getNode(p); if(node&&node.type===DIR){ cwd=p; renderAll(); } else if(node&&node.type===FILE){ openEditor(p); } });
    function renderList(){ const {node}=getNode(cwd); listEl.innerHTML=""; if(!node||node.type!==DIR) return; Object.keys(node.children).sort().forEach(name=>{ const child=node.children[name]; const p = (cwd === "/" ? "/" + name : cwd + "/" + name); const row=document.createElement("div"); row.className="panel"; row.style.display="grid"; row.style.gridTemplateColumns="1fr auto auto"; row.style.gap="12px"; const typeCol=child.type===DIR?"dir":`${(child.content||"").length} B`; const action=child.type===DIR?"Open":"View"; row.innerHTML = `<div>${child.type===DIR?"📁":"📄"} ${esc(name)}</div><div>${typeCol}</div><div><button class="btn" data-action="${action.toLowerCase()}" data-path="${esc(p)}">${action}</button></div>`; listEl.appendChild(row); }); }
    listEl.addEventListener("click",(e)=>{ const btn=e.target.closest("button[data-action]"); if(!btn) return; const p=btn.dataset.path; const {node}=getNode(p); if(!node) return; if(btn.dataset.action==="open" && node.type===DIR){ cwd=p; renderAll(); } if(btn.dataset.action==="view" && node.type===FILE){ openEditor(p); } });
    function openEditor(p){ const {node}=getNode(p); if(!node||node.type!==FILE) return; prevEl.style.display="block"; prevEl.innerHTML = `<div class="hint">Viewing: <b>${esc(p)}</b></div><pre style="margin-top:6px;white-space:pre-wrap">${esc(node.content)}</pre><div style="margin-top:8px;"><button class="btn" id="fsClosePrev">Close</button></div>`; $("#fsClosePrev",prevEl).onclick = ()=>{ prevEl.style.display="none"; }; }
    function renderAll(){ renderBreadcrumbs(); renderTree(); renderList(); }
    renderAll();
  }
  function openHelp(){ const w=win("Help — simulated","small"); w.querySelector(".body").innerHTML="<div class='panel'><b>Mission 1 flow:</b> scan → select → capture → crack → connect. Everything is simulated.</div>"; }
  function openLabGuide(){ const w=win("Lab Guide — simulation & ethics","wide"); const b=w.querySelector(".body"); const m=state.missions[state.missionIndex]; const checklist=(m?m.steps:[]).map(s=>`<li class="${state.progress[s]?'done':''}">${esc(s)}</li>`).join(""); b.innerHTML=`<div class="panel" style="border-left:6px solid #ef4444"><b>Ethics & Legal:</b> This is a <i>simulation</i>. Never attack networks you don't own or lack permission to test.</div><div class="grid2" style="margin-top:10px"><div class="panel"><div class="hint">Mission checklist</div><ol style="margin-top:6px">${checklist}</ol></div><div class="panel"><div class="hint">802.11 basics</div><ul style="margin-top:6px"><li><b>SSID</b>: network name</li><li><b>BSSID</b>: AP MAC</li><li><b>Channel</b>: 1/6/11…</li><li><b>EAPOL</b>: WPA handshake frames</li><li><b>Handshake</b>: client+AP exchange proof</li></ul></div></div>`; }

  // ===== Boot =====
  window.addEventListener("DOMContentLoaded", () => {
    // Floating actions
    $("#btnStart").addEventListener("click", () => {
      setMission(0);
      state.started = true;
      $("#btnStart").style.display = "none";
      saveAll();
      openTerminal();
      startBriefing();
    });
    $("#btnSave").addEventListener("click", saveAll);
    $("#btnReset").addEventListener("click", resetAll);

    // Dock
    $$(".dock-btn[data-app]").forEach(b => b.addEventListener("click", () => openApp(b.dataset.app)));

    // Cap/Crack window actions
    document.addEventListener("click", (e)=>{
      const w = e.target.closest(".win"); if(!w) return;
      if(w.dataset.app==="capterm"){
        if(e.target.id==="capCopy"){
          const term = w.querySelector("#capOut");
          const lines = Array.from(term.children).map(d=>d.textContent);
          const pathLine = lines.find(s=>s.includes("Writing handshake to "));
          const filePath = pathLine ? pathLine.split("Writing handshake to ")[1] : null;
          if(!filePath){ toast("No path yet.","⚠️"); return; }
          navigator.clipboard?.writeText(filePath.trim()).then(()=>toast("Path copied.","📋"));
        }
        if(e.target.id==="capShow"){ openFiles("/captures"); }
      }
      if(w.dataset.app==="crackterm"){
        if(e.target.id==="crCopy"){
          const term = w.querySelector("#crOut");
          const lines = Array.from(term.children).map(d=>d.textContent);
          const keyLine = lines.find(s=>s.includes('KEY FOUND! Passphrase: "'));
          const pw = keyLine ? keyLine.split('KEY FOUND! Passphrase: "')[1].split('"')[0] : null;
          if(!pw){ toast("No password yet.","⚠️"); return; }
          navigator.clipboard?.writeText(pw).then(()=>toast("Password copied.","📋"));
        }
        if(e.target.id==="crShow"){ openFiles("/home/guest/found"); }
      }
    });

    // Log panel controls
    $("#logClose")?.addEventListener("click", ()=>toggleLog(false));
    $("#logClear")?.addEventListener("click", ()=>{ state.log=[]; renderLog(); saveAll(); });
    $("#logExport")?.addEventListener("click", ()=>{
      ensureFS(); ensureLog();
      const lines = state.log.map(e=>`${new Date(e.ts).toISOString()} [${e.type}] ${e.msg}`);
      const p="/home/guest/logs/mission.log"; mkdirP("/home/guest/logs"); writeFile(p, lines.join("\n")+"\n");
      toast("Exported to ~/logs/mission.log","📄");
    });
    window.addEventListener("keydown", (e)=>{ if (e.key === "Escape") toggleLog(false); });

    // Init
    ensureFS(); updateHUD(); renderSteps();
    const restored=loadAll();
    if (state.started) $("#btnStart").style.display="none";
    if(!restored) toast("Welcome to HackerOS (simulation).","ℹ️");
  });
})();
