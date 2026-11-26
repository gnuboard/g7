/**
 * sirsoft-admin_basic — 환경설정 「고급」 탭의 신뢰 프록시 편집.
 *
 * 브라우저에서만 확정되는 것을 잰다. 이 화면의 결함은 **오류를 내지 않기** 때문이다 —
 * 라디오가 엉뚱한 값을 들고 있어도, 조건부 필드가 안 나타나도 화면은 그려지고 저장도 된다.
 *
 * 1. **모드 라디오의 `value` 가 서로 다르게 유지된다.** 실측(2026-09-12)에서 이것이 깨졌다:
 *    라디오에 `name` 이 있으면 엔진의 폼 자동바인딩이 `value` 를 폼 값으로 되써서, 한 번
 *    클릭한 뒤 **세 라디오가 전부 같은 값**이 됐다(초기 none/nearest/list → 클릭 후 셋 다
 *    nearest). `checked` 는 맞게 붙어 있어 눈으로는 드러나지 않았고, 레이아웃 JSON 을 읽는
 *    계약 테스트도 잡지 못했다 — 렌더를 거쳐야만 보이는 축이다.
 * 2. **조건부 표시가 모드를 따라간다.** 주소 목록은 「지정한 주소만」에서만, 위조 경고는
 *    「신뢰하지 않음」이 아닐 때만 나온다. 경고가 늘 떠 있으면 배경 소음이 되어 읽히지 않는다.
 * 3. **사전 검사가 저장 없이 결과를 돌려준다.** 이 설정은 잘못 저장하면 관리자 화면 자체에
 *    못 들어가 되돌릴 통로가 사라지므로, 저장 전에 결과를 보는 경로가 살아 있어야 한다.
 *
 * 프록시 헤더가 **있는** 경우의 해석 판정은 여기서 재지 않는다 — 브라우저 fetch 는
 * `X-Forwarded-*` 를 붙일 수 없다(금지 헤더). 그 축은
 * `tests/Feature/Settings/TrustedProxySettingsTest.php` 가 소유한다.
 *
 * @scenario proxies_setting=unset, forwarded_proto=absent
 * @axes proxies_setting=unset, forwarded_proto=absent
 * @effects mode_choice_writes_the_saved_list, the_spoof_warning_shows_only_when_trust_is_opened, probe_reports_resolution_without_saving
 */
import { test, expect } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/auth';

test.describe.configure({ mode: 'serial' });

/** 모드 라디오 — 카드 안에서 순서가 곧 none / nearest / list 다. */
const MODES = ['none', 'nearest', 'list'] as const;

test.describe('@sirsoft-admin_basic 환경설정 고급 — 신뢰 프록시 편집', () => {
  test.beforeEach(async ({ page }) => {
    await authenticatePage(page, issueToken());
    await page.goto('/admin/settings?tab=advanced');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    await expect(page.locator('#trusted_proxy_edit')).toBeVisible();
  });

  // @scenario proxies_setting=star
  // @effects mode_choice_writes_the_saved_list
  test('모드를 바꿔도 세 라디오의 value 가 서로 달라진 채 유지된다', async ({ page }) => {
    const radios = page.locator('#trusted_proxy_modes input[type="radio"]');
    await expect(radios).toHaveCount(3);

    const values = async () => radios.evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));

    expect(await values()).toEqual([...MODES]);

    // 한 번 고르고 나면 자동바인딩이 value 를 덮을 수 있다 — 그 회귀를 여기서 잡는다.
    for (const index of [1, 2, 0]) {
      await radios.nth(index).click();
      await expect(radios.nth(index)).toBeChecked();

      expect(
        await values(),
        `${MODES[index]} 를 고른 뒤 라디오 value 가 서로 같아졌다 — 폼 자동바인딩이 덮었다`
      ).toEqual([...MODES]);

      // 상호배타는 native name 그룹이 아니라 checked 표현식이 보장한다.
      await expect(radios.nth((index + 1) % 3)).not.toBeChecked();
      await expect(radios.nth((index + 2) % 3)).not.toBeChecked();
    }
  });

  // @scenario proxies_setting=cidr
  // @effects mode_choice_writes_the_saved_list
  test('주소 목록은 「지정한 주소만」에서만 나타난다', async ({ page }) => {
    const radios = page.locator('#trusted_proxy_modes input[type="radio"]');
    const listField = page.locator('#trusted_proxy_list_field');

    await expect(listField).toHaveCount(0);

    await radios.nth(1).click();
    await expect(listField).toHaveCount(0);

    await radios.nth(2).click();
    await expect(listField).toBeVisible();

    await radios.nth(0).click();
    await expect(listField).toHaveCount(0);
  });

  // @scenario proxies_setting=star
  // @effects the_spoof_warning_shows_only_when_trust_is_opened
  test('위조 경고는 신뢰를 여는 모드에서만 나온다', async ({ page }) => {
    const radios = page.locator('#trusted_proxy_modes input[type="radio"]');
    const warning = page.locator('#trusted_proxy_spoof_warning');

    await expect(warning).toHaveCount(0);

    await radios.nth(1).click();
    await expect(warning).toBeVisible();

    await radios.nth(0).click();
    await expect(warning).toHaveCount(0);
  });

  // @scenario proxies_setting=star, forwarded_proto=absent
  // @effects probe_reports_resolution_without_saving
  test('사전 검사가 저장하지 않고 결과 블록을 띄운다', async ({ page }) => {
    const result = page.locator('#trusted_proxy_probe_result');
    await expect(result).toHaveCount(0);

    await page.locator('#trusted_proxy_modes input[type="radio"]').nth(1).click();

    const request = page.waitForRequest(
      (r) => r.url().includes('/api/admin/settings/test-trusted-proxy') && r.method() === 'POST'
    );
    await page.locator('#btn_probe_trusted_proxy').click();

    // 저장이 아니라 검사다 — 설정 저장 엔드포인트로 나가면 계약 위반이다.
    const sent = await request;
    expect(sent.postDataJSON()).toHaveProperty('trusted_proxies');

    await expect(result).toBeVisible();
    // 앞단이 없는 실행 환경에서는 「달라지는 것이 없다」가 올바른 답이다.
    await expect(result).toContainText('검사한 결과');
  });
});
