// Draws one 800x800 product image per spice (as a PNG) using headless Chrome.
//   node make-images.js
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const products = JSON.parse(fs.readFileSync(path.join(__dirname, 'products.json'), 'utf8'));
const outDir = path.join(__dirname, '..', 'images');
fs.mkdirSync(outDir, { recursive: true });

const html = `<!doctype html><html><head><meta charset="utf-8"><style>
  html,body{margin:0;background:#fff}
  .tile{width:800px;height:800px;position:relative;overflow:hidden;font-family:Georgia,'Times New Roman',serif}
  .tile svg{position:absolute;inset:0}
  .name{position:absolute;left:40px;right:40px;bottom:118px;text-align:center;color:#0B3B2E;font-size:58px;font-weight:700;line-height:1.08}
  .tag{position:absolute;left:0;right:0;top:34px;text-align:center;color:#8a6a1f;font-size:24px;letter-spacing:.32em;text-transform:uppercase;font-family:Arial,sans-serif;font-weight:700}
  .band{position:absolute;left:0;right:0;bottom:0;height:78px;background:#0B3B2E;color:#E4C77E;text-align:center;line-height:78px;font-size:26px;letter-spacing:.14em;font-family:Arial,sans-serif;font-weight:700}
</style></head><body></body></html>`;

const draw = `
function rng(seed){let s=seed>>>0;return()=>{s=(s*1664525+1013904223)>>>0;return s/4294967296;};}
function hash(str){let h=2166136261;for(const c of str){h^=c.charCodeAt(0);h=Math.imul(h,16777619);}return h>>>0;}
function shade(hex,amt){const n=parseInt(hex.slice(1),16);let r=(n>>16)+amt,g=((n>>8)&255)+amt,b=(n&255)+amt;const c=v=>Math.max(0,Math.min(255,v));return '#'+[c(r),c(g),c(b)].map(v=>v.toString(16).padStart(2,'0')).join('');}
function mound(p,R){
  const cx=400,base=520,parts=[],[c1,c2,c3]=p.colors;
  parts.push('<ellipse cx="400" cy="'+(base+70)+'" rx="290" ry="50" fill="#000" opacity=".10"/>');
  if(p.kind==='whole'||p.kind==='star'||p.kind==='leaf'){
    const N=p.kind==='whole'?620:130;
    const pts=[];
    for(let i=0;i<N;i++){const a=R()*Math.PI,r=Math.sqrt(R());const x=cx+Math.cos(a)*r*250,y=base-Math.sin(a)*r*190+(R()*30);pts.push([x,y]);}
    pts.sort((a,b)=>a[1]-b[1]);
    for(const [x,y] of pts){
      const col=[c1,c2,c3][Math.floor(R()*3)],sz=p.kind==='whole'?(9+R()*8):(26+R()*12),rot=Math.floor(R()*180);
      if(p.kind==='whole') parts.push('<ellipse cx="'+x.toFixed(1)+'" cy="'+y.toFixed(1)+'" rx="'+sz+'" ry="'+(sz*0.78)+'" transform="rotate('+rot+' '+x.toFixed(1)+' '+y.toFixed(1)+')" fill="'+col+'" stroke="'+shade(col,-35)+'" stroke-width="1.5"/><ellipse cx="'+(x-sz*0.25).toFixed(1)+'" cy="'+(y-sz*0.25).toFixed(1)+'" rx="'+(sz*0.32)+'" ry="'+(sz*0.2)+'" fill="'+shade(col,55)+'" opacity=".45"/>');
      else if(p.kind==='star'){let d='';for(let k=0;k<16;k++){const ang=k*Math.PI/8,rr=(k%2?sz*0.45:sz);d+=(k?'L':'M')+(x+Math.cos(ang)*rr).toFixed(1)+' '+(y+Math.sin(ang)*rr).toFixed(1);}parts.push('<path d="'+d+'Z" transform="rotate('+rot+' '+x.toFixed(1)+' '+y.toFixed(1)+')" fill="'+col+'" stroke="'+shade(col,-40)+'" stroke-width="2"/><circle cx="'+x.toFixed(1)+'" cy="'+y.toFixed(1)+'" r="'+(sz*0.16)+'" fill="'+shade(col,-45)+'"/>');}
      else parts.push('<g transform="rotate('+rot+' '+x.toFixed(1)+' '+y.toFixed(1)+')"><ellipse cx="'+x.toFixed(1)+'" cy="'+y.toFixed(1)+'" rx="'+(sz*1.2)+'" ry="'+(sz*0.5)+'" fill="'+col+'" stroke="'+shade(col,-40)+'" stroke-width="2"/><line x1="'+(x-sz*1.1).toFixed(1)+'" y1="'+y.toFixed(1)+'" x2="'+(x+sz*1.1).toFixed(1)+'" y2="'+y.toFixed(1)+'" stroke="'+shade(col,-50)+'" stroke-width="2"/></g>');
    }
  } else if(p.kind==='stick'){
    for(let i=0;i<9;i++){
      const a=-40+i*10+(R()*4),col=[c1,c2,c3][i%3],len=330+R()*40;
      parts.push('<g transform="translate(400 '+(base+30)+') rotate('+a+')"><rect x="-17" y="-'+len+'" width="34" height="'+len+'" rx="17" fill="'+col+'" stroke="'+shade(col,-45)+'" stroke-width="2"/><rect x="-6" y="-'+(len-10)+'" width="8" height="'+(len-30)+'" rx="4" fill="'+shade(col,45)+'" opacity=".5"/><ellipse cx="0" cy="-'+len+'" rx="17" ry="8" fill="'+shade(col,-60)+'"/></g>');
    }
    parts.push('<rect x="345" y="'+(base-95)+'" width="110" height="22" rx="8" fill="#0B3B2E"/>');
  } else {
    const powder=p.kind==='powder';
    if(!powder){parts.push('<ellipse cx="400" cy="'+(base+20)+'" rx="285" ry="95" fill="#3a2a1b"/><ellipse cx="400" cy="'+(base-5)+'" rx="265" ry="80" fill="#5a422b"/>');}
    parts.push('<defs><radialGradient id="g" cx="50%" cy="30%" r="75%"><stop offset="0" stop-color="'+shade(c2,35)+'"/><stop offset=".6" stop-color="'+c1+'"/><stop offset="1" stop-color="'+c3+'"/></radialGradient></defs>');
    parts.push('<path d="M'+(cx-255)+' '+(base+15)+' C'+(cx-200)+' '+(base-60)+' '+(cx-90)+' '+(base-215)+' '+cx+' '+(base-225)+' C'+(cx+90)+' '+(base-215)+' '+(cx+200)+' '+(base-60)+' '+(cx+255)+' '+(base+15)+' C'+(cx+150)+' '+(base+62)+' '+(cx-150)+' '+(base+62)+' '+(cx-255)+' '+(base+15)+'Z" fill="url(#g)"/>');
    for(let i=0;i<340;i++){const a=R()*Math.PI,r=Math.sqrt(R());const x=cx+Math.cos(a)*r*225,y=base-Math.sin(a)*r*175+R()*24;parts.push('<circle cx="'+x.toFixed(1)+'" cy="'+y.toFixed(1)+'" r="'+(1.2+R()*3.2).toFixed(1)+'" fill="'+[shade(c1,28),shade(c3,-18),c2][Math.floor(R()*3)]+'" opacity=".55"/>');}
  }
  return parts.join('');
}
for(const p of PRODUCTS){
  const R=rng(hash(p.name));
  const t=document.createElement('div');t.className='tile';t.id=p.file;
  t.innerHTML='<svg width="800" height="800" viewBox="0 0 800 800"><defs><radialGradient id="bg" cx="50%" cy="38%" r="75%"><stop offset="0" stop-color="#fbf6ea"/><stop offset="1" stop-color="#eadfc4"/></radialGradient></defs><rect width="800" height="800" fill="url(#bg)"/>'+mound(p,R)+'</svg><div class="tag">'+p.sub+'</div><div class="name">'+p.name+'</div><div class="band">5STAR SPICES</div>';
  document.body.appendChild(t);
}
`;

