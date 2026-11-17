/**
 * sirsoft-admin_basic — 관리자 템플릿 목록의 [레이아웃 갱신] 창: 겹친 화면별 결정 (#888)
 *
 * 창을 열면 미리보기 API 가 화면마다 어떻게 바뀌는지 알려 주고, 운영자 수정과 겹친 화면은 화면마다
 * 「겹치지 않는 곳만 반영 (권장) / 운영자 판 유지 / 새 판 적용」을 고른다. 결정을 고르지 않은 화면은
 * 요청 본문에 실리지 않아 서버 기본 병합을 받는다 — 그래서 첫 열기의 기본 선택지는 「겹치지 않는 곳만
 * 반영」이어야 하고, 한 번 고른 결정을 그 선택지로 되돌리면 본문에서 그 화면 키가 빠져야 한다.
 * 단위 테스트(jsdom)는 「되돌리면 키가 지워진다」를 실제 클릭 순서로 재지 못해 이 spec 이 잠근다.
 *
 * 미리보기 요청 주소는 식(`layoutPreviewRequest.url`)으로 정해진다 — 식이 풀리지 않으면 요청이 엉뚱한
 * 경로·메서드로 나가 405 가 된다(엔진 수정 회귀 축). 첫 열기 요청의 경로·메서드·본문을 직접 본다.
 *
 * 목록·미리보기 응답은 고정하고, 적용 요청은 가로채 본문만 기록한다 — 개발 DB 의 레이아웃은 바뀌지 않는다.
 *
 * @scenario-manifest tests/scenarios/e2e-layout-sync-preview-decisions.yaml
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page, Request, Route } from '@playwright/test';

const TEMPLATE_ID = 'sirsoft-admin_basic';
const PREVIEW_PATH = '/api/admin/templates/refresh-layouts/preview';
const APPLY_PATH = '/api/admin/templates/refresh-layouts';

/** 겹친 화면 두 개 + 병합만 된 화면 하나 + 바뀌지 않는 화면 하나 */
const KEY_403 = `layout:${TEMPLATE_ID}:errors/403`;
const KEY_500 = `layout:${TEMPLATE_ID}:errors/500`;
const KEY_DASH = `layout:${TEMPLATE_ID}:admin_dashboard`;
const KEY_SAME = `layout:${TEMPLATE_ID}:admin_login`;

const DECISION_MERGE = '겹치지 않는 곳만 반영 (권장)';
const DECISION_KEEP_OURS = '운영자 판 유지';
const DECISION_TAKE_THEIRS = '새 판 적용';

/** 받은 쓰기 요청 한 건 */
interface Captured {
  method: string;
  path: string;
  body: Record<string, unknown>;
}

/**
 * 요청 본문을 객체로 읽습니다.
 *
 * @param request Playwright 요청
 * @returns 본문 객체 (없으면 빈 객체)
 */
function bodyOf(request: Request): Record<string, unknown> {
  try {
    return (request.postDataJSON() as Record<string, unknown>) ?? {};
  } catch {
    return {};
  }
}

/**
 * 관리자용 템플릿 목록 응답 — 활성 템플릿 하나.
 *
 * @returns 목록 응답 본문
 */
function templateListBody(): Record<string, unknown> {
  return {
    success: true,
    data: {
      data: [
        {
          identifier: TEMPLATE_ID,
          name: 'E2E 관리자 템플릿',
          version: '1.0.0',
          vendor: 'sirsoft',
          description: '',
          type: 'admin',
          status: 'active',
          update_available: false,
          has_settings: false,
          is_compatible: true,
          core_compatible: true,
          dependencies: { modules: [], plugins: [] },
          abilities: {
            can_activate: true,
            can_install: true,
            can_uninstall: true,
            can_edit_layouts: true,
            can_update_settings: true,
          },
        },
      ],
      pagination: { current_page: 1, last_page: 1, per_page: 12, total: 1, has_more_pages: false },
      abilities: { can_view_language_packs: true },
    },
  };
}

/**
 * 미리보기 응답 — 겹친 화면 2개(결정 선택지 표시), 병합 1개, 바뀌지 않는 화면 1개.
 *
 * @returns 미리보기 응답 본문
 */
