/**
 * E2E: 컴포넌트 오류 경계는 같은 레이아웃 안에서 경로·쿼리가 바뀌면 다시 그려 본다 (engine-v1.93.0)
 *
 * 엔진 `DynamicRenderer.tsx` 의 `ComponentErrorBoundary` 는 렌더 오류를 잡으면 「컴포넌트 로드 실패」 박스를 그린다.
 * 종전에는 되돌리는 경로가 없어, 같은 레이아웃 안의 SPA 이동(쿼리만 바뀌는 탭 링크)으로 정상 데이터 화면에 가도 박스가
 * 새로고침 전까지 남았다. 이 spec 은 실제 화면(관리자 히어로섹션 관리의 위치 탭)으로 그 회귀를 잠근다.
 *
 * 조작:
 *   1. 홈 위치 목록 응답만 `data.data` 를 배열이 아닌 객체로 바꿔 DataGrid 가 렌더 중에 던지게 한다 → 박스 1.
 *   2. 위치 탭 줄(nav)을 변수에 잡아 둔다(DOM 노드 동일성 — 다시 마운트되면 다른 노드가 된다).
 *   3. [쇼핑] 위치 탭(실제 응답) → 박스 0, DataGrid 가 다시 그려진다(배너가 있으면 첫 배너 제목까지).
 *   4. [홈] 위치 탭 → 깨진 데이터로 박스 1 로 돌아가고, 정착 뒤에도 1 로 남는다(다시 시도 루프 없음).
 *   5. 2~4 를 2회 반복하는 내내 탭 줄은 같은 노드이고, pageerror·렌더 루프 경고는 0 이다.
 *
 * 데이터: 서버 데이터는 바꾸지 않는다. GET·HEAD 가 아닌 `/api/` 요청은 모두 네트워크 단에서 abort 하고 기록해 0 건을 단언한다
 *   (XHR·fetch 모두). 히어로섹션 플러그인이 비활성(목록 API 가 200 이 아님)이면 사유와 함께 건너뛴다.
 *
 * 계정(토큰은 출력하지 않는다): `G7_E2E_TOKEN` 이 있으면 그 토큰, 없으면 `tests/Playwright/fixtures/admin-login.ts` 의 `adminToken` —
 *   `G7_E2E_ADMIN_EMAIL` + `G7_E2E_ADMIN_PASSWORD_FILE`(비밀번호 파일 경로) 로그인, 둘 다 없으면 `playwright:issue-token` 발급.
 *
 * 수정 전 대조: `G7_E2E_ENGINE_BUNDLE=<수정 전 엔진 번들 경로>`(`git show HEAD:public/build/core/template-engine.min.js` 로 뽑은 파일)를
 *   주면 이 브라우저만 엔진 번들을 그 파일로 바꿔 끼운다(서버 파일 불변). 수정 전 번들에서는 3단계(쇼핑 탭 뒤 박스 0)가 red 다.
 *
 * @scenario-manifest tests/scenarios/engine-error-boundary-route-reset.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://<개발 사이트 주소>'
 *   npx playwright test tests/Playwright/specs/engine-error-boundary-route-reset.spec.ts --workers=1
 */
import { readFileSync } from 'node:fs';
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { adminToken } from '../fixtures/admin-login';

test.describe.configure({ mode: 'serial', timeout: 180_000 });

/** 히어로섹션 관리자 API 접두 */
const HERO_API = '/api/plugins/sirsoft-hero_section/admin';

/** 히어로섹션 관리 목록 화면 */
const LIST = '/admin/plugins/sirsoft-hero_section/banners';

/** 경계가 그리는 기본 오류 박스의 제목(엔진 고정 문구) */
const BOX_TITLE = '컴포넌트 로드 실패';

/** 발급 경로일 때 넘길 권한 — 목록 화면 진입에 필요한 조회 권한 */
const PERMISSIONS = ['sirsoft-hero_section.banners.read'];

/** 탭 왕복 횟수 */
const ROUNDS = 2;

/**
 * 관리자 토큰을 얻는다(출력하지 않는다).
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns Sanctum 토큰
 */
async function resolveToken(request: APIRequestContext): Promise<string> {
  if (process.env.G7_E2E_TOKEN) return process.env.G7_E2E_TOKEN;

  return adminToken(request, ...PERMISSIONS);
}

/**
 * 위치별 배너 목록을 GET 으로 읽는다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 관리자 토큰
 * @param position 위치(home_hero | shop_hero)
 * @returns 상태 코드와 첫 배너 제목(없으면 null)
 */
