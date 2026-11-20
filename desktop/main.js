'use strict';

/**
 * LEVI POS - Electron desktop shell
 * ------------------------------------------------------------
 * 1) Starts a private portable PHP 8.0 server on localhost.
 * 2) Shows the login page as a frameless transparent window (564x622)
 *    with the original card styling, 64px margin and real circle buttons.
 * 3) The card has two real circle buttons (minimize / close) and can
 *    be dragged from anywhere except form controls.
 * 4) After login, the card is destroyed and a normal framed window
 *    opens the dashboard unchanged.
 *
 * PHP 8.0 / Node syntax only. No PHP 8.1+ features are used.
 */

const { app, BrowserWindow, ipcMain, shell } = require('electron');
const path = require('path');
const fs = require('fs');
const net = require('net');
const http = require('http');
const { spawn } = require('child_process');

// Internal app-copy marker. Bumped so existing devices refresh their
// %APPDATA%\LEVI POS\app copy (new login design + MySQL client config).
const APP_VERSION = '1.1.0-r2';

/* ------------------------------------------------------------------ */
/* Paths                                                              */
/* ------------------------------------------------------------------ */
const packaged = fs.existsSync(path.join(process.resourcesPath, 'app'));
const appSource = packaged
    ? path.join(process.resourcesPath, 'app')
    : path.dirname(__dirname);
const phpDir = packaged
    ? path.join(process.resourcesPath, 'php')
    : path.join(__dirname, 'php');

const userData = path.join(app.getPath('appData'), 'LEVI POS');
const appCopy = path.join(userData, 'app');
const dbPath = path.join(userData, 'data', 'database', 'levi_pos.sqlite');
const webRoot = path.join(appCopy, 'public');

let phpProc = null;
let loginWin = null;
let dashboardWin = null;
let baseUrl = '';

/* ------------------------------------------------------------------ */
/* Helpers                                                            */
/* ------------------------------------------------------------------ */
function getFreePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const port = server.address().port;
            server.close(() => resolve(port));
        });
    });
}

function probe(url, tries) {
    return new Promise((resolve, reject) => {
        const attempt = (left) => {
            const req = http.get(url, (res) => {
                res.resume();
                resolve();
            });
            req.on('error', () => {
                if (left <= 0) {
                    reject(new Error('PHP server did not start'));
                } else {
                    setTimeout(() => attempt(left - 1), 150);
                }
            });
            req.setTimeout(1000, () => req.destroy());
        };
        attempt(tries);
    });
}

function syncAppCopy() {
    fs.mkdirSync(userData, { recursive: true });
    const marker = path.join(appCopy, '.levi-version');
    let current = '';
    try {
        if (fs.existsSync(marker)) current = fs.readFileSync(marker, 'utf8').trim();
    } catch (e) { /* ignore */ }

    if (current === APP_VERSION && fs.existsSync(path.join(webRoot, 'login.php'))) {
        return;
    }

    if (fs.existsSync(appCopy)) {
        fs.rmSync(appCopy, { recursive: true, force: true });
    }
    fs.cpSync(appSource, appCopy, { recursive: true });

    // Runtime files live in userData; the packaged desktop shell is not needed.
    const bundledShell = path.join(appCopy, 'desktop');
    if (fs.existsSync(bundledShell)) {
        fs.rmSync(bundledShell, { recursive: true, force: true });
    }
    fs.writeFileSync(marker, APP_VERSION, 'utf8');
}

function ensureDatabase() {
    fs.mkdirSync(path.dirname(dbPath), { recursive: true });
    if (fs.existsSync(dbPath)) return;
    const seed = path.join(appCopy, 'storage', 'database', 'levi_pos.sqlite');
    if (fs.existsSync(seed)) {
        fs.copyFileSync(seed, dbPath);
    }
}

function loadClientEnv() {
    const result = {};
    const candidates = [
        path.join(path.dirname(process.execPath), 'config', 'levi-db.client.env'),
        path.join(appCopy, 'config', 'levi-db.client.env'),
    ];
    for (let i = 0; i < candidates.length; i++) {
        const file = candidates[i];
        if (!fs.existsSync(file)) continue;
        try {
            const text = fs.readFileSync(file, 'utf8');
            const lines = text.split(/\r?\n/);
            for (let j = 0; j < lines.length; j++) {
                const line = lines[j].trim();
                if (!line || line.charAt(0) === '#') continue;
                const pos = line.indexOf('=');
                if (pos < 1) continue;
                const key = line.slice(0, pos).trim();
                const value = line.slice(pos + 1).trim();
                if (key) result[key] = value;
            }
        } catch (e) { /* ignore unreadable env file */ }
        break;
    }
    return result;
}

