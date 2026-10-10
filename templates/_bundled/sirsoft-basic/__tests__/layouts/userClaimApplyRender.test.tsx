/**
 * @file userClaimApplyRender.test.tsx
 * @description 유저 클레임 신청서 — 금액·오류 자리 **렌더** 회귀 테스트 (#599 S11)
 *
 * 형제 파일 `userClaimApplyExchange.test.tsx` 는 레이아웃 JSON 의 **구조**를 읽는다.
 * 그 형태로는 잡히지 않는 결함이 실재했다 — 2026-08-31 3차 검증에서, 총 청구액의 보조통화
 * 병기가 `[1]?.formatted` 로 적혀 있어 **항상 빈 문자열**이었는데 구조 검사는 "그 바인딩이
 * 있다" 를 확인하고 통과시켰다. 서버 미리보기의 다통화는 `{통화코드: 숫자}` 이고 주문
 * 리소스는 `{amount, formatted}` 라, 두 줄이 같은 규약처럼 보여도 형태가 다르다.
 *
 * 그래서 이 파일은 **화면을 실제로 그려** 금액과 오류 안내가 글자로 나오는지만 잰다.
 * 구조 단언은 형제 파일이 계속 맡는다 — 여기서 되풀이하지 않는다.
 *
 * 잰다:
 *  ① 요약 금액 줄(총 청구액·배송비·환불 예정액)의 보조통화가 글자로 나온다 — 유형별로 나눠 잰다
 *  ② 상품 줄 단가의 보조통화가 글자로 나온다
 *  ③ 취소·환불 유형에서는 총 청구액 줄 자체가 서지 않는다
 *  ④ 제출 거절(422)의 사유가 그 칸 옆에 나온다 — 상품 줄 / 첨부 / 재발송지 / 청구 2축
 *  ⑤ 미리보기 거절(P13)은 화면에 남고 금액은 지워진다
 *  ⑥ (S12) 반품 요약 3줄 · 취소·환불 분기 라벨 · 환불계좌 · 분기 혼재 잠금 · 철회 모달
 *
 * @scenario exchange_mode=change, preview_result=ok
 * @effects claim_apply_total_charge_shows_secondary_currency,
 *   claim_apply_summary_amount_rows_all_show_secondary_currency,
 *   claim_apply_total_charge_row_hidden_for_refund,
 *   claim_apply_item_validation_error_maps_to_its_row,
 *   claim_apply_field_errors_render_beside_their_input,
 *   claim_apply_preview_rejection_stays_on_screen,
 *   claim_apply_return_summary_shows_gross_deduction_and_final,
 *   claim_apply_refund_branch_drives_notice_and_submit_label,
 *   claim_apply_refund_account_shown_only_for_bank_refunds,
 *   claim_apply_submit_blocked_until_the_refund_account_is_filled,
 *   claim_apply_refund_branch_locks_cross_branch_items,
 *   claim_withdraw_modal_states_consequences,
 *   claim_apply_redelivery_shows_the_international_address
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

// ── 컴포넌트 레지스트리 ──────────────────────────────────────────
// 렌더 경로만 재므로 실제 컴포넌트의 스타일·동작은 필요하지 않다. 공용 헬퍼가 basic 27종과
// 루트 래퍼(`Fragment`)를 등록해 주므로, 이 화면이 더 쓰는 둘만 보탠다.

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();

  registry.register('basic', 'Icon', ({ className }: any) =>
    React.createElement('i', { className })
  );
  registry.register('composite', 'FileUploader', () =>
    React.createElement('div', { 'data-testid': 'file-uploader' })
  );
  // 철회 모달은 composite Modal 이다 — 껍데기만 있으면 되고, 잴 것은 그 안의 글자다.
  registry.register('composite', 'Modal', ({ children, title }: any) =>
    React.createElement('div', { 'data-testid': 'modal' }, title, children)
  );

  return registry;
}

// ── 대상 partial ────────────────────────────────────────────────

function loadPartial(file: string): any {
  return JSON.parse(
    fs.readFileSync(
      path.resolve(__dirname, '../../layouts/partials/mypage/claims/' + file),
      'utf-8'
    )
  );
}

/** partial 참조를 파일에서 읽어 인라인한다 — 하네스는 `{ "partial": … }` 을 해석하지 않는다. */
function inlinePartials(node: any): any {
  if (Array.isArray(node)) return node.map(inlinePartials);
  if (node && typeof node === 'object') {
    if (typeof node.partial === 'string') {
      return inlinePartials(
        JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../layouts/', node.partial), 'utf-8'))
      );
    }
    const out: Record<string, any> = {};
    for (const [key, value] of Object.entries(node)) out[key] = inlinePartials(value);
    return out;
  }
  return node;
}

/** partial 하나를 단독 레이아웃으로 감싼다 — 셸은 이 파일의 관심사가 아니다. */
function asLayout(file: string): any {
  return { components: [inlinePartials(loadPartial(file))] };
}

// ── 주문금액 비교 섹션 (#599 후속) ─────────────────────────────

const KO_CLAIM = JSON.parse(
  fs.readFileSync(path.resolve(__dirname, '../../lang/partial/ko/claim.json'), 'utf-8')
);
const KO_TRANSLATIONS = { claim: KO_CLAIM };

/** 서버 스냅샷 한 벌 — 숫자 + base_currency + 기준 통화 포맷. 값은 응답에만 있고 다른 줄에서 만들 수 없는 것으로 고른다. */
function snapshotFixture(values: Record<string, number>, formatted: Record<string, string>): Record<string, any> {
  return { ...values, base_currency: 'KRW', formatted };
}

const ORIGINAL_SNAPSHOT = snapshotFixture(
  {
    item_count: 5, total_list_price_amount: 86000, subtotal_amount: 70000,
    total_product_coupon_discount_amount: 0, total_order_coupon_discount_amount: 3000, total_code_discount_amount: 0,
    shipping_discount_amount: 0, base_shipping_amount: 0, extra_shipping_amount: 0, total_points_used_amount: 5000,
    total_tax_amount: 62000, total_vat_amount: 5636, total_paid_amount: 62000, total_earned_points_amount: 620,
  },
  {
    total_list_price_amount: '86,000원', subtotal_amount: '70,000원', total_product_coupon_discount_amount: '0원',
    total_order_coupon_discount_amount: '3,000원', total_code_discount_amount: '0원', shipping_discount_amount: '0원',
    base_shipping_amount: '0원', extra_shipping_amount: '0원', total_points_used_amount: '5,000원',
    total_tax_amount: '62,000원', total_vat_amount: '5,636원', total_paid_amount: '62,000원',
  }
);

const RECALCULATED_SNAPSHOT = snapshotFixture(
  {
    item_count: 4, total_list_price_amount: 61000, subtotal_amount: 50000,
    total_product_coupon_discount_amount: 0, total_order_coupon_discount_amount: 0, total_code_discount_amount: 0,
    shipping_discount_amount: 0, base_shipping_amount: 0, extra_shipping_amount: 0, total_points_used_amount: 5000,
    total_tax_amount: 45000, total_vat_amount: 3091, total_paid_amount: 45000, total_earned_points_amount: 450,
  },
  {
    total_list_price_amount: '61,000원', subtotal_amount: '50,000원', total_product_coupon_discount_amount: '0원',
    total_order_coupon_discount_amount: '0원', total_code_discount_amount: '0원', shipping_discount_amount: '0원',
    base_shipping_amount: '0원', extra_shipping_amount: '0원', total_points_used_amount: '5,000원',
    total_tax_amount: '45,000원', total_vat_amount: '3,091원', total_paid_amount: '45,000원',
  }
);

