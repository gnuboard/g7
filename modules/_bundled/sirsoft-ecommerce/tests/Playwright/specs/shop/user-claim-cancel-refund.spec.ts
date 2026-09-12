/**
 * 구매자 클레임 신청서 작성 — 취소·환불 분기 (#599 S15b · D2 · §6-3)
 *
 * 한 화면이지만 서버는 두 파이프라인으로 갈린다 — 결제완료 상품은 **즉시 취소**로 바로
 * 환불되고, 배송준비 이후 상품은 **환불 신청서**로 심사를 받는다. 화면이 재는 축은 셋이다.
 *
 *  ① **라벨·안내·실행 버튼이 지금 고른 상품의 분기를 따르는가.** 서버 판정(`resolved_type`)에
 *     맡기면 두 분기가 섞인 주문에서 값이 `mixed` 라, 결제완료 상품만 골라 **즉시 취소되는데도**
 *     화면은 "환불 신청" 이라 부르고 "판매자 확인을 거친다" 고 안내한다. 응답은 200 이고 접수도
 *     성공하므로 어긋난 사실이 어디에도 남지 않는다.
 *  ② **다른 분기의 상품을 함께 고를 수 없는가.** 섞으면 서버가 신청 **전체**를 거절하므로
 *     (`claim_refund_branch_mixed`) 구매자는 사유까지 다 채운 뒤에야 막힌다.
 *  ③ **잠근 이유를 밝히는가.** 밝히지 않으면 체크가 먹지 않는 고장으로 보인다.
 *
 * 셋 다 로컬 상태와 DOM 이 맞물리는 자리라 화면을 실제로 그려야 드러난다.
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

/** 접수 후 승인·완료까지 자동으로 진행되는 상품 (미결제·결제완료) — 분기 `auto` */
const CANCEL_ITEM = {
  option: { id: 710, name: '자동 전이 상품', optionName: '블랙 / L', unitPrice: 31_000 },
  availableQuantity: 1,
  types: ['refund'],
  refundBranch: 'auto' as const,
};

/** 접수에서 멈춰 운영자 승인을 기다리는 상품 (배송준비 이후) — 분기 `request` */
const REFUND_ITEM = {
  option: { id: 711, name: '환불신청 상품', optionName: '화이트 / S', unitPrice: 22_000 },
  availableQuantity: 1,
  types: ['refund'],
  refundBranch: 'request' as const,
};

/**
 * 취소·환불 신청서를 엽니다.
 *
 * @param page Playwright 페이지
 * @returns 완료 Promise
 */
