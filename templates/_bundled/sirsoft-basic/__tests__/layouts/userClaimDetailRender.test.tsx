/**
 * @file userClaimDetailRender.test.tsx
 * @description 유저 클레임 **신청서 상세** 렌더 회귀 테스트 (#599 S13)
 *
 * 상세는 유형에 따라 서는 섹션이 다르다(§6-2). 환불·취소에는 회수 배송이 없어 배송 카드가
 * 통째로 서지 않고, 교환에만 교환 대상과 재발송이 있다. 그 분기가 어긋나도 응답은 200 이라
 * 화면에 빈 카드가 서거나 있어야 할 값이 조용히 비는 것이 유일한 증상이다.
 *
 * 잰다:
 *  ① 신청 상품 — 상품명·옵션·수량·금액, 교환 대상, 상품 단위 상태(헤더와 다를 때만)
 *  ② 배송 — 교환은 회수·재발송 두 항목이 한 카드, 환불은 카드 자체가 없다
 *  ③ 클레임 정보 — 원 주문 ↔ 이 클레임 대비 2행, 추가결제 내역, 환불 계좌(마스킹된 값)
 *  ④ 신청 사유 — 반려 사유·귀책·상세 사유·첨부 사진
 *
 * @scenario claim_type=exchange, claim_type=refund
 * @effects claim_detail_items_show_exchange_target,
 *   claim_detail_item_status_badge_only_when_different,
 *   claim_detail_shipping_card_absent_for_refund,
 *   claim_detail_contrast_axis_shows_both_sides,
 *   claim_detail_refund_account_reads_the_claim_row,
 *   claim_detail_reject_reason_leads_the_reason_card,
 *   claim_detail_progress_stepper_renders_server_steps
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

  // 실제 Icon 처럼 부가 속성(data-*)을 DOM 에 남긴다 — 진행 단계의 꺾쇠를 속성으로 센다.
  registry.register('basic', 'Icon', ({ className, name, ...rest }: any) =>
    React.createElement('i', { className, 'data-icon': name, ...rest })
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

/** 상세 partial 을 셸의 데이터소스 계약(claim / order)만 갖춘 레이아웃으로 감싼다. */
function detailLayout(files: string[]): any {
  return {
    data_sources: [
      {
        id: 'claim',
        type: 'api',
        endpoint: '/api/modules/sirsoft-ecommerce/user/claims/X',
        method: 'GET',
        auto_fetch: true,
      },
      {
        id: 'order',
        type: 'api',
        endpoint: '/api/modules/sirsoft-ecommerce/user/orders/X',
        method: 'GET',
        auto_fetch: true,
      },
    ],
    components: files.map(loadPartial),
  };
}

const TRANSLATIONS = {
  claim: {
    detail: {
      progress_title: '진행 단계',
      items_title: '신청 상품',
      no_items: '신청 상품이 없습니다.',
      item_refund_amount: '환불 예정 {{amount}}',
      exchange_target: '교환 대상',
      exchange_same_option: '동일 상품으로 교환',
      price_difference: '옵션 차액 {{amount}}',
      shipping_title: '배송',
      collection_title: '회수',
      collection_address: '회수지',
      collection_completed_at: '회수 완료',
      redelivery_title: '재발송',
      redelivery_changed_badge: '고객이 수정함',
      redelivered_at: '재발송 완료',
      tracking: '송장',
      tracking_pending: '등록 전',
      claim_info_title: '클레임 정보',
      compare_item: '항목',
      compare_original: '원 주문',
      compare_claim: '이 클레임',
      compare_shipping: '배송비',
      compare_charge: '청구',
      compare_refund: '환불',
      additional_payment_title: '추가 결제',
      charge_method: '청구 방식',
      payment_refunded: '환급 {{amount}}',
      outstanding_amount: '미수납 금액',
      pay_method: '결제 수단',
      pay_action: '결제하기',
      pay_in_progress: '결제 진행 중…',
      refund_title: '환불',
      refund_method: '환불 수단',
      refund_points_amount: '마일리지 환급',
      refund_account: '환불 계좌',
      refunded_at: '환불 완료',
      reason_title: '신청 사유',
      reason_label: '사유',
      fault_type: '귀책',
      reason_detail: '상세 사유',
      reject_reason: '반려 사유',
      images: '첨부 사진',
    },
  },
  shop: { count_unit: '개' },
};