async function startPhp() {
    const port = await getFreePort();
    const phpExe = path.join(phpDir, 'php.exe');
    const phpIni = path.join(phpDir, 'php.ini');

    if (!fs.existsSync(phpExe)) {
        throw new Error('Portable PHP not found at ' + phpExe);
    }

    const env = Object.assign({}, process.env, loadClientEnv(), {
        LEVI_SQLITE_PATH: dbPath,
        LEVI_ENV: 'production',
    });

    phpProc = spawn(phpExe, [
        '-c', phpIni,
        '-S', '127.0.0.1:' + port,
        '-t', webRoot,
    ], {
        cwd: webRoot,
        env: env,
        windowsHide: true,
        stdio: ['ignore', 'ignore', 'pipe'],
    });

    let stderr = '';
    if (phpProc.stderr) {
        phpProc.stderr.on('data', (chunk) => { stderr += chunk.toString(); });
    }

    const url = 'http://127.0.0.1:' + port;
    try {
        await probe(url + '/login.php', 40);
    } catch (err) {
        throw new Error(String(err.message) + (stderr ? '\n' + stderr : ''));
    }
    return url;
}

/* ------------------------------------------------------------------ */
/* Login window styling (injected, project files stay untouched)      */
/* ------------------------------------------------------------------ */
const LOGIN_CSS = `
html, body.ll-body {
    background: transparent !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    min-height: 100vh !important;
    overflow: hidden !important;
    align-items: center !important;
    justify-content: center !important;
}
body.ll-body::before {
    content: "" !important;
    display: block !important;
    position: fixed !important;
    inset: 0 !important;
    z-index: 0 !important;
    pointer-events: none !important;
    border-radius: 18px !important;
    background:
        radial-gradient(600px 420px at 14% 6%, rgba(15, 118, 110, .22), transparent 62%),
        radial-gradient(560px 420px at 90% 92%, rgba(217, 119, 6, .16), transparent 60%),
        #fbf7f0 !important;
    -webkit-mask-image: none !important;
    mask-image: none !important;
}
.ll-topbar { display: none !important; }
.ll-card {
    -webkit-app-region: drag;
    background: #fffdf9 !important;
    border-color: rgba(4, 120, 87, .20) !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    box-shadow: none !important;
    animation: none !important;
}
.ll-card input, .ll-card button, .ll-card a, .ll-card select,
.ll-card textarea, .ll-card label, .ll-suggest, .ll-sug-item,
.ll-win-controls, .ll-win-btn {
    -webkit-app-region: no-drag !important;
}
.ll-win-controls {
    position: absolute;
    top: 14px;
    right: 14px;
    z-index: 50;
    display: flex;
    gap: 8px;
    -webkit-app-region: no-drag;
}
.ll-win-btn {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 1px solid rgba(4, 120, 87, .24);
    background: rgba(255, 253, 249, .92);
    color: #0f766e;
    display: grid;
    place-items: center;
    cursor: pointer;
    font-size: 15px;
    line-height: 1;
    padding: 0;
    -webkit-app-region: no-drag;
}
.ll-win-btn:hover { background: rgba(4, 120, 87, .12); color: #047857; }
`;

const LOGIN_JS = `
(function () {
    function inject() {
        if (!window.leviDesktop || !window.leviDesktop.isElectron) return false;
        if (document.getElementById('ll-win-controls')) return true;
        var card = document.querySelector('.ll-card');
        if (!card) return false;

        var wrap = document.createElement('div');
        wrap.id = 'll-win-controls';
        wrap.className = 'll-win-controls';
        wrap.innerHTML =
            '<button type="button" class="ll-win-btn" id="ll-win-close" title="إغلاق">×</button>' +
            '<button type="button" class="ll-win-btn" id="ll-win-min" title="تصغير">−</button>';
        card.appendChild(wrap);

        document.getElementById('ll-win-min').addEventListener('click', function (e) {
            e.preventDefault();
            window.leviDesktop.minimize();
        });
        document.getElementById('ll-win-close').addEventListener('click', function (e) {
            e.preventDefault();
            window.leviDesktop.close();
        });
        return true;
    }
    if (inject()) return;
    var tries = 0;
    var timer = setInterval(function () {
        if (inject() || ++tries > 120) clearInterval(timer);
    }, 40);
})();
`;

