/**
 * 쿠폰 「타 쿠폰과 중복 사용」 라디오 boolean 저장 — 공개 이슈 #97 회귀.
 *
 * 결함: 라디오 클릭 시 DOM 문자열 "true"/"false" 가 폼 상태에 그대로 저장되어
 *   저장 요청이 서버 boolean 규칙에서 422 (`is combinable 필드는 true 또는
 *   false여야 합니다.`) 로 실패했다. 표시 계층은 느슨 비교로 정상이라 저장
 *   시점에야 드러난다. 라디오를 클릭하지 않으면 초기 boolean 이 전송되어
 *   저장이 되는 것이 제보의 "임시 우회" 였다.
 *
 * 검증: 등록 화면에서 "불가능" 을 실제 클릭해 저장(POST body 의 is_combinable 이
 *   boolean false)하고, 수정 화면에서 저장값 반영 + "가능" 재선택 저장(PUT 200)
 *   까지 왕복한다. 생성한 쿠폰은 API 로 정리한다.
 *
 * @scenario issue97-coupon-combinable-boolean
 * @axes request=store,update surface=browser_form
 * @effects radio_click_saves_boolean_state, edit_screen_reflects_saved_value
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const CREATE_URL = '/admin/ecommerce/promotion-coupon-create';
const LIST_URL_PATTERN = /\/admin\/ecommerce\/promotion-coupons(\?|$)/;
const API_BASE = '/api/modules/sirsoft-ecommerce/admin/promotion-coupons';

/** 폼 자동바인딩 debounce(300ms) 유실 방지 — 필드별 순차 입력 + 대기 */
async function fillDebounced(
  page: import('@playwright/test').Page,
  selector: string,
  value: string,
): Promise<void> {
  await page.locator(selector).fill(value);
  await page.waitForTimeout(500);
}

test.describe('쿠폰 중복 사용 라디오 boolean 저장 (#97)', () => {
  // @scenario request=store surface=browser_form
  // @effects radio_click_saves_boolean_state
  test('등록: "불가능" 클릭 후 저장하면 boolean false 로 201 저장되고, 수정 화면 반영 + "가능" 재저장까지 왕복한다', async ({
    page,
    couponManageToken,
  }) => {
    await authenticatePage(page, couponManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(page.locator('input[name="is_combinable"][value="false"]')).toBeVisible({
      timeout: 30_000,
    });

    // 필수값 입력 — debounce 유실 방지를 위해 필드별 순차 입력
    const today = new Date();
    const nextMonth = new Date(today.getTime() + 30 * 24 * 60 * 60 * 1000);
    const fmt = (d: Date) => d.toISOString().slice(0, 10);

    await fillDebounced(page, 'input[name="name[ko]"]', 'E2E 중복사용 라디오 검증 쿠폰');
    await fillDebounced(page, 'input[name="discount_value"]', '1000');
    await fillDebounced(page, 'input[name="valid_from"]', fmt(today));
    await fillDebounced(page, 'input[name="valid_to"]', fmt(nextMonth));

    // 핵심 조작: "불가능" 라디오를 실제로 클릭한다 (수정 전에는 이 클릭이 422 를 만들었다)
    await page.locator('input[name="is_combinable"][value="false"]').check();
    await page.waitForTimeout(500);

    // 저장 → POST 요청 본문의 is_combinable 이 boolean false 인지 채증
    const [request, response] = await Promise.all([
      page.waitForRequest(
        (req) => req.url().includes(API_BASE) && req.method() === 'POST',
        { timeout: 30_000 },
      ),
      page.waitForResponse(
        (res) => res.url().includes(API_BASE) && res.request().method() === 'POST',
        { timeout: 30_000 },
      ),
      page.locator('#save_button_top').click(),
    ]);

    const body = request.postDataJSON() as Record<string, unknown>;
    expect(body.is_combinable).toBe(false); // boolean false — 문자열 "false" 금지
    expect(response.status()).toBe(201);

    const created = (await response.json()) as { data?: { id?: number } };
    const couponId = created.data?.id;
    expect(couponId, '생성 응답에서 쿠폰 id 를 얻지 못했습니다.').toBeTruthy();

    try {
      // 저장 성공 → 목록 복귀
      await page.waitForURL(LIST_URL_PATTERN, { timeout: 30_000 });

      // 수정 화면: 저장된 false 가 "불가능" checked 로 반영되는지
      await page.goto(`/admin/ecommerce/promotion-coupons/${couponId}/edit`);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
      const noRadio = page.locator('input[name="is_combinable"][value="false"]');
      await expect(noRadio).toBeVisible({ timeout: 30_000 });
      await expect(noRadio).toBeChecked({ timeout: 30_000 });

      // "가능" 재선택 후 저장 → PUT 200 + body boolean true
      await page.locator('input[name="is_combinable"][value="true"]').check();
      await page.waitForTimeout(500);

      const [putRequest, putResponse] = await Promise.all([
        page.waitForRequest(
          (req) => req.url().includes(`${API_BASE}/${couponId}`) && req.method() === 'PUT',
          { timeout: 30_000 },
        ),
        page.waitForResponse(
          (res) =>
            res.url().includes(`${API_BASE}/${couponId}`) && res.request().method() === 'PUT',
          { timeout: 30_000 },
        ),
        page.locator('#save_button_top').click(),
      ]);

      const putBody = putRequest.postDataJSON() as Record<string, unknown>;
      expect(putBody.is_combinable).toBe(true);
      expect(putResponse.status()).toBe(200);

      await page.waitForURL(LIST_URL_PATTERN, { timeout: 30_000 });
    } finally {
      // 정리: 생성한 쿠폰 삭제 (페이지 컨텍스트의 토큰 재사용)
      const deleteStatus = await page.evaluate(
        async ({ apiBase, id }) => {
          const res = await fetch(`${apiBase}/${id}`, {
            method: 'DELETE',
            headers: {
              Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
              Accept: 'application/json',
            },
          });
          return res.status;
        },
        { apiBase: API_BASE, id: couponId },
      );
      expect([200, 204]).toContain(deleteStatus);
    }
  });
});