function comparisonAdjustment(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    original_snapshot: ORIGINAL_SNAPSHOT,
    recalculated_snapshot: RECALCULATED_SNAPSHOT,
    mc_original_snapshot: null,
    mc_recalculated_snapshot: null,
    original_coupons: [{ name: '첫구매 쿠폰', target_type: 'order_amount', discount_amount: 3000, discount_amount_formatted: '3,000원' }],
    recalculated_coupons: [],
    refund_priority: 'pg_first',
    refund_amount: 17000,
    refund_points_amount: 0,
    refund_total: 17000,
    remaining_pg_balance: 45000,
    remaining_points_balance: 5000,
    refund_formatted: {
      base_currency: 'KRW',
      base: { refund_total: '17,000원', refund_amount: '17,000원', remaining_pg_balance: '45,000원', remaining_points_balance: '5,000원' },
      mc: {},
    },
    restored_coupons: [],
    cancel_blocked: false,
    cancel_blocked_reason: null,
    substituted_items: [],
    ...overrides,
  };
}

function comparisonState(overrides: Record<string, any> = {}, adjustmentOverrides: Record<string, any> = {}): Record<string, any> {
  return {
    claimType: 'refund',
    claimResolvedBranch: 'auto',
    claimApiBase: '/api/x',
    claimEditMode: false,
    claimComparisonOpen: true,
    claimEstimateLoading: false,
    claimEstimateError: null,
    claimEstimate: { refund_product_amount: 0, adjustment: comparisonAdjustment(adjustmentOverrides) },
    claimEstimateFormatted: {
      refund_cash: '17,000원',
      refund_points: '0원',
      remaining_pg_balance: '45,000원',
      remaining_points_balance: '5,000원',
      mc_refund_total: [],
      mc_refund_cash: [],
      mc_refund_points: [],
      mc_remaining_pg_balance: [],
      mc_remaining_points_balance: [],
      substituted_items: [],
    },
    ...overrides,
  };
}

/** 요약이 금액을 그리는 데 필요한 최소 상태. 값은 서버 응답 형태를 그대로 흉내낸다. */
/**
 * 요약 카드에서 **제출** 버튼 하나를 집어냅니다.
 *
 * 이 파샬에는 버튼이 둘이다(작성의 [신청하기], 수정의 [저장]). 라벨 키로 고른다 —
 * `querySelector('button')` 로 첫 번째를 집으면 화면이 바뀔 때 조용히 다른 버튼을 재게 된다.
 *
 * @param container 렌더 결과 컨테이너
 * @returns 제출 버튼 (없으면 null)
 */
function findSubmitButton(container: HTMLElement): HTMLButtonElement | null {
  const buttons = Array.from(container.querySelectorAll('button')) as HTMLButtonElement[];
  return (
    buttons.find((button) => (button.textContent ?? '').includes('claim.apply.submit')) ?? null
  );
}

function estimateState(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claimType: 'exchange',
    claimApiBase: '/api/x',
    claimEstimateLoading: false,
    claimEstimateError: null,
    claimEstimate: {
      price_difference: 3000,
      adjustment: { refund_total: 26000 },
    },
    claimEstimateFormatted: {
      shipping_fee: '6,000원',
      shipping_extra_fee: '0원',
      shipping_groups: [],
      price_difference: '3,000원',
      total_charge_amount: '9,000원',
      refund_total: '26,000원',
      mc_total_charge_amount: [{ code: 'USD', formatted: '$6.75' }],
      mc_shipping_fee: [{ code: 'USD', formatted: '$4.50' }],
      mc_refund_total: [{ code: 'USD', formatted: '$19.50' }],
    },
    claimableSnapshot: {
      settings: {
        allowed_charge_methods: ['pay_on_request', 'pay_on_approval'],
        charge_payment_methods: ['card', 'point'],
        max_images: 5,
        image_max_size_kb: 2048,
      },
    },
    ...overrides,
  };
}

function itemState(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claimType: 'exchange',
    claimItems: [
      {
        order_option_id: 359,
        product_name: '티셔츠',
        option_label: '차콜/M',
        image_url: '',
        selected: true,
        quantity: 1,
        max_quantity: 2,
        unit_price: 5000,
        unit_price_formatted: '5,000원',
        exchange_mode: 'same',
        new_product_option_id: null,
        new_option_label: '',
        custom_options: null,
        price_difference: 0,
        price_difference_formatted: '0원',
        mc_unit_price: [{ code: 'USD', formatted: '$4.25' }],
        validation_error: '',
      },
    ],
    ...overrides,
  };
}

async function renderPartial(
  file: string,
  local: Record<string, any>,
  translations: Record<string, any> = {}
) {
  const utils = createLayoutTest(asLayout(file), {
    initialState: { _local: local },
    locale: 'ko',
    translations,
    componentRegistry: setupRegistry(),
  });

  const { container } = await utils.render();

  return { utils, container };
}

// ── 테스트 ──────────────────────────────────────────────────────