const page = html.replace('</body>', `<script>const PRODUCTS=${JSON.stringify(products)};${draw}</script></body>`);
const pagePath = path.join(__dirname, 'tiles.html');
fs.writeFileSync(pagePath, page);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--remote-debugging-port=9334', '--allow-file-access-from-files', '--user-data-dir=' + process.env.TEMP + '/img-prof-' + Date.now(), 'about:blank'], { stdio: 'ignore' });

(async () => {
  let list;
  for (let i = 0; i < 40; i++) { try { list = await (await fetch('http://127.0.0.1:9334/json')).json(); if (list.length) break; } catch {} await sleep(250); }
  const ws = new WebSocket(list.find((t) => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let id = 0; const pending = {};
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending[d.id]) pending[d.id](d.result || d.error); };
  const send = (method, params = {}) => new Promise((r) => { pending[++id] = r; ws.send(JSON.stringify({ id, method, params })); });

  await send('Emulation.setDeviceMetricsOverride', { width: 800, height: 800, deviceScaleFactor: 1, mobile: false });
  await send('Page.enable');
  await send('Page.navigate', { url: 'file:///' + pagePath.replace(/\\/g, '/') });
  await sleep(2500);

  for (let i = 0; i < products.length; i++) {
    const top = i * 800;
    await send('Emulation.setDeviceMetricsOverride', { width: 800, height: 800, deviceScaleFactor: 1, mobile: false });
    await send('Runtime.evaluate', { expression: `window.scrollTo(0, ${top})` });
    await sleep(120);
    const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 88, captureBeyondViewport: true, clip: { x: 0, y: top, width: 800, height: 800, scale: 1 } });
    fs.writeFileSync(path.join(outDir, products[i].file), Buffer.from(shot.data, 'base64'));
  }

  chrome.kill();
  console.log('images:', products.length);
  process.exit(0);
})();
