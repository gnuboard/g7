#!/usr/bin/env node
/**
 * 화면 스타일 지문 일괄 채취기
 *
 * 화면 목록(JSON)의 각 화면을 라이트·다크 × 너비 조합으로 열어 `style-fingerprint.js` 의 지문을
 * 떠서 gzip JSON 으로 저장한다. 확장 CSS 전환 전(기준선)과 후에 같은 목록으로 두 번 돌리고
 * `compare-style-fingerprints.mjs` 로 비교한다.
 *
 * 사용:
 *   node scripts/extension-css/capture-style-fingerprints.mjs --base https://example.test \
 *     --screens <screens.json> --out <디렉터리> [--only <확장식별자,…>] [--ids <화면id,…>] \
 *     [--schemes light,dark] [--widths 1440,768,390] [--concurrency 3]
 *
 * 인증: 화면의 `auth` 값이 토큰 이름이다. `--token <이름>=<파일>` 로 토큰 파일을 넘긴다(여러 번 가능).
 * 토큰 값은 명령줄·로그에 남기지 않으려고 파일로만 받는다. `auth: "guest"` 는 토큰 없이 연다.
 *
 * 화면 목록 형식:
 *   { "screens": [ { "ext": "sirsoft-board", "id": "admin-boards", "path": "/admin/boards", "auth": "admin",
 *                    "steps": [ { "click": "role=tab[name=\"알림\"]" }, { "wait": 500 } ] } ] }
 * `steps` 는 진입 뒤 상호작용(탭 전환·모달 열기)이다. 값은 Playwright 로케이터 문자열이며 클래스명을 쓰지 않는다.
 * 좁은 화면(640px 미만)에서 UI 형태가 달라지면(예: 탭 줄 → 목록 상자) `stepsNarrow` 를 따로 둔다.
 *
 * 결과: `<out>/<ext>/<id>-<scheme>-<width>.json.gz` 와 실행 요약 `<out>/_run-<시각>.json`
 * (화면별 주 문서 상태코드·콘솔 오류·페이지 오류·요소 수).
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { chromium } from 'playwright';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * 명령줄 인자를 읽는다. `--token` 은 여러 번 받는다.
 *
 * @param {string[]} argv 인자 목록
 * @returns {Record<string, any>} 옵션
 */
function parseArgs(argv) {
  const opts = { tokens: {}, schemes: ['light', 'dark'], widths: [1440, 768, 390], concurrency: 3 };
  for (let i = 0; i < argv.length; i += 1) {
    const key = argv[i];
    const value = argv[i + 1];
    switch (key) {
      case '--base': opts.base = value.replace(/\/$/, ''); i += 1; break;
      case '--screens': opts.screens = value; i += 1; break;
      case '--out': opts.out = value; i += 1; break;
      case '--only': opts.only = value.split(','); i += 1; break;
      case '--ids': opts.ids = value.split(','); i += 1; break;
      case '--schemes': opts.schemes = value.split(','); i += 1; break;
      case '--widths': opts.widths = value.split(',').map(Number); i += 1; break;
      case '--concurrency': opts.concurrency = Number(value); i += 1; break;
      case '--token': {
        const [name, file] = value.split('=');
        opts.tokens[name] = readFileSync(file, 'utf8').trim();
        i += 1;
        break;
      }
      default: throw new Error(`알 수 없는 인자: ${key}`);
    }
  }
  if (!opts.base || !opts.screens || !opts.out) {
    throw new Error('--base, --screens, --out 은 필수입니다.');
  }
  return opts;
}

/** 지문 함수 원문(파일 전체)을 페이지에서 실행할 식으로 만든다. */
const FINGERPRINT_SOURCE = `${readFileSync(join(__dirname, 'style-fingerprint.js'), 'utf8')}\n;g7StyleFingerprint();`;

/**
 * 일반 브라우저 UA — 헤드리스 UA(`HeadlessChrome`)는 서버가 봇으로 보고 SEO HTML 을 내줄 수 있어
 * SPA 화면 대신 봇 화면을 뜨게 된다. 전후 채취 모두 같은 UA 다.
 */
const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

/** 애니메이션·전환을 멈춰 같은 순간의 값을 뜨게 한다. 전후 채취 모두 같은 조건이다. */
const FREEZE_CSS = '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}';

/**
 * DOM 이 더 바뀌지 않을 때까지 기다린다(요소 수와 HTML 길이가 연속 3회 같으면 안정).
 *
 * @param {import('playwright').Page} page 페이지
 * @param {number} timeoutMs 최대 대기
 * @returns {Promise<boolean>} 안정 여부
 */
