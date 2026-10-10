/**
 * Layout Editor — 「다른 페이지로 이동」 동작의 「다시 받을 데이터 소스」 왕복 보존.
 *
 * 관리자 환경설정 본인인증 탭의 목록 조작(검색·쪽 이동)은 `navigate(replace)` 에
 * `refetch_data_sources: 'policies'` 를 실어 목록만 다시 받는다. 편집기 레시피(`coreActionRecipes.ts`)의
 * navigate 폼에 그 칸이 없던 때는, 운영자가 편집기에서 그 동작을 열어 다른 칸(스크롤 위치)만 고쳐 저장해도
 * 재조립된 params 에서 `refetch_data_sources` 가 빠졌다. 그러면 목록 조작이 설정 전체를 다시 받아 편집 중이던
 * 설정을 덮는다(engine-v1.79.0 탭 복귀 덮개·목록 조작 결함의 편집기 저장 경로 재발).
 *
 * 이 spec 은 실제 편집기 UI 로 그 왕복을 태운다: 「검색」 단추 선택 → 속성 설정 → 동작 탭 → 동작 편집에서
 * 「다시 받을 데이터 소스」 칸이 하나 보이고 값이 `policies` 인지 → 스크롤 위치를 실제 선택으로 `preserve` 로
 * 바꿔 저장 → 저장 요청 본문의 본인인증 탭 navigate 7개가 모두 `refetch_data_sources` 를 갖는지.
 *
 * 제품 화면(`admin_settings`)을 대상으로 하므로 **저장(PUT)은 서버로 보내지 않는다** — `page.route` 가 GET 이
 * 아닌 API 요청을 모두 가로채 본문만 남기고 성공으로 응답한다. 운영자 편집 이력(레이아웃 버전)은 늘지 않으며,
 * spec 이 시작과 끝의 버전 목록 개수로 그것을 확인한다.
 *
 * 캔버스 노드는 드래그 핸들이 포인터를 가로채므로 action-flex-condition.spec.ts 와 같은 합성 선택 이벤트로 고른다.
 * 그 밖의 판정 조작(칸 열기, 스크롤 위치 선택, 저장)은 실제 입력이다.
 */
import type { APIRequestContext, Page, Route } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/auth';
import { adminToken } from '../../fixtures/admin-login';

const EDITOR_URL = '/admin/layout-editor/sirsoft-admin_basic?route=*%2Fadmin%2Fsettings';
const LAYOUT_VERSIONS_API = '/api/admin/templates/sirsoft-admin_basic/layouts/admin_settings/versions';
const OVERLAY_TARGET = 'policy_table_card';

type NavigateParams = Record<string, unknown> & { transition_overlay_target?: string };
type CapturedWrite = { method: string; url: string; body: string | null };

/**
 * 레이아웃 버전 개수를 읽는다(운영자 편집 이력이 늘지 않았는지 확인용).
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 관리자 토큰
 * @returns 버전 목록 응답의 전체 개수
 */
async function layoutVersionCount(request: APIRequestContext, token: string): Promise<number> {
  const res = await request.get(LAYOUT_VERSIONS_API, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  expect(res.status()).toBe(200);
  const body = await res.json();
  const data = body.data ?? {};
  const total = data.pagination?.total ?? data.meta?.total ?? data.total;
  if (typeof total === 'number') return total;
  const rows = Array.isArray(data) ? data : data.data ?? data.versions ?? [];
  return Array.isArray(rows) ? rows.length : 0;
}

/**
 * GET 이 아닌 API 요청을 모두 가로채 본문만 남기고 성공으로 응답한다(서버 미전송).
 *
 * @param page Playwright page
 * @returns 가로챈 쓰기 요청 목록(호출 뒤 계속 채워진다)
 */
async function interceptWrites(page: Page): Promise<CapturedWrite[]> {
  const writes: CapturedWrite[] = [];
  await page.route('**/api/**', async (route: Route) => {
    const req = route.request();
    if (req.method() === 'GET') return route.continue();
    writes.push({ method: req.method(), url: req.url(), body: req.postData() });
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, message: 'intercepted', data: {} }),
    });
  });
  return writes;
}

/**
 * 저장 본문에서 본인인증 탭 목록 조작 navigate 의 params 를 모두 모은다.
 *
 * @param body 레이아웃 저장 요청 본문(JSON 문자열 — 레이아웃은 그 안의 content 문자열)
 * @returns transition_overlay_target 이 정책 표 카드인 navigate 의 params 목록
 */
