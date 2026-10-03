const port = process.argv[2] || '9223';
const targetUrl = process.argv[3];
const widths = (process.argv[4] || '430,768,1024,1440').split(',').map(Number);
const cookiePath = process.argv[5] || '';
const injectCssPath = process.argv[6] || '';
let injectedCss = '';
if (injectCssPath) {
    const { readFile } = await import('node:fs/promises');
    injectedCss = await readFile(injectCssPath, 'utf8');
}

if (!targetUrl) {
    throw new Error('Usage: node ssf-edge-layout-audit.mjs <port> <url> [widths]');
}

const targets = await fetch(`http://127.0.0.1:${port}/json/list`).then((response) => response.json());
const page = targets.find((target) => target.type === 'page' && target.url === 'about:blank')
    || targets.find((target) => target.type === 'page');
if (!page) throw new Error('No Edge page target found.');

const socket = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});

let id = 0;
const pending = new Map();
socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
        const { resolve, reject } = pending.get(message.id);
        pending.delete(message.id);
        message.error ? reject(new Error(message.error.message)) : resolve(message.result);
    }
});

function command(method, params = {}) {
    const commandId = ++id;
    socket.send(JSON.stringify({ id: commandId, method, params }));
    return new Promise((resolve, reject) => pending.set(commandId, { resolve, reject }));
}

async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 1800));
}

await command('Page.enable');
await command('Runtime.enable');
if (cookiePath) {
    const { readFile } = await import('node:fs/promises');
    const cookies = (await readFile(cookiePath, 'utf8')).split(/\r?\n/).flatMap((line) => {
        const normalized = line.startsWith('#HttpOnly_') ? line.slice('#HttpOnly_'.length) : line;
        if (! normalized || normalized.startsWith('#')) return [];
        const parts = normalized.split('\t');
        if (parts.length < 7) return [];
        return [{
            domain: parts[0],
            path: parts[2],
            secure: parts[3] === 'TRUE',
            expires: Number(parts[4]) || undefined,
            name: parts[5],
            value: parts.slice(6).join('\t'),
            httpOnly: line.startsWith('#HttpOnly_'),
        }];
    });
    await command('Network.enable');
    await command('Network.setCookies', { cookies });
}

for (const width of widths) {
    await command('Emulation.setDeviceMetricsOverride', {
        width,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: width <= 430,
    });
    await command('Page.navigate', { url: targetUrl });
    await settle();
    if (injectedCss) {
        await command('Runtime.evaluate', {
            expression: `(() => { const style = document.createElement('style'); style.dataset.ssfAudit = 'injected'; style.textContent = ${JSON.stringify(injectedCss)}; document.head.append(style); })()`,
        });
    }
    const result = await command('Runtime.evaluate', {
        returnByValue: true,
        expression: `JSON.stringify((() => {
            const viewport = document.documentElement.clientWidth;
            const visible = (element) => {
                const rect = element.getBoundingClientRect();
                const style = getComputedStyle(element);
                return rect.height > 0 && rect.width > 0 && style.display !== 'none' && style.visibility !== 'hidden';
            };
            const describe = (element) => {
                const rect = element.getBoundingClientRect();
                const style = getComputedStyle(element);
                return {
                    tag: element.tagName.toLowerCase(),
                    id: element.id,
                    className: typeof element.className === 'string' ? element.className : '',
                    left: Math.round(rect.left * 10) / 10,
                    right: Math.round(rect.right * 10) / 10,
                    width: Math.round(rect.width * 10) / 10,
                    display: style.display,
                    minWidth: style.minWidth,
                    inlineSize: style.inlineSize,
                    boxSizing: style.boxSizing,
                    overflowWrap: style.overflowWrap,
                };
            };
            return {
                url: location.href,
                title: document.title,
                viewport,
                innerWidth: window.innerWidth,
                scrollWidth: document.documentElement.scrollWidth,
                bodyClass: document.body.className,
                offenders: [...document.querySelectorAll('body *')]
                    .filter(visible)
                    .filter((element) => {
                        const rect = element.getBoundingClientRect();
                        return rect.right > viewport + 1 || rect.left < -1;
                    })
                    .slice(0, 20)
                    .map(describe),
                grids: [...document.querySelectorAll('.ssf-card-grid, .ssf-home-card-grid')].map((grid) => ({
                    ...describe(grid),
                    columns: getComputedStyle(grid).gridTemplateColumns,
                    gap: getComputedStyle(grid).gap,
                    parent: grid.parentElement ? describe(grid.parentElement) : null,
                    children: [...grid.children].map(describe),
                })),
            };
        })())`,
    });
    console.log(JSON.stringify({ width, ...JSON.parse(result.result.value) }));
}

socket.close();
