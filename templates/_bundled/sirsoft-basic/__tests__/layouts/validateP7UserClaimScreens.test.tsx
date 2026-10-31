/**
 * @file validateP7UserClaimScreens.test.tsx
 * @description #599 validate P7 — 사용자 화면(sirsoft-basic) 결함 회귀 테스트
 *
 * 브랜치 검증(2026-09-23)이 주문상세·주문서·클레임 신청/상세 화면에서 찾은 결함을 잠근다.
 * 구조로 판정되는 것은 레이아웃 JSON 을 읽고, 글자로만 드러나는 것은 화면을 그려 잰다.
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

// ── 공통 ──────────────────────────────────────────────────────────

const LAYOUTS = path.resolve(__dirname, '../../layouts');

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(LAYOUTS, relative), 'utf-8'));
}

/** 조건을 만족하는 노드를 모두 모은다. */
function collect(node: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, out));
    return out;
  }
  if (!node || typeof node !== 'object') return out;
  if (predicate(node)) out.push(node);
  for (const value of Object.values(node)) collect(value, predicate, out);
  return out;
}

/** 액션 트리를 평탄화한다 (sequence·conditions 안쪽까지). */
function flattenActions(node: unknown): any[] {
  return collect(node, (n) => typeof n?.handler === 'string');
}

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('basic', 'Icon', ({ className }: any) => React.createElement('i', { className }));
  registry.register('composite', 'FileUploader', () => React.createElement('div', { 'data-testid': 'file-uploader' }));
  registry.register('composite', 'Modal', ({ children, title }: any) =>
    React.createElement('div', { 'data-testid': 'modal' }, title, children)
  );
  return registry;
}

/** partial 참조를 파일에서 읽어 인라인한다. */
function inlinePartials(node: any): any {
  if (Array.isArray(node)) return node.map(inlinePartials);
  if (node && typeof node === 'object') {
    if (typeof node.partial === 'string') return inlinePartials(loadJson(node.partial));
    const out: Record<string, any> = {};
    for (const [key, value] of Object.entries(node)) out[key] = inlinePartials(value);
    return out;
  }
  return node;
}

async function renderPartial(file: string, local: Record<string, any>, translations: Record<string, any> = {}) {
  const utils = createLayoutTest(
    { components: [inlinePartials(loadJson('partials/mypage/claims/' + file))] },
    { initialState: { _local: local }, locale: 'ko', translations, componentRegistry: setupRegistry() }
  );
  const { container } = await utils.render();
  return { utils, container };
}

function summaryState(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claimType: 'exchange',
    claimApiBase: '/api/x',
    claimEstimateLoading: false,
    claimEstimateError: null,
    claimItems: [{ order_option_id: 421, selected: true }],
    claimReason: 'color_mismatch',
    claimEstimate: { price_difference: 2000, adjustment: { refund_total: 0 } },
    claimEstimateFormatted: {
      shipping_fee: '6,000원',
      shipping_groups: [],
      price_difference: '3,000원',
      total_charge_amount: '9,000원',
      refund_total: '0원',
      mc_total_charge_amount: [],
      mc_shipping_fee: [],
      mc_refund_total: [],
    },
    claimableSnapshot: {
      settings: {
        allowed_charge_methods: ['pay_on_request', 'pay_on_approval'],
        charge_payment_methods: ['card', 'point'],
      },
    },
    ...overrides,
  };
}

// ── 테스트 ──────────────────────────────────────────────────────

describe('주문상세 상품 선택 (_items)', () => {
  /**
   * 핸들러가 화면 잠금과 같은 술어(열린 클레임 유형)로 고르도록 claimable 판정을 넘긴다.
   *
   * @scenario screen=order_detail, fault=select_all_claimable
   * @effects order_detail_selection_passes_claimable_to_handler
   */
  it('전체선택·개별선택 핸들러에 claimable 판정을 넘긴다', () => {
    const items = loadJson('partials/mypage/orders/_items.json');
    const actions = flattenActions(items).filter((a) =>
      ['sirsoft-ecommerce.toggleSelectAllItems', 'sirsoft-ecommerce.toggleItemSelection'].includes(a.handler)
    );

    expect(actions).toHaveLength(2);
    for (const action of actions) {
      expect(String(action.params?.claimableOptions ?? '')).toContain('claimable?.data?.options');
    }
  });

  /**
   * 행 체크박스는 id 를 갖고 같은 id 를 가리키는 Label 이 있다.
   *
   * @scenario screen=order_detail, fault=checkbox_label
   * @effects claim_item_checkbox_has_label_for
   */
  it('행 체크박스에 id 가 있고 Label 이 그 id 를 가리킨다 (주문상세 · 신청서 작성)', () => {
    for (const file of ['partials/mypage/orders/_items.json', 'partials/mypage/claims/_apply_items.json']) {
      const layout = loadJson(file);
      const rowCheckbox = collect(
        layout,
        (n) => n?.props?.type === 'checkbox' && typeof n?.props?.id === 'string' && n.props.id.includes('{{')
      );
      expect(rowCheckbox.length, file).toBe(1);

      const label = collect(layout, (n) => n?.name === 'Label' && n?.props?.htmlFor === rowCheckbox[0].props.id);
      expect(label.length, file).toBe(1);
    }
  });
});