function identityTabNavigates(body: string): NavigateParams[] {
  const found: NavigateParams[] = [];
  const walk = (node: unknown): void => {
    if (!node || typeof node !== 'object') return;
    const n = node as Record<string, unknown>;
    const params = n.params as NavigateParams | undefined;
    if (n.handler === 'navigate' && params && params.transition_overlay_target === OVERLAY_TARGET) {
      found.push(params);
    }
    for (const v of Object.values(n)) walk(v);
  };
  // 저장 본문은 `{ content: "<레이아웃 JSON 문자열>", expected_lock_version }` 이다 — content 를 한 번 더 푼다
  const parsed = JSON.parse(body) as { content?: unknown };
  walk(typeof parsed.content === 'string' ? JSON.parse(parsed.content) : parsed);
  return found;
}

test.describe('@layout-editor navigate 「다시 받을 데이터 소스」 왕복', () => {
  /**
   * @scenario catalog_aspect=param_widget
   *
   * @effects navigate_refetch_data_sources_survives_editor_save_e2e
   */
  test('본인인증 탭 「검색」 navigate 를 편집기로 고쳐 저장해도 refetch_data_sources 가 남는다', async ({ page, request }) => {
    test.setTimeout(180_000);
    const token = await adminToken(request, 'core.templates.layouts.edit');
    const versionsBefore = await layoutVersionCount(request, token);

    await authenticatePage(page, token);
    const writes = await interceptWrites(page);
    const consoleErrors: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'error') consoleErrors.push(m.text());
    });

    await page.goto(EDITOR_URL);
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 60_000 });
    await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, null, { timeout: 30_000 });

    // 본인인증 탭 상태로 캔버스를 바꾼다
    await page.getByTestId('g7le-state-switcher-select').selectOption('settings_identity');
    const findSearchButton = (): Promise<string | null> =>
      page.evaluate(
        () =>
          Array.from(document.querySelectorAll('[data-editor-path]'))
            .find((e) => e.tagName === 'BUTTON' && (e.textContent || '').trim() === '검색')
            ?.getAttribute('data-editor-path') ?? null,
      );
    await expect
      .poll(findSearchButton, { timeout: 30_000, message: '본인인증 탭 「검색」 단추가 캔버스에 그려지지 않았다' })
      .not.toBeNull();
    const searchPath = (await findSearchButton()) as string;

    // 캔버스 노드 선택 — 드래그 핸들이 포인터를 가로채므로 합성 선택 이벤트(저장소 편집기 spec 관례)
    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`) as HTMLElement | null;
      if (!el) throw new Error(`node not found: ${p}`);
      el.scrollIntoView({ block: 'center' });
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 4, clientY: r.top + 4, view: window }));
      }
    }, searchPath);

    await page.getByTestId('g7le-overlay-info-button').first().click();
    await page.getByTestId('g7le-context-menu-edit-props').click();
    await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
    await page.getByTestId('g7le-property-tab-action').click();

    // 동작 카드: 다른 페이지로 이동
    await expect(page.getByTestId('g7le-action-slot-onClick-summary-0')).toBeVisible();
    await page.getByTestId('g7le-action-slot-onClick-edit-0').click();

    // 「다시 받을 데이터 소스」 칸이 하나 보이고 저장된 값이 채워져 있다
    const refetchField = page.getByTestId('g7le-action-slot-onClick-edit-param-refetch_data_sources');
    await expect(refetchField).toHaveCount(1);
    await expect(refetchField.locator('input, textarea').first()).toHaveValue('policies');

    // 다른 칸(스크롤 위치)만 실제 선택으로 바꾼다
    const scroll = page.getByTestId('g7le-action-slot-onClick-edit-param-scroll').locator('select').first();
    await scroll.selectOption('preserve');
    await expect(scroll).toHaveValue('preserve');

    await page.getByTestId('g7le-property-modal-close').click();
    await page.getByTestId('g7le-toolbar-save').click();

    // 저장 요청(PUT)은 가로채 서버로 가지 않는다 — 본문만 검사한다
    await expect
      .poll(() => writes.filter((w) => w.method === 'PUT' && /\/layouts\/admin_settings\b/.test(w.url)).length, {
        timeout: 20_000,
        message: '레이아웃 저장 요청이 나가지 않았다',
      })
      .toBeGreaterThan(0);
    const save = writes.filter((w) => w.method === 'PUT' && /\/layouts\/admin_settings\b/.test(w.url)).pop()!;
    const navigates = identityTabNavigates(save.body ?? '{}');

    expect(navigates).toHaveLength(7);
    expect(navigates.filter((p) => p.refetch_data_sources === undefined)).toEqual([]);

    // 고친 「검색」 navigate — 스크롤은 바뀌고 refetch 와 replace 는 그대로
    const edited = navigates.filter((p) => p.scroll === 'preserve');
    expect(edited.length).toBeGreaterThan(0);
    for (const p of edited) {
      expect(p.refetch_data_sources).toBe('policies');
      expect(p.replace).toBe(true);
    }

    expect(consoleErrors).toEqual([]);

    // 서버에는 아무것도 저장되지 않았다
    expect(await layoutVersionCount(request, token)).toBe(versionsBefore);
  });
});
