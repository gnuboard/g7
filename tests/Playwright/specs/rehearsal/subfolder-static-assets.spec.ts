/**
 * E2E: 하위 폴더(`/g7/`) 운영 설치의 정적 게시본 주소와 실패 복구가 사이트 안에 머문다 (7.2.0 W7-004)
 *
 * 하위 폴더 배치에서 서버는 정적 게시 트리를 `/g7/build/ext/{v}/…` 로 렌더하지만 `G7Config.staticBase` 는 게이트 값이라
 * 접두사 없는 `/build/ext/{v}` 다. 종전에는 그 값을 받아 주소를 만드는 프론트 세 곳이 접두사를 붙이지 않았다.
 *   - `assetUrl.ts` `extStaticUrl()` — 템플릿 routes.json·components.json·lang 조회가 문서 루트(사이트 밖)로 나감
 *   - `staticToLegacy()` 두 사본(엔진 TS, 셸 head 복구기 `g7-shell-head.js`) — `/g7/build/ext/…` 실패를 알아보지 못하고,
 *     알아봐도 복구 주소가 `/api/…`(사이트 밖)라 첫 방문 템플릿 CSS·확장 번들이 빠짐
 *   - `templateAssetDir()` — 코드 편집기(Monaco) 디렉토리 주소
 * 이 spec 은 실제 하위 폴더 설치본에서 저장소가 빈 첫 방문으로 위 네 경로를 잰다. 단위 시험(`assetUrl.test.ts`,
 * `assetUrlRecovery.test.ts`)이 문자열 규칙을 잠그고, 여기서는 브라우저가 실제로 내보내는 요청과 실린 서식을 본다.
 *
 * 케이스:
 *   1. 첫 방문(관리자 로그인, 사용자 홈) — 같은 오리진 요청이 전부 `/g7/…` 안, 템플릿 정적 조회 3종이 정적 게시본 200,
 *      정적 조회 실패 경고·자산 실패 띠·확장자 없는 방식 전환 기록이 없고, 템플릿 CSS 와 본문 글꼴이 실린다.
 *   2. 셸 head 복구 — 템플릿 `components.css` 정적 게시본을 404 로 바꾸면 링크가 `/g7/api/templates/assets/…` 로 바뀌어
 *      200 으로 실리고 규칙 수가 0 보다 크다.
 *   3. 엔진 복구 — 모듈 확장 번들(css·js) 정적 게시본을 404 로 바꾸면 `/g7/api/modules/bundle.{css,js}` 로 바뀌어 실린다.
 *   4. 자산 디렉토리 — `G7Core.asset.templateDir()` 가 `/g7/build/ext/…` 를 주고 그 아래 Monaco `loader.js` 가 200 이다.
 *
 * 환경(이 spec 은 개발 사이트를 재지 않는다):
 *   - `PLAYWRIGHT_BASE_URL` 이 하위 폴더 설치본(예: `http://127.0.0.1:8731/g7/`)이어야 한다. 경로가 `/` 면 건너뛴다.
 *   - 운영 자산 방식(`APP_ENV=production`, 정적 게시 `G7Config.staticBase` 있음)이어야 한다. 없으면 건너뛴다.
 *   - 사용자 템플릿 홈이 SPA 로 뜨지 않으면(사용자 템플릿 미설치) 사용자 쪽만 건너뛴다.
 *   - 쓰기 요청은 하지 않는다(GET 화면 열기와 GET fetch 뿐). 방문 통계 수집 POST 는 막는다.
 *
 * 수정 전 대조(기본 꺼짐):
 *   - `G7_E2E_ENGINE_BUNDLE=<수정 전 엔진 번들>`(`git show HEAD:public/build/core/template-engine.min.js`)
 *   - `G7_E2E_SHELL_HEAD=<수정 전 셸 head 복구기>`(`git show HEAD:public/assets/core/js/g7-shell-head.js`)
 *   를 주면 이 브라우저에서만 그 파일로 바꿔 끼운다(서버 파일 불변). 바꿔 끼운 응답이 1 이상인지도 단언한다.
 *   수정 전 엔진에서는 1·3·4, 수정 전 셸 head 에서는 2 가 red 다.
 *
 * @scenario-manifest tests/scenarios/docroot-single-folder.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='http://127.0.0.1:8731/g7/'
 *   npx playwright test --config=tests/Playwright/rehearsal.config.ts subfolder-static-assets
 */
