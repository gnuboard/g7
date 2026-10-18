/**
 * @file userClaimEditRender.test.tsx
 * @description 클레임 신청서 **수정 모드** 렌더 회귀 테스트 (#599 S13)
 *
 * 수정 화면은 작성 셸을 그대로 재사용한다(§5-1-6). 그래서 "무엇이 잠기는가" 가 화면의 계약이다 —
 * 잠겨야 할 컨트롤이 살아 있으면 구매자는 눌러도 아무 일이 없는 자리를 만나고, 반대로 열려야 할
 * 칸이 잠기면 승인 전에 고칠 수 있는 것을 고치지 못한다. 어느 쪽도 오류를 남기지 않는다.
 *
 * 잰다:
 *  ① 품목·수량·교환 대상은 잠긴다 (D13 — 바꿀 수 있는 것은 사유·상세 사유·첨부, 교환은 재발송지)
 *  ② 청구 2축은 서지 않고 접수 때 고른 방식이 읽기 전용으로 남는다
 *  ③ 제출 버튼 대신 저장 버튼이 선다
 *  ④ 재발송지 변경 차액 안내(D14)는 차액이 있을 때만 선다
 *  ⑤ 수정 거절(4xx) 사유가 요약 카드에 남는다
 *  ⑥ 환불 계좌는 편집할 수 있고, 왜 빈 칸으로 열리는지 화면이 말한다 (2026-09-01 확정)
 *
 * @scenario claim_type=exchange, charge_method=pay_on_request, refund_branch=none
 * @effects claim_edit_locks_items_and_quantity,
 *   claim_edit_hides_charge_axes_and_shows_the_recorded_method,
 *   claim_edit_replaces_submit_with_save,
 *   claim_edit_charge_delta_notice_only_when_nonzero,
 *   claim_edit_rejection_stays_on_screen,
 *   claim_edit_refund_account_is_editable_and_explained
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

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();

  registry.register('basic', 'Icon', ({ className, name }: any) =>
    React.createElement('i', { className, 'data-icon': name })
  );
  registry.register('composite', 'FileUploader', () =>
    React.createElement('div', { 'data-testid': 'file-uploader' })
  );

  return registry;
}

function loadPartial(file: string): any {
  return JSON.parse(
    fs.readFileSync(
      path.resolve(__dirname, '../../layouts/partials/mypage/claims/' + file),
      'utf-8'
    )
  );
}

/**
 * 라벨 중 `text` 안의 `$t('...')` 로 만드는 것은 이 하네스가 치환하지 않고 키를 그대로 남긴다
 * (`$t:키` 형태만 치환한다). 실 화면에서는 엔진이 둘 다 치환하므로, 잴 것은 **어느 키가
 * 섰는가** 이고 그것이 곧 분기가 맞았다는 증거다.
 */
const SAVE_LABEL = 'claim.edit.save';
const SUBMIT_LABEL = 'claim.apply.submit';
const DELTA_MORE = 'claim.edit.charge_delta_more';
const DELTA_LESS = 'claim.edit.charge_delta_less';

const TRANSLATIONS = {
  claim: {
    apply: {
      items_title: '신청 상품',
      select_all: '전체선택',
      quantity: '수량',
      change_product: '교환상품 변경',
      summary_title: '신청 요약',
      charge_method_title: '청구 방식',
      payment_method_title: '결제 수단',
      submit: '신청하기',
      exchange_target_title: '교환 대상',
      exchange_same_option: '동일 상품 교환',
      no_price_difference: '차액 없음',
      available_quantity: '신청 가능 {{count}}',
      locked_quantity: '신청 중 {{count}}',
      estimate_pending: '상품과 사유를 선택하면 금액이 계산됩니다.',
    },
    edit: {
      save: '저장',
      charge_delta_more: '추가로 청구됩니다.',
      charge_delta_less: '환급됩니다.',
      refund_account_current: '지금 기록된 환불 계좌',
      refund_account_hint: '계좌를 바꾸려면 아래에 다시 입력해 주세요.',
    },
  },
  common: { processing: '처리 중' },
  shop: { count_unit: '개' },
};

