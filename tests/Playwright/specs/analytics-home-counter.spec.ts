/**
 * E2E: 홈 방문자 카운터 (이슈 #682 S3 라운드 3)
 *
 * 레이아웃 계약 테스트는 조각의 `if` 식과 데이터소스 선언까지만 잠근다. 그런데 이 카운터가 실제로
 * 깨진 방식은 선언이 아니라 **배선**이었다 — 공개 설정을 읽는 경로에 `_global.` 접두가 빠져 조건이
 * 늘 거짓이었고, 그래서 홈에 카드가 아예 없고 `GET /api/analytics/summary` 요청조차 찍히지 않았다
 * (브라우저 실측 M10-T1 에서야 드러났다). 요청도 오류도 없으니 콘솔·로그 어디에도 흔적이 없다.
 *
 * 그래서 이 스펙은 두 방향을 모두 브라우저에서 잰다. 켜면 **요청이 실제로 나가고 카드가 그려지는가**,
 * 끄면 **카드도 요청도 없는가**. 설정은 가드가 원래 값으로 되돌리고, 테스트 전에 없던 설정 파일은 지운다.
 *
 * 축 요약(마커 아님 — 평문): 공개 요약 설정 켬/끔 × 홈 렌더.
 * 효과 요약(마커 아님 — 평문): home_counter_source_is_gated_by_setting,
 *   home_counter_hides_when_disabled_or_failed.
 */
import { expect, issueToken, test } from '../fixtures/auth';
import { AnalyticsSettingsGuard } from '../fixtures/analytics';

/** 공개 방문자 요약 API */
const SUMMARY_PATH = '/api/analytics/summary';

test.describe('홈 방문자 카운터', () => {
  // @scenario screen=home_widget, state=normal, viewport=1440, locale=ko, e2e=home_counter
  // @effects home_counter_source_is_gated_by_setting
  test('공개 요약을 켜면 홈이 요약 API 를 부르고 카운터 네 숫자를 그린다', async ({ page }) => {
    const token = issueToken('core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    await guard.apply({ public_summary_enabled: true });

    try {
      const summary = page.waitForResponse((r) => r.url().includes(SUMMARY_PATH), { timeout: 20_000 });
      await page.goto('/');
      const response = await summary;
      expect(response.status()).toBe(200);

      const counter = page.locator('[data-visitor-counter]');
      await expect(counter).toBeVisible({ timeout: 20_000 });

      // 라벨과 값이 짝으로 읽혀야 한다 — 숫자 넷이 맥락 없이 떠 있으면 스크린리더가 읽지 못한다.
      await expect(counter).toHaveAttribute('role', 'group');
      const values = counter.locator('[data-visitor-counter-value]');
      await expect(values).toHaveCount(4);
      for (const key of ['today', 'yesterday', 'max', 'total']) {
        await expect(counter.locator(`[data-visitor-counter-value="${key}"]`)).toHaveText(/^[0-9,]+$/);
      }
    } finally {
      await guard.restore();
    }
  });

  // @scenario screen=home_widget, state=empty, viewport=1440, locale=ko, e2e=home_counter
  // @effects home_counter_hides_when_disabled_or_failed
  test('공개 요약을 끄면 카드도 요청도 없다 — 0 을 그리지 않는다', async ({ page }) => {
    const token = issueToken('core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    await guard.apply({ public_summary_enabled: false });

    try {
      const calls: string[] = [];
      page.on('request', (request) => {
        if (request.url().includes(SUMMARY_PATH)) calls.push(request.url());
      });

      await page.goto('/');
      // 홈 본문이 떴는지 먼저 확인한다 — 화면 자체가 안 떴는데 「카운터 없음」을 통과로 읽으면 안 된다.
      await expect(page.locator('body')).toBeVisible();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('[data-visitor-counter]')).toHaveCount(0);
      expect(calls, '설정을 껐는데도 요약 API 를 불렀다').toEqual([]);
    } finally {
      await guard.restore();
    }
  });
});