function previewBody(): Record<string, unknown> {
  const conflict = (key: string, name: string) => ({
    key,
    name,
    kind: 'layout',
    verdict: 'conflict',
    will_delete: false,
    conflicts: [{ path: 'components.0.text' }],
  });
  return {
    success: true,
    data: {
      entries: [
        conflict(KEY_403, 'errors/403'),
        conflict(KEY_500, 'errors/500'),
        { key: KEY_DASH, name: 'admin_dashboard', kind: 'layout', verdict: 'merged', will_delete: false, conflicts: [] },
        { key: KEY_SAME, name: 'admin_login', kind: 'layout', verdict: 'unchanged', will_delete: false, conflicts: [] },
      ],
      conflict_screens: [KEY_403, KEY_500],
      has_conflicts: true,
      summary: { changed: 3, conflicts: 2, deleted: 0 },
    },
  };
}

/**
 * 쓰기 요청을 모두 가로채고(개발 DB 보호) 목록·미리보기·적용 요청을 고정 응답으로 대체합니다.
 *
 * Playwright 는 나중에 등록한 route 를 먼저 본다 — 안전망(모든 쓰기)을 먼저, 구체 경로를 뒤에 건다.
 *
 * @param page Playwright page
 * @param writes 받은 쓰기 요청 기록
 */
async function stubRoutes(page: Page, writes: Captured[]): Promise<void> {
  await page.route('**/api/**', async (route: Route) => {
    const request = route.request();
    if (request.method() === 'GET') {
      await route.fallback();
      return;
    }
    writes.push({ method: request.method(), path: new URL(request.url()).pathname, body: bodyOf(request) });
    const path = new URL(request.url()).pathname;
    const payload = path === PREVIEW_PATH ? previewBody() : { success: true, data: {} };
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) });
  });

  await page.route(
    (url) => url.pathname === '/api/admin/templates' && url.searchParams.get('type') === 'admin',
    async (route: Route) => {
      if (route.request().method() !== 'GET') {
        await route.fallback();
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(templateListBody()) });
    },
  );
}

/**
 * 관리자용 템플릿 목록에서 [레이아웃 갱신] 창을 엽니다.
 *
 * @param page Playwright page
 * @param token 인증 토큰
 */
async function openRefreshLayouts(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/admin');
  await page.waitForSelector('#template_card_grid', { timeout: 60_000 });

  const menuTrigger = page.locator('#template_card_grid [aria-haspopup="menu"]').first();
  await expect(menuTrigger).toBeVisible({ timeout: 60_000 });
  await menuTrigger.click();
  await page.getByRole('menuitem', { name: '레이아웃 갱신' }).click();

  await expect(page.locator('#layout_sync_preview_result')).toBeVisible({ timeout: 30_000 });
}

/**
 * 한 화면의 결정 라디오를 찾습니다.
 *
 * @param page Playwright page
 * @param key 화면 키
 * @param value merge | keep-ours | take-theirs
 * @returns 라디오 locator
 */
function decisionRadio(page: Page, key: string, value: string) {
  return page.locator(`input[type="radio"][name="layout_decision_${key}"][value="${value}"]`);
}