function editState(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claimEditMode: true,
    claimEditNumber: 'EX20260901-0001',
    claimEditSnapshot: {
      claim_number: 'EX20260901-0001',
      claim_type: 'exchange',
      claim_type_label: '교환',
      charge_method_label: '신청 시 결제',
    },
    claimEditOriginalCharge: 9000,
    claimEditChargeDelta: 0,
    claimEditChargeDeltaFormatted: '',
    claimEditSaving: false,
    claimEditError: null,
    claimType: 'exchange',
    claimApiBase: '/api/x',
    claimEditApiBase: '/api/modules/sirsoft-ecommerce/user/claims',
    claimReason: 'size_mismatch',
    claimReasonDetail: '',
    claimImages: [],
    claimRemoveImageIds: [],
    claimEstimate: null,
    claimEstimateFormatted: null,
    claimEstimateLoading: false,
    claimEstimateError: null,
    claimableSnapshot: {
      settings: {
        allowed_charge_methods: ['pay_on_request', 'pay_on_approval'],
        charge_payment_methods: ['card', 'point'],
        max_images: 5,
        image_max_size_kb: 2048,
      },
    },
    claimItems: [
      {
        order_option_id: 359,
        product_name: '티셔츠',
        product_option_name: '차콜/M',
        selected: true,
        quantity: 1,
        available_quantity: 1,
        locked_quantity: 0,
        unit_price_formatted: '29,000원',
        exchange_mode: 'change',
        new_product_option_id: 77,
        new_option_label: '블랙/L',
        price_difference: 3000,
        price_difference_formatted: '3,000원',
        mc_unit_price: [],
        validation_error: '',
      },
    ],
    ...overrides,
  };
}

async function renderPartial(file: string, state: Record<string, any>) {
  const utils = createLayoutTest(
    { components: [loadPartial(file)] },
    {
      initialState: { _local: state },
      locale: 'ko',
      translations: TRANSLATIONS,
      componentRegistry: setupRegistry(),
    }
  );

  const { container } = await utils.render();

  return { utils, container };
}

