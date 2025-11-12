// scripts/get-token.cjs
// npm i puppeteer
const puppeteer = require('puppeteer');

function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

function buildSubmitTextHints(selector) {
  const hints = [];
  const explicit = (process.env.SEL_SUBMIT_TEXT || '').trim();
  if (explicit) hints.push(explicit);
  if (selector) {
    const match = selector.match(/["'`](.+?)["'`]/);
    if (match && match[1]) hints.push(match[1].trim());
  }
  hints.push('MASUK', 'LOGIN', 'SUBMIT');
  return Array.from(new Set(hints.filter(Boolean)));
}

async function clickSubmitButton(page, selector, debug = false) {
  if (selector) {
    try {
      const clicked = await page.evaluate((sel) => {
        try {
          const el = document.querySelector(sel);
          if (!el) return false;
          if (el && typeof el.focus === 'function') el.focus();
          el.click();
          return true;
        } catch (err) {
          return false;
        }
      }, selector);
      if (clicked) return true;
    } catch (err) {
      if (debug) console.error(`[WARN] submit selector failed: ${err.message}`);
    }
  }

  const hints = buildSubmitTextHints(selector);
  for (const hint of hints) {
    const upper = hint.toUpperCase().replace(/"/g, '\\"');
    try {
      const [btn] = await page.$x(`//button[contains(translate(normalize-space(.), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'), "${upper}")]`);
      if (btn) {
        await btn.click();
        await btn.dispose();
        if (debug) console.error(`[DEBUG] clicked submit button via text hint "${hint}"`);
        return true;
      }
    } catch (err) {
      if (debug) console.error(`[WARN] submit text hint "${hint}" failed: ${err.message}`);
    }
  }

  try {
    const fallbackBtn = await page.$('button[type="submit"], button[name="submit"]');
    if (fallbackBtn) {
      await fallbackBtn.click();
      await fallbackBtn.dispose();
      if (debug) console.error('[DEBUG] clicked fallback submit button');
      return true;
    }
  } catch (err) {
    if (debug) console.error(`[WARN] fallback submit click failed: ${err.message}`);
  }

  return false;
}

(async () => {
  const EMAIL = process.env.AUTH_EMAIL || '';
  const PIN   = process.env.AUTH_PIN   || '';
  const LOGIN_URL = process.env.LOGIN_URL || 'https://subsiditepatlpg.mypertamina.id/merchant-login';

  const SEL_EMAIL  = process.env.SEL_EMAIL  || 'input[placeholder="Masukkan Nomor Ponsel atau Email"]';
  const SEL_PIN    = process.env.SEL_PIN    || 'input[placeholder="Masukkan nomor PIN Anda"]';
  const SEL_SUBMIT = process.env.SEL_SUBMIT || 'button[type="submit"]';

  // request yg harus kita intip
  // Accept both PROFILE_URL_HINT (old) and TOKEN_URL_HINT (new) env names.
  const PROFILE_URL_HINT = process.env.PROFILE_URL_HINT
    || process.env.TOKEN_URL_HINT
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

    const submitClicked = await clickSubmitButton(page, SEL_SUBMIT, DEBUG);
    if (!submitClicked) {
      if (DEBUG) console.error('[WARN] falling back to pressing Enter for submit');
      await page.keyboard.press('Enter').catch(()=>{});
    }

    try {
      await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 });
    } catch (_) {
      await sleep(4000);
    }

    // 4) Tunggu request profile (yang seharusnya bawa Authorization)
    // kasih waktu cukup lama karena SPA bisa lazy-load
    try {
      await page.waitForRequest(r => r.url().includes(PROFILE_URL_HINT), { timeout: 45000 });
    } catch (_) {
      if (DEBUG) console.error('[WARN] waitForRequest timed out');
    }

    // 5) Fallback: baca token yang tertangkap dari hook fetch/XHR
    if (!foundToken) {
      const auths = await page.evaluate(() => Array.isArray(window.__authTokens) ? window.__authTokens : []);
      if (DEBUG) console.error(`[HOOKED_AUTH] ${JSON.stringify(auths)}`);
      const cleaned = (auths || []).map(String);
      const rawToken =
        cleaned.find(a => /^Bearer\s+/i.test(a)) ||
        cleaned.find(a => /eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/.test(a));
      if (rawToken) {
        foundToken = rawToken.replace(/^Bearer\s+/i, '').trim();
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

    // 7) Fallback: cek cookies untuk pola JWT
    if (!foundToken) {
      try {
        const cookies = await page.cookies();
        const fromCookies = (cookies || [])
          .map(c => c.value)
          .filter(Boolean)
          .find(v => /eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/.test(String(v)));
        if (fromCookies) {
          const normalized = String(fromCookies).replace(/^Bearer\s+/i, '').trim();
          if (normalized) {
            console.log(JSON.stringify({ token: normalized }));
            try { await browser.close(); } catch {}
            process.exit(0);
          }
        }
      } catch (err) {
        if (DEBUG) console.error(`[WARN] reading cookies failed: ${err.message}`);
      }
    }

    // 8) Opsi debug: screenshot terakhir
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
