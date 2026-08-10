/**
 * Layout Editor — 진입 인증 가드 + 샘플 로케일 목록
 *
 * 가드 결함:
 *  - 편집기 진입 routes fetch 가 공개(인증 불필요) `/api/templates/{id}/routes.json`
 *    을 호출해, 세션 만료/비로그인 상태에서도 200 → 레이아웃 선택 전까지 401/자동
 *    로그아웃이 발동하지 않고 편집기 chrome 이 노출됐다.
 *  - 수정: 진입 routes fetch 를 권한 가드된 `/api/admin/templates/{id}/editor/
 *    routes.json`(auth:sanctum + permission) + Bearer 토큰으로 전환. 미인증 →
 *    진입 시점 401 → AccessErrorPanel(unauthorized) → 로그인 리다이렉트. chrome
 *    (툴바/트리/캔버스)은 렌더되지 않는다. (권한 부족 403→forbidden 분기는 백엔드
 *    Feature 테스트 `test_serve_routes_requires_authentication` 등으로 커버 — E2E 의
 *    issue-token 역할은 편집 권한을 보유하므로 forbidden 재현 불가.)
 *  - 가드 전환 시 가드 컨트롤러가 source 태깅을 빠뜨려(raw routes.json) 유효 토큰으로도
 *    라우트 트리가 무너지던 회귀를 함께 수정 — 정상 진입 케이스가 그 회귀를 잠근다.
 *
 * 샘플 로케일 결함:
 *  - 편집기 미리보기는 createGlobalVariables() 주입을 거치지 않아 `$locales`(목록)가
 *    비어, 로그인 화면 등 로케일 선택 드롭다운(`options="{{$locales}}"`)이 빈 채로
 *    렌더됐다. 수정: editor-spec sampleGlobal 에 `$locale`/`$locales` 선언 +
 *    PreviewCanvas 가 `$`-prefixed 키를 렌더 컨텍스트 최상위로 lift.
 *
 * @scenario editor_entry_unauthorized + editor_entry_authorized + sample_locales_dropdown
 * @effects access_error_panel_unauthorized + chrome_not_rendered_when_unauthorized + route_tree_source_tagged + locale_dropdown_populated
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@layout-editor 진입 인증 가드', () => {
  test('비로그인(토큰 없음) 진입 시 chrome 미렌더 + unauthorized 안내', async ({ page }) => {
    // authenticatePage 미호출 — localStorage 에 auth_token 없음(세션 만료/로그아웃 상태 모사).
    await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=%2Flogin');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 진입 routes fetch 가 가드 엔드포인트라 401 → AccessErrorPanel(unauthorized) 렌더.
    const panel = page.getByTestId('g7le-access-error');
    await expect(panel).toBeVisible({ timeout: 20_000 });
    await expect(panel).toHaveAttribute('data-error-kind', 'unauthorized');

    // chrome(툴바/라우트 트리/캔버스)은 렌더되지 않는다 — 편집기 자체 접근 차단.
    await expect(page.getByTestId('g7le-toolbar-add-element')).toHaveCount(0);
    await expect(page.getByTestId('g7le-chrome-header')).toHaveCount(0);
  });

  test('편집 권한 보유 시 정상 진입 (chrome 렌더 + 라우트 트리 source 태깅 정상)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=%2Flogin');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 정상 진입 → 툴바 렌더, routes-error/access-error 패널 없음.
    // (source 태깅 누락 시 buildRouteTree 가 throw → g7le-chrome-routes-error 전면 표시)
    await expect(page.getByTestId('g7le-toolbar-add-element')).toBeVisible({ timeout: 40_000 });
    await expect(page.getByTestId('g7le-chrome-routes-error')).toHaveCount(0);
    await expect(page.getByTestId('g7le-access-error')).toHaveCount(0);
    // 라우트 트리가 렌더됨 — source 태깅으로 그룹핑 성공
    await expect(page.locator('[data-route-path]').first()).toBeVisible({ timeout: 10_000 });
  });
});

test.describe('@layout-editor 샘플 로케일 목록', () => {
  test('로그인 화면 로케일 선택 드롭다운이 $locales 로 채워진다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=%2Flogin');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-toolbar-add-element"]', { timeout: 40_000 });

    // 캔버스는 정적 시뮬레이션 — 트리에서 로그인 라우트 노드를 선택해 캔버스에 로드한다.
    const loginNode = page.locator('[data-route-path*="login"]').first();
    await loginNode.click();

    // 로케일 Select 가 $locales(샘플 ['ko','en'])로 채워지면 캔버스 프레임에 로케일 명이 렌더된다.
    // (getLocaleName: ko→한국어, en→English). 빈 드롭다운(결함)이면 로케일 명이 없다.
    await expect
      .poll(
        async () => {
          return page.evaluate(() => {
            const frame = document.querySelector('[data-testid="g7le-preview-frame"]');
            return frame ? /한국어|Korean|English|영어/.test(frame.textContent ?? '') : false;
          });
        },
        { timeout: 25_000, intervals: [1000] },
      )
      .toBe(true);
  });
});