async function waitStable(page, timeoutMs = 15000) {
  const start = Date.now();
  let last = '';
  let same = 0;
  while (Date.now() - start < timeoutMs) {
    const sig = await page.evaluate(() => `${document.getElementsByTagName('*').length}:${document.body.innerHTML.length}`);
    same = sig === last ? same + 1 : 0;
    last = sig;
    if (same >= 3) return true;
    await page.waitForTimeout(400);
  }
  return false;
}

/**
 * 화면 하나를 한 조합으로 채취한다.
 *
 * @param {import('playwright').Browser} browser 브라우저
 * @param {Record<string, any>} opts 옵션
 * @param {Record<string, any>} screen 화면 정의
 * @param {string} scheme light|dark
 * @param {number} width 뷰포트 너비
 * @returns {Promise<Record<string, any>>} 실행 요약 행
 */
async function captureOne(browser, opts, screen, scheme, width) {
  const context = await browser.newContext({ viewport: { width, height: 900 }, ignoreHTTPSErrors: true, locale: 'ko-KR', userAgent: BROWSER_UA });
  const token = screen.auth && screen.auth !== 'guest' ? opts.tokens[screen.auth] : null;
  if (screen.auth && screen.auth !== 'guest' && !token) {
    await context.close();
    return { ext: screen.ext, id: screen.id, scheme, width, error: `토큰 없음: ${screen.auth}` };
  }
  await context.addInitScript(([t, s]) => {
    try {
      if (t) localStorage.setItem('auth_token', t); else localStorage.removeItem('auth_token');
      localStorage.setItem('g7_color_scheme', s);
      localStorage.setItem('g7_locale', 'ko');
    } catch { /* 저장소 차단 환경 */ }
  }, [token, scheme]);
  const page = await context.newPage();
  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text().slice(0, 300)); });
  page.on('pageerror', (err) => pageErrors.push(String(err.message).slice(0, 300)));
  const row = { ext: screen.ext, id: screen.id, path: screen.path, scheme, width };
  try {
    const response = await page.goto(opts.base + screen.path, { waitUntil: 'networkidle', timeout: 60000 });
    row.status = response ? response.status() : null;
    await page.addStyleTag({ content: FREEZE_CSS });
    row.stable = await waitStable(page);
    const steps = width < 640 && screen.stepsNarrow ? screen.stepsNarrow : (screen.steps ?? []);
    for (const step of steps) {
      if (step.click) await page.locator(step.click).first().click({ timeout: 10000 });
      if (step.wait) await page.waitForTimeout(step.wait);
      await waitStable(page, 8000);
    }
    const fp = await page.evaluate(FINGERPRINT_SOURCE);
    row.count = fp.count;
    row.finalUrl = fp.url;
    row.actualScheme = fp.scheme;
    const dir = join(opts.out, screen.ext);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, `${screen.id}-${scheme}-${width}.json.gz`), gzipSync(JSON.stringify({ screen, ...fp })));
  } catch (err) {
    row.error = String(err.message).split('\n')[0].slice(0, 300);
  }
  row.consoleErrors = consoleErrors;
  row.pageErrors = pageErrors;
  await context.close();
  return row;
}

/**
 * 진입점 — 화면 × 조합 작업을 동시 실행 수만큼 나눠 돈다.
 *
 * @returns {Promise<void>}
 */
async function main() {
  const opts = parseArgs(process.argv.slice(2));
  const all = JSON.parse(readFileSync(opts.screens, 'utf8')).screens;
  const screens = all.filter((s) => (!opts.only || opts.only.includes(s.ext)) && (!opts.ids || opts.ids.includes(s.id)));
  const jobs = [];
  for (const screen of screens) {
    for (const scheme of opts.schemes) for (const width of opts.widths) jobs.push([screen, scheme, width]);
  }
  mkdirSync(resolve(opts.out), { recursive: true });
  const browser = await chromium.launch();
  const rows = [];
  let next = 0;
  const worker = async () => {
    while (next < jobs.length) {
      const [screen, scheme, width] = jobs[next];
      next += 1;
      const row = await captureOne(browser, opts, screen, scheme, width);
      rows.push(row);
      const flag = row.error ? `오류 ${row.error}` : `${row.status} 요소 ${row.count}${row.stable ? '' : ' (불안정)'}`;
      process.stdout.write(`[${rows.length}/${jobs.length}] ${screen.ext} ${screen.id} ${scheme} ${width} → ${flag}\n`);
    }
  };
  await Promise.all(Array.from({ length: opts.concurrency }, worker));
  await browser.close();
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  writeFileSync(join(opts.out, `_run-${stamp}.json`), JSON.stringify(rows, null, 2));
  const failed = rows.filter((r) => r.error);
  process.stdout.write(`완료: ${rows.length}건, 오류 ${failed.length}건\n`);
  process.exitCode = failed.length ? 1 : 0;
}

main().catch((err) => {
  process.stderr.write(`${err.stack}\n`);
  process.exitCode = 2;
});
