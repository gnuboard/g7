/**
 * 주소 검색 SDK 를 못 불러왔을 때 주소를 직접 입력해 주문을 진행할 수 있는지 검증 (공개 이슈 #123).
 *
 * 배경: 우편번호/주소 칸은 평소 `sirsoft-daum_postcode` 플러그인이 채운다. 그래서 두 칸은
 *   화면에서 `readOnly` 로 잠겨 있고, 플러그인의 `openPostcode` 가 `_local.shipping.*` 에
 *   값을 직접 써 넣는 유일한 기록자였다.
 *
 *   #123 이 도입한 폴백은 SDK 를 못 불러오면 그 잠금을 풀어 사용자가 직접 칠 수 있게 한다.
 *   그런데 두 칸에는 다른 필드(`recipient_name`·`address_detail`)와 달리 입력값을 상태로
 *   옮기는 `change` 액션이 없었다 — 평소에는 플러그인이 써 주니 드러나지 않던 공백이다.
 *
 *   실측(2026-08-26): SDK 차단 상태에서 두 칸에 값을 쳐도 `_local.shipping.zipcode` 와
 *   `.address` 가 계속 `null` 이었고, 결제 버튼의 활성 조건이 바로 그 두 값이라 버튼이
 *   영영 비활성이었다. 화면상 입력은 되는데 주문만 못 끝내는 상태 — 오류도 안내도 없다.
 *
 * 그래서 이 spec 은 "칸이 편집 가능한가" 가 아니라 **친 값이 주문 상태에 도달하는가** 를 잰다.
 * 편집 가능 여부만 보면 이 결함이 있는 채로도 통과한다.
 *
 * @scenario asset_class=vendored, outcome=failed
 * @effects failed_asset_falls_back_to_plain_input, failed_asset_shows_retry_notice
 */
import { test, expect, type Page } from '@playwright/test';

import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

/** 주소 검색 SDK (외부 서비스 — 자체 제공 대상이 아니다) */
const POSTCODE_SDK = /t1\.daumcdn\.net/i;

/**
 * 판매 중인 상품 1건을 장바구니에 담습니다 (주문서 진입 조건).
 *
 * @param page 대상 페이지 (인증 토큰 주입 상태)
 * @return void
 */
async function seedCart(page: Page): Promise<void> {
  const result = await page.evaluate(async () => {
    const token = localStorage.getItem('auth_token');
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` };

    const listResponse = await fetch('/api/modules/sirsoft-ecommerce/products?per_page=1', { headers });
    const productCode = (await listResponse.json())?.data?.data?.[0]?.product_code ?? null;
    if (!productCode) {
      return { status: 0, reason: '판매 중인 상품이 없습니다' };
    }

    const detail = await (await fetch(`/api/modules/sirsoft-ecommerce/products/${productCode}`, { headers })).json();
    const product = detail?.data ?? {};

    const addResponse = await fetch('/api/modules/sirsoft-ecommerce/cart', {
      method: 'POST',
      headers,
      body: JSON.stringify({
        product_id: product.id,
        items: [{ product_option_id: product.options?.[0]?.id ?? null, quantity: 1 }],
      }),
    });

    return { status: addResponse.status, reason: addResponse.ok ? null : JSON.stringify(await addResponse.json()) };
  });

  expect(result.status, `장바구니 담기에 실패했습니다: ${result.reason}`).toBe(201);
}

/**
 * GDPR 쿠키 동의 배너를 닫습니다 (배너가 조작 영역을 덮으면 클릭이 타임아웃으로 죽는다).
 *
 * @param page 대상 페이지
 * @return void
 */
async function dismissCookieBanner(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: '모두 동의' }).first();

  try {
    await accept.waitFor({ state: 'visible', timeout: 3000 });
  } catch {
    return;
  }

  await accept.click();
  await accept.waitFor({ state: 'hidden', timeout: 5000 });
}

/**
 * 장바구니를 비웁니다 (다음 실행에 이월되지 않도록).
 *
 * @param page 대상 페이지
 * @return void
 */
async function clearCart(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const token = localStorage.getItem('auth_token');
    await fetch('/api/modules/sirsoft-ecommerce/cart/all', {
      method: 'DELETE',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
  });
}

test.describe('#123 주소 검색 실패 시 직접 입력', () => {
  test.beforeEach(({}, testInfo) => {
    testInfo.setTimeout(120_000);
  });

  test('SDK 를 못 불러와도 직접 친 주소가 주문 상태에 도달하고 결제로 넘어갈 수 있다', async ({ page }) => {
    await page.route(POSTCODE_SDK, route => route.abort());
    await authenticatePage(page, issueToken());

    await page.goto('/shop/products');
    await dismissCookieBanner(page);
    await seedCart(page);

    try {
      await page.goto('/shop/cart');
      await dismissCookieBanner(page);
      await page.getByRole('button', { name: '주문하기' }).first().click();
      await page.waitForURL(/\/shop\/checkout/);

      // 자산 실패 사실이 화면에 남아야 한다 (조용히 기능만 사라지면 안 된다)
      await expect(page.locator('#g7-asset-failure-notice')).toBeVisible({ timeout: 30_000 });

      // 잠금이 풀려 있어야 직접 칠 수 있다
      const zipcode = page.locator('input[name="zipcode"]');
      const address = page.locator('input[name="address"]');
      await expect(zipcode).toBeVisible({ timeout: 30_000 });
      expect(await zipcode.evaluate(el => (el as HTMLInputElement).readOnly)).toBe(false);
      expect(await address.evaluate(el => (el as HTMLInputElement).readOnly)).toBe(false);

      await page.locator('input[name="recipient_name"]').fill('E2E 수령인');
      await page.locator('input[name="recipient_phone"]').fill('01098765432');
      await zipcode.fill('06236');
      await address.fill('서울특별시 강남구 테헤란로 152');
      await page.locator('input[name="address_detail"]').fill('10층');

      // 핵심: 친 값이 주문 상태에 도달해야 한다. 편집 가능 여부만 보면 결함이 있어도 통과한다.
      await expect
        .poll(
          () => page.evaluate(() => (window as any).G7Core?.state?.getLocal?.()?.shipping ?? {}),
          { timeout: 15_000 }
        )
        .toMatchObject({ zipcode: '06236', address: '서울특별시 강남구 테헤란로 152' });

      // 결제 버튼의 활성 조건이 바로 그 두 값이다 — 도달하지 못하면 영영 비활성이었다.
      await expect(page.getByRole('button', { name: /결제하기/ }).first()).toBeEnabled({ timeout: 15_000 });
    } finally {
      await clearCart(page);
    }
  });
});
