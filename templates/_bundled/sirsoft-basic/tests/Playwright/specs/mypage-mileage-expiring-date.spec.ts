/**
 * 마이페이지 마일리지 요약 「소멸 예정」 칸의 날짜 문구가 보는 사람 날짜로 보이는지 검증 (#160).
 *
 * 결함: 「…까지」 문구에 ISO 원문(`expiring_date` — 「2026-12-31T14:59:59+00:00」)을 그대로 넣어 시각·오프셋이 보였다.
 *   이커머스 잔액 API 가 함께 내려주는 `expiring_date_formatted` 를 쓰도록 바꿨다.
 *
 * 잔액 응답은 page.route 로 고정한다 — 실제 회원의 소멸 예정분 유무와 무관하게 카드 문구의 바인딩만 잰다.
 * 서버 값(사이트·보는 사람 시간대 변환)은 PHPUnit(UserMileageBalanceTimezoneTest)이 잠근다.
 *
 * @scenario mypage-mileage-expiring-date
 * @axes value=formatted_present
 * @effects expiring_until_shown_in_browser
 */
import { test, expect } from '@playwright/test';

import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

const BALANCE = {
  success: true,
  message: 'ok',
  data: {
    mileage: {
      enabled: true,
      available: 100,
      pending: 0,
      expiring_soon: 100,
      expiring_date: '2026-12-31T14:59:59+00:00',
      expiring_date_formatted: '2026-12-31',
      total_earned: 100,
      total_used: 0,
      by_currency: {},
    },
  },
};

test.describe('마이페이지 마일리지 — 소멸 예정일', () => {
  /**
   * @scenario value=formatted_present
   * @effects expiring_until_shown_in_browser
   */
  test('「…까지」 문구가 보는 사람 날짜로 보이고 ISO 원문이 보이지 않는다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    await page.route('**/api/modules/sirsoft-ecommerce/user/mileage', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(BALANCE),
    }));

    await page.goto('/mypage/mileage');

    // 첫 화면은 템플릿 번들·데이터소스를 모두 받은 뒤에 그려진다 — 이 템플릿 스펙들의 관례대로 넉넉히 기다린다.
    const card = page.getByText(/2026-12-31/).first();
    await expect(card).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('T14:59:59')).toHaveCount(0);
    await expect(page.getByText('+00:00')).toHaveCount(0);
  });
});
