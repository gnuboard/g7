/**
 * 미배치 공식 번들 안내 — 관리자 목록 4화면 브라우저 회귀 (공개 #146)
 *
 * 안내 데이터는 코어 업데이트가 서버에 남기는 기록 파일에서 오므로, 이 spec 은 안내 API 응답을
 * `page.route` 로 고정해 화면 계약만 잰다(기록 파일·DB 를 건드리지 않는다).
 * - 화면마다 자기 유형 항목만 그리고(role="status"), 배치 명령을 보인다
 * - [이 안내 닫기] 는 그 유형 dismiss 를 POST 하고, 다시 받은 응답이 비면 블록이 사라진다
 * - dismiss 가 403 이면 서버 문구를 토스트로 보이고 블록은 남는다
 *
 * 소유 매니페스트: tests/scenarios/core-update-bundled-sources.yaml (테스트별 마킹은 각 test 위)
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

test.describe.configure({ timeout: 60_000 });

type Plural = 'modules' | 'plugins' | 'templates' | 'language_packs';

const SCREENS: Array<{ path: string; type: string; plural: Plural; permission: string; noun: string }> = [
  { path: '/admin/modules', type: 'module', plural: 'modules', permission: 'core.modules.read', noun: '모듈' },
  { path: '/admin/plugins', type: 'plugin', plural: 'plugins', permission: 'core.plugins.read', noun: '플러그인' },
  { path: '/admin/templates/user', type: 'template', plural: 'templates', permission: 'core.templates.read', noun: '템플릿' },
  { path: '/admin/settings?tab=language_packs', type: 'language_pack', plural: 'language_packs', permission: 'core.language_packs.read', noun: '언어팩' },
];

/**
 * 안내 API 응답 본문을 만든다.
 *
 * @param plural 항목을 채울 유형 키
 * @param count 항목 수 (0 이면 빈 응답)
 * @returns ResponseHelper 봉투 JSON
 */
function noticeBody(plural: Plural, count: number): string {
  const items: Record<Plural, unknown[]> = { modules: [], plugins: [], templates: [], language_packs: [] };
  const list = Array.from({ length: count }, (_, i) => ({ identifier: `e2e-unplaced-${i + 1}`, name: `E2E 번들 ${i + 1}`, version: '1.0.0' }));
  items[plural] = list;
  const commands: Record<string, string> = {};
  if (count > 0) commands[plural] = `php artisan core:bundled-sources place ${list.map((x) => x.identifier).join(' ')}`;
  return JSON.stringify({
    success: true,
    message: 'ok',
    data: { items, hash: count > 0 ? 'e2e-hash' : null, core_version: '7.1.1', command: 'php artisan core:bundled-sources place', commands },
  });
}

/**
 * 안내 GET 을 고정한다 — 첫 응답은 count 개, 이후(닫기 뒤 재조회)는 afterCount 개.
 *
 * @param page 페이지
 * @param plural 항목 유형 키
 * @param count 첫 응답 항목 수
 * @param afterCount 두 번째 이후 응답 항목 수
 * @returns 지금까지의 GET 호출 수를 돌려주는 함수
 */
async function stubNotice(page: Page, plural: Plural, count: number, afterCount = count): Promise<() => number> {
  let calls = 0;
  await page.route('**/api/admin/extensions/unplaced-bundled', async (route) => {
    calls += 1;
    await route.fulfill({ status: 200, contentType: 'application/json', body: noticeBody(plural, calls === 1 ? count : afterCount) });
  });
  await page.route('**/api/admin/extensions/auto-deactivated*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, message: 'ok', data: { items: { plugins: [], modules: [], templates: [] }, current_core_version: '7.1.1' } }),
    });
  });

  return () => calls;
}

for (const scr of SCREENS) {
  /**
   * @effects notice_block_renders_per_screen_type
   */
  test(`${scr.noun} 화면이 자기 유형의 미배치 공식 번들 안내와 배치 명령을 보인다`, async ({ page }) => {
    await authenticatePage(page, issueToken(scr.permission));
    await stubNotice(page, scr.plural, 2);

    await page.goto(scr.path);
    const block = page.locator('[role="status"].alert-info');
    await expect(block).toHaveCount(1, { timeout: 20_000 });
    await expect(block).toContainText(`배치하지 않은 공식 ${scr.noun} 2개`);
    await expect(block.locator('code')).toHaveText('php artisan core:bundled-sources place e2e-unplaced-1 e2e-unplaced-2');
    await expect(block.getByRole('button', { name: '이 안내 닫기' })).toBeVisible();
    await expect(block).not.toContainText('$t:');
  });
}

/**
 * @effects notice_dismiss_posts_refetches_and_toasts_on_failure
 */
test('[이 안내 닫기] 가 그 유형 dismiss 를 POST 하고, 다시 받은 응답이 비면 블록이 사라진다', async ({ page }) => {
  await authenticatePage(page, issueToken('core.plugins.read'));
  const getCalls = await stubNotice(page, 'plugins', 1, 0);
  const posts: string[] = [];
  await page.route('**/api/admin/extensions/unplaced-bundled/*/dismiss', async (route) => {
    posts.push(new URL(route.request().url()).pathname + ' ' + route.request().method());
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'ok', data: { type: 'plugin', hash: 'e2e-hash' } }) });
  });

  await page.goto('/admin/plugins');
  const block = page.locator('[role="status"].alert-info');
  await expect(block).toHaveCount(1, { timeout: 20_000 });
  await block.getByRole('button', { name: '이 안내 닫기' }).click();

  await expect(block).toHaveCount(0, { timeout: 10_000 });
  expect(posts).toEqual(['/api/admin/extensions/unplaced-bundled/plugin/dismiss POST']);
  expect(getCalls()).toBeGreaterThanOrEqual(2);
});

/**
 * @effects notice_dismiss_posts_refetches_and_toasts_on_failure
 */
test('dismiss 가 403 이면 서버 문구를 토스트로 보이고 블록은 남는다', async ({ page }) => {
  await authenticatePage(page, issueToken('core.plugins.read'));
  await stubNotice(page, 'plugins', 1);
  await page.route('**/api/admin/extensions/unplaced-bundled/*/dismiss', async (route) => {
    await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ success: false, message: '해당 권한이 없습니다.' }) });
  });

  await page.goto('/admin/plugins');
  const block = page.locator('[role="status"].alert-info');
  await expect(block).toHaveCount(1, { timeout: 20_000 });
  await block.getByRole('button', { name: '이 안내 닫기' }).click();

  await expect(page.getByText('해당 권한이 없습니다.').first()).toBeVisible({ timeout: 10_000 });
  await expect(block).toHaveCount(1);
});
