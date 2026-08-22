/**
 * 확장(모듈/플러그인/템플릿) 관리 화면의 업데이트 계약 회귀 검증
 *
 * Chrome MCP 정밀 점검(2026-07-31)에서 실측된 결함을 브라우저 경로로 고정한다.
 *
 * - 목록 요약이 `data.total` 을 읽어 항상 "총 0개 중 1-0개 표시" 로 고정 표기됐다.
 *   실제 응답은 `data.pagination.total` 이다.
 * - `check-updates` 응답 필드는 `updated_count` 인데 레이아웃이 `update_count` 를
 *   읽어, 업데이트가 감지돼도 항상 "모두 최신 상태입니다" 토스트가 떴다.
 * - 업데이트 모달의 수정 감지 조회가 실패해도 "수정된 레이아웃이 없습니다" 라고
 *   단언해, 그대로 '모두 교체' 를 진행하면 사용자가 고친 화면이 소실됐다.
 *
 * @scenario extension-update-modal
 * @effects pagination-summary, check-updates-toast, modified-layouts-warning
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Locator, Page } from '@playwright/test';

// 이 파일의 테스트는 매번 관리자 SPA 를 냉시동해 목록 화면을 완전히 렌더한 뒤 조작한다.
// 전역 기본 30s 는 워커가 여럿 붙어 서로 PHP-FPM 을 나눠 쓰는 병렬 실행에서 부족하다
// (실측: 6워커 동시 실행 시 테스트당 22~35s). 회귀 감지는 개별 단언 타임아웃이 맡고,
// 이 값은 하네스 예산일 뿐이므로 파일 단위로 늘린다.
test.describe.configure({ timeout: 60_000 });

const LIST_ROUTES: Array<{ path: string; label: string; api: string; permission: string }> = [
  { path: '/admin/modules', label: '모듈', api: '**/api/admin/modules?*', permission: 'core.modules.read' },
  { path: '/admin/plugins', label: '플러그인', api: '**/api/admin/plugins?*', permission: 'core.plugins.read' },
];

/**
 * 목록 화면이 함께 부르는 부수 데이터소스를 고정한다.
 *
 * `admin_module_list` / `admin_plugin_list` 는 목록 외에 `auto_deactivated_banner`
 * (`/api/admin/extensions/auto-deactivated`) 도 페이지 데이터소스로 선언한다. 카드 그리드에는
 * `blur_until_loaded: true` 가 걸려 있어 **모든** 데이터소스가 끝나기 전까지 래퍼에
 * `pointer-events-none` 이 붙고(DynamicRenderer), 툴바 버튼은 목록 응답의 abilities 로
 * `disabled` 가 결정된다. 이 요청 하나를 실서버에 남겨두면 병렬 워커 경합에서 응답이 늦어져
 * 클릭이 로딩 가드에 막힌다 — 실측: 6워커에서 카드 버튼 클릭이 `#content_area` 로 흘러 20s 타임아웃.
 * 이 spec 이 검증하는 것은 바인딩 계약이지 서버 지연이 아니므로 빈 응답으로 고정한다.
 */
async function stubSupportingSources(page: Page): Promise<void> {
  await page.route('**/api/admin/extensions/auto-deactivated*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: 'ok',
        data: {
          items: { plugins: [], modules: [], templates: [] },
          current_core_version: '7.0.6',
        },
      }),
    });
  });
}

/**
 * 카드 그리드 안의 대상이 실제로 클릭 가능해질 때까지 기다린다.
 *
 * `blur_until_loaded` 래퍼는 로딩 중 `opacity-50 blur-sm … pointer-events-none` 을 달아
 * 카드 안 버튼 클릭을 부모(`#content_area`)로 흘려보낸다. `domcontentloaded` 는 SPA 가
 * 데이터를 받기 전에 끝나므로, 이 대기 없이 카드 버튼을 누르면 클릭이 가로채인다.
 *
 * 순서가 중요하다 — blur 부재(`toHaveCount(0)`)만 먼저 단언하면 그리드가 **아직 렌더되기
 * 전이라** 즉시 통과하고, 그 뒤에 blur 가 붙어 대기가 무효가 된다. 대상이 보이는 것을
 * 먼저 확인해 그리드 렌더를 확정한 뒤 blur 해제를 기다린다.
 */
async function waitForGridInteractive(page: Page, target: Locator): Promise<void> {
  await expect(target).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('#content_area .blur-sm')).toHaveCount(0, { timeout: 20_000 });
}

