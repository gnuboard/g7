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
 *
 * @scenario exchange_mode=change, preview_result=ok
 * @effects claim_apply_total_charge_shows_secondary_currency,
 *   claim_apply_summary_amount_rows_all_show_secondary_currency,
 *   claim_apply_total_charge_row_hidden_for_refund,
 *   claim_apply_item_validation_error_maps_to_its_row,
 *   claim_apply_field_errors_render_beside_their_input,
 *   claim_apply_preview_rejection_stays_on_screen
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

/** partial 하나를 단독 레이아웃으로 감싼다 — 셸은 이 파일의 관심사가 아니다. */
function asLayout(file: string): any {
  return { components: [loadPartial(file)] };
}

/** 요약이 금액을 그리는 데 필요한 최소 상태. 값은 서버 응답 형태를 그대로 흉내낸다. */
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

async function renderPartial(file: string, local: Record<string, any>) {
  const utils = createLayoutTest(asLayout(file), {
    initialState: { _local: local },
    locale: 'ko',
    translations: {},
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

  it('상품 줄 단가의 보조통화가 글자로 나온다', async () => {
    const { utils, container } = await renderPartial('_apply_items.json', itemState());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('5,000원');
    expect(text).toContain('$4.25');
  });

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

  it('첨부 거절 사유가 첨부 칸 아래에 나온다', async () => {
    const { utils, container } = await renderPartial('_apply_reason.json', {
      claimType: 'exchange',
      claimableSnapshot: estimateState().claimableSnapshot,
      claimFieldErrors: { images: '사진은 최대 2장까지 첨부할 수 있습니다.', redelivery: '' },
    });
    active = utils;

    expect(container.textContent ?? '').toContain('사진은 최대 2장까지 첨부할 수 있습니다.');
  });

  it('재발송지 거절 사유가 재발송지 카드에 나온다', async () => {
    const { utils, container } = await renderPartial('_apply_redelivery.json', {
      claimType: 'exchange',
      claimFieldErrors: { redelivery: '교환 재발송지의 국가는 변경할 수 없습니다.', images: '' },
    });
    active = utils;

    expect(container.textContent ?? '').toContain('교환 재발송지의 국가는 변경할 수 없습니다.');
  });
});
