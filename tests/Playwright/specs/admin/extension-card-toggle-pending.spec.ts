/**
 * 확장 목록 카드 스위치 — 서버 작업이 끝날 때까지 값을 바꾸지 않고, 그동안 손잡이 자리에 스피너 (#882)
 *
 * 종전에는 누르는 즉시 스위치가 뒤집히고 그 뒤에 작업이 돌아, 실패·거절돼도 「꺼졌다/켜졌다」로 읽혔다. 이제 누르면 원래 값에
 * 머물고 누른 카드만 잠기며(aria-busy) 손잡이 자리에 스피너가 돈다. 응답이 오면 — 성공은 목록을 다시 받아 새 값으로, 거절(409
 * 「다른 작업 진행 중」)은 원래 값 그대로 — 진행 표시를 끈다. 실서버의 확장 상태를 바꾸지 않도록 목록·비활성화 응답을 고정한다.
 *
 * 소유 매니페스트: tests/scenarios/extension-update-concurrency.yaml (case=card_toggle_pending)
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page, Route } from '@playwright/test';

test.describe.configure({ timeout: 60_000 });

const NAME = 'E2E 잠금 시험 플러그인';

/**
 * 플러그인 한 장짜리 목록 응답을 만든다.
 *
 * @param status 확장 상태
 * @returns 목록 응답 본문
 */
function listBody(status: 'active' | 'inactive'): string {
  return JSON.stringify({
    success: true,
    message: 'ok',
    data: {
      data: [
        {
          identifier: 'e2e-lockprobe',
          vendor: 'e2e',
          name: NAME,
          version: '1.0.0',
          description: 'e2e',
          status,
          stuck_update: false,
          update_available: false,
          dependencies: [],
          integrated_by: [],
          core_compatible: true,
          abilities: { can_install: true, can_activate: true, can_uninstall: true },
        },
      ],
      pagination: { total: 1, current_page: 1, last_page: 1, per_page: 12 },
      meta: {},
      abilities: { can_install: true, can_activate: true, can_uninstall: true },
    },
  });
}

/**
 * 목록·부수 데이터소스를 고정하고, 비활성화 요청에는 지연 뒤 주어진 응답을 돌려준다.
 *
 * @param page 페이지
 * @param deactivate 비활성화 요청 처리기
 * @returns 현재 목록 상태를 바꾸는 함수
 */
async function stub(page: Page, deactivate: (route: Route) => Promise<void>): Promise<(s: 'active' | 'inactive') => void> {
  let status: 'active' | 'inactive' = 'active';
  await page.route('**/api/admin/extensions/auto-deactivated*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'ok', data: { items: { plugins: [], modules: [], templates: [] }, current_core_version: '7.1.5' } }) }),
  );
  await page.route('**/api/admin/plugins?*', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: listBody(status) }));
  await page.route('**/api/admin/plugins/deactivate', deactivate);

  return (s) => {
    status = s;
  };
}

/**
 * 스위치 상태(값·진행 중·스피너)를 읽는다.
 *
 * @param page 페이지
 * @returns 스위치 상태
 */
async function readSwitch(page: Page): Promise<{ checked: string | null; busy: string | null; spinner: boolean }> {
  return page.evaluate((n) => {
    const el = document.querySelector(`[role=switch][aria-label="${n}"]`);
    return { checked: el?.getAttribute('aria-checked') ?? null, busy: el?.getAttribute('aria-busy') ?? null, spinner: !!el?.querySelector('[data-testid="toggle-pending-spinner"]') };
  }, NAME);
}

/**
 * 스위치가 비활성 모양인지(계산된 커서·트랙 투명도·스피너 투명도)를 읽는다.
 *
 * @param page 페이지
 * @returns 커서·트랙 투명도·스피너 투명도
 */
async function readLook(page: Page): Promise<{ cursor: string; trackOpacity: string; spinnerOpacity: string | null }> {
  return page.evaluate((n) => {
    const el = document.querySelector(`[role=switch][aria-label="${n}"]`) as HTMLElement;
    const spinner = el.querySelector('[data-testid="toggle-pending-spinner"]');
    return {
      cursor: getComputedStyle(el).cursor,
      trackOpacity: getComputedStyle(el.querySelector('.toggle-switch-track') as Element).opacity,
      spinnerOpacity: spinner ? getComputedStyle(spinner).opacity : null,
    };
  }, NAME);
}

/**
 * @scenario case=card_toggle_pending
 * @effects card_toggle_keeps_value_until_server_confirms, card_toggle_follows_server_value_after_work
 */