function exchangeDetail(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claim_number: 'EX20260901-0001',
    claim_type: 'exchange',
    claim_type_label: '교환',
    status_label: '교환회수중',
    status_variant: 'info',
    currency_code: 'KRW',
    reason_label: '사이즈가 맞지 않아요',
    fault_type_label: '고객 귀책',
    reason_detail: '한 치수 큰 것으로 부탁드립니다.',
    reject_reason: null,
    claim_shipping_fee: 6000,
    claim_shipping_fee_formatted: '6,000원',
    total_charge_amount: 9000,
    total_charge_amount_formatted: '9,000원',
    mc_total_charge_amount: { USD: { formatted: '$6.75' } },
    charge_method_label: '신청 시 결제',
    outstanding_amount: 0,
    collection_carrier_name: 'CJ대한통운',
    collection_tracking_number: '651234567890',
    collection_tracking_url: 'https://example.test/track/651234567890',
    collection_completed_at_formatted: '2026-09-02 14:00',
    return_address_snapshot: { zipcode: '06236', address1: '서울시 강남구', address2: '1층' },
    redelivery_address_snapshot: {
      recipient_name: '홍길동',
      phone: '010-1234-5678',
      zipcode: '06236',
      address1: '서울시 강남구',
      address2: '2층',
      changed_by_user: true,
    },
    redelivery_tracking_number: null,
    redelivery_tracking_url: null,
    payments: [
      {
        payment_number: 'CP20260901-0001',
        payment_method_label: '신용카드',
        total_amount_formatted: '9,000원',
        payment_status_label: '결제완료',
        payment_status_variant: 'success',
        paid_at_formatted: '2026-09-01 10:05',
        refunded_amount: 0,
      },
    ],
    items: [
      {
        product_name: '티셔츠',
        product_option_name: '차콜/M',
        quantity: 1,
        unit_price_formatted: '29,000원',
        subtotal_amount_formatted: '29,000원',
        is_option_change: true,
        new_option_label: '블랙/L',
        price_difference: 3000,
        price_difference_formatted: '3,000원',
        mc_price_difference: { USD: { formatted: '$2.25' } },
        status_label: '교환회수중',
        status_variant: 'info',
      },
    ],
    images: [],
    ...overrides,
  };
}

function refundDetail(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claim_number: 'RF20260901-0003',
    claim_type: 'refund',
    claim_type_label: '환불',
    status_label: '환불완료',
    status_variant: 'success',
    currency_code: 'KRW',
    reason_label: '주문 실수',
    reason_detail: null,
    reject_reason: null,
    refund_shipping_amount_formatted: '3,000원',
    refund_total_formatted: '39,000원',
    refund_points_amount: 1000,
    refund_points_amount_formatted: '1,000원',
    refund_method_label: '계좌 환불',
    refund_bank_code: '004',
    refund_bank_name: '국민은행',
    refund_bank_account: '****7890',
    refund_bank_holder: '홍*동',
    refunded_at_formatted: '2026-09-03 09:00',
    items: [
      {
        product_name: '니트',
        product_option_name: '아이보리/L',
        quantity: 1,
        unit_price_formatted: '39,000원',
        subtotal_amount_formatted: '39,000원',
        refund_amount: 39000,
        refund_amount_formatted: '39,000원',
      },
    ],
    images: [
      { id: 9, original_name: 'photo.jpg', download_url: 'https://example.test/signed/photo.jpg' },
    ],
    ...overrides,
  };
}

const ORDER = {
  order_number: 'O20260812-0000123',
  total_shipping_amount_formatted: '2,500원',
  total_amount_formatted: '81,000원',
};

