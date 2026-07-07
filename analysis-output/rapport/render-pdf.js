// Render the Hubs report HTML to an A4 PDF via Chrome DevTools Protocol.
// Backgrounds + @page size honored; footer is baked into the HTML.
const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const CHROME_CANDIDATES = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
];
const CHROME = CHROME_CANDIDATES.find(p => fs.existsSync(p));
if (!CHROME) { console.error('No Chrome/Edge found'); process.exit(1); }

const PORT = 9412;
const htmlPath = path.resolve(__dirname, 'Hubs-operativt-verksamhetslager.html');
const fileUrl = 'file:///' + htmlPath.replace(/\\/g, '/');
const outPdf  = path.resolve(__dirname, 'Hubs-operativt-verksamhetslager.pdf');
const userDir = path.resolve(__dirname, '.chrome-profile');

const sleep = ms => new Promise(r => setTimeout(r, ms));
const httpJson = p => new Promise((res, rej) => {
  http.get('http://127.0.0.1:' + PORT + p, r => {
    let d = ''; r.on('data', c => d += c); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } });
  }).on('error', rej);
});

(async () => {
  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    '--hide-scrollbars', '--force-color-profile=srgb',
    '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDir, fileUrl,
  ], { stdio: 'ignore' });

  let targets;
  for (let i = 0; i < 80; i++) {
    try { targets = await httpJson('/json'); if (targets && targets.some(t => t.type === 'page')) break; } catch (e) {}
    await sleep(250);
  }
  const page = targets.find(t => t.type === 'page');
  if (!page) { console.error('No page target'); chrome.kill(); process.exit(1); }

  const ws = new WebSocket(page.webSocketDebuggerUrl);
  let id = 0; const pending = {};
  const send = (method, params = {}) => new Promise((res, rej) => {
    const mid = ++id; pending[mid] = { res, rej };
    ws.send(JSON.stringify({ id: mid, method, params }));
  });
  ws.addEventListener('message', ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending[m.id]) { m.error ? pending[m.id].rej(new Error(JSON.stringify(m.error))) : pending[m.id].res(m.result); delete pending[m.id]; }
  });
  await new Promise(r => ws.addEventListener('open', r));

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Page.navigate', { url: fileUrl });
  await sleep(2200);
  try { await send('Runtime.evaluate', { expression: 'document.fonts.ready.then(()=>true)', awaitPromise: true }); } catch (e) {}
  // ensure SVG <img> figures are decoded
  try { await send('Runtime.evaluate', { expression: 'Promise.all([...document.images].map(i=>i.decode().catch(()=>0))).then(()=>true)', awaitPromise: true }); } catch (e) {}
  await sleep(600);

  const { data } = await send('Page.printToPDF', {
    paperWidth: 8.2677, paperHeight: 11.6929,    // A4
    marginTop: 0, marginBottom: 0, marginLeft: 0, marginRight: 0,
    printBackground: true, preferCSSPageSize: true,
    displayHeaderFooter: false, scale: 1,
  });
  fs.writeFileSync(outPdf, Buffer.from(data, 'base64'));
  ws.close(); chrome.kill();
  console.log('PDF written:', outPdf);
  console.log('Size:', (fs.statSync(outPdf).size / 1024).toFixed(0), 'KB');
  process.exit(0);
})().catch(e => { console.error('ERR', e); process.exit(1); });
