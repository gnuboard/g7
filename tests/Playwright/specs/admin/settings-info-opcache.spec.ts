/**
 * E2E: 환경설정 > 정보 — OPcache 상태 행 및 성능 저하 경고
 *
 * @scenario admin_settings_info_opcache
 * @effects opcache_row_mounted, opcache_warning_conditional
 *
 * 배경: OPcache 는 코어 어디에서도 상태를 조회하지 않아, 비활성 환경에서 성능이
 * 떨어져도 운영자가 인지할 경로가 없었다. 정보 탭에 상태를 노출하고, 비활성일 때만
 * 성능 저하 경고를 띄운다.
 *
 * 검증:
 *  1. 정보 탭에 OPcache 행이 마운트되고 값이 raw 키가 아닌 번역문으로 표시된다
 *  2. 경고 블록은 enabled=false 일 때만 뜬다 (활성/확인불가에서는 뜨지 않는다)
 *     — API 응답을 가로채 세 상태를 모두 주입해 조건부 렌더를 검증한다
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const OPCACHE_ROW = '#opcache_row';
const OPCACHE_WARNING = '#opcache_warning';
const SYSTEM_INFO_API = '**/api/admin/settings/system-info*';

/** 관리자 환경설정 정보 탭 진입 */
async function gotoInfoTab(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/settings?tab=info');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator(OPCACHE_ROW)).toBeAttached({ timeout: 20_000 });
}

/**
 * system-info 응답의 opcache 필드만 교체해 라우팅한다.
 * 나머지 필드는 실제 서버 응답을 그대로 쓴다 (정보 탭 전체가 깨지지 않도록).
 */
async function stubOpcache(
  page: import('@playwright/test').Page,
  opcache: { loaded: boolean; enabled: boolean | null },
): Promise<void> {
  await page.route(SYSTEM_INFO_API, async (route) => {
    try {
      const response = await route.fetch();
      const body = await response.json();

      if (body?.data) {
        body.data.opcache = opcache;
      }

      await route.fulfill({ response, json: body });
    } catch {
      // 테스트가 끝난 뒤 도착한 요청에서 `route.fetch()` 는 "Test ended" 로 거부된다.
      // 이 콜백은 테스트가 await 하지 않으므로 그 거부가 **어느 테스트에도 속하지 않는 에러**가
      // 되고, 워커가 중단되어 남은 테스트가 "did not run" 으로 집계된다(실측: 전수 실행에서
      // 에러 2건 → 미실행 2건). 종료 직후 요청은 조용히 통과시킨다.
      await route.continue().catch(() => undefined);
    }
  });
}

// @scenario tab=info, permitted=yes
// @effects opcache_row_mounted
test('@smoke - 정보 탭에 OPcache 상태 행이 번역문과 함께 마운트된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await gotoInfoTab(page);
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const row = page.locator(OPCACHE_ROW);
  await expect(row).toBeAttached();

  // 다국어 키가 해석되지 않으면 "$t:admin.settings.info.opcache" 원문이 그대로 노출된다
  const text = (await row.innerText()).trim();
  expect(text).not.toContain('$t:');
  expect(text).toContain('OPcache');

  // 값이 비어 있으면 상태 표현식이 해석되지 않은 것이다 (바인딩 회귀 가드)
  expect(text.replace('OPcache', '').trim().length).toBeGreaterThan(0);
});

// @scenario tab=info, opcache_enabled=false
// @effects opcache_warning_conditional
test('- OPcache 비활성이면 성능 저하 경고 블록이 표시된다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await stubOpcache(page, { loaded: true, enabled: false });
  await gotoInfoTab(page);

  await expect(page.locator(OPCACHE_WARNING)).toBeVisible({ timeout: 20_000 });

  const warningText = (await page.locator(OPCACHE_WARNING).innerText()).trim();
  expect(warningText).not.toContain('$t:');
  expect(warningText.length).toBeGreaterThan(0);
});

// @scenario tab=info, opcache_enabled=false
// @effects opcache_warning_placed_at_tab_top
test('- 경고 블록이 정보 탭 최상단에 놓이고 아래 카드와 간격을 둔다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await stubOpcache(page, { loaded: true, enabled: false });
  await gotoInfoTab(page);

  const warning = page.locator(OPCACHE_WARNING);
  await expect(warning).toBeVisible({ timeout: 20_000 });

  // 경고는 카드 그리드보다 위에 있어야 한다. 그리드 안(세 번째 셀)에 들어가면
  // 카드 사이에 끼어 배너로 읽히지 않는다 — 그 회귀를 좌표로 고정한다.
  const grid = page.locator('#tab_content_info > .grid-2col-responsive').first();
  await expect(grid).toBeVisible();

  const warningBox = await warning.boundingBox();
  const gridBox = await grid.boundingBox();
  expect(warningBox).not.toBeNull();
  expect(gridBox).not.toBeNull();
  expect(warningBox!.y).toBeLessThan(gridBox!.y);

  // 경고와 카드가 붙지 않아야 한다 (자산이 부여하는 하단 간격).
  const gap = gridBox!.y - (warningBox!.y + warningBox!.height);
  expect(gap).toBeGreaterThan(0);

  // 경고가 그리드 셀이 아니라 탭의 직계 자식이어야 한다 (구조 단언)
  await expect(page.locator('#tab_content_info > #opcache_warning')).toHaveCount(1);
});

// @scenario tab=info, opcache_enabled=true
// @effects opcache_warning_conditional
test('- OPcache 활성이면 경고 블록이 표시되지 않는다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await stubOpcache(page, { loaded: true, enabled: true });
  await gotoInfoTab(page);

  // 행이 떠 있음을 먼저 확인해야(긍정 앵커) 아래 부정 단언이 의미를 갖는다
  await expect(page.locator(OPCACHE_ROW)).toBeVisible();
  await expect(page.locator(OPCACHE_WARNING)).toHaveCount(0);
});

// @scenario tab=info, opcache_enabled=null
// @effects opcache_warning_conditional
test('- OPcache 확인 불가(null)면 경고 블록이 표시되지 않는다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  // `!enabled` 로 조건을 쓰면 null 에서도 경고가 잘못 뜬다 — 엄격 비교 회귀 가드
  await stubOpcache(page, { loaded: true, enabled: null });
  await gotoInfoTab(page);

  await expect(page.locator(OPCACHE_ROW)).toBeVisible();
  await expect(page.locator(OPCACHE_WARNING)).toHaveCount(0);
});