describe('클레임 신청서 — 금액·오류 자리 렌더', () => {
  let active: { cleanup: () => void } | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  /**
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_total_charge_shows_secondary_currency
   */
  it('교환 요약의 총 청구액·배송비 보조통화가 글자로 나온다 (3차 C1 회귀)', async () => {
    const { utils, container } = await renderPartial('_apply_summary.json', estimateState());
    active = utils;

    const text = container.textContent ?? '';

    // 기준통화가 나온다는 것만으로는 부족하다 — 보조통화 자리가 빈 채로도 그렇게 보인다.
    expect(text).toContain('9,000원');
    expect(text).toContain('$6.75');
    expect(text).toContain('6,000원');
    expect(text).toContain('$4.50');
    // 환불 예정액은 교환에서 그리지 않는다(§6-3) — 그 줄의 보조통화는 아래 반품 케이스가 잰다.
  });

  /**
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_total_charge_shows_secondary_currency
   */
  it('보조통화 재료가 서버 원형이면 그 줄은 비되 값이 새지 않는다', async () => {
    // 핸들러가 편 배열이 아니라 서버 원형(`{통화코드: 숫자}`)이 들어오면 그 줄은 비어야 한다 —
    // 조용히 `[object Object]` 가 새어 나오면 그것이 더 나쁘다.
    const base = estimateState();
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimEstimateFormatted: {
          ...base.claimEstimateFormatted,
          mc_total_charge_amount: { KRW: 9000, USD: 6.75 } as any,
        },
      })
    );
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('9,000원');
    expect(text).not.toContain('[object Object]');
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_apply_charge_axes_hidden_for_refund
   */
  it('취소·환불은 총 청구액 줄이 서지 않고 환불 예정액 보조통화가 나온다 (3차 A1 회귀)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({ claimType: 'refund' })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).not.toContain('9,000원');
    expect(text).not.toContain('$6.75');
    // 환불 예정액은 그대로 선다 — 그 유형의 요약은 환불예정만이다(§6-3).
    expect(text).toContain('26,000원');
    expect(text).toContain('$19.50');
  });

  /**
   * @scenario exchange_mode=change, preview_result=rejected
   *
   * @effects claim_apply_preview_rejection_stays_on_screen
   */
  it('미리보기 거절은 화면에 남고 금액은 지워진다 (P13)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimEstimate: null,
        claimEstimateFormatted: null,
        claimEstimateError: '신청 가능 기간(7일)이 지나 신청할 수 없습니다.',
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('신청 가능 기간(7일)이 지나 신청할 수 없습니다.');
    expect(text).not.toContain('9,000원');
  });

  /**
   * @scenario exchange_mode=change, preview_result=rejected
   *
   * @effects claim_apply_error_channel_splits_by_fixability
   */
  it('청구 2축의 거절 사유가 그 축 아래에 나온다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimFieldErrors: {
          charge_method: '선택한 청구 방식은 사용할 수 없습니다.',
          payment_method: '선택한 결제 수단은 사용할 수 없습니다.',
          images: '',
          redelivery: '',
        },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('선택한 청구 방식은 사용할 수 없습니다.');
    expect(text).toContain('선택한 결제 수단은 사용할 수 없습니다.');
  });

  /**
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_option_price_difference_broken_down_per_item
   */
  it('상품 줄 단가의 보조통화가 글자로 나온다', async () => {
    const { utils, container } = await renderPartial('_apply_items.json', itemState());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('5,000원');
    expect(text).toContain('$4.25');
  });

  /**
   * @scenario exchange_mode=change, preview_result=rejected
   *
   * @effects claim_apply_error_channel_splits_by_fixability
   */
  it('항목별 거절 사유가 그 상품 줄에 나온다', async () => {
    const base = itemState();
    const { utils, container } = await renderPartial(
      '_apply_items.json',
      itemState({
        claimItems: [
          { ...base.claimItems[0], validation_error: '신청 수량이 남은 수량을 넘습니다.' },
        ],
      })
    );
    active = utils;

    expect(container.textContent ?? '').toContain('신청 수량이 남은 수량을 넘습니다.');
  });

  /**
   * @scenario exchange_mode=change, preview_result=rejected
   *
   * @effects claim_apply_error_channel_splits_by_fixability
   */
  it('첨부 거절 사유가 첨부 칸 아래에 나온다', async () => {
    const { utils, container } = await renderPartial('_apply_reason.json', {
      claimType: 'exchange',
      claimableSnapshot: estimateState().claimableSnapshot,
      claimFieldErrors: { images: '사진은 최대 2장까지 첨부할 수 있습니다.', redelivery: '' },
    });
    active = utils;

    expect(container.textContent ?? '').toContain('사진은 최대 2장까지 첨부할 수 있습니다.');
  });

  /**
   * @scenario exchange_mode=change, preview_result=rejected
   *
   * @effects claim_apply_error_channel_splits_by_fixability
   */
  it('재발송지 거절 사유가 재발송지 카드에 나온다', async () => {
    const { utils, container } = await renderPartial('_apply_redelivery.json', {
      claimType: 'exchange',
      claimFieldErrors: { redelivery: '교환 재발송지의 국가는 변경할 수 없습니다.', images: '' },
    });
    active = utils;

    expect(container.textContent ?? '').toContain('교환 재발송지의 국가는 변경할 수 없습니다.');
  });

  // ================================================================
  // S12 — 반품 요약 3줄 / 취소·환불 분기 / 환불계좌
  // ================================================================

  /**
   * 반품 요약 — 회수비를 환불금에서 차감하는 방식(§6-3).
   *
   * `adjustment.refund_total` 은 **이미 차감된 뒤**의 금액이라, 그 값만으로는 세 줄을 세울 수
   * 없다. 차감 전 금액과 차감액을 서버가 따로 낸다.
   */
  function returnDeductedState(): Record<string, any> {
    return estimateState({
      claimType: 'return',
      claimEstimate: {
        adjustment: { refund_total: 26000 },
        deducted_shipping_fee: 3000,
        refund_before_deduction: 29000,
      },
      claimEstimateFormatted: {
        shipping_fee: '3,000원',
        shipping_extra_fee: '0원',
        shipping_groups: [],
        price_difference: '0원',
        total_charge_amount: '3,000원',
        refund_total: '26,000원',
        refund_before_deduction: '29,000원',
        deducted_shipping_fee: '3,000원',
        mc_total_charge_amount: [{ code: 'USD', formatted: '$2.25' }],
        mc_shipping_fee: [{ code: 'USD', formatted: '$2.25' }],
        mc_refund_total: [{ code: 'USD', formatted: '$19.50' }],
        mc_refund_before_deduction: [{ code: 'USD', formatted: '$21.75' }],
      },
      claimableSnapshot: {
        settings: {
          allowed_charge_methods: ['deduct_from_refund'],
          charge_payment_methods: ['card'],
          max_images: 5,
          image_max_size_kb: 2048,
        },
      },
      claimChargeMethod: 'deduct_from_refund',
    });
  }

  /**
   * @scenario claim_type=return, charge_method=deduct_from_refund, refund_branch=none
   * @effects claim_apply_return_summary_shows_gross_deduction_and_final
   */
  it('반품 요약 — 환불예정(차감 전) · 회수비 차감 · 최종 세 줄이 글자로 나온다 (§6-3)', async () => {
    const { utils, container } = await renderPartial('_apply_summary.json', returnDeductedState());
    active = utils;

    const text = container.textContent ?? '';

    // 첫 줄은 차감 **전** 금액이다 — refund_total(26,000) 을 여기 그리면 구매자는 회수비가
    // 두 번 빠진 것으로 읽는다.
    expect(text).toContain('29,000원');
    expect(text).toContain('-3,000원');
    expect(text).toContain('26,000원');
    // 세 줄 모두 보조통화를 병기한다(§5-4).
    expect(text).toContain('$21.75');
    expect(text).toContain('$19.50');
  });

  /**
   * @scenario claim_type=return, charge_method=pay_on_request, refund_branch=none
   * @effects claim_apply_return_summary_shows_gross_deduction_and_final
   */
  it('반품 요약 — 별도 결제 방식이면 차감·최종 줄이 서지 않는다 (전액 환불인데 덜 받는 것으로 읽히지 않는다)', async () => {
    const state = returnDeductedState();
    state.claimChargeMethod = 'pay_on_request';
    state.claimEstimate = {
      adjustment: { refund_total: 29000 },
      deducted_shipping_fee: 0,
      refund_before_deduction: 29000,
    };
    state.claimEstimateFormatted.refund_total = '29,000원';
    state.claimEstimateFormatted.deducted_shipping_fee = '0원';

    const { utils, container } = await renderPartial('_apply_summary.json', state);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('29,000원');
    expect(text).not.toContain('-3,000원');
    expect(text).not.toContain('claim.apply.refund_final');
    expect(text).not.toContain('claim.apply.collection_fee_deducted');
  });

  /**
   * 요약 카드의 **모든** 금액 줄이 같은 규약으로 보조통화를 단다 (S17 트랙 A F-1).
   *
   * 차액·차감 줄만 기준 통화 하나로 남아 있었다. 위(배송비)·아래(총 청구액) 줄에는 보조통화가
   * 붙어 있어 같은 카드 안에서 표기가 갈렸고, 접수 뒤 상세는 같은 값을 병기하므로 구매자는
   * 같은 금액을 화면마다 다르게 봤다.
   *
   * @scenario claim_type=exchange, exchange_mode=change, preview_result=ok
   * @effects claim_apply_summary_amount_rows_all_show_secondary_currency
   */
  it('옵션 차액 줄도 보조통화를 단다 (형제 금액 줄과 같은 규약)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimItems: [{ order_option_id: 430, selected: true }],
        claimReason: 'size_mismatch',
        claimEstimateFormatted: {
          ...estimateState().claimEstimateFormatted,
          mc_price_difference: [{ code: 'USD', formatted: '$2.25' }],
        },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('+3,000원');
    expect(text).toContain('$2.25');
  });

  /**
   * @scenario claim_type=return, charge_method=deduct_from_refund, preview_result=ok
   * @effects claim_apply_summary_amount_rows_all_show_secondary_currency,
   *   claim_apply_return_summary_shows_gross_deduction_and_final
   */
  it('회수 배송비 차감 줄도 보조통화를 단다 (반품 요약 3줄이 같은 규약)', async () => {
    const base = estimateState();
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'return',
        claimItems: [{ order_option_id: 431, selected: true }],
        claimReason: 'changed_mind',
        claimChargeMethod: 'deduct_from_refund',
        claimEstimate: {
          deducted_shipping_fee: 3000,
          refund_before_deduction: 29000,
          adjustment: { refund_total: 26000 },
        },
        claimEstimateFormatted: {
          ...base.claimEstimateFormatted,
          deducted_shipping_fee: '3,000원',
          refund_before_deduction: '29,000원',
          mc_deducted_shipping_fee: [{ code: 'USD', formatted: '$2.25' }],
          mc_refund_before_deduction: [{ code: 'USD', formatted: '$21.75' }],
        },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('-3,000원');

    // S-B-D5(2026-09-21 세션B 실측) — 보조통화도 **같은 부호**로 적는다. 기준통화만 '-' 를
    // 붙이면 한 줄 안에서 「−3,000원 / $2.25」처럼 부호가 갈려, 차감인지 추가 청구인지
    // 읽히지 않는다. 서버가 주는 문자열에는 부호가 없으므로 화면이 붙인다.
    expect(text, '보조통화 차감액에 부호가 없다(S-B-D5)').toContain('-$2.25');
    expect(text, '부호 없는 보조통화가 그대로 남았다(S-B-D5)').not.toMatch(/(?<!-)\$2\.25/);

    // S-B-D6(같은 실측 · 운영 결정 「감추기」) — 이 방식은 회수비를 환불액에서 이미 뺐으므로
    // 따로 낼 돈이 없다. 차감 줄 아래에 「총 청구액」이 서면 같은 돈을 두 번 내는 것으로
    // 읽힌다. 0원으로 그려도 오해가 남으므로 **줄의 부재**를 잰다.
    expect(text, '「환불액에서 차감」인데 총 청구액 줄이 섰다(S-B-D6)').not.toContain('claim.apply.total_charge');

    // 같은 이유로 결제 수단 칸도 서지 않는다 — 종전에는 회색+disabled 로 남겼었다.
    expect(text, '「환불액에서 차감」인데 결제 수단 칸이 섰다(S-B-D6)').not.toContain('claim.apply.payment_method_title');
  });

  /**
   * 제출 버튼 라벨은 **지금 결제창이 뜨는가**를 말해야 한다 (S17 트랙 A F-6).
   *
   * 서버의 `requires_payment` 는 `ClaimChargeMethodEnum::requiresPayment()` = "상계가 아니다"
   * 라는 도메인 뜻이라 `신청 시 결제`와 `승인 후 결제`를 구분하지 않는다. 그 값만 보고 라벨을
   * 정하면 승인 후 결제에서도 "N원 결제하기" 로 예고하는데 실제로는 접수만 되고 결제창이 뜨지
   * 않는다 — 실행이 예고와 갈리고, 처리는 정상 성공하므로 어디에도 흔적이 남지 않는다.
   *
   * @scenario claim_type=exchange, charge_method=pay_on_approval, preview_result=ok
   * @effects claim_apply_submit_label_matches_payment_timing
   */
  it('승인 후 결제 — 지금 결제하지 않으므로 버튼이 결제하기가 아니다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimChargeMethod: 'pay_on_approval',
        claimItems: [{ order_option_id: 420, selected: true }],
        claimReason: 'color_mismatch',
        claimEstimate: {
          price_difference: 2000,
          charge_method: 'pay_on_approval',
          requires_payment: true,
          adjustment: { refund_total: 0 },
        },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.apply.submit');
    expect(text).not.toContain('claim.apply.submit_with_payment');
  });

  /**
   * 반대쪽 변종 — 신청 시 결제는 정말로 결제창이 뜨므로 금액과 함께 예고한다.
   *
   * @scenario claim_type=exchange, charge_method=pay_on_request, preview_result=ok
   * @effects claim_apply_submit_label_matches_payment_timing
   */
  it('신청 시 결제 — 버튼이 금액과 함께 결제하기로 예고한다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimChargeMethod: 'pay_on_request',
        claimItems: [{ order_option_id: 421, selected: true }],
        claimReason: 'color_mismatch',
        claimEstimate: {
          price_difference: 2000,
          charge_method: 'pay_on_request',
          requires_payment: true,
          adjustment: { refund_total: 0 },
        },
      }),
      KO_TRANSLATIONS
    );
    active = utils;

    const text = container.textContent ?? '';

    // 금액은 번역문의 파라미터다(#599 validate 2026-09-23) — 번역문 밖에서 이어 붙이면 언어마다 어순이 틀린다.
    expect(text).toContain('9,000원 결제하기');
  });

  /**
   * 결제 수단 라디오는 수단마다 제 이름으로 보인다 — 값만 보고 `point` 가 아니면 모두
   * 「카드·간편결제」로 그리면 계좌이체를 고르면서 카드로 낸다고 읽는다.
   *
   * 회귀 배경 (S1-D14, 2026-10-07 실측): 허용 수단을 `['card','bank']` 로 두자 두 라디오가
   * 모두 「카드·간편결제」였다.
   *
   * @scenario claim_type=exchange, charge_method=pay_on_request, preview_result=ok
   * @effects claim_payment_method_choices_carry_their_own_labels
   */
  it('결제 수단 — 카드·마일리지 밖의 수단은 서버 표시명으로 보인다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimChargeMethod: 'pay_on_request',
        claimItems: [{ order_option_id: 422, selected: true }],
        claimReason: 'color_mismatch',
        claimEstimate: {
          price_difference: 2000,
          charge_method: 'pay_on_request',
          requires_payment: true,
          adjustment: { refund_total: 0 },
        },
        claimableSnapshot: {
          settings: {
            allowed_charge_methods: ['pay_on_request'],
            charge_payment_methods: ['card', 'bank'],
            charge_payment_method_labels: { card: '신용카드', bank: '계좌이체' },
            max_images: 5,
            image_max_size_kb: 2048,
          },
        },
      })
    );
    active = utils;

    const labelOf = (value: string) =>
      container
        .querySelector<HTMLInputElement>(`input[type=radio][name=claimPaymentMethod][value=${value}]`)
        ?.closest('label')
        ?.textContent?.trim();

    expect(labelOf('bank')).toBe('계좌이체');
    // 카드는 종전 안내 문구(간편결제까지 묶어 부르는 이름)를 지킨다 — 이 환경은 식 안의
    // `$t()` 를 키 그대로 돌려주므로 키로 잰다.
    expect(labelOf('card')).toBe('claim.apply.payment_method_card');
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_refund_branch_drives_notice_and_submit_label
   */
  it('취소 분기 — 안내 문구가 즉시취소로 나오고 실행 버튼이 주문 취소가 된다 (§6-3)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimResolvedBranch: 'auto',
        claimItems: [{ order_option_id: 410, selected: true }],
        claimReason: 'changed_mind',
        claimEstimate: { adjustment: { refund_total: 10000 } },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.apply.notice_cancel');
    expect(text).toContain('claim.apply.submit_cancel');
    expect(text).not.toContain('claim.apply.notice_refund');
    expect(text).not.toContain('claim.apply.submit_refund');
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=request
   * @effects claim_apply_refund_branch_drives_notice_and_submit_label,
   *   claim_apply_refund_account_shown_only_for_bank_refunds,
   *   claim_apply_refund_bank_error_renders_beside_the_card
   */
  it('환불신청 분기 — 안내 문구와 실행 버튼이 환불 신청이 된다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimResolvedBranch: 'request',
        claimItems: [{ order_option_id: 411, selected: true }],
        claimReason: 'changed_mind',
        claimEstimate: { adjustment: { refund_total: 20000 } },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.apply.notice_refund');
    expect(text).toContain('claim.apply.submit_refund');
    expect(text).not.toContain('claim.apply.notice_cancel');
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=mixed
   * @effects claim_apply_refund_branch_unset_shows_no_branch_notice,
   *   claim_apply_refund_branch_locks_cross_branch_items,
   *   claim_withdraw_modal_states_consequences
   */
  it('분기가 정해지지 않았으면(아직 아무것도 고르지 않음) 안내가 서지 않는다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimResolvedBranch: null,
        claimableSnapshot: {
          resolved_type: 'mixed',
          settings: { allowed_charge_methods: [], charge_payment_methods: [], max_images: 5, image_max_size_kb: 2048 },
        },
        claimEstimate: { adjustment: { refund_total: 0 } },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    // 'mixed' 는 안내할 분기가 아니다 — 둘 중 무엇도 단정하지 않는다.
    expect(text).not.toContain('claim.apply.notice_cancel');
    expect(text).not.toContain('claim.apply.notice_refund');
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_apply_refund_account_shown_only_for_bank_refunds
   */
  it('환불계좌 — 계좌 환불 주문에서만 카드가 선다', async () => {
    const { utils, container } = await renderPartial('_apply_refund_account.json', {
      claimType: 'refund',
      claimableSnapshot: {
        refund_account: {
          required: true,
          banks: [{ code: '004', name: { ko: '국민은행' } }],
        },
      },
    });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.apply.refund_account_title');
    expect(text).toContain('claim.apply.refund_account_all_required');
    // 입력칸은 비어 있다 — 주문에 기록된 계좌를 프리필하면 마스킹된 값이 저장된다.
    const inputs = Array.from(container.querySelectorAll('input')) as HTMLInputElement[];
    expect(inputs.length).toBeGreaterThan(0);
    expect(inputs.every((input) => (input.value ?? '') === '')).toBe(true);
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_apply_refund_account_shown_only_for_bank_refunds
   */
  it('환불계좌 — PG 환불 주문에서는 카드가 서지 않는다 (쓸모없는 정보를 요구하지 않는다)', async () => {
    const { utils, container } = await renderPartial('_apply_refund_account.json', {
      claimType: 'refund',
      claimableSnapshot: { refund_account: { required: false, banks: [] } },
    });
    active = utils;

    expect(container.textContent ?? '').not.toContain('claim.apply.refund_account_title');
  });

  /**
   * @scenario claim_type=return, charge_method=deduct_from_refund
   *
   * @effects claim_apply_refund_account_shown_only_for_bank_refunds
   */
  it('환불계좌 — 반품에서도 선다 (§6-3 \"무통장 원결제면 환불계좌 입력\")', async () => {
    const { utils, container } = await renderPartial('_apply_refund_account.json', {
      claimType: 'return',
      claimableSnapshot: { refund_account: { required: true, banks: [{ code: '004', name: { ko: '국민은행' } }] } },
    });
    active = utils;

    // 계좌 환불은 운영자가 직접 이체한다 — 계좌가 없는 무통장 주문의 반품이 계좌 없이
    // 접수되면 승인 단계에서 운영자가 구매자에게 따로 물어야 한다.
    expect(container.textContent ?? '').toContain('claim.apply.refund_account_title');
  });

  /**
   * @scenario exchange_mode=same, preview_result=ok
   *
   * @effects claim_apply_refund_account_shown_only_for_bank_refunds
   */
  it('환불계좌 — 차액이 돌아오지 않는 교환에서는 서지 않는다 (대조군)', async () => {
    const { utils, container } = await renderPartial('_apply_refund_account.json', {
      claimType: 'exchange',
      claimEstimate: { refund_product_amount: 0 },
      claimableSnapshot: { refund_account: { required: true, banks: [{ code: '004', name: { ko: '국민은행' } }] } },
    });
    active = utils;

    expect(container.textContent ?? '').not.toContain('claim.apply.refund_account_title');
  });

  /**
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_refund_account_shown_only_for_bank_refunds
   */
  it('환불계좌 — 저가 옵션 교환으로 차액이 돌아오면 선다 (2026-08-31 확정)', async () => {
    const { utils, container } = await renderPartial('_apply_refund_account.json', {
      claimType: 'exchange',
      claimEstimate: { refund_product_amount: 1000 },
      claimableSnapshot: { refund_account: { required: true, banks: [{ code: '004', name: { ko: '국민은행' } }] } },
    });
    active = utils;

    expect(container.textContent ?? '').toContain('claim.apply.refund_account_title');
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_refund_account_prefills_the_registered_account
   */
  it('환불계좌 — 주문 시 등록해 둔 계좌가 채워진 상태로 열린다 (2026-08-31 확정)', async () => {
    const { utils, container } = await renderPartial('_apply_refund_account.json', {
      claimType: 'refund',
      claimableSnapshot: { refund_account: { required: true, banks: [{ code: '004', name: { ko: '국민은행' } }] } },
      claimRefundBankCode: '004',
      claimRefundBankAccount: '110234567890',
      claimRefundBankHolder: '홍길동',
    });
    active = utils;

    const values = Array.from(container.querySelectorAll('input')).map((el) => (el as HTMLInputElement).value);

    // 채우는 값은 서버가 실어 준 원문이다 — 마스킹 값(****7890)을 채우면 손대지 않은 제출에서
    // 별표가 섞인 계좌번호가 그대로 저장된다.
    expect(values).toContain('110234567890');
    expect(values).toContain('홍길동');
    expect(values.some((v) => v.includes('*'))).toBe(false);
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_apply_refund_bank_error_renders_beside_the_card
   */
  it('환불계좌 — 제출 거절 사유가 그 카드 아래에 나온다 (§6-3 P13)', async () => {
    const { utils, container } = await renderPartial('_apply_refund_account.json', {
      claimType: 'refund',
      claimableSnapshot: { refund_account: { required: true, banks: [] } },
      claimFieldErrors: { refund_bank: '은행·계좌번호·예금주를 모두 입력해 주세요.' },
    });
    active = utils;

    expect(container.textContent ?? '').toContain('은행·계좌번호·예금주를 모두 입력해 주세요.');
  });

  /**
   * @scenario claim_type=refund, refund_branch=auto
   *
   * @effects claim_apply_refund_branch_locks_cross_branch_items
   */
  it('상품 줄 — 취소·환불에서 분기 배지가 나오고 다른 분기의 체크는 잠긴다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_items.json',
      itemState({
        claimType: 'refund',
        claimResolvedBranch: 'auto',
        claimableSnapshot: { resolved_type: 'mixed' },
        claimItems: [
          {
            order_option_id: 410,
            product_name: '즉시취소 상품',
            selected: true,
            quantity: 1,
            available_quantity: 1,
            refund_branch: 'auto',
            unit_price_formatted: '10,000원',
            mc_unit_price: [],
            validation_error: '',
          },
          {
            order_option_id: 411,
            product_name: '환불신청 상품',
            selected: false,
            quantity: 1,
            available_quantity: 1,
            refund_branch: 'request',
            unit_price_formatted: '20,000원',
            mc_unit_price: [],
            validation_error: '',
          },
        ],
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.apply.refund_branch_cancel_badge');
    expect(text).toContain('claim.apply.refund_branch_refund_badge');
    // 왜 체크가 먹지 않는지 밝힌다 — 밝히지 않으면 화면이 고장난 것으로 보인다.
    expect(text).toContain('claim.apply.refund_branch_mixed_notice');

    const checkboxes = Array.from(
      container.querySelectorAll('input[type="checkbox"]')
    ) as HTMLInputElement[];
    // 전체 선택 + 상품 2줄
    expect(checkboxes.length).toBe(3);
    expect(checkboxes[1].disabled).toBe(false);
    expect(checkboxes[2].disabled).toBe(true);
  });

  /**
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_refund_branch_unset_shows_no_branch_notice
   */
  it('상품 줄 — 교환에서는 분기 배지도 혼재 안내도 서지 않는다 (대조군)', async () => {
    const { utils, container } = await renderPartial('_apply_items.json', itemState());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).not.toContain('claim.apply.refund_branch_cancel_badge');
    expect(text).not.toContain('claim.apply.refund_branch_refund_badge');
    expect(text).not.toContain('claim.apply.refund_branch_mixed_notice');
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_withdraw_uses_the_shell_supplied_base
   */
  it('철회 모달 — 대상 번호와 되돌릴 수 없다는 안내·환급 안내가 함께 나온다', async () => {
    const { utils, container } = await renderPartial(
      '_modal_withdraw.json',
      {
        withdrawClaimNumber: 'RT20260901-0001',
        withdrawError: null,
        isWithdrawingClaim: false,
        claimApiBase: '/api/x',
      },
      // 대상 번호가 실제로 문구에 실려 나오는지 재려면 그 키의 번역이 있어야 한다 —
      // 번역이 없으면 키 이름만 그려져 파라미터가 사라진 것도 통과한다.
      { claim: { withdraw: { confirm_message: '{{number}} 신청을 철회하시겠습니까?' } } }
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('RT20260901-0001');
    expect(text).toContain('claim.withdraw.irreversible_notice');
    expect(text).toContain('claim.withdraw.payment_refund_notice');
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_withdraw_requires_a_target
   */
  it('철회 모달 — 거절 사유가 모달 안에 남는다 (토스트만 띄우면 사라진 뒤 화면에 없다)', async () => {
    const { utils, container } = await renderPartial('_modal_withdraw.json', {
      withdrawClaimNumber: 'RT20260901-0001',
      withdrawError: '승인된 신청은 철회할 수 없습니다.',
      isWithdrawingClaim: false,
      claimApiBase: '/api/x',
    });
    active = utils;

    expect(container.textContent ?? '').toContain('승인된 신청은 철회할 수 없습니다.');
  });

  /**
   * @scenario claim_type=refund, refund_branch=request
   *
   * @effects claim_withdraw_requires_a_target
   */
  it('철회 모달 — 대상이 없으면 확인 버튼이 잠긴다 (아무것도 가리키지 않는 실행을 막는다)', async () => {
    const { utils, container } = await renderPartial('_modal_withdraw.json', {
      withdrawClaimNumber: null,
      withdrawError: null,
      isWithdrawingClaim: false,
      claimApiBase: '/api/x',
    });
    active = utils;

    const buttons = Array.from(container.querySelectorAll('button')) as HTMLButtonElement[];

    expect(buttons.length).toBeGreaterThan(0);
    expect(buttons[buttons.length - 1].disabled).toBe(true);
  });

  // ── 주문금액 비교 섹션 (#599 후속 — 옛 취소 모달의 비교표 복구) ──────────

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_comparison_table_renders_sixteen_rows
   */
  it('비교표 — 취소 신청서에서 16행(쿠폰 있음 · 치환 없음 = 15행) 라벨과 금액이 한국어 글자로 난다', async () => {
    const { utils, container } = await renderPartial('_apply_comparison.json', comparisonState(), KO_TRANSLATIONS);
    active = utils;

    const text = container.textContent ?? '';
    const rows = Array.from(container.querySelectorAll('[data-row]')).map((el) => el.getAttribute('data-row'));

    // 14 금액행 + 쿠폰행. 교환 치환행은 취소에서 서지 않는다.
    expect(rows).toHaveLength(15);
    expect(rows).not.toContain('substituted');
    expect(text).toContain('주문금액 비교');
    // 열 라벨은 유형 분기 식(`$t(...)`) 이라 하네스에서는 키가 그대로 난다 — 취소 분기의 키가 골라졌는지를 잰다.
    expect(text).toContain('claim.apply.comparison.col_before_cancel');
    expect(text).toContain('claim.apply.comparison.col_after_cancel');
    expect(text).not.toContain('col_before_refund');
    expect(text).toContain('부가세');
    expect(text).toContain('5,636원');
    expect(text).toContain('3,091원');
    expect(text).toContain('5개');
    expect(text).toContain('4개');
    expect(text).toContain('620P');
    expect(text).toContain('첫구매 쿠폰');
    expect(text).toContain('-3,000원');
    // `$t:` 접두 라벨은 전부 번역돼야 한다 — 접두가 그대로 새면 키가 언어팩에 없다는 뜻이다.
    expect(text).not.toContain('$t:');
    expect(text).not.toContain('row_');
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_comparison_highlights_changed_after_values
   */
  it('비교표 — 후열은 전열과 다른 행만 강조색이다', async () => {
    const { utils, container } = await renderPartial('_apply_comparison.json', comparisonState(), KO_TRANSLATIONS);
    active = utils;

    const lastSpan = (row: string): HTMLElement => {
      const spans = container.querySelectorAll(`[data-row="${row}"] span`);
      return spans[spans.length - 1] as HTMLElement;
    };

    // 62,000 → 45,000 (변함) · 0 → 0 (그대로) · 5,000 → 5,000 (그대로)
    expect(lastSpan('total_paid_amount').className).toContain('text-red-600');
    expect(lastSpan('total_vat_amount').className).toContain('text-red-600');
    expect(lastSpan('base_shipping_amount').className).not.toContain('text-red-600');
    expect(lastSpan('total_points_used_amount').className).not.toContain('text-red-600');
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_comparison_table_renders_sixteen_rows
   */
  it('비교표 — 정가·판매가·실결제 3행은 보조통화를 병기한다 (기준 통화는 뺀다)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_comparison.json',
      comparisonState({}, {
        mc_original_snapshot: {
          mc_total_list_price_amount_formatted: { KRW: '86,000원', USD: '$73.10' },
          mc_subtotal_amount_formatted: { KRW: '70,000원', USD: '$59.50' },
          mc_total_paid_amount_formatted: { KRW: '62,000원', USD: '$52.70' },
        },
        mc_recalculated_snapshot: {
          mc_total_list_price_amount_formatted: { KRW: '61,000원', USD: '$51.85' },
          mc_subtotal_amount_formatted: { KRW: '50,000원', USD: '$42.50' },
          mc_total_paid_amount_formatted: { KRW: '45,000원', USD: '$38.25' },
        },
      }),
      KO_TRANSLATIONS
    );
    active = utils;

    const text = container.textContent ?? '';
    for (const usd of ['$73.10', '$59.50', '$52.70', '$51.85', '$42.50', '$38.25']) {
      expect(text).toContain(usd);
    }
    // 기준 통화(KRW)는 보조통화 줄에 반복되지 않는다 — 판매가 70,000원은 본 줄 한 번뿐이다.
    expect(text.split('70,000원')).toHaveLength(2);
  });

  /**
   * @scenario claim_type=exchange, charge_method=pay_on_request, exchange_mode=change
   * @effects claim_apply_exchange_substituted_rows_render, claim_apply_exchange_after_column_reflects_substitution
   */
  it('비교표 — 교환은 열 라벨이 교환 전/후이고 치환 근거 행(상품별 old→new)이 선다', async () => {
    const substituted = [{ order_option_id: 310, quantity: 1, product_option_id: 77, unit_price: 29000, new_unit_price: 32000, line_key: -310, option_label: '블랙 / L' }];
    const state = comparisonState(
      { claimType: 'exchange', claimResolvedBranch: null },
      { substituted_items: substituted }
    );
    state.claimEstimateFormatted.substituted_items = [
      { ...substituted[0], unit_price_formatted: '29,000원', new_unit_price_formatted: '32,000원' },
    ];
    const { utils, container } = await renderPartial('_apply_comparison.json', state, KO_TRANSLATIONS);
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('claim.apply.comparison.col_before_exchange');
    expect(text).toContain('claim.apply.comparison.col_after_exchange');
    expect(container.querySelector('[data-row="substituted"]')).not.toBeNull();
    expect(text).toContain('블랙 / L');
    expect(text).toContain('29,000원 → 32,000원');
    // 고가 교환(차액 환불 없음)은 환불 요약을 그리지 않는다.
    expect(container.querySelector('[data-testid="claim-comparison-refund-summary"]')).toBeNull();
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_cancel_blocked_locks_submit_inline
   */
  it('환불 불가 — 사유와 안내가 표 아래 인라인으로 남고 제출 버튼이 잠긴다 (P3-①)', async () => {
    const blocked = { cancel_blocked: true, cancel_blocked_reason: '취소 후 결제금액이 늘어나 취소할 수 없습니다.' };

    const inline = await renderPartial('_apply_comparison.json', comparisonState({}, blocked), KO_TRANSLATIONS);
    active = inline.utils;
    const block = inline.container.querySelector('[data-testid="claim-comparison-blocked"]');
    expect(block?.textContent).toContain('취소 후 결제금액이 늘어나 취소할 수 없습니다.');
    expect(block?.textContent).toContain('0원 아래로 내려가');
    expect(inline.container.querySelector('[data-testid="claim-comparison-refund-summary"]')).toBeNull();
    // 표는 그대로 남는다 — 왜 막혔는지 후열이 말한다.
    expect(inline.container.querySelectorAll('[data-row]').length).toBe(15);
    inline.utils.cleanup();

    const summary = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimResolvedBranch: 'auto',
        claimItems: [{ order_option_id: 410, selected: true }],
        claimReason: 'changed_mind',
        claimComparisonOpen: true,
        claimEstimate: { refund_product_amount: 0, adjustment: comparisonAdjustment(blocked) },
      }),
      KO_TRANSLATIONS
    );
    active = summary.utils;
    const submit = summary.container.querySelector('button.w-full.py-3') as HTMLButtonElement | null;
    expect(submit).not.toBeNull();
    expect(submit!.disabled).toBe(true);
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_refund_priority_reestimates
   */
  it('환불 우선순위 라디오 — 마일리지를 쓴 주문에서만 선다 (P3-③)', async () => {
    const withPoints = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimResolvedBranch: 'auto',
        claimRefundPriority: 'pg_first',
        claimEstimate: { refund_product_amount: 0, adjustment: comparisonAdjustment() },
      }),
      KO_TRANSLATIONS
    );
    active = withPoints.utils;
    const radios = withPoints.container.querySelectorAll('input[name="claimRefundPriority"]');
    expect(radios).toHaveLength(2);
    expect(withPoints.container.textContent).toContain('PG 우선 환불');
    expect(withPoints.container.textContent).toContain('마일리지 우선 환불');
    withPoints.utils.cleanup();

    const noPoints = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimResolvedBranch: 'auto',
        claimEstimate: {
          refund_product_amount: 0,
          adjustment: comparisonAdjustment({ original_snapshot: { ...ORIGINAL_SNAPSHOT, total_points_used_amount: 0 } }),
        },
      }),
      KO_TRANSLATIONS
    );
    active = noPoints.utils;
    expect(noPoints.container.querySelector('[data-testid="claim-refund-priority"]')).toBeNull();
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_restored_coupons_and_remaining_balances_render
   */
  it('환불 요약 — 잔여 결제 잔액·잔여 마일리지 2행과 복원 쿠폰 블록이 글자로 난다 (P3-②·④)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_comparison.json',
      comparisonState({}, {
        restored_coupons: [{ coupon_name: '웰컴 쿠폰', discount_amount: 2000, discount_amount_formatted: '2,000원' }],
      }),
      KO_TRANSLATIONS
    );
    active = utils;

    expect(container.querySelector('[data-line="remaining_pg"]')?.textContent).toContain('45,000원');
    expect(container.querySelector('[data-line="remaining_points"]')?.textContent).toContain('5,000원');
    expect(container.querySelector('[data-line="refund_cash"]')?.textContent).toContain('17,000원');
    // 마일리지 환불분이 0 이면 그 줄은 서지 않는다.
    expect(container.querySelector('[data-line="refund_points"]')).toBeNull();

    const coupons = container.querySelector('[data-testid="claim-comparison-restored-coupons"]');
    expect(coupons?.textContent).toContain('웰컴 쿠폰');
    expect(coupons?.textContent).toContain('2,000원');
    expect(coupons?.textContent).toContain('쿠폰함으로 복원');
  });

  /**
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_comparison_default_open_follows_breakpoint
   */
  it('접힘 상태 — 본문은 렌더하지 않고 토글만 aria-expanded=false 로 선다 (P6)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_comparison.json',
      comparisonState({ claimComparisonOpen: false }),
      KO_TRANSLATIONS
    );
    active = utils;

    expect(container.querySelector('#claim-comparison-body')).toBeNull();
    expect(container.querySelectorAll('[data-row]')).toHaveLength(0);
    const toggle = container.querySelector('[data-testid="claim-comparison-toggle"]');
    expect(toggle?.getAttribute('aria-expanded')).toBe('false');
    expect(toggle?.getAttribute('aria-controls')).toBe('claim-comparison-body');
    // 토글 문구는 상태 분기 식이라 하네스에서는 키가 난다 — 접힘 상태에서 '펼치기' 쪽 키가 골라진다.
    expect(toggle?.textContent).toContain('claim.apply.comparison.expand');
    expect(toggle?.textContent).not.toContain('collapse');
    // 제목은 접혀 있어도 보인다 — 무엇이 접혀 있는지 알 수 있어야 한다.
    expect(container.textContent).toContain('주문금액 비교');
  });
  /**
   * 계좌로 돈이 돌아가는 주문인데 계좌가 비어 있으면 **제출 버튼이 잠긴다**. (T2-D5)
   *
   * 2026-09-22 #599 3차 실측 L-8 — 무통장으로 10,000원을 낸 주문의 환불을 계좌 세 칸을 비운 채
   * 신청해도 접수됐다. 그 뒤 운영자 화면에는 계좌를 적을 자리가 없어, 승인·[환불하기]를 지나
   * **송금 없이 장부만 `completed`** 로 닫혔고 되돌릴 경로도 없었다.
   *
   * 서버가 422 로 막는 것(`StoreRefundRequestRequest::requiresRefundBank`)만으로는 부족하다 —
   * 구매자는 다 적었다고 생각하고 누른 뒤에야 거절을 본다. 화면이 먼저 막고 이유를 남긴다.
   *
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_submit_blocked_until_the_refund_account_is_filled
   */
  it('환불계좌 — 계좌가 비면 제출 버튼이 잠기고 이유가 남는다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimItems: [{ order_option_id: 430, selected: true }],
        claimReason: 'changed_mind',
        claimEstimate: { price_difference: 0, adjustment: { refund_total: 10000 } },
        claimableSnapshot: { refund_account: { required: true, banks: [] } },
      })
    );
    active = utils;

    const submit = findSubmitButton(container);
    expect(submit).not.toBeNull();
    expect(submit?.disabled).toBe(true);
    expect(container.textContent ?? '').toContain('claim.apply.refund_account_required_to_submit');
  });

  /**
   * 대조군 ① — 세 칸을 채우면 잠금이 풀린다. 잠기기만 하면 아무도 신청할 수 없다.
   *
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_submit_blocked_until_the_refund_account_is_filled
   */
  it('환불계좌 — 세 칸을 채우면 제출 버튼이 풀린다 (대조군)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimItems: [{ order_option_id: 431, selected: true }],
        claimReason: 'changed_mind',
        claimEstimate: { price_difference: 0, adjustment: { refund_total: 10000 } },
        claimableSnapshot: { refund_account: { required: true, banks: [] } },
        claimRefundBankCode: '004',
        claimRefundBankAccount: '01012345678',
        claimRefundBankHolder: '홍길동',
      })
    );
    active = utils;

    expect(findSubmitButton(container)?.disabled).toBe(false);
    expect(container.textContent ?? '').not.toContain('claim.apply.refund_account_required_to_submit');
  });

  /**
   * 대조군 ② — PG 로 되돌아가는 주문은 계좌가 없어도 막지 않는다.
   *
   * 게이트를 계좌 카드와 다른 값으로 재현하면 카드는 없는데 버튼만 잠겨 **영원히 신청할 수 없는
   * 주문**이 생긴다. 카드가 서는 조건(`refund_account.required`)과 같은 값을 쓴다는 잠금이다.
   *
   * @scenario claim_type=refund, charge_method=none, refund_branch=auto
   * @effects claim_apply_submit_blocked_until_the_refund_account_is_filled
   */
  it('환불계좌 — PG 환불 주문은 계좌가 없어도 제출할 수 있다 (대조군)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      estimateState({
        claimType: 'refund',
        claimItems: [{ order_option_id: 432, selected: true }],
        claimReason: 'changed_mind',
        claimEstimate: { price_difference: 0, adjustment: { refund_total: 10000 } },
        claimableSnapshot: { refund_account: { required: false, banks: [] } },
      })
    );
    active = utils;

    expect(findSubmitButton(container)?.disabled).toBe(false);
    expect(container.textContent ?? '').not.toContain('claim.apply.refund_account_required_to_submit');
  });
});

