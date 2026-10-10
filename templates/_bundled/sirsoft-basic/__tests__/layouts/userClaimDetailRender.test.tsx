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
 *   claim_detail_progress_stepper_renders_server_steps,
 *   claim_detail_shipping_groups_by_waybill,
 *   claim_detail_item_row_shows_its_waybill,
 *   claim_detail_item_reject_reason_on_the_row,
 *   claim_detail_advance_exchange_notice_and_overdue,
 *   claim_detail_unreturned_items_marked,
 *   claim_detail_admin_requested_notice,
 *   claim_detail_redelivery_shows_the_international_city_and_state
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
      collection_completed_at: '전체 회수 완료',
      redelivery_title: '재발송',
      redelivery_changed_badge: '고객이 수정함',
      redelivered_at: '전체 재발송 완료',
      tracking_pending: '등록 전',
      tracking_no_tracking: '송장 없이 도착',
      tracking_arrived: '도착',
      tracking_in_transit: '배송 중',
      advance_exchange_notice: '선교환 — 새 상품을 먼저 보내드립니다. 기존 상품은 {{date}}까지 보내 주세요.',
      advance_exchange_notice_no_due: '선교환 — 새 상품을 먼저 보내드립니다.',
      collection_overdue: '반송 기한 {{days}}일 지남',
      unreturned_badge: '미회수',
      collection_address_n: '회수지 {{n}}',
      item_collection_tracking: '회수 송장',
      item_redelivery_tracking: '재발송 송장',
      item_collection_state: '기존 상품 {{state}}',
      item_reject_reason: '반려 사유: {{reason}}',
      collection_lane_title: '기존 상품 회수',
      collection_lane_counts: '{{total}}개 중 {{collected}}개 도착',
      collection_lane_due: '반송 기한 {{date}}',
      admin_requested_notice: '판매자가 접수한 신청은 고객센터로 문의해 주세요.',
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
      // 접수 시점 주문금액 비교표(#599 후속) — 라벨은 언어팩 원본을 그대로 쓴다(키 누락이 곧 실패).
      comparison: JSON.parse(
        fs.readFileSync(path.resolve(__dirname, '../../lang/partial/ko/claim.json'), 'utf-8')
      ).detail.comparison,
    },
  },
  shop: { count_unit: '개' },
};

