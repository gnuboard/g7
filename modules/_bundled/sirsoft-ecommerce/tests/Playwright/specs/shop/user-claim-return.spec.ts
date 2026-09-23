/**
 * 구매자 클레임 신청서 작성 — 반품 (#599 S15b · §6-3)
 *
 * 반품 요약의 줄 수를 가르는 축은 **청구 방식** 하나다. 회수비를 환불금에서 차감하는 방식이면
 * 세 줄(환불 예정액 / 회수 배송비 차감 / 최종 환불액)이 서고, 별도 결제 방식이면 두 줄이다.
 * 이 분기가 어긋나도 응답은 200 이고 금액 서식도 정상이라 **화면을 그려 글자로 확인**해야만
 * 드러난다 — 그것이 이 spec 이 렌더 테스트와 별개로 존재하는 이유다.
 *
 * 그리고 세 줄의 값은 전부 **서버가 낸 것**이어야 한다. 화면이 회수비를 되더해 차감 전 금액을
 * 만들면 예고와 청구가 서로 다른 식을 쓰게 되므로, 응답에만 있고 다른 줄에서는 만들 수 없는
 * 금액을 심어 그 값이 그대로 나오는지로 잰다.
 *
 *
 * 매니페스트 마킹(@scenario/@effects)은 **테스트마다** 그 케이스 위에 둔다 — 한 docblock 에
 * 여러 줄을 적으면 마킹 추출기가 첫 줄만 읽어 나머지 조합이 조용히 미커버로 남는다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import {
  MOCK_ORDER,
  mockClaimable,
  grantFunctionalConsent,
  mockOrder,
  mockPreview,
  waitForApp,
  previewWithComparison,
} from '../../fixtures/claim-screens';
import type { Page } from '@playwright/test';

/**
 * 서버가 내는 금액.
 *
 * 셋 다 **상품 단가와 다른 수**여야 한다 — 요약 카드는 상품 줄도 그리므로, 단가와 같은 수를
 * 쓰면 그 줄이 대신 나타나 통과시킨다(같은 이유로 세 값도 서로 달라야 한다).
 */
const UNIT_PRICE = 57_000;

/** 회수비를 환불금에서 차감할 때 서버가 내는 금액 (세 줄이 서로 다른 수를 갖는다) */
const DEDUCT = {
  grossRefund: 43_000, // 차감 전 환불 예정액 — 응답에만 있다
  collectionFee: 3_000, // 회수 배송비
  netRefund: 40_000, // 최종 환불액 — 서버가 정한다
};

/** 별도 결제 방식일 때 서버가 내는 금액 */
const SEPARATE = {
  refund: 41_000,
  charge: 2_500,
};

/**
 * 반품 신청서를 열고 상품·사유까지 채웁니다.
 *
 * 미리보기는 사유를 고른 뒤에야 나가므로(귀책이 배송비를 정한다) 여기까지 와야 요약이 선다.
 *
 * @param page Playwright 페이지
 * @returns 완료 Promise
 */