test.describe('@sirsoft-admin_basic 레이아웃 갱신 창 — 겹친 화면별 결정', () => {
  test.describe.configure({ timeout: 120_000 });

  /**
   * @scenario case=first_open
   * @effects preview_request_uses_resolved_post_route, decision_default_is_merge_on_first_open, decision_choices_carry_hint_title
   */
  test('첫 열기 — 미리보기가 정상 경로 POST 로 나가고 겹친 화면마다 「겹치지 않는 곳만 반영」이 골라져 있다', async ({
    page,
    extensionReadToken,
  }) => {
    const writes: Captured[] = [];
    const statuses: Array<{ url: string; status: number }> = [];
    page.on('response', (response) => {
      if (response.url().includes('/api/')) statuses.push({ url: response.url(), status: response.status() });
    });
    await stubRoutes(page, writes);

    await openRefreshLayouts(page, extensionReadToken);

    // 미리보기 요청 — 식으로 정한 주소가 풀려 정상 경로·메서드로 나갔다
    const previews = writes.filter((w) => w.path === PREVIEW_PATH);
    expect(previews.length).toBeGreaterThan(0);
    expect(previews[0].method).toBe('POST');
    expect(previews[0].body).toMatchObject({ template_name: TEMPLATE_ID, layout_strategy: 'keep', decisions: {} });
    // 미리보기 외 쓰기 요청이 나가지 않았다 — 창을 여는 것만으로는 아무것도 바뀌지 않는다
    expect(writes.filter((w) => w.path !== PREVIEW_PATH)).toEqual([]);
    // 어떤 API 응답도 405 가 아니다(엉뚱한 경로·메서드로 나가면 405)
    expect(statuses.filter((s) => s.status === 405)).toEqual([]);

    // 겹친 두 화면 + 병합 화면 모두 결정 묶음이 있고 기본값은 「겹치지 않는 곳만 반영」
    for (const key of [KEY_403, KEY_500, KEY_DASH]) {
      await expect(decisionRadio(page, key, 'merge')).toBeChecked();
      await expect(decisionRadio(page, key, 'keep-ours')).not.toBeChecked();
      await expect(decisionRadio(page, key, 'take-theirs')).not.toBeChecked();
    }
    // 바뀌지 않는 화면은 목록에 그리지 않는다
    await expect(page.locator(`input[name="layout_decision_${KEY_SAME}"]`)).toHaveCount(0);

    // 세 선택지는 무엇이 일어나는지 title 로 설명한다
    const row = decisionRadio(page, KEY_403, 'merge').locator('xpath=ancestor::div[1]');
    await expect(row.locator('label', { hasText: DECISION_MERGE })).toHaveAttribute('title', /겹치는 부분은 운영자 판을 두고/);
    await expect(row.locator('label', { hasText: DECISION_KEEP_OURS })).toHaveAttribute('title', /운영자 판을 통째로 둡니다/);
    await expect(row.locator('label', { hasText: DECISION_TAKE_THEIRS })).toHaveAttribute('title', /이 화면만 새 판으로 덮습니다/);
  });

  /**
   * @scenario case=apply_take_theirs
   * @effects apply_body_carries_only_chosen_decision
   */
  test('한 화면만 「새 판 적용」을 고르면 적용 본문 decisions 에 그 화면 키만 take-theirs 로 실린다', async ({
    page,
    extensionReadToken,
  }) => {
    const writes: Captured[] = [];
    await stubRoutes(page, writes);
    await openRefreshLayouts(page, extensionReadToken);

    await decisionRadio(page, KEY_500, 'take-theirs').click();
    await expect(decisionRadio(page, KEY_500, 'take-theirs')).toBeChecked();
    await expect(decisionRadio(page, KEY_403, 'merge')).toBeChecked();

    await page.locator('#template_refresh_layouts_apply').click();
    await expect.poll(() => writes.filter((w) => w.path === APPLY_PATH).length, { timeout: 20_000 }).toBe(1);

    const apply = writes.find((w) => w.path === APPLY_PATH)!;
    expect(apply.method).toBe('POST');
    expect(apply.body.template_name).toBe(TEMPLATE_ID);
    expect(apply.body.layout_strategy).toBe('keep');
    expect(apply.body.decisions).toEqual({ [KEY_500]: 'take-theirs' });
  });

  /**
   * @scenario case=revert_to_merge
   * @effects reverting_to_merge_drops_decision_key
   */
  test('고른 결정을 「겹치지 않는 곳만 반영」으로 되돌리면 그 화면 키가 본문에서 빠진다', async ({
    page,
    extensionReadToken,
  }) => {
    const writes: Captured[] = [];
    await stubRoutes(page, writes);
    await openRefreshLayouts(page, extensionReadToken);

    await decisionRadio(page, KEY_403, 'keep-ours').click();
    await expect(decisionRadio(page, KEY_403, 'keep-ours')).toBeChecked();
    await decisionRadio(page, KEY_500, 'take-theirs').click();
    await expect(decisionRadio(page, KEY_500, 'take-theirs')).toBeChecked();
    await expect(decisionRadio(page, KEY_403, 'keep-ours')).toBeChecked();

    // 403 을 되돌린다 — 깊은 병합이면 키가 남아 「운영자 판 유지」가 그대로 실린다
    await decisionRadio(page, KEY_403, 'merge').click();
    await expect(decisionRadio(page, KEY_403, 'merge')).toBeChecked();
    await expect(decisionRadio(page, KEY_403, 'keep-ours')).not.toBeChecked();

    await page.locator('#template_refresh_layouts_apply').click();
    await expect.poll(() => writes.filter((w) => w.path === APPLY_PATH).length, { timeout: 20_000 }).toBe(1);

    const apply = writes.find((w) => w.path === APPLY_PATH)!;
    expect(apply.body.layout_strategy).toBe('keep');
    expect(apply.body.decisions).toEqual({ [KEY_500]: 'take-theirs' });
    expect(Object.keys(apply.body.decisions as Record<string, string>)).not.toContain(KEY_403);
  });
});
