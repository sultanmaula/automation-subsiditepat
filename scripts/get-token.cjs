// scripts/get-token.cjs
// npm i puppeteer
const puppeteer = require('puppeteer');

function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

(async () => {
  const EMAIL = process.env.AUTH_EMAIL || '';
  const PIN   = process.env.AUTH_PIN   || '';
  const LOGIN_URL = process.env.LOGIN_URL || 'https://subsiditepatlpg.mypertamina.id/merchant-login';

  const SEL_EMAIL  = process.env.SEL_EMAIL  || 'input[placeholder="Masukkan Nomor Ponsel atau Email"]';
  const SEL_PIN    = process.env.SEL_PIN    || 'input[placeholder="Masukkan nomor PIN Anda"]';
  const SEL_SUBMIT = process.env.SEL_SUBMIT || 'button[type="submit"]';

  // request yg harus kita intip
  const PROFILE_URL_HINT = process.env.PROFILE_URL_HINT
    || 'api-map.my-pertamina.id/general/v1/users/profile';

  const DEBUG    = (process.env.DEBUG === '1' || process.env.DEBUG === 'true');
  const HEADLESS = !(process.env.HEADLESS === 'false'); // default: headless true

  if (!EMAIL || !PIN) {
    console.error(JSON.stringify({ error: 'MISSING_CREDENTIALS' }));
    process.exit(2);
  }

  const browser = await puppeteer.launch({
    headless: HEADLESS,
    args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage'],
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined
  });

  try {
    const page = await browser.newPage();
    await page.setUserAgent(
      'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36'
    );

    // 0) Hook fetch & XHR SEBELUM halaman load, supaya semua request ketangkep
    await page.evaluateOnNewDocument(() => {
      try {
        window.__authTokens = [];
        // Hook fetch
        const _fetch = window.fetch;
        window.fetch = async (...args) => {
          try {
            const [, init = {}] = args;
            const h = init.headers || {};
            let auth = '';
            if (h instanceof Headers) auth = h.get('Authorization') || h.get('authorization') || '';
            else auth = h['Authorization'] || h['authorization'] || '';
            if (auth) window.__authTokens.push(auth);
          } catch {}
          const res = await _fetch(...args);
          return res;
        };

        // Hook XHR
        const _open = XMLHttpRequest.prototype.open;
        const _setHeader = XMLHttpRequest.prototype.setRequestHeader;
        XMLHttpRequest.prototype.open = function(method, url, ...rest) {
          this.__url = url; return _open.call(this, method, url, ...rest);
        };
        XMLHttpRequest.prototype.setRequestHeader = function(k, v) {
          try {
            if ((k||'').toLowerCase() === 'authorization' && v) {
              window.__authTokens = window.__authTokens || [];
              window.__authTokens.push(v);
            }
          } catch {}
          return _setHeader.call(this, k, v);
        };
      } catch {}
    });

    let foundToken = null;

    // 1) Listener REQUEST: tangkap Authorization dari request ke PROFILE_URL_HINT
    page.on('request', async (req) => {
      if (foundToken) return;
      const url = req.url();
      if (!url.includes(PROFILE_URL_HINT)) return;
      const headers = req.headers() || {};
      const raw = headers['authorization'] || headers['Authorization'];
      if (DEBUG) {
        console.error(`[REQ] ${url}`);
        console.error(`[REQ-HEADERS] ${JSON.stringify(headers)}`);
      }
      if (raw) {
        const token = String(raw).replace(/^Bearer\s+/i, '').trim();
        if (token) {
          foundToken = token;
          console.log(JSON.stringify({ token }));
          try { await browser.close(); } catch {}
          process.exit(0);
        }
      }
    });

    // 2) (tambahan debug) log RESPONSE yg berkaitan
    if (DEBUG) {
      page.on('response', async (resp) => {
        const u = resp.url();
        if (u.includes(PROFILE_URL_HINT)) {
          const hh = resp.headers() || {};
          console.error(`[RESP] ${u}`);
          console.error(`[RESP-HEADERS] ${JSON.stringify(hh)}`);
        }
      });
    }

    // 3) Buka halaman & login
    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });

    await page.waitForSelector(SEL_EMAIL, { timeout: 20000 });
    await page.click(SEL_EMAIL, { clickCount: 3 }).catch(()=>{});
    await page.type(SEL_EMAIL, EMAIL, { delay: 25 });

    const pinExists = await page.$(SEL_PIN);
    if (pinExists) {
      await page.click(SEL_PIN, { clickCount: 3 }).catch(()=>{});
      await page.type(SEL_PIN, PIN, { delay: 40 });
    } else {
      const digits = PIN.split('');
      for (let i = 0; i < digits.length; i++) {
        const sel = `input[name="pin[${i}]"], input[data-pin-index="${i}"]`;
        const el = await page.$(sel);
        if (el) await page.type(sel, digits[i], { delay: 35 });
      }
    }

    await page.click(SEL_SUBMIT).catch(()=>{});

    // 4) Tunggu request profile (yang seharusnya bawa Authorization)
    // kasih waktu cukup lama karena SPA bisa lazy-load
    try {
      await page.waitForRequest(r => r.url().includes(PROFILE_URL_HINT), { timeout: 45000 });
    } catch (_) {}

    // 5) Fallback: baca token yang tertangkap dari hook fetch/XHR
    if (!foundToken) {
      const auths = await page.evaluate(() => Array.isArray(window.__authTokens) ? window.__authTokens : []);
      if (DEBUG) console.error(`[HOOKED_AUTH] ${JSON.stringify(auths)}`);
      const bearer = (auths || []).find(a => /^Bearer\s+/i.test(a));
      if (bearer) {
        foundToken = bearer.replace(/^Bearer\s+/i, '').trim();
        if (foundToken) {
          console.log(JSON.stringify({ token: foundToken }));
          try { await browser.close(); } catch {}
          process.exit(0);
        }
      }
    }

    // 6) Fallback: cari di localStorage/sessionStorage
    if (!foundToken) {
      const tokenMaybe = await page.evaluate(() => {
        const candidates = [];
        try {
          for (let i=0;i<localStorage.length;i++){
            const k = localStorage.key(i);
            const v = localStorage.getItem(k) || '';
            if (/token|auth|access/i.test(k+v)) candidates.push(v);
          }
          for (let i=0;i<sessionStorage.length;i++){
            const k = sessionStorage.key(i);
            const v = sessionStorage.getItem(k) || '';
            if (/token|auth|access/i.test(k+v)) candidates.push(v);
          }
        } catch {}
        return candidates;
      });
      if (DEBUG) console.error(`[STORAGE_CANDIDATES] ${JSON.stringify(tokenMaybe)}`);
      const jwtLike = (tokenMaybe || []).map(String).find(s => /eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/.test(s));
      if (jwtLike) {
        console.log(JSON.stringify({ token: jwtLike }));
        try { await browser.close(); } catch {}
        process.exit(0);
      }
    }

    // 7) Opsi debug: screenshot terakhir
    if (DEBUG) {
      try { await page.screenshot({ path: '/var/www/html/scripts/debug.png', fullPage: true }); } catch {}
    }

    console.error(JSON.stringify({ error: 'TOKEN_NOT_FOUND' }));
    await browser.close();
    process.exit(3);

  } catch (err) {
    console.error(JSON.stringify({ error: 'EXCEPTION', message: err.message }));
    try { await browser.close(); } catch {}
    process.exit(4);
  }
})();