/* ------------------------------------------------------------------ */
/* Windows                                                            */
/* ------------------------------------------------------------------ */
function baseWebPreferences() {
    return {
        preload: path.join(__dirname, 'preload.js'),
        contextIsolation: true,
        nodeIntegration: false,
        sandbox: true,
        spellcheck: false,
        webSecurity: true,
    };
}

function allowExternalLinks(win) {
    win.webContents.setWindowOpenHandler((details) => {
        shell.openExternal(details.url);
        return { action: 'deny' };
    });
}

function openDashboard(url) {
    if (dashboardWin && !dashboardWin.isDestroyed()) return;

    dashboardWin = new BrowserWindow({
        width: 1264,
        height: 683,
        useContentSize: true,
        minWidth: 1024,
        minHeight: 680,
        show: true,
        backgroundColor: '#101419',
        title: 'LEVI POS',
        webPreferences: baseWebPreferences(),
    });

    allowExternalLinks(dashboardWin);
    dashboardWin.loadURL(url);

    dashboardWin.on('closed', () => {
        dashboardWin = null;
        app.quit();
    });

    if (loginWin && !loginWin.isDestroyed()) {
        loginWin.destroy();
        loginWin = null;
    }
}

function openLogin() {
    loginWin = new BrowserWindow({
        width: 564,
        height: 622,
        frame: false,
        transparent: true,
        backgroundColor: '#00000000',
        resizable: false,
        movable: true,
        minimizable: true,
        maximizable: false,
        fullscreenable: false,
        skipTaskbar: false,
        show: false,
        webPreferences: baseWebPreferences(),
    });

    allowExternalLinks(loginWin);

    loginWin.webContents.on('dom-ready', async () => {
        const win = loginWin;
        if (!win || win.isDestroyed()) return;
        try {
            await win.webContents.insertCSS(LOGIN_CSS);
            await win.webContents.executeJavaScript(LOGIN_JS);
            await win.webContents.executeJavaScript(
                'new Promise(function (resolve) { requestAnimationFrame(function () { requestAnimationFrame(resolve); }); })'
            );
            if (!win.isDestroyed()) {
                win.show();
                win.focus();
            }
        } catch (err) {
            if (!win.isDestroyed()) {
                win.show();
                win.focus();
            }
        }
    });

    loginWin.webContents.on('did-navigate', (event, url) => {
        if (/^https?:\/\//.test(url) && !/login\.php/.test(url)) {
            openDashboard(url);
        }
    });

    loginWin.on('closed', () => {
        loginWin = null;
        if (!dashboardWin) app.quit();
    });

    loginWin.loadURL(baseUrl + '/login.php');
}

/* ------------------------------------------------------------------ */
/* IPC from the two circle buttons                                    */
/* ------------------------------------------------------------------ */
ipcMain.on('levi:minimize', () => {
    if (loginWin && !loginWin.isDestroyed()) loginWin.minimize();
});
ipcMain.on('levi:close', () => {
    app.quit();
});

/* ------------------------------------------------------------------ */
/* Lifecycle                                                          */
/* ------------------------------------------------------------------ */
app.setAppUserModelId('com.levi.pos');

app.whenReady().then(async () => {
    try {
        syncAppCopy();
        ensureDatabase();
        baseUrl = await startPhp();
        openLogin();
    } catch (err) {
        console.error('[LEVI POS] startup failed:', err);
        const { dialog } = require('electron');
        dialog.showErrorBox('LEVI POS', String(err && err.message ? err.message : err));
        app.quit();
    }
});

app.on('window-all-closed', () => {
    app.quit();
});

app.on('before-quit', () => {
    if (phpProc) {
        const proc = phpProc;
        phpProc = null;
        try { proc.kill(); } catch (e) { /* ignore */ }
    }
});