describe('주문서 기본 배송지 채움 (checkout)', () => {
  /**
   * 배송지 관리 창이 목록을 다시 받을 때마다 채움이 돌면 구매자가 고른 배송지를 기본 배송지로 덮는다.
   * 데이터소스 onSuccess 컨텍스트에는 _local 이 없으므로 핸들러가 1회 여부를 판정하고, 첫 회만 채운다.
   *
   * @scenario screen=checkout, fault=prefill_once
   * @effects checkout_default_address_prefills_once
   */
  it('채움은 1회 가드(markLocalOnce) 결과가 참일 때만 돈다', () => {
    const checkout = loadJson('shop/checkout.json');
    const source = checkout.data_sources.find((d: any) => d.id === 'userAddresses');
    const prefill = ([] as any[]).concat(source.onSuccess ?? [])[0];
    const steps = prefill.actions;

    expect(steps[0].handler).toBe('sirsoft-basic.markLocalOnce');
    const flagKey = steps[0].params.key;
    expect(flagKey).toBeTruthy();

    const gate = steps.find((s: any) => s.handler === 'conditions');
    expect(gate.conditions[0].if).toMatch(/\$prev === true/);
    const gated = flattenActions(gate.conditions[0]).map((a) => a.handler);
    expect(gated).toContain('setState');
    expect(gated).toContain('refetchDataSource');

    // 화면에 들어올 때마다 플래그를 거짓으로 세운다 — 다음 진입에서 다시 한 번 채운다.
    expect(checkout.initLocal?.[flagKey] ?? checkout.state?.[flagKey]).toBe(false);
  });
});

describe('클레임 상세 첨부 (_detail_reason)', () => {
  /**
   * 반복 노드가 grid 를 들고 있으면 사진마다 격자가 따로 생겨 한 줄에 하나씩 쌓인다.
   *
   * @scenario screen=claim_detail, fault=iteration_spacing
   * @effects claim_detail_images_iterate_inside_one_grid
   */
  it('사진 버튼이 반복되고 격자는 부모가 한 번만 갖는다', () => {
    const layout = loadJson('partials/mypage/claims/_detail_reason.json');
    const grid = collect(layout, (n) => (n?.children ?? []).some((c: any) => c?.iteration?.item_var === 'claimImage'))[0];

    expect(grid.props.className).toContain('grid grid-cols-5');
    expect(grid.responsive?.portable?.props?.className).toContain('grid-cols-3');
    const repeated = grid.children.find((c: any) => c?.iteration?.item_var === 'claimImage');
    expect(repeated.name).toBe('Button');
  });
});

describe('반복 노드 간격 클래스 (#599 사용자 클레임 화면)', () => {
  /**
   * `data-each` 노드는 자기 자신이 복제되므로 그 노드의 space-y·divide-y 는 형제 사이에 걸리지 않는다.
   *
   * @scenario screen=claim_apply, fault=iteration_spacing
   * @effects iteration_spacing_lives_on_parent
   */
  it('반복 노드 자신에 space-y·divide-y 를 두지 않는다', () => {
    const files = [
      'partials/mypage/claims/_apply_items.json',
      'partials/mypage/claims/_apply_summary.json',
      'partials/mypage/claims/_detail_claim_info.json',
      'partials/mypage/claims/_detail_items.json',
      'partials/mypage/claims/_list.json',
      'partials/mypage/orders/_items.json',
    ];
    const offenders: string[] = [];

    for (const file of files) {
      for (const node of collect(loadJson(file), (n) => !!n?.iteration)) {
        const classes = [node.props?.className, node.responsive?.portable?.props?.className].filter(Boolean).join(' ');
        if (/\b(space-[xy]-|divide-[xy])/.test(classes)) offenders.push(`${file}: ${node.iteration.item_var}`);
      }
    }

    expect(offenders).toEqual([]);
  });
});

