const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const edge = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const fixture = path.resolve(__dirname, 'visual-fixture.html');
const output = path.join(os.tmpdir(), 'ssf-promotions-mobile-cdp.png');
const port = 9339;
const browser = spawn(edge, [
  '--headless=new',
  '--no-sandbox',
  '--disable-gpu',
  '--allow-file-access-from-files',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${path.join(os.tmpdir(), 'ssf-promotions-edge-cdp')}`,
  'about:blank'
], { stdio: 'ignore' });

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function endpoint(route) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${port}${route}`);
      if (response.ok) {
        return response.json();
      }
    } catch (error) {
      // Edge is still starting.
    }
    await delay(100);
  }
  throw new Error('Could not connect to Edge DevTools.');
}

async function run() {
  const targets = await endpoint('/json/list');
  const target = targets.find((item) => item.type === 'page');
  if (!target) {
    throw new Error('No page target found.');
  }

  const socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  let commandId = 0;
  const pending = new Map();
  const events = new Map();
  socket.addEventListener('message', (message) => {
    const payload = JSON.parse(message.data);
    if (payload.id && pending.has(payload.id)) {
      const callbacks = pending.get(payload.id);
      pending.delete(payload.id);
      if (payload.error) {
        callbacks.reject(new Error(payload.error.message));
      } else {
        callbacks.resolve(payload.result);
      }
    } else if (payload.method && events.has(payload.method)) {
      events.get(payload.method).forEach((resolve) => resolve(payload.params));
      events.delete(payload.method);
    }
  });

  const send = (method, params = {}) => new Promise((resolve, reject) => {
    commandId += 1;
    pending.set(commandId, { resolve, reject });
    socket.send(JSON.stringify({ id: commandId, method, params }));
  });
  const once = (method) => new Promise((resolve) => {
    const listeners = events.get(method) || [];
    listeners.push(resolve);
    events.set(method, listeners);
  });

  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: 320,
    height: 1200,
    deviceScaleFactor: 1,
    mobile: false,
    screenWidth: 320,
    screenHeight: 1200
  });
  const loaded = once('Page.loadEventFired');
  await send('Page.navigate', { url: `file:///${fixture.replace(/\\/g, '/')}` });
  await loaded;

  const result = await send('Runtime.evaluate', {
    expression: '({innerWidth: window.innerWidth, scrollWidth: document.documentElement.scrollWidth, scrollHeight: document.documentElement.scrollHeight, overflow: document.documentElement.scrollWidth > window.innerWidth})',
    returnByValue: true
  });
  const screenshot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, fromSurface: true });
  fs.writeFileSync(output, Buffer.from(screenshot.data, 'base64'));
  console.log(JSON.stringify({ ...result.result.value, screenshot: output }));
  socket.close();
}

run()
  .catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
  })
  .finally(() => browser.kill());
