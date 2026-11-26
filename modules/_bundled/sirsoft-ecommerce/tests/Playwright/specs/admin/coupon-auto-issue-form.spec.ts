/**
 * 쿠폰 등록 폼 — 자동발급 칸의 노출 전환
 *
 * 발급방법을 「자동」으로 고르면 자동 조건 칸과 「한 번만 발급」 안내가 나오고 회원당 한도 칸이 사라진다.
 * 조건을 첫구매로 고르면 발급 시점 선택이 나온다. 「다운로드」로 바꾸면 발급 알림 구획이 사라진다.
 * 저장하지 않는다 — 화면 전환만 잰다(저장·검증은 PHPUnit CouponAutoIssueValidationTest).
 *
 * 소속 시나리오(마커 아님 — 평문): coupon-auto-issue
 * 효과 요약(마커 아님 — 평문): coupon_form_auto_condition_fields, coupon_form_first_purchase_trigger, coupon_form_notification_section
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const CREATE_URL = '/admin/ecommerce/promotion-coupon-create';

test.describe('쿠폰 등록 폼 — 자동발급', () => {
  // @scenario case=admin_coupon_form_browser
  // @effects coupon_form_auto_condition_fields
  test('자동을 고르면 자동 조건·한 번만 안내가 나오고, 첫구매면 발급 시점, 다운로드면 알림 구획이 사라진다', async ({ page, couponManageToken }) => {
    await authenticatePage(page, couponManageToken);
    await page.goto(CREATE_URL);
    const auto = page.locator('input[name="issue_method"][value="auto"]');
    await expect(auto).toBeVisible({ timeout: 30_000 });

    await auto.check();
    await expect(page.locator('#field_issue_condition_auto')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#auto_issue_limit_guide')).toBeVisible();
    await expect(page.locator('#field_reissue')).toHaveCount(0);

    await page.locator('input[name="issue_condition"][value="first_purchase"]').check();
    await expect(page.locator('#field_first_purchase_trigger')).toBeVisible({ timeout: 10_000 });
    // 옵션 목록형 선택 상자(버튼)라 기본값 라벨로 잰다
    await expect(page.locator('#field_first_purchase_trigger')).toContainText('구매확정');

    await page.locator('input[name="issue_condition"][value="signup"]').check();
    await expect(page.locator('#field_first_purchase_trigger')).toHaveCount(0);
    await expect(page.locator('#signup_issue_guide')).toBeVisible();

    await expect(page.locator('#notification_settings_section')).toBeVisible();
    await page.locator('input[name="issue_method"][value="download"]').check();
    await expect(page.locator('#notification_settings_section')).toHaveCount(0);
    await expect(page.locator('#field_reissue')).toBeVisible();
  });
});