describe('클레임 신청서 수정 모드 — 렌더', () => {
  let active: { cleanup: () => void } | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  it('품목·수량은 잠긴다 (D13 — 승인 전에도 바꿀 수 없는 축)', async () => {
    const { utils, container } = await renderPartial('_apply_items.json', editState());
    active = utils;

    const checkboxes = Array.from(
      container.querySelectorAll('input[type="checkbox"]')
    ) as HTMLInputElement[];
    // 상품 체크박스는 있되 전부 잠겨 있어야 한다.
    expect(checkboxes.length).toBeGreaterThan(0);
    expect(checkboxes.every((el) => el.disabled)).toBe(true);

    const numbers = Array.from(
      container.querySelectorAll('input[type="number"]')
    ) as HTMLInputElement[];
    expect(numbers.every((el) => el.disabled)).toBe(true);
  });

  it('전체선택과 [교환상품 변경] 은 서지 않는다 (눌러도 아무 일이 없는 자리를 남기지 않는다)', async () => {
    const { utils, container } = await renderPartial('_apply_items.json', editState());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).not.toContain('전체선택');
    expect(text).not.toContain('교환상품 변경');
  });

  it('작성 화면에서는 같은 컨트롤이 살아 있다 (대조군 — 잠금이 수정 모드에만 걸린다)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_items.json',
      editState({ claimEditMode: false })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('전체선택');
    expect(text).toContain('교환상품 변경');

    const checkboxes = Array.from(
      container.querySelectorAll('input[type="checkbox"]')
    ) as HTMLInputElement[];
    expect(checkboxes.some((el) => !el.disabled)).toBe(true);
  });

  it('청구 2축은 서지 않고 접수 때 고른 방식이 읽기 전용으로 남는다', async () => {
    const { utils, container } = await renderPartial('_apply_summary.json', editState());
    active = utils;

    const text = container.textContent ?? '';

    // 라디오가 하나도 없다 — 있으면 구매자가 바꿀 수 있다고 읽는다.
    expect(container.querySelectorAll('input[type="radio"]')).toHaveLength(0);
    expect(text).not.toContain('결제 수단');
    // 값 자체는 남는다 — 무엇으로 청구되는지는 알아야 한다.
    expect(text).toContain('청구 방식');
    expect(text).toContain('신청 시 결제');
  });

  it('제출 버튼 대신 저장 버튼이 선다', async () => {
    const { utils, container } = await renderPartial('_apply_summary.json', editState());
    active = utils;

    const labels = Array.from(container.querySelectorAll('button')).map((el) => el.textContent);

    expect(labels).toContain(SAVE_LABEL);
    expect(labels).not.toContain(SUBMIT_LABEL);
  });

  it('사유를 지우면 저장 버튼이 잠긴다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      editState({ claimReason: '' })
    );
    active = utils;

    const save = Array.from(container.querySelectorAll('button')).find(
      (el) => el.textContent === SAVE_LABEL
    ) as HTMLButtonElement | undefined;

    expect(save).toBeDefined();
    expect(save?.disabled).toBe(true);
  });

  it('미리보기가 아직 없어도 저장은 열려 있다 (사유만 고치는 저장은 금액을 건드리지 않는다)', async () => {
    const { utils, container } = await renderPartial('_apply_summary.json', editState());
    active = utils;

    const save = Array.from(container.querySelectorAll('button')).find(
      (el) => el.textContent === SAVE_LABEL
    ) as HTMLButtonElement | undefined;

    expect(save?.disabled).toBe(false);
  });

  it('재발송지 변경 차액 — 더 청구될 때만 그 안내가 선다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      editState({ claimEditChargeDelta: 3000, claimEditChargeDeltaFormatted: '3,000원' })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain(DELTA_MORE);
    expect(text).toContain('3,000원');
    expect(text).not.toContain(DELTA_LESS);
  });

  it('재발송지 변경 차액 — 돌려받을 금액이면 환급 안내가 선다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      editState({ claimEditChargeDelta: -2000, claimEditChargeDeltaFormatted: '2,000원' })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain(DELTA_LESS);
    expect(text).not.toContain(DELTA_MORE);
  });

  it('차액이 0 이면 안내를 세우지 않는다 (0원 안내는 안내가 아니다)', async () => {
    const { utils, container } = await renderPartial('_apply_summary.json', editState());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).not.toContain(DELTA_MORE);
    expect(text).not.toContain(DELTA_LESS);
  });

  it('수정 거절 사유는 요약 카드에 남는다 (토스트로만 알리면 사라진다)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      editState({ claimEditError: '승인된 신청은 수정할 수 없습니다.' })
    );
    active = utils;

    expect(container.textContent ?? '').toContain('승인된 신청은 수정할 수 없습니다.');
  });

  it('환불 계좌는 수정 화면에서 편집할 수 있다 (2026-09-01 확정 — 신청 단계에서만)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_refund_account.json',
      editState({
        claimType: 'return',
        claimEditSnapshot: {
          claim_number: 'RT20260901-0001',
          claim_type: 'return',
          refund_bank_name: '국민은행',
          refund_bank_account: '****1234',
          refund_bank_holder: '홍*',
        },
        claimableSnapshot: { refund_account: { required: true } },
      })
    );
    active = utils;

    // 잠긴 칸이 하나도 없어야 한다 — 잠겨 있으면 확정(편집 가능)이 되돌아간 것이다.
    const inputs = Array.from(
      container.querySelectorAll('input, select')
    ) as HTMLInputElement[];

    expect(inputs.length).toBeGreaterThan(0);
    expect(inputs.some((el) => el.disabled)).toBe(false);
  });

  it('빈 칸으로 여는 이유를 화면이 말한다 (지금 기록된 계좌 + 비우면 유지)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_refund_account.json',
      editState({
        claimType: 'return',
        claimEditSnapshot: {
          claim_number: 'RT20260901-0001',
          claim_type: 'return',
          refund_bank_name: '국민은행',
          refund_bank_account: '****1234',
          refund_bank_holder: '홍*',
        },
        claimableSnapshot: { refund_account: { required: true } },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    // 안내가 없으면 구매자는 빈 칸을 "계좌가 등록되지 않았다" 로 읽고, 채우지 않고 저장한다.
    // 라벨은 `$t('...')` 형태라 이 하네스가 키를 그대로 남긴다(파일 상단 주석) — 잴 것은
    // 어느 키가 섰는가이며, 값(마스킹된 계좌)은 그 옆에 실제로 그려진다.
    expect(text).toContain('claim.edit.refund_account_current');
    expect(text).toContain('국민은행');
    expect(text).toContain('****1234');
    expect(text).toContain('계좌를 바꾸려면 아래에 다시 입력해 주세요.');
  });

  it('작성 화면에서는 그 안내가 서지 않는다 (대조군 — 고칠 대상이 없다)', async () => {
    const { utils, container } = await renderPartial(
      '_apply_refund_account.json',
      editState({
        claimEditMode: false,
        claimType: 'return',
        claimEditSnapshot: null,
        claimableSnapshot: { refund_account: { required: true } },
      })
    );
    active = utils;

    expect(container.textContent ?? '').not.toContain('claim.edit.refund_account_current');
  });
});