describe('신청서 요약 (_apply_summary)', () => {
  let active: { cleanup: () => void } | null = null;
  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  /**
   * 수정 화면은 계좌 칸을 빈 채로 열고 「비워 두면 그대로 둔다」 고 안내한다 — 저장도 계좌를 요구하지 않는다.
   * 그 화면에 「계좌를 채워야 제출할 수 있다」 를 띄우면 안내가 서로 맞선다.
   *
   * @scenario screen=claim_edit, fault=account_notice
   * @effects claim_edit_hides_refund_account_required_notice
   */
  it('수정 화면에는 환불계좌 미입력 안내를 띄우지 않는다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      summaryState({
        claimType: 'refund',
        claimEditMode: true,
        claimEstimate: { price_difference: 0, adjustment: { refund_total: 10000 } },
        claimableSnapshot: { refund_account: { required: true, banks: [] } },
      })
    );
    active = utils;

    expect(container.textContent ?? '').not.toContain('claim.apply.refund_account_required_to_submit');
  });

  /**
   * 금액을 번역문 밖에서 이어 붙이면 언어마다 어순이 틀린다(`9,000원 Pay now`). 금액은 번역문의 파라미터다.
   *
   * @scenario screen=claim_apply, fault=submit_label
   * @effects claim_submit_label_passes_amount_as_param
   */
  it('결제 버튼 라벨은 금액을 번역 파라미터로 넘긴다', async () => {
    const { utils, container } = await renderPartial(
      '_apply_summary.json',
      summaryState({
        claimChargeMethod: 'pay_on_request',
        claimEstimate: { price_difference: 2000, charge_method: 'pay_on_request', requires_payment: true, adjustment: { refund_total: 0 } },
      }),
      { claim: { apply: { submit_with_payment: 'PAY {{amount}} NOW' } } }
    );
    active = utils;

    expect(container.textContent ?? '').toContain('PAY 9,000원 NOW');
  });

  /**
   * 라디오의 파란 강조색은 다크 모드 짝을 갖는다.
   *
   * @scenario screen=claim_apply, fault=dark_pair
   * @effects claim_apply_blue_text_has_dark_pair
   */
  it('text-blue-600 은 다크 모드 짝과 함께 쓴다', () => {
    const offenders = collect(loadJson('partials/mypage/claims/_apply_summary.json'), (n) => {
      const cls = n?.props?.className;
      return typeof cls === 'string' && /(^|\s)text-blue-600(\s|$)/.test(cls) && !/dark:text-blue-/.test(cls);
    });

    expect(offenders).toEqual([]);
  });
});

describe('적립 마일리지 표기 (_apply_comparison · _detail_claim_info)', () => {
  let active: { cleanup: () => void } | null = null;
  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  /**
   * 적립 마일리지의 단위 표기는 서버 `*_formatted` 가 정본이다 — 화면이 `toLocaleString() + 'P'` 를 하면
   * 서버와 화면이 같은 규칙을 두 벌 갖는다.
   *
   * @scenario screen=claim_apply, fault=points_format
   * @effects claim_earned_points_reads_server_formatted
   */
  it('비교표의 적립 마일리지는 서버 포맷 문자열을 그린다', async () => {
    const snapshot = (points: number, label: string) => ({
      base_currency: 'KRW',
      total_earned_points_amount: points,
      total_earned_points_amount_formatted: label,
      formatted: {},
    });
    const { utils, container } = await renderPartial('_apply_comparison.json', {
      claimType: 'refund',
      claimComparisonOpen: true,
      claimEstimateLoading: false,
      claimEstimate: {
        refund_product_amount: 0,
        adjustment: {
          original_snapshot: snapshot(620, 'SERVER-620'),
          recalculated_snapshot: snapshot(450, 'SERVER-450'),
          original_coupons: [],
          recalculated_coupons: [],
          refund_formatted: { base: {}, mc: {} },
          substituted_items: [],
        },
      },
      claimEstimateFormatted: { substituted_items: [], mc_refund_total: [] },
    });
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('SERVER-620');
    expect(text).toContain('SERVER-450');
  });

  /**
   * @scenario screen=claim_detail, fault=points_format
   * @effects claim_earned_points_reads_server_formatted
   */
  it('상세 비교표도 서버 포맷 키를 읽는다', () => {
    const json = JSON.stringify(loadJson('partials/mypage/claims/_detail_claim_info.json'));
    expect(json).toContain('original?.total_earned_points_amount_formatted');
    expect(json).toContain('recalculated?.total_earned_points_amount_formatted');
  });
});