import { readFileSync } from 'node:fs';
import { test, expect, type Page } from '@playwright/test';

test.describe.configure({ mode: 'serial', timeout: 90_000 });

/** 설치본 주소(끝 슬래시 포함)와 마운트 접두사(끝 슬래시 없음) */
const BASE = new URL(process.env.PLAYWRIGHT_BASE_URL || 'http://localhost/');
const SITE = BASE.href.endsWith('/') ? BASE.href : `${BASE.href}/`;
const PREFIX = new URL(SITE).pathname.replace(/\/+$/, '');
const ORIGIN = BASE.origin;

/** 수정 전 대조용 파일(있을 때만) */
const ENGINE_BUNDLE = process.env.G7_E2E_ENGINE_BUNDLE || '';
const SHELL_HEAD = process.env.G7_E2E_SHELL_HEAD || '';

/** 관리자·사용자 템플릿 식별자(번들 기본값) */
const ADMIN_TEMPLATE = 'sirsoft-admin_basic';
const USER_TEMPLATE = 'sirsoft-basic';

interface Probe {
  /** 같은 오리진으로 나간 요청(메서드, 주소, 상태) */
  requests: Array<{ method: string; url: string; status: number | null }>;
  /** 콘솔 경고·오류 */
  console: string[];
  /** 페이지 오류 */
  pageErrors: string[];
  /** 수정 전 파일로 바꿔 끼운 응답 수 */
  swapped: number;
}

/**
 * 측정을 건다 — 요청 기록, 콘솔, 수정 전 파일 교체, 쓰기 차단.
 *
 * @param page 페이지
 * @returns 측정값 저장소
 */
async function instrument(page: Page): Promise<Probe> {
  const probe: Probe = { requests: [], console: [], pageErrors: [], swapped: 0 };

  page.on('response', (res) => {
    const url = res.url();
    if (url.startsWith(ORIGIN)) probe.requests.push({ method: res.request().method(), url, status: res.status() });
  });
  page.on('requestfailed', (req) => {
    const url = req.url();
    if (url.startsWith(ORIGIN) && req.method() === 'GET') probe.requests.push({ method: 'GET', url, status: null });
  });
  page.on('console', (msg) => {
    if (msg.type() === 'warning' || msg.type() === 'error') probe.console.push(`${msg.type()} ${msg.text()}`);
  });
  page.on('pageerror', (err) => probe.pageErrors.push(String(err)));

  // 방문 통계 수집(sendBeacon POST)만 막는다 — 이 spec 은 서버에 아무것도 쓰지 않는다.
  await page.route('**/api/analytics/**', (route) =>
    route.request().method() === 'GET' ? route.fallback() : route.fulfill({ status: 204, body: '' }),
  );

  if (ENGINE_BUNDLE) {
    const body = readFileSync(ENGINE_BUNDLE);
    await page.route('**/build/core/template-engine.min.js*', (route) => {
      probe.swapped += 1;

      return route.fulfill({ status: 200, contentType: 'text/javascript; charset=utf-8', body });
    });
  }
  if (SHELL_HEAD) {
    const body = readFileSync(SHELL_HEAD);
    await page.route('**/assets/core/js/g7-shell-head.js*', (route) => {
      probe.swapped += 1;

      return route.fulfill({ status: 200, contentType: 'text/javascript; charset=utf-8', body });
    });
  }

  return probe;
}

/**
 * 화면을 저장소가 빈 첫 방문으로 열고 엔진이 뜰 때까지 기다린다.
 *
 * @param page 페이지
 * @param path 사이트 안 상대 경로(앞 슬래시 없음)
 */
