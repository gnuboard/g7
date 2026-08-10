/**
 * E2E: 관리자 대시보드 — 자산 URL 방식 드리프트 안내 (이슈 #486 §5)
 *
 * 배경: 브라우저 자가 복구는 방문자 화면을 살리지만 **저장된 설정은 그대로 틀린 상태**로
 * 남는다. 검색엔진 봇은 JavaScript 를 실행하지 않아 자가 복구가 닿지 않으므로, 저장값을
 * 고치지 않으면 SEO 는 계속 깨진다. 그런데 화면이 멀쩡해 보이므로 관리자는 문제를
 * 인지할 계기가 없다 — 대시보드가 스스로 프로브를 던져 저장값과 대조하는 이유다.
 *
 * L5(클라이언트는 서버 설정을 쓰지 않는다)를 지킨다: 이 화면은 **알리기만** 하고,
 * 저장은 관리자가 환경설정에서 직접 수행한다.
 *
 * 검증:
 *  1. 저장값과 실제 환경이 일치하면 안내가 뜨지 않는다 (상시 경고는 소음이 된다)
 *  2. 확장자 형태만 가로채이면 안내가 뜨고, 버튼이 환경설정 일반 탭으로 보낸다
 *  3. 프로브가 양쪽 다 실패하면(PHP/네트워크 장애) 안내가 뜨지 않는다
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const DRIFT_ALERT = '#asset_url_mode_drift_alert';
const PROBE_WITH_EXT = '**/api/system/asset-probe.js*';
const PROBE_WITHOUT_EXT = '**/api/system/asset-probe';

/**
 * 프로브 응답을 가로채 특정 환경을 모사한다.
 *
 * 실제 정적 최적화 블록은 경로가 정적 확장자로 끝나는 요청만 가로채므로,
 * `.js` 프로브만 404 로 만드는 것이 그 환경의 정확한 재현이다.
 *
 * @param page      대상 페이지
 * @param withExt   확장자 형태 프로브 성공 여부
 * @param withoutExt 확장자 없는 형태 프로브 성공 여부
 */
async function stubProbes(
  page: import('@playwright/test').Page,
  withExt: boolean,
  withoutExt: boolean,
): Promise<void> {
  const block = async (route: import('@playwright/test').Route) => {
    await route.fulfill({ status: 404, contentType: 'text/html', body: 'Not Found' });
  };

  if (!withExt) {
    await page.route(PROBE_WITH_EXT, block);
  }
  if (!withoutExt) {
    await page.route(PROBE_WITHOUT_EXT, block);
  }
}

/** 대시보드 진입 후 드리프트 판정이 끝날 때까지 대기 */
async function gotoDashboard(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 판정은 프로브 2건의 왕복이 끝나야 나온다. 요청이 실제로 나갔는지로 대기 조건을 잡는다.
  await page
    .waitForResponse((res) => res.url().includes('/api/system/asset-probe'), { timeout: 20_000 })
    .catch(() => undefined);
}

test.describe('관리자 대시보드 — 자산 URL 방식 드리프트 안내', () => {
  test.beforeEach(async ({ page }) => {
    const token = issueToken('core.dashboard.read', 'core.settings.read');
    await authenticatePage(page, token);
  });

  test('저장값과 실제 환경이 일치하면 안내가 뜨지 않는다', async ({ page }) => {
    await stubProbes(page, true, true);
    await gotoDashboard(page);

    await expect(
      page.locator(DRIFT_ALERT),
      '드리프트가 없는데 경고가 떴다 — 상시 경고는 신호가 아니라 소음이 된다',
    ).toHaveCount(0);
  });

  test('확장자 형태가 가로채이면 안내가 뜨고 환경설정으로 이동시킨다', async ({ page }) => {
    await stubProbes(page, false, true);
    await gotoDashboard(page);

    const alert = page.locator(DRIFT_ALERT);
    await expect(
      alert,
      '확장자 주소가 가로채이는데 대시보드가 아무것도 알리지 않았다 — 봇에게는 자가 복구가 닿지 않는다',
    ).toBeVisible({ timeout: 20_000 });

    await alert.locator('#asset_url_mode_drift_link').click();

    await expect(page).toHaveURL(/\/admin\/settings\?.*tab=general/, { timeout: 20_000 });
  });

  test('프로브가 양쪽 다 실패하면 안내가 뜨지 않는다 (모드 문제가 아님)', async ({ page }) => {
    await stubProbes(page, false, false);
    await gotoDashboard(page);

    await expect(
      page.locator(DRIFT_ALERT),
      '일시적 네트워크 장애를 설정 불일치로 오인해 경고했다',
    ).toHaveCount(0);
  });
});