test('끄기를 누르면 응답 전까지 켜진 채 스피너, 409 「진행 중」이면 켜진 채 진행 표시만 꺼진다', async ({ page }) => {
  await authenticatePage(page, issueToken('core.plugins.read', 'core.plugins.activate'));
  await stub(page, async (route) => {
    await new Promise((r) => setTimeout(r, 1500));
    await route.fulfill({
      status: 409,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, message: `"e2e-lockprobe"에 다른 작업(설치·업데이트·활성화 등)이 진행 중입니다. 끝난 뒤 다시 시도하세요.`, errors: { operation_in_progress: true } }),
    });
  });

  await page.goto('/admin/plugins');
  const sw = page.getByRole('switch', { name: NAME, exact: true });
  await expect(sw).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('#content_area .blur-sm')).toHaveCount(0, { timeout: 20_000 });

  expect(await readLook(page)).toEqual({ cursor: 'pointer', trackOpacity: '1', spinnerOpacity: null });
  await sw.click();
  await expect.poll(() => readSwitch(page), { timeout: 1_000 }).toEqual({ checked: 'true', busy: 'true', spinner: true });
  // 진행 중에는 비활성 모양 — 금지 커서·흐린 트랙, 스피너는 흐리지 않는다
  expect(await readLook(page)).toEqual({ cursor: 'not-allowed', trackOpacity: '0.5', spinnerOpacity: '1' });

  await expect(page.getByText('다른 작업(설치·업데이트·활성화 등)이 진행 중입니다').first()).toBeVisible({ timeout: 10_000 });
  await expect.poll(() => readSwitch(page), { timeout: 5_000 }).toEqual({ checked: 'true', busy: null, spinner: false });
  expect(await readLook(page)).toEqual({ cursor: 'pointer', trackOpacity: '1', spinnerOpacity: null });
  await expect(page.getByRole('dialog')).toHaveCount(0);
});

/**
 * 켜기 성공 뒤에도 그 플러그인의 화면 파일(CSS)을 받는 동안은 잠금을 둔다 — 목록이 바뀌자마자 풀면 그 사이 다시 끌 수 있어
 * 받던 파일이 404 가 되고, 엔진이 「Failed to execute action: reloadPluginHandlers」 토스트를 띄웠다(#882 실측).
 *
 * @scenario case=card_toggle_pending
 * @effects card_toggle_holds_until_post_success_work_done, extension_asset_reload_failure_is_silent
 */
test('켜기 성공 뒤 화면 파일을 받는 동안 켜진 채 잠금이 남고, 파일 받기가 실패해도 내부 문구 토스트가 없다', async ({ page }) => {
  await authenticatePage(page, issueToken('core.plugins.read', 'core.plugins.activate'));
  let status: 'active' | 'inactive' = 'inactive';
  let cssRequests = 0;
  await page.route('**/api/admin/extensions/auto-deactivated*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'ok', data: { items: { plugins: [], modules: [], templates: [] }, current_core_version: '7.1.5' } }) }),
  );
  await page.route('**/api/admin/plugins?*', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: listBody(status) }));
  await page.route('**/api/admin/plugins/activate', async (route) => {
    status = 'active';
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, message: 'ok', data: { plugin: { identifier: 'e2e-lockprobe', assets: { css: '/api/plugins/assets/e2e-lockprobe/dist/css/plugin.css' } } } }),
    });
  });
  // 화면 파일은 느리게, 그리고 404 로 — 켜기 직후 다시 꺼졌을 때와 같은 응답
  await page.route('**/api/plugins/assets/e2e-lockprobe/**', async (route) => {
    cssRequests++;
    await new Promise((r) => setTimeout(r, 1200));
    await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'not found' }) });
  });

  await page.goto('/admin/plugins');
  const sw = page.getByRole('switch', { name: NAME, exact: true });
  await expect(sw).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('#content_area .blur-sm')).toHaveCount(0, { timeout: 20_000 });
  expect(await readSwitch(page)).toEqual({ checked: 'false', busy: null, spinner: false });

  await sw.click();
  // 목록이 바뀌어 켜진 값이 보여도, 화면 파일을 받는 동안은 잠금·스피너가 남는다
  await expect.poll(() => readSwitch(page), { timeout: 10_000 }).toEqual({ checked: 'true', busy: 'true', spinner: true });
  await expect.poll(() => cssRequests, { timeout: 10_000 }).toBeGreaterThanOrEqual(1);
  expect((await readSwitch(page)).busy).toBe('true');

  // 세 번 모두 실패한 뒤 잠금이 풀린다 — 내부 문구 토스트는 없다
  await expect.poll(() => readSwitch(page), { timeout: 20_000 }).toEqual({ checked: 'true', busy: null, spinner: false });
  expect(cssRequests).toBe(3);
  await expect(page.getByText('Failed to execute action')).toHaveCount(0);
});

/**
 * @scenario case=card_toggle_pending
 * @effects card_toggle_keeps_value_until_server_confirms, card_toggle_follows_server_value_after_work
 */
test('끄기를 누르면 응답 전까지 켜진 채 스피너, 성공 뒤 목록이 바뀌어야 꺼진다', async ({ page }) => {
  await authenticatePage(page, issueToken('core.plugins.read', 'core.plugins.activate'));
  let setStatus: (s: 'active' | 'inactive') => void = () => {};
  setStatus = await stub(page, async (route) => {
    await new Promise((r) => setTimeout(r, 1500));
    setStatus('inactive');
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'ok', data: { plugin_info: { identifier: 'e2e-lockprobe' } } }) });
  });

  await page.goto('/admin/plugins');
  const sw = page.getByRole('switch', { name: NAME, exact: true });
  await expect(sw).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('#content_area .blur-sm')).toHaveCount(0, { timeout: 20_000 });

  await sw.click();
  await expect.poll(() => readSwitch(page), { timeout: 1_000 }).toEqual({ checked: 'true', busy: 'true', spinner: true });
  // 응답 전 1초 동안 값이 바뀌지 않는다
  await page.waitForTimeout(800);
  expect(await readSwitch(page)).toEqual({ checked: 'true', busy: 'true', spinner: true });

  await expect.poll(() => readSwitch(page), { timeout: 10_000 }).toEqual({ checked: 'false', busy: null, spinner: false });
});