describe('첨부 설정 (_apply_reason)', () => {
  /**
   * 허용 확장자는 서버 설정(`settings.accept`)을 따르고, 용량 상한이 없으면 0 이 아니라 null 이다 —
   * 0 을 넘기면 컴포지트가 모든 파일을 「0MB 초과」 로 거절한다.
   *
   * @scenario screen=claim_apply, fault=uploader_limits
   * @effects claim_uploader_limits_follow_server_settings
   */
  it('FileUploader 의 accept 는 서버 설정, maxSize 폴백은 null 이다', () => {
    const uploader = collect(loadJson('partials/mypage/claims/_apply_reason.json'), (n) => n?.name === 'FileUploader')[0];

    expect(uploader.props.accept).toContain('settings?.accept');
    expect(uploader.props.maxSize).toMatch(/\?\? null\}\}$/);
  });
});

describe('철회 뒤 신청 가능 여부 (_modal_withdraw · 주문상세 셸)', () => {
  /**
   * 주문상세에서 철회하면 그 상품이 다시 신청 가능해진다 — claimable 을 다시 받지 않으면 칸이 잠긴 채 남는다.
   *
   * @scenario screen=order_detail, fault=withdraw_refresh
   * @effects withdraw_refreshes_claimable_on_order_detail
   */
  it('주문상세 셸에서 철회하면 claimable 을 다시 받는다', () => {
    const modal = loadJson('partials/mypage/claims/_modal_withdraw.json');
    const gates = collect(modal, (n) => n?.handler === 'conditions' && JSON.stringify(n).includes('withdrawRefreshClaimable'));
    expect(gates.length).toBe(1);
    const refetch = flattenActions(gates[0]).find((a) => a.handler === 'refetchDataSource');
    expect(refetch?.params?.dataSourceId).toBe('claimable');

    for (const shell of ['mypage/orders/show.json', 'shop/guest_order_show.json']) {
      expect(loadJson(shell).initLocal?.withdrawRefreshClaimable, shell).toBe(true);
    }
  });

  /**
   * 어디서도 읽지 않는 환불 사유 데이터소스는 진입마다 요청만 낸다.
   *
   * @scenario screen=order_detail, fault=dead_datasource
   * @effects order_detail_has_no_dead_refund_reasons_source
   */
  it('주문상세 셸에 refundReasons 데이터소스가 없다', () => {
    for (const shell of ['mypage/orders/show.json', 'shop/guest_order_show.json']) {
      const ids = (loadJson(shell).data_sources ?? []).map((d: any) => d.id);
      expect(ids, shell).not.toContain('refundReasons');
    }
  });
});

describe('주문 목록 추가옵션 요약 (orders/_list)', () => {
  /**
   * 「외 N건」 을 한국어 리터럴로 이어 붙이면 다른 언어 화면에 한국어가 섞인다.
   *
   * @scenario screen=order_list, fault=hardcoded_text
   * @effects order_list_additional_option_more_is_translated
   */
  it('추가옵션 「외 N건」 은 번역 키로 그린다', () => {
    const json = JSON.stringify(loadJson('partials/mypage/orders/_list.json'));

    // 표현식 안의 한국어 리터럴(`' 외 '`·`'건'`)이 없어야 한다 — 주석의 설명 문구는 대상이 아니다.
    expect(json).not.toContain("' 외 '");
    expect(json).not.toContain("'건'");
    expect(json).toContain('mypage.orders.additional_options_more');
  });
});

describe('비회원 클레임 상세 — 판매자 접수 표시 (shop/guest_claim_show · D8)', () => {
  /**
   * 판매자가 대신 접수한 신청(`request_source === 'admin'`)은 비회원 상세에도 출처 라벨과 안내를 세운다 —
   * 고객은 그 신청을 고치거나 철회할 수 없으므로 버튼이 사라진 이유를 알아야 한다. 라벨은 서버가 로케일로
   * 풀어 준 값(`request_source_label`)이고, 안내는 끝난 신청서에는 세우지 않는다.
   *
   * @effects guest_claim_show_marks_seller_filed_claims
   */
  it('출처 라벨과 판매자 접수 안내가 request_source 로만 선다', () => {
    const layout = loadJson('shop/guest_claim_show.json');
    const nodes = collect(layout, (n) => typeof n.if === 'string' && n.if.includes('request_source'));

    expect(nodes.map((n) => [n.if, n.text])).toEqual([
      ["{{claim.data?.claim?.request_source === 'admin'}}", "{{claim.data?.claim?.request_source_label ?? ''}}"],
      [
        "{{claim.data?.claim?.request_source === 'admin' && claim.data?.claim?.is_final !== true}}",
        '$t:claim.detail.admin_requested_notice',
      ],
    ]);
  });
});