for (const { path, label, api, permission } of LIST_ROUTES) {
  test(`${label} 목록의 페이지네이션 요약이 응답의 총건수를 표시한다`, async ({ page }) => {
    const token = issueToken(permission);
    await authenticatePage(page, token);
    await stubSupportingSources(page);

    // 응답을 고정해 "환경에 몇 건 있는지" 가 아니라 바인딩 경로를 검증한다.
    // (회귀 시 응답이 무엇이든 화면은 "총 0개 중 1-0개 표시" 로 고정된다)
    await page.route(api, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          message: 'ok',
          data: {
            data: [],
            pagination: { total: 37, current_page: 1, last_page: 4, per_page: 12 },
            meta: {},
            abilities: {},
          },
        }),
      });
    });

    await page.goto(path);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const summary = page.locator('#pagination_info');
    await expect(summary).toBeVisible({ timeout: 20_000 });
    // "총 0개 중 1-0개 표시" 는 data.pagination 경로 회귀의 지문이다.
    await expect(summary).toContainText('총 37개', { timeout: 20_000 });
    await expect(summary).not.toContainText('총 0개');
  });
}

test('업데이트 확인은 감지 건수를 토스트에 반영한다', async ({ page }) => {
  const token = issueToken('core.modules.read', 'core.modules.install');
  await authenticatePage(page, token);
  await stubSupportingSources(page);

  // 툴바의 '업데이트 확인' 은 `disabled: "{{… modules?.data?.abilities?.can_install !== true}}"` 이라
  // 목록 응답이 도착하기 전까지 비활성이다. 목록도 고정해 버튼 활성 시점을 서버 지연에서 분리한다.
  // (route 는 나중에 등록한 것이 먼저 매칭되므로 check-updates 보다 앞서 등록한다 —
  //  `**/api/admin/modules?*` 의 `?` 는 임의 1문자라 check-updates 경로에도 매칭될 수 있다)
  await page.route('**/api/admin/modules?*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: 'ok',
        data: {
          data: [],
          pagination: { total: 0, current_page: 1, last_page: 1, per_page: 12 },
          meta: {},
          abilities: { can_install: true, can_activate: true, can_uninstall: true },
        },
      }),
    });
  });

  // check-updates 응답을 감지 1건으로 고정 — 레이아웃이 updated_count 를 읽는지 검증
  await page.route('**/api/admin/modules/check-updates', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: '업데이트 확인이 완료되었습니다.',
        data: {
          updated_count: 1,
          details: [
            {
              identifier: 'sirsoft-board',
              current_version: '1.0.1',
              latest_version: '1.0.2',
              update_source: 'bundled',
            },
          ],
        },
      }),
    });
  });

  await page.goto('/admin/modules');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const checkButton = page.getByRole('button', { name: /업데이트 확인/ });
  await expect(checkButton).toBeEnabled({ timeout: 20_000 });
  await checkButton.click();

  const toast = page.getByRole('alert');
  await expect(toast).toContainText(/업데이트 확인 완료/, { timeout: 20_000 });
  await expect(toast).not.toContainText(/최신 상태입니다/);
});

test('수정 감지 조회가 실패하면 "수정 없음" 대신 확인 실패를 알린다', async ({ page }) => {
  const token = issueToken('core.modules.read', 'core.modules.install');
  await authenticatePage(page, token);
  await stubSupportingSources(page);

  // 업데이트 가용 상태를 고정하고, 수정 감지 조회만 실패시킨다
  await page.route('**/api/admin/modules?*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: 'ok',
        data: {
          data: [
            {
              identifier: 'sirsoft-board',
              vendor: 'sirsoft',
              name: '게시판',
              description: '검수용 고정 응답',
              version: '1.0.1',
              status: 'active',
              dependencies: [],
              update_available: true,
              update_source: 'bundled',
              latest_version: '1.0.2',
              is_compatible: true,
              is_pending: false,
              is_bundled: true,
              abilities: { can_install: true, can_activate: true, can_uninstall: true },
            },
          ],
          pagination: { total: 1, current_page: 1, last_page: 1, per_page: 12 },
          meta: {},
          abilities: { can_install: true, can_activate: true, can_uninstall: true },
        },
      }),
    });
  });
  await page.route('**/check-modified-layouts', (route) => route.abort('failed'));
  await page.route('**/changelog*', (route) => route.abort('failed'));

  await page.goto('/admin/modules');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 접근성 이름은 아이콘 글리프를 포함한다 ("refresh 업데이트") — '업데이트 확인' 과 구분한다.
  const updateButton = page
    .getByRole('button', { name: 'refresh 업데이트', exact: true })
    .first();
  await waitForGridInteractive(page, updateButton);
  await updateButton.click({ timeout: 20_000 });

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible({ timeout: 20_000 });
  await expect(dialog).toContainText(/확인하지 못했습니다/, { timeout: 20_000 });
  await expect(dialog).not.toContainText(/수정된 레이아웃이 없습니다/);
});