// ================================================================
// S2-D8 — 해외 주문의 교환 재발송지 (2026-10-07 S4 실측 → 인계)
// ================================================================

/** 재발송지 카드를 주문 데이터소스와 함께 감싼다 — 원 배송지는 `order.data.shipping_address` 에서 온다. */
function redeliveryLayout(): any {
  return {
    data_sources: [
      { id: 'order', type: 'api', endpoint: '/api/modules/sirsoft-ecommerce/user/orders/X', method: 'GET', auto_fetch: true },
    ],
    components: [inlinePartials(loadPartial('_apply_redelivery.json'))],
  };
}

/** 미국 배송 주문 — 체크아웃은 해외 주문의 국내 주소 칸을 빈 문자열로 남긴다. */
const US_SHIPPING_ADDRESS = {
  recipient_name: 'Jane Doe',
  recipient_phone: '+1-212-555-0101',
  recipient_country_code: 'US',
  recipient_country_name: 'United States',
  zipcode: '',
  address: '',
  address_detail: '',
  address_line_1: '350 5th Ave',
  address_line_2: 'Suite 2',
  intl_city: 'New York',
  intl_state: 'NY',
  intl_postal_code: '10118',
};

async function renderRedelivery(shippingAddress: Record<string, any>, local: Record<string, any> = {}) {
  const utils = createLayoutTest(redeliveryLayout(), {
    initialState: { _local: { claimType: 'exchange', ...local } },
    locale: 'ko',
    translations: KO_TRANSLATIONS,
    componentRegistry: setupRegistry(),
  });
  utils.mockApi('order', { response: { data: { shipping_address: shippingAddress } } });

  const { container } = await utils.render();

  return { utils, container };
}

