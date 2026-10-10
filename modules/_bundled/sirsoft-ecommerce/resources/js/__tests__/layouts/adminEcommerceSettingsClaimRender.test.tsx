// @vitest-environment jsdom
import '@testing-library/jest-dom';

/**
 * 환경설정 클레임 탭 렌더링 테스트 (S10 · D23/D26)
 *
 * 구조 계약은 `adminEcommerceSettingsClaim.test.ts` 가 고정한다. 여기서 보는 것은 **실제로
 * 그려지는가** 다 — 사유 카탈로그 3종이 같은 화면에 있어서, 한 표가 다른 표의 데이터를
 * 그리면 구조 검사는 통과하고 화면만 틀린다. 세 표의 행 수와 코드 문자열을 갈라 심어
 * 각자 자기 목록만 그리는지 본다.
 *
 * `_computed.isReadOnly` 는 셸 레이아웃의 computed 다. 이 테스트의 래퍼 레이아웃은 그 computed 를
 * 싣지 않으므로 읽기전용 잠금은 여기서 보이지 않는다(값이 비어 입력은 활성으로 그려진다) —
 * 잠금 계약은 구조 테스트 `adminEcommerceSettingsClaim.test.ts` 가 노드 단위로 고정한다.
 */
import React from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

import claimPolicy from '../../../layouts/admin/partials/admin_ecommerce_settings/_claim_policy_section.json';
import exchangeSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_exchange_reason_section.json';
import refundSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_refund_reason_section.json';
import returnSection from '../../../layouts/admin/partials/admin_ecommerce_settings/_return_reason_section.json';

// ─── 테스트용 컴포넌트 ───

const Passthrough = (tag: keyof React.JSX.IntrinsicElements) => {
  const Comp: React.FC<any> = ({ className, children, text, id }) =>
    React.createElement(tag, { className, id }, children ?? text);

  return Comp;
};

const TestInput: React.FC<any> = ({ type, value, checked, className, disabled, onChange, id }) => (
  <input
    type={type}
    id={id}
    value={type === 'checkbox' ? undefined : (value ?? '')}
    checked={type === 'checkbox' ? !!checked : undefined}
    className={className}
    disabled={disabled}
    onChange={onChange}
  />
);

const TestSelect: React.FC<any> = ({ value, options, className, disabled, onChange, id }) => (
  <select id={id} className={className} value={value ?? ''} disabled={disabled} onChange={onChange}>
    {(options ?? []).map((o: any) => (
      <option key={o.value} value={o.value}>{o.label}</option>
    ))}
  </select>
);

/** 다국어 값 묶음 편집기 — 실제 컴포넌트는 언어 탭을 그리지만 여기서는 ko 값만 노출한다 */
const TestMultilingualInput: React.FC<any> = ({ value, layout, disabled }) => (
  <span data-testid="multilingual" data-layout={layout} data-disabled={String(!!disabled)}>
    {(value ?? {}).ko ?? ''}
  </span>
);

const TestTagInput: React.FC<any> = ({ name, value, disabled, options }) => (
  <span
    data-testid="taginput"
    data-name={name}
    data-disabled={String(!!disabled)}
    /* 선택지도 DOM 에 남긴다 — 화면 선택지와 서버 허용 어휘가 갈리면 한쪽이 우회로가 된다. */
    data-options={(Array.isArray(options) ? options : []).map((o: any) => o?.value ?? o).join(',')}
  >
    {(Array.isArray(value) ? value : []).join(',')}
  </span>
);

const TestToggle: React.FC<any> = ({ name, value, checked, disabled }) => (
  <span
    data-testid="toggle"
    data-name={name}
    data-disabled={String(!!disabled)}
    data-checked={String(value ?? checked ?? false)}
  />
);

/**
 * 이 화면이 쓰는 컴포넌트만 등록한 레지스트리를 만든다.
 *
 * @returns 테스트 레지스트리
 */