async function openRefundApply(page: Page): Promise<void> {
  await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims/apply/refund`);
  await waitForApp(page);
  await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });
}

/** 상품 줄의 체크박스 (전체선택 체크박스가 첫 번째이므로 그 뒤부터가 상품이다) */
function itemCheckbox(page: Page, index: number) {
  return page.locator('#claim_apply_items input[type="checkbox"]').nth(index + 1);
}

/** 전체 선택 체크박스 */
function selectAllCheckbox(page: Page) {
  return page.locator('#claim_apply_items input[type="checkbox"]').first();
}

/** 화면 전체 글자 (공백 정규화) */
async function screenText(page: Page): Promise<string> {
  return ((await page.locator('body').innerText()) ?? '').replace(/\s+/g, ' ');
}

test.describe('구매자 클레임 신청서 작성 — 취소·환불 분기', () => {
  test.beforeEach(async ({ page, memberToken }) => {
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await mockOrder(page);
    // 금액은 이 spec 의 축이 아니다 — 미리보기가 나가도 화면이 잠기지 않게만 응답한다.
    await mockPreview(page, () => ({
      claim_type: 'refund',
      requires_payment: false,
      shipping_fee: { fee: 0, extra_fee: 0, groups: [] },
      price_difference: 0,
      additional_product_amount: 0,
      refund_product_amount: 31_000,
      total_charge_amount: 0,
      refund_before_deduction: 31_000,
      deducted_shipping_fee: 0,
      adjustment: { refund_total: 31_000 },
      items: [],
    }));
  });

  // @scenario claim_type=refund, charge_method=none, refund_branch=auto
  // @effects claim_apply_refund_branch_drives_notice_and_submit_label
  test('즉시 취소 상품만 있는 주문은 라벨·안내·버튼이 취소로 선다', async ({ page }) => {
    await mockClaimable(page, [CANCEL_ITEM], { resolved_type: 'auto' });
    await openRefundApply(page);

    await itemCheckbox(page, 0).check();

    const text = await screenText(page);
    expect(text, '화면 제목이 주문 취소가 아니다').toContain('주문 취소');
    expect(text, '자동 처리 안내가 없다').toContain('승인·환불완료까지 자동으로 진행됩니다');
    await expect(
      page.getByRole('button', { name: '주문 취소하기' }),
      '실행 버튼이 주문 취소하기가 아니다',
    ).toBeVisible();
  });

  // @scenario claim_type=refund, charge_method=none, refund_branch=request
  // @effects claim_apply_refund_branch_drives_notice_and_submit_label
  test('환불 신청 상품만 있는 주문은 라벨·안내·버튼이 환불 신청으로 선다', async ({ page }) => {
    await mockClaimable(page, [REFUND_ITEM], { resolved_type: 'request' });
    await openRefundApply(page);

    await itemCheckbox(page, 0).check();

    const text = await screenText(page);
    expect(text, '심사 안내가 없다').toContain('판매자 확인을 거쳐 환불됩니다');
    await expect(
      page.getByRole('button', { name: '환불 신청하기' }),
      '실행 버튼이 환불 신청하기가 아니다',
    ).toBeVisible();
  });

  // @scenario claim_type=refund, charge_method=none, refund_branch=mixed
  // @effects claim_apply_refund_branch_unset_shows_no_branch_notice
  test('분기가 섞인 주문 — 고르기 전에는 분기 안내가 서지 않고 혼재 안내만 선다', async ({ page }) => {
    await mockClaimable(page, [CANCEL_ITEM, REFUND_ITEM], { resolved_type: 'mixed' });
    await openRefundApply(page);

    const text = await screenText(page);

    // 혼재 안내는 선다 — 잠글 것을 예고한다.
    expect(text, '혼재 안내가 없다').toContain('두 가지는 한 번에 신청할 수 없어');

    // 서버 판정이 mixed 인 동안에는 어느 분기 안내도 서지 않는다 — 서면 둘 중 하나는 거짓이다.
    expect(text, '고르기 전인데 자동 처리 안내가 섰다').not.toContain('승인·환불완료까지 자동으로 진행됩니다');
    expect(text, '고르기 전인데 심사 안내가 섰다').not.toContain('판매자 확인을 거쳐 환불됩니다');

    // 상품 줄마다 자기 분기 배지를 단다 — 무엇을 나눠 신청해야 하는지 알려 준다.
    expect(text, '자동 처리 배지가 없다').toContain('자동 처리');
    expect(text, '환불 신청 배지가 없다').toContain('환불 신청');
  });

  // @scenario claim_type=refund, charge_method=none, refund_branch=mixed
  // @effects claim_apply_refund_branch_locks_cross_branch_items, claim_apply_refund_branch_follows_selection, claim_apply_refund_branch_cannot_be_mixed
  test('분기가 섞인 주문 — 한 분기를 고르면 다른 분기가 잠기고 라벨이 그 분기를 따른다', async ({ page }) => {
    await mockClaimable(page, [CANCEL_ITEM, REFUND_ITEM], { resolved_type: 'mixed' });
    await openRefundApply(page);

    // 즉시 취소 상품을 고른다 — 서버 판정은 여전히 `mixed` 다.
    await itemCheckbox(page, 0).check();
    await expect(itemCheckbox(page, 0)).toBeChecked();

    // 다른 분기의 상품은 고를 수 없다 (잠금).
    await expect(
      itemCheckbox(page, 1),
      '다른 분기 상품이 잠기지 않았다 — 서버가 신청 전체를 거절하는 조합을 화면이 만들 수 있다',
    ).toBeDisabled();

    // 라벨·안내는 **지금 고른 상품**을 따른다 (서버 판정 mixed 를 따르지 않는다).
    const text = await screenText(page);
    expect(text, '고른 분기(자동 처리)의 안내가 서지 않았다').toContain(
      '승인·환불완료까지 자동으로 진행됩니다',
    );
    expect(text, '고르지 않은 분기의 안내가 섰다').not.toContain('판매자 확인을 거쳐 환불됩니다');
    await expect(
      page.getByRole('button', { name: '주문 취소하기' }),
      '실행 버튼이 고른 분기를 따르지 않았다',
    ).toBeVisible();
  });

  // @scenario claim_type=refund, charge_method=none, refund_branch=mixed
  // @effects claim_apply_refund_branch_select_all_picks_one_branch
  test('분기가 섞인 주문 — 전체 선택은 한 분기만 고른다', async ({ page }) => {
    await mockClaimable(page, [CANCEL_ITEM, REFUND_ITEM], { resolved_type: 'mixed' });
    await openRefundApply(page);

    // 전체선택 체크박스는 로컬 상태를 경유해 `checked` 가 되는 제어 입력이라 `check()` 는
    // 클릭 직후 상태를 확인해 실패한다 — 클릭과 확인을 분리한다. 그리고 이 주문에서 그
    // 체크박스는 **켜지지 않는다**: 표시 근거가 "전건이 선택됐는가" 인데 분기가 섞이면 한
    // 분기만 선택되기 때문이다. 재는 것은 그 표시가 아니라 무엇이 선택됐는가다.
    await selectAllCheckbox(page).click();

    // 먼저 걸린 분기 하나만 선택된다 — 둘 다 선택되면 제출이 통째로 거절된다.
    await expect(itemCheckbox(page, 0), '첫 분기 상품이 선택되지 않았다').toBeChecked();
    await expect(
      itemCheckbox(page, 1),
      '전체 선택이 두 분기를 한꺼번에 골랐다 — 서버가 거절하는 조합이다',
    ).not.toBeChecked();
  });

  // @scenario claim_type=refund, charge_method=none, refund_branch=auto
  // @effects claim_apply_cancel_blocked_locks_submit_inline
  test('환불 불가 조합은 표 아래 인라인 안내가 서고 제출이 잠긴다 (#599 후속 P3-①)', async ({ page }) => {
    await mockClaimable(page, [CANCEL_ITEM], { resolved_type: 'auto' });
    const calls = await mockPreview(page, () => previewWithComparison({}, {
      cancel_blocked: true,
      cancel_blocked_reason: '취소 후 결제금액이 늘어나 취소할 수 없습니다.',
    }));
    await openRefundApply(page);

    await itemCheckbox(page, 0).check();
    const reason = page.locator('#claim_apply_reason select').first();
    await expect(reason).toBeVisible({ timeout: 15_000 });
    const values = await reason.locator('option').evaluateAll((nodes) =>
      nodes.map((node) => (node as HTMLOptionElement).value).filter((value) => value !== ''),
    );
    await reason.selectOption(values[0]);
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBeGreaterThan(0);

    const blocked = page.locator('[data-testid="claim-comparison-blocked"]');
    await expect(blocked).toBeVisible({ timeout: 15_000 });
    await expect(blocked).toContainText('취소 후 결제금액이 늘어나 취소할 수 없습니다.');
    // 표는 그대로 남고(왜 막혔는지 후열이 말한다) 환불 요약은 숨는다.
    await expect(page.locator('#claim_apply_summary [data-row]')).toHaveCount(15);
    await expect(page.locator('[data-testid="claim-comparison-refund-summary"]')).toHaveCount(0);

    const submit = page.getByRole('button', { name: '주문 취소하기' });
    await expect(submit).toBeDisabled();
  });

  // @scenario claim_type=refund, charge_method=none, refund_branch=auto
  // @effects claim_apply_refund_priority_reestimates, claim_apply_restored_coupons_and_remaining_balances_render
  test('마일리지를 쓴 주문은 환불 우선순위 라디오가 서고 바꾸면 그 값으로 미리보기를 다시 부른다 (#599 후속 P3-③)', async ({ page }) => {
    await mockClaimable(page, [CANCEL_ITEM], { resolved_type: 'auto' });
    const calls = await mockPreview(page, (body) => previewWithComparison({}, {
      original_snapshot: { ...(previewWithComparison().adjustment as any).original_snapshot, total_points_used_amount: 5000 },
      refund_priority: body.refund_priority ?? 'pg_first',
      refund_points_amount: body.refund_priority === 'points_first' ? 5000 : 0,
      remaining_points_balance: body.refund_priority === 'points_first' ? 0 : 5000,
      refund_formatted: {
        base_currency: 'KRW',
        base: {
          refund_total: '17,000원', refund_amount: body.refund_priority === 'points_first' ? '12,000원' : '17,000원',
          remaining_pg_balance: '45,000원', remaining_points_balance: body.refund_priority === 'points_first' ? '0원' : '5,000원',
        },
        mc: {},
      },
    }));
    await openRefundApply(page);

    await itemCheckbox(page, 0).check();
    const reason = page.locator('#claim_apply_reason select').first();
    await expect(reason).toBeVisible({ timeout: 15_000 });
    const values = await reason.locator('option').evaluateAll((nodes) =>
      nodes.map((node) => (node as HTMLOptionElement).value).filter((value) => value !== ''),
    );
    await reason.selectOption(values[0]);
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBe(1);
    expect(calls[0].refund_priority).toBe('pg_first');

    // 잔여 마일리지 5,000원 · 복원 쿠폰 블록
    await expect(page.locator('[data-line="remaining_points"]')).toContainText('5,000원');
    await expect(page.locator('[data-testid="claim-comparison-restored-coupons"]')).toContainText('첫구매 쿠폰');

    const radios = page.locator('input[name="claimRefundPriority"]');
    await expect(radios).toHaveCount(2);
    await radios.nth(1).check();
    await expect.poll(() => calls.length, { timeout: 15_000 }).toBe(2);
    expect(calls[1].refund_priority, '바뀐 우선순위가 페이로드에 실리지 않았다').toBe('points_first');
    await expect(page.locator('[data-line="remaining_points"]')).toContainText('0원');
  });
});