async function openFirstVisit(page: Page, path: string): Promise<void> {
  await page.goto(`${SITE}${path}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => typeof (window as any).G7Core === 'object' && !!document.querySelector('#app *'), null, {
    timeout: 30_000,
  });
  // 정적 조회·번들 적재가 끝나 화면이 정착할 때까지(폴링이 있어 networkidle 은 쓰지 않는다)
  await page.waitForTimeout(3_000);
}

/**
 * 같은 오리진 요청 중 사이트(접두사) 밖으로 나간 것.
 *
 * @param probe 측정값
 * @returns 사이트 밖 요청 목록
 */
function outsideSite(probe: Probe): string[] {
  return probe.requests
    .filter((r) => {
      const path = new URL(r.url).pathname;

      return !(path === PREFIX || path.startsWith(`${PREFIX}/`));
    })
    .map((r) => `${r.method} ${r.url} ${r.status}`);
}

/**
 * 스타일시트별 규칙 수(교차 출처라 못 읽으면 -1).
 *
 * @param page 페이지
 * @returns href(오리진 제거)와 규칙 수
 */
async function sheetRules(page: Page): Promise<Array<{ href: string; rules: number }>> {
  return page.evaluate(() =>
    [...document.styleSheets].map((s) => {
      let rules = -1;
      try {
        rules = s.cssRules.length;
      } catch {
        rules = -1;
      }

      return { href: (s.href || '').replace(location.origin, ''), rules };
    }),
  );
}

/**
 * 운영 자산 방식(정적 게시)인지 확인한다 — 아니면 이 spec 의 전제가 성립하지 않는다.
 *
 * @param page 페이지
 * @returns `G7Config.staticBase`
 */
async function staticBaseOf(page: Page): Promise<string | null> {
  return page.evaluate(() => (window as any).G7Config?.staticBase ?? null);
}

test.describe('하위 폴더 운영 설치 — 정적 게시본 주소와 복구', () => {
  test.skip(PREFIX === '', 'PLAYWRIGHT_BASE_URL 이 하위 폴더 설치본이 아니다(경로가 /) — 이 spec 은 /g7/ 같은 하위 주소 설치본에서만 잰다');

  /**
   * @scenario base_path=subpath, mount_shape=physical_subdir, deployment_layout=docroot_project_root, asset_url_mode=extension, fetch_origin=core_bundle, navigation_direction=request
   *
   * @effects static_publish_urls_carry_the_site_base_prefix, browser_subfolder_first_visit_stays_inside_the_site
   */
  test('첫 방문 관리자 로그인과 사용자 홈 — 요청과 서식이 사이트 안에 머문다', async ({ browser, locale, userAgent }) => {
    const screens: Array<{ path: string; template: string; optional: boolean }> = [
      { path: 'admin/login', template: ADMIN_TEMPLATE, optional: false },
      { path: '', template: USER_TEMPLATE, optional: true },
    ];

    const checked: string[] = [];
    for (const screen of screens) {
      // 화면마다 새 컨텍스트 — 저장소·캐시가 빈 첫 방문
      const context = await browser.newContext({ locale, userAgent });
      const tab = await context.newPage();
      const probe = await instrument(tab);
      await openFirstVisit(tab, screen.path);

      const staticBase = await staticBaseOf(tab);
      test.skip(staticBase === null, '정적 게시(G7Config.staticBase)가 없는 설치본 — 운영 자산 방식(APP_ENV=production)에서 잰다');
      // 화면이 실은 템플릿 — 템플릿 컴포넌트 번들 요청에서 읽는다(G7Config 에는 템플릿 식별자가 없다)
      const templateId =
        probe.requests.map((r) => r.url.match(/\/templates\/([^/]+)\/assets\/js\/components\.iife\.js/)?.[1]).find(Boolean) ?? null;
      if (screen.optional && templateId !== screen.template) {
        test.info().annotations.push({ type: 'skip-screen', description: `${screen.path || '/'} 의 템플릿이 ${templateId} — 사용자 템플릿 미설치로 건너뜀` });
        await context.close();
        continue;
      }
      if (ENGINE_BUNDLE || SHELL_HEAD) expect(probe.swapped, '수정 전 파일을 바꿔 끼운 응답').toBeGreaterThan(0);

      const label = screen.path || '(홈)';
      expect(outsideSite(probe), `${label}: 사이트(${PREFIX}/) 밖으로 나간 같은 오리진 요청`).toEqual([]);

      // extStaticUrl() — 템플릿 정적 조회 3종이 접두사 붙은 정적 게시본으로 200
      const version = String(staticBase).match(/(\d+)$/)?.[1];
      for (const file of ['routes.json', 'components.json', 'lang/ko.json']) {
        const want = `${ORIGIN}${PREFIX}/build/ext/${version}/templates/${screen.template}/${file}`;
        const hit = probe.requests.find((r) => r.url.split('?')[0] === want);
        expect(hit?.status, `${label}: ${want} 응답`).toBe(200);
      }

      const state = await tab.evaluate(() => ({
        modeKeys: Object.keys(localStorage).filter((k) => k.startsWith('g7_asset_url_mode')),
        notice: !!document.querySelector('#g7-asset-failure-notice'),
        font: getComputedStyle(document.body).fontFamily,
      }));
      expect(state.modeKeys, `${label}: 확장자 없는 방식 전환 기록`).toEqual([]);
      expect(state.notice, `${label}: 자산 실패 띠`).toBe(false);
      expect(state.font, `${label}: 본문 글꼴`).toContain('Pretendard');
      expect(probe.console.filter((c) => /Static fast path miss|Static asset failed|static miss/i.test(c)), `${label}: 정적 조회 실패 경고`).toEqual([]);
      const components = (await sheetRules(tab)).find((s) => s.href.includes(`/templates/${screen.template}/`) && s.href.includes('components.css'));
      expect(components?.rules ?? 0, `${label}: 템플릿 components.css 규칙 수`).toBeGreaterThan(0);
      expect(probe.pageErrors, `${label}: 페이지 오류`).toEqual([]);
      checked.push(`${label}(${screen.template})`);
      await context.close();
    }
    test.info().annotations.push({ type: 'screens', description: checked.join(', ') });
    expect(checked.length, '잰 화면 수').toBeGreaterThan(0);
  });

  /**
   * @scenario base_path=subpath, mount_shape=physical_subdir, asset_url_mode=extension, e2e_asset_url_recovery=browser_self_heal, fetch_origin=core_bundle
   *
   * @effects static_publish_recovery_keeps_the_site_base_prefix, asset_url_recovery_follows_the_site_base_path_in_both_implementations, browser_subfolder_recovery_stays_inside_the_site
   */
  test('셸 head 복구 — 템플릿 CSS 정적 게시본이 사라지면 사이트 안 API 주소로 바꿔 싣는다', async ({ page }) => {
    const probe = await instrument(page);
    let missed = 0;
    await page.route(`**${PREFIX}/build/ext/*/templates/${ADMIN_TEMPLATE}/assets/css/components.css*`, (route) => {
      missed += 1;

      return route.fulfill({ status: 404, contentType: 'text/plain', body: 'gone' });
    });
    await openFirstVisit(page, 'admin/login');
    test.skip((await staticBaseOf(page)) === null, '정적 게시(G7Config.staticBase)가 없는 설치본');
    if (SHELL_HEAD) expect(probe.swapped, '수정 전 셸 head 를 바꿔 끼운 응답').toBeGreaterThan(0);

    expect(missed, '정적 게시본 components.css 를 404 로 바꾼 횟수').toBeGreaterThan(0);
    const legacy = `${ORIGIN}${PREFIX}/api/templates/assets/${ADMIN_TEMPLATE}/css/components.css`;
    await expect
      .poll(() => probe.requests.find((r) => r.url.split('?')[0] === legacy)?.status ?? null, {
        message: `복구 주소 ${legacy} 응답`,
        timeout: 15_000,
      })
      .toBe(200);
    const sheet = (await sheetRules(page)).find((s) => s.href.startsWith(`${PREFIX}/api/templates/assets/${ADMIN_TEMPLATE}/css/components.css`));
    expect(sheet?.rules ?? 0, '복구 주소로 실린 components.css 규칙 수').toBeGreaterThan(0);
    expect(outsideSite(probe), `사이트(${PREFIX}/) 밖으로 나간 같은 오리진 요청`).toEqual([]);
  });

  /**
   * @scenario base_path=subpath, mount_shape=physical_subdir, asset_url_mode=extension, e2e_asset_url_recovery=browser_self_heal, fetch_origin=extension_bundle
   *
   * @effects static_publish_recovery_keeps_the_site_base_prefix, browser_subfolder_recovery_stays_inside_the_site
   */
  test('엔진 복구 — 확장 번들 정적 게시본이 사라지면 사이트 안 API 번들로 바꿔 싣는다', async ({ page }) => {
    const probe = await instrument(page);
    const missed: string[] = [];
    await page.route(`**${PREFIX}/build/ext/*/bundles/modules.*`, (route) => {
      missed.push(new URL(route.request().url()).pathname.split('/').pop() || '');

      return route.fulfill({ status: 404, contentType: 'text/plain', body: 'gone' });
    });
    await openFirstVisit(page, 'admin/login');
    test.skip((await staticBaseOf(page)) === null, '정적 게시(G7Config.staticBase)가 없는 설치본');
    test.skip(missed.length === 0, '모듈 확장 번들을 싣지 않는 설치본(모듈 미설치)');
    if (ENGINE_BUNDLE) expect(probe.swapped, '수정 전 엔진을 바꿔 끼운 응답').toBeGreaterThan(0);

    for (const ext of ['css', 'js']) {
      if (!missed.includes(`modules.${ext}`)) continue;
      const legacy = `${ORIGIN}${PREFIX}/api/modules/bundle.${ext}`;
      await expect
        .poll(() => probe.requests.find((r) => r.url.split('?')[0] === legacy)?.status ?? null, {
          message: `복구 주소 ${legacy} 응답`,
          timeout: 15_000,
        })
        .toBe(200);
    }
    if (missed.includes('modules.css')) {
      const sheet = (await sheetRules(page)).find((s) => s.href.startsWith(`${PREFIX}/api/modules/bundle.css`));
      expect(sheet?.rules ?? 0, '복구 주소로 실린 모듈 번들 CSS 규칙 수').toBeGreaterThan(0);
    }
    expect(await page.evaluate(() => !!document.querySelector('#g7-asset-failure-notice')), '자산 실패 띠').toBe(false);
    expect(outsideSite(probe), `사이트(${PREFIX}/) 밖으로 나간 같은 오리진 요청`).toEqual([]);
  });

  /**
   * @scenario base_path=subpath, mount_shape=physical_subdir, asset_url_mode=extension, fetch_origin=core_bundle
   *
   * @effects static_publish_urls_carry_the_site_base_prefix, browser_subfolder_first_visit_stays_inside_the_site
   */
  test('자산 디렉토리 주소 — 코드 편집기 로더가 사이트 안 정적 게시본에서 온다', async ({ page }) => {
    const probe = await instrument(page);
    await openFirstVisit(page, 'admin/login');
    const staticBase = await staticBaseOf(page);
    test.skip(staticBase === null, '정적 게시(G7Config.staticBase)가 없는 설치본');
    if (ENGINE_BUNDLE) expect(probe.swapped, '수정 전 엔진을 바꿔 끼운 응답').toBeGreaterThan(0);

    // 관리자 템플릿 코드 편집기(CodeEditor.tsx)가 Monaco `paths.vs` 로 쓰는 호출과 같은 인자
    const monaco = readFileSync(new URL('../../../../templates/_bundled/sirsoft-admin_basic/src/components/composite/CodeEditor.tsx', import.meta.url), 'utf-8')
      .match(/MONACO_VERSION\s*=\s*'([^']+)'/)?.[1];
    expect(monaco, '관리자 템플릿의 Monaco 판').toBeTruthy();
    const dir: string = await page.evaluate(
      ([id, path]) => (window as any).G7Core.asset.templateDir(id, path),
      [ADMIN_TEMPLATE, `vendor/monaco-editor/${monaco}/vs`],
    );
    const version = String(staticBase).match(/(\d+)$/)?.[1];
    expect(dir, 'templateDir 결과').toBe(`${PREFIX}/build/ext/${version}/templates/${ADMIN_TEMPLATE}/assets/vendor/monaco-editor/${monaco}/vs`);
    const status = await page.evaluate(async (url) => (await fetch(url, { cache: 'no-store' })).status, `${dir}/loader.js`);
    expect(status, `${dir}/loader.js 응답`).toBe(200);
    expect(outsideSite(probe), `사이트(${PREFIX}/) 밖으로 나간 같은 오리진 요청`).toEqual([]);
  });
});