/** 접수 시점에 동결된 비교 스냅샷(전/후) — 값은 응답에만 있고 다른 줄에서 만들 수 없는 것으로 고른다. */
const CALCULATION_COMPARISON = {
  original: {
    item_count: 2, total_list_price_amount: 58000, subtotal_amount: 58000, total_product_coupon_discount_amount: 0,
    total_order_coupon_discount_amount: 0, total_code_discount_amount: 0, shipping_discount_amount: 0,
    base_shipping_amount: 0, extra_shipping_amount: 0, total_points_used_amount: 0, total_tax_amount: 58000,
    total_vat_amount: 5272, total_paid_amount: 58000, total_earned_points_amount: 580, base_currency: 'KRW',
    formatted: {
      total_list_price_amount: '58,000원', subtotal_amount: '58,000원', total_product_coupon_discount_amount: '0원',
      total_order_coupon_discount_amount: '0원', total_code_discount_amount: '0원', shipping_discount_amount: '0원',
      base_shipping_amount: '0원', extra_shipping_amount: '0원', total_points_used_amount: '0원',
      total_tax_amount: '58,000원', total_vat_amount: '5,272원', total_paid_amount: '58,000원',
    },
  },
  recalculated: {
    item_count: 1, total_list_price_amount: 29000, subtotal_amount: 29000, total_product_coupon_discount_amount: 0,
    total_order_coupon_discount_amount: 0, total_code_discount_amount: 0, shipping_discount_amount: 0,
    base_shipping_amount: 0, extra_shipping_amount: 0, total_points_used_amount: 0, total_tax_amount: 29000,
    total_vat_amount: 2636, total_paid_amount: 29000, total_earned_points_amount: 290, base_currency: 'KRW',
    formatted: {
      total_list_price_amount: '29,000원', subtotal_amount: '29,000원', total_product_coupon_discount_amount: '0원',
      total_order_coupon_discount_amount: '0원', total_code_discount_amount: '0원', shipping_discount_amount: '0원',
      base_shipping_amount: '0원', extra_shipping_amount: '0원', total_points_used_amount: '0원',
      total_tax_amount: '29,000원', total_vat_amount: '2,636원', total_paid_amount: '29,000원',
    },
  },
  mc_original: { mc_total_paid_amount_formatted: { KRW: '58,000원', USD: '$49.30' } },
  mc_recalculated: { mc_total_paid_amount_formatted: { KRW: '29,000원', USD: '$24.65' } },
  substituted_items: [],
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
    // 송장은 상품 행마다 있다(#599) — 서버가 같은 운송장을 가진 행을 묶어 `tracking_groups` 로 싣는다.
    tracking_groups: {
      collection: [
        {
          key: 'collection:1|651234567890',
          direction: 'collection',
          no_tracking: false,
          carrier_name: 'CJ대한통운',
          tracking_number: '651234567890',
          tracking_url: 'https://example.test/track/651234567890',
          items: [{ claim_item_id: 1, product_name: '티셔츠', option_name: '차콜/M', quantity: 1, arrived: true }],
          all_arrived: true,
        },
      ],
      redelivery: [],
    },
    collection_completed_at_formatted: '2026-09-02 14:00',
    return_address_snapshot: { zipcode: '06236', address1: '서울시 강남구', address2: '1층' },
    return_addresses: [
      { address_snapshot: { zipcode: '06236', address1: '서울시 강남구', address2: '1층' }, claim_item_ids: [1] },
    ],
    redelivery_address_snapshot: {
      recipient_name: '홍길동',
      phone: '010-1234-5678',
      zipcode: '06236',
      address1: '서울시 강남구',
      address2: '2층',
      changed_by_user: true,
    },
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
        id: 1,
        product_name: '티셔츠',
        product_option_name: '차콜/M',
        quantity: 1,
        collection_carrier_name: 'CJ대한통운',
        collection_tracking_number: '651234567890',
        collection_tracking_url: 'https://example.test/track/651234567890',
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_item_status_badge_only_when_different
   */
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

  /**
   * @scenario claim_type=refund, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
  it('환불 유형에는 교환 대상 줄이 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_items.json'], refundDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('니트');
    expect(text).not.toContain('교환 대상');
    // 돌려받는 금액은 상품 줄이 든다.
    expect(text).toContain('환불 예정 39,000원');
  });

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_shipping_card_absent_for_refund
   */
  it('배송 — 교환은 회수·재발송이 한 카드에 서고 수정 뱃지가 붙는다', async () => {
    const { utils, container } = await renderDetail(['_detail_shipping.json'], exchangeDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('배송');
    expect(text).toContain('회수');
    expect(text).toContain('CJ대한통운 651234567890');
    // 운송장 묶음 안에 담긴 상품 × 수량과 도착 여부를 적는다.
    expect(text).toContain('티셔츠 (차콜/M) × 1');
    expect(text).toContain('도착');
    expect(text).toContain('회수지');
    expect(text).toContain('재발송');
    // 구매자가 재발송지를 고친 사실은 값이 같아도 남는다(D14).
    expect(text).toContain('고객이 수정함');
    // 송장이 아직 없는 재발송은 '등록 전' 이다 — 빈 칸으로 두면 없는 것인지 못 읽은 것인지 모른다.
    expect(text).toContain('등록 전');
    expect(container.querySelector('a[href="https://example.test/track/651234567890"]')).not.toBeNull();
  });

  /**
   * 해외 주문의 재발송지는 도시·주를 따로 담는다 — 카드가 우편번호·주소 두 줄만 읽으면 운영자와
   * 구매자가 도시·주가 빠진 주소를 보게 된다(S2-D8). 국내 재발송지는 두 칸이 비어 종전 모양 그대로다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_redelivery_shows_the_international_city_and_state
   */
  it('배송 — 해외 재발송지는 도시·주까지 보이고, 국내 재발송지에는 자리가 끼지 않는다', async () => {
    const intl = await renderDetail(['_detail_shipping.json'], exchangeDetail({
      redelivery_address_snapshot: {
        recipient_name: 'Jane Doe', phone: '+1-212-555-0101', zipcode: '10118',
        address1: '350 5th Ave', address2: 'Suite 2', city: 'New York', state: 'NY',
        country_code: 'US', changed_by_user: false,
      },
    }));
    active = intl.utils;
    expect(intl.container.textContent ?? '').toContain('(10118) 350 5th Ave Suite 2, New York, NY');
    intl.utils.cleanup();

    const domestic = await renderDetail(['_detail_shipping.json'], exchangeDetail());
    active = domestic.utils;
    const text = domestic.container.textContent ?? '';
    expect(text).toContain('(06236) 서울시 강남구 2층');
    expect(text).not.toContain('2층,');
  });

  /**
   * @scenario claim_type=refund, screen=detail, entry=none
   * @effects claim_detail_shipping_card_absent_for_refund
   */
  it('배송 — 환불 유형에는 카드 자체가 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_shipping.json'], refundDetail());
    active = utils;

    expect((container.textContent ?? '').trim()).toBe('');
  });

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_contrast_axis_shows_both_sides
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_contrast_axis_shows_both_sides
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_outstanding_payment_has_an_entry_point
   */
  it('클레임 정보 — 미수납이 남으면 그 줄이 선다', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({ outstanding_amount: 3000, outstanding_amount_formatted: '3,000원' })
    );
    active = utils;

    expect(container.textContent ?? '').toContain('미수납 금액');
  });

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_outstanding_payment_has_an_entry_point
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_outstanding_payment_has_an_entry_point
   */
  it('클레임 정보 — 결제 대기 건이 있으면 결제 진입점이 선다 (없으면 낼 방법이 없다)', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({
        outstanding_amount: 6000,
        outstanding_amount_formatted: '6,000원',
        charge_method_label: '승인 시점 결제',
        // 결제 권한(7.1.0 G31) — 신청서 응답은 `can_pay` 를 늘 싣고 [결제하기]는 `can_pay !== true` 면 잠긴다.
        can_pay: true,
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

  /**
   * 결제 대기 건의 수단은 접수 때 확정되지만 그 뒤에 낼 수 없게 될 수 있다 — 마일리지 잔액이
   * 모자라거나, 운영자가 그 수단을 `claim.charge_payment_methods` 에서 내리거나. 두 경우 모두
   * 서버가 거절하는데 화면에 고를 자리가 없으면 사용자는 「미수납 ○○원 + 결제하기」를 보면서
   * 누를 때마다 같은 거절을 받고, 빠져나갈 길이 신청서 철회뿐이다.
   *
   * 회귀 배경 (#599 3차 실측 F-1·F-2, 2026-09-22): 카탈로그에서 `point` 를 내리자 이미 `point` 로
   * 만들어진 대기 건이 계속 「포인트결제」를 안내하며 422 만 돌려줬다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_pending_payment_method_can_be_rechosen
   */
  it('클레임 정보 — 낼 수 있는 수단이 둘 이상이면 다시 고를 수 있다', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({
        outstanding_amount: 4000,
        outstanding_amount_formatted: '4,000원',
        available_payment_methods: ['card', 'point'],
        pending_payment: {
          payment_number: 'CP20260901-0003',
          payment_method: 'point',
          payment_method_label: '포인트결제',
          total_amount_formatted: '4,000원',
        },
      })
    );
    active = utils;

    const radios = Array.from(
      container.querySelectorAll<HTMLInputElement>('input[type=radio][name=claimPayMethod]')
    );

    expect(radios.map((r) => r.value)).toEqual(['card', 'point']);
    // 손대기 전에는 서버가 확정해 둔 수단이 골라져 있어야 한다 — 화면이 안내하는 값과
    // 실제로 나가는 값이 어긋나면 고른 적 없는 수단으로 결제가 시작된다.
    expect(radios.find((r) => r.value === 'point')?.checked).toBe(true);
  });

  /**
   * 카드·마일리지가 아닌 수단은 서버가 붙인 표시명으로 보인다 — 값만 보고 `point` 가 아니면
   * 모두 「카드·간편결제」로 그리면 계좌이체를 고르면서 카드로 낸다고 읽는다.
   *
   * 회귀 배경 (S1-D14, 2026-10-07 실측): 허용 수단을 `['card','bank']` 로 두자 두 라디오가
   * 모두 「카드·간편결제」였다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_payment_method_choices_carry_their_own_labels
   */
  it('클레임 정보 — 카드·마일리지 밖의 수단은 서버 표시명으로 보인다', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({
        outstanding_amount: 4000,
        outstanding_amount_formatted: '4,000원',
        available_payment_methods: ['card', 'bank'],
        available_payment_method_labels: { card: '신용카드', bank: '계좌이체' },
        pending_payment: {
          payment_number: 'CP20260901-0005',
          payment_method: 'card',
          payment_method_label: '신용카드',
          total_amount_formatted: '4,000원',
        },
      })
    );
    active = utils;

    const labelOf = (value: string) =>
      container
        .querySelector<HTMLInputElement>(`input[type=radio][name=claimPayMethod][value=${value}]`)
        ?.closest('label')
        ?.textContent?.trim();

    expect(labelOf('bank')).toBe('계좌이체');
    // 카드는 종전 안내 문구(간편결제까지 묶어 부르는 이름)를 지킨다 — 이 환경은 식 안의
    // `$t()` 를 키 그대로 돌려주므로 키로 잰다.
    expect(labelOf('card')).toBe('claim.apply.payment_method_card');
  });

  /**
   * 선택지가 하나뿐이면 고르는 시늉만 시키지 않는다 — 종전 표시 전용 동작을 지킨다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_pending_payment_method_can_be_rechosen
   */
  it('클레임 정보 — 낼 수 있는 수단이 하나뿐이면 표시만 한다', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_claim_info.json'],
      exchangeDetail({
        outstanding_amount: 4000,
        outstanding_amount_formatted: '4,000원',
        available_payment_methods: ['card'],
        pending_payment: {
          payment_number: 'CP20260901-0004',
          payment_method: 'card',
          payment_method_label: '신용카드',
          total_amount_formatted: '4,000원',
        },
      })
    );
    active = utils;

    expect(
      container.querySelectorAll('input[type=radio][name=claimPayMethod]')
    ).toHaveLength(0);
    expect(container.textContent ?? '').toContain('신용카드');
  });

  /**
   * @scenario claim_type=refund, screen=detail, entry=none
   * @effects claim_detail_refund_account_reads_the_claim_row
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_loads_the_relations_the_screen_draws
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_reject_reason_leads_the_reason_card
   */
  it('신청 사유 — 반려 사유가 있으면 카드 맨 위에 선다', async () => {
    // 헤더 반려 사유는 신청서 **전체가** 반려됐을 때만 선다(S3).
    const { utils, container } = await renderDetail(
      ['_detail_reason.json'],
      exchangeDetail({ status: 'rejected', reject_reason: '사용 흔적이 있어 교환이 어렵습니다.' })
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('반려 사유');
    expect(text).toContain('사용 흔적이 있어 교환이 어렵습니다.');
    // 반려 사유가 사유 라벨보다 앞에 나온다 — 구매자가 가장 먼저 읽어야 할 값이다.
    expect(text.indexOf('반려 사유')).toBeLessThan(text.indexOf('사이즈가 맞지 않아요'));
  });

  /**
   * 한 신청서의 상품이 여러 상자로 오갈 수 있다 — 운송장마다 한 묶음이 서고, 묶음마다 자기
   * 조회 링크와 담긴 상품을 적는다. 송장 없이 회수도착한 상품은 따로 묶인다.
   *
   * @scenario claim_type=return, screen=detail, entry=none
   * @effects claim_detail_shipping_groups_by_waybill
   */
  it('배송 — 운송장이 둘이면 두 묶음이 각자 링크와 상품을 가진다', async () => {
    const claim = exchangeDetail({
      claim_type: 'return',
      tracking_groups: {
        collection: [
          {
            key: 'collection:1|A-1', direction: 'collection', no_tracking: false, carrier_name: 'CJ대한통운',
            tracking_number: 'A-1', tracking_url: 'https://example.test/track/A-1',
            items: [{ claim_item_id: 1, product_name: '셔츠', option_name: null, quantity: 2, arrived: true }], all_arrived: true,
          },
          {
            key: 'collection:1|B-2', direction: 'collection', no_tracking: false, carrier_name: 'CJ대한통운',
            tracking_number: 'B-2', tracking_url: 'https://example.test/track/B-2',
            items: [{ claim_item_id: 2, product_name: '셔츠', option_name: null, quantity: 1, arrived: false }], all_arrived: false,
          },
          {
            key: 'collection:no_tracking', direction: 'collection', no_tracking: true, carrier_name: null,
            tracking_number: null, tracking_url: null,
            items: [{ claim_item_id: 3, product_name: '바지', option_name: null, quantity: 1, arrived: true }], all_arrived: true,
          },
        ],
        redelivery: [],
      },
    });
    const { utils, container } = await renderDetail(['_detail_shipping.json'], claim);
    active = utils;

    const text = container.textContent ?? '';
    expect(container.querySelector('a[href="https://example.test/track/A-1"]')).not.toBeNull();
    expect(container.querySelector('a[href="https://example.test/track/B-2"]')).not.toBeNull();
    expect(text).toContain('셔츠 × 2');
    expect(text).toContain('셔츠 × 1');
    expect(text).toContain('배송 중');
    expect(text).toContain('송장 없이 도착');
    expect(text).toContain('바지 × 1');
    // 반품은 재발송이 없다.
    expect(text).not.toContain('재발송');
  });

  /**
   * 회수지가 상품마다 다르면 주소별로 담긴 상품을 적는다(S4).
   *
   * @scenario claim_type=return, screen=detail, entry=none
   * @effects claim_detail_shipping_groups_by_waybill
   */
  it('배송 — 회수지가 둘이면 주소마다 번호와 상품을 적는다', async () => {
    const claim = exchangeDetail({
      claim_type: 'return',
      items: [
        { ...exchangeDetail().items[0], id: 1, product_name: '셔츠' },
        { ...exchangeDetail().items[0], id: 2, product_name: '바지' },
      ],
      return_addresses: [
        { address_snapshot: { zipcode: '06236', address1: '서울시 강남구', address2: '1층' }, claim_item_ids: [1] },
        { address_snapshot: { zipcode: '48058', address1: '부산시 해운대구', address2: '' }, claim_item_ids: [2] },
      ],
    });
    const { utils, container } = await renderDetail(['_detail_shipping.json'], claim);
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('회수지 1');
    expect(text).toContain('회수지 2');
    expect(text).toContain('부산시 해운대구');
  });

  /**
   * 상품 줄에 그 행의 운송장이 선다 — 같은 신청서의 다른 상품은 다른 상자일 수 있다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_item_row_shows_its_waybill
   */
  it('신청 상품 — 상품 줄에 그 행의 회수·재발송 운송장이 선다', async () => {
    const claim = exchangeDetail();
    claim.items[0].redelivery_carrier_name = '한진택배';
    claim.items[0].redelivery_tracking_number = 'RD-77';
    claim.items[0].redelivery_tracking_url = null;
    const { utils, container } = await renderDetail(['_detail_items.json'], claim);
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('회수 송장');
    expect(container.querySelector('a[href="https://example.test/track/651234567890"]')?.textContent).toContain('651234567890');
    expect(text).toContain('재발송 송장');
    expect(text).toContain('한진택배 RD-77');
  });

  /**
   * 일부 상품만 반려되면 사유는 그 상품 줄에 서고, 헤더 사유 카드는 반려를 외치지 않는다(S3).
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_item_reject_reason_on_the_row
   */
  it('상품별 반려 — 사유는 상품 줄에, 진행 중 신청서의 사유 카드에는 반려가 없다', async () => {
    const claim = exchangeDetail({ status: 'collecting', reject_reason: '예전 사유' });
    claim.items[0].reject_reason = '사용 흔적이 있습니다.';
    const { utils, container } = await renderDetail(['_detail_items.json', '_detail_reason.json'], claim);
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('반려 사유: 사용 흔적이 있습니다.');
    expect(text).not.toContain('예전 사유');
  });

  /**
   * 선교환은 새 상품이 먼저 나간다 — 기존 상품 반송 기한을 알리고, 넘기면 지난 일수를 띄운다.
   * 진행 단계 아래에는 서버가 센 회수 줄(`collection_lane`)이 선다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_advance_exchange_notice_and_overdue
   */
  it('선교환 — 반송 기한 안내·지연·회수 줄', async () => {
    const claim = exchangeDetail({
      is_advance_exchange: true,
      collection_due_date_formatted: '2026-09-20',
      is_collection_overdue: true,
      collection_overdue_days: 3,
    });
    claim.items[0].collection_state_label = '회수중';
    const { utils, container } = await renderDetail(
      ['_detail_progress.json', '_detail_items.json', '_detail_shipping.json'],
      claim,
      {
        progress_steps: PROGRESS_STEPS,
        collection_lane: { total: 3, collected: 1, in_transit: 1, pending: 1, unreturned: 0, state: 'in_transit', state_label: '회수중', state_variant: 'info', due_date: '2026-09-20', is_overdue: true, overdue_days: 3 },
      },
    );
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('기존 상품은 2026-09-20까지 보내 주세요');
    expect(text).toContain('반송 기한 3일 지남');
    expect(text).toContain('기존 상품 회수');
    expect(text).toContain('3개 중 1개 도착');
    expect(text).toContain('반송 기한 2026-09-20');
    expect(text).toContain('기존 상품 회수중');
  });

  /**
   * 일반 교환에는 선교환 안내·회수 줄이 서지 않는다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_advance_exchange_notice_and_overdue
   */
  it('일반 교환 — 선교환 안내와 회수 줄이 없다', async () => {
    const { utils, container } = await renderDetail(
      ['_detail_progress.json', '_detail_shipping.json'],
      exchangeDetail({ is_advance_exchange: false }),
      { progress_steps: PROGRESS_STEPS, collection_lane: null },
    );
    active = utils;

    const text = container.textContent ?? '';
    expect(text).not.toContain('선교환');
    expect(text).not.toContain('기존 상품 회수');
  });

  /**
   * 끝난 선교환(미회수 완료)에는 반송 안내와 「등록 전」 이 서지 않는다 — 처리가 끝났는데 「보내 주세요」 가
   * 남으면 고객이 무엇을 더 해야 하는지 헷갈린다(Chrome 실측 A-5).
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_final_advance_exchange_drops_the_return_request
   */
  it('끝난 선교환 — 반송 안내와 등록 전 문구가 없다', async () => {
    const claim = exchangeDetail({
      is_advance_exchange: true,
      is_final: true,
      collection_due_date_formatted: '2026-10-10',
      tracking_groups: { collection: [], redelivery: [] },
    });
    claim.items[0].is_unreturned = true;
    const { utils, container } = await renderDetail(['_detail_shipping.json'], claim);
    active = utils;

    const text = container.textContent ?? '';
    expect(text).not.toContain('보내 주세요');
    expect(text).not.toContain('선교환');
    expect(text).toContain('미회수');
    // 재발송 칸의 「등록 전」 은 재발송 운송장이 없어서 서는 것 — 회수 칸의 것만 사라져야 한다.
    expect(text.split('등록 전').length - 1).toBe(1);
  });

  /**
   * 미회수로 완료된 상품은 상품 줄과 배송 카드 양쪽에 「미회수」 로 남는다.
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_unreturned_items_marked
   */
  it('미회수 — 상품 줄과 배송 카드에 표시된다', async () => {
    const claim = exchangeDetail({ is_advance_exchange: true });
    claim.items[0].is_unreturned = true;
    const { utils, container } = await renderDetail(['_detail_items.json', '_detail_shipping.json'], claim);
    active = utils;

    const text = container.textContent ?? '';
    expect(text.split('미회수').length - 1).toBe(2);
    expect(text).toContain('티셔츠 (차콜/M) × 1');
  });

  /**
   * 판매자가 접수한 신청은 고객이 고치거나 철회할 수 없다 — 회원·비회원 상세 머리가 안내와
   * 출처 뱃지를 같은 서버 필드(`request_source`)로 세운다(D8).
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_admin_requested_notice
   */
  it.each([
    ['회원', '../../layouts/mypage/claims/show.json'],
    ['비회원', '../../layouts/shop/guest_claim_show.json'],
  ])('%s 상세 — 판매자 접수 뱃지와 안내가 request_source 로 선다', (_label, file) => {
    const json = fs.readFileSync(path.resolve(__dirname, file), 'utf-8');
    const nodes: any[] = [];
    const walk = (n: any) => {
      if (Array.isArray(n)) return n.forEach(walk);
      if (n && typeof n === 'object') {
        nodes.push(n);
        Object.values(n).forEach(walk);
      }
    };
    walk(JSON.parse(json));

    const badge = nodes.find((n) => typeof n.if === 'string' && n.if.includes("request_source === 'admin'") && String(n.text ?? '').includes('request_source_label'));
    const notice = nodes.find((n) => typeof n.if === 'string' && n.if.includes("request_source === 'admin'") && String(n.text ?? '').includes('claim.detail.admin_requested_notice'));
    expect(badge).toBeDefined();
    expect(notice).toBeDefined();
    expect(notice.if).toContain('is_final !== true');
  });

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
  it('교환 대상 — 차액이 있으면 보조 통화 줄이 선다 (게이트가 값을 삼키지 않는다)', async () => {
    const { utils, container } = await renderDetail(['_detail_items.json'], exchangeDetail());
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('옵션 차액 3,000원');
    expect(text).toContain('$2.25');
  });

  /**
   * @scenario claim_type=refund, screen=detail, entry=none
   * @effects claim_detail_contrast_axis_shows_both_sides
   */
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

  /**
   * @scenario claim_type=cancel, screen=detail, entry=none
   * @effects claim_detail_contrast_axis_shows_both_sides
   */
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

  /**
   * @scenario claim_type=return, screen=detail, entry=none
   * @effects claim_detail_contrast_axis_shows_both_sides
   */
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

  /**
   * @scenario claim_type=refund, screen=detail, entry=none
   * @effects claim_detail_contrast_axis_shows_both_sides
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_contrast_axis_shows_both_sides
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_items_show_exchange_target
   */
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

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_loads_the_relations_the_screen_draws
   */
  it('신청 사유 — 첨부가 없으면 그 블록이 서지 않는다', async () => {
    const { utils, container } = await renderDetail(['_detail_reason.json'], exchangeDetail());
    active = utils;

    expect(container.textContent ?? '').not.toContain('첨부 사진');
  });

  // ── 접수 시점 주문금액 비교표 (#599 후속 P4) ─────────────────────────

  // @scenario claim_type=exchange, screen=detail, entry=none
  // @effects claim_detail_comparison_renders_from_frozen_snapshot
  it('클레임 정보 — 접수 시점 비교표(14 금액행 + 교환 치환행)가 저장 스냅샷 그대로 선다', async () => {
    const { utils, container } = await renderDetail(['_detail_claim_info.json'], exchangeDetail({
      calculation_comparison: {
        ...CALCULATION_COMPARISON,
        substituted_items: [{
          order_option_id: 310, quantity: 1, product_option_id: 77, unit_price: 29000, new_unit_price: 32000,
          line_key: -310, option_label: '블랙 / L', unit_price_formatted: '29,000원', new_unit_price_formatted: '32,000원',
        }],
      },
    }));
    active = utils;

    const text = container.textContent ?? '';
    const table = container.querySelector('[data-testid="claim-detail-comparison-table"]');
    const rows = Array.from(table?.querySelectorAll('[data-row]') ?? []).map((el) => el.getAttribute('data-row'));

    expect(rows).toHaveLength(15);
    expect(rows[0]).toBe('item_count');
    expect(rows[rows.length - 1]).toBe('substituted');
    expect(text).toContain('주문금액 비교');
    expect(text).toContain('5,272원');
    expect(text).toContain('2,636원');
    expect(text).toContain('2개');
    expect(text).toContain('1개');
    expect(text).toContain('$49.30');
    expect(text).toContain('$24.65');
    expect(text).toContain('블랙 / L');
    expect(text).toContain('29,000원 → 32,000원');
    // 열 라벨은 유형 분기 식이라 하네스에서는 키가 난다 — 교환 키가 골라졌는지를 잰다.
    expect(text).toContain('claim.detail.comparison.col_before_exchange');
    expect(container.querySelector('[data-testid="claim-detail-comparison-empty"]')).toBeNull();
    // 기존 3행 대비표(원 주문 ↔ 이 클레임)는 그대로 남는다.
    expect(text).toContain('원 주문');
    expect(text).toContain('+9,000원');
  });

  // @scenario claim_type=exchange, screen=detail, entry=none
  // @effects claim_detail_comparison_absent_notice_for_legacy_claims
  it('클레임 정보 — 스냅샷이 없는 구형 신청서는 안내 한 줄만 선다', async () => {
    const { utils, container } = await renderDetail(['_detail_claim_info.json'], exchangeDetail({ calculation_comparison: null }));
    active = utils;

    expect(container.querySelector('[data-testid="claim-detail-comparison-empty"]')?.textContent).toBe('접수 시점 비교표가 없습니다');
    expect(container.querySelector('[data-testid="claim-detail-comparison-table"]')).toBeNull();
    expect(container.querySelectorAll('[data-row]')).toHaveLength(0);
  });

  // @scenario claim_type=return, screen=detail, entry=none
  // @effects claim_detail_comparison_mobile_kv_form
  it('클레임 정보 — 모바일은 행마다 "원값 → 변경값" kv 형이고 머리글은 숨는다 (구조)', async () => {
    const partial = loadPartial('_detail_claim_info.json');
    const nodes: any[] = [];
    (function walk(n: any) { if (Array.isArray(n)) n.forEach(walk); else if (n && typeof n === 'object') { nodes.push(n); Object.values(n).forEach(walk); } })(partial);

    // `responsive.portable` 도 props 를 들므로 노드 자신(type: basic)만 센다.
    const rows = nodes.filter((n) => n.type === 'basic' && typeof n.props?.['data-row'] === 'string' && n.props['data-row'] !== 'substituted');
    expect(rows).toHaveLength(14);
    for (const row of rows) {
      // 회귀(2026-09-11 브라우저 실측): 임의값 grid 템플릿(`grid-cols-[1fr_auto_auto]`)은 행마다 내용으로
      // 열 폭을 정해 "전/후" 열이 행마다 어긋났고, 관리자 템플릿 CSS 는 임의값 자체를 컴파일하지 않아
      // 표가 세로로 쌓였다. 세 화면 모두 기본 클래스(grid-cols-3)로 같은 격자를 쓴다.
      expect(row.props.className).toContain('grid-cols-3');
      expect(row.props.className).not.toContain('grid-cols-[');
      expect(row.responsive.portable.props.className).toContain('flex');
      // 화살표는 PC 에서 숨고 모바일에서만 보인다.
      const arrow = row.children.find((c: any) => c.text === '→');
      expect(arrow.props.className).toBe('hidden');
      expect(arrow.responsive.portable.props.className).not.toContain('hidden');
    }
    const header = nodes.find((n) => typeof n.comment === 'string' && n.comment.startsWith('표 머리글'));
    expect(header.responsive.portable.props.className).toBe('hidden');

    // 렌더에서도 화살표가 행마다 하나씩 DOM 에 선다(표시 여부는 뷰포트 규칙이 정한다).
    const { utils, container } = await renderDetail(['_detail_claim_info.json'], exchangeDetail({ calculation_comparison: CALCULATION_COMPARISON }));
    active = utils;
    const arrows = Array.from(container.querySelectorAll('[data-testid="claim-detail-comparison-table"] [data-row] span'))
      .filter((el) => el.textContent === '→');
    expect(arrows).toHaveLength(14);
  });

  // @scenario claim_type=return, entry=with_token, screen=detail
  // @effects claim_detail_comparison_renders_from_frozen_snapshot
  it('클레임 정보 — 비회원 상세도 같은 partial 을 실어 같은 표가 선다', async () => {
    const guestShow = fs.readFileSync(path.resolve(__dirname, '../../layouts/shop/guest_claim_show.json'), 'utf-8');
    const memberShow = fs.readFileSync(path.resolve(__dirname, '../../layouts/mypage/claims/show.json'), 'utf-8');
    expect(guestShow).toContain('partials/mypage/claims/_detail_claim_info.json');
    expect(memberShow).toContain('partials/mypage/claims/_detail_claim_info.json');

    // 비회원 응답은 같은 ClaimResource 라 같은 키(calculation_comparison)를 싣는다 — 회원과 같은 상태로 그리면 행 수가 같다.
    const { utils, container } = await renderDetail(['_detail_claim_info.json'], exchangeDetail({
      is_guest_request: true, requested_by: null, calculation_comparison: CALCULATION_COMPARISON,
    }));
    active = utils;
    expect(container.querySelectorAll('[data-testid="claim-detail-comparison-table"] [data-row]')).toHaveLength(14);
    expect(container.textContent).toContain('5,272원');
  });
});