async function renderDetail(
  files: string[],
  claim: Record<string, any>,
  envelope: Record<string, any> = {}
) {
  const utils = createLayoutTest(detailLayout(files), {
    initialState: { _local: {} },
    locale: 'ko',
    translations: TRANSLATIONS,
    componentRegistry: setupRegistry(),
  });

  // 상세 봉투는 `claim` 옆에 `progress_steps` 를 싣는다 (관리자 상세와 같은 배열).
  utils.mockApi('claim', { response: { data: { claim, ...envelope } } });
  utils.mockApi('order', { response: { data: ORDER } });

  const { container } = await utils.render();

  return { utils, container };
}

describe('클레임 신청서 상세 — 렌더', () => {
  let active: { cleanup: () => void } | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  const PROGRESS_STEPS = [
    { value: 'requested', label: '교환신청', state: 'done', icon: 'status-request', variant: 'secondary' },
    { value: 'approved', label: '교환승인', state: 'done', icon: 'status-approved', variant: 'info' },
    { value: 'collection_ready', label: '교환회수준비중', state: 'done', icon: 'status-pickup-ready', variant: 'primary' },
    { value: 'collecting', label: '교환회수중', state: 'current', icon: 'status-pickup', variant: 'primary' },
    { value: 'collected', label: '교환회수도착', state: 'upcoming', icon: 'status-received', variant: 'primary' },
    { value: 'completed', label: '교환완료', state: 'upcoming', icon: 'status-completed', variant: 'success' },
  ];

  // @scenario claim_type=exchange, screen=detail, entry=none
  // @effects claim_detail_progress_stepper_renders_server_steps
  it('진행 단계 — 서버가 준 칸을 그리고 현재 칸이 하나이며 꺾쇠는 칸 사이에만 선다', async () => {
    const { utils, container } = await renderDetail(['_detail_progress.json'], exchangeDetail(), {
      progress_steps: PROGRESS_STEPS,
    });
    active = utils;

    expect(container.textContent).toContain('진행 단계');

    const cells = [...container.querySelectorAll('[id^="claim_progress_step_"]')];
    expect(cells).toHaveLength(PROGRESS_STEPS.length);
    expect(cells.map((el) => el.textContent?.trim())).toEqual(PROGRESS_STEPS.map((s) => s.label));
    expect(cells.filter((el) => el.getAttribute('data-step-state') === 'current')).toHaveLength(1);
    expect(cells.map((el) => el.querySelector('.rounded-full [data-icon]')?.getAttribute('data-icon'))).toEqual(
      PROGRESS_STEPS.map((s) => s.icon)
    );
    expect(container.querySelectorAll('[data-step-chevron="pc"]')).toHaveLength(PROGRESS_STEPS.length - 1);
    expect(container.querySelector('#claim_progress_steps')?.className).toContain('auto-cols-fr');
    expect(container.querySelector('#claim_progress_steps')?.className).not.toContain('overflow-x-auto');
  });

  // @scenario claim_type=exchange, screen=detail, entry=none
  // @effects claim_detail_progress_stepper_renders_server_steps
  it('진행 단계 — 배열이 비면 카드 자체가 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_progress.json'], exchangeDetail(), {
      progress_steps: [],
    });
    active = utils;

    expect(container.textContent).not.toContain('진행 단계');
    expect(container.querySelector('#claim_progress_steps')).toBeNull();
  });

  it('신청 상품 — 상품·옵션·수량·금액과 교환 대상이 나온다', async () => {
    const { utils, container } = await renderDetail(['_detail_items.json'], exchangeDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('티셔츠');
    expect(text).toContain('차콜/M');
    expect(text).toContain('29,000원 × 1개');
    expect(text).toContain('교환 대상');
    expect(text).toContain('블랙/L');
    expect(text).toContain('옵션 차액 3,000원');
    expect(text).toContain('$2.25');
  });

  it('상품 단위 상태 배지는 헤더와 다를 때만 선다', async () => {
    // 같을 때 — 배지를 세우면 같은 값이 두 번 보여 어느 쪽이 이 상품의 상태인지 흐려진다.
    const { utils: u1, container: same } = await renderDetail(
      ['_detail_items.json'],
      exchangeDetail()
    );
    active = u1;
    expect((same.textContent ?? '').match(/교환회수중/g) ?? []).toHaveLength(0);

    active.cleanup();

    const claim = exchangeDetail();
    claim.items[0].status_label = '교환반려';
    const { utils: u2, container: diff } = await renderDetail(['_detail_items.json'], claim);
    active = u2;
    expect(diff.textContent ?? '').toContain('교환반려');
  });

  it('환불 유형에는 교환 대상 줄이 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_items.json'], refundDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('니트');
    expect(text).not.toContain('교환 대상');
    // 돌려받는 금액은 상품 줄이 든다.
    expect(text).toContain('환불 예정 39,000원');
  });

  it('배송 — 교환은 회수·재발송이 한 카드에 서고 수정 뱃지가 붙는다', async () => {
    const { utils, container } = await renderDetail(['_detail_shipping.json'], exchangeDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('배송');
    expect(text).toContain('회수');
    expect(text).toContain('CJ대한통운 651234567890');
    expect(text).toContain('회수지');
    expect(text).toContain('재발송');
    // 구매자가 재발송지를 고친 사실은 값이 같아도 남는다(D14).
    expect(text).toContain('고객이 수정함');
    // 송장이 아직 없는 재발송은 '등록 전' 이다 — 빈 칸으로 두면 없는 것인지 못 읽은 것인지
    // 모른다. 그 자리는 `$t('...')` 형태라 하네스에서는 키로 남는다(실 화면에서는 치환된다).
    expect(text).toContain('claim.detail.tracking_pending');
    expect(container.querySelector('a[href="https://example.test/track/651234567890"]')).not.toBeNull();
  });

  it('배송 — 환불 유형에는 카드 자체가 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_shipping.json'], refundDetail());
    active = utils;

    expect((container.textContent ?? '').trim()).toBe('');
  });

  it('클레임 정보 — 원 주문과 이 클레임을 나란히 그린다 (대비 축 유지)', async () => {
    const { utils, container } = await renderDetail(['_detail_claim_info.json'], exchangeDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('원 주문');
    expect(text).toContain('이 클레임');
    // 왼쪽 = 원 주문의 값, 오른쪽 = 이 클레임의 값. 둘 다 서버가 포맷한 문자열 그대로다.
    expect(text).toContain('2,500원');
    expect(text).toContain('6,000원');
    expect(text).toContain('81,000원');
    expect(text).toContain('+9,000원');
    expect(text).toContain('$6.75');
  });

  it('클레임 정보 — 추가결제 내역과 청구 방식이 나온다', async () => {
    const { utils, container } = await renderDetail(['_detail_claim_info.json'], exchangeDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('추가 결제');
    expect(text).toContain('신용카드');
    expect(text).toContain('CP20260901-0001');
    expect(text).toContain('결제완료');
    expect(text).toContain('신청 시 결제');
    // 다 낸 신청서에는 미수납 줄을 세우지 않는다.
    expect(text).not.toContain('미수납 금액');
  });

  it('클레임 정보 — 미수납이 남으면 그 줄이 선다', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({ outstanding_amount: 3000, outstanding_amount_formatted: '3,000원' })
    );
    active = utils;

    expect(container.textContent ?? '').toContain('미수납 금액');
  });

  it('클레임 정보 — 미수납만 있고 결제 대기 건이 없으면 결제 버튼을 세우지 않는다', async () => {
    // 낼 것이 없는데 버튼만 있으면 눌러도 거절만 돌아온다 — 승인 시점 결제는 운영자가
    // 승인해야 서버가 결제 대기 건(CP)을 만든다.
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({ outstanding_amount: 3000, outstanding_amount_formatted: '3,000원', pending_payment: null })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('미수납 금액');
    // 형제 케이스와 **같은 토큰**으로 잰다 — 이 하네스는 표현식 안의 `$t(...)` 를 해석하지
    // 않으므로 해석된 문구('결제하기')로 재면 게이트를 지워도 통과하는 공허한 단언이 된다.
    expect(text).not.toContain('claim.detail.pay_action');
    expect(
      Array.from(container.querySelectorAll('button')).filter((b) =>
        (b.textContent ?? '').includes('claim.detail.pay_action')
      )
    ).toHaveLength(0);
  });

  it('클레임 정보 — 결제 대기 건이 있으면 결제 진입점이 선다 (없으면 낼 방법이 없다)', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({
        outstanding_amount: 6000,
        outstanding_amount_formatted: '6,000원',
        charge_method_label: '승인 시점 결제',
        pending_payment: {
          payment_number: 'CP20260901-0002',
          payment_method_label: '신용카드',
          total_amount_formatted: '6,000원',
        },
      })
    );
    active = utils;

    const text = container.textContent ?? '';

    // 수단은 서버가 결제 대기 건에 확정해 둔 값을 **표시만** 한다.
    expect(text).toContain('결제 수단');
    expect(text).toContain('신용카드');

    // 라벨은 표현식 안의 `$t(...)` 형태다(진행 중 표시와 한 노드를 쓴다). 이 하네스는 그
    // 형태를 해석하지 않아 키가 그대로 남는다 — 형제 항목(`compare_charge`)도 같다.
    // 그래서 잰다: 결제 버튼 노드가 그 자리에 서고 라벨 자리가 결제 라벨 키를 가리키는가.
    // 문구가 실제로 해석되는지는 i18n 대칭 게이트와 브라우저 실측이 맡는다.
    const button = Array.from(container.querySelectorAll('button')).find(
      (b) => (b.textContent ?? '').includes('claim.detail.pay_action')
    );

    expect(button).toBeTruthy();
    expect(button?.getAttribute('disabled')).toBeNull();
  });

  it('클레임 정보 — 환불 유형은 환불 수단·마일리지 환급·계좌를 그린다', async () => {
    const { utils, container } = await renderDetail(['_detail_claim_info.json'], refundDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('환불');
    expect(text).toContain('계좌 환불');
    expect(text).toContain('마일리지 환급');
    expect(text).toContain('1,000원');
    // 계좌는 이 **신청서 행**의 값이며 서버가 마스킹해 내려준다 — 화면이 원문을 요구하지 않는다.
    expect(text).toContain('국민은행 ****7890 홍*동');
    expect(text).toContain('−39,000원');
    // 청구 블록은 환불 유형에 서지 않는다.
    expect(text).not.toContain('추가 결제');
  });

  it('신청 사유 — 사유·귀책·상세 사유가 나오고 첨부는 서명 URL 을 그대로 쓴다', async () => {
    const { utils, container } = await renderDetail(['_detail_reason.json'], refundDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('신청 사유');
    expect(text).toContain('주문 실수');
    expect(text).toContain('첨부 사진');
    expect(
      container.querySelector('img[src="https://example.test/signed/photo.jpg"]')
    ).not.toBeNull();
  });

  it('신청 사유 — 반려 사유가 있으면 카드 맨 위에 선다', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_reason.json'],
      exchangeDetail({ reject_reason: '사용 흔적이 있어 교환이 어렵습니다.' })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('반려 사유');
    expect(text).toContain('사용 흔적이 있어 교환이 어렵습니다.');
    // 반려 사유가 사유 라벨보다 앞에 나온다 — 구매자가 가장 먼저 읽어야 할 값이다.
    expect(text.indexOf('반려 사유')).toBeLessThan(text.indexOf('사이즈가 맞지 않아요'));
  });

  it('교환 대상 — 차액이 0 이면 보조 통화 줄도 서지 않는다', async () => {
    // 기준 통화 줄(옵션 차액)은 차액이 0 이면 서지 않는다. 보조 통화 iteration 에 같은 게이트가
    // 없으면 "동일 상품으로 교환" 밑에 라벨 없는 `$0.00 ¥0` 만 남는다 — 서버는 교환 행이면
    // 차액 0 에도 통화별 항목을 싣기 때문이다(2026-09-01 3차 계획 검증).
    const claim = exchangeDetail();
    claim.items[0].is_option_change = false;
    claim.items[0].new_option_label = null;
    claim.items[0].price_difference = 0;
    claim.items[0].price_difference_formatted = '0원';
    claim.items[0].mc_price_difference = {
      USD: { amount: 0, formatted: '$0.00' },
      JPY: { amount: 0, formatted: '¥0' },
    };

    const { utils, container } = await renderDetail(['_detail_items.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('동일 상품으로 교환');
    expect(text).not.toContain('옵션 차액');
    expect(text).not.toContain('$0.00');
    expect(text).not.toContain('¥0');
  });

  it('교환 대상 — 차액이 있으면 보조 통화 줄이 선다 (게이트가 값을 삼키지 않는다)', async () => {
    const { utils, container } = await renderDetail(['_detail_items.json'], exchangeDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('옵션 차액 3,000원');
    expect(text).toContain('$2.25');
  });

  it('대비 행 — 환불의 보조 통화는 현금+마일리지 합계(mc_refund_total)를 읽는다', async () => {
    // 기준 줄이 `refund_total_formatted`(39,000원 = 현금 38,000 + 마일리지 1,000) 이므로
    // 보조 통화도 그 합계여야 한다. 현금분만인 `mc_refund_amount` 를 읽으면 두 줄이 서로
    // 다른 금액이 되는데 둘 다 정상 서식이라 어긋난 흔적이 남지 않는다.
    const claim = refundDetail({
      mc_refund_amount: { USD: { amount: 28.5, formatted: '$28.50' } },
      mc_refund_total: { USD: { amount: 29.25, formatted: '$29.25' } },
    });

    const { utils, container } = await renderDetail(['_detail_claim_info.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('39,000원');
    expect(text).toContain('$29.25');
    expect(text).not.toContain('$28.50');
  });

  it('대비 행 — 취소도 보조 통화를 병기한다 (합계 키가 없으면 현금분으로 떨어진다)', async () => {
    const claim = refundDetail({
      claim_type: 'cancel',
      claim_type_label: '취소',
      claim_number: 'CN20260901-0004',
      refund_total_formatted: undefined,
      refund_amount_formatted: '18,000원',
      mc_refund_amount: { USD: { amount: 13.5, formatted: '$13.50' } },
    });

    const { utils, container } = await renderDetail(['_detail_claim_info.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('18,000원');
    expect(text).toContain('$13.50');
  });

  it('대비 행 — 배송비도 보조 통화를 병기한다 (교환·반품은 mc_claim_shipping_fee)', async () => {
    // 대비 행 2(청구·환불)는 병기하는데 대비 행 1(배송비)만 기준 통화로 남아 있었다
    // — 같은 카드의 두 줄이 다통화 상점에서 서로 다른 정보를 그렸다(2026-09-01 4차).
    const claim = exchangeDetail({
      mc_claim_shipping_fee: { USD: { formatted: '$4.50' }, JPY: { formatted: '¥810' } },
    });

    const { utils, container } = await renderDetail(['_detail_claim_info.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('6,000원');
    expect(text).toContain('$4.50');
    expect(text).toContain('¥810');
  });

  it('대비 행 — 환불의 배송비 보조 통화는 mc_refund_shipping_amount 를 읽는다', async () => {
    // 환불 분기의 기준 줄은 `refund_shipping_amount_formatted` 다. 컬럼은 이미 있었고 리소스가
    // 노출하지 않아, 그 자리에서 환불 신청서만 외화가 사라졌다.
    const claim = refundDetail({
      mc_refund_shipping_amount: { USD: { formatted: '$2.55' } },
      mc_claim_shipping_fee: { USD: { formatted: '$4.50' } },
    });

    const { utils, container } = await renderDetail(['_detail_claim_info.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('3,000원');
    expect(text).toContain('$2.55');
    // 교환·반품 필드를 읽으면 배송비 줄이 다른 금액이 된다.
    expect(text).not.toContain('$4.50');
  });

  it('추가결제 내역 — 결제 금액에도 보조 통화가 병기된다', async () => {
    // `mc_amount` 는 결제 수단과 무관하게 청구액 전체로 만들어지므로 `total_amount_formatted`
    // 와 같은 값이다. 총 청구액의 보조 통화($6.75)와 구별되는 값을 넣어, 결제 행 **자신이**
    // 그리지 않으면 통과할 수 없게 한다.
    const claim = exchangeDetail();
    claim.payments[0].mc_amount = { USD: { formatted: '$6.90' } };

    const { utils, container } = await renderDetail(['_detail_claim_info.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('9,000원');
    expect(text).toContain('$6.90');
  });

  it('신청 상품 — 소계에도 보조 통화가 병기된다 (목록 카드와 같은 정보다)', async () => {
    // 목록 카드의 같은 상품 행은 소계의 보조 통화를 그린다(3차 ⓕ). 상세만 빠져 있어 같은
    // 상품의 소계가 화면에 따라 외화가 있고 없고가 갈렸다.
    const claim = exchangeDetail();
    claim.items[0].mc_subtotal_amount = { USD: { formatted: '$21.75' }, JPY: { formatted: '¥3,915' } };

    const { utils, container } = await renderDetail(['_detail_items.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('29,000원');
    expect(text).toContain('$21.75');
    expect(text).toContain('¥3,915');
  });

  it('교환 대상 — 이 교환에 얹은 추가옵션이 이름으로 나온다', async () => {
    // 신청 시점에 이름과 추가금이 동결된다. 예전에는 선택 ID 만 남아 이 자리에 아무것도
    // 그릴 수 없었고, 구매자는 자기가 무엇을 골랐는지 확인할 수 없었다(2026-09-01 4차 실측).
    const claim = exchangeDetail();
    claim.items[0].new_additional_options = [
      { name: '선물 포장', price_adjustment: 3000, price_adjustment_formatted: '3,000원', custom_text: '' },
      { name: '각인', price_adjustment: 0, price_adjustment_formatted: '0원', custom_text: 'G7' },
    ];

    const { utils, container } = await renderDetail(['_detail_items.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('선물 포장');
    // 직접입력 텍스트는 그 선택지 옆에 붙는다.
    expect(text).toContain('각인 (G7)');
  });

  it('교환 대상 — 추가옵션이 없으면 그 줄이 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_items.json'], exchangeDetail());
    active = utils;

    expect(container.textContent ?? '').not.toContain('선물 포장');

    // 이름이 없는 것만으로는 부족하다 — 게이트가 없으면 목록이 비어도 접두사 `+` 만 남은
    // 줄이 선다(3차 ⓖ 의 `$0.00` 과 같은 형태: 구매자는 그 `+` 가 무엇의 `+` 인지 알 수 없다).
    const strays = Array.from(container.querySelectorAll('p')).filter(
      (el) => (el.textContent ?? '').trim() === '+'
    );
    expect(strays).toHaveLength(0);
  });

  it('교환 대상 — 동일상품 교환이라도 추가옵션은 나온다', async () => {
    // 옵션을 바꾸지 않아도 추가옵션은 새로 고를 수 있다 — 그 줄의 게이트는 옵션 변경 여부가
    // 아니라 추가옵션의 유무다.
    const claim = exchangeDetail();
    claim.items[0].is_option_change = false;
    claim.items[0].new_option_label = null;
    claim.items[0].price_difference = 0;
    claim.items[0].new_additional_options = [
      { name: '선물 포장', price_adjustment: 3000, price_adjustment_formatted: '3,000원', custom_text: '' },
    ];

    const { utils, container } = await renderDetail(['_detail_items.json'], claim);
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('동일 상품으로 교환');
    expect(text).toContain('선물 포장');
  });

  it('신청 사유 — 첨부가 없으면 그 블록이 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_reason.json'], exchangeDetail());
    active = utils;

    expect(container.textContent ?? '').not.toContain('첨부 사진');
  });
});