describe('교환 재발송지 — 해외 주문 (S2-D8)', () => {
  let active: { cleanup: () => void } | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  /**
   * 요약이 국내 칸만 이어 붙이면 해외 주문은 「Jane Doe / +1-212-555-0101 ()」 처럼 주소가 비고
   * 빈 괄호만 남는다(주문 710 실측).
   *
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_redelivery_shows_the_international_address
   */
  it('원 배송지 요약에 해외 주소(거리·도시·주·우편번호)를 보인다', async () => {
    const { utils, container } = await renderRedelivery(US_SHIPPING_ADDRESS);
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('(10118) 350 5th Ave Suite 2, New York, NY');
    expect(text).not.toContain('()');
  });

  /**
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_redelivery_shows_the_international_address
   */
  it('국내 주문 요약은 종전 모양 그대로다 — 도시·주 자리가 끼지 않는다', async () => {
    const { utils, container } = await renderRedelivery({
      recipient_name: '홍길동', recipient_phone: '010-1234-5678', recipient_country_code: 'KR',
      zipcode: '06236', address: '서울 강남구 테헤란로 1', address_detail: '101호',
      address_line_1: null, address_line_2: null, intl_city: null, intl_state: null, intl_postal_code: null,
    });
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('(06236) 서울 강남구 테헤란로 1 101호');
    expect(text).not.toContain(', ,');
  });

  /**
   * [변경] 폼은 해외 주문에만 도시·주 칸을 더한다 — 서버 스냅샷이 두 칸을 따로 담는다.
   *
   * @scenario exchange_mode=change, preview_result=ok
   *
   * @effects claim_apply_redelivery_shows_the_international_address
   */
  it('해외 주문의 [변경] 폼에는 도시·주 칸이 있고, 국내 주문에는 없다', async () => {
    const editing = {
      claimRedeliveryEditing: true,
      claimRedelivery: { recipient_name: 'Jane Doe', phone: '+1', zipcode: '10118', address1: '350 5th Ave', address2: '', city: 'New York', state: 'NY', country_code: 'US' },
    };
    const intl = await renderRedelivery(US_SHIPPING_ADDRESS, editing);
    active = intl.utils;
    const values = Array.from(intl.container.querySelectorAll('input')).map((input) => (input as HTMLInputElement).value);
    expect(values).toEqual(expect.arrayContaining(['New York', 'NY']));
    expect(intl.container.textContent ?? '').toContain(KO_CLAIM.apply.city);
    intl.utils.cleanup();

    const domestic = await renderRedelivery(
      { recipient_country_code: 'KR', zipcode: '06236', address: '서울', address_detail: '' },
      { ...editing, claimRedelivery: { ...editing.claimRedelivery, city: '', state: '', country_code: 'KR' } },
    );
    active = domestic.utils;
    expect(domestic.container.textContent ?? '').not.toContain(KO_CLAIM.apply.city);
  });
});
