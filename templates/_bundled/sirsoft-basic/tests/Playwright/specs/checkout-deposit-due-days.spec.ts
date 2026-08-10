/**
 * 주문서 입금 기한 안내가 관리자 설정(미입금 자동취소 기한)과 일치하는지 검증 (공개 이슈 #81).
 *
 * 결함: 입금 기한은 결제수단과 무관하게 `order_settings.auto_cancel_days` 하나만 쓰도록
 *   서버를 통일했는데, 주문서 화면은 서버에 존재하지 않는 `vbank_due_days`/`dbank_due_days`
 *   를 참조하고 있었다. 두 키는 설정 응답에 없으므로 표현식이 항상 undefined 가 되고
 *   하드코딩 폴백(가상계좌 3일, 무통장입금 7일)이 그대로 노출됐다.
 *
 *   실측(2026-07-29): `auto_cancel_days = 3` 인 사이트에서 무통장입금 주문서가
 *   "입금 기한: 7일 이내" 로 안내했다. 안내를 믿은 구매자는 3일 뒤 주문이 자동취소된 뒤에야
 *   알게 된다 — 화면만 봐서는 어긋난 것을 알 방법이 없다.
 *
 * 후속 결함(브라우저 실측 2026-08-01): 폴백이 `?? 3`(nullish) 이라 빈 문자열이 통과해
 *   `days=` 가 비고 치환이 스킵되어 주문서에 `입금 기한: {{days}}일 이내` 가 그대로 노출됐다.
 *   서버는 같은 값에서 3일로 폴백하므로 화면만 깨지는 조용한 불일치였다. 그래서 이 spec 은
 *   숫자 일치뿐 아니라 **미치환 플레이스홀더 부재**도 함께 고정한다.
 *
 * 단위/정적:
 *   - templates/_bundled/sirsoft-basic/__tests__/layouts/checkout-deposit-due-days.test.ts
 *     가 레이아웃 표현식을 실제로 평가해 오염값(빈 문자열/0/음수/비숫자) 폴백을 잠근다.
 *   이 spec 은 브라우저 수준 — 렌더된 안내 숫자가 설정 응답의 값과 같은지를 담당한다.
 *
 * @scenario checkout-deposit-due
 * @axes method=dbank,vbank
 * @effects due_notice_matches_auto_cancel_days, due_notice_has_no_unsubstituted_placeholder
 */
import { test, expect, type Page } from '@playwright/test';

import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

/** 서버 기본치 (`OrderProcessingService::AUTO_CANCEL_DAYS_DEFAULT`) */
const SERVER_DEFAULT_DAYS = 3;

/**
 * 설정 응답의 원본 값을 서버(`resolveAutoCancelDays`)와 같은 규칙으로 해석합니다.
 *
 * @param raw 설정 응답이 담고 있는 원본 값
 * @returns 안내에 표시되어야 할 일수
 */
function resolveExpectedDays(raw: unknown): number {
  return Number(raw) > 0 ? Number(raw) : SERVER_DEFAULT_DAYS;
}

/**
 * 판매 중인 상품 1건을 장바구니에 담습니다 (주문서 진입 조건 확보).
 *
 * 상품 상세 UI(옵션 드롭다운 → 바로 구매)를 거치지 않는다. 옵션 구성은 상품마다 다르고
 * 하위 옵션이 비동기로 열려, 그 경로를 통과하는 것 자체가 이 spec 의 검증 대상과 무관하게
 * 불안정하다(실측: 동일 코드에서 통과/타임아웃이 갈렸다). 검증 대상인 주문서 입금 기한 안내는
 * 담은 뒤 전부 브라우저 실렌더로 측정한다.
 *
 * @param page 대상 페이지 (인증 토큰이 주입된 상태여야 한다)
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
 * 주문서에 진입합니다 (상품 상세 → 옵션 선택 → 바로 구매).
 *
 * 대상 상품은 목록 API 로 고른다 — 카드 마크업/정렬은 이 spec 의 검증 대상이 아니고,
 * 시드가 바뀌면 조용히 깨지는 하드코딩 상품코드를 피하기 위함이다. 검증 대상인
 * 입금 기한 안내는 그 뒤 전부 브라우저 실렌더로 측정한다.
 *
 * @param page 대상 페이지
 */