async function readList(request: APIRequestContext, token: string, position: string): Promise<{ status: number; firstTitle: string | null }> {
  const res = await request.get(`${HERO_API}/banners?position=${position}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  if (res.status() !== 200) return { status: res.status(), firstTitle: null };
  const body = await res.json().catch(() => null);
  const rows: any[] = Array.isArray(body?.data?.data) ? body.data.data : [];

  return { status: 200, firstTitle: rows[0]?.title_localized ?? null };
}

/**
 * 토큰을 심고, 엔진 번들을 바꿔 끼우고(환경변수가 있을 때), 쓰기를 막고, 홈 위치 목록 응답만 깨뜨린다.
 *
 * Playwright 는 나중에 건 route 를 먼저 태우므로 쓰기 가로채기 → 목록 깨뜨리기 순서로 건다.
 *
 * @param page Playwright 페이지
 * @param token 관리자 토큰
 * @returns 막은 쓰기 기록, 바꿔 끼운 번들 응답 수, 깨뜨린 응답 수
 */
async function preparePage(page: Page, token: string): Promise<{ writes: string[]; served: { count: number }; broken: { count: number } }> {
  await page.addInitScript((t) => {
    localStorage.setItem('auth_token', t);
  }, token);

  const served = { count: 0 };
  const bundle = process.env.G7_E2E_ENGINE_BUNDLE;
  if (bundle) {
    const source = readFileSync(bundle);
    await page.route('**/build/core/template-engine.min.js*', (route) => {
      served.count += 1;

      return route.fulfill({ status: 200, contentType: 'application/javascript', body: source });
    });
  }

  const writes: string[] = [];
  await page.route(
    (url) => url.pathname.startsWith('/api/'),
    (route) => {
      const req = route.request();
      if (['GET', 'HEAD', 'OPTIONS'].includes(req.method())) return route.fallback();
      writes.push(`${req.method()} ${new URL(req.url()).pathname}`);

      return route.abort();
    },
  );

  // 홈 위치 목록만 data.data 를 객체로 — DataGrid 가 렌더 중에 던진다. 쇼핑 위치는 실제 응답 그대로.
  const broken = { count: 0 };
  await page.route(
    (url) => url.pathname === `${HERO_API}/banners`,
    async (route) => {
      const req = route.request();
      if (req.method() !== 'GET') return route.fallback();
      const position = new URL(req.url()).searchParams.get('position') || 'home_hero';
      if (position !== 'home_hero') return route.fallback();
      const response = await route.fetch();
      const body = await response.json().catch(() => null);
      if (!body?.data) return route.fulfill({ response });
      body.data.data = { broken: true };
      broken.count += 1;

      return route.fulfill({ response, body: JSON.stringify(body), headers: { ...response.headers(), 'content-type': 'application/json' } });
    },
  );
  await page.setViewportSize({ width: 1440, height: 900 });

  return { writes, served, broken };
}

/**
 * 화면의 오류 박스 수·위치·탭 줄 동일성을 잰다.
 *
 * @param page Playwright 페이지
 * @returns 측정값
 */
async function probe(page: Page): Promise<{ position: string | null; boxes: number; boxNames: string[]; tabsSameNode: boolean; tables: number }> {
  return page.evaluate((title) => {
    const boxes = [...document.querySelectorAll('div.font-semibold')].filter((d) => d.textContent?.trim() === title);
    const nav = document.querySelector('nav a[href*="position=shop_hero"]')?.closest('nav') ?? null;
    const marked = (window as any).__e2eBoundaryTabs as Element | undefined;

    return {
      position: new URLSearchParams(location.search).get('position'),
      boxes: boxes.length,
      boxNames: boxes.map((b) => (b.nextElementSibling?.textContent ?? '').trim().slice(0, 60)),
      tabsSameNode: !!marked && marked.isConnected && nav === marked,
      tables: document.querySelectorAll('table').length,
    };
  }, BOX_TITLE);
}

/**
 * 위치 탭 링크를 누른다(사용자 클릭 — 링크의 SPA 이동).
 *
 * @param page Playwright 페이지
 * @param position 위치
 */
async function clickTab(page: Page, position: 'home_hero' | 'shop_hero'): Promise<void> {
  await page.locator(`nav a[href*="position=${position}"]`).first().click();
}

test.describe('엔진 오류 경계 — 같은 레이아웃의 위치 탭 이동으로 오류 박스가 풀리고, 정상 노드는 다시 마운트되지 않는다', () => {
  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  /**
   * @scenario case=same_screen_navigate_recovers, case=healthy_boundary_not_remounted, case=retry_fails_again
   * @effects browser_position_tab_round_trip_resets_error_box, recovered_component_shows_new_data, healthy_boundary_not_remounted, error_box_kept_when_retry_fails, no_render_loop
   */
  test('홈 위치 목록이 깨져 박스가 뜬 뒤 [쇼핑] 탭이면 박스 0, [홈] 탭이면 다시 박스 1 — 탭 줄은 같은 노드, pageerror 0', async ({ page, request }) => {
    page.setDefaultTimeout(20_000);
    page.setDefaultNavigationTimeout(60_000);

    const token = await resolveToken(request);
    const shop = await readList(request, token, 'shop_hero');
    test.skip(shop.status === 404, '히어로섹션 플러그인이 설치·활성화되지 않아 건너뛴다');
    expect(shop.status, '쇼핑 위치 목록 조회').toBe(200);

    const pageErrors: string[] = [];
    const loopWarnings: string[] = [];
    let step = '진입';
    page.on('pageerror', (e) => pageErrors.push(`[${step}] ${String(e.message).split(/\r?\n/)[0].slice(0, 120)}`));
    page.on('console', (m) => {
      if (/Maximum update depth|Too many re-renders/.test(m.text())) loopWarnings.push(m.text().slice(0, 160));
    });

    const { writes, served, broken } = await preparePage(page, token);
    // 페이지 오류의 원인(cause)을 함께 남긴다 — React #520(동시 렌더 오류를 동기 재렌더로 복구)은 메시지만으로 어느
    // 컴포넌트가 던졌는지 알 수 없다. 실패 메시지에 원인 첫 줄과 그때의 쿼리를 싣는다.
    await page.addInitScript(() => {
      (window as any).__e2eErrorCauses = [];
      window.addEventListener('error', (e) => {
        const err: any = (e as ErrorEvent).error;
        (window as any).__e2eErrorCauses.push(`${location.search} ${String(err?.cause?.message ?? err?.message ?? '').slice(0, 120)}`);
      });
    });
    await page.goto(`${LIST}?position=home_hero`, { waitUntil: 'domcontentloaded' });
    if (process.env.G7_E2E_ENGINE_BUNDLE) expect(served.count, '바꿔 끼운 엔진 번들이 쓰였다').toBeGreaterThan(0);

    // 1. 깨진 홈 목록 → DataGrid 경계의 박스 1
    await expect.poll(async () => (await probe(page)).boxes, { message: '깨진 홈 목록에서 오류 박스가 뜬다', timeout: 30_000 }).toBe(1);
    expect(broken.count, '홈 목록 응답을 깨뜨렸다').toBeGreaterThan(0);
    const initial = await probe(page);
    expect(initial.boxNames[0], '박스는 DataGrid 경계의 것').toContain('DataGrid');

    // 2. 탭 줄 노드를 잡아 둔다 — 다시 마운트되면 isConnected 가 false 가 되거나 다른 노드로 바뀐다
    const marked = await page.evaluate(() => {
      const nav = document.querySelector('nav a[href*="position=shop_hero"]')?.closest('nav') ?? null;
      (window as any).__e2eBoundaryTabs = nav;

      return !!nav;
    });
    expect(marked, '위치 탭 줄을 찾았다').toBe(true);

    for (let round = 1; round <= ROUNDS; round += 1) {
      // 3. 쇼핑 탭(실제 응답) → 박스 0, DataGrid 다시 그려짐
      step = `${round}회차 쇼핑`;
      await clickTab(page, 'shop_hero');
      await expect
        .poll(async () => {
          const v = await probe(page);

          return v.position === 'shop_hero' ? v.boxes : -1;
        }, { message: `${round}회차: [쇼핑] 탭 뒤 오류 박스가 풀린다`, timeout: 15_000 })
        .toBe(0);
      await page.waitForTimeout(800);
      const atShop = await probe(page);
      console.log(`[engine-error-boundary-route-reset] ${round}회차 쇼핑 · 박스 ${atShop.boxes} · 표 ${atShop.tables} · 탭 줄 같은 노드 ${atShop.tabsSameNode}`);
      expect(atShop.boxes, `${round}회차: 쇼핑 정착 뒤에도 박스 0`).toBe(0);
      expect(atShop.tables, `${round}회차: DataGrid 표가 다시 그려졌다`).toBeGreaterThan(0);
      if (shop.firstTitle) {
        await expect(page.locator('table').getByText(shop.firstTitle, { exact: false }).first(), `${round}회차: 쇼핑 첫 배너 행`).toBeVisible();
      }
      expect(atShop.tabsSameNode, `${round}회차: 쇼핑 이동 뒤 탭 줄이 같은 노드(다시 마운트 없음)`).toBe(true);

      // 4. 홈 탭(깨진 데이터) → 박스 1 로 돌아가고 정착 뒤에도 1
      step = `${round}회차 홈`;
      await clickTab(page, 'home_hero');
      await expect
        .poll(async () => {
          const v = await probe(page);

          return v.position === 'home_hero' ? v.boxes : -1;
        }, { message: `${round}회차: [홈] 탭 뒤 오류 박스로 돌아간다`, timeout: 15_000 })
        .toBe(1);
      await page.waitForTimeout(1500);
      const atHome = await probe(page);
      console.log(`[engine-error-boundary-route-reset] ${round}회차 홈 · 박스 ${atHome.boxes} · 탭 줄 같은 노드 ${atHome.tabsSameNode}`);
      expect(atHome.boxes, `${round}회차: 홈 정착 뒤에도 박스 1(다시 시도 루프 없음)`).toBe(1);
      expect(atHome.tabsSameNode, `${round}회차: 홈 복귀 뒤 탭 줄이 같은 노드`).toBe(true);
    }

    console.log(`[engine-error-boundary-route-reset] 깨뜨린 홈 응답 ${broken.count} · 막은 쓰기 ${writes.length}${writes.length ? ` (${writes.join(', ')})` : ''}`);
    const causes: string[] = await page.evaluate(() => (window as any).__e2eErrorCauses ?? []);
    expect(pageErrors, `pageerror 0 (원인: ${causes.join(' / ') || '없음'})`).toEqual([]);
    expect(loopWarnings, '렌더 루프 경고 0').toEqual([]);
  });
});