/** 덮어쓰기 전 레지스트리 원본 (afterEach 에서 되돌린다) */
let savedRegistry: unknown = null;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  // 싱글턴을 통째로 덮어쓰므로 원본을 보관해 둔다. 되돌리지 않으면 파일 격리(isolate)가
  // 꺼지거나 같은 파일에 describe 가 추가되는 순간 다른 테스트가 이 축소 레지스트리 위에서
  // 돌아 원인이 드러나지 않는 실패를 낸다.
  savedRegistry ??= (registry as any).registry;

  (registry as any).registry = {
    Div: { component: Passthrough('div'), metadata: { name: 'Div', type: 'basic' } },
    Span: { component: Passthrough('span'), metadata: { name: 'Span', type: 'basic' } },
    P: { component: Passthrough('p'), metadata: { name: 'P', type: 'basic' } },
    H2: { component: Passthrough('h2'), metadata: { name: 'H2', type: 'basic' } },
    H3: { component: Passthrough('h3'), metadata: { name: 'H3', type: 'basic' } },
    Label: { component: Passthrough('label'), metadata: { name: 'Label', type: 'basic' } },
    Button: { component: Passthrough('button'), metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: Passthrough('i'), metadata: { name: 'Icon', type: 'basic' } },
    Table: { component: Passthrough('table'), metadata: { name: 'Table', type: 'basic' } },
    Thead: { component: Passthrough('thead'), metadata: { name: 'Thead', type: 'basic' } },
    Tbody: { component: Passthrough('tbody'), metadata: { name: 'Tbody', type: 'basic' } },
    Tr: { component: Passthrough('tr'), metadata: { name: 'Tr', type: 'basic' } },
    Th: { component: Passthrough('th'), metadata: { name: 'Th', type: 'basic' } },
    Td: { component: Passthrough('td'), metadata: { name: 'Td', type: 'basic' } },
    Input: { component: TestInput, metadata: { name: 'Input', type: 'basic' } },
    Select: { component: TestSelect, metadata: { name: 'Select', type: 'basic' } },
    MultilingualInput: { component: TestMultilingualInput, metadata: { name: 'MultilingualInput', type: 'composite' } },
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    Toggle: { component: TestToggle, metadata: { name: 'Toggle', type: 'composite' } },
    Fragment: { component: Passthrough('div'), metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/** 유형마다 행 수와 코드를 갈라 심는다 — 한 표가 남의 목록을 그리면 즉시 드러난다 */
const REASONS = {
  exchange: [
    { id: 11, code: 'ex_size', name: { ko: '사이즈 교환' }, fault_type: 'customer', is_user_selectable: true, is_active: true, sort_order: 0 },
  ],
  return: [
    { id: 21, code: 'rt_defect', name: { ko: '상품 불량' }, fault_type: 'seller', is_user_selectable: true, is_active: true, sort_order: 0 },
    { id: 22, code: 'rt_mind', name: { ko: '단순 변심' }, fault_type: 'customer', is_user_selectable: true, is_active: false, sort_order: 1 },
  ],
  refund: [
    { id: 31, code: 'rf_mistake', name: { ko: '주문 실수' }, fault_type: 'customer', is_user_selectable: true, is_active: true, sort_order: 0 },
    { id: 32, code: 'rf_delay', name: { ko: '배송 지연' }, fault_type: 'carrier', is_user_selectable: false, is_active: true, sort_order: 1 },
    { id: 33, code: 'rf_other', name: { ko: '기타' }, fault_type: 'seller', is_user_selectable: true, is_active: true, sort_order: 2 },
  ],
};

const wrapperLayout = {
  version: '1.0.0',
  layout_name: 'test_claim_tab_wrapper',
  components: [
    {
      id: 'wrapper',
      type: 'basic',
      name: 'Div',
      children: [claimPolicy, exchangeSection, returnSection, refundSection],
    },
  ],
};

/**
 * @scenario reason_type=exchange
 *
 * @effects settings_claim_catalogs_render_independently, settings_claim_complete_defaults_declared_as_modal_seed
 */
describe('설정 클레임 탭 렌더링', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    testUtils = createLayoutTest(wrapperLayout as any, {
      auth: { isAuthenticated: true, user: { id: 1, name: 'Admin', role: 'super_admin' }, authType: 'admin' },
      locale: 'ko',
      initialState: {
        _global: { activeEcommerceSettingsTab: 'claim' },
        _local: {
          form: {
            _meta: { limits: { claim_max_images_min: 1, claim_max_images_max: 20 } },
            claim: {
              exchange_requestable_statuses: ['delivered'],
              return_requestable_statuses: ['delivered', 'confirmed'],
              instant_cancel_statuses: ['pending_order', 'pending_payment', 'payment_complete'],
              refund_request_statuses: ['preparing', 'shipping'],
              allowed_charge_methods: ['pay_on_request'],
              charge_payment_methods: ['card', 'point'],
              auto_approve_zero_charge: true,
              auto_approve_after_payment: false,
              auto_approve_refund_request: false,
              auto_approve_refund_statuses: ['payment_complete', 'preparing'],
              max_images: 7,
              image_max_size_kb: 3072,
              request_period_days: 14,
              complete_defaults: { create_refund_request: false, restore_stock: true },
              exchange_reasons: REASONS.exchange,
              return_reasons: REASONS.return,
              refund_reasons: REASONS.refund,
            },
          },
        },
      },
      componentRegistry: setupTestRegistry(),
    });
  });

  afterEach(() => {
    testUtils.cleanup();

    if (savedRegistry !== null) {
      (ComponentRegistry.getInstance() as any).registry = savedRegistry;
      savedRegistry = null;
    }
  });

  /**
   * 클레임 탭이 정책과 사유 카탈로그 셋을 검증 오류 없이 그린다.
   *
   * @effects settings_claim_tab_composes_policy_and_three_catalogs
   */
  it('레이아웃 검증 오류 없이 렌더링된다', async () => {
    const { container } = await testUtils.render();

    expect(container.innerHTML.length).toBeGreaterThan(0);
    expect(() => testUtils.assertNoValidationErrors()).not.toThrow();
  });

  /**
   * 교환·반품·환불 사유 카탈로그는 각자 자기 목록만 그린다.
   *
   * @effects settings_claim_catalogs_render_independently
   */
  it('사유 카탈로그 3종이 각자의 목록만 그린다', async () => {
    const { container } = await testUtils.render();

    const lists: Array<[string, string[]]> = [
      ['exchange_reason_list', REASONS.exchange.map((r) => r.code)],
      ['return_reason_list', REASONS.return.map((r) => r.code)],
      ['refund_reason_list', REASONS.refund.map((r) => r.code)],
    ];

    for (const [id, codes] of lists) {
      const list = container.querySelector(`#${id}`);
      expect(list, `${id} 가 렌더되지 않았다`).not.toBeNull();

      const rows = list!.querySelectorAll('tbody tr');
      expect(rows).toHaveLength(codes.length);

      const text = list!.textContent ?? '';
      for (const code of codes) expect(text).toContain(code);
    }
  });

  /**
   * 사유명은 다국어 입력으로 그린다.
   *
   * @effects settings_claim_reason_name_is_multilingual
   */
  it('사유명은 MultilingualInput 으로 그려지고 목록 행은 compact 다 (D23)', async () => {
    const { container } = await testUtils.render();

    const rowEditors = Array.from(container.querySelectorAll('tbody [data-testid="multilingual"]'));

    expect(rowEditors).toHaveLength(
      REASONS.exchange.length + REASONS.return.length + REASONS.refund.length,
    );
    for (const editor of rowEditors) {
      expect(editor.getAttribute('data-layout')).toBe('compact');
    }
    expect(rowEditors.map((e) => e.textContent)).toContain('사이즈 교환');
  });

  /**
   * 정책 스칼라(선교환 반송 기한 포함)가 저장된 값으로 그려진다.
   *
   * @effects settings_claim_policy_scalars_persist
   */
  it('정책 스칼라 값이 저장된 값 그대로 그려진다', async () => {
    const { container } = await testUtils.render();

    const tags = Object.fromEntries(
      Array.from(container.querySelectorAll('[data-testid="taginput"]')).map((n) => [
        n.getAttribute('data-name'),
        n.textContent,
      ]),
    );

    expect(tags['claim.exchange_requestable_statuses']).toBe('delivered');
    expect(tags['claim.refund_request_statuses']).toBe('preparing,shipping');
    expect(tags['claim.charge_payment_methods']).toBe('card,point');

    const numbers = Array.from(container.querySelectorAll('input[type="number"]')).map(
      (n) => (n as HTMLInputElement).value,
    );
    // 선교환 회수 기한(값이 없으면 기본 14일)이 정책 카드 맨 앞 숫자 입력이다(#599 §3-3).
    expect(numbers).toEqual(['14', '14', '7', '3072']);
  });

  /**
   * 자동 전이 대상 상태에 **미결제 2종**이 선택지로 있고 저장값이 그대로 그려진다. (#599 후속 D10)
   *
   * 화면 선택지와 서버 허용 어휘가 갈리면 한쪽이 우회로가 된다 — 화면에 없으면 운영자가
   * 고를 수 없고, 서버에 없으면 API 로 보낸 값이 422 가 된다.
   *
   * @scenario reason_type=refund
   *
   * @effects instant_cancel_statuses_default_includes_pending_states
   */
  it('자동 전이 대상 상태에 미결제 2종이 선택지로 있다 (D10)', async () => {
    const { container } = await testUtils.render();

    const node = container.querySelector('[data-testid="taginput"][data-name="claim.instant_cancel_statuses"]');
    expect(node).not.toBeNull();
    expect(node?.textContent).toBe('pending_order,pending_payment,payment_complete');

    const options = node?.getAttribute('data-options') ?? '';
    expect(options).toContain('pending_order');
    expect(options).toContain('pending_payment');
  });

  /**
   * 자동승인 **대상 상태** 입력이 있고, 토글이 꺼져 있으면 잠긴다. (#599 후속 D8)
   *
   * 토글만 있으면 "출고 전만 자동승인" 같은 운영을 표현할 수 없어, 전건 수동 승인과 배송중
   * 건까지 자동승인의 양자택일이 된다.
   *
   * @scenario reason_type=refund
   *
   * @effects auto_approve_refund_statuses_is_editable, auto_approve_refund_statuses_is_disabled_when_toggle_off
   */
  it('자동승인 대상 상태 입력이 있고 토글이 꺼져 있으면 잠긴다 (D8)', async () => {
    const { container } = await testUtils.render();

    const node = container.querySelector('[data-testid="taginput"][data-name="claim.auto_approve_refund_statuses"]');
    expect(node).not.toBeNull();
    expect(node?.textContent).toBe('payment_complete,preparing');
    // 픽스처의 `auto_approve_refund_request` 는 false 다 — 켜야 의미가 있는 값이므로 잠긴다.
    expect(node?.getAttribute('data-disabled')).toBe('true');
  });

  /**
   * 완료 처리 기본값 토글이 저장된 값을 반영한다.
   *
   * @effects settings_claim_complete_defaults_declared_as_modal_seed
   */
  it('완료 처리 기본값 토글이 저장된 값을 반영한다 (D26)', async () => {
    const { container } = await testUtils.render();

    const toggles = Object.fromEntries(
      Array.from(container.querySelectorAll('[data-testid="toggle"]')).map((n) => [
        n.getAttribute('data-name'),
        n.getAttribute('data-checked'),
      ]),
    );

    expect(toggles['claim.complete_defaults.create_refund_request']).toBe('false');
    expect(toggles['claim.complete_defaults.restore_stock']).toBe('true');
    expect(toggles['claim.auto_approve_zero_charge']).toBe('true');
    expect(toggles['claim.auto_approve_after_payment']).toBe('false');
  });

  /**
   * 한 카탈로그를 고쳐도 다른 카탈로그는 그대로다.
   *
   * @effects settings_claim_save_of_one_catalog_keeps_others
   */
  it('한 유형의 사유를 지워도 다른 유형의 목록은 그대로다', async () => {
    const { container } = await testUtils.render();

    testUtils.setState('form.claim.return_reasons', [], 'local');
    await testUtils.rerender();

    expect(container.querySelector('#return_reason_list')).toBeNull();
    expect(container.querySelector('#exchange_reason_list')?.querySelectorAll('tbody tr')).toHaveLength(1);
    expect(container.querySelector('#refund_reason_list')?.querySelectorAll('tbody tr')).toHaveLength(3);
  });
});