async function openReturnApply(page: Page): Promise<void> {
  await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims/apply/return`);
  await waitForApp(page);

  await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });
  await page.locator('#claim_apply_items input[type="checkbox"]').last().check();

  const reason = page.locator('#claim_apply_reason select').first();
  await expect(reason).toBeVisible({ timeout: 15_000 });
  const values = await reason.locator('option').evaluateAll((nodes) =>
    nodes.map((node) => (node as HTMLOptionElement).value).filter((value) => value !== ''),
  );
  expect(values.length, '반품 사유 카탈로그가 비어 미리보기를 유발할 수 없다').toBeGreaterThan(0);
  await reason.selectOption(values[0]);
}

/** 요약 카드의 글자 (공백을 줄여 비교하기 쉽게 만든다) */
async function summaryText(page: Page): Promise<string> {
  const summary = page.locator('#claim_apply_summary');
  await expect(summary).toBeVisible({ timeout: 20_000 });

  return ((await summary.innerText()) ?? '').replace(/\s+/g, ' ');
}

test.describe('구매자 클레임 신청서 작성 — 반품', () => {
  test.beforeEach(async ({ page, memberToken }) => {
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await mockOrder(page);
    await mockClaimable(
      page,
      [
        {
          option: { id: 610, name: '반팔 티셔츠', optionName: '화이트 / M', unitPrice: UNIT_PRICE },
          availableQuantity: 1,
          types: ['return', 'exchange'],
        },
      ],
    );
  });

  // @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
  // @effects claim_apply_return_summary_uses_server_gross_refund, claim_apply_return_summary_shows_gross_deduction_and_final
  test('환불금에서 차감하는 반품은 요약이 세 줄이고 세 값 모두 서버가 낸다', async ({ page }) => {
    const calls = await mockPreview(page, () => ({
      claim_type: 'return',
      requires_payment: false,
      shipping_fee: { fee: DEDUCT.collectionFee, extra_fee: 0, groups: [] },
      price_difference: 0,
      additional_product_amount: 0,
      refund_product_amount: DEDUCT.grossRefund,
      total_charge_amount: 0,
      // 차감 전 금액은 **응답에만** 있다 — 화면이 되더해 만들 수 없는 수여야 축이 성립한다.
      refund_before_deduction: DEDUCT.grossRefund,
      deducted_shipping_fee: DEDUCT.collectionFee,
      adjustment: { refund_total: DEDUCT.netRefund },
      items: [{ order_option_id: 610, price_difference: 0 }],
    }));

    await openReturnApply(page);

    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(0);

    const text = await summaryText(page);

    // 세 줄이 전부 서고, 각 줄이 **자기 값**을 달고 있다 — 라벨과 금액의 짝까지 본다.
    // (라벨만 세면 세 줄이 같은 수를 그려도 통과한다.)
    expect(text, '환불 예정액 줄이 차감 전 금액(서버값)을 달고 있지 않다').toMatch(/환불 예정액 43,000/);
    expect(text, '회수 배송비 차감 줄이 차감액(서버값)을 달고 있지 않다').toMatch(/회수 배송비 차감 -3,000/);
    expect(text, '최종 환불액 줄이 최종 금액(서버값)을 달고 있지 않다').toMatch(/최종 환불액 40,000/);

    // S-B-D6(2026-09-21 세션B 실측 · 운영 결정 「감추기」) — 이 방식은 회수비를 환불액에서
    // 이미 뺐으므로 따로 낼 돈이 없다. 바로 위 「회수 배송비 차감 −3,000원」 아래에
    // 「총 청구액 3,000원」이 서면 같은 3,000원을 두 번 내는 것으로 읽힌다.
    // 값이 아니라 **줄의 부재**를 재야 한다 — 0원으로 그려도 같은 오해가 남는다.
    expect(text, '「환불액에서 차감」인데 총 청구액 줄이 섰다(S-B-D6)').not.toMatch(/총 청구액/);

    // 같은 이유로 결제 수단 칸도 서지 않는다 — 낼 돈이 없는데 칸이 있으면
    // 「카드로 결제해야 하나」로 읽힌다. 종전에는 회색+disabled 로 남겼었다.
    expect(text, '「환불액에서 차감」인데 결제 수단 칸이 섰다(S-B-D6)').not.toMatch(/결제 수단/);
  });

  // @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
  // @effects claim_apply_return_summary_uses_server_gross_refund
  test('별도 결제 반품은 차감·최종 줄을 세우지 않는다', async ({ page }) => {
    const calls = await mockPreview(page, () => ({
      claim_type: 'return',
      requires_payment: true,
      shipping_fee: { fee: SEPARATE.charge, extra_fee: 0, groups: [] },
      price_difference: 0,
      additional_product_amount: 0,
      refund_product_amount: SEPARATE.refund,
      total_charge_amount: SEPARATE.charge,
      // 별도 결제는 환불금에서 빼지 않는다 — 서버가 차감액 0 을 낸다.
      refund_before_deduction: SEPARATE.refund,
      deducted_shipping_fee: 0,
      adjustment: { refund_total: SEPARATE.refund },
      items: [{ order_option_id: 610, price_difference: 0 }],
    }));

    await openReturnApply(page);

    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(0);

    const text = await summaryText(page);

    // 전액 환불되는데 배송비만큼 덜 받는 것으로 읽히면 안 된다.
    expect(text, '차감액 0 인데 회수 배송비 차감 줄이 섰다').not.toContain('회수 배송비 차감');
    expect(text, '차감이 없는데 최종 환불액 줄이 섰다').not.toContain('최종 환불액');

    // 환불 예정액과 총 청구액은 선다 — 각각 서버가 낸 자기 값을 달고서.
    expect(text, '환불 예정액 줄이 서버값을 달고 있지 않다').toMatch(/환불 예정액 41,000/);
    expect(text, '총 청구액 줄이 서버값을 달고 있지 않다').toMatch(/총 청구액 2,500/);
  });

  // @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
  test('미리보기는 사유를 고른 뒤 한 번만 나간다', async ({ page }) => {
    const calls = await mockPreview(page, () => ({
      claim_type: 'return',
      requires_payment: false,
      shipping_fee: { fee: 0, extra_fee: 0, groups: [] },
      price_difference: 0,
      additional_product_amount: 0,
      refund_product_amount: 10_000,
      total_charge_amount: 0,
      refund_before_deduction: 10_000,
      deducted_shipping_fee: 0,
      adjustment: { refund_total: 10_000 },
      items: [{ order_option_id: 610, price_difference: 0 }],
    }));

    await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims/apply/return`);
    await waitForApp(page);
    await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });

    // 상품만 골랐을 때는 아직 계산할 수 없다 — 사유가 귀책을 정하고 귀책이 배송비를 정한다.
    await page.locator('#claim_apply_items input[type="checkbox"]').last().check();
    await page.waitForTimeout(1_000);
    expect(calls, '사유 없이 미리보기가 나갔다').toHaveLength(0);

    const reason = page.locator('#claim_apply_reason select').first();
    const values = await reason.locator('option').evaluateAll((nodes) =>
      nodes.map((node) => (node as HTMLOptionElement).value).filter((value) => value !== ''),
    );
    await reason.selectOption(values[0]);

    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(0);
    expect(calls, '토글마다 요청이 나갔다 (debounce 미동작)').toHaveLength(1);
  });

  // @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
  // @effects claim_apply_comparison_table_renders_sixteen_rows, claim_apply_comparison_highlights_changed_after_values
  test('요약 카드 안 주문금액 비교표가 서버 formatted 값 그대로 15행으로 선다 (#599 후속)', async ({ page }) => {
    const calls = await mockPreview(page, () => previewWithComparison({
      claim_type: 'return',
      shipping_fee: { fee: DEDUCT.collectionFee, extra_fee: 0, groups: [] },
      refund_product_amount: DEDUCT.grossRefund,
      refund_before_deduction: DEDUCT.grossRefund,
      deducted_shipping_fee: DEDUCT.collectionFee,
      items: [{ order_option_id: 610, price_difference: 0 }],
    }));

    await openReturnApply(page);
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(0);

    // PC(1280) 는 펼침으로 열린다 — 14 금액행 + 쿠폰행. 치환행은 반품에 없다.
    const rows = page.locator('#claim_apply_summary [data-row]');
    await expect(rows).toHaveCount(15, { timeout: 15_000 });
    await expect(page.locator('[data-testid="claim-comparison-toggle"]')).toHaveAttribute('aria-expanded', 'true');

    const text = await summaryText(page);
    expect(text, '열 라벨이 반품 전/후가 아니다').toMatch(/반품 전.*반품 후/);
    // 응답에만 있는 값이 그대로 난다 — 화면이 포맷하거나 합산했다면 나올 수 없는 수다.
    expect(text).toContain('5,636원');
    expect(text).toContain('3,091원');
    expect(text).toContain('첫구매 쿠폰');
    expect(text).not.toContain('$t:');

    // 후열은 변한 행만 강조색이다.
    const paidColor = await page.locator('[data-row="total_paid_amount"] > div').last().locator('span').first()
      .evaluate((el) => getComputedStyle(el).color);
    const shippingColor = await page.locator('[data-row="base_shipping_amount"] > div').last().locator('span').first()
      .evaluate((el) => getComputedStyle(el).color);
    expect(paidColor, '변한 행(실결제금액)이 강조되지 않았다').not.toBe(shippingColor);
  });
});