/**
 * GDPR 쿠키 동의 배너를 닫습니다.
 *
 * 신규 브라우저 컨텍스트에는 동의 이력이 없어 배너가 하단 고정으로 뜬다. 배너가 옵션/구매
 * 영역을 덮으면 클릭이 재시도만 반복하다 타임아웃으로 죽어, 판정기가 검증 대상에 닿기도 전에
 * 자기 문제로 실패한다(실측). 배너가 없으면 조용히 지나간다.
 *
 * @param page 대상 페이지
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
 * 주문서에 진입합니다 (장바구니 시드 → 장바구니 화면 → 주문하기).
 *
 * `/shop/checkout` 으로 바로 이동하면 "주문 정보가 없습니다" 모달이 뜬다 — 주문서는 장바구니에
 * 담긴 것만으로 열리지 않고, 장바구니에서 주문 대상을 확정해야 한다(실측).
 *
 * @param page 대상 페이지
 */
async function gotoCheckout(page: Page): Promise<void> {
  await page.goto('/shop/products');
  await dismissCookieBanner(page);

  await seedCart(page);

  await page.goto('/shop/cart');
  await dismissCookieBanner(page);

  await page.getByRole('button', { name: '주문하기' }).first().click();
  await page.waitForURL(/\/shop\/checkout/);
}

test.describe('#81 주문서 입금 기한 안내', () => {
  test('설정 응답이 입금 기한 SSoT(auto_cancel_days)를 노출한다', async ({ page }) => {
    await page.goto('/shop/products');

    const orderSettings = await page.evaluate(async () => {
      const response = await fetch('/api/modules/sirsoft-ecommerce/settings/payment', {
        headers: { Accept: 'application/json' },
      });
      const body = await response.json();

      return (body?.data?.order_settings ?? {}) as Record<string, unknown>;
    });

    // 화면이 참조하는 키가 응답에 있어야 한다. 없으면 폴백으로 떨어져 설정과 어긋난다.
    expect(
      Object.keys(orderSettings),
      '입금 기한 SSoT 가 결제 설정 응답에 없습니다 — 주문서 안내가 하드코딩 폴백으로 떨어집니다.'
    ).toContain('auto_cancel_days');

    // 폐기된 키가 되살아나면 화면이 다시 그 값을 따라가므로 부재를 함께 고정한다.
    expect(Object.keys(orderSettings)).not.toContain('dbank_due_days');
    expect(Object.keys(orderSettings)).not.toContain('vbank_due_days');
  });

  test('렌더된 안내가 설정값과 일치하고 미치환 플레이스홀더가 없다', async ({ page }) => {
    await authenticatePage(page, issueToken());
    await gotoCheckout(page);

    const rawSetting = await page.evaluate(async () => {
      const response = await fetch('/api/modules/sirsoft-ecommerce/settings/payment', {
        headers: { Accept: 'application/json' },
      });
      const body = await response.json();

      return (body?.data?.order_settings ?? {}).auto_cancel_days ?? null;
    });
    const expectedDays = resolveExpectedDays(rawSetting);

    // 결제수단은 설정 응답 도착 후에 그려진다 — 렌더 전에 조회하면 전부 "없음"으로 읽혀
    // 아무것도 측정하지 않은 채 통과한다. 섹션이 뜬 것을 먼저 확정한다.
    await expect(page.getByText('결제 수단').first()).toBeVisible();

    // 주문 금액 계산 중에는 전면 오버레이가 떠 결제수단 클릭을 가로챈다(실측: 30초 재시도 후 사망).
    // 합계가 그려지면 계산이 끝난 것이므로 그때까지 기다린다.
    await expect(page.getByText('총 결제금액').first()).toBeVisible({ timeout: 15000 });

    // 활성 결제수단만 화면에 뜬다 — 사이트 설정에 따라 한쪽만 있을 수 있으므로 존재하는 것만 검사한다.
    let measured = 0;
    for (const label of ['무통장입금', '가상계좌']) {
      const method = page.getByRole('button', { name: new RegExp(label) }).first();
      try {
        await method.waitFor({ state: 'visible', timeout: 3000 });
      } catch {
        continue;
      }

      await method.click();

      const notice = page.getByText(/입금 기한/).first();
      await expect(notice).toBeVisible();

      const text = (await notice.innerText()).trim();

      // 치환 실패는 원문(`{{days}}`)이 그대로 남는 형태로 드러난다 — 숫자 비교보다 먼저 잠근다.
      expect(text, `${label} 안내에 미치환 플레이스홀더가 남아 있습니다: ${text}`).not.toContain('{{');
      expect(text, `${label} 안내가 설정값(${expectedDays}일)과 다릅니다: ${text}`).toContain(String(expectedDays));

      measured += 1;
    }

    expect(measured, '활성 결제수단이 하나도 없어 안내를 측정하지 못했습니다').toBeGreaterThan(0);
  });
});
